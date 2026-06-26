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
];

const MODULE_CONNECTIONS = [
    'identity-platform' => ['database' => 'identity_platform', 'owner' => 'identity-platform'],
    'catalog-inventory' => ['database' => 'catalog_inventory', 'owner' => 'catalog-inventory'],
    'commerce' => ['database' => 'commerce_orders', 'owner' => 'commerce'],
    'commerce-orders' => ['database' => 'commerce_orders', 'owner' => 'commerce'],
    'billing' => ['database' => 'billing_service', 'owner' => 'billing'],
    'billing-sri' => ['database' => 'billing_service', 'owner' => 'billing'],
    'reporting-finance' => ['database' => 'reporting_finance', 'owner' => 'reporting-finance'],
    'mailer-service' => ['database' => 'mailer_service', 'owner' => 'mailer-service'],
    'email-service' => ['database' => 'mailer_service', 'owner' => 'mailer-service'],
];

const FDW_COMPATIBILITY_TABLES = [
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

const LOCAL_RELKINDS = ['r', 'p'];

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
    if (($config['mode'] ?? null) !== 'dedicated') {
        fail("{$moduleKey} config mode=" . ($config['mode'] ?? 'null') . ', expected dedicated');
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

function assertCompatibilityTables(PDO $pdo, string $moduleKey, array $ownerMap): void
{
    if ($moduleKey === 'billing') {
        return;
    }

    foreach (FDW_COMPATIBILITY_TABLES as $table) {
        $owner = $ownerMap[$table] ?? null;
        if ($owner === null || $owner === $moduleKey) {
            continue;
        }

        $kind = relationKind($pdo, $table);
        if ($kind !== 'f') {
            fail("{$moduleKey} compatibility table {$table} relkind=" . ($kind ?? 'missing') . ', expected foreign table');
        }
    }
}

function assertNoCrossDomainForeignKeys(PDO $pdo, string $moduleKey, array $ownerMap): void
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
$checkedOwners = [];

foreach (MODULE_CONNECTIONS as $moduleKey => $expectation) {
    assertConnection($moduleKey, (string)$expectation['database']);

    $ownerModule = (string)$expectation['owner'];
    if (isset($checkedOwners[$ownerModule])) {
        continue;
    }

    $pdo = Database::getModuleInstance($moduleKey);
    assertOwnerTables($pdo, $ownerModule, MODULE_OWNER_TABLES[$ownerModule]);
    assertCompatibilityTables($pdo, $ownerModule, $ownerMap);
    assertNoCrossDomainForeignKeys($pdo, $ownerModule, $ownerMap);
    $checkedOwners[$ownerModule] = true;
}

fwrite(STDOUT, sprintf(
    "[module-db-check] OK connections=%d owner_modules=%d\n",
    count(MODULE_CONNECTIONS),
    count($checkedOwners)
));
