<?php

namespace App\Modules\LoyaltyRewards\Infrastructure;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use App\Modules\LoyaltyRewards\Infrastructure\Wallet\GoogleWalletFactory;
use App\Modules\LoyaltyRewards\Infrastructure\Wallet\GoogleWalletService;
use App\Services\MailService;
use PDO;

final class LoyaltyRepository {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
        $this->ensureSchema();
        $this->ensureDemoData($this->tenantId());
    }

    public function dashboard(?string $month = null): array {
        $tenantId = $this->tenantId();
        $program = $this->program($tenantId);
        $month = $this->normalizeDashboardMonth($month);
        $dateScope = $this->dashboardDateScope($month);

        $metrics = [
            'activeMembers' => (int)$this->scalar(
                'SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id AND status = :status',
                ['tenant_id' => $tenantId, 'status' => 'active']
            ),
            'digitalMembers' => (int)$this->scalar(
                "SELECT COUNT(*) FROM loyalty_members
                 WHERE tenant_id = :tenant_id
                   AND status = 'active'
                   AND wallet_platform IN ('google', 'apple')",
                ['tenant_id' => $tenantId]
            ),
            'issuedPoints' => (int)$this->scalar(
                "SELECT COALESCE(SUM(points), 0) FROM loyalty_point_ledger WHERE tenant_id = :tenant_id AND points > 0",
                ['tenant_id' => $tenantId]
            ),
            'monthlyRedemptions' => (int)$this->scalar(
                "SELECT COUNT(*) FROM loyalty_redemptions WHERE tenant_id = :tenant_id AND created_at >= date_trunc('month', NOW())",
                ['tenant_id' => $tenantId]
            ),
            'averageTicket' => (float)$this->scalar(
                "SELECT COALESCE(AVG((metadata->>'invoiceAmount')::numeric), 0)
                 FROM loyalty_point_ledger
                 WHERE tenant_id = :tenant_id
                   AND entry_type = 'purchase'
                   AND jsonb_exists(metadata, 'invoiceAmount')",
                ['tenant_id' => $tenantId]
            ),
        ];

        return [
            'program' => $program,
            'metrics' => $metrics,
            'topCustomers' => $this->dashboardTopCustomers($tenantId, $dateScope),
            'recentConsumptions' => $this->recentConsumptions($tenantId, $dateScope),
            'walletSummary' => $this->walletSummary($tenantId),
            'recentRedemptions' => $this->recentRedemptions($tenantId, $dateScope),
            'recommendedActions' => $this->recommendedActions($tenantId),
            'analytics' => $this->dashboardAnalytics($tenantId, $metrics, $dateScope),
        ];
    }

    public function customers(?string $search = null): array {
        return $this->customersPage([
            'q' => $search,
            'limit' => 150,
            'offset' => 0,
            'sort' => 'recent',
        ])['items'];
    }

    public function customersPage(array $filters = []): array {
        $tenantId = $this->tenantId();
        $limit = min(100, max(10, (int)($filters['limit'] ?? 25)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        [$where, $params] = $this->customerWhere($tenantId, $filters);
        $orderBy = $this->customerOrderBy((string)($filters['sort'] ?? 'recent'));
        $countTotal = !in_array(strtolower((string)($filters['count'] ?? '1')), ['0', 'false', 'no'], true);
        $queryLimit = $countTotal ? $limit : $limit + 1;

        $total = null;
        if ($countTotal) {
            $total = (int)$this->scalar(
                "SELECT COUNT(*)
                 FROM loyalty_members m
                 LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
                 WHERE {$where}",
                $params
            );
        }

        $items = $this->fetchAll(
            "SELECT m.id, m.account_name AS name, m.account_id, m.email, m.phone, m.tier, m.status,
                    m.wallet_platform, COALESCE(a.balance, 0) AS points, m.last_activity_at, m.created_at
             FROM loyalty_members m
             LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT {$queryLimit} OFFSET {$offset}",
            $params
        );
        $hasMore = $countTotal
            ? ($offset + $limit) < (int)$total
            : count($items) > $limit;
        if (!$countTotal && $hasMore) {
            $items = array_slice($items, 0, $limit);
        }
        $total = $countTotal ? (int)$total : $offset + count($items) + ($hasMore ? 1 : 0);

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => $hasMore,
        ];
    }

    public function customerDetail(string $memberId): array {
        $member = $this->memberById($memberId);
        if (!$member) {
            throw new \RuntimeException('Socio no encontrado.');
        }

        $tenantId = $this->tenantId();

        return [
            'member' => $member,
            'ledger' => $this->fetchAll(
                "SELECT id, entry_type, points, balance_after, reference, source, metadata, created_at
                 FROM loyalty_point_ledger
                 WHERE tenant_id = :tenant_id AND member_id = :member_id
                 ORDER BY created_at DESC
                 LIMIT 12",
                ['tenant_id' => $tenantId, 'member_id' => $memberId]
            ),
            'redemptions' => $this->fetchAll(
                "SELECT r.id, r.member_id, w.name AS reward, r.points_cost, r.status, r.metadata, r.created_at
                 FROM loyalty_redemptions r
                 JOIN loyalty_rewards w ON w.id = r.reward_id AND w.tenant_id = r.tenant_id
                 WHERE r.tenant_id = :tenant_id AND r.member_id = :member_id
                 ORDER BY r.created_at DESC
                 LIMIT 8",
                ['tenant_id' => $tenantId, 'member_id' => $memberId]
            ),
            'walletPasses' => $this->fetchAll(
                "SELECT DISTINCT ON (platform) id, platform, external_object_id, status, updated_at, created_at
                 FROM loyalty_wallet_passes
                 WHERE tenant_id = :tenant_id AND member_id = :member_id
                 ORDER BY platform, updated_at DESC NULLS LAST, created_at DESC",
                ['tenant_id' => $tenantId, 'member_id' => $memberId]
            ),
        ];
    }

    public function rewards(array $filters = []): array {
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->tenantId()];
        $query = mb_strtolower(trim((string)($filters['q'] ?? '')));
        $status = strtolower(trim((string)($filters['status'] ?? 'all')));
        $stock = strtolower(trim((string)($filters['stock'] ?? 'all')));
        if ($query !== '') {
            $where[] = '(lower(name) LIKE :q OR lower(COALESCE(description, \'\')) LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }
        if (in_array($status, ['active', 'inactive', 'deleted'], true)) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($stock === 'available') {
            $where[] = 'stock > 0';
        } elseif ($stock === 'empty') {
            $where[] = 'stock <= 0';
        }

        return $this->fetchAll(
            "SELECT id, name, description, points_cost, stock, status, image_url, metadata, created_at, updated_at,
                    (SELECT COUNT(*) FROM loyalty_redemptions r WHERE r.tenant_id = loyalty_rewards.tenant_id AND r.reward_id = loyalty_rewards.id) AS redemption_count
             FROM loyalty_rewards
             WHERE " . implode(' AND ', $where) . "
             ORDER BY CASE status WHEN 'active' THEN 1 WHEN 'inactive' THEN 2 ELSE 3 END, points_cost ASC, name ASC",
            $params
        );
    }

    public function createReward(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $name = trim((string)($payload['name'] ?? ''));
        $pointsCost = max(1, (int)($payload['pointsCost'] ?? $payload['points_cost'] ?? 0));
        if ($name === '' || $pointsCost <= 0) {
            throw new \InvalidArgumentException('Nombre y costo en puntos son obligatorios.');
        }

        $reward = [
            'id' => $this->id('reward'),
            'tenant_id' => $tenantId,
            'name' => $name,
            'description' => trim((string)($payload['description'] ?? '')),
            'points_cost' => $pointsCost,
            'stock' => max(0, (int)($payload['stock'] ?? 0)),
            'status' => trim((string)($payload['status'] ?? 'active')) ?: 'active',
            'image_url' => trim((string)($payload['imageUrl'] ?? $payload['image_url'] ?? '')),
            'metadata' => json_encode($payload['metadata'] ?? new \stdClass()),
        ];
        if (!in_array($reward['status'], ['active', 'inactive'], true)) {
            throw new \InvalidArgumentException('El estado del premio debe ser activo o inactivo.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO loyalty_rewards (id, tenant_id, name, description, points_cost, stock, status, image_url, metadata)
             VALUES (:id, :tenant_id, :name, :description, :points_cost, :stock, :status, :image_url, :metadata)'
        );
        $stmt->execute($reward);
        $created = $this->rewardById($reward['id']);
        $this->recordAudit('reward.created', 'reward', $reward['id'], null, $created, trim((string)($payload['reason'] ?? '')), $userId);

        return $created;
    }

    public function rewardDetail(string $rewardId): array {
        $reward = $this->rewardById($rewardId);
        if (!$reward) {
            throw new \RuntimeException('Premio no encontrado.');
        }

        $summary = $this->fetchAll(
            "SELECT COUNT(*) AS redemption_count,
                    COALESCE(SUM(points_cost), 0) AS points_redeemed,
                    MAX(created_at) AS last_redeemed_at
             FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id AND reward_id = :reward_id",
            ['tenant_id' => $this->tenantId(), 'reward_id' => $rewardId]
        )[0] ?? [];
        $recent = $this->fetchAll(
            "SELECT r.id, r.member_id, m.account_name AS customer, r.points_cost, r.status, r.created_at
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.id = r.member_id AND m.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant_id AND r.reward_id = :reward_id
             ORDER BY r.created_at DESC
             LIMIT 8",
            ['tenant_id' => $this->tenantId(), 'reward_id' => $rewardId]
        );

        return [
            'reward' => $reward,
            'summary' => $summary,
            'recentRedemptions' => $recent,
        ];
    }

    public function updateReward(string $rewardId, array $payload, ?string $userId = null): array {
        $before = $this->rewardById($rewardId);
        if (!$before) {
            throw new \RuntimeException('Premio no encontrado.');
        }

        $name = trim((string)($payload['name'] ?? $before['name'] ?? ''));
        $pointsCost = max(1, (int)($payload['pointsCost'] ?? $payload['points_cost'] ?? $before['points_cost'] ?? 0));
        $stock = max(0, (int)($payload['stock'] ?? $before['stock'] ?? 0));
        $status = strtolower(trim((string)($payload['status'] ?? $before['status'] ?? 'active')));
        if ($name === '' || $pointsCost <= 0) {
            throw new \InvalidArgumentException('Nombre y costo en puntos son obligatorios.');
        }
        if (!in_array($status, ['active', 'inactive', 'deleted'], true)) {
            throw new \InvalidArgumentException('El estado del premio debe ser activo, inactivo o eliminado.');
        }
        if (($before['status'] ?? '') === 'deleted' && $status !== 'deleted') {
            throw new \InvalidArgumentException('Un premio eliminado no puede reactivarse.');
        }

        $this->execute(
            'UPDATE loyalty_rewards
             SET name = :name,
                 description = :description,
                 points_cost = :points_cost,
                 stock = :stock,
                 status = :status,
                 image_url = :image_url,
                 updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id',
            [
                'name' => $name,
                'description' => trim((string)($payload['description'] ?? $before['description'] ?? '')),
                'points_cost' => $pointsCost,
                'stock' => $stock,
                'status' => $status,
                'image_url' => trim((string)($payload['imageUrl'] ?? $payload['image_url'] ?? $before['image_url'] ?? '')),
                'tenant_id' => $this->tenantId(),
                'id' => $rewardId,
            ]
        );
        $after = $this->rewardById($rewardId);
        $this->recordAudit('reward.updated', 'reward', $rewardId, $before, $after, trim((string)($payload['reason'] ?? '')), $userId);

        return $after;
    }

    public function deleteReward(string $rewardId, ?string $userId = null): array {
        $before = $this->rewardById($rewardId);
        if (!$before) {
            throw new \RuntimeException('Premio no encontrado.');
        }
        $redemptionCount = (int)$this->scalar(
            'SELECT COUNT(*) FROM loyalty_redemptions WHERE tenant_id = :tenant_id AND reward_id = :reward_id',
            ['tenant_id' => $this->tenantId(), 'reward_id' => $rewardId]
        );
        if ($redemptionCount > 0) {
            $this->execute(
                'UPDATE loyalty_rewards SET status = :status, updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['status' => 'deleted', 'tenant_id' => $this->tenantId(), 'id' => $rewardId]
            );
            $after = $this->rewardById($rewardId);
            $this->recordAudit('reward.deleted', 'reward', $rewardId, $before, $after, 'Baja logica por historial de canjes.', $userId);

            return ['deleted' => false, 'archived' => true, 'reward' => $after];
        }

        $this->execute('DELETE FROM loyalty_rewards WHERE tenant_id = :tenant_id AND id = :id', [
            'tenant_id' => $this->tenantId(),
            'id' => $rewardId,
        ]);
        $this->recordAudit('reward.deleted', 'reward', $rewardId, $before, ['deleted' => true], 'Premio sin historial eliminado.', $userId);

        return ['deleted' => true, 'archived' => false, 'reward' => $before];
    }

    public function createMember(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $program = $this->program($tenantId);
        $name = trim((string)($payload['name'] ?? $payload['accountName'] ?? $payload['account_name'] ?? ''));
        $email = mb_strtolower(trim((string)($payload['email'] ?? '')));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del socio es obligatorio.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo del socio es obligatorio y debe ser valido.');
        }
        $phone = trim((string)($payload['phone'] ?? ''));
        if ($phone === '') {
            throw new \InvalidArgumentException('El telefono del socio es obligatorio.');
        }

        $accountId = trim((string)($payload['accountId'] ?? $payload['account_id'] ?? ''));
        if ($accountId === '') {
            throw new \InvalidArgumentException('La cuenta del socio es obligatoria.');
        }
        $this->assertUniqueMemberIdentity($tenantId, $accountId, $email);

        $walletPlatform = strtolower(trim((string)($payload['walletPlatform'] ?? $payload['wallet_platform'] ?? 'none')));
        if (!in_array($walletPlatform, ['google', 'apple', 'none'], true)) {
            throw new \InvalidArgumentException('La tarjeta debe ser Android, iPhone o sin tarjeta.');
        }

        $memberId = $this->id('member');
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $this->pdo->beginTransaction();
        try {
            $this->execute(
                'INSERT INTO loyalty_members
                    (id, tenant_id, program_id, external_customer_id, account_id, account_name, email, phone, tier, status, wallet_platform, metadata, last_activity_at)
                 VALUES
                    (:id, :tenant_id, :program_id, :external_customer_id, :account_id, :account_name, :email, :phone, :tier, :status, :wallet_platform, :metadata, NOW())',
                [
                    'id' => $memberId,
                    'tenant_id' => $tenantId,
                    'program_id' => $program['id'],
                    'external_customer_id' => trim((string)($payload['externalCustomerId'] ?? $payload['external_customer_id'] ?? '')),
                    'account_id' => $accountId,
                    'account_name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'tier' => 'Bronce',
                    'status' => 'active',
                    'wallet_platform' => $walletPlatform,
                    'metadata' => json_encode($metadata),
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_accounts (id, tenant_id, member_id, program_id, balance, lifetime_points)
                 VALUES (:id, :tenant_id, :member_id, :program_id, 0, 0)',
                [
                    'id' => $this->id('account'),
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'program_id' => $program['id'],
                ]
            );
            if ($walletPlatform !== 'none') {
                $this->createWalletPass($tenantId, $memberId, $walletPlatform, $accountId, ['source' => 'member-create']);
            }
            $this->recordAudit('member.created', 'member', $memberId, null, ['accountId' => $accountId, 'email' => $email], null, $userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->throwMemberWriteException($e);
            throw $e;
        }

        return $this->memberById($memberId) ?? [];
    }

    public function updateMember(string $memberId, array $payload, ?string $userId = null): array {
        $member = $this->memberById($memberId);
        if (!$member) {
            throw new \RuntimeException('Socio no encontrado.');
        }

        $tenantId = $this->tenantId();
        $name = trim((string)($payload['name'] ?? $payload['accountName'] ?? $payload['account_name'] ?? $member['account_name']));
        $email = mb_strtolower(trim((string)($payload['email'] ?? $member['email'] ?? '')));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del socio es obligatorio.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El correo del socio es obligatorio y debe ser valido.');
        }
        $status = strtolower(trim((string)($payload['status'] ?? $member['status'])));
        if (!in_array($status, ['active', 'inactive', 'blocked'], true)) {
            throw new \InvalidArgumentException('El estado debe ser activo, inactivo o bloqueado.');
        }
        if ($status === 'blocked' && trim((string)($payload['reason'] ?? $payload['blockedReason'] ?? '')) === '') {
            throw new \InvalidArgumentException('Indica el motivo para bloquear al socio.');
        }
        $this->assertUniqueMemberIdentity($tenantId, (string)$member['account_id'], $email, $memberId);

        $before = $member;
        $this->pdo->beginTransaction();
        try {
            $this->execute(
                'UPDATE loyalty_members
                 SET account_name = :account_name,
                     email = :email,
                     phone = :phone,
                     status = :status,
                     blocked_reason = :blocked_reason,
                     blocked_at = CASE WHEN :status = \'blocked\' AND blocked_at IS NULL THEN NOW() WHEN :status <> \'blocked\' THEN NULL ELSE blocked_at END,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id',
                [
                    'account_name' => $name,
                    'email' => $email,
                    'phone' => trim((string)($payload['phone'] ?? $member['phone'] ?? '')),
                    'status' => $status,
                    'blocked_reason' => $status === 'blocked' ? trim((string)($payload['reason'] ?? $payload['blockedReason'] ?? '')) : null,
                    'tenant_id' => $tenantId,
                    'id' => $memberId,
                ]
            );
            $after = $this->memberById($memberId) ?? [];
            $this->recordAudit('member.updated', 'member', $memberId, $before, $after, trim((string)($payload['reason'] ?? '')), $userId);
            if ($status === 'blocked') {
                $this->recordRisk('medium', 'member_blocked', 'Socio bloqueado por operador.', $memberId, null, ['reason' => $payload['reason'] ?? $payload['blockedReason'] ?? '']);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->throwMemberWriteException($e);
            throw $e;
        }

        return $this->memberById($memberId) ?? [];
    }

    public function settings(): array {
        $tenantId = $this->tenantId();
        $program = $this->program($tenantId);
        $this->ensureProgramConfiguration($tenantId, (string)$program['id']);

        $rows = $this->fetchAll(
            'SELECT tenant_id, program_id, settings, updated_by_user_id, created_at, updated_at
             FROM loyalty_program_settings
             WHERE tenant_id = :tenant_id LIMIT 1',
            ['tenant_id' => $tenantId]
        );

        return [
            'program' => $program,
            // Merge sobre defaults: tenants con fila previa tambien ven secciones nuevas (p.ej. googleWallet).
            'settings' => $this->mergeSettings(
                (new LoyaltySchema($this->pdo))->defaultSettings(),
                is_array($rows[0]['settings'] ?? null) ? $rows[0]['settings'] : []
            ),
            'updatedAt' => $rows[0]['updated_at'] ?? null,
            'updatedByUserId' => $rows[0]['updated_by_user_id'] ?? null,
        ];
    }

    public function updateSettings(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $program = $this->program($tenantId);
        $before = $this->settings()['settings'];
        $settings = $this->mergeSettings($before, $payload['settings'] ?? $payload);
        $this->validateSettings($settings);

        $this->execute(
            'INSERT INTO loyalty_program_settings (tenant_id, program_id, settings, updated_by_user_id)
             VALUES (:tenant_id, :program_id, :settings, :updated_by_user_id)
             ON CONFLICT (tenant_id) DO UPDATE
             SET settings = EXCLUDED.settings,
                 updated_by_user_id = EXCLUDED.updated_by_user_id,
                 updated_at = NOW()',
            [
                'tenant_id' => $tenantId,
                'program_id' => $program['id'],
                'settings' => json_encode($settings),
                'updated_by_user_id' => $userId,
            ]
        );
        $programSettings = $settings['program'] ?? [];
        $this->execute(
            'UPDATE loyalty_programs
             SET name = :name, status = :status, currency_code = :currency_code, brand_color = :brand_color, logo_url = :logo_url, updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id',
            [
                'name' => trim((string)($programSettings['name'] ?? $program['name'] ?? 'Fidepuntos')),
                'status' => trim((string)($programSettings['status'] ?? $program['status'] ?? 'active')),
                'currency_code' => trim((string)($programSettings['currencyCode'] ?? $program['currency_code'] ?? 'USD')),
                'brand_color' => trim((string)($programSettings['brandColor'] ?? $program['brand_color'] ?? '#0f766e')),
                'logo_url' => trim((string)($programSettings['logoUrl'] ?? $program['logo_url'] ?? '')),
                'tenant_id' => $tenantId,
                'id' => $program['id'],
            ]
        );
        $this->recordAudit('settings.updated', 'program', (string)$program['id'], $before, $settings, trim((string)($payload['reason'] ?? '')), $userId);

        return $this->settings();
    }

    public function rules(): array {
        $tenantId = $this->tenantId();
        $program = $this->program($tenantId);
        $this->ensureProgramConfiguration($tenantId, (string)$program['id']);

        return [
            'settings' => $this->settings()['settings'],
            'tiers' => $this->tierRules($tenantId),
        ];
    }

    public function updateRules(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $program = $this->program($tenantId);
        $before = $this->rules();
        if (isset($payload['settings']) || isset($payload['earning']) || isset($payload['redemption']) || isset($payload['expiration'])) {
            $this->updateSettings($payload['settings'] ?? $payload, $userId);
        }

        if (isset($payload['tiers']) && is_array($payload['tiers'])) {
            $this->execute('DELETE FROM loyalty_tier_rules WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]);
            foreach ($payload['tiers'] as $index => $tier) {
                $name = trim((string)($tier['name'] ?? ''));
                if ($name === '') {
                    throw new \InvalidArgumentException('Cada nivel debe tener nombre.');
                }
                $this->execute(
                    'INSERT INTO loyalty_tier_rules
                        (id, tenant_id, program_id, name, min_lifetime_points, max_lifetime_points, multiplier, benefits, status, sort_order)
                     VALUES
                        (:id, :tenant_id, :program_id, :name, :min_lifetime_points, :max_lifetime_points, :multiplier, :benefits, :status, :sort_order)',
                    [
                        'id' => $this->id('tier'),
                        'tenant_id' => $tenantId,
                        'program_id' => $program['id'],
                        'name' => $name,
                        'min_lifetime_points' => max(0, (int)($tier['minLifetimePoints'] ?? $tier['min_lifetime_points'] ?? 0)),
                        'max_lifetime_points' => isset($tier['maxLifetimePoints']) && $tier['maxLifetimePoints'] !== '' ? (int)$tier['maxLifetimePoints'] : (isset($tier['max_lifetime_points']) && $tier['max_lifetime_points'] !== '' ? (int)$tier['max_lifetime_points'] : null),
                        'multiplier' => max(0.01, (float)($tier['multiplier'] ?? 1)),
                        'benefits' => json_encode(is_array($tier['benefits'] ?? null) ? $tier['benefits'] : []),
                        'status' => trim((string)($tier['status'] ?? 'active')) ?: 'active',
                        'sort_order' => (int)($tier['sortOrder'] ?? $tier['sort_order'] ?? ($index + 1)),
                    ]
                );
            }
            $this->refreshAllMemberTiers($tenantId);
        }

        $after = $this->rules();
        $this->recordAudit('rules.updated', 'program', (string)$program['id'], $before, $after, trim((string)($payload['reason'] ?? '')), $userId);

        return $after;
    }

    public function adjustPoints(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $member = $this->memberFromPayload($payload);
        if (!$member) {
            throw new \InvalidArgumentException('Selecciona un socio existente.');
        }
        $points = (int)($payload['points'] ?? 0);
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($points === 0 || $reason === '') {
            throw new \InvalidArgumentException('El ajuste requiere puntos diferentes de cero y un motivo.');
        }
        $this->assertMemberCanOperate($member, 'recibir ajustes');

        $program = $this->program($tenantId);
        $this->pdo->beginTransaction();
        try {
            $account = $this->accountForMember($tenantId, (string)$member['id']);
            $balanceAfter = (int)$account['balance'] + $points;
            if ($balanceAfter < 0) {
                $this->recordRisk('high', 'negative_balance_attempt', 'Ajuste bloqueado por saldo negativo.', (string)$member['id'], null, ['points' => $points]);
                throw new \InvalidArgumentException('El ajuste dejaria al socio con saldo negativo.');
            }
            $this->execute(
                'UPDATE loyalty_point_accounts
                 SET balance = :balance,
                     lifetime_points = CASE WHEN :points > 0 THEN lifetime_points + :points ELSE lifetime_points END,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id',
                [
                    'balance' => $balanceAfter,
                    'points' => $points,
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, source_reference, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :source_reference, :metadata, :created_by_user_id)',
                [
                    'id' => $this->id('ledger'),
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                    'program_id' => $program['id'],
                    'entry_type' => 'adjustment',
                    'points' => $points,
                    'balance_after' => $balanceAfter,
                    'reference' => $this->id('adjustment'),
                    'source' => 'dashboard',
                    'source_reference' => trim((string)($payload['reference'] ?? '')),
                    'metadata' => json_encode(['reason' => $reason, 'evidence' => trim((string)($payload['evidence'] ?? ''))]),
                    'created_by_user_id' => $userId,
                ]
            );
            $this->refreshMemberTier($tenantId, (string)$member['id']);
            $this->recordAudit('points.adjusted', 'member', (string)$member['id'], null, ['points' => $points, 'balanceAfter' => $balanceAfter], $reason, $userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->syncGoogleWalletBestEffort($member, $balanceAfter);

        return [
            'member' => $this->memberById((string)$member['id']),
            'pointsAdjusted' => $points,
            'balanceAfter' => $balanceAfter,
        ];
    }

    public function registerPurchase(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $amount = round((float)($payload['invoiceAmount'] ?? $payload['amount'] ?? 0), 2);
        $invoiceNumber = trim((string)($payload['invoiceNumber'] ?? $payload['invoice_number'] ?? ''));
        if ($amount <= 0 || $invoiceNumber === '') {
            throw new \InvalidArgumentException('Monto de factura y numero de factura son obligatorios.');
        }

        $member = $this->memberFromPayload($payload);
        if (!$member) {
            throw new \InvalidArgumentException('Selecciona un socio existente antes de registrar la compra.');
        }
        $this->assertMemberCanOperate($member, 'acumular puntos');
        $this->assertUniqueReference($tenantId, $invoiceNumber, 'purchase');

        $program = $this->program($tenantId);
        $settings = $this->settings()['settings'];
        $points = $this->calculatePurchasePoints($amount, $member, $settings);
        $ledgerId = $this->id('ledger');

        $this->pdo->beginTransaction();
        try {
            $account = $this->accountForMember($tenantId, $member['id']);
            $balanceAfter = (int)$account['balance'] + $points;
            $this->execute(
                'UPDATE loyalty_point_accounts SET balance = :balance, lifetime_points = lifetime_points + :points, updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id',
                [
                    'balance' => $balanceAfter,
                    'points' => $points,
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, source_reference, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :source_reference, :metadata, :created_by_user_id)',
                [
                    'id' => $ledgerId,
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                    'program_id' => $program['id'],
                    'entry_type' => 'purchase',
                    'points' => $points,
                    'balance_after' => $balanceAfter,
                    'reference' => $invoiceNumber,
                    'source' => 'pos',
                    'source_reference' => $invoiceNumber,
                    'metadata' => json_encode([
                        'invoiceAmount' => $amount,
                        'invoiceNumber' => $invoiceNumber,
                        'formula' => $this->purchaseFormulaSummary($settings, $member),
                    ]),
                    'created_by_user_id' => $userId,
                ]
            );
            $this->refreshMemberTier($tenantId, $member['id']);
            $this->execute(
                'UPDATE loyalty_members SET last_activity_at = NOW(), updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $member['id']]
            );
            $this->recordAudit('purchase.registered', 'member', $member['id'], null, [
                'invoiceNumber' => $invoiceNumber,
                'invoiceAmount' => $amount,
                'points' => $points,
            ], null, $userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->syncGoogleWalletBestEffort($member, $balanceAfter);

        return [
            'member' => $this->memberById($member['id']),
            'pointsEarned' => $points,
            'balanceAfter' => $balanceAfter,
            'invoiceNumber' => $invoiceNumber,
            'invoiceAmount' => $amount,
        ];
    }

    public function reversePurchase(string $reference, array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $reference = trim($reference);
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reference === '' || $reason === '') {
            throw new \InvalidArgumentException('La reversa requiere referencia y motivo.');
        }

        $ledgerRows = $this->fetchAll(
            "SELECT *
             FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id
               AND entry_type = 'purchase'
               AND reference = :reference
               AND reversed_at IS NULL
             ORDER BY created_at DESC
             LIMIT 1",
            ['tenant_id' => $tenantId, 'reference' => $reference]
        );
        if ($ledgerRows === []) {
            throw new \RuntimeException('Compra no encontrada o ya reversada.');
        }
        $ledger = $ledgerRows[0];
        $member = $this->memberById((string)$ledger['member_id']);
        if (!$member) {
            throw new \RuntimeException('Socio no encontrado para reversar la compra.');
        }

        $points = (int)$ledger['points'];
        $this->pdo->beginTransaction();
        try {
            $account = $this->accountForMember($tenantId, (string)$member['id']);
            $pointsToReverse = min($points, (int)$account['balance']);
            $balanceAfter = (int)$account['balance'] - $pointsToReverse;
            $this->execute(
                'UPDATE loyalty_point_accounts
                 SET balance = :balance,
                     lifetime_points = GREATEST(lifetime_points - :points, 0),
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id',
                [
                    'balance' => $balanceAfter,
                    'points' => $points,
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                ]
            );
            $this->execute(
                'UPDATE loyalty_point_ledger SET reversed_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $ledger['id']]
            );
            $this->execute(
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, source_reference, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :source_reference, :metadata, :created_by_user_id)',
                [
                    'id' => $this->id('ledger'),
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                    'program_id' => $ledger['program_id'],
                    'entry_type' => 'reversal',
                    'points' => -$pointsToReverse,
                    'balance_after' => $balanceAfter,
                    'reference' => 'REV-' . $reference,
                    'source' => 'dashboard',
                    'source_reference' => $reference,
                    'metadata' => json_encode(['reason' => $reason, 'originalPoints' => $points, 'pointsReversed' => $pointsToReverse]),
                    'created_by_user_id' => $userId,
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_reversals
                    (id, tenant_id, member_id, original_reference, ledger_id, points_reversed, reason, created_by_user_id, metadata)
                 VALUES
                    (:id, :tenant_id, :member_id, :original_reference, :ledger_id, :points_reversed, :reason, :created_by_user_id, :metadata)',
                [
                    'id' => $this->id('reversal'),
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                    'original_reference' => $reference,
                    'ledger_id' => $ledger['id'],
                    'points_reversed' => $pointsToReverse,
                    'reason' => $reason,
                    'created_by_user_id' => $userId,
                    'metadata' => json_encode(['requestedBy' => $payload['requestedBy'] ?? null]),
                ]
            );
            $this->refreshMemberTier($tenantId, (string)$member['id']);
            $this->recordAudit('purchase.reversed', 'member', (string)$member['id'], $ledger, ['balanceAfter' => $balanceAfter], $reason, $userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->syncGoogleWalletBestEffort($member, $balanceAfter);

        return [
            'member' => $this->memberById((string)$member['id']),
            'originalReference' => $reference,
            'pointsReversed' => $pointsToReverse,
            'balanceAfter' => $balanceAfter,
        ];
    }

    public function redeemReward(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $memberId = trim((string)($payload['memberId'] ?? $payload['member_id'] ?? ''));
        $rewardId = trim((string)($payload['rewardId'] ?? $payload['reward_id'] ?? ''));
        if ($memberId === '' || $rewardId === '') {
            throw new \InvalidArgumentException('Socio y premio son obligatorios.');
        }

        $member = $this->memberById($memberId);
        $reward = $this->rewardById($rewardId);
        if (!$member || !$reward) {
            throw new \RuntimeException('Socio o premio no encontrado.');
        }
        $this->assertMemberCanOperate($member, 'canjear puntos');
        $settings = $this->settings()['settings'];
        if ((bool)($settings['redemption']['requireDigitalCard'] ?? true) && !$this->hasActiveWallet($memberId)) {
            $this->recordRisk('high', 'redemption_without_card', 'Canje bloqueado porque el socio no tiene tarjeta digital activa.', $memberId, null);
            throw new \InvalidArgumentException('Este socio necesita una tarjeta digital activa para canjear puntos.');
        }
        if (($reward['status'] ?? '') !== 'active') {
            throw new \InvalidArgumentException('El premio no esta activo.');
        }
        if ((int)($reward['stock'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('El premio no tiene stock disponible.');
        }
        $this->assertRedemptionLimits($tenantId, $memberId, $rewardId, $settings);

        $program = $this->program($tenantId);
        $account = $this->accountForMember($tenantId, $memberId);
        $pointsCost = (int)($reward['points_cost'] ?? 0);
        if ((int)$account['balance'] < $pointsCost) {
            throw new \InvalidArgumentException('El socio no tiene puntos suficientes para este canje.');
        }

        $redemptionId = $this->id('redemption');
        $balanceAfter = (int)$account['balance'] - $pointsCost;
        $this->pdo->beginTransaction();
        try {
            $this->execute(
                'UPDATE loyalty_point_accounts SET balance = :balance, updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id',
                ['balance' => $balanceAfter, 'tenant_id' => $tenantId, 'member_id' => $memberId]
            );
            $this->execute(
                'UPDATE loyalty_rewards SET stock = GREATEST(stock - 1, 0), updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $rewardId]
            );
            $this->execute(
                'INSERT INTO loyalty_redemptions
                    (id, tenant_id, member_id, reward_id, points_cost, status, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :reward_id, :points_cost, :status, :metadata, :created_by_user_id)',
                [
                    'id' => $redemptionId,
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'reward_id' => $rewardId,
                    'points_cost' => $pointsCost,
                    'status' => 'approved',
                    'metadata' => json_encode([
                        'rewardName' => $reward['name'],
                        'memberName' => $member['name'] ?? $member['account_name'] ?? '',
                    ]),
                    'created_by_user_id' => $userId,
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, source_reference, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :source_reference, :metadata, :created_by_user_id)',
                [
                    'id' => $this->id('ledger'),
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'program_id' => $program['id'],
                    'entry_type' => 'redemption',
                    'points' => -$pointsCost,
                    'balance_after' => $balanceAfter,
                    'reference' => $redemptionId,
                    'source' => 'dashboard',
                    'source_reference' => $redemptionId,
                    'metadata' => json_encode(['rewardId' => $rewardId, 'rewardName' => $reward['name']]),
                    'created_by_user_id' => $userId,
                ]
            );
            $this->execute(
                'UPDATE loyalty_members SET last_activity_at = NOW(), updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $memberId]
            );
            $this->recordAudit('redemption.approved', 'member', $memberId, null, [
                'rewardId' => $rewardId,
                'rewardName' => $reward['name'],
                'pointsCost' => $pointsCost,
                'balanceAfter' => $balanceAfter,
            ], null, $userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->syncGoogleWalletBestEffort($member, $balanceAfter);

        return [
            'redemption' => $this->redemptionById($redemptionId),
            'member' => $this->memberById($memberId),
            'reward' => $this->rewardById($rewardId),
            'pointsRedeemed' => $pointsCost,
            'balanceAfter' => $balanceAfter,
        ];
    }

    public function updateWallet(string $memberId, array $payload): array {
        $tenantId = $this->tenantId();
        $platform = strtolower(trim((string)($payload['platform'] ?? 'none')));
        if (!in_array($platform, ['google', 'apple', 'none'], true)) {
            throw new \InvalidArgumentException('La tarjeta digital debe ser Android, iPhone o sin tarjeta.');
        }
        $member = $this->memberById($memberId);
        if (!$member) {
            throw new \RuntimeException('Socio no encontrado.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->execute(
                'UPDATE loyalty_members SET wallet_platform = :platform, updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['platform' => $platform, 'tenant_id' => $tenantId, 'id' => $memberId]
            );
            $this->execute(
                "UPDATE loyalty_wallet_passes
                 SET status = CASE WHEN :platform = 'none' THEN 'inactive' ELSE status END, updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id",
                ['platform' => $platform, 'tenant_id' => $tenantId, 'member_id' => $memberId]
            );

            if ($platform !== 'none') {
                $existingPass = $this->fetchAll(
                    'SELECT id FROM loyalty_wallet_passes WHERE tenant_id = :tenant_id AND member_id = :member_id AND platform = :platform LIMIT 1',
                    ['tenant_id' => $tenantId, 'member_id' => $memberId, 'platform' => $platform]
                );
                if ($existingPass !== []) {
                    $this->execute(
                        "UPDATE loyalty_wallet_passes
                         SET status = 'ready-for-issuer', external_object_id = :external_object_id,
                             last_payload = :last_payload, updated_at = NOW()
                         WHERE tenant_id = :tenant_id AND member_id = :member_id AND platform = :platform",
                        [
                            'tenant_id' => $tenantId,
                            'member_id' => $memberId,
                            'platform' => $platform,
                            'external_object_id' => $this->walletExternalObjectId($platform, (string)($member['account_id'] ?? ''), $memberId),
                            'last_payload' => json_encode(['updatedFrom' => 'dashboard']),
                        ]
                    );
                } else {
                    $this->execute(
                        'INSERT INTO loyalty_wallet_passes
                            (id, tenant_id, member_id, platform, external_object_id, status, last_payload)
                         VALUES
                            (:id, :tenant_id, :member_id, :platform, :external_object_id, :status, :last_payload)',
                        [
                            'id' => $this->id('pass'),
                            'tenant_id' => $tenantId,
                            'member_id' => $memberId,
                            'platform' => $platform,
                            'external_object_id' => $this->walletExternalObjectId($platform, (string)($member['account_id'] ?? ''), $memberId),
                            'status' => 'ready-for-issuer',
                            'last_payload' => json_encode(['updatedFrom' => 'dashboard']),
                        ]
                    );
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->passPreview($memberId);
    }

    public function passPreview(string $memberId): array {
        $member = $this->memberById($memberId);
        if (!$member) {
            throw new \RuntimeException('Socio no encontrado.');
        }
        $program = $this->program($this->tenantId());

        return [
            'program' => $program,
            'member' => $member,
            'qrPayload' => sprintf('LOYALTY:%s:%s', $this->tenantId(), $member['id']),
            'walletPlatforms' => ['google', 'apple'],
        ];
    }

    public function googleWalletLink(array $payload, ?string $userId = null): array {
        $member = $this->memberFromPayload($payload);
        if (!$member) {
            throw new \RuntimeException('Socio no encontrado para generar pase.');
        }

        $sendEmail = filter_var($payload['sendEmail'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $this->issueGoogleWalletLink($member, $userId, $sendEmail ?? true);
    }

    public function googleWalletLinkForAccount(string $accountId, array $client): array {
        $member = $this->memberFromPayload(['accountId' => $accountId]);
        if (!$member) {
            throw new \RuntimeException('Socio no encontrado.');
        }
        $this->assertMemberCanOperate($member, 'generar tarjeta digital');

        return $this->issueGoogleWalletLink($member, 'api:' . (string)($client['id'] ?? 'external'), false);
    }

    public function googleWalletSaveUrlFromQrToken(string $token): string {
        $payload = $this->decodeGoogleWalletQrToken($token);
        $tenantId = (string)($payload['tenantId'] ?? '');
        $accountId = (string)($payload['accountId'] ?? '');

        if ($tenantId === '' || !hash_equals($this->tenantId(), $tenantId) || $accountId === '') {
            throw new \InvalidArgumentException('El QR de la tarjeta no pertenece a este programa.');
        }

        $member = $this->memberFromPayload(['accountId' => $accountId]);
        if (!$member) {
            throw new \InvalidArgumentException('Socio no encontrado para abrir la tarjeta.');
        }
        $this->assertMemberCanOperate($member, 'abrir la tarjeta digital');

        $service = $this->googleWalletServiceOrFail();
        $result = $service->buildSaveUrl(
            (string)$member['account_id'],
            (string)($member['account_name'] ?? $member['account_id']),
            (int)($member['points'] ?? 0)
        );

        $this->upsertGoogleWalletPass((string)$member['id'], $result['objectId'], 'qr-opened', [
            'points' => (int)($member['points'] ?? 0),
            'classId' => $result['classId'],
            'openedAt' => date('c'),
        ]);

        return (string)$result['saveUrl'];
    }

    public function googleWalletNotify(array $payload, ?string $userId = null): array {
        $body = trim((string)($payload['body'] ?? $payload['message'] ?? ''));
        if ($body === '') {
            throw new \InvalidArgumentException('Escribe un mensaje para la notificacion.');
        }

        $member = $this->memberFromPayload($payload);
        if (!$member) {
            throw new \RuntimeException('Socio no encontrado.');
        }

        $service = $this->googleWalletServiceOrFail();
        $header = trim((string)($payload['header'] ?? $payload['title'] ?? ''));
        if ($header === '') {
            $walletSettings = $this->settings()['settings']['googleWallet'] ?? [];
            $header = trim((string)($walletSettings['issuerName'] ?? '')) ?: 'Notificacion';
        }
        $header = mb_substr($header, 0, 100);
        $body = mb_substr($body, 0, 300);

        $result = $service->addMessage((string)$member['account_id'], $header, $body);

        $this->recordAudit(
            'wallet.google.message_sent',
            'member',
            (string)$member['id'],
            null,
            ['objectId' => $result['objectId'], 'messageId' => $result['messageId'], 'header' => $header],
            null,
            $userId
        );

        return [
            'sent' => true,
            'objectId' => $result['objectId'],
            'messageId' => $result['messageId'],
        ];
    }

    private function issueGoogleWalletLink(array $member, ?string $userId, bool $sendEmail = false): array {
        $service = $this->googleWalletServiceOrFail();

        $result = $service->buildSaveUrl(
            (string)$member['account_id'],
            (string)($member['account_name'] ?? $member['account_id']),
            (int)($member['points'] ?? 0)
        );

        $this->upsertGoogleWalletPass((string)$member['id'], $result['objectId'], 'link-generated', [
            'points' => (int)($member['points'] ?? 0),
            'classId' => $result['classId'],
            'generatedAt' => date('c'),
        ]);

        if (($member['wallet_platform'] ?? 'none') !== 'google') {
            $this->execute(
                'UPDATE loyalty_members SET wallet_platform = \'google\', updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $this->tenantId(), 'id' => (string)$member['id']]
            );
        }

        $emailResult = $sendEmail
            ? $this->sendGoogleWalletEmail($member, $result['saveUrl'], $result['objectId'], (int)($member['points'] ?? 0))
            : ['sent' => false, 'recipient' => null, 'reason' => 'email-disabled-for-request'];

        // Nunca auditar el saveUrl: el JWT firmado contiene PII del socio.
        $this->recordAudit(
            'wallet.google.link_generated',
            'member',
            (string)$member['id'],
            null,
            [
                'objectId' => $result['objectId'],
                'classId' => $result['classId'],
                'points' => (int)($member['points'] ?? 0),
                'emailSent' => (bool)($emailResult['sent'] ?? false),
                'emailRecipient' => $emailResult['recipient'] ?? null,
                'emailReason' => $emailResult['reason'] ?? null,
            ],
            null,
            $userId
        );

        return [
            'configured' => true,
            'saveUrl' => $result['saveUrl'],
            'qrPath' => '/api/l/w/' . $this->googleWalletQrToken($member),
            'objectId' => $result['objectId'],
            'classId' => $result['classId'],
            'member' => [
                'id' => (string)$member['id'],
                'accountId' => (string)$member['account_id'],
                'name' => (string)($member['account_name'] ?? ''),
            ],
            'points' => (int)($member['points'] ?? 0),
            'email' => $emailResult,
        ];
    }

    /**
     * Envia el boton "Agregar a Google Wallet" al correo del socio. El saveUrl
     * no se muestra como URL larga en el HTML y se almacena redaccion en outbox.
     *
     * @return array{sent: bool, recipient: ?string, reason?: string}
     */
    private function sendGoogleWalletEmail(array $member, string $saveUrl, string $objectId, int $points): array {
        $recipient = mb_strtolower(trim((string)($member['email'] ?? '')));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'recipient' => null, 'reason' => 'member-email-missing'];
        }

        $program = $this->program($this->tenantId());
        $programName = trim((string)($program['wallet_program_name'] ?? $program['name'] ?? 'Programa de fidelizacion'));
        $memberName = trim((string)($member['account_name'] ?? $member['name'] ?? 'Socio'));
        $accountId = trim((string)($member['account_id'] ?? ''));
        $subject = 'Agrega tu tarjeta de recompensas a Google Wallet';
        $safeProgram = htmlspecialchars($programName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMember = htmlspecialchars($memberName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeAccount = htmlspecialchars($accountId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($saveUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safePoints = number_format($points, 0, ',', '.');

        $html = <<<HTML
<!doctype html>
<html lang="es">
  <body style="margin:0;padding:0;background:#f4f7f6;font-family:Arial,sans-serif;color:#142724;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f6;padding:28px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #dce9e4;border-radius:14px;overflow:hidden;">
            <tr>
              <td style="background:#173d39;color:#ffffff;padding:24px;">
                <div style="font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.78;">{$safeProgram}</div>
                <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;">Tu tarjeta de recompensas esta lista</h1>
              </td>
            </tr>
            <tr>
              <td style="padding:24px;">
                <p style="margin:0 0 14px;font-size:16px;line-height:1.5;">Hola {$safeMember},</p>
                <p style="margin:0 0 18px;font-size:16px;line-height:1.5;">Agrega tu tarjeta a Google Wallet para consultar y usar tus puntos desde el telefono.</p>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;background:#f7fbf9;border:1px solid #dce9e4;border-radius:10px;">
                  <tr>
                    <td style="padding:14px;">
                      <div style="font-size:12px;color:#506a65;font-weight:700;">Cuenta</div>
                      <div style="font-size:18px;font-weight:800;">{$safeAccount}</div>
                    </td>
                    <td style="padding:14px;">
                      <div style="font-size:12px;color:#506a65;font-weight:700;">Saldo actual</div>
                      <div style="font-size:18px;font-weight:800;">{$safePoints} pts</div>
                    </td>
                  </tr>
                </table>
                <table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:24px auto 0;">
                  <tr>
                    <td align="center" bgcolor="#0f766e" style="border-radius:10px;">
                      <a href="{$safeUrl}" target="_blank" style="display:inline-block;color:#ffffff;text-decoration:none;font-weight:800;font-size:14px;line-height:18px;padding:14px 22px;border-radius:10px;">Agregar tarjeta a Google Wallet</a>
                    </td>
                  </tr>
                </table>
                <p style="margin:16px 0 0;text-align:center;color:#506a65;font-size:13px;line-height:1.5;">Si no abre, intenta tocar el boton desde Chrome en tu telefono Android.</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;

        $plain = "Hola {$memberName},\n\nAgrega tu tarjeta de recompensas de {$programName} a Google Wallet.\nCuenta: {$accountId}\nSaldo actual: {$points} pts\n\nEnlace: {$saveUrl}\n";
        $stored = "Invitacion Google Wallet enviada. Cuenta: {$accountId}. Saldo: {$points} pts. Enlace firmado omitido.";
        $sent = MailService::sendHtml($recipient, $subject, $html, $plain, null, null, [
            'module' => 'loyalty-points',
            'template' => 'google-wallet-invite',
            'member_id' => (string)($member['id'] ?? ''),
            'account_id' => $accountId,
            'object_id' => $objectId,
        ], $stored);

        return [
            'sent' => $sent,
            'recipient' => $recipient,
            'reason' => $sent ? 'sent' : 'mail-transport-failed',
        ];
    }

    private function googleWalletQrToken(array $member): string {
        $accountId = trim((string)($member['account_id'] ?? ''));
        if ($accountId === '') {
            throw new \InvalidArgumentException('El socio no tiene cuenta para generar el QR.');
        }

        $expiresAt = time() + 86400;
        $expiresAtBase36 = base_convert((string)$expiresAt, 10, 36);
        $payload = 'v1.' . $accountId . '.' . $expiresAtBase36;
        $signature = substr(hash_hmac('sha256', $this->tenantId() . '|' . $accountId . '|' . $expiresAtBase36, $this->googleWalletQrSecret(), true), 0, 8);

        return $payload . '.' . $this->base64UrlEncode($signature);
    }

    private function decodeGoogleWalletQrToken(string $token): array {
        $token = trim($token);
        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new \InvalidArgumentException('El QR de la tarjeta no es valido.');
        }

        [, $accountId, $expiresAtBase36, $signature] = $parts;
        if ($accountId === '' || $expiresAtBase36 === '' || !ctype_alnum($expiresAtBase36)) {
            throw new \InvalidArgumentException('El QR de la tarjeta no es valido.');
        }

        $expectedSignature = $this->base64UrlEncode(substr(hash_hmac('sha256', $this->tenantId() . '|' . $accountId . '|' . $expiresAtBase36, $this->googleWalletQrSecret(), true), 0, 8));
        if (!hash_equals($expectedSignature, $signature)) {
            throw new \InvalidArgumentException('El QR de la tarjeta no es valido.');
        }

        $expiresAt = (int)base_convert(strtolower($expiresAtBase36), 36, 10);
        if ((int)$expiresAt <= time()) {
            throw new \InvalidArgumentException('El QR de la tarjeta expiro. Genera uno nuevo.');
        }

        return [
            'tenantId' => $this->tenantId(),
            'accountId' => $accountId,
            'exp' => $expiresAt,
        ];
    }

    private function googleWalletQrSecret(): string {
        $secret = trim((string)($_ENV['LOYALTY_WALLET_QR_SECRET'] ?? ''));
        if ($secret === '') {
            $secret = trim((string)($_ENV['JWT_SECRET'] ?? ''));
        }
        if ($secret === '') {
            throw new \RuntimeException('Configura LOYALTY_WALLET_QR_SECRET o JWT_SECRET para firmar QR de Google Wallet.');
        }

        return $secret;
    }

    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * ObjectId para registrar en loyalty_wallet_passes: canonico de Google si
     * hay issuer configurado; formato legacy local si no. Nunca se deriva
     * leyendo la columna: siempre se recomputa (la columna es espejo).
     */
    private function walletExternalObjectId(string $platform, string $accountId, string $fallbackMemberId): string {
        $tenantId = $this->tenantId();
        if ($platform === 'google') {
            $config = GoogleWalletFactory::config($tenantId, $this->settings()['settings'], $this->program($tenantId));
            if ($config->issuerId() !== '') {
                return $config->objectId($accountId !== '' ? $accountId : $fallbackMemberId);
            }
        }

        return sprintf('%s.%s', $tenantId, $accountId !== '' ? $accountId : $fallbackMemberId);
    }

    private function googleWalletServiceOrNull(): ?GoogleWalletService {
        $tenantId = $this->tenantId();
        $settings = $this->settings()['settings'];
        $program = $this->program($tenantId);

        return GoogleWalletFactory::make($tenantId, $settings, $program);
    }

    private function googleWalletServiceOrFail(): GoogleWalletService {
        $tenantId = $this->tenantId();
        $settings = $this->settings()['settings'];
        $program = $this->program($tenantId);

        $config = GoogleWalletFactory::config($tenantId, $settings, $program);
        if (!$config->isConfigured()) {
            throw new \InvalidArgumentException('Google Wallet no esta configurado. Faltan: ' . implode(', ', $config->missing()) . '.');
        }

        return GoogleWalletFactory::fromConfig($config);
    }

    private function upsertGoogleWalletPass(string $memberId, string $objectId, string $status, array $payloadMeta): void {
        $tenantId = $this->tenantId();
        $existing = $this->fetchAll(
            'SELECT id, external_object_id, last_payload FROM loyalty_wallet_passes
             WHERE tenant_id = :tenant_id AND member_id = :member_id AND platform = \'google\' LIMIT 1',
            ['tenant_id' => $tenantId, 'member_id' => $memberId]
        );

        if ($existing !== []) {
            $row = $existing[0];
            $previousObjectId = (string)($row['external_object_id'] ?? '');
            if ($previousObjectId !== '' && $previousObjectId !== $objectId) {
                $payloadMeta['legacyExternalObjectId'] = $previousObjectId;
            }
            $this->execute(
                'UPDATE loyalty_wallet_passes
                 SET status = :status, external_object_id = :external_object_id, last_payload = :last_payload, updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => (string)$row['id'],
                    'status' => $status,
                    'external_object_id' => $objectId,
                    'last_payload' => json_encode($payloadMeta, JSON_UNESCAPED_UNICODE),
                ]
            );
            return;
        }

        $this->execute(
            'INSERT INTO loyalty_wallet_passes
                (id, tenant_id, member_id, platform, external_object_id, status, last_payload)
             VALUES
                (:id, :tenant_id, :member_id, \'google\', :external_object_id, :status, :last_payload)',
            [
                'id' => $this->id('pass'),
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'external_object_id' => $objectId,
                'status' => $status,
                'last_payload' => json_encode($payloadMeta, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private function googleWalletObjectIdForMember(string $memberId, string $accountId, GoogleWalletService $service): string {
        $existing = $this->fetchAll(
            'SELECT external_object_id FROM loyalty_wallet_passes
             WHERE tenant_id = :tenant_id AND member_id = :member_id AND platform = \'google\' LIMIT 1',
            ['tenant_id' => $this->tenantId(), 'member_id' => $memberId]
        );
        $objectId = trim((string)($existing[0]['external_object_id'] ?? ''));

        return $objectId !== '' && $service->ownsObjectId($objectId)
            ? $objectId
            : $service->objectId($accountId);
    }

    /**
     * Sincroniza el balance al pase de Google despues de una mutacion de
     * puntos. Best-effort: se llama SIEMPRE despues del commit y jamas
     * propaga errores (la operacion de puntos ya es definitiva).
     */
    private function syncGoogleWalletBestEffort(array $member, int $balanceAfter): void {
        $service = null;
        try {
            if (($member['wallet_platform'] ?? 'none') !== 'google') {
                return;
            }
            $service = $this->googleWalletServiceOrNull();
            if ($service === null) {
                return; // integracion no configurada: silencio total
            }
            $objectId = $this->googleWalletObjectIdForMember(
                (string)$member['id'],
                (string)$member['account_id'],
                $service
            );

            $result = $service->pushPointsToObject(
                $objectId,
                (string)$member['account_id'],
                (string)($member['account_name'] ?? $member['account_id']),
                $balanceAfter
            );

            $this->upsertGoogleWalletPass((string)$member['id'], $result['objectId'], 'synced', [
                'points' => $balanceAfter,
                'syncedAt' => date('c'),
                'created' => $result['created'],
            ]);
        } catch (\Throwable $e) {
            try {
                if ($service !== null) {
                    $objectId = $this->googleWalletObjectIdForMember(
                        (string)$member['id'],
                        (string)$member['account_id'],
                        $service
                    );
                    $this->upsertGoogleWalletPass(
                        (string)$member['id'],
                        $objectId,
                        'sync-error',
                        ['points' => $balanceAfter, 'error' => mb_substr($e->getMessage(), 0, 500), 'failedAt' => date('c')]
                    );
                }
                $this->recordRisk(
                    'medium',
                    'wallet_sync_failed',
                    'Fallo la sincronizacion de puntos con Google Wallet.',
                    (string)($member['id'] ?? ''),
                    null,
                    ['balance' => $balanceAfter, 'error' => mb_substr($e->getMessage(), 0, 500)]
                );
            } catch (\Throwable) {
                // nunca romper la operacion de puntos por un fallo del espejo wallet
            }
        }
    }

    public function report(string $reportKey, array $filters = []): array {
        $tenantId = $this->tenantId();
        [$from, $to] = $this->dateRange($filters);
        $params = ['tenant_id' => $tenantId, 'from' => $from, 'to' => $to];

        $report = match ($reportKey) {
            'executive-summary' => [
                'key' => $reportKey,
                'title' => 'Resumen ejecutivo',
                'period' => ['from' => $from, 'to' => $to],
                'metrics' => [
                    'activeMembers' => (int)$this->scalar("SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id AND status = 'active'", ['tenant_id' => $tenantId]),
                    'newMembers' => (int)$this->scalar('SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id AND created_at::date BETWEEN :from::date AND :to::date', $params),
                    'pointsIssued' => (int)$this->scalar("SELECT COALESCE(SUM(points), 0) FROM loyalty_point_ledger WHERE tenant_id = :tenant_id AND points > 0 AND created_at::date BETWEEN :from::date AND :to::date", $params),
                    'pointsRedeemed' => abs((int)$this->scalar("SELECT COALESCE(SUM(points), 0) FROM loyalty_point_ledger WHERE tenant_id = :tenant_id AND points < 0 AND entry_type = 'redemption' AND created_at::date BETWEEN :from::date AND :to::date", $params)),
                    'availableLiabilityPoints' => (int)$this->scalar('SELECT COALESCE(SUM(balance), 0) FROM loyalty_point_accounts WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]),
                ],
                'rows' => $this->activityTrend($tenantId),
            ],
            'point-activity' => [
                'key' => $reportKey,
                'title' => 'Actividad de puntos',
                'period' => ['from' => $from, 'to' => $to],
                'rows' => $this->fetchAll(
                    "SELECT l.created_at, m.account_id, m.account_name, l.entry_type, l.points, l.balance_after, l.reference, l.source, l.metadata
                     FROM loyalty_point_ledger l
                     JOIN loyalty_members m ON m.id = l.member_id AND m.tenant_id = l.tenant_id
                     WHERE l.tenant_id = :tenant_id AND l.created_at::date BETWEEN :from::date AND :to::date
                     ORDER BY l.created_at DESC
                     LIMIT 500",
                    $params
                ),
            ],
            'members-tiers' => [
                'key' => $reportKey,
                'title' => 'Socios y niveles',
                'period' => ['from' => $from, 'to' => $to],
                'rows' => $this->fetchAll(
                    "SELECT m.tier, m.status, m.wallet_platform, COUNT(*) AS total, COALESCE(SUM(a.balance), 0) AS balance
                     FROM loyalty_members m
                     LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
                     WHERE m.tenant_id = :tenant_id
                     GROUP BY m.tier, m.status, m.wallet_platform
                     ORDER BY m.tier, m.status, m.wallet_platform",
                    ['tenant_id' => $tenantId]
                ),
            ],
            'card-adoption' => [
                'key' => $reportKey,
                'title' => 'Adopcion de tarjetas digitales',
                'period' => ['from' => $from, 'to' => $to],
                'rows' => $this->fetchAll(
                    "SELECT m.wallet_platform, COALESCE(p.status, 'sin tarjeta') AS pass_status, COUNT(*) AS total
                     FROM loyalty_members m
                     LEFT JOIN loyalty_wallet_passes p ON p.member_id = m.id AND p.tenant_id = m.tenant_id AND p.platform = m.wallet_platform
                     WHERE m.tenant_id = :tenant_id
                     GROUP BY m.wallet_platform, COALESCE(p.status, 'sin tarjeta')
                     ORDER BY m.wallet_platform, pass_status",
                    ['tenant_id' => $tenantId]
                ),
            ],
            'redemptions-rewards' => [
                'key' => $reportKey,
                'title' => 'Canjes y premios',
                'period' => ['from' => $from, 'to' => $to],
                'rows' => $this->fetchAll(
                    "SELECT w.name AS reward, w.stock, COUNT(r.id) AS redemptions, COALESCE(SUM(r.points_cost), 0) AS points_redeemed
                     FROM loyalty_rewards w
                     LEFT JOIN loyalty_redemptions r ON r.reward_id = w.id AND r.tenant_id = w.tenant_id AND r.created_at::date BETWEEN :from::date AND :to::date
                     WHERE w.tenant_id = :tenant_id
                     GROUP BY w.name, w.stock
                     ORDER BY redemptions DESC, points_redeemed DESC",
                    $params
                ),
            ],
            'risk-events' => [
                'key' => $reportKey,
                'title' => 'Riesgo y antifraude',
                'period' => ['from' => $from, 'to' => $to],
                'rows' => $this->riskEvents($filters)['items'],
            ],
            'audit-events' => [
                'key' => $reportKey,
                'title' => 'Auditoria',
                'period' => ['from' => $from, 'to' => $to],
                'rows' => $this->auditEvents($filters)['items'],
            ],
            'api-usage' => [
                'key' => $reportKey,
                'title' => 'Uso de API externa',
                'period' => ['from' => $from, 'to' => $to],
                'rows' => $this->fetchAll(
                    "SELECT c.name, c.source, c.status, c.scopes, c.rate_limit_per_minute, c.last_used_at,
                            COUNT(k.id) FILTER (WHERE k.created_at::date BETWEEN :from::date AND :to::date) AS requests
                     FROM loyalty_api_clients c
                     LEFT JOIN loyalty_idempotency_keys k ON k.api_client_id = c.id AND k.tenant_id = c.tenant_id
                     WHERE c.tenant_id = :tenant_id
                     GROUP BY c.id
                     ORDER BY requests DESC, c.name",
                    $params
                ),
            ],
            'ledger-reconciliation' => [
                'key' => $reportKey,
                'title' => 'Conciliacion de saldos',
                'period' => ['from' => $from, 'to' => $to],
                'rows' => $this->fetchAll(
                    "SELECT m.account_id, m.account_name, a.balance,
                            COALESCE(SUM(l.points), 0) AS ledger_balance,
                            a.balance - COALESCE(SUM(l.points), 0) AS difference
                     FROM loyalty_members m
                     JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
                     LEFT JOIN loyalty_point_ledger l ON l.member_id = m.id AND l.tenant_id = m.tenant_id
                     WHERE m.tenant_id = :tenant_id
                     GROUP BY m.account_id, m.account_name, a.balance
                     HAVING a.balance <> COALESCE(SUM(l.points), 0)
                     ORDER BY ABS(a.balance - COALESCE(SUM(l.points), 0)) DESC
                     LIMIT 500",
                    ['tenant_id' => $tenantId]
                ),
            ],
            default => throw new \InvalidArgumentException('Reporte no disponible.'),
        };

        return $this->localizeReport($report);
    }

    private function localizeReport(array $report): array {
        if (is_array($report['metrics'] ?? null)) {
            $localizedMetrics = [];
            foreach ($report['metrics'] as $key => $value) {
                $localizedMetrics[$this->reportMetricLabel((string)$key)] = $value;
            }
            $report['metrics'] = $localizedMetrics;
        }

        if (is_array($report['rows'] ?? null)) {
            $report['rows'] = array_map(fn($row) => $this->localizeReportRow((array)$row), $report['rows']);
        }

        return $report;
    }

    private function localizeReportRow(array $row): array {
        $localized = [];
        foreach ($row as $key => $value) {
            if (in_array((string)$key, ['id', 'tenant_id', 'program_id', 'member_id', 'created_by_user_id', 'api_client_id'], true)) {
                continue;
            }
            $localized[$this->reportColumnLabel((string)$key)] = $this->reportValue((string)$key, $value);
        }

        return $localized;
    }

    private function reportMetricLabel(string $key): string {
        return [
            'activeMembers' => 'Socios activos',
            'newMembers' => 'Socios nuevos',
            'pointsIssued' => 'Puntos emitidos',
            'pointsRedeemed' => 'Puntos canjeados',
            'availableLiabilityPoints' => 'Saldo pendiente',
        ][$key] ?? $this->humanizeKey($key);
    }

    private function reportColumnLabel(string $key): string {
        return [
            'date' => 'Fecha',
            'label' => 'Dia',
            'created_at' => 'Fecha',
            'updated_at' => 'Actualizado',
            'resolved_at' => 'Resuelto',
            'revoked_at' => 'Revocado',
            'last_used_at' => 'Ultimo uso',
            'account_id' => 'Cuenta',
            'account_name' => 'Socio',
            'member_id' => 'Socio',
            'entry_type' => 'Movimiento',
            'points' => 'Puntos',
            'balance' => 'Saldo',
            'balance_after' => 'Saldo posterior',
            'ledger_balance' => 'Saldo libro mayor',
            'difference' => 'Diferencia',
            'reference' => 'Referencia',
            'source' => 'Canal',
            'metadata' => 'Detalle',
            'points_issued' => 'Puntos emitidos',
            'points_redeemed' => 'Puntos canjeados',
            'purchases' => 'Compras',
            'redemptions' => 'Canjes',
            'tier' => 'Nivel',
            'status' => 'Estado',
            'wallet_platform' => 'Tarjeta',
            'pass_status' => 'Estado de tarjeta',
            'total' => 'Total',
            'reward' => 'Premio',
            'stock' => 'Stock',
            'name' => 'Nombre',
            'scopes' => 'Permisos',
            'rate_limit_per_minute' => 'Limite por minuto',
            'requests' => 'Solicitudes',
            'severity' => 'Prioridad',
            'event_type' => 'Evento',
            'message' => 'Mensaje',
            'created_by_user_id' => 'Usuario',
            'before_state' => 'Antes',
            'after_state' => 'Despues',
        ][$key] ?? $this->humanizeKey($key);
    }

    private function reportValue(string $key, $value) {
        if ($value === null || $value === '') {
            return 'Sin dato';
        }

        if (in_array($key, ['metadata', 'before_state', 'after_state', 'settings', 'benefits', 'last_payload', 'response_payload'], true)) {
            return $this->metadataSummary($value);
        }

        if ($key === 'entry_type') {
            return $this->movementLabel((string)$value);
        }

        if ($key === 'reference') {
            return $this->referenceLabel((string)$value);
        }

        if (in_array($key, ['source'], true)) {
            return $this->channelLabel((string)$value);
        }

        if ($key === 'wallet_platform') {
            return $this->walletLabel((string)$value);
        }

        if ($key === 'pass_status') {
            return $this->passStatusLabel((string)$value);
        }

        if ($key === 'status') {
            return $this->statusLabel((string)$value);
        }

        if ($key === 'severity') {
            return $this->severityLabel((string)$value);
        }

        if ($key === 'event_type') {
            return $this->eventTypeLabel((string)$value);
        }

        if ($key === 'scopes' && is_array($value)) {
            return $value === [] ? 'Sin permisos' : implode(', ', array_map([$this, 'scopeLabel'], $value));
        }

        if (is_array($value) || is_object($value)) {
            return $this->metadataSummary($value);
        }

        return $value;
    }

    private function movementLabel(string $value): string {
        return [
            'purchase' => 'Compra',
            'redemption' => 'Canje',
            'adjustment' => 'Ajuste',
            'reversal' => 'Reversa',
        ][$value] ?? $this->humanizeKey($value);
    }

    private function channelLabel(string $value): string {
        return [
            'pos' => 'Caja',
            'dashboard' => 'Panel administrativo',
            'api' => 'API externa',
            'external' => 'Sistema externo',
            'system' => 'Sistema',
        ][$value] ?? $this->humanizeKey($value);
    }

    private function referenceLabel(string $value): string {
        foreach ([
            'redemption_' => 'Canje',
            'adjustment_' => 'Ajuste',
            'reversal_' => 'Reversa',
        ] as $prefix => $label) {
            if (str_starts_with($value, $prefix)) {
                return $label . ' ' . strtoupper(substr($value, strlen($prefix)));
            }
        }

        return $value;
    }

    private function walletLabel(string $value): string {
        return [
            'google' => 'Android',
            'apple' => 'iPhone',
            'none' => 'Sin tarjeta',
            'sin tarjeta' => 'Sin tarjeta',
        ][$value] ?? $this->humanizeKey($value);
    }

    private function passStatusLabel(string $value): string {
        return [
            'ready-for-issuer' => 'Lista para emitir',
            'issued' => 'Emitida',
            'active' => 'Activa',
            'inactive' => 'Inactiva',
            'revoked' => 'Revocada',
            'sin tarjeta' => 'Sin tarjeta',
        ][$value] ?? $this->statusLabel($value);
    }

    private function statusLabel(string $value): string {
        return [
            'active' => 'Activo',
            'inactive' => 'Inactivo',
            'blocked' => 'Bloqueado',
            'approved' => 'Aprobado',
            'cancelled' => 'Cancelado',
            'deleted' => 'Eliminado',
            'revoked' => 'Revocado',
            'open' => 'Abierto',
            'resolved' => 'Resuelto',
        ][$value] ?? $this->humanizeKey($value);
    }

    private function severityLabel(string $value): string {
        return [
            'critical' => 'Critica',
            'high' => 'Alta',
            'medium' => 'Media',
            'low' => 'Baja',
            'info' => 'Informativa',
        ][$value] ?? $this->humanizeKey($value);
    }

    private function eventTypeLabel(string $value): string {
        return [
            'duplicate_invoice' => 'Factura duplicada',
            'duplicate_reference' => 'Referencia duplicada',
            'negative_balance' => 'Saldo negativo',
            'negative_balance_attempt' => 'Intento de saldo negativo',
            'missing_wallet' => 'Sin tarjeta digital',
            'redemption_without_card' => 'Canje sin tarjeta digital',
            'daily_redemption_limit' => 'Limite diario de canjes',
            'daily_reward_limit' => 'Limite diario por premio',
            'same_reward_daily_limit' => 'Limite diario del mismo premio',
            'out_of_stock' => 'Premio sin stock',
            'insufficient_points' => 'Puntos insuficientes',
            'blocked_member' => 'Socio bloqueado',
            'member_blocked' => 'Socio bloqueado',
            'purchase_points_capped' => 'Compra limitada por reglas',
            'daily_earning_limit' => 'Limite diario de acumulacion',
            'rule_update' => 'Cambio de reglas',
            'manual_adjustment' => 'Ajuste manual',
        ][$value] ?? $this->humanizeKey($value);
    }

    private function scopeLabel(string $value): string {
        return [
            'points:write' => 'Registrar puntos',
            'points:read' => 'Consultar puntos',
            'redemptions:write' => 'Registrar canjes',
            'members:read' => 'Consultar socios',
            'members:write' => 'Administrar socios',
        ][$value] ?? $this->humanizeKey($value);
    }

    private function metadataSummary($value): string {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : trim($value);
        }

        if (!is_array($value)) {
            return $value === '' || $value === null ? 'Sin detalle' : (string)$value;
        }

        if ($value === []) {
            return 'Sin detalle';
        }

        $parts = [];
        foreach ($value as $key => $item) {
            if ($item === null || $item === '') {
                continue;
            }

            $parts[] = $this->metadataKeyLabel((string)$key) . ': ' . $this->metadataValue($item);
        }

        return $parts === [] ? 'Sin detalle' : implode(' | ', $parts);
    }

    private function metadataKeyLabel(string $key): string {
        return [
            'rewardId' => 'Premio',
            'rewardName' => 'Premio',
            'invoiceAmount' => 'Monto factura',
            'invoiceNumber' => 'Factura',
            'store' => 'Sucursal',
            'reason' => 'Motivo',
            'evidence' => 'Evidencia',
            'originalPoints' => 'Puntos originales',
            'pointsReversed' => 'Puntos reversados',
            'requestedBy' => 'Solicitado por',
            'memberId' => 'Socio',
            'accountId' => 'Cuenta',
            'walletPlatform' => 'Tarjeta',
            'source' => 'Canal',
            'channel' => 'Canal',
            'entryType' => 'Movimiento',
            'entry_type' => 'Movimiento',
            'limit' => 'Limite',
            'points' => 'Puntos',
            'today' => 'Acumulado hoy',
            'maximum' => 'Maximo permitido',
            'calculated' => 'Calculado',
        ][$key] ?? $this->humanizeKey($key);
    }

    private function metadataValue($value): string {
        if (is_array($value) || is_object($value)) {
            return $this->metadataSummary((array)$value);
        }

        if (is_bool($value)) {
            return $value ? 'Si' : 'No';
        }

        if (is_string($value)) {
            if (in_array($value, ['purchase', 'redemption', 'adjustment', 'reversal'], true)) {
                return $this->movementLabel($value);
            }
            if (in_array($value, ['pos', 'dashboard', 'api', 'external', 'system'], true)) {
                return $this->channelLabel($value);
            }
            if (in_array($value, ['google', 'apple', 'none'], true)) {
                return $this->walletLabel($value);
            }
        }

        return (string)$value;
    }

    public function reportsCatalog(): array {
        return [
            ['key' => 'executive-summary', 'name' => 'Resumen ejecutivo', 'purpose' => 'Muestra salud general, puntos emitidos, canjes y pasivo de puntos.'],
            ['key' => 'point-activity', 'name' => 'Actividad de puntos', 'purpose' => 'Detalle de compras, canjes, ajustes y reversas.'],
            ['key' => 'members-tiers', 'name' => 'Socios y niveles', 'purpose' => 'Distribucion por Bronce, Plata, Oro, estado y tarjeta.'],
            ['key' => 'card-adoption', 'name' => 'Tarjetas digitales', 'purpose' => 'Adopcion Android/iPhone y pases listos para emitir.'],
            ['key' => 'redemptions-rewards', 'name' => 'Canjes y premios', 'purpose' => 'Premios mas usados, puntos canjeados y stock.'],
            ['key' => 'risk-events', 'name' => 'Riesgo y antifraude', 'purpose' => 'Bloqueos por duplicados, saldo, tarjeta o limites.'],
            ['key' => 'audit-events', 'name' => 'Auditoria', 'purpose' => 'Cambios de reglas, ajustes, reversas y acciones administrativas.'],
            ['key' => 'api-usage', 'name' => 'Uso de API', 'purpose' => 'Consumo por sistemas externos autorizados.'],
            ['key' => 'ledger-reconciliation', 'name' => 'Conciliacion de saldos', 'purpose' => 'Diferencias entre libro mayor y saldos actuales.'],
        ];
    }

    public function reportCsv(string $reportKey, array $filters = []): array {
        $report = $this->report($reportKey, $filters);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo preparar el archivo de exportacion.');
        }

        $this->writeCsvLine($handle, ['Reporte', (string)($report['title'] ?? $reportKey)]);
        $this->writeCsvLine($handle, ['Periodo desde', (string)($report['period']['from'] ?? '')]);
        $this->writeCsvLine($handle, ['Periodo hasta', (string)($report['period']['to'] ?? '')]);
        $this->writeCsvLine($handle, []);

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        if ($metrics !== []) {
            $this->writeCsvLine($handle, ['Indicador', 'Valor']);
            foreach ($metrics as $key => $value) {
                $this->writeCsvLine($handle, [$this->humanizeKey((string)$key), $this->csvValue($value)]);
            }
            $this->writeCsvLine($handle, []);
        }

        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        if ($rows === []) {
            $this->writeCsvLine($handle, ['Sin datos para el periodo seleccionado']);
        } else {
            $columns = array_keys((array)$rows[0]);
            $this->writeCsvLine($handle, array_map(fn($column) => $this->humanizeKey((string)$column), $columns));
            foreach ($rows as $row) {
                $row = (array)$row;
                $this->writeCsvLine($handle, array_map(fn($column) => $this->csvValue($row[$column] ?? null), $columns));
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return [
            'filename' => 'fidepuntos-' . preg_replace('/[^a-z0-9-]+/i', '-', $reportKey) . '-' . date('Ymd-His') . '.csv',
            'content' => "\xEF\xBB\xBF" . (is_string($content) ? $content : ''),
        ];
    }

    public function reportExcel(string $reportKey, array $filters = []): array {
        $report = $this->report($reportKey, $filters);
        $title = (string)($report['title'] ?? $reportKey);
        $periodFrom = (string)($report['period']['from'] ?? '');
        $periodTo = (string)($report['period']['to'] ?? '');
        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];

        $sheetRows = [];
        $rowIndex = 1;
        $sheetRows[] = $this->xlsxRow($rowIndex++, [
            ['value' => $title, 'style' => 1],
        ]);
        $sheetRows[] = $this->xlsxRow($rowIndex++, [
            ['value' => 'Periodo', 'style' => 2],
            ['value' => $periodFrom . ' a ' . $periodTo],
        ]);
        $rowIndex++;

        if ($metrics !== []) {
            $sheetRows[] = $this->xlsxRow($rowIndex++, [
                ['value' => 'Indicadores', 'style' => 2],
            ]);
            $sheetRows[] = $this->xlsxRow($rowIndex++, [
                ['value' => 'Indicador', 'style' => 2],
                ['value' => 'Valor', 'style' => 2],
            ]);
            foreach ($metrics as $key => $value) {
                $sheetRows[] = $this->xlsxRow($rowIndex++, [
                    ['value' => $this->humanizeKey((string)$key)],
                    ['value' => $value],
                ]);
            }
            $rowIndex++;
        }

        $sheetRows[] = $this->xlsxRow($rowIndex++, [
            ['value' => 'Detalle', 'style' => 2],
        ]);
        if ($rows === []) {
            $sheetRows[] = $this->xlsxRow($rowIndex++, [
                ['value' => 'Sin datos para el periodo seleccionado'],
            ]);
        } else {
            $columns = array_keys((array)$rows[0]);
            $sheetRows[] = $this->xlsxRow($rowIndex++, array_map(
                fn($column) => ['value' => $this->humanizeKey((string)$column), 'style' => 2],
                $columns
            ));

            foreach ($rows as $row) {
                $row = (array)$row;
                $sheetRows[] = $this->xlsxRow($rowIndex++, array_map(
                    fn($column) => ['value' => $row[$column] ?? null],
                    $columns
                ));
            }
        }

        $sheetXml = $this->xlsxWorksheet(implode('', $sheetRows));
        $content = $this->buildXlsx($title, $sheetXml);

        return [
            'filename' => 'fidepuntos-' . preg_replace('/[^a-z0-9-]+/i', '-', $reportKey) . '-' . date('Ymd-His') . '.xlsx',
            'content' => $content,
        ];
    }

    public function auditEvents(array $filters = []): array {
        return $this->eventPage('loyalty_audit_events', $filters);
    }

    public function riskEvents(array $filters = []): array {
        return $this->eventPage('loyalty_risk_events', $filters);
    }

    public function resolveRiskEvent(string $eventId, array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $eventId = trim($eventId);
        $note = trim((string)($payload['note'] ?? $payload['reason'] ?? ''));
        if ($eventId === '') {
            throw new \InvalidArgumentException('El evento de riesgo es obligatorio.');
        }
        if ($note === '') {
            throw new \InvalidArgumentException('Indica como se reviso o resolvio el evento.');
        }

        $before = $this->fetchAll(
            'SELECT *
             FROM loyalty_risk_events
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1',
            ['tenant_id' => $tenantId, 'id' => $eventId]
        )[0] ?? null;
        if (!$before) {
            throw new \RuntimeException('Evento de riesgo no encontrado.');
        }
        if (($before['status'] ?? '') === 'resolved') {
            throw new \InvalidArgumentException('El evento ya esta resuelto.');
        }

        $this->execute(
            'UPDATE loyalty_risk_events
             SET status = :status,
                 resolved_at = NOW(),
                 resolved_by_user_id = :resolved_by_user_id,
                 resolution_note = :resolution_note
             WHERE tenant_id = :tenant_id AND id = :id',
            [
                'status' => 'resolved',
                'resolved_by_user_id' => $userId,
                'resolution_note' => $note,
                'tenant_id' => $tenantId,
                'id' => $eventId,
            ]
        );

        $after = $this->fetchAll(
            'SELECT *
             FROM loyalty_risk_events
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1',
            ['tenant_id' => $tenantId, 'id' => $eventId]
        )[0] ?? [];
        $this->recordAudit('risk_event.resolved', 'risk_event', $eventId, $before, $after, $note, $userId);

        return $after;
    }

    public function apiClients(): array {
        return $this->fetchAll(
            'SELECT id, name, source, scopes, status, rate_limit_per_minute, last_used_at, created_at, updated_at, revoked_at
             FROM loyalty_api_clients
             WHERE tenant_id = :tenant_id
             ORDER BY status ASC, name ASC',
            ['tenant_id' => $this->tenantId()]
        );
    }

    public function createApiClient(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $name = trim((string)($payload['name'] ?? ''));
        $source = trim((string)($payload['source'] ?? 'external'));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del cliente API es obligatorio.');
        }
        $rawKey = 'fp_' . bin2hex(random_bytes(24));
        $clientId = $this->id('api_client');
        $scopes = is_array($payload['scopes'] ?? null) ? $payload['scopes'] : ['program:read', 'members:read', 'members:write', 'purchases:write', 'purchases:reverse', 'redemptions:write', 'rewards:read', 'reports:read', 'wallet:link'];
        $this->execute(
            'INSERT INTO loyalty_api_clients (id, tenant_id, name, source, key_hash, scopes, status, rate_limit_per_minute)
             VALUES (:id, :tenant_id, :name, :source, :key_hash, :scopes, :status, :rate_limit_per_minute)',
            [
                'id' => $clientId,
                'tenant_id' => $tenantId,
                'name' => $name,
                'source' => $source,
                'key_hash' => hash('sha256', $rawKey),
                'scopes' => json_encode($scopes),
                'status' => 'active',
                'rate_limit_per_minute' => max(1, (int)($payload['rateLimitPerMinute'] ?? $payload['rate_limit_per_minute'] ?? 120)),
            ]
        );
        $this->recordAudit('api_client.created', 'api_client', $clientId, null, ['name' => $name, 'source' => $source, 'scopes' => $scopes], null, $userId);

        $client = $this->fetchAll(
            'SELECT id, name, source, scopes, status, rate_limit_per_minute, created_at FROM loyalty_api_clients WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
            ['tenant_id' => $tenantId, 'id' => $clientId]
        )[0] ?? [];
        $client['apiKey'] = $rawKey;
        $client['apiKeyNotice'] = 'Guarda esta clave ahora. Luego solo se conserva su hash.';

        return $client;
    }

    public function updateApiClient(string $clientId, array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $before = $this->apiClientById($clientId);
        if (!$before) {
            throw new \RuntimeException('Cliente API no encontrado.');
        }

        $name = trim((string)($payload['name'] ?? $before['name'] ?? ''));
        $source = trim((string)($payload['source'] ?? $before['source'] ?? 'external'));
        $status = trim((string)($payload['status'] ?? $before['status'] ?? 'active'));
        $allowedStatuses = ['active', 'suspended', 'revoked'];
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del cliente API es obligatorio.');
        }
        if (!in_array($status, $allowedStatuses, true)) {
            throw new \InvalidArgumentException('Estado de cliente API no permitido.');
        }

        $scopes = is_array($payload['scopes'] ?? null)
            ? array_values(array_filter(array_map('strval', $payload['scopes'])))
            : (is_array($before['scopes'] ?? null) ? $before['scopes'] : []);
        $rateLimit = max(1, (int)($payload['rateLimitPerMinute'] ?? $payload['rate_limit_per_minute'] ?? $before['rate_limit_per_minute'] ?? 120));

        $this->execute(
            'UPDATE loyalty_api_clients
             SET name = :name,
                 source = :source,
                 scopes = :scopes,
                 status = :status,
                 rate_limit_per_minute = :rate_limit_per_minute,
                 revoked_at = CASE WHEN :status = \'revoked\' THEN COALESCE(revoked_at, NOW()) ELSE NULL END,
                 updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id',
            [
                'tenant_id' => $tenantId,
                'id' => $clientId,
                'name' => $name,
                'source' => $source,
                'scopes' => json_encode($scopes),
                'status' => $status,
                'rate_limit_per_minute' => $rateLimit,
            ]
        );

        $after = $this->apiClientById($clientId) ?? [];
        $this->recordAudit('api_client.updated', 'api_client', $clientId, $before, $after, trim((string)($payload['reason'] ?? '')), $userId);

        return $after;
    }

    public function revokeApiClient(string $clientId, array $payload = [], ?string $userId = null): array {
        $payload['status'] = 'revoked';
        $payload['reason'] = trim((string)($payload['reason'] ?? 'Clave revocada por operador.'));

        return $this->updateApiClient($clientId, $payload, $userId);
    }

    public function externalProgram(): array {
        return [
            'program' => $this->program($this->tenantId()),
            'settings' => $this->settings()['settings'],
            'tiers' => $this->tierRules($this->tenantId()),
            'rewards' => $this->rewards(),
        ];
    }

    public function upsertExternalMember(array $payload, array $client): array {
        $existing = $this->memberFromPayload($payload);
        if ($existing) {
            return $this->updateMember((string)$existing['id'], $payload, 'api:' . ($client['id'] ?? 'external'));
        }

        return $this->createMember($payload, 'api:' . ($client['id'] ?? 'external'));
    }

    public function authenticateExternalClient(string $rawKey, string $requiredScope): array {
        $tenantId = $this->tenantId();
        $key = trim($rawKey);
        if ($key === '') {
            throw new \RuntimeException('Clave API requerida.');
        }
        $rows = $this->fetchAll(
            "SELECT id, name, source, scopes, status, rate_limit_per_minute
             FROM loyalty_api_clients
             WHERE tenant_id = :tenant_id AND key_hash = :key_hash AND status = 'active'
             LIMIT 1",
            ['tenant_id' => $tenantId, 'key_hash' => hash('sha256', $key)]
        );
        if ($rows === []) {
            throw new \RuntimeException('Clave API no autorizada.');
        }
        $client = $rows[0];
        $scopes = is_array($client['scopes'] ?? null) ? $client['scopes'] : [];
        if (!in_array($requiredScope, $scopes, true) && !in_array('*', $scopes, true)) {
            throw new \RuntimeException('El cliente API no tiene permisos para esta operacion.');
        }
        $this->execute(
            'UPDATE loyalty_api_clients SET last_used_at = NOW(), updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
            ['tenant_id' => $tenantId, 'id' => $client['id']]
        );

        return $client;
    }

    public function idempotentExternalMutation(string $operation, string $idempotencyKey, array $payload, callable $callback): array {
        $tenantId = $this->tenantId();
        $key = trim($idempotencyKey);
        if ($key === '') {
            throw new \InvalidArgumentException('Idempotency-Key es obligatorio para mutaciones externas.');
        }

        $requestHash = hash('sha256', json_encode($payload));
        $rows = $this->fetchAll(
            'SELECT request_hash, status_code, response_payload
             FROM loyalty_idempotency_keys
             WHERE tenant_id = :tenant_id AND idempotency_key = :idempotency_key AND operation = :operation
             LIMIT 1',
            ['tenant_id' => $tenantId, 'idempotency_key' => $key, 'operation' => $operation]
        );
        if ($rows !== []) {
            if (($rows[0]['request_hash'] ?? '') !== $requestHash) {
                throw new \InvalidArgumentException('Idempotency-Key ya fue usada con un payload diferente.');
            }
            return [
                'payload' => $rows[0]['response_payload'] ?? [],
                'status' => (int)($rows[0]['status_code'] ?? 200),
                'replayed' => true,
            ];
        }

        $payloadResult = $callback();
        $this->execute(
            'INSERT INTO loyalty_idempotency_keys
                (id, tenant_id, idempotency_key, operation, request_hash, status_code, response_payload)
             VALUES
                (:id, :tenant_id, :idempotency_key, :operation, :request_hash, :status_code, :response_payload)',
            [
                'id' => $this->id('idem'),
                'tenant_id' => $tenantId,
                'idempotency_key' => $key,
                'operation' => $operation,
                'request_hash' => $requestHash,
                'status_code' => 201,
                'response_payload' => json_encode($payloadResult),
            ]
        );

        return ['payload' => $payloadResult, 'status' => 201, 'replayed' => false];
    }

    private function memberFromPayload(array $payload): ?array {
        $tenantId = $this->tenantId();

        $byAccountId = function (string $accountId) use ($tenantId): ?array {
            $rows = $this->fetchAll(
                'SELECT m.*, COALESCE(a.balance, 0) AS points
                 FROM loyalty_members m
                 LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
                 WHERE m.tenant_id = :tenant_id AND m.account_id = :account_id
                 LIMIT 1',
                ['tenant_id' => $tenantId, 'account_id' => $accountId]
            );
            return $rows[0] ?? null;
        };

        $memberId = trim((string)($payload['memberId'] ?? $payload['member_id'] ?? ''));
        if ($memberId !== '') {
            // El identificador puede ser el id interno (member_xxx) o el id visible
            // del socio (account_id, p. ej. CLI-00848, que es lo que el operador ve
            // en la tarjeta y en el QR). Se aceptan ambos.
            return $this->memberById($memberId) ?? $byAccountId($memberId);
        }

        $accountId = trim((string)($payload['accountId'] ?? $payload['account_id'] ?? ''));
        if ($accountId !== '') {
            return $byAccountId($accountId);
        }

        $email = mb_strtolower(trim((string)($payload['customerEmail'] ?? $payload['email'] ?? '')));
        if ($email !== '') {
            $rows = $this->fetchAll(
                'SELECT m.*, COALESCE(a.balance, 0) AS points
                 FROM loyalty_members m
                 LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
                 WHERE m.tenant_id = :tenant_id AND lower(m.email) = :email
                 LIMIT 1',
                ['tenant_id' => $tenantId, 'email' => $email]
            );
            return $rows[0] ?? null;
        }

        return null;
    }

    private function assertMemberCanOperate(array $member, string $operation): void {
        $status = strtolower((string)($member['status'] ?? 'inactive'));
        if ($status !== 'active') {
            $this->recordRisk('high', 'inactive_member_operation', "Operacion bloqueada: socio no puede {$operation}.", (string)($member['id'] ?? ''), null, ['status' => $status]);
            throw new \InvalidArgumentException("Este socio no puede {$operation} porque no esta activo.");
        }
    }

    private function assertUniqueReference(string $tenantId, string $reference, string $entryType): void {
        $exists = (int)$this->scalar(
            'SELECT COUNT(*) FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id AND entry_type = :entry_type AND reference = :reference AND reversed_at IS NULL',
            ['tenant_id' => $tenantId, 'entry_type' => $entryType, 'reference' => $reference]
        );
        if ($exists > 0) {
            $this->recordRisk('high', 'duplicate_reference', 'Factura duplicada bloqueada.', null, $reference, ['entryType' => $entryType]);
            throw new \InvalidArgumentException('Esta factura ya fue registrada en el programa.');
        }
    }

    private function assertUniqueMemberIdentity(string $tenantId, string $accountId, string $email, ?string $excludeMemberId = null): void {
        $rows = $this->fetchAll(
            'SELECT id, account_id, account_name, email
             FROM loyalty_members
             WHERE tenant_id = :tenant_id
               AND id <> COALESCE(:exclude_member_id, \'\')
               AND (account_id = :account_id OR (:email <> \'\' AND lower(email) = :email))
             LIMIT 3',
            [
                'tenant_id' => $tenantId,
                'exclude_member_id' => $excludeMemberId,
                'account_id' => $accountId,
                'email' => $email,
            ]
        );

        if (!$rows) {
            return;
        }

        $messages = [];
        foreach ($rows as $row) {
            $owner = $this->memberConflictOwnerLabel($row);
            if ((string)($row['account_id'] ?? '') === $accountId) {
                $messages['account'] = "La cuenta {$accountId} ya esta asignada a {$owner}.";
            }
            if ($email !== '' && mb_strtolower((string)($row['email'] ?? '')) === $email) {
                $messages['email'] = "El correo {$email} ya esta registrado en {$owner}.";
            }
        }

        throw new \InvalidArgumentException(implode(' ', array_values($messages ?: ['Ya existe un socio con esa cuenta o correo.'])));
    }

    private function memberConflictOwnerLabel(array $row): string {
        $name = trim((string)($row['account_name'] ?? ''));
        $accountId = trim((string)($row['account_id'] ?? ''));

        if ($name !== '' && $accountId !== '') {
            return "{$name} ({$accountId})";
        }
        if ($name !== '') {
            return $name;
        }
        if ($accountId !== '') {
            return $accountId;
        }

        return 'otro socio';
    }

    private function throwMemberWriteException(\Throwable $e): void {
        if ($e instanceof \PDOException && $e->getCode() === '23505') {
            throw new \InvalidArgumentException('No se pudo guardar el socio porque la cuenta ya esta asignada a otro cliente.');
        }
    }

    private function calculatePurchasePoints(float $amount, array $member, array $settings): int {
        $earning = $settings['earning'] ?? [];
        $minimum = (float)($earning['minimumPurchaseAmount'] ?? 1.0);
        if ($amount < $minimum) {
            throw new \InvalidArgumentException(sprintf('El monto minimo para acumular puntos es %.2f.', $minimum));
        }

        $pointsPerUnit = max(0.0001, (float)($earning['pointsPerUnit'] ?? 1));
        $amountPerUnit = max(0.0001, (float)($earning['amountPerUnit'] ?? 1));
        $multiplier = $this->tierMultiplier((string)($member['tier'] ?? 'Bronce'));
        $raw = ($amount / $amountPerUnit) * $pointsPerUnit * $multiplier;
        $points = match ((string)($earning['roundingMode'] ?? 'floor')) {
            'ceil' => (int)ceil($raw),
            'round' => (int)round($raw),
            default => (int)floor($raw),
        };
        $points = max(1, $points);
        $maximum = max(1, (int)($earning['maximumPointsPerPurchase'] ?? 20000));
        if ($points > $maximum) {
            $this->recordRisk('medium', 'purchase_points_capped', 'Compra limitada por maximo de puntos.', (string)($member['id'] ?? ''), null, ['calculated' => $points, 'maximum' => $maximum]);
            $points = $maximum;
        }

        $dailyLimit = (int)($earning['maximumPointsPerMemberPerDay'] ?? 50000);
        $today = (int)$this->scalar(
            "SELECT COALESCE(SUM(points), 0)
             FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id
               AND member_id = :member_id
               AND entry_type = 'purchase'
               AND created_at::date = CURRENT_DATE",
            ['tenant_id' => $this->tenantId(), 'member_id' => $member['id']]
        );
        if ($dailyLimit > 0 && ($today + $points) > $dailyLimit) {
            $this->recordRisk('high', 'daily_earning_limit', 'Compra bloqueada por limite diario de puntos.', (string)$member['id'], null, ['today' => $today, 'points' => $points, 'limit' => $dailyLimit]);
            throw new \InvalidArgumentException('El socio alcanzo el limite diario de puntos acumulables.');
        }

        return $points;
    }

    private function purchaseFormulaSummary(array $settings, array $member): array {
        $earning = $settings['earning'] ?? [];
        return [
            'pointsPerUnit' => (float)($earning['pointsPerUnit'] ?? 1),
            'amountPerUnit' => (float)($earning['amountPerUnit'] ?? 1),
            'roundingMode' => (string)($earning['roundingMode'] ?? 'floor'),
            'tier' => (string)($member['tier'] ?? 'Bronce'),
            'tierMultiplier' => $this->tierMultiplier((string)($member['tier'] ?? 'Bronce')),
        ];
    }

    private function assertRedemptionLimits(string $tenantId, string $memberId, string $rewardId, array $settings): void {
        $redemption = $settings['redemption'] ?? [];
        $maxDaily = max(1, (int)($redemption['maximumRedemptionsPerMemberPerDay'] ?? 3));
        $maxSameReward = max(1, (int)($redemption['maximumSameRewardPerMemberPerDay'] ?? 1));
        $dailyTotal = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id AND member_id = :member_id AND status = 'approved' AND created_at::date = CURRENT_DATE",
            ['tenant_id' => $tenantId, 'member_id' => $memberId]
        );
        if ($dailyTotal >= $maxDaily) {
            $this->recordRisk('high', 'daily_redemption_limit', 'Canje bloqueado por limite diario.', $memberId, null, ['limit' => $maxDaily]);
            throw new \InvalidArgumentException('El socio alcanzo el limite diario de canjes.');
        }
        $sameReward = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id AND member_id = :member_id AND reward_id = :reward_id AND status = 'approved' AND created_at::date = CURRENT_DATE",
            ['tenant_id' => $tenantId, 'member_id' => $memberId, 'reward_id' => $rewardId]
        );
        if ($sameReward >= $maxSameReward) {
            $this->recordRisk('medium', 'same_reward_daily_limit', 'Canje bloqueado por limite del mismo premio.', $memberId, null, ['rewardId' => $rewardId, 'limit' => $maxSameReward]);
            throw new \InvalidArgumentException('El socio ya canjeo este premio hoy.');
        }
    }

    private function hasActiveWallet(string $memberId): bool {
        $passes = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_wallet_passes
             WHERE tenant_id = :tenant_id
               AND member_id = :member_id
               AND platform IN ('google', 'apple')
               AND status IN ('ready-for-issuer', 'issued', 'link-generated', 'synced', 'sync-error')",
            ['tenant_id' => $this->tenantId(), 'member_id' => $memberId]
        );

        return $passes > 0;
    }

    private function tierMultiplier(string $tierName): float {
        foreach ($this->tierRules($this->tenantId()) as $tier) {
            if (strcasecmp((string)$tier['name'], $tierName) === 0) {
                return max(0.01, (float)($tier['multiplier'] ?? 1));
            }
        }

        return 1.0;
    }

    private function tierRules(string $tenantId): array {
        $program = $this->program($tenantId);
        $this->ensureProgramConfiguration($tenantId, (string)$program['id']);
        return $this->fetchAll(
            "SELECT id, name, min_lifetime_points AS \"minLifetimePoints\", max_lifetime_points AS \"maxLifetimePoints\",
                    multiplier, benefits, status, sort_order AS \"sortOrder\"
             FROM loyalty_tier_rules
             WHERE tenant_id = :tenant_id
             ORDER BY sort_order ASC, min_lifetime_points ASC",
            ['tenant_id' => $tenantId]
        );
    }

    private function refreshMemberTier(string $tenantId, string $memberId): void {
        $account = $this->accountForMember($tenantId, $memberId);
        $tier = $this->tierForLifetimePoints($tenantId, (int)($account['lifetime_points'] ?? 0));
        $this->execute(
            'UPDATE loyalty_members SET tier = :tier, updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
            ['tier' => $tier, 'tenant_id' => $tenantId, 'id' => $memberId]
        );
    }

    private function refreshAllMemberTiers(string $tenantId): void {
        $rows = $this->fetchAll('SELECT id FROM loyalty_members WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]);
        foreach ($rows as $row) {
            $this->refreshMemberTier($tenantId, (string)$row['id']);
        }
    }

    private function tierForLifetimePoints(string $tenantId, int $lifetimePoints): string {
        foreach ($this->tierRules($tenantId) as $tier) {
            $min = (int)($tier['minLifetimePoints'] ?? 0);
            $max = $tier['maxLifetimePoints'] ?? null;
            if ($lifetimePoints >= $min && ($max === null || $max === '' || $lifetimePoints <= (int)$max)) {
                return (string)$tier['name'];
            }
        }

        return 'Bronce';
    }

    private function ensureProgramConfiguration(string $tenantId, string $programId): void {
        $schema = new LoyaltySchema($this->pdo);
        $exists = (int)$this->scalar('SELECT COUNT(*) FROM loyalty_program_settings WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]);
        if ($exists === 0) {
            $this->execute(
                'INSERT INTO loyalty_program_settings (tenant_id, program_id, settings)
                 VALUES (:tenant_id, :program_id, :settings)',
                ['tenant_id' => $tenantId, 'program_id' => $programId, 'settings' => json_encode($schema->defaultSettings())]
            );
        }
        $tiers = (int)$this->scalar('SELECT COUNT(*) FROM loyalty_tier_rules WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]);
        if ($tiers === 0) {
            foreach ($schema->defaultTierRules() as $tier) {
                $this->execute(
                    'INSERT INTO loyalty_tier_rules
                        (id, tenant_id, program_id, name, min_lifetime_points, max_lifetime_points, multiplier, benefits, sort_order)
                     VALUES
                        (:id, :tenant_id, :program_id, :name, :min_lifetime_points, :max_lifetime_points, :multiplier, :benefits, :sort_order)',
                    [
                        'id' => $this->id('tier'),
                        'tenant_id' => $tenantId,
                        'program_id' => $programId,
                        'name' => $tier['name'],
                        'min_lifetime_points' => $tier['minLifetimePoints'],
                        'max_lifetime_points' => $tier['maxLifetimePoints'],
                        'multiplier' => $tier['multiplier'],
                        'benefits' => json_encode($tier['benefits']),
                        'sort_order' => $tier['sortOrder'],
                    ]
                );
            }
        }
    }

    private function mergeSettings(array $current, array $incoming): array {
        foreach ($incoming as $key => $value) {
            if ($key === 'reason') {
                continue;
            }
            if (is_array($value) && isset($current[$key]) && is_array($current[$key])) {
                $current[$key] = $this->mergeSettings($current[$key], $value);
            } else {
                $current[$key] = $value;
            }
        }

        return $current;
    }

    private function validateSettings(array $settings): void {
        $earning = $settings['earning'] ?? [];
        if ((float)($earning['pointsPerUnit'] ?? 0) <= 0 || (float)($earning['amountPerUnit'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('La formula debe tener puntos y monto mayores a cero.');
        }
        if (!in_array((string)($earning['roundingMode'] ?? 'floor'), ['floor', 'round', 'ceil'], true)) {
            throw new \InvalidArgumentException('El redondeo debe ser hacia abajo, normal o hacia arriba.');
        }

        $wallet = is_array($settings['googleWallet'] ?? null) ? $settings['googleWallet'] : [];
        if (filter_var($wallet['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            $classSuffix = trim((string)($wallet['classSuffix'] ?? ''));
            if ($classSuffix === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $classSuffix)) {
                throw new \InvalidArgumentException('googleWallet.classSuffix es obligatorio y solo admite letras, numeros, punto, guion y guion bajo.');
            }
            if (!str_starts_with(trim((string)($wallet['logoUrl'] ?? '')), 'https://')) {
                throw new \InvalidArgumentException('googleWallet.logoUrl debe ser una URL https publica (Google exige logo para la clase).');
            }
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', trim((string)($wallet['hexBackgroundColor'] ?? '')))) {
                throw new \InvalidArgumentException('googleWallet.hexBackgroundColor debe ser un color hex de 6 digitos, por ejemplo #0F6E56.');
            }
            foreach ((array)($wallet['origins'] ?? []) as $origin) {
                if (!str_starts_with(trim((string)$origin), 'https://')) {
                    throw new \InvalidArgumentException('googleWallet.origins solo admite origenes https.');
                }
            }
        }
    }

    private function createWalletPass(string $tenantId, string $memberId, string $platform, string $accountId, array $payload): void {
        $this->execute(
            'INSERT INTO loyalty_wallet_passes
                (id, tenant_id, member_id, platform, external_object_id, status, last_payload)
             VALUES
                (:id, :tenant_id, :member_id, :platform, :external_object_id, :status, :last_payload)',
            [
                'id' => $this->id('pass'),
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'platform' => $platform,
                'external_object_id' => $this->walletExternalObjectId($platform, $accountId, $memberId),
                'status' => 'ready-for-issuer',
                'last_payload' => json_encode($payload),
            ]
        );
    }

    private function nextAccountId(string $tenantId): string {
        $next = (int)$this->scalar(
            "SELECT COALESCE(MAX(NULLIF(regexp_replace(account_id, '[^0-9]', '', 'g'), '')::integer), 1000) + 1
             FROM loyalty_members
             WHERE tenant_id = :tenant_id AND account_id LIKE 'FID-%'",
            ['tenant_id' => $tenantId]
        );

        return 'FID-' . $next;
    }

    private function recordAudit(string $action, string $subjectType, ?string $subjectId, ?array $before, array $after, ?string $reason = null, ?string $userId = null): void {
        $this->execute(
            'INSERT INTO loyalty_audit_events
                (id, tenant_id, actor_user_id, actor_type, action, subject_type, subject_id, reason, before_state, after_state)
             VALUES
                (:id, :tenant_id, :actor_user_id, :actor_type, :action, :subject_type, :subject_id, :reason, :before_state, :after_state)',
            [
                'id' => $this->id('audit'),
                'tenant_id' => $this->tenantId(),
                'actor_user_id' => $userId,
                'actor_type' => str_starts_with((string)$userId, 'api:') ? 'api' : 'dashboard',
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'reason' => $reason,
                'before_state' => json_encode($before ?? new \stdClass()),
                'after_state' => json_encode($after),
            ]
        );
    }

    private function recordRisk(string $severity, string $eventType, string $message, ?string $memberId = null, ?string $reference = null, array $metadata = []): void {
        $this->execute(
            'INSERT INTO loyalty_risk_events
                (id, tenant_id, severity, event_type, status, member_id, reference, message, metadata)
             VALUES
                (:id, :tenant_id, :severity, :event_type, :status, :member_id, :reference, :message, :metadata)',
            [
                'id' => $this->id('risk'),
                'tenant_id' => $this->tenantId(),
                'severity' => $severity,
                'event_type' => $eventType,
                'status' => 'open',
                'member_id' => $memberId,
                'reference' => $reference,
                'message' => $message,
                'metadata' => json_encode($metadata),
            ]
        );
    }

    private function eventPage(string $table, array $filters): array {
        $tenantId = $this->tenantId();
        $limit = min(200, max(10, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        [$from, $to] = $this->dateRange($filters);
        $where = [
            'tenant_id = :tenant_id',
            'created_at::date BETWEEN :from::date AND :to::date',
        ];
        $params = ['tenant_id' => $tenantId, 'from' => $from, 'to' => $to];

        if ($table === 'loyalty_risk_events') {
            $status = strtolower(trim((string)($filters['status'] ?? 'all')));
            if (in_array($status, ['open', 'resolved'], true)) {
                $where[] = 'status = :status';
                $params['status'] = $status;
            }

            $severity = strtolower(trim((string)($filters['severity'] ?? 'all')));
            if (in_array($severity, ['critical', 'high', 'medium', 'low', 'info'], true)) {
                $where[] = 'severity = :severity';
                $params['severity'] = $severity;
            }
        }

        $whereSql = implode(' AND ', $where);
        $items = $this->fetchAll(
            "SELECT *
             FROM {$table}
             WHERE {$whereSql}
             ORDER BY created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        $total = (int)$this->scalar(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE {$whereSql}",
            $params
        );

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    private function dateRange(array $filters): array {
        $to = trim((string)($filters['to'] ?? date('Y-m-d')));
        $from = trim((string)($filters['from'] ?? date('Y-m-d', strtotime('-30 days'))));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d', strtotime('-30 days'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }

        return [$from, $to];
    }

    private function resolveMember(string $tenantId, array $payload): array {
        $memberId = trim((string)($payload['memberId'] ?? $payload['member_id'] ?? ''));
        if ($memberId !== '') {
            $member = $this->memberById($memberId);
            if ($member) {
                return $member;
            }
        }

        $email = mb_strtolower(trim((string)($payload['customerEmail'] ?? $payload['email'] ?? '')));
        if ($email !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM loyalty_members WHERE tenant_id = :tenant_id AND lower(email) = :email LIMIT 1');
            $stmt->execute(['tenant_id' => $tenantId, 'email' => $email]);
            $member = $stmt->fetch();
            if ($member) {
                return $this->normalizeRow($member);
            }
        }

        $member = [
            'id' => $this->id('member'),
            'tenant_id' => $tenantId,
            'program_id' => $this->program($tenantId)['id'],
            'external_customer_id' => trim((string)($payload['externalCustomerId'] ?? $payload['external_customer_id'] ?? '')),
            'account_id' => 'FID-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'account_name' => trim((string)($payload['customerName'] ?? $payload['name'] ?? 'Cliente demo')),
            'email' => $email,
            'phone' => trim((string)($payload['phone'] ?? '')),
            'tier' => 'Bronce',
            'status' => 'active',
            'wallet_platform' => trim((string)($payload['walletPlatform'] ?? $payload['wallet_platform'] ?? 'none')),
            'metadata' => json_encode($payload['metadata'] ?? new \stdClass()),
        ];
        $this->execute(
            'INSERT INTO loyalty_members
                (id, tenant_id, program_id, external_customer_id, account_id, account_name, email, phone, tier, status, wallet_platform, metadata, last_activity_at)
             VALUES
                (:id, :tenant_id, :program_id, :external_customer_id, :account_id, :account_name, :email, :phone, :tier, :status, :wallet_platform, :metadata, NOW())',
            $member
        );
        $this->execute(
            'INSERT INTO loyalty_point_accounts (id, tenant_id, member_id, program_id, balance, lifetime_points)
             VALUES (:id, :tenant_id, :member_id, :program_id, 0, 0)',
            [
                'id' => $this->id('account'),
                'tenant_id' => $tenantId,
                'member_id' => $member['id'],
                'program_id' => $member['program_id'],
            ]
        );

        return $this->memberById($member['id']);
    }

    private function customerWhere(string $tenantId, array $filters): array {
        $params = ['tenant_id' => $tenantId];
        $where = 'm.tenant_id = :tenant_id';
        $search = trim((string)($filters['q'] ?? $filters['query'] ?? ''));
        if ($search !== '') {
            $params['search'] = '%' . mb_strtolower($search) . '%';
            $where .= " AND (lower(m.account_name) LIKE :search OR lower(m.email) LIKE :search OR lower(m.account_id) LIKE :search OR lower(COALESCE(m.phone, '')) LIKE :search)";
        }

        $wallet = strtolower(trim((string)($filters['wallet'] ?? 'all')));
        if (in_array($wallet, ['google', 'apple', 'none'], true)) {
            $params['wallet'] = $wallet;
            $where .= ' AND m.wallet_platform = :wallet';
        }

        $status = strtolower(trim((string)($filters['status'] ?? 'all')));
        if (in_array($status, ['active', 'blocked', 'inactive'], true)) {
            $params['status'] = $status;
            $where .= ' AND m.status = :status';
        }

        $tier = trim((string)($filters['tier'] ?? 'all'));
        if ($tier !== '' && strtolower($tier) !== 'all') {
            $params['tier'] = $tier;
            $where .= ' AND m.tier = :tier';
        }

        return [$where, $params];
    }

    private function customerOrderBy(string $sort): string {
        return match ($sort) {
            'points_desc' => 'COALESCE(a.balance, 0) DESC, m.last_activity_at DESC NULLS LAST',
            'points_asc' => 'COALESCE(a.balance, 0) ASC, m.account_name ASC',
            'name' => 'm.account_name ASC, m.created_at DESC',
            'oldest' => 'm.created_at ASC',
            default => 'm.last_activity_at DESC NULLS LAST, m.created_at DESC',
        };
    }

    private function memberById(string $memberId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, COALESCE(a.balance, 0) AS points
             FROM loyalty_members m
             LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
             WHERE m.tenant_id = :tenant_id AND m.id = :id
             LIMIT 1'
        );
        $stmt->execute(['tenant_id' => $this->tenantId(), 'id' => $memberId]);
        $row = $stmt->fetch();

        return $row ? $this->normalizeRow($row) : null;
    }

    private function rewardById(string $rewardId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM loyalty_rewards WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $stmt->execute(['tenant_id' => $this->tenantId(), 'id' => $rewardId]);

        return $this->normalizeRow($stmt->fetch() ?: []);
    }

    private function redemptionById(string $redemptionId): array {
        $rows = $this->fetchAll(
            "SELECT r.id, r.member_id, m.account_name AS customer, r.reward_id, w.name AS reward,
                    r.points_cost, r.status, r.metadata, r.created_at
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.id = r.member_id AND m.tenant_id = r.tenant_id
             JOIN loyalty_rewards w ON w.id = r.reward_id AND w.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant_id AND r.id = :id
             LIMIT 1",
            ['tenant_id' => $this->tenantId(), 'id' => $redemptionId]
        );

        return $rows[0] ?? [];
    }

    private function dashboardTopCustomers(string $tenantId, ?array $dateScope): array {
        if ($dateScope === null) {
            return $this->fetchAll(
                "SELECT m.id, m.account_id, m.account_name AS name, m.email, m.tier, m.wallet_platform AS wallet_platform,
                        COALESCE(a.balance, 0) AS points, m.last_activity_at
                 FROM loyalty_members m
                 LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
                 WHERE m.tenant_id = :tenant_id
                 ORDER BY COALESCE(a.balance, 0) DESC, m.last_activity_at DESC NULLS LAST
                 LIMIT 20",
                ['tenant_id' => $tenantId]
            );
        }

        return $this->fetchAll(
            "SELECT m.id, m.account_id, m.account_name AS name, m.email, m.tier, m.wallet_platform AS wallet_platform,
                    COALESCE(SUM(
                        CASE
                            WHEN l.entry_type = 'purchase' THEN l.points
                            WHEN l.entry_type = 'redemption' THEN ABS(l.points)
                            ELSE 0
                        END
                    ), 0) AS points,
                    m.last_activity_at
             FROM loyalty_members m
             JOIN loyalty_point_ledger l ON l.member_id = m.id AND l.tenant_id = m.tenant_id
             WHERE m.tenant_id = :tenant_id
               AND l.created_at::date BETWEEN :from::date AND :to::date
             GROUP BY m.id, m.account_id, m.account_name, m.email, m.tier, m.wallet_platform, m.last_activity_at
             ORDER BY points DESC, m.last_activity_at DESC NULLS LAST
             LIMIT 20",
            ['tenant_id' => $tenantId, 'from' => $dateScope['start'], 'to' => $dateScope['end']]
        );
    }

    private function walletSummary(string $tenantId): array {
        $rows = $this->fetchAll(
            "SELECT wallet_platform AS platform, COUNT(*) AS total
             FROM loyalty_members
             WHERE tenant_id = :tenant_id
             GROUP BY wallet_platform",
            ['tenant_id' => $tenantId]
        );
        $summary = ['google' => 0, 'apple' => 0, 'none' => 0];
        foreach ($rows as $row) {
            $platform = (string)($row['platform'] ?? 'none');
            $summary[$platform] = (int)($row['total'] ?? 0);
        }

        return $summary;
    }

    private function recentConsumptions(string $tenantId, ?array $dateScope): array {
        $where = 'l.tenant_id = :tenant_id AND l.entry_type = \'purchase\'';
        $params = ['tenant_id' => $tenantId];
        $limit = 12;
        if ($dateScope !== null) {
            $where .= ' AND l.created_at::date BETWEEN :from::date AND :to::date';
            $params['from'] = $dateScope['start'];
            $params['to'] = $dateScope['end'];
            $limit = 50;
        }

        return $this->fetchAll(
            "SELECT l.id, l.member_id, m.account_name AS customer, l.reference AS invoice_number,
                    l.points, l.metadata, l.created_at
             FROM loyalty_point_ledger l
             JOIN loyalty_members m ON m.id = l.member_id AND m.tenant_id = l.tenant_id
             WHERE {$where}
             ORDER BY l.created_at DESC
             LIMIT {$limit}",
            $params
        );
    }

    private function recentRedemptions(string $tenantId, ?array $dateScope = null): array {
        $where = 'r.tenant_id = :tenant_id';
        $params = ['tenant_id' => $tenantId];
        $limit = 10;
        if ($dateScope !== null) {
            $where .= ' AND r.created_at::date BETWEEN :from::date AND :to::date';
            $params['from'] = $dateScope['start'];
            $params['to'] = $dateScope['end'];
            $limit = 50;
        }

        return $this->fetchAll(
            "SELECT r.id, r.member_id, m.account_name AS customer, r.reward_id, w.name AS reward,
                    r.points_cost, r.status, r.metadata, r.created_at
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.id = r.member_id AND m.tenant_id = r.tenant_id
             JOIN loyalty_rewards w ON w.id = r.reward_id AND w.tenant_id = r.tenant_id
             WHERE {$where}
             ORDER BY r.created_at DESC
             LIMIT {$limit}",
            $params
        );
    }

    private function recommendedActions(string $tenantId): array {
        $withoutWallet = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id AND wallet_platform = 'none'",
            ['tenant_id' => $tenantId]
        );
        $lowStock = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_rewards WHERE tenant_id = :tenant_id AND status = 'active' AND stock <= 5",
            ['tenant_id' => $tenantId]
        );
        $inactive = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_members
             WHERE tenant_id = :tenant_id
               AND COALESCE(last_activity_at, created_at) < NOW() - INTERVAL '30 days'",
            ['tenant_id' => $tenantId]
        );

        return [
            [
                'id' => 'wallet-adoption',
                'label' => 'Activar tarjetas digitales',
                'detail' => "{$withoutWallet} socios aun no tienen tarjeta Android/iPhone.",
                'priority' => $withoutWallet > 0 ? 'high' : 'normal',
            ],
            [
                'id' => 'reward-stock',
                'label' => 'Reponer premios',
                'detail' => "{$lowStock} premios activos estan con stock bajo.",
                'priority' => $lowStock > 0 ? 'medium' : 'normal',
            ],
            [
                'id' => 'reactivation',
                'label' => 'Reactivar socios',
                'detail' => "{$inactive} socios no compran hace mas de 30 dias.",
                'priority' => $inactive > 0 ? 'medium' : 'normal',
            ],
        ];
    }

    private function dashboardAnalytics(string $tenantId, array $metrics, ?array $dateScope): array {
        $activeMembers = max(0, (int)($metrics['activeMembers'] ?? 0));
        $withDigitalCard = min($activeMembers, max(0, (int)($metrics['digitalMembers'] ?? 0)));
        $recentBuyers = (int)$this->scalar(
            "SELECT COUNT(DISTINCT member_id)
             FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id
               AND entry_type = 'purchase'
               AND created_at >= NOW() - INTERVAL '30 days'",
            ['tenant_id' => $tenantId]
        );
        $recentRedeemers = (int)$this->scalar(
            "SELECT COUNT(DISTINCT member_id)
             FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id
               AND created_at >= NOW() - INTERVAL '30 days'",
            ['tenant_id' => $tenantId]
        );
        $availablePoints = (int)$this->scalar(
            'SELECT COALESCE(SUM(balance), 0) FROM loyalty_point_accounts WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId]
        );

        return [
            'activityTrend' => $this->activityTrend($tenantId, $dateScope),
            'tierSummary' => $this->tierSummary($tenantId),
            'operationalFunnel' => [
                [
                    'label' => 'Socios activos',
                    'total' => $activeMembers,
                    'rate' => 100,
                    'hint' => 'Base que puede acumular y canjear puntos.',
                ],
                [
                    'label' => 'Con tarjeta digital',
                    'total' => $withDigitalCard,
                    'rate' => $this->percentage($withDigitalCard, $activeMembers),
                    'hint' => 'Socios listos para usar Android o iPhone.',
                ],
                [
                    'label' => 'Compraron en 30 dias',
                    'total' => $recentBuyers,
                    'rate' => $this->percentage($recentBuyers, $activeMembers),
                    'hint' => 'Socios con actividad comercial reciente.',
                ],
                [
                    'label' => 'Canjearon en 30 dias',
                    'total' => $recentRedeemers,
                    'rate' => $this->percentage($recentRedeemers, $activeMembers),
                    'hint' => 'Socios que ya percibieron valor del programa.',
                ],
            ],
            'valueAtRisk' => [
                'availablePoints' => $availablePoints,
                'estimatedValue' => round($availablePoints / max(1, (float)($metrics['averageTicket'] ?? 1)), 2),
                'digitalCardRate' => $this->percentage($withDigitalCard, $activeMembers),
                'redemptionRate' => $this->percentage($recentRedeemers, max(1, $recentBuyers)),
            ],
            'periodMetrics' => $this->periodMetrics($tenantId, $metrics, $dateScope),
        ];
    }

    private function periodMetrics(string $tenantId, array $baseMetrics, ?array $dateScope): array {
        if ($dateScope !== null) {
            return $this->periodMetricsForWindow($tenantId, $dateScope);
        }

        $row = $this->fetchAll(
            "SELECT
                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND l.entry_type IN ('purchase', 'redemption') AND l.created_at >= CURRENT_DATE - INTERVAL '6 days') AS active_7,
                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND m.wallet_platform IN ('google', 'apple') AND l.entry_type IN ('purchase', 'redemption') AND l.created_at >= CURRENT_DATE - INTERVAL '6 days') AS digital_7,
                COALESCE(SUM(l.points) FILTER (WHERE l.entry_type = 'purchase' AND l.points > 0 AND l.created_at >= CURRENT_DATE - INTERVAL '6 days'), 0) AS issued_7,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption' AND l.created_at >= CURRENT_DATE - INTERVAL '6 days') AS redemptions_7,
                COALESCE(AVG((l.metadata->>'invoiceAmount')::numeric) FILTER (WHERE l.entry_type = 'purchase' AND jsonb_exists(l.metadata, 'invoiceAmount') AND l.created_at >= CURRENT_DATE - INTERVAL '6 days'), 0) AS average_ticket_7,

                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND l.entry_type IN ('purchase', 'redemption') AND l.created_at >= CURRENT_DATE - INTERVAL '13 days') AS active_14,
                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND m.wallet_platform IN ('google', 'apple') AND l.entry_type IN ('purchase', 'redemption') AND l.created_at >= CURRENT_DATE - INTERVAL '13 days') AS digital_14,
                COALESCE(SUM(l.points) FILTER (WHERE l.entry_type = 'purchase' AND l.points > 0 AND l.created_at >= CURRENT_DATE - INTERVAL '13 days'), 0) AS issued_14,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption' AND l.created_at >= CURRENT_DATE - INTERVAL '13 days') AS redemptions_14,
                COALESCE(AVG((l.metadata->>'invoiceAmount')::numeric) FILTER (WHERE l.entry_type = 'purchase' AND jsonb_exists(l.metadata, 'invoiceAmount') AND l.created_at >= CURRENT_DATE - INTERVAL '13 days'), 0) AS average_ticket_14,

                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND l.entry_type IN ('purchase', 'redemption') AND l.created_at >= CURRENT_DATE - INTERVAL '29 days') AS active_30,
                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND m.wallet_platform IN ('google', 'apple') AND l.entry_type IN ('purchase', 'redemption') AND l.created_at >= CURRENT_DATE - INTERVAL '29 days') AS digital_30,
                COALESCE(SUM(l.points) FILTER (WHERE l.entry_type = 'purchase' AND l.points > 0 AND l.created_at >= CURRENT_DATE - INTERVAL '29 days'), 0) AS issued_30,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption' AND l.created_at >= CURRENT_DATE - INTERVAL '29 days') AS redemptions_30,
                COALESCE(AVG((l.metadata->>'invoiceAmount')::numeric) FILTER (WHERE l.entry_type = 'purchase' AND jsonb_exists(l.metadata, 'invoiceAmount') AND l.created_at >= CURRENT_DATE - INTERVAL '29 days'), 0) AS average_ticket_30,

                COALESCE(SUM(l.points) FILTER (WHERE l.entry_type = 'purchase' AND l.points > 0), 0) AS issued_all,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption') AS redemptions_all,
                COALESCE(AVG((l.metadata->>'invoiceAmount')::numeric) FILTER (WHERE l.entry_type = 'purchase' AND jsonb_exists(l.metadata, 'invoiceAmount')), 0) AS average_ticket_all
             FROM loyalty_point_ledger l
             JOIN loyalty_members m ON m.id = l.member_id AND m.tenant_id = l.tenant_id
             WHERE l.tenant_id = :tenant_id",
            ['tenant_id' => $tenantId]
        )[0] ?? [];

        return [
            '7' => $this->metricRow($row, '7', null, null),
            '14' => $this->metricRow($row, '14', null, null),
            '30' => $this->metricRow($row, '30', null, null),
            'all' => $this->metricRow($row, 'all', (int)($baseMetrics['activeMembers'] ?? 0), (int)($baseMetrics['digitalMembers'] ?? 0)),
        ];
    }

    private function periodMetricsForWindow(string $tenantId, array $dateScope): array {
        $monthStart = new \DateTimeImmutable((string)$dateScope['start']);
        $monthEnd = new \DateTimeImmutable((string)$dateScope['end']);
        $start7 = $this->maxDate($monthStart, $monthEnd->modify('-6 days'))->format('Y-m-d');
        $start14 = $this->maxDate($monthStart, $monthEnd->modify('-13 days'))->format('Y-m-d');
        $start30 = $this->maxDate($monthStart, $monthEnd->modify('-29 days'))->format('Y-m-d');
        $end = $monthEnd->format('Y-m-d');

        $row = $this->fetchAll(
            "SELECT
                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND l.entry_type IN ('purchase', 'redemption') AND l.created_at::date BETWEEN :start_7::date AND :end::date) AS active_7,
                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND m.wallet_platform IN ('google', 'apple') AND l.entry_type IN ('purchase', 'redemption') AND l.created_at::date BETWEEN :start_7::date AND :end::date) AS digital_7,
                COALESCE(SUM(l.points) FILTER (WHERE l.entry_type = 'purchase' AND l.points > 0 AND l.created_at::date BETWEEN :start_7::date AND :end::date), 0) AS issued_7,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption' AND l.created_at::date BETWEEN :start_7::date AND :end::date) AS redemptions_7,
                COALESCE(AVG((l.metadata->>'invoiceAmount')::numeric) FILTER (WHERE l.entry_type = 'purchase' AND jsonb_exists(l.metadata, 'invoiceAmount') AND l.created_at::date BETWEEN :start_7::date AND :end::date), 0) AS average_ticket_7,

                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND l.entry_type IN ('purchase', 'redemption') AND l.created_at::date BETWEEN :start_14::date AND :end::date) AS active_14,
                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND m.wallet_platform IN ('google', 'apple') AND l.entry_type IN ('purchase', 'redemption') AND l.created_at::date BETWEEN :start_14::date AND :end::date) AS digital_14,
                COALESCE(SUM(l.points) FILTER (WHERE l.entry_type = 'purchase' AND l.points > 0 AND l.created_at::date BETWEEN :start_14::date AND :end::date), 0) AS issued_14,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption' AND l.created_at::date BETWEEN :start_14::date AND :end::date) AS redemptions_14,
                COALESCE(AVG((l.metadata->>'invoiceAmount')::numeric) FILTER (WHERE l.entry_type = 'purchase' AND jsonb_exists(l.metadata, 'invoiceAmount') AND l.created_at::date BETWEEN :start_14::date AND :end::date), 0) AS average_ticket_14,

                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND l.entry_type IN ('purchase', 'redemption') AND l.created_at::date BETWEEN :start_30::date AND :end::date) AS active_30,
                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND m.wallet_platform IN ('google', 'apple') AND l.entry_type IN ('purchase', 'redemption') AND l.created_at::date BETWEEN :start_30::date AND :end::date) AS digital_30,
                COALESCE(SUM(l.points) FILTER (WHERE l.entry_type = 'purchase' AND l.points > 0 AND l.created_at::date BETWEEN :start_30::date AND :end::date), 0) AS issued_30,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption' AND l.created_at::date BETWEEN :start_30::date AND :end::date) AS redemptions_30,
                COALESCE(AVG((l.metadata->>'invoiceAmount')::numeric) FILTER (WHERE l.entry_type = 'purchase' AND jsonb_exists(l.metadata, 'invoiceAmount') AND l.created_at::date BETWEEN :start_30::date AND :end::date), 0) AS average_ticket_30,

                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND l.entry_type IN ('purchase', 'redemption') AND l.created_at::date BETWEEN :month_start::date AND :end::date) AS active_all,
                COUNT(DISTINCT l.member_id) FILTER (WHERE m.status = 'active' AND m.wallet_platform IN ('google', 'apple') AND l.entry_type IN ('purchase', 'redemption') AND l.created_at::date BETWEEN :month_start::date AND :end::date) AS digital_all,
                COALESCE(SUM(l.points) FILTER (WHERE l.entry_type = 'purchase' AND l.points > 0 AND l.created_at::date BETWEEN :month_start::date AND :end::date), 0) AS issued_all,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption' AND l.created_at::date BETWEEN :month_start::date AND :end::date) AS redemptions_all,
                COALESCE(AVG((l.metadata->>'invoiceAmount')::numeric) FILTER (WHERE l.entry_type = 'purchase' AND jsonb_exists(l.metadata, 'invoiceAmount') AND l.created_at::date BETWEEN :month_start::date AND :end::date), 0) AS average_ticket_all
             FROM loyalty_point_ledger l
             JOIN loyalty_members m ON m.id = l.member_id AND m.tenant_id = l.tenant_id
             WHERE l.tenant_id = :tenant_id
               AND l.created_at::date BETWEEN :month_start::date AND :end::date",
            [
                'tenant_id' => $tenantId,
                'month_start' => $monthStart->format('Y-m-d'),
                'start_7' => $start7,
                'start_14' => $start14,
                'start_30' => $start30,
                'end' => $end,
            ]
        )[0] ?? [];

        return [
            '7' => $this->metricRow($row, '7', null, null),
            '14' => $this->metricRow($row, '14', null, null),
            '30' => $this->metricRow($row, '30', null, null),
            'all' => $this->metricRow($row, 'all', null, null),
        ];
    }

    private function metricRow(array $row, string $suffix, ?int $activeMembers, ?int $digitalMembers): array {
        $active = $activeMembers ?? (int)($row["active_{$suffix}"] ?? 0);
        $digital = $digitalMembers ?? (int)($row["digital_{$suffix}"] ?? 0);

        return [
            'activeMembers' => $active,
            'digitalMembers' => min($active, max(0, $digital)),
            'issuedPoints' => (int)($row["issued_{$suffix}"] ?? 0),
            'monthlyRedemptions' => (int)($row["redemptions_{$suffix}"] ?? 0),
            'averageTicket' => (float)($row["average_ticket_{$suffix}"] ?? 0),
        ];
    }

    private function normalizeDashboardMonth(?string $month): ?string {
        $month = trim((string)$month);
        if ($month === '' || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return null;
        }

        [$year, $monthNumber] = array_map('intval', explode('-', $month));
        if (!checkdate($monthNumber, 1, $year)) {
            return null;
        }

        $selected = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $monthNumber));
        $current = new \DateTimeImmutable('first day of this month');
        if ($selected > $current) {
            return $current->format('Y-m');
        }

        return $selected->format('Y-m');
    }

    private function dashboardDateScope(?string $month): ?array {
        if ($month === null) {
            return null;
        }

        $start = new \DateTimeImmutable("{$month}-01");
        $lastDay = $start->modify('last day of this month');
        $today = new \DateTimeImmutable('today');
        $end = $lastDay > $today ? $today : $lastDay;

        return [
            'month' => $start->format('Y-m'),
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    private function maxDate(\DateTimeImmutable $left, \DateTimeImmutable $right): \DateTimeImmutable {
        return $left > $right ? $left : $right;
    }

    private function activityTrend(string $tenantId, ?array $dateScope = null): array {
        if ($dateScope !== null) {
            return $this->fetchAll(
                "WITH days AS (
                    SELECT generate_series(:from::date, :to::date, INTERVAL '1 day')::date AS day
                 )
                 SELECT
                    to_char(days.day, 'YYYY-MM-DD') AS date,
                    to_char(days.day, 'DD Mon') AS label,
                    COALESCE(SUM(CASE WHEN l.entry_type = 'purchase' THEN l.points ELSE 0 END), 0) AS points_issued,
                    ABS(COALESCE(SUM(CASE WHEN l.entry_type = 'redemption' THEN l.points ELSE 0 END), 0)) AS points_redeemed,
                    COUNT(l.id) FILTER (WHERE l.entry_type = 'purchase') AS purchases,
                    COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption') AS redemptions
                  FROM days
                  LEFT JOIN loyalty_point_ledger l
                    ON l.tenant_id = :tenant_id
                   AND l.created_at::date = days.day
                  GROUP BY days.day
                  ORDER BY days.day",
                ['tenant_id' => $tenantId, 'from' => $dateScope['start'], 'to' => $dateScope['end']]
            );
        }

        return $this->fetchAll(
            "WITH days AS (
                SELECT generate_series(CURRENT_DATE - INTERVAL '29 days', CURRENT_DATE, INTERVAL '1 day')::date AS day
             )
             SELECT
                to_char(days.day, 'YYYY-MM-DD') AS date,
                to_char(days.day, 'DD Mon') AS label,
                COALESCE(SUM(CASE WHEN l.entry_type = 'purchase' THEN l.points ELSE 0 END), 0) AS points_issued,
                ABS(COALESCE(SUM(CASE WHEN l.entry_type = 'redemption' THEN l.points ELSE 0 END), 0)) AS points_redeemed,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'purchase') AS purchases,
                COUNT(l.id) FILTER (WHERE l.entry_type = 'redemption') AS redemptions
              FROM days
              LEFT JOIN loyalty_point_ledger l
                ON l.tenant_id = :tenant_id
               AND l.created_at::date = days.day
              GROUP BY days.day
              ORDER BY days.day",
            ['tenant_id' => $tenantId]
        );
    }

    private function tierSummary(string $tenantId): array {
        return $this->fetchAll(
            "SELECT tier, COUNT(*) AS total
             FROM loyalty_members
             WHERE tenant_id = :tenant_id
             GROUP BY tier
             ORDER BY CASE tier WHEN 'Oro' THEN 1 WHEN 'Plata' THEN 2 WHEN 'Bronce' THEN 3 ELSE 4 END, tier",
            ['tenant_id' => $tenantId]
        );
    }

    private function percentage(int $value, int $total): float {
        if ($total <= 0) {
            return 0.0;
        }

        return round(min(100, max(0, ($value / $total) * 100)), 1);
    }

    private function accountForMember(string $tenantId, string $memberId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM loyalty_point_accounts WHERE tenant_id = :tenant_id AND member_id = :member_id LIMIT 1');
        $stmt->execute(['tenant_id' => $tenantId, 'member_id' => $memberId]);
        $account = $stmt->fetch();
        if ($account) {
            return $this->normalizeRow($account);
        }

        $program = $this->program($tenantId);
        $this->execute(
            'INSERT INTO loyalty_point_accounts (id, tenant_id, member_id, program_id, balance, lifetime_points)
             VALUES (:id, :tenant_id, :member_id, :program_id, 0, 0)',
            [
                'id' => $this->id('account'),
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'program_id' => $program['id'],
            ]
        );

        return $this->accountForMember($tenantId, $memberId);
    }

    private function program(string $tenantId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM loyalty_programs WHERE tenant_id = :tenant_id ORDER BY created_at ASC LIMIT 1');
        $stmt->execute(['tenant_id' => $tenantId]);
        $program = $stmt->fetch();
        if ($program) {
            return $this->normalizeRow($program);
        }

        $programId = $this->id('program');
        $this->execute(
            'INSERT INTO loyalty_programs
                (id, tenant_id, name, status, points_per_currency, currency_code, wallet_issuer_name, wallet_program_name, brand_color, logo_url)
             VALUES
                (:id, :tenant_id, :name, :status, :points_per_currency, :currency_code, :wallet_issuer_name, :wallet_program_name, :brand_color, :logo_url)',
            [
                'id' => $programId,
                'tenant_id' => $tenantId,
                'name' => 'Fidepuntos Demo',
                'status' => 'active',
                'points_per_currency' => 1,
                'currency_code' => 'USD',
                'wallet_issuer_name' => 'TECNOLTS',
                'wallet_program_name' => 'Fidepuntos',
                'brand_color' => '#1D4ED8',
                'logo_url' => '',
            ]
        );

        return $this->program($tenantId);
    }

    private function ensureDemoData(string $tenantId): void {
        $program = $this->program($tenantId);
        $existing = (int)$this->scalar('SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]);
        if ($existing > 0) {
            $this->ensureDemoEnhancements($tenantId, $program);
            return;
        }

        $members = [
            ['Mariana Hidalgo', 'mariana.hidalgo@andesshop.ec', 'FID-1001', 'Oro', 'google', 12450, 149.90],
            ['Carlos Mejia', 'carlos.mejia@marketquito.ec', 'FID-1002', 'Plata', 'apple', 8120, 82.40],
            ['Ana Salazar', 'ana.salazar@familiasalazar.ec', 'FID-1003', 'Bronce', 'none', 3680, 45.10],
            ['Daniel Castro', 'daniel.castro@castroretail.ec', 'FID-1004', 'Plata', 'google', 5940, 63.75],
        ];
        foreach ($members as [$name, $email, $accountId, $tier, $wallet, $points, $amount]) {
            $memberId = $this->id('member');
            $this->execute(
                'INSERT INTO loyalty_members
                    (id, tenant_id, program_id, account_id, account_name, email, tier, status, wallet_platform, last_activity_at)
                 VALUES
                    (:id, :tenant_id, :program_id, :account_id, :account_name, :email, :tier, :status, :wallet_platform, NOW())',
                [
                    'id' => $memberId,
                    'tenant_id' => $tenantId,
                    'program_id' => $program['id'],
                    'account_id' => $accountId,
                    'account_name' => $name,
                    'email' => $email,
                    'tier' => $tier,
                    'status' => 'active',
                    'wallet_platform' => $wallet,
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_accounts (id, tenant_id, member_id, program_id, balance, lifetime_points)
                 VALUES (:id, :tenant_id, :member_id, :program_id, :balance, :lifetime_points)',
                [
                    'id' => $this->id('account'),
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'program_id' => $program['id'],
                    'balance' => $points,
                    'lifetime_points' => $points,
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, metadata)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :metadata)',
                [
                    'id' => $this->id('ledger'),
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'program_id' => $program['id'],
                    'entry_type' => 'purchase',
                    'points' => $points,
                    'balance_after' => $points,
                    'reference' => '001-001-' . str_pad(substr($accountId, -4), 9, '0', STR_PAD_LEFT),
                    'source' => 'pos',
                    'metadata' => json_encode(['invoiceAmount' => $amount, 'invoiceNumber' => '001-001-' . str_pad(substr($accountId, -4), 9, '0', STR_PAD_LEFT), 'store' => 'Sucursal Norte']),
                ]
            );
        }

        foreach ([
            ['Bono de compra $10', 'Credito comercial aplicable en la siguiente factura.', 1000, 25],
            ['Envio a domicilio sin costo', 'Entrega gratis dentro de la zona configurada.', 450, 100],
            ['Producto seleccionado del mes', 'Producto promocional definido por el comercio.', 1800, 12],
        ] as [$name, $description, $pointsCost, $stock]) {
            $this->execute(
                'INSERT INTO loyalty_rewards (id, tenant_id, program_id, name, description, points_cost, stock, status)
                 VALUES (:id, :tenant_id, :program_id, :name, :description, :points_cost, :stock, :status)',
                [
                    'id' => $this->id('reward'),
                    'tenant_id' => $tenantId,
                    'program_id' => $program['id'],
                    'name' => $name,
                    'description' => $description,
                    'points_cost' => $pointsCost,
                    'stock' => $stock,
                    'status' => 'active',
                ]
            );
        }

        $this->ensureDemoEnhancements($tenantId, $program);
    }

    private function ensureDemoEnhancements(string $tenantId, array $program): void {
        foreach ([
            ['Lucia Paredes', 'lucia.paredes@paredesstore.ec', 'FID-1005', 'Oro', 'apple', 15320, 120.30],
            ['Jorge Andrade', 'jorge.andrade@andradehogar.ec', 'FID-1006', 'Bronce', 'none', 980, 18.75],
            ['Paola Vera', 'paola.vera@veracompras.ec', 'FID-1007', 'Plata', 'google', 6420, 70.25],
            ['Rafael Cardenas', 'rafael.cardenas@cardenas.ec', 'FID-1008', 'Bronce', 'none', 240, 12.00],
        ] as [$name, $email, $accountId, $tier, $wallet, $points, $amount]) {
            $exists = (int)$this->scalar(
                'SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id AND account_id = :account_id',
                ['tenant_id' => $tenantId, 'account_id' => $accountId]
            );
            if ($exists > 0) {
                continue;
            }

            $memberId = $this->id('member');
            $this->execute(
                'INSERT INTO loyalty_members
                    (id, tenant_id, program_id, account_id, account_name, email, tier, status, wallet_platform, last_activity_at)
                 VALUES
                    (:id, :tenant_id, :program_id, :account_id, :account_name, :email, :tier, :status, :wallet_platform, NOW() - INTERVAL \'8 days\')',
                [
                    'id' => $memberId,
                    'tenant_id' => $tenantId,
                    'program_id' => $program['id'],
                    'account_id' => $accountId,
                    'account_name' => $name,
                    'email' => $email,
                    'tier' => $tier,
                    'status' => 'active',
                    'wallet_platform' => $wallet,
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_accounts (id, tenant_id, member_id, program_id, balance, lifetime_points)
                 VALUES (:id, :tenant_id, :member_id, :program_id, :balance, :lifetime_points)',
                [
                    'id' => $this->id('account'),
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'program_id' => $program['id'],
                    'balance' => $points,
                    'lifetime_points' => $points,
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, metadata, created_at)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :metadata, NOW() - INTERVAL \'8 days\')',
                [
                    'id' => $this->id('ledger'),
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'program_id' => $program['id'],
                    'entry_type' => 'purchase',
                    'points' => $points,
                    'balance_after' => $points,
                    'reference' => '001-002-' . str_pad(substr($accountId, -4), 9, '0', STR_PAD_LEFT),
                    'source' => 'pos',
                    'metadata' => json_encode(['invoiceAmount' => $amount, 'invoiceNumber' => '001-002-' . str_pad(substr($accountId, -4), 9, '0', STR_PAD_LEFT), 'store' => 'Sucursal Cumbaya']),
                ]
            );
        }

        foreach ([
            ['Cafe de cortesia', 'Beneficio para punto fisico o cafeteria aliada.', 300, 50],
            ['Atencion preferente 30 dias', 'Activa prioridad de atencion para socios frecuentes.', 2500, 8],
            ['Bono de compra $5', 'Credito comercial aplicable en la siguiente factura.', 700, 40],
            ['Experiencia premium', 'Premio de alto valor para clientes Oro.', 5000, 4],
        ] as [$name, $description, $pointsCost, $stock]) {
            $exists = (int)$this->scalar(
                'SELECT COUNT(*) FROM loyalty_rewards WHERE tenant_id = :tenant_id AND lower(name) = lower(:name)',
                ['tenant_id' => $tenantId, 'name' => $name]
            );
            if ($exists > 0) {
                continue;
            }
            $this->execute(
                'INSERT INTO loyalty_rewards (id, tenant_id, program_id, name, description, points_cost, stock, status)
                 VALUES (:id, :tenant_id, :program_id, :name, :description, :points_cost, :stock, :status)',
                [
                    'id' => $this->id('reward'),
                    'tenant_id' => $tenantId,
                    'program_id' => $program['id'],
                    'name' => $name,
                    'description' => $description,
                    'points_cost' => $pointsCost,
                    'stock' => $stock,
                    'status' => 'active',
                ]
            );
        }

        $redemptions = (int)$this->scalar('SELECT COUNT(*) FROM loyalty_redemptions WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]);
        if ($redemptions === 0) {
            $this->seedDemoRedemption($tenantId, 'FID-1001', 'Envio a domicilio sin costo');
            $this->seedDemoRedemption($tenantId, 'FID-1002', 'Cafe de cortesia');
        }

        $this->execute(
            "INSERT INTO loyalty_wallet_passes (id, tenant_id, member_id, platform, external_object_id, status, last_payload)
             SELECT
                'pass_' || substr(md5(m.id || m.wallet_platform), 1, 16),
                m.tenant_id,
                m.id,
                m.wallet_platform,
                m.tenant_id || '.' || m.account_id,
                'ready-for-issuer',
                jsonb_build_object('source', 'demo-seed')
             FROM loyalty_members m
             WHERE m.tenant_id = :tenant_id
               AND m.wallet_platform IN ('google', 'apple')
               AND NOT EXISTS (
                   SELECT 1 FROM loyalty_wallet_passes p
                   WHERE p.tenant_id = m.tenant_id
                     AND p.member_id = m.id
                     AND p.platform = m.wallet_platform
               )",
            ['tenant_id' => $tenantId]
        );
    }

    private function seedDemoRedemption(string $tenantId, string $accountId, string $rewardName): void {
        $memberRows = $this->fetchAll(
            'SELECT id FROM loyalty_members WHERE tenant_id = :tenant_id AND account_id = :account_id LIMIT 1',
            ['tenant_id' => $tenantId, 'account_id' => $accountId]
        );
        $rewardRows = $this->fetchAll(
            'SELECT id FROM loyalty_rewards WHERE tenant_id = :tenant_id AND lower(name) = lower(:name) LIMIT 1',
            ['tenant_id' => $tenantId, 'name' => $rewardName]
        );
        if ($memberRows === [] || $rewardRows === []) {
            return;
        }

        try {
            $this->redeemReward([
                'memberId' => (string)$memberRows[0]['id'],
                'rewardId' => (string)$rewardRows[0]['id'],
            ], null);
        } catch (\Throwable) {
            return;
        }
    }

    private function ensureSchema(): void {
        (new LoyaltySchema($this->pdo))->ensure();
    }

    private function schemaExists(): bool {
        $stmt = $this->pdo->query("
            SELECT COUNT(*)
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relname IN (
                'loyalty_programs',
                'loyalty_members',
                'loyalty_point_accounts',
                'loyalty_point_ledger',
                'loyalty_rewards',
                'loyalty_redemptions',
                'loyalty_wallet_passes'
              )
              AND c.relkind IN ('r', 'p')
        ");

        return (int)$stmt->fetchColumn() === 7;
    }

    private function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll() ?: []);
    }

    private function execute(string $sql, array $params = []): void {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function scalar(string $sql, array $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private function normalizeRow(array $row): array {
        foreach ($row as $key => $value) {
            if (is_string($value) && in_array($key, ['metadata', 'last_payload', 'settings', 'benefits', 'scopes', 'before_state', 'after_state', 'response_payload'], true)) {
                $decoded = json_decode($value, true);
                $row[$key] = is_array($decoded) ? $decoded : [];
            }
            if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                $row[$key] = (int)$value;
            }
            if (is_string($value) && preg_match('/^-?\d+\.\d+$/', $value)) {
                $row[$key] = (float)$value;
            }
        }

        return $row;
    }

    private function apiClientById(string $clientId): ?array {
        $rows = $this->fetchAll(
            'SELECT id, name, source, scopes, status, rate_limit_per_minute, last_used_at, created_at, updated_at, revoked_at
             FROM loyalty_api_clients
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1',
            ['tenant_id' => $this->tenantId(), 'id' => $clientId]
        );

        return $rows[0] ?? null;
    }

    private function humanizeKey(string $key): string {
        $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key) ?? $key;
        $key = str_replace(['_', '-'], ' ', $key);

        return mb_convert_case(trim($key), MB_CASE_TITLE, 'UTF-8');
    }

    private function writeCsvLine($handle, array $fields): void {
        fputcsv($handle, $fields, ',', '"', '');
    }

    private function excelEscape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }

    private function xlsxWorksheet(string $sheetRows): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>'
            . '<col min="1" max="1" width="22" customWidth="1"/>'
            . '<col min="2" max="2" width="26" customWidth="1"/>'
            . '<col min="3" max="8" width="20" customWidth="1"/>'
            . '<col min="9" max="20" width="34" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '</worksheet>';
    }

    private function xlsxRow(int $rowIndex, array $cells): string {
        $xml = '<row r="' . $rowIndex . '">';
        foreach (array_values($cells) as $columnIndex => $cell) {
            $value = is_array($cell) ? ($cell['value'] ?? null) : $cell;
            $style = is_array($cell) ? (int)($cell['style'] ?? 0) : 0;
            $xml .= $this->xlsxCell($rowIndex, $columnIndex, $value, $style);
        }

        return $xml . '</row>';
    }

    private function xlsxCell(int $rowIndex, int $columnIndex, $value, int $style = 0): string {
        $reference = $this->xlsxColumnName($columnIndex) . $rowIndex;
        $styleAttribute = $style > 0 ? ' s="' . $style . '"' : '';
        if (is_int($value) || is_float($value)) {
            return '<c r="' . $reference . '"' . $styleAttribute . '><v>' . $this->excelEscape((string)$value) . '</v></c>';
        }

        $text = $this->csvValue($value);
        return '<c r="' . $reference . '" t="inlineStr"' . $styleAttribute . '><is><t>' . $this->excelEscape($text) . '</t></is></c>';
    }

    private function xlsxColumnName(int $index): string {
        $name = '';
        $index++;
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function buildXlsx(string $title, string $sheetXml): string {
        $tmp = tempnam(sys_get_temp_dir(), 'loyalty-xlsx-');
        if (!is_string($tmp)) {
            throw new \RuntimeException('No se pudo preparar el archivo Excel.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo crear el archivo Excel.');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Fidepuntos</Application></Properties>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->excelEscape($title) . '</dc:title><dc:creator>Fidepuntos</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Reporte" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->xlsxStyles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $content = file_get_contents($tmp);
        @unlink($tmp);
        if (!is_string($content)) {
            throw new \RuntimeException('No se pudo leer el archivo Excel.');
        }

        return $content;
    }

    private function xlsxStyles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><color rgb="FF0F172A"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FF0F172A"/><name val="Arial"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF4F1"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD9E7E2"/></left><right style="thin"><color rgb="FFD9E7E2"/></right><top style="thin"><color rgb="FFD9E7E2"/></top><bottom style="thin"><color rgb="FFD9E7E2"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function csvValue($value): string {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Si' : 'No';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string)$value;
    }

    private function tenantId(): string {
        return TenantContext::id() ?: (TenantContext::slug() ?: 'default');
    }

    private function id(string $prefix): string {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Construye FROM+WHERE de socios elegibles (con pase Google guardado) segun el
     * filtro de audiencia masiva. Reusado por preview y por la materializacion.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function notificationAudienceQuery(array $filter): array {
        $tenantId = $this->tenantId();
        $params = ['tenant_id' => $tenantId];

        $sql = "FROM loyalty_members m
                JOIN loyalty_wallet_passes p
                  ON p.member_id = m.id AND p.tenant_id = m.tenant_id
                 AND p.platform = 'google' AND p.external_object_id IS NOT NULL
                LEFT JOIN loyalty_point_accounts a
                  ON a.member_id = m.id AND a.tenant_id = m.tenant_id
                WHERE m.tenant_id = :tenant_id";

        $type = (string)($filter['audience_type'] ?? 'segment');

        // Estado: por defecto solo activos; 'segment' puede pedir otro estado explicito.
        $status = $type === 'segment' ? (string)($filter['status'] ?? 'active') : 'active';
        if ($status !== 'all') {
            $sql .= ' AND m.status = :status';
            $params['status'] = $status;
        }

        if ($type === 'segment') {
            $tier = (string)($filter['tier'] ?? 'all');
            if ($tier !== 'all' && $tier !== '') {
                $sql .= ' AND m.tier = :tier';
                $params['tier'] = $tier;
            }
            $query = trim((string)($filter['query'] ?? ''));
            if ($query !== '') {
                $sql .= ' AND (m.account_name ILIKE :q OR m.account_id ILIKE :q OR m.email ILIKE :q)';
                $params['q'] = '%' . $query . '%';
            }
            if (isset($filter['purchasedWithinDays']) && (int)$filter['purchasedWithinDays'] > 0) {
                $sql .= ' AND m.last_activity_at >= NOW() - make_interval(days => :purchased_days)';
                $params['purchased_days'] = (int)$filter['purchasedWithinDays'];
            }
            if (isset($filter['inactiveForDays']) && (int)$filter['inactiveForDays'] > 0) {
                $sql .= ' AND (m.last_activity_at IS NULL OR m.last_activity_at <= NOW() - make_interval(days => :inactive_days))';
                $params['inactive_days'] = (int)$filter['inactiveForDays'];
            }
            if (isset($filter['minBalance']) && $filter['minBalance'] !== '' && $filter['minBalance'] !== null) {
                $sql .= ' AND COALESCE(a.balance, 0) >= :min_balance';
                $params['min_balance'] = (int)$filter['minBalance'];
            }
            if (isset($filter['maxBalance']) && $filter['maxBalance'] !== '' && $filter['maxBalance'] !== null) {
                $sql .= ' AND COALESCE(a.balance, 0) <= :max_balance';
                $params['max_balance'] = (int)$filter['maxBalance'];
            }
        }

        return [$sql, $params];
    }

    /** @return array{recipients: int} */
    public function previewNotificationAudience(array $filter): array {
        [$fromWhere, $params] = $this->notificationAudienceQuery($filter);
        $count = (int)$this->scalar('SELECT COUNT(DISTINCT m.id) ' . $fromWhere, $params);

        return ['recipients' => $count];
    }

    /**
     * Crea una campaña de notificacion. Individual se envia inline (feedback
     * inmediato); masivo (all/segment) queda 'pending' para el worker.
     */
    public function createNotificationCampaign(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $type = (string)($payload['audience_type'] ?? '');
        if (!in_array($type, ['individual', 'all', 'segment'], true)) {
            throw new \InvalidArgumentException('Audiencia invalida.');
        }

        $body = trim((string)($payload['body'] ?? ''));
        if ($body === '') {
            throw new \InvalidArgumentException('Escribe un mensaje para la notificacion.');
        }
        $body = mb_substr($body, 0, 300);

        $title = mb_substr(trim((string)($payload['title'] ?? '')), 0, 100);
        if ($title === '') {
            $walletSettings = $this->settings()['settings']['googleWallet'] ?? [];
            $title = (string)($walletSettings['issuerName'] ?? '') ?: 'Notificacion';
        }

        // Resolver destinatarios.
        if ($type === 'individual') {
            $member = $this->memberFromPayload($payload);
            if (!$member) {
                throw new \RuntimeException('Socio no encontrado.');
            }
            $recipients = [['member_id' => (string)$member['id'], 'account_id' => (string)$member['account_id']]];
            $filter = ['audience_type' => 'individual', 'memberId' => (string)$member['id']];
        } else {
            $filter = $this->sanitizeAudienceFilter($payload, $type);
            [$fromWhere, $params] = $this->notificationAudienceQuery($filter);
            $recipients = $this->fetchAll('SELECT DISTINCT m.id AS member_id, m.account_id ' . $fromWhere . ' ORDER BY m.id ASC', $params);
            if ($recipients === []) {
                throw new \RuntimeException('El segmento no tiene socios con tarjeta guardada.');
            }
        }

        // Fail-fast: resolver el servicio del tenant ANTES de escribir filas, para
        // no dejar una campaña individual huerfana si falta la config de Wallet.
        $individualService = $type === 'individual' ? $this->googleWalletServiceOrFail() : null;

        $campaignId = 'wcmp_' . bin2hex(random_bytes(8));
        $this->execute(
            'INSERT INTO loyalty_wallet_campaigns
                (id, tenant_id, created_by_user_id, title, body, audience_type, audience_filter, status, total_recipients)
             VALUES (:id, :tenant_id, :user, :title, :body, :type, :filter, :status, :total)',
            [
                'id' => $campaignId, 'tenant_id' => $tenantId, 'user' => $userId,
                'title' => $title, 'body' => $body, 'type' => $type,
                'filter' => json_encode($filter, JSON_UNESCAPED_UNICODE),
                'status' => 'pending', 'total' => count($recipients),
            ]
        );

        foreach ($recipients as $r) {
            $this->execute(
                'INSERT INTO loyalty_wallet_campaign_recipients (id, tenant_id, campaign_id, member_id, account_id, status)
                 VALUES (:id, :tenant_id, :campaign_id, :member_id, :account_id, :status)
                 ON CONFLICT (campaign_id, member_id) DO NOTHING',
                [
                    'id' => 'wrcp_' . bin2hex(random_bytes(8)), 'tenant_id' => $tenantId,
                    'campaign_id' => $campaignId, 'member_id' => (string)$r['member_id'],
                    'account_id' => (string)$r['account_id'], 'status' => 'pending',
                ]
            );
        }

        $this->recordAudit(
            'wallet.google.campaign_created', 'campaign', $campaignId, null,
            ['audienceType' => $type, 'totalRecipients' => count($recipients)], null, $userId
        );

        // Individual: enviar inline con el servicio del tenant.
        if ($type === 'individual') {
            (new WalletNotificationProcessor($this->pdo))->drainCampaign($campaignId, $individualService);
        }

        return $this->getNotificationCampaign($campaignId);
    }

    /** @return array<string, mixed> */
    private function sanitizeAudienceFilter(array $payload, string $type): array {
        $filter = ['audience_type' => $type];
        if ($type !== 'segment') {
            return $filter;
        }
        foreach (['tier', 'status', 'query'] as $key) {
            if (isset($payload[$key])) {
                $filter[$key] = (string)$payload[$key];
            }
        }
        foreach (['purchasedWithinDays', 'inactiveForDays', 'minBalance', 'maxBalance'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '' && $payload[$key] !== null) {
                $filter[$key] = (int)$payload[$key];
            }
        }
        return $filter;
    }

    /** @return array{items: array<int, array<string, mixed>>, total: int} */
    public function listNotificationCampaigns(array $filters = []): array {
        $tenantId = $this->tenantId();
        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $rows = $this->fetchAll(
            'SELECT id, tenant_id, created_by_user_id, title, body, audience_type, audience_filter,
                    status, total_recipients, sent_count, failed_count, skipped_count,
                    created_at, started_at, finished_at
             FROM loyalty_wallet_campaigns
             WHERE tenant_id = :tenant_id
             ORDER BY created_at DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['tenant_id' => $tenantId]
        );
        $total = (int)$this->scalar('SELECT COUNT(*) FROM loyalty_wallet_campaigns WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]);

        return ['items' => array_map(fn($row) => $this->mapCampaign($row), $rows), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function getNotificationCampaign(string $campaignId): array {
        $rows = $this->fetchAll(
            'SELECT id, tenant_id, created_by_user_id, title, body, audience_type, audience_filter,
                    status, total_recipients, sent_count, failed_count, skipped_count,
                    created_at, started_at, finished_at
             FROM loyalty_wallet_campaigns
             WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
            ['tenant_id' => $this->tenantId(), 'id' => $campaignId]
        );
        if ($rows === []) {
            throw new \RuntimeException('Campaña no encontrada.');
        }
        return $this->mapCampaign($rows[0]);
    }

    /** @return array<string, mixed> */
    private function mapCampaign(array $row): array {
        $filter = $row['audience_filter'] ?? [];
        if (is_string($filter)) {
            $filter = json_decode($filter, true) ?: [];
        }
        return [
            'id' => (string)$row['id'],
            'title' => (string)$row['title'],
            'body' => (string)$row['body'],
            'audience_type' => (string)$row['audience_type'],
            'audience_filter' => is_array($filter) ? $filter : [],
            'status' => (string)$row['status'],
            'total_recipients' => (int)$row['total_recipients'],
            'sent_count' => (int)$row['sent_count'],
            'failed_count' => (int)$row['failed_count'],
            'skipped_count' => (int)$row['skipped_count'],
            'created_at' => $row['created_at'] ?? null,
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
        ];
    }
}
