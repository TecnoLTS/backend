#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-}"
if [[ "${MODE}" != "development" && "${MODE}" != "production" ]]; then
  echo "Uso: $0 development|production" >&2
  exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENTORNO_DIR="${APP_DIR}/entorno"
ENTORNO_ENV_FILE="${ENTORNO_DIR}/.env"
ENTORNO_SERVER_FILE="${ENTORNO_DIR}/servidor.env"
TEMPLATE_ENTORNO_DIR="${APP_DIR}/templates/entorno"
BACKUP_DIR="/home/admincenter/secure-backups/entorno-migration/paramascotasec-backend/$(date -u +%Y%m%dT%H%M%SZ)"

backup_legacy_path() {
  local path="$1"
  [[ -e "${path}" ]] || return 0
  mkdir -p "${BACKUP_DIR}"
  mv "${path}" "${BACKUP_DIR}/$(basename "${path}")"
  echo "Se movio ${path#${APP_DIR}/} a ${BACKUP_DIR}/"
}

upsert_env_value() {
  local file="$1" key="$2" value="$3"

  python3 - "$file" "$key" "$value" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
key = sys.argv[2]
value = sys.argv[3]
lines = path.read_text().splitlines() if path.exists() else []
for index, line in enumerate(lines):
    if line.startswith(f"{key}="):
        lines[index] = f"{key}={value}"
        break
else:
    lines.append(f"{key}={value}")
path.write_text("\n".join(lines) + "\n")
PY
}

umask 077
mkdir -p "${ENTORNO_DIR}"

source_env=""
if [[ "${MODE}" == "development" && -f "${APP_DIR}/.env.development" ]]; then
  source_env="${APP_DIR}/.env.development"
elif [[ "${MODE}" == "production" && -f "${APP_DIR}/.env" ]]; then
  source_env="${APP_DIR}/.env"
elif [[ -f "${APP_DIR}/.env" ]]; then
  source_env="${APP_DIR}/.env"
fi

if [[ -n "${source_env}" && ! -f "${ENTORNO_ENV_FILE}" ]]; then
  mv "${source_env}" "${ENTORNO_ENV_FILE}"
elif [[ ! -f "${ENTORNO_ENV_FILE}" ]]; then
  cp "${TEMPLATE_ENTORNO_DIR}/.env.example" "${ENTORNO_ENV_FILE}"
  chmod 600 "${ENTORNO_ENV_FILE}"
  echo "Se creo entorno/.env desde la plantilla; completa valores reales antes de desplegar." >&2
fi

if [[ ! -f "${ENTORNO_SERVER_FILE}" ]]; then
  cp "${TEMPLATE_ENTORNO_DIR}/servidor.env.example" "${ENTORNO_SERVER_FILE}"
fi
upsert_env_value "${ENTORNO_SERVER_FILE}" "ENTORNO_MODE" "${MODE}"
chmod 600 "${ENTORNO_SERVER_FILE}"

backup_legacy_path "${APP_DIR}/.env"
backup_legacy_path "${APP_DIR}/.env.development"
backup_legacy_path "${APP_DIR}/.env.production"
backup_legacy_path "${APP_DIR}/.env.local"

echo "Migracion entorno completada para paramascotasec-backend (${MODE})."
