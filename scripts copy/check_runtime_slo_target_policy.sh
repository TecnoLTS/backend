#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SLO_CHECK="${SCRIPT_DIR}/check_runtime_slo.sh"
tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT

write_env() {
  local path="$1"
  shift
  printf '%s\n' "$@" >"${path}"
}

run_preflight() {
  local backend_env="$1" gateway_env="$2" resolve_ip="$3"
  env -u ENTORNO_MODE -u APP_ENV -u GATEWAY_ENV \
    BACKEND_ENV_FILE="${backend_env}" \
    GATEWAY_ENV_FILE="${gateway_env}" \
    RESOLVE_IP="${resolve_ip}" \
    "${SLO_CHECK}" --preflight 2>&1
}

qa_backend="${tmp_dir}/backend-qa.env"
qa_gateway="${tmp_dir}/gateway-qa.env"
invalid_gateway="${tmp_dir}/gateway-invalid.env"
production_backend="${tmp_dir}/backend-production.env"
production_gateway="${tmp_dir}/gateway-production.env"

write_env "${qa_backend}" 'ENTORNO_MODE=qa' 'APP_ENV=qa'
write_env "${qa_gateway}" 'GATEWAY_ENV=qa' 'GATEWAY_BIND_IP=192.0.2.10'
write_env "${invalid_gateway}" 'GATEWAY_ENV=qa' 'GATEWAY_BIND_IP=0.0.0.0'
write_env "${production_backend}" 'ENTORNO_MODE=production' 'APP_ENV=production'
write_env "${production_gateway}" 'GATEWAY_ENV=production'

output="$(run_preflight "${qa_backend}" "${qa_gateway}" '')"
grep -Fq 'target_mode=qa_gateway_bind' <<<"${output}" || {
  echo 'QA no derivo el bind local del gateway.' >&2
  exit 1
}

if output="$(run_preflight "${qa_backend}" "${invalid_gateway}" '')"; then
  echo 'QA acepto ejecutar el SLO sin un destino local valido.' >&2
  exit 1
fi
grep -Fq 'no se medira DNS externo' <<<"${output}" || {
  echo 'El fallo cerrado QA no explica la politica de resolucion.' >&2
  exit 1
}
if grep -Fq '0.0.0.0' <<<"${output}"; then
  echo 'El preflight no debe reflejar el valor de configuracion rechazado.' >&2
  exit 1
fi

output="$(run_preflight "${production_backend}" "${production_gateway}" '')"
grep -Fq 'target_mode=public_dns' <<<"${output}" || {
  echo 'Produccion no conservo la resolucion DNS publica.' >&2
  exit 1
}

output="$(run_preflight "${qa_backend}" "${invalid_gateway}" '198.51.100.10')"
grep -Fq 'target_mode=explicit_resolve' <<<"${output}" || {
  echo 'Un RESOLVE_IP QA explicito y valido no tuvo precedencia.' >&2
  exit 1
}

echo 'Runtime SLO target policy: OK'
