#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  echo "Uso: $0"
  echo "El ambiente activo sale de entorno/.env (ENTORNO_MODE=qa|production)."
  exit 0
fi

if [[ "$#" -ne 0 ]]; then
  echo "Uso: $0" >&2
  echo "El ambiente activo sale de entorno/.env (ENTORNO_MODE=qa|production)." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE_ENV_MODE="${SCRIPT_DIR}/../../scripts/env-mode.sh"
# shellcheck disable=SC1090
source "${WORKSPACE_ENV_MODE}"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/common.sh"

mode="$(env_mode_from_file "${SCRIPT_DIR}/../entorno/.env")"
deploy_backend "${mode}"
