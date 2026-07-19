#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT
probe_keyring="${tmp_dir}/billing-secret-keyring.json"
php "${SCRIPT_DIR}/manage_billing_secret_keyring.php" init --file="${probe_keyring}" >/dev/null

php "${APP_DIR}/src/Infrastructure/Workers/Tests/check_worker_cycle_result.php"
php "${APP_DIR}/src/Infrastructure/Workers/Tests/check_wallet_notification_budget.php"
php "${APP_DIR}/src/Infrastructure/Workers/Tests/check_worker_tenant_fairness.php"
php "${SCRIPT_DIR}/check_internal_proxy_scope_isolation.php"
php "${APP_DIR}/src/Infrastructure/Storage/Tests/check_local_storage_migration.php"
php "${APP_DIR}/src/Infrastructure/Storage/Tests/check_public_upload_url_resolver.php"
php "${APP_DIR}/src/Infrastructure/Storage/Tests/check_billing_artifact_tenant_keys.php"
php "${SCRIPT_DIR}/check_billing_secret_encryption.php"
php "${APP_DIR}/src/Modules/Billing/Tests/check_billing_commerce_ports.php"
php "${APP_DIR}/src/Modules/Billing/Tests/check_monolog_stream_channel.php"
php "${APP_DIR}/src/Modules/Commerce/Tests/check_billing_port_boundary.php"
php "${APP_DIR}/src/Modules/Commerce/Tests/check_commerce_billing_outbox.php"
php "${APP_DIR}/src/Modules/Commerce/Tests/check_commerce_billing_tenant_credentials.php"
php "${APP_DIR}/src/Modules/Mailer/Tests/check_mailer_durable_outbox.php"
php "${APP_DIR}/src/Modules/Commerce/Tests/check_collaboration_port_boundaries.php"
php "${APP_DIR}/src/Modules/Commerce/Tests/check_shipping_settings_batch_read.php"
php "${APP_DIR}/src/Modules/Commerce/Tests/check_tenant_tax_source_of_truth.php"
php "${APP_DIR}/src/Modules/ReportingFinance/Tests/check_reporting_finance_boundaries.php"
php "${APP_DIR}/src/Modules/ReportingFinance/Tests/check_product_analytics_projection.php"
php "${SCRIPT_DIR}/check_module_dependency_boundaries.php"
php "${APP_DIR}/src/Modules/IdentityPlatform/Tests/check_tenant_registry_snapshot.php"
php "${APP_DIR}/src/Modules/IdentityPlatform/Tests/check_admin_ip_access_policy.php"
php "${APP_DIR}/src/Modules/IdentityPlatform/Tests/check_auth_surface_persistence_boundary.php"
php "${APP_DIR}/src/Modules/LoyaltyRewards/Tests/check_purchase_source_security_contract.php"
php "${APP_DIR}/src/Modules/LoyaltyRewards/Tests/check_purchase_source_verifier.php"
php "${APP_DIR}/src/Modules/LoyaltyRewards/Tests/check_loyalty_health_lightweight.php"
WORKER_RUNNER="${APP_DIR}/docker/periodic-worker.sh"
WORKER_HEALTH="${APP_DIR}/docker/check-periodic-worker-health.sh"

for script in \
  "${WORKER_RUNNER}" \
  "${WORKER_HEALTH}" \
  "${APP_DIR}/docker/start-php-fpm.sh"; do
  sh -n "${script}"
done
bash -n "${SCRIPT_DIR}/benchmark-api.sh"
bash -n "${SCRIPT_DIR}/collect_runtime_metrics.sh"
bash -n "${SCRIPT_DIR}/check_runtime_slo.sh"
bash -n "${SCRIPT_DIR}/check_runtime_slo_target_policy.sh"
bash -n "${SCRIPT_DIR}/run_sustained_mixed_load.sh"
bash -n "${SCRIPT_DIR}/common.sh"
php "${SCRIPT_DIR}/check_sustained_load_pacing.php" >/dev/null
"${SCRIPT_DIR}/check_fpm_capacity_contract.sh" >/dev/null
"${SCRIPT_DIR}/check_db_rotation_deploy_contract.sh" >/dev/null
"${SCRIPT_DIR}/check_runtime_slo.sh" --preflight >/dev/null
"${SCRIPT_DIR}/check_runtime_slo_target_policy.sh" >/dev/null
if DURATION_SECONDS=5 ALLOW_SHORT_TEST=false OUTPUT_DIR="${tmp_dir}/short-load" \
  "${SCRIPT_DIR}/run_sustained_mixed_load.sh" >/dev/null 2>&1; then
  echo "La carga sostenida acepto menos de 600 segundos sin opt-in de prueba." >&2
  exit 1
fi

php -l "${SCRIPT_DIR}/process_billing_recovery.php" >/dev/null
php -l "${SCRIPT_DIR}/check_sustained_load_pacing.php" >/dev/null
php -l "${SCRIPT_DIR}/process_wallet_notifications.php" >/dev/null
php -l "${SCRIPT_DIR}/process_mailer_outbox.php" >/dev/null
php -l "${SCRIPT_DIR}/check_storage_configuration.php" >/dev/null
php -l "${SCRIPT_DIR}/check_object_storage_canary.php" >/dev/null
php -l "${SCRIPT_DIR}/migrate_local_storage_to_s3.php" >/dev/null
php -l "${SCRIPT_DIR}/check_database_transport_policy.php" >/dev/null
php -l "${SCRIPT_DIR}/check_fdw_bootstrap_secret_boundary.php" >/dev/null
php -l "${SCRIPT_DIR}/check_platform_auth_rls_contract.php" >/dev/null
php -l "${SCRIPT_DIR}/check_billing_tenant_bootstrap_contract.php" >/dev/null
php -l "${SCRIPT_DIR}/check_billing_secret_runtime_readiness.php" >/dev/null
grep -Fq 'TenantContext::set' "${SCRIPT_DIR}/check_billing_secret_runtime_readiness.php"
php -l "${SCRIPT_DIR}/migrate_billing_secrets.php" >/dev/null
php -l "${SCRIPT_DIR}/check_billing_secret_storage.php" >/dev/null
php -l "${SCRIPT_DIR}/manage_billing_secret_keyring.php" >/dev/null
php -l "${SCRIPT_DIR}/manage_commerce_billing_credentials.php" >/dev/null
php -l "${SCRIPT_DIR}/process_commerce_billing_outbox.php" >/dev/null
php -l "${APP_DIR}/src/Modules/Billing/Native/Billing/Infrastructure/Security/BillingSecretCipher.php" >/dev/null
php -l "${APP_DIR}/src/Modules/Billing/Native/Billing/Infrastructure/Security/BillingSecretCipherFactory.php" >/dev/null
php -l "${APP_DIR}/src/Modules/Billing/Native/Billing/Infrastructure/Security/BillingSecretAdminConnection.php" >/dev/null
php -l "${SCRIPT_DIR}/check_modular_bootstrap_safety.php" >/dev/null
php -l "${SCRIPT_DIR}/check_http_list_boundaries.php" >/dev/null
php -l "${APP_DIR}/src/Modules/Commerce/Application/OrderListSummary.php" >/dev/null
php -l "${APP_DIR}/src/Modules/Commerce/Tests/check_order_list_summary.php" >/dev/null
php -l "${APP_DIR}/src/Modules/CatalogInventory/Application/PublicCatalogCursor.php" >/dev/null
php -l "${APP_DIR}/src/Modules/CatalogInventory/Application/PublicCatalogFilters.php" >/dev/null
php -l "${APP_DIR}/src/Modules/CatalogInventory/Tests/check_admin_catalog_pagination.php" >/dev/null
php "${SCRIPT_DIR}/check_fdw_bootstrap_secret_boundary.php" >/dev/null
php "${SCRIPT_DIR}/check_platform_auth_rls_contract.php" >/dev/null
php "${SCRIPT_DIR}/check_billing_tenant_bootstrap_contract.php" >/dev/null
php "${SCRIPT_DIR}/check_modular_bootstrap_safety.php" >/dev/null
php "${SCRIPT_DIR}/check_http_list_boundaries.php" >/dev/null
php "${APP_DIR}/src/Modules/Commerce/Tests/check_order_list_summary.php" >/dev/null
php "${APP_DIR}/src/Modules/CatalogInventory/Tests/check_public_catalog_cursor.php" >/dev/null
php "${APP_DIR}/src/Modules/CatalogInventory/Tests/check_public_catalog_filters.php" >/dev/null
php "${APP_DIR}/src/Modules/CatalogInventory/Tests/check_public_product_projection.php" >/dev/null
php "${APP_DIR}/src/Modules/CatalogInventory/Tests/check_admin_catalog_pagination.php" >/dev/null
php "${APP_DIR}/src/Modules/Commerce/Tests/check_http_list_pagination.php" >/dev/null
php "${APP_DIR}/src/Modules/Commerce/Tests/check_admin_list_pagination.php" >/dev/null
php "${APP_DIR}/src/Infrastructure/Storage/Tests/check_storage_drivers.php" >/dev/null
php "${SCRIPT_DIR}/check_database_transport_policy.php" >/dev/null
php "${APP_DIR}/src/Modules/CatalogInventory/Tests/check_catalog_image_storage.php" >/dev/null
grep -Fq 'zend.exception_ignore_args = On' "${APP_DIR}/docker/Dockerfile"
grep -Fq 'zend.exception_string_param_max_len = 0' "${APP_DIR}/docker/Dockerfile"
grep -Fq 'opcache.validate_timestamps = Off' "${APP_DIR}/docker/Dockerfile"
for catalog_cache_contract in \
  'location = /api/products' \
  'location = /api/settings/shipping' \
  'location ~ ^/api/settings/(?:brand-logos|product-categories|product-category-references)$' \
  'fastcgi_cache_key "$http_host|$http_x_forwarded_host|$http_x_original_host|$request_uri|$http_origin|$http_accept_encoding"' \
  'fastcgi_cache_bypass $catalog_cache_bypass' \
  'fastcgi_no_cache $catalog_cache_bypass $upstream_http_set_cookie' \
  'fastcgi_cache_valid 200 15s' \
  'fastcgi_cache_valid 200 60s' \
  'fastcgi_cache_lock on' \
  'fastcgi_cache_background_update on' \
  'fastcgi_cache_use_stale error timeout invalid_header updating http_500 http_503' \
  'add_header X-Public-Read-Cache $upstream_cache_status always'; do
  grep -Fq "${catalog_cache_contract}" "${APP_DIR}/docker/nginx.conf"
done
for public_settings_cache_contract in \
  "enableAnonymousPublicReadCache('/api/settings/shipping')" \
  "enableAnonymousPublicReadCache('/api/settings/brand-logos', true)" \
  "enableAnonymousPublicReadCache('/api/settings/product-categories', true)" \
  "enableAnonymousPublicReadCache('/api/settings/product-category-references', true)" \
  "header('Cache-Control: public, max-age=15, s-maxage=15, must-revalidate')" \
  "header('Cache-Control: public, max-age=60, s-maxage=60, stale-while-revalidate=30, stale-if-error=300')" \
  "header('Vary: Origin, Accept-Encoding')"; do
  grep -Fq "${public_settings_cache_contract}" "${APP_DIR}/src/Http/Shared/SettingsControllerBase.php"
done
APP_ENV=qa STORAGE_DRIVER=local REQUIRE_HA=false \
  php "${SCRIPT_DIR}/check_storage_configuration.php" --quiet
if APP_ENV=qa STORAGE_DRIVER=local REQUIRE_HA=true \
  php "${SCRIPT_DIR}/check_storage_configuration.php" --quiet >/dev/null 2>&1; then
  echo "El preflight de storage acepto filesystem local con REQUIRE_HA=true." >&2
  exit 1
fi
if APP_ENV=production STORAGE_DRIVER=s3 REQUIRE_HA=false \
  OBJECT_STORAGE_ENDPOINT=https://objects.example.test \
  OBJECT_STORAGE_PUBLIC_BASE_URL=https://cdn.example.test/catalog \
  OBJECT_STORAGE_BUCKET=pm-artifacts \
  OBJECT_STORAGE_ACCESS_KEY=fake-access-key \
  OBJECT_STORAGE_SECRET_KEY=fake-secret-key \
  php "${SCRIPT_DIR}/check_storage_configuration.php" --quiet >/dev/null 2>&1; then
  echo "El preflight acepto production sin REQUIRE_HA=true." >&2
  exit 1
fi
APP_ENV=production STORAGE_DRIVER=s3 REQUIRE_HA=true \
  OBJECT_STORAGE_ENDPOINT=https://objects.example.test \
  OBJECT_STORAGE_PUBLIC_BASE_URL=https://cdn.example.test/catalog \
  OBJECT_STORAGE_BUCKET=pm-artifacts \
  OBJECT_STORAGE_ACCESS_KEY=fake-access-key \
  OBJECT_STORAGE_SECRET_KEY=fake-secret-key \
  php "${SCRIPT_DIR}/check_storage_configuration.php" --quiet

if APP_ENV=production OBJECT_STORAGE_CANARY_ENABLED=true \
  php "${SCRIPT_DIR}/check_object_storage_canary.php" >/dev/null 2>&1; then
  echo "El canary S3 se ejecuto sin el opt-in --execute." >&2
  exit 1
fi
if APP_ENV=qa OBJECT_STORAGE_CANARY_ENABLED=true \
  php "${SCRIPT_DIR}/check_object_storage_canary.php" --execute >/dev/null 2>&1; then
  echo "El canary S3 acepto ejecutarse fuera de production." >&2
  exit 1
fi

BILLING_SECRET_KEYRING_HOST_PATH="${probe_keyring}" docker compose \
  --project-directory "${APP_DIR}" \
  --env-file "${APP_DIR}/templates/entorno/.env.example" \
  -f "${APP_DIR}/docker-compose.yml" \
  config --quiet

compose_json="$(BILLING_SECRET_KEYRING_HOST_PATH="${probe_keyring}" docker compose \
  --project-directory "${APP_DIR}" \
  --env-file "${APP_DIR}/templates/entorno/.env.example" \
  -f "${APP_DIR}/docker-compose.yml" \
  config --format json)"
COMPOSE_JSON="${compose_json}" php -r '
  $config = json_decode((string)getenv("COMPOSE_JSON"), true, 512, JSON_THROW_ON_ERROR);
  $services = $config["services"] ?? [];
  $api = $services["api"]["environment"] ?? [];
  $billingKeyringPath = "/run/secrets/backend/billing-secret-keyring.json";
  if (($api["BILLING_SECRET_KEYRING_FILE"] ?? null) !== $billingKeyringPath) {
      fwrite(STDERR, "api no apunta al keyring Billing montado.\n");
      exit(1);
  }
  if (($api["BILLING_SECRET_LEGACY_READ_ENABLED"] ?? null) !== "false"
      || ($api["BILLING_SECRET_WRITE_MODE"] ?? null) !== "encrypted") {
      fwrite(STDERR, "api no usa el contrato Billing ciphertext-only por defecto.\n");
      exit(1);
  }
  if (!array_key_exists("OBJECT_STORAGE_PUBLIC_BASE_URL", $api)) {
      fwrite(STDERR, "api no recibe OBJECT_STORAGE_PUBLIC_BASE_URL.\n");
      exit(1);
  }
  foreach (["DB_ADMIN_USERNAME", "DB_ADMIN_PASSWORD", "DB_FDW_USERNAME", "DB_FDW_PASSWORD", "DB_WORKER_USERNAME", "DB_WORKER_PASSWORD", "DB_PLATFORM_AUTH_ROLE", "ECOMMERCE_LEGACY_TENANT_ID", "BILLING_LEGACY_TENANT_ID"] as $forbidden) {
      if (array_key_exists($forbidden, $api)) {
          fwrite(STDERR, "api expone credencial privilegiada: {$forbidden}\n");
          exit(1);
      }
  }
  foreach (array_keys($api) as $key) {
      if (str_starts_with((string)$key, "DB_WORKER_")) {
          fwrite(STDERR, "api expone credencial worker: {$key}\n");
          exit(1);
      }
  }
  $workerContracts = [
      "commerce-billing-worker" => ["DB_WORKER_USERNAME_COMMERCE", "DB_WORKER_PASSWORD_COMMERCE", ["BILLING", "LOYALTY_REWARDS", "IDENTITY_PLATFORM", "MAILER_SERVICE"]],
      "sri-worker" => ["DB_WORKER_USERNAME_BILLING", "DB_WORKER_PASSWORD_BILLING", ["COMMERCE", "LOYALTY_REWARDS", "IDENTITY_PLATFORM", "MAILER_SERVICE"]],
      "wallet-notify-worker" => ["DB_WORKER_USERNAME_LOYALTY_REWARDS", "DB_WORKER_PASSWORD_LOYALTY_REWARDS", ["COMMERCE", "BILLING", "IDENTITY_PLATFORM", "MAILER_SERVICE"]],
      "mailer-worker" => ["DB_WORKER_USERNAME_MAILER_SERVICE", "DB_WORKER_PASSWORD_MAILER_SERVICE", ["COMMERCE", "BILLING", "LOYALTY_REWARDS", "IDENTITY_PLATFORM"]],
  ];
  foreach ($workerContracts as $name => [$requiredUser, $requiredPassword, $forbiddenSuffixes]) {
      $env = $services[$name]["environment"] ?? [];
      foreach (["DB_USERNAME", "DB_PASSWORD", "DB_ADMIN_USERNAME", "DB_ADMIN_PASSWORD", "DB_FDW_USERNAME", "DB_FDW_PASSWORD"] as $forbidden) {
          if (array_key_exists($forbidden, $env)) {
              fwrite(STDERR, "{$name} expone credencial no permitida: {$forbidden}\n");
              exit(1);
          }
      }
      $hasForeignWorkerCredential = false;
      foreach ($forbiddenSuffixes as $forbiddenSuffix) {
          $hasForeignWorkerCredential = $hasForeignWorkerCredential
              || array_key_exists("DB_WORKER_USERNAME_{$forbiddenSuffix}", $env)
              || array_key_exists("DB_WORKER_PASSWORD_{$forbiddenSuffix}", $env);
      }
      if (($env["DB_CONNECTION_ROLE"] ?? null) !== "worker"
          || !array_key_exists($requiredUser, $env)
          || !array_key_exists($requiredPassword, $env)
          || array_key_exists("DB_WORKER_USERNAME", $env)
          || array_key_exists("DB_WORKER_PASSWORD", $env)
          || $hasForeignWorkerCredential) {
          fwrite(STDERR, "{$name} no declara exclusivamente su rol worker de dominio.\n");
          exit(1);
      }
  }
  $sri = $services["sri-worker"] ?? [];
  if ((($sri["environment"] ?? [])["BILLING_SECRET_KEYRING_FILE"] ?? null) !== $billingKeyringPath) {
      fwrite(STDERR, "sri-worker no apunta al keyring Billing montado.\n");
      exit(1);
  }
  if ((($sri["environment"] ?? [])["BILLING_SECRET_LEGACY_READ_ENABLED"] ?? null) !== "false"
      || (($sri["environment"] ?? [])["BILLING_SECRET_WRITE_MODE"] ?? null) !== "encrypted") {
      fwrite(STDERR, "sri-worker no usa el contrato Billing ciphertext-only por defecto.\n");
      exit(1);
  }
  foreach (["api", "sri-worker"] as $name) {
      $serviceSecrets = $services[$name]["secrets"] ?? [];
      $targets = array_map(static fn(array $secret): string => (string)($secret["target"] ?? ""), $serviceSecrets);
      if (!in_array($billingKeyringPath, $targets, true)) {
          fwrite(STDERR, "{$name} no monta el keyring Billing en el destino canonico.\n");
          exit(1);
      }
  }
  $commerceBillingService = $services["commerce-billing-worker"] ?? [];
  $commerceBilling = $commerceBillingService["environment"] ?? [];
  $commerceBillingRegistryPath = "/run/secrets/backend/commerce-billing-credentials.json";
  if (($commerceBilling["BILLING_OUTBOX_CREDENTIALS_FILE"] ?? null) !== $commerceBillingRegistryPath
      || array_key_exists("BILLING_OUTBOX_API_KEY", $commerceBilling)
      || array_key_exists("BILLING_API_KEY", $commerceBilling)) {
      fwrite(STDERR, "commerce-billing-worker no usa exclusivamente el registry multi-tenant por archivo.\n");
      exit(1);
  }
  $commerceBillingSecretTargets = array_map(
      static fn(array $secret): string => (string)($secret["target"] ?? ""),
      $commerceBillingService["secrets"] ?? []
  );
  if ($commerceBillingSecretTargets !== [$commerceBillingRegistryPath]) {
      fwrite(STDERR, "commerce-billing-worker monta secretos fuera de su registry tenant.\n");
      exit(1);
  }
  $walletService = $services["wallet-notify-worker"] ?? [];
  if (isset($walletService["secrets"])) {
      foreach ($walletService["secrets"] as $secret) {
          if (($secret["target"] ?? null) === $billingKeyringPath) {
              fwrite(STDERR, "wallet-notify-worker monta un keyring Billing ajeno.\n");
              exit(1);
          }
      }
  }
  $wallet = $services["wallet-notify-worker"]["environment"] ?? [];
  foreach (["OBJECT_STORAGE_ACCESS_KEY", "OBJECT_STORAGE_SECRET_KEY", "OBJECT_STORAGE_SESSION_TOKEN"] as $forbidden) {
      if (array_key_exists($forbidden, $wallet)) {
          fwrite(STDERR, "wallet-notify-worker expone credencial object-storage innecesaria: {$forbidden}\n");
          exit(1);
      }
  }
  $mailerService = $services["mailer-worker"] ?? [];
  $mailer = $mailerService["environment"] ?? [];
  foreach (["JWT_SECRET", "EDGE_BACKEND_PROXY_TOKEN", "STOREFRONT_BACKEND_PROXY_TOKEN", "BILLING_API_KEY", "BILLING_SECRET_KEYRING_FILE", "GOOGLE_WALLET_SA_PATH", "OBJECT_STORAGE_ACCESS_KEY", "OBJECT_STORAGE_SECRET_KEY", "OBJECT_STORAGE_SESSION_TOKEN"] as $forbidden) {
      if (array_key_exists($forbidden, $mailer)) {
          fwrite(STDERR, "mailer-worker expone secreto ajeno: {$forbidden}\n");
          exit(1);
      }
  }
  $mailerNetworks = array_keys($mailerService["networks"] ?? []);
  sort($mailerNetworks);
  if ($mailerNetworks !== ["backend_egress", "db_internal"]
      || !str_contains(implode(" ", $mailerService["command"] ?? []), "process_mailer_outbox.php")) {
      fwrite(STDERR, "mailer-worker no conserva la frontera DB Mailer + SMTP.\n");
      exit(1);
  }
' >/dev/null

if rg -q '^[[:space:]]*env_file:' "${APP_DIR}/docker-compose.yml"; then
  echo "docker-compose.yml aun inyecta un env_file completo en un servicio." >&2
  exit 1
fi

if ! rg -q -- '-e BILLING_LEGACY_TENANT_ID' "${APP_DIR}/scripts/common.sh"; then
  echo "El bootstrap one-shot no recibe BILLING_LEGACY_TENANT_ID de forma efimera." >&2
  exit 1
fi
if ! rg -q -- '-e ECOMMERCE_LEGACY_TENANT_ID' "${APP_DIR}/scripts/common.sh"; then
  echo "El bootstrap one-shot no recibe ECOMMERCE_LEGACY_TENANT_ID de forma efimera." >&2
  exit 1
fi
if rg -q -- '-e (DB_ADMIN_PASSWORD|DB_FDW_PASSWORD)' "${APP_DIR}/scripts/common.sh"; then
  echo "El bootstrap one-shot expone un password privilegiado en Config.Env de Docker." >&2
  exit 1
fi
for required_stdin_contract in \
  'IFS= read -r DB_ADMIN_PASSWORD' \
  'IFS= read -r DB_FDW_PASSWORD' \
  'export DB_ADMIN_PASSWORD DB_FDW_PASSWORD' \
  'run --rm --no-deps -T'; do
  if ! grep -Fq -- "${required_stdin_contract}" "${APP_DIR}/scripts/common.sh"; then
    echo "El bootstrap one-shot no cumple transporte privilegiado por stdin: ${required_stdin_contract}." >&2
    exit 1
  fi
done

runner_log="${tmp_dir}/runner.log"
set +e
PERIODIC_WORKER_STATE_DIR="${tmp_dir}/state" \
  timeout --signal=TERM 3 \
  /bin/sh "${WORKER_RUNNER}" self-test 1 /bin/true >"${runner_log}" 2>&1
runner_exit="$?"
set -e
if [[ "${runner_exit}" -ne 0 && "${runner_exit}" -ne 124 ]]; then
  echo "El supervisor periodico fallo en self-test (exit=${runner_exit})." >&2
  cat "${runner_log}" >&2
  exit 1
fi

state_dir="${tmp_dir}/state/self-test"
test -s "${state_dir}/last-success"
test "$(cat "${state_dir}/last-exit-code")" = "0"
WORKER_HEALTH_SKIP_PROCESS_CHECK=1 PERIODIC_WORKER_STATE_DIR="${tmp_dir}/state" \
  /bin/sh "${WORKER_HEALTH}" self-test 2 >/dev/null

echo 1 > "${state_dir}/last-exit-code"
if WORKER_HEALTH_SKIP_PROCESS_CHECK=1 PERIODIC_WORKER_STATE_DIR="${tmp_dir}/state" \
  /bin/sh "${WORKER_HEALTH}" self-test 2 >/dev/null 2>&1; then
  echo "El healthcheck acepto incorrectamente un ultimo ciclo fallido." >&2
  exit 1
fi

echo "Runtime operativo backend: OK"
