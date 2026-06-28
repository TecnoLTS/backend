<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_schema.php';

const MODULE_TABLES = [
    'identity-platform' => [
        'Tenant',
        'User',
        'tenant_module_entitlements',
        'tenant_memberships',
        'tenant_roles',
        'tenant_user_roles',
        'AuthSecurityEvent',
        'PasswordResetToken',
        'Setting',
    ],
    'catalog-inventory' => [
        'Product',
        'Image',
        'Variation',
        'PurchaseInvoice',
        'PurchaseInvoiceItem',
        'InventoryLot',
        'InventoryLotAllocation',
        'ProductReferenceCatalog',
        'ProductReview',
    ],
    'commerce' => [
        'Order',
        'OrderItem',
        'Quotation',
        'DiscountCode',
        'DiscountAudit',
        'PosShift',
        'PosMovement',
    ],
    'reporting-finance' => [
        'FinancialPeriod',
        'FinancialAdjustment',
        'BusinessExpenseRecurrence',
        'BusinessExpense',
        'BusinessExpensePayment',
    ],
    'mailer-service' => [
        'ContactMessage',
        'EmailOutbox',
        'EmailDeliveryLog',
    ],
];

const LEGACY_TABLES = [
    'Tenant',
    'User',
    'tenant_module_entitlements',
    'tenant_memberships',
    'tenant_roles',
    'tenant_user_roles',
    'AuthSecurityEvent',
    'PasswordResetToken',
    'Setting',
    'Product',
    'Image',
    'Variation',
    'PurchaseInvoice',
    'PurchaseInvoiceItem',
    'InventoryLot',
    'InventoryLotAllocation',
    'ProductReferenceCatalog',
    'ProductReview',
    'Order',
    'OrderItem',
    'Quotation',
    'DiscountCode',
    'DiscountAudit',
    'PosShift',
    'PosMovement',
    'FinancialPeriod',
    'FinancialAdjustment',
    'BusinessExpenseRecurrence',
    'BusinessExpense',
    'BusinessExpensePayment',
    'ContactMessage',
];

const MODULE_SKIPPED_CONSTRAINTS = [
    // Cross-domain link: Inventory keeps order_item_id as a stable Commerce ID, not a physical FK.
    'InventoryLotAllocation_order_item_id_fkey',
];

function quoteIdent(string $identifier): string {
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function envOrDefault(string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || trim((string)$value) === '') {
        return $default;
    }

    return trim((string)$value);
}

function moduleEnvSuffix(string $moduleKey): string {
    return strtoupper(str_replace('-', '_', $moduleKey));
}

function moduleTargets(array $baseConfig): array {
    $registryPath = __DIR__ . '/../config/module-databases.php';
    $registry = is_readable($registryPath) ? require $registryPath : [];
    $targets = [];

    foreach ($registry as $moduleKey => $entry) {
        if (!is_array($entry) || !isset(MODULE_TABLES[$moduleKey])) {
            continue;
        }

        $suffix = moduleEnvSuffix((string)$moduleKey);
        $targets[(string)$moduleKey] = normalizeConfig($baseConfig, [
            'host' => envOrDefault("DB_HOST_{$suffix}", (string)($entry['host'] ?? $baseConfig['host'])),
            'port' => envOrDefault("DB_PORT_{$suffix}", (string)($entry['port'] ?? $baseConfig['port'])),
            'database' => envOrDefault("DB_DATABASE_{$suffix}", (string)($entry['database'] ?? $entry['target_database'] ?? $baseConfig['database'])),
            'username' => envOrDefault("DB_USERNAME_{$suffix}", (string)($entry['username'] ?? $baseConfig['username'])),
            'password' => envOrDefault("DB_PASSWORD_{$suffix}", (string)($entry['password'] ?? $baseConfig['password'])),
        ]);
    }

    return $targets;
}

function adminConnectionConfig(array $runtimeConfig): array {
    return normalizeConfig($runtimeConfig, [
        'username' => envValue('DB_ADMIN_USERNAME', envValue('POSTGRES_USER', (string)$runtimeConfig['username'])),
        'password' => envValue('DB_ADMIN_PASSWORD', envValue('POSTGRES_PASSWORD', (string)$runtimeConfig['password'])),
    ]);
}

function connectionTargetConfig(array $targetConfig, array $adminConfig): array {
    return normalizeConfig($targetConfig, [
        'username' => (string)$adminConfig['username'],
        'password' => (string)$adminConfig['password'],
    ]);
}

function tableOwnerMap(): array {
    $owners = [];
    foreach (MODULE_TABLES as $moduleKey => $tables) {
        foreach ($tables as $table) {
            $owners[$table] = $moduleKey;
        }
    }

    return $owners;
}

function createMailerTables(PDO $pdo): void {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS "EmailOutbox" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            recipient_email text NOT NULL,
            subject text NOT NULL,
            body text NOT NULL,
            status text NOT NULL DEFAULT \'pending\',
            attempts integer NOT NULL DEFAULT 0,
            last_error text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            sent_at timestamp without time zone
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS "EmailDeliveryLog" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            outbox_id text,
            recipient_email text NOT NULL,
            status text NOT NULL,
            provider_message_id text,
            error_message text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "EmailOutbox_tenant_status_idx" ON "EmailOutbox" (tenant_id, status, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "EmailDeliveryLog_tenant_created_idx" ON "EmailDeliveryLog" (tenant_id, created_at DESC)');
}

function dropRemoteSchema(PDO $pdo, string $schema): void {
    $pdo->exec('DROP SCHEMA IF EXISTS ' . quoteIdent($schema) . ' CASCADE');
    $pdo->exec('CREATE SCHEMA ' . quoteIdent($schema));
}

function createFdwServer(PDO $pdo, string $serverName, array $targetConfig): void {
    $quotedServer = quoteIdent($serverName);
    try {
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS postgres_fdw');
    } catch (PDOException $e) {
        $exists = $pdo->query("SELECT 1 FROM pg_extension WHERE extname = 'postgres_fdw'")->fetchColumn();
        if ($exists !== 1 && $exists !== '1') {
            throw $e;
        }
    }
    $pdo->exec('DROP SERVER IF EXISTS ' . $quotedServer . ' CASCADE');
    $pdo->exec(sprintf(
        'CREATE SERVER %s FOREIGN DATA WRAPPER postgres_fdw OPTIONS (host %s, port %s, dbname %s)',
        $quotedServer,
        $pdo->quote((string)$targetConfig['host']),
        $pdo->quote((string)$targetConfig['port']),
        $pdo->quote((string)$targetConfig['database'])
    ));
    $pdo->exec(sprintf(
        'CREATE USER MAPPING FOR CURRENT_USER SERVER %s OPTIONS (user %s, password %s)',
        $quotedServer,
        $pdo->quote((string)$targetConfig['username']),
        $pdo->quote((string)$targetConfig['password'])
    ));

    $runtimeRole = trim((string)($targetConfig['username'] ?? ''));
    if ($runtimeRole !== '') {
        grantFdwServerAccessForRole($pdo, $serverName, $runtimeRole, $targetConfig);
    }
}

function grantFdwServerAccessForRole(PDO $pdo, string $serverName, string $roleName, array $targetConfig): void {
    $roleExists = $pdo
        ->query('SELECT to_regrole(' . $pdo->quote($roleName) . ') IS NOT NULL')
        ->fetchColumn();
    if (!$roleExists) {
        return;
    }

    $quotedServer = quoteIdent($serverName);
    $quotedRole = quoteIdent($roleName);
    $pdo->exec(sprintf('GRANT USAGE ON FOREIGN SERVER %s TO %s', $quotedServer, $quotedRole));
    $pdo->exec(sprintf(
        'DROP USER MAPPING IF EXISTS FOR %s SERVER %s',
        $quotedRole,
        $quotedServer
    ));
    $pdo->exec(sprintf(
        'CREATE USER MAPPING FOR %s SERVER %s OPTIONS (user %s, password %s)',
        $quotedRole,
        $quotedServer,
        $pdo->quote((string)$targetConfig['username']),
        $pdo->quote((string)$targetConfig['password'])
    ));
}

function importForeignTables(PDO $pdo, string $serverName, string $schema, array $tables): void {
    if ($tables === []) {
        return;
    }

    $tableList = implode(', ', array_map(static fn(string $table): string => quoteIdent($table), $tables));
    $pdo->exec(sprintf(
        'IMPORT FOREIGN SCHEMA public LIMIT TO (%s) FROM SERVER %s INTO %s',
        $tableList,
        quoteIdent($serverName),
        quoteIdent($schema)
    ));
}

function copyOwnedTablesFromLegacy(PDO $pdo, array $ownerTables): void {
    foreach ($ownerTables as $table) {
        if (!in_array($table, LEGACY_TABLES, true)) {
            continue;
        }

        ensureLocalOwnerTable($pdo, $table);
        $columns = commonColumns($pdo, $table);
        if ($columns === []) {
            continue;
        }
        $columnList = implode(', ', array_map(static fn(string $column): string => quoteIdent($column), $columns));
        $pdo->exec(sprintf(
            'INSERT INTO public.%1$s (%2$s) SELECT %2$s FROM legacy_source.%1$s ON CONFLICT DO NOTHING',
            quoteIdent($table),
            $columnList
        ));
        refreshSerialSequence($pdo, $table);
    }
}

function dropForeignOwnerTables(PDO $pdo, array $ownerTables): void {
    foreach ($ownerTables as $table) {
        if (relationKind($pdo, $table) === 'f') {
            $pdo->exec('DROP FOREIGN TABLE IF EXISTS public.' . quoteIdent($table) . ' CASCADE');
        }
    }
}

function dropForeignLegacyTables(PDO $pdo): void {
    foreach (LEGACY_TABLES as $table) {
        if (relationKind($pdo, $table) === 'f') {
            $pdo->exec('DROP FOREIGN TABLE IF EXISTS public.' . quoteIdent($table) . ' CASCADE');
        }
    }
}

function relationKind(PDO $pdo, string $table): ?string {
    $stmt = $pdo->prepare("
        SELECT c.relkind::text
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND c.relname = :table
        LIMIT 1
    ");
    $stmt->execute(['table' => $table]);
    $kind = $stmt->fetchColumn();

    return is_string($kind) && $kind !== '' ? $kind : null;
}

function ensureLocalOwnerTable(PDO $pdo, string $table): void {
    $kind = relationKind($pdo, $table);
    if ($kind === 'r' || $kind === 'p') {
        return;
    }

    if ($kind !== null) {
        dropLocalTableOrForeignTable($pdo, $table);
    }

    $pdo->exec(sprintf(
        'CREATE TABLE public.%1$s AS SELECT * FROM legacy_source.%1$s WITH NO DATA',
        quoteIdent($table)
    ));
}

function commonColumns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = :schema
          AND table_name = :table
        ORDER BY ordinal_position
    ");
    $stmt->execute(['schema' => 'public', 'table' => $table]);
    $target = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $stmt->execute(['schema' => 'legacy_source', 'table' => $table]);
    $source = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

    return array_values(array_filter($target, static fn(string $column): bool => isset($source[$column])));
}

function refreshSerialSequence(PDO $pdo, string $table): void {
    $columnCheck = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table
          AND column_name = 'id'
        LIMIT 1
    ");
    $columnCheck->execute(['table' => $table]);
    if (!$columnCheck->fetchColumn()) {
        return;
    }

    $stmt = $pdo->prepare('SELECT pg_get_serial_sequence(:table_name, :column_name)');
    $stmt->execute([
        'table_name' => 'public.' . quoteIdent($table),
        'column_name' => 'id',
    ]);
    $sequence = $stmt->fetchColumn();
    if (!is_string($sequence) || trim($sequence) === '') {
        return;
    }

    $pdo->exec(sprintf(
        'SELECT setval(%s, COALESCE((SELECT MAX(id) FROM public.%s), 1), true)',
        $pdo->quote($sequence),
        quoteIdent($table)
    ));
}

function dropKnownCrossDomainConstraints(PDO $pdo): void {
    $pdo->exec('ALTER TABLE IF EXISTS "InventoryLotAllocation" DROP CONSTRAINT IF EXISTS "InventoryLotAllocation_order_item_id_fkey"');
}

function dropLocalTableOrForeignTable(PDO $pdo, string $table): void {
    $qualified = 'public.' . quoteIdent($table);
    $stmt = $pdo->prepare("
        SELECT c.relkind
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public'
          AND c.relname = :table
        LIMIT 1
    ");
    $stmt->execute(['table' => $table]);
    $kind = $stmt->fetchColumn();
    if ($kind === 'f') {
        $pdo->exec('DROP FOREIGN TABLE IF EXISTS ' . $qualified . ' CASCADE');
        return;
    }

    $pdo->exec('DROP TABLE IF EXISTS ' . $qualified . ' CASCADE');
}

function replaceNonOwnedTablesWithForeignTables(PDO $pdo, string $moduleKey, array $targets): void {
    $owners = tableOwnerMap();
    $localTables = MODULE_TABLES[$moduleKey] ?? [];
    $remoteByModule = [];

    foreach (LEGACY_TABLES as $table) {
        if (in_array($table, $localTables, true)) {
            continue;
        }
        $owner = $owners[$table] ?? null;
        if (!is_string($owner) || $owner === $moduleKey || !isset($targets[$owner])) {
            continue;
        }
        $remoteByModule[$owner][] = $table;
        dropLocalTableOrForeignTable($pdo, $table);
    }

    foreach ($remoteByModule as $ownerModule => $tables) {
        $server = 'fdw_' . str_replace('-', '_', $ownerModule);
        createFdwServer($pdo, $server, $targets[$ownerModule]);
        importForeignTables($pdo, $server, 'public', $tables);
    }
}

function runModuleDatabaseBootstrap(): int {
    $runtimeConfig = [
        'host' => envValue('DB_HOST', 'db'),
        'port' => envValue('DB_PORT', '5432'),
        'database' => envValue('DB_DATABASE', 'paramascotasec'),
        'username' => envValue('DB_USERNAME', 'postgres'),
        'password' => envValue('DB_PASSWORD', 'postgres'),
    ];
    $defaultTenant = envValue('DEFAULT_TENANT', 'paramascotasec') ?? 'paramascotasec';
    $targets = moduleTargets($runtimeConfig);
    $primaryRuntimeConfig = normalizeConfig($runtimeConfig);
    $adminConfig = adminConnectionConfig($runtimeConfig);

    if ($targets === []) {
        fwrite(STDOUT, "[module-db] no module targets configured\n");
        return 0;
    }

    try {
        foreach ($targets as $moduleKey => $target) {
            $pdo = connect(connectionTargetConfig($target, $adminConfig));
            dropForeignLegacyTables($pdo);
            dropForeignOwnerTables($pdo, MODULE_TABLES[$moduleKey]);
            executeSchemaBootstrap($pdo, $defaultTenant, ['skip_constraints' => MODULE_SKIPPED_CONSTRAINTS]);
            if ($moduleKey === 'mailer-service') {
                createMailerTables($pdo);
            }
            dropKnownCrossDomainConstraints($pdo);
            createFdwServer($pdo, 'legacy_paramascotasec', $primaryRuntimeConfig);
            dropRemoteSchema($pdo, 'legacy_source');
            importForeignTables($pdo, 'legacy_paramascotasec', 'legacy_source', LEGACY_TABLES);
            copyOwnedTablesFromLegacy($pdo, MODULE_TABLES[$moduleKey]);
            $pdo->exec('DROP SCHEMA IF EXISTS legacy_source CASCADE');
            fwrite(STDOUT, sprintf("[module-db] copied owner tables module=%s db=%s\n", $moduleKey, $target['database']));
        }

        foreach ($targets as $moduleKey => $target) {
            $pdo = connect(connectionTargetConfig($target, $adminConfig));
            replaceNonOwnedTablesWithForeignTables($pdo, $moduleKey, $targets);
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS module_database_metadata (
                    module_key text PRIMARY KEY,
                    database_name text NOT NULL,
                    ownership_mode text NOT NULL,
                    updated_at timestamp without time zone DEFAULT NOW() NOT NULL
                )
            ');
            $stmt = $pdo->prepare('
                INSERT INTO module_database_metadata (module_key, database_name, ownership_mode, updated_at)
                VALUES (:module_key, :database_name, :ownership_mode, NOW())
                ON CONFLICT (module_key)
                DO UPDATE SET database_name = EXCLUDED.database_name,
                              ownership_mode = EXCLUDED.ownership_mode,
                              updated_at = NOW()
            ');
            $stmt->execute([
                'module_key' => $moduleKey,
                'database_name' => (string)$target['database'],
                'ownership_mode' => 'local-owner-with-fdw-compat',
            ]);
            fwrite(STDOUT, sprintf("[module-db] linked compatibility tables module=%s db=%s\n", $moduleKey, $target['database']));
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[module-db] error: ' . $e->getMessage() . PHP_EOL);
        return 1;
    }

    return 0;
}

exit(runModuleDatabaseBootstrap());
