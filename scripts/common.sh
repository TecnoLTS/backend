#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORKSPACE_ENV_MODE="${APP_DIR}/../scripts/env-mode.sh"
# shellcheck disable=SC1090
source "${WORKSPACE_ENV_MODE}"
ENTORNO_DIR="${APP_DIR}/entorno"
ENTORNO_ENV_FILE="${ENTORNO_DIR}/.env"
TEMPLATE_ENTORNO_DIR="${APP_DIR}/templates/entorno"

read_env_value() {
  local file="$1"
  local key="$2"

  awk -F= -v target="${key}" '
    $1 == target {
      sub(/^[[:space:]]+/, "", $2)
      sub(/[[:space:]]+$/, "", $2)
      print $2
      exit
    }
  ' "${file}"
}

normalize_env_value() {
  local value="$1"

  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  value="${value%$'\r'}"

  if [[ "${value}" == \"*\" && "${value}" == *\" ]]; then
    value="${value:1:${#value}-2}"
  elif [[ "${value}" == \'*\' && "${value}" == *\' ]]; then
    value="${value:1:${#value}-2}"
  fi

  printf '%s' "${value}"
}

ensure_docker_ready() {
  if ! command -v docker >/dev/null 2>&1; then
    echo "docker no esta instalado"
    exit 1
  fi

  if ! docker compose version >/dev/null 2>&1; then
    echo "docker compose no esta disponible"
    exit 1
  fi

  if ! docker network inspect edge >/dev/null 2>&1; then
    docker network create edge >/dev/null
  fi

  if ! docker network inspect webparamascotas-internal >/dev/null 2>&1; then
    docker network create --internal webparamascotas-internal >/dev/null
  fi

  if ! docker network inspect basesdedatos-internal >/dev/null 2>&1; then
    docker network create --internal basesdedatos-internal >/dev/null
  fi

}

upsert_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"

  # Incluso los upserts de compatibilidad deben mantener valores fuera de argv.
  python3 - "$file" "$key" 3<<<"${value}" <<'PY'
import os
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
key = sys.argv[2]
value = os.fdopen(3, "r", encoding="utf-8").read().rstrip("\n")

def render_env_value(raw: str) -> str:
    if re.fullmatch(r"[A-Za-z0-9_./:@,%+=-]*", raw):
        return raw
    escaped = (
        raw
        .replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("$", "\\$")
        .replace("`", "\\`")
    )
    return f'"{escaped}"'

rendered = render_env_value(value)
lines = path.read_text().splitlines()
for index, line in enumerate(lines):
    if line.startswith(f"{key}="):
        lines[index] = f"{key}={rendered}"
        break
else:
    lines.append(f"{key}={rendered}")
path.write_text("\n".join(lines) + "\n")
PY
}

ensure_entorno_files() {
  local created=0

  mkdir -p "${ENTORNO_DIR}"

  if [[ ! -f "${ENTORNO_ENV_FILE}" ]]; then
    if [[ ! -f "${TEMPLATE_ENTORNO_DIR}/.env.example" ]]; then
      echo "No se encontro ${TEMPLATE_ENTORNO_DIR}/.env.example" >&2
      exit 1
    fi
    cp "${TEMPLATE_ENTORNO_DIR}/.env.example" "${ENTORNO_ENV_FILE}"
    chmod 600 "${ENTORNO_ENV_FILE}"
    echo "Se creo ${ENTORNO_ENV_FILE} desde templates/entorno/.env.example."
    created=1
  fi

  if [[ "${created}" == "1" ]]; then
    echo "Completa valores reales y ENTORNO_MODE en entorno/.env antes de desplegar." >&2
    exit 1
  fi
}

assert_no_legacy_runtime_paths() {
  local env_name=".env"
  local suffix
  local found=()
  local path

  for suffix in "" ".production" ".local"; do
    path="${APP_DIR}/${env_name}${suffix}"
    if [[ -e "${path}" ]]; then
      found+=("${path#${APP_DIR}/}")
    fi
  done

  if (( ${#found[@]} > 0 )); then
    printf 'Rutas legacy fuera de entorno/ detectadas en backend: %s\n' "${found[*]}" >&2
    printf 'Mueve esos archivos a un backup externo antes de desplegar.\n' >&2
    exit 1
  fi
}

assert_entorno_mode() {
  local expected="$1"
  local actual expected_canonical actual_canonical

  actual="$(read_env_value "${ENTORNO_ENV_FILE}" "ENTORNO_MODE" || true)"
  actual="$(normalize_env_value "${actual}")"
  expected_canonical="$(canonical_env_mode "${expected}")"
  actual_canonical="$(canonical_env_mode "${actual}" 2>/dev/null || true)"

  if [[ "${actual_canonical}" != "${expected_canonical}" ]]; then
    echo "ENTORNO_MODE=${actual:-<vacio>} en ${ENTORNO_ENV_FILE}; esperado ${expected}." >&2
    exit 1
  fi
}

validate_backend_env_for_mode() {
  local mode="$1"
  local env_file="$2"
  local app_env sri_env driver billing_legacy_read billing_write_mode

  app_env="$(normalize_env_value "$(read_env_value "${env_file}" "APP_ENV" || true)")"
  sri_env="$(normalize_env_value "$(read_env_value "${env_file}" "SRI_ENVIRONMENT" || true)")"
  driver="$(normalize_env_value "$(read_env_value "${env_file}" "BILLING_GATEWAY_DRIVER" || true)")"
  driver="${driver:-native}"
  billing_legacy_read="$(normalize_env_value "$(read_env_value "${env_file}" "BILLING_SECRET_LEGACY_READ_ENABLED" || true)")"
  billing_write_mode="$(normalize_env_value "$(read_env_value "${env_file}" "BILLING_SECRET_WRITE_MODE" || true)")"
  billing_legacy_read="${billing_legacy_read:-false}"
  billing_write_mode="${billing_write_mode:-encrypted}"

  if [[ "${driver}" != "native" ]]; then
    echo "BILLING_GATEWAY_DRIVER=${driver} no permitido; Billing SRI debe usar native." >&2
    exit 1
  fi
  if [[ "${billing_legacy_read}" != "false" || "${billing_write_mode}" != "encrypted" ]]; then
    echo "El deploy Compose exige Billing ciphertext-only (LEGACY_READ=false, WRITE_MODE=encrypted)." >&2
    echo "La compatibilidad escalonada pertenece exclusivamente al rollout Kubernetes V002." >&2
    exit 1
  fi

  case "${mode}" in
    qa)
      if [[ "${app_env}" != "qa" ]]; then
        echo "APP_ENV=${app_env:-<vacio>} no es valido para QA; usa qa." >&2
        exit 1
      fi
      if [[ "${sri_env}" != "pruebas" ]]; then
        echo "SRI_ENVIRONMENT=${sri_env:-<vacio>} no es valido para QA; usa pruebas." >&2
        exit 1
      fi
      ;;
    production)
      if [[ ! "${app_env}" =~ ^(production|prod)$ ]]; then
        echo "APP_ENV=${app_env:-<vacio>} no es valido para production; usa production." >&2
        exit 1
      fi
      if [[ "${sri_env}" != "produccion" ]]; then
        echo "SRI_ENVIRONMENT=${sri_env:-<vacio>} no es valido para production; usa produccion." >&2
        exit 1
      fi
      ;;
  esac
}

resolve_env_file() {
  local mode="${1:-qa}"
  local env_file="${ENTORNO_ENV_FILE}"
  local primary_domain primary_aliases public_scheme tenant_slug loyalty_segment public_base_url tenant_name gateway_env

  if ! mode="$(canonical_env_mode "${mode}")"; then
    echo "Modo invalido: ${mode}. Usa qa o production." >&2
    exit 1
  fi

  assert_no_legacy_runtime_paths
  ensure_entorno_files
  assert_entorno_mode "${mode}"

  gateway_env="${APP_DIR}/../gatewayapisix/entorno/.env"
  tenant_slug="$(normalize_env_value "$(read_env_value "${env_file}" "PUBLIC_TENANT_SLUG" || true)")"
  tenant_slug="${tenant_slug:-$(normalize_env_value "$(read_env_value "${env_file}" "DEFAULT_TENANT" || true)")}"
  tenant_slug="${tenant_slug:-paramascotasec}"
  loyalty_segment="$(normalize_env_value "$(read_env_value "${env_file}" "PUBLIC_LOYALTY_SERVICE_SEGMENT" || true)")"
  loyalty_segment="${loyalty_segment:-fidelizacion}"
  primary_domain="$(normalize_env_value "$(read_env_value "${env_file}" "PRIMARY_SITE_DOMAIN" || true)")"
  primary_domain="${primary_domain:-paramascotasec.com}"
  primary_aliases="$(normalize_env_value "$(read_env_value "${env_file}" "PRIMARY_SITE_ALIASES" || true)")"
  primary_aliases="${primary_aliases:-www.${primary_domain}}"
  public_scheme="$(normalize_env_value "$(read_env_value "${env_file}" "PUBLIC_SCHEME" || true)")"
  public_scheme="${public_scheme:-https}"
  if [[ -f "${gateway_env}" ]]; then
    tenant_slug="$(normalize_env_value "$(read_env_value "${gateway_env}" "PUBLIC_TENANT_SLUG" || true)")"
    tenant_slug="${tenant_slug:-paramascotasec}"
    loyalty_segment="$(normalize_env_value "$(read_env_value "${gateway_env}" "PUBLIC_LOYALTY_SERVICE_SEGMENT" || true)")"
    loyalty_segment="${loyalty_segment:-fidelizacion}"
    primary_domain="$(normalize_env_value "$(read_env_value "${gateway_env}" "PRIMARY_SITE_DOMAIN" || true)")"
    primary_domain="${primary_domain:-paramascotasec.com}"
    primary_aliases="$(normalize_env_value "$(read_env_value "${gateway_env}" "PRIMARY_SITE_ALIASES" || true)")"
    primary_aliases="${primary_aliases:-www.${primary_domain}}"
    public_scheme="$(normalize_env_value "$(read_env_value "${gateway_env}" "PUBLIC_SCHEME" || true)")"
    public_scheme="${public_scheme:-https}"
  fi
  public_base_url="${public_scheme}://${primary_domain}"
  tenant_name="$(normalize_env_value "$(read_env_value "${env_file}" "TENANT_DISPLAY_NAME" || true)")"
  tenant_name="${tenant_name:-Para Mascotas EC}"
  local db_username db_password
  db_username="$(normalize_env_value "$(read_env_value "${env_file}" "DB_USERNAME" || true)")"
  db_username="${db_username:-paramascotasec_backend_app}"
  db_password="$(normalize_env_value "$(read_env_value "${env_file}" "DB_PASSWORD" || true)")"

  upsert_env_value "${env_file}" "DEFAULT_TENANT" "${tenant_slug}"
  upsert_env_value "${env_file}" "PUBLIC_TENANT_SLUG" "${tenant_slug}"
  upsert_env_value "${env_file}" "PUBLIC_LOYALTY_SERVICE_SEGMENT" "${loyalty_segment}"
  upsert_env_value "${env_file}" "PUBLIC_SCHEME" "${public_scheme}"
  upsert_env_value "${env_file}" "PRIMARY_SITE_DOMAIN" "${primary_domain}"
  upsert_env_value "${env_file}" "PRIMARY_SITE_ALIASES" "${primary_aliases}"
  upsert_env_value "${env_file}" "PUBLIC_BASE_URL" "${public_base_url}"
  upsert_env_value "${env_file}" "TENANT_DISPLAY_NAME" "${tenant_name}"
  upsert_env_value "${env_file}" "DB_DATABASE_IDENTITY_PLATFORM" "dashboard"
  upsert_env_value "${env_file}" "DB_DATABASE_CATALOG_INVENTORY" "ecommerce"
  upsert_env_value "${env_file}" "DB_DATABASE_COMMERCE" "ecommerce"
  upsert_env_value "${env_file}" "DB_DATABASE_REPORTING_FINANCE" "ecommerce"
  upsert_env_value "${env_file}" "DB_DATABASE_BILLING" "facturacion"
  upsert_env_value "${env_file}" "DB_USERNAME_BILLING" "${db_username}"
  if [[ -n "${db_password}" ]]; then
    upsert_env_value "${env_file}" "DB_PASSWORD_BILLING" "${db_password}"
  fi
  upsert_env_value "${env_file}" "DB_DATABASE_MAILER_SERVICE" "dashboard"

  if [[ "${mode}" == "qa" ]]; then
    local backend_bind_ip backend_port backend_public_scheme backend_public_host app_url
    backend_bind_ip="${BACKEND_BIND_IP:-$(read_env_value "${env_file}" "BACKEND_BIND_IP")}"
    backend_bind_ip="${backend_bind_ip:-0.0.0.0}"
    backend_port="${BACKEND_PORT:-$(read_env_value "${env_file}" "BACKEND_PORT")}"
    backend_port="${backend_port:-8080}"
    backend_public_scheme="${BACKEND_PUBLIC_SCHEME:-$(read_env_value "${env_file}" "BACKEND_PUBLIC_SCHEME")}"
    backend_public_scheme="${backend_public_scheme:-http}"
    backend_public_host="${BACKEND_PUBLIC_HOST:-$(read_env_value "${env_file}" "BACKEND_PUBLIC_HOST")}"
    backend_public_host="${backend_public_host:-$(detect_qa_public_host)}"
    app_url="${public_base_url}"

    upsert_env_value "${env_file}" "BACKEND_BIND_IP" "${backend_bind_ip}"
    upsert_env_value "${env_file}" "BACKEND_PORT" "${backend_port}"
    upsert_env_value "${env_file}" "BACKEND_PUBLIC_SCHEME" "${backend_public_scheme}"
    upsert_env_value "${env_file}" "BACKEND_PUBLIC_HOST" "${backend_public_host}"
    upsert_env_value "${env_file}" "APP_URL" "${app_url}"
    validate_backend_env_for_mode "${mode}" "${env_file}"

    printf '%s\n' "${env_file}"
    return 0
  fi

  local app_url
  app_url="${APP_URL:-$(read_env_value "${env_file}" "APP_URL")}"
  if [[ -z "${app_url}" || "${app_url}" == http://localhost* || "${app_url}" == http://127.0.0.1* ]]; then
    app_url="${public_base_url}"
  fi

  upsert_env_value "${env_file}" "APP_URL" "${app_url%/}"
  validate_backend_env_for_mode "${mode}" "${env_file}"
  printf '%s\n' "${env_file}"
  return 0
}

detect_qa_public_host() {
  local candidate

  if command -v ip >/dev/null 2>&1; then
    candidate="$(ip route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}')"
  fi

  if [[ -z "${candidate:-}" ]]; then
    candidate="$(hostname -I 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i != "127.0.0.1" && $i !~ /^172\.(1[6-9]|2[0-9]|3[0-1])\./) { print $i; exit }}')"
  fi

  if [[ -z "${candidate:-}" ]]; then
    candidate="localhost"
  fi

  printf '%s\n' "${candidate}"
}

runtime_mount_path() {
  local env_file="$1"
  local key="$2"
  local default_value="$3"
  local configured

  configured="$(normalize_env_value "$(read_env_value "${env_file}" "${key}" || true)")"
  configured="${configured:-${default_value}}"
  if [[ "${configured}" == /* ]]; then
    printf '%s\n' "${configured}"
  else
    printf '%s\n' "${APP_DIR}/${configured#./}"
  fi
}

prepare_backend_storage_permissions() {
  local env_file="$1"
  local storage_dir uploads_dir billing_storage_dir
  local billing_runtime_gid="${BILLING_RUNTIME_GID:-82}"

  storage_dir="$(runtime_mount_path "${env_file}" BACKEND_STORAGE_PATH ./storage)"
  uploads_dir="$(runtime_mount_path "${env_file}" BACKEND_UPLOADS_PATH ./public/uploads)"
  billing_storage_dir="${storage_dir}/billing"

  mkdir -p \
    "${storage_dir}/logs" \
    "${storage_dir}/wallet" \
    "${billing_storage_dir}/cache" \
    "${billing_storage_dir}/certs" \
    "${billing_storage_dir}/logs" \
    "${billing_storage_dir}/pdf/rides" \
    "${billing_storage_dir}/xml/autorizados" \
    "${billing_storage_dir}/xml/firmados" \
    "${billing_storage_dir}/xml/generados" \
    "${uploads_dir}/products" \
    "${uploads_dir}/loyalty/rewards"

  chgrp -R "${billing_runtime_gid}" "${billing_storage_dir}"
  find "${billing_storage_dir}" -type d -exec chmod 770 {} +
  find "${billing_storage_dir}" -type f -exec chmod 660 {} +
  chgrp "${billing_runtime_gid}" \
    "${storage_dir}" "${storage_dir}/logs" "${storage_dir}/wallet" \
    "${uploads_dir}" "${uploads_dir}/products" "${uploads_dir}/loyalty" "${uploads_dir}/loyalty/rewards"
  chmod 770 "${storage_dir}" "${storage_dir}/logs" "${storage_dir}/wallet"
  chmod 775 "${uploads_dir}" "${uploads_dir}/products" "${uploads_dir}/loyalty" "${uploads_dir}/loyalty/rewards"
}

atomic_replace_backend_database_passwords() {
  local env_file="$1"
  local new_password="$2"

  if [[ -z "${new_password}" ]]; then
    echo "La rotacion DB no genero un password nuevo." >&2
    return 1
  fi

  # El secreto viaja por un descriptor privado, no por argv. El reemplazo se
  # hace en el mismo directorio y termina con rename(2), fsync y modo 0600.
  python3 - "${env_file}" 3<<<"${new_password}" <<'PY'
import os
import re
import stat
import sys
import tempfile
from pathlib import Path

path = Path(sys.argv[1])
secret = os.fdopen(3, "r", encoding="utf-8").read().rstrip("\n")
if not re.fullmatch(r"[A-Fa-f0-9]{64}", secret):
    raise SystemExit("El password DB rotado no cumple el formato aleatorio esperado.")

def render(raw: str) -> str:
    if re.fullmatch(r"[A-Za-z0-9_./:@,%+=-]*", raw):
        return raw
    escaped = (
        raw.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("$", "\\$")
        .replace("`", "\\`")
    )
    return f'"{escaped}"'

original = path.stat()
lines = path.read_text(encoding="utf-8").splitlines()
for key in ("DB_PASSWORD", "DB_PASSWORD_BILLING"):
    replacement = f"{key}={render(secret)}"
    for index, line in enumerate(lines):
        if line.startswith(f"{key}="):
            lines[index] = replacement
            break
    else:
        lines.append(replacement)

fd, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.rotate-", dir=path.parent)
try:
    os.fchmod(fd, 0o600)
    try:
        os.fchown(fd, original.st_uid, original.st_gid)
    except PermissionError:
        pass
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        handle.write("\n".join(lines) + "\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temporary_name, path)
    os.chmod(path, stat.S_IRUSR | stat.S_IWUSR)
    directory_fd = os.open(path.parent, os.O_RDONLY)
    try:
        os.fsync(directory_fd)
    finally:
        os.close(directory_fd)
except BaseException:
    try:
        os.unlink(temporary_name)
    except FileNotFoundError:
        pass
    raise
PY
}

disable_backend_database_role() {
  local admin_username="$1"
  local admin_password="$2"
  local app_role="$3"

  if [[ -z "${admin_username}" || -z "${admin_password}" ]]; then
    echo "La rotacion DB requiere credenciales admin efimeras." >&2
    return 1
  fi
  if [[ ! "${admin_username}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "El usuario DB administrativo no es un identificador seguro." >&2
    return 1
  fi
  if [[ ! "${app_role}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "El rol DB de aplicacion no es un identificador seguro." >&2
    return 1
  fi

  # La primera linea de stdin contiene la credencial solo para el shell
  # efimero del contenedor; no aparece en argv ni en la salida del deploy.
  {
    printf '%s\n' "${admin_password}"
    cat <<'SQL'
SELECT set_config('app.rotation_role', :'app_role', false);
DO $$
DECLARE role_name text := current_setting('app.rotation_role');
BEGIN
    IF to_regrole(role_name) IS NULL THEN
        RAISE EXCEPTION 'No existe el rol de aplicacion solicitado para rotacion';
    END IF;
    EXECUTE format('ALTER ROLE %I NOLOGIN', role_name);
    PERFORM pg_terminate_backend(pid)
    FROM pg_stat_activity
    WHERE usename = role_name AND pid <> pg_backend_pid();
    IF EXISTS (
        SELECT 1
        FROM pg_roles
        WHERE rolname = role_name
          AND rolcanlogin
    ) THEN
        RAISE EXCEPTION 'El rol de aplicacion continuo con LOGIN tras la invalidacion';
    END IF;
END $$;
SQL
  } | docker exec -i basesdedatos /bin/sh -c '
    set -eu
    umask 077
    unset POSTGRES_PASSWORD PGPASSWORD
    IFS= read -r db_password
    passfile="$(mktemp /tmp/pgpass.XXXXXX)"
    trap '\''rm -f -- "$passfile"'\'' 0 1 2 3 15
    escaped_password="$(printf "%s" "$db_password" | sed '\''s/\\/\\\\/g; s/:/\\:/g'\'')"
    unset db_password
    printf "*:*:*:*:%s\n" "$escaped_password" > "$passfile"
    unset escaped_password
    chmod 600 "$passfile"
    PGPASSFILE="$passfile" psql -X -h 127.0.0.1 -U "$1" -d postgres -v ON_ERROR_STOP=1 -v app_role="$2"
  ' rotation-disable "${admin_username}" "${app_role}" >/dev/null
}

backend_rotation_exit_guard() {
  local attempt role_disabled=0

  if [[ "${BACKEND_ROTATION_GUARD_ACTIVE:-0}" != "1" ]]; then
    return 0
  fi
  # Desarmar primero evita recursion si una operacion del propio guard falla.
  BACKEND_ROTATION_GUARD_ACTIVE=0

  compose_cmd "${BACKEND_ROTATION_GUARD_ENV_FILE}" stop \
    api http sri-worker wallet-notify-worker commerce-billing-worker mailer-worker \
    >/dev/null 2>&1 || true

  for attempt in 1 2 3; do
    if disable_backend_database_role \
      "${BACKEND_ROTATION_GUARD_ADMIN_USERNAME}" \
      "${BACKEND_ROTATION_GUARD_ADMIN_PASSWORD}" \
      "${BACKEND_ROTATION_GUARD_APP_ROLE}" >/dev/null 2>&1; then
      role_disabled=1
      break
    fi
  done

  if [[ "${role_disabled}" == "1" ]]; then
    echo "Rotacion DB interrumpida: runtimes detenidos y rol DB comprobado en NOLOGIN; el secreto anterior no se restaura." >&2
  else
    echo "CRITICO: rotacion DB interrumpida y runtimes detenidos, pero no se pudo comprobar el rol DB en NOLOGIN; requiere intervencion administrativa inmediata." >&2
  fi

  unset BACKEND_ROTATION_GUARD_ADMIN_PASSWORD
  return 0
}

verify_rotated_backend_database_role() {
  local app_role="$1"
  local app_password="$2"
  local database_name="$3"

  if [[ ! "${app_role}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ || ! "${database_name}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "No se puede verificar la rotacion con identificadores DB inseguros." >&2
    return 1
  fi

  printf '%s\n' "${app_password}" | docker exec -i basesdedatos /bin/sh -c '
    set -eu
    umask 077
    unset POSTGRES_PASSWORD PGPASSWORD
    IFS= read -r db_password
    passfile="$(mktemp /tmp/pgpass.XXXXXX)"
    trap '\''rm -f -- "$passfile"'\'' 0 1 2 3 15
    escaped_password="$(printf "%s" "$db_password" | sed '\''s/\\/\\\\/g; s/:/\\:/g'\'')"
    unset db_password
    printf "*:*:*:*:%s\n" "$escaped_password" > "$passfile"
    unset escaped_password
    chmod 600 "$passfile"
    PGPASSFILE="$passfile" psql -X -h 127.0.0.1 -U "$1" -d "$2" -v ON_ERROR_STOP=1 -Atqc "SELECT 1"
  ' rotation-verify "${app_role}" "${database_name}" >/dev/null
}

compose_cmd() {
  local env_file="$1"
  shift

  (
    cd "${APP_DIR}"
    docker compose --env-file "${env_file}" "$@"
  )
}

assert_backend_mode() {
  local env_file="$1"
  local container_env expected_env

  container_env="$(docker inspect -f '{{range .Config.Env}}{{if eq . "APP_ENV=qa"}}qa{{else if eq . "APP_ENV=production"}}production{{end}}{{end}}' backend-api 2>/dev/null)"
  expected_env="$(normalize_env_value "$(read_env_value "${env_file}" "APP_ENV" || true)")"
  if [[ "${container_env}" != "${expected_env}" ]]; then
    echo "El backend quedo levantado con APP_ENV=${container_env:-desconocido}, esperado ${expected_env:-<vacio>}" >&2
    exit 1
  fi
}

wait_for_container_state() {
  local container_name="$1"
  local max_attempts="${2:-90}"
  local attempt=1
  local status

  while (( attempt <= max_attempts )); do
    status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${container_name}" 2>/dev/null || true)"
    if [[ "${status}" == "healthy" || "${status}" == "running" ]]; then
      return 0
    fi

    if [[ "${status}" == "unhealthy" || "${status}" == "exited" || "${status}" == "dead" ]]; then
      echo "El contenedor ${container_name} quedo en estado ${status}" >&2
      docker logs --tail 80 "${container_name}" >&2 || true
      exit 1
    fi

    sleep 2
    ((attempt++))
  done

  echo "El contenedor ${container_name} no quedo listo a tiempo" >&2
  docker logs --tail 80 "${container_name}" >&2 || true
  exit 1
}

verify_local_catalog_uploads() {
  local env_file="$1"
  local storage_driver upload_root catalog_file paths_file
  local attempt=1 max_attempts=15
  local upload_path relative_path
  local -a missing=()

  storage_driver="$(normalize_env_value "$(read_env_value "${env_file}" "STORAGE_DRIVER" || true)")"
  if [[ "${storage_driver}" != "local" ]]; then
    return 0
  fi

  upload_root="$(runtime_mount_path "${env_file}" BACKEND_UPLOADS_PATH ./public/uploads)"
  catalog_file="$(mktemp)"
  paths_file="$(mktemp)"

  if ! docker exec backend-http sh -lc \
    "wget -q -O - 'http://127.0.0.1:8080/api/products'" >"${catalog_file}"; then
    rm -f -- "${catalog_file}" "${paths_file}"
    echo "No se pudo consultar el catalogo para verificar sus imagenes locales." >&2
    return 1
  fi

  if ! python3 - "${catalog_file}" >"${paths_file}" <<'PY'
import json
import re
import sys
from urllib.parse import urlsplit

with open(sys.argv[1], encoding="utf-8") as source:
    payload = json.load(source)

paths = set()

def visit(value):
    if isinstance(value, dict):
        for item in value.values():
            visit(item)
        return
    if isinstance(value, list):
        for item in value:
            visit(item)
        return
    if not isinstance(value, str) or not value.startswith("/uploads/tenants/"):
        return

    path = urlsplit(value).path
    segments = path.split("/")[1:]
    if (not re.fullmatch(r"/uploads/tenants/[A-Za-z0-9._/-]+", path)
            or any(segment in {"", ".", ".."} for segment in segments)):
        raise ValueError(f"Ruta local de imagen insegura: {path!r}")
    paths.add(path)
    if "/products/" in path and path.lower().endswith(".webp"):
        stem = path[:-5]
        paths.add(f"{stem}-220.webp")
        paths.add(f"{stem}-360.webp")

visit(payload.get("data", []) if isinstance(payload, dict) else [])
for path in sorted(paths):
    print(path)
PY
  then
    rm -f -- "${catalog_file}" "${paths_file}"
    echo "El catalogo no tiene un JSON valido para verificar imagenes." >&2
    return 1
  fi

  while (( attempt <= max_attempts )); do
    missing=()
    while IFS= read -r upload_path; do
      [[ -n "${upload_path}" ]] || continue
      relative_path="${upload_path#/uploads/}"
      if [[ ! -r "${upload_root}/${relative_path}" ]] || \
        ! docker exec backend-http wget -q -O /dev/null "http://127.0.0.1:8080${upload_path}"; then
        missing+=("${upload_path}")
      fi
    done <"${paths_file}"

    if (( ${#missing[@]} == 0 )); then
      local verified_count
      verified_count="$(wc -l <"${paths_file}" | tr -d '[:space:]')"
      rm -f -- "${catalog_file}" "${paths_file}"
      echo "Imagenes locales del catalogo verificadas (${verified_count:-0} archivos y variantes)."
      return 0
    fi

    if (( attempt < max_attempts )); then
      sleep 2
    fi
    ((attempt++))
  done

  echo "El catalogo referencia imagenes que Git/almacenamiento local aun no publico:" >&2
  printf '  %s\n' "${missing[@]}" >&2
  rm -f -- "${catalog_file}" "${paths_file}"
  return 1
}

remove_legacy_backend_containers() {
  local container

  for container in backend-app backend-web backend-billing-worker; do
    if docker ps -a --format '{{.Names}}' | grep -qx "${container}"; then
      docker rm -f "${container}" >/dev/null 2>&1 || true
    fi
  done
}

ensure_billing_secret_keyring() {
  local mode="$1"
  local env_file="$2"
  local configured keyring_path keyring_directory managed_secret_directory
  local manager billing_runtime_gid actual_mode actual_gid directory_mode

  configured="$(normalize_env_value "$(read_env_value "${env_file}" "BILLING_SECRET_KEYRING_HOST_PATH" || true)")"
  configured="${configured:-./entorno/.secrets/billing-secret-keyring.json}"
  if [[ "${configured}" == /* ]]; then
    keyring_path="${configured}"
  else
    keyring_path="${APP_DIR}/${configured#./}"
  fi
  manager="${APP_DIR}/scripts/manage_billing_secret_keyring.php"

  if [[ ! -f "${keyring_path}" ]]; then
    if [[ "${mode}" != "qa" ]]; then
      echo "Production exige provisionar BILLING_SECRET_KEYRING_HOST_PATH desde el gestor de secretos antes del deploy." >&2
      return 1
    fi
    command -v php >/dev/null 2>&1 || {
      echo "QA necesita php para generar el keyring Billing local." >&2
      return 1
    }
    php "${manager}" init --file="${keyring_path}"
  fi

  command -v php >/dev/null 2>&1 || {
    echo "No se puede validar el keyring Billing: php no esta disponible en el host." >&2
    return 1
  }
  php "${manager}" validate --file="${keyring_path}"

  # Docker Compose implementa los secrets basados en archivos como bind
  # mounts y, por ello, ignora uid/gid/mode declarados en el compose. El
  # archivo origen debe ser realmente legible por el runtime (UID/GID 82),
  # sin abrirlo a otros usuarios del host. El directorio 0700 impide que un
  # miembro accidental del mismo GID en el host pueda recorrer hasta la KEK.
  billing_runtime_gid="${BILLING_RUNTIME_GID:-82}"
  if [[ ! "${billing_runtime_gid}" =~ ^[0-9]+$ ]]; then
    echo "BILLING_RUNTIME_GID debe ser un GID numerico." >&2
    return 1
  fi
  keyring_directory="$(dirname "${keyring_path}")"
  managed_secret_directory="${APP_DIR}/entorno/.secrets"
  if [[ -L "${keyring_path}" || -L "${keyring_directory}" ]]; then
    echo "El keyring Billing y su directorio no pueden ser enlaces simbolicos." >&2
    return 1
  fi
  if [[ "${keyring_directory}" == "${managed_secret_directory}" ]]; then
    chmod 0700 "${keyring_directory}"
  else
    directory_mode="$(stat -c '%a' "${keyring_directory}")"
    if [[ "${directory_mode}" != "700" ]]; then
      echo "El directorio externo del keyring Billing debe estar preprovisionado en modo 0700; el deploy no lo modifica." >&2
      return 1
    fi
  fi
  chgrp "${billing_runtime_gid}" "${keyring_path}"
  chmod 0440 "${keyring_path}"

  actual_mode="$(stat -c '%a' "${keyring_path}")"
  actual_gid="$(stat -c '%g' "${keyring_path}")"
  if [[ "${actual_mode}" != "440" || "${actual_gid}" != "${billing_runtime_gid}" ]]; then
    echo "El keyring Billing no quedo con permisos Compose owner:runtime-gid 0440." >&2
    return 1
  fi
  php "${manager}" validate --file="${keyring_path}"
}

ensure_commerce_billing_credentials() {
  local mode="$1"
  local env_file="$2"
  local configured registry_path manager

  configured="$(normalize_env_value "$(read_env_value "${env_file}" "BILLING_OUTBOX_CREDENTIALS_HOST_PATH" || true)")"
  configured="${configured:-./entorno/.secrets/commerce-billing-credentials.json}"
  if [[ "${configured}" == /* ]]; then
    registry_path="${configured}"
  else
    registry_path="${APP_DIR}/${configured#./}"
  fi
  manager="${APP_DIR}/scripts/manage_commerce_billing_credentials.php"

  command -v php >/dev/null 2>&1 || {
    echo "No se puede validar el registro Commerce->Billing: php no esta disponible en el host." >&2
    return 1
  }
  if [[ ! -f "${registry_path}" ]]; then
    if [[ "${mode}" != "qa" ]]; then
      echo "Production exige provisionar BILLING_OUTBOX_CREDENTIALS_HOST_PATH mediante ExternalSecret/CSI antes del deploy." >&2
      return 1
    fi
    php "${manager}" migrate-legacy-env --env-file="${env_file}" --file="${registry_path}"
  fi
  php "${manager}" validate --file="${registry_path}"
}

deploy_backend() {
  local mode="${1:-qa}"
  # Los fallbacks legacy son una decision de migracion one-shot. Capturarlos
  # antes de declarar las variables locales evita que un valor persistido en
  # .env tenga prioridad sobre la autorizacion explicita del operador.
  local runtime_ecommerce_legacy_tenant_id="${ECOMMERCE_LEGACY_TENANT_ID:-}"
  local runtime_billing_legacy_tenant_id="${BILLING_LEGACY_TENANT_ID:-}"
  local env_file
  local run_db_setup="${RUN_DB_SETUP:-${RUN_DB_BOOTSTRAP:-0}}"
  local db_admin_username=""
  local db_admin_password=""
  local db_fdw_username=""
  local db_fdw_password=""
  local db_owner_role=""
  local ecommerce_legacy_tenant_id=""
  local billing_legacy_tenant_id=""
  local tenant_rls_mode=""
  local rotate_db_app_password="${ROTATE_DB_APP_PASSWORD:-0}"
  local app_role=""
  local billing_app_role=""
  local app_database=""
  local module_database_registry="${APP_DIR}/../basesdedatos/config/module-databases.json"
  local -a app_databases=()
  local rotated_app_password=""
  local shared_db_mode=""
  local shared_db_env_file="${APP_DIR}/../basesdedatos/entorno/.env"
  local tenant_isolation_script="${APP_DIR}/../basesdedatos/scripts/tenant-isolation.sh"
  local sync_module_databases_script="${APP_DIR}/../basesdedatos/scripts/sync-module-databases.sh"

  ensure_docker_ready
  env_file="$(resolve_env_file "${mode}")"
  ensure_billing_secret_keyring "${mode}" "${env_file}"
  ensure_commerce_billing_credentials "${mode}" "${env_file}"
  prepare_backend_storage_permissions "${env_file}"
  tenant_rls_mode="$(normalize_env_value "$(read_env_value "${env_file}" "TENANT_RLS_MODE" || true)")"

  if [[ -f "${shared_db_env_file}" ]]; then
    shared_db_mode="$(env_mode_from_file "${shared_db_env_file}")"
    if [[ "${shared_db_mode}" != "${mode}" ]]; then
      echo "Ambientes incompatibles: backend=${mode}, basesdedatos=${shared_db_mode}. No se inicia ni migra el backend." >&2
      exit 1
    fi
  elif [[ "${run_db_setup}" == "1" || "${rotate_db_app_password}" == "1" ]]; then
    echo "La migracion backend exige el entorno canonico de DB: ${shared_db_env_file}." >&2
    exit 1
  fi

  if [[ "${rotate_db_app_password}" != "0" && "${rotate_db_app_password}" != "1" ]]; then
    echo "ROTATE_DB_APP_PASSWORD solo admite 0 o 1." >&2
    exit 1
  fi
  if [[ "${rotate_db_app_password}" == "1" && "${run_db_setup}" != "1" ]]; then
    echo "ROTATE_DB_APP_PASSWORD=1 exige RUN_DB_SETUP=1 para reconstruir roles, mappings y RLS." >&2
    exit 1
  fi
  if [[ "${rotate_db_app_password}" == "1" && -n "${tenant_rls_mode}" && "${tenant_rls_mode}" != "enforce" ]]; then
    echo "La rotacion/migracion integrada exige TENANT_RLS_MODE=enforce o ausente para prepararlo; no admite ${tenant_rls_mode}." >&2
    exit 1
  fi

  # Construir y validar antes de tocar roles, mappings o detener el runtime.
  (
    cd "${APP_DIR}"
    remove_legacy_backend_containers
    docker compose --env-file "${env_file}" build api
    docker compose --env-file "${env_file}" run --rm --no-deps \
      --entrypoint php api scripts/check_storage_configuration.php --quiet
    # Verifica el bind mount con el UID/GID real de la imagen antes de detener
    # runtimes o invalidar el rol DB. Evita descubrir un secreto ilegible a
    # mitad de una migracion fail-closed.
    docker compose --env-file "${env_file}" run --rm --no-deps \
      --entrypoint php api scripts/manage_billing_secret_keyring.php validate \
      --file=/run/secrets/backend/billing-secret-keyring.json
  )

  echo "Levantando backend Paramascotasec (${mode}) usando ${env_file}..."
  if [[ "${run_db_setup}" == "1" && ( "${tenant_rls_mode}" == "enforce" || -z "${tenant_rls_mode}" ) ]]; then
    if [[ ! -x "${tenant_isolation_script}" ]]; then
      echo "Falta el script canonico de aislamiento tenant: ${tenant_isolation_script}" >&2
      exit 1
    fi
    "${tenant_isolation_script}" --prepare
    tenant_rls_mode="$(normalize_env_value "$(read_env_value "${env_file}" "TENANT_RLS_MODE" || true)")"
  fi

  if [[ "${run_db_setup}" == "1" && -f "${shared_db_env_file}" ]]; then
    db_admin_username="$(normalize_env_value "$(read_env_value "${shared_db_env_file}" "POSTGRES_USER" || true)")"
    db_admin_password="$(normalize_env_value "$(read_env_value "${shared_db_env_file}" "POSTGRES_PASSWORD" || true)")"
  fi
  db_fdw_username="$(normalize_env_value "$(read_env_value "${env_file}" "DB_FDW_USERNAME" || true)")"
  db_fdw_password="$(normalize_env_value "$(read_env_value "${env_file}" "DB_FDW_PASSWORD" || true)")"
  db_owner_role="$(normalize_env_value "$(read_env_value "${env_file}" "DB_OWNER_ROLE" || true)")"
  ecommerce_legacy_tenant_id="$(normalize_env_value "$(read_env_value "${env_file}" "ECOMMERCE_LEGACY_TENANT_ID" || true)")"
  billing_legacy_tenant_id="$(normalize_env_value "$(read_env_value "${env_file}" "BILLING_LEGACY_TENANT_ID" || true)")"
  if [[ -n "${runtime_ecommerce_legacy_tenant_id}" ]]; then
    ecommerce_legacy_tenant_id="$(normalize_env_value "${runtime_ecommerce_legacy_tenant_id}")"
  fi
  if [[ -n "${runtime_billing_legacy_tenant_id}" ]]; then
    billing_legacy_tenant_id="$(normalize_env_value "${runtime_billing_legacy_tenant_id}")"
  fi
  for legacy_tenant_id in "${ecommerce_legacy_tenant_id}" "${billing_legacy_tenant_id}"; do
    if [[ -n "${legacy_tenant_id}" && ! "${legacy_tenant_id}" =~ ^[a-z0-9][a-z0-9-]{0,62}$ ]]; then
      echo "Los fallbacks tenant legacy deben ser slugs canonicos one-shot." >&2
      exit 1
    fi
  done
  tenant_rls_mode="$(normalize_env_value "$(read_env_value "${env_file}" "TENANT_RLS_MODE" || true)")"

  if [[ "${rotate_db_app_password}" == "1" ]]; then
    app_role="$(normalize_env_value "$(read_env_value "${env_file}" "DB_USERNAME" || true)")"
    billing_app_role="$(normalize_env_value "$(read_env_value "${env_file}" "DB_USERNAME_BILLING" || true)")"
    if [[ ! -r "${module_database_registry}" ]]; then
      echo "La rotacion exige el registro canonico de bases modulares: ${module_database_registry}." >&2
      exit 1
    fi
    mapfile -t app_databases < <(php -r '
      $document = json_decode((string)file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
      $names = [];
      foreach (($document["databases"] ?? []) as $entry) {
          $name = trim((string)($entry["databaseName"] ?? ""));
          if ($name !== "") { $names[$name] = true; }
      }
      $names = array_keys($names);
      sort($names, SORT_STRING);
      foreach ($names as $name) { fwrite(STDOUT, $name . PHP_EOL); }
    ' "${module_database_registry}")
    if (( ${#app_databases[@]} == 0 )); then
      echo "El registro canonico no contiene bases para verificar la rotacion." >&2
      exit 1
    fi
    for app_database in "${app_databases[@]}"; do
      if [[ ! "${app_database}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
        echo "El registro canonico contiene una base con identificador inseguro." >&2
        exit 1
      fi
    done
    if [[ -z "${db_admin_username}" || -z "${db_admin_password}" ]]; then
      echo "La rotacion DB exige POSTGRES_USER/POSTGRES_PASSWORD en ${shared_db_env_file}." >&2
      exit 1
    fi
    if [[ -z "${app_role}" || ! "${app_role}" =~ ^[A-Za-z_][A-Za-z0-9_]*$ \
      || ( -n "${billing_app_role}" && "${billing_app_role}" != "${app_role}" ) ]]; then
      echo "La rotacion integrada exige que ecommerce y Billing compartan un rol DB de aplicacion seguro." >&2
      exit 1
    fi
    if [[ ! -x "${sync_module_databases_script}" ]]; then
      echo "Falta el sincronizador DB requerido por la rotacion: ${sync_module_databases_script}" >&2
      exit 1
    fi
    command -v openssl >/dev/null 2>&1 || { echo "La rotacion DB requiere openssl." >&2; exit 1; }
    command -v python3 >/dev/null 2>&1 || { echo "La rotacion DB requiere python3." >&2; exit 1; }
  fi

  if [[ "${run_db_setup}" == "1" && ( "${tenant_rls_mode}" == "enforce" || "${rotate_db_app_password}" == "1" ) ]]; then
    compose_cmd "${env_file}" stop api http sri-worker wallet-notify-worker commerce-billing-worker mailer-worker >/dev/null
  fi

  if [[ "${rotate_db_app_password}" == "1" ]]; then
    # El trap EXIT se ejecuta despues de desmontar el scope local de
    # deploy_backend(). El contexto del guard debe vivir deliberadamente en
    # variables globales no exportadas; de otro modo el guard ve valores
    # vacios y no invalida el rol tras un fallo tardio de bootstrap.
    BACKEND_ROTATION_GUARD_ACTIVE=1
    BACKEND_ROTATION_GUARD_ENV_FILE="${env_file}"
    BACKEND_ROTATION_GUARD_ADMIN_USERNAME="${db_admin_username}"
    BACKEND_ROTATION_GUARD_ADMIN_PASSWORD="${db_admin_password}"
    BACKEND_ROTATION_GUARD_APP_ROLE="${app_role}"
    trap 'rotation_status=$?; backend_rotation_exit_guard; exit "${rotation_status}"' EXIT

    # Invalidar primero el secreto expuesto. Desde este punto una falla queda
    # cerrada: nunca se escribe ni reactiva el password anterior.
    disable_backend_database_role "${db_admin_username}" "${db_admin_password}" "${app_role}"
    rotated_app_password="$(openssl rand -hex 32)"
    if [[ "${rotated_app_password}" == "${db_admin_password}" ]]; then
      echo "La credencial DB de aplicacion generada no puede coincidir con la administradora." >&2
      exit 1
    fi
    atomic_replace_backend_database_passwords "${env_file}" "${rotated_app_password}"
    echo "Credencial DB de aplicacion reemplazada atomicamente; valor oculto."
  fi

  if [[ "${run_db_setup}" == "1" && -x "${sync_module_databases_script}" ]]; then
    (
      cd "${APP_DIR}/../basesdedatos"
      ./scripts/sync-module-databases.sh
    )
  fi

  if [[ "${rotate_db_app_password}" == "1" ]]; then
    for app_database in "${app_databases[@]}"; do
      verify_rotated_backend_database_role "${app_role}" "${rotated_app_password}" "${app_database}"
    done
    echo "Rol DB de aplicacion validado en todas las bases modulares con la credencial nueva; valor oculto."
  fi

  (
    cd "${APP_DIR}"

    if [[ "${run_db_setup}" == "1" ]]; then
      # Las credenciales privilegiadas entran por stdin. No usar `compose run
      # -e ...PASSWORD`: esos valores quedan observables en Config.Env mediante
      # docker inspect durante toda la vida del contenedor one-shot.
      {
        printf '%s\n' "${db_admin_password}"
        printf '%s\n' "${db_fdw_password}"
      } | DB_ADMIN_USERNAME="${db_admin_username}" \
        DB_FDW_USERNAME="${db_fdw_username}" \
        DB_OWNER_ROLE="${db_owner_role}" \
        ECOMMERCE_LEGACY_TENANT_ID="${ecommerce_legacy_tenant_id}" \
        BILLING_LEGACY_TENANT_ID="${billing_legacy_tenant_id}" \
        TENANT_RLS_MODE="${tenant_rls_mode}" \
        docker compose --env-file "${env_file}" run --rm --no-deps -T \
          -e DB_ADMIN_USERNAME \
          -e DB_FDW_USERNAME \
          -e DB_OWNER_ROLE \
          -e ECOMMERCE_LEGACY_TENANT_ID \
          -e BILLING_LEGACY_TENANT_ID \
          -e TENANT_RLS_MODE \
          --entrypoint /bin/sh api -c \
          'set -eu
           umask 077
           IFS= read -r DB_ADMIN_PASSWORD
           IFS= read -r DB_FDW_PASSWORD
           if [ -z "$DB_ADMIN_PASSWORD" ] || [ -z "$DB_FDW_PASSWORD" ]; then
             echo "Faltan credenciales privilegiadas por stdin para bootstrap." >&2
             exit 1
           fi
           export DB_ADMIN_PASSWORD DB_FDW_PASSWORD
           php scripts/bootstrap_module_databases.php
           unset DB_ADMIN_PASSWORD DB_FDW_PASSWORD'

      if [[ "${tenant_rls_mode}" == "enforce" ]]; then
        "${tenant_isolation_script}" --apply
      fi
    fi

    # TENANT_RLS_MODE=enforce es una promesa sobre el estado real de todas las
    # bases, no solo una variable de entorno. Cada arranque (incluidos deploys
    # sin bootstrap) debe revalidarla. Ante drift o migracion incompleta se
    # detienen todos los runtimes en lugar de arrancar/continuar ambiguamente.
    if [[ "${tenant_rls_mode}" == "enforce" ]]; then
      if ! "${tenant_isolation_script}" --check; then
        docker compose --env-file "${env_file}" stop api http sri-worker wallet-notify-worker commerce-billing-worker mailer-worker >/dev/null 2>&1 || true
        echo "Aislamiento tenant no atestado; runtimes backend detenidos." >&2
        exit 1
      fi
    fi

    # Contract phase cannot start on plaintext or an unvalidated V002 schema.
    # Expand mode is explicit in the environment and still writes ciphertext.
    docker compose --env-file "${env_file}" run --rm --no-deps \
      --entrypoint php api scripts/check_billing_secret_runtime_readiness.php

    docker compose --env-file "${env_file}" up -d --force-recreate --remove-orphans api http sri-worker wallet-notify-worker commerce-billing-worker mailer-worker
  )
  wait_for_container_state backend-api
  wait_for_container_state backend-http
  wait_for_container_state backend-sri-worker
  wait_for_container_state backend-wallet-notify-worker
  wait_for_container_state backend-commerce-billing-worker
  wait_for_container_state backend-mailer-worker
  assert_backend_mode "${env_file}"
  verify_local_catalog_uploads "${env_file}"
  compose_cmd "${env_file}" ps
  if [[ "${rotate_db_app_password}" == "1" ]]; then
    BACKEND_ROTATION_GUARD_ACTIVE=0
    unset \
      BACKEND_ROTATION_GUARD_ENV_FILE \
      BACKEND_ROTATION_GUARD_ADMIN_USERNAME \
      BACKEND_ROTATION_GUARD_ADMIN_PASSWORD \
      BACKEND_ROTATION_GUARD_APP_ROLE
    trap - EXIT
  fi
  echo "Backend Paramascotasec (${mode}) listo"
}
