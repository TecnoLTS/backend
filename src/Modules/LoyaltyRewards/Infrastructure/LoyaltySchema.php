<?php

namespace App\Modules\LoyaltyRewards\Infrastructure;

use PDO;

final class LoyaltySchema {
    public function __construct(private readonly PDO $pdo) {}

    public function ensure(): void {
        $this->createCoreTables();
        $this->createOperationalTables();
        $this->addCompatibilityColumns();
        $this->createIndexes();
    }

    public function defaultSettings(): array {
        return [
            'program' => [
                'name' => 'Fidepuntos Demo',
                'status' => 'active',
                'currencyCode' => 'USD',
                'timezone' => 'America/Guayaquil',
                'locale' => 'es-EC',
                'brandColor' => '#0f766e',
                'logoUrl' => '',
                'supportEmail' => 'soporte@tecnolts.com',
                'supportPhone' => '',
            ],
            'earning' => [
                'pointsPerUnit' => 1,
                'amountPerUnit' => 1.0,
                'eligibleAmountSource' => 'invoice_total',
                'roundingMode' => 'floor',
                'minimumPurchaseAmount' => 1.0,
                'maximumPointsPerPurchase' => 20000,
                'maximumPointsPerMemberPerDay' => 50000,
                'duplicateReferencePolicy' => 'reject',
            ],
            'redemption' => [
                'requireDigitalCard' => true,
                'maximumRedemptionsPerMemberPerDay' => 3,
                'maximumSameRewardPerMemberPerDay' => 1,
                'manualApprovalThresholdPoints' => 20000,
                'minimumRewardStockAlert' => 5,
            ],
            'expiration' => [
                'enabled' => false,
                'pointsExpireAfterDays' => 365,
                'warningDays' => 30,
            ],
            'security' => [
                'idempotencyRequiredForExternalApi' => true,
                'auditRetentionDays' => 1095,
                'riskBlockThreshold' => 5,
            ],
            'communication' => [
                'emailEnabled' => false,
                'webhooksEnabled' => false,
            ],
            'googleWallet' => [
                'enabled' => false,
                'classSuffix' => '',
                'issuerName' => 'TecnoLTS',
                'programName' => 'Programa de fidelizacion',
                'hexBackgroundColor' => '#0f766e',
                'logoUrl' => '',
                'pointsLabel' => 'Puntos',
                'origins' => [],
            ],
        ];
    }

    public function defaultTierRules(): array {
        return [
            [
                'name' => 'Bronce',
                'minLifetimePoints' => 0,
                'maxLifetimePoints' => 4999,
                'multiplier' => 1.0,
                'benefits' => ['Beneficios base del programa'],
                'sortOrder' => 1,
            ],
            [
                'name' => 'Plata',
                'minLifetimePoints' => 5000,
                'maxLifetimePoints' => 14999,
                'multiplier' => 1.1,
                'benefits' => ['10% mas puntos por compra', 'Acceso a premios seleccionados'],
                'sortOrder' => 2,
            ],
            [
                'name' => 'Oro',
                'minLifetimePoints' => 15000,
                'maxLifetimePoints' => null,
                'multiplier' => 1.25,
                'benefits' => ['25% mas puntos por compra', 'Premios premium y prioridad en campanas'],
                'sortOrder' => 3,
            ],
        ];
    }

    private function createCoreTables(): void {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_programs (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            name text NOT NULL,
            status text NOT NULL DEFAULT \'active\',
            points_per_currency numeric(12,4) NOT NULL DEFAULT 1,
            currency_code text NOT NULL DEFAULT \'USD\',
            wallet_issuer_name text,
            wallet_program_name text,
            brand_color text,
            logo_url text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_members (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            program_id text NOT NULL,
            external_customer_id text,
            account_id text NOT NULL,
            account_name text NOT NULL,
            email text,
            phone text,
            tier text NOT NULL DEFAULT \'Bronce\',
            status text NOT NULL DEFAULT \'active\',
            wallet_platform text NOT NULL DEFAULT \'none\',
            metadata jsonb DEFAULT \'{}\'::jsonb,
            last_activity_at timestamp without time zone,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, account_id)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_point_accounts (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            program_id text NOT NULL,
            balance integer NOT NULL DEFAULT 0,
            lifetime_points integer NOT NULL DEFAULT 0,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, member_id)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_point_ledger (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            program_id text NOT NULL,
            entry_type text NOT NULL,
            points integer NOT NULL,
            balance_after integer NOT NULL,
            reference text,
            source text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_by_user_id text,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_rewards (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            program_id text,
            name text NOT NULL,
            description text,
            points_cost integer NOT NULL,
            stock integer NOT NULL DEFAULT 0,
            status text NOT NULL DEFAULT \'active\',
            image_url text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_redemptions (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            reward_id text NOT NULL,
            points_cost integer NOT NULL,
            status text NOT NULL DEFAULT \'pending\',
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_wallet_passes (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            platform text NOT NULL,
            external_object_id text,
            status text NOT NULL DEFAULT \'pending\',
            last_payload jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
    }

    private function createOperationalTables(): void {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_program_settings (
            tenant_id text PRIMARY KEY,
            program_id text NOT NULL,
            settings jsonb DEFAULT \'{}\'::jsonb NOT NULL,
            updated_by_user_id text,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_tier_rules (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            program_id text NOT NULL,
            name text NOT NULL,
            min_lifetime_points integer NOT NULL DEFAULT 0,
            max_lifetime_points integer,
            multiplier numeric(12,4) NOT NULL DEFAULT 1,
            benefits jsonb DEFAULT \'[]\'::jsonb,
            status text NOT NULL DEFAULT \'active\',
            sort_order integer NOT NULL DEFAULT 0,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, name)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_api_clients (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            name text NOT NULL,
            source text NOT NULL,
            key_hash text NOT NULL,
            scopes jsonb DEFAULT \'[]\'::jsonb NOT NULL,
            status text NOT NULL DEFAULT \'active\',
            rate_limit_per_minute integer NOT NULL DEFAULT 120,
            last_used_at timestamp without time zone,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            revoked_at timestamp without time zone,
            UNIQUE (tenant_id, key_hash)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_idempotency_keys (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            api_client_id text,
            idempotency_key text NOT NULL,
            operation text NOT NULL,
            request_hash text NOT NULL,
            status_code integer NOT NULL DEFAULT 200,
            response_payload jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, idempotency_key, operation)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_audit_events (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            actor_user_id text,
            actor_type text NOT NULL DEFAULT \'dashboard\',
            action text NOT NULL,
            subject_type text NOT NULL,
            subject_id text,
            reason text,
            before_state jsonb DEFAULT \'{}\'::jsonb,
            after_state jsonb DEFAULT \'{}\'::jsonb,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_risk_events (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            severity text NOT NULL DEFAULT \'info\',
            event_type text NOT NULL,
            status text NOT NULL DEFAULT \'open\',
            member_id text,
            reference text,
            message text NOT NULL,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            resolved_at timestamp without time zone
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_point_expirations (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            ledger_id text,
            points integer NOT NULL,
            status text NOT NULL DEFAULT \'pending\',
            expires_at timestamp without time zone NOT NULL,
            processed_at timestamp without time zone,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_reversals (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            original_reference text NOT NULL,
            ledger_id text,
            points_reversed integer NOT NULL,
            reason text NOT NULL,
            created_by_user_id text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, original_reference)
        )');
    }

    private function addCompatibilityColumns(): void {
        $this->addColumnIfMissing('loyalty_point_ledger', 'source_reference', 'text');
        $this->addColumnIfMissing('loyalty_point_ledger', 'reversed_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_point_ledger', 'expires_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_redemptions', 'created_by_user_id', 'text');
        $this->addColumnIfMissing('loyalty_members', 'blocked_reason', 'text');
        $this->addColumnIfMissing('loyalty_members', 'blocked_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_risk_events', 'resolved_by_user_id', 'text');
        $this->addColumnIfMissing('loyalty_risk_events', 'resolution_note', 'text');
    }

    private function createIndexes(): void {
        $this->createIndexIfMissing('loyalty_members_tenant_search_idx', 'CREATE INDEX loyalty_members_tenant_search_idx ON loyalty_members (tenant_id, lower(account_name), lower(email), lower(account_id), lower(COALESCE(phone, \'\')))');
        $this->createIndexIfMissing('loyalty_members_tenant_status_idx', 'CREATE INDEX loyalty_members_tenant_status_idx ON loyalty_members (tenant_id, status, tier, wallet_platform)');
        $this->createIndexIfMissing('loyalty_ledger_tenant_created_idx', 'CREATE INDEX loyalty_ledger_tenant_created_idx ON loyalty_point_ledger (tenant_id, created_at DESC)');
        $this->createIndexIfMissing('loyalty_ledger_reference_idx', 'CREATE INDEX loyalty_ledger_reference_idx ON loyalty_point_ledger (tenant_id, source, reference)');
        $this->createIndexIfMissing('loyalty_redemptions_member_day_idx', 'CREATE INDEX loyalty_redemptions_member_day_idx ON loyalty_redemptions (tenant_id, member_id, created_at DESC)');
        $this->createIndexIfMissing('loyalty_api_clients_hash_idx', 'CREATE INDEX loyalty_api_clients_hash_idx ON loyalty_api_clients (tenant_id, key_hash)');
        $this->createIndexIfMissing('loyalty_audit_tenant_created_idx', 'CREATE INDEX loyalty_audit_tenant_created_idx ON loyalty_audit_events (tenant_id, created_at DESC)');
        $this->createIndexIfMissing('loyalty_risk_tenant_created_idx', 'CREATE INDEX loyalty_risk_tenant_created_idx ON loyalty_risk_events (tenant_id, created_at DESC)');
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = :schema
               AND table_name = :table
               AND column_name = :column'
        );
        $stmt->execute([
            'schema' => 'public',
            'table' => $table,
            'column' => $column,
        ]);

        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private function createIndexIfMissing(string $indexName, string $sql): void {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = :schema
               AND c.relname = :index_name
               AND c.relkind = :relkind'
        );
        $stmt->execute([
            'schema' => 'public',
            'index_name' => $indexName,
            'relkind' => 'i',
        ]);

        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $this->pdo->exec($sql);
    }
}
