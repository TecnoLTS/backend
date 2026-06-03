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
  cp "${ENTORNO_DIR}/.env.example" "${ENTORNO_ENV_FILE}"
  chmod 600 "${ENTORNO_ENV_FILE}"
  echo "Se creo entorno/.env desde la plantilla; completa valores reales antes de desplegar." >&2
fi

printf 'ENTORNO_MODE=%s\n' "${MODE}" > "${ENTORNO_SERVER_FILE}"
chmod 600 "${ENTORNO_SERVER_FILE}"

rm -f "${APP_DIR}/.env" "${APP_DIR}/.env.development" "${APP_DIR}/.env.production" "${APP_DIR}/.env.local"

echo "Migracion entorno completada para paramascotasec-backend (${MODE})."
