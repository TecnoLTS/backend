#!/usr/bin/env bash

set -euo pipefail
umask 077

usage() {
  cat >&2 <<'EOF'
Uso: ./scripts/collect_runtime_metrics.sh

Recolecta un snapshot Prometheus sin desplegar un stack de monitoreo. Escribe a
stdout o atomicamente en OUTPUT_FILE.

Variables: BASE_URL, TENANT_SLUG, RESOLVE_IP, CA_CERT, TIMEOUT_SECONDS,
HTTP_PROBE_SPACING_MS, CGROUP_CPU_SAMPLE_MS, COLLECTION_PROFILE,
OUTPUT_FILE y RUNTIME_CONTAINERS (lista separada por espacios).

COLLECTION_PROFILE=full (default) incluye outboxes; load conserva solo las
fuentes necesarias para evidencias de carga y evita crear procesos PHP CLI.
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
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-10}"
HTTP_PROBE_SPACING_MS="${HTTP_PROBE_SPACING_MS:-500}"
CGROUP_CPU_SAMPLE_MS="${CGROUP_CPU_SAMPLE_MS:-200}"
COLLECTION_PROFILE="${COLLECTION_PROFILE:-full}"
OUTPUT_FILE="${OUTPUT_FILE:-}"
RUNTIME_CONTAINERS="${RUNTIME_CONTAINERS:-apisix-gateway apisix-etcd basesdedatos backend-api backend-http backend-sri-worker backend-commerce-billing-worker backend-mailer-worker backend-wallet-notify-worker webparamascotas dashboard}"

for command in awk curl date docker php timeout; do
  command -v "${command}" >/dev/null 2>&1 || {
    echo "${command} no esta disponible." >&2
    exit 2
  }
done
if [[ ! "${TIMEOUT_SECONDS}" =~ ^[1-9][0-9]*$ ]]; then
  echo "TIMEOUT_SECONDS debe ser un entero positivo." >&2
  exit 2
fi
if [[ ! "${HTTP_PROBE_SPACING_MS}" =~ ^[0-9]+$ ]]; then
  echo "HTTP_PROBE_SPACING_MS debe ser un entero no negativo." >&2
  exit 2
fi
if [[ ! "${CGROUP_CPU_SAMPLE_MS}" =~ ^[1-9][0-9]*$ ]] \
  || (( 10#${CGROUP_CPU_SAMPLE_MS} < 50 || 10#${CGROUP_CPU_SAMPLE_MS} > 5000 )); then
  echo "CGROUP_CPU_SAMPLE_MS debe estar entre 50 y 5000." >&2
  exit 2
fi
if [[ "${COLLECTION_PROFILE}" != "full" && "${COLLECTION_PROFILE}" != "load" ]]; then
  echo "COLLECTION_PROFILE debe ser full o load." >&2
  exit 2
fi
if [[ ! "${BASE_URL}" =~ ^https?://[A-Za-z0-9.-]+(:[1-9][0-9]{0,4})?/?$ ]]; then
  echo "BASE_URL debe ser un origen HTTP(S) sin path, query ni credenciales." >&2
  exit 2
fi
if [[ ! "${TENANT_SLUG}" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
  echo "TENANT_SLUG invalido." >&2
  exit 2
fi
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

read -r -a runtime_containers <<<"${RUNTIME_CONTAINERS}"
if (( ${#runtime_containers[@]} == 0 )); then
  echo "RUNTIME_CONTAINERS no puede estar vacio." >&2
  exit 2
fi
for container in "${runtime_containers[@]}"; do
  if [[ ! "${container}" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
    echo "Nombre de contenedor invalido: ${container}" >&2
    exit 2
  fi
done

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
(( 10#${port} <= 65535 )) || { echo "Puerto de BASE_URL fuera de rango." >&2; exit 2; }

curl_args=(--silent --show-error --max-time "${TIMEOUT_SECONDS}" --output /dev/null)
if [[ -n "${RESOLVE_IP}" && -z "${CA_CERT}" && -f "${WORKSPACE_DIR}/gatewayapisix/entorno/certs/local-ca.crt" ]]; then
  CA_CERT="${WORKSPACE_DIR}/gatewayapisix/entorno/certs/local-ca.crt"
fi
if [[ -n "${CA_CERT}" ]]; then
  [[ -r "${CA_CERT}" ]] || { echo "CA_CERT no legible: ${CA_CERT}" >&2; exit 2; }
  curl_args+=(--cacert "${CA_CERT}")
fi
if [[ -n "${RESOLVE_IP}" ]]; then
  curl_args+=(--noproxy "${host}" --resolve "${host}:${port}:${RESOLVE_IP}")
fi

postgres_metrics_sql() {
  cat <<'SQL'
      WITH activity AS (
        SELECT *
        FROM pg_stat_activity
        WHERE backend_type = 'client backend' AND pid <> pg_backend_pid()
      ), database_stats AS (
        SELECT
          COALESCE(sum(xact_commit), 0) AS commits,
          COALESCE(sum(xact_rollback), 0) AS rollbacks,
          COALESCE(sum(blks_read), 0) AS blocks_read,
          COALESCE(sum(blks_hit), 0) AS blocks_hit,
          COALESCE(sum(temp_bytes), 0) AS temp_bytes,
          COALESCE(sum(deadlocks), 0) AS deadlocks
        FROM pg_stat_database
      )
      SELECT
        (SELECT count(*) FROM activity),
        (SELECT count(*) FROM activity WHERE state = 'active'),
        (SELECT count(*) FROM activity WHERE wait_event_type = 'Lock'),
        (SELECT count(*) FROM pg_locks WHERE NOT granted),
        (SELECT count(*) FROM activity WHERE xact_start IS NOT NULL AND clock_timestamp() - xact_start > interval '30 seconds'),
        current_setting('max_connections')::integer,
        round((SELECT count(*) FROM activity) * 100.0 / NULLIF(current_setting('max_connections')::numeric, 0), 4),
        database_stats.commits,
        database_stats.rollbacks,
        database_stats.blocks_read,
        database_stats.blocks_hit,
        COALESCE(round(database_stats.blocks_hit * 100.0 / NULLIF(database_stats.blocks_hit + database_stats.blocks_read, 0), 4), 100.0000),
        database_stats.temp_bytes,
        database_stats.deadlocks,
        COALESCE((SELECT round(max(EXTRACT(EPOCH FROM clock_timestamp() - query_start))::numeric, 6) FROM activity WHERE state = 'active' AND query_start IS NOT NULL), 0)
      FROM database_stats;
SQL
}

postgres_failure_is_transport() {
  local exit_code="$1"
  local stderr_file="$2"

  # Autenticacion/configuracion y SQL son fallos reales: nunca se reintentan
  # como si fueran ruido del plano de control.
  if LC_ALL=C grep -Eqi \
    'password authentication failed|no password supplied|role ".*" does not exist|database ".*" does not exist|permission denied|syntax error|must be (owner|superuser)' \
    "${stderr_file}"; then
    return 1
  fi
  case "${exit_code}" in
    2|124|125|126|127|137|143) return 0 ;;
  esac
  LC_ALL=C grep -Eqi \
    '(^|[[:space:]])nsenter:|Cannot connect to the Docker daemon|Error response from daemon|OCI runtime exec failed|context deadline exceeded|container .* is not running|connection to server .* failed|server closed the connection unexpectedly|timeout expired|Connection refused|No route to host|Network is unreachable' \
    "${stderr_file}"
}

run_postgres_netns_attempt() (
  local inspect_source="$1"
  local output_file="$2"
  local stderr_file="$3"
  local database_pid environment_entry postgres_password="" postgres_user="postgres"
  local escaped_password escaped_user passfile exit_code

  command -v nsenter >/dev/null 2>&1 && command -v psql >/dev/null 2>&1 \
    && (( EUID == 0 )) || exit 125
  database_pid="$(awk -F '\t' '$1 == "/basesdedatos" {print $7; exit}' "${inspect_source}")"
  [[ "${database_pid}" =~ ^[1-9][0-9]*$ && -r "/proc/${database_pid}/environ" \
    && -r "/proc/${database_pid}/ns/net" ]] || exit 125

  while IFS= read -r -d '' environment_entry; do
    case "${environment_entry}" in
      POSTGRES_PASSWORD=*) postgres_password="${environment_entry#POSTGRES_PASSWORD=}" ;;
      POSTGRES_USER=*) postgres_user="${environment_entry#POSTGRES_USER=}" ;;
    esac
  done <"/proc/${database_pid}/environ"
  [[ -n "${postgres_password}" && -n "${postgres_user}" ]] || exit 125

  # libpq exige escapar '\\' y ':' en pgpass. El secreto nunca entra en argv,
  # stdout, stderr ni metricas; el archivo vive bajo tmp_dir (umask 077).
  escaped_password="${postgres_password//\\/\\\\}"
  escaped_password="${escaped_password//:/\\:}"
  escaped_user="${postgres_user//\\/\\\\}"
  escaped_user="${escaped_user//:/\\:}"
  passfile="${tmp_dir}/postgres-netns.pgpass.$$"
  (umask 077; printf '127.0.0.1:5432:postgres:%s:%s\n' \
    "${escaped_user}" "${escaped_password}" >"${passfile}")
  chmod 600 "${passfile}"
  unset postgres_password escaped_password

  set +e
  LC_ALL=C PGCONNECT_TIMEOUT="${TIMEOUT_SECONDS}" PGPASSFILE="${passfile}" \
    hard_timeout nsenter -t "${database_pid}" -n \
      psql -XAtq -w -h 127.0.0.1 -p 5432 -U "${postgres_user}" \
      -d postgres -v ON_ERROR_STOP=1 -c "$(postgres_metrics_sql)" \
      >"${output_file}" 2>"${stderr_file}"
  exit_code=$?
  set -e
  rm -f "${passfile}"
  unset escaped_user postgres_user
  exit "${exit_code}"
)

run_postgres_docker_attempt() {
  local output_file="$1"
  local stderr_file="$2"
  hard_timeout docker exec basesdedatos sh -eu -c '
    LC_ALL=C PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD unavailable}" \
    psql -XAtq -w -U "${POSTGRES_USER:-postgres}" -d postgres \
      -v ON_ERROR_STOP=1 -c "$1"
  ' sh "$(postgres_metrics_sql)" >"${output_file}" 2>"${stderr_file}"
}

collect_postgres_metrics_source() {
  local inspect_source="$1"
  local status_file="$2"
  local attempts=0 transport_failures=0 method="netns_psql"
  local exit_code=0 attempt_output attempt_stderr

  attempt_output="${tmp_dir}/postgres-attempt.out"
  attempt_stderr="${tmp_dir}/postgres-attempt.stderr"
  : >"${attempt_output}"
  : >"${attempt_stderr}"

  attempts=$((attempts + 1))
  if run_postgres_netns_attempt "${inspect_source}" "${attempt_output}" "${attempt_stderr}"; then
    printf '%s\t%s\t%s\n' "${attempts}" "${transport_failures}" "${method}" >"${status_file}"
    cat "${attempt_output}"
    return 0
  else
    exit_code=$?
  fi
  if ! postgres_failure_is_transport "${exit_code}" "${attempt_stderr}"; then
    printf 'postgres_scrape attempt=%s method=%s outcome=non_transport_failure exit_code=%s\n' \
      "${attempts}" "${method}" "${exit_code}" >&2
    printf '%s\t%s\t%s\n' "${attempts}" "${transport_failures}" "${method}" >"${status_file}"
    return "${exit_code}"
  fi
  transport_failures=$((transport_failures + 1))
  printf 'postgres_scrape attempt=%s method=%s outcome=transport_failure exit_code=%s\n' \
    "${attempts}" "${method}" "${exit_code}" >&2

  method="docker_exec"
  for fallback_attempt in 1 2; do
    attempts=$((attempts + 1))
    : >"${attempt_output}"
    : >"${attempt_stderr}"
    if run_postgres_docker_attempt "${attempt_output}" "${attempt_stderr}"; then
      printf '%s\t%s\t%s\n' "${attempts}" "${transport_failures}" "${method}" >"${status_file}"
      cat "${attempt_output}"
      return 0
    else
      exit_code=$?
    fi
    if ! postgres_failure_is_transport "${exit_code}" "${attempt_stderr}"; then
      printf 'postgres_scrape attempt=%s method=%s outcome=non_transport_failure exit_code=%s\n' \
        "${attempts}" "${method}" "${exit_code}" >&2
      printf '%s\t%s\t%s\n' "${attempts}" "${transport_failures}" "${method}" >"${status_file}"
      return "${exit_code}"
    fi
    transport_failures=$((transport_failures + 1))
    printf 'postgres_scrape attempt=%s method=%s outcome=transport_failure exit_code=%s\n' \
      "${attempts}" "${method}" "${exit_code}" >&2
    if (( fallback_attempt == 1 )); then sleep 0.1; fi
  done

  printf '%s\t%s\t%s\n' "${attempts}" "${transport_failures}" "${method}" >"${status_file}"
  return "${exit_code}"
}

# GNU timeout envia TERM al vencer, pero un cliente Docker bloqueado puede no
# terminar. El KILL posterior hace que cada fuente tenga un limite real sin
# convertir un scrape perdido en un dato exitoso.
hard_timeout() {
  timeout --kill-after=2s "${TIMEOUT_SECONDS}s" "$@"
}

collect_fpm_status_source() {
  local backend_http_pid
  backend_http_pid="$(awk -F '\t' '$1 == "/backend-http" {print $7; exit}' "${inspect_file}")"

  # El PID ya viene del inspect obligatorio. En Linux/root, entrar solo al
  # namespace de red evita otro docker exec y consulta el endpoint que sigue
  # limitado a 127.0.0.1 dentro del contenedor. Otros hosts conservan el
  # fallback compatible y fallan cerrados si tampoco responde.
  if command -v nsenter >/dev/null 2>&1 \
    && (( EUID == 0 )) \
    && [[ "${backend_http_pid}" =~ ^[1-9][0-9]*$ ]] \
    && [[ -r "/proc/${backend_http_pid}/ns/net" ]]; then
    hard_timeout nsenter -t "${backend_http_pid}" -n \
      curl --silent --show-error --max-time "${TIMEOUT_SECONDS}" \
      'http://127.0.0.1:8080/internal/fpm-status?json'
    return
  fi

  hard_timeout docker exec backend-http \
    wget -qO- 'http://127.0.0.1:8080/internal/fpm-status?json'
}

# `docker stats --no-stream` espera dos lecturas del daemon para calcular CPU.
# Bajo saturacion esa espera puede consumir todo el intervalo del scorecard. En
# hosts Linux/cgroup v2 obtenemos el mismo porcentaje como delta de usage_usec
# sobre tiempo real y leemos memoria/PIDs directamente. La ruta no se supone:
# se descubre desde el PID de cada contenedor en /proc/<pid>/cgroup.
collect_cgroup_v2_stats() {
  local inspect_source="$1"
  local output_file="$2"
  local row name state container_id container_pid cgroup_path cgroup_dir
  local key value unused usage memory_current memory_limit inactive_file pids
  local started_ns finished_ns elapsed_usec
  local raw_file="${tmp_dir}/cgroup-stats-raw.tsv"
  local host_memory_bytes=0
  local -A cgroup_dirs=()
  local -A first_usage=()

  : >"${raw_file}"
  while IFS=$'\t' read -r name state _ _ _ container_id container_pid; do
    name="${name#/}"
    [[ "${state}" == "running" && "${container_id}" =~ ^[a-f0-9]{64}$ \
      && "${container_pid}" =~ ^[1-9][0-9]*$ ]] || return 1
    cgroup_path=""
    while IFS=: read -r _ controllers value; do
      if [[ -z "${controllers}" && "${value}" == /* ]]; then
        cgroup_path="${value}"
        break
      fi
    done <"/proc/${container_pid}/cgroup" || return 1
    [[ -n "${cgroup_path}" ]] || return 1
    cgroup_dir="/sys/fs/cgroup${cgroup_path}"
    [[ -r "${cgroup_dir}/cpu.stat" && -r "${cgroup_dir}/memory.current" \
      && -r "${cgroup_dir}/memory.max" && -r "${cgroup_dir}/pids.current" ]] || return 1
    cgroup_dirs["${name}"]="${cgroup_dir}"
  done <"${inspect_source}"
  (( ${#cgroup_dirs[@]} == ${#runtime_containers[@]} )) || return 1

  started_ns="$(date +%s%N)"
  for name in "${runtime_containers[@]}"; do
    usage=""
    while read -r key value unused; do
      if [[ "${key}" == "usage_usec" ]]; then usage="${value}"; break; fi
    done <"${cgroup_dirs[${name}]}/cpu.stat"
    [[ "${usage}" =~ ^[0-9]+$ ]] || return 1
    first_usage["${name}"]="${usage}"
  done
  sleep "$(awk -v milliseconds="${CGROUP_CPU_SAMPLE_MS}" 'BEGIN {printf "%.3f", milliseconds/1000}')"
  finished_ns="$(date +%s%N)"
  [[ "${started_ns}" =~ ^[0-9]+$ && "${finished_ns}" =~ ^[0-9]+$ \
    && "${finished_ns}" -gt "${started_ns}" ]] || return 1
  elapsed_usec=$(( (finished_ns - started_ns) / 1000 ))
  (( elapsed_usec > 0 )) || return 1

  while read -r key value unused; do
    if [[ "${key}" == "MemTotal:" && "${value}" =~ ^[0-9]+$ ]]; then
      host_memory_bytes=$(( value * 1024 ))
      break
    fi
  done </proc/meminfo

  for name in "${runtime_containers[@]}"; do
    cgroup_dir="${cgroup_dirs[${name}]}"
    usage=""
    while read -r key value unused; do
      if [[ "${key}" == "usage_usec" ]]; then usage="${value}"; break; fi
    done <"${cgroup_dir}/cpu.stat"
    memory_current="$(<"${cgroup_dir}/memory.current")"
    memory_limit="$(<"${cgroup_dir}/memory.max")"
    pids="$(<"${cgroup_dir}/pids.current")"
    inactive_file=0
    if [[ -r "${cgroup_dir}/memory.stat" ]]; then
      while read -r key value unused; do
        if [[ "${key}" == "inactive_file" ]]; then inactive_file="${value}"; break; fi
      done <"${cgroup_dir}/memory.stat"
    fi
    if [[ "${memory_limit}" == "max" ]]; then memory_limit="${host_memory_bytes}"; fi
    [[ "${usage}" =~ ^[0-9]+$ && "${memory_current}" =~ ^[0-9]+$ \
      && "${memory_limit}" =~ ^[1-9][0-9]*$ && "${inactive_file}" =~ ^[0-9]+$ \
      && "${pids}" =~ ^[0-9]+$ && "${usage}" -ge "${first_usage[${name}]}" ]] || return 1
    (( inactive_file <= memory_current )) || inactive_file=0
    printf '%s\t%s\t%s\t%s\t%s\t%s\n' "${name}" \
      "$(( usage - first_usage[${name}] ))" "${elapsed_usec}" \
      "$(( memory_current - inactive_file ))" "${memory_limit}" "${pids}" >>"${raw_file}"
  done

  awk -F '\t' 'BEGIN { OFS="\t" }
    NF != 6 || $2 !~ /^[0-9]+$/ || $3 !~ /^[1-9][0-9]*$/ ||
      $4 !~ /^[0-9]+$/ || $5 !~ /^[1-9][0-9]*$/ || $6 !~ /^[0-9]+$/ { exit 1 }
    {
      printf "%s\t%.2f%%\t%.2f%%\t%s\n", $1, ($2 * 100.0 / $3),
        ($4 * 100.0 / $5), $6
    }
  ' "${raw_file}" >"${output_file}"
  [[ "$(wc -l <"${output_file}")" -eq "${#runtime_containers[@]}" ]]
}

probe_http() {
  local name="$1"
  local path="$2"
  local probe_metrics_file="$3"
  local method="${4:-GET}"
  local request_path="${5:-${path}}"
  local result status ttfb duration response_bytes redirect_url effective_url
  local curl_exit transport_success success redirect redirect_same_authority target_authority
  local separator=$'\x1f'
  local -a curl_method_args=()

  case "${method}" in
    GET) ;;
    HEAD) curl_method_args=(--head) ;;
    *)
      echo "Metodo HTTP de sonda no soportado: ${method}" >&2
      return 2
      ;;
  esac

  if result="$(curl "${curl_args[@]}" "${curl_method_args[@]}" --header 'Accept-Encoding: identity' \
    --write-out $'%{http_code}\x1f%{time_starttransfer}\x1f%{time_total}\x1f%{size_download}\x1f%{redirect_url}\x1f%{url_effective}' \
    "${BASE_URL}${request_path}")"; then
    curl_exit=0
  else
    curl_exit=$?
  fi
  IFS="${separator}" read -r status ttfb duration response_bytes redirect_url effective_url <<<"${result:-}"
  [[ "${status:-}" =~ ^[0-9]{3}$ ]] || status=000
  [[ "${ttfb:-}" =~ ^[0-9]+([.][0-9]+)?$ ]] || ttfb=0
  [[ "${duration:-}" =~ ^[0-9]+([.][0-9]+)?$ ]] || duration="${TIMEOUT_SECONDS}"
  [[ "${response_bytes:-}" =~ ^[0-9]+$ ]] || response_bytes=0
  if (( curl_exit == 0 )); then transport_success=1; else transport_success=0; fi
  if (( curl_exit == 0 )) && [[ "${status}" =~ ^2[0-9][0-9]$ ]]; then success=1; else success=0; fi
  if [[ "${status}" =~ ^3[0-9][0-9]$ || -n "${redirect_url:-}" ]]; then redirect=1; else redirect=0; fi
  redirect_same_authority=1
  if [[ -n "${redirect_url:-}" ]]; then
    if [[ "${redirect_url}" =~ ^https?:// ]]; then
      target_authority="${redirect_url#*://}"
      target_authority="${target_authority%%/*}"
      [[ "${target_authority,,}" == "${authority,,}" ]] || redirect_same_authority=0
    elif [[ "${redirect_url}" == //* ]]; then
      target_authority="${redirect_url#//}"
      target_authority="${target_authority%%/*}"
      [[ "${target_authority,,}" == "${authority,,}" ]] || redirect_same_authority=0
    fi
  fi
  printf 'paramascotas_http_probe_success{name="%s",path="%s"} %s\n' "${name}" "${path}" "${success}" >> "${probe_metrics_file}"
  printf 'paramascotas_http_probe_transport_success{name="%s",path="%s"} %s\n' "${name}" "${path}" "${transport_success}" >> "${probe_metrics_file}"
  printf 'paramascotas_http_probe_redirect{name="%s",path="%s"} %s\n' "${name}" "${path}" "${redirect}" >> "${probe_metrics_file}"
  printf 'paramascotas_http_probe_redirect_same_authority{name="%s",path="%s"} %s\n' "${name}" "${path}" "${redirect_same_authority}" >> "${probe_metrics_file}"
  printf 'paramascotas_http_probe_status_code{name="%s",path="%s"} %s\n' "${name}" "${path}" "${status}" >> "${probe_metrics_file}"
  printf 'paramascotas_http_probe_ttfb_seconds{name="%s",path="%s"} %s\n' "${name}" "${path}" "${ttfb}" >> "${probe_metrics_file}"
  printf 'paramascotas_http_probe_duration_seconds{name="%s",path="%s"} %s\n' "${name}" "${path}" "${duration}" >> "${probe_metrics_file}"
  printf 'paramascotas_http_probe_response_bytes{name="%s",path="%s"} %s\n' "${name}" "${path}" "${response_bytes:-0}" >> "${probe_metrics_file}"
}

# Emite todos los gauges de contenedores en un solo proceso. El loop anterior
# arrancaba awk/date tres veces por runtime (33 procesos por snapshot), costo
# que se amplificaba bajo carga y que no aportaba evidencia adicional.
emit_container_metrics() {
  local inspect_source="$1" stats_source="$2" names_source="$3" output_file="$4"
  php -r '
$inspectRows = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $fields = explode("\t", $line);
    if (count($fields) === 7) {
        $inspectRows[ltrim($fields[0], "/")] = $fields;
    }
}
$statsRows = [];
foreach (file($argv[2], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $fields = explode("\t", $line);
    if (count($fields) === 4) {
        $statsRows[$fields[0]] = $fields;
    }
}
$decimal = static fn(string $value): bool => preg_match("/^[0-9]+(?:[.][0-9]+)?$/D", $value) === 1;
$integer = static fn(string $value): bool => preg_match("/^[0-9]+$/D", $value) === 1;
foreach (file($argv[3], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $container) {
    if (!isset($inspectRows[$container])) {
        printf("paramascotas_container_up{name=\"%s\"} 0\n", $container);
        printf("paramascotas_container_health{name=\"%s\",status=\"missing\"} 1\n", $container);
        continue;
    }
    [, $state, $health, $restartCount, $startedAt] = $inspectRows[$container];
    if (preg_match("/^[A-Za-z0-9_.-]+$/D", $health) !== 1) {
        $health = "unknown";
    }
    if (!$integer($restartCount)) {
        $restartCount = "0";
    }
    try {
        $startedAtSeconds = (string)(new DateTimeImmutable($startedAt))->getTimestamp();
    } catch (Throwable) {
        $startedAtSeconds = "0";
    }
    printf("paramascotas_container_up{name=\"%s\"} %d\n", $container, $state === "running" ? 1 : 0);
    printf("paramascotas_container_health{name=\"%s\",status=\"%s\"} 1\n", $container, $health);
    printf("paramascotas_container_restart_count{name=\"%s\"} %s\n", $container, $restartCount);
    printf("paramascotas_container_started_at_seconds{name=\"%s\"} %s\n", $container, $startedAtSeconds);
    if (!isset($statsRows[$container])) {
        continue;
    }
    [, $cpuPercent, $memoryPercent, $pids] = $statsRows[$container];
    $cpuPercent = rtrim($cpuPercent, "%");
    $memoryPercent = rtrim($memoryPercent, "%");
    if (!$decimal($cpuPercent)) {
        $cpuPercent = "0";
    }
    if (!$decimal($memoryPercent)) {
        $memoryPercent = "0";
    }
    if (!$integer($pids)) {
        $pids = "0";
    }
    printf("paramascotas_container_cpu_percent{name=\"%s\"} %s\n", $container, $cpuPercent);
    printf("paramascotas_container_memory_percent{name=\"%s\"} %s\n", $container, $memoryPercent);
    printf("paramascotas_container_pids{name=\"%s\"} %s\n", $container, $pids);
}
' "${inspect_source}" "${stats_source}" "${names_source}" >"${output_file}"
}

tmp_dir="$(mktemp -d)"
background_pids=()
cleanup() {
  if (( ${#background_pids[@]} > 0 )); then
    kill "${background_pids[@]}" 2>/dev/null || true
    wait "${background_pids[@]}" 2>/dev/null || true
  fi
  rm -rf "${tmp_dir}"
}
trap cleanup EXIT
metrics_file="${tmp_dir}/runtime.prom"
stats_file="${tmp_dir}/docker-stats.tsv"
inspect_file="${tmp_dir}/docker-inspect.tsv"
stats_status_file="${tmp_dir}/docker-stats.status"
stats_method_file="${tmp_dir}/docker-stats.method"
fpm_json_file="${tmp_dir}/fpm.json"
postgres_metrics_file="${tmp_dir}/postgres.tsv"
postgres_status_file="${tmp_dir}/postgres.status.tsv"
postgres_diagnostics_file="${tmp_dir}/postgres.stderr"
mailer_metrics_file="${tmp_dir}/backend-mailer-worker.prom"
billing_metrics_file="${tmp_dir}/backend-commerce-billing-worker.prom"
container_names_file="${tmp_dir}/runtime-containers.txt"
container_metrics_file="${tmp_dir}/container-metrics.prom"
printf '%s\n' "${runtime_containers[@]}" >"${container_names_file}"
: >"${postgres_status_file}"
: >"${postgres_diagnostics_file}"

# Las cuatro sondas se ejecutan como maximo en paralelo y comienzan junto con
# las otras fuentes. Conservan el stagger para no formar una rafaga. El probe
# de catalogo mantiene su etiqueta/ruta canonica, pero solicita una sola fila:
# valida APISIX, PHP y PostgreSQL sin serializar el catalogo completo.
probe_specs=(
  "api_health|/${TENANT_SLUG}/api/health|GET|/${TENANT_SLUG}/api/health"
  "api_live|/${TENANT_SLUG}/api/livez|GET|/${TENANT_SLUG}/api/livez"
  "storefront_home|/|HEAD|/"
  "public_catalog|/${TENANT_SLUG}/api/products|GET|/${TENANT_SLUG}/api/products?page_size=1"
)
probe_pids=()
probe_files=()
probe_spacing_seconds="$(awk -v milliseconds="${HTTP_PROBE_SPACING_MS}" 'BEGIN {printf "%.3f", milliseconds/1000}')"
for probe_index in "${!probe_specs[@]}"; do
  IFS='|' read -r probe_name probe_path probe_method probe_request_path <<<"${probe_specs[${probe_index}]}"
  probe_file="${tmp_dir}/http-probe-${probe_index}.prom"
  probe_files+=("${probe_file}")
  (
    if (( probe_index > 0 && HTTP_PROBE_SPACING_MS > 0 )); then
      sleep "$(awk -v idx="${probe_index}" -v spacing="${probe_spacing_seconds}" 'BEGIN {printf "%.3f", idx*spacing}')"
    fi
    probe_http "${probe_name}" "${probe_path}" "${probe_file}" "${probe_method:-GET}" "${probe_request_path:-${probe_path}}"
  ) &
  probe_pids+=("$!")
done
background_pids+=("${probe_pids[@]}")

# Las sondas HTTP y el inspect batched empiezan juntos. PostgreSQL arranca solo
# cuando el inspect ya entrego su PID, evitando competir con otra operacion del
# plano de control Docker. La recoleccion HTTP permanece espaciada.
# Cada job siempre termina con exit 0 y deja un artefacto que luego se valida;
# asi `set -e` no convierte una fuente degradada en un snapshot inexistente.
(
  hard_timeout docker inspect \
    --format $'{{.Name}}\t{{.State.Status}}\t{{if (index .State "Health")}}{{(index .State.Health "Status")}}{{else}}none{{end}}\t{{.RestartCount}}\t{{.State.StartedAt}}\t{{.Id}}\t{{.State.Pid}}' \
    "${runtime_containers[@]}" >"${inspect_file}" 2>/dev/null || : >"${inspect_file}"
) &
inspect_pid=$!
background_pids+=("${inspect_pid}")

mailer_pid=""
billing_pid=""
if [[ "${COLLECTION_PROFILE}" == "full" ]]; then
  (
    hard_timeout docker exec backend-mailer-worker php \
      /var/www/html/scripts/process_mailer_outbox.php --metrics \
      >"${mailer_metrics_file}" 2>/dev/null || : >"${mailer_metrics_file}"
  ) &
  mailer_pid=$!
  background_pids+=("${mailer_pid}")

  (
    hard_timeout docker exec backend-commerce-billing-worker php \
      /var/www/html/scripts/process_commerce_billing_outbox.php --metrics \
      >"${billing_metrics_file}" 2>/dev/null || : >"${billing_metrics_file}"
  ) &
  billing_pid=$!
  background_pids+=("${billing_pid}")
fi

wait "${inspect_pid}"
(
  collect_postgres_metrics_source "${inspect_file}" "${postgres_status_file}" \
    >"${postgres_metrics_file}" 2>"${postgres_diagnostics_file}" \
    || : >"${postgres_metrics_file}"
) &
postgres_pid=$!
background_pids=("${postgres_pid}" "${probe_pids[@]}")
if [[ -n "${mailer_pid}" ]]; then background_pids+=("${mailer_pid}"); fi
if [[ -n "${billing_pid}" ]]; then background_pids+=("${billing_pid}"); fi

(
  collect_fpm_status_source >"${fpm_json_file}" 2>/dev/null \
    || : >"${fpm_json_file}"
) &
fpm_pid=$!
background_pids+=("${fpm_pid}")

if collect_cgroup_v2_stats "${inspect_file}" "${stats_file}" 2>/dev/null; then
  printf '1\n' >"${stats_status_file}"
  printf 'cgroup_v2\n' >"${stats_method_file}"
elif hard_timeout docker stats --no-stream \
  --format $'{{.Name}}\t{{.CPUPerc}}\t{{.MemPerc}}\t{{.PIDs}}' \
  "${runtime_containers[@]}" >"${stats_file}" 2>/dev/null; then
  printf '1\n' >"${stats_status_file}"
  printf 'docker_cli_fallback\n' >"${stats_method_file}"
else
  : >"${stats_file}"
  printf '0\n' >"${stats_status_file}"
  printf 'unavailable\n' >"${stats_method_file}"
fi
docker_stats_success="$(<"${stats_status_file}")"
docker_stats_method="$(<"${stats_method_file}")"
[[ "${docker_stats_success}" =~ ^[01]$ ]] || docker_stats_success=0

cat > "${metrics_file}" <<'EOF'
# HELP paramascotas_collector_timestamp_seconds Unix timestamp of this snapshot.
# TYPE paramascotas_collector_timestamp_seconds gauge
EOF
printf 'paramascotas_collector_timestamp_seconds %s\n' "$(date +%s)" >> "${metrics_file}"
printf '# HELP paramascotas_collector_profile Active collector profile.\n' >>"${metrics_file}"
printf '# TYPE paramascotas_collector_profile gauge\n' >>"${metrics_file}"
printf 'paramascotas_collector_profile{profile="%s"} 1\n' "${COLLECTION_PROFILE}" >>"${metrics_file}"
cat >> "${metrics_file}" <<'EOF'
# HELP paramascotas_container_up Container exists and is running.
# TYPE paramascotas_container_up gauge
# HELP paramascotas_container_health Docker health status as a labeled gauge.
# TYPE paramascotas_container_health gauge
# HELP paramascotas_container_cpu_percent Instant Docker CPU percentage.
# TYPE paramascotas_container_cpu_percent gauge
# HELP paramascotas_container_memory_percent Instant Docker memory percentage.
# TYPE paramascotas_container_memory_percent gauge
# HELP paramascotas_container_pids Instant Docker PID count.
# TYPE paramascotas_container_pids gauge
# HELP paramascotas_container_restart_count Docker restart count since container creation.
# TYPE paramascotas_container_restart_count gauge
# HELP paramascotas_container_started_at_seconds Unix timestamp of the current container process start.
# TYPE paramascotas_container_started_at_seconds gauge
# HELP paramascotas_docker_stats_scrape_success Docker CPU/memory/PID snapshot was collected.
# TYPE paramascotas_docker_stats_scrape_success gauge
# HELP paramascotas_docker_stats_collection_method Runtime stats collection method selected for this snapshot.
# TYPE paramascotas_docker_stats_collection_method gauge
EOF
printf 'paramascotas_docker_stats_scrape_success %s\n' "${docker_stats_success}" >> "${metrics_file}"
printf 'paramascotas_docker_stats_collection_method{method="%s"} 1\n' "${docker_stats_method}" >>"${metrics_file}"

emit_container_metrics \
  "${inspect_file}" "${stats_file}" "${container_names_file}" "${container_metrics_file}"
cat "${container_metrics_file}" >>"${metrics_file}"

cat >> "${metrics_file}" <<'EOF'
# HELP paramascotas_http_probe_success Probe completed without a transport error and with a 2xx status.
# TYPE paramascotas_http_probe_success gauge
# HELP paramascotas_http_probe_transport_success Curl completed without a transport error.
# TYPE paramascotas_http_probe_transport_success gauge
# HELP paramascotas_http_probe_redirect Probe returned a redirect instead of the canonical resource.
# TYPE paramascotas_http_probe_redirect gauge
# HELP paramascotas_http_probe_redirect_same_authority Redirect target keeps the configured public authority.
# TYPE paramascotas_http_probe_redirect_same_authority gauge
# HELP paramascotas_http_probe_status_code Last HTTP status code.
# TYPE paramascotas_http_probe_status_code gauge
# HELP paramascotas_http_probe_ttfb_seconds Time to first byte.
# TYPE paramascotas_http_probe_ttfb_seconds gauge
# HELP paramascotas_http_probe_duration_seconds Total probe duration.
# TYPE paramascotas_http_probe_duration_seconds gauge
# HELP paramascotas_http_probe_response_bytes Response body bytes with identity encoding.
# TYPE paramascotas_http_probe_response_bytes gauge
EOF

wait "${probe_pids[@]}"
background_pids=("${fpm_pid}" "${postgres_pid}")
if [[ -n "${mailer_pid}" ]]; then background_pids+=("${mailer_pid}"); fi
if [[ -n "${billing_pid}" ]]; then background_pids+=("${billing_pid}"); fi
cat "${probe_files[@]}" >>"${metrics_file}"

wait "${fpm_pid}"
background_pids=("${postgres_pid}")
if [[ -n "${mailer_pid}" ]]; then background_pids+=("${mailer_pid}"); fi
if [[ -n "${billing_pid}" ]]; then background_pids+=("${billing_pid}"); fi
fpm_json="$(<"${fpm_json_file}")"
if [[ -n "${fpm_json}" ]] && printf '%s' "${fpm_json}" | php -r '
$payload = json_decode(stream_get_contents(STDIN), true);
if (!is_array($payload)) { exit(1); }
$map = [
    "accepted conn" => "accepted_connections_total",
    "listen queue" => "listen_queue",
    "max listen queue" => "max_listen_queue",
    "idle processes" => "idle_processes",
    "active processes" => "active_processes",
    "total processes" => "total_processes",
    "max active processes" => "max_active_processes",
    "max children reached" => "max_children_reached_total",
    "slow requests" => "slow_requests_total",
];
foreach ($map as $source => $metric) {
    $value = $payload[$source] ?? null;
    if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
        printf("paramascotas_php_fpm_%s %s\n", $metric, $value);
    }
}
' >> "${metrics_file}"; then
  echo 'paramascotas_php_fpm_scrape_success 1' >> "${metrics_file}"
else
  echo 'paramascotas_php_fpm_scrape_success 0' >> "${metrics_file}"
fi

cat >> "${metrics_file}" <<'EOF'
# HELP paramascotas_postgres_scrape_success PostgreSQL runtime metrics were collected.
# TYPE paramascotas_postgres_scrape_success gauge
# HELP paramascotas_postgres_scrape_attempts Transport attempts used by this PostgreSQL snapshot.
# TYPE paramascotas_postgres_scrape_attempts gauge
# HELP paramascotas_postgres_scrape_transport_failures Transport-only failures before the final result.
# TYPE paramascotas_postgres_scrape_transport_failures gauge
# HELP paramascotas_postgres_scrape_transport Transport used by the final PostgreSQL attempt.
# TYPE paramascotas_postgres_scrape_transport gauge
# HELP paramascotas_postgres_connections Current client connections by state.
# TYPE paramascotas_postgres_connections gauge
# HELP paramascotas_postgres_max_connections Configured PostgreSQL connection ceiling.
# TYPE paramascotas_postgres_max_connections gauge
# HELP paramascotas_postgres_connection_utilization_percent Used PostgreSQL connection slots.
# TYPE paramascotas_postgres_connection_utilization_percent gauge
# HELP paramascotas_postgres_lock_waiters Sessions currently waiting for a PostgreSQL lock.
# TYPE paramascotas_postgres_lock_waiters gauge
# HELP paramascotas_postgres_ungranted_locks Locks not yet granted.
# TYPE paramascotas_postgres_ungranted_locks gauge
# HELP paramascotas_postgres_long_transactions Transactions open for more than 30 seconds.
# TYPE paramascotas_postgres_long_transactions gauge
# HELP paramascotas_postgres_longest_active_query_seconds Age of the longest active client query.
# TYPE paramascotas_postgres_longest_active_query_seconds gauge
# HELP paramascotas_postgres_transactions_total Cumulative transactions across databases.
# TYPE paramascotas_postgres_transactions_total counter
# HELP paramascotas_postgres_cache_hit_percent Shared block cache hit ratio across databases.
# TYPE paramascotas_postgres_cache_hit_percent gauge
# HELP paramascotas_postgres_blocks_total Cumulative blocks read from disk/cache across databases.
# TYPE paramascotas_postgres_blocks_total counter
# HELP paramascotas_postgres_temp_bytes_total Cumulative temporary bytes across databases.
# TYPE paramascotas_postgres_temp_bytes_total counter
# HELP paramascotas_postgres_deadlocks_total Cumulative deadlocks across databases.
# TYPE paramascotas_postgres_deadlocks_total counter
EOF

wait "${postgres_pid}"
background_pids=("${mailer_pid}" "${billing_pid}")
if [[ -s "${postgres_diagnostics_file}" ]]; then
  cat "${postgres_diagnostics_file}" >&2
fi
postgres_metrics="$(<"${postgres_metrics_file}")"
postgres_status="$(<"${postgres_status_file}")"
IFS=$'\t' read -r postgres_attempts postgres_transport_failures postgres_transport \
  <<<"${postgres_status}"
if [[ ! "${postgres_attempts:-}" =~ ^[1-3]$ \
  || ! "${postgres_transport_failures:-}" =~ ^[0-3]$ \
  || ! "${postgres_transport:-}" =~ ^(netns_psql|docker_exec)$ ]]; then
  postgres_attempts=0
  postgres_transport_failures=0
  postgres_transport=unknown
fi
printf 'paramascotas_postgres_scrape_attempts %s\n' "${postgres_attempts}" >>"${metrics_file}"
printf 'paramascotas_postgres_scrape_transport_failures %s\n' \
  "${postgres_transport_failures}" >>"${metrics_file}"
printf 'paramascotas_postgres_scrape_transport{method="%s"} 1\n' \
  "${postgres_transport}" >>"${metrics_file}"
IFS='|' read -r pg_total pg_active pg_waiters pg_ungranted pg_long_transactions \
  pg_max_connections pg_connection_utilization pg_commits pg_rollbacks pg_blocks_read \
  pg_blocks_hit pg_cache_hit pg_temp_bytes pg_deadlocks pg_longest_query <<<"${postgres_metrics}"
postgres_valid=1
for value in "${pg_total:-}" "${pg_active:-}" "${pg_waiters:-}" "${pg_ungranted:-}" \
  "${pg_long_transactions:-}" "${pg_max_connections:-}" "${pg_commits:-}" "${pg_rollbacks:-}" \
  "${pg_blocks_read:-}" "${pg_blocks_hit:-}" "${pg_temp_bytes:-}" "${pg_deadlocks:-}"; do
  [[ "${value}" =~ ^[0-9]+$ ]] || postgres_valid=0
done
for value in "${pg_connection_utilization:-}" "${pg_cache_hit:-}" "${pg_longest_query:-}"; do
  [[ "${value}" =~ ^[0-9]+([.][0-9]+)?$ ]] || postgres_valid=0
done
if (( postgres_valid == 1 )); then
  echo 'paramascotas_postgres_scrape_success 1' >> "${metrics_file}"
  printf 'paramascotas_postgres_connections{state="total"} %s\n' "${pg_total}" >> "${metrics_file}"
  printf 'paramascotas_postgres_connections{state="active"} %s\n' "${pg_active}" >> "${metrics_file}"
  printf 'paramascotas_postgres_max_connections %s\n' "${pg_max_connections}" >> "${metrics_file}"
  printf 'paramascotas_postgres_connection_utilization_percent %s\n' "${pg_connection_utilization}" >> "${metrics_file}"
  printf 'paramascotas_postgres_lock_waiters %s\n' "${pg_waiters}" >> "${metrics_file}"
  printf 'paramascotas_postgres_ungranted_locks %s\n' "${pg_ungranted}" >> "${metrics_file}"
  printf 'paramascotas_postgres_long_transactions %s\n' "${pg_long_transactions}" >> "${metrics_file}"
  printf 'paramascotas_postgres_longest_active_query_seconds %s\n' "${pg_longest_query}" >> "${metrics_file}"
  printf 'paramascotas_postgres_transactions_total{result="commit"} %s\n' "${pg_commits}" >> "${metrics_file}"
  printf 'paramascotas_postgres_transactions_total{result="rollback"} %s\n' "${pg_rollbacks}" >> "${metrics_file}"
  printf 'paramascotas_postgres_blocks_total{source="disk"} %s\n' "${pg_blocks_read}" >> "${metrics_file}"
  printf 'paramascotas_postgres_blocks_total{source="cache"} %s\n' "${pg_blocks_hit}" >> "${metrics_file}"
  printf 'paramascotas_postgres_cache_hit_percent %s\n' "${pg_cache_hit}" >> "${metrics_file}"
  printf 'paramascotas_postgres_temp_bytes_total %s\n' "${pg_temp_bytes}" >> "${metrics_file}"
  printf 'paramascotas_postgres_deadlocks_total %s\n' "${pg_deadlocks}" >> "${metrics_file}"
else
  echo 'paramascotas_postgres_scrape_success 0' >> "${metrics_file}"
fi

cat >> "${metrics_file}" <<'EOF'
# HELP paramascotasec_mailer_outbox_scrape_success Mailer durable-outbox metrics were collected from its least-privilege worker.
# TYPE paramascotasec_mailer_outbox_scrape_success gauge
# HELP paramascotasec_commerce_billing_outbox_scrape_success Commerce-to-Billing durable-outbox metrics were collected from its least-privilege worker.
# TYPE paramascotasec_commerce_billing_outbox_scrape_success gauge
EOF

collect_outbox_metrics() {
  local worker_metrics="$1"
  local metric_prefix="$2"
  local success_metric="$3"

  if awk -v prefix="${metric_prefix}" '
      BEGIN { valid = 1 }
      $1 !~ ("^" prefix "[a-z0-9_]+$") || $2 !~ /^[0-9]+$/ || NF != 2 { valid = 0 }
      END { exit(NR > 0 && valid ? 0 : 1) }
    ' "${worker_metrics}"; then
    cat "${worker_metrics}" >> "${metrics_file}"
    printf '%s 1\n' "${success_metric}" >> "${metrics_file}"
  else
    printf '%s 0\n' "${success_metric}" >> "${metrics_file}"
  fi
}

if [[ "${COLLECTION_PROFILE}" == "full" ]]; then
  wait "${mailer_pid}" "${billing_pid}"
  collect_outbox_metrics \
    "${mailer_metrics_file}" \
    paramascotasec_mailer_outbox_ \
    paramascotasec_mailer_outbox_scrape_success
  collect_outbox_metrics \
    "${billing_metrics_file}" \
    paramascotasec_commerce_billing_outbox_ \
    paramascotasec_commerce_billing_outbox_scrape_success
fi
background_pids=()

if [[ -n "${OUTPUT_FILE}" ]]; then
  mkdir -p "$(dirname "${OUTPUT_FILE}")"
  output_tmp="${OUTPUT_FILE}.tmp.$$"
  cp "${metrics_file}" "${output_tmp}"
  mv -f "${output_tmp}" "${OUTPUT_FILE}"
  echo "Metricas guardadas en ${OUTPUT_FILE}" >&2
else
  cat "${metrics_file}"
fi
