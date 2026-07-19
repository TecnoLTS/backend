#!/bin/sh

set -eu

if [ "$#" -ne 2 ]; then
  echo "Uso: check-periodic-worker-health.sh <worker-name> <grace-seconds>" >&2
  exit 64
fi

worker_name="$1"
grace_seconds="$2"
state_root="${PERIODIC_WORKER_STATE_DIR:-/tmp/periodic-workers}"
state_dir="${state_root}/${worker_name}"

case "$worker_name" in
  ''|*[!A-Za-z0-9_-]*)
    echo "Nombre de worker invalido." >&2
    exit 64
    ;;
esac

case "$grace_seconds" in
  ''|*[!0-9]*)
    echo "Margen de salud invalido para ${worker_name}." >&2
    exit 64
    ;;
esac

read_number() {
  health_file="$1"
  if [ ! -s "$health_file" ]; then
    return 1
  fi
  health_value="$(sed -n '1p' "$health_file")"
  case "$health_value" in
    ''|*[!0-9]*) return 1 ;;
  esac
  printf '%s\n' "$health_value"
}

if [ "${WORKER_HEALTH_SKIP_PROCESS_CHECK:-0}" != "1" ]; then
  if ! pgrep -f "/var/www/html/docker/periodic-worker[.]sh ${worker_name} " >/dev/null 2>&1; then
    echo "${worker_name}: el supervisor periodico no esta ejecutandose." >&2
    exit 1
  fi
fi

interval_seconds="$(read_number "${state_dir}/interval-seconds")" || {
  echo "${worker_name}: no existe el intervalo registrado." >&2
  exit 1
}
last_success="$(read_number "${state_dir}/last-success")" || {
  echo "${worker_name}: aun no registra un ciclo exitoso." >&2
  exit 1
}
last_exit_code="$(read_number "${state_dir}/last-exit-code")" || {
  echo "${worker_name}: no registra el resultado del ultimo ciclo." >&2
  exit 1
}

if [ "$last_exit_code" -ne 0 ]; then
  echo "${worker_name}: el ultimo ciclo termino con codigo ${last_exit_code}." >&2
  exit 1
fi

now="$(date +%s)"
max_age=$((interval_seconds + grace_seconds))
age=$((now - last_success))
if [ "$age" -lt 0 ] || [ "$age" -gt "$max_age" ]; then
  echo "${worker_name}: ultimo ciclo exitoso hace ${age}s; maximo ${max_age}s." >&2
  exit 1
fi

echo "${worker_name}: healthy last_success_age=${age}s max_age=${max_age}s"
