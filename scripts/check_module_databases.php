<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\ConnectionRegistry;
use App\Core\Database;

if (strtolower(trim((string)($_ENV['TENANT_RLS_MODE'] ?? getenv('TENANT_RLS_MODE') ?: 'off'))) === 'enforce') {
    $_ENV['DB_CONNECTION_ROLE'] = 'worker';
}

const MODULE_OWNER_TABLES = [
    'identity-platform' => [
        'Tenant',
        'User',
        'tenant_module_entitlements',
        'tenant_memberships',
        'tenant_roles',
        'tenant_user_roles',
        'tenant_role_navigation_grants',
        'tenant_access_audit_events',
        'tenant_user_sessions',
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
        'CommerceBillingOutbox',
        'CommerceBillingOutboxAttempt',
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
        'loyalty_cash_receipts',
        'loyalty_rewards',
        'loyalty_redemptions',
        'loyalty_wallet_passes',
        'loyalty_program_settings',
        'loyalty_tier_rules',
        'loyalty_api_clients',
        'loyalty_idempotency_keys',
        'loyalty_api_rate_limit_counters',
        'loyalty_api_usage_daily',
        'loyalty_audit_events',
        'loyalty_risk_events',
        'loyalty_point_expirations',
        'loyalty_reversals',
        'loyalty_debt_ledger',
        'loyalty_command_journal',
        'loyalty_earning_rule_versions',
        'loyalty_api_request_nonces',
        'loyalty_portal_otp_challenges',
        'loyalty_portal_sessions',
        'loyalty_portal_form_nonces',
        'loyalty_wallet_campaigns',
        'loyalty_wallet_campaign_recipients',
        'loyalty_navigation_items',
        'loyalty_navigation_item_actions',
    ],
];

// These are the only owner tables whose rows are intentionally global. Every
// other owner table must carry a NOT NULL tenant_id and, in enforce mode, FORCE RLS.
const OWNER_GLOBAL_TABLES = [
    'identity-platform' => ['Tenant'],
];

const INFRASTRUCTURE_LOCAL_TABLES = [
    'module_database_metadata',
    'module_migration_receipts',
    'billing_schema_migrations',
    'flyway_schema_history',
    'tenant_runtime_registry',
    'tenant_runtime_registry_mutations',
];

const TENANT_CHILD_CONTRACTS = [
    'catalog-inventory' => [
        [
            'child' => 'Image',
            'parent' => 'Product',
            'child_column' => 'product_id',
            'parent_column' => 'id',
            'constraint' => 'Image_product_tenant_fk',
            'index' => 'Image_tenant_product_idx',
        ],
        [
            'child' => 'Variation',
            'parent' => 'Product',
            'child_column' => 'product_id',
            'parent_column' => 'id',
            'constraint' => 'Variation_product_tenant_fk',
            'index' => 'Variation_tenant_product_idx',
        ],
    ],
    'commerce' => [
        [
            'child' => 'OrderItem',
            'parent' => 'Order',
            'child_column' => 'order_id',
            'parent_column' => 'id',
            'constraint' => 'OrderItem_order_tenant_fk',
            'index' => 'OrderItem_tenant_order_idx',
        ],
        [
            'child' => 'CommerceBillingOutbox',
            'parent' => 'Order',
            'child_column' => 'order_id',
            'parent_column' => 'id',
            'constraint' => 'commerce_billing_outbox_order_fk',
            'index' => 'CommerceBillingOutbox_tenant_order_idx',
        ],
        [
            'child' => 'CommerceBillingOutboxAttempt',
            'parent' => 'CommerceBillingOutbox',
            'child_column' => 'outbox_id',
            'parent_column' => 'id',
            'constraint' => 'commerce_billing_attempt_outbox_fk',
            'index' => 'CommerceBillingOutboxAttempt_tenant_outbox_idx',
        ],
    ],
    'billing' => [
        [
            'child' => 'invoice_details',
            'parent' => 'invoice_headers',
            'child_column' => 'invoice_header_id',
            'parent_column' => 'id',
            'constraint' => 'invoice_details_header_tenant_fk',
            'index' => 'invoice_details_tenant_header_idx',
        ],
        [
            'child' => 'branch_sequences',
            'parent' => 'client_branches',
            'child_column' => 'branch_id',
            'parent_column' => 'id',
            'constraint' => 'branch_sequences_branch_tenant_fk',
            'index' => 'branch_sequences_tenant_branch_idx',
        ],
        [
            'child' => 'billing_domain_events',
            'parent' => 'invoice_headers',
            'child_column' => 'access_key',
            'parent_column' => 'access_key',
            'constraint' => 'billing_domain_events_invoice_tenant_fk',
            'index' => 'billing_domain_events_tenant_access_idx',
        ],
        [
            'child' => 'billing_domain_events',
            'parent' => 'clients',
            'child_column' => 'client_id',
            'parent_column' => 'id',
            'constraint' => 'billing_domain_events_client_tenant_fk',
            'index' => 'billing_domain_events_tenant_client_event_idx',
        ],
        [
            'child' => 'billing_domain_events',
            'parent' => 'client_branches',
            'child_column' => 'branch_id',
            'parent_column' => 'id',
            'constraint' => 'billing_domain_events_branch_tenant_fk',
            'index' => 'billing_domain_events_tenant_branch_idx',
        ],
        [
            'child' => 'billing_domain_events',
            'parent' => 'api_keys',
            'child_column' => 'api_key_id',
            'parent_column' => 'id',
            'constraint' => 'billing_domain_events_api_key_tenant_fk',
            'index' => 'billing_domain_events_tenant_api_key_idx',
        ],
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
    'loyalty_cash_receipts',
    'loyalty_rewards',
    'loyalty_redemptions',
    'loyalty_wallet_passes',
    'loyalty_program_settings',
    'loyalty_tier_rules',
    'loyalty_api_clients',
    'loyalty_idempotency_keys',
    'loyalty_api_rate_limit_counters',
    'loyalty_api_usage_daily',
    'loyalty_audit_events',
    'loyalty_risk_events',
    'loyalty_point_expirations',
    'loyalty_reversals',
    'loyalty_debt_ledger',
    'loyalty_command_journal',
    'loyalty_earning_rule_versions',
    'loyalty_api_request_nonces',
    'loyalty_portal_otp_challenges',
    'loyalty_portal_sessions',
    'loyalty_portal_form_nonces',
    'loyalty_wallet_campaigns',
    'loyalty_wallet_campaign_recipients',
    'loyalty_navigation_items',
    'loyalty_navigation_item_actions',
];

const LOCAL_RELKINDS = ['r', 'p'];
const LOCAL_AUTH_ONLY_TABLES = [
    'User',
    'AuthSecurityEvent',
    'PasswordResetToken',
    'tenant_role_navigation_grants',
    'tenant_access_audit_events',
];

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

function assertNoUnclassifiedLocalTables(PDO $pdo, array $ownerMap): void
{
    $known = array_fill_keys(array_merge(array_keys($ownerMap), INFRASTRUCTURE_LOCAL_TABLES), true);
    $stmt = $pdo->query(
        "SELECT relation.relname
         FROM pg_class relation
         JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
         WHERE namespace.nspname = 'public'
           AND relation.relkind IN ('r', 'p')
         ORDER BY relation.relname"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $table) {
        $table = (string)$table;
        if (!isset($known[$table])) {
            fail('unclassified local owner/infra table: ' . $table);
        }
    }
}

function tenantOwnerTables(string $ownerModule): array
{
    $global = array_fill_keys(OWNER_GLOBAL_TABLES[$ownerModule] ?? [], true);

    return array_values(array_filter(
        MODULE_OWNER_TABLES[$ownerModule] ?? [],
        static fn(string $table): bool => !isset($global[$table])
    ));
}

function assertGlobalOwnerTables(PDO $pdo, string $ownerModule): void
{
    foreach (OWNER_GLOBAL_TABLES[$ownerModule] ?? [] as $table) {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = :table_name
               AND column_name = 'tenant_id'"
        );
        $stmt->execute(['table_name' => $table]);
        if ($stmt->fetchColumn()) {
            fail("{$ownerModule}.{$table} is allowlisted as global but now carries tenant_id; reclassify it explicitly");
        }
    }
}

function assertTenantChildContracts(PDO $pdo, string $ownerModule): void
{
    foreach (TENANT_CHILD_CONTRACTS[$ownerModule] ?? [] as $relationship) {
        $child = (string)$relationship['child'];
        $parent = (string)$relationship['parent'];
        $childColumn = (string)$relationship['child_column'];
        $parentColumn = (string)$relationship['parent_column'];
        $constraint = (string)$relationship['constraint'];
        $index = (string)$relationship['index'];

        $constraintStmt = $pdo->prepare(
            "SELECT constraint_info.convalidated,
                    parent.relname AS parent_table,
                    (
                        SELECT string_agg(attribute.attname, ',' ORDER BY key.position)
                        FROM unnest(constraint_info.conkey) WITH ORDINALITY AS key(attnum, position)
                        JOIN pg_attribute attribute
                          ON attribute.attrelid = constraint_info.conrelid
                         AND attribute.attnum = key.attnum
                    ) AS child_columns,
                    (
                        SELECT string_agg(attribute.attname, ',' ORDER BY key.position)
                        FROM unnest(constraint_info.confkey) WITH ORDINALITY AS key(attnum, position)
                        JOIN pg_attribute attribute
                          ON attribute.attrelid = constraint_info.confrelid
                         AND attribute.attnum = key.attnum
                    ) AS parent_columns
             FROM pg_constraint constraint_info
             JOIN pg_class child ON child.oid = constraint_info.conrelid
             JOIN pg_class parent ON parent.oid = constraint_info.confrelid
             JOIN pg_namespace namespace ON namespace.oid = child.relnamespace
             WHERE namespace.nspname = 'public'
               AND child.relname = :child_table
               AND constraint_info.conname = :constraint_name
               AND constraint_info.contype = 'f'"
        );
        $constraintStmt->execute([
            'child_table' => $child,
            'constraint_name' => $constraint,
        ]);
        $contract = $constraintStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($contract)
            || !filter_var($contract['convalidated'], FILTER_VALIDATE_BOOLEAN)
            || (string)$contract['parent_table'] !== $parent
            || (string)$contract['child_columns'] !== 'tenant_id,' . $childColumn
            || (string)$contract['parent_columns'] !== 'tenant_id,' . $parentColumn) {
            fail("{$ownerModule}.{$child} lacks validated compound tenant FK {$constraint}");
        }

        $indexStmt = $pdo->prepare(
            "SELECT index_info.indisvalid,
                    (
                        SELECT string_agg(attribute.attname, ',' ORDER BY key.position)
                        FROM unnest(index_info.indkey) WITH ORDINALITY AS key(attnum, position)
                        JOIN pg_attribute attribute
                          ON attribute.attrelid = index_info.indrelid
                         AND attribute.attnum = key.attnum
                    ) AS index_columns
             FROM pg_class index_relation
             JOIN pg_namespace namespace ON namespace.oid = index_relation.relnamespace
             JOIN pg_index index_info ON index_info.indexrelid = index_relation.oid
             JOIN pg_class table_relation ON table_relation.oid = index_info.indrelid
             WHERE namespace.nspname = 'public'
               AND index_relation.relname = :index_name
               AND table_relation.relname = :child_table"
        );
        $indexStmt->execute([
            'index_name' => $index,
            'child_table' => $child,
        ]);
        $indexContract = $indexStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($indexContract)
            || !filter_var($indexContract['indisvalid'], FILTER_VALIDATE_BOOLEAN)
            || !str_starts_with((string)$indexContract['index_columns'], 'tenant_id,' . $childColumn)) {
            fail("{$ownerModule}.{$child} lacks valid compound tenant index {$index}");
        }

        $mismatch = (int)$pdo->query(sprintf(
            'SELECT COUNT(*)
             FROM public.%s child
             JOIN public.%s parent ON parent.%s = child.%s
             WHERE child.%s IS NOT NULL
               AND child.tenant_id <> parent.tenant_id',
            quoteIdentifier($child),
            quoteIdentifier($parent),
            quoteIdentifier($parentColumn),
            quoteIdentifier($childColumn),
            quoteIdentifier($childColumn)
        ))->fetchColumn();
        if ($mismatch !== 0) {
            fail("{$ownerModule}.{$child} has {$mismatch} tenant rows conflicting with {$parent}");
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

function assertBillingTenantIsolation(PDO $pdo): void
{
    $requiredColumns = (int)$pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND (table_name, column_name) IN (
               ('invoice_headers', 'tenant_id'),
               ('invoice_headers', 'billing_customer_id'),
               ('billing_customers', 'tenant_id')
           )"
    )->fetchColumn();
    if ($requiredColumns !== 3) {
        fail('billing tenant isolation columns are incomplete');
    }

    $tenantNullable = (bool)$pdo->query(
        "SELECT is_nullable = 'YES'
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'invoice_headers'
           AND column_name = 'tenant_id'"
    )->fetchColumn();
    if ($tenantNullable) {
        fail('billing invoice_headers.tenant_id must be NOT NULL');
    }

    $unresolved = (int)$pdo->query(
        "SELECT COUNT(*) FROM invoice_headers WHERE NULLIF(BTRIM(tenant_id), '') IS NULL"
    )->fetchColumn();
    if ($unresolved !== 0) {
        fail("billing contains {$unresolved} invoices without tenant");
    }

    $mismatches = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM invoice_headers ih
         JOIN billing_customers bc ON bc.id = ih.billing_customer_id
         WHERE ih.tenant_id IS NOT NULL
           AND ih.tenant_id <> bc.tenant_id'
    )->fetchColumn();
    if ($mismatches !== 0) {
        fail("billing contains {$mismatches} invoice/customer tenant mismatches");
    }
}

function isolationRoleNames(): array
{
    $keys = ['DB_USERNAME', 'DB_WORKER_USERNAME', 'DB_FDW_USERNAME'];
    $roles = [];
    foreach ($keys as $key) {
        $value = trim((string)($_ENV[$key] ?? getenv($key) ?: ''));
        if ($value !== '') {
            $roles[$key] = $value;
        }
    }

    return $roles;
}

function assertIsolationRoles(PDO $pdo): void
{
    $rlsMode = strtolower(trim((string)($_ENV['TENANT_RLS_MODE'] ?? getenv('TENANT_RLS_MODE') ?: 'off')));
    if ($rlsMode !== 'enforce') {
        return;
    }

    $roles = isolationRoleNames();
    $ownerRole = trim((string)($_ENV['DB_OWNER_ROLE'] ?? getenv('DB_OWNER_ROLE') ?: ''));
    foreach (['DB_USERNAME', 'DB_WORKER_USERNAME', 'DB_FDW_USERNAME'] as $required) {
        if (!isset($roles[$required])) {
            fail("RLS enforce is missing configured role {$required}");
        }
    }
    if ($ownerRole === '') {
        fail('RLS enforce is missing configured role DB_OWNER_ROLE');
    }

    foreach (array_merge($roles, ['DB_OWNER_ROLE' => $ownerRole]) as $key => $roleName) {
        $stmt = $pdo->prepare(
            'SELECT rolcanlogin, rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls
             FROM pg_roles WHERE rolname = :role_name'
        );
        $stmt->execute(['role_name' => $roleName]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($role)) {
            fail("configured isolation role {$key}={$roleName} does not exist");
        }
        foreach (['rolsuper', 'rolcreaterole', 'rolcreatedb', 'rolreplication', 'rolbypassrls'] as $forbidden) {
            if (filter_var($role[$forbidden], FILTER_VALIDATE_BOOLEAN)) {
                fail("isolation role {$key}={$roleName} has forbidden capability {$forbidden}");
            }
        }
        if ($key === 'DB_OWNER_ROLE' && filter_var($role['rolcanlogin'], FILTER_VALIDATE_BOOLEAN)) {
            fail("owner role {$ownerRole} must be NOLOGIN");
        }
    }

    $apiRole = (string)$roles['DB_USERNAME'];
    $schemaPrivileges = $pdo->prepare(
        "SELECT has_schema_privilege(:owner_role, 'public', 'USAGE') AS can_use,
                has_schema_privilege(:owner_role, 'public', 'CREATE') AS can_create"
    );
    $schemaPrivileges->execute(['owner_role' => $ownerRole]);
    $ownerSchemaPrivileges = $schemaPrivileges->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!filter_var($ownerSchemaPrivileges['can_use'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        fail("owner role {$ownerRole} cannot resolve referential-integrity relations in schema public");
    }
    if (filter_var($ownerSchemaPrivileges['can_create'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        fail("owner role {$ownerRole} must not CREATE relations in runtime schema public");
    }

    foreach (['DB_OWNER_ROLE' => $ownerRole, 'DB_WORKER_USERNAME' => $roles['DB_WORKER_USERNAME'], 'DB_FDW_USERNAME' => $roles['DB_FDW_USERNAME']] as $key => $targetRole) {
        $membership = $pdo->prepare("SELECT pg_has_role(:api_role, :target_role, 'MEMBER')");
        $membership->execute(['api_role' => $apiRole, 'target_role' => $targetRole]);
        if (filter_var($membership->fetchColumn(), FILTER_VALIDATE_BOOLEAN)) {
            fail("API role {$apiRole} can SET ROLE/inherits {$key}={$targetRole}");
        }
    }
}

function relationHasAnyPrivilege(PDO $pdo, string $roleName, string $relation): bool
{
    $stmt = $pdo->prepare(
        "SELECT has_table_privilege(:role_name, :relation, 'SELECT')
             OR has_table_privilege(:role_name, :relation, 'INSERT')
             OR has_table_privilege(:role_name, :relation, 'UPDATE')
             OR has_table_privilege(:role_name, :relation, 'DELETE')
             OR has_table_privilege(:role_name, :relation, 'TRUNCATE')
             OR has_table_privilege(:role_name, :relation, 'REFERENCES')
             OR has_table_privilege(:role_name, :relation, 'TRIGGER')"
    );
    $stmt->execute(['role_name' => $roleName, 'relation' => $relation]);

    return filter_var($stmt->fetchColumn(), FILTER_VALIDATE_BOOLEAN);
}

function assertRuntimeCannotUseFdw(PDO $pdo, string $ownerModule): void
{
    $rlsMode = strtolower(trim((string)($_ENV['TENANT_RLS_MODE'] ?? getenv('TENANT_RLS_MODE') ?: 'off')));
    if ($rlsMode !== 'enforce') {
        return;
    }
    $roles = isolationRoleNames();

    $foreignTables = $pdo->query(
        "SELECT relation.relname
         FROM pg_class relation
         JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
         WHERE namespace.nspname = 'public' AND relation.relkind = 'f'
         ORDER BY relation.relname"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($foreignTables as $table) {
        $relation = 'public.' . quoteIdentifier((string)$table);
        foreach ($roles as $key => $roleName) {
            if (relationHasAnyPrivilege($pdo, $roleName, $relation)) {
                fail("{$ownerModule}.{$table} grants foreign-table privileges to {$key}={$roleName}");
            }
        }
    }

    $servers = $pdo->query('SELECT oid, srvname FROM pg_foreign_server ORDER BY srvname')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($servers as $server) {
        foreach ($roles as $key => $roleName) {
            $usage = $pdo->prepare("SELECT has_server_privilege(:role_name, :server_name, 'USAGE')");
            $usage->execute(['role_name' => $roleName, 'server_name' => (string)$server['srvname']]);
            if (filter_var($usage->fetchColumn(), FILTER_VALIDATE_BOOLEAN)) {
                fail("{$ownerModule}.{$server['srvname']} grants foreign-server USAGE to {$key}={$roleName}");
            }
        }

        // PostgreSQL 18 protects pg_user_mapping from non-superusers. This
        // runtime audit intentionally remains least-privilege, so it verifies
        // the current worker through the public filtered view and verifies all
        // runtime roles are unusable through has_server_privilege above. The
        // privileged tenant-isolation --check separately proves that no hidden
        // role or PUBLIC mappings exist for any server.
        $currentMapping = $pdo->prepare(
            'SELECT 1 FROM pg_user_mappings
             WHERE srvid = :server_oid AND usename = current_user'
        );
        $currentMapping->execute(['server_oid' => (int)$server['oid']]);
        if ($currentMapping->fetchColumn()) {
            fail("{$ownerModule}.{$server['srvname']} keeps a user mapping for the audit runtime");
        }
    }

    $fdwExists = (bool)$pdo->query("SELECT 1 FROM pg_foreign_data_wrapper WHERE fdwname = 'postgres_fdw'")->fetchColumn();
    if ($fdwExists) {
        foreach ($roles as $key => $roleName) {
            $usage = $pdo->prepare("SELECT has_foreign_data_wrapper_privilege(:role_name, 'postgres_fdw', 'USAGE')");
            $usage->execute(['role_name' => $roleName]);
            if (filter_var($usage->fetchColumn(), FILTER_VALIDATE_BOOLEAN)) {
                fail("{$ownerModule} grants postgres_fdw wrapper USAGE to {$key}={$roleName}");
            }
        }
    }
}

function assertInfrastructureTablesIsolated(PDO $pdo, string $ownerModule): void
{
    $rlsMode = strtolower(trim((string)($_ENV['TENANT_RLS_MODE'] ?? getenv('TENANT_RLS_MODE') ?: 'off')));
    if ($rlsMode !== 'enforce') {
        return;
    }
    foreach (INFRASTRUCTURE_LOCAL_TABLES as $table) {
        if (!in_array(relationKind($pdo, $table), LOCAL_RELKINDS, true)) {
            continue;
        }
        $relation = 'public.' . quoteIdentifier($table);
        foreach (isolationRoleNames() as $key => $roleName) {
            if (relationHasAnyPrivilege($pdo, $roleName, $relation)) {
                fail("{$ownerModule}.{$table} grants technical-table privileges to {$key}={$roleName}");
            }
        }
    }
}

function assertTenantTableContract(PDO $pdo, string $ownerModule, array $tables): void
{
    $rlsMode = strtolower(trim((string)($_ENV['TENANT_RLS_MODE'] ?? getenv('TENANT_RLS_MODE') ?: 'off')));
    $apiRole = trim((string)($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: ''));
    foreach ($tables as $table) {
        $stmt = $pdo->prepare(
            "SELECT c.relrowsecurity, c.relforcerowsecurity, a.attnotnull,
                    pg_get_userbyid(c.relowner) AS owner_name
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_attribute a ON a.attrelid = c.oid
             WHERE n.nspname = 'public' AND c.relname = :table
               AND c.relkind IN ('r', 'p')
               AND a.attname = 'tenant_id' AND NOT a.attisdropped"
        );
        $stmt->execute(['table' => $table]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($contract)) {
            fail("{$ownerModule}.{$table} lacks a local tenant_id contract");
        }
        if (!filter_var($contract['attnotnull'], FILTER_VALIDATE_BOOLEAN)) {
            fail("{$ownerModule}.{$table}.tenant_id is nullable");
        }
        $blank = (int)$pdo->query(
            'SELECT COUNT(*) FROM public.' . quoteIdentifier($table) .
            " WHERE NULLIF(BTRIM(tenant_id), '') IS NULL"
        )->fetchColumn();
        if ($blank !== 0) {
            fail("{$ownerModule}.{$table} contains {$blank} blank tenant rows");
        }
        if ($rlsMode !== 'enforce') {
            continue;
        }
        if (!filter_var($contract['relrowsecurity'], FILTER_VALIDATE_BOOLEAN)
            || !filter_var($contract['relforcerowsecurity'], FILTER_VALIDATE_BOOLEAN)) {
            fail("{$ownerModule}.{$table} does not have ENABLE + FORCE RLS");
        }
        if ($apiRole !== '' && (string)$contract['owner_name'] === $apiRole) {
            fail("{$ownerModule}.{$table} is still owned by the API role {$apiRole}");
        }
        $policyStmt = $pdo->prepare(
            "SELECT p.polname, p.polcmd,
                    pg_get_expr(p.polqual, p.polrelid) AS using_expression,
                    pg_get_expr(p.polwithcheck, p.polrelid) AS check_expression,
                    COALESCE((
                        SELECT json_agg(r.rolname ORDER BY r.rolname)
                        FROM unnest(p.polroles) role_oid
                        JOIN pg_roles r ON r.oid = role_oid
                    ), '[]'::json) AS role_names
             FROM pg_policy p
             WHERE p.polrelid = to_regclass(:relation)
               AND p.polname IN ('tenant_scope_app', 'tenant_scope_system')"
        );
        $policyStmt->execute(['relation' => 'public.' . quoteIdentifier($table)]);
        $policyRows = $policyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($policyRows) !== 2) {
            fail("{$ownerModule}.{$table} RLS policies are incomplete");
        }
        $policies = [];
        foreach ($policyRows as $policyRow) {
            $policies[(string)$policyRow['polname']] = $policyRow;
        }
        $appPolicy = $policies['tenant_scope_app'] ?? null;
        $systemPolicy = $policies['tenant_scope_system'] ?? null;
        if (!is_array($appPolicy) || !is_array($systemPolicy)) {
            fail("{$ownerModule}.{$table} RLS policy names are incomplete");
        }
        $normalizePolicyExpression = static function (mixed $expression): string {
            $value = strtolower((string)$expression);
            $value = str_replace(['public.', '"', '::text'], '', $value);
            $value = preg_replace('/[[:space:]]+/', '', $value) ?? $value;
            // PostgreSQL renders varchar tenant columns as `(tenant_id)::text`
            // while text columns render as `tenant_id`.  Both expressions are
            // semantically identical; remove only the redundant identifier
            // parentheses so the audit does not reject correct RLS policies.
            return str_replace('(tenant_id)', 'tenant_id', $value);
        };
        $requiredTenantExpression = "tenant_id=nullif(current_setting('app.tenant_id',true),'')";
        $usingExpression = $normalizePolicyExpression($appPolicy['using_expression'] ?? '');
        $checkExpression = $normalizePolicyExpression($appPolicy['check_expression'] ?? '');
        if (($appPolicy['polcmd'] ?? null) !== '*'
            || !str_contains($usingExpression, $requiredTenantExpression)
            || !str_contains($checkExpression, $requiredTenantExpression)) {
            fail("{$ownerModule}.{$table} tenant_scope_app does not enforce tenant equality in USING + WITH CHECK");
        }
        if ($table === 'User'
            && (!str_contains($usingExpression, "tenant_id<>'platform'")
                || !str_contains($checkExpression, "tenant_id<>'platform'"))) {
            fail("{$ownerModule}.{$table} tenant_scope_app permits platform rows through app.tenant_id");
        }
        $appRoles = json_decode((string)($appPolicy['role_names'] ?? '[]'), true);
        if (!is_array($appRoles) || $appRoles !== [$apiRole]) {
            fail("{$ownerModule}.{$table} tenant_scope_app roles differ from the API role");
        }
        $expectedSystemRoles = array_values(array_filter([
            trim((string)($_ENV['DB_FDW_USERNAME'] ?? getenv('DB_FDW_USERNAME') ?: '')),
            trim((string)($_ENV['DB_WORKER_USERNAME'] ?? getenv('DB_WORKER_USERNAME') ?: '')),
        ]));
        sort($expectedSystemRoles);
        $systemRoles = json_decode((string)($systemPolicy['role_names'] ?? '[]'), true);
        if (is_array($systemRoles)) {
            sort($systemRoles);
        }
        if (($systemPolicy['polcmd'] ?? null) !== 'r'
            || $normalizePolicyExpression($systemPolicy['using_expression'] ?? '') !== 'true'
            || trim((string)($systemPolicy['check_expression'] ?? '')) !== ''
            || $systemRoles !== $expectedSystemRoles) {
            fail("{$ownerModule}.{$table} tenant_scope_system command/roles/expression differ from read-only contract");
        }
    }
}

$ownerMap = tableOwnerMap();
$ownerDatabaseMap = ownerDatabaseMap();
$checkedOwners = [];
$rolesChecked = false;

foreach (MODULE_CONNECTIONS as $moduleKey => $expectation) {
    assertConnection($moduleKey, (string)$expectation['database']);

    $ownerModule = (string)$expectation['owner'];
    if (isset($checkedOwners[$ownerModule])) {
        continue;
    }

    $pdo = Database::getModuleInstance($moduleKey);
    if (!$rolesChecked) {
        assertIsolationRoles($pdo);
        $rolesChecked = true;
    }
    assertOwnerTables($pdo, $ownerModule, MODULE_OWNER_TABLES[$ownerModule]);
    assertNoUnclassifiedLocalTables($pdo, $ownerMap);
    assertNoLeakedAuthTables($pdo, $ownerModule, $ownerDatabaseMap);
    assertCompatibilityTables($pdo, $ownerModule, $ownerMap, $ownerDatabaseMap);
    assertRuntimeCannotUseFdw($pdo, $ownerModule);
    assertInfrastructureTablesIsolated($pdo, $ownerModule);
    assertNoCrossDomainForeignKeys($pdo, $ownerModule, $ownerMap, $ownerDatabaseMap);
    assertGlobalOwnerTables($pdo, $ownerModule);
    assertTenantTableContract($pdo, $ownerModule, tenantOwnerTables($ownerModule));
    assertTenantChildContracts($pdo, $ownerModule);
    if ($ownerModule === 'billing') {
        assertBillingTenantIsolation($pdo);
    }
    $checkedOwners[$ownerModule] = true;
}

fwrite(STDOUT, sprintf(
    "[module-db-check] OK connections=%d owner_modules=%d\n",
    count(MODULE_CONNECTIONS),
    count($checkedOwners)
));
