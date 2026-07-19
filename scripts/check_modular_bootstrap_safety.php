<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_module_databases.php';

$bootstrapSource = file_get_contents(__DIR__ . '/bootstrap_module_databases.php');
$schemaSource = file_get_contents(__DIR__ . '/bootstrap_schema.php');
$deploySource = file_get_contents(__DIR__ . '/common.sh');
$failures = [];

foreach ([
    'module transactions' => '$pdo->beginTransaction()',
    'transaction rollback' => '$pdo->rollBack()',
    'physical legacy source filter' => 'physicalLocalTables($primarySourcePdo, LEGACY_TABLES)',
    'copyable physical tables only' => 'array_intersect($tables, $physicalLegacyTables)',
    'migration receipts' => 'module_migration_receipts',
    'row reconciliation' => 'Legacy migration reconciliation failed',
    'tenant seed in identity owner' => 'syncConfiguredTenantRows($pdo, $tenantRows)',
    'transaction-local FDW secret scope' => '$transactionLocal = $pdo->inTransaction()',
] as $description => $snippet) {
    if (!is_string($bootstrapSource) || !str_contains($bootstrapSource, $snippet)) {
        $failures[] = "Missing modular bootstrap safety contract: {$description}.";
    }
}
if (is_string($bootstrapSource) && substr_count($bootstrapSource, '$pdo->beginTransaction()') < 2) {
    $failures[] = 'Both modular bootstrap phases must be transactional.';
}
foreach ([
    'standalone topology preflight' => 'assertLegacySchemaBootstrapTopology($pdo)',
    'preflight every target' => 'foreach ($targets as $targetKey => $target)',
    'standalone transaction' => '$pdo->beginTransaction()',
] as $description => $snippet) {
    if (!is_string($schemaSource) || !str_contains($schemaSource, $snippet)) {
        $failures[] = "Missing standalone bootstrap safety contract: {$description}.";
    }
}
if (!is_string($deploySource)
    || !str_contains($deploySource, 'php scripts/bootstrap_module_databases.php')
    || str_contains($deploySource, 'php scripts/bootstrap_schema.php')) {
    $failures[] = 'Integrated deploy must invoke only the modular bootstrap.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

function modularSchemaFingerprint(PDO $pdo): string
{
    $parts = $pdo->query(<<<'SQL'
        WITH catalog_parts AS (
            SELECT 'relation|' || n.nspname || '|' || c.relname || '|' || c.relkind::text AS value
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'f', 'S')
            UNION ALL
            SELECT 'column|' || table_schema || '|' || table_name || '|' || column_name || '|' || data_type || '|' || is_nullable
            FROM information_schema.columns WHERE table_schema = 'public'
            UNION ALL
            SELECT 'constraint|' || conrelid::regclass::text || '|' || conname || '|' || pg_get_constraintdef(oid, true)
            FROM pg_constraint WHERE connamespace = 'public'::regnamespace
            UNION ALL
            SELECT 'index|' || schemaname || '|' || tablename || '|' || indexname || '|' || indexdef
            FROM pg_indexes WHERE schemaname = 'public'
            UNION ALL
            SELECT 'server|' || srvname || '|' || pg_get_userbyid(srvowner)
            FROM pg_foreign_server
        )
        SELECT md5(COALESCE(string_agg(value, E'\n' ORDER BY value), '')) FROM catalog_parts
        SQL
    )->fetchColumn();

    return is_string($parts) ? $parts : '';
}

if (($argv[1] ?? '') === '--database-probe') {
    $runtimeConfig = [
        'host' => envValue('DB_HOST', 'db'),
        'port' => envValue('DB_PORT', '5432'),
        'database' => envValue('DB_DATABASE', 'ecommerce'),
        'username' => envValue('DB_USERNAME', 'postgres'),
        'password' => envValue('DB_PASSWORD', ''),
    ];
    $defaultTenant = (string)envValue('DEFAULT_TENANT', 'paramascotasec');
    $targets = moduleTargets($runtimeConfig);
    $groups = moduleTargetGroups($targets);
    $adminConfig = adminConnectionConfig($runtimeConfig);
    $databaseName = (string)$runtimeConfig['database'];
    $group = $groups[$databaseName] ?? null;
    if (!is_array($group)) {
        fwrite(STDERR, "Primary modular target group is unavailable.\n");
        exit(1);
    }
    $pdo = connect(connectionTargetConfig($group['target'], $adminConfig));
    $before = modularSchemaFingerprint($pdo);

    // Standalone must reject the active modular topology before mutation.
    try {
        assertLegacySchemaBootstrapTopology($pdo);
        fwrite(STDERR, "Standalone bootstrap accepted an FDW modular topology.\n");
        exit(1);
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'use scripts/bootstrap_module_databases.php')) {
            throw $exception;
        }
    }

    try {
        $pdo->beginTransaction();
        dropForeignLegacyTables($pdo);
        dropForeignOwnerTables($pdo, $group['tables']);
        executeSchemaBootstrap($pdo, $defaultTenant, ['skip_constraints' => MODULE_SKIPPED_CONSTRAINTS]);
        dropKnownCrossDomainConstraints($pdo);
        // Exercises DROP/CREATE SERVER, transaction-local secret GUCs, user
        // mappings and all compatibility imports against the real QA shape.
        replaceNonOwnedTablesWithForeignTables($pdo, $group['modules'], $targets);
        $pdo->rollBack();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'Modular bootstrap rollback probe failed: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
    $after = modularSchemaFingerprint($pdo);
    if (!hash_equals($before, $after)) {
        fwrite(STDERR, "Modular bootstrap rollback changed the QA schema fingerprint.\n");
        exit(1);
    }
    fwrite(STDOUT, "Modular bootstrap real rollback probe: OK\n");
    exit(0);
}

fwrite(STDOUT, "Modular bootstrap safety contract: OK\n");
