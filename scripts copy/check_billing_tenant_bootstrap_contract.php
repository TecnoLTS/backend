<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_module_databases.php';

use App\Modules\Billing\Domain\BillingDomain;

$failures = [];
$expectedOwnerTables = [
    'clients',
    'client_branches',
    'billing_customers',
    'api_keys',
    'invoice_retry_settings',
    'branch_sequences',
    'invoice_headers',
    'invoice_details',
    'billing_domain_events',
];

$billingTables = MODULE_TABLES[BillingDomain::KEY] ?? null;
if (!is_array($billingTables) || $billingTables !== $expectedOwnerTables) {
    $failures[] = 'Billing MODULE_TABLES does not match the canonical owner-table list.';
}

$registry = require __DIR__ . '/../config/module-databases.php';
foreach (array_keys(is_array($registry) ? $registry : []) as $moduleKey) {
    if (!array_key_exists((string)$moduleKey, MODULE_TABLES)) {
        $failures[] = "Registry module {$moduleKey} lacks MODULE_TABLES.";
    }
}
$missingRegistryKeys = array_values(array_diff(
    array_keys(MODULE_TABLES),
    array_keys(is_array($registry) ? $registry : [])
));
if ($missingRegistryKeys !== []) {
    $failures[] = 'MODULE_TABLES lacks registry entries: ' . implode(',', $missingRegistryKeys);
}

try {
    $targets = moduleTargets([
        'host' => 'db',
        'port' => '5432',
        'database' => 'ecommerce',
        'username' => 'contract_probe',
        'password' => 'not-used',
    ]);
    if (!isset($targets[BillingDomain::KEY])) {
        $failures[] = 'moduleTargets silently omitted Billing.';
    }
    $billingRegistry = is_array($registry[BillingDomain::KEY] ?? null)
        ? $registry[BillingDomain::KEY]
        : [];
    if (($billingRegistry['target_database'] ?? null) !== BillingDomain::STORE_KEY) {
        $failures[] = 'Billing registry target is not facturacion.';
    }
} catch (Throwable $exception) {
    $failures[] = 'moduleTargets validation failed: ' . $exception->getMessage();
}

$source = file_get_contents(__DIR__ . '/bootstrap_module_databases.php');
$schemaSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Persistence/BillingSchema.php'
);
$migrationSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Persistence/Migrations/001_create_billing_core.sql'
);
$secretMigrationSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Persistence/Migrations/002_enforce_billing_secret_ciphertexts.sql'
);
$taxIdentityMigrationSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Persistence/Migrations/003_add_invoice_detail_tax_identity.sql'
);
$secretMigratorSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Persistence/BillingSecretMigrator.php'
);
$secretCipherSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Security/BillingSecretCipher.php'
);
$secretAdminConnectionSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Security/BillingSecretAdminConnection.php'
);
$secretMigrationCliSource = file_get_contents(__DIR__ . '/migrate_billing_secrets.php');
$secretGateCliSource = file_get_contents(__DIR__ . '/check_billing_secret_storage.php');
$secretKeyringCliSource = file_get_contents(__DIR__ . '/manage_billing_secret_keyring.php');
$configurationSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Persistence/BillingConfigurationRepository.php'
);
$nativeGatewaySource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Infrastructure/NativeBillingGateway.php'
);
$dockerComposeSource = file_get_contents(__DIR__ . '/../docker-compose.yml');
$apiKeySource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Persistence/ApiKeyRepository.php'
);
$invoiceSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Persistence/InvoiceRepository.php'
);
$billingControllerSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Controllers/BillingDocumentController.php'
);
$publicBillingControllerSource = file_get_contents(
    __DIR__ . '/../src/Modules/Billing/Controllers/PublicBillingController.php'
);
foreach ([
    'registry coverage fails closed' => 'lacks a MODULE_TABLES ownership contract',
    'Billing key drives execution' => 'in_array(BillingDomain::KEY, $modules, true)',
    'Billing schema migration executes' => 'createBillingTables($pdo)',
    'Billing isolation executes' => 'ensureBillingTenantIsolation($pdo)',
    'Billing secret migration executes after isolation' => 'migrateBillingSecrets($pdo)',
    'invoice headers derive tenant from customers' => 'SET tenant_id = bc.tenant_id',
    'invoice headers become NOT NULL' => 'ALTER TABLE invoice_headers ALTER COLUMN tenant_id SET NOT NULL',
    'client tables become NOT NULL' => "ALTER TABLE %s ALTER COLUMN tenant_id SET NOT NULL",
    'invoice details child isolation' => "ensureTenantChildIsolation(\$pdo, 'invoice_details', 'invoice_headers'",
    'branch sequence child isolation' => "ensureTenantChildIsolation(\$pdo, 'branch_sequences', 'client_branches'",
    'domain events tenant isolation' => 'ensureBillingDomainEventTenantIsolation($pdo, $legacyTenant)',
] as $description => $snippet) {
    if (!is_string($source) || !str_contains($source, $snippet)) {
        $failures[] = "Missing Billing bootstrap contract: {$description}.";
    }
}
foreach ([
    'explicit expand read boundary' => 'legacyPlaintextReadEnabled',
    'encrypted write method' => 'public function encrypt(',
    'staged runtime write boundary' => 'public function prepareForStorage(',
    'strict contract decrypt' => 'public function decrypt(',
    'transitional stored read' => 'public function decryptStored(',
] as $description => $snippet) {
    if (!is_string($secretCipherSource) || !str_contains($secretCipherSource, $snippet)) {
        $failures[] = "Missing Billing expand/contract cipher behavior: {$description}.";
    }
}
if (!is_string($secretMigrationCliSource)
    || !str_contains($secretMigrationCliSource, 'BILLING_SECRET_MIGRATION_RECEIPT')
    || !str_contains($secretMigrationCliSource, 'BILLING_SECRET_LEGACY_READ_ENABLED')) {
    $failures[] = 'Billing secret migration CLI lacks expand-phase and receipt gates.';
}
if (!is_string($secretGateCliSource)
    || !str_contains($secretGateCliSource, 'BILLING_SECRET_STORAGE_GATE')) {
    $failures[] = 'Billing secret storage gate lacks its canonical receipt.';
}
if (!is_string($secretKeyringCliSource)
    || !str_contains($secretKeyringCliSource, "'add-key'")
    || !str_contains($secretKeyringCliSource, "'activate-key'")
    || !str_contains($secretKeyringCliSource, '--attestation-file=')
    || str_contains($secretKeyringCliSource, "'rotate'")) {
    $failures[] = 'Billing keyring CLI does not enforce staged, attested rotation.';
}
if (!is_string($secretAdminConnectionSource)
    || !str_contains($secretAdminConnectionSource, 'rolbypassrls')
    || !str_contains($secretAdminConnectionSource, 'SET row_security = off')) {
    $failures[] = 'Billing migration administrator does not fail closed on cross-tenant RLS visibility.';
}
foreach (['certificate_password', 'mail_password', 'pmbillenc:v1:'] as $snippet) {
    if (!is_string($secretMigrationSource) || !str_contains($secretMigrationSource, $snippet)) {
        $failures[] = "Missing Billing V002 ciphertext contract: {$snippet}.";
    }
}
foreach ([
    'transactional row lock' => 'FOR UPDATE',
    'constraint validation' => 'VALIDATE CONSTRAINT',
    'idempotent rotation' => '->rotate(',
    'checksum receipt' => '002_enforce_billing_secret_ciphertexts.sql',
] as $description => $snippet) {
    if (!is_string($secretMigratorSource) || !str_contains($secretMigratorSource, $snippet)) {
        $failures[] = "Missing Billing secret migrator contract: {$description}.";
    }
}
foreach ([
    'checksum-pinned migration ledger' => 'billing_schema_migrations',
    'immutable V001 migration' => '001_create_billing_core.sql',
    'immutable V003 tax identity migration' => '003_add_invoice_detail_tax_identity.sql',
] as $description => $snippet) {
    if (!is_string($schemaSource) || !str_contains($schemaSource, $snippet)) {
        $failures[] = "Missing Billing schema contract: {$description}.";
    }
}
foreach ([
    'line-ordinal raw request backfill' => 'WITH ORDINALITY',
    'exact SRI percentage code persistence' => 'tax_percentage_code',
    'zero-rated/exempt treatment persistence' => 'tax_treatment',
    'supported IVA rate guard' => 'tax_rate NOT IN (0, 5, 12, 13, 14, 15)',
    'database SRI identity invariant' => 'invoice_details_sri_vat_identity_check',
] as $description => $snippet) {
    if (!is_string($taxIdentityMigrationSource) || !str_contains($taxIdentityMigrationSource, $snippet)) {
        $failures[] = "Missing Billing V003 tax identity contract: {$description}.";
    }
}
foreach ([
    'retry tenant column' => 'ADD COLUMN IF NOT EXISTS tenant_id text',
    'no customer tenant default' => 'ALTER COLUMN tenant_id DROP DEFAULT',
] as $description => $snippet) {
    if (!is_string($migrationSource) || !str_contains($migrationSource, $snippet)) {
        $failures[] = "Missing Billing V001 contract: {$description}.";
    }
}
foreach ([
    'configuration reads retry by tenant' => 'WHERE tenant_id = :tenant_id',
    'configuration writes retry by tenant' => 'ON CONFLICT (tenant_id, ambiente)',
    'configuration protects stored secrets' => '$this->secretCipher->prepareForStorage(',
    'configuration decrypts only for use' => '$this->decryptRowSecret(',
    'OpenSSL password uses an anonymous descriptor' => "'fd:3'",
    'OpenSSL password descriptor is parent-write/child-read' => "3 => ['pipe', 'r']",
    'secret inputs reject ambiguous control bytes' => '$this->assertSafeSecretInput(',
    'secret values preserve exact non-control bytes' => '$this->optionalSecretString(',
] as $description => $snippet) {
    if (!is_string($configurationSource) || !str_contains($configurationSource, $snippet)) {
        $failures[] = "Missing Billing configuration isolation: {$description}.";
    }
}
if (!is_string($configurationSource)
    || str_contains($configurationSource, 'PM_CERT_PASSWORD')
    || str_contains($configurationSource, 'env:PM_CERT_PASSWORD')) {
    $failures[] = 'Billing certificate parsing can expose its password through the child-process environment.';
}
if (!is_string($nativeGatewaySource)
    || str_contains($nativeGatewaySource, 'SRI_CERT_PASSWORD')
    || str_contains($nativeGatewaySource, 'CERT_PASSWORD')
    || str_contains($nativeGatewaySource, 'MAIL_PASSWORD')
    || str_contains($nativeGatewaySource, 'SMTP_PASS')
    || substr_count($nativeGatewaySource, "'password' => '',") < 2) {
    $failures[] = 'Billing base configuration can fall back to a cross-tenant certificate or SMTP password.';
}
if (!is_string($dockerComposeSource) || str_contains($dockerComposeSource, 'SRI_CERT_PASSWORD')) {
    $failures[] = 'Billing runtime still injects a global SRI certificate password.';
}
if (!is_string($apiKeySource)
    || !str_contains($apiKeySource, 'decryptBranchSecrets')
    || !str_contains($apiKeySource, 'decryptStored')) {
    $failures[] = 'API key repository does not decrypt Billing secrets at its use boundary.';
}
if (!is_string($invoiceSource)
    || !str_contains($invoiceSource, 'decryptCandidateSecrets')
    || !str_contains($invoiceSource, 'decryptStored')) {
    $failures[] = 'Billing recovery repository does not decrypt secrets at its use boundary.';
}
if (!is_string($billingControllerSource)
    || str_contains($billingControllerSource, 'Response::error($e->getMessage(), 500')
    || !str_contains($billingControllerSource, "'error_type' => \$exception::class")) {
    $failures[] = 'Billing admin controller can expose raw internal/secret-bearing exception details.';
}
if (!is_string($publicBillingControllerSource)
    || str_contains($publicBillingControllerSource, "error_log('[PUBLIC_BILLING")
    || !str_contains($publicBillingControllerSource, 'PUBLIC_BILLING_DATABASE_ERROR')) {
    $failures[] = 'Billing public controller can expose or log raw internal exception details.';
}
if (!is_string($apiKeySource)
    || substr_count($apiKeySource, 'retry.tenant_id = ak.tenant_id') !== 2) {
    $failures[] = 'API key retry joins are not tenant-scoped for both environments.';
}
if (!is_string($invoiceSource)
    || substr_count($invoiceSource, 'irs.tenant_id = ih.tenant_id') < 3) {
    $failures[] = 'Billing recovery retry joins are not tenant-scoped in every query.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

if (($argv[1] ?? '') === '--database-probe') {
    $legacyTenant = trim((string)($_ENV['BILLING_LEGACY_TENANT_ID'] ?? getenv('BILLING_LEGACY_TENANT_ID') ?: ''));
    if ($legacyTenant === '') {
        fwrite(STDERR, "BILLING_LEGACY_TENANT_ID is required only for this explicit rollback probe.\n");
        exit(1);
    }
    $runtimeConfig = [
        'host' => envValue('DB_HOST', 'db'),
        'port' => envValue('DB_PORT', '5432'),
        'database' => envValue('DB_DATABASE', 'ecommerce'),
        'username' => envValue('DB_USERNAME', 'postgres'),
        'password' => envValue('DB_PASSWORD', ''),
    ];
    $target = moduleTarget($runtimeConfig, BillingDomain::KEY);
    if (!is_array($target)) {
        fwrite(STDERR, "Billing database target is unavailable.\n");
        exit(1);
    }
    $pdo = connect(connectionTargetConfig($target, adminConnectionConfig($runtimeConfig)));
    try {
        $pdo->beginTransaction();
        if (!ensureBillingTenantIsolation($pdo)) {
            throw new RuntimeException('Billing tenant isolation reported unavailable tables.');
        }
        $tenantTables = $expectedOwnerTables;
        $placeholders = implode(',', array_fill(0, count($tenantTables), '?'));
        $columns = $pdo->prepare(
            "SELECT table_name, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND column_name = 'tenant_id'
               AND table_name IN ({$placeholders})"
        );
        $columns->execute($tenantTables);
        $columnState = [];
        foreach ($columns->fetchAll(PDO::FETCH_ASSOC) ?: [] as $column) {
            $columnState[(string)$column['table_name']] = (string)$column['is_nullable'];
        }
        foreach ($tenantTables as $table) {
            if (($columnState[$table] ?? null) !== 'NO') {
                throw new RuntimeException("Billing table {$table}.tenant_id is not NOT NULL after bootstrap.");
            }
            $missing = (int)$pdo->query(sprintf(
                "SELECT COUNT(*) FROM %s WHERE NULLIF(BTRIM(tenant_id), '') IS NULL",
                quoteIdent($table)
            ))->fetchColumn();
            if ($missing !== 0) {
                throw new RuntimeException("Billing table {$table} retains {$missing} unresolved tenants.");
            }
        }
        $requiredConstraints = [
            'client_branches_client_tenant_fk',
            'api_keys_client_tenant_fk',
            'invoice_headers_client_tenant_fk',
            'invoice_headers_branch_tenant_fk',
            'invoice_headers_billing_customer_tenant_fk',
            'invoice_details_header_tenant_fk',
            'branch_sequences_branch_tenant_fk',
            'billing_domain_events_invoice_tenant_fk',
            'billing_domain_events_client_tenant_fk',
            'billing_domain_events_branch_tenant_fk',
            'billing_domain_events_api_key_tenant_fk',
        ];
        $constraintPlaceholders = implode(',', array_fill(0, count($requiredConstraints), '?'));
        $constraints = $pdo->prepare(
            "SELECT COUNT(*)
             FROM pg_constraint
             WHERE convalidated
               AND conname IN ({$constraintPlaceholders})"
        );
        $constraints->execute($requiredConstraints);
        if ((int)$constraints->fetchColumn() !== count($requiredConstraints)) {
            throw new RuntimeException('Billing tenant foreign keys are incomplete or not validated.');
        }
        $pdo->rollBack();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'Billing rollback database probe failed: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, "Billing tenant bootstrap rollback probe: OK\n");
    exit(0);
}

fwrite(STDOUT, "Billing tenant bootstrap contract: OK\n");
