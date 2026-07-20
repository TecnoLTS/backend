#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${BACKEND_ENV_FILE:-${APP_DIR}/entorno/.env}"
receipt_file="${1:-}"

if [[ "$#" -ne 1 || ! -r "${receipt_file}" ]]; then
  echo "Uso: $0 receipt.json" >&2
  exit 64
fi
if [[ ! -r "${ENV_FILE}" ]]; then
  echo "No se puede leer la configuracion backend activa." >&2
  exit 1
fi

read_env_value() {
  local key="$1" line value
  line="$(awk -v key="${key}" -F= '$0 !~ /^[[:space:]]*#/ && $1 == key { print; exit }' "${ENV_FILE}" 2>/dev/null || true)"
  value="${line#*=}"
  value="${value%$'\r'}"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  value="${value%\"}"; value="${value#\"}"
  value="${value%\'}"; value="${value#\'}"
  printf '%s' "${value}"
}

identity_password="$(read_env_value DB_WORKER_PASSWORD_IDENTITY_PLATFORM)"
if [[ -z "${identity_password}" ]]; then
  echo "Falta la credencial del reconciliador IdentityPlatform." >&2
  exit 1
fi

{
  printf '%s\n' "${identity_password}"
  cat "${receipt_file}"
} | docker compose \
  --env-file "${ENV_FILE}" \
  -f "${APP_DIR}/docker-compose.yml" \
  run --rm --no-deps -T tenant-reconcile-worker
unset identity_password
