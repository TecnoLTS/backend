#!/bin/sh

set -eu

positive_integer() {
  value="$1"
  label="$2"
  case "$value" in
    ''|*[!0-9]*)
      echo "${label} debe ser un entero positivo; recibido: ${value}" >&2
      exit 64
      ;;
  esac
  if [ "$value" -lt 1 ]; then
    echo "${label} debe ser mayor que cero." >&2
    exit 64
  fi
}

duration_value() {
  value="$1"
  label="$2"
  case "$value" in
    *[!0-9smhd]*)
      echo "${label} tiene un formato invalido: ${value}" >&2
      exit 64
      ;;
    *s|*m|*h|*d) numeric="${value%?}" ;;
    *) numeric="$value" ;;
  esac
  positive_integer "$numeric" "$label"
}

memory_value() {
  value="$1"
  label="$2"
  case "$value" in
    *K|*M|*G) numeric="${value%?}" ;;
    *) numeric="$value" ;;
  esac
  positive_integer "$numeric" "$label"
}

max_children="${PHP_FPM_MAX_CHILDREN:-16}"
start_servers="${PHP_FPM_START_SERVERS:-12}"
min_spare_servers="${PHP_FPM_MIN_SPARE_SERVERS:-12}"
max_spare_servers="${PHP_FPM_MAX_SPARE_SERVERS:-14}"
max_requests="${PHP_FPM_MAX_REQUESTS:-500}"
request_timeout="${PHP_FPM_REQUEST_TERMINATE_TIMEOUT:-120s}"
slow_request_timeout="${PHP_FPM_SLOW_REQUEST_TIMEOUT:-5s}"
php_memory_limit="${PHP_MEMORY_LIMIT:-128M}"
application_socket_dir="/run/php-fpm"
application_socket="${application_socket_dir}/app.sock"

positive_integer "$max_children" PHP_FPM_MAX_CHILDREN
positive_integer "$start_servers" PHP_FPM_START_SERVERS
positive_integer "$min_spare_servers" PHP_FPM_MIN_SPARE_SERVERS
positive_integer "$max_spare_servers" PHP_FPM_MAX_SPARE_SERVERS
positive_integer "$max_requests" PHP_FPM_MAX_REQUESTS
duration_value "$request_timeout" PHP_FPM_REQUEST_TERMINATE_TIMEOUT
duration_value "$slow_request_timeout" PHP_FPM_SLOW_REQUEST_TIMEOUT
memory_value "$php_memory_limit" PHP_MEMORY_LIMIT

if [ "$start_servers" -gt "$max_children" ] \
  || [ "$min_spare_servers" -gt "$max_children" ] \
  || [ "$max_spare_servers" -gt "$max_children" ] \
  || [ "$start_servers" -lt "$min_spare_servers" ] \
  || [ "$start_servers" -gt "$max_spare_servers" ] \
  || [ "$min_spare_servers" -gt "$max_spare_servers" ]; then
  echo "La configuracion PHP-FPM excede PHP_FPM_MAX_CHILDREN o no ordena min_spare <= start <= max_spare." >&2
  exit 64
fi

runtime_dir="/tmp/php-fpm-runtime"
pool_dir="${runtime_dir}/pool.d"
main_config="${runtime_dir}/php-fpm.conf"
pool_config="${pool_dir}/zz-runtime.conf"
umask 077
mkdir -p "$pool_dir"
mkdir -p "$application_socket_dir"
if [ ! -w "$application_socket_dir" ]; then
  echo "El directorio compartido FPM no es escribible: ${application_socket_dir}" >&2
  exit 73
fi
# El volumen es efimero, pero puede sobrevivir brevemente a una recreacion
# coordinada de contenedores. Nunca intentar enlazar sobre un inode obsoleto.
rm -f "$application_socket"

cat > "$main_config" <<'EOF'
[global]
daemonize = no
error_log = /proc/self/fd/2
include = /usr/local/etc/php-fpm.d/*.conf
include = /tmp/php-fpm-runtime/pool.d/*.conf
EOF

cat > "$pool_config" <<EOF
[www]
listen = ${application_socket}
listen.backlog = 4096
listen.mode = 0660
pm = dynamic
pm.max_children = ${max_children}
pm.start_servers = ${start_servers}
pm.min_spare_servers = ${min_spare_servers}
pm.max_spare_servers = ${max_spare_servers}
pm.max_requests = ${max_requests}
pm.status_path = /internal/fpm-status
pm.status_listen = 9001
ping.path = /internal/fpm-ping
ping.response = pong
request_terminate_timeout = ${request_timeout}
request_slowlog_timeout = ${slow_request_timeout}
slowlog = /proc/self/fd/2
catch_workers_output = yes
decorate_workers_output = no
php_admin_value[memory_limit] = ${php_memory_limit}
EOF

echo "PHP-FPM runtime: max_children=${max_children} start=${start_servers} spare=${min_spare_servers}-${max_spare_servers} max_requests=${max_requests} memory_limit=${php_memory_limit}"
if [ "${PHP_FPM_CONFIG_ONLY:-0}" = "1" ]; then
  php-fpm --test --fpm-config "$main_config"
  exit 0
fi
php /var/www/html/scripts/check_storage_configuration.php --quiet
exec php-fpm --nodaemonize --fpm-config "$main_config"
