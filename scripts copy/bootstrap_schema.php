<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    $dotenv = Dotenv::createImmutable($envDir);
    $dotenv->load();
}

function envValue(string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    $value = trim((string)$value);
    return $value === '' ? $default : $value;
}

function normalizeConfig(array $base, array $override = []): array {
    return [
        'host' => (string)($override['host'] ?? $base['host']),
        'port' => (string)($override['port'] ?? $base['port']),
        'database' => (string)($override['database'] ?? $base['database']),
        'username' => (string)($override['username'] ?? $base['username']),
        'password' => (string)($override['password'] ?? $base['password']),
    ];
}

function connect(array $config): PDO {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $config['host'],
        $config['port'],
        $config['database']
    );
    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function assertLegacySchemaBootstrapTopology(PDO $pdo): void {
    $foreignTables = $pdo->query(
        "SELECT string_agg(c.relname, ',' ORDER BY c.relname)
         FROM pg_class c
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public'
           AND c.relkind = 'f'"
    )->fetchColumn();
    if (is_string($foreignTables) && $foreignTables !== '') {
        throw new RuntimeException(sprintf(
            'Legacy schema bootstrap refuses modular foreign tables (%s); use scripts/bootstrap_module_databases.php.',
            $foreignTables
        ));
    }
}

function schemaQuoteIdentifier(string $identifier): string {
    return '"' . str_replace('"', '""', $identifier) . '"';
}

/**
 * Adds and validates the tenant half of an existing parent/child relationship.
 * Legacy rows are derived from the authoritative parent first. A fallback is
 * allowed only through the explicitly named environment variable.
 */
function ensureTenantChildIsolation(
    PDO $pdo,
    string $childTable,
    string $parentTable,
    string $parentColumn,
    array $options
): void {
    $child = schemaQuoteIdentifier($childTable);
    $parent = schemaQuoteIdentifier($parentTable);
    $parentKey = schemaQuoteIdentifier($parentColumn);
    $legacyEnv = trim((string)($options['legacy_env'] ?? ''));
    $legacyTenant = $legacyEnv !== '' ? trim((string)envValue($legacyEnv, '')) : '';

    $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS tenant_id text', $child));
    $pdo->exec(sprintf(
        "UPDATE %s SET tenant_id = NULL WHERE tenant_id IS NOT NULL AND NULLIF(BTRIM(tenant_id), '') IS NULL",
        $child
    ));

    $mismatch = (int)$pdo->query(sprintf(
        "SELECT COUNT(*)
         FROM %s child
         JOIN %s parent ON parent.id = child.%s
         WHERE NULLIF(BTRIM(child.tenant_id), '') IS NOT NULL
           AND NULLIF(BTRIM(parent.tenant_id), '') IS NOT NULL
           AND child.tenant_id <> parent.tenant_id",
        $child,
        $parent,
        $parentKey
    ))->fetchColumn();
    if ($mismatch !== 0) {
        throw new RuntimeException(sprintf(
            '%s tenant backfill found %d rows that conflict with authoritative parent %s',
            $childTable,
            $mismatch,
            $parentTable
        ));
    }

    $pdo->exec(sprintf(
        "UPDATE %s child
         SET tenant_id = parent.tenant_id
         FROM %s parent
         WHERE parent.id = child.%s
           AND NULLIF(BTRIM(child.tenant_id), '') IS NULL
           AND NULLIF(BTRIM(parent.tenant_id), '') IS NOT NULL",
        $child,
        $parent,
        $parentKey
    ));

    if ($legacyTenant !== '') {
        $statement = $pdo->prepare(sprintf(
            "UPDATE %s SET tenant_id = :tenant_id WHERE NULLIF(BTRIM(tenant_id), '') IS NULL",
            $child
        ));
        $statement->execute(['tenant_id' => $legacyTenant]);
    }

    $unresolved = (int)$pdo->query(sprintf(
        "SELECT COUNT(*) FROM %s WHERE NULLIF(BTRIM(tenant_id), '') IS NULL",
        $child
    ))->fetchColumn();
    if ($unresolved !== 0) {
        $fallbackMessage = $legacyEnv !== ''
            ? sprintf(' or set %s explicitly after validating ownership', $legacyEnv)
            : '';
        throw new RuntimeException(sprintf(
            '%s tenant backfill unresolved rows=%d; repair the parent relationship%s',
            $childTable,
            $unresolved,
            $fallbackMessage
        ));
    }

    $pdo->exec(sprintf('ALTER TABLE %s ALTER COLUMN tenant_id SET NOT NULL', $child));
    $pdo->exec(sprintf(
        'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (tenant_id, id)',
        schemaQuoteIdentifier((string)$options['parent_unique_index']),
        $parent
    ));
    $childUniqueColumns = array_map(
        static fn(string $column): string => schemaQuoteIdentifier($column),
        array_map('strval', $options['child_unique_columns'] ?? ['tenant_id', 'id'])
    );
    $pdo->exec(sprintf(
        'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s)',
        schemaQuoteIdentifier((string)$options['child_unique_index']),
        $child,
        implode(', ', $childUniqueColumns)
    ));
    $pdo->exec(sprintf(
        'CREATE INDEX IF NOT EXISTS %s ON %s (tenant_id, %s)',
        schemaQuoteIdentifier((string)$options['child_parent_index']),
        $child,
        $parentKey
    ));

    $constraintName = (string)$options['constraint_name'];
    $constraintExists = $pdo->prepare(
        "SELECT 1
         FROM pg_constraint constraint_info
         JOIN pg_class relation ON relation.oid = constraint_info.conrelid
         JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
         WHERE namespace.nspname = 'public'
           AND relation.relname = :table_name
           AND constraint_info.conname = :constraint_name
         LIMIT 1"
    );
    $constraintExists->execute([
        'table_name' => $childTable,
        'constraint_name' => $constraintName,
    ]);
    if (!$constraintExists->fetchColumn()) {
        $actions = trim((string)($options['actions'] ?? ''));
        $pdo->exec(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (tenant_id, %s) REFERENCES %s (tenant_id, id) %s NOT VALID',
            $child,
            schemaQuoteIdentifier($constraintName),
            $parentKey,
            $parent,
            $actions
        ));
    }
    $pdo->exec(sprintf(
        'ALTER TABLE %s VALIDATE CONSTRAINT %s',
        $child,
        schemaQuoteIdentifier($constraintName)
    ));
}

function assertTenantChildBackfillPreflight(
    PDO $pdo,
    string $childTable,
    string $parentTable,
    string $parentColumn,
    string $legacyEnv
): void {
    $relations = $pdo->prepare(
        "SELECT to_regclass(:child_relation) IS NOT NULL
            AND to_regclass(:parent_relation) IS NOT NULL"
    );
    $relations->execute([
        'child_relation' => 'public.' . schemaQuoteIdentifier($childTable),
        'parent_relation' => 'public.' . schemaQuoteIdentifier($parentTable),
    ]);
    if (!filter_var($relations->fetchColumn(), FILTER_VALIDATE_BOOLEAN)) {
        return;
    }

    $tenantColumn = $pdo->prepare(
        "SELECT 1
         FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = :table_name
           AND column_name = 'tenant_id'"
    );
    $tenantColumn->execute(['table_name' => $childTable]);
    $needsTenant = $tenantColumn->fetchColumn()
        ? "NULLIF(BTRIM(child.tenant_id), '') IS NULL"
        : 'TRUE';
    $unresolved = (int)$pdo->query(sprintf(
        'SELECT COUNT(*)
         FROM %s child
         LEFT JOIN %s parent ON parent.id = child.%s
         WHERE parent.id IS NULL
           AND %s',
        schemaQuoteIdentifier($childTable),
        schemaQuoteIdentifier($parentTable),
        schemaQuoteIdentifier($parentColumn),
        $needsTenant
    ))->fetchColumn();
    if ($unresolved !== 0 && trim((string)envValue($legacyEnv, '')) === '') {
        throw new RuntimeException(sprintf(
            '%s tenant preflight found orphan rows=%d; set %s explicitly after validating ownership',
            $childTable,
            $unresolved,
            $legacyEnv
        ));
    }
}

function executeSchemaBootstrap(PDO $pdo, string $defaultTenant, array $options = []): void {
    // This legacy schema routine owns local relations. Running its ALTER/DDL
    // list against compatibility foreign tables produces partial bootstraps
    // (PostgreSQL 42809). The modular orchestrator removes foreign relations
    // transactionally before calling this function for each owner database.
    assertLegacySchemaBootstrapTopology($pdo);
    assertTenantChildBackfillPreflight($pdo, 'Image', 'Product', 'product_id', 'ECOMMERCE_LEGACY_TENANT_ID');
    assertTenantChildBackfillPreflight($pdo, 'Variation', 'Product', 'product_id', 'ECOMMERCE_LEGACY_TENANT_ID');
    assertTenantChildBackfillPreflight($pdo, 'OrderItem', 'Order', 'order_id', 'ECOMMERCE_LEGACY_TENANT_ID');

    $statements = [
        'CREATE TABLE IF NOT EXISTS "Tenant" (id text PRIMARY KEY, name text, created_at timestamp without time zone DEFAULT NOW())',
        'CREATE TABLE IF NOT EXISTS "User" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            email text NOT NULL,
            name text,
            password text NOT NULL,
            created_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            email_verified boolean DEFAULT false NOT NULL,
            verification_token text,
            role text DEFAULT \'customer\' NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "Customer" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            email text NOT NULL,
            name text,
            password text NOT NULL,
            created_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            email_verified boolean DEFAULT false NOT NULL,
            verification_token text,
            role text DEFAULT \'customer\' NOT NULL,
            addresses jsonb,
            profile jsonb,
            document_type text,
            document_number text,
            business_name text,
            otp_code text,
            otp_expires_at timestamp,
            otp_attempts integer,
            failed_login_attempts integer,
            login_locked_until timestamp,
            last_login_at timestamp,
            active_token_id text
        )',
        'CREATE TABLE IF NOT EXISTS tenant_module_entitlements (
            tenant_id text NOT NULL,
            module_key text NOT NULL,
            status text NOT NULL DEFAULT \'active\',
            source text NOT NULL DEFAULT \'configured-tenant\',
            granted_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            PRIMARY KEY (tenant_id, module_key)
        )',
        'CREATE TABLE IF NOT EXISTS tenant_memberships (
            tenant_id text NOT NULL,
            user_id text NOT NULL,
            identity_type text NOT NULL DEFAULT \'customer\',
            status text NOT NULL DEFAULT \'active\',
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            PRIMARY KEY (tenant_id, user_id)
        )',
        'CREATE TABLE IF NOT EXISTS tenant_roles (
            tenant_id text NOT NULL,
            role_id text NOT NULL,
            name text NOT NULL,
            description text,
            permissions jsonb NOT NULL DEFAULT \'[]\'::jsonb,
            system_role boolean NOT NULL DEFAULT false,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            PRIMARY KEY (tenant_id, role_id)
        )',
        'CREATE TABLE IF NOT EXISTS tenant_user_roles (
            tenant_id text NOT NULL,
            user_id text NOT NULL,
            role_id text NOT NULL,
            assigned_at timestamp without time zone DEFAULT NOW() NOT NULL,
            PRIMARY KEY (tenant_id, user_id, role_id)
        )',
        'CREATE TABLE IF NOT EXISTS tenant_role_navigation_grants (
            tenant_id text NOT NULL,
            role_id text NOT NULL,
            menu_option_key text NOT NULL,
            action_key text NOT NULL,
            assigned_by_user_id text,
            granted_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            PRIMARY KEY (tenant_id, role_id, menu_option_key, action_key),
            CONSTRAINT tenant_role_navigation_grants_action_check
                CHECK (action_key IN (
                    \'view\', \'create\', \'update\', \'delete\', \'reverse\', \'approve\', \'deliver\',
                    \'cancel\', \'export\', \'assign_roles\', \'unlock\', \'invite\', \'revoke_sessions\',
                    \'adjust_points\'
                )),
            CONSTRAINT tenant_role_navigation_grants_role_fk
                FOREIGN KEY (tenant_id, role_id)
                REFERENCES tenant_roles (tenant_id, role_id)
                ON DELETE RESTRICT
        )',
        'CREATE TABLE IF NOT EXISTS tenant_access_audit_events (
            id bigserial PRIMARY KEY,
            tenant_id text NOT NULL,
            actor_user_id text,
            event_type text NOT NULL,
            target_type text NOT NULL,
            target_id text,
            metadata jsonb NOT NULL DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS tenant_user_sessions (
            tenant_id text NOT NULL,
            user_id text NOT NULL,
            session_id text NOT NULL,
            auth_surface text NOT NULL DEFAULT \'dashboard\',
            ip_address text,
            user_agent text,
            expires_at timestamp without time zone NOT NULL,
            revoked_at timestamp without time zone,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            last_seen_at timestamp without time zone DEFAULT NOW() NOT NULL,
            PRIMARY KEY (tenant_id, user_id, session_id),
            CONSTRAINT tenant_user_sessions_surface_check
                CHECK (auth_surface IN (\'dashboard\', \'ecommerce\'))
        )',
        'CREATE TABLE IF NOT EXISTS "Product" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            legacy_id text,
            category text NOT NULL,
            product_type text,
            name text NOT NULL,
            gender text,
            is_new boolean DEFAULT false NOT NULL,
            is_sale boolean DEFAULT false NOT NULL,
            is_published boolean DEFAULT true NOT NULL,
            price numeric(12,4) NOT NULL,
            original_price numeric(12,4) NOT NULL,
            cost numeric(10,2) DEFAULT 0 NOT NULL,
            brand text,
            sold integer DEFAULT 0 NOT NULL,
            quantity integer NOT NULL,
            description text NOT NULL,
            action text,
            slug text NOT NULL,
            attributes jsonb,
            created_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "Image" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            url text NOT NULL,
            product_id text,
            kind text,
            width integer,
            height integer,
            alt_text text,
            display_order integer DEFAULT 0 NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "Variation" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            color text NOT NULL,
            color_code text,
            color_image text,
            image text,
            product_id text NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "Order" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            user_id text,
            customer_id text,
            status text DEFAULT \'pending\' NOT NULL,
            total numeric(10,2) NOT NULL,
            created_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            shipping_address jsonb,
            billing_address jsonb,
            payment_method text
        )',
        'CREATE TABLE IF NOT EXISTS "OrderItem" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            order_id text NOT NULL,
            product_id text NOT NULL,
            quantity integer NOT NULL,
            price numeric(10,2) NOT NULL,
            unit_cost numeric(12,4) DEFAULT 0 NOT NULL,
            cost_total numeric(12,4) DEFAULT 0 NOT NULL,
            product_name text,
            product_image text
        )',
        'CREATE TABLE IF NOT EXISTS "CommerceBillingOutbox" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            order_id text NOT NULL,
            event_type text NOT NULL DEFAULT \'commerce.order.ready_for_billing.v1\',
            command jsonb NOT NULL DEFAULT \'{}\'::jsonb,
            status text NOT NULL DEFAULT \'pending\',
            delivery_state text NOT NULL DEFAULT \'not_started\',
            attempts integer NOT NULL DEFAULT 0,
            max_attempts integer NOT NULL DEFAULT 12,
            requeue_count integer NOT NULL DEFAULT 0,
            available_at timestamp without time zone NOT NULL DEFAULT NOW(),
            locked_at timestamp without time zone,
            locked_by text,
            lock_token text,
            billing_access_key text,
            billing_response jsonb,
            last_http_status integer,
            last_error_code text,
            last_error text,
            created_at timestamp without time zone NOT NULL DEFAULT NOW(),
            updated_at timestamp without time zone NOT NULL DEFAULT NOW(),
            completed_at timestamp without time zone,
            CONSTRAINT commerce_billing_outbox_status_check CHECK (status IN (\'pending\', \'processing\', \'retry\', \'delivery_unknown\', \'sent\', \'dead_letter\')),
            CONSTRAINT commerce_billing_outbox_delivery_check CHECK (delivery_state IN (\'not_started\', \'lookup\', \'emit_requested\', \'delivery_unknown\', \'confirmed\')),
            CONSTRAINT commerce_billing_outbox_attempts_check CHECK (attempts >= 0 AND max_attempts BETWEEN 1 AND 100),
            CONSTRAINT commerce_billing_outbox_requeue_check CHECK (requeue_count >= 0),
            CONSTRAINT commerce_billing_outbox_http_check CHECK (last_http_status IS NULL OR last_http_status BETWEEN 100 AND 599)
        )',
        'CREATE TABLE IF NOT EXISTS "CommerceBillingOutboxAttempt" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            outbox_id text NOT NULL,
            order_id text NOT NULL,
            attempt_number integer NOT NULL,
            phase text NOT NULL,
            outcome text NOT NULL,
            http_status integer,
            error_code text,
            error_message text,
            metadata jsonb NOT NULL DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone NOT NULL DEFAULT NOW(),
            CONSTRAINT commerce_billing_attempt_number_check CHECK (attempt_number >= 0),
            CONSTRAINT commerce_billing_attempt_http_check CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599)
        )',
        'CREATE TABLE IF NOT EXISTS "Quotation" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            status text DEFAULT \'quoted\' NOT NULL,
            customer_name text NOT NULL,
            customer_document_type text,
            customer_document_number text,
            customer_email text,
            customer_phone text,
            customer_address jsonb,
            delivery_method text DEFAULT \'pickup\' NOT NULL,
            payment_method text,
            discount_code text,
            notes text,
            items jsonb DEFAULT \'[]\'::jsonb NOT NULL,
            quote_snapshot jsonb DEFAULT \'{}\'::jsonb NOT NULL,
            created_by_user_id text,
            converted_order_id text,
            valid_until timestamp without time zone,
            converted_at timestamp without time zone,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "InventoryLot" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            product_id text NOT NULL,
            source_type text NOT NULL,
            source_ref text,
            purchase_invoice_id text,
            purchase_invoice_item_id text,
            unit_cost numeric(12,4) DEFAULT 0 NOT NULL,
            initial_quantity integer NOT NULL,
            remaining_quantity integer NOT NULL,
            metadata jsonb,
            received_at timestamp without time zone DEFAULT NOW() NOT NULL,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "InventoryLotAllocation" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            lot_id text NOT NULL,
            order_item_id text NOT NULL,
            product_id text NOT NULL,
            quantity integer NOT NULL,
            unit_cost numeric(12,4) DEFAULT 0 NOT NULL,
            metadata jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "PurchaseInvoice" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            supplier_name text NOT NULL,
            supplier_document text,
            invoice_number text NOT NULL,
            external_key text NOT NULL,
            issued_at date NOT NULL,
            subtotal numeric(12,4) DEFAULT 0 NOT NULL,
            tax_total numeric(12,4) DEFAULT 0 NOT NULL,
            total numeric(12,4) DEFAULT 0 NOT NULL,
            notes text,
            metadata jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "PurchaseInvoiceItem" (
            id text PRIMARY KEY,
            purchase_invoice_id text NOT NULL,
            tenant_id text NOT NULL,
            product_id text NOT NULL,
            product_name_snapshot text,
            quantity integer NOT NULL,
            unit_cost numeric(12,4) DEFAULT 0 NOT NULL,
            line_total numeric(12,4) DEFAULT 0 NOT NULL,
            metadata jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "Setting" (
            key text PRIMARY KEY,
            value text NOT NULL,
            tenant_id text NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "ProductReferenceCatalog" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            catalog_key text NOT NULL,
            label text NOT NULL,
            payload jsonb DEFAULT \'{}\'::jsonb,
            sort_order integer DEFAULT 0 NOT NULL,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "DiscountCode" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            code text NOT NULL,
            name text,
            description text,
            type text NOT NULL,
            value numeric(12,2) NOT NULL,
            min_subtotal numeric(12,2) DEFAULT 0 NOT NULL,
            max_discount numeric(12,2),
            max_uses integer,
            used_count integer DEFAULT 0 NOT NULL,
            starts_at timestamp without time zone,
            ends_at timestamp without time zone,
            is_active boolean DEFAULT true NOT NULL,
            created_by text,
            metadata jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "DiscountAudit" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            discount_code_id text,
            code text,
            action text NOT NULL,
            reason text,
            order_id text,
            amount numeric(12,2),
            payload jsonb,
            user_id text,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "AuthSecurityEvent" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            user_id text,
            email text,
            event_type text NOT NULL,
            status text DEFAULT \'info\' NOT NULL,
            ip_address text,
            user_agent text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "CustomerAuthSecurityEvent" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            user_id text,
            email text,
            event_type text NOT NULL,
            status text DEFAULT \'info\' NOT NULL,
            ip_address text,
            user_agent text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "ContactMessage" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            name text NOT NULL,
            email text NOT NULL,
            phone text,
            subject text NOT NULL,
            message text NOT NULL,
            source text DEFAULT \'web\' NOT NULL,
            status text DEFAULT \'new\' NOT NULL,
            ip_address text,
            user_agent text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "PasswordResetToken" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            user_id text NOT NULL,
            token_hash text NOT NULL,
            purpose text NOT NULL DEFAULT \'password_reset\',
            created_by_user_id text,
            expires_at timestamp without time zone NOT NULL,
            used_at timestamp without time zone,
            request_ip text,
            request_user_agent text,
            used_ip text,
            used_user_agent text,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "CustomerPasswordResetToken" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            user_id text NOT NULL,
            token_hash text NOT NULL,
            purpose text NOT NULL DEFAULT \'password_reset\',
            created_by_user_id text,
            expires_at timestamp without time zone NOT NULL,
            used_at timestamp without time zone,
            request_ip text,
            request_user_agent text,
            used_ip text,
            used_user_agent text,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "ProductReview" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            product_id text NOT NULL,
            order_id text NOT NULL,
            order_item_id text NOT NULL,
            user_id text NOT NULL,
            customer_id text,
            rating integer NOT NULL,
            title text,
            body text NOT NULL,
            author_name text NOT NULL,
            status text DEFAULT \'pending\' NOT NULL,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "FinancialPeriod" (
            id varchar(64) PRIMARY KEY,
            tenant_id varchar(120) NOT NULL,
            period_key varchar(7) NOT NULL,
            start_date date NOT NULL,
            end_date date NOT NULL,
            status varchar(20) NOT NULL DEFAULT \'open\',
            snapshot_json jsonb NULL,
            closed_by_user_id varchar(64) NULL,
            closed_at timestamptz NULL,
            notes text NULL,
            created_at timestamptz NOT NULL DEFAULT NOW(),
            updated_at timestamptz NOT NULL DEFAULT NOW(),
            UNIQUE (tenant_id, period_key)
        )',
        'CREATE TABLE IF NOT EXISTS "FinancialAdjustment" (
            id varchar(64) PRIMARY KEY,
            tenant_id varchar(120) NOT NULL,
            period_key varchar(7) NOT NULL,
            adjustment_date date NOT NULL,
            type varchar(40) NOT NULL,
            target_type varchar(60) NULL,
            target_id varchar(80) NULL,
            original_period_key varchar(7) NULL,
            description text NOT NULL,
            amount numeric(12,2) NOT NULL DEFAULT 0,
            tax_amount numeric(12,2) NOT NULL DEFAULT 0,
            total numeric(12,2) NOT NULL DEFAULT 0,
            reason text NULL,
            created_by_user_id varchar(64) NOT NULL,
            created_at timestamptz NOT NULL DEFAULT NOW()
        )',
        'CREATE TABLE IF NOT EXISTS "BusinessExpenseRecurrence" (
            id varchar(64) PRIMARY KEY,
            tenant_id varchar(120) NOT NULL,
            category varchar(120) NOT NULL,
            description text NOT NULL,
            amount numeric(12,2) NOT NULL DEFAULT 0,
            tax_amount numeric(12,2) NOT NULL DEFAULT 0,
            total numeric(12,2) NOT NULL DEFAULT 0,
            frequency varchar(20) NOT NULL DEFAULT \'monthly\',
            interval_count integer NOT NULL DEFAULT 1,
            start_date date NOT NULL,
            next_due_date date NOT NULL,
            payment_method varchar(60) NULL,
            reference varchar(160) NULL,
            notes text NULL,
            active boolean NOT NULL DEFAULT TRUE,
            created_by_user_id varchar(64) NOT NULL,
            created_at timestamptz NOT NULL DEFAULT NOW(),
            updated_at timestamptz NOT NULL DEFAULT NOW()
        )',
        'CREATE TABLE IF NOT EXISTS "BusinessExpense" (
            id varchar(64) PRIMARY KEY,
            tenant_id varchar(120) NOT NULL,
            recurrence_id varchar(64) NULL REFERENCES "BusinessExpenseRecurrence"(id) ON DELETE SET NULL,
            category varchar(120) NOT NULL,
            description text NOT NULL,
            amount numeric(12,2) NOT NULL DEFAULT 0,
            tax_amount numeric(12,2) NOT NULL DEFAULT 0,
            total numeric(12,2) NOT NULL DEFAULT 0,
            expense_date date NOT NULL,
            due_date date NULL,
            paid_at timestamptz NULL,
            status varchar(20) NOT NULL DEFAULT \'pending\',
            type varchar(30) NOT NULL DEFAULT \'one_time\',
            payment_method varchar(60) NULL,
            reference varchar(160) NULL,
            notes text NULL,
            source varchar(40) NULL,
            source_id varchar(80) NULL,
            created_by_user_id varchar(64) NOT NULL,
            created_at timestamptz NOT NULL DEFAULT NOW(),
            updated_at timestamptz NOT NULL DEFAULT NOW()
        )',
        'CREATE TABLE IF NOT EXISTS "BusinessExpensePayment" (
            id bigserial PRIMARY KEY,
            tenant_id varchar(120) NOT NULL,
            expense_id varchar(64) NOT NULL REFERENCES "BusinessExpense"(id) ON DELETE CASCADE,
            amount numeric(12,2) NOT NULL DEFAULT 0,
            paid_at timestamptz NOT NULL DEFAULT NOW(),
            payment_method varchar(60) NULL,
            reference varchar(160) NULL,
            notes text NULL,
            created_by_user_id varchar(64) NOT NULL,
            created_at timestamptz NOT NULL DEFAULT NOW()
        )',
        'CREATE TABLE IF NOT EXISTS "PosShift" (
            id varchar(64) PRIMARY KEY,
            tenant_id varchar(120) NOT NULL,
            opened_by_user_id varchar(64) NOT NULL,
            opened_at timestamptz NOT NULL DEFAULT NOW(),
            opening_cash numeric(12,2) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT \'open\',
            open_notes text NULL,
            closed_by_user_id varchar(64) NULL,
            closed_at timestamptz NULL,
            closing_cash numeric(12,2) NULL,
            close_notes text NULL,
            expected_cash numeric(12,2) NULL,
            difference_cash numeric(12,2) NULL,
            summary_json text NULL
        )',
        'CREATE TABLE IF NOT EXISTS "PosMovement" (
            id bigserial PRIMARY KEY,
            tenant_id varchar(120) NOT NULL,
            shift_id varchar(64) NOT NULL,
            type varchar(20) NOT NULL,
            amount numeric(12,2) NOT NULL,
            description text NULL,
            business_expense_id varchar(64) NULL,
            created_by_user_id varchar(64) NOT NULL,
            created_at timestamptz NOT NULL DEFAULT NOW(),
            CONSTRAINT pos_movement_shift_fk FOREIGN KEY (shift_id) REFERENCES "PosShift"(id) ON DELETE CASCADE
        )',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS addresses jsonb',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS profile jsonb',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS document_type text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS document_number text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS business_name text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS delivery_method text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS otp_code text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS otp_expires_at timestamp',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS otp_attempts integer',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS failed_login_attempts integer',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS login_locked_until timestamp',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS last_login_at timestamp',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS active_token_id text',
        'ALTER TABLE "PasswordResetToken" ADD COLUMN IF NOT EXISTS purpose text NOT NULL DEFAULT \'password_reset\'',
        'ALTER TABLE "PasswordResetToken" ADD COLUMN IF NOT EXISTS created_by_user_id text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS addresses jsonb',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS profile jsonb',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS document_type text',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS document_number text',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS business_name text',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS otp_code text',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS otp_expires_at timestamp',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS otp_attempts integer',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS failed_login_attempts integer',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS login_locked_until timestamp',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS last_login_at timestamp',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS active_token_id text',
        'ALTER TABLE "CustomerPasswordResetToken" ADD COLUMN IF NOT EXISTS purpose text NOT NULL DEFAULT \'password_reset\'',
        'ALTER TABLE "CustomerPasswordResetToken" ADD COLUMN IF NOT EXISTS created_by_user_id text',
        'ALTER TABLE "Customer" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS product_type text',
        'ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS attributes jsonb',
        'ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS is_published boolean',
        'ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS kind text',
        'ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS width integer',
        'ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS height integer',
        'ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS alt_text text',
        'ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS display_order integer',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS customer_id text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_number text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_html text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_created_at timestamp(3) without time zone',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_data jsonb',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS payment_details jsonb',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS items_subtotal numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS vat_subtotal numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS vat_rate numeric(6,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS vat_amount numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping_base numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping_tax_rate numeric(6,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping_tax_amount numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS order_notes text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS discount_code text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS discount_total numeric(12,2) DEFAULT 0',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS discount_snapshot jsonb',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS customer_name text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS customer_email text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS customer_phone text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS customer_document_type text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS customer_document_number text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS customer_company text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS sales_channel text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS customer_snapshot_version smallint NOT NULL DEFAULT 0',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS status text DEFAULT \'quoted\'',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_name text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_document_type text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_document_number text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_email text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_phone text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_address jsonb',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS delivery_method text DEFAULT \'pickup\'',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS payment_method text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS discount_code text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS notes text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS items jsonb DEFAULT \'[]\'::jsonb',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS quote_snapshot jsonb DEFAULT \'{}\'::jsonb',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS created_by_user_id text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS converted_order_id text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS valid_until timestamp without time zone',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS converted_at timestamp without time zone',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS created_at timestamp without time zone DEFAULT NOW()',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS updated_at timestamp without time zone DEFAULT NOW()',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS unit_cost numeric(12,4)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Variation" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "OrderItem" ALTER COLUMN unit_cost TYPE numeric(12,4) USING COALESCE(unit_cost, 0)::numeric(12,4)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS cost_total numeric(12,4)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS price_net numeric(12,4)',
        'ALTER TABLE "OrderItem" ALTER COLUMN price_net TYPE numeric(12,4) USING COALESCE(price_net, 0)::numeric(12,4)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS net_total numeric(12,4)',
        'ALTER TABLE "OrderItem" ALTER COLUMN net_total TYPE numeric(12,4) USING COALESCE(net_total, 0)::numeric(12,4)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS tax_rate numeric(6,2)',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_rate TYPE numeric(6,2) USING COALESCE(tax_rate, 0)::numeric(6,2)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS tax_amount numeric(12,4)',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_amount TYPE numeric(12,4) USING COALESCE(tax_amount, 0)::numeric(12,4)',
        'ALTER TABLE "ProductReview" ADD COLUMN IF NOT EXISTS customer_id text',
        'ALTER TABLE "InventoryLot" ADD COLUMN IF NOT EXISTS purchase_invoice_id text',
        'ALTER TABLE "InventoryLot" ADD COLUMN IF NOT EXISTS purchase_invoice_item_id text',
        'ALTER TABLE "Setting" ADD COLUMN IF NOT EXISTS tenant_id text',
        'UPDATE "OrderItem" oi SET unit_cost = COALESCE((
            SELECT p.cost
            FROM "Order" o
            LEFT JOIN "Product" p ON p.id = oi.product_id AND p.tenant_id = o.tenant_id
            WHERE o.id = oi.order_id
            LIMIT 1
        ), 0) WHERE oi.unit_cost IS NULL',
        'UPDATE "OrderItem" SET cost_total = ROUND((COALESCE(quantity, 0) * COALESCE(unit_cost, 0))::numeric, 4) WHERE cost_total IS NULL',
        'UPDATE "OrderItem" oi
            SET price_net = ROUND((
                COALESCE(oi.price, 0) / NULLIF((1 + (COALESCE(o.vat_rate, 0) / 100.0)), 0)
            )::numeric, 4)
          FROM "Order" o
          WHERE oi.order_id = o.id
            AND oi.price_net IS NULL',
        'UPDATE "OrderItem" oi
            SET net_total = ROUND((
                COALESCE(oi.quantity, 0) * COALESCE(oi.price_net, 0)
            )::numeric, 4)
          WHERE oi.net_total IS NULL',
        'UPDATE "OrderItem" oi
            SET tax_rate = COALESCE(o.vat_rate, 0)
          FROM "Order" o
          WHERE oi.order_id = o.id
            AND oi.tax_rate IS NULL',
        'UPDATE "OrderItem" oi
            SET tax_amount = ROUND((
                (COALESCE(oi.quantity, 0) * COALESCE(oi.price, 0)) - COALESCE(oi.net_total, 0)
            )::numeric, 4)
          WHERE oi.tax_amount IS NULL',
        'UPDATE "Order"
            SET shipping_address = CASE
                    WHEN jsonb_typeof(shipping_address) = \'object\'
                    THEN shipping_address - \'password\' - \'confirmPassword\'
                    ELSE shipping_address
                END,
                billing_address = CASE
                    WHEN jsonb_typeof(billing_address) = \'object\'
                    THEN billing_address - \'password\' - \'confirmPassword\'
                    ELSE billing_address
                END,
                invoice_data = CASE
                    WHEN jsonb_typeof(invoice_data) = \'object\'
                    THEN jsonb_set(
                        invoice_data,
                        ARRAY[\'billing_address\'],
                        COALESCE(invoice_data->\'billing_address\', \'{}\'::jsonb) - \'password\' - \'confirmPassword\',
                        true
                    )
                    ELSE invoice_data
                END
          WHERE (shipping_address ? \'password\')
             OR (shipping_address ? \'confirmPassword\')
             OR (billing_address ? \'password\')
             OR (billing_address ? \'confirmPassword\')
             OR ((invoice_data->\'billing_address\') ? \'password\')
             OR ((invoice_data->\'billing_address\') ? \'confirmPassword\')',
        'UPDATE "Product" SET is_published = true WHERE is_published IS NULL',
        'ALTER TABLE "Product" ALTER COLUMN is_published SET DEFAULT true',
        'ALTER TABLE "Product" ALTER COLUMN is_published SET NOT NULL',
        'WITH ordered_images AS (
            SELECT
                id,
                ROW_NUMBER() OVER (
                    PARTITION BY product_id, COALESCE(kind, \'gallery\')
                    ORDER BY id
                ) - 1 AS next_display_order
            FROM "Image"
        )
        UPDATE "Image" image
        SET display_order = ordered_images.next_display_order
        FROM ordered_images
        WHERE image.id = ordered_images.id
          AND image.display_order IS NULL',
        'ALTER TABLE "Image" ALTER COLUMN display_order SET DEFAULT 0',
        'ALTER TABLE "Image" ALTER COLUMN display_order SET NOT NULL',
        'UPDATE "OrderItem" SET unit_cost = 0 WHERE unit_cost IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN unit_cost SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN unit_cost SET NOT NULL',
        'UPDATE "OrderItem" SET cost_total = 0 WHERE cost_total IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN cost_total SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN cost_total SET NOT NULL',
        'UPDATE "OrderItem" SET price_net = 0 WHERE price_net IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN price_net SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN price_net SET NOT NULL',
        'UPDATE "OrderItem" SET net_total = 0 WHERE net_total IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN net_total SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN net_total SET NOT NULL',
        'UPDATE "OrderItem" SET tax_rate = 0 WHERE tax_rate IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_rate SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_rate SET NOT NULL',
        'UPDATE "OrderItem" SET tax_amount = 0 WHERE tax_amount IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_amount SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_amount SET NOT NULL',
        'ALTER TABLE "User" DROP CONSTRAINT IF EXISTS "User_email_key"',
        'ALTER TABLE "Product" DROP CONSTRAINT IF EXISTS "Product_slug_key"',
        'DROP INDEX IF EXISTS "Product_legacy_id_key"',
        'CREATE INDEX IF NOT EXISTS "User_tenant_id_idx" ON "User" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "User_tenant_email_idx" ON "User" (tenant_id, email)',
        'CREATE INDEX IF NOT EXISTS "User_tenant_created_cursor_idx" ON "User" (tenant_id, created_at DESC, id DESC)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "User_tenant_email_uidx" ON "User" (tenant_id, email)',
        'CREATE INDEX IF NOT EXISTS "Customer_tenant_id_idx" ON "Customer" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "Customer_tenant_email_idx" ON "Customer" (tenant_id, email)',
        'CREATE INDEX IF NOT EXISTS "Customer_tenant_created_cursor_idx" ON "Customer" (tenant_id, created_at DESC, id DESC)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "Customer_tenant_email_uidx" ON "Customer" (tenant_id, email)',
        'CREATE INDEX IF NOT EXISTS "Customer_tenant_document_idx" ON "Customer" (tenant_id, document_number)',
        'CREATE INDEX IF NOT EXISTS tenant_module_entitlements_status_idx ON tenant_module_entitlements (tenant_id, status, module_key)',
        'CREATE INDEX IF NOT EXISTS tenant_memberships_identity_idx ON tenant_memberships (tenant_id, identity_type, status)',
        'CREATE INDEX IF NOT EXISTS tenant_roles_system_idx ON tenant_roles (tenant_id, system_role)',
        'CREATE INDEX IF NOT EXISTS tenant_user_roles_role_idx ON tenant_user_roles (tenant_id, role_id)',
        'CREATE INDEX IF NOT EXISTS tenant_user_roles_user_idx ON tenant_user_roles (tenant_id, user_id, assigned_at DESC)',
        'CREATE INDEX IF NOT EXISTS tenant_role_navigation_grants_role_idx ON tenant_role_navigation_grants (tenant_id, role_id, menu_option_key)',
        'CREATE INDEX IF NOT EXISTS tenant_role_navigation_grants_option_idx ON tenant_role_navigation_grants (tenant_id, menu_option_key, action_key)',
        'CREATE INDEX IF NOT EXISTS tenant_access_audit_events_created_idx ON tenant_access_audit_events (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS tenant_access_audit_events_actor_idx ON tenant_access_audit_events (tenant_id, actor_user_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS tenant_access_audit_events_target_idx ON tenant_access_audit_events (tenant_id, target_type, target_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS tenant_user_sessions_active_idx ON tenant_user_sessions (tenant_id, user_id, expires_at DESC) WHERE revoked_at IS NULL',
        'UPDATE tenant_memberships
            SET identity_type = CASE
                WHEN identity_type IN (\'platform\', \'tenant_staff\', \'customer\', \'service\') THEN identity_type
                ELSE \'customer\'
            END,
            status = CASE
                WHEN status IN (\'invited\', \'active\', \'inactive\', \'blocked\') THEN status
                ELSE \'inactive\'
            END',
        'DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = \'tenant_memberships_identity_type_check\'
            ) THEN
                ALTER TABLE tenant_memberships
                    ADD CONSTRAINT tenant_memberships_identity_type_check
                    CHECK (identity_type IN (\'platform\', \'tenant_staff\', \'customer\', \'service\'));
            END IF;
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = \'tenant_memberships_status_check\'
            ) THEN
                ALTER TABLE tenant_memberships
                    ADD CONSTRAINT tenant_memberships_status_check
                    CHECK (status IN (\'invited\', \'active\', \'inactive\', \'blocked\'));
            END IF;
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint WHERE conname = \'tenant_user_roles_role_fk\'
            ) THEN
                ALTER TABLE tenant_user_roles
                    ADD CONSTRAINT tenant_user_roles_role_fk
                    FOREIGN KEY (tenant_id, role_id)
                    REFERENCES tenant_roles (tenant_id, role_id)
                    ON DELETE RESTRICT
                    NOT VALID;
            END IF;
            ALTER TABLE tenant_role_navigation_grants
                DROP CONSTRAINT IF EXISTS tenant_role_navigation_grants_action_check;
            ALTER TABLE tenant_role_navigation_grants
                ADD CONSTRAINT tenant_role_navigation_grants_action_check
                CHECK (action_key IN (
                    \'view\', \'create\', \'update\', \'delete\', \'reverse\', \'approve\', \'deliver\',
                    \'cancel\', \'export\', \'assign_roles\', \'unlock\', \'invite\', \'revoke_sessions\',
                    \'adjust_points\'
                ));
        END
        $$',
        'INSERT INTO tenant_memberships (tenant_id, user_id, identity_type, status, created_at, updated_at)
            SELECT
                COALESCE(u.tenant_id, \'' . str_replace("'", "''", $defaultTenant) . '\'),
                u.id,
                CASE
                    WHEN LOWER(COALESCE(u.role, \'\')) = \'admin\' THEN \'tenant_staff\'
                    ELSE \'customer\'
                END,
                CASE WHEN COALESCE(u.email_verified, false) THEN \'active\' ELSE \'inactive\' END,
                COALESCE(u.created_at, NOW()),
                COALESCE(u.updated_at, NOW())
            FROM "User" u
            WHERE u.tenant_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM tenant_memberships tm
                  WHERE tm.tenant_id = COALESCE(u.tenant_id, \'' . str_replace("'", "''", $defaultTenant) . '\')
                    AND tm.user_id = u.id
              )',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_id_idx" ON "Product" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_published_idx" ON "Product" (tenant_id, is_published)',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_slug_idx" ON "Product" (tenant_id, slug)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "Product_tenant_slug_uidx" ON "Product" (tenant_id, slug)',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_legacy_id_idx" ON "Product" (tenant_id, legacy_id)',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_created_idx" ON "Product" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "Product_admin_cursor_idx" ON "Product" (tenant_id, created_at DESC, id DESC) WHERE COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Product_public_cursor_idx" ON "Product" (tenant_id, created_at DESC, id DESC) WHERE is_published = true AND quantity > 0 AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Product_public_category_cursor_idx" ON "Product" (tenant_id, category, created_at DESC, id DESC) WHERE is_published = true AND quantity > 0 AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Product_public_brand_cursor_idx" ON "Product" (tenant_id, brand, created_at DESC, id DESC) WHERE is_published = true AND quantity > 0 AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Product_public_gender_cursor_idx" ON "Product" (tenant_id, gender, created_at DESC, id DESC) WHERE is_published = true AND quantity > 0 AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Product_public_variant_group_cursor_idx" ON "Product" (tenant_id, (attributes->>\'variantGroupKey\'), created_at DESC, id DESC) WHERE is_published = true AND quantity > 0 AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Product_public_category_slug_cursor_idx" ON "Product" (tenant_id, (TRIM(BOTH \'-\' FROM REGEXP_REPLACE(TRANSLATE(LOWER(COALESCE(category, \'\')), \'áéíóúüñ\', \'aeiouun\'), \'[^a-z0-9]+\', \'-\', \'g\'))), created_at DESC, id DESC) WHERE is_published = true AND quantity > 0 AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Product_public_brand_slug_cursor_idx" ON "Product" (tenant_id, (TRIM(BOTH \'-\' FROM REGEXP_REPLACE(TRANSLATE(LOWER(COALESCE(brand, \'\')), \'áéíóúüñ\', \'aeiouun\'), \'[^a-z0-9]+\', \'-\', \'g\'))), created_at DESC, id DESC) WHERE is_published = true AND quantity > 0 AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Product_public_search_fts_idx" ON "Product" USING GIN (TO_TSVECTOR(\'simple\', TRANSLATE(LOWER(COALESCE(name, \'\') || \' \' || COALESCE(brand, \'\') || \' \' || COALESCE(category, \'\') || \' \' || COALESCE(product_type, \'\') || \' \' || COALESCE(gender, \'\') || \' \' || COALESCE(description, \'\') || \' \' || COALESCE(attributes->>\'sku\', \'\') || \' \' || COALESCE(attributes->>\'presentation\', \'\') || \' \' || COALESCE(attributes->>\'variantLabel\', \'\') || \' \' || COALESCE(attributes->>\'catalogCategories\', \'\')), \'áéíóúüñ\', \'aeiouun\'))) WHERE is_published = true AND quantity > 0 AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE UNIQUE INDEX IF NOT EXISTS "Product_tenant_sku_active_uidx" ON "Product" (tenant_id, upper(trim(attributes->>\'sku\'))) WHERE COALESCE(NULLIF(trim(attributes->>\'sku\'), \'\'), \'\') <> \'\' AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE UNIQUE INDEX IF NOT EXISTS "Product_tenant_variant_label_active_uidx" ON "Product" (tenant_id, (attributes->>\'variantGroupKey\'), (attributes->>\'variantLabel\')) WHERE COALESCE(NULLIF(attributes->>\'variantGroupKey\', \'\'), \'\') <> \'\' AND COALESCE(NULLIF(attributes->>\'variantLabel\', \'\'), \'\') <> \'\' AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_id_idx" ON "Order" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_created_idx" ON "Order" (tenant_id, created_at)',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_created_cursor_idx" ON "Order" (tenant_id, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_status_created_idx" ON "Order" (tenant_id, lower(COALESCE(status, \'pending\')), created_at)',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_user_idx" ON "Order" (tenant_id, user_id)',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_user_created_cursor_idx" ON "Order" (tenant_id, user_id, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_customer_idx" ON "Order" (tenant_id, customer_id)',
        'CREATE INDEX IF NOT EXISTS "Quotation_tenant_created_idx" ON "Quotation" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "Quotation_tenant_status_idx" ON "Quotation" (tenant_id, status, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "Quotation_tenant_converted_order_idx" ON "Quotation" (tenant_id, converted_order_id)',
        'CREATE INDEX IF NOT EXISTS "OrderItem_order_id_idx" ON "OrderItem" (order_id)',
        'CREATE INDEX IF NOT EXISTS "OrderItem_product_id_idx" ON "OrderItem" (product_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_product_received_idx" ON "InventoryLot" (tenant_id, product_id, received_at, created_at)',
        'CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_product_remaining_idx" ON "InventoryLot" (tenant_id, product_id, remaining_quantity)',
        'CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_purchase_invoice_idx" ON "InventoryLot" (tenant_id, purchase_invoice_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_purchase_invoice_item_idx" ON "InventoryLot" (tenant_id, purchase_invoice_item_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLotAllocation_tenant_order_item_idx" ON "InventoryLotAllocation" (tenant_id, order_item_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLotAllocation_tenant_product_idx" ON "InventoryLotAllocation" (tenant_id, product_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLotAllocation_tenant_lot_idx" ON "InventoryLotAllocation" (tenant_id, lot_id)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "PurchaseInvoice_tenant_external_key_uidx" ON "PurchaseInvoice" (tenant_id, external_key)',
        'CREATE INDEX IF NOT EXISTS "PurchaseInvoice_tenant_issued_idx" ON "PurchaseInvoice" (tenant_id, issued_at DESC, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "PurchaseInvoiceItem_tenant_invoice_idx" ON "PurchaseInvoiceItem" (tenant_id, purchase_invoice_id, created_at ASC)',
        'CREATE INDEX IF NOT EXISTS "PurchaseInvoiceItem_tenant_product_idx" ON "PurchaseInvoiceItem" (tenant_id, product_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "Image_product_id_idx" ON "Image" (product_id)',
        'CREATE INDEX IF NOT EXISTS "Image_product_kind_order_idx" ON "Image" (product_id, kind, display_order, id)',
        'CREATE INDEX IF NOT EXISTS "Image_tenant_product_kind_order_idx" ON "Image" (tenant_id, product_id, kind, display_order, id)',
        'CREATE INDEX IF NOT EXISTS "ProductReview_tenant_product_status_idx" ON "ProductReview" (tenant_id, product_id, status, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "ProductReview_tenant_product_status_cursor_idx" ON "ProductReview" (tenant_id, product_id, status, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS "ProductReview_tenant_status_created_idx" ON "ProductReview" (tenant_id, status, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "ProductReview_tenant_user_idx" ON "ProductReview" (tenant_id, user_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "ProductReview_tenant_customer_idx" ON "ProductReview" (tenant_id, customer_id, created_at DESC)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "ProductReview_tenant_order_item_uidx" ON "ProductReview" (tenant_id, order_item_id)',
        'CREATE INDEX IF NOT EXISTS idx_financial_period_tenant_dates ON "FinancialPeriod"(tenant_id, start_date DESC, status)',
        'CREATE INDEX IF NOT EXISTS idx_financial_adjustment_tenant_period ON "FinancialAdjustment"(tenant_id, period_key, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_business_expense_recurrence_tenant_next ON "BusinessExpenseRecurrence"(tenant_id, active, next_due_date)',
        'CREATE INDEX IF NOT EXISTS idx_business_expense_tenant_status_date ON "BusinessExpense"(tenant_id, status, expense_date DESC)',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_business_expense_recurrence_due_unique ON "BusinessExpense"(tenant_id, recurrence_id, due_date) WHERE recurrence_id IS NOT NULL',
        'CREATE INDEX IF NOT EXISTS idx_business_expense_payment_expense ON "BusinessExpensePayment"(tenant_id, expense_id, paid_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_pos_shift_tenant_status ON "PosShift"(tenant_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_pos_shift_tenant_opened_at ON "PosShift"(tenant_id, opened_at DESC)',
        'ALTER TABLE "PosMovement" ADD COLUMN IF NOT EXISTS business_expense_id varchar(64) NULL',
        'CREATE INDEX IF NOT EXISTS idx_pos_movement_shift ON "PosMovement"(tenant_id, shift_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "Variation_product_id_idx" ON "Variation" (product_id)',
        'CREATE INDEX IF NOT EXISTS "Variation_tenant_product_id_idx" ON "Variation" (tenant_id, product_id, id)',
        'CREATE INDEX IF NOT EXISTS "Setting_tenant_id_idx" ON "Setting" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "ProductReferenceCatalog_tenant_id_idx" ON "ProductReferenceCatalog" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "ProductReferenceCatalog_tenant_catalog_idx" ON "ProductReferenceCatalog" (tenant_id, catalog_key, sort_order)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "DiscountCode_tenant_code_uidx" ON "DiscountCode" (tenant_id, code)',
        'CREATE INDEX IF NOT EXISTS "DiscountCode_tenant_created_cursor_idx" ON "DiscountCode" (tenant_id, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS "DiscountCode_tenant_active_idx" ON "DiscountCode" (tenant_id, is_active)',
        'CREATE INDEX IF NOT EXISTS "DiscountCode_tenant_window_idx" ON "DiscountCode" (tenant_id, starts_at, ends_at)',
        'CREATE INDEX IF NOT EXISTS "DiscountAudit_tenant_created_idx" ON "DiscountAudit" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "DiscountAudit_tenant_code_idx" ON "DiscountAudit" (tenant_id, code)',
        'CREATE INDEX IF NOT EXISTS "DiscountAudit_tenant_order_idx" ON "DiscountAudit" (tenant_id, order_id)',
        'CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_created_idx" ON "AuthSecurityEvent" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_event_idx" ON "AuthSecurityEvent" (tenant_id, event_type, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_user_idx" ON "AuthSecurityEvent" (tenant_id, user_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_email_idx" ON "AuthSecurityEvent" (tenant_id, email, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "CustomerAuthSecurityEvent_tenant_created_idx" ON "CustomerAuthSecurityEvent" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "CustomerAuthSecurityEvent_tenant_event_idx" ON "CustomerAuthSecurityEvent" (tenant_id, event_type, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "CustomerAuthSecurityEvent_tenant_user_idx" ON "CustomerAuthSecurityEvent" (tenant_id, user_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "CustomerAuthSecurityEvent_tenant_email_idx" ON "CustomerAuthSecurityEvent" (tenant_id, email, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "ContactMessage_tenant_created_idx" ON "ContactMessage" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "ContactMessage_tenant_status_idx" ON "ContactMessage" (tenant_id, status)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "PasswordResetToken_tenant_hash_uidx" ON "PasswordResetToken" (tenant_id, token_hash)',
        'CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_user_idx" ON "PasswordResetToken" (tenant_id, user_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_expires_idx" ON "PasswordResetToken" (tenant_id, expires_at)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "CustomerPasswordResetToken_tenant_hash_uidx" ON "CustomerPasswordResetToken" (tenant_id, token_hash)',
        'CREATE INDEX IF NOT EXISTS "CustomerPasswordResetToken_tenant_user_idx" ON "CustomerPasswordResetToken" (tenant_id, user_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "CustomerPasswordResetToken_tenant_expires_idx" ON "CustomerPasswordResetToken" (tenant_id, expires_at)',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLot_product_id_fkey\') THEN ALTER TABLE "InventoryLot" ADD CONSTRAINT "InventoryLot_product_id_fkey" FOREIGN KEY (product_id) REFERENCES "Product"(id) ON UPDATE CASCADE ON DELETE RESTRICT; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLot_purchase_invoice_id_fkey\') THEN ALTER TABLE "InventoryLot" ADD CONSTRAINT "InventoryLot_purchase_invoice_id_fkey" FOREIGN KEY (purchase_invoice_id) REFERENCES "PurchaseInvoice"(id) ON UPDATE CASCADE ON DELETE SET NULL; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLot_purchase_invoice_item_id_fkey\') THEN ALTER TABLE "InventoryLot" ADD CONSTRAINT "InventoryLot_purchase_invoice_item_id_fkey" FOREIGN KEY (purchase_invoice_item_id) REFERENCES "PurchaseInvoiceItem"(id) ON UPDATE CASCADE ON DELETE SET NULL; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'PurchaseInvoiceItem_purchase_invoice_id_fkey\') THEN ALTER TABLE "PurchaseInvoiceItem" ADD CONSTRAINT "PurchaseInvoiceItem_purchase_invoice_id_fkey" FOREIGN KEY (purchase_invoice_id) REFERENCES "PurchaseInvoice"(id) ON UPDATE CASCADE ON DELETE CASCADE; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'PurchaseInvoiceItem_product_id_fkey\') THEN ALTER TABLE "PurchaseInvoiceItem" ADD CONSTRAINT "PurchaseInvoiceItem_product_id_fkey" FOREIGN KEY (product_id) REFERENCES "Product"(id) ON UPDATE CASCADE ON DELETE RESTRICT; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLotAllocation_lot_id_fkey\') THEN ALTER TABLE "InventoryLotAllocation" ADD CONSTRAINT "InventoryLotAllocation_lot_id_fkey" FOREIGN KEY (lot_id) REFERENCES "InventoryLot"(id) ON UPDATE CASCADE ON DELETE RESTRICT; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLotAllocation_product_id_fkey\') THEN ALTER TABLE "InventoryLotAllocation" ADD CONSTRAINT "InventoryLotAllocation_product_id_fkey" FOREIGN KEY (product_id) REFERENCES "Product"(id) ON UPDATE CASCADE ON DELETE RESTRICT; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLotAllocation_order_item_id_fkey\') THEN ALTER TABLE "InventoryLotAllocation" ADD CONSTRAINT "InventoryLotAllocation_order_item_id_fkey" FOREIGN KEY (order_item_id) REFERENCES "OrderItem"(id) ON UPDATE CASCADE ON DELETE SET NULL; END IF; END $$',
    ];

    $skipConstraints = array_flip(array_map('strval', $options['skip_constraints'] ?? []));
    $identityOwnerIsRemote = (bool)$pdo->query("
        SELECT 1
        FROM pg_class relation
        JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
        WHERE namespace.nspname = 'public'
          AND relation.relname = 'tenant_roles'
          AND relation.relkind = 'f'
        LIMIT 1
    ")->fetchColumn();
    $identityContractTables = [
        'tenant_module_entitlements',
        'tenant_memberships',
        'tenant_roles',
        'tenant_user_roles',
        'tenant_role_navigation_grants',
        'tenant_access_audit_events',
        'tenant_user_sessions',
    ];

    foreach ($statements as $sql) {
        if ($identityOwnerIsRemote) {
            foreach ($identityContractTables as $identityTable) {
                if (str_contains($sql, $identityTable)) {
                    continue 2;
                }
            }
        }
        foreach ($skipConstraints as $constraintName => $_) {
            if ($constraintName !== '' && str_contains($sql, $constraintName)) {
                continue 2;
            }
        }

        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '42501') {
                $summary = trim(preg_replace('/\s+/', ' ', substr($sql, 0, 160)) ?? '');
                fwrite(STDERR, '[schema] warning skipped insufficient-privilege statement: ' . $summary . PHP_EOL);
                continue;
            }

            throw $e;
        }
    }

    $stmtUser = $pdo->prepare('UPDATE "User" SET tenant_id = :tenant WHERE NULLIF(BTRIM(tenant_id), \'\') IS NULL');
    $stmtUser->execute(['tenant' => $defaultTenant]);

    $stmtCustomer = $pdo->prepare('UPDATE "Customer" SET tenant_id = :tenant WHERE NULLIF(BTRIM(tenant_id), \'\') IS NULL');
    $stmtCustomer->execute(['tenant' => $defaultTenant]);

    $stmtProduct = $pdo->prepare('UPDATE "Product" SET tenant_id = :tenant WHERE NULLIF(BTRIM(tenant_id), \'\') IS NULL');
    $stmtProduct->execute(['tenant' => $defaultTenant]);

    $pdo->exec('
        INSERT INTO "InventoryLot" (
            id,
            tenant_id,
            product_id,
            source_type,
            source_ref,
            unit_cost,
            initial_quantity,
            remaining_quantity,
            metadata,
            received_at,
            created_at,
            updated_at
        )
        SELECT
            \'lot_seed_\' || md5(COALESCE(p.tenant_id, \'\') || \':\' || COALESCE(p.id, \'\') || \':opening\'),
            p.tenant_id,
            p.id,
            \'bootstrap_opening\',
            p.id,
            COALESCE(p.cost, 0)::numeric(12,4),
            COALESCE(p.quantity, 0),
            COALESCE(p.quantity, 0),
            jsonb_build_object(\'seed\', \'bootstrap_schema\'),
            COALESCE(p.created_at, NOW()),
            NOW(),
            NOW()
        FROM "Product" p
        WHERE COALESCE(p.quantity, 0) > 0
          AND COALESCE(p.tenant_id, \'\') <> \'\'
          AND NOT EXISTS (
              SELECT 1
              FROM "InventoryLot" l
              WHERE l.tenant_id = p.tenant_id
                AND l.product_id = p.id
          )
    ');

    $stmtOrder = $pdo->prepare('UPDATE "Order" SET tenant_id = :tenant WHERE NULLIF(BTRIM(tenant_id), \'\') IS NULL');
    $stmtOrder->execute(['tenant' => $defaultTenant]);
    $pdo->exec('UPDATE "Order" SET customer_id = COALESCE(customer_id, user_id) WHERE customer_id IS NULL AND user_id IS NOT NULL');
    $pdo->exec(<<<'SQL'
        UPDATE "Order" o
        SET customer_name = COALESCE(
                NULLIF(BTRIM(o.customer_name), ''),
                NULLIF(BTRIM(o.shipping_address->>'name'), ''),
                NULLIF(BTRIM(CONCAT_WS(' ', o.shipping_address->>'firstName', o.shipping_address->>'lastName')), ''),
                NULLIF(BTRIM(o.billing_address->>'name'), ''),
                NULLIF(BTRIM(CONCAT_WS(' ', o.billing_address->>'firstName', o.billing_address->>'lastName')), '')
            ),
            customer_email = COALESCE(
                NULLIF(BTRIM(o.customer_email), ''),
                NULLIF(BTRIM(o.shipping_address->>'email'), ''),
                NULLIF(BTRIM(o.billing_address->>'email'), '')
            ),
            customer_phone = COALESCE(
                NULLIF(BTRIM(o.customer_phone), ''),
                NULLIF(BTRIM(o.shipping_address->>'phone'), ''),
                NULLIF(BTRIM(o.billing_address->>'phone'), '')
            ),
            customer_document_type = COALESCE(
                NULLIF(BTRIM(o.customer_document_type), ''),
                NULLIF(BTRIM(o.billing_address->>'documentType'), ''),
                NULLIF(BTRIM(o.shipping_address->>'documentType'), '')
            ),
            customer_document_number = COALESCE(
                NULLIF(BTRIM(o.customer_document_number), ''),
                NULLIF(BTRIM(o.billing_address->>'documentNumber'), ''),
                NULLIF(BTRIM(o.shipping_address->>'documentNumber'), '')
            ),
            customer_company = COALESCE(
                NULLIF(BTRIM(o.customer_company), ''),
                NULLIF(BTRIM(o.billing_address->>'company'), ''),
                NULLIF(BTRIM(o.billing_address->>'businessName'), ''),
                NULLIF(BTRIM(o.shipping_address->>'company'), ''),
                NULLIF(BTRIM(o.shipping_address->>'businessName'), '')
            ),
            sales_channel = COALESCE(
                NULLIF(BTRIM(o.sales_channel), ''),
                NULLIF(BTRIM(o.payment_details->>'channel'), '')
            )
        WHERE o.customer_snapshot_version < 1
        SQL);
    $pdo->exec(<<<'SQL'
        UPDATE "Order" o
        SET customer_name = COALESCE(NULLIF(BTRIM(o.customer_name), ''), NULLIF(BTRIM(c.name), '')),
            customer_email = COALESCE(NULLIF(BTRIM(o.customer_email), ''), NULLIF(BTRIM(c.email), '')),
            customer_phone = COALESCE(
                NULLIF(BTRIM(o.customer_phone), ''),
                NULLIF(BTRIM(c.profile->>'phone'), ''),
                NULLIF(BTRIM(c.profile->>'mobile'), '')
            ),
            customer_document_type = COALESCE(
                NULLIF(BTRIM(o.customer_document_type), ''),
                NULLIF(BTRIM(c.document_type), '')
            ),
            customer_document_number = COALESCE(
                NULLIF(BTRIM(o.customer_document_number), ''),
                NULLIF(BTRIM(c.document_number), '')
            ),
            customer_company = COALESCE(
                NULLIF(BTRIM(o.customer_company), ''),
                NULLIF(BTRIM(c.business_name), '')
            )
        FROM "Customer" c
        WHERE c.tenant_id = o.tenant_id
          AND c.id = COALESCE(o.customer_id, o.user_id)
          AND o.customer_snapshot_version < 1
        SQL);
    $pdo->exec('UPDATE "Order" SET customer_snapshot_version = 1 WHERE customer_snapshot_version < 1');
    $pdo->exec('UPDATE "ProductReview" SET customer_id = COALESCE(customer_id, user_id) WHERE customer_id IS NULL AND user_id IS NOT NULL');

    $stmtQuotation = $pdo->prepare('UPDATE "Quotation" SET tenant_id = COALESCE(tenant_id, :tenant)');
    $stmtQuotation->execute(['tenant' => $defaultTenant]);

    $stmtSettingTenant = $pdo->prepare('UPDATE "Setting" SET tenant_id = :tenant WHERE NULLIF(BTRIM(tenant_id), \'\') IS NULL');
    $stmtSettingTenant->execute(['tenant' => $defaultTenant]);

    foreach (['User', 'Customer', 'Product', 'Order', 'Setting'] as $tenantTable) {
        $pdo->exec(sprintf(
            'ALTER TABLE "%s" ALTER COLUMN tenant_id SET NOT NULL',
            str_replace('"', '""', $tenantTable)
        ));
    }

    ensureTenantChildIsolation($pdo, 'Image', 'Product', 'product_id', [
        'legacy_env' => 'ECOMMERCE_LEGACY_TENANT_ID',
        'parent_unique_index' => 'Product_tenant_id_id_uidx',
        'child_unique_index' => 'Image_tenant_id_id_uidx',
        'child_parent_index' => 'Image_tenant_product_idx',
        'constraint_name' => 'Image_product_tenant_fk',
        'actions' => 'ON UPDATE CASCADE ON DELETE SET NULL (product_id)',
    ]);
    ensureTenantChildIsolation($pdo, 'Variation', 'Product', 'product_id', [
        'legacy_env' => 'ECOMMERCE_LEGACY_TENANT_ID',
        'parent_unique_index' => 'Product_tenant_id_id_uidx',
        'child_unique_index' => 'Variation_tenant_id_id_uidx',
        'child_parent_index' => 'Variation_tenant_product_idx',
        'constraint_name' => 'Variation_product_tenant_fk',
        'actions' => 'ON UPDATE CASCADE ON DELETE RESTRICT',
    ]);
    ensureTenantChildIsolation($pdo, 'OrderItem', 'Order', 'order_id', [
        'legacy_env' => 'ECOMMERCE_LEGACY_TENANT_ID',
        'parent_unique_index' => 'Order_tenant_id_id_uidx',
        'child_unique_index' => 'OrderItem_tenant_id_id_uidx',
        'child_parent_index' => 'OrderItem_tenant_order_idx',
        'constraint_name' => 'OrderItem_order_tenant_fk',
        'actions' => 'ON UPDATE CASCADE ON DELETE RESTRICT',
    ]);
    ensureTenantChildIsolation($pdo, 'CommerceBillingOutbox', 'Order', 'order_id', [
        'legacy_env' => 'ECOMMERCE_LEGACY_TENANT_ID',
        'parent_unique_index' => 'Order_tenant_id_id_uidx',
        'child_unique_index' => 'CommerceBillingOutbox_tenant_id_id_uidx',
        'child_parent_index' => 'CommerceBillingOutbox_tenant_order_idx',
        'constraint_name' => 'commerce_billing_outbox_order_fk',
        'actions' => 'ON UPDATE CASCADE ON DELETE RESTRICT',
    ]);
    ensureTenantChildIsolation($pdo, 'CommerceBillingOutboxAttempt', 'CommerceBillingOutbox', 'outbox_id', [
        'legacy_env' => 'ECOMMERCE_LEGACY_TENANT_ID',
        'parent_unique_index' => 'CommerceBillingOutbox_tenant_id_id_uidx',
        'child_unique_index' => 'CommerceBillingOutboxAttempt_tenant_id_id_uidx',
        'child_parent_index' => 'CommerceBillingOutboxAttempt_tenant_outbox_idx',
        'constraint_name' => 'commerce_billing_attempt_outbox_fk',
        'actions' => 'ON UPDATE CASCADE ON DELETE RESTRICT',
    ]);
    $pdo->exec('CREATE INDEX IF NOT EXISTS "OrderItem_tenant_product_idx" ON "OrderItem" (tenant_id, product_id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS "CommerceBillingOutbox_tenant_id_id_uidx" ON "CommerceBillingOutbox" (tenant_id, id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS "CommerceBillingOutbox_tenant_order_uidx" ON "CommerceBillingOutbox" (tenant_id, order_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "CommerceBillingOutbox_due_idx" ON "CommerceBillingOutbox" (available_at, tenant_id, created_at) WHERE status IN (\'pending\', \'retry\', \'delivery_unknown\')');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "CommerceBillingOutbox_lease_idx" ON "CommerceBillingOutbox" (locked_at) WHERE status = \'processing\'');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "CommerceBillingOutbox_dead_idx" ON "CommerceBillingOutbox" (tenant_id, updated_at DESC) WHERE status = \'dead_letter\'');
    $pdo->exec('CREATE INDEX IF NOT EXISTS "CommerceBillingOutboxAttempt_outbox_idx" ON "CommerceBillingOutboxAttempt" (tenant_id, outbox_id, created_at)');

    $stmtSettingKeys = $pdo->prepare('
        UPDATE "Setting" s
        SET key = :prefix || s.key
        WHERE s.key NOT LIKE :pattern
          AND NOT EXISTS (
            SELECT 1 FROM "Setting" t WHERE t.key = :prefix || s.key
          )
    ');
    $stmtSettingKeys->execute([
        'prefix' => $defaultTenant . ':',
        'pattern' => '%:%',
    ]);

    $catalogCountStmt = $pdo->prepare('SELECT COUNT(*) FROM "ProductReferenceCatalog" WHERE tenant_id = :tenant_id');
    $catalogCountStmt->execute(['tenant_id' => $defaultTenant]);
    $catalogCount = (int)$catalogCountStmt->fetchColumn();

    if ($catalogCount === 0) {
        $legacySettingStmt = $pdo->prepare('SELECT value FROM "Setting" WHERE key = :key LIMIT 1');
        $legacySettingStmt->execute(['key' => $defaultTenant . ':product_reference_data']);
        $legacyRaw = $legacySettingStmt->fetchColumn();

        if (is_string($legacyRaw) && trim($legacyRaw) !== '') {
            $decoded = json_decode($legacyRaw, true);
            if (is_array($decoded)) {
                $insertCatalogStmt = $pdo->prepare('
                    INSERT INTO "ProductReferenceCatalog" (
                        id,
                        tenant_id,
                        catalog_key,
                        label,
                        payload,
                        sort_order,
                        created_at,
                        updated_at
                    ) VALUES (
                        :id,
                        :tenant_id,
                        :catalog_key,
                        :label,
                        :payload::jsonb,
                        :sort_order,
                        NOW(),
                        NOW()
                    )
                ');

                foreach ($decoded as $catalogKey => $values) {
                    if (!is_array($values)) {
                        continue;
                    }

                    foreach (array_values($values) as $index => $value) {
                        if ($catalogKey === 'suppliers' && is_array($value)) {
                            $name = trim((string)($value['name'] ?? ''));
                            if ($name === '') {
                                continue;
                            }

                            $supplierId = trim((string)($value['id'] ?? ''));
                            if ($supplierId === '') {
                                $supplierId = 'prc_' . substr(hash('sha256', $defaultTenant . '|suppliers|' . $name . '|' . ($index + 1)), 0, 28);
                            }

                            $payload = [
                                'id' => $supplierId,
                                'name' => $name,
                                'document' => trim((string)($value['document'] ?? '')),
                                'email' => trim((string)($value['email'] ?? '')),
                                'phone' => trim((string)($value['phone'] ?? '')),
                                'contactName' => trim((string)($value['contactName'] ?? '')),
                                'address' => trim((string)($value['address'] ?? '')),
                                'notes' => trim((string)($value['notes'] ?? '')),
                            ];

                            $insertCatalogStmt->execute([
                                'id' => $supplierId,
                                'tenant_id' => $defaultTenant,
                                'catalog_key' => 'suppliers',
                                'label' => $name,
                                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'sort_order' => $index,
                            ]);
                            continue;
                        }

                        if ($catalogKey === 'brands' && is_array($value)) {
                            $label = trim((string)($value['name'] ?? ($value['label'] ?? '')));
                            $payload = [
                                'id' => trim((string)($value['id'] ?? '')),
                                'name' => $label,
                                'logoUrl' => trim((string)($value['logoUrl'] ?? ($value['logo_url'] ?? ''))),
                            ];
                            if ($payload['id'] === '') {
                                $payload['id'] = 'prc_' . substr(hash('sha256', $defaultTenant . '|' . $catalogKey . '|' . $label . '|' . ($index + 1)), 0, 28);
                            }
                        } else {
                            $label = trim((string)$value);
                            $payload = ['label' => $label];
                        }
                        if ($label === '') {
                            continue;
                        }

                        $rowId = $catalogKey === 'brands' && is_array($payload) && trim((string)($payload['id'] ?? '')) !== ''
                            ? (string)$payload['id']
                            : 'prc_' . substr(hash('sha256', $defaultTenant . '|' . $catalogKey . '|' . $label . '|' . ($index + 1)), 0, 28);

                        $insertCatalogStmt->execute([
                            'id' => $rowId,
                            'tenant_id' => $defaultTenant,
                            'catalog_key' => (string)$catalogKey,
                            'label' => $label,
                            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'sort_order' => $index,
                        ]);
                    }
                }
            }
        }
    }
}

function configuredTenantRows(array $tenants, string $defaultTenant): array {
    $rows = [];
    foreach ($tenants as $slug => $tenant) {
        if (!is_string($slug) || $slug === '') {
            continue;
        }
        $rows[] = [
            'id' => (string)($tenant['id'] ?? $slug),
            'name' => (string)($tenant['name'] ?? $slug),
        ];
    }

    return $rows !== [] ? $rows : [['id' => $defaultTenant, 'name' => $defaultTenant]];
}

function syncConfiguredTenantRows(PDO $pdo, array $tenantRows): void {
    $insertTenant = $pdo->prepare('
        INSERT INTO "Tenant" (id, name)
        VALUES (:id, :name)
        ON CONFLICT (id) DO UPDATE
        SET name = EXCLUDED.name
    ');
    foreach ($tenantRows as $row) {
        $insertTenant->execute([
            'id' => (string)($row['id'] ?? ''),
            'name' => (string)($row['name'] ?? $row['id'] ?? ''),
        ]);
    }
}

function runSchemaBootstrap(): int {
$tenantRlsMode = strtolower(trim((string)envValue('TENANT_RLS_MODE', 'off')));
$schemaUsername = envValue('DB_USERNAME', 'postgres');
$schemaPassword = envValue('DB_PASSWORD', 'postgres');
if ($tenantRlsMode === 'enforce') {
    $schemaUsername = envValue('DB_ADMIN_USERNAME', '');
    $schemaPassword = envValue('DB_ADMIN_PASSWORD', '');
    if ($schemaUsername === '' || $schemaPassword === '') {
        fwrite(STDERR, "[schema] RLS enforce requires DB_ADMIN_USERNAME/DB_ADMIN_PASSWORD; the API role cannot bootstrap owner tables\n");
        return 1;
    }
}
$defaultConfig = [
    'host' => envValue('DB_HOST', 'db'),
    'port' => envValue('DB_PORT', '5432'),
    'database' => envValue('DB_DATABASE', 'ecommerce'),
    'username' => $schemaUsername,
    'password' => $schemaPassword,
];

$defaultTenant = envValue('DEFAULT_TENANT', 'paramascotasec');
$tenants = [];
$tenantsFile = __DIR__ . '/../config/tenants.php';
if (file_exists($tenantsFile)) {
    $loaded = require $tenantsFile;
    if (is_array($loaded)) {
        $tenants = $loaded;
    }
}

$targets = [];
$addTarget = static function (array $config) use (&$targets): void {
    $key = implode('|', [$config['host'], $config['port'], $config['database'], $config['username']]);
    $targets[$key] = $config;
};

$addTarget(normalizeConfig($defaultConfig));

foreach ($tenants as $tenant) {
    if (!is_array($tenant)) {
        continue;
    }
    $tenantDb = is_array($tenant['db'] ?? null) ? $tenant['db'] : [];
    if ($tenantRlsMode === 'enforce') {
        unset($tenantDb['username'], $tenantDb['password']);
    }
    $addTarget(normalizeConfig($defaultConfig, $tenantDb));
}

$tenantInsertRows = configuredTenantRows($tenants, (string)$defaultTenant);

try {
    $connections = [];
    // Preflight every configured legacy target before the first mutation. A
    // single modular/FDW target makes standalone bootstrap fail closed.
    foreach ($targets as $targetKey => $target) {
        $pdo = connect($target);
        assertLegacySchemaBootstrapTopology($pdo);
        $connections[$targetKey] = $pdo;
    }
    foreach ($targets as $target) {
        $targetKey = implode('|', [$target['host'], $target['port'], $target['database'], $target['username']]);
        $pdo = $connections[$targetKey];
        $pdo->beginTransaction();
        try {
            executeSchemaBootstrap($pdo, $defaultTenant);
            syncConfiguredTenantRows($pdo, $tenantInsertRows);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        fwrite(STDOUT, sprintf(
            "[schema] ok host=%s db=%s user=%s\n",
            $target['host'],
            $target['database'],
            $target['username']
        ));
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[schema] error: ' . $e->getMessage() . PHP_EOL);
    return 1;
}

return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runSchemaBootstrap());
}
