#!/usr/bin/env bash
set -euo pipefail

# Utilidad legacy del backend compartido.
# No forma parte del flujo diario de Paramascotasec y solo sirve para
# clonar una linea base entre bases aisladas cuando hace falta.

DB_CONTAINER="${DB_CONTAINER:-basesdedatos}"
DB_USER="${DB_USER:-postgres}"
SOURCE_DB="${1:-paramascotasec}"
TARGET_DB="${2:-paramascotasec}"
SOURCE_TENANT="${3:-paramascotasec}"
TARGET_TENANT="${4:-paramascotasec}"

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Falta comando requerido: $1"
    exit 1
  fi
}

validate_identifier() {
  local value="$1"
  if [[ ! "$value" =~ ^[a-z0-9_]+$ ]]; then
    echo "Identificador invalido: $value"
    exit 1
  fi
}

psql_exec() {
  local db="$1"
  local sql="$2"
  docker exec "$DB_CONTAINER" psql -v ON_ERROR_STOP=1 -U "$DB_USER" -d "$db" -Atc "$sql"
}

table_count() {
  local db="$1"
  local table="$2"
  psql_exec "$db" "SELECT count(*) FROM \"$table\";"
}

copy_query_to_table() {
  local source_db="$1"
  local target_db="$2"
  local query="$3"
  local table="$4"
  local columns="$5"

  docker exec "$DB_CONTAINER" psql -v ON_ERROR_STOP=1 -U "$DB_USER" -d "$source_db" -Atc "COPY ($query) TO STDOUT WITH CSV" \
    | docker exec -i "$DB_CONTAINER" psql -v ON_ERROR_STOP=1 -U "$DB_USER" -d "$target_db" -c "COPY \"$table\" ($columns) FROM STDIN WITH CSV"
}

require_command docker
validate_identifier "$SOURCE_DB"
validate_identifier "$TARGET_DB"
validate_identifier "$SOURCE_TENANT"
validate_identifier "$TARGET_TENANT"

if ! docker ps --format '{{.Names}}' | grep -qx "$DB_CONTAINER"; then
  echo "El contenedor de base de datos no esta corriendo: $DB_CONTAINER"
  exit 1
fi

echo "Baseline tenant: ${SOURCE_TENANT}/${SOURCE_DB} -> ${TARGET_TENANT}/${TARGET_DB}"

target_user_count="$(table_count "$TARGET_DB" "User")"
target_setting_count="$(table_count "$TARGET_DB" "Setting")"
target_discount_count="$(table_count "$TARGET_DB" "DiscountCode")"

if [[ "$target_setting_count" == "0" ]]; then
  echo "Copiando settings..."
  copy_query_to_table \
    "$SOURCE_DB" \
    "$TARGET_DB" \
    "SELECT replace(key, '${SOURCE_TENANT}:', '${TARGET_TENANT}:'), value, '${TARGET_TENANT}' FROM \"Setting\" WHERE tenant_id = '${SOURCE_TENANT}' ORDER BY key" \
    "Setting" \
    "key, value, tenant_id"
else
  echo "Saltando settings: target ya tiene ${target_setting_count} registro(s)"
fi

if [[ "$target_user_count" == "0" ]]; then
  echo "Copiando usuarios administradores..."
  copy_query_to_table \
    "$SOURCE_DB" \
    "$TARGET_DB" \
    "SELECT id, email, name, password, created_at, updated_at, email_verified, verification_token, role, addresses, profile, active_token_id, document_type, document_number, business_name, otp_code, otp_expires_at, otp_attempts, '${TARGET_TENANT}' FROM \"User\" WHERE tenant_id = '${SOURCE_TENANT}' AND role = 'admin' ORDER BY email" \
    "User" \
    "id, email, name, password, created_at, updated_at, email_verified, verification_token, role, addresses, profile, active_token_id, document_type, document_number, business_name, otp_code, otp_expires_at, otp_attempts, tenant_id"
else
  echo "Saltando usuarios: target ya tiene ${target_user_count} registro(s)"
fi

if [[ "$target_discount_count" == "0" ]]; then
  echo "Copiando codigos de descuento..."
  copy_query_to_table \
    "$SOURCE_DB" \
    "$TARGET_DB" \
    "SELECT id, '${TARGET_TENANT}', code, name, description, type, value, min_subtotal, max_discount, max_uses, used_count, starts_at, ends_at, is_active, created_by, metadata, created_at, updated_at FROM \"DiscountCode\" WHERE tenant_id = '${SOURCE_TENANT}' ORDER BY code" \
    "DiscountCode" \
    "id, tenant_id, code, name, description, type, value, min_subtotal, max_discount, max_uses, used_count, starts_at, ends_at, is_active, created_by, metadata, created_at, updated_at"
else
  echo "Saltando descuentos: target ya tiene ${target_discount_count} registro(s)"
fi

echo
echo "Conteos finales en ${TARGET_DB}:"
echo "users=$(table_count "$TARGET_DB" "User")"
echo "settings=$(table_count "$TARGET_DB" "Setting")"
echo "discount_codes=$(table_count "$TARGET_DB" "DiscountCode")"
echo "products=$(table_count "$TARGET_DB" "Product")"
echo "orders=$(table_count "$TARGET_DB" "Order")"
