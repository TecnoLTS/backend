#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
launcher="${APP_DIR}/docker/start-php-fpm.sh"
nginx_config="${APP_DIR}/docker/nginx.conf"
compose_file="${APP_DIR}/docker-compose.yml"
env_template="${APP_DIR}/templates/entorno/.env.example"
dockerfile="${APP_DIR}/docker/Dockerfile"

require_line() {
  local file="$1"
  local expected="$2"
  grep -Fqx "${expected}" "${file}" || {
    echo "Contrato FPM ausente en ${file}: ${expected}" >&2
    exit 1
  }
}

# Capacidad medida para ocho generadores, sondas simultaneas y margen de
# expansion. El limite de 3 GiB deja holgura sobre 16 procesos de 128 MiB.
require_line "${launcher}" 'max_children="${PHP_FPM_MAX_CHILDREN:-16}"'
require_line "${launcher}" 'start_servers="${PHP_FPM_START_SERVERS:-12}"'
require_line "${launcher}" 'min_spare_servers="${PHP_FPM_MIN_SPARE_SERVERS:-12}"'
require_line "${launcher}" 'max_spare_servers="${PHP_FPM_MAX_SPARE_SERVERS:-14}"'
require_line "${launcher}" 'application_socket="${application_socket_dir}/app.sock"'
require_line "${launcher}" 'listen = ${application_socket}'
require_line "${launcher}" 'listen.backlog = 4096'
require_line "${launcher}" 'listen.mode = 0660'
require_line "${launcher}" 'pm.status_listen = 9001'

grep -Fq 'PHP_FPM_MAX_CHILDREN: ${PHP_FPM_MAX_CHILDREN:-16}' "${compose_file}"
grep -Fq 'PHP_FPM_START_SERVERS: ${PHP_FPM_START_SERVERS:-12}' "${compose_file}"
grep -Fq 'PHP_FPM_MIN_SPARE_SERVERS: ${PHP_FPM_MIN_SPARE_SERVERS:-12}' "${compose_file}"
grep -Fq 'PHP_FPM_MAX_SPARE_SERVERS: ${PHP_FPM_MAX_SPARE_SERVERS:-14}' "${compose_file}"
grep -Fq 'mem_limit: "${BACKEND_API_MEMORY_LIMIT:-3g}"' "${compose_file}"
grep -Fq 'mem_reservation: "${BACKEND_API_MEMORY_RESERVATION:-768m}"' "${compose_file}"
[[ "$(grep -Fc 'fpm-socket:/run/php-fpm:rw' "${compose_file}")" -eq 1 ]]
[[ "$(grep -Fc 'fpm-socket:/run/php-fpm:ro' "${compose_file}")" -eq 1 ]]
grep -Fq 'o: size=1m,uid=82,gid=82,mode=0770' "${compose_file}"
grep -Fq 'group_add:' "${compose_file}"
grep -Fq 'EXPOSE 9001' "${dockerfile}"
if grep -Eq '^EXPOSE[[:space:]].*9000([[:space:]]|$)' "${dockerfile}"; then
  echo 'La imagen backend aun publica el puerto FastCGI de aplicacion.' >&2
  exit 1
fi

for expected in \
  'PHP_FPM_MAX_CHILDREN=16' \
  'PHP_FPM_START_SERVERS=12' \
  'PHP_FPM_MIN_SPARE_SERVERS=12' \
  'PHP_FPM_MAX_SPARE_SERVERS=14' \
  'BACKEND_API_MEMORY_LIMIT=3g' \
  'BACKEND_API_MEMORY_RESERVATION=768m'; do
  require_line "${env_template}" "${expected}"
done

status_block="$(awk '
  /^[[:space:]]*location = \/internal\/fpm-status \{/ { capture=1 }
  capture { print }
  capture && /^[[:space:]]*}/ { exit }
' "${nginx_config}")"
ping_block="$(awk '
  /^[[:space:]]*location = \/internal\/fpm-ping \{/ { capture=1 }
  capture { print }
  capture && /^[[:space:]]*}/ { exit }
' "${nginx_config}")"
grep -Fq 'fastcgi_pass php_observer;' <<<"${status_block}"
grep -Fq 'fastcgi_pass unix:/run/php-fpm/app.sock;' <<<"${ping_block}"
if grep -Fq 'fastcgi_pass unix:/run/php-fpm/app.sock;' <<<"${status_block}" \
  || grep -Fq 'fastcgi_pass php_observer;' <<<"${ping_block}"; then
  echo 'Los listeners FPM de trafico y estado no estan aislados.' >&2
  exit 1
fi

for transport_contract in \
  'upstream php_observer {' \
  'server backend-api:9001;' \
  'fastcgi_pass unix:/run/php-fpm/app.sock;' \
  'fastcgi_pass php_observer;'; do
  grep -Fq "${transport_contract}" "${nginx_config}"
done
if grep -Fq 'fastcgi_pass $php_upstream' "${nginx_config}" \
  || grep -Fq 'upstream php_application' "${nginx_config}" \
  || grep -Fq 'server backend-api:9000' "${nginx_config}" \
  || grep -Fq 'fastcgi_keep_conn' "${nginx_config}" \
  || grep -Eq '^[[:space:]]*keepalive(_requests|_timeout)?[[:space:]]' "${nginx_config}" \
  || [[ "$(grep -Fc 'fastcgi_pass unix:/run/php-fpm/app.sock;' "${nginx_config}")" -ne 5 ]] \
  || [[ "$(grep -Fc 'fastcgi_pass php_observer;' "${nginx_config}")" -ne 1 ]] \
  || [[ "$(grep -Fc 'upstream php_observer {' "${nginx_config}")" -ne 1 ]]; then
  echo 'El transporte Nginx/FPM no usa exclusivamente socket Unix por request y estado TCP aislado.' >&2
  exit 1
fi

echo 'PHP-FPM capacity/status isolation contract: OK (16/12/12/14; app=unix socket; status=9001 sin keepalive; memory=3g).'
