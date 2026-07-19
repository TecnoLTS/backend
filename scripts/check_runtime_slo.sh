#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORKSPACE_DIR="$(cd "${APP_DIR}/.." && pwd)"
BACKEND_ENV_FILE="${BACKEND_ENV_FILE:-${APP_DIR}/entorno/.env}"
GATEWAY_ENV_FILE="${GATEWAY_ENV_FILE:-${WORKSPACE_DIR}/gatewayapisix/entorno/.env}"
BASE_URL="${BASE_URL:-https://paramascotasec.com}"
RESOLVE_IP="${RESOLVE_IP:-}"
CA_CERT="${CA_CERT:-}"
TENANT_SLUG="${TENANT_SLUG:-${PUBLIC_TENANT_SLUG:-paramascotasec}}"
SLO_REQUESTS="${SLO_REQUESTS:-20}"
SLO_CONCURRENCY="${SLO_CONCURRENCY:-5}"
SLO_REQUEST_INTERVAL_MS="${SLO_REQUEST_INTERVAL_MS:-125}"
SLO_MIN_SUCCESS_PERCENT="${SLO_MIN_SUCCESS_PERCENT:-100}"
SLO_HEALTH_P95_MAX_SECONDS="${SLO_HEALTH_P95_MAX_SECONDS:-0.5}"
SLO_HEALTH_P99_MAX_SECONDS="${SLO_HEALTH_P99_MAX_SECONDS:-1.0}"
SLO_HOME_P95_MAX_SECONDS="${SLO_HOME_P95_MAX_SECONDS:-2.0}"
SLO_HOME_P99_MAX_SECONDS="${SLO_HOME_P99_MAX_SECONDS:-3.0}"
SLO_CATALOG_P95_MAX_SECONDS="${SLO_CATALOG_P95_MAX_SECONDS:-1.0}"
SLO_CATALOG_P99_MAX_SECONDS="${SLO_CATALOG_P99_MAX_SECONDS:-2.0}"
SLO_HOME_MAX_BYTES="${SLO_HOME_MAX_BYTES:-350000}"
SLO_CATALOG_MAX_BYTES="${SLO_CATALOG_MAX_BYTES:-225000}"
REQUIRED_RUNNING_CONTAINERS="${REQUIRED_RUNNING_CONTAINERS:-apisix-gateway apisix-etcd basesdedatos backend-api backend-http backend-sri-worker backend-commerce-billing-worker backend-mailer-worker backend-wallet-notify-worker webparamascotas dashboard}"
REQUIRED_HEALTHY_CONTAINERS="${REQUIRED_HEALTHY_CONTAINERS:-apisix-gateway apisix-etcd basesdedatos backend-http backend-sri-worker backend-commerce-billing-worker backend-mailer-worker backend-wallet-notify-worker webparamascotas dashboard}"
SLO_POSTGRES_CONNECTION_UTILIZATION_MAX_PERCENT="${SLO_POSTGRES_CONNECTION_UTILIZATION_MAX_PERCENT:-80}"
SLO_MAILER_DEAD_LETTER_MAX="${SLO_MAILER_DEAD_LETTER_MAX:-0}"
SLO_COMMERCE_BILLING_DEAD_LETTER_MAX="${SLO_COMMERCE_BILLING_DEAD_LETTER_MAX:-0}"
ACTIVE_ENVIRONMENT=''
TARGET_RESOLUTION_MODE=''

read_env_value() {
  local file="$1" key="$2" line value
  [[ -r "${file}" ]] || return 0
  line="$(awk -v key="${key}" -F= '$0 !~ /^[[:space:]]*#/ && $1 == key { print; exit }' "${file}" 2>/dev/null || true)"
  [[ -n "${line}" ]] || return 0
  value="${line#*=}"
  value="${value%$'\r'}"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  printf '%s' "${value}"
}

valid_ipv4() {
  local value="$1" octet
  local -a octets
  [[ "${value}" =~ ^([0-9]{1,3}[.]){3}[0-9]{1,3}$ ]] || return 1
  IFS='.' read -r -a octets <<<"${value}"
  for octet in "${octets[@]}"; do
    (( 10#${octet} <= 255 )) || return 1
  done
}

resolve_active_environment() {
  local mode
  mode="${ENTORNO_MODE:-${APP_ENV:-}}"
  if [[ -z "${mode}" ]]; then
    mode="$(read_env_value "${BACKEND_ENV_FILE}" ENTORNO_MODE)"
  fi
  if [[ -z "${mode}" ]]; then
    mode="$(read_env_value "${BACKEND_ENV_FILE}" APP_ENV)"
  fi
  if [[ -z "${mode}" ]]; then
    mode="$(read_env_value "${GATEWAY_ENV_FILE}" GATEWAY_ENV)"
  fi
  mode="${mode,,}"
  case "${mode}" in
    qa) printf 'qa' ;;
    production|prod) printf 'production' ;;
    *)
      echo 'No se pudo determinar ENTORNO_MODE=qa|production para el SLO runtime.' >&2
      return 1
      ;;
  esac
}

configure_public_target() {
  ACTIVE_ENVIRONMENT="$(resolve_active_environment)" || return 1
  if [[ -n "${RESOLVE_IP}" ]]; then
    if ! valid_ipv4 "${RESOLVE_IP}"; then
      echo 'RESOLVE_IP debe ser una IPv4 valida.' >&2
      return 1
    fi
    TARGET_RESOLUTION_MODE='explicit_resolve'
    return 0
  fi

  if [[ "${ACTIVE_ENVIRONMENT}" == 'qa' ]]; then
    RESOLVE_IP="$(read_env_value "${GATEWAY_ENV_FILE}" GATEWAY_BIND_IP)"
    if ! valid_ipv4 "${RESOLVE_IP}" || [[ "${RESOLVE_IP}" == '0.0.0.0' ]]; then
      echo 'QA exige RESOLVE_IP explicita o GATEWAY_BIND_IP local valido; no se medira DNS externo.' >&2
      return 1
    fi
    TARGET_RESOLUTION_MODE='qa_gateway_bind'
    return 0
  fi

  TARGET_RESOLUTION_MODE='public_dns'
}

validate_configuration() {
  local value label
  for label in SLO_REQUESTS SLO_CONCURRENCY; do
    value="${!label}"
    if [[ ! "${value}" =~ ^[1-9][0-9]*$ ]]; then
      echo "${label} debe ser un entero positivo." >&2
      return 1
    fi
  done
  if [[ ! "${SLO_REQUEST_INTERVAL_MS}" =~ ^[0-9]+$ ]]; then
    echo "SLO_REQUEST_INTERVAL_MS debe ser un entero no negativo." >&2
    return 1
  fi
  if [[ ! "${SLO_MIN_SUCCESS_PERCENT}" =~ ^[0-9]+([.][0-9]+)?$ ]] \
    || ! awk -v value="${SLO_MIN_SUCCESS_PERCENT}" 'BEGIN {exit(value >= 0 && value <= 100 ? 0 : 1)}'; then
    echo "SLO_MIN_SUCCESS_PERCENT debe estar entre 0 y 100." >&2
    return 1
  fi
  for label in SLO_HEALTH_P95_MAX_SECONDS SLO_HEALTH_P99_MAX_SECONDS \
    SLO_HOME_P95_MAX_SECONDS SLO_HOME_P99_MAX_SECONDS \
    SLO_CATALOG_P95_MAX_SECONDS SLO_CATALOG_P99_MAX_SECONDS \
    SLO_POSTGRES_CONNECTION_UTILIZATION_MAX_PERCENT; do
    value="${!label}"
    if [[ ! "${value}" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
      echo "${label} debe ser un numero no negativo." >&2
      return 1
    fi
  done
  for label in SLO_HOME_MAX_BYTES SLO_CATALOG_MAX_BYTES; do
    value="${!label}"
    if [[ ! "${value}" =~ ^[1-9][0-9]*$ ]]; then
      echo "${label} debe ser un entero positivo." >&2
      return 1
    fi
  done
  for label in SLO_MAILER_DEAD_LETTER_MAX SLO_COMMERCE_BILLING_DEAD_LETTER_MAX; do
    value="${!label}"
    if [[ ! "${value}" =~ ^[0-9]+$ ]]; then
      echo "${label} debe ser un entero no negativo." >&2
      return 1
    fi
  done
  for command in bash curl docker php awk grep; do
    command -v "${command}" >/dev/null 2>&1 || {
      echo "Falta comando requerido para SLO: ${command}" >&2
      return 1
    }
  done
  [[ -x "${SCRIPT_DIR}/collect_runtime_metrics.sh" \
    && -x "${SCRIPT_DIR}/benchmark-api.sh" \
    && -x "${SCRIPT_DIR}/run_sustained_mixed_load.sh" ]] || {
    echo "Los scripts de metricas/benchmark/carga sostenida deben ser ejecutables." >&2
    return 1
  }
}

configure_public_target

if [[ "${1:-}" == "--preflight" ]]; then
  validate_configuration
  bash -n "${SCRIPT_DIR}/collect_runtime_metrics.sh"
  bash -n "${SCRIPT_DIR}/benchmark-api.sh"
  bash -n "${SCRIPT_DIR}/run_sustained_mixed_load.sh"
  echo "SLO runtime preflight: OK target_mode=${TARGET_RESOLUTION_MODE}"
  exit 0
fi
if [[ "$#" -ne 0 ]]; then
  echo "Uso: ./scripts/check_runtime_slo.sh [--preflight]" >&2
  exit 2
fi
validate_configuration

tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT
metrics_file="${tmp_dir}/runtime.prom"
BASE_URL="${BASE_URL}" TENANT_SLUG="${TENANT_SLUG}" RESOLVE_IP="${RESOLVE_IP}" \
  CA_CERT="${CA_CERT}" OUTPUT_FILE="${metrics_file}" \
  "${SCRIPT_DIR}/collect_runtime_metrics.sh" >/dev/null

failed=0
if ! grep -Fqx 'paramascotas_collector_profile{profile="full"} 1' "${metrics_file}"; then
  echo 'ALERT runtime_slo_requires_full_collector_profile' >&2
  failed=1
fi
for container in ${REQUIRED_RUNNING_CONTAINERS}; do
  if ! grep -Fqx "paramascotas_container_up{name=\"${container}\"} 1" "${metrics_file}"; then
    echo "ALERT container_not_running name=${container}" >&2
    failed=1
  fi
done
for container in ${REQUIRED_HEALTHY_CONTAINERS}; do
  if ! grep -Fqx "paramascotas_container_health{name=\"${container}\",status=\"healthy\"} 1" "${metrics_file}"; then
    echo "ALERT container_not_healthy name=${container}" >&2
    failed=1
  fi
done
if ! grep -Fqx 'paramascotas_php_fpm_scrape_success 1' "${metrics_file}"; then
  echo 'ALERT php_fpm_metrics_unavailable' >&2
  failed=1
fi
if ! grep -Fqx 'paramascotas_docker_stats_scrape_success 1' "${metrics_file}"; then
  echo 'ALERT docker_stats_metrics_unavailable' >&2
  failed=1
fi
if ! grep -Fqx 'paramascotas_postgres_scrape_success 1' "${metrics_file}"; then
  echo 'ALERT postgres_metrics_unavailable' >&2
  failed=1
fi
postgres_scrape_attempts="$(awk '$1 == "paramascotas_postgres_scrape_attempts" {print $2; exit}' "${metrics_file}")"
if [[ ! "${postgres_scrape_attempts}" =~ ^[1-3]$ ]]; then
  echo "ALERT postgres_scrape_attempts_invalid actual=${postgres_scrape_attempts:-missing}" >&2
  failed=1
fi
if ! grep -Eq '^paramascotas_postgres_scrape_transport\{method="(netns_psql|docker_exec)"\} 1$' "${metrics_file}"; then
  echo 'ALERT postgres_scrape_transport_missing' >&2
  failed=1
fi
for outbox_scrape in \
  'paramascotasec_mailer_outbox_scrape_success 1' \
  'paramascotasec_commerce_billing_outbox_scrape_success 1'; do
  if ! grep -Fqx "${outbox_scrape}" "${metrics_file}"; then
    echo "ALERT outbox_metrics_unavailable metric=${outbox_scrape%% *}" >&2
    failed=1
  fi
done
mailer_dead_letter="$(awk '$1 == "paramascotasec_mailer_outbox_dead_letter" {print $2; exit}' "${metrics_file}")"
if [[ ! "${mailer_dead_letter}" =~ ^[0-9]+$ ]] || (( mailer_dead_letter > SLO_MAILER_DEAD_LETTER_MAX )); then
  echo "ALERT mailer_unresolved_dead_letter actual=${mailer_dead_letter:-missing} maximum=${SLO_MAILER_DEAD_LETTER_MAX}" >&2
  failed=1
fi
commerce_billing_dead_letter="$(awk '$1 == "paramascotasec_commerce_billing_outbox_dead_letter" {print $2; exit}' "${metrics_file}")"
if [[ ! "${commerce_billing_dead_letter}" =~ ^[0-9]+$ ]] || (( commerce_billing_dead_letter > SLO_COMMERCE_BILLING_DEAD_LETTER_MAX )); then
  echo "ALERT commerce_billing_unresolved_dead_letter actual=${commerce_billing_dead_letter:-missing} maximum=${SLO_COMMERCE_BILLING_DEAD_LETTER_MAX}" >&2
  failed=1
fi
for healthy_metric in \
  'paramascotas_postgres_lock_waiters 0' \
  'paramascotas_postgres_ungranted_locks 0' \
  'paramascotas_postgres_long_transactions 0'; do
  if ! grep -Fqx "${healthy_metric}" "${metrics_file}"; then
    echo "ALERT postgres_runtime_contention metric=${healthy_metric%% *}" >&2
    failed=1
  fi
done
if ! grep -Fqx 'paramascotas_php_fpm_listen_queue 0' "${metrics_file}"; then
  echo 'ALERT php_fpm_listen_queue_nonzero' >&2
  failed=1
fi
for probe in \
  "paramascotas_http_probe_success{name=\"api_health\",path=\"/${TENANT_SLUG}/api/health\"} 1" \
  "paramascotas_http_probe_success{name=\"api_live\",path=\"/${TENANT_SLUG}/api/livez\"} 1" \
  'paramascotas_http_probe_success{name="storefront_home",path="/"} 1' \
  "paramascotas_http_probe_success{name=\"public_catalog\",path=\"/${TENANT_SLUG}/api/products\"} 1" \
  "paramascotas_http_probe_redirect{name=\"api_health\",path=\"/${TENANT_SLUG}/api/health\"} 0" \
  "paramascotas_http_probe_redirect{name=\"api_live\",path=\"/${TENANT_SLUG}/api/livez\"} 0" \
  'paramascotas_http_probe_redirect{name="storefront_home",path="/"} 0' \
  "paramascotas_http_probe_redirect{name=\"public_catalog\",path=\"/${TENANT_SLUG}/api/products\"} 0"; do
  if ! grep -Fqx "${probe}" "${metrics_file}"; then
    echo "ALERT public_probe_failed metric=${probe%% *}" >&2
    failed=1
  fi
done
postgres_connection_utilization="$(awk '$1 == "paramascotas_postgres_connection_utilization_percent" {print $2; exit}' "${metrics_file}")"
if [[ ! "${postgres_connection_utilization}" =~ ^[0-9]+([.][0-9]+)?$ ]] \
  || ! awk -v actual="${postgres_connection_utilization}" -v maximum="${SLO_POSTGRES_CONNECTION_UTILIZATION_MAX_PERCENT}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "ALERT postgres_connection_utilization actual=${postgres_connection_utilization:-missing} maximum=${SLO_POSTGRES_CONNECTION_UTILIZATION_MAX_PERCENT}" >&2
  failed=1
fi

common_benchmark_env=(
  "BASE_URL=${BASE_URL}"
  "TENANT_SLUG=${TENANT_SLUG}"
  "RESOLVE_IP=${RESOLVE_IP}"
  "CA_CERT=${CA_CERT}"
  "REQUESTS=${SLO_REQUESTS}"
  "CONCURRENCY=${SLO_CONCURRENCY}"
  "REQUEST_INTERVAL_MS=${SLO_REQUEST_INTERVAL_MS}"
  "MIN_SUCCESS_PERCENT=${SLO_MIN_SUCCESS_PERCENT}"
  "MAX_REDIRECTS=0"
)

if ! env "${common_benchmark_env[@]}" MAX_P95_SECONDS="${SLO_HEALTH_P95_MAX_SECONDS}" \
  MAX_P99_SECONDS="${SLO_HEALTH_P99_MAX_SECONDS}" \
  "${SCRIPT_DIR}/benchmark-api.sh" "/${TENANT_SLUG}/api/health"; then
  echo "ALERT api_health_slo_failed" >&2
  failed=1
fi
if ! env "${common_benchmark_env[@]}" \
  MAX_P95_SECONDS="${SLO_HOME_P95_MAX_SECONDS}" \
  MAX_P99_SECONDS="${SLO_HOME_P99_MAX_SECONDS}" \
  MAX_RESPONSE_BYTES="${SLO_HOME_MAX_BYTES}" \
  "${SCRIPT_DIR}/benchmark-api.sh" "/"; then
  echo "ALERT storefront_home_slo_failed" >&2
  failed=1
fi
if ! env "${common_benchmark_env[@]}" \
  MAX_P95_SECONDS="${SLO_CATALOG_P95_MAX_SECONDS}" \
  MAX_P99_SECONDS="${SLO_CATALOG_P99_MAX_SECONDS}" \
  MAX_RESPONSE_BYTES="${SLO_CATALOG_MAX_BYTES}" \
  "${SCRIPT_DIR}/benchmark-api.sh" "/${TENANT_SLUG}/api/products"; then
  echo "ALERT public_catalog_slo_failed" >&2
  failed=1
fi

if [[ "${failed}" -ne 0 ]]; then
  exit 1
fi
echo "Runtime SLO: OK"
