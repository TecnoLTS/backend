<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\ConnectionRegistry;
use App\Core\Database;

const MODULE_OWNER_TABLES = [
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
        'Customer',
        'CustomerAuthSecurityEvent',
        'CustomerPasswordResetToken',
        'Order',
        'OrderItem',
        'Quotation',
        'DiscountCode',
        'DiscountAudit',
        'PosShift',
        'PosMovement',
    ],
    'billing' => [
        'clients',
        'client_branches',
        'branch_sequences',
        'invoice_headers',
        'invoice_details',
        'billing_customers',
        'invoice_retry_settings',
        'api_keys',
        'billing_domain_events',
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
    'loyalty-rewards' => [
        'loyalty_programs',
        'loyalty_members',
        'loyalty_point_accounts',
        'loyalty_point_ledger',
        'loyalty_rewards',
        'loyalty_redemptions',
        'loyalty_wallet_passes',
    ],
];

const MODULE_CONNECTIONS = [
    'identity-platform' => ['database' => 'dashboard', 'owner' => 'identity-platform'],
    'catalog-inventory' => ['database' => 'ecommerce', 'owner' => 'catalog-inventory'],
    'commerce' => ['database' => 'ecommerce', 'owner' => 'commerce'],
    'commerce-orders' => ['database' => 'ecommerce', 'owner' => 'commerce'],
    'billing' => ['database' => 'facturacion', 'owner' => 'billing'],
    'billing-sri' => ['database' => 'facturacion', 'owner' => 'billing'],
    'reporting-finance' => ['database' => 'ecommerce', 'owner' => 'reporting-finance'],
    'mailer-service' => ['database' => 'dashboard', 'owner' => 'mailer-service'],
    'email-service' => ['database' => 'dashboard', 'owner' => 'mailer-service'],
    'loyalty-rewards' => ['database' => 'loyalty', 'owner' => 'loyalty-rewards'],
    'loyalty-points' => ['database' => 'loyalty', 'owner' => 'loyalty-rewards'],
];

const FDW_COMPATIBILITY_TABLES = [
    'Tenant',
    'tenant_module_entitlements',
    'tenant_memberships',
    'tenant_roles',
    'tenant_user_roles',
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
    'loyalty_programs',
    'loyalty_members',
    'loyalty_point_accounts',
    'loyalty_point_ledger',
    'loyalty_rewards',
    'loyalty_redemptions',
    'loyalty_wallet_passes',
];

const LOCAL_RELKINDS = ['r', 'p'];
const LOCAL_AUTH_ONLY_TABLES = ['User', 'AuthSecurityEvent', 'PasswordResetToken'];

function fail(string $message): void
{
    fwrite(STDERR, "[module-db-check] {$message}\n");
    exit(1);
}

function relationKind(PDO $pdo, string $table): ?string
{
    $stmt = $pdo->prepare(
        "SELECT c.relkind::text
         FROM pg_class c
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public'
           AND c.relname = :table
         LIMIT 1"
    );
    $stmt->execute(['table' => $table]);
    $value = $stmt->fetchColumn();

    return is_string($value) && $value !== '' ? $value : null;
}

function tableOwnerMap(): array
{
    $owners = [];
    foreach (MODULE_OWNER_TABLES as $moduleKey => $tables) {
        foreach ($tables as $table) {
            $owners[$table] = $moduleKey;
        }
    }

    return $owners;
}

function assertConnection(string $moduleKey, string $expectedDatabase): void
{
    $config = ConnectionRegistry::resolveDatabaseConfig($moduleKey);
    if (($config['database'] ?? null) !== $expectedDatabase) {
        fail("{$moduleKey} config resolves database=" . ($config['database'] ?? 'null') . ", expected {$expectedDatabase}");
    }
    if (($config['mode'] ?? null) !== 'service-group') {
        fail("{$moduleKey} config mode=" . ($config['mode'] ?? 'null') . ', expected service-group');
    }

    $pdo = Database::getModuleInstance($moduleKey);
    $actualDatabase = $pdo->query('SELECT current_database()')->fetchColumn();
    if ($actualDatabase !== $expectedDatabase) {
        fail("{$moduleKey} runtime resolves database={$actualDatabase}, expected {$expectedDatabase}");
    }
}

function assertOwnerTables(PDO $pdo, string $moduleKey, array $tables): void
{
    foreach ($tables as $table) {
        $kind = relationKind($pdo, $table);
        if (!in_array($kind, LOCAL_RELKINDS, true)) {
            fail("{$moduleKey} owner table {$table} relkind=" . ($kind ?? 'missing') . ', expected local table');
        }
    }
}

function ownerDatabaseMap(): array
{
    $map = [];
    foreach (MODULE_CONNECTIONS as $expectation) {
        $owner = (string)$expectation['owner'];
        $map[$owner] ??= (string)$expectation['database'];
    }

    return $map;
}

function assertCompatibilityTables(PDO $pdo, string $moduleKey, array $ownerMap, array $ownerDatabaseMap): void
{
    if ($moduleKey === 'billing') {
        return;
    }

    $currentDatabase = $ownerDatabaseMap[$moduleKey] ?? '';

    foreach (FDW_COMPATIBILITY_TABLES as $table) {
        $owner = $ownerMap[$table] ?? null;
        if ($owner === null || $owner === $moduleKey) {
            continue;
        }
        if (($ownerDatabaseMap[$owner] ?? null) === $currentDatabase) {
            continue;
        }

        $kind = relationKind($pdo, $table);
        if ($kind !== 'f') {
            fail("{$moduleKey} compatibility table {$table} relkind=" . ($kind ?? 'missing') . ', expected foreign table');
        }
    }
}

function assertForeignServerAccess(PDO $pdo, string $moduleKey): void
{
    if ($moduleKey === 'billing') {
        return;
    }

    $stmt = $pdo->query(
        "SELECT srvname
         FROM pg_foreign_server
         WHERE NOT has_server_privilege(current_user, srvname, 'USAGE')
         ORDER BY srvname"
    );
    $missingUsage = array_map(static fn($value): string => (string)$value, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($missingUsage !== []) {
        fail($moduleKey . ' missing USAGE on foreign servers: ' . implode(', ', $missingUsage));
    }

    $stmt = $pdo->query(
        "SELECT s.srvname
         FROM pg_foreign_server s
         WHERE NOT EXISTS (
             SELECT 1
             FROM pg_user_mappings um
             WHERE um.srvid = s.oid
               AND um.umuser = current_user::regrole
         )
         ORDER BY s.srvname"
    );
    $missingMappings = array_map(static fn($value): string => (string)$value, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($missingMappings !== []) {
        fail($moduleKey . ' missing user mappings for current_user on foreign servers: ' . implode(', ', $missingMappings));
    }
}

function assertNoLeakedAuthTables(PDO $pdo, string $moduleKey, array $ownerDatabaseMap): void
{
    if (($ownerDatabaseMap[$moduleKey] ?? null) === 'dashboard') {
        return;
    }

    foreach (LOCAL_AUTH_ONLY_TABLES as $table) {
        $kind = relationKind($pdo, $table);
        if ($kind !== null) {
            fail("{$moduleKey} contains auth table {$table} relkind={$kind}; auth tables belong only to dashboard");
        }
    }
}

function assertCompatibilityTablesReadable(PDO $pdo, string $moduleKey, array $ownerMap, array $ownerDatabaseMap): void
{
    if ($moduleKey === 'billing') {
        return;
    }

    $currentDatabase = $ownerDatabaseMap[$moduleKey] ?? '';

    foreach (FDW_COMPATIBILITY_TABLES as $table) {
        $owner = $ownerMap[$table] ?? null;
        if ($owner === null || $owner === $moduleKey) {
            continue;
        }
        if (($ownerDatabaseMap[$owner] ?? null) === $currentDatabase) {
            continue;
        }

        try {
            $pdo->query('SELECT 1 FROM public.' . quoteIdentifier($table) . ' LIMIT 1')->fetchColumn();
        } catch (Throwable $e) {
            fail(sprintf('%s compatibility table %s is not readable: %s', $moduleKey, $table, $e->getMessage()));
        }
    }
}

function quoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function assertNoCrossDomainForeignKeys(PDO $pdo, string $moduleKey, array $ownerMap, array $ownerDatabaseMap): void
{
    $stmt = $pdo->query(
        "SELECT
            source.relname AS source_table,
            target.relname AS target_table,
            constraint_info.conname AS constraint_name
         FROM pg_constraint constraint_info
         JOIN pg_class source ON source.oid = constraint_info.conrelid
         JOIN pg_namespace source_ns ON source_ns.oid = source.relnamespace
         JOIN pg_class target ON target.oid = constraint_info.confrelid
         JOIN pg_namespace target_ns ON target_ns.oid = target.relnamespace
         WHERE constraint_info.contype = 'f'
           AND source_ns.nspname = 'public'
           AND target_ns.nspname = 'public'
         ORDER BY source.relname, constraint_info.conname"
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $sourceTable = (string)$row['source_table'];
        $targetTable = (string)$row['target_table'];
        $sourceOwner = $ownerMap[$sourceTable] ?? null;
        $targetOwner = $ownerMap[$targetTable] ?? null;

        if ($sourceOwner !== $moduleKey) {
            continue;
        }
        if ($targetOwner !== $moduleKey) {
            if ($targetOwner !== null && ($ownerDatabaseMap[$sourceOwner] ?? null) === ($ownerDatabaseMap[$targetOwner] ?? null)) {
                continue;
            }
            fail(sprintf(
                '%s FK %s links owner table %s to %s owned by %s',
                $moduleKey,
                (string)$row['constraint_name'],
                $sourceTable,
                $targetTable,
                $targetOwner ?? 'unknown'
            ));
        }
    }
}

$ownerMap = tableOwnerMap();
$ownerDatabaseMap = ownerDatabaseMap();
$checkedOwners = [];

foreach (MODULE_CONNECTIONS as $moduleKey => $expectation) {
    assertConnection($moduleKey, (string)$expectation['database']);

    $ownerModule = (string)$expectation['owner'];
    if (isset($checkedOwners[$ownerModule])) {
        continue;
    }

    $pdo = Database::getModuleInstance($moduleKey);
    assertOwnerTables($pdo, $ownerModule, MODULE_OWNER_TABLES[$ownerModule]);
    assertNoLeakedAuthTables($pdo, $ownerModule, $ownerDatabaseMap);
    assertCompatibilityTables($pdo, $ownerModule, $ownerMap, $ownerDatabaseMap);
    assertForeignServerAccess($pdo, $ownerModule);
    assertCompatibilityTablesReadable($pdo, $ownerModule, $ownerMap, $ownerDatabaseMap);
    assertNoCrossDomainForeignKeys($pdo, $ownerModule, $ownerMap, $ownerDatabaseMap);
    $checkedOwners[$ownerModule] = true;
}

fwrite(STDOUT, sprintf(
    "[module-db-check] OK connections=%d owner_modules=%d\n",
    count(MODULE_CONNECTIONS),
    count($checkedOwners)
));
