#!/usr/bin/env bash

set -euo pipefail
umask 077

usage() {
  cat >&2 <<'EOF'
Uso: ./scripts/benchmark-api.sh [ruta ...]

Benchmark GET no destructivo contra el contrato publico APISIX.

Variables:
  BASE_URL       URL publica (default https://paramascotasec.com)
  RESOLVE_IP      IP opcional para curl --resolve
  CA_CERT         CA opcional; usa la CA QA local si existe
  REQUESTS        solicitudes por ruta (default 20)
  CONCURRENCY     concurrencia maxima (default 5)
  REQUEST_INTERVAL_MS separacion minima entre inicios; 125 equivale a <=8 RPS
  TIMEOUT_SECONDS timeout curl por solicitud (default 30)
  OUTPUT_FILE     TSV opcional donde conservar las muestras
  MIN_SUCCESS_PERCENT minimo 2xx sin error de transporte (default 100)
  MAX_P95_SECONDS limite p95 opcional; incumplir devuelve exit 1
  MAX_P99_SECONDS limite p99 opcional; incumplir devuelve exit 1
  MAX_RESPONSE_BYTES limite maximo de cuerpo sin compresion opcional
  MAX_REDIRECTS   respuestas 3xx permitidas; no se siguen (default 0)

Ejemplo QA:
  REQUESTS=50 CONCURRENCY=10 RESOLVE_IP=192.168.100.229 \
    ./scripts/benchmark-api.sh /paramascotasec/api/health /paramascotasec/api/products
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORKSPACE_DIR="$(cd "${APP_DIR}/.." && pwd)"

BASE_URL="${BASE_URL:-https://paramascotasec.com}"
TENANT_SLUG="${TENANT_SLUG:-${PUBLIC_TENANT_SLUG:-paramascotasec}}"
RESOLVE_IP="${RESOLVE_IP:-}"
REQUESTS="${REQUESTS:-20}"
CONCURRENCY="${CONCURRENCY:-5}"
REQUEST_INTERVAL_MS="${REQUEST_INTERVAL_MS:-125}"
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-30}"
OUTPUT_FILE="${OUTPUT_FILE:-}"
CA_CERT="${CA_CERT:-}"
MIN_SUCCESS_PERCENT="${MIN_SUCCESS_PERCENT:-100}"
MAX_P95_SECONDS="${MAX_P95_SECONDS:-}"
MAX_P99_SECONDS="${MAX_P99_SECONDS:-}"
MAX_RESPONSE_BYTES="${MAX_RESPONSE_BYTES:-}"
MAX_REDIRECTS="${MAX_REDIRECTS:-0}"

positive_integer() {
  local value="$1"
  local label="$2"
  if [[ ! "${value}" =~ ^[1-9][0-9]*$ ]]; then
    echo "${label} debe ser un entero positivo; recibido: ${value}" >&2
    exit 2
  fi
}

positive_integer "${REQUESTS}" REQUESTS
positive_integer "${CONCURRENCY}" CONCURRENCY
positive_integer "${TIMEOUT_SECONDS}" TIMEOUT_SECONDS
if [[ ! "${REQUEST_INTERVAL_MS}" =~ ^[0-9]+$ ]]; then
  echo "REQUEST_INTERVAL_MS debe ser un entero no negativo." >&2
  exit 2
fi
if [[ ! "${MIN_SUCCESS_PERCENT}" =~ ^[0-9]+([.][0-9]+)?$ ]] \
  || ! awk -v value="${MIN_SUCCESS_PERCENT}" 'BEGIN {exit(value >= 0 && value <= 100 ? 0 : 1)}'; then
  echo "MIN_SUCCESS_PERCENT debe estar entre 0 y 100." >&2
  exit 2
fi
if [[ -n "${MAX_P95_SECONDS}" && ! "${MAX_P95_SECONDS}" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
  echo "MAX_P95_SECONDS debe ser un numero no negativo." >&2
  exit 2
fi
if [[ -n "${MAX_P99_SECONDS}" && ! "${MAX_P99_SECONDS}" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
  echo "MAX_P99_SECONDS debe ser un numero no negativo." >&2
  exit 2
fi
if [[ -n "${MAX_RESPONSE_BYTES}" && ! "${MAX_RESPONSE_BYTES}" =~ ^[1-9][0-9]*$ ]]; then
  echo "MAX_RESPONSE_BYTES debe ser un entero positivo." >&2
  exit 2
fi
if [[ ! "${MAX_REDIRECTS}" =~ ^[0-9]+$ ]]; then
  echo "MAX_REDIRECTS debe ser un entero no negativo." >&2
  exit 2
fi

if [[ ! "${BASE_URL}" =~ ^https?://[A-Za-z0-9.-]+(:[1-9][0-9]{0,4})?/?$ ]]; then
  echo "BASE_URL debe ser un origen HTTP(S) sin path, query ni credenciales." >&2
  exit 2
fi
BASE_URL="${BASE_URL%/}"
if [[ -n "${RESOLVE_IP}" ]]; then
  if [[ ! "${RESOLVE_IP}" =~ ^([0-9]{1,3}[.]){3}[0-9]{1,3}$ ]]; then
    echo "RESOLVE_IP debe ser una direccion IPv4." >&2
    exit 2
  fi
  IFS='.' read -r -a resolve_octets <<<"${RESOLVE_IP}"
  for octet in "${resolve_octets[@]}"; do
    (( 10#${octet} <= 255 )) || { echo "RESOLVE_IP invalida." >&2; exit 2; }
  done
fi

for command in awk curl date paste sed sort tr wc; do
  command -v "${command}" >/dev/null 2>&1 || {
    echo "Falta comando requerido: ${command}" >&2
    exit 2
  }
done

if [[ "$#" -gt 0 ]]; then
  paths=("$@")
else
  paths=(
    "/${TENANT_SLUG}/api/health"
    "/${TENANT_SLUG}/api/products"
  )
fi

for path in "${paths[@]}"; do
  if [[ "${path}" != /* || "${path}" == *[$'\r\n\t ']* ]]; then
    echo "Ruta invalida para benchmark: ${path}" >&2
    exit 2
  fi
done

authority="${BASE_URL#*://}"
authority="${authority%%/*}"
host="${authority%%:*}"
if [[ "${authority}" == *:* ]]; then
  port="${authority##*:}"
elif [[ "${BASE_URL}" == https://* ]]; then
  port=443
else
  port=80
fi
(( 10#${port} <= 65535 )) || { echo "Puerto de BASE_URL fuera de rango." >&2; exit 2; }

curl_args=(
  --silent
  --show-error
  --max-time "${TIMEOUT_SECONDS}"
  --output /dev/null
  --header "Accept-Encoding: identity"
)

if [[ -n "${RESOLVE_IP}" && -z "${CA_CERT}" && -f "${WORKSPACE_DIR}/gatewayapisix/entorno/certs/local-ca.crt" ]]; then
  CA_CERT="${WORKSPACE_DIR}/gatewayapisix/entorno/certs/local-ca.crt"
fi
if [[ -n "${CA_CERT}" ]]; then
  if [[ ! -r "${CA_CERT}" ]]; then
    echo "CA_CERT no legible: ${CA_CERT}" >&2
    exit 2
  fi
  curl_args+=(--cacert "${CA_CERT}")
fi
if [[ -n "${RESOLVE_IP}" ]]; then
  curl_args+=(--noproxy "${host}" --resolve "${host}:${port}:${RESOLVE_IP}")
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT
all_samples="${tmp_dir}/all-samples.tsv"
printf 'path\trequest\thttp_code\tttfb_seconds\ttotal_seconds\tbytes\tcurl_exit_code\tredirect_url\tremote_ip\n' > "${all_samples}"

run_request() {
  local path="$1"
  local request_number="$2"
  local metrics status ttfb total bytes redirect_url remote_ip curl_exit
  local separator=$'\x1f'

  if metrics="$(curl "${curl_args[@]}" \
    --write-out $'%{http_code}\x1f%{time_starttransfer}\x1f%{time_total}\x1f%{size_download}\x1f%{redirect_url}\x1f%{remote_ip}' \
    "${BASE_URL}${path}")"; then
    curl_exit=0
  else
    curl_exit=$?
  fi
  IFS="${separator}" read -r status ttfb total bytes redirect_url remote_ip <<<"${metrics:-}"
  [[ "${status:-}" =~ ^[0-9]{3}$ ]] || status=000
  [[ "${ttfb:-}" =~ ^[0-9]+([.][0-9]+)?$ ]] || ttfb=0
  [[ "${total:-}" =~ ^[0-9]+([.][0-9]+)?$ ]] || total="${TIMEOUT_SECONDS}"
  [[ "${bytes:-}" =~ ^[0-9]+$ ]] || bytes=0
  redirect_url="${redirect_url//$'\t'/%09}"
  redirect_url="${redirect_url//$'\r'/%0D}"
  redirect_url="${redirect_url//$'\n'/%0A}"
  remote_ip="${remote_ip//$'\t'/}"
  remote_ip="${remote_ip//$'\r'/}"
  remote_ip="${remote_ip//$'\n'/}"
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "${path}" "${request_number}" "${status}" "${ttfb}" "${total}" "${bytes}" \
    "${curl_exit}" "${redirect_url}" "${remote_ip}"
}

overall_failure=0
for path in "${paths[@]}"; do
  path_samples="${tmp_dir}/$(printf '%s' "${path}" | tr -c 'A-Za-z0-9' '_').tsv"
  : > "${path_samples}"
  started_ns="$(date +%s%N)"

  request_interval_seconds="$(awk -v milliseconds="${REQUEST_INTERVAL_MS}" 'BEGIN {printf "%.3f", milliseconds/1000}')"
  for ((request_number = 1; request_number <= REQUESTS; request_number++)); do
    run_request "${path}" "${request_number}" >> "${path_samples}" &
    while (( $(jobs -pr | wc -l) >= CONCURRENCY )); do
      if ! wait -n; then
        overall_failure=1
      fi
    done
    if (( REQUEST_INTERVAL_MS > 0 && request_number < REQUESTS )); then
      sleep "${request_interval_seconds}"
    fi
  done
  while (( $(jobs -pr | wc -l) > 0 )); do
    if ! wait -n; then
      overall_failure=1
    fi
  done

  finished_ns="$(date +%s%N)"
  cat "${path_samples}" >> "${all_samples}"

  request_count="$(wc -l < "${path_samples}" | tr -d ' ')"
  success_count="$(awk -F '\t' '$3 ~ /^2[0-9][0-9]$/ && $7 == 0 {count++} END {print count+0}' "${path_samples}")"
  transport_errors="$(awk -F '\t' '$7 != 0 {count++} END {print count+0}' "${path_samples}")"
  redirect_count="$(awk -F '\t' '$3 ~ /^3[0-9][0-9]$/ || length($8) > 0 {count++} END {print count+0}' "${path_samples}")"
  remote_ip_mismatches=0
  if [[ -n "${RESOLVE_IP}" ]]; then
    remote_ip_mismatches="$(awk -F '\t' -v expected="${RESOLVE_IP}" '$9 != expected {count++} END {print count+0}' "${path_samples}")"
  fi
  success_percent="$(awk -v success="${success_count}" -v total="${request_count}" 'BEGIN {printf "%.4f", total ? success*100/total : 0}')"
  wall_seconds="$(awk -v start="${started_ns}" -v finish="${finished_ns}" 'BEGIN {printf "%.3f", (finish-start)/1000000000}')"
  average_total="$(awk -F '\t' '{sum += $5} END {printf "%.3f", NR ? sum/NR : 0}' "${path_samples}")"
  p50_total="$(sort -t $'\t' -k5,5n "${path_samples}" | awk -F '\t' -v total="${request_count}" 'NR == int((total+1)/2) {printf "%.3f", $5}')"
  p95_total="$(sort -t $'\t' -k5,5n "${path_samples}" | awk -F '\t' -v total="${request_count}" 'NR == int((total*95+99)/100) {printf "%.3f", $5}')"
  p99_total="$(sort -t $'\t' -k5,5n "${path_samples}" | awk -F '\t' -v total="${request_count}" 'NR == int((total*99+99)/100) {printf "%.3f", $5}')"
  p95_ttfb="$(sort -t $'\t' -k4,4n "${path_samples}" | awk -F '\t' -v total="${request_count}" 'NR == int((total*95+99)/100) {printf "%.3f", $4}')"
  requests_per_second="$(awk -v requests="${request_count}" -v elapsed="${wall_seconds}" 'BEGIN {rate = 0; if (elapsed > 0) rate = requests / elapsed; printf "%.2f", rate}')"
  average_bytes="$(awk -F '\t' '{sum += $6} END {printf "%.0f", NR ? sum/NR : 0}' "${path_samples}")"
  max_bytes="$(awk -F '\t' 'BEGIN {max=0} $6 > max {max=$6} END {printf "%.0f", max}' "${path_samples}")"

  status_counts="$(awk -F '\t' '{count[$3]++} END {for (code in count) printf "%s:%s,", code, count[code]}' "${path_samples}" | sed 's/,$//' | tr ',' '\n' | sort | paste -sd, -)"
  printf '%s requests=%s success=%s success_pct=%s transport_errors=%s redirects=%s remote_ip_mismatches=%s status_counts=%s concurrency=%s launch_interval_ms=%s wall=%ss rps=%s avg=%ss p50=%ss p95=%ss p99=%ss ttfb_p95=%ss avg_bytes=%s max_bytes=%s\n' \
    "${path}" "${request_count}" "${success_count}" "${success_percent}" "${transport_errors}" \
    "${redirect_count}" "${remote_ip_mismatches}" "${status_counts:-none}" "${CONCURRENCY}" "${REQUEST_INTERVAL_MS}" "${wall_seconds}" \
    "${requests_per_second}" "${average_total}" "${p50_total:-0}" "${p95_total:-0}" "${p99_total:-0}" "${p95_ttfb:-0}" \
    "${average_bytes}" "${max_bytes}"

  if ! awk -v actual="${success_percent}" -v minimum="${MIN_SUCCESS_PERCENT}" 'BEGIN {exit(actual + 0 >= minimum + 0 ? 0 : 1)}'; then
    echo "SLO_VIOLATION path=${path} success_pct=${success_percent} minimum=${MIN_SUCCESS_PERCENT}" >&2
    overall_failure=1
  fi
  if [[ -n "${MAX_P99_SECONDS}" ]] \
    && ! awk -v actual="${p99_total:-0}" -v maximum="${MAX_P99_SECONDS}" 'BEGIN {exit(actual + 0 <= maximum + 0 ? 0 : 1)}'; then
    echo "SLO_VIOLATION path=${path} p99_seconds=${p99_total:-0} maximum=${MAX_P99_SECONDS}" >&2
    overall_failure=1
  fi
  if [[ -n "${MAX_P95_SECONDS}" ]] \
    && ! awk -v actual="${p95_total:-0}" -v maximum="${MAX_P95_SECONDS}" 'BEGIN {exit(actual + 0 <= maximum + 0 ? 0 : 1)}'; then
    echo "SLO_VIOLATION path=${path} p95_seconds=${p95_total:-0} maximum=${MAX_P95_SECONDS}" >&2
    overall_failure=1
  fi
  if [[ -n "${MAX_RESPONSE_BYTES}" ]] && (( max_bytes > MAX_RESPONSE_BYTES )); then
    echo "SLO_VIOLATION path=${path} max_response_bytes=${max_bytes} maximum=${MAX_RESPONSE_BYTES}" >&2
    overall_failure=1
  fi
  if (( redirect_count > MAX_REDIRECTS )); then
    echo "SLO_VIOLATION path=${path} redirects=${redirect_count} maximum=${MAX_REDIRECTS}" >&2
    overall_failure=1
  fi
  if (( remote_ip_mismatches > 0 )); then
    echo "SLO_VIOLATION path=${path} remote_ip_mismatches=${remote_ip_mismatches} expected=${RESOLVE_IP}" >&2
    overall_failure=1
  fi
done

if [[ -n "${OUTPUT_FILE}" ]]; then
  mkdir -p "$(dirname "${OUTPUT_FILE}")"
  output_tmp="${OUTPUT_FILE}.tmp.$$"
  cp "${all_samples}" "${output_tmp}"
  mv -f "${output_tmp}" "${OUTPUT_FILE}"
  echo "Muestras guardadas en ${OUTPUT_FILE}"
fi

exit "${overall_failure}"
