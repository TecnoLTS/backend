#!/usr/bin/env bash
set -euo pipefail

mode="${1:-development}"
if [[ "${mode}" != "development" && "${mode}" != "production" ]]; then
  echo "Uso: $0 [development|production]" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/common.sh"

deploy_backend "${mode}"
