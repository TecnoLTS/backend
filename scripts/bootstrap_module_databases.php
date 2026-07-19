<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_schema.php';
require_once __DIR__ . '/../src/Modules/LoyaltyRewards/Infrastructure/LoyaltySchema.php';
require_once __DIR__ . '/../src/Modules/Billing/Native/Billing/Infrastructure/Persistence/BillingSchema.php';

use App\Modules\Billing\Domain\BillingDomain;
use App\Modules\Billing\Native\Billing\Infrastructure\Persistence\BillingSchema;
use BillingService\Billing\Infrastructure\Persistence\BillingSecretMigrator;
use BillingService\Billing\Infrastructure\Security\BillingSecretCipherFactory;

const MODULE_TABLES = [
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
    BillingDomain::KEY => [
        'clients',
        'client_branches',
        'billing_customers',
        'api_keys',
        'invoice_retry_settings',
        'branch_sequences',
        'invoice_headers',
        'invoice_details',
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

const LEGACY_TABLES = [
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
    'CommerceBillingOutbox',
    'CommerceBillingOutboxAttempt',
    'Customer',
    'CustomerAuthSecurityEvent',
    'CustomerPasswordResetToken',
    'Quotation',
    'DiscountCode',
    'DiscountAudit',
    'PosShift',
    'PosMovement',
    'clients',
    'client_branches',
    'branch_sequences',
    'invoice_headers',
    'invoice_details',
    'billing_customers',
    'invoice_retry_settings',
    'api_keys',
    'billing_domain_events',
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

const MODULE_SKIPPED_CONSTRAINTS = [
    // Cross-domain link: Inventory keeps order_item_id as a stable Commerce ID, not a physical FK.
    'InventoryLotAllocation_order_item_id_fkey',
];

const LOCAL_AUTH_ONLY_TABLES = [
    'User',
    'AuthSecurityEvent',
    'PasswordResetToken',
    'tenant_role_navigation_grants',
    'tenant_access_audit_events',
    'tenant_user_sessions',
];

// Runtime roles do not need bootstrap/migration bookkeeping. Keeping these
// tables outside their ACL also prevents them from becoming an unreviewed
// cross-tenant side channel.
const INFRASTRUCTURE_LOCAL_TABLES = [
    'module_database_metadata',
    'module_migration_receipts',
    'billing_schema_migrations',
    'flyway_schema_history',
    'tenant_runtime_registry',
    'tenant_runtime_registry_mutations',
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
    if (!is_array($registry) || $registry === []) {
        throw new RuntimeException('Module database registry is missing or empty.');
    }
    foreach ($registry as $moduleKey => $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException(sprintf('Module database registry entry %s is invalid.', (string)$moduleKey));
        }
        if (!array_key_exists((string)$moduleKey, MODULE_TABLES)) {
            throw new RuntimeException(sprintf(
                'Module database registry key %s lacks a MODULE_TABLES ownership contract.',
                (string)$moduleKey
            ));
        }
    }
    $missingRegistryKeys = array_values(array_diff(array_keys(MODULE_TABLES), array_keys($registry)));
    if ($missingRegistryKeys !== []) {
        throw new RuntimeException(sprintf(
            'MODULE_TABLES keys lack module database registry entries: %s.',
            implode(',', $missingRegistryKeys)
        ));
    }
    $targets = [];

    foreach ($registry as $moduleKey => $entry) {
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

function moduleTarget(array $baseConfig, string $moduleKey): ?array {
    $registryPath = __DIR__ . '/../config/module-databases.php';
    $registry = is_readable($registryPath) ? require $registryPath : [];
    $entry = $registry[$moduleKey] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $suffix = moduleEnvSuffix($moduleKey);

    return normalizeConfig($baseConfig, [
        'host' => envOrDefault("DB_HOST_{$suffix}", (string)($entry['host'] ?? $baseConfig['host'])),
        'port' => envOrDefault("DB_PORT_{$suffix}", (string)($entry['port'] ?? $baseConfig['port'])),
        'database' => envOrDefault("DB_DATABASE_{$suffix}", (string)($entry['database'] ?? $entry['target_database'] ?? $baseConfig['database'])),
        'username' => envOrDefault("DB_USERNAME_{$suffix}", (string)($entry['username'] ?? $baseConfig['username'])),
        'password' => envOrDefault("DB_PASSWORD_{$suffix}", (string)($entry['password'] ?? $baseConfig['password'])),
    ]);
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

function ensureTargetDatabase(array $targetConfig, array $adminConfig): void {
    $database = trim((string)($targetConfig['database'] ?? ''));
    if ($database === '') {
        return;
    }

    $adminDatabaseConfig = normalizeConfig($adminConfig, ['database' => 'postgres']);
    $pdo = connect($adminDatabaseConfig);
    $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :database');
    $stmt->execute(['database' => $database]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $ownerRole = trim((string)($targetConfig['username'] ?? ''));
    $ownerExists = false;
    if ($ownerRole !== '') {
        $roleStmt = $pdo->prepare('SELECT to_regrole(:role_name) IS NOT NULL');
        $roleStmt->execute(['role_name' => $ownerRole]);
        $ownerExists = (bool)$roleStmt->fetchColumn();
    }

    $sql = 'CREATE DATABASE ' . quoteIdent($database);
    if ($ownerExists) {
        $sql .= ' OWNER ' . quoteIdent($ownerRole);
    }
    $pdo->exec($sql);
}

function grantRuntimeSchemaAccess(PDO $pdo, string $runtimeRole): void {
    $runtimeRole = trim($runtimeRole);
    if ($runtimeRole === '') {
        return;
    }

    $roleExists = $pdo
        ->query('SELECT to_regrole(' . $pdo->quote($runtimeRole) . ') IS NOT NULL')
        ->fetchColumn();
    if (!$roleExists) {
        return;
    }

    $quotedRole = quoteIdent($runtimeRole);
    $pdo->exec('GRANT CONNECT ON DATABASE ' . quoteIdent((string)$pdo->query('SELECT current_database()')->fetchColumn()) . ' TO ' . $quotedRole);
    $pdo->exec('GRANT USAGE ON SCHEMA public TO ' . $quotedRole);
    $pdo->exec('REVOKE CREATE ON SCHEMA public FROM ' . $quotedRole);
    $relations = $pdo->query(
        "SELECT c.relname, c.relkind
         FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'f')"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($relations as $relation) {
        $qualified = 'public.' . quoteIdent((string)$relation['relname']);
        if ((string)$relation['relkind'] === 'f'
            || in_array((string)$relation['relname'], INFRASTRUCTURE_LOCAL_TABLES, true)) {
            $pdo->exec('REVOKE ALL PRIVILEGES ON ' . $qualified . ' FROM ' . $quotedRole);
            continue;
        }
        $pdo->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON ' . $qualified . ' TO ' . $quotedRole);
    }
    $fdwExists = (bool)$pdo->query("SELECT 1 FROM pg_foreign_data_wrapper WHERE fdwname = 'postgres_fdw'")->fetchColumn();
    if ($fdwExists) {
        $pdo->exec('REVOKE USAGE ON FOREIGN DATA WRAPPER postgres_fdw FROM ' . $quotedRole);
    }
    $servers = $pdo->query('SELECT srvname FROM pg_foreign_server ORDER BY srvname')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($servers as $serverName) {
        $quotedServer = quoteIdent((string)$serverName);
        $pdo->exec('REVOKE USAGE ON FOREIGN SERVER ' . $quotedServer . ' FROM ' . $quotedRole);
        $pdo->exec('DROP USER MAPPING IF EXISTS FOR ' . $quotedRole . ' SERVER ' . $quotedServer);
    }
    $pdo->exec('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO ' . $quotedRole);
    $pdo->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ' . $quotedRole);
    $pdo->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO ' . $quotedRole);
}

function grantFdwSourceReadAccess(PDO $pdo): void {
    if (strtolower(trim((string)envValue('TENANT_RLS_MODE', 'off'))) !== 'enforce') {
        return;
    }
    $fdwRole = trim((string)envValue('DB_FDW_USERNAME', ''));
    if ($fdwRole === '' || !(bool)$pdo->query('SELECT to_regrole(' . $pdo->quote($fdwRole) . ') IS NOT NULL')->fetchColumn()) {
        throw new RuntimeException('RLS enforce requires the dedicated FDW role to be provisioned before bootstrap.');
    }
    $quotedRole = quoteIdent($fdwRole);
    $pdo->exec('GRANT CONNECT ON DATABASE ' . quoteIdent((string)$pdo->query('SELECT current_database()')->fetchColumn()) . ' TO ' . $quotedRole);
    $pdo->exec('GRANT USAGE ON SCHEMA public TO ' . $quotedRole);
    $pdo->exec('REVOKE CREATE ON SCHEMA public FROM ' . $quotedRole);
    $pdo->exec('GRANT SELECT ON ALL TABLES IN SCHEMA public TO ' . $quotedRole);
    $pdo->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO ' . $quotedRole);
    foreach (INFRASTRUCTURE_LOCAL_TABLES as $table) {
        if (relationKind($pdo, $table) === null) {
            continue;
        }
        $pdo->exec('REVOKE ALL PRIVILEGES ON public.' . quoteIdent($table) . ' FROM ' . $quotedRole);
    }
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

function moduleTargetGroups(array $targets): array {
    $groups = [];
    foreach ($targets as $moduleKey => $target) {
        $database = (string)($target['database'] ?? '');
        if ($database === '') {
            continue;
        }
        if (!isset($groups[$database])) {
            $groups[$database] = [
                'target' => $target,
                'modules' => [],
                'tables' => [],
            ];
        }
        $groups[$database]['modules'][] = $moduleKey;
        $groups[$database]['tables'] = array_values(array_unique(array_merge(
            $groups[$database]['tables'],
            MODULE_TABLES[$moduleKey] ?? []
        )));
    }

    return $groups;
}

function createMailerTables(PDO $pdo): void {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS "EmailOutbox" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            idempotency_key text NOT NULL,
            payload_fingerprint text NOT NULL,
            recipient_email text NOT NULL,
            subject text NOT NULL,
            body text NOT NULL,
            plain_body text NOT NULL,
            html_body text,
            message_format text NOT NULL DEFAULT \'plain\',
            reply_to_email text,
            reply_to_name text,
            audit_preview text NOT NULL DEFAULT \'Queued mail payload retained for delivery.\',
            status text NOT NULL DEFAULT \'pending\',
            attempts integer NOT NULL DEFAULT 0,
            max_attempts integer NOT NULL DEFAULT 8,
            requeue_count integer NOT NULL DEFAULT 0,
            available_at timestamp without time zone DEFAULT NOW() NOT NULL,
            expires_at timestamp without time zone DEFAULT (NOW() + interval \'1 hour\') NOT NULL,
            locked_at timestamp without time zone,
            locked_by text,
            lock_token text,
            last_error_code text,
            last_error text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            sent_at timestamp without time zone,
            completed_at timestamp without time zone,
            resolved_at timestamp without time zone,
            resolved_by text,
            resolution_reason text
        )
    ');
    foreach ([
        'idempotency_key text',
        'payload_fingerprint text',
        'plain_body text',
        'html_body text',
        'message_format text DEFAULT \'plain\'',
        'reply_to_email text',
        'reply_to_name text',
        'audit_preview text DEFAULT \'Queued mail payload retained for delivery.\'',
        'max_attempts integer DEFAULT 8',
        'requeue_count integer DEFAULT 0',
        'available_at timestamp without time zone DEFAULT NOW()',
        'expires_at timestamp without time zone DEFAULT (NOW() + interval \'1 hour\')',
        'locked_at timestamp without time zone',
        'locked_by text',
        'lock_token text',
        'last_error_code text',
        'completed_at timestamp without time zone',
        'resolved_at timestamp without time zone',
        'resolved_by text',
        'resolution_reason text',
    ] as $definition) {
        $pdo->exec('ALTER TABLE "EmailOutbox" ADD COLUMN IF NOT EXISTS ' . $definition);
    }
    $pdo->exec(<<<'SQL'
        UPDATE "EmailOutbox"
           SET idempotency_key = COALESCE(NULLIF(idempotency_key, ''), 'legacy:' || id),
               payload_fingerprint = COALESCE(NULLIF(payload_fingerprint, ''), md5(tenant_id || '|' || id)),
               plain_body = COALESCE(plain_body, body, ''),
               message_format = CASE
                   WHEN message_format IN ('plain', 'html', 'attachment_audit') THEN message_format
                   WHEN COALESCE(metadata->>'transport', '') IN ('html', 'smtp-html', 'mail-html') THEN 'html'
                   WHEN COALESCE(metadata->>'transport', '') = 'attachment' THEN 'attachment_audit'
                   ELSE 'plain'
               END,
               audit_preview = COALESCE(NULLIF(audit_preview, ''), 'Legacy mail audit record.'),
               max_attempts = GREATEST(1, LEAST(COALESCE(max_attempts, 8), 25)),
               requeue_count = GREATEST(0, COALESCE(requeue_count, 0)),
               available_at = COALESCE(available_at, created_at, NOW()),
               expires_at = COALESCE(expires_at, created_at + interval '24 hours', NOW() + interval '1 hour'),
               status = CASE WHEN status = 'failed' THEN 'dead_letter' ELSE status END,
               completed_at = CASE
                   WHEN status IN ('sent', 'failed', 'dead_letter') THEN COALESCE(completed_at, sent_at, updated_at, NOW())
                   ELSE completed_at
               END
        SQL);
    foreach (['idempotency_key', 'payload_fingerprint', 'plain_body', 'message_format', 'audit_preview', 'max_attempts', 'requeue_count', 'available_at', 'expires_at'] as $column) {
        $pdo->exec(sprintf('ALTER TABLE "EmailOutbox" ALTER COLUMN %s SET NOT NULL', quoteIdent($column)));
    }
    $pdo->exec('ALTER TABLE "EmailOutbox" DROP CONSTRAINT IF EXISTS "EmailOutbox_status_check"');
    $pdo->exec('ALTER TABLE "EmailOutbox" ADD CONSTRAINT "EmailOutbox_status_check" CHECK (status IN (\'pending\', \'retry\', \'processing\', \'sent\', \'dead_letter\'))');
    $pdo->exec('ALTER TABLE "EmailOutbox" DROP CONSTRAINT IF EXISTS "EmailOutbox_format_check"');
    $pdo->exec('ALTER TABLE "EmailOutbox" ADD CONSTRAINT "EmailOutbox_format_check" CHECK (message_format IN (\'plain\', \'html\', \'attachment_audit\'))');
    $pdo->exec('ALTER TABLE "EmailOutbox" DROP CONSTRAINT IF EXISTS "EmailOutbox_attempts_check"');
    $pdo->exec('ALTER TABLE "EmailOutbox" ADD CONSTRAINT "EmailOutbox_attempts_check" CHECK (attempts >= 0 AND max_attempts BETWEEN 1 AND 25 AND requeue_count >= 0)');
    $pdo->exec('ALTER TABLE "EmailOutbox" DROP CONSTRAINT IF EXISTS "EmailOutbox_resolution_check"');
    $pdo->exec(<<<'SQL'
        ALTER TABLE "EmailOutbox"
        ADD CONSTRAINT "EmailOutbox_resolution_check" CHECK (
            (resolved_at IS NULL AND resolved_by IS NULL AND resolution_reason IS NULL)
            OR (
                status = 'dead_letter'
                AND resolved_at IS NOT NULL
                AND NULLIF(BTRIM(resolved_by), '') IS NOT NULL
                AND NULLIF(BTRIM(resolution_reason), '') IS NOT NULL
            )
        )
        SQL);
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS "EmailDeliveryLog" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            outbox_id text,
            recipient_email text NOT NULL,
            attempt_number integer NOT NULL DEFAULT 0,
            phase text NOT NULL DEFAULT \'delivery\',
            status text NOT NULL,
            provider_message_id text,
            error_code text,
            error_message text,
            actor_id text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )
    ');
    foreach ([
        'attempt_number integer DEFAULT 0',
        'phase text DEFAULT \'delivery\'',
        'error_code text',
        'actor_id text',
    ] as $definition) {
        $pdo->exec('ALTER TABLE "EmailDeliveryLog" ADD COLUMN IF NOT EXISTS ' . $definition);
    }
    $pdo->exec('UPDATE "EmailDeliveryLog" SET attempt_number = COALESCE(attempt_number, 0), phase = COALESCE(NULLIF(phase, \'\'), \'delivery\')');
    $pdo->exec('ALTER TABLE "EmailDeliveryLog" ALTER COLUMN attempt_number SET NOT NULL');
    $pdo->exec('ALTER TABLE "EmailDeliveryLog" ALTER COLUMN phase SET NOT NULL');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS "EmailOutbox_tenant_idempotency_uidx" ON "EmailOutbox" (tenant_id, idempotency_key)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "EmailOutbox_tenant_status_idx" ON "EmailOutbox" (tenant_id, status, available_at, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "EmailOutbox_due_idx" ON "EmailOutbox" (available_at, tenant_id, created_at) WHERE status IN (\'pending\', \'retry\') AND message_format IN (\'plain\', \'html\')');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "EmailOutbox_lease_idx" ON "EmailOutbox" (locked_at) WHERE status = \'processing\'');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "EmailOutbox_unresolved_dead_idx" ON "EmailOutbox" (tenant_id, completed_at DESC) WHERE status = \'dead_letter\' AND resolved_at IS NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "EmailDeliveryLog_tenant_created_idx" ON "EmailDeliveryLog" (tenant_id, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "EmailDeliveryLog_outbox_idx" ON "EmailDeliveryLog" (tenant_id, outbox_id, created_at)');
}

function createLoyaltyTables(PDO $pdo): void {
    (new \App\Modules\LoyaltyRewards\Infrastructure\LoyaltySchema($pdo))->ensure();
}

function createBillingTables(PDO $pdo): void {
    (new BillingSchema($pdo))->ensure();
}

/** @return array{rows:int,migrated:int,rotated:int,verified:int,key_id:string} */
function migrateBillingSecrets(PDO $pdo): array {
    try {
        return (new BillingSecretMigrator(
            $pdo,
            BillingSecretCipherFactory::fromEnvironment()
        ))->migrateAndRotate();
    } catch (Throwable) {
        // Never let a PDO DETAIL containing a failing row reach deploy logs.
        throw new RuntimeException('Billing secret migration failed closed; raw database details were suppressed.');
    }
}

function ensureValidatedTenantForeignKey(
    PDO $pdo,
    string $childTable,
    string $parentTable,
    string $childColumn,
    string $parentColumn,
    string $constraintName,
    string $actions = ''
): void {
    $exists = $pdo->prepare(
        "SELECT 1
         FROM pg_constraint constraint_info
         JOIN pg_class relation ON relation.oid = constraint_info.conrelid
         JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
         WHERE namespace.nspname = 'public'
           AND relation.relname = :table_name
           AND constraint_info.conname = :constraint_name
         LIMIT 1"
    );
    $exists->execute([
        'table_name' => $childTable,
        'constraint_name' => $constraintName,
    ]);
    if (!$exists->fetchColumn()) {
        $pdo->exec(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (tenant_id, %s) REFERENCES %s (tenant_id, %s) %s NOT VALID',
            quoteIdent($childTable),
            quoteIdent($constraintName),
            quoteIdent($childColumn),
            quoteIdent($parentTable),
            quoteIdent($parentColumn),
            trim($actions)
        ));
    }
    $pdo->exec(sprintf(
        'ALTER TABLE %s VALIDATE CONSTRAINT %s',
        quoteIdent($childTable),
        quoteIdent($constraintName)
    ));
}

function ensureBillingDomainEventTenantIsolation(PDO $pdo, string $legacyTenant): void {
    $pdo->exec('ALTER TABLE billing_domain_events ADD COLUMN IF NOT EXISTS tenant_id text');
    $pdo->exec(
        "UPDATE billing_domain_events
         SET tenant_id = NULL
         WHERE tenant_id IS NOT NULL AND NULLIF(BTRIM(tenant_id), '') IS NULL"
    );

    $evidenceCte = <<<'SQL'
        WITH event_tenant_evidence AS (
            SELECT event.event_id, candidate.tenant_id
            FROM billing_domain_events event
            CROSS JOIN LATERAL (
                SELECT invoice.tenant_id
                FROM invoice_headers invoice
                WHERE event.access_key IS NOT NULL
                  AND invoice.access_key = event.access_key
                UNION
                SELECT client.tenant_id
                FROM clients client
                WHERE event.client_id IS NOT NULL
                  AND client.id = event.client_id
                UNION
                SELECT branch.tenant_id
                FROM client_branches branch
                WHERE event.branch_id IS NOT NULL
                  AND branch.id = event.branch_id
                UNION
                SELECT api_key.tenant_id
                FROM api_keys api_key
                WHERE event.api_key_id IS NOT NULL
                  AND api_key.id = event.api_key_id
            ) candidate
            WHERE NULLIF(BTRIM(candidate.tenant_id), '') IS NOT NULL
        ), resolved_event_tenants AS (
            SELECT event_id, MIN(tenant_id) AS tenant_id, COUNT(DISTINCT tenant_id) AS tenant_count
            FROM event_tenant_evidence
            GROUP BY event_id
        )
        SQL;

    $conflicts = (int)$pdo->query(
        $evidenceCte . ' SELECT COUNT(*) FROM resolved_event_tenants WHERE tenant_count > 1'
    )->fetchColumn();
    if ($conflicts !== 0) {
        throw new RuntimeException(sprintf(
            'billing_domain_events tenant backfill has %d rows with conflicting parent evidence',
            $conflicts
        ));
    }

    $existingMismatches = (int)$pdo->query(
        $evidenceCte .
        " SELECT COUNT(*)
          FROM billing_domain_events event
          JOIN resolved_event_tenants resolved ON resolved.event_id = event.event_id
          WHERE resolved.tenant_count = 1
            AND NULLIF(BTRIM(event.tenant_id), '') IS NOT NULL
            AND event.tenant_id <> resolved.tenant_id"
    )->fetchColumn();
    if ($existingMismatches !== 0) {
        throw new RuntimeException(sprintf(
            'billing_domain_events contains %d tenant values that conflict with parent evidence',
            $existingMismatches
        ));
    }

    $pdo->exec(
        $evidenceCte .
        " UPDATE billing_domain_events event
          SET tenant_id = resolved.tenant_id
          FROM resolved_event_tenants resolved
          WHERE resolved.event_id = event.event_id
            AND resolved.tenant_count = 1
            AND NULLIF(BTRIM(event.tenant_id), '') IS NULL"
    );
    if ($legacyTenant !== '') {
        $statement = $pdo->prepare(
            "UPDATE billing_domain_events
             SET tenant_id = :tenant_id
             WHERE NULLIF(BTRIM(tenant_id), '') IS NULL"
        );
        $statement->execute(['tenant_id' => $legacyTenant]);
    }

    $unresolved = (int)$pdo->query(
        "SELECT COUNT(*) FROM billing_domain_events WHERE NULLIF(BTRIM(tenant_id), '') IS NULL"
    )->fetchColumn();
    if ($unresolved !== 0) {
        throw new RuntimeException(sprintf(
            'billing_domain_events tenant backfill unresolved rows=%d; set BILLING_LEGACY_TENANT_ID only after validating ownership',
            $unresolved
        ));
    }

    $pdo->exec('ALTER TABLE billing_domain_events ALTER COLUMN tenant_id SET NOT NULL');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS billing_domain_events_tenant_event_uidx ON billing_domain_events (tenant_id, event_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS billing_domain_events_tenant_access_idx ON billing_domain_events (tenant_id, access_key, occurred_on DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS billing_domain_events_tenant_client_event_idx ON billing_domain_events (tenant_id, client_id, event_name, occurred_on DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS billing_domain_events_tenant_branch_idx ON billing_domain_events (tenant_id, branch_id, occurred_on DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS billing_domain_events_tenant_api_key_idx ON billing_domain_events (tenant_id, api_key_id, occurred_on DESC)');

    ensureValidatedTenantForeignKey(
        $pdo,
        'billing_domain_events',
        'invoice_headers',
        'access_key',
        'access_key',
        'billing_domain_events_invoice_tenant_fk',
        'ON UPDATE CASCADE ON DELETE SET NULL (access_key)'
    );
    ensureValidatedTenantForeignKey(
        $pdo,
        'billing_domain_events',
        'clients',
        'client_id',
        'id',
        'billing_domain_events_client_tenant_fk',
        'ON UPDATE CASCADE ON DELETE SET NULL (client_id)'
    );
    ensureValidatedTenantForeignKey(
        $pdo,
        'billing_domain_events',
        'client_branches',
        'branch_id',
        'id',
        'billing_domain_events_branch_tenant_fk',
        'ON UPDATE CASCADE ON DELETE SET NULL (branch_id)'
    );
    ensureValidatedTenantForeignKey(
        $pdo,
        'billing_domain_events',
        'api_keys',
        'api_key_id',
        'id',
        'billing_domain_events_api_key_tenant_fk',
        'ON UPDATE CASCADE ON DELETE SET NULL (api_key_id)'
    );
}

function ensureBillingTenantIsolation(PDO $pdo): bool {
    $tablesReady = (bool)$pdo->query(
        "SELECT to_regclass('public.invoice_headers') IS NOT NULL
             AND to_regclass('public.invoice_details') IS NOT NULL
             AND to_regclass('public.billing_customers') IS NOT NULL
             AND to_regclass('public.clients') IS NOT NULL
             AND to_regclass('public.client_branches') IS NOT NULL
             AND to_regclass('public.branch_sequences') IS NOT NULL
             AND to_regclass('public.invoice_retry_settings') IS NOT NULL
             AND to_regclass('public.api_keys') IS NOT NULL"
    )->fetchColumn();
    if (!$tablesReady) {
        return false;
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS billing_domain_events (
            event_id text PRIMARY KEY,
            tenant_id text,
            event_name text NOT NULL,
            access_key text,
            client_id bigint,
            branch_id bigint,
            api_key_id bigint,
            payload jsonb NOT NULL DEFAULT '{}'::jsonb,
            context jsonb NOT NULL DEFAULT '{}'::jsonb,
            occurred_on timestamp without time zone NOT NULL,
            recorded_at timestamp without time zone DEFAULT NOW() NOT NULL
        )
        SQL
    );

    $pdo->exec('ALTER TABLE invoice_headers ADD COLUMN IF NOT EXISTS tenant_id text');
    $pdo->exec("UPDATE invoice_headers SET tenant_id = NULL WHERE tenant_id IS NOT NULL AND NULLIF(BTRIM(tenant_id), '') IS NULL");
    $pdo->exec(
        'UPDATE invoice_headers ih
         SET tenant_id = bc.tenant_id
         FROM billing_customers bc
         WHERE bc.id = ih.billing_customer_id
           AND NULLIF(BTRIM(ih.tenant_id), \'\') IS NULL
           AND NULLIF(BTRIM(bc.tenant_id), \'\') IS NOT NULL'
    );
    if (relationKind($pdo, 'Order') !== null) {
        $pdo->exec(
            'UPDATE invoice_headers ih
             SET tenant_id = o.tenant_id
             FROM "Order" o
             WHERE o.id = ih.source_reference
               AND NULLIF(BTRIM(ih.tenant_id), \'\') IS NULL
               AND NULLIF(BTRIM(o.tenant_id), \'\') IS NOT NULL'
        );
    }

    $legacyTenant = trim((string)envValue('BILLING_LEGACY_TENANT_ID', ''));
    if ($legacyTenant !== '') {
        $stmt = $pdo->prepare(
            'UPDATE invoice_headers SET tenant_id = :tenant_id
             WHERE NULLIF(BTRIM(tenant_id), \'\') IS NULL'
        );
        $stmt->execute(['tenant_id' => $legacyTenant]);
    }

    $unresolved = (int)$pdo->query(
        "SELECT COUNT(*) FROM invoice_headers WHERE NULLIF(BTRIM(tenant_id), '') IS NULL"
    )->fetchColumn();
    if ($unresolved !== 0) {
        throw new RuntimeException(sprintf(
            'Billing tenant backfill unresolved rows=%d; derive them through billing_customer_id/Order or set BILLING_LEGACY_TENANT_ID explicitly',
            $unresolved
        ));
    }

    // Retry policy is tenant-owned. The former global UNIQUE(ambiente)
    // allowed one API client to alter the recovery behavior of every tenant.
    $pdo->exec('ALTER TABLE billing_customers ALTER COLUMN tenant_id DROP DEFAULT');
    $pdo->exec('ALTER TABLE invoice_retry_settings ADD COLUMN IF NOT EXISTS tenant_id text');
    $pdo->exec(
        "UPDATE invoice_retry_settings
         SET tenant_id = NULL
         WHERE tenant_id IS NOT NULL AND NULLIF(BTRIM(tenant_id), '') IS NULL"
    );
    if ($legacyTenant !== '') {
        $statement = $pdo->prepare(
            "UPDATE invoice_retry_settings
             SET tenant_id = :tenant_id
             WHERE NULLIF(BTRIM(tenant_id), '') IS NULL"
        );
        $statement->execute(['tenant_id' => $legacyTenant]);
    }
    $unresolvedRetrySettings = (int)$pdo->query(
        "SELECT COUNT(*) FROM invoice_retry_settings WHERE NULLIF(BTRIM(tenant_id), '') IS NULL"
    )->fetchColumn();
    if ($unresolvedRetrySettings !== 0) {
        throw new RuntimeException(sprintf(
            'Billing retry settings tenant backfill unresolved rows=%d; set BILLING_LEGACY_TENANT_ID after validating ownership',
            $unresolvedRetrySettings
        ));
    }
    $pdo->exec('ALTER TABLE invoice_retry_settings DROP CONSTRAINT IF EXISTS invoice_retry_settings_ambiente_key');
    $pdo->exec('ALTER TABLE invoice_retry_settings ALTER COLUMN tenant_id SET NOT NULL');
    $pdo->exec('DROP INDEX IF EXISTS idx_invoice_retry_settings_ambiente');
    $pdo->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS invoice_retry_settings_tenant_environment_uidx
         ON invoice_retry_settings (tenant_id, ambiente)'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS invoice_retry_settings_tenant_active_idx
         ON invoice_retry_settings (tenant_id, is_active, ambiente)'
    );

    foreach (['clients', 'client_branches', 'api_keys'] as $billingTable) {
        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS tenant_id text', quoteIdent($billingTable)));
        $pdo->exec(sprintf(
            "UPDATE %s SET tenant_id = NULL WHERE tenant_id IS NOT NULL AND NULLIF(BTRIM(tenant_id), '') IS NULL",
            quoteIdent($billingTable)
        ));
    }
    $ambiguousClients = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM (
             SELECT client_id
             FROM invoice_headers
             GROUP BY client_id
             HAVING COUNT(DISTINCT tenant_id) > 1
         ) conflicts'
    )->fetchColumn();
    if ($ambiguousClients !== 0) {
        throw new RuntimeException(sprintf(
            'Billing tenant backfill found %d clients referenced by invoices from multiple tenants',
            $ambiguousClients
        ));
    }

    $clientInvoiceMismatches = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM clients client
         JOIN invoice_headers invoice ON invoice.client_id = client.id
         WHERE NULLIF(BTRIM(client.tenant_id), \'\') IS NOT NULL
           AND client.tenant_id <> invoice.tenant_id'
    )->fetchColumn();
    if ($clientInvoiceMismatches !== 0) {
        throw new RuntimeException(sprintf(
            'Billing contains %d preexisting client/invoice tenant conflicts',
            $clientInvoiceMismatches
        ));
    }

    $pdo->exec(
        'WITH client_tenants AS (
            SELECT client_id, MIN(tenant_id) AS tenant_id
            FROM invoice_headers
            GROUP BY client_id
            HAVING COUNT(DISTINCT tenant_id) = 1
         )
         UPDATE clients c SET tenant_id = ct.tenant_id
         FROM client_tenants ct
         WHERE ct.client_id = c.id
           AND NULLIF(BTRIM(c.tenant_id), \'\') IS NULL'
    );
    $pdo->exec(
        'UPDATE client_branches b SET tenant_id = c.tenant_id
         FROM clients c
         WHERE c.id = b.client_id
           AND NULLIF(BTRIM(b.tenant_id), \'\') IS NULL'
    );
    $pdo->exec(
        'UPDATE api_keys ak SET tenant_id = c.tenant_id
         FROM clients c
         WHERE c.id = ak.client_id
           AND NULLIF(BTRIM(ak.tenant_id), \'\') IS NULL'
    );

    foreach ([
        ['child' => 'client_branches', 'parent' => 'clients', 'column' => 'client_id'],
        ['child' => 'api_keys', 'parent' => 'clients', 'column' => 'client_id'],
    ] as $relationship) {
        $mismatch = (int)$pdo->query(sprintf(
            "SELECT COUNT(*)
             FROM %s child
             JOIN %s parent ON parent.id = child.%s
             WHERE NULLIF(BTRIM(child.tenant_id), '') IS NOT NULL
               AND NULLIF(BTRIM(parent.tenant_id), '') IS NOT NULL
               AND child.tenant_id <> parent.tenant_id",
            quoteIdent($relationship['child']),
            quoteIdent($relationship['parent']),
            quoteIdent($relationship['column'])
        ))->fetchColumn();
        if ($mismatch !== 0) {
            throw new RuntimeException(sprintf(
                'Billing contains %d preexisting %s/%s tenant conflicts',
                $mismatch,
                $relationship['child'],
                $relationship['parent']
            ));
        }
    }

    if ($legacyTenant !== '') {
        foreach (['clients', 'client_branches', 'api_keys'] as $billingTable) {
            $stmt = $pdo->prepare(sprintf(
                'UPDATE %s SET tenant_id = :tenant_id WHERE NULLIF(BTRIM(tenant_id), \'\') IS NULL',
                quoteIdent($billingTable)
            ));
            $stmt->execute(['tenant_id' => $legacyTenant]);
        }
    }

    $postFallbackMismatches = (int)$pdo->query(
        'SELECT
            (SELECT COUNT(*) FROM clients client JOIN invoice_headers invoice ON invoice.client_id = client.id WHERE client.tenant_id <> invoice.tenant_id)
          + (SELECT COUNT(*) FROM client_branches branch JOIN clients client ON client.id = branch.client_id WHERE branch.tenant_id <> client.tenant_id)
          + (SELECT COUNT(*) FROM api_keys api_key JOIN clients client ON client.id = api_key.client_id WHERE api_key.tenant_id <> client.tenant_id)'
    )->fetchColumn();
    if ($postFallbackMismatches !== 0) {
        throw new RuntimeException(sprintf(
            'Billing tenant fallback would create %d parent/child conflicts; resolve ownership explicitly',
            $postFallbackMismatches
        ));
    }

    foreach (['clients', 'client_branches', 'api_keys'] as $billingTable) {
        $missing = (int)$pdo->query(sprintf(
            "SELECT COUNT(*) FROM %s WHERE NULLIF(BTRIM(tenant_id), '') IS NULL",
            quoteIdent($billingTable)
        ))->fetchColumn();
        if ($missing !== 0) {
            throw new RuntimeException(sprintf(
                'Billing tenant backfill unresolved table=%s rows=%d; set BILLING_LEGACY_TENANT_ID only after validating ownership',
                $billingTable,
                $missing
            ));
        }
        $pdo->exec(sprintf('ALTER TABLE %s ALTER COLUMN tenant_id SET NOT NULL', quoteIdent($billingTable)));
    }

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS clients_tenant_id_id_uidx ON clients (tenant_id, id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS client_branches_tenant_id_id_uidx ON client_branches (tenant_id, id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS api_keys_tenant_id_id_uidx ON api_keys (tenant_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS api_keys_tenant_key_hash_idx ON api_keys (tenant_id, key_hash)');
    $pdo->exec(<<<'SQL'
        DO $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'client_branches_client_tenant_fk') THEN
                ALTER TABLE client_branches ADD CONSTRAINT client_branches_client_tenant_fk
                FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id) NOT VALID;
            END IF;
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'api_keys_client_tenant_fk') THEN
                ALTER TABLE api_keys ADD CONSTRAINT api_keys_client_tenant_fk
                FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id) NOT VALID;
            END IF;
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'invoice_headers_client_tenant_fk') THEN
                ALTER TABLE invoice_headers ADD CONSTRAINT invoice_headers_client_tenant_fk
                FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id) NOT VALID;
            END IF;
            IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'invoice_headers_branch_tenant_fk') THEN
                ALTER TABLE invoice_headers ADD CONSTRAINT invoice_headers_branch_tenant_fk
                FOREIGN KEY (tenant_id, branch_id) REFERENCES client_branches (tenant_id, id) NOT VALID;
            END IF;
        END
        $$
        SQL
    );
    foreach ([
        'client_branches_client_tenant_fk',
        'api_keys_client_tenant_fk',
        'invoice_headers_client_tenant_fk',
        'invoice_headers_branch_tenant_fk',
    ] as $constraint) {
        $pdo->exec('ALTER TABLE ' . match ($constraint) {
            'client_branches_client_tenant_fk' => 'client_branches',
            'api_keys_client_tenant_fk' => 'api_keys',
            default => 'invoice_headers',
        } . ' VALIDATE CONSTRAINT ' . quoteIdent($constraint));
    }

    $pdo->exec('ALTER TABLE invoice_headers DROP CONSTRAINT IF EXISTS invoice_headers_tenant_nonblank_check');
    $pdo->exec("ALTER TABLE invoice_headers ADD CONSTRAINT invoice_headers_tenant_nonblank_check CHECK (NULLIF(BTRIM(tenant_id), '') IS NOT NULL)");
    $pdo->exec('ALTER TABLE invoice_headers ALTER COLUMN tenant_id SET NOT NULL');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS billing_customers_tenant_id_id_uidx ON billing_customers (tenant_id, id)');
    $pdo->exec(<<<'SQL'
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint
                WHERE conname = 'invoice_headers_billing_customer_tenant_fk'
                  AND conrelid = 'invoice_headers'::regclass
            ) THEN
                ALTER TABLE invoice_headers
                ADD CONSTRAINT invoice_headers_billing_customer_tenant_fk
                FOREIGN KEY (tenant_id, billing_customer_id)
                REFERENCES billing_customers (tenant_id, id)
                NOT VALID;
            END IF;
        END
        $$
        SQL
    );
    $pdo->exec('ALTER TABLE invoice_headers VALIDATE CONSTRAINT invoice_headers_billing_customer_tenant_fk');
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS invoice_headers_tenant_source_reference_idx
         ON invoice_headers (tenant_id, source_reference)'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS invoice_headers_tenant_client_access_idx
         ON invoice_headers (tenant_id, client_id, access_key)'
    );
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS invoice_headers_tenant_id_id_uidx ON invoice_headers (tenant_id, id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS invoice_headers_tenant_access_key_uidx ON invoice_headers (tenant_id, access_key)');

    ensureTenantChildIsolation($pdo, 'invoice_details', 'invoice_headers', 'invoice_header_id', [
        'legacy_env' => 'BILLING_LEGACY_TENANT_ID',
        'parent_unique_index' => 'invoice_headers_tenant_id_id_uidx',
        'child_unique_index' => 'invoice_details_tenant_id_id_uidx',
        'child_parent_index' => 'invoice_details_tenant_header_idx',
        'constraint_name' => 'invoice_details_header_tenant_fk',
        'actions' => 'ON UPDATE CASCADE ON DELETE CASCADE',
    ]);
    ensureTenantChildIsolation($pdo, 'branch_sequences', 'client_branches', 'branch_id', [
        'legacy_env' => 'BILLING_LEGACY_TENANT_ID',
        'parent_unique_index' => 'client_branches_tenant_id_id_uidx',
        'child_unique_index' => 'branch_sequences_tenant_branch_environment_uidx',
        'child_unique_columns' => ['tenant_id', 'branch_id', 'ambiente'],
        'child_parent_index' => 'branch_sequences_tenant_branch_idx',
        'constraint_name' => 'branch_sequences_branch_tenant_fk',
        'actions' => 'ON UPDATE CASCADE ON DELETE CASCADE',
    ]);
    ensureBillingDomainEventTenantIsolation($pdo, $legacyTenant);

    return true;
}

function ensureFidepuntosAdjustPermission(PDO $pdo): void {
    $tablesReady = (bool)$pdo->query(
        "SELECT to_regclass('public.tenant_roles') IS NOT NULL
             AND to_regclass('public.tenant_role_navigation_grants') IS NOT NULL"
    )->fetchColumn();
    if (!$tablesReady) {
        return;
    }

    $pdo->exec(
        "INSERT INTO tenant_role_navigation_grants
            (tenant_id, role_id, menu_option_key, action_key, assigned_by_user_id, granted_at, updated_at)
         SELECT 'fidepuntos', 'fidepuntos_admin', 'loyalty.customers', 'adjust_points', NULL, NOW(), NOW()
         FROM tenant_roles
         WHERE tenant_id = 'fidepuntos' AND role_id = 'fidepuntos_admin'
         ON CONFLICT (tenant_id, role_id, menu_option_key, action_key)
         DO UPDATE SET updated_at = NOW()"
    );
}

function dropRemoteSchema(PDO $pdo, string $schema): void {
    $pdo->exec('DROP SCHEMA IF EXISTS ' . quoteIdent($schema) . ' CASCADE');
    $pdo->exec('CREATE SCHEMA ' . quoteIdent($schema));
}

/**
 * Loads the mapping input through PostgreSQL's extended query protocol.
 *
 * The password must never become part of a SQL string. PDO native prepares
 * keep it out of the statement text exposed through pg_stat_activity and out
 * of any utility-command error generated while the mapping is created.
 *
 * @param array{username:mixed,password:mixed} $mappingConfig
 */
function setFdwBootstrapGucs(PDO $pdo, string $serverName, array $mappingConfig, bool $transactionLocal): void {
    $scope = $transactionLocal ? 'true' : 'false';
    $statement = $pdo->prepare(sprintf(<<<'SQL'
        SELECT
            set_config('paramascotasec.bootstrap_fdw_server', :mapping_server, %1$s),
            set_config('paramascotasec.bootstrap_fdw_username', :mapping_username, %1$s),
            set_config('paramascotasec.bootstrap_fdw_password', :mapping_password, %1$s),
            set_config('paramascotasec.bootstrap_fdw_status', 'pending', %1$s),
            set_config('paramascotasec.bootstrap_fdw_scope', %2$s, %1$s)
        SQL, $scope, $pdo->quote($transactionLocal ? 'local' : 'session')));
    $statement->bindValue(':mapping_server', $serverName, PDO::PARAM_STR);
    $statement->bindValue(':mapping_username', (string)$mappingConfig['username'], PDO::PARAM_STR);
    $statement->bindValue(':mapping_password', (string)$mappingConfig['password'], PDO::PARAM_STR);
    $statement->execute();
    $statement->closeCursor();
}

/**
 * Removes every bootstrap value from the current database session.
 * Empty custom GUC values are equivalent to an unset placeholder and, most
 * importantly, cannot retain any credential after this call.
 */
function clearFdwBootstrapGucs(PDO $pdo, bool $transactionLocal = false): void {
    $scope = $transactionLocal ? 'true' : 'false';
    $statement = $pdo->prepare(sprintf(<<<'SQL'
        SELECT
            set_config('paramascotasec.bootstrap_fdw_server', '', %1$s),
            set_config('paramascotasec.bootstrap_fdw_username', '', %1$s),
            set_config('paramascotasec.bootstrap_fdw_password', '', %1$s),
            set_config('paramascotasec.bootstrap_fdw_status', '', %1$s),
            set_config('paramascotasec.bootstrap_fdw_scope', '', %1$s)
        SQL, $scope));
    $statement->execute();
    $statement->closeCursor();
}

/** @return array{statement:string,error:string} */
function suspendFdwBootstrapParameterLogging(PDO $pdo, bool $transactionLocal): array {
    $settings = $pdo->query(<<<'SQL'
        SELECT
            MAX(setting) FILTER (WHERE name = 'log_parameter_max_length') AS statement_setting,
            MAX(setting) FILTER (WHERE name = 'log_parameter_max_length_on_error') AS error_setting
        FROM pg_settings
        WHERE name IN ('log_parameter_max_length', 'log_parameter_max_length_on_error')
        SQL)->fetch(PDO::FETCH_ASSOC);
    if (!is_array($settings)
        || preg_match('/^-?\d+$/', (string)($settings['statement_setting'] ?? '')) !== 1
        || preg_match('/^-?\d+$/', (string)($settings['error_setting'] ?? '')) !== 1) {
        throw new RuntimeException('Unable to secure FDW bootstrap parameter logging.');
    }

    // PostgreSQL 18 exposes log_parameter_max_length as SUSET and
    // log_parameter_max_length_on_error as USERSET. Consequently this fixed
    // statement is also a fail-closed privilege gate: the bootstrap DB_ADMIN
    // session must be allowed to set the SUSET parameter before any password
    // is sent through the extended protocol.
    $scope = $transactionLocal ? 'true' : 'false';
    $pdo->exec(sprintf(<<<'SQL'
        SELECT
            set_config('log_parameter_max_length', '0', %1$s),
            set_config('log_parameter_max_length_on_error', '0', %1$s)
        SQL, $scope));

    return [
        'statement' => (string)$settings['statement_setting'],
        'error' => (string)$settings['error_setting'],
    ];
}

/** @param array{statement:string,error:string} $settings */
function restoreFdwBootstrapParameterLogging(PDO $pdo, array $settings, bool $transactionLocal): void {
    $scope = $transactionLocal ? 'true' : 'false';
    $statement = $pdo->prepare(sprintf(<<<'SQL'
        SELECT
            set_config('log_parameter_max_length', :statement_setting, %1$s),
            set_config('log_parameter_max_length_on_error', :error_setting, %1$s)
        SQL, $scope));
    $statement->bindValue(':statement_setting', $settings['statement'], PDO::PARAM_STR);
    $statement->bindValue(':error_setting', $settings['error'], PDO::PARAM_STR);
    $statement->execute();
    $statement->closeCursor();
}

function createCurrentUserFdwMapping(PDO $pdo, string $serverName, array $mappingConfig): void {
    $transactionLocal = $pdo->inTransaction();

    $mappingSucceeded = false;
    $mappingStatus = '';
    $parameterLogSettings = null;
    try {
        // Remove stale placeholders before loading a new value. This statement
        // is fixed SQL and contains no credential.
        clearFdwBootstrapGucs($pdo, $transactionLocal);
        $parameterLogSettings = suspendFdwBootstrapParameterLogging($pdo, $transactionLocal);
        setFdwBootstrapGucs($pdo, $serverName, $mappingConfig, $transactionLocal);
        $inputState = $pdo->query(<<<'SQL'
            SELECT
                NULLIF(current_setting('paramascotasec.bootstrap_fdw_server', true), '') IS NOT NULL AS has_server,
                NULLIF(current_setting('paramascotasec.bootstrap_fdw_username', true), '') IS NOT NULL AS has_username,
                NULLIF(current_setting('paramascotasec.bootstrap_fdw_password', true), '') IS NOT NULL AS has_password,
                current_setting('paramascotasec.bootstrap_fdw_scope', true) AS scope
            SQL
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($inputState)
            || !filter_var($inputState['has_server'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || !filter_var($inputState['has_username'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || !filter_var($inputState['has_password'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || ($inputState['scope'] ?? null) !== ($transactionLocal ? 'local' : 'session')) {
            error_log(sprintf(
                '[FDW_MAPPING_INPUT_INVALID] server=%d username=%d password=%d scope=%s',
                filter_var($inputState['has_server'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                filter_var($inputState['has_username'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                filter_var($inputState['has_password'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                (($inputState['scope'] ?? null) === 'local' ? 'local' : 'invalid')
            ));
            throw new RuntimeException('FDW mapping input state is incomplete.');
        }

        // Utility statements cannot consume bind parameters directly. The DO
        // block reads session-local placeholders and builds the utility command
        // only inside PostgreSQL. Every dynamic error is swallowed inside the
        // block, the credential GUCs are cleared, and PHP receives only a
        // non-sensitive status marker.
        $pdo->exec(<<<'SQL'
            DO $paramascotasec_fdw_mapping$
            DECLARE
                mapping_server text := NULLIF(current_setting('paramascotasec.bootstrap_fdw_server', true), '');
                mapping_username text := NULLIF(current_setting('paramascotasec.bootstrap_fdw_username', true), '');
                mapping_password text := NULLIF(current_setting('paramascotasec.bootstrap_fdw_password', true), '');
                transaction_local boolean := current_setting('paramascotasec.bootstrap_fdw_scope', true) = 'local';
                mapping_created boolean := false;
                mapping_error_code text := '';
            BEGIN
                BEGIN
                    IF mapping_server IS NULL THEN
                        RAISE EXCEPTION USING ERRCODE = '22001', MESSAGE = 'FDW server input is unavailable';
                    END IF;
                    IF mapping_username IS NULL THEN
                        RAISE EXCEPTION USING ERRCODE = '22002', MESSAGE = 'FDW username input is unavailable';
                    END IF;
                    IF mapping_password IS NULL THEN
                        RAISE EXCEPTION USING ERRCODE = '22003', MESSAGE = 'FDW password input is unavailable';
                    END IF;

                    EXECUTE format(
                        'CREATE USER MAPPING FOR CURRENT_USER SERVER %I OPTIONS (user %L, password %L)',
                        mapping_server,
                        mapping_username,
                        mapping_password
                    );
                    mapping_created := true;
                EXCEPTION
                    WHEN query_canceled THEN mapping_error_code := '57014';
                    WHEN assert_failure THEN mapping_error_code := 'P0004';
                    WHEN OTHERS THEN
                        GET STACKED DIAGNOSTICS mapping_error_code = RETURNED_SQLSTATE;
                END;

                PERFORM set_config('paramascotasec.bootstrap_fdw_server', '', transaction_local);
                PERFORM set_config('paramascotasec.bootstrap_fdw_username', '', transaction_local);
                PERFORM set_config('paramascotasec.bootstrap_fdw_password', '', transaction_local);
                mapping_server := NULL;
                mapping_username := NULL;
                mapping_password := NULL;
                PERFORM set_config(
                    'paramascotasec.bootstrap_fdw_status',
                    CASE WHEN mapping_created THEN 'ok' ELSE 'error:' || COALESCE(NULLIF(mapping_error_code, ''), 'unknown') END,
                    transaction_local
                );
            END
            $paramascotasec_fdw_mapping$
            SQL);

        $statusStatement = $pdo->prepare(
            "SELECT NULLIF(current_setting('paramascotasec.bootstrap_fdw_status', true), '')"
        );
        $statusStatement->execute();
        $mappingStatus = (string)$statusStatement->fetchColumn();
        $mappingSucceeded = $mappingStatus === 'ok';
        $statusStatement->closeCursor();
    } catch (Throwable) {
        // Do not chain the database exception: a driver/server detail must not
        // become a second path for exposing mapping input.
        $mappingSucceeded = false;
    } finally {
        try {
            clearFdwBootstrapGucs($pdo, $transactionLocal);
        } catch (Throwable) {
            // The public failure below remains deliberately generic. In
            // autocommit mode a failed statement is already rolled back; a
            // broken connection cannot be reused and therefore cannot expose
            // the session-local placeholder.
            $mappingSucceeded = false;
        }
        if (is_array($parameterLogSettings)) {
            try {
                restoreFdwBootstrapParameterLogging($pdo, $parameterLogSettings, $transactionLocal);
            } catch (Throwable) {
                $mappingSucceeded = false;
            }
        }
    }

    if (!$mappingSucceeded) {
        if (preg_match('/^error:([A-Z0-9]{5}|unknown)$/', $mappingStatus, $match) === 1) {
            error_log('[FDW_MAPPING_FAILED] sqlstate=' . $match[1]);
        }
        throw new RuntimeException('Unable to create FDW user mapping.');
    }
}

function createFdwServer(PDO $pdo, string $serverName, array $targetConfig): void {
    $mappingConfig = fdwMappingConfig($targetConfig);
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
    createCurrentUserFdwMapping($pdo, $serverName, $mappingConfig);
}

function fdwMappingConfig(array $targetConfig): array {
    if (strtolower(trim((string)envValue('TENANT_RLS_MODE', 'off'))) !== 'enforce') {
        return $targetConfig;
    }

    $username = trim((string)envValue('DB_FDW_USERNAME', ''));
    $password = trim((string)envValue('DB_FDW_PASSWORD', ''));
    if ($username === '' || $password === '' || $username === (string)($targetConfig['username'] ?? '')) {
        throw new RuntimeException('RLS enforce requires a dedicated DB_FDW_USERNAME/DB_FDW_PASSWORD.');
    }

    return array_merge($targetConfig, ['username' => $username, 'password' => $password]);
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

function migrationProjectionStats(PDO $pdo, string $schema, string $table, array $columns): array {
    $columnList = implode(', ', array_map(static fn(string $column): string => quoteIdent($column), $columns));
    $sql = sprintf(
        'SELECT COUNT(*)::bigint AS row_count,
                md5(COALESCE(string_agg(row_hash, \'\' ORDER BY row_hash), \'\')) AS fingerprint
         FROM (
             SELECT md5(to_jsonb(projected)::text) AS row_hash
             FROM (SELECT %3$s FROM %1$s.%2$s) projected
         ) hashed',
        quoteIdent($schema),
        quoteIdent($table),
        $columnList
    );
    $stats = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    return [
        'count' => (int)($stats['row_count'] ?? 0),
        'fingerprint' => (string)($stats['fingerprint'] ?? md5('')),
    ];
}

function ensureMigrationReceiptTable(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS module_migration_receipts (
            table_name text PRIMARY KEY,
            source_row_count bigint NOT NULL,
            target_row_count bigint NOT NULL,
            source_fingerprint text NOT NULL,
            target_fingerprint text NOT NULL,
            status text NOT NULL CHECK (status = 'reconciled'),
            updated_at timestamp without time zone NOT NULL DEFAULT clock_timestamp()
        )
        SQL
    );
    foreach (['DB_USERNAME', 'DB_WORKER_USERNAME', 'DB_FDW_USERNAME'] as $roleKey) {
        $role = trim((string)envValue($roleKey, ''));
        if ($role === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $role)) {
            continue;
        }
        $exists = $pdo->query('SELECT to_regrole(' . $pdo->quote($role) . ') IS NOT NULL')->fetchColumn();
        if ($exists) {
            $pdo->exec('REVOKE ALL PRIVILEGES ON module_migration_receipts FROM ' . quoteIdent($role));
        }
    }
    $pdo->exec('REVOKE ALL PRIVILEGES ON module_migration_receipts FROM PUBLIC');
}

function copyOwnedTablesFromLegacy(PDO $pdo, array $ownerTables): void {
    ensureMigrationReceiptTable($pdo);
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
        $sourceStats = migrationProjectionStats($pdo, 'legacy_source', $table, $columns);
        $pdo->exec(sprintf(
            'INSERT INTO public.%1$s (%2$s) SELECT %2$s FROM legacy_source.%1$s ON CONFLICT DO NOTHING',
            quoteIdent($table),
            $columnList
        ));
        $missingRows = (int)$pdo->query(sprintf(
            'SELECT COUNT(*) FROM (
                SELECT to_jsonb(source_row) AS payload
                FROM (SELECT %3$s FROM legacy_source.%1$s) source_row
                EXCEPT ALL
                SELECT to_jsonb(target_row) AS payload
                FROM (SELECT %3$s FROM public.%1$s) target_row
            ) missing',
            quoteIdent($table),
            quoteIdent($table),
            $columnList
        ))->fetchColumn();
        if ($missingRows !== 0) {
            throw new RuntimeException(sprintf(
                'Legacy migration reconciliation failed table=%s missing_rows=%d',
                $table,
                $missingRows
            ));
        }
        $targetStats = migrationProjectionStats($pdo, 'public', $table, $columns);
        $receipt = $pdo->prepare(<<<'SQL'
            INSERT INTO module_migration_receipts (
                table_name, source_row_count, target_row_count,
                source_fingerprint, target_fingerprint, status, updated_at
            ) VALUES (
                :table_name, :source_row_count, :target_row_count,
                :source_fingerprint, :target_fingerprint, 'reconciled', clock_timestamp()
            )
            ON CONFLICT (table_name) DO UPDATE
            SET source_row_count = EXCLUDED.source_row_count,
                target_row_count = EXCLUDED.target_row_count,
                source_fingerprint = EXCLUDED.source_fingerprint,
                target_fingerprint = EXCLUDED.target_fingerprint,
                status = EXCLUDED.status,
                updated_at = EXCLUDED.updated_at
            SQL
        );
        $receipt->execute([
            'table_name' => $table,
            'source_row_count' => $sourceStats['count'],
            'target_row_count' => $targetStats['count'],
            'source_fingerprint' => $sourceStats['fingerprint'],
            'target_fingerprint' => $targetStats['fingerprint'],
        ]);
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

function physicalLocalTables(PDO $pdo, array $tables): array {
    return array_values(array_filter(
        $tables,
        static fn (string $table): bool => in_array(relationKind($pdo, $table), ['r', 'p'], true)
    ));
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

function replaceNonOwnedTablesWithForeignTables(PDO $pdo, array $localModules, array $targets): void {
    $owners = tableOwnerMap();
    $localTables = [];
    foreach ($localModules as $moduleKey) {
        $localTables = array_merge($localTables, MODULE_TABLES[$moduleKey] ?? []);
    }
    $localTables = array_values(array_unique($localTables));
    $remoteByModule = [];

    foreach (LEGACY_TABLES as $table) {
        if (in_array($table, $localTables, true)) {
            continue;
        }
        if (in_array($table, LOCAL_AUTH_ONLY_TABLES, true)) {
            dropLocalTableOrForeignTable($pdo, $table);
            continue;
        }
        $owner = $owners[$table] ?? null;
        if (!is_string($owner) || in_array($owner, $localModules, true) || !isset($targets[$owner])) {
            continue;
        }
        $remoteByModule[$owner][] = $table;
        dropLocalTableOrForeignTable($pdo, $table);
    }

    foreach ($remoteByModule as $ownerModule => $tables) {
        $server = 'fdw_' . str_replace('-', '_', $ownerModule);
        createFdwServer($pdo, $server, $targets[$ownerModule]);
        importForeignTables($pdo, $server, 'public', $tables);
        foreach ($tables as $table) {
            if (relationKind($pdo, $table) !== 'f') {
                throw new RuntimeException(sprintf(
                    'FDW compatibility table %s from %s was not imported',
                    $table,
                    $ownerModule
                ));
            }
        }
    }
}

function runModuleDatabaseBootstrap(): int {
    $runtimeConfig = [
        'host' => envValue('DB_HOST', 'db'),
        'port' => envValue('DB_PORT', '5432'),
        'database' => envValue('DB_DATABASE', 'ecommerce'),
        'username' => envValue('DB_USERNAME', 'postgres'),
        'password' => envValue('DB_PASSWORD', 'postgres'),
    ];
    $defaultTenant = envValue('DEFAULT_TENANT', 'paramascotasec') ?? 'paramascotasec';
    $configuredTenantsPath = __DIR__ . '/../config/tenants.php';
    $configuredTenants = is_readable($configuredTenantsPath) ? require $configuredTenantsPath : [];
    $tenantRows = configuredTenantRows(is_array($configuredTenants) ? $configuredTenants : [], (string)$defaultTenant);
    $targets = moduleTargets($runtimeConfig);
    $targetGroups = moduleTargetGroups($targets);
    $primaryRuntimeConfig = normalizeConfig($runtimeConfig);
    $adminConfig = adminConnectionConfig($runtimeConfig);
    if (strtolower(trim((string)envValue('TENANT_RLS_MODE', 'off'))) === 'enforce') {
        $adminUsername = trim((string)envValue('DB_ADMIN_USERNAME', ''));
        $adminPassword = trim((string)envValue('DB_ADMIN_PASSWORD', ''));
        if ($adminUsername === '' || $adminPassword === '' || $adminUsername === (string)$runtimeConfig['username']) {
            fwrite(STDERR, "[module-db] RLS enforce requires a DB admin distinct from the API role\n");
            return 1;
        }
    }

    if ($targetGroups === []) {
        fwrite(STDOUT, "[module-db] no module targets configured\n");
        return 0;
    }

    try {
        $primarySourcePdo = connect(connectionTargetConfig($primaryRuntimeConfig, $adminConfig));
        // A compatibility foreign table is a pointer, never migration source
        // data. Copy only physical source relations from the original primary
        // DB; existing owner-local targets remain authoritative on reruns.
        $physicalLegacyTables = physicalLocalTables($primarySourcePdo, LEGACY_TABLES);
        foreach ($targetGroups as $databaseName => $group) {
            $target = $group['target'];
            $modules = $group['modules'];
            $tables = $group['tables'];
            ensureTargetDatabase($target, $adminConfig);
            $pdo = connect(connectionTargetConfig($target, $adminConfig));
            $pdo->beginTransaction();
            try {
                dropForeignLegacyTables($pdo);
                dropForeignOwnerTables($pdo, $tables);
                executeSchemaBootstrap($pdo, $defaultTenant, ['skip_constraints' => MODULE_SKIPPED_CONSTRAINTS]);
                if (in_array('identity-platform', $modules, true)) {
                    syncConfiguredTenantRows($pdo, $tenantRows);
                }
                if (in_array('mailer-service', $modules, true)) {
                    createMailerTables($pdo);
                }
                if (in_array('loyalty-rewards', $modules, true)) {
                    createLoyaltyTables($pdo);
                }
                if (in_array(BillingDomain::KEY, $modules, true)) {
                    createBillingTables($pdo);
                }
                if (in_array('identity-platform', $modules, true)) {
                    ensureFidepuntosAdjustPermission($pdo);
                }
                grantRuntimeSchemaAccess($pdo, (string)($target['username'] ?? ''));
                grantFdwSourceReadAccess($pdo);
                dropKnownCrossDomainConstraints($pdo);
                if ((string)$target['database'] !== (string)$primaryRuntimeConfig['database']) {
                    $copyableTables = array_values(array_intersect($tables, $physicalLegacyTables));
                    if ($copyableTables !== []) {
                        createFdwServer($pdo, 'origen_ecommerce', $primaryRuntimeConfig);
                        dropRemoteSchema($pdo, 'legacy_source');
                        importForeignTables($pdo, 'origen_ecommerce', 'legacy_source', $copyableTables);
                        copyOwnedTablesFromLegacy($pdo, $copyableTables);
                        $pdo->exec('DROP SCHEMA IF EXISTS legacy_source CASCADE');
                    }
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            fwrite(STDOUT, sprintf(
                "[module-db] prepared owner tables modules=%s db=%s\n",
                implode(',', $modules),
                $databaseName
            ));
        }

        foreach ($targetGroups as $databaseName => $group) {
            $target = $group['target'];
            $modules = $group['modules'];
            ensureTargetDatabase($target, $adminConfig);
            $pdo = connect(connectionTargetConfig($target, $adminConfig));
            $pdo->beginTransaction();
            try {
                replaceNonOwnedTablesWithForeignTables($pdo, $modules, $targets);
                if (in_array(BillingDomain::KEY, $modules, true)) {
                    if (!ensureBillingTenantIsolation($pdo)) {
                        throw new RuntimeException('Billing tenant isolation could not be applied: fiscal tables unavailable');
                    }
                    $secretMigration = migrateBillingSecrets($pdo);
                    fwrite(STDOUT, sprintf(
                        "[module-db] prepared Billing tenant isolation and encrypted secrets db=%s rows=%d migrated=%d rotated=%d verified=%d key_id=%s\n",
                        $databaseName,
                        $secretMigration['rows'],
                        $secretMigration['migrated'],
                        $secretMigration['rotated'],
                        $secretMigration['verified'],
                        $secretMigration['key_id']
                    ));
                }
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
                foreach ($modules as $moduleKey) {
                    $stmt->execute([
                        'module_key' => $moduleKey,
                        'database_name' => (string)$target['database'],
                        'ownership_mode' => 'service-db-with-fdw-compat',
                    ]);
                }
                grantRuntimeSchemaAccess($pdo, (string)($target['username'] ?? ''));
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            fwrite(STDOUT, sprintf(
                "[module-db] linked compatibility tables modules=%s db=%s\n",
                implode(',', $modules),
                $databaseName
            ));
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[module-db] error: ' . $e->getMessage() . PHP_EOL);
        return 1;
    }

    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runModuleDatabaseBootstrap());
}
