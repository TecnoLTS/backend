#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck source=/dev/null
source "${SCRIPT_DIR}/common.sh"

MODE="qa"
CONFIRM=false

usage() {
  cat <<'EOF'
Uso:
  ./scripts/reset_sales_data.sh [qa|production] [--yes]

Acciones:
  - elimina pedidos y sus items
  - elimina turnos POS y movimientos POS
  - elimina asignaciones de lotes por ventas
  - elimina auditorias de descuentos ligadas a pedidos
  - elimina eventos de seguridad por manipulación de pedidos
  - restaura los lotes a su cantidad inicial
  - recompone quantity y deja sold = 0 en productos con lotes

Conserva:
  - catalogo de productos
  - compras / facturas de compra
  - lotes de inventario
  - usuarios, settings y tenants

Ejemplos:
  ./scripts/reset_sales_data.sh qa
  ./scripts/reset_sales_data.sh qa --yes
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    qa|production)
      MODE="$1"
      shift
      ;;
    --yes|-y)
      CONFIRM=true
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "Argumento no reconocido: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

ENV_FILE="$(resolve_env_file "${MODE}")"

set -a
# shellcheck source=/dev/null
source "${ENV_FILE}"
set +a

DB_CONTAINER="${DB_CONTAINER:-basesdedatos}"
DB_USER="${DB_USERNAME:-${POSTGRES_USER:-postgres}}"
DB_NAME="${DB_DATABASE:-${POSTGRES_DB:-paramascotasec}}"

if ! docker ps --format '{{.Names}}' | grep -qx "${DB_CONTAINER}"; then
  echo "No se encontro el contenedor de base ${DB_CONTAINER}. Ajusta DB_CONTAINER si hace falta." >&2
  exit 1
fi

echo "Base: ${DB_NAME}"
echo "Contenedor: ${DB_CONTAINER}"
echo "Entorno: ${MODE}"
echo "Se limpiaran SOLO datos de ventas y POS; no se tocara catalogo ni compras."

if [[ "${CONFIRM}" != "true" ]]; then
  printf "Escribe LIMPIAR-VENTAS para continuar: "
  read -r typed
  if [[ "${typed}" != "LIMPIAR-VENTAS" ]]; then
    echo "Cancelado."
    exit 1
  fi
fi

read -r -d '' COUNT_SQL <<'SQL' || true
SELECT 'Order', COUNT(*) FROM "Order"
UNION ALL SELECT 'OrderItem', COUNT(*) FROM "OrderItem"
UNION ALL SELECT 'PosShift', COUNT(*) FROM "PosShift"
UNION ALL SELECT 'PosMovement', COUNT(*) FROM "PosMovement"
UNION ALL SELECT 'InventoryLotAllocation', COUNT(*) FROM "InventoryLotAllocation"
UNION ALL SELECT 'DiscountAudit(order_id)', COUNT(*) FROM "DiscountAudit" WHERE order_id IS NOT NULL
UNION ALL SELECT 'AuthSecurityEvent(order_pricing_tamper)', COUNT(*) FROM "AuthSecurityEvent" WHERE event_type = 'order_pricing_tamper'
UNION ALL SELECT 'Products(total)', COUNT(*) FROM "Product"
UNION ALL SELECT 'PurchaseInvoice', COUNT(*) FROM "PurchaseInvoice"
UNION ALL SELECT 'PurchaseInvoiceItem', COUNT(*) FROM "PurchaseInvoiceItem"
ORDER BY 1;
SQL

echo
echo "Conteos antes:"
docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d "${DB_NAME}" -P pager=off -c "${COUNT_SQL}"

read -r -d '' RESET_SQL <<'SQL' || true
BEGIN;

-- Restablece lotes al estado de compra para devolver stock vendido.
UPDATE "InventoryLot"
SET remaining_quantity = initial_quantity,
    updated_at = NOW();

-- Limpia auditorias estrictamente vinculadas a ventas/pedidos.
DELETE FROM "DiscountAudit"
WHERE order_id IS NOT NULL;

DELETE FROM "AuthSecurityEvent"
WHERE event_type = 'order_pricing_tamper';

DELETE FROM "InventoryLotAllocation";
DELETE FROM "OrderItem";
DELETE FROM "Order";
DELETE FROM "PosMovement";
DELETE FROM "PosShift";

-- Reinicia contadores de descuentos tras borrar pedidos de prueba.
UPDATE "DiscountCode"
SET used_count = 0,
    updated_at = NOW();

-- Recompone stock desde lotes y borra historial de vendidos.
WITH lot_totals AS (
  SELECT
    product_id,
    SUM(remaining_quantity) AS qty
  FROM "InventoryLot"
  GROUP BY product_id
)
UPDATE "Product" p
SET quantity = COALESCE(lot_totals.qty, 0),
    sold = 0,
    updated_at = NOW()
FROM lot_totals
WHERE p.id = lot_totals.product_id;

-- Si hubiera productos sin lotes pero con ventas previas, al menos resetea sold.
UPDATE "Product"
SET sold = 0,
    updated_at = NOW()
WHERE sold <> 0;

COMMIT;
SQL

docker exec "${DB_CONTAINER}" psql -v ON_ERROR_STOP=1 -U "${DB_USER}" -d "${DB_NAME}" -c "${RESET_SQL}"

echo
echo "Conteos despues:"
docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d "${DB_NAME}" -P pager=off -c "${COUNT_SQL}"

echo
echo "Reset de ventas completado."
