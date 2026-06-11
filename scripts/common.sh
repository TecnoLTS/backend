#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
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

  if ! docker network inspect paramascotasec-web-internal >/dev/null 2>&1; then
    docker network create --internal paramascotasec-web-internal >/dev/null
  fi

  if ! docker network inspect paramascotasec-db-internal >/dev/null 2>&1; then
    docker network create --internal paramascotasec-db-internal >/dev/null
  fi

  if ! docker network inspect paramascotasec-services-internal >/dev/null 2>&1; then
    docker network create --internal paramascotasec-services-internal >/dev/null
  fi
}

upsert_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"

  python3 - "$file" "$key" "$value" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
key = sys.argv[2]
value = sys.argv[3]

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

  for suffix in "" ".development" ".production" ".local"; do
    path="${APP_DIR}/${env_name}${suffix}"
    if [[ -e "${path}" ]]; then
      found+=("${path#${APP_DIR}/}")
    fi
  done

  if (( ${#found[@]} > 0 )); then
    printf 'Rutas legacy fuera de entorno/ detectadas en paramascotasec-backend: %s\n' "${found[*]}" >&2
    printf 'Ejecuta scripts/migrate-entorno.sh o mueve esos archivos a un backup externo antes de desplegar.\n' >&2
    exit 1
  fi
}

assert_entorno_mode() {
  local expected="$1"
  local actual

  actual="$(read_env_value "${ENTORNO_ENV_FILE}" "ENTORNO_MODE" || true)"
  actual="$(normalize_env_value "${actual}")"

  if [[ "${actual}" != "${expected}" ]]; then
    echo "ENTORNO_MODE=${actual:-<vacio>} en ${ENTORNO_ENV_FILE}; esperado ${expected}." >&2
    exit 1
  fi
}

resolve_env_file() {
  local mode="${1:-development}"
  local env_file="${ENTORNO_ENV_FILE}"
  local primary_domain primary_aliases public_scheme tenant_slug public_base_url tenant_name gateway_env

  if [[ "${mode}" != "development" && "${mode}" != "production" ]]; then
    echo "Modo invalido: ${mode}. Usa development o production." >&2
    exit 1
  fi

  assert_no_legacy_runtime_paths
  ensure_entorno_files
  assert_entorno_mode "${mode}"

  gateway_env="${APP_DIR}/../Gateway/entorno/.env"
  tenant_slug="$(normalize_env_value "$(read_env_value "${env_file}" "PUBLIC_TENANT_SLUG" || true)")"
  tenant_slug="${tenant_slug:-$(normalize_env_value "$(read_env_value "${env_file}" "DEFAULT_TENANT" || true)")}"
  tenant_slug="${tenant_slug:-paramascotasec}"
  primary_domain="$(normalize_env_value "$(read_env_value "${env_file}" "PRIMARY_SITE_DOMAIN" || true)")"
  primary_domain="${primary_domain:-paramascotasec.com}"
  primary_aliases="$(normalize_env_value "$(read_env_value "${env_file}" "PRIMARY_SITE_ALIASES" || true)")"
  primary_aliases="${primary_aliases:-www.${primary_domain}}"
  public_scheme="$(normalize_env_value "$(read_env_value "${env_file}" "PUBLIC_SCHEME" || true)")"
  public_scheme="${public_scheme:-https}"
  if [[ -f "${gateway_env}" ]]; then
    tenant_slug="$(normalize_env_value "$(read_env_value "${gateway_env}" "PUBLIC_TENANT_SLUG" || true)")"
    tenant_slug="${tenant_slug:-paramascotasec}"
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

  upsert_env_value "${env_file}" "DEFAULT_TENANT" "${tenant_slug}"
  upsert_env_value "${env_file}" "PUBLIC_TENANT_SLUG" "${tenant_slug}"
  upsert_env_value "${env_file}" "PUBLIC_SCHEME" "${public_scheme}"
  upsert_env_value "${env_file}" "PRIMARY_SITE_DOMAIN" "${primary_domain}"
  upsert_env_value "${env_file}" "PRIMARY_SITE_ALIASES" "${primary_aliases}"
  upsert_env_value "${env_file}" "PUBLIC_BASE_URL" "${public_base_url}"
  upsert_env_value "${env_file}" "TENANT_DISPLAY_NAME" "${tenant_name}"

  if [[ "${mode}" == "development" ]]; then
    local backend_bind_ip backend_port backend_public_scheme backend_public_host app_url
    backend_bind_ip="${BACKEND_BIND_IP:-$(read_env_value "${env_file}" "BACKEND_BIND_IP")}"
    backend_bind_ip="${backend_bind_ip:-0.0.0.0}"
    backend_port="${BACKEND_PORT:-$(read_env_value "${env_file}" "BACKEND_PORT")}"
    backend_port="${backend_port:-8080}"
    backend_public_scheme="${BACKEND_PUBLIC_SCHEME:-$(read_env_value "${env_file}" "BACKEND_PUBLIC_SCHEME")}"
    backend_public_scheme="${backend_public_scheme:-http}"
    backend_public_host="${BACKEND_PUBLIC_HOST:-$(read_env_value "${env_file}" "BACKEND_PUBLIC_HOST")}"
    backend_public_host="${backend_public_host:-$(detect_development_public_host)}"
    app_url="${public_base_url}"

    upsert_env_value "${env_file}" "APP_ENV" "development"
    upsert_env_value "${env_file}" "BACKEND_BIND_IP" "${backend_bind_ip}"
    upsert_env_value "${env_file}" "BACKEND_PORT" "${backend_port}"
    upsert_env_value "${env_file}" "BACKEND_PUBLIC_SCHEME" "${backend_public_scheme}"
    upsert_env_value "${env_file}" "BACKEND_PUBLIC_HOST" "${backend_public_host}"
    upsert_env_value "${env_file}" "APP_URL" "${app_url}"
    upsert_env_value "${env_file}" "ADMIN_IP_MODE" "private"
    upsert_env_value "${env_file}" "ADMIN_IP_ALLOWLIST" ""
    upsert_env_value "${env_file}" "TRUST_PROXY_HEADERS" "false"
    upsert_env_value "${env_file}" "SRI_ENVIRONMENT" "pruebas"
    upsert_env_value "${env_file}" "FACTURADOR_API_URL" "http://facturador:8080"
    upsert_env_value "${env_file}" "FACTURADOR_API_INVOICES_PATH" "/api/test/v1/invoices"

    printf '%s\n' "${env_file}"
    return 0
  fi

  local app_url
  app_url="${APP_URL:-$(read_env_value "${env_file}" "APP_URL")}"
  if [[ -z "${app_url}" || "${app_url}" == http://localhost* || "${app_url}" == http://127.0.0.1* ]]; then
    app_url="${public_base_url}"
  fi

  upsert_env_value "${env_file}" "APP_ENV" "production"
  upsert_env_value "${env_file}" "APP_URL" "${app_url%/}"
  upsert_env_value "${env_file}" "TRUST_PROXY_HEADERS" "false"
  upsert_env_value "${env_file}" "SRI_ENVIRONMENT" "produccion"
  upsert_env_value "${env_file}" "FACTURADOR_API_URL" "http://facturador:8080"
  upsert_env_value "${env_file}" "FACTURADOR_API_INVOICES_PATH" "/api/production/v1/invoices"
  printf '%s\n' "${env_file}"
  return 0
}

detect_development_public_host() {
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

compose_cmd() {
  local env_file="$1"
  shift

  (
    cd "${APP_DIR}"
    docker compose --env-file "${env_file}" "$@"
  )
}

assert_backend_mode() {
  local mode="${1:-development}"
  local container_env

  container_env="$(docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' paramascotasec-backend-app 2>/dev/null | awk -F= '/^APP_ENV=/{print $2; exit}')"
  if [[ "${container_env}" != "${mode}" ]]; then
    echo "El backend quedo levantado con APP_ENV=${container_env:-desconocido}, esperado ${mode}" >&2
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

deploy_backend() {
  local mode="${1:-development}"
  local env_file
  local run_db_setup="${RUN_DB_SETUP:-${RUN_DB_BOOTSTRAP:-0}}"

  ensure_docker_ready
  env_file="$(resolve_env_file "${mode}")"

  echo "Levantando backend Paramascotasec en ${mode} usando ${env_file}..."
  (
    cd "${APP_DIR}"

    local facturador_path
    facturador_path="$(read_env_value "${env_file}" "FACTURADOR_API_INVOICES_PATH")"
    if [[ -z "${facturador_path}" ]]; then
      if [[ "${mode}" == "development" ]]; then
        facturador_path="/api/test/v1/invoices"
      else
        facturador_path="/api/production/v1/invoices"
      fi
    fi

    APP_ENV="${mode}" \
      RUN_DB_SETUP="${run_db_setup}" \
      RUN_DB_BOOTSTRAP="${run_db_setup}" \
      FACTURADOR_API_INVOICES_PATH="${facturador_path}" \
      docker compose --env-file "${env_file}" up -d --build --force-recreate --remove-orphans app web
  )
  wait_for_container_state paramascotasec-backend-app
  wait_for_container_state paramascotasec-backend-web
  assert_backend_mode "${mode}"
  compose_cmd "${env_file}" ps
  echo "Backend Paramascotasec ${mode} listo"
}
