#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Reutiliza la resolucion de entorno del backend.
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/common.sh"

MODE="qa"
CONFIRM=false
FULL_WIPE=false
KEEP_USERS=true

usage() {
  cat <<'EOF'
Uso:
  ./scripts/reset_test_data.sh [qa|production] [--yes] [--full]

Comportamiento por defecto:
  - limpia productos, imagenes, variantes, lotes, compras, pedidos, descuentos y POS
  - conserva User, Setting y Tenant para no perder acceso ni configuracion

Opciones:
  qa|production           Entorno cuyo .env se usara para conectar
  --yes                   Ejecuta sin pedir confirmacion interactiva
  --full                  Limpia tambien la tabla User (muy destructivo)

Ejemplos:
  ./scripts/reset_test_data.sh qa
  ./scripts/reset_test_data.sh qa --yes
  ./scripts/reset_test_data.sh production --yes --full
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
    --full)
      FULL_WIPE=true
      KEEP_USERS=false
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

SAFE_TABLES=(
  '"AuthSecurityEvent"'
  '"DiscountAudit"'
  '"DiscountCode"'
  '"Image"'
  '"InventoryLotAllocation"'
  '"InventoryLot"'
  '"OrderItem"'
  '"Order"'
  '"PosMovement"'
  '"PosShift"'
  '"PurchaseInvoiceItem"'
  '"PurchaseInvoice"'
  '"Variation"'
  '"Product"'
)

FULL_ONLY_TABLES=(
  '"User"'
)

TABLES_TO_TRUNCATE=("${SAFE_TABLES[@]}")
if [[ "${KEEP_USERS}" == "false" ]]; then
  TABLES_TO_TRUNCATE+=("${FULL_ONLY_TABLES[@]}")
fi

TABLE_LIST="$(printf ', %s' "${TABLES_TO_TRUNCATE[@]}")"
TABLE_LIST="${TABLE_LIST:2}"

echo "Base: ${DB_NAME}"
echo "Contenedor: ${DB_CONTAINER}"
echo "Entorno: ${MODE}"
echo "Tablas a limpiar: ${TABLE_LIST}"
if [[ "${KEEP_USERS}" == "true" ]]; then
  echo "Se conservaran: \"User\", \"Setting\", \"Tenant\""
else
  echo "ATENCION: tambien se limpiara \"User\""
fi

if [[ "${CONFIRM}" != "true" ]]; then
  printf "Escribe LIMPIAR para continuar: "
  read -r typed
  if [[ "${typed}" != "LIMPIAR" ]]; then
    echo "Cancelado."
    exit 1
  fi
fi

read -r -d '' COUNT_SQL <<'SQL' || true
SELECT 'Product', COUNT(*) FROM "Product"
UNION ALL SELECT 'Variation', COUNT(*) FROM "Variation"
UNION ALL SELECT 'Image', COUNT(*) FROM "Image"
UNION ALL SELECT 'InventoryLot', COUNT(*) FROM "InventoryLot"
UNION ALL SELECT 'InventoryLotAllocation', COUNT(*) FROM "InventoryLotAllocation"
UNION ALL SELECT 'PurchaseInvoice', COUNT(*) FROM "PurchaseInvoice"
UNION ALL SELECT 'PurchaseInvoiceItem', COUNT(*) FROM "PurchaseInvoiceItem"
UNION ALL SELECT 'Order', COUNT(*) FROM "Order"
UNION ALL SELECT 'OrderItem', COUNT(*) FROM "OrderItem"
UNION ALL SELECT 'DiscountCode', COUNT(*) FROM "DiscountCode"
UNION ALL SELECT 'DiscountAudit', COUNT(*) FROM "DiscountAudit"
UNION ALL SELECT 'AuthSecurityEvent', COUNT(*) FROM "AuthSecurityEvent"
UNION ALL SELECT 'PosShift', COUNT(*) FROM "PosShift"
UNION ALL SELECT 'PosMovement', COUNT(*) FROM "PosMovement"
UNION ALL SELECT 'User', COUNT(*) FROM "User"
UNION ALL SELECT 'Setting', COUNT(*) FROM "Setting"
UNION ALL SELECT 'Tenant', COUNT(*) FROM "Tenant"
ORDER BY 1;
SQL

echo
echo "Conteos antes:"
docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d "${DB_NAME}" -P pager=off -c "${COUNT_SQL}"

TRUNCATE_SQL="BEGIN; TRUNCATE TABLE ${TABLE_LIST} RESTART IDENTITY CASCADE; COMMIT;"

docker exec "${DB_CONTAINER}" psql -v ON_ERROR_STOP=1 -U "${DB_USER}" -d "${DB_NAME}" -c "${TRUNCATE_SQL}"

echo
echo "Conteos despues:"
docker exec "${DB_CONTAINER}" psql -U "${DB_USER}" -d "${DB_NAME}" -P pager=off -c "${COUNT_SQL}"

echo
echo "Limpieza completada."
