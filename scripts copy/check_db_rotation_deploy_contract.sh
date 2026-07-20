#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMMON_SCRIPT="${SCRIPT_DIR}/common.sh"
TENANT_ISOLATION_SCRIPT="${SCRIPT_DIR}/../../basesdedatos/scripts/tenant-isolation.sh"

bash -n "${COMMON_SCRIPT}"
bash -n "${TENANT_ISOLATION_SCRIPT}"
if ! grep -Fq 'assert_db_mode "${env_file}"' "${TENANT_ISOLATION_SCRIPT}"; then
  echo "tenant-isolation no atesta el modo real de PostgreSQL antes de provisionar." >&2
  exit 1
fi
if ! grep -Fq 'Ambientes incompatibles para aislamiento tenant: basesdedatos=${mode}, backend=${backend_mode}' "${TENANT_ISOLATION_SCRIPT}"; then
  echo "tenant-isolation no bloquea una combinacion backend/DB de ambientes distintos." >&2
  exit 1
fi

python3 - "${COMMON_SCRIPT}" <<'PY'
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
source = path.read_text(encoding="utf-8")
deploy_marker = "deploy_backend() {"
if deploy_marker not in source:
    raise SystemExit("No existe deploy_backend() en common.sh.")
deploy = source[source.index(deploy_marker):]

required_global = {
    "upserts sin secreto en argv": 'python3 - "$file" "$key" 3<<<"${value}"',
    "actualizacion conjunta DB_PASSWORD/DB_PASSWORD_BILLING": 'for key in ("DB_PASSWORD", "DB_PASSWORD_BILLING")',
    "secreto por descriptor privado": 'os.fdopen(3, "r", encoding="utf-8")',
    "archivo temporal en mismo directorio": 'tempfile.mkstemp(prefix=f".{path.name}.rotate-", dir=path.parent)',
    "persistencia del archivo": "os.fsync(handle.fileno())",
    "rename atomico": "os.replace(temporary_name, path)",
    "modo secreto 0600": "os.chmod(path, stat.S_IRUSR | stat.S_IWUSR)",
    "persistencia del directorio": "os.fsync(directory_fd)",
    "corte de sesiones antiguas": "PERFORM pg_terminate_backend(pid)",
    "pgpass efimero en rotacion": 'mktemp /tmp/pgpass.XXXXXX',
    "pgpass privado en rotacion": 'chmod 600 "$passfile"',
    "psql usa pgpass": 'PGPASSFILE="$passfile" psql -X',
    "limpieza de password heredado": 'unset POSTGRES_PASSWORD PGPASSWORD',
    "guard fail-closed no restaura secreto": 'el secreto anterior no se restaura',
    "guard vuelve a NOLOGIN": 'if disable_backend_database_role',
    "guard comprueba NOLOGIN": 'rol DB comprobado en NOLOGIN',
}
for description, snippet in required_global.items():
    if snippet not in source:
        raise SystemExit(f"Falta contrato de rotacion: {description}.")

required_deploy = {
    "flag explicito": 'local rotate_db_app_password="${ROTATE_DB_APP_PASSWORD:-0}"',
    "fallback ecommerce capturado como one-shot": 'local runtime_ecommerce_legacy_tenant_id="${ECOMMERCE_LEGACY_TENANT_ID:-}"',
    "fallback billing capturado como one-shot": 'local runtime_billing_legacy_tenant_id="${BILLING_LEGACY_TENANT_ID:-}"',
    "prioridad one-shot ecommerce": 'ecommerce_legacy_tenant_id="$(normalize_env_value "${runtime_ecommerce_legacy_tenant_id}")"',
    "prioridad one-shot billing": 'billing_legacy_tenant_id="$(normalize_env_value "${runtime_billing_legacy_tenant_id}")"',
    "validacion slug fallback": 'Los fallbacks tenant legacy deben ser slugs canonicos one-shot.',
    "ambiente backend/DB alineado": 'Ambientes incompatibles: backend=${mode}, basesdedatos=${shared_db_mode}',
    "RUN_DB_SETUP obligatorio": 'ROTATE_DB_APP_PASSWORD=1 exige RUN_DB_SETUP=1',
    "RLS enforce obligatorio": 'La rotacion/migracion integrada exige TENANT_RLS_MODE=enforce o ausente para prepararlo',
    "preflight openssl": 'command -v openssl >/dev/null 2>&1',
    "preflight python3": 'command -v python3 >/dev/null 2>&1',
    "preflight keyring con identidad runtime": 'scripts/manage_billing_secret_keyring.php validate',
    "keyring en destino runtime": '--file=/run/secrets/backend/billing-secret-keyring.json',
    "sincronizador obligatorio": 'Falta el sincronizador DB requerido por la rotacion',
    "registro canonico de bases": 'config/module-databases.json',
    "verificacion de todas las bases": 'for app_database in "${app_databases[@]}"',
    "guard con contexto global no exportado": 'BACKEND_ROTATION_GUARD_ADMIN_PASSWORD="${db_admin_password}"',
    "trampa invoca guard verificable": 'backend_rotation_exit_guard',
    "credenciales admin/app independientes": 'if [[ "${rotated_app_password}" == "${db_admin_password}" ]]; then',
    "bootstrap admin por stdin": 'IFS= read -r DB_ADMIN_PASSWORD',
    "bootstrap FDW por stdin": 'IFS= read -r DB_FDW_PASSWORD',
    "bootstrap sin TTY": 'run --rm --no-deps -T',
    "fallo RLS detiene runtimes": 'Aislamiento tenant no atestado; runtimes backend detenidos.',
}
for description, snippet in required_deploy.items():
    if snippet not in deploy:
        raise SystemExit(f"Falta contrato deploy de rotacion: {description}.")

ordered_patterns = [
    ("validar ambiente compartido", r'^\s*shared_db_mode="\$\(env_mode_from_file "\$\{shared_db_env_file\}"\)"$'),
    ("build", r'^\s*docker compose --env-file "\$\{env_file\}" build api$'),
    ("preflight storage", r'^\s*--entrypoint php api scripts/check_storage_configuration\.php --quiet$'),
    ("preflight keyring runtime", r'^\s*--file=/run/secrets/backend/billing-secret-keyring\.json$'),
    ("preparar/provisionar RLS", r'^\s*"\$\{tenant_isolation_script\}" --prepare$'),
    ("stop runtimes", r'^\s*compose_cmd "\$\{env_file\}" stop api http sri-worker wallet-notify-worker commerce-billing-worker mailer-worker >/dev/null$'),
    ("invalidar rol", r'^\s{4}disable_backend_database_role "\$\{db_admin_username\}" "\$\{db_admin_password\}" "\$\{app_role\}"$'),
    ("generar secreto", r'^\s*rotated_app_password="\$\(openssl rand -hex 32\)"$'),
    ("actualizar env", r'^\s*atomic_replace_backend_database_passwords "\$\{env_file\}" "\$\{rotated_app_password\}"$'),
    ("sincronizar roles/mappings", r'^\s*\./scripts/sync-module-databases\.sh$'),
    ("validar credencial nueva", r'^\s*verify_rotated_backend_database_role "\$\{app_role\}" "\$\{rotated_app_password\}" "\$\{app_database\}"$'),
    ("bootstrap modular", r"^\s*php scripts/bootstrap_module_databases\.php$"),
    ("aplicar RLS", r'^\s*"\$\{tenant_isolation_script\}" --apply$'),
    ("verificar RLS", r'^\s*if ! "\$\{tenant_isolation_script\}" --check; then$'),
    ("arrancar runtimes", r'^\s*docker compose --env-file "\$\{env_file\}" up -d --force-recreate --remove-orphans api http sri-worker wallet-notify-worker commerce-billing-worker mailer-worker$'),
    ("desarmar trampa", r'^\s*trap - EXIT$'),
]

positions = []
for description, pattern in ordered_patterns:
    match = re.search(pattern, deploy, re.MULTILINE)
    if match is None:
        raise SystemExit(f"No se encontro paso de rotacion: {description}.")
    positions.append((description, match.start()))

for (left_name, left), (right_name, right) in zip(positions, positions[1:]):
    if left >= right:
        raise SystemExit(
            f"Orden inseguro de rotacion: {left_name} debe ocurrir antes de {right_name}."
        )

stop_pattern = next(pattern for description, pattern in ordered_patterns if description == "stop runtimes")
stop_line = re.search(stop_pattern, deploy, re.MULTILINE)
assert stop_line is not None
if "|| true" in stop_line.group(0):
    raise SystemExit("El stop previo a rotacion no puede ignorar errores.")

for forbidden in (
    "old_app_password",
    "previous_db_password",
    "restore_old_password",
    'python3 - "$file" "$key" "$value"',
    "docker exec -e PGPASSWORD=",
    "docker exec -i -e PGPASSWORD=",
    "-e DB_ADMIN_PASSWORD",
    "-e DB_FDW_PASSWORD",
    "IFS= read -r PGPASSWORD",
    "export PGPASSWORD",
    'upsert_env_value "${env_file}" "ECOMMERCE_LEGACY_TENANT_ID"',
    'upsert_env_value "${env_file}" "BILLING_LEGACY_TENANT_ID"',
    'php scripts/bootstrap_schema.php',
  ):
    if forbidden in source:
        raise SystemExit(f"Patron inseguro presente en rotacion: {forbidden}.")

print("Contrato estatico de rotacion DB: OK")
PY

tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT
test_env="${tmp_dir}/backend.env"
test_secret="$(openssl rand -hex 32)"
umask 077
printf '%s\n' \
  'APP_ENV=qa' \
  'DB_PASSWORD=legacy-test-value' \
  'DB_PASSWORD_BILLING=legacy-test-value' >"${test_env}"
(
  # shellcheck source=./common.sh
  source "${COMMON_SCRIPT}"
  atomic_replace_backend_database_passwords "${test_env}" "${test_secret}"
)

db_password="$(awk -F= '$1 == "DB_PASSWORD" {print substr($0, index($0, "=") + 1)}' "${test_env}")"
billing_password="$(awk -F= '$1 == "DB_PASSWORD_BILLING" {print substr($0, index($0, "=") + 1)}' "${test_env}")"
if [[ "${db_password}" != "${test_secret}" || "${billing_password}" != "${test_secret}" ]]; then
  echo "La prueba atomica no actualizo ambas credenciales con el mismo valor." >&2
  exit 1
fi
if [[ "$(stat -c '%a' "${test_env}")" != "600" ]]; then
  echo "La prueba atomica no dejo el archivo de entorno en modo 0600." >&2
  exit 1
fi
if find "${tmp_dir}" -maxdepth 1 -type f -name '*.rotate-*' -print -quit | grep -q .; then
  echo "La prueba atomica dejo un archivo temporal residual." >&2
  exit 1
fi
unset test_secret db_password billing_password
