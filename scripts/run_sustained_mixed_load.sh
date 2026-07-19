#!/usr/bin/env bash

set -euo pipefail
umask 077

usage() {
  cat >&2 <<'EOF'
Uso: ./scripts/run_sustained_mixed_load.sh

Carga GET mixta, no destructiva y sostenida contra el contrato publico APISIX.
Genera muestras HTTP, snapshots de CPU/memoria/FPM/PostgreSQL y un resumen con
p50/p95/p99, RPS y errores por ruta.

Variables:
  BASE_URL, TENANT_SLUG, RESOLVE_IP, CA_CERT
  DURATION_SECONDS       default 600; minimo probatorio 600
  CONCURRENCY            default 8
  METRICS_INTERVAL       default 30; 21 snapshots inicio-fin en 600 s
  TIMEOUT_SECONDS        default 15
  REQUEST_INTERVAL_MS    default 1000 entre inicios por worker (cadencia absoluta)
  MAX_CLIENT_RPS         default 9; incluye workers y probes, bajo 10 RPS/IP
  MAX_ERRORS             default 0 para evidencia arquitectonica
  OUTPUT_DIR             ruta absoluta nueva; nunca sobrescribe evidencia
  MIN_SAMPLE_COUNT       default 3000 para validez estadistica
  MIN_ROUTE_SAMPLES      default 200 por ruta unica
  MIN_SUCCESS_PERCENT    default 99.9
  MAX_REDIRECTS          default 0; las rutas probatorias deben ser canonicas
  MAX_P95_SECONDS        default 1.0
  MAX_P99_SECONDS        default 2.0
  WORKLOAD_PATHS         rutas separadas por espacio; repetir pondera trafico
  ALLOW_SHORT_TEST       true permite menos de 600 s solo para probar el script
  ALLOW_RATE_LIMIT_TEST  true permite exceder MAX_CLIENT_RPS, no es probatorio
  ALLOW_INSECURE_HTTP_TEST true permite HTTP solo en una prueba no probatoria

Un manifest probatorio v4 exige exactamente 600 s configurados, concurrencia 8,
intervalo HTTP de 1000 ms, metricas cada 30 s, cero errores, RESOLVE_IP
explicita, la mezcla canonica de ecommerce/Dashboard/tenant y umbrales no mas
laxos que los defaults documentados. Todos los flags ALLOW_* deben estar false.
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi
if [[ "$#" -ne 0 ]]; then
  usage
  exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORKSPACE_DIR="$(cd "${APP_DIR}/.." && pwd)"
BASE_URL="${BASE_URL:-https://paramascotasec.com}"
TENANT_SLUG="${TENANT_SLUG:-${PUBLIC_TENANT_SLUG:-paramascotasec}}"
RESOLVE_IP="${RESOLVE_IP:-}"
CA_CERT="${CA_CERT:-}"
DURATION_SECONDS="${DURATION_SECONDS:-600}"
CONCURRENCY="${CONCURRENCY:-8}"
METRICS_INTERVAL="${METRICS_INTERVAL:-30}"
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-15}"
REQUEST_INTERVAL_MS="${REQUEST_INTERVAL_MS:-1000}"
OUTPUT_DIR="${OUTPUT_DIR:-}"
MIN_SUCCESS_PERCENT="${MIN_SUCCESS_PERCENT:-99.9}"
MAX_P95_SECONDS="${MAX_P95_SECONDS:-1.0}"
MAX_P99_SECONDS="${MAX_P99_SECONDS:-2.0}"
ALLOW_SHORT_TEST="${ALLOW_SHORT_TEST:-false}"
ALLOW_RATE_LIMIT_TEST="${ALLOW_RATE_LIMIT_TEST:-false}"
ALLOW_INSECURE_HTTP_TEST="${ALLOW_INSECURE_HTTP_TEST:-false}"
MAX_CLIENT_RPS="${MAX_CLIENT_RPS:-9}"
MAX_ERRORS="${MAX_ERRORS:-0}"
MIN_SAMPLE_COUNT="${MIN_SAMPLE_COUNT:-}"
MIN_ROUTE_SAMPLES="${MIN_ROUTE_SAMPLES:-}"
MAX_REDIRECTS="${MAX_REDIRECTS:-0}"
MIN_METRICS_COVERAGE_PERCENT="${MIN_METRICS_COVERAGE_PERCENT:-100}"
MAX_FPM_LISTEN_QUEUE="${MAX_FPM_LISTEN_QUEUE:-0}"
MAX_FPM_MAX_CHILDREN_REACHED_DELTA="${MAX_FPM_MAX_CHILDREN_REACHED_DELTA:-0}"
MAX_FPM_SLOW_REQUESTS_DELTA="${MAX_FPM_SLOW_REQUESTS_DELTA:-0}"
MAX_POSTGRES_CONNECTION_UTILIZATION_PERCENT="${MAX_POSTGRES_CONNECTION_UTILIZATION_PERCENT:-80}"
MAX_POSTGRES_LONGEST_QUERY_SECONDS="${MAX_POSTGRES_LONGEST_QUERY_SECONDS:-30}"
MAX_METRIC_START_LAG_SECONDS="${MAX_METRIC_START_LAG_SECONDS:-2}"
GATEWAY_ENV_FILE="${WORKSPACE_DIR}/gatewayapisix/entorno/.env"

gateway_env_value() {
  local key="$1"
  awk -F= -v key="${key}" '
    $0 !~ /^[[:space:]]*#/ && $1 == key {
      value=substr($0, index($0, "=")+1)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
      single_quote=sprintf("%c", 39)
      if ((substr(value, 1, 1) == "\"" && substr(value, length(value), 1) == "\"") || (substr(value, 1, 1) == single_quote && substr(value, length(value), 1) == single_quote)) {
        value=substr(value, 2, length(value)-2)
      }
      print value
      exit
    }
  ' "${GATEWAY_ENV_FILE}" 2>/dev/null || true
}

# Contrato probatorio de runtimes. Mantener esta lista explicita impide que una
# variable de ambiente omita silenciosamente un worker durante la evidencia.
runtime_containers=(
  apisix-gateway apisix-etcd basesdedatos backend-api backend-http
  backend-sri-worker backend-commerce-billing-worker backend-mailer-worker
  backend-wallet-notify-worker webparamascotas dashboard
)
healthy_runtime_containers=(
  apisix-gateway apisix-etcd basesdedatos backend-http backend-sri-worker
  backend-commerce-billing-worker backend-mailer-worker
  backend-wallet-notify-worker webparamascotas dashboard
)

if [[ -z "${MIN_SAMPLE_COUNT}" ]]; then
  if [[ "${DURATION_SECONDS}" =~ ^[1-9][0-9]*$ ]] \
    && (( DURATION_SECONDS < 600 )) && [[ "${ALLOW_SHORT_TEST,,}" == "true" ]]; then
    MIN_SAMPLE_COUNT=1
  else
    MIN_SAMPLE_COUNT=3000
  fi
fi
if [[ -z "${MIN_ROUTE_SAMPLES}" ]]; then
  if [[ "${DURATION_SECONDS}" =~ ^[1-9][0-9]*$ ]] \
    && (( DURATION_SECONDS < 600 )) && [[ "${ALLOW_SHORT_TEST,,}" == "true" ]]; then
    MIN_ROUTE_SAMPLES=1
  else
    MIN_ROUTE_SAMPLES=200
  fi
fi

positive_integer() {
  local value="$1" name="$2"
  if [[ ! "${value}" =~ ^[1-9][0-9]*$ ]]; then
    echo "${name} debe ser un entero positivo." >&2
    exit 2
  fi
}
for boolean_name in ALLOW_SHORT_TEST ALLOW_RATE_LIMIT_TEST ALLOW_INSECURE_HTTP_TEST; do
  boolean_value="${!boolean_name}"
  if [[ ! "${boolean_value,,}" =~ ^(true|false)$ ]]; then
    echo "${boolean_name} debe ser true o false." >&2
    exit 2
  fi
done
decimal() {
  local value="$1" name="$2"
  if [[ ! "${value}" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
    echo "${name} debe ser un numero no negativo." >&2
    exit 2
  fi
}

for pair in "${DURATION_SECONDS}:DURATION_SECONDS" "${CONCURRENCY}:CONCURRENCY" \
  "${METRICS_INTERVAL}:METRICS_INTERVAL" "${TIMEOUT_SECONDS}:TIMEOUT_SECONDS" \
  "${MIN_SAMPLE_COUNT}:MIN_SAMPLE_COUNT" "${MIN_ROUTE_SAMPLES}:MIN_ROUTE_SAMPLES"; do
  positive_integer "${pair%%:*}" "${pair##*:}"
done
if [[ ! "${REQUEST_INTERVAL_MS}" =~ ^[0-9]+$ ]]; then
  echo 'REQUEST_INTERVAL_MS debe ser un entero no negativo.' >&2
  exit 2
fi
if [[ ! "${MAX_REDIRECTS}" =~ ^[0-9]+$ ]]; then
  echo 'MAX_REDIRECTS debe ser un entero no negativo.' >&2
  exit 2
fi
if [[ ! "${MAX_ERRORS}" =~ ^[0-9]+$ ]]; then
  echo 'MAX_ERRORS debe ser un entero no negativo.' >&2
  exit 2
fi
decimal "${MIN_SUCCESS_PERCENT}" MIN_SUCCESS_PERCENT
decimal "${MAX_P95_SECONDS}" MAX_P95_SECONDS
decimal "${MAX_P99_SECONDS}" MAX_P99_SECONDS
decimal "${MAX_CLIENT_RPS}" MAX_CLIENT_RPS
decimal "${MIN_METRICS_COVERAGE_PERCENT}" MIN_METRICS_COVERAGE_PERCENT
decimal "${MAX_FPM_LISTEN_QUEUE}" MAX_FPM_LISTEN_QUEUE
decimal "${MAX_FPM_MAX_CHILDREN_REACHED_DELTA}" MAX_FPM_MAX_CHILDREN_REACHED_DELTA
decimal "${MAX_FPM_SLOW_REQUESTS_DELTA}" MAX_FPM_SLOW_REQUESTS_DELTA
decimal "${MAX_POSTGRES_CONNECTION_UTILIZATION_PERCENT}" MAX_POSTGRES_CONNECTION_UTILIZATION_PERCENT
decimal "${MAX_POSTGRES_LONGEST_QUERY_SECONDS}" MAX_POSTGRES_LONGEST_QUERY_SECONDS
decimal "${MAX_METRIC_START_LAG_SECONDS}" MAX_METRIC_START_LAG_SECONDS
if ! awk -v value="${MIN_SUCCESS_PERCENT}" 'BEGIN {exit(value >= 0 && value <= 100 ? 0 : 1)}'; then
  echo 'MIN_SUCCESS_PERCENT debe estar entre 0 y 100.' >&2
  exit 2
fi
if ! awk -v value="${MIN_METRICS_COVERAGE_PERCENT}" 'BEGIN {exit(value >= 0 && value <= 100 ? 0 : 1)}'; then
  echo 'MIN_METRICS_COVERAGE_PERCENT debe estar entre 0 y 100.' >&2
  exit 2
fi
if (( DURATION_SECONDS < 600 )) && [[ "${ALLOW_SHORT_TEST,,}" != "true" ]]; then
  echo 'La evidencia sostenida exige DURATION_SECONDS >= 600.' >&2
  exit 2
fi
if [[ -z "${OUTPUT_DIR}" || "${OUTPUT_DIR}" != /* || "${OUTPUT_DIR}" == "/" ]]; then
  echo 'OUTPUT_DIR debe ser una ruta absoluta durable y distinta de /.' >&2
  exit 2
fi
if [[ -e "${OUTPUT_DIR}" ]]; then
  echo 'OUTPUT_DIR ya existe; use una ruta nueva para no sobrescribir evidencia.' >&2
  exit 2
fi
if [[ ! "${BASE_URL}" =~ ^https?://[A-Za-z0-9.-]+(:[1-9][0-9]{0,4})?/?$ ]]; then
  echo 'BASE_URL debe ser un origen HTTP(S) sin path, query ni credenciales.' >&2
  exit 2
fi
if [[ "${BASE_URL}" == http://* && "${ALLOW_INSECURE_HTTP_TEST,,}" != "true" ]]; then
  echo 'La evidencia sostenida exige HTTPS; HTTP solo se permite con ALLOW_INSECURE_HTTP_TEST=true.' >&2
  exit 2
fi
if [[ ! "${TENANT_SLUG}" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
  echo 'TENANT_SLUG invalido.' >&2
  exit 2
fi
if [[ -n "${RESOLVE_IP}" ]]; then
  if [[ ! "${RESOLVE_IP}" =~ ^([0-9]{1,3}[.]){3}[0-9]{1,3}$ ]]; then
    echo 'RESOLVE_IP debe ser una direccion IPv4.' >&2
    exit 2
  fi
  IFS='.' read -r -a resolve_octets <<<"${RESOLVE_IP}"
  for octet in "${resolve_octets[@]}"; do
    (( 10#${octet} <= 255 )) || { echo 'RESOLVE_IP invalida.' >&2; exit 2; }
  done
fi
for command in awk cmp curl date dirname docker find nice php sha256sum sort tee xargs; do
  command -v "${command}" >/dev/null 2>&1 || { echo "Falta ${command}." >&2; exit 2; }
done
if [[ ! -x "${SCRIPT_DIR}/collect_runtime_metrics.sh" ]]; then
  echo 'collect_runtime_metrics.sh debe ser ejecutable.' >&2
  exit 2
fi

if (( REQUEST_INTERVAL_MS == 0 )); then
  workload_theoretical_rps=unbounded
  theoretical_rps=unbounded
else
  workload_theoretical_rps="$(awk -v workers="${CONCURRENCY}" -v interval="${REQUEST_INTERVAL_MS}" 'BEGIN {printf "%.4f", workers*1000/interval}')"
  theoretical_rps="$(awk -v workload="${workload_theoretical_rps}" -v duration="${DURATION_SECONDS}" -v metrics_interval="${METRICS_INTERVAL}" 'BEGIN {snapshots=int(duration/metrics_interval)+1; printf "%.4f", workload + (4*snapshots/duration)}')"
fi
if [[ "${ALLOW_RATE_LIMIT_TEST,,}" != "true" ]]; then
  if (( REQUEST_INTERVAL_MS == 0 )) \
    || ! awk -v actual="${theoretical_rps}" -v maximum="${MAX_CLIENT_RPS}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
    if (( REQUEST_INTERVAL_MS == 0 )); then rate_label=sin_limite; else rate_label="${theoretical_rps}"; fi
    echo "La tasa teorica (${rate_label} RPS) excede MAX_CLIENT_RPS=${MAX_CLIENT_RPS}; aumente REQUEST_INTERVAL_MS." >&2
    exit 2
  fi
fi

BASE_URL="${BASE_URL%/}"
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
(( 10#${port} <= 65535 )) || { echo 'Puerto de BASE_URL fuera de rango.' >&2; exit 2; }

dashboard_admin_host="${DASHBOARD_ADMIN_HOST:-$(gateway_env_value DASHBOARD_ADMIN_HOST)}"
dashboard_admin_host="${dashboard_admin_host:-admin.${host}}"
dashboard_tenant_hosts="${DASHBOARD_TENANT_HOSTS:-$(gateway_env_value DASHBOARD_TENANT_HOSTS)}"
IFS=',' read -r dashboard_tenant_host _ <<<"${dashboard_tenant_hosts}"
dashboard_tenant_host="${dashboard_tenant_host//[[:space:]]/}"
dashboard_tenant_slug="${dashboard_tenant_host%%.*}"

workload_customized=false
if [[ -n "${WORKLOAD_TARGETS:-}" ]]; then
  read -r -a targets <<<"${WORKLOAD_TARGETS}"
  workload_customized=true
elif [[ -n "${WORKLOAD_PATHS:-}" ]]; then
  read -r -a legacy_paths <<<"${WORKLOAD_PATHS}"
  targets=()
  for path in "${legacy_paths[@]}"; do
    targets+=("${host}|${path}")
  done
  workload_customized=true
else
  if [[ -z "${dashboard_tenant_host}" || -z "${dashboard_tenant_slug}" ]]; then
    echo 'La mezcla canonica exige al menos un DASHBOARD_TENANT_HOSTS.' >&2
    exit 2
  fi
  targets=(
    "${host}|/"
    "${host}|/${TENANT_SLUG}/api/health"
    "${host}|/${TENANT_SLUG}/api/products"
    "${host}|/${TENANT_SLUG}/api/products"
    "${host}|/${TENANT_SLUG}/api/products"
    "${host}|/${TENANT_SLUG}/api/products"
    "${host}|/${TENANT_SLUG}/api/settings/shipping"
    "${host}|/${TENANT_SLUG}/api/settings/product-categories"
    "${host}|/login"
    "${host}|/my-account"
    "${dashboard_admin_host}|/dashboard/"
    "${dashboard_admin_host}|/dashboard/"
    "${dashboard_admin_host}|/dashboard/api/health"
    "${dashboard_admin_host}|/dashboard/api/health"
    "${dashboard_admin_host}|/dashboard/module.json"
    "${dashboard_tenant_host}|/"
    "${dashboard_tenant_host}|/"
    "${dashboard_tenant_host}|/${dashboard_tenant_slug}/fidelizacion/health"
  )
fi
if (( ${#targets[@]} < 2 )); then
  echo 'WORKLOAD_TARGETS debe contener al menos dos objetivos host|path.' >&2
  exit 2
fi
for target in "${targets[@]}"; do
  target_host="${target%%|*}"
  path="${target#*|}"
  if [[ "${target}" != *'|'* \
    || ! "${target_host}" =~ ^[A-Za-z0-9.-]+$ \
    || "${path}" != /* \
    || "${path}" == *[$'\r\n\t ']* ]]; then
    echo "Objetivo invalido; use host|/path: ${target}" >&2
    exit 2
  fi
done
workload_target_set_hash="$(printf '%s\n' "${targets[@]}" | LC_ALL=C sort | sha256sum | awk '{print $1}')"
target_urls=()
for target in "${targets[@]}"; do
  target_host="${target%%|*}"
  path="${target#*|}"
  if [[ "${BASE_URL}" == https://* ]]; then
    target_url="https://${target_host}"
  else
    target_url="http://${target_host}"
  fi
  if ! { [[ "${BASE_URL}" == https://* && "${port}" == '443' ]] \
    || [[ "${BASE_URL}" == http://* && "${port}" == '80' ]]; }; then
    target_url="${target_url}:${port}"
  fi
  target_urls+=("${target_url}${path}")
done

mkdir -p "$(dirname "${OUTPUT_DIR}")"
if ! mkdir "${OUTPUT_DIR}"; then
  echo 'No se pudo reservar OUTPUT_DIR de forma exclusiva.' >&2
  exit 2
fi
mkdir "${OUTPUT_DIR}/workers" "${OUTPUT_DIR}/metrics"
chmod 700 "${OUTPUT_DIR}" "${OUTPUT_DIR}/workers" "${OUTPUT_DIR}/metrics"
samples="${OUTPUT_DIR}/http-samples.tsv"
summary="${OUTPUT_DIR}/summary.txt"
manifest="${OUTPUT_DIR}/manifest.json"
metric_log="${OUTPUT_DIR}/metric-samples.tsv"
runtime_identity_file="${OUTPUT_DIR}/runtime-identities.tsv"
runtime_identity_after="${OUTPUT_DIR}/runtime-identities.after.tsv"
load_harness_sha256="$(sha256sum "${BASH_SOURCE[0]}" | awk '{print $1}')"
metrics_collector_sha256="$(sha256sum "${SCRIPT_DIR}/collect_runtime_metrics.sh" | awk '{print $1}')"

capture_runtime_identities() {
  local output_file="$1" container container_id image_id started_at restart_count
  : >"${output_file}"
  printf 'container\tcontainer_id\timage_id\tstarted_at\trestart_count\n' >>"${output_file}"
  for container in "${runtime_containers[@]}"; do
    container_id="$(docker inspect -f '{{.Id}}' "${container}")"
    image_id="$(docker inspect -f '{{.Image}}' "${container}")"
    started_at="$(docker inspect -f '{{.State.StartedAt}}' "${container}")"
    restart_count="$(docker inspect -f '{{.RestartCount}}' "${container}")"
    [[ "${container_id}" =~ ^[a-f0-9]{64}$ \
      && "${image_id}" =~ ^sha256:[a-f0-9]{64}$ \
      && -n "${started_at}" \
      && "${restart_count}" =~ ^[0-9]+$ ]] || return 1
    printf '%s\t%s\t%s\t%s\t%s\n' \
      "${container}" "${container_id}" "${image_id}" "${started_at}" "${restart_count}" >>"${output_file}"
  done
}

capture_runtime_identities "${runtime_identity_file}" \
  || { echo 'No se pudo capturar la identidad inicial de todos los runtimes.' >&2; exit 2; }
printf 'sample\tscheduled_epoch_ms\tstarted_epoch_ms\tfinished_epoch_ms\texit_code\tartifact\n' >"${metric_log}"

curl_args=(
  --silent --show-error --max-time "${TIMEOUT_SECONDS}"
  --output /dev/null --header 'Accept-Encoding: identity'
)
if [[ -n "${RESOLVE_IP}" && -z "${CA_CERT}" && -f "${WORKSPACE_DIR}/gatewayapisix/entorno/certs/local-ca.crt" ]]; then
  CA_CERT="${WORKSPACE_DIR}/gatewayapisix/entorno/certs/local-ca.crt"
fi
if [[ -n "${CA_CERT}" ]]; then
  [[ -r "${CA_CERT}" ]] || { echo 'CA_CERT no legible.' >&2; exit 2; }
  curl_args+=(--cacert "${CA_CERT}")
fi
if [[ -n "${RESOLVE_IP}" ]]; then
  curl_args+=(--noproxy '*')
  mapfile -t workload_hosts < <(printf '%s\n' "${targets[@]%%|*}" | LC_ALL=C sort -u)
  for target_host in "${workload_hosts[@]}"; do
    curl_args+=(--resolve "${target_host}:${port}:${RESOLVE_IP}")
  done
fi

started_epoch_ms="$(date +%s%3N)"
metrics_deadline_epoch_ms=$((started_epoch_ms + DURATION_SECONDS * 1000))
# Un segundo de guarda asegura que la ventana HTTP medida, no solo la vida de
# los procesos, cubra al menos DURATION_SECONDS aun con el arranque escalonado.
deadline_epoch_ms=$((metrics_deadline_epoch_ms + 1000))
started_utc="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

worker() {
  local worker_id="$1" sequence=0 path_index path target target_host target_url result status ttfb total bytes
  local curl_exit redirect_url remote_ip stagger_ms request_started_ms request_finished_ms
  local next_start_ms now_ms wait_ms wait_seconds
  local separator=$'\x1f'
  local worker_file="${OUTPUT_DIR}/workers/worker-${worker_id}.tsv"
  stagger_ms=$((worker_id * REQUEST_INTERVAL_MS / CONCURRENCY))
  next_start_ms=$((started_epoch_ms + stagger_ms))
  : >"${worker_file}"
  while :; do
    # El intervalo es una cadencia absoluta entre inicios, no think-time
    # agregado despues de la respuesta. Los workers comparten el mismo origen
    # y se distribuyen uniformemente dentro del primer segundo. Cada inicio
    # real ancla el siguiente; si una respuesta tarda mas que el intervalo, el
    # slot queda vencido y se continua sin crear una rafaga de catch-up.
    if (( REQUEST_INTERVAL_MS > 0 )); then
      (( next_start_ms <= deadline_epoch_ms )) || break
      now_ms="$(date +%s%3N)"
      if (( now_ms < next_start_ms )); then
        wait_ms=$((next_start_ms - now_ms))
        printf -v wait_seconds '%d.%03d' "$((wait_ms / 1000))" "$((wait_ms % 1000))"
        sleep "${wait_seconds}"
      fi
    fi
    path_index=$(((worker_id + sequence) % ${#targets[@]}))
    target="${targets[$path_index]}"
    target_host="${target%%|*}"
    path="${target#*|}"
    target_url="${target_urls[$path_index]}"
    request_started_ms="$(date +%s%3N)"
    if result="$(curl "${curl_args[@]}" \
      --write-out $'%{http_code}\x1f%{time_starttransfer}\x1f%{time_total}\x1f%{size_download}\x1f%{redirect_url}\x1f%{remote_ip}' \
      "${target_url}")"; then
      curl_exit=0
    else
      curl_exit=$?
    fi
    request_finished_ms="$(date +%s%3N)"
    IFS="${separator}" read -r status ttfb total bytes redirect_url remote_ip <<<"${result:-}"
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
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
      "${request_started_ms}" "${request_finished_ms}" "${worker_id}" "${sequence}" "${target_url}" "${status}" \
      "${ttfb}" "${total}" "${bytes}" "${curl_exit}" "${redirect_url}" "${remote_ip}" >>"${worker_file}"
    sequence=$((sequence + 1))
    (( request_finished_ms < deadline_epoch_ms )) || break
    if (( REQUEST_INTERVAL_MS > 0 )); then
      next_start_ms=$((request_started_ms + REQUEST_INTERVAL_MS))
    fi
  done
}

metric_sampler() {
  local sample=0 now_ms output scheduled_ms collector_started_ms collector_finished_ms collector_exit stderr_file sleep_seconds
  local -a collector_command=(nice -n 10)
  # El muestreo no debe competir con las solicitudes que observa. CPU siempre
  # queda en nice 10; I/O usa clase idle solo cuando el kernel/host la admite.
  if command -v ionice >/dev/null 2>&1 && ionice -c 3 true >/dev/null 2>&1; then
    collector_command=(ionice -c 3 nice -n 10)
  fi
  while (( started_epoch_ms + sample * METRICS_INTERVAL * 1000 <= metrics_deadline_epoch_ms )); do
    scheduled_ms=$((started_epoch_ms + sample * METRICS_INTERVAL * 1000))
    now_ms="$(date +%s%3N)"
    if (( now_ms < scheduled_ms )); then
      sleep_seconds="$(awk -v milliseconds="$((scheduled_ms - now_ms))" 'BEGIN {printf "%.3f", milliseconds/1000}')"
      sleep "${sleep_seconds}"
    fi
    output="${OUTPUT_DIR}/metrics/runtime-${sample}.prom"
    stderr_file="${OUTPUT_DIR}/metrics/runtime-${sample}.stderr"
    collector_started_ms="$(date +%s%3N)"
    if BASE_URL="${BASE_URL}" TENANT_SLUG="${TENANT_SLUG}" RESOLVE_IP="${RESOLVE_IP}" \
      RUNTIME_CONTAINERS="${runtime_containers[*]}" \
      CA_CERT="${CA_CERT}" TIMEOUT_SECONDS="${TIMEOUT_SECONDS}" \
      COLLECTION_PROFILE=load OUTPUT_FILE="${output}" \
      "${collector_command[@]}" "${SCRIPT_DIR}/collect_runtime_metrics.sh" >/dev/null 2>"${stderr_file}"; then
      collector_exit=0
    else
      collector_exit=$?
    fi
    collector_finished_ms="$(date +%s%3N)"
    printf '%s\t%s\t%s\t%s\t%s\t%s\n' \
      "${sample}" "${scheduled_ms}" "${collector_started_ms}" "${collector_finished_ms}" "${collector_exit}" "${output}" >>"${metric_log}"
    sample=$((sample + 1))
  done
}

metric_sampler &
sampler_pid=$!
worker_pids=()
for ((worker_id = 0; worker_id < CONCURRENCY; worker_id++)); do
  worker "${worker_id}" &
  worker_pids+=("$!")
done

interrupted=0
trap 'interrupted=1; for pid in "${worker_pids[@]:-}" "${sampler_pid:-}"; do kill "${pid}" 2>/dev/null || true; done' INT TERM
for pid in "${worker_pids[@]}"; do
  wait "${pid}" || interrupted=1
done
workers_finished_epoch_ms="$(date +%s%3N)"
wait "${sampler_pid}" || true
trap - INT TERM

printf 'started_epoch_ms\tfinished_epoch_ms\tworker\tsequence\ttarget_url\thttp_code\tttfb_seconds\ttotal_seconds\tbytes\tcurl_exit_code\tredirect_url\tremote_ip\n' >"${samples}"
sort -t $'\t' -k1,1n "${OUTPUT_DIR}/workers"/*.tsv >>"${samples}"
finished_utc="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
elapsed="$(awk -v milliseconds="$((workers_finished_epoch_ms - started_epoch_ms))" 'BEGIN {printf "%.3f", milliseconds/1000}')"

sample_count="$(awk 'NR > 1 {count++} END {print count+0}' "${samples}")"
success_count="$(awk -F '\t' 'NR > 1 && $6 ~ /^2[0-9][0-9]$/ && $10 == 0 {count++} END {print count+0}' "${samples}")"
error_count=$((sample_count - success_count))
transport_errors="$(awk -F '\t' 'NR > 1 && $10 != 0 {count++} END {print count+0}' "${samples}")"
redirect_count="$(awk -F '\t' 'NR > 1 && ($6 ~ /^3[0-9][0-9]$/ || length($11) > 0) {count++} END {print count+0}' "${samples}")"
remote_ip_mismatches=0
if [[ -n "${RESOLVE_IP}" ]]; then
  remote_ip_mismatches="$(awk -F '\t' -v expected="${RESOLVE_IP}" 'NR > 1 && $12 != expected {count++} END {print count+0}' "${samples}")"
fi
success_percent="$(awk -v ok="${success_count}" -v total="${sample_count}" 'BEGIN {printf "%.4f", total ? ok*100/total : 0}')"
measurement_window_seconds="$(awk -F '\t' 'NR > 1 {if (!seen || $1 < first) first=$1; if (!seen || $2 > final) final=$2; seen=1} END {value=0; if (seen && final > first) value=(final-first)/1000; printf "%.3f", value}' "${samples}")"
rps="$(awk -v total="${sample_count}" -v elapsed="${measurement_window_seconds}" 'BEGIN {rate=0; if (elapsed > 0) rate=total/elapsed; printf "%.2f", rate}')"
observed_max_concurrency="$(awk -F '\t' 'NR > 1 {print $1 "\t0\t1"; print $2 "\t1\t-1"}' "${samples}" \
  | sort -t $'\t' -k1,1n -k2,2n \
  | awk -F '\t' '{active += $3; if (active > maximum) maximum=active} END {print maximum+0}')"
observed_peak_request_starts_per_second="$(awk -F '\t' 'NR > 1 {bucket=int($1/1000); count[bucket]++} END {for (bucket in count) if (count[bucket] > maximum) maximum=count[bucket]; print maximum+0}' "${samples}")"

metrics_expected=$((DURATION_SECONDS / METRICS_INTERVAL + 1))
metrics_attempted="$(awk 'NR > 1 {count++} END {print count+0}' "${metric_log}")"
metrics_success="$(awk -F '\t' 'NR > 1 && $5 == 0 {count++} END {print count+0}' "${metric_log}")"
metrics_coverage_percent="$(awk -v success="${metrics_success}" -v expected="${metrics_expected}" 'BEGIN {printf "%.4f", expected ? success*100/expected : 0}')"
metrics_start_lag_max_seconds="$(awk -F '\t' 'NR > 1 {lag=($3-$2)/1000; if (lag > maximum) maximum=lag} END {printf "%.3f", maximum+0}' "${metric_log}")"
metrics_collection_duration_max_seconds="$(awk -F '\t' 'NR > 1 {duration=($4-$3)/1000; if (duration > maximum) maximum=duration} END {printf "%.3f", maximum+0}' "${metric_log}")"
shopt -s nullglob
metric_files=("${OUTPUT_DIR}/metrics"/*.prom)
shopt -u nullglob

percentile() {
  local path_filter="$1" percentile="$2"
  percentile_field "${path_filter}" "${percentile}" 8
}

percentile_field() {
  local path_filter="$1" percentile="$2" field="$3"
  awk -F '\t' -v path="${path_filter}" -v field="${field}" 'NR > 1 && (path == "*" || $5 == path) {print $field}' "${samples}" \
    | sort -n \
    | awk -v pct="${percentile}" '{v[NR]=$1} END {if (NR == 0) {printf "0.000"; exit} i=int((NR*pct+99)/100); if(i<1)i=1; if(i>NR)i=NR; printf "%.3f", v[i]}'
}

metric_extreme() {
  local metric_prefix="$1" mode="${2:-max}"
  if (( ${#metric_files[@]} == 0 )); then
    echo 0
    return
  fi
  awk -v prefix="${metric_prefix}" -v mode="${mode}" '
    $1 == prefix && $2 ~ /^-?[0-9]+([.][0-9]+)?$/ {
      if (!seen || (mode == "min" ? $2 < value : $2 > value)) { value=$2 }
      seen=1
    }
    END { if (seen) print value; else print 0 }
  ' "${metric_files[@]}"
}

metric_delta() {
  local metric_prefix="$1" minimum maximum
  minimum="$(metric_extreme "${metric_prefix}" min)"
  maximum="$(metric_extreme "${metric_prefix}" max)"
  awk -v minimum="${minimum}" -v maximum="${maximum}" 'BEGIN {printf "%.6f", maximum-minimum}'
}

metric_sample_count() {
  local metric_prefix="$1"
  if (( ${#metric_files[@]} == 0 )); then
    echo 0
    return
  fi
  awk -v prefix="${metric_prefix}" '$1 == prefix {count++} END {print count+0}' "${metric_files[@]}"
}

overall_p50="$(percentile '*' 50)"
overall_p95="$(percentile '*' 95)"
overall_p99="$(percentile '*' 99)"
overall_ttfb_p95="$(percentile_field '*' 95 7)"
overall_ttfb_p99="$(percentile_field '*' 99 7)"
total_response_bytes="$(awk -F '\t' 'NR > 1 {sum += $9} END {printf "%.0f", sum+0}' "${samples}")"
average_response_bytes="$(awk -F '\t' 'NR > 1 {sum += $9; count++} END {average=0; if (count) average=sum/count; printf "%.0f", average}' "${samples}")"
max_response_bytes="$(awk -F '\t' 'NR > 1 && $9 > maximum {maximum=$9} END {printf "%.0f", maximum+0}' "${samples}")"
fpm_scrape_min="$(metric_extreme 'paramascotas_php_fpm_scrape_success' min)"
fpm_listen_queue_max="$(metric_extreme 'paramascotas_php_fpm_listen_queue' max)"
fpm_max_children_delta="$(metric_delta 'paramascotas_php_fpm_max_children_reached_total')"
fpm_slow_requests_delta="$(metric_delta 'paramascotas_php_fpm_slow_requests_total')"
postgres_scrape_min="$(metric_extreme 'paramascotas_postgres_scrape_success' min)"
postgres_scrape_attempts_max="$(metric_extreme 'paramascotas_postgres_scrape_attempts' max)"
postgres_transport_failures_max="$(metric_extreme 'paramascotas_postgres_scrape_transport_failures' max)"
postgres_netns_samples="$(metric_sample_count 'paramascotas_postgres_scrape_transport{method="netns_psql"}')"
postgres_docker_exec_samples="$(metric_sample_count 'paramascotas_postgres_scrape_transport{method="docker_exec"}')"
postgres_unknown_transport_samples="$(metric_sample_count 'paramascotas_postgres_scrape_transport{method="unknown"}')"
postgres_connection_utilization_max="$(metric_extreme 'paramascotas_postgres_connection_utilization_percent' max)"
postgres_lock_waiters_max="$(metric_extreme 'paramascotas_postgres_lock_waiters' max)"
postgres_ungranted_locks_max="$(metric_extreme 'paramascotas_postgres_ungranted_locks' max)"
postgres_long_transactions_max="$(metric_extreme 'paramascotas_postgres_long_transactions' max)"
postgres_longest_query_max="$(metric_extreme 'paramascotas_postgres_longest_active_query_seconds' max)"
postgres_deadlocks_delta="$(metric_delta 'paramascotas_postgres_deadlocks_total')"
{
  printf 'SUSTAINED_MIXED_LOAD version=4 started=%s finished=%s duration_seconds=%s measurement_window_seconds=%s configured_concurrency=%s observed_max_concurrency=%s observed_peak_starts_per_second=%s theoretical_rps=%s requests=%s success=%s errors=%s transport_errors=%s redirects=%s remote_ip_mismatches=%s success_pct=%s rps=%s p50_seconds=%s p95_seconds=%s p99_seconds=%s ttfb_p95_seconds=%s ttfb_p99_seconds=%s bytes_total=%s bytes_avg=%s bytes_max=%s\n' \
    "${started_utc}" "${finished_utc}" "${elapsed}" "${measurement_window_seconds}" "${CONCURRENCY}" "${observed_max_concurrency}" "${observed_peak_request_starts_per_second}" "${theoretical_rps}" \
    "${sample_count}" "${success_count}" "${error_count}" "${transport_errors}" \
    "${redirect_count}" "${remote_ip_mismatches}" "${success_percent}" "${rps}" \
    "${overall_p50}" "${overall_p95}" "${overall_p99}" "${overall_ttfb_p95}" "${overall_ttfb_p99}" \
    "${total_response_bytes}" "${average_response_bytes}" "${max_response_bytes}"
  awk -F '\t' 'NR > 1 {count[$6]++} END {for (code in count) printf "STATUS code=%s count=%s\n", code, count[code]}' "${samples}" | sort
  while IFS= read -r path; do
    [[ -n "${path}" ]] || continue
    path_count="$(awk -F '\t' -v path="${path}" 'NR > 1 && $5 == path {count++} END {print count+0}' "${samples}")"
    path_success="$(awk -F '\t' -v path="${path}" 'NR > 1 && $5 == path && $6 ~ /^2[0-9][0-9]$/ && $10 == 0 {count++} END {print count+0}' "${samples}")"
    path_success_percent="$(awk -v success="${path_success}" -v total="${path_count}" 'BEGIN {printf "%.4f", total ? success*100/total : 0}')"
    path_redirects="$(awk -F '\t' -v path="${path}" 'NR > 1 && $5 == path && ($6 ~ /^3[0-9][0-9]$/ || length($11) > 0) {count++} END {print count+0}' "${samples}")"
    path_average_bytes="$(awk -F '\t' -v path="${path}" 'NR > 1 && $5 == path {sum += $9; count++} END {average=0; if (count) average=sum/count; printf "%.0f", average}' "${samples}")"
    path_max_bytes="$(awk -F '\t' -v path="${path}" 'NR > 1 && $5 == path && $9 > maximum {maximum=$9} END {printf "%.0f", maximum+0}' "${samples}")"
    printf 'ROUTE target=%s requests=%s success=%s success_pct=%s redirects=%s p50_seconds=%s p95_seconds=%s p99_seconds=%s ttfb_p95_seconds=%s ttfb_p99_seconds=%s bytes_avg=%s bytes_max=%s\n' \
      "${path}" "${path_count}" "${path_success}" "${path_success_percent}" "${path_redirects}" "$(percentile "${path}" 50)" \
      "$(percentile "${path}" 95)" "$(percentile "${path}" 99)" \
      "$(percentile_field "${path}" 95 7)" "$(percentile_field "${path}" 99 7)" \
      "${path_average_bytes}" "${path_max_bytes}"
  done < <(printf '%s\n' "${target_urls[@]}" | sort -u)
  for container in "${runtime_containers[@]}"; do
    printf 'RUNTIME container=%s cpu_max_pct=%s memory_max_pct=%s restart_delta=%s started_at_min=%s started_at_max=%s\n' \
      "${container}" \
      "$(metric_extreme "paramascotas_container_cpu_percent{name=\"${container}\"}" max)" \
      "$(metric_extreme "paramascotas_container_memory_percent{name=\"${container}\"}" max)" \
      "$(metric_delta "paramascotas_container_restart_count{name=\"${container}\"}")" \
      "$(metric_extreme "paramascotas_container_started_at_seconds{name=\"${container}\"}" min)" \
      "$(metric_extreme "paramascotas_container_started_at_seconds{name=\"${container}\"}" max)"
  done
  printf 'METRICS collection_profile=load expected=%s attempted=%s successful=%s artifacts=%s coverage_pct=%s start_lag_max_seconds=%s collection_duration_max_seconds=%s docker_stats_min=%s\n' \
    "${metrics_expected}" "${metrics_attempted}" "${metrics_success}" "${#metric_files[@]}" \
    "${metrics_coverage_percent}" "${metrics_start_lag_max_seconds}" "${metrics_collection_duration_max_seconds}" \
    "$(metric_extreme 'paramascotas_docker_stats_scrape_success' min)"
  printf 'FPM scrape_min=%s active_max=%s total_max=%s listen_queue_max=%s accepted_connections_delta=%s max_children_reached_delta=%s slow_requests_delta=%s\n' \
    "${fpm_scrape_min}" \
    "$(metric_extreme 'paramascotas_php_fpm_active_processes' max)" \
    "$(metric_extreme 'paramascotas_php_fpm_total_processes' max)" \
    "${fpm_listen_queue_max}" \
    "$(metric_delta 'paramascotas_php_fpm_accepted_connections_total')" \
    "${fpm_max_children_delta}" "${fpm_slow_requests_delta}"
  printf 'POSTGRES scrape_min=%s scrape_attempts_max=%s transport_failures_max=%s netns_samples=%s docker_exec_samples=%s connections_max=%s active_max=%s utilization_max_pct=%s cache_hit_min_pct=%s lock_waiters_max=%s ungranted_locks_max=%s long_transactions_max=%s longest_query_max_seconds=%s commits_delta=%s rollbacks_delta=%s temp_bytes_delta=%s deadlocks_delta=%s\n' \
    "${postgres_scrape_min}" "${postgres_scrape_attempts_max}" \
    "${postgres_transport_failures_max}" "${postgres_netns_samples}" \
    "${postgres_docker_exec_samples}" \
    "$(metric_extreme 'paramascotas_postgres_connections{state="total"}' max)" \
    "$(metric_extreme 'paramascotas_postgres_connections{state="active"}' max)" \
    "${postgres_connection_utilization_max}" \
    "$(metric_extreme 'paramascotas_postgres_cache_hit_percent' min)" \
    "${postgres_lock_waiters_max}" "${postgres_ungranted_locks_max}" \
    "${postgres_long_transactions_max}" "${postgres_longest_query_max}" \
    "$(metric_delta 'paramascotas_postgres_transactions_total{result="commit"}')" \
    "$(metric_delta 'paramascotas_postgres_transactions_total{result="rollback"}')" \
    "$(metric_delta 'paramascotas_postgres_temp_bytes_total')" "${postgres_deadlocks_delta}"
} | tee "${summary}"

failed="${interrupted}"
if ! awk -v actual="${elapsed}" -v configured="${DURATION_SECONDS}" 'BEGIN {exit(actual >= configured ? 0 : 1)}'; then
  echo "SLO_VIOLATION duration_seconds=${elapsed} configured=${DURATION_SECONDS}" >&2
  failed=1
fi
if ! awk -v actual="${measurement_window_seconds}" -v configured="${DURATION_SECONDS}" 'BEGIN {exit(actual >= configured ? 0 : 1)}'; then
  echo "SLO_VIOLATION measurement_window_seconds=${measurement_window_seconds} configured=${DURATION_SECONDS}" >&2
  failed=1
fi
if (( sample_count < MIN_SAMPLE_COUNT )); then
  echo "SLO_VIOLATION requests=${sample_count} minimum=${MIN_SAMPLE_COUNT}; percentiles/no-error confidence insufficient." >&2
  failed=1
fi
if (( error_count > MAX_ERRORS )); then
  echo "SLO_VIOLATION errors=${error_count} maximum=${MAX_ERRORS}" >&2
  failed=1
fi
if ! awk -v actual="${success_percent}" -v minimum="${MIN_SUCCESS_PERCENT}" 'BEGIN {exit(actual >= minimum ? 0 : 1)}'; then
  echo "SLO_VIOLATION success_pct=${success_percent} minimum=${MIN_SUCCESS_PERCENT}" >&2
  failed=1
fi
if ! awk -v actual="${overall_p95}" -v maximum="${MAX_P95_SECONDS}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION p95_seconds=${overall_p95} maximum=${MAX_P95_SECONDS}" >&2
  failed=1
fi
if ! awk -v actual="${overall_p99}" -v maximum="${MAX_P99_SECONDS}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION p99_seconds=${overall_p99} maximum=${MAX_P99_SECONDS}" >&2
  failed=1
fi
if (( redirect_count > MAX_REDIRECTS )); then
  echo "SLO_VIOLATION redirects=${redirect_count} maximum=${MAX_REDIRECTS}" >&2
  failed=1
fi
if (( remote_ip_mismatches > 0 )); then
  echo "SLO_VIOLATION remote_ip_mismatches=${remote_ip_mismatches} expected=${RESOLVE_IP}" >&2
  failed=1
fi
if ! awk -v actual="${observed_peak_request_starts_per_second}" -v maximum="${MAX_CLIENT_RPS}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION observed_peak_request_starts_per_second=${observed_peak_request_starts_per_second} maximum=${MAX_CLIENT_RPS}" >&2
  failed=1
fi
while IFS= read -r path; do
  [[ -n "${path}" ]] || continue
  path_count="$(awk -F '\t' -v path="${path}" 'NR > 1 && $5 == path {count++} END {print count+0}' "${samples}")"
  path_success="$(awk -F '\t' -v path="${path}" 'NR > 1 && $5 == path && $6 ~ /^2[0-9][0-9]$/ && $10 == 0 {count++} END {print count+0}' "${samples}")"
  path_success_percent="$(awk -v success="${path_success}" -v total="${path_count}" 'BEGIN {printf "%.4f", total ? success*100/total : 0}')"
  path_p95="$(percentile "${path}" 95)"
  path_p99="$(percentile "${path}" 99)"
  if (( path_count < MIN_ROUTE_SAMPLES )); then
    echo "SLO_VIOLATION path=${path} requests=${path_count} minimum=${MIN_ROUTE_SAMPLES}" >&2
    failed=1
  fi
  if ! awk -v actual="${path_success_percent}" -v minimum="${MIN_SUCCESS_PERCENT}" 'BEGIN {exit(actual >= minimum ? 0 : 1)}'; then
    echo "SLO_VIOLATION path=${path} success_pct=${path_success_percent} minimum=${MIN_SUCCESS_PERCENT}" >&2
    failed=1
  fi
  if ! awk -v actual="${path_p95}" -v maximum="${MAX_P95_SECONDS}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
    echo "SLO_VIOLATION path=${path} p95_seconds=${path_p95} maximum=${MAX_P95_SECONDS}" >&2
    failed=1
  fi
  if ! awk -v actual="${path_p99}" -v maximum="${MAX_P99_SECONDS}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
    echo "SLO_VIOLATION path=${path} p99_seconds=${path_p99} maximum=${MAX_P99_SECONDS}" >&2
    failed=1
  fi
done < <(printf '%s\n' "${target_urls[@]}" | sort -u)
if ! awk -v actual="${metrics_coverage_percent}" -v minimum="${MIN_METRICS_COVERAGE_PERCENT}" 'BEGIN {exit(actual >= minimum ? 0 : 1)}'; then
  echo "SLO_VIOLATION metrics_coverage_pct=${metrics_coverage_percent} minimum=${MIN_METRICS_COVERAGE_PERCENT}" >&2
  failed=1
fi
if ! awk -v actual="${metrics_start_lag_max_seconds}" -v maximum="${MAX_METRIC_START_LAG_SECONDS}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION metric_start_lag_seconds=${metrics_start_lag_max_seconds} maximum=${MAX_METRIC_START_LAG_SECONDS}" >&2
  failed=1
fi
if ! awk -v actual="${metrics_collection_duration_max_seconds}" -v maximum="${METRICS_INTERVAL}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION metric_collection_duration_seconds=${metrics_collection_duration_max_seconds} interval=${METRICS_INTERVAL}" >&2
  failed=1
fi
if (( metrics_success != ${#metric_files[@]} )); then
  echo "SLO_VIOLATION metric_success=${metrics_success} artifacts=${#metric_files[@]}" >&2
  failed=1
fi
if [[ "$(metric_extreme 'paramascotas_docker_stats_scrape_success' min)" != "1" ]]; then
  echo 'SLO_VIOLATION no se obtuvieron metricas Docker durante toda la carga.' >&2
  failed=1
fi
if [[ "$(metric_extreme 'paramascotas_collector_profile{profile="load"}' min)" != "1" \
  || "$(metric_sample_count 'paramascotas_collector_profile{profile="load"}')" -ne "${metrics_success}" ]]; then
  echo 'SLO_VIOLATION el muestreo sostenido no uso COLLECTION_PROFILE=load en todos los snapshots.' >&2
  failed=1
fi
if [[ "${fpm_scrape_min}" != "1" ]]; then
  echo 'SLO_VIOLATION no se obtuvieron metricas PHP-FPM durante toda la carga.' >&2
  failed=1
fi
if [[ "${postgres_scrape_min}" != "1" ]]; then
  echo 'SLO_VIOLATION no se obtuvieron metricas PostgreSQL durante toda la carga.' >&2
  failed=1
fi
for required_metric in \
  paramascotas_php_fpm_listen_queue \
  paramascotas_php_fpm_max_children_reached_total \
  paramascotas_php_fpm_slow_requests_total \
  paramascotas_postgres_connection_utilization_percent \
  paramascotas_postgres_scrape_attempts \
  paramascotas_postgres_scrape_transport_failures \
  paramascotas_postgres_lock_waiters \
  paramascotas_postgres_ungranted_locks \
  paramascotas_postgres_long_transactions \
  paramascotas_postgres_longest_active_query_seconds \
  paramascotas_postgres_deadlocks_total; do
  if [[ "$(metric_sample_count "${required_metric}")" -ne "${metrics_success}" ]]; then
    echo "SLO_VIOLATION metric_samples_incomplete metric=${required_metric}" >&2
    failed=1
  fi
done
if (( postgres_netns_samples + postgres_docker_exec_samples + postgres_unknown_transport_samples != metrics_success )); then
  echo "SLO_VIOLATION postgres_transport_samples=$((postgres_netns_samples + postgres_docker_exec_samples + postgres_unknown_transport_samples)) expected=${metrics_success}" >&2
  failed=1
fi
if ! awk -v actual="${postgres_scrape_attempts_max}" 'BEGIN {exit(actual >= 1 && actual <= 3 ? 0 : 1)}'; then
  echo "SLO_VIOLATION postgres_scrape_attempts_max=${postgres_scrape_attempts_max} allowed=1..3" >&2
  failed=1
fi
for probe in \
  'paramascotas_http_probe_success{name="api_health",path="/'"${TENANT_SLUG}"'/api/health"}' \
  'paramascotas_http_probe_success{name="storefront_home",path="/"}' \
  'paramascotas_http_probe_success{name="public_catalog",path="/'"${TENANT_SLUG}"'/api/products"}'; do
  if [[ "$(metric_extreme "${probe}" min)" != "1" || "$(metric_sample_count "${probe}")" -ne "${metrics_success}" ]]; then
    echo "SLO_VIOLATION runtime_probe_unavailable metric=${probe}" >&2
    failed=1
  fi
done
if ! awk -v actual="${fpm_listen_queue_max}" -v maximum="${MAX_FPM_LISTEN_QUEUE}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION fpm_listen_queue_max=${fpm_listen_queue_max} maximum=${MAX_FPM_LISTEN_QUEUE}" >&2
  failed=1
fi
if ! awk -v actual="${fpm_max_children_delta}" -v maximum="${MAX_FPM_MAX_CHILDREN_REACHED_DELTA}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION fpm_max_children_reached_delta=${fpm_max_children_delta} maximum=${MAX_FPM_MAX_CHILDREN_REACHED_DELTA}" >&2
  failed=1
fi
if ! awk -v actual="${fpm_slow_requests_delta}" -v maximum="${MAX_FPM_SLOW_REQUESTS_DELTA}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION fpm_slow_requests_delta=${fpm_slow_requests_delta} maximum=${MAX_FPM_SLOW_REQUESTS_DELTA}" >&2
  failed=1
fi
for metric_and_value in \
  "postgres_lock_waiters:${postgres_lock_waiters_max}" \
  "postgres_ungranted_locks:${postgres_ungranted_locks_max}" \
  "postgres_long_transactions:${postgres_long_transactions_max}" \
  "postgres_deadlocks_delta:${postgres_deadlocks_delta}"; do
  if ! awk -v actual="${metric_and_value#*:}" 'BEGIN {exit(actual == 0 ? 0 : 1)}'; then
    echo "SLO_VIOLATION ${metric_and_value%%:*}=${metric_and_value#*:}" >&2
    failed=1
  fi
done
if ! awk -v actual="${postgres_connection_utilization_max}" -v maximum="${MAX_POSTGRES_CONNECTION_UTILIZATION_PERCENT}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION postgres_connection_utilization_pct=${postgres_connection_utilization_max} maximum=${MAX_POSTGRES_CONNECTION_UTILIZATION_PERCENT}" >&2
  failed=1
fi
if ! awk -v actual="${postgres_longest_query_max}" -v maximum="${MAX_POSTGRES_LONGEST_QUERY_SECONDS}" 'BEGIN {exit(actual <= maximum ? 0 : 1)}'; then
  echo "SLO_VIOLATION postgres_longest_query_seconds=${postgres_longest_query_max} maximum=${MAX_POSTGRES_LONGEST_QUERY_SECONDS}" >&2
  failed=1
fi
for container in "${runtime_containers[@]}"; do
  up_metric="paramascotas_container_up{name=\"${container}\"}"
  cpu_metric="paramascotas_container_cpu_percent{name=\"${container}\"}"
  memory_metric="paramascotas_container_memory_percent{name=\"${container}\"}"
  start_metric="paramascotas_container_started_at_seconds{name=\"${container}\"}"
  restart_metric="paramascotas_container_restart_count{name=\"${container}\"}"
  if [[ "$(metric_extreme "${up_metric}" min)" != "1" \
    || "$(metric_sample_count "${up_metric}")" -ne "${metrics_success}" \
    || "$(metric_sample_count "${cpu_metric}")" -ne "${metrics_success}" \
    || "$(metric_sample_count "${memory_metric}")" -ne "${metrics_success}" \
    || "$(metric_sample_count "${start_metric}")" -ne "${metrics_success}" \
    || "$(metric_sample_count "${restart_metric}")" -ne "${metrics_success}" \
    || "$(metric_extreme "${start_metric}" min)" == "0" ]]; then
    echo "SLO_VIOLATION container_metrics_incomplete name=${container}" >&2
    failed=1
  fi
  if [[ "$(metric_extreme "${start_metric}" min)" != "$(metric_extreme "${start_metric}" max)" \
    || "$(metric_delta "${restart_metric}")" != "0.000000" ]]; then
    echo "SLO_VIOLATION container_restarted_during_load name=${container}" >&2
    failed=1
  fi
done
for container in "${healthy_runtime_containers[@]}"; do
  healthy_metric="paramascotas_container_health{name=\"${container}\",status=\"healthy\"}"
  if [[ "$(metric_sample_count "${healthy_metric}")" -ne "${metrics_success}" ]]; then
    echo "SLO_VIOLATION container_not_healthy_during_load name=${container}" >&2
    failed=1
  fi
done
if ! capture_runtime_identities "${runtime_identity_after}"; then
  echo 'SLO_VIOLATION no se pudo capturar la identidad final de los runtimes.' >&2
  failed=1
elif ! cmp -s "${runtime_identity_file}" "${runtime_identity_after}"; then
  echo 'SLO_VIOLATION la identidad, imagen, arranque o restart count de un runtime cambio durante la carga.' >&2
  failed=1
fi
rm -f "${runtime_identity_after}"

canonical_evidence_contract=false
if (( DURATION_SECONDS == 600 \
  && CONCURRENCY == 8 \
  && METRICS_INTERVAL == 30 \
  && REQUEST_INTERVAL_MS == 1000 \
  && MIN_SAMPLE_COUNT >= 3000 \
  && MIN_ROUTE_SAMPLES >= 200 \
  && MAX_ERRORS == 0 \
  && MAX_REDIRECTS == 0 )) \
  && awk -v success="${MIN_SUCCESS_PERCENT}" \
    -v p95="${MAX_P95_SECONDS}" \
    -v p99="${MAX_P99_SECONDS}" \
    -v metrics="${MIN_METRICS_COVERAGE_PERCENT}" \
    -v clientRps="${MAX_CLIENT_RPS}" \
    -v fpmQueue="${MAX_FPM_LISTEN_QUEUE}" \
    -v fpmChildren="${MAX_FPM_MAX_CHILDREN_REACHED_DELTA}" \
    -v fpmSlow="${MAX_FPM_SLOW_REQUESTS_DELTA}" \
    -v pgUtil="${MAX_POSTGRES_CONNECTION_UTILIZATION_PERCENT}" \
    -v pgLongest="${MAX_POSTGRES_LONGEST_QUERY_SECONDS}" \
    -v metricLag="${MAX_METRIC_START_LAG_SECONDS}" \
    'BEGIN {ok = success >= 99.9 && p95 <= 1.0 && p99 <= 2.0 && metrics == 100.0 && clientRps <= 9.0 && fpmQueue == 0 && fpmChildren == 0 && fpmSlow == 0 && pgUtil <= 80.0 && pgLongest <= 30.0 && metricLag <= 2.0; exit(ok ? 0 : 1)}' \
  && [[ "${RESOLVE_IP}" == "$(gateway_env_value GATEWAY_BIND_IP)" ]] \
  && [[ "${workload_customized}" == "false" ]] \
  && [[ "$(dirname "${OUTPUT_DIR}")" == "${WORKSPACE_DIR}/reports/load" ]] \
  && [[ "${ALLOW_SHORT_TEST,,}" == "false" ]] \
  && [[ "${ALLOW_RATE_LIMIT_TEST,,}" == "false" ]] \
  && [[ "${ALLOW_INSECURE_HTTP_TEST,,}" == "false" ]]; then
  canonical_evidence_contract=true
fi

evidence_eligible=false
if (( failed == 0 )) \
  && [[ "${canonical_evidence_contract}" == "true" ]] \
  && awk -v elapsed="${elapsed}" -v window="${measurement_window_seconds}" 'BEGIN {exit(elapsed >= 600 && window >= 600 ? 0 : 1)}'; then
  evidence_eligible=true
fi
if (( failed == 0 )); then result_status=PASS; else result_status=FAIL; fi
printf 'RESULT status=%s evidence_eligible=%s\n' "${result_status}" "${evidence_eligible}" | tee -a "${summary}"

php -r '
$routeSampleCounts = [];
$sampleHandle = fopen($argv[44], "rb");
if ($sampleHandle === false) { throw new RuntimeException("cannot read HTTP samples"); }
$header = fgetcsv($sampleHandle, 0, "\t", "\"", "");
while (($row = fgetcsv($sampleHandle, 0, "\t", "\"", "")) !== false) {
  if (count($row) < 5) { continue; }
  $path = (string)$row[4];
  $routeSampleCounts[$path] = ($routeSampleCounts[$path] ?? 0) + 1;
}
fclose($sampleHandle);
ksort($routeSampleCounts);
$runtimeInstances = [];
$identityHandle = fopen($argv[69], "rb");
if ($identityHandle === false) { throw new RuntimeException("cannot read runtime identities"); }
$identityHeader = fgetcsv($identityHandle, 0, "\t", "\"", "");
while (($row = fgetcsv($identityHandle, 0, "\t", "\"", "")) !== false) {
  if (count($row) !== 5) { throw new RuntimeException("invalid runtime identity row"); }
  [$container, $containerId, $imageId, $startedAt, $restartCount] = $row;
  $runtimeInstances[$container] = [
    "container_id" => $containerId,
    "image_id" => $imageId,
    "started_at" => $startedAt,
    "restart_count" => (int)$restartCount,
  ];
}
fclose($identityHandle);
ksort($runtimeInstances);
$payload = [
  "version" => 4,
  "status" => $argv[1],
  "evidence_eligible" => $argv[2] === "true",
  "started_at" => $argv[3],
  "finished_at" => $argv[4],
  "duration_seconds" => (float)$argv[5],
  "configured_duration_seconds" => (int)$argv[6],
  "measurement_window_seconds" => (float)$argv[7],
  "configured_concurrency" => (int)$argv[8],
  "configured_metrics_interval_seconds" => (int)$argv[47],
  "observed_max_concurrency" => (int)$argv[9],
  "request_interval_ms" => (int)$argv[10],
  "request_pacing" => [
    "mode" => "absolute_start_cadence",
    "missed_slot_policy" => "skip_without_catch_up",
    "worker_phase_policy" => "uniform_stagger",
  ],
  "theoretical_max_rps" => is_numeric($argv[11]) ? (float)$argv[11] : null,
  "request_count" => (int)$argv[12],
  "success_count" => (int)$argv[13],
  "error_count" => (int)$argv[14],
  "transport_error_count" => (int)$argv[15],
  "redirect_count" => (int)$argv[16],
  "remote_ip_mismatch_count" => (int)$argv[17],
  "success_percent" => (float)$argv[18],
  "requests_per_second" => (float)$argv[19],
  "p50_seconds" => (float)$argv[20],
  "p95_seconds" => (float)$argv[21],
  "p99_seconds" => (float)$argv[22],
  "metrics" => [
    "collection_profile" => "load",
    "expected" => (int)$argv[23],
    "attempted" => (int)$argv[24],
    "successful" => (int)$argv[25],
    "coverage_percent" => (float)$argv[26],
    "max_start_lag_seconds" => (float)$argv[27],
    "max_collection_duration_seconds" => (float)$argv[28],
  ],
  "thresholds" => [
    "minimum_requests" => (int)$argv[29],
    "minimum_route_requests" => (int)$argv[30],
    "minimum_success_percent" => (float)$argv[31],
    "maximum_p95_seconds" => (float)$argv[32],
    "maximum_p99_seconds" => (float)$argv[33],
    "maximum_redirects" => (int)$argv[45],
    "maximum_errors" => (int)$argv[48],
    "minimum_metrics_coverage_percent" => (float)$argv[46],
    "maximum_client_rps" => (float)$argv[49],
    "maximum_fpm_listen_queue" => (float)$argv[50],
    "maximum_fpm_max_children_reached_delta" => (float)$argv[51],
    "maximum_fpm_slow_requests_delta" => (float)$argv[52],
    "maximum_postgres_connection_utilization_percent" => (float)$argv[53],
    "maximum_postgres_longest_query_seconds" => (float)$argv[54],
    "maximum_metric_start_lag_seconds" => (float)$argv[55],
  ],
  "runtime_observations" => [
    "php_fpm" => [
      "scrape_success_min" => (float)$argv[56],
      "listen_queue_max" => (float)$argv[57],
      "max_children_reached_delta" => (float)$argv[58],
      "slow_requests_delta" => (float)$argv[59],
    ],
    "postgres" => [
      "scrape_success_min" => (float)$argv[60],
      "connection_utilization_max_percent" => (float)$argv[61],
      "lock_waiters_max" => (float)$argv[62],
      "ungranted_locks_max" => (float)$argv[63],
      "long_transactions_max" => (float)$argv[64],
      "longest_query_max_seconds" => (float)$argv[65],
      "deadlocks_delta" => (float)$argv[66],
    ],
  ],
  "test_overrides" => [
    "short_duration" => strtolower($argv[34]) === "true",
    "rate_limit" => strtolower($argv[35]) === "true",
    "insecure_http" => strtolower($argv[36]) === "true",
  ],
  "target" => [
    "base_url" => $argv[37],
    "tenant_slug" => $argv[38],
    "resolved_ip" => $argv[39] !== "" ? $argv[39] : null,
  ],
  "observed_peak_request_starts_per_second" => (int)$argv[40],
  "runtime_containers" => array_values(array_filter(explode(",", $argv[41]), static fn(string $value): bool => $value !== "")),
  "runtime_instances" => $runtimeInstances,
  "workload_targets" => array_values(array_filter(explode(",", $argv[42]), static fn(string $value): bool => $value !== "")),
  "workload_contract" => [
    "customized" => strtolower($argv[67]) === "true",
    "weighted_target_set_sha256" => $argv[68],
  ],
  "route_sample_counts" => $routeSampleCounts,
  "provenance" => [
    "load_harness_sha256" => $argv[70],
    "metrics_collector_sha256" => $argv[71],
  ],
];
$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
$manifestPath = $argv[43];
$temporary = $manifestPath . ".tmp." . getmypid();
file_put_contents($temporary, $encoded, LOCK_EX);
if (!rename($temporary, $manifestPath)) { @unlink($temporary); throw new RuntimeException("manifest rename failed"); }
' "${result_status}" "${evidence_eligible}" "${started_utc}" "${finished_utc}" "${elapsed}" \
  "${DURATION_SECONDS}" "${measurement_window_seconds}" "${CONCURRENCY}" "${observed_max_concurrency}" \
  "${REQUEST_INTERVAL_MS}" "${theoretical_rps}" \
  "${sample_count}" "${success_count}" "${error_count}" "${transport_errors}" "${redirect_count}" \
  "${remote_ip_mismatches}" "${success_percent}" "${rps}" "${overall_p50}" "${overall_p95}" \
  "${overall_p99}" "${metrics_expected}" "${metrics_attempted}" "${metrics_success}" \
  "${metrics_coverage_percent}" "${metrics_start_lag_max_seconds}" "${metrics_collection_duration_max_seconds}" \
  "${MIN_SAMPLE_COUNT}" "${MIN_ROUTE_SAMPLES}" \
  "${MIN_SUCCESS_PERCENT}" "${MAX_P95_SECONDS}" "${MAX_P99_SECONDS}" \
  "${ALLOW_SHORT_TEST}" "${ALLOW_RATE_LIMIT_TEST}" "${ALLOW_INSECURE_HTTP_TEST}" \
  "${BASE_URL}" "${TENANT_SLUG}" "${RESOLVE_IP}" "${observed_peak_request_starts_per_second}" \
  "$(IFS=,; printf '%s' "${runtime_containers[*]}")" \
  "$(IFS=,; printf '%s' "${target_urls[*]}")" "${manifest}" "${samples}" \
  "${MAX_REDIRECTS}" "${MIN_METRICS_COVERAGE_PERCENT}" \
  "${METRICS_INTERVAL}" "${MAX_ERRORS}" "${MAX_CLIENT_RPS}" \
  "${MAX_FPM_LISTEN_QUEUE}" "${MAX_FPM_MAX_CHILDREN_REACHED_DELTA}" "${MAX_FPM_SLOW_REQUESTS_DELTA}" \
  "${MAX_POSTGRES_CONNECTION_UTILIZATION_PERCENT}" "${MAX_POSTGRES_LONGEST_QUERY_SECONDS}" \
  "${MAX_METRIC_START_LAG_SECONDS}" "${fpm_scrape_min}" "${fpm_listen_queue_max}" \
  "${fpm_max_children_delta}" "${fpm_slow_requests_delta}" "${postgres_scrape_min}" \
  "${postgres_connection_utilization_max}" "${postgres_lock_waiters_max}" \
  "${postgres_ungranted_locks_max}" "${postgres_long_transactions_max}" \
  "${postgres_longest_query_max}" "${postgres_deadlocks_delta}" "${workload_customized}" \
  "${workload_target_set_hash}" "${runtime_identity_file}" "${load_harness_sha256}" "${metrics_collector_sha256}"

checksums_tmp="${OUTPUT_DIR}/SHA256SUMS.tmp.$$"
(
  cd "${OUTPUT_DIR}"
  find . -type f ! -name 'SHA256SUMS' ! -name 'SHA256SUMS.tmp.*' -print0 \
    | sort -z \
    | xargs -0 sha256sum
) >"${checksums_tmp}"
mv -f "${checksums_tmp}" "${OUTPUT_DIR}/SHA256SUMS"

exit "${failed}"
