#!/bin/sh

set -u

usage() {
  echo "Uso: periodic-worker.sh <worker-name> <interval-seconds> <command> [args...]" >&2
}

if [ "$#" -lt 3 ]; then
  usage
  exit 64
fi

worker_name="$1"
interval_seconds="$2"
shift 2

case "$worker_name" in
  ''|*[!A-Za-z0-9_-]*)
    echo "Nombre de worker invalido: ${worker_name}" >&2
    exit 64
    ;;
esac

case "$interval_seconds" in
  ''|*[!0-9]*)
    echo "Intervalo invalido para ${worker_name}: ${interval_seconds}" >&2
    exit 64
    ;;
esac

if [ "$interval_seconds" -lt 1 ]; then
  echo "El intervalo de ${worker_name} debe ser mayor que cero." >&2
  exit 64
fi

state_root="${PERIODIC_WORKER_STATE_DIR:-/tmp/periodic-workers}"
state_dir="${state_root}/${worker_name}"
failure_retry_seconds="${PERIODIC_WORKER_FAILURE_RETRY_SECONDS:-60}"
case "$failure_retry_seconds" in
  ''|*[!0-9]*)
    echo "Reintento de falla invalido para ${worker_name}: ${failure_retry_seconds}" >&2
    exit 64
    ;;
esac
if [ "$failure_retry_seconds" -lt 1 ]; then
  echo "El reintento de falla de ${worker_name} debe ser mayor que cero." >&2
  exit 64
fi

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
if [ "${STORAGE_PREFLIGHT_REQUIRED:-true}" = "true" ]; then
  php "${script_dir}/../scripts/check_storage_configuration.php" --quiet
fi

umask 077
mkdir -p "$state_dir"

write_state() {
  state_key="$1"
  state_value="$2"
  state_tmp="${state_dir}/.${state_key}.$$"
  printf '%s\n' "$state_value" > "$state_tmp"
  mv -f "$state_tmp" "${state_dir}/${state_key}"
}

write_state interval-seconds "$interval_seconds"
write_state runner-pid "$$"

child_pid=''

terminate() {
  signal="${1:-TERM}"
  if [ -n "$child_pid" ] && kill -0 "$child_pid" 2>/dev/null; then
    kill "-${signal}" "$child_pid" 2>/dev/null || kill "$child_pid" 2>/dev/null || true
    wait "$child_pid" 2>/dev/null || true
  fi
  now="$(date +%s)"
  write_state stopped-at "$now"
  printf '{"event":"periodic_worker_stopped","worker":"%s","timestamp":%s,"signal":"%s"}\n' \
    "$worker_name" "$now" "$signal"
  exit 0
}

trap 'terminate TERM' TERM
trap 'terminate INT' INT
trap 'terminate HUP' HUP

while true; do
  started_at="$(date +%s)"
  write_state last-started "$started_at"
  write_state command-pid 0

  "$@" &
  child_pid="$!"
  write_state command-pid "$child_pid"

  set +e
  wait "$child_pid"
  exit_code="$?"
  set -e
  child_pid=''

  finished_at="$(date +%s)"
  duration_seconds=$((finished_at - started_at))
  write_state command-pid 0
  write_state last-finished "$finished_at"
  write_state last-exit-code "$exit_code"
  if [ "$exit_code" -eq 0 ]; then
    write_state last-success "$finished_at"
    next_run_seconds="$interval_seconds"
  else
    next_run_seconds="$failure_retry_seconds"
  fi
  write_state next-run-seconds "$next_run_seconds"

  printf '{"event":"periodic_worker_cycle","worker":"%s","started_at":%s,"finished_at":%s,"duration_seconds":%s,"exit_code":%s,"next_run_seconds":%s}\n' \
    "$worker_name" "$started_at" "$finished_at" "$duration_seconds" "$exit_code" "$next_run_seconds"

  sleep "$next_run_seconds" &
  child_pid="$!"
  wait "$child_pid" || true
  child_pid=''
done
