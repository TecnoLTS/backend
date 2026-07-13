<?php

namespace App\Modules\LoyaltyRewards\Infrastructure;

use App\Modules\LoyaltyRewards\Domain\LoyaltyNavigationCatalog;
use PDO;

final class LoyaltySchema {
    public function __construct(private readonly PDO $pdo) {}

    public function ensure(): void {
        $this->createCoreTables();
        $this->createOperationalTables();
        $this->createNavigationTables();
        $this->addCompatibilityColumns();
        $this->normalizeFinancialReferences();
        $this->createIntegrityConstraints();
        $this->dropLegacyIdempotencyConstraint();
        $this->pdo->exec('DROP INDEX IF EXISTS loyalty_redemptions_validation_code_idx');
        $this->createIndexes();
        $this->seedNavigationCatalog();
    }

    public function defaultSettings(): array {
        return [
            'program' => [
                'name' => 'Programa de fidelizacion',
                'status' => 'active',
                'currencyCode' => 'USD',
                'timezone' => 'America/Guayaquil',
                'locale' => 'es-EC',
                'brandColor' => '#2b648f',
                'logoUrl' => '',
                'supportEmail' => '',
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
                'hexBackgroundColor' => '#2b648f',
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
            points_debt integer NOT NULL DEFAULT 0,
            lifetime_points integer NOT NULL DEFAULT 0,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, member_id)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_point_ledger (
            id text PRIMARY KEY,
            sequence_no bigint GENERATED BY DEFAULT AS IDENTITY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            program_id text NOT NULL,
            entry_type text NOT NULL,
            points integer NOT NULL,
            balance_after integer NOT NULL,
            reference text,
            source text,
            normalized_reference text,
            amount_minor bigint,
            currency_code text,
            rules_version integer,
            formula_snapshot jsonb DEFAULT \'{}\'::jsonb,
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
            claim_mode text NOT NULL DEFAULT \'staff_only\',
            claim_instructions text,
            claim_delivery_options jsonb DEFAULT \'[]\'::jsonb,
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
            source text NOT NULL DEFAULT \'dashboard\',
            fulfillment_type text,
            validation_code_hash text,
            code_expires_at timestamp without time zone,
            expires_at timestamp without time zone,
            resolved_at timestamp without time zone,
            resolved_by_user_id text,
            resolution_note text,
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
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_portal_otp_challenges (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            channel text NOT NULL,
            destination text NOT NULL,
            code_hash text NOT NULL,
            attempts integer NOT NULL DEFAULT 0,
            max_attempts integer NOT NULL DEFAULT 5,
            expires_at timestamp without time zone NOT NULL,
            consumed_at timestamp without time zone,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_portal_sessions (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            token_hash text NOT NULL,
            expires_at timestamp without time zone NOT NULL,
            exchanged_at timestamp without time zone,
            revoked_at timestamp without time zone,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            last_used_at timestamp without time zone,
            UNIQUE (tenant_id, token_hash)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_portal_form_nonces (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            session_id text NOT NULL,
            member_id text NOT NULL,
            action text NOT NULL,
            nonce_hash text NOT NULL,
            expires_at timestamp without time zone NOT NULL,
            consumed_at timestamp without time zone,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, session_id, nonce_hash)
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
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_api_rate_limit_counters (
            tenant_id text NOT NULL,
            api_client_id text NOT NULL,
            window_started_at timestamp without time zone NOT NULL,
            request_count integer NOT NULL DEFAULT 0,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            PRIMARY KEY (tenant_id, api_client_id)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_api_usage_daily (
            tenant_id text NOT NULL,
            api_client_id text NOT NULL,
            usage_date date NOT NULL,
            request_count bigint NOT NULL DEFAULT 0,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            PRIMARY KEY (tenant_id, api_client_id, usage_date)
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
            debt_created integer NOT NULL DEFAULT 0,
            reason text NOT NULL,
            created_by_user_id text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, original_reference)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_debt_ledger (
            id text PRIMARY KEY,
            sequence_no bigint GENERATED BY DEFAULT AS IDENTITY,
            tenant_id text NOT NULL,
            member_id text NOT NULL,
            program_id text NOT NULL,
            entry_type text NOT NULL,
            points integer NOT NULL,
            debt_after integer NOT NULL,
            reference text,
            source text NOT NULL,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_by_user_id text,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_command_journal (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            operation text NOT NULL,
            command_id text NOT NULL,
            request_hash text NOT NULL,
            status text NOT NULL DEFAULT \'processing\',
            response_payload jsonb DEFAULT \'{}\'::jsonb NOT NULL,
            actor_type text NOT NULL,
            actor_id text,
            request_id text,
            source text NOT NULL,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            completed_at timestamp without time zone,
            UNIQUE (tenant_id, operation, command_id)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_earning_rule_versions (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            program_id text NOT NULL,
            version integer NOT NULL,
            rule_hash text NOT NULL,
            formula_snapshot jsonb NOT NULL,
            created_by_user_id text,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, version),
            UNIQUE (tenant_id, rule_hash)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_api_request_nonces (
            tenant_id text NOT NULL,
            api_client_id text NOT NULL,
            nonce_hash text NOT NULL,
            request_timestamp timestamp with time zone NOT NULL,
            expires_at timestamp with time zone NOT NULL,
            created_at timestamp with time zone DEFAULT NOW() NOT NULL,
            PRIMARY KEY (tenant_id, api_client_id, nonce_hash)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_wallet_campaigns (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            created_by_user_id text,
            title text NOT NULL DEFAULT \'\',
            body text NOT NULL,
            audience_type text NOT NULL,
            audience_filter jsonb DEFAULT \'{}\'::jsonb NOT NULL,
            status text NOT NULL DEFAULT \'pending\',
            total_recipients integer NOT NULL DEFAULT 0,
            sent_count integer NOT NULL DEFAULT 0,
            failed_count integer NOT NULL DEFAULT 0,
            skipped_count integer NOT NULL DEFAULT 0,
            delivery_unknown_count integer NOT NULL DEFAULT 0,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            started_at timestamp without time zone,
            finished_at timestamp without time zone
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_wallet_campaign_recipients (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            campaign_id text NOT NULL,
            member_id text NOT NULL,
            account_id text NOT NULL,
            status text NOT NULL DEFAULT \'pending\',
            attempts integer NOT NULL DEFAULT 0,
            message_id text,
            last_error text,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (campaign_id, member_id)
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_wallet_campaign_recipients_pending
            ON loyalty_wallet_campaign_recipients (tenant_id, status)');
    }

    private function createNavigationTables(): void {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_navigation_items (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            item_key text NOT NULL,
            parent_item_key text,
            item_kind text NOT NULL,
            label text NOT NULL,
            icon text,
            route_key text,
            sort_order integer NOT NULL DEFAULT 0,
            depth smallint NOT NULL DEFAULT 1,
            mandatory boolean NOT NULL DEFAULT false,
            status text NOT NULL DEFAULT \'active\',
            catalog_version text NOT NULL,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, item_key),
            CONSTRAINT loyalty_navigation_items_kind_check
                CHECK (item_kind IN (\'section\', \'group\', \'page\')),
            CONSTRAINT loyalty_navigation_items_status_check
                CHECK (status IN (\'active\', \'inactive\')),
            CONSTRAINT loyalty_navigation_items_depth_check
                CHECK (depth BETWEEN 0 AND 3),
            CONSTRAINT loyalty_navigation_items_section_shape_check
                CHECK (
                    (item_kind = \'section\' AND parent_item_key IS NULL AND depth = 0 AND route_key IS NULL)
                    OR
                    (item_kind <> \'section\' AND parent_item_key IS NOT NULL AND depth BETWEEN 1 AND 3)
                ),
            CONSTRAINT loyalty_navigation_items_page_route_check
                CHECK (item_kind <> \'page\' OR route_key IS NOT NULL),
            CONSTRAINT loyalty_navigation_items_parent_fk
                FOREIGN KEY (tenant_id, parent_item_key)
                REFERENCES loyalty_navigation_items (tenant_id, item_key)
                DEFERRABLE INITIALLY DEFERRED
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS loyalty_navigation_item_actions (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            item_key text NOT NULL,
            action_key text NOT NULL,
            permission_key text NOT NULL,
            label text NOT NULL,
            dangerous boolean NOT NULL DEFAULT false,
            sort_order integer NOT NULL DEFAULT 0,
            status text NOT NULL DEFAULT \'active\',
            catalog_version text NOT NULL,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL,
            UNIQUE (tenant_id, item_key, action_key),
            UNIQUE (tenant_id, permission_key),
            CONSTRAINT loyalty_navigation_actions_action_check
                CHECK (action_key IN (
                    \'view\', \'create\', \'update\', \'delete\', \'reverse\', \'approve\', \'deliver\',
                    \'cancel\', \'export\', \'assign_roles\', \'unlock\', \'invite\', \'revoke_sessions\',
                    \'adjust_points\'
                )),
            CONSTRAINT loyalty_navigation_actions_status_check
                CHECK (status IN (\'active\', \'inactive\')),
            CONSTRAINT loyalty_navigation_actions_item_fk
                FOREIGN KEY (tenant_id, item_key)
                REFERENCES loyalty_navigation_items (tenant_id, item_key)
                ON DELETE CASCADE
        )');
    }

    private function seedNavigationCatalog(): void {
        $tenantId = LoyaltyNavigationCatalog::INITIAL_TENANT_ID;
        $version = LoyaltyNavigationCatalog::VERSION;
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // Las acciones no son editables por administradores. Se reconstruyen
            // de forma atomica para que una version futura pueda mover una
            // permission_key entre opciones sin chocar con el UNIQUE tenant.
            $deleteActions = $this->pdo->prepare(
                'DELETE FROM loyalty_navigation_item_actions WHERE tenant_id = :tenant_id'
            );
            $deleteActions->execute(['tenant_id' => $tenantId]);

            $itemStatement = $this->pdo->prepare(
                'INSERT INTO loyalty_navigation_items
                    (id, tenant_id, item_key, parent_item_key, item_kind, label, icon, route_key,
                     sort_order, depth, mandatory, status, catalog_version)
                 VALUES
                    (:id, :tenant_id, :item_key, :parent_item_key, :item_kind, :label, :icon, :route_key,
                     :sort_order, :depth, :mandatory, \'active\', :catalog_version)
                 ON CONFLICT (tenant_id, item_key) DO UPDATE SET
                    parent_item_key = EXCLUDED.parent_item_key,
                    item_kind = EXCLUDED.item_kind,
                    label = EXCLUDED.label,
                    icon = EXCLUDED.icon,
                    route_key = EXCLUDED.route_key,
                    sort_order = EXCLUDED.sort_order,
                    depth = EXCLUDED.depth,
                    mandatory = EXCLUDED.mandatory,
                    status = \'active\',
                    catalog_version = EXCLUDED.catalog_version,
                    updated_at = NOW()'
            );
            $actionStatement = $this->pdo->prepare(
                'INSERT INTO loyalty_navigation_item_actions
                    (id, tenant_id, item_key, action_key, permission_key, label, dangerous,
                     sort_order, status, catalog_version)
                 VALUES
                    (:id, :tenant_id, :item_key, :action_key, :permission_key, :label, :dangerous,
                     :sort_order, \'active\', :catalog_version)
                 ON CONFLICT (tenant_id, item_key, action_key) DO UPDATE SET
                    permission_key = EXCLUDED.permission_key,
                    label = EXCLUDED.label,
                    dangerous = EXCLUDED.dangerous,
                    sort_order = EXCLUDED.sort_order,
                    status = \'active\',
                    catalog_version = EXCLUDED.catalog_version,
                    updated_at = NOW()'
            );

            foreach (LoyaltyNavigationCatalog::definitions() as $definition) {
                $itemKey = (string)$definition['key'];
                $itemStatement->execute([
                    'id' => $this->navigationId('nav', $tenantId, $itemKey),
                    'tenant_id' => $tenantId,
                    'item_key' => $itemKey,
                    'parent_item_key' => $definition['parentKey'],
                    'item_kind' => $definition['kind'],
                    'label' => $definition['label'],
                    'icon' => $definition['icon'],
                    'route_key' => $definition['routeKey'],
                    'sort_order' => $definition['sortOrder'],
                    'depth' => $definition['depth'],
                    'mandatory' => !empty($definition['mandatory']) ? 'true' : 'false',
                    'catalog_version' => $version,
                ]);

                foreach ($definition['actions'] as $actionOrder => $action) {
                    $actionKey = (string)$action['key'];
                    $actionStatement->execute([
                        'id' => $this->navigationId('nav_action', $tenantId, $itemKey . ':' . $actionKey),
                        'tenant_id' => $tenantId,
                        'item_key' => $itemKey,
                        'action_key' => $actionKey,
                        'permission_key' => $action['permissionKey'],
                        'label' => $action['label'],
                        'dangerous' => !empty($action['dangerous']) ? 'true' : 'false',
                        'sort_order' => ($actionOrder + 1) * 10,
                        'catalog_version' => $version,
                    ]);
                }
            }

            $deactivateItems = $this->pdo->prepare(
                'UPDATE loyalty_navigation_items
                 SET status = \'inactive\', updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND catalog_version <> :catalog_version'
            );
            $deactivateItems->execute(['tenant_id' => $tenantId, 'catalog_version' => $version]);

            $deactivateActions = $this->pdo->prepare(
                'UPDATE loyalty_navigation_item_actions
                 SET status = \'inactive\', updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND catalog_version <> :catalog_version'
            );
            $deactivateActions->execute(['tenant_id' => $tenantId, 'catalog_version' => $version]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function addCompatibilityColumns(): void {
        $this->addColumnIfMissing('loyalty_point_ledger', 'source_reference', 'text');
        $this->addColumnIfMissing('loyalty_point_ledger', 'sequence_no', 'bigint GENERATED BY DEFAULT AS IDENTITY');
        $this->addColumnIfMissing('loyalty_point_ledger', 'reversed_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_point_ledger', 'expires_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_point_ledger', 'normalized_reference', 'text');
        $this->addColumnIfMissing('loyalty_point_ledger', 'amount_minor', 'bigint');
        $this->addColumnIfMissing('loyalty_point_ledger', 'currency_code', 'text');
        $this->addColumnIfMissing('loyalty_point_ledger', 'rules_version', 'integer');
        $this->addColumnIfMissing('loyalty_point_ledger', 'formula_snapshot', 'jsonb DEFAULT \'{}\'::jsonb');
        $this->addColumnIfMissing('loyalty_point_accounts', 'points_debt', 'integer NOT NULL DEFAULT 0');
        $this->addColumnIfMissing('loyalty_debt_ledger', 'sequence_no', 'bigint GENERATED BY DEFAULT AS IDENTITY');
        $this->addColumnIfMissing('loyalty_reversals', 'debt_created', 'integer NOT NULL DEFAULT 0');
        $this->addColumnIfMissing('loyalty_api_clients', 'credential_version', 'integer NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('loyalty_api_clients', 'rotated_from_client_id', 'text');
        $this->addColumnIfMissing('loyalty_rewards', 'claim_mode', 'text NOT NULL DEFAULT \'staff_only\'');
        $this->addColumnIfMissing('loyalty_rewards', 'claim_instructions', 'text');
        $this->addColumnIfMissing('loyalty_rewards', 'claim_delivery_options', 'jsonb DEFAULT \'[]\'::jsonb');
        $this->addColumnIfMissing('loyalty_rewards', 'image_url', 'text');
        $this->addColumnIfMissing('loyalty_redemptions', 'created_by_user_id', 'text');
        $this->addColumnIfMissing('loyalty_redemptions', 'source', 'text NOT NULL DEFAULT \'dashboard\'');
        $this->addColumnIfMissing('loyalty_redemptions', 'fulfillment_type', 'text');
        $this->addColumnIfMissing('loyalty_redemptions', 'validation_code_hash', 'text');
        $this->addColumnIfMissing('loyalty_redemptions', 'code_expires_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_redemptions', 'expires_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_redemptions', 'resolved_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_redemptions', 'resolved_by_user_id', 'text');
        $this->addColumnIfMissing('loyalty_redemptions', 'resolution_note', 'text');
        $this->addColumnIfMissing('loyalty_portal_otp_challenges', 'metadata', 'jsonb DEFAULT \'{}\'::jsonb');
        $this->addColumnIfMissing('loyalty_portal_sessions', 'exchanged_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_members', 'blocked_reason', 'text');
        $this->addColumnIfMissing('loyalty_members', 'blocked_at', 'timestamp without time zone');
        $this->addColumnIfMissing('loyalty_risk_events', 'resolved_by_user_id', 'text');
        $this->addColumnIfMissing('loyalty_risk_events', 'resolution_note', 'text');
        $this->addColumnIfMissing('loyalty_wallet_campaigns', 'delivery_unknown_count', 'integer NOT NULL DEFAULT 0');
    }

    private function createIndexes(): void {
        $this->createOptionalExtension('pg_trgm');
        $this->createIndexIfMissing('loyalty_programs_tenant_uidx', 'CREATE UNIQUE INDEX loyalty_programs_tenant_uidx ON loyalty_programs (tenant_id)');
        $this->createIndexIfMissing('loyalty_members_tenant_email_uidx', "CREATE UNIQUE INDEX loyalty_members_tenant_email_uidx ON loyalty_members (tenant_id, lower(email)) WHERE COALESCE(email, '') <> ''");
        $this->createIndexIfMissing('loyalty_wallet_passes_tenant_member_platform_uidx', 'CREATE UNIQUE INDEX loyalty_wallet_passes_tenant_member_platform_uidx ON loyalty_wallet_passes (tenant_id, member_id, platform)');
        $this->createIndexIfMissing('loyalty_members_tenant_search_idx', 'CREATE INDEX loyalty_members_tenant_search_idx ON loyalty_members (tenant_id, lower(account_name), lower(email), lower(account_id), lower(COALESCE(phone, \'\')))');
        $this->createIndexIfMissing('loyalty_members_tenant_status_idx', 'CREATE INDEX loyalty_members_tenant_status_idx ON loyalty_members (tenant_id, status, tier, wallet_platform)');
        $this->createIndexIfMissing('loyalty_members_report_cursor_idx', 'CREATE INDEX loyalty_members_report_cursor_idx ON loyalty_members (tenant_id, created_at, id)');
        $this->createOptionalIndexIfMissing('loyalty_members_tenant_account_id_idx', 'CREATE INDEX loyalty_members_tenant_account_id_idx ON loyalty_members (tenant_id, account_id)');
        $this->createOptionalIndexIfMissing('loyalty_members_tenant_lower_email_idx', 'CREATE INDEX loyalty_members_tenant_lower_email_idx ON loyalty_members (tenant_id, lower(email))');
        $this->createOptionalIndexIfMissing('loyalty_members_account_name_trgm_idx', 'CREATE INDEX loyalty_members_account_name_trgm_idx ON loyalty_members USING gin (lower(account_name) gin_trgm_ops)');
        $this->createOptionalIndexIfMissing('loyalty_members_email_trgm_idx', 'CREATE INDEX loyalty_members_email_trgm_idx ON loyalty_members USING gin (lower(email) gin_trgm_ops)');
        $this->createOptionalIndexIfMissing('loyalty_members_account_id_trgm_idx', 'CREATE INDEX loyalty_members_account_id_trgm_idx ON loyalty_members USING gin (lower(account_id) gin_trgm_ops)');
        $this->createOptionalIndexIfMissing('loyalty_members_phone_trgm_idx', 'CREATE INDEX loyalty_members_phone_trgm_idx ON loyalty_members USING gin (lower(COALESCE(phone, \'\')) gin_trgm_ops)');
        $this->createOptionalIndexIfMissing('loyalty_rewards_name_trgm_idx', 'CREATE INDEX loyalty_rewards_name_trgm_idx ON loyalty_rewards USING gin (lower(name) gin_trgm_ops)');
        $this->createIndexIfMissing('loyalty_ledger_tenant_created_idx', 'CREATE INDEX loyalty_ledger_tenant_created_idx ON loyalty_point_ledger (tenant_id, created_at DESC)');
        $this->createIndexIfMissing('loyalty_ledger_member_sequence_idx', 'CREATE INDEX loyalty_ledger_member_sequence_idx ON loyalty_point_ledger (tenant_id, member_id, sequence_no)');
        $this->createIndexIfMissing('loyalty_ledger_report_cursor_idx', 'CREATE INDEX loyalty_ledger_report_cursor_idx ON loyalty_point_ledger (tenant_id, member_id, created_at, id)');
        $this->createIndexIfMissing('loyalty_ledger_reference_idx', 'CREATE INDEX loyalty_ledger_reference_idx ON loyalty_point_ledger (tenant_id, source, reference)');
        $this->createIndexIfMissing('loyalty_ledger_active_purchase_reference_uidx', "CREATE UNIQUE INDEX loyalty_ledger_active_purchase_reference_uidx ON loyalty_point_ledger (tenant_id, reference) WHERE entry_type = 'purchase' AND reversed_at IS NULL");
        $this->createIndexIfMissing('loyalty_ledger_active_purchase_normalized_reference_uidx', "CREATE UNIQUE INDEX loyalty_ledger_active_purchase_normalized_reference_uidx ON loyalty_point_ledger (tenant_id, normalized_reference) WHERE entry_type = 'purchase' AND reversed_at IS NULL AND normalized_reference IS NOT NULL");
        $this->createIndexIfMissing('loyalty_debt_ledger_member_idx', 'CREATE INDEX loyalty_debt_ledger_member_idx ON loyalty_debt_ledger (tenant_id, member_id, created_at, id)');
        $this->createIndexIfMissing('loyalty_debt_ledger_member_sequence_idx', 'CREATE INDEX loyalty_debt_ledger_member_sequence_idx ON loyalty_debt_ledger (tenant_id, member_id, sequence_no)');
        $this->createIndexIfMissing('loyalty_command_journal_actor_idx', 'CREATE INDEX loyalty_command_journal_actor_idx ON loyalty_command_journal (tenant_id, actor_type, actor_id, created_at DESC)');
        $this->createIndexIfMissing('loyalty_api_request_nonces_expiry_idx', 'CREATE INDEX loyalty_api_request_nonces_expiry_idx ON loyalty_api_request_nonces (expires_at)');
        $this->createIndexIfMissing('loyalty_redemptions_member_day_idx', 'CREATE INDEX loyalty_redemptions_member_day_idx ON loyalty_redemptions (tenant_id, member_id, created_at DESC)');
        $this->createIndexIfMissing('loyalty_redemptions_report_cursor_idx', 'CREATE INDEX loyalty_redemptions_report_cursor_idx ON loyalty_redemptions (tenant_id, created_at, id)');
        $this->createIndexIfMissing('loyalty_wallet_passes_report_cursor_idx', 'CREATE INDEX loyalty_wallet_passes_report_cursor_idx ON loyalty_wallet_passes (tenant_id, created_at, id)');
        $this->createIndexIfMissing('loyalty_rewards_claim_mode_idx', 'CREATE INDEX loyalty_rewards_claim_mode_idx ON loyalty_rewards (tenant_id, claim_mode, status)');
        $this->createIndexIfMissing('loyalty_redemptions_claim_queue_idx', 'CREATE INDEX loyalty_redemptions_claim_queue_idx ON loyalty_redemptions (tenant_id, source, status, created_at DESC)');
        $this->createIndexIfMissing('loyalty_redemptions_claim_created_idx', 'CREATE INDEX loyalty_redemptions_claim_created_idx ON loyalty_redemptions (tenant_id, source, created_at DESC)');
        $this->createIndexIfMissing('loyalty_redemptions_claim_fulfillment_idx', 'CREATE INDEX loyalty_redemptions_claim_fulfillment_idx ON loyalty_redemptions (tenant_id, source, fulfillment_type, created_at DESC)');
        $this->createIndexIfMissing('loyalty_portal_otp_member_idx', 'CREATE INDEX loyalty_portal_otp_member_idx ON loyalty_portal_otp_challenges (tenant_id, member_id, created_at DESC)');
        $this->createIndexIfMissing('loyalty_portal_otp_open_idx', 'CREATE INDEX loyalty_portal_otp_open_idx ON loyalty_portal_otp_challenges (tenant_id, expires_at) WHERE consumed_at IS NULL');
        $this->createIndexIfMissing('loyalty_portal_sessions_member_idx', 'CREATE INDEX loyalty_portal_sessions_member_idx ON loyalty_portal_sessions (tenant_id, member_id, expires_at DESC)');
        $this->createIndexIfMissing('loyalty_portal_form_nonces_open_idx', 'CREATE INDEX loyalty_portal_form_nonces_open_idx ON loyalty_portal_form_nonces (tenant_id, session_id, expires_at) WHERE consumed_at IS NULL');
        $this->createIndexIfMissing('loyalty_redemptions_expiry_idx', 'CREATE INDEX loyalty_redemptions_expiry_idx ON loyalty_redemptions (tenant_id, expires_at) WHERE expires_at IS NOT NULL');
        $this->createIndexIfMissing('loyalty_redemptions_active_validation_code_uidx', "CREATE UNIQUE INDEX loyalty_redemptions_active_validation_code_uidx ON loyalty_redemptions (tenant_id, validation_code_hash) WHERE status = 'ready_for_pickup' AND validation_code_hash IS NOT NULL");
        $this->createIndexIfMissing('loyalty_api_clients_hash_idx', 'CREATE INDEX loyalty_api_clients_hash_idx ON loyalty_api_clients (tenant_id, key_hash)');
        $this->createIndexIfMissing('loyalty_idempotency_client_key_uidx', "CREATE UNIQUE INDEX loyalty_idempotency_client_key_uidx ON loyalty_idempotency_keys (tenant_id, COALESCE(api_client_id, ''), idempotency_key, operation)");
        $this->createIndexIfMissing('loyalty_audit_tenant_created_idx', 'CREATE INDEX loyalty_audit_tenant_created_idx ON loyalty_audit_events (tenant_id, created_at DESC)');
        $this->createIndexIfMissing('loyalty_risk_tenant_created_idx', 'CREATE INDEX loyalty_risk_tenant_created_idx ON loyalty_risk_events (tenant_id, created_at DESC)');
        $this->createIndexIfMissing('loyalty_audit_report_cursor_idx', 'CREATE INDEX loyalty_audit_report_cursor_idx ON loyalty_audit_events (tenant_id, created_at, id)');
        $this->createIndexIfMissing('loyalty_risk_report_cursor_idx', 'CREATE INDEX loyalty_risk_report_cursor_idx ON loyalty_risk_events (tenant_id, created_at, id)');
        $this->createIndexIfMissing('loyalty_api_usage_report_cursor_idx', 'CREATE INDEX loyalty_api_usage_report_cursor_idx ON loyalty_api_usage_daily (tenant_id, usage_date, api_client_id)');
        $this->createIndexIfMissing('loyalty_navigation_items_tenant_tree_idx', 'CREATE INDEX loyalty_navigation_items_tenant_tree_idx ON loyalty_navigation_items (tenant_id, status, parent_item_key, sort_order)');
        $this->createIndexIfMissing('loyalty_navigation_actions_tenant_item_idx', 'CREATE INDEX loyalty_navigation_actions_tenant_item_idx ON loyalty_navigation_item_actions (tenant_id, status, item_key, sort_order)');
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

    private function normalizeFinancialReferences(): void {
        $this->pdo->exec(
            "UPDATE loyalty_point_ledger
             SET normalized_reference = UPPER(regexp_replace(BTRIM(reference), '\\s+', ' ', 'g'))
             WHERE entry_type = 'purchase'
               AND reference IS NOT NULL
               AND normalized_reference IS DISTINCT FROM UPPER(regexp_replace(BTRIM(reference), '\\s+', ' ', 'g'))"
        );
    }

    private function createIntegrityConstraints(): void {
        $this->pdo->exec('ALTER TABLE loyalty_navigation_item_actions DROP CONSTRAINT IF EXISTS loyalty_navigation_actions_action_check');
        $this->pdo->exec(
            'ALTER TABLE loyalty_navigation_item_actions
             ADD CONSTRAINT loyalty_navigation_actions_action_check
             CHECK (action_key IN (
                \'view\', \'create\', \'update\', \'delete\', \'reverse\', \'approve\', \'deliver\',
                \'cancel\', \'export\', \'assign_roles\', \'unlock\', \'invite\', \'revoke_sessions\',
                \'adjust_points\'
             ))'
        );
        $this->addCheckConstraintIfMissing(
            'loyalty_point_accounts',
            'loyalty_point_accounts_nonnegative_check',
            'balance >= 0 AND points_debt >= 0 AND lifetime_points >= 0'
        );
        $this->addCheckConstraintIfMissing(
            'loyalty_point_ledger',
            'loyalty_point_ledger_nonnegative_after_check',
            'balance_after >= 0'
        );
        $this->addCheckConstraintIfMissing(
            'loyalty_rewards',
            'loyalty_rewards_financial_values_check',
            'points_cost > 0 AND stock >= 0'
        );
        $this->addCheckConstraintIfMissing(
            'loyalty_redemptions',
            'loyalty_redemptions_positive_cost_check',
            'points_cost > 0'
        );
        $this->addCheckConstraintIfMissing(
            'loyalty_debt_ledger',
            'loyalty_debt_ledger_nonnegative_after_check',
            'debt_after >= 0'
        );
        $this->addCheckConstraintIfMissing(
            'loyalty_command_journal',
            'loyalty_command_journal_status_check',
            "status IN ('processing', 'completed')"
        );
    }

    private function addCheckConstraintIfMissing(string $table, string $constraint, string $expression): void {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM pg_constraint WHERE conrelid = to_regclass(:table) AND conname = :constraint'
        );
        $stmt->execute(['table' => $table, 'constraint' => $constraint]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $quoted = str_replace('"', '""', $constraint);
        $this->pdo->exec("ALTER TABLE {$table} ADD CONSTRAINT \"{$quoted}\" CHECK ({$expression})");
    }

    private function dropLegacyIdempotencyConstraint(): void {
        $stmt = $this->pdo->query(
            "SELECT conname
             FROM pg_constraint
             WHERE conrelid = 'loyalty_idempotency_keys'::regclass
               AND contype = 'u'
               AND pg_get_constraintdef(oid) = 'UNIQUE (tenant_id, idempotency_key, operation)'"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $constraintName) {
            $quoted = str_replace('"', '""', (string)$constraintName);
            $this->pdo->exec('ALTER TABLE loyalty_idempotency_keys DROP CONSTRAINT "' . $quoted . '"');
        }
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

    private function createOptionalExtension(string $extension): void {
        try {
            $this->pdo->exec("CREATE EXTENSION IF NOT EXISTS {$extension}");
        } catch (\Throwable) {
            // Optional search acceleration; deployments without extension privileges still run.
        }
    }

    private function createOptionalIndexIfMissing(string $indexName, string $sql): void {
        try {
            $this->createIndexIfMissing($indexName, $sql);
        } catch (\Throwable) {
            // Optional index that depends on extension availability or DB privileges.
        }
    }

    private function navigationId(string $prefix, string $tenantId, string $key): string {
        return $prefix . '_' . substr(hash('sha256', $tenantId . '|' . $key), 0, 24);
    }
}
