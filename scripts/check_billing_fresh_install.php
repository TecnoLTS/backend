<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_module_databases.php';

use BillingService\Billing\Infrastructure\Persistence\BillingConfigurationRepository;
use BillingService\Billing\Infrastructure\Security\BillingSecretCipher;
use BillingService\Billing\Infrastructure\Security\BillingSecretCipherFactory;
use BillingService\Billing\Infrastructure\Security\FileKeyringDataKeyWrapper;

$runtimeConfig = [
    'host' => envValue('DB_HOST', 'db'),
    'port' => envValue('DB_PORT', '5432'),
    'database' => envValue('DB_DATABASE', 'ecommerce'),
    'username' => envValue('DB_USERNAME', 'postgres'),
    'password' => envValue('DB_PASSWORD', ''),
];
$adminConfig = adminConnectionConfig($runtimeConfig);
$server = connect(normalizeConfig($adminConfig, ['database' => 'postgres']));
$probeDatabase = sprintf('billing_fresh_probe_%d_%s', getmypid(), bin2hex(random_bytes(4)));
$probe = null;
$exitCode = 0;
$keyringPath = tempnam(sys_get_temp_dir(), 'billing-keyring-probe-');
if (!is_string($keyringPath)) {
    throw new RuntimeException('Could not allocate Billing keyring probe file.');
}
chmod($keyringPath, 0600);
file_put_contents($keyringPath, json_encode([
    'version' => 1,
    'active_key_id' => 'fresh-install-v1',
    'keys' => ['fresh-install-v1' => base64_encode(random_bytes(32))],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
$_ENV['BILLING_SECRET_KEYRING_FILE'] = $keyringPath;
putenv('BILLING_SECRET_KEYRING_FILE=' . $keyringPath);
BillingSecretCipherFactory::resetForTests();
$secretCipher = new BillingSecretCipher(FileKeyringDataKeyWrapper::fromFile($keyringPath));

try {
    $server->exec('CREATE DATABASE ' . quoteIdent($probeDatabase));
    $probe = connect(normalizeConfig($adminConfig, ['database' => $probeDatabase]));

    // A truly empty installation must not depend on a legacy tenant fallback.
    $_ENV['BILLING_LEGACY_TENANT_ID'] = '';
    putenv('BILLING_LEGACY_TENANT_ID=');
    createBillingTables($probe);
    if (!ensureBillingTenantIsolation($probe)) {
        throw new RuntimeException('Fresh Billing isolation reported missing owner tables.');
    }
    // Legacy plaintext exists only before the deferred V002 transaction. The
    // probe proves conversion, ciphertext-only storage and atomic validation.
    $legacyClient = $probe->query(
        "INSERT INTO clients (tenant_id, ruc, business_name, address)
         VALUES ('billing-legacy-probe', '1799999999001', 'Legacy probe', 'Quito')
         RETURNING id"
    );
    $legacyClientId = (int)$legacyClient->fetchColumn();
    $legacyCertificateSecret = 'legacy-certificate-probe-value';
    $legacyMailSecret = 'legacy-mail-probe-value';
    $legacyInsert = $probe->prepare(
        "INSERT INTO client_branches (
            tenant_id, client_id, code, emission_point, address,
            certificate_path, certificate_password, mail_password, is_default
         ) VALUES (
            'billing-legacy-probe', :client_id, '099', '001', 'Quito',
            '', :certificate_password, :mail_password, true
         ) RETURNING id"
    );
    $legacyInsert->execute([
        'client_id' => $legacyClientId,
        'certificate_password' => $legacyCertificateSecret,
        'mail_password' => $legacyMailSecret,
    ]);
    $legacyBranchId = (int)$legacyInsert->fetchColumn();
    $firstSecretMigration = migrateBillingSecrets($probe);
    if ($firstSecretMigration['migrated'] !== 2) {
        throw new RuntimeException('Billing V002 did not convert both legacy plaintext values.');
    }
    $legacyEncrypted = $probe->query(
        "SELECT certificate_password, mail_password
         FROM client_branches
         WHERE id = {$legacyBranchId}"
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($legacyEncrypted)
        || !str_starts_with((string)$legacyEncrypted['certificate_password'], BillingSecretCipher::PREFIX)
        || !str_starts_with((string)$legacyEncrypted['mail_password'], BillingSecretCipher::PREFIX)
        || str_contains((string)$legacyEncrypted['certificate_password'], $legacyCertificateSecret)
        || str_contains((string)$legacyEncrypted['mail_password'], $legacyMailSecret)
        || $secretCipher->decrypt(
            (string)$legacyEncrypted['certificate_password'],
            'billing-legacy-probe',
            $legacyBranchId,
            'certificate_password'
        ) !== $legacyCertificateSecret
        || $secretCipher->decrypt(
            (string)$legacyEncrypted['mail_password'],
            'billing-legacy-probe',
            $legacyBranchId,
            'mail_password'
        ) !== $legacyMailSecret
    ) {
        throw new RuntimeException('Billing V002 ciphertext postcondition failed.');
    }
    // Both the migration runner and the tenant upgrade are repeatable.
    createBillingTables($probe);
    if (!ensureBillingTenantIsolation($probe)) {
        throw new RuntimeException('Second fresh Billing isolation run failed.');
    }
    $secondSecretMigration = migrateBillingSecrets($probe);
    if ($secondSecretMigration['migrated'] !== 0 || $secondSecretMigration['rotated'] !== 0) {
        throw new RuntimeException('Billing V002 rerun was not idempotent.');
    }
    $validatedSecretConstraints = (int)$probe->query(
        "SELECT COUNT(*)
         FROM pg_constraint
         WHERE conrelid = 'public.client_branches'::regclass
           AND conname IN (
               'client_branches_certificate_password_ciphertext_check',
               'client_branches_mail_password_ciphertext_check'
           )
           AND convalidated = TRUE"
    )->fetchColumn();
    if ($validatedSecretConstraints !== 2) {
        throw new RuntimeException('Billing ciphertext constraints are not validated.');
    }
    $plaintextWriteRejected = false;
    try {
        $rejectedWrite = $probe->prepare(
            'UPDATE client_branches SET mail_password = :plaintext WHERE id = :branch_id'
        );
        $rejectedWrite->execute([
            'plaintext' => 'must-not-be-persisted',
            'branch_id' => $legacyBranchId,
        ]);
    } catch (PDOException) {
        $plaintextWriteRejected = true;
    }
    if (!$plaintextWriteRejected) {
        throw new RuntimeException('Billing DB accepted a plaintext secret after V002 validation.');
    }

    $ownerTables = MODULE_TABLES[\App\Modules\Billing\Domain\BillingDomain::KEY];
    $tableNames = array_merge($ownerTables, ['billing_schema_migrations']);
    $tablePlaceholders = implode(',', array_fill(0, count($tableNames), '?'));
    $tables = $probe->prepare(
        "SELECT COUNT(*)
         FROM pg_class relation
         JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
         WHERE namespace.nspname = 'public'
           AND relation.relkind IN ('r', 'p')
           AND relation.relname IN ({$tablePlaceholders})"
    );
    $tables->execute($tableNames);
    if ((int)$tables->fetchColumn() !== count($tableNames)) {
        throw new RuntimeException('Fresh Billing owner/ledger tables are incomplete.');
    }

    $tenantPlaceholders = implode(',', array_fill(0, count($ownerTables), '?'));
    $tenantColumns = $probe->prepare(
        "SELECT table_name, is_nullable, column_default
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND column_name = 'tenant_id'
           AND table_name IN ({$tenantPlaceholders})"
    );
    $tenantColumns->execute($ownerTables);
    $tenantState = [];
    foreach ($tenantColumns->fetchAll(PDO::FETCH_ASSOC) ?: [] as $column) {
        $tenantState[(string)$column['table_name']] = $column;
    }
    foreach ($ownerTables as $table) {
        if (($tenantState[$table]['is_nullable'] ?? null) !== 'NO') {
            throw new RuntimeException("Fresh Billing {$table}.tenant_id is not NOT NULL.");
        }
    }
    if (($tenantState['billing_customers']['column_default'] ?? null) !== null) {
        throw new RuntimeException('billing_customers.tenant_id retains a hardcoded default.');
    }

    $retryIndex = $probe->query(
        "SELECT indexdef FROM pg_indexes
         WHERE schemaname = 'public'
           AND indexname = 'invoice_retry_settings_tenant_environment_uidx'"
    )->fetchColumn();
    if (!is_string($retryIndex)
        || !str_contains($retryIndex, 'UNIQUE INDEX')
        || !preg_match('/\(tenant_id, ambiente\)$/', $retryIndex)) {
        throw new RuntimeException('Retry settings lack exact tenant/environment uniqueness.');
    }
    $legacyRetryUnique = (int)$probe->query(
        "SELECT COUNT(*) FROM pg_constraint
         WHERE conrelid = 'public.invoice_retry_settings'::regclass
           AND conname = 'invoice_retry_settings_ambiente_key'"
    )->fetchColumn();
    if ($legacyRetryUnique !== 0) {
        throw new RuntimeException('Global retry UNIQUE(ambiente) survived tenant migration.');
    }

    $sequenceDefaults = (int)$probe->query(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name IN (
               'clients', 'client_branches', 'billing_customers', 'api_keys',
               'invoice_retry_settings', 'invoice_headers', 'invoice_details'
           )
           AND column_name = 'id'
           AND column_default LIKE 'nextval(%'"
    )->fetchColumn();
    if ($sequenceDefaults !== 7) {
        throw new RuntimeException("Fresh Billing serial defaults are incomplete: {$sequenceDefaults}/7.");
    }
    $invoiceTaxIdentityColumns = (int)$probe->query(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'invoice_details'
           AND column_name IN ('tax_code', 'tax_percentage_code', 'tax_treatment')
           AND is_nullable = 'NO'"
    )->fetchColumn();
    if ($invoiceTaxIdentityColumns !== 3) {
        throw new RuntimeException('Fresh Billing invoice tax identity columns are incomplete.');
    }
    $invoiceTaxIdentityConstraints = (int)$probe->query(
        "SELECT COUNT(*)
         FROM pg_constraint
         WHERE conrelid = 'public.invoice_details'::regclass
           AND conname IN (
               'invoice_details_tax_code_iva_check',
               'invoice_details_tax_treatment_check',
               'invoice_details_sri_vat_identity_check'
           )"
    )->fetchColumn();
    if ($invoiceTaxIdentityConstraints !== 3) {
        throw new RuntimeException('Fresh Billing invoice tax identity constraints are incomplete.');
    }
    $taxProbeHeader = $probe->prepare(
        "INSERT INTO invoice_headers (
            tenant_id, client_id, branch_id, access_key, issue_date,
            customer_name, customer_identification,
            subtotal_without_tax, total_tax, total_with_tax,
            establishment_code, emission_point, sequential, ambiente, sri_status,
            raw_request
         ) VALUES (
            'billing-legacy-probe', :client_id, :branch_id, :access_key, CURRENT_DATE,
            'Cliente fiscal mixto', '1702527887',
            175.00, 15.00, 190.00,
            '099', '001', '000000001', 'pruebas', 'PENDING',
            CAST(:raw_request AS jsonb)
         ) RETURNING id"
    );
    $taxProbeHeader->execute([
        'client_id' => $legacyClientId,
        'branch_id' => $legacyBranchId,
        'access_key' => str_repeat('1', 49),
        'raw_request' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
    ]);
    $taxProbeHeaderId = (int)$taxProbeHeader->fetchColumn();
    $taxProbeDetail = $probe->prepare(
        "INSERT INTO invoice_details (
            tenant_id, invoice_header_id, line_number, description,
            quantity, unit_price, discount, line_subtotal, tax_amount, tax_rate,
            tax_code, tax_percentage_code, tax_treatment
         ) VALUES (
            'billing-legacy-probe', :invoice_header_id, :line_number, :description,
            1, :unit_price, 0, :line_subtotal, :tax_amount, :tax_rate,
            '2', :percentage_code, :treatment
         )"
    );
    foreach ([
        [1, 'Gravado IVA 15%', 100, 100, 15, 15, '4', 'taxed'],
        [2, 'Tarifa IVA 0%', 50, 50, 0, 0, '0', 'zero-rated'],
        [3, 'Exento IVA', 25, 25, 0, 0, '7', 'exempt'],
    ] as [$line, $description, $unitPrice, $lineSubtotal, $taxAmount, $taxRate, $percentageCode, $treatment]) {
        $taxProbeDetail->execute([
            'invoice_header_id' => $taxProbeHeaderId,
            'line_number' => $line,
            'description' => $description,
            'unit_price' => $unitPrice,
            'line_subtotal' => $lineSubtotal,
            'tax_amount' => $taxAmount,
            'tax_rate' => $taxRate,
            'percentage_code' => $percentageCode,
            'treatment' => $treatment,
        ]);
    }
    $persistedTaxIdentity = $probe->query(
        "SELECT string_agg(tax_treatment || ':' || tax_percentage_code, ',' ORDER BY line_number)
         FROM invoice_details
         WHERE invoice_header_id = {$taxProbeHeaderId}"
    )->fetchColumn();
    if ($persistedTaxIdentity !== 'taxed:4,zero-rated:0,exempt:7') {
        throw new RuntimeException('Fresh Billing collapsed mixed IVA line identities.');
    }
    $incoherentTaxWriteRejected = false;
    try {
        $taxProbeDetail->execute([
            'invoice_header_id' => $taxProbeHeaderId,
            'line_number' => 4,
            'description' => 'Exento con codigo incoherente',
            'unit_price' => 1,
            'line_subtotal' => 1,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'percentage_code' => '0',
            'treatment' => 'exempt',
        ]);
    } catch (PDOException) {
        $incoherentTaxWriteRejected = true;
    }
    if (!$incoherentTaxWriteRejected) {
        throw new RuntimeException('Fresh Billing accepted an incoherent exempt/code-0 detail.');
    }
    $triggers = (int)$probe->query(
        "SELECT COUNT(*) FROM pg_trigger
         WHERE NOT tgisinternal
           AND tgname IN (
               'trg_clients_updated_at', 'trg_client_branches_updated_at',
               'trg_invoice_headers_updated_at', 'trg_invoice_retry_settings_updated_at'
           )"
    )->fetchColumn();
    if ($triggers !== 4) {
        throw new RuntimeException("Fresh Billing updated_at triggers are incomplete: {$triggers}/4.");
    }
    $migrationReceipt = (int)$probe->query(
        "SELECT COUNT(*) FROM billing_schema_migrations
         WHERE version IN (
             '001_create_billing_core.sql',
             '002_enforce_billing_secret_ciphertexts.sql',
             '003_add_invoice_detail_tax_identity.sql'
         )
           AND checksum_sha256 ~ '^[0-9a-f]{64}$'"
    )->fetchColumn();
    if ($migrationReceipt !== 3) {
        throw new RuntimeException('Billing checksum-pinned migration receipts are incomplete.');
    }

    // Multi-tenant negative test: updating tenant A retry settings must leave
    // tenant B untouched, and reads must return the caller's own policy.
    $fixtures = [];
    foreach ([
        ['tenant' => 'billing-probe-a', 'ruc' => '1790000000001', 'code' => '001'],
        ['tenant' => 'billing-probe-b', 'ruc' => '1790000000002', 'code' => '002'],
    ] as $fixture) {
        $client = $probe->prepare(
            'INSERT INTO clients (tenant_id, ruc, business_name, address)
             VALUES (:tenant_id, :ruc, :business_name, :address)
             RETURNING id'
        );
        $client->execute([
            'tenant_id' => $fixture['tenant'],
            'ruc' => $fixture['ruc'],
            'business_name' => 'Billing probe ' . $fixture['tenant'],
            'address' => 'Quito',
        ]);
        $clientId = (int)$client->fetchColumn();
        $branchId = (int)$probe->query(
            "SELECT nextval(pg_get_serial_sequence('client_branches', 'id'))"
        )->fetchColumn();
        $certificateSecret = 'certificate-' . $fixture['tenant'];
        $mailSecret = 'mail-' . $fixture['tenant'];
        $branch = $probe->prepare(
            'INSERT INTO client_branches (
                id, tenant_id, client_id, code, emission_point, branch_name,
                address, certificate_path, certificate_password, mail_password, is_default
             ) VALUES (
                :branch_id, :tenant_id, :client_id, :code, :emission_point, :branch_name,
                :address, :certificate_path, :certificate_password, :mail_password, true
             ) RETURNING id'
        );
        $branch->execute([
            'branch_id' => $branchId,
            'tenant_id' => $fixture['tenant'],
            'client_id' => $clientId,
            'code' => $fixture['code'],
            'emission_point' => '001',
            'branch_name' => 'Probe',
            'address' => 'Quito',
            'certificate_path' => '',
            'certificate_password' => $secretCipher->encrypt(
                $certificateSecret,
                $fixture['tenant'],
                $branchId,
                'certificate_password'
            ),
            'mail_password' => $secretCipher->encrypt(
                $mailSecret,
                $fixture['tenant'],
                $branchId,
                'mail_password'
            ),
        ]);
        $fixtures[$fixture['tenant']] = [
            'tenant_id' => $fixture['tenant'],
            'client_id' => $clientId,
            'resolved_branch_id' => (int)$branch->fetchColumn(),
            'api_key_id' => 0,
            'certificate_secret' => $certificateSecret,
            'mail_secret' => $mailSecret,
        ];
    }
    $retryInsert = $probe->prepare(
        'INSERT INTO invoice_retry_settings (
            tenant_id, ambiente, max_retry_days, max_attempts, delay_seconds, is_active
         ) VALUES (:tenant_id, :ambiente, 5, :max_attempts, 3600, true)'
    );
    foreach ([
        ['tenant' => 'billing-probe-a', 'attempts' => 3],
        ['tenant' => 'billing-probe-b', 'attempts' => 6],
    ] as $setting) {
        foreach (['pruebas', 'produccion'] as $environment) {
            $retryInsert->execute([
                'tenant_id' => $setting['tenant'],
                'ambiente' => $environment,
                'max_attempts' => $setting['attempts'],
            ]);
        }
    }

    $repository = new BillingConfigurationRepository($probe, $secretCipher);
    $beforeA = $repository->getConfiguration($fixtures['billing-probe-a'], []);
    $beforeB = $repository->getConfiguration($fixtures['billing-probe-b'], []);
    if (($beforeA['retries']['test']['max_attempts'] ?? null) !== 3
        || ($beforeB['retries']['test']['max_attempts'] ?? null) !== 6) {
        throw new RuntimeException('Retry settings read crossed tenant boundaries.');
    }
    $serializedResponses = json_encode([$beforeA, $beforeB], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    foreach ($fixtures as $fixtureContext) {
        if (str_contains($serializedResponses, (string)$fixtureContext['certificate_secret'])
            || str_contains($serializedResponses, (string)$fixtureContext['mail_secret'])
            || str_contains($serializedResponses, BillingSecretCipher::PREFIX)
        ) {
            throw new RuntimeException('Billing configuration response exposed secret material.');
        }
    }
    $repository->updateConfiguration(
        $fixtures['billing-probe-a'],
        ['retries' => ['test' => ['max_attempts' => 9]]],
        []
    );
    $afterB = $repository->getConfiguration($fixtures['billing-probe-b'], []);
    if (($afterB['retries']['test']['max_attempts'] ?? null) !== 6) {
        throw new RuntimeException('Tenant A retry update modified tenant B.');
    }
    $tenantAAttempts = $probe->query(
        "SELECT max_attempts FROM invoice_retry_settings
         WHERE tenant_id = 'billing-probe-a' AND ambiente = 'pruebas'"
    )->fetchColumn();
    if ((int)$tenantAAttempts !== 9) {
        throw new RuntimeException('Tenant A retry update was not persisted in its own scope.');
    }

    fwrite(STDOUT, "Billing fresh-install + tenant retry isolation: OK\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Billing fresh-install probe failed: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    $probe = null;
    $terminate = $server->prepare(
        'SELECT pg_terminate_backend(pid) FROM pg_stat_activity
         WHERE datname = :database_name AND pid <> pg_backend_pid()'
    );
    $terminate->execute(['database_name' => $probeDatabase]);
    $server->exec('DROP DATABASE IF EXISTS ' . quoteIdent($probeDatabase));
    @unlink($keyringPath);
    putenv('BILLING_SECRET_KEYRING_FILE');
    unset($_ENV['BILLING_SECRET_KEYRING_FILE']);
    BillingSecretCipherFactory::resetForTests();
}

exit($exitCode);
