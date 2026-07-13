<?php

namespace App\Modules\LoyaltyRewards\Infrastructure;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Application\PurchaseSourceVerifier;
use App\Modules\LoyaltyRewards\Domain\DecimalMath;
use App\Modules\LoyaltyRewards\Domain\ExternalApiAccessException;
use App\Modules\LoyaltyRewards\Domain\ExternalApiConflictException;
use App\Modules\LoyaltyRewards\Domain\ExternalApiNotFoundException;
use App\Modules\LoyaltyRewards\Domain\LoyaltyResourceNotFoundException;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use App\Modules\LoyaltyRewards\Domain\PurchaseVerificationException;
use App\Modules\LoyaltyRewards\Domain\ReferenceNormalizer;
use App\Modules\LoyaltyRewards\Infrastructure\Wallet\GoogleWalletFactory;
use App\Modules\LoyaltyRewards\Infrastructure\Wallet\GoogleWalletService;
use App\Services\MailService;
use PDO;

final class LoyaltyRepository {
    private const EXTERNAL_API_SCOPES = [
        'program:read',
        'members:read',
        'members:write',
        'purchases:write',
        'purchases:reverse',
        'redemptions:write',
        'rewards:read',
        'reports:read',
        'wallet:link',
    ];
    private const EXTERNAL_API_SOURCES = ['pos', 'ecommerce', 'billing', 'external'];
    private const EXTERNAL_API_RATE_LIMIT_MAX = 600;
    private const MINIMUM_POINTS_PER_PURCHASE = 1;
    private const POSTGRES_INTEGER_MAX = 2147483647;
    private const CLAIM_MODE_STAFF_ONLY = 'staff_only';
    private const CLAIM_MODE_IN_STORE = 'in_store';
    private const CLAIM_MODE_MANAGED = 'managed';
    private const CUSTOMER_PORTAL_SOURCE = 'customer_portal';
    private const CLAIM_STATUS_PENDING_REVIEW = 'pending_review';
    private const CLAIM_STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    private const CLAIM_STATUS_APPROVED = 'approved';
    private const CLAIM_STATUS_DELIVERED = 'delivered';
    private const CLAIM_STATUS_CANCELLED = 'cancelled';
    private const CLAIM_STATUS_EXPIRED = 'expired';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
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
                    m.wallet_platform, COALESCE(a.balance, 0) AS points,
                    COALESCE(a.points_debt, 0) AS points_debt,
                    m.last_activity_at, m.created_at
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
            throw new LoyaltyResourceNotFoundException('Socio no encontrado.');
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
            "SELECT id, name, description, points_cost, stock, status, claim_mode, claim_instructions,
                    claim_delivery_options, image_url, metadata, created_at, updated_at,
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
        $hasPointsCost = array_key_exists('pointsCost', $payload) || array_key_exists('points_cost', $payload);
        $hasStock = array_key_exists('stock', $payload);
        if (!$hasPointsCost || !$hasStock) {
            throw new \InvalidArgumentException('Nombre, costo en puntos y stock son obligatorios.');
        }
        $pointsCostValue = array_key_exists('pointsCost', $payload)
            ? $payload['pointsCost']
            : $payload['points_cost'];
        $pointsCost = $this->strictNonNegativeInteger(
            $pointsCostValue,
            'costo en puntos del premio'
        );
        $stock = $this->strictNonNegativeInteger($payload['stock'], 'stock del premio');
        if ($name === '' || $pointsCost < 1) {
            throw new \InvalidArgumentException('Nombre y costo en puntos son obligatorios.');
        }
        $claim = $this->normalizeRewardClaimPayload($payload);

        $reward = [
            'id' => $this->id('reward'),
            'tenant_id' => $tenantId,
            'name' => $name,
            'description' => trim((string)($payload['description'] ?? '')),
            'points_cost' => $pointsCost,
            'stock' => $stock,
            'status' => trim((string)($payload['status'] ?? 'active')) ?: 'active',
            'claim_mode' => $claim['claim_mode'],
            'claim_instructions' => $claim['claim_instructions'],
            'claim_delivery_options' => json_encode($claim['claim_delivery_options'], JSON_UNESCAPED_UNICODE),
            'image_url' => $this->normalizeRewardImageUrl($payload['imageUrl'] ?? $payload['image_url'] ?? ''),
            'metadata' => json_encode($payload['metadata'] ?? new \stdClass()),
        ];
        if (!in_array($reward['status'], ['active', 'inactive'], true)) {
            throw new \InvalidArgumentException('El estado del premio debe ser activo o inactivo.');
        }

        return $this->atomically(function () use ($reward, $payload, $userId): array {
            $stmt = $this->pdo->prepare(
                'INSERT INTO loyalty_rewards
                    (id, tenant_id, name, description, points_cost, stock, status, claim_mode, claim_instructions, claim_delivery_options, image_url, metadata)
                 VALUES
                    (:id, :tenant_id, :name, :description, :points_cost, :stock, :status, :claim_mode, :claim_instructions, :claim_delivery_options, :image_url, :metadata)'
            );
            $stmt->execute($reward);
            $created = $this->rewardById($reward['id']);
            $this->recordAudit('reward.created', 'reward', $reward['id'], null, $created, trim((string)($payload['reason'] ?? '')), $userId);

            return $created;
        });
    }

    public function rewardDetail(string $rewardId): array {
        $reward = $this->rewardById($rewardId);
        if (!$reward) {
            throw new LoyaltyResourceNotFoundException('Premio no encontrado.');
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
        return $this->atomically(function () use ($rewardId, $payload, $userId): array {
            $before = $this->rewardForUpdate($rewardId);

            $name = trim((string)($payload['name'] ?? $before['name'] ?? ''));
            $hasPointsCostUpdate = array_key_exists('pointsCost', $payload) || array_key_exists('points_cost', $payload);
            $pointsCostValue = array_key_exists('pointsCost', $payload)
                ? $payload['pointsCost']
                : ($payload['points_cost'] ?? null);
            $pointsCost = $hasPointsCostUpdate
                ? $this->strictNonNegativeInteger(
                    $pointsCostValue,
                    'costo en puntos del premio'
                )
                : (int)($before['points_cost'] ?? 0);
            $hasStockUpdate = array_key_exists('stock', $payload);
            $stock = $hasStockUpdate
                ? $this->strictNonNegativeInteger($payload['stock'], 'stock del premio')
                : (int)($before['stock'] ?? 0);
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
            $claim = $this->normalizeRewardClaimPayload($payload, $before);

            $this->execute(
                'UPDATE loyalty_rewards
                 SET name = :name,
                     description = :description,
                     points_cost = :points_cost,
                     stock = CASE WHEN :has_stock_update = 1 THEN :stock ELSE stock END,
                     status = :status,
                     claim_mode = :claim_mode,
                     claim_instructions = :claim_instructions,
                     claim_delivery_options = :claim_delivery_options,
                     image_url = :image_url,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id',
                [
                    'name' => $name,
                    'description' => trim((string)($payload['description'] ?? $before['description'] ?? '')),
                    'points_cost' => $pointsCost,
                    'has_stock_update' => $hasStockUpdate ? 1 : 0,
                    'stock' => $stock,
                    'status' => $status,
                    'claim_mode' => $claim['claim_mode'],
                    'claim_instructions' => $claim['claim_instructions'],
                    'claim_delivery_options' => json_encode($claim['claim_delivery_options'], JSON_UNESCAPED_UNICODE),
                    'image_url' => $this->normalizeRewardImageUrl($payload['imageUrl'] ?? $payload['image_url'] ?? $before['image_url'] ?? ''),
                    'tenant_id' => $this->tenantId(),
                    'id' => $rewardId,
                ]
            );
            $after = $this->rewardById($rewardId);
            $this->recordAudit('reward.updated', 'reward', $rewardId, $before, $after, trim((string)($payload['reason'] ?? '')), $userId);

            return $after;
        });
    }

    public function deleteReward(string $rewardId, ?string $userId = null): array {
        return $this->atomically(function () use ($rewardId, $userId): array {
            $tenantId = $this->tenantId();
            $before = $this->rewardForUpdate($rewardId);
            $redemptionCount = (int)$this->scalar(
                'SELECT COUNT(*) FROM loyalty_redemptions WHERE tenant_id = :tenant_id AND reward_id = :reward_id',
                ['tenant_id' => $tenantId, 'reward_id' => $rewardId]
            );
            if ($redemptionCount > 0) {
                return $this->archiveRewardWithinTransaction($tenantId, $rewardId, $before, $userId);
            }

            $delete = $this->pdo->prepare(
                'DELETE FROM loyalty_rewards w
                 WHERE w.tenant_id = :tenant_id
                   AND w.id = :id
                   AND NOT EXISTS (
                       SELECT 1 FROM loyalty_redemptions r
                       WHERE r.tenant_id = w.tenant_id AND r.reward_id = w.id
                   )'
            );
            $delete->execute(['tenant_id' => $tenantId, 'id' => $rewardId]);
            if ($delete->rowCount() !== 1) {
                return $this->archiveRewardWithinTransaction($tenantId, $rewardId, $before, $userId);
            }

            $this->recordAudit('reward.deleted', 'reward', $rewardId, $before, ['deleted' => true], 'Premio sin historial eliminado.', $userId);

            return ['deleted' => true, 'archived' => false, 'reward' => $before];
        });
    }

    private function archiveRewardWithinTransaction(string $tenantId, string $rewardId, array $before, ?string $userId): array {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('La baja logica del premio requiere una transaccion activa.');
        }
        $this->execute(
            'UPDATE loyalty_rewards SET status = :status, updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
            ['status' => 'deleted', 'tenant_id' => $tenantId, 'id' => $rewardId]
        );
        $after = $this->rewardById($rewardId);
        $this->recordAudit('reward.deleted', 'reward', $rewardId, $before, $after, 'Baja logica por historial de canjes.', $userId);

        return ['deleted' => false, 'archived' => true, 'reward' => $after];
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
            throw new LoyaltyResourceNotFoundException('Socio no encontrado.');
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
        return $this->atomically(function () use ($payload, $userId): array {
            $tenantId = $this->tenantId();
            $program = $this->program($tenantId);
            $this->transactionAdvisoryLock('program-configuration', (string)$program['id']);

            return $this->persistSettingsWithinTransaction($payload, $tenantId, $program, $userId);
        });
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
        return $this->atomically(function () use ($payload, $userId): array {
            $tenantId = $this->tenantId();
            $program = $this->program($tenantId);
            $this->transactionAdvisoryLock('program-configuration', (string)$program['id']);
            $before = $this->rules();

            if (isset($payload['settings']) || isset($payload['earning']) || isset($payload['redemption']) || isset($payload['expiration'])) {
                $settingsPayload = isset($payload['settings']) && is_array($payload['settings'])
                    ? ['settings' => $payload['settings'], 'reason' => $payload['reason'] ?? '']
                    : array_intersect_key($payload, array_flip(['program', 'earning', 'redemption', 'expiration', 'security', 'communication', 'googleWallet', 'reason']));
                $this->persistSettingsWithinTransaction($settingsPayload, $tenantId, $program, $userId);
            }

            if (isset($payload['tiers']) && is_array($payload['tiers'])) {
                $tiers = $this->normalizeTierRules($payload['tiers']);
                $this->execute('DELETE FROM loyalty_tier_rules WHERE tenant_id = :tenant_id', ['tenant_id' => $tenantId]);
                foreach ($tiers as $index => $tier) {
                    $this->execute(
                        'INSERT INTO loyalty_tier_rules
                            (id, tenant_id, program_id, name, min_lifetime_points, max_lifetime_points, multiplier, benefits, status, sort_order)
                         VALUES
                            (:id, :tenant_id, :program_id, :name, :min_lifetime_points, :max_lifetime_points, :multiplier, :benefits, :status, :sort_order)',
                        [
                            'id' => $this->id('tier'),
                            'tenant_id' => $tenantId,
                            'program_id' => $program['id'],
                            'name' => $tier['name'],
                            'min_lifetime_points' => $tier['minLifetimePoints'],
                            'max_lifetime_points' => $tier['maxLifetimePoints'],
                            'multiplier' => $tier['multiplier'],
                            'benefits' => json_encode($tier['benefits'], JSON_UNESCAPED_UNICODE),
                            'status' => $tier['status'],
                            'sort_order' => $index + 1,
                        ]
                    );
                }
                $this->refreshAllMemberTiers($tenantId);
            }

            $after = $this->rules();
            $this->recordAudit('rules.updated', 'program', (string)$program['id'], $before, $after, trim((string)($payload['reason'] ?? '')), $userId);

            return $after;
        });
    }

    private function persistSettingsWithinTransaction(
        array $payload,
        string $tenantId,
        array $program,
        ?string $userId
    ): array {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('La configuracion de Loyalty requiere una transaccion activa.');
        }

        $before = $this->settings()['settings'];
        $settings = $this->mergeSettings($before, $payload['settings'] ?? $payload);
        $settings = $this->normalizeSettingsIntegers($settings);
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
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
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
                'brand_color' => trim((string)($programSettings['brandColor'] ?? $program['brand_color'] ?? '#2b648f')),
                'logo_url' => trim((string)($programSettings['logoUrl'] ?? $program['logo_url'] ?? '')),
                'tenant_id' => $tenantId,
                'id' => $program['id'],
            ]
        );
        $this->recordAudit(
            'settings.updated',
            'program',
            (string)$program['id'],
            $before,
            $settings,
            trim((string)($payload['reason'] ?? '')),
            $userId
        );

        return $this->settings();
    }

    private function atomically(callable $operation): mixed {
        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = 'loyalty_atomic_' . bin2hex(random_bytes(6));
            $this->pdo->exec("SAVEPOINT {$savepoint}");
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            } elseif ($savepoint !== null) {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif ($savepoint !== null && $this->pdo->inTransaction()) {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            throw $exception;
        }
    }

    private function normalizeTierRules(array $tiers): array {
        if ($tiers === []) {
            throw new \InvalidArgumentException('Debe existir al menos un nivel.');
        }

        $normalized = [];
        $names = [];
        foreach ($tiers as $index => $tier) {
            if (!is_array($tier)) {
                throw new \InvalidArgumentException('Cada nivel debe ser un objeto valido.');
            }
            $name = trim((string)($tier['name'] ?? ''));
            if ($name === '') {
                throw new \InvalidArgumentException('Cada nivel debe tener nombre.');
            }
            $nameKey = mb_strtolower($name);
            if (isset($names[$nameKey])) {
                throw new \InvalidArgumentException(sprintf('El nivel "%s" esta duplicado.', $name));
            }
            $names[$nameKey] = true;

            $min = $this->strictNonNegativeInteger(
                $tier['minLifetimePoints'] ?? $tier['min_lifetime_points'] ?? 0,
                sprintf('minimo del nivel "%s"', $name)
            );
            $maxValue = $tier['maxLifetimePoints'] ?? $tier['max_lifetime_points'] ?? null;
            $max = $maxValue === null || $maxValue === ''
                ? null
                : $this->strictNonNegativeInteger($maxValue, sprintf('maximo del nivel "%s"', $name));
            $multiplier = DecimalMath::factor($tier['multiplier'] ?? '1', 'multiplicador de nivel');
            if ($max !== null && $max < $min) {
                throw new \InvalidArgumentException(sprintf('El maximo del nivel "%s" debe ser mayor o igual al minimo.', $name));
            }
            $benefits = is_array($tier['benefits'] ?? null) ? array_values(array_filter(array_map(
                static fn($benefit): string => trim((string)$benefit),
                $tier['benefits']
            ))) : [];
            $status = strtolower(trim((string)($tier['status'] ?? 'active')));
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            $normalized[] = [
                'sourceIndex' => $index,
                'name' => $name,
                'minLifetimePoints' => $min,
                'maxLifetimePoints' => $max,
                'multiplier' => $multiplier,
                'benefits' => $benefits,
                'status' => $status,
            ];
        }

        usort($normalized, static fn(array $a, array $b): int => ($a['minLifetimePoints'] <=> $b['minLifetimePoints']) ?: ($a['sourceIndex'] <=> $b['sourceIndex']));

        $expectedMin = 0;
        foreach ($normalized as $index => $tier) {
            if ($tier['minLifetimePoints'] !== $expectedMin) {
                throw new \InvalidArgumentException(sprintf(
                    'Los niveles deben ser continuos: "%s" debe iniciar en %d puntos.',
                    $tier['name'],
                    $expectedMin
                ));
            }
            if ($tier['maxLifetimePoints'] === null) {
                if ($index !== count($normalized) - 1) {
                    throw new \InvalidArgumentException('Solo el ultimo nivel puede quedar sin limite superior.');
                }
                continue;
            }
            if ($tier['maxLifetimePoints'] === self::POSTGRES_INTEGER_MAX) {
                throw new \InvalidArgumentException('Un nivel intermedio no puede terminar en el maximo entero de PostgreSQL.');
            }
            $expectedMin = $tier['maxLifetimePoints'] + 1;
        }

        if ($normalized[count($normalized) - 1]['maxLifetimePoints'] !== null) {
            throw new \InvalidArgumentException('El ultimo nivel debe quedar sin limite superior para cubrir socios con mas puntos.');
        }

        return array_map(static function (array $tier): array {
            unset($tier['sourceIndex']);
            return $tier;
        }, $normalized);
    }

    public function adjustPoints(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $points = $this->strictPoints($payload['points'] ?? null, 'puntos del ajuste');
        $reason = trim((string)($payload['reason'] ?? ''));
        $evidence = trim((string)($payload['evidence'] ?? ''));
        $adjustmentType = strtolower(trim((string)($payload['adjustmentType'] ?? $payload['adjustment_type'] ?? '')));
        $allowedTypes = ['correction', 'service_recovery', 'fraud_correction', 'migration'];
        if ($points === 0 || $reason === '' || $evidence === '' || !in_array($adjustmentType, $allowedTypes, true)) {
            throw new \InvalidArgumentException('El ajuste requiere tipo valido, puntos, motivo y evidencia.');
        }
        if (mb_strlen($reason) > 500 || mb_strlen($evidence) > 1000) {
            throw new \InvalidArgumentException('El motivo o la evidencia exceden el limite permitido.');
        }
        $commandId = $this->commandId($payload, '', true);
        $journalCommandId = $this->journalCommandId($commandId, $userId);
        $earlyReplay = $this->replayCommandIfCompleted('points.adjust', $journalCommandId, $payload);
        if ($earlyReplay !== null) {
            return $earlyReplay;
        }
        $member = $this->memberFromPayload($payload);
        if (!$member) {
            throw new \InvalidArgumentException('Selecciona un socio existente.');
        }
        $this->assertMemberCanOperate($member, 'recibir ajustes');

        $program = $this->program($tenantId);
        $settings = $this->settings()['settings'];
        $perOperationLimit = max(1, (int)($settings['earning']['maximumPointsPerPurchase'] ?? 20000));
        if (abs($points) > $perOperationLimit) {
            throw new \InvalidArgumentException('El ajuste supera el limite maximo permitido por operacion.');
        }
        $dailyLimit = $points > 0
            ? max(0, (int)($settings['earning']['maximumPointsPerMemberPerDay'] ?? 50000))
            : 0;

        $this->pdo->beginTransaction();
        try {
            $replay = $this->reserveCommand('points.adjust', $journalCommandId, $payload, $userId, 'dashboard');
            if ($replay !== null) {
                $this->pdo->commit();
                return $replay;
            }
            $account = $this->accountForMemberForUpdate($tenantId, (string)$member['id']);
            if ($dailyLimit > 0) {
                $timezone = $this->tenantTimezoneFromSettings($settings);
                $today = (int)$this->scalar(
                    "SELECT COALESCE(SUM(
                        CASE
                            WHEN metadata->>'requestedPoints' ~ '^[0-9]+$' THEN (metadata->>'requestedPoints')::integer
                            ELSE GREATEST(points, 0)
                        END
                     ), 0)
                     FROM loyalty_point_ledger
                     WHERE tenant_id = :tenant_id
                       AND member_id = :member_id
                       AND entry_type = 'adjustment'
                       AND points >= 0
                       AND created_at >= date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE :timezone)
                       AND created_at < date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE :timezone) + INTERVAL '1 day'",
                    [
                        'tenant_id' => $tenantId,
                        'member_id' => (string)$member['id'],
                        'timezone' => $timezone,
                    ]
                );
                if ($today + $points > $dailyLimit) {
                    throw new PurchaseVerificationException(
                        'El ajuste supera el limite diario de puntos.',
                        'daily_adjustment_limit',
                        ['today' => $today, 'points' => $points, 'limit' => $dailyLimit],
                        409
                    );
                }
            }
            $debtBefore = (int)($account['points_debt'] ?? 0);
            $debtPayment = $points > 0 ? min($debtBefore, $points) : 0;
            $availableDelta = $points - $debtPayment;
            $balanceAfter = (int)$account['balance'] + $availableDelta;
            $debtAfter = $debtBefore - $debtPayment;
            if ($balanceAfter < 0) {
                throw new PurchaseVerificationException(
                    'El ajuste dejaria al socio con saldo negativo.',
                    'negative_balance_attempt',
                    ['points' => $points, 'balance' => (int)$account['balance']],
                    409
                );
            }
            $this->execute(
                'UPDATE loyalty_point_accounts
                 SET balance = :balance,
                     points_debt = :points_debt,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id',
                [
                    'balance' => $balanceAfter,
                    'points_debt' => $debtAfter,
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
                    'points' => $availableDelta,
                    'balance_after' => $balanceAfter,
                    'reference' => $commandId,
                    'source' => 'dashboard',
                    'source_reference' => trim((string)($payload['reference'] ?? '')),
                    'metadata' => json_encode([
                        'adjustmentType' => $adjustmentType,
                        'reason' => $reason,
                        'evidence' => $evidence,
                        'requestedPoints' => $points,
                        'availablePoints' => $availableDelta,
                        'debtPayment' => $debtPayment,
                        'affectsLifetime' => false,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_by_user_id' => $userId,
                ]
            );
            if ($debtPayment > 0) {
                $this->insertDebtLedger(
                    $tenantId,
                    (string)$member['id'],
                    (string)$program['id'],
                    'debt_payment',
                    -$debtPayment,
                    $debtAfter,
                    $commandId,
                    'dashboard',
                    ['operation' => 'adjustment'],
                    $userId
                );
            }
            $response = [
                'member' => $this->memberById((string)$member['id']),
                'pointsAdjusted' => $points,
                'pointsAvailable' => $availableDelta,
                'debtPaid' => $debtPayment,
                'debtAfter' => $debtAfter,
                'balanceAfter' => $balanceAfter,
                'commandId' => $commandId,
            ];
            $this->recordAudit('points.adjusted', 'member', (string)$member['id'], null, $response, $reason, $userId);
            $this->completeCommand('points.adjust', $journalCommandId, $response);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->persistOperationRisk($e, (string)$member['id'], $commandId);
            throw $e;
        }

        $this->syncGoogleWalletBestEffort($member, $balanceAfter);

        return $response;
    }

    public function registerPurchase(array $payload, ?string $userId = null, ?array $trustedSourceContext = null): array {
        $tenantId = $this->tenantId();
        $amount = DecimalMath::money($payload['invoiceAmount'] ?? $payload['amount'] ?? null, 'monto de factura');
        $amountMinor = DecimalMath::moneyToMinorUnits($amount, 'monto de factura');
        $invoiceNumber = ReferenceNormalizer::normalize($payload['invoiceNumber'] ?? $payload['invoice_number'] ?? '');
        $commandId = $this->commandId($payload, 'purchase:' . hash('sha256', $invoiceNumber));
        $journalCommandId = $this->journalCommandId($commandId, $userId);
        $earlyReplay = $this->replayCommandIfCompleted('purchase.register', $journalCommandId, $payload);
        if ($earlyReplay !== null) {
            return $earlyReplay;
        }

        $member = $this->memberFromPayload($payload);
        if (!$member) {
            throw new \InvalidArgumentException('Selecciona un socio existente antes de registrar la compra.');
        }
        $this->assertMemberCanOperate($member, 'acumular puntos');
        $program = $this->program($tenantId);
        $programCurrency = strtoupper(trim((string)($program['currency_code'] ?? 'USD')));
        $currency = strtoupper(trim((string)($payload['currency'] ?? $payload['currencyCode'] ?? $programCurrency)));
        if ($currency === '' || !hash_equals($programCurrency, $currency)) {
            throw new PurchaseVerificationException(
                'La moneda de la compra no coincide con la moneda del programa.',
                'purchase_currency_mismatch',
                ['expectedCurrency' => $programCurrency, 'receivedCurrency' => $currency],
                409
            );
        }
        try {
            $sourceVerification = (new PurchaseSourceVerifier())->verify(
                $tenantId,
                $member,
                $amount,
                $currency,
                $invoiceNumber,
                $payload,
                $trustedSourceContext
            );
        } catch (PurchaseVerificationException $exception) {
            $riskMetadata = $exception->riskMetadata();
            $requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
            $apiClientId = trim((string)($trustedSourceContext['clientId'] ?? ''));
            if ($requestId !== '') {
                $riskMetadata['requestId'] = mb_substr($requestId, 0, 160);
            }
            if ($apiClientId !== '') {
                $riskMetadata['apiClientId'] = mb_substr($apiClientId, 0, 160);
                $riskMetadata['actorId'] = 'api:' . mb_substr($apiClientId, 0, 156);
            }
            $clientIp = $this->trustedClientIp();
            if ($clientIp !== null) {
                $riskMetadata['clientIp'] = $clientIp;
            }
            $this->recordRisk(
                'critical',
                $exception->riskType(),
                $exception->getMessage(),
                (string)$member['id'],
                $invoiceNumber,
                $riskMetadata
            );
            throw $exception;
        }
        $points = 0;
        $ledgerId = $this->id('ledger');

        $this->pdo->beginTransaction();
        try {
            $replay = $this->reserveCommand('purchase.register', $journalCommandId, $payload, $userId, (string)$sourceVerification['type']);
            if ($replay !== null) {
                $this->pdo->commit();
                return $replay;
            }
            $this->transactionAdvisoryLock('program-configuration', (string)$program['id']);
            $program = $this->program($tenantId);
            $currentProgramCurrency = strtoupper(trim((string)($program['currency_code'] ?? 'USD')));
            if (!hash_equals($currentProgramCurrency, $currency)) {
                throw new PurchaseVerificationException(
                    'La moneda del programa cambio mientras se verificaba la compra; vuelve a intentarlo.',
                    'purchase_currency_changed',
                    ['expectedCurrency' => $currentProgramCurrency, 'receivedCurrency' => $currency],
                    409
                );
            }
            $settings = $this->settings()['settings'];
            $this->transactionAdvisoryLock('purchase-member', (string)$member['id']);
            $this->transactionAdvisoryLock('purchase-reference', $invoiceNumber);
            $this->assertUniqueReference($tenantId, $invoiceNumber, 'purchase');
            $account = $this->accountForMemberForUpdate($tenantId, $member['id']);
            $member = $this->memberById((string)$member['id']);
            if (!$member) {
                throw new LoyaltyResourceNotFoundException('Socio no encontrado para registrar la compra.');
            }
            $this->assertMemberCanOperate($member, 'acumular puntos');
            $formula = $this->purchaseFormulaSummary($settings, $member);
            $points = $this->calculatePurchasePoints($amount, $member, $formula);
            $rulesVersion = $this->earningRuleVersion($tenantId, (string)$program['id'], $formula, $userId);
            $debtBefore = (int)($account['points_debt'] ?? 0);
            $debtPayment = min($debtBefore, $points);
            $availablePoints = $points - $debtPayment;
            $balanceAfterCredit = (int)$account['balance'] + $points;
            $balanceAfter = $balanceAfterCredit - $debtPayment;
            $debtAfter = $debtBefore - $debtPayment;
            $this->execute(
                'UPDATE loyalty_point_accounts
                 SET balance = :balance,
                     points_debt = :points_debt,
                     lifetime_points = lifetime_points + :points,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id',
                [
                    'balance' => $balanceAfter,
                    'points_debt' => $debtAfter,
                    'points' => $points,
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after,
                     reference, normalized_reference, source, source_reference, amount_minor,
                     currency_code, rules_version, formula_snapshot, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after,
                     :reference, :normalized_reference, :source, :source_reference, :amount_minor,
                     :currency_code, :rules_version, :formula_snapshot, :metadata, :created_by_user_id)',
                [
                    'id' => $ledgerId,
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                    'program_id' => $program['id'],
                    'entry_type' => 'purchase',
                    'points' => $points,
                    'balance_after' => $balanceAfterCredit,
                    'reference' => $invoiceNumber,
                    'normalized_reference' => $invoiceNumber,
                    'source' => (string)$sourceVerification['type'],
                    'source_reference' => (string)($sourceVerification['sourceReference'] ?? $invoiceNumber),
                    'amount_minor' => $amountMinor,
                    'currency_code' => $currency,
                    'rules_version' => $rulesVersion,
                    'formula_snapshot' => json_encode($formula, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'metadata' => json_encode([
                        'invoiceAmount' => $amount,
                        'invoiceAmountMinor' => $amountMinor,
                        'currency' => $currency,
                        'invoiceNumber' => $invoiceNumber,
                        'rulesVersion' => $rulesVersion,
                        'formula' => $formula,
                        'sourceVerification' => $sourceVerification,
                        'debtPayment' => $debtPayment,
                        'availablePoints' => $availablePoints,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_by_user_id' => $userId,
                ]
            );
            if ($debtPayment > 0) {
                $this->execute(
                    'INSERT INTO loyalty_point_ledger
                        (id, tenant_id, member_id, program_id, entry_type, points, balance_after,
                         reference, source, source_reference, metadata, created_by_user_id)
                     VALUES
                        (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after,
                         :reference, :source, :source_reference, :metadata, :created_by_user_id)',
                    [
                        'id' => $this->id('ledger'),
                        'tenant_id' => $tenantId,
                        'member_id' => $member['id'],
                        'program_id' => $program['id'],
                        'entry_type' => 'debt_offset',
                        'points' => -$debtPayment,
                        'balance_after' => $balanceAfter,
                        'reference' => 'DEBT-' . $invoiceNumber,
                        'source' => (string)$sourceVerification['type'],
                        'source_reference' => $invoiceNumber,
                        'metadata' => json_encode(['purchaseLedgerId' => $ledgerId, 'debtBefore' => $debtBefore, 'debtAfter' => $debtAfter]),
                        'created_by_user_id' => $userId,
                    ]
                );
                $this->insertDebtLedger(
                    $tenantId,
                    (string)$member['id'],
                    (string)$program['id'],
                    'debt_payment',
                    -$debtPayment,
                    $debtAfter,
                    $invoiceNumber,
                    (string)$sourceVerification['type'],
                    ['purchaseLedgerId' => $ledgerId],
                    $userId
                );
            }
            $this->refreshMemberTier($tenantId, $member['id']);
            $this->execute(
                'UPDATE loyalty_members SET last_activity_at = NOW(), updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $member['id']]
            );
            $this->recordAudit('purchase.registered', 'member', $member['id'], null, [
                'invoiceNumber' => $invoiceNumber,
                'invoiceAmount' => $amount,
                'points' => $points,
                'availablePoints' => $availablePoints,
                'debtPaid' => $debtPayment,
                'rulesVersion' => $rulesVersion,
                'source' => (string)$sourceVerification['type'],
                'apiClientId' => $sourceVerification['clientId']
                    ?? (str_starts_with((string)$userId, 'api:') ? substr((string)$userId, 4) : null),
            ], null, $userId);
            $response = [
                'member' => $this->memberById($member['id']),
                'pointsEarned' => $points,
                'pointsAvailable' => $availablePoints,
                'debtPaid' => $debtPayment,
                'debtAfter' => $debtAfter,
                'balanceAfter' => $balanceAfter,
                'invoiceNumber' => $invoiceNumber,
                'invoiceAmount' => $amount,
                'invoiceAmountMinor' => $amountMinor,
                'currency' => $currency,
                'rulesVersion' => $rulesVersion,
                'ledgerId' => $ledgerId,
                'commandId' => $commandId,
                'sourceVerification' => $sourceVerification,
            ];
            $this->completeCommand('purchase.register', $journalCommandId, $response);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (
                $e instanceof \PDOException
                && (string)$e->getCode() === '23505'
                && str_contains($e->getMessage(), 'loyalty_ledger_active_purchase_')
            ) {
                $e = new PurchaseVerificationException(
                    'Esta factura ya fue registrada en el programa.',
                    'duplicate_reference',
                    ['invoiceNumber' => $invoiceNumber],
                    409
                );
            }
            $this->persistOperationRisk($e, (string)$member['id'], $invoiceNumber, $trustedSourceContext);
            throw $e;
        }

        $this->syncGoogleWalletBestEffort($member, $balanceAfter);

        return $response;
    }

    public function reversePurchase(
        string $reference,
        array $payload,
        ?string $userId = null,
        ?array $externalSourceContext = null
    ): array {
        $tenantId = $this->tenantId();
        $reference = ReferenceNormalizer::normalize($reference);
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException('La reversa requiere referencia y motivo.');
        }
        $commandId = $this->commandId($payload, 'reversal:' . hash('sha256', $reference));
        $journalCommandId = $this->journalCommandId($commandId, $userId);
        $commandPayload = $payload;
        $commandPayload['_normalizedReference'] = $reference;
        $reversalSourceContext = $this->normalizeExternalReversalContext($externalSourceContext, $userId);
        $operationSource = $reversalSourceContext['source'] ?? 'dashboard';

        $member = [];
        $points = 0;
        $this->pdo->beginTransaction();
        try {
            $replay = $this->reserveCommand('purchase.reverse', $journalCommandId, $commandPayload, $userId, $operationSource);
            if ($replay !== null) {
                $this->pdo->commit();
                return $replay;
            }
            $this->transactionAdvisoryLock('purchase-reference', $reference);
            $lockedRows = $this->fetchAll(
                "SELECT *
                 FROM loyalty_point_ledger
                 WHERE tenant_id = :tenant_id
                   AND entry_type = 'purchase'
                   AND normalized_reference = :reference
                   AND reversed_at IS NULL
                 ORDER BY created_at DESC
                 LIMIT 1
                 FOR UPDATE",
                ['tenant_id' => $tenantId, 'reference' => $reference]
            );
            if ($lockedRows === []) {
                throw new LoyaltyResourceNotFoundException('Compra no encontrada o ya reversada.');
            }
            $ledger = $lockedRows[0];
            if ($reversalSourceContext !== null) {
                $this->assertExternalPurchaseReversalAllowed($tenantId, $ledger, $reversalSourceContext);
            }
            $points = (int)$ledger['points'];
            $memberId = (string)$ledger['member_id'];
            $this->transactionAdvisoryLock('redemption-member', $memberId);
            $account = $this->accountForMemberForUpdate($tenantId, $memberId);
            $member = $this->memberById($memberId);
            if (!$member) {
                throw new LoyaltyResourceNotFoundException('Socio no encontrado para reversar la compra.');
            }
            $reservationRelease = $this->cancelOpenReservationsForPurchaseReversal(
                $tenantId,
                $memberId,
                $points,
                (int)$account['balance'],
                $reference,
                $userId,
                (string)$ledger['program_id']
            );
            $balanceBeforeReversal = $reservationRelease['balance'];
            $pointsToReverse = min($points, $balanceBeforeReversal);
            $debtCreated = $points - $pointsToReverse;
            $debtBefore = (int)($account['points_debt'] ?? 0);
            $debtAfter = $debtBefore + $debtCreated;
            $balanceAfter = $balanceBeforeReversal - $pointsToReverse;
            $this->execute(
                'UPDATE loyalty_point_accounts
                 SET balance = :balance,
                     points_debt = :points_debt,
                     lifetime_points = GREATEST(lifetime_points - :points, 0),
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id',
                [
                    'balance' => $balanceAfter,
                    'points_debt' => $debtAfter,
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
                    'source' => $operationSource,
                    'source_reference' => $reference,
                    'metadata' => json_encode([
                        'reason' => $reason,
                        'originalPoints' => $points,
                        'pointsReversed' => $pointsToReverse,
                        'debtCreated' => $debtCreated,
                        'cancelledReservations' => $reservationRelease['cancelled'],
                        'reversalSource' => $operationSource,
                        'apiClientId' => $reversalSourceContext['clientId'] ?? null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_by_user_id' => $userId,
                ]
            );
            if ($debtCreated > 0) {
                $this->insertDebtLedger(
                    $tenantId,
                    $memberId,
                    (string)$ledger['program_id'],
                    'purchase_reversal_debt',
                    $debtCreated,
                    $debtAfter,
                    $reference,
                    $operationSource,
                    [
                        'purchaseLedgerId' => (string)$ledger['id'],
                        'reason' => $reason,
                        'apiClientId' => $reversalSourceContext['clientId'] ?? null,
                    ],
                    $userId
                );
            }
            $this->execute(
                'INSERT INTO loyalty_reversals
                    (id, tenant_id, member_id, original_reference, ledger_id, points_reversed, debt_created, reason, created_by_user_id, metadata)
                 VALUES
                    (:id, :tenant_id, :member_id, :original_reference, :ledger_id, :points_reversed, :debt_created, :reason, :created_by_user_id, :metadata)',
                [
                    'id' => $this->id('reversal'),
                    'tenant_id' => $tenantId,
                    'member_id' => $member['id'],
                    'original_reference' => $reference,
                    'ledger_id' => $ledger['id'],
                    'points_reversed' => $pointsToReverse,
                    'debt_created' => $debtCreated,
                    'reason' => $reason,
                    'created_by_user_id' => $userId,
                    'metadata' => json_encode([
                        'requestedBy' => $payload['requestedBy'] ?? null,
                        'originalPoints' => $points,
                        'debtBefore' => $debtBefore,
                        'debtAfter' => $debtAfter,
                        'cancelledReservations' => $reservationRelease['cancelled'],
                        'reversalSource' => $operationSource,
                        'apiClientId' => $reversalSourceContext['clientId'] ?? null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
            $this->refreshMemberTier($tenantId, (string)$member['id']);
            $response = [
                'member' => $this->memberById((string)$member['id']),
                'originalReference' => $reference,
                'originalPoints' => $points,
                'pointsReversed' => $pointsToReverse,
                'debtCreated' => $debtCreated,
                'debtAfter' => $debtAfter,
                'balanceAfter' => $balanceAfter,
                'cancelledReservations' => $reservationRelease['cancelled'],
                'commandId' => $commandId,
                'source' => $operationSource,
                'apiClientId' => $reversalSourceContext['clientId'] ?? null,
            ];
            $this->recordAudit('purchase.reversed', 'member', (string)$member['id'], $ledger, $response, $reason, $userId);
            $this->completeCommand('purchase.reverse', $journalCommandId, $response);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->persistOperationRisk(
                $e,
                isset($member['id']) ? (string)$member['id'] : null,
                $reference,
                $reversalSourceContext
            );
            throw $e;
        }

        $this->syncGoogleWalletBestEffort($member, $balanceAfter);

        return $response;
    }

    /** @return null|array{clientId:string,source:string} */
    private function normalizeExternalReversalContext(?array $context, ?string $userId): ?array
    {
        $apiActor = str_starts_with((string)$userId, 'api:');
        if ($context === null) {
            if ($apiActor) {
                throw new PurchaseVerificationException(
                    'La reversa externa no tiene un contexto de origen verificable.',
                    'purchase_reversal_context_missing',
                    ['actorId' => $userId],
                    403
                );
            }

            return null;
        }

        $clientId = trim((string)($context['clientId'] ?? ''));
        $source = strtolower(trim((string)($context['source'] ?? '')));
        if (
            !$apiActor
            || $clientId === ''
            || strlen($clientId) > 160
            || !in_array($source, self::EXTERNAL_API_SOURCES, true)
            || !hash_equals('api:' . $clientId, (string)$userId)
        ) {
            throw new PurchaseVerificationException(
                'El contexto de la reversa externa no es valido.',
                'purchase_reversal_context_invalid',
                [
                    'actorId' => $userId,
                    'apiClientId' => $clientId !== '' ? mb_substr($clientId, 0, 160) : null,
                    'source' => $source !== '' ? mb_substr($source, 0, 32) : null,
                ],
                403
            );
        }

        return ['clientId' => $clientId, 'source' => $source];
    }

    /** @param array{clientId:string,source:string} $context */
    private function assertExternalPurchaseReversalAllowed(string $tenantId, array $ledger, array $context): void
    {
        $clientId = $context['clientId'];
        $source = $context['source'];
        $ledgerSource = strtolower(trim((string)($ledger['source'] ?? '')));
        $ledgerActor = trim((string)($ledger['created_by_user_id'] ?? ''));
        $metadata = $ledger['metadata'] ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        $actorOwned = $this->apiClientActorBelongsToRotationLineage(
            $tenantId,
            $clientId,
            $source,
            $ledgerActor
        );
        $sourceOwned = hash_equals($source, $ledgerSource);
        $posVerificationOwned = true;
        if ($source === 'pos') {
            $verifiedClientId = trim((string)($metadata['sourceVerification']['clientId'] ?? ''));
            $posVerificationOwned = $verifiedClientId !== ''
                && $this->apiClientActorBelongsToRotationLineage(
                    $tenantId,
                    $clientId,
                    'pos',
                    'api:' . $verifiedClientId
                );
        }

        if (!$sourceOwned || !$actorOwned || !$posVerificationOwned) {
            throw new PurchaseVerificationException(
                'La credencial externa no puede reversar una compra creada por otra fuente o cliente.',
                'purchase_reversal_source_mismatch',
                [
                    'apiClientId' => $clientId,
                    'actorId' => 'api:' . $clientId,
                    'requestedSource' => $source,
                    'purchaseSource' => $ledgerSource,
                    'purchaseLedgerId' => (string)($ledger['id'] ?? ''),
                ],
                403
            );
        }
    }

    private function apiClientActorBelongsToRotationLineage(
        string $tenantId,
        string $currentClientId,
        string $expectedSource,
        string $purchaseActor
    ): bool {
        if (!str_starts_with($purchaseActor, 'api:')) {
            return false;
        }
        $purchaseClientId = substr($purchaseActor, 4);
        if ($purchaseClientId === '') {
            return false;
        }

        $cursor = $currentClientId;
        $visited = [];
        for ($depth = 0; $depth < 32 && $cursor !== ''; $depth++) {
            if (isset($visited[$cursor])) {
                return false;
            }
            $visited[$cursor] = true;
            $rows = $this->fetchAll(
                'SELECT id, source, status, rotated_from_client_id
                 FROM loyalty_api_clients
                 WHERE tenant_id = :tenant_id AND id = :id
                 LIMIT 1',
                ['tenant_id' => $tenantId, 'id' => $cursor]
            );
            if ($rows === []) {
                return false;
            }
            $client = $rows[0];
            $expectedStatus = $depth === 0 ? 'active' : 'revoked';
            if (
                !hash_equals($expectedSource, strtolower(trim((string)($client['source'] ?? ''))))
                || !hash_equals($expectedStatus, strtolower(trim((string)($client['status'] ?? ''))))
            ) {
                return false;
            }
            if (hash_equals($purchaseClientId, (string)$client['id'])) {
                return true;
            }
            $cursor = trim((string)($client['rotated_from_client_id'] ?? ''));
        }

        return false;
    }

    public function redeemReward(array $payload, ?string $userId = null, ?string $sourceContext = null): array {
        $tenantId = $this->tenantId();
        $redemptionSource = $this->redemptionOperationSource($userId, $sourceContext);
        $memberId = trim((string)($payload['memberId'] ?? $payload['member_id'] ?? ''));
        $rewardId = trim((string)($payload['rewardId'] ?? $payload['reward_id'] ?? ''));
        if ($memberId === '' || $rewardId === '') {
            throw new \InvalidArgumentException('Socio y premio son obligatorios.');
        }
        $commandId = $this->commandId($payload, '', true);
        $journalCommandId = $this->journalCommandId($commandId, $userId);
        $earlyReplay = $this->replayCommandIfCompleted('redemption.redeem', $journalCommandId, $payload);
        if ($earlyReplay !== null) {
            return $earlyReplay;
        }

        $member = $this->memberById($memberId);
        $reward = $this->rewardById($rewardId);
        if (!$member || !$reward) {
            throw new LoyaltyResourceNotFoundException('Socio o premio no encontrado.');
        }
        $this->assertMemberCanOperate($member, 'canjear puntos');
        $settings = $this->settings()['settings'];
        if ((bool)($settings['redemption']['requireDigitalCard'] ?? true) && !$this->hasActiveWallet($memberId)) {
            $this->recordRisk('high', 'redemption_without_card', 'Canje bloqueado porque el socio no tiene tarjeta digital activa.', $memberId, null);
            throw new \InvalidArgumentException('Este socio necesita una tarjeta digital activa para canjear puntos.');
        }
        $this->assertRedemptionLimits($tenantId, $memberId, $rewardId, $settings);

        $program = $this->program($tenantId);
        $pointsCost = (int)($reward['points_cost'] ?? 0);
        $accountSnapshot = $this->accountForMember($tenantId, $memberId);
        $this->assertNoOutstandingDebt($accountSnapshot, $memberId);

        $redemptionId = $this->id('redemption');
        $this->pdo->beginTransaction();
        try {
            $replay = $this->reserveCommand(
                'redemption.redeem',
                $journalCommandId,
                $payload,
                $userId,
                $redemptionSource
            );
            if ($replay !== null) {
                $this->pdo->commit();
                return $replay;
            }
            $this->transactionAdvisoryLock('redemption-member', $memberId);
            $this->assertRedemptionLimits($tenantId, $memberId, $rewardId, $settings);
            $account = $this->accountForMemberForUpdate($tenantId, $memberId);
            $this->assertNoOutstandingDebt($account, $memberId);
            $reward = $this->rewardForUpdate($rewardId);
            if (($reward['status'] ?? '') !== 'active') {
                throw new PurchaseVerificationException(
                    'El premio no esta activo.',
                    'redemption_reward_inactive',
                    ['rewardId' => $rewardId],
                    409
                );
            }
            if ((int)($reward['stock'] ?? 0) <= 0) {
                throw new PurchaseVerificationException(
                    'El premio no tiene stock disponible.',
                    'redemption_stock_exhausted',
                    ['rewardId' => $rewardId],
                    409
                );
            }
            $pointsCost = (int)($reward['points_cost'] ?? 0);
            if ((int)$account['balance'] < $pointsCost) {
                throw new PurchaseVerificationException(
                    'El socio no tiene puntos suficientes para este canje.',
                    'redemption_insufficient_balance',
                    ['balance' => (int)$account['balance'], 'pointsCost' => $pointsCost, 'rewardId' => $rewardId],
                    409
                );
            }
            $balanceAfter = (int)$account['balance'] - $pointsCost;
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
                    (id, tenant_id, member_id, reward_id, points_cost, status, source, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :reward_id, :points_cost, :status, :source, :metadata, :created_by_user_id)',
                [
                    'id' => $redemptionId,
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'reward_id' => $rewardId,
                    'points_cost' => $pointsCost,
                    'status' => 'approved',
                    'source' => $redemptionSource,
                    'metadata' => json_encode([
                        'rewardName' => $reward['name'],
                        'memberName' => $member['name'] ?? $member['account_name'] ?? '',
                        'source' => $redemptionSource,
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
                    'source' => $redemptionSource,
                    'source_reference' => $redemptionId,
                    'metadata' => json_encode([
                        'rewardId' => $rewardId,
                        'rewardName' => $reward['name'],
                        'source' => $redemptionSource,
                    ]),
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
                'commandId' => $commandId,
            ], null, $userId);
            $response = [
                'redemption' => $this->redemptionById($redemptionId),
                'member' => $this->memberById($memberId),
                'reward' => $this->rewardById($rewardId),
                'pointsRedeemed' => $pointsCost,
                'balanceAfter' => $balanceAfter,
                'commandId' => $commandId,
            ];
            $this->completeCommand('redemption.redeem', $journalCommandId, $response);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->persistOperationRisk($e, $memberId, $redemptionId);
            throw $e;
        }

        $this->syncGoogleWalletBestEffort($member, $balanceAfter);

        return $response;
    }

    public function publicRewardsPortal(string $token): array {
        $this->expireCustomerPortalReservations();
        $member = $this->memberFromPortalToken($token);
        $program = $this->program($this->tenantId());
        $path = '/api/l/portal';
        $rewards = array_map(function (array $reward) use ($token): array {
            $reward['formNonce'] = $this->issuePortalFormNonce($token, 'claim:' . (string)$reward['id']);
            return $reward;
        }, $this->portalRewardsForMember($member));
        $claims = array_map(function (array $claim) use ($token): array {
            if (in_array((string)($claim['status'] ?? ''), [self::CLAIM_STATUS_PENDING_REVIEW, self::CLAIM_STATUS_READY_FOR_PICKUP], true)) {
                $claim['cancelFormNonce'] = $this->issuePortalFormNonce($token, 'cancel:' . (string)$claim['id']);
            }
            return $claim;
        }, $this->portalClaimsForMember((string)$member['id']));

        return [
            'portalUrl' => $this->publicUrlForPath($path),
            'publicPath' => $this->publicGatewayPath($path),
            'program' => $program,
            'member' => $member,
            'rewards' => $rewards,
            'claims' => $claims,
            'support' => $this->portalSupport(),
        ];
    }

    public function exchangePortalSession(string $token): array {
        $token = trim($token);
        if (preg_match('/^lps_[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('El enlace de acceso no es valido.');
        }
        $rows = $this->fetchAll(
            'UPDATE loyalty_portal_sessions
             SET exchanged_at = NOW(), last_used_at = NOW()
             WHERE tenant_id = :tenant_id
               AND token_hash = :token_hash
               AND exchanged_at IS NULL
               AND revoked_at IS NULL
               AND expires_at > NOW()
             RETURNING member_id, expires_at',
            ['tenant_id' => $this->tenantId(), 'token_hash' => hash('sha256', $token)]
        );
        if ($rows === []) {
            throw new \InvalidArgumentException('El enlace ya fue utilizado o expiro.');
        }

        return [
            'portalPath' => $this->publicGatewayPath('/api/l/portal'),
            'expiresAt' => $rows[0]['expires_at'],
        ];
    }

    public function publicRewardsAccessPage(array $state = []): array {
        $program = $this->program($this->tenantId());
        $identifier = trim((string)($state['identifier'] ?? $state['account'] ?? $state['accountId'] ?? $state['account_id'] ?? ''));

        $defaults = [
            'program' => $program,
            'accessPath' => $this->publicGatewayPath($this->portalAccessPath()),
            'requestPath' => $this->publicGatewayPath($this->portalAccessPath() . '/request'),
            'verifyPath' => $this->publicGatewayPath($this->portalAccessPath() . '/verify'),
            'support' => $this->portalSupport(),
            'identifier' => $identifier,
            'step' => $identifier !== '' ? 'identified' : 'identify',
        ];

        return array_merge($defaults, $state);
    }

    public function requestPortalAccess(array $payload): array {
        $this->expirePortalOtpChallenges();
        $identifier = trim((string)($payload['identifier'] ?? $payload['accountId'] ?? $payload['account_id'] ?? $payload['email'] ?? $payload['phone'] ?? ''));
        if ($identifier === '') {
            throw new \InvalidArgumentException('Ingresa tu cuenta, correo o telefono registrado.');
        }

        $member = $this->memberFromPortalAccessIdentifier($identifier);
        if (!$member) {
            throw new \InvalidArgumentException('No encontramos una tarjeta activa con esos datos.');
        }
        $this->assertMemberCanOperate($member, 'entrar al catalogo');
        if (($member['wallet_platform'] ?? 'none') === 'none' || !$this->hasActiveWallet((string)$member['id'])) {
            throw new \InvalidArgumentException('Necesitas una tarjeta Wallet activa para entrar al catalogo.');
        }

        $recipient = mb_strtolower(trim((string)($member['email'] ?? '')));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Este socio no tiene un correo valido para recibir el codigo.');
        }

        $challengeId = $this->id('otp');
        $code = (string)random_int(100000, 999999);
        $expiresAt = $this->futureTimestamp(10 * 60);
        $this->pdo->beginTransaction();
        try {
            $this->transactionAdvisoryLock('portal-otp-member', (string)$member['id']);
            $recent = (int)$this->scalar(
                "SELECT COUNT(*)
                 FROM loyalty_portal_otp_challenges
                 WHERE tenant_id = :tenant_id
                   AND member_id = :member_id
                   AND created_at > NOW() - INTERVAL '60 seconds'",
                ['tenant_id' => $this->tenantId(), 'member_id' => (string)$member['id']]
            );
            if ($recent > 0) {
                throw new \InvalidArgumentException('Ya enviamos un codigo hace poco. Espera un minuto antes de pedir otro.');
            }
            $this->execute(
                "UPDATE loyalty_portal_otp_challenges
                 SET consumed_at = NOW(),
                     metadata = COALESCE(metadata, '{}'::jsonb) || '{\"invalidatedByNewChallenge\":true}'::jsonb,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id AND consumed_at IS NULL",
                ['tenant_id' => $this->tenantId(), 'member_id' => (string)$member['id']]
            );
            $this->execute(
                'INSERT INTO loyalty_portal_otp_challenges
                    (id, tenant_id, member_id, channel, destination, code_hash, expires_at, metadata)
                 VALUES
                    (:id, :tenant_id, :member_id, :channel, :destination, :code_hash, :expires_at, :metadata)',
                [
                    'id' => $challengeId,
                    'tenant_id' => $this->tenantId(),
                    'member_id' => (string)$member['id'],
                    'channel' => 'email',
                    'destination' => $recipient,
                    'code_hash' => $this->portalOtpHash($challengeId, $code),
                    'expires_at' => $expiresAt,
                    'metadata' => json_encode(['accountId' => (string)$member['account_id'], 'requestedFrom' => 'wallet-catalog'], JSON_UNESCAPED_UNICODE),
                ]
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $sent = $this->sendPortalOtpEmail($member, $recipient, $code, $expiresAt);
        if (!$sent) {
            $this->execute(
                'UPDATE loyalty_portal_otp_challenges SET consumed_at = NOW(), updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id AND consumed_at IS NULL',
                ['tenant_id' => $this->tenantId(), 'id' => $challengeId]
            );
            throw new \RuntimeException('No se pudo enviar el codigo por correo. Revisa la configuracion SMTP.');
        }

        $this->recordAudit('portal_access.otp_requested', 'member', (string)$member['id'], null, [
            'channel' => 'email',
            'destination' => $this->maskEmail($recipient),
            'expiresAt' => $expiresAt,
        ], null, 'customer:otp');

        return $this->publicRewardsAccessPage([
            'step' => 'verify',
            'challengeId' => $challengeId,
            'identifier' => (string)$member['account_id'],
            'destination' => $this->maskEmail($recipient),
            'expiresAt' => $expiresAt,
            'message' => 'Enviamos un codigo de 6 digitos a tu correo registrado.',
        ]);
    }

    public function verifyPortalAccess(array $payload): array {
        $this->expirePortalOtpChallenges();
        $challengeId = trim((string)($payload['challengeId'] ?? $payload['challenge_id'] ?? ''));
        $code = preg_replace('/\D+/', '', (string)($payload['code'] ?? $payload['otp'] ?? '')) ?? '';
        if ($challengeId === '' || strlen($code) !== 6) {
            throw new \InvalidArgumentException('Ingresa el codigo recibido.');
        }

        $failedMemberId = null;
        $result = null;
        $this->pdo->beginTransaction();
        try {
            $rows = $this->fetchAll(
                'SELECT c.*, m.account_id, m.account_name, m.email, m.phone, m.status, m.wallet_platform
                 FROM loyalty_portal_otp_challenges c
                 JOIN loyalty_members m ON m.id = c.member_id AND m.tenant_id = c.tenant_id
                 WHERE c.tenant_id = :tenant_id AND c.id = :id
                 LIMIT 1
                 FOR UPDATE OF c',
                ['tenant_id' => $this->tenantId(), 'id' => $challengeId]
            );
            $challenge = $rows[0] ?? null;
            if (!$challenge || !empty($challenge['consumed_at'])) {
                throw new \InvalidArgumentException('El codigo no es valido, ya fue utilizado o expiro.');
            }
            if (strtotime((string)$challenge['expires_at']) <= time()) {
                throw new \InvalidArgumentException('El codigo expiro. Solicita uno nuevo.');
            }
            if ((int)($challenge['attempts'] ?? 0) >= (int)($challenge['max_attempts'] ?? 5)) {
                throw new \InvalidArgumentException('Se supero el numero de intentos. Solicita un codigo nuevo.');
            }

            $member = $this->memberById((string)$challenge['member_id']);
            if (!$member) {
                throw new \InvalidArgumentException('Socio no encontrado para abrir el catalogo.');
            }
            $this->assertMemberCanOperate($member, 'entrar al catalogo');
            if (($member['wallet_platform'] ?? 'none') === 'none' || !$this->hasActiveWallet((string)$member['id'])) {
                throw new \InvalidArgumentException('Necesitas una tarjeta Wallet activa para entrar al catalogo.');
            }

            $expected = (string)($challenge['code_hash'] ?? '');
            if (!hash_equals($expected, $this->portalOtpHash($challengeId, $code))) {
                $failedMemberId = (string)$member['id'];
                $this->execute(
                    'UPDATE loyalty_portal_otp_challenges
                     SET attempts = LEAST(attempts + 1, max_attempts), updated_at = NOW()
                     WHERE tenant_id = :tenant_id AND id = :id AND consumed_at IS NULL',
                    ['tenant_id' => $this->tenantId(), 'id' => $challengeId]
                );
                $this->pdo->commit();
            } else {
                $consumed = $this->fetchAll(
                    'UPDATE loyalty_portal_otp_challenges
                     SET consumed_at = NOW(), updated_at = NOW()
                     WHERE tenant_id = :tenant_id AND id = :id
                       AND consumed_at IS NULL AND attempts < max_attempts
                     RETURNING id',
                    ['tenant_id' => $this->tenantId(), 'id' => $challengeId]
                );
                if ($consumed === []) {
                    throw new \InvalidArgumentException('Este codigo ya fue utilizado.');
                }
                $sessionToken = 'lps_' . bin2hex(random_bytes(32));
                $sessionExpiresAt = $this->futureTimestamp(15 * 60);
                $this->execute(
                    'UPDATE loyalty_portal_sessions SET revoked_at = NOW()
                     WHERE tenant_id = :tenant_id AND member_id = :member_id AND revoked_at IS NULL',
                    ['tenant_id' => $this->tenantId(), 'member_id' => (string)$member['id']]
                );
                $this->execute(
                    'INSERT INTO loyalty_portal_sessions
                        (id, tenant_id, member_id, token_hash, expires_at)
                     VALUES
                        (:id, :tenant_id, :member_id, :token_hash, :expires_at)',
                    [
                        'id' => $this->id('portal_session'),
                        'tenant_id' => $this->tenantId(),
                        'member_id' => (string)$member['id'],
                        'token_hash' => hash('sha256', $sessionToken),
                        'expires_at' => $sessionExpiresAt,
                    ]
                );
                $this->recordAudit('portal_access.otp_verified', 'member', (string)$member['id'], null, [
                    'challengeId' => $challengeId,
                    'sessionExpiresAt' => $sessionExpiresAt,
                ], null, 'customer:otp');
                $path = '/api/l/r/' . rawurlencode($sessionToken);
                $result = [
                    'member' => $member,
                    'portalPath' => $this->publicGatewayPath($path),
                    'portalUrl' => $this->publicUrlForPath($path),
                    'expiresAt' => $sessionExpiresAt,
                ];
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if ($failedMemberId !== null) {
            $this->recordRisk('medium', 'portal_otp_failed', 'Codigo OTP incorrecto para acceso al catalogo.', $failedMemberId, null, [
                'challengeId' => $challengeId,
            ]);
            throw new \InvalidArgumentException('El codigo no es correcto.');
        }

        return $result ?? throw new \RuntimeException('No se pudo crear la sesion del portal.');
    }

    public function createPortalClaim(string $token, array $payload): array {
        $this->expireCustomerPortalReservations();
        $member = $this->memberFromPortalToken($token);
        $tenantId = $this->tenantId();
        $rewardId = trim((string)($payload['rewardId'] ?? $payload['reward_id'] ?? ''));
        if ($rewardId === '') {
            throw new \InvalidArgumentException('Selecciona un premio.');
        }
        $formNonce = trim((string)($payload['formNonce'] ?? $payload['form_nonce'] ?? ''));
        $this->consumePortalFormNonce($token, 'claim:' . $rewardId, $formNonce);

        $reward = $this->rewardById($rewardId);
        if (!$reward) {
            throw new LoyaltyResourceNotFoundException('Premio no encontrado.');
        }
        $claimMode = $this->normalizeClaimMode((string)($reward['claim_mode'] ?? self::CLAIM_MODE_STAFF_ONLY));
        if ($claimMode === self::CLAIM_MODE_STAFF_ONLY) {
            throw new \InvalidArgumentException('Este premio solo puede canjearlo el equipo del local.');
        }
        if (($reward['status'] ?? '') !== 'active') {
            throw new \InvalidArgumentException('El premio no esta activo.');
        }
        if ((int)($reward['stock'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('El premio no tiene stock disponible.');
        }

        $settings = $this->settings()['settings'];
        $this->assertRedemptionLimits($tenantId, (string)$member['id'], $rewardId, $settings);

        $account = $this->accountForMember($tenantId, (string)$member['id']);
        $this->assertNoOutstandingDebt($account, (string)$member['id']);
        $pointsCost = (int)($reward['points_cost'] ?? 0);
        if ((int)$account['balance'] < $pointsCost) {
            throw new \InvalidArgumentException('No tienes puntos suficientes para este premio.');
        }

        $manualApprovalThreshold = max(0, (int)($settings['redemption']['manualApprovalThresholdPoints'] ?? 0));
        $requiresManualApproval = $manualApprovalThreshold > 0 && $pointsCost >= $manualApprovalThreshold;
        $deliveryOptions = $this->normalizeDeliveryOptions($reward['claim_delivery_options'] ?? []);
        $fulfillmentType = $this->portalFulfillmentType($claimMode, $payload, $deliveryOptions);
        $claimCodeRequired = $claimMode === self::CLAIM_MODE_IN_STORE && !$requiresManualApproval;
        $claimCode = null;
        $expiresAt = $claimCodeRequired
            ? $this->futureTimestamp(15 * 60)
            : $this->futureTimestamp(7 * 24 * 60 * 60);
        $status = $claimCodeRequired
            ? self::CLAIM_STATUS_READY_FOR_PICKUP
            : self::CLAIM_STATUS_PENDING_REVIEW;
        $metadata = [
            'claimMode' => $claimMode,
            'rewardName' => $reward['name'],
            'memberName' => $member['name'] ?? $member['account_name'] ?? '',
            'contactName' => trim((string)($payload['contactName'] ?? $payload['contact_name'] ?? $member['account_name'] ?? '')),
            'contactPhone' => trim((string)($payload['contactPhone'] ?? $payload['contact_phone'] ?? $member['phone'] ?? '')),
            'contactEmail' => trim((string)($payload['contactEmail'] ?? $payload['contact_email'] ?? $member['email'] ?? '')),
            'deliveryAddress' => trim((string)($payload['deliveryAddress'] ?? $payload['delivery_address'] ?? '')),
            'notes' => mb_substr(trim((string)($payload['notes'] ?? $payload['note'] ?? '')), 0, 500),
            'createdFrom' => 'wallet-portal',
            'manualApprovalRequired' => $requiresManualApproval,
            'manualApprovalThresholdPoints' => $manualApprovalThreshold,
        ];

        $redemptionId = '';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $claimCode = $claimCodeRequired ? $this->claimCode() : null;
            try {
                $redemptionId = $this->reservePortalRedemption(
                    $member,
                    $reward,
                    $status,
                    $fulfillmentType,
                    $metadata,
                    $expiresAt,
                    $claimCode,
                    $formNonce,
                    $payload
                );
                break;
            } catch (\PDOException $exception) {
                if (!$claimCodeRequired || !$this->isClaimCodeCollision($exception) || $attempt === 5) {
                    throw $exception;
                }
            }
        }
        if ($redemptionId === '') {
            throw new \RuntimeException('No se pudo reservar el premio.');
        }
        $redemption = $this->redemptionById($redemptionId);
        if ($claimCode !== null) {
            $redemption['claimCode'] = $claimCode;
        }

        return [
            'redemption' => $redemption,
            'member' => $this->memberById((string)$member['id']),
            'reward' => $this->rewardById($rewardId),
            'claimCode' => $claimCode,
        ];
    }

    public function cancelPortalClaim(string $token, string $redemptionId, array $payload = []): array {
        $this->expireCustomerPortalReservations();
        $member = $this->memberFromPortalToken($token);
        $this->consumePortalFormNonce($token, 'cancel:' . $redemptionId, (string)($payload['formNonce'] ?? $payload['form_nonce'] ?? ''));
        $redemption = $this->redemptionById($redemptionId);
        if (!$redemption || (string)($redemption['member_id'] ?? '') !== (string)$member['id']) {
            throw new LoyaltyResourceNotFoundException('Solicitud no encontrada.');
        }
        if (!in_array((string)($redemption['status'] ?? ''), [self::CLAIM_STATUS_PENDING_REVIEW, self::CLAIM_STATUS_READY_FOR_PICKUP], true)) {
            throw new \InvalidArgumentException('Esta solicitud ya no se puede cancelar desde el portal.');
        }

        return $this->releaseRedemptionReservation(
            $redemptionId,
            self::CLAIM_STATUS_CANCELLED,
            trim((string)($payload['reason'] ?? 'Cancelado por el cliente desde Wallet.')),
            'customer:' . (string)$member['id']
        );
    }

    public function redemptionClaims(array $filters = []): array {
        $this->expireCustomerPortalReservations();
        $tenantId = $this->tenantId();
        $limit = min(100, max(10, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $status = strtolower(trim((string)($filters['status'] ?? 'open')));
        $query = mb_substr(strtolower(trim((string)($filters['query'] ?? ''))), 0, 120);
        $fulfillment = strtolower(trim((string)($filters['fulfillment'] ?? 'all')));
        $claimMode = strtolower(trim((string)($filters['claim_mode'] ?? 'all')));
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));
        $sort = strtolower(trim((string)($filters['sort'] ?? 'priority')));
        $where = ['r.tenant_id = :tenant_id', 'r.source = :source'];
        $params = ['tenant_id' => $tenantId, 'source' => self::CUSTOMER_PORTAL_SOURCE];
        if ($status !== 'all') {
            if ($status === 'open') {
                $where[] = "r.status IN ('pending_review', 'ready_for_pickup', 'approved')";
            } elseif (in_array($status, [
                self::CLAIM_STATUS_PENDING_REVIEW,
                self::CLAIM_STATUS_READY_FOR_PICKUP,
                self::CLAIM_STATUS_APPROVED,
                self::CLAIM_STATUS_DELIVERED,
                self::CLAIM_STATUS_CANCELLED,
                self::CLAIM_STATUS_EXPIRED,
            ], true)) {
                $where[] = 'r.status = :status';
                $params['status'] = $status;
            }
        }

        if ($query !== '') {
            $where[] = '(lower(m.account_id) LIKE :query OR lower(m.account_name) LIKE :query OR lower(COALESCE(m.email, \'\')) LIKE :query OR lower(COALESCE(m.phone, \'\')) LIKE :query OR lower(w.name) LIKE :query OR lower(r.id) LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }

        if (in_array($fulfillment, ['in_store', 'pickup', 'delivery'], true)) {
            $where[] = 'r.fulfillment_type = :fulfillment';
            $params['fulfillment'] = $fulfillment;
        } elseif ($fulfillment === 'unassigned') {
            $where[] = '(r.fulfillment_type IS NULL OR r.fulfillment_type = \'\')';
        }

        if (in_array($claimMode, ['in_store', 'managed'], true)) {
            $where[] = 'w.claim_mode = :claim_mode';
            $params['claim_mode'] = $claimMode;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1) {
            $where[] = 'r.created_at >= CAST(:date_from AS date)';
            $params['date_from'] = $dateFrom;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
            $where[] = "r.created_at < (CAST(:date_to AS date) + INTERVAL '1 day')";
            $params['date_to'] = $dateTo;
        }

        $orderBy = match ($sort) {
            'oldest' => 'r.created_at ASC',
            'expires' => 'COALESCE(r.code_expires_at, r.expires_at, r.created_at) ASC, r.created_at DESC',
            'newest' => 'r.created_at DESC',
            default => "CASE r.status
                 WHEN 'ready_for_pickup' THEN 1
                 WHEN 'pending_review' THEN 2
                 WHEN 'approved' THEN 3
                 ELSE 4
               END,
               r.created_at DESC",
        };

        $sqlWhere = implode(' AND ', $where);
        $items = $this->fetchAll(
            "SELECT r.id, r.member_id, m.account_id, m.account_name AS customer, m.email, m.phone,
                    r.reward_id, w.name AS reward, w.claim_mode, r.points_cost, r.status, r.source,
                    r.fulfillment_type, r.code_expires_at, r.expires_at, r.resolved_at,
                    r.resolved_by_user_id, r.resolution_note, r.metadata, r.created_at, r.updated_at
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.id = r.member_id AND m.tenant_id = r.tenant_id
             JOIN loyalty_rewards w ON w.id = r.reward_id AND w.tenant_id = r.tenant_id
             WHERE {$sqlWhere}
             ORDER BY {$orderBy}
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        $total = (int)$this->scalar(
            "SELECT COUNT(*)
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.id = r.member_id AND m.tenant_id = r.tenant_id
             JOIN loyalty_rewards w ON w.id = r.reward_id AND w.tenant_id = r.tenant_id
             WHERE {$sqlWhere}",
            $params
        );

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total,
            'summary' => $this->redemptionClaimsSummary($tenantId),
        ];
    }

    public function approveRedemptionClaim(string $redemptionId, array $payload = [], ?string $userId = null): array {
        $this->expireCustomerPortalReservations();
        $note = trim((string)($payload['note'] ?? $payload['reason'] ?? 'Solicitud aprobada por gestor.'));
        $this->pdo->beginTransaction();
        try {
            $preview = $this->redemptionById($redemptionId);
            if (!$preview) {
                throw new LoyaltyResourceNotFoundException('Solicitud no encontrada.');
            }
            $this->transactionAdvisoryLock('redemption-member', (string)$preview['member_id']);
            $before = $this->redemptionForUpdate($redemptionId);
            if (!$before) {
                throw new LoyaltyResourceNotFoundException('Solicitud no encontrada.');
            }
            if ((string)$before['status'] !== (string)$preview['status']) {
                throw new \InvalidArgumentException('La solicitud cambio mientras se procesaba; vuelve a cargar su estado.');
            }
            if ((string)($before['status'] ?? '') !== self::CLAIM_STATUS_PENDING_REVIEW) {
                throw new \InvalidArgumentException('Solo las solicitudes pendientes pueden aprobarse.');
            }

            $this->execute(
                'UPDATE loyalty_redemptions
                 SET status = :status,
                     expires_at = NULL,
                     resolved_at = NOW(),
                     resolved_by_user_id = :resolved_by_user_id,
                     resolution_note = :resolution_note,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id',
                [
                    'status' => self::CLAIM_STATUS_APPROVED,
                    'resolved_by_user_id' => $userId,
                    'resolution_note' => $note,
                    'tenant_id' => $this->tenantId(),
                    'id' => $redemptionId,
                ]
            );
            $after = $this->redemptionById($redemptionId);
            $this->recordAudit('redemption_claim.approved', 'redemption', $redemptionId, $before, $after, $note, $userId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $after;
    }

    public function validateInStoreClaimCode(array $payload, ?string $userId = null): array {
        $this->expireCustomerPortalReservations();
        $code = preg_replace('/\D+/', '', (string)($payload['code'] ?? '')) ?? '';
        if (strlen($code) !== 6) {
            throw new \InvalidArgumentException('Ingresa el codigo de 6 digitos.');
        }

        $rows = $this->fetchAll(
            "SELECT id
             FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id
               AND source = :source
               AND status = :status
               AND validation_code_hash = :validation_code_hash
               AND code_expires_at >= NOW()
             ORDER BY created_at DESC
             LIMIT 1",
            [
                'tenant_id' => $this->tenantId(),
                'source' => self::CUSTOMER_PORTAL_SOURCE,
                'status' => self::CLAIM_STATUS_READY_FOR_PICKUP,
                'validation_code_hash' => $this->claimCodeHash($code),
            ]
        );
        if ($rows !== []) {
            return $this->deliverRedemptionClaim((string)$rows[0]['id'], ['note' => 'Codigo validado en local.'], $userId);
        }

        throw new \InvalidArgumentException('Codigo invalido o expirado.');
    }

    public function deliverRedemptionClaim(string $redemptionId, array $payload = [], ?string $userId = null): array {
        $this->expireCustomerPortalReservations();
        $note = trim((string)($payload['note'] ?? $payload['reason'] ?? 'Premio entregado.'));
        $this->pdo->beginTransaction();
        try {
            $preview = $this->redemptionById($redemptionId);
            if (!$preview) {
                throw new LoyaltyResourceNotFoundException('Solicitud no encontrada.');
            }
            $this->transactionAdvisoryLock('redemption-member', (string)$preview['member_id']);
            $before = $this->redemptionForUpdate($redemptionId);
            if (!$before) {
                throw new LoyaltyResourceNotFoundException('Solicitud no encontrada.');
            }
            if ((string)$before['status'] !== (string)$preview['status']) {
                throw new \InvalidArgumentException('La solicitud cambio mientras se procesaba; vuelve a cargar su estado.');
            }
            if (!in_array((string)($before['status'] ?? ''), [self::CLAIM_STATUS_READY_FOR_PICKUP, self::CLAIM_STATUS_APPROVED], true)) {
                throw new \InvalidArgumentException('Esta solicitud no esta lista para entrega.');
            }
            if ((string)($before['status'] ?? '') === self::CLAIM_STATUS_READY_FOR_PICKUP && !empty($before['code_expires_at']) && strtotime((string)$before['code_expires_at']) < time()) {
                $this->pdo->rollBack();
                $this->releaseRedemptionReservation($redemptionId, self::CLAIM_STATUS_EXPIRED, 'Codigo expirado antes de entrega.', 'system');
                throw new \InvalidArgumentException('El codigo de entrega expiro.');
            }

            $this->execute(
                'UPDATE loyalty_redemptions
                 SET status = :status,
                     expires_at = NULL,
                     resolved_at = NOW(),
                     resolved_by_user_id = :resolved_by_user_id,
                     resolution_note = :resolution_note,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id',
                [
                    'status' => self::CLAIM_STATUS_DELIVERED,
                    'resolved_by_user_id' => $userId,
                    'resolution_note' => $note,
                    'tenant_id' => $this->tenantId(),
                    'id' => $redemptionId,
                ]
            );
            $after = $this->redemptionById($redemptionId);
            $this->recordAudit('redemption_claim.delivered', 'redemption', $redemptionId, $before, $after, $note, $userId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $after;
    }

    public function cancelRedemptionClaim(string $redemptionId, array $payload = [], ?string $userId = null): array {
        $this->expireCustomerPortalReservations();
        $reason = trim((string)($payload['reason'] ?? 'Solicitud cancelada por gestor.'));

        return $this->releaseRedemptionReservation($redemptionId, self::CLAIM_STATUS_CANCELLED, $reason, $userId);
    }

    public function updateWallet(string $memberId, array $payload): array {
        $tenantId = $this->tenantId();
        $platform = strtolower(trim((string)($payload['platform'] ?? 'none')));
        if (!in_array($platform, ['google', 'apple', 'none'], true)) {
            throw new \InvalidArgumentException('La tarjeta digital debe ser Android, iPhone o sin tarjeta.');
        }
        $member = $this->memberById($memberId);
        if (!$member) {
            throw new LoyaltyResourceNotFoundException('Socio no encontrado.');
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
                $this->execute(
                    'INSERT INTO loyalty_wallet_passes
                        (id, tenant_id, member_id, platform, external_object_id, status, last_payload)
                     VALUES
                        (:id, :tenant_id, :member_id, :platform, :external_object_id, :status, :last_payload)
                     ON CONFLICT (tenant_id, member_id, platform) DO UPDATE
                     SET status = EXCLUDED.status,
                         external_object_id = EXCLUDED.external_object_id,
                         last_payload = EXCLUDED.last_payload,
                         updated_at = NOW()',
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
            throw new LoyaltyResourceNotFoundException('Socio no encontrado.');
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
            throw new LoyaltyResourceNotFoundException('Socio no encontrado para generar pase.');
        }

        $sendEmail = filter_var($payload['sendEmail'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $this->issueGoogleWalletLink($member, $userId, $sendEmail ?? true);
    }

    public function googleWalletLinkForAccount(string $accountId, array $client): array {
        $member = $this->memberFromPayload(['accountId' => $accountId]);
        if (!$member) {
            throw new ExternalApiNotFoundException('Socio no encontrado.');
        }
        $this->assertMemberCanOperate($member, 'generar tarjeta digital');

        return $this->issueGoogleWalletLink($member, 'api:' . (string)($client['id'] ?? 'external'), false);
    }

    /**
     * Resuelve el token del QR corto a los datos de la pagina de aterrizaje:
     * el saveUrl de Google mas los datos de display del socio para el boton.
     *
     * @return array{saveUrl: string, memberName: string, accountId: string, points: int, programName: string}
     */
    public function googleWalletQrLanding(string $token): array {
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
            (int)($member['points'] ?? 0),
            $this->portalAccessUrl((string)$member['account_id'])
        );

        $this->upsertGoogleWalletPass((string)$member['id'], $result['objectId'], 'qr-opened', [
            'points' => (int)($member['points'] ?? 0),
            'classId' => $result['classId'],
            'openedAt' => date('c'),
        ]);

        $walletSettings = $this->settings()['settings']['googleWallet'] ?? [];
        $programName = trim((string)($walletSettings['programName'] ?? $walletSettings['issuerName'] ?? ''))
            ?: 'tu programa de fidelizacion';

        return [
            'saveUrl' => (string)$result['saveUrl'],
            'memberName' => (string)($member['account_name'] ?? $member['account_id']),
            'accountId' => (string)$member['account_id'],
            'points' => (int)($member['points'] ?? 0),
            'programName' => $programName,
        ];
    }

    public function googleWalletNotify(array $payload, ?string $userId = null): array {
        $body = trim((string)($payload['body'] ?? $payload['message'] ?? ''));
        if ($body === '') {
            throw new \InvalidArgumentException('Escribe un mensaje para la notificacion.');
        }

        $member = $this->memberFromPayload($payload);
        if (!$member) {
            throw new LoyaltyResourceNotFoundException('Socio no encontrado.');
        }

        $service = $this->googleWalletServiceOrFail();
        $header = trim((string)($payload['header'] ?? $payload['title'] ?? ''));
        if ($header === '') {
            $walletSettings = $this->settings()['settings']['googleWallet'] ?? [];
            $header = trim((string)($walletSettings['issuerName'] ?? '')) ?: 'Notificacion';
        }
        $header = mb_substr($header, 0, 100);
        $body = mb_substr($body, 0, 300);

        $objectId = $this->googleWalletObjectIdForMember(
            (string)$member['id'],
            (string)$member['account_id'],
            $service
        );
        $result = $service->addMessageToObject($objectId, $header, $body);

        $this->recordAudit(
            'wallet.google.message_sent',
            'member',
            (string)$member['id'],
            null,
            [
                'objectId' => $result['objectId'],
                'messageId' => $result['messageId'],
                'messageType' => $result['messageType'] ?? null,
                'header' => $header,
            ],
            null,
            $userId
        );

        return [
            'sent' => true,
            'objectId' => $result['objectId'],
            'messageId' => $result['messageId'],
            'messageType' => $result['messageType'] ?? null,
        ];
    }

    private function issueGoogleWalletLink(array $member, ?string $userId, bool $sendEmail = false): array {
        $service = $this->googleWalletServiceOrFail();

        $result = $service->buildSaveUrl(
            (string)$member['account_id'],
            (string)($member['account_name'] ?? $member['account_id']),
            (int)($member['points'] ?? 0),
            $this->portalAccessUrl((string)$member['account_id'])
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
     * Envia el boton "Agregar a Google Wallet" al correo del socio.
     * El email usa un enlace corto propio para evitar que Gmail recorte el
     * mensaje por el JWT largo de Google Wallet.
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
        $landingUrl = $this->publicUrlForPath('/api/l/w/' . $this->googleWalletQrToken($member));
        $safeUrl = htmlspecialchars($landingUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safePoints = number_format($points, 0, ',', '.');

        $html = <<<HTML
<!doctype html>
<html lang="es">
  <body style="margin:0;padding:0;background:#f3f7fa;font-family:Arial,sans-serif;color:#172a3d;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f7fa;padding:28px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #d9e5ee;border-radius:14px;overflow:hidden;">
            <tr>
              <td style="background:#17324a;color:#ffffff;padding:24px;">
                <div style="font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.78;">{$safeProgram}</div>
                <h1 style="margin:8px 0 0;font-size:24px;line-height:1.2;">Tu tarjeta de recompensas esta lista</h1>
              </td>
            </tr>
            <tr>
              <td style="padding:24px;">
                <p style="margin:0 0 14px;font-size:16px;line-height:1.5;">Hola {$safeMember},</p>
                <p style="margin:0 0 18px;font-size:16px;line-height:1.5;">Agrega tu tarjeta a Google Wallet para consultar y usar tus puntos desde el telefono.</p>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px;background:#f7fafe;border:1px solid #d9e5ee;border-radius:10px;">
                  <tr>
                    <td style="padding:14px;">
                      <div style="font-size:12px;color:#50657a;font-weight:700;">Cuenta</div>
                      <div style="font-size:18px;font-weight:800;">{$safeAccount}</div>
                    </td>
                    <td style="padding:14px;">
                      <div style="font-size:12px;color:#50657a;font-weight:700;">Saldo actual</div>
                      <div style="font-size:18px;font-weight:800;">{$safePoints} pts</div>
                    </td>
                  </tr>
                </table>
                <p style="margin:24px 0 0;text-align:center;">
                  <a href="{$safeUrl}" target="_blank" style="display:inline-block;background:#2b648f;color:#ffffff;text-decoration:none;font-weight:800;font-size:14px;line-height:18px;padding:14px 22px;border-radius:10px;">Agregar tarjeta a Google Wallet</a>
                </p>
                <p style="margin:16px 0 0;text-align:center;color:#50657a;font-size:13px;line-height:1.5;">Si no abre, intenta tocar el boton desde Chrome en tu telefono Android.</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;

        $plain = "Hola {$memberName},\n\nAgrega tu tarjeta de recompensas de {$programName} a Google Wallet.\nCuenta: {$accountId}\nSaldo actual: {$points} pts\n\nEnlace: {$landingUrl}\n";
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
        $this->execute(
            'INSERT INTO loyalty_wallet_passes
                (id, tenant_id, member_id, platform, external_object_id, status, last_payload)
             VALUES
                (:id, :tenant_id, :member_id, \'google\', :external_object_id, :status, :last_payload)
             ON CONFLICT (tenant_id, member_id, platform) DO UPDATE
             SET status = EXCLUDED.status,
                 external_object_id = EXCLUDED.external_object_id,
                 last_payload = CASE
                     WHEN COALESCE(loyalty_wallet_passes.external_object_id, \'\') <> \'\'
                      AND loyalty_wallet_passes.external_object_id <> EXCLUDED.external_object_id
                     THEN EXCLUDED.last_payload || jsonb_build_object(
                         \'legacyExternalObjectId\', loyalty_wallet_passes.external_object_id
                     )
                     ELSE EXCLUDED.last_payload
                 END,
                 updated_at = NOW()',
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
                $balanceAfter,
                $this->portalAccessUrl((string)$member['account_id'])
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
                            COALESCE(SUM(u.request_count), 0) AS requests
                     FROM loyalty_api_clients c
                     LEFT JOIN loyalty_api_usage_daily u
                       ON u.api_client_id = c.id
                      AND u.tenant_id = c.tenant_id
                      AND u.usage_date BETWEEN :from::date AND :to::date
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
            throw new LoyaltyResourceNotFoundException('Evento de riesgo no encontrado.');
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
        $source = strtolower(trim((string)($payload['source'] ?? '')));
        if ($name === '') {
            throw new \InvalidArgumentException('El nombre del cliente API es obligatorio.');
        }
        if (!in_array($source, self::EXTERNAL_API_SOURCES, true)) {
            throw new \InvalidArgumentException('La fuente del cliente API no esta permitida.');
        }
        $rawKey = 'fp_' . bin2hex(random_bytes(24));
        $clientId = $this->id('api_client');
        $scopes = $this->normalizeApiScopes($payload['scopes'] ?? null, true);
        $rateLimit = $this->normalizeApiRateLimit($payload['rateLimitPerMinute'] ?? $payload['rate_limit_per_minute'] ?? 120);
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
                'rate_limit_per_minute' => $rateLimit,
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
        $this->pdo->beginTransaction();
        try {
            $before = $this->fetchAll(
                'SELECT id, name, source, scopes, status, rate_limit_per_minute, last_used_at, created_at, updated_at, revoked_at
                 FROM loyalty_api_clients
                 WHERE tenant_id = :tenant_id AND id = :id
                 LIMIT 1
                 FOR UPDATE',
                ['tenant_id' => $tenantId, 'id' => $clientId]
            )[0] ?? null;
            if (!$before) {
                throw new LoyaltyResourceNotFoundException('Cliente API no encontrado.');
            }
            if ((string)($before['status'] ?? '') === 'revoked') {
                throw new \InvalidArgumentException('Una credencial revocada es terminal y no puede modificarse ni reactivarse.');
            }

            $name = trim((string)($payload['name'] ?? $before['name'] ?? ''));
            $source = strtolower(trim((string)($before['source'] ?? 'external')));
            $status = trim((string)($payload['status'] ?? $before['status'] ?? 'active'));
            if ($name === '') {
                throw new \InvalidArgumentException('El nombre del cliente API es obligatorio.');
            }
            if (!in_array($status, ['active', 'suspended', 'revoked'], true)) {
                throw new \InvalidArgumentException('Estado de cliente API no permitido.');
            }
            if (array_key_exists('source', $payload) && strtolower(trim((string)$payload['source'])) !== $source) {
                throw new \InvalidArgumentException('La fuente de una credencial API es inmutable; rota la credencial para cambiarla.');
            }

            $scopes = array_key_exists('scopes', $payload)
                ? $this->normalizeApiScopes($payload['scopes'], true)
                : $this->normalizeApiScopes($before['scopes'] ?? null, true);
            $rateLimit = $this->normalizeApiRateLimit($payload['rateLimitPerMinute'] ?? $payload['rate_limit_per_minute'] ?? $before['rate_limit_per_minute'] ?? 120);
            $this->execute(
                'UPDATE loyalty_api_clients
                 SET name = :name,
                     source = :source,
                     scopes = :scopes,
                     status = :status,
                     rate_limit_per_minute = :rate_limit_per_minute,
                     revoked_at = CASE WHEN :status = \'revoked\' THEN COALESCE(revoked_at, NOW()) ELSE revoked_at END,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id AND status <> \'revoked\'',
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
            $this->pdo->commit();

            return $after;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function revokeApiClient(string $clientId, array $payload = [], ?string $userId = null): array {
        $payload['status'] = 'revoked';
        $payload['reason'] = trim((string)($payload['reason'] ?? 'Clave revocada por operador.'));

        return $this->updateApiClient($clientId, $payload, $userId);
    }

    public function rotateApiClient(string $clientId, array $payload = [], ?string $userId = null): array {
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException('La rotacion requiere un motivo.');
        }

        $this->pdo->beginTransaction();
        try {
            $before = $this->fetchAll(
                'SELECT id, name, source, scopes, status, rate_limit_per_minute, last_used_at, created_at, updated_at, revoked_at
                 FROM loyalty_api_clients
                 WHERE tenant_id = :tenant_id AND id = :id
                 LIMIT 1
                 FOR UPDATE',
                ['tenant_id' => $this->tenantId(), 'id' => $clientId]
            )[0] ?? null;
            if (!$before) {
                throw new LoyaltyResourceNotFoundException('Cliente API no encontrado.');
            }
            if ((string)($before['status'] ?? '') === 'revoked') {
                throw new \InvalidArgumentException('No se puede rotar una credencial ya revocada.');
            }
            $newClient = $this->createApiClient([
                'name' => trim((string)($payload['name'] ?? ($before['name'] . ' (rotada)'))),
                'source' => (string)$before['source'],
                'scopes' => $payload['scopes'] ?? $before['scopes'],
                'rateLimitPerMinute' => $payload['rateLimitPerMinute'] ?? $before['rate_limit_per_minute'],
            ], $userId);
            $this->execute(
                'UPDATE loyalty_api_clients
                 SET status = \'revoked\', revoked_at = NOW(), updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id AND status <> \'revoked\'',
                ['tenant_id' => $this->tenantId(), 'id' => $clientId]
            );
            $this->execute(
                'UPDATE loyalty_api_clients SET rotated_from_client_id = :old_id
                 WHERE tenant_id = :tenant_id AND id = :new_id',
                ['tenant_id' => $this->tenantId(), 'old_id' => $clientId, 'new_id' => $newClient['id']]
            );
            $this->recordAudit('api_client.rotated', 'api_client', $clientId, $before, [
                'replacementClientId' => $newClient['id'],
            ], $reason, $userId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['revokedClientId' => $clientId, 'replacement' => $newClient];
    }

    private function normalizeApiScopes(mixed $scopes, bool $required): array {
        if (!is_array($scopes)) {
            if ($required) {
                throw new \InvalidArgumentException('Selecciona scopes explicitos para la credencial API.');
            }
            return [];
        }
        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if ($scope === '*' || !in_array($scope, self::EXTERNAL_API_SCOPES, true)) {
                throw new \InvalidArgumentException("Scope API no permitido: {$scope}.");
            }
            if (!in_array($scope, $normalized, true)) {
                $normalized[] = $scope;
            }
        }
        sort($normalized, SORT_STRING);
        if ($required && $normalized === []) {
            throw new \InvalidArgumentException('La credencial API debe tener al menos un scope explicito.');
        }

        return $normalized;
    }

    private function normalizeApiRateLimit(mixed $value): int {
        if (is_int($value)) {
            $limit = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', trim($value)) === 1) {
            $limit = (int)trim($value);
        } else {
            throw new \InvalidArgumentException('El limite por minuto debe ser un entero.');
        }
        if ($limit < 1 || $limit > self::EXTERNAL_API_RATE_LIMIT_MAX) {
            throw new \InvalidArgumentException('El limite por minuto debe estar entre 1 y ' . self::EXTERNAL_API_RATE_LIMIT_MAX . '.');
        }

        return $limit;
    }

    public function externalProgram(): array {
        return [
            'program' => $this->program($this->tenantId()),
            'settings' => $this->settings()['settings'],
            'tiers' => $this->tierRules($this->tenantId()),
            'rewards' => $this->rewards(['status' => 'active']),
        ];
    }

    public function externalMember(string $accountId): array {
        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new \InvalidArgumentException('La cuenta del socio es obligatoria.');
        }

        $page = $this->customersPage([
            'account_id_exact' => $accountId,
            'limit' => 10,
            'offset' => 0,
        ]);
        if ($page['items'] === []) {
            throw new ExternalApiNotFoundException('Socio no encontrado.');
        }

        return $page;
    }

    public function upsertExternalMember(array $payload, array $client): array {
        $protected = [
            'status', 'blocked', 'blockedReason', 'blocked_reason', 'points', 'balance', 'pointsDebt',
            'points_debt', 'lifetimePoints', 'lifetime_points', 'tier', 'walletPlatform', 'wallet_platform',
            'role', 'permissions', 'unlocked',
        ];
        foreach ($protected as $field) {
            if (array_key_exists($field, $payload)) {
                throw new \InvalidArgumentException("members:write no puede modificar el campo protegido {$field}.");
            }
        }
        $allowed = [
            'memberId', 'member_id', 'accountId', 'account_id', 'externalCustomerId', 'external_customer_id',
            'name', 'accountName', 'account_name', 'email', 'customerEmail', 'phone', 'metadata',
        ];
        $payload = array_intersect_key($payload, array_flip($allowed));
        if (is_array($payload['metadata'] ?? null)) {
            $payload['metadata'] = array_intersect_key($payload['metadata'], array_flip([
                'identification', 'documentNumber', 'birthDate', 'preferences', 'marketingConsent', 'sourceProfile',
            ]));
        }
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
            throw ExternalApiAccessException::authenticationRequired();
        }
        $rows = $this->fetchAll(
            "SELECT id, name, source, scopes, status, rate_limit_per_minute
             FROM loyalty_api_clients
             WHERE tenant_id = :tenant_id AND key_hash = :key_hash AND status = 'active'
             LIMIT 1",
            ['tenant_id' => $tenantId, 'key_hash' => hash('sha256', $key)]
        );
        if ($rows === []) {
            throw ExternalApiAccessException::invalidCredential();
        }
        $client = $rows[0];
        $scopes = is_array($client['scopes'] ?? null) ? $client['scopes'] : [];
        if (!in_array($requiredScope, $scopes, true)) {
            throw ExternalApiAccessException::insufficientScope();
        }
        $this->consumeExternalApiQuota(
            $tenantId,
            (string)$client['id'],
            max(1, (int)($client['rate_limit_per_minute'] ?? 120))
        );
        $this->execute(
            "UPDATE loyalty_api_clients
             SET last_used_at = NOW(), updated_at = NOW()
             WHERE tenant_id = :tenant_id
               AND id = :id
               AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL '1 minute')",
            ['tenant_id' => $tenantId, 'id' => $client['id']]
        );

        return $client;
    }

    public function verifySignedPosRequest(
        array $client,
        string $rawCredential,
        string $method,
        string $publicPath,
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $signature
    ): void {
        if (strtolower(trim((string)($client['source'] ?? ''))) !== 'pos') {
            return;
        }
        $tenantId = $this->tenantId();
        $clientId = trim((string)($client['id'] ?? ''));
        $metadata = [
            'apiClientId' => $clientId,
            'actorId' => $clientId !== '' ? 'api:' . $clientId : null,
            'requestId' => trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '')) ?: null,
            'clientIp' => $this->trustedClientIp(),
        ];
        try {
            if ($rawCredential === '' || $clientId === '') {
                throw new PurchaseVerificationException('Credencial POS no resuelta.', 'pos_signature_missing', $metadata, 401);
            }
            if (preg_match('/^[0-9]{10}$/D', $timestamp) !== 1 || abs(time() - (int)$timestamp) > 300) {
                throw new PurchaseVerificationException('La firma POS esta fuera de la ventana de cinco minutos.', 'pos_signature_expired', $metadata, 401);
            }
            if (strlen($nonce) < 16 || strlen($nonce) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/D', $nonce) !== 1) {
                throw new PurchaseVerificationException('El nonce POS no es valido.', 'pos_nonce_invalid', $metadata, 422);
            }
            if (preg_match('/^v1=([a-f0-9]{64})$/Di', trim($signature), $matches) !== 1) {
                throw new PurchaseVerificationException('La firma POS no tiene el formato esperado.', 'pos_signature_invalid', $metadata, 401);
            }
            $path = (string)(parse_url($publicPath, PHP_URL_PATH) ?? '');
            $tenantSlug = trim((string)(TenantContext::slug() ?? ''));
            $loyaltySegment = trim((string)($_ENV['PUBLIC_LOYALTY_SERVICE_SEGMENT'] ?? 'fidelizacion'), '/ ');
            if (
                $tenantSlug === ''
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $tenantSlug) !== 1
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $loyaltySegment) !== 1
            ) {
                throw new PurchaseVerificationException('El contrato publico POS no esta configurado.', 'pos_signature_path_config_invalid', $metadata, 500);
            }
            $publicApiPrefix = '/' . rawurlencode($tenantSlug) . '/' . rawurlencode($loyaltySegment) . '/v1/';
            if ($path === '' || !str_starts_with($path, $publicApiPrefix) || strlen($path) <= strlen($publicApiPrefix)) {
                throw new PurchaseVerificationException('La ruta firmada no pertenece al tenant.', 'pos_signature_tenant_mismatch', $metadata, 401);
            }
            $canonical = implode("\n", [
                strtoupper(trim($method)),
                $path,
                $tenantId,
                $timestamp,
                $nonce,
                hash('sha256', $rawBody),
            ]);
            $expected = hash_hmac('sha256', $canonical, $rawCredential);
            if (!hash_equals($expected, strtolower($matches[1]))) {
                throw new PurchaseVerificationException('La firma POS no es valida.', 'pos_signature_invalid', $metadata, 401);
            }

            $this->execute('DELETE FROM loyalty_api_request_nonces WHERE expires_at < NOW()');
            try {
                $this->execute(
                    'INSERT INTO loyalty_api_request_nonces
                        (tenant_id, api_client_id, nonce_hash, request_timestamp, expires_at)
                     VALUES
                        (:tenant_id, :api_client_id, :nonce_hash, to_timestamp(:request_timestamp), to_timestamp(:expires_at))',
                    [
                        'tenant_id' => $tenantId,
                        'api_client_id' => $clientId,
                        'nonce_hash' => hash('sha256', $nonce),
                        'request_timestamp' => (int)$timestamp,
                        'expires_at' => (int)$timestamp + 300,
                    ]
                );
            } catch (\PDOException $exception) {
                if ($exception->getCode() === '23505') {
                    throw new PurchaseVerificationException('El nonce POS ya fue utilizado.', 'pos_nonce_replayed', $metadata, 409);
                }
                throw $exception;
            }
        } catch (PurchaseVerificationException $exception) {
            $this->recordRisk('critical', $exception->riskType(), $exception->getMessage(), null, null, $exception->riskMetadata());
            throw $exception;
        }
    }

    private function consumeExternalApiQuota(string $tenantId, string $apiClientId, int $limit): void {
        $rows = $this->fetchAll(
            "INSERT INTO loyalty_api_rate_limit_counters
                (tenant_id, api_client_id, window_started_at, request_count, updated_at)
             VALUES
                (:tenant_id, :api_client_id, date_trunc('minute', CURRENT_TIMESTAMP), 1, NOW())
             ON CONFLICT (tenant_id, api_client_id) DO UPDATE
             SET window_started_at = EXCLUDED.window_started_at,
                 request_count = CASE
                     WHEN loyalty_api_rate_limit_counters.window_started_at = EXCLUDED.window_started_at
                     THEN loyalty_api_rate_limit_counters.request_count + 1
                     ELSE 1
                 END,
                 updated_at = NOW()
             RETURNING request_count",
            ['tenant_id' => $tenantId, 'api_client_id' => $apiClientId]
        );
        $this->execute(
            'INSERT INTO loyalty_api_usage_daily
                (tenant_id, api_client_id, usage_date, request_count, updated_at)
             VALUES
                (:tenant_id, :api_client_id, CURRENT_DATE, 1, NOW())
             ON CONFLICT (tenant_id, api_client_id, usage_date) DO UPDATE
             SET request_count = loyalty_api_usage_daily.request_count + 1,
                 updated_at = NOW()',
            ['tenant_id' => $tenantId, 'api_client_id' => $apiClientId]
        );

        if ((int)($rows[0]['request_count'] ?? 0) > $limit) {
            throw ExternalApiAccessException::rateLimitExceeded();
        }
    }

    public function idempotentExternalMutation(
        string $operation,
        string $idempotencyKey,
        array $payload,
        callable $callback,
        string $apiClientId
    ): array {
        $tenantId = $this->tenantId();
        $key = trim($idempotencyKey);
        $apiClientId = trim($apiClientId);
        if ($apiClientId === '') {
            throw new \LogicException('La idempotencia externa requiere un cliente API resuelto.');
        }
        if ($key === '') {
            $settings = $this->settings()['settings'];
            $required = filter_var($settings['security']['idempotencyRequiredForExternalApi'] ?? true, FILTER_VALIDATE_BOOL);
            if ($required) {
                throw new \InvalidArgumentException('Idempotency-Key es obligatorio para mutaciones externas.');
            }

            return [
                'payload' => $callback(),
                'status' => 201,
                'replayed' => false,
            ];
        }

        $requestHash = hash('sha256', $this->canonicalJson($payload));
        $lockKey = implode('|', [$tenantId, $apiClientId, $operation, $key]);
        $this->sessionAdvisoryLock($lockKey);

        try {
            $rows = $this->fetchAll(
                'SELECT request_hash, status_code, response_payload
                 FROM loyalty_idempotency_keys
                 WHERE tenant_id = :tenant_id
                   AND idempotency_key = :idempotency_key
                   AND operation = :operation
                   AND (api_client_id = :api_client_id OR api_client_id IS NULL)
                 ORDER BY CASE WHEN api_client_id = :preferred_api_client_id THEN 0 ELSE 1 END
                 LIMIT 1',
                [
                    'tenant_id' => $tenantId,
                    'api_client_id' => $apiClientId,
                    'preferred_api_client_id' => $apiClientId,
                    'idempotency_key' => $key,
                    'operation' => $operation,
                ]
            );
            if ($rows !== []) {
                if (($rows[0]['request_hash'] ?? '') !== $requestHash) {
                    throw ExternalApiConflictException::payloadMismatch();
                }
                if ((int)($rows[0]['status_code'] ?? 0) === 0) {
                    throw ExternalApiConflictException::pendingRequest();
                }
                return [
                    'payload' => $rows[0]['response_payload'] ?? [],
                    'status' => (int)($rows[0]['status_code'] ?? 200),
                    'replayed' => true,
                ];
            }

            $reservationId = $this->id('idem');
            $this->execute(
                'INSERT INTO loyalty_idempotency_keys
                    (id, tenant_id, api_client_id, idempotency_key, operation, request_hash, status_code, response_payload)
                 VALUES
                    (:id, :tenant_id, :api_client_id, :idempotency_key, :operation, :request_hash, 0, :response_payload)',
                [
                    'id' => $reservationId,
                    'tenant_id' => $tenantId,
                    'api_client_id' => $apiClientId,
                    'idempotency_key' => $key,
                    'operation' => $operation,
                    'request_hash' => $requestHash,
                    'response_payload' => json_encode(['state' => 'processing']),
                ]
            );

            try {
                $payloadResult = $callback();
                $this->execute(
                    'UPDATE loyalty_idempotency_keys
                     SET status_code = 201, response_payload = :response_payload
                     WHERE tenant_id = :tenant_id AND id = :id',
                    [
                        'tenant_id' => $tenantId,
                        'id' => $reservationId,
                        'response_payload' => json_encode($payloadResult),
                    ]
                );
            } catch (\Throwable $exception) {
                $this->execute(
                    'DELETE FROM loyalty_idempotency_keys WHERE tenant_id = :tenant_id AND id = :id AND status_code = 0',
                    ['tenant_id' => $tenantId, 'id' => $reservationId]
                );
                throw $exception;
            }

            return ['payload' => $payloadResult, 'status' => 201, 'replayed' => false];
        } finally {
            $this->sessionAdvisoryUnlock($lockKey);
        }
    }

    private function memberFromPayload(array $payload): ?array {
        $tenantId = $this->tenantId();

        $byAccountId = function (string $accountId) use ($tenantId): ?array {
            $rows = $this->fetchAll(
                'SELECT m.*, COALESCE(a.balance, 0) AS points, COALESCE(a.points_debt, 0) AS points_debt
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
                'SELECT m.*, COALESCE(a.balance, 0) AS points, COALESCE(a.points_debt, 0) AS points_debt
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
             WHERE tenant_id = :tenant_id
               AND entry_type = :entry_type
               AND normalized_reference = :reference
               AND reversed_at IS NULL',
            ['tenant_id' => $tenantId, 'entry_type' => $entryType, 'reference' => $reference]
        );
        if ($exists > 0) {
            throw new PurchaseVerificationException(
                'Esta factura ya fue registrada en el programa.',
                'duplicate_reference',
                ['entryType' => $entryType],
                409
            );
        }
    }

    private function commandId(array $payload, string $fallback, bool $required = false): string {
        $commandId = trim((string)($payload['commandId'] ?? $payload['command_id'] ?? $payload['_commandId'] ?? ''));
        if ($commandId === '') {
            if ($required) {
                throw new \InvalidArgumentException('commandId o Idempotency-Key es obligatorio para esta operacion.');
            }
            $commandId = $fallback;
        }
        if ($commandId === '' || strlen($commandId) > 160 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D', $commandId) !== 1) {
            throw new \InvalidArgumentException('commandId no tiene un formato valido.');
        }

        return $commandId;
    }

    /**
     * Idempotency keys are scoped to the actor that supplied them. The public
     * commandId remains unchanged in responses for backward compatibility.
     */
    private function journalCommandId(string $commandId, ?string $actorId): string
    {
        $actorId = trim((string)$actorId);
        if ($actorId === '') {
            return $commandId;
        }

        return 'actor.' . substr(hash('sha256', $actorId), 0, 20) . '.' . $commandId;
    }

    private function reserveCommand(string $operation, string $commandId, array $payload, ?string $actorId, string $source): ?array {
        $tenantId = $this->tenantId();
        $this->transactionAdvisoryLock('loyalty-command', $operation . '|' . $commandId);
        $requestHash = hash('sha256', $this->canonicalJson($payload));
        $rows = $this->fetchAll(
            'SELECT request_hash, status, response_payload
             FROM loyalty_command_journal
             WHERE tenant_id = :tenant_id AND operation = :operation AND command_id = :command_id
             LIMIT 1
             FOR UPDATE',
            ['tenant_id' => $tenantId, 'operation' => $operation, 'command_id' => $commandId]
        );
        if ($rows !== []) {
            if (!hash_equals((string)$rows[0]['request_hash'], $requestHash)) {
                throw ExternalApiConflictException::payloadMismatch();
            }
            if ((string)$rows[0]['status'] !== 'completed') {
                throw ExternalApiConflictException::pendingRequest();
            }

            return is_array($rows[0]['response_payload'] ?? null) ? $rows[0]['response_payload'] : [];
        }

        $actorType = str_starts_with((string)$actorId, 'api:')
            ? 'api'
            : (str_starts_with((string)$actorId, 'customer:') ? 'customer' : ((string)$actorId === 'system' ? 'system' : 'dashboard'));
        $this->execute(
            'INSERT INTO loyalty_command_journal
                (id, tenant_id, operation, command_id, request_hash, status, actor_type, actor_id, request_id, source)
             VALUES
                (:id, :tenant_id, :operation, :command_id, :request_hash, \'processing\', :actor_type, :actor_id, :request_id, :source)',
            [
                'id' => $this->id('command'),
                'tenant_id' => $tenantId,
                'operation' => $operation,
                'command_id' => $commandId,
                'request_hash' => $requestHash,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'request_id' => trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '')) ?: null,
                'source' => $source,
            ]
        );

        return null;
    }

    private function replayCommandIfCompleted(string $operation, string $commandId, array $payload): ?array
    {
        $rows = $this->fetchAll(
            'SELECT request_hash, status, response_payload
             FROM loyalty_command_journal
             WHERE tenant_id = :tenant_id AND operation = :operation AND command_id = :command_id
             LIMIT 1',
            ['tenant_id' => $this->tenantId(), 'operation' => $operation, 'command_id' => $commandId]
        );
        if ($rows === []) {
            return null;
        }
        $requestHash = hash('sha256', $this->canonicalJson($payload));
        if (!hash_equals((string)$rows[0]['request_hash'], $requestHash)) {
            throw ExternalApiConflictException::payloadMismatch();
        }
        if ((string)$rows[0]['status'] !== 'completed') {
            throw ExternalApiConflictException::pendingRequest();
        }

        return is_array($rows[0]['response_payload'] ?? null) ? $rows[0]['response_payload'] : [];
    }

    private function completeCommand(string $operation, string $commandId, array $response): void {
        $this->execute(
            'UPDATE loyalty_command_journal
             SET status = \'completed\', response_payload = :response_payload, completed_at = NOW()
             WHERE tenant_id = :tenant_id AND operation = :operation AND command_id = :command_id',
            [
                'tenant_id' => $this->tenantId(),
                'operation' => $operation,
                'command_id' => $commandId,
                'response_payload' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function canonicalJson(array $payload): string {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (array_is_list($value)) {
                return array_map($normalize, $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }

            return $value;
        };

        return json_encode($normalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
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

    private function redemptionOperationSource(?string $userId, ?string $sourceContext): string {
        $actor = trim((string)$userId);
        if (str_starts_with($actor, 'customer:')) {
            return self::CUSTOMER_PORTAL_SOURCE;
        }
        if (str_starts_with($actor, 'api:')) {
            return strtolower(trim((string)$sourceContext)) === 'pos' ? 'pos' : 'api';
        }

        return 'dashboard';
    }

    private function throwMemberWriteException(\Throwable $e): void {
        if ($e instanceof \PDOException && $e->getCode() === '23505') {
            throw new \InvalidArgumentException('No se pudo guardar el socio porque la cuenta ya esta asignada a otro cliente.');
        }
    }

    private function calculatePurchasePoints(string $amount, array $member, array $formula): int {
        $minimum = DecimalMath::nonNegativeMoney($formula['minimumPurchaseAmount'] ?? '1.00', 'monto minimo');
        if (DecimalMath::compare($amount, $minimum, 2) < 0) {
            throw new \InvalidArgumentException('El monto minimo para acumular puntos es ' . $minimum . '.');
        }

        $pointsPerUnit = DecimalMath::factor($formula['pointsPerUnit'] ?? '1', 'puntos por unidad');
        $amountPerUnit = DecimalMath::factor($formula['amountPerUnit'] ?? '1', 'monto por unidad');
        $multiplier = DecimalMath::factor($formula['tierMultiplier'] ?? '1', 'multiplicador de nivel');
        $rounding = (string)($formula['roundingMode'] ?? DecimalMath::ROUND_FLOOR);
        $points = DecimalMath::calculatePoints($amount, $amountPerUnit, $pointsPerUnit, $multiplier, $rounding);
        $minimumPoints = $this->strictNonNegativeInteger(
            $formula['minimumPointsPerPurchase'] ?? self::MINIMUM_POINTS_PER_PURCHASE,
            'minimo de puntos por compra'
        );
        $points = max($minimumPoints, $points);
        $maximum = $this->strictNonNegativeInteger(
            $formula['maximumPointsPerPurchase'] ?? 20000,
            'maximo de puntos por compra'
        );
        if ($points > $maximum) {
            $this->recordRisk('medium', 'purchase_points_capped', 'Compra limitada por maximo de puntos.', (string)($member['id'] ?? ''), null, ['calculated' => $points, 'maximum' => $maximum]);
        }
        $points = DecimalMath::capPoints($points, $maximum);

        $dailyLimit = $this->strictNonNegativeInteger(
            $formula['maximumPointsPerMemberPerDay'] ?? 50000,
            'maximo diario de puntos por socio'
        );
        $timezone = trim((string)($formula['dailyWindowTimezone'] ?? 'America/Guayaquil'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('La zona horaria de la regla de acumulacion no es valida.');
        }
        $today = (int)$this->scalar(
            "SELECT COALESCE(SUM(points), 0)
             FROM loyalty_point_ledger
             WHERE tenant_id = :tenant_id
               AND member_id = :member_id
               AND entry_type = 'purchase'
               AND created_at >= date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE :timezone)
               AND created_at < date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE :timezone) + INTERVAL '1 day'",
            [
                'tenant_id' => $this->tenantId(),
                'member_id' => $member['id'],
                'timezone' => $timezone,
            ]
        );
        if ($dailyLimit > 0 && ($today + $points) > $dailyLimit) {
            throw new PurchaseVerificationException(
                'El socio alcanzo el limite diario de puntos acumulables.',
                'daily_earning_limit',
                ['today' => $today, 'points' => $points, 'limit' => $dailyLimit],
                409
            );
        }

        return $points;
    }

    private function tenantTimezoneFromSettings(array $settings): string
    {
        $timezone = trim((string)($settings['program']['timezone'] ?? 'America/Guayaquil'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return 'America/Guayaquil';
        }

        return $timezone;
    }

    private function purchaseFormulaSummary(array $settings, array $member): array {
        $earning = $settings['earning'] ?? [];
        $rounding = (string)($earning['roundingMode'] ?? DecimalMath::ROUND_FLOOR);
        return [
            'schemaVersion' => 'loyalty-purchase-formula-v2',
            'eligibleAmountSource' => (string)($earning['eligibleAmountSource'] ?? 'invoice_total'),
            'minimumPurchaseAmount' => DecimalMath::nonNegativeMoney($earning['minimumPurchaseAmount'] ?? '1.00', 'monto minimo'),
            'pointsPerUnit' => DecimalMath::factor($earning['pointsPerUnit'] ?? '1', 'puntos por unidad'),
            'amountPerUnit' => DecimalMath::factor($earning['amountPerUnit'] ?? '1', 'monto por unidad'),
            'roundingMode' => $rounding === 'round' ? DecimalMath::ROUND_HALF_UP : $rounding,
            'minimumPointsPerPurchase' => self::MINIMUM_POINTS_PER_PURCHASE,
            'maximumPointsPerPurchase' => $this->strictNonNegativeInteger(
                $earning['maximumPointsPerPurchase'] ?? 20000,
                'maximo de puntos por compra'
            ),
            'maximumPointsPerMemberPerDay' => $this->strictNonNegativeInteger(
                $earning['maximumPointsPerMemberPerDay'] ?? 50000,
                'maximo diario de puntos por socio'
            ),
            'dailyLimitZeroMeansUnlimited' => true,
            'dailyLimitLedgerEntryType' => 'purchase',
            'dailyWindowTimezone' => $this->tenantTimezoneFromSettings($settings),
            'tier' => (string)($member['tier'] ?? 'Bronce'),
            'tierMultiplier' => $this->tierMultiplier((string)($member['tier'] ?? 'Bronce')),
            'calculationSteps' => [
                'validate_amount_at_or_above_minimum',
                'divide_amount_by_amount_per_unit',
                'multiply_by_points_per_unit',
                'multiply_by_tier_multiplier',
                'apply_rounding_mode',
                'apply_minimum_points_per_purchase',
                'apply_maximum_points_per_purchase',
                'validate_member_daily_limit',
            ],
        ];
    }

    private function earningRuleVersion(string $tenantId, string $programId, array $formula, ?string $userId): int {
        $ruleHash = hash('sha256', $this->canonicalJson($formula));
        $rows = $this->fetchAll(
            'SELECT version FROM loyalty_earning_rule_versions
             WHERE tenant_id = :tenant_id AND rule_hash = :rule_hash
             LIMIT 1',
            ['tenant_id' => $tenantId, 'rule_hash' => $ruleHash]
        );
        if ($rows !== []) {
            return (int)$rows[0]['version'];
        }

        $this->transactionAdvisoryLock('earning-rules-version', $tenantId);
        $rows = $this->fetchAll(
            'SELECT version FROM loyalty_earning_rule_versions
             WHERE tenant_id = :tenant_id AND rule_hash = :rule_hash
             LIMIT 1',
            ['tenant_id' => $tenantId, 'rule_hash' => $ruleHash]
        );
        if ($rows !== []) {
            return (int)$rows[0]['version'];
        }
        $version = 1 + (int)$this->scalar(
            'SELECT COALESCE(MAX(version), 0) FROM loyalty_earning_rule_versions WHERE tenant_id = :tenant_id',
            ['tenant_id' => $tenantId]
        );
        $this->execute(
            'INSERT INTO loyalty_earning_rule_versions
                (id, tenant_id, program_id, version, rule_hash, formula_snapshot, created_by_user_id)
             VALUES
                (:id, :tenant_id, :program_id, :version, :rule_hash, :formula_snapshot, :created_by_user_id)',
            [
                'id' => $this->id('earning_rule'),
                'tenant_id' => $tenantId,
                'program_id' => $programId,
                'version' => $version,
                'rule_hash' => $ruleHash,
                'formula_snapshot' => json_encode($formula, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by_user_id' => $userId,
            ]
        );

        return $version;
    }

    private function assertRedemptionLimits(string $tenantId, string $memberId, string $rewardId, array $settings): void {
        $redemption = $settings['redemption'] ?? [];
        $maxDaily = max(1, (int)($redemption['maximumRedemptionsPerMemberPerDay'] ?? 3));
        $maxSameReward = max(1, (int)($redemption['maximumSameRewardPerMemberPerDay'] ?? 1));
        $timezone = $this->tenantTimezoneFromSettings($settings);
        $dailyTotal = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id
               AND member_id = :member_id
               AND status IN ('approved', 'pending_review', 'ready_for_pickup', 'delivered')
               AND created_at >= (date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE :timezone) AT TIME ZONE :timezone)
               AND created_at < ((date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE :timezone) + INTERVAL '1 day') AT TIME ZONE :timezone)",
            ['tenant_id' => $tenantId, 'member_id' => $memberId, 'timezone' => $timezone]
        );
        if ($dailyTotal >= $maxDaily) {
            $this->throwRedemptionRisk(
                'El socio alcanzo el limite diario de canjes.',
                'daily_redemption_limit',
                $memberId,
                ['limit' => $maxDaily, 'count' => $dailyTotal]
            );
        }
        $sameReward = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id
               AND member_id = :member_id
               AND reward_id = :reward_id
               AND status IN ('approved', 'pending_review', 'ready_for_pickup', 'delivered')
               AND created_at >= (date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE :timezone) AT TIME ZONE :timezone)
               AND created_at < ((date_trunc('day', CURRENT_TIMESTAMP AT TIME ZONE :timezone) + INTERVAL '1 day') AT TIME ZONE :timezone)",
            ['tenant_id' => $tenantId, 'member_id' => $memberId, 'reward_id' => $rewardId, 'timezone' => $timezone]
        );
        if ($sameReward >= $maxSameReward) {
            $this->throwRedemptionRisk(
                'El socio ya canjeo este premio hoy.',
                'same_reward_daily_limit',
                $memberId,
                ['rewardId' => $rewardId, 'limit' => $maxSameReward, 'count' => $sameReward]
            );
        }
    }

    private function throwRedemptionRisk(string $message, string $riskType, string $memberId, array $metadata): never
    {
        $exception = new PurchaseVerificationException($message, $riskType, $metadata, 409);
        if (!$this->pdo->inTransaction()) {
            $this->persistOperationRisk($exception, $memberId, $metadata['rewardId'] ?? null);
        }
        throw $exception;
    }

    private function assertNoOutstandingDebt(array $account, string $memberId): void {
        $debt = (int)($account['points_debt'] ?? 0);
        if ($debt <= 0) {
            return;
        }

        $exception = new PurchaseVerificationException(
            'El socio mantiene una deuda de puntos y no puede realizar canjes hasta amortizarla.',
            'redemption_blocked_by_debt',
            ['debt' => $debt],
            409
        );
        if (!$this->pdo->inTransaction()) {
            $this->recordRisk('high', $exception->riskType(), $exception->getMessage(), $memberId, null, $exception->riskMetadata());
        }
        throw $exception;
    }

    private function strictPoints(mixed $value, string $field): int {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?[0-9]+$/D', trim($value)) !== 1) {
            throw new \InvalidArgumentException("{$field} debe ser un entero sin exponentes ni decimales.");
        }
        $value = trim($value);
        if (strlen(ltrim($value, '-')) > 9) {
            throw new \InvalidArgumentException("{$field} excede el limite permitido.");
        }

        return (int)$value;
    }

    private function insertDebtLedger(
        string $tenantId,
        string $memberId,
        string $programId,
        string $entryType,
        int $points,
        int $debtAfter,
        string $reference,
        string $source,
        array $metadata,
        ?string $userId
    ): void {
        $this->execute(
            'INSERT INTO loyalty_debt_ledger
                (id, tenant_id, member_id, program_id, entry_type, points, debt_after,
                 reference, source, metadata, created_by_user_id)
             VALUES
                (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :debt_after,
                 :reference, :source, :metadata, :created_by_user_id)',
            [
                'id' => $this->id('debt'),
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'program_id' => $programId,
                'entry_type' => $entryType,
                'points' => $points,
                'debt_after' => $debtAfter,
                'reference' => $reference,
                'source' => $source,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by_user_id' => $userId,
            ]
        );
    }

    /** @return array{balance:int,cancelled:list<string>} */
    private function cancelOpenReservationsForPurchaseReversal(
        string $tenantId,
        string $memberId,
        int $pointsNeeded,
        int $currentBalance,
        string $purchaseReference,
        ?string $userId,
        string $programId
    ): array {
        if ($currentBalance >= $pointsNeeded) {
            return ['balance' => $currentBalance, 'cancelled' => []];
        }
        $rows = $this->fetchAll(
            "SELECT * FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id
               AND member_id = :member_id
               AND source = :source
               AND status IN ('pending_review', 'ready_for_pickup', 'approved')
             ORDER BY created_at DESC, id DESC
             FOR UPDATE",
            ['tenant_id' => $tenantId, 'member_id' => $memberId, 'source' => self::CUSTOMER_PORTAL_SOURCE]
        );
        $balance = $currentBalance;
        $cancelled = [];
        foreach ($rows as $reservation) {
            if ($balance >= $pointsNeeded) {
                break;
            }
            $redemptionId = (string)$reservation['id'];
            $rewardId = (string)$reservation['reward_id'];
            $points = (int)$reservation['points_cost'];
            $this->rewardForUpdate($rewardId);
            $balance += $points;
            $this->execute(
                'UPDATE loyalty_redemptions
                 SET status = :status, expires_at = NULL, code_expires_at = NULL,
                     resolved_at = NOW(), resolved_by_user_id = :resolved_by_user_id,
                     resolution_note = :resolution_note, updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id
                   AND status IN (\'pending_review\', \'ready_for_pickup\', \'approved\')',
                [
                    'status' => self::CLAIM_STATUS_CANCELLED,
                    'resolved_by_user_id' => $userId,
                    'resolution_note' => 'Reserva cancelada para recuperar una compra reversada.',
                    'tenant_id' => $tenantId,
                    'id' => $redemptionId,
                ]
            );
            $this->execute(
                'UPDATE loyalty_rewards SET stock = stock + 1, updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $rewardId]
            );
            $this->execute(
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after,
                     reference, source, source_reference, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after,
                     :reference, :source, :source_reference, :metadata, :created_by_user_id)',
                [
                    'id' => $this->id('ledger'),
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'program_id' => $programId,
                    'entry_type' => 'redemption_reversal',
                    'points' => $points,
                    'balance_after' => $balance,
                    'reference' => $redemptionId,
                    'source' => 'purchase_reversal',
                    'source_reference' => $purchaseReference,
                    'metadata' => json_encode(['automatic' => true, 'reason' => 'purchase_reversal'], JSON_UNESCAPED_UNICODE),
                    'created_by_user_id' => $userId,
                ]
            );
            $cancelled[] = $redemptionId;
        }

        return ['balance' => $balance, 'cancelled' => $cancelled];
    }

    private function persistOperationRisk(
        \Throwable $exception,
        ?string $memberId,
        ?string $reference,
        ?array $actorContext = null
    ): void {
        if (!$exception instanceof PurchaseVerificationException) {
            return;
        }
        try {
            $metadata = $exception->riskMetadata();
            $requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
            if ($requestId !== '') {
                $metadata['requestId'] = mb_substr($requestId, 0, 160);
            }
            $apiClientId = trim((string)($actorContext['clientId'] ?? ''));
            if ($apiClientId !== '') {
                $metadata['apiClientId'] = mb_substr($apiClientId, 0, 160);
                $metadata['actorId'] = 'api:' . mb_substr($apiClientId, 0, 156);
            }
            $clientIp = $this->trustedClientIp();
            if ($clientIp !== null) {
                $metadata['clientIp'] = $clientIp;
            }
            $this->recordRisk(
                in_array($exception->riskType(), ['duplicate_reference', 'purchase_amount_mismatch', 'purchase_customer_mismatch'], true) ? 'critical' : 'high',
                $exception->riskType(),
                $exception->getMessage(),
                $memberId,
                $reference,
                $metadata
            );
        } catch (\Throwable $riskFailure) {
            error_log('[LOYALTY_RISK_PERSIST_FAILED] ' . $riskFailure->getMessage());
        }
    }

    private function trustedClientIp(): ?string
    {
        $candidate = function_exists('get_client_ip')
            ? trim((string)get_client_ip())
            : trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false
            ? mb_substr($candidate, 0, 64)
            : null;
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

    private function reservePortalRedemption(
        array $member,
        array $reward,
        string $status,
        string $fulfillmentType,
        array $metadata,
        string $expiresAt,
        ?string $claimCode,
        string $commandId,
        array $commandPayload
    ): string {
        $tenantId = $this->tenantId();
        $memberId = (string)$member['id'];
        $rewardId = (string)$reward['id'];
        $program = $this->program($tenantId);
        $this->accountForMember($tenantId, $memberId);
        $redemptionId = $this->id('redemption');
        $pointsCost = (int)($reward['points_cost'] ?? 0);
        $this->pdo->beginTransaction();
        try {
            $commandId = $this->commandId(['commandId' => $commandId], '', true);
            $journalCommandId = $this->journalCommandId($commandId, 'customer:' . $memberId);
            $replay = $this->reserveCommand(
                'redemption.reserve',
                $journalCommandId,
                $commandPayload + ['memberId' => $memberId, 'rewardId' => $rewardId],
                'customer:' . $memberId,
                self::CUSTOMER_PORTAL_SOURCE
            );
            if ($replay !== null) {
                $replayedId = trim((string)($replay['redemptionId'] ?? ''));
                if ($replayedId === '') {
                    throw new \RuntimeException('La respuesta idempotente de la reserva no es valida.');
                }
                $this->pdo->commit();
                return $replayedId;
            }
            $this->transactionAdvisoryLock('redemption-member', $memberId);
            $this->assertRedemptionLimits(
                $tenantId,
                $memberId,
                $rewardId,
                $this->settings()['settings']
            );
            $account = $this->accountForMemberForUpdate($tenantId, $memberId);
            $this->assertNoOutstandingDebt($account, $memberId);
            $lockedReward = $this->rewardForUpdate($rewardId);
            if (($lockedReward['status'] ?? '') !== 'active') {
                throw new PurchaseVerificationException(
                    'El premio no esta activo.',
                    'redemption_reward_inactive',
                    ['rewardId' => $rewardId],
                    409
                );
            }
            if ((int)($lockedReward['stock'] ?? 0) <= 0) {
                throw new PurchaseVerificationException(
                    'El premio no tiene stock disponible.',
                    'redemption_stock_exhausted',
                    ['rewardId' => $rewardId],
                    409
                );
            }
            $pointsCost = (int)($lockedReward['points_cost'] ?? 0);
            if ((int)$account['balance'] < $pointsCost) {
                throw new PurchaseVerificationException(
                    'No tienes puntos suficientes para este premio.',
                    'redemption_insufficient_balance',
                    ['balance' => (int)$account['balance'], 'pointsCost' => $pointsCost, 'rewardId' => $rewardId],
                    409
                );
            }

            $balanceAfter = (int)$account['balance'] - $pointsCost;
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
                    (id, tenant_id, member_id, reward_id, points_cost, status, source, fulfillment_type,
                     validation_code_hash, code_expires_at, expires_at, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :reward_id, :points_cost, :status, :source, :fulfillment_type,
                     :validation_code_hash, :code_expires_at, :expires_at, :metadata, :created_by_user_id)',
                [
                    'id' => $redemptionId,
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'reward_id' => $rewardId,
                    'points_cost' => $pointsCost,
                    'status' => $status,
                    'source' => self::CUSTOMER_PORTAL_SOURCE,
                    'fulfillment_type' => $fulfillmentType,
                    'validation_code_hash' => $claimCode !== null ? $this->claimCodeHash($claimCode) : null,
                    'code_expires_at' => $claimCode !== null ? $expiresAt : null,
                    'expires_at' => $expiresAt,
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    'created_by_user_id' => 'customer:' . $memberId,
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
                    'source' => self::CUSTOMER_PORTAL_SOURCE,
                    'source_reference' => $redemptionId,
                    'metadata' => json_encode(['rewardId' => $rewardId, 'rewardName' => $reward['name'], 'reserved' => true], JSON_UNESCAPED_UNICODE),
                    'created_by_user_id' => 'customer:' . $memberId,
                ]
            );
            $this->execute(
                'UPDATE loyalty_members SET last_activity_at = NOW(), updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $memberId]
            );
            $this->recordAudit('redemption_claim.reserved', 'redemption', $redemptionId, null, [
                'memberId' => $memberId,
                'rewardId' => $rewardId,
                'status' => $status,
                'fulfillmentType' => $fulfillmentType,
                'pointsCost' => $pointsCost,
                'balanceAfter' => $balanceAfter,
                'commandId' => $commandId,
            ], null, 'customer:' . $memberId);
            $this->completeCommand('redemption.reserve', $journalCommandId, [
                'redemptionId' => $redemptionId,
                'balanceAfter' => $balanceAfter,
                'pointsReserved' => $pointsCost,
                'commandId' => $commandId,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->persistOperationRisk($e, $memberId, $redemptionId);
            throw $e;
        }

        $this->syncGoogleWalletBestEffort($member, $balanceAfter);

        return $redemptionId;
    }

    private function releaseRedemptionReservation(string $redemptionId, string $nextStatus, string $reason, ?string $userId): array {
        $tenantId = $this->tenantId();
        $this->pdo->beginTransaction();
        try {
            $preview = $this->redemptionById($redemptionId);
            if (!$preview) {
                throw new LoyaltyResourceNotFoundException('Solicitud no encontrada.');
            }
            $memberId = (string)$preview['member_id'];
            $this->transactionAdvisoryLock('redemption-member', $memberId);
            $account = $this->accountForMemberForUpdate($tenantId, $memberId);
            $before = $this->redemptionForUpdate($redemptionId);
            if (!$before || (string)$before['member_id'] !== $memberId) {
                throw new LoyaltyResourceNotFoundException('Solicitud no encontrada.');
            }
            if ((string)$before['status'] !== (string)$preview['status']) {
                throw new \InvalidArgumentException('La solicitud cambio mientras se procesaba; vuelve a cargar su estado.');
            }
            if ((string)($before['source'] ?? '') !== self::CUSTOMER_PORTAL_SOURCE) {
                throw new \InvalidArgumentException('Solo se pueden revertir solicitudes del portal Wallet.');
            }
            if (!in_array((string)($before['status'] ?? ''), [
                self::CLAIM_STATUS_PENDING_REVIEW,
                self::CLAIM_STATUS_READY_FOR_PICKUP,
                self::CLAIM_STATUS_APPROVED,
            ], true)) {
                throw new \InvalidArgumentException('Esta solicitud ya fue cerrada.');
            }
            $rewardId = (string)$before['reward_id'];
            $points = (int)$before['points_cost'];
            $program = $this->program($tenantId);
            $member = $this->memberById($memberId);
            if (!$member) {
                throw new LoyaltyResourceNotFoundException('Socio no encontrado.');
            }
            $this->rewardForUpdate($rewardId);
            $balanceAfter = (int)$account['balance'] + $points;
            $this->execute(
                'UPDATE loyalty_point_accounts SET balance = :balance, updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND member_id = :member_id',
                ['balance' => $balanceAfter, 'tenant_id' => $tenantId, 'member_id' => $memberId]
            );
            $this->execute(
                'UPDATE loyalty_rewards SET stock = stock + 1, updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $rewardId]
            );
            $this->execute(
                'UPDATE loyalty_redemptions
                 SET status = :status,
                     expires_at = NULL,
                     resolved_at = NOW(),
                     resolved_by_user_id = :resolved_by_user_id,
                     resolution_note = :resolution_note,
                     updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id',
                [
                    'status' => $nextStatus,
                    'resolved_by_user_id' => $userId,
                    'resolution_note' => $reason,
                    'tenant_id' => $tenantId,
                    'id' => $redemptionId,
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
                    'entry_type' => 'redemption_reversal',
                    'points' => $points,
                    'balance_after' => $balanceAfter,
                    'reference' => $redemptionId,
                    'source' => self::CUSTOMER_PORTAL_SOURCE,
                    'source_reference' => $redemptionId,
                    'metadata' => json_encode(['reason' => $reason, 'status' => $nextStatus], JSON_UNESCAPED_UNICODE),
                    'created_by_user_id' => $userId,
                ]
            );
            $after = $this->redemptionById($redemptionId);
            $this->recordAudit('redemption_claim.' . $nextStatus, 'redemption', $redemptionId, $before, $after, $reason, $userId);
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
            'balanceAfter' => $balanceAfter,
            'pointsRestored' => $points,
        ];
    }

    private function expireCustomerPortalReservations(): void {
        $rows = $this->fetchAll(
            "SELECT id
             FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id
               AND source = :source
               AND status IN ('pending_review', 'ready_for_pickup')
               AND expires_at IS NOT NULL
               AND expires_at < NOW()
             ORDER BY expires_at ASC
             LIMIT 50",
            ['tenant_id' => $this->tenantId(), 'source' => self::CUSTOMER_PORTAL_SOURCE]
        );
        foreach ($rows as $row) {
            try {
                $this->releaseRedemptionReservation((string)$row['id'], self::CLAIM_STATUS_EXPIRED, 'Reserva expirada automaticamente.', 'system');
            } catch (\Throwable $e) {
                $this->recordRisk('medium', 'redemption_claim_expire_failed', 'No se pudo expirar una reserva de premio.', null, (string)($row['id'] ?? ''), [
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }
        }
    }

    private function portalRewardsForMember(array $member): array {
        $rewards = $this->fetchAll(
            "SELECT id, name, description, points_cost, stock, status, claim_mode, claim_instructions,
                    claim_delivery_options, image_url, metadata, created_at, updated_at
             FROM loyalty_rewards
             WHERE tenant_id = :tenant_id
               AND status = 'active'
               AND claim_mode IN ('in_store', 'managed')
             ORDER BY stock DESC, points_cost ASC, name ASC",
            ['tenant_id' => $this->tenantId()]
        );

        return array_map(function (array $reward) use ($member): array {
            $reward['claim_mode'] = $this->normalizeClaimMode((string)($reward['claim_mode'] ?? self::CLAIM_MODE_STAFF_ONLY));
            $reward['claim_delivery_options'] = $this->normalizeDeliveryOptions($reward['claim_delivery_options'] ?? []);
            $reward['canClaim'] = true;
            $reward['blockReason'] = null;
            if ((int)($reward['stock'] ?? 0) <= 0) {
                $reward['canClaim'] = false;
                $reward['blockReason'] = 'Sin stock disponible.';
            } elseif ((int)($member['points'] ?? 0) < (int)($reward['points_cost'] ?? 0)) {
                $reward['canClaim'] = false;
                $reward['blockReason'] = 'Puntos insuficientes.';
            }

            return $reward;
        }, $rewards);
    }

    private function portalClaimsForMember(string $memberId): array {
        return $this->fetchAll(
            "SELECT r.id, r.reward_id, w.name AS reward, w.claim_mode, r.points_cost, r.status,
                    r.fulfillment_type, r.code_expires_at, r.expires_at, r.resolved_at,
                    r.resolution_note, r.metadata, r.created_at, r.updated_at
             FROM loyalty_redemptions r
             JOIN loyalty_rewards w ON w.id = r.reward_id AND w.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant_id
               AND r.member_id = :member_id
               AND r.source = :source
             ORDER BY r.created_at DESC
             LIMIT 12",
            ['tenant_id' => $this->tenantId(), 'member_id' => $memberId, 'source' => self::CUSTOMER_PORTAL_SOURCE]
        );
    }

    private function redemptionClaimsSummary(string $tenantId): array {
        $rows = $this->fetchAll(
            "SELECT status, COUNT(*) AS total
             FROM loyalty_redemptions
             WHERE tenant_id = :tenant_id AND source = :source
             GROUP BY status",
            ['tenant_id' => $tenantId, 'source' => self::CUSTOMER_PORTAL_SOURCE]
        );
        $summary = [
            self::CLAIM_STATUS_PENDING_REVIEW => 0,
            self::CLAIM_STATUS_READY_FOR_PICKUP => 0,
            self::CLAIM_STATUS_APPROVED => 0,
            self::CLAIM_STATUS_DELIVERED => 0,
            self::CLAIM_STATUS_CANCELLED => 0,
            self::CLAIM_STATUS_EXPIRED => 0,
        ];
        foreach ($rows as $row) {
            $summary[(string)$row['status']] = (int)$row['total'];
        }

        return $summary;
    }

    private function accountForMemberForUpdate(string $tenantId, string $memberId): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM loyalty_point_accounts
             WHERE tenant_id = :tenant_id AND member_id = :member_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'member_id' => $memberId]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new \RuntimeException('Cuenta de puntos no encontrada.');
        }

        return $this->normalizeRow($account);
    }

    private function rewardForUpdate(string $rewardId): array {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('El bloqueo del premio requiere una transaccion activa.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM loyalty_rewards
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['tenant_id' => $this->tenantId(), 'id' => $rewardId]);
        $reward = $stmt->fetch();
        if (!$reward) {
            throw new LoyaltyResourceNotFoundException('Premio no encontrado.');
        }

        return $this->normalizeRow($reward);
    }

    private function memberFromPortalToken(string $token): array {
        $token = trim($token);
        if (preg_match('/^lps_[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('La sesion del portal no es valida.');
        }
        $rows = $this->fetchAll(
            'UPDATE loyalty_portal_sessions
             SET last_used_at = NOW()
             WHERE tenant_id = :tenant_id
               AND token_hash = :token_hash
               AND exchanged_at IS NOT NULL
               AND revoked_at IS NULL
               AND expires_at > NOW()
             RETURNING member_id, expires_at',
            ['tenant_id' => $this->tenantId(), 'token_hash' => hash('sha256', $token)]
        );
        if ($rows === []) {
            throw new \InvalidArgumentException('La sesion del portal expiro. Solicita un nuevo codigo.');
        }
        $member = $this->memberById((string)$rows[0]['member_id']);
        if (!$member) {
            throw new \InvalidArgumentException('Socio no encontrado para reclamar premios.');
        }
        $this->assertMemberCanOperate($member, 'reclamar premios');
        if (($member['wallet_platform'] ?? 'none') === 'none' || !$this->hasActiveWallet((string)$member['id'])) {
            throw new \InvalidArgumentException('Necesitas una tarjeta Wallet activa para reclamar premios.');
        }

        return $member;
    }

    private function issuePortalFormNonce(string $token, string $action): string
    {
        $action = trim($action);
        if ($action === '' || strlen($action) > 180) {
            throw new \InvalidArgumentException('La accion del formulario no es valida.');
        }
        if (preg_match('/^lps_[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('La sesion del portal no es valida.');
        }

        $this->execute(
            'DELETE FROM loyalty_portal_form_nonces
             WHERE tenant_id = :tenant_id AND (expires_at <= NOW() OR consumed_at IS NOT NULL)',
            ['tenant_id' => $this->tenantId()]
        );
        $nonce = 'lfn_' . bin2hex(random_bytes(24));
        $rows = $this->fetchAll(
            'INSERT INTO loyalty_portal_form_nonces
                (id, tenant_id, session_id, member_id, action, nonce_hash, expires_at)
             SELECT :id, s.tenant_id, s.id, s.member_id, :action, :nonce_hash,
                    LEAST(s.expires_at, NOW() + INTERVAL \'15 minutes\')
             FROM loyalty_portal_sessions s
             WHERE s.tenant_id = :tenant_id
               AND s.token_hash = :token_hash
               AND s.exchanged_at IS NOT NULL
               AND s.revoked_at IS NULL
               AND s.expires_at > NOW()
             RETURNING id',
            [
                'id' => $this->id('form_nonce'),
                'tenant_id' => $this->tenantId(),
                'action' => $action,
                'nonce_hash' => hash('sha256', $nonce),
                'token_hash' => hash('sha256', $token),
            ]
        );
        if ($rows === []) {
            throw new \InvalidArgumentException('La sesion del portal expiro. Solicita un nuevo codigo.');
        }

        return $nonce;
    }

    private function consumePortalFormNonce(string $token, string $action, string $nonce): void
    {
        $token = trim($token);
        $action = trim($action);
        $nonce = trim($nonce);
        if (
            preg_match('/^lps_[a-f0-9]{64}$/D', $token) !== 1
            || preg_match('/^lfn_[a-f0-9]{48}$/D', $nonce) !== 1
            || $action === ''
            || strlen($action) > 180
        ) {
            throw new \InvalidArgumentException('El formulario expiro o ya fue utilizado.');
        }

        $rows = $this->fetchAll(
            'UPDATE loyalty_portal_form_nonces n
             SET consumed_at = NOW()
             FROM loyalty_portal_sessions s
             WHERE n.tenant_id = :tenant_id
               AND n.session_id = s.id
               AND s.tenant_id = n.tenant_id
               AND s.token_hash = :token_hash
               AND s.exchanged_at IS NOT NULL
               AND s.revoked_at IS NULL
               AND s.expires_at > NOW()
               AND n.action = :action
               AND n.nonce_hash = :nonce_hash
               AND n.consumed_at IS NULL
               AND n.expires_at > NOW()
             RETURNING n.id',
            [
                'tenant_id' => $this->tenantId(),
                'token_hash' => hash('sha256', $token),
                'action' => $action,
                'nonce_hash' => hash('sha256', $nonce),
            ]
        );
        if ($rows === []) {
            throw new \InvalidArgumentException('El formulario expiro o ya fue utilizado.');
        }
    }

    private function portalAccessPath(): string {
        return '/api/l/access';
    }

    /**
     * El enlace de Wallet es una URL web normal y no prueba que el clic venga
     * desde la Wallet del titular. Por eso este endpoint legacy nunca debe
     * entregar un portal privado ni reenviar el token del socio.
     */
    public function publicCatalogRedirect(string $token): string {
        $configured = trim((string)($_ENV['LOYALTY_CATALOG_URL'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        throw new \InvalidArgumentException('El catalogo privado ya no esta disponible desde Google Wallet.');
    }

    private function portalAccessUrl(?string $accountId = null): string {
        $path = $this->portalAccessPath();
        $accountId = trim((string)$accountId);
        if ($accountId !== '') {
            $path .= '?account=' . rawurlencode($accountId);
        }

        return $this->publicUrlForPath($path);
    }

    private function publicUrlForPath(string $path): string {
        $path = $this->publicGatewayPath($path);
        $base = rtrim($this->walletPublicBaseUrl(), '/');
        if ($base === '') {
            return $path;
        }

        return $base . '/' . ltrim($path, '/');
    }

    private function walletPublicBaseUrl(): string {
        $override = trim((string)($_ENV['LOYALTY_WALLET_PUBLIC_BASE_URL'] ?? ''));
        if ($override !== '') {
            return $override;
        }

        $walletSettings = $this->settings()['settings']['googleWallet'] ?? [];
        $origins = is_array($walletSettings['origins'] ?? null) ? $walletSettings['origins'] : [];
        foreach ($origins as $origin) {
            $origin = rtrim(trim((string)$origin), '/');
            if (preg_match('#^https?://#i', $origin) === 1) {
                return $origin;
            }
        }

        return (string)(TenantContext::publicBaseUrl() ?? TenantContext::appUrl() ?? '');
    }

    private function publicGatewayPath(string $path): string {
        $path = '/' . ltrim($path, '/');
        if (!str_starts_with($path, '/api')) {
            return $path;
        }

        $tenant = trim((string)($_ENV['PUBLIC_TENANT_SLUG'] ?? TenantContext::slug() ?? $this->tenantId()), '/ ');
        $apiSegment = trim((string)($_ENV['PUBLIC_API_SERVICE_SEGMENT'] ?? 'api'), '/ ');
        if ($tenant === '' || $apiSegment === '') {
            return $path;
        }

        return '/' . $tenant . '/' . $apiSegment . substr($path, 4);
    }

    private function portalSupport(): array {
        $settings = $this->settings()['settings'];
        $program = is_array($settings['program'] ?? null) ? $settings['program'] : [];

        return [
            'email' => trim((string)($program['supportEmail'] ?? '')),
            'phone' => trim((string)($program['supportPhone'] ?? '')),
        ];
    }

    private function memberFromPortalAccessIdentifier(string $identifier): ?array {
        $tenantId = $this->tenantId();
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        $normalizedEmail = mb_strtolower($identifier);
        $phoneDigits = preg_replace('/\D+/', '', $identifier) ?? '';

        $where = ['m.account_id = :account_id'];
        $params = ['tenant_id' => $tenantId, 'account_id' => $identifier];
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $where[] = 'lower(m.email) = :email';
            $params['email'] = $normalizedEmail;
        }
        if ($phoneDigits !== '') {
            $where[] = "regexp_replace(COALESCE(m.phone, ''), '\\D', '', 'g') = :phone";
            $params['phone'] = $phoneDigits;
        }

        $rows = $this->fetchAll(
            'SELECT m.*, COALESCE(a.balance, 0) AS points, COALESCE(a.points_debt, 0) AS points_debt
             FROM loyalty_members m
             LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
             WHERE m.tenant_id = :tenant_id AND (' . implode(' OR ', $where) . ')
             ORDER BY m.updated_at DESC
             LIMIT 1',
            $params
        );

        return $rows[0] ?? null;
    }

    private function sendPortalOtpEmail(array $member, string $recipient, string $code, string $expiresAt): bool {
        $program = $this->program($this->tenantId());
        $programName = trim((string)($program['wallet_program_name'] ?? $program['name'] ?? 'Fidepuntos')) ?: 'Fidepuntos';
        $memberName = trim((string)($member['account_name'] ?? $member['name'] ?? ''));
        $safeProgram = htmlspecialchars($programName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMember = htmlspecialchars($memberName !== '' ? $memberName : 'Socio', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $expiresLabel = htmlspecialchars(date('H:i', strtotime($expiresAt)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = "Codigo para entrar al catalogo {$programName}";
        $html = <<<HTML
<!doctype html>
<html lang="es">
  <body style="margin:0;background:#f3f7fa;font-family:Arial,sans-serif;color:#172a3d;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f7fa;padding:28px 12px;">
      <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#ffffff;border:1px solid #d9e5ee;border-radius:14px;overflow:hidden;">
          <tr><td style="background:#17324a;color:#ffffff;padding:22px;">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;opacity:.78;">{$safeProgram}</div>
            <h1 style="margin:8px 0 0;font-size:22px;line-height:1.25;">Codigo de acceso</h1>
          </td></tr>
          <tr><td style="padding:24px;">
            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;">Hola {$safeMember}, usa este codigo para entrar al catalogo de premios.</p>
            <div style="text-align:center;font-size:34px;letter-spacing:8px;font-weight:900;background:#f7fafe;border:1px solid #d9e5ee;border-radius:12px;padding:16px;margin:18px 0;">{$safeCode}</div>
            <p style="margin:0;color:#50657a;font-size:14px;line-height:1.5;">Expira a las {$expiresLabel}. Si no solicitaste este codigo, ignora este mensaje.</p>
          </td></tr>
        </table>
      </td></tr>
    </table>
  </body>
</html>
HTML;
        $plain = "Hola {$memberName},\n\nTu codigo para entrar al catalogo de premios de {$programName} es: {$code}\nExpira a las {$expiresLabel}.\n\nSi no solicitaste este codigo, ignora este mensaje.\n";
        $stored = "OTP de acceso al catalogo emitido para cuenta " . (string)($member['account_id'] ?? '') . ". Codigo omitido.";

        return MailService::sendHtml($recipient, $subject, $html, $plain, null, null, [
            'module' => 'loyalty-points',
            'template' => 'loyalty-portal-access-otp',
            'member_id' => (string)($member['id'] ?? ''),
            'account_id' => (string)($member['account_id'] ?? ''),
        ], $stored);
    }

    private function portalOtpHash(string $challengeId, string $code): string {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        return hash_hmac('sha256', $this->tenantId() . '|portal-otp|' . $challengeId . '|' . $code, $this->loyaltyPortalSecret());
    }

    private function expirePortalOtpChallenges(): void {
        $this->execute(
            "UPDATE loyalty_portal_otp_challenges
             SET updated_at = NOW()
             WHERE tenant_id = :tenant_id
               AND consumed_at IS NULL
               AND expires_at < NOW()",
            ['tenant_id' => $this->tenantId()]
        );
    }

    private function maskEmail(string $email): string {
        $email = mb_strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return 'correo registrado';
        }
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 2);
        return $visible . str_repeat('*', max(2, mb_strlen($local) - 2)) . '@' . $domain;
    }

    private function loyaltyPortalSecret(): string {
        $secret = trim((string)($_ENV['LOYALTY_PORTAL_SECRET'] ?? ''));
        if ($secret === '') {
            $secret = trim((string)($_ENV['LOYALTY_WALLET_QR_SECRET'] ?? ''));
        }
        if ($secret === '') {
            $secret = trim((string)($_ENV['JWT_SECRET'] ?? ''));
        }
        if ($secret === '') {
            throw new \RuntimeException('Configura LOYALTY_PORTAL_SECRET, LOYALTY_WALLET_QR_SECRET o JWT_SECRET para firmar el portal de premios.');
        }

        return $secret;
    }

    private function normalizeRewardClaimPayload(array $payload, ?array $before = null): array {
        $mode = $this->normalizeClaimMode((string)($payload['claimMode'] ?? $payload['claim_mode'] ?? $before['claim_mode'] ?? self::CLAIM_MODE_STAFF_ONLY));
        $instructions = trim((string)($payload['claimInstructions'] ?? $payload['claim_instructions'] ?? $before['claim_instructions'] ?? ''));
        $options = $payload['claimDeliveryOptions'] ?? $payload['claim_delivery_options'] ?? $before['claim_delivery_options'] ?? [];
        $options = $mode === self::CLAIM_MODE_MANAGED ? $this->normalizeDeliveryOptions($options) : [];
        if ($mode === self::CLAIM_MODE_MANAGED && $options === []) {
            $options = ['pickup'];
        }

        return [
            'claim_mode' => $mode,
            'claim_instructions' => $instructions,
            'claim_delivery_options' => $options,
        ];
    }

    private function normalizeRewardImageUrl($value): string {
        $url = trim((string)$value);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        if (str_starts_with($url, '/api/l/reward-images/') || str_starts_with($url, '/uploads/')) {
            return $url;
        }

        throw new \InvalidArgumentException('La imagen del premio debe venir del cargador seguro.');
    }

    private function normalizeClaimMode(string $mode): string {
        $mode = strtolower(trim($mode));
        if (in_array($mode, [self::CLAIM_MODE_STAFF_ONLY, self::CLAIM_MODE_IN_STORE, self::CLAIM_MODE_MANAGED], true)) {
            return $mode;
        }

        return self::CLAIM_MODE_STAFF_ONLY;
    }

    private function normalizeDeliveryOptions($options): array {
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [$options];
        }
        if (!is_array($options)) {
            return [];
        }
        $normalized = [];
        foreach ($options as $option) {
            $value = strtolower(trim((string)$option));
            if (in_array($value, ['pickup', 'delivery'], true) && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    private function portalFulfillmentType(string $claimMode, array $payload, array $deliveryOptions): string {
        if ($claimMode === self::CLAIM_MODE_IN_STORE) {
            return 'in_store';
        }
        $requested = strtolower(trim((string)($payload['fulfillmentType'] ?? $payload['fulfillment_type'] ?? '')));
        if (in_array($requested, $deliveryOptions, true)) {
            return $requested;
        }
        if ($deliveryOptions !== []) {
            return (string)$deliveryOptions[0];
        }

        return 'pickup';
    }

    private function claimCode(): string {
        return (string)random_int(100000, 999999);
    }

    private function claimCodeHash(string $code): string {
        return hash_hmac('sha256', preg_replace('/\D+/', '', $code) ?? '', $this->loyaltyPortalSecret());
    }

    private function isClaimCodeCollision(\PDOException $exception): bool {
        $detail = (string)($exception->errorInfo[2] ?? $exception->getMessage());

        return $exception->getCode() === '23505'
            && str_contains($detail, 'loyalty_redemptions_active_validation_code_uidx');
    }

    private function futureTimestamp(int $seconds): string {
        return date('Y-m-d H:i:s', time() + $seconds);
    }

    private function tierMultiplier(string $tierName): string {
        foreach ($this->tierRules($this->tenantId()) as $tier) {
            if (strcasecmp((string)$tier['name'], $tierName) === 0) {
                return DecimalMath::factor($tier['multiplier'] ?? '1', 'multiplicador de nivel');
            }
        }

        return '1.0000';
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
                 VALUES (:tenant_id, :program_id, :settings)
                 ON CONFLICT (tenant_id) DO NOTHING',
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
                        (:id, :tenant_id, :program_id, :name, :min_lifetime_points, :max_lifetime_points, :multiplier, :benefits, :sort_order)
                     ON CONFLICT (tenant_id, name) DO NOTHING',
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

    private function normalizeSettingsIntegers(array $settings): array {
        $integerFields = [
            ['earning', 'maximumPointsPerPurchase', 'maximo de puntos por compra'],
            ['earning', 'maximumPointsPerMemberPerDay', 'maximo diario de puntos por socio'],
            ['redemption', 'maximumRedemptionsPerMemberPerDay', 'maximo diario de canjes por socio'],
            ['redemption', 'maximumSameRewardPerMemberPerDay', 'maximo diario del mismo premio'],
            ['redemption', 'manualApprovalThresholdPoints', 'umbral de aprobacion manual'],
            ['redemption', 'minimumRewardStockAlert', 'alerta minima de stock'],
            ['expiration', 'pointsExpireAfterDays', 'dias para expiracion'],
            ['expiration', 'warningDays', 'dias de aviso de expiracion'],
            ['security', 'auditRetentionDays', 'dias de retencion de auditoria'],
            ['security', 'riskBlockThreshold', 'umbral antifraude'],
        ];

        foreach ($integerFields as [$section, $key, $label]) {
            if (!array_key_exists($key, $settings[$section] ?? [])) {
                continue;
            }
            $settings[$section][$key] = $this->strictNonNegativeInteger($settings[$section][$key], $label);
        }

        return $settings;
    }

    private function strictNonNegativeInteger(mixed $value, string $field): int {
        if (is_int($value)) {
            $normalized = $value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            if (strlen($value) > 10 || (strlen($value) === 10 && strcmp($value, (string)self::POSTGRES_INTEGER_MAX) > 0)) {
                throw new \InvalidArgumentException("{$field} excede el limite entero permitido.");
            }
            $normalized = (int)$value;
        } else {
            throw new \InvalidArgumentException("{$field} debe ser un entero decimal canonico no negativo.");
        }

        if ($normalized < 0 || $normalized > self::POSTGRES_INTEGER_MAX) {
            throw new \InvalidArgumentException("{$field} debe estar entre 0 y " . self::POSTGRES_INTEGER_MAX . '.');
        }

        return $normalized;
    }

    private function validateSettings(array $settings): void {
        $earning = $settings['earning'] ?? [];
        DecimalMath::factor($earning['pointsPerUnit'] ?? null, 'puntos por unidad');
        DecimalMath::factor($earning['amountPerUnit'] ?? null, 'monto por unidad');
        if (!in_array((string)($earning['roundingMode'] ?? 'floor'), ['floor', 'round', 'half-up', 'ceil'], true)) {
            throw new \InvalidArgumentException('El redondeo debe ser hacia abajo, normal o hacia arriba.');
        }
        DecimalMath::nonNegativeMoney($earning['minimumPurchaseAmount'] ?? 0, 'monto minimo');
        $maximumPointsPerPurchase = $this->strictNonNegativeInteger(
            $earning['maximumPointsPerPurchase'] ?? 0,
            'maximo de puntos por compra'
        );
        if ($maximumPointsPerPurchase < 1) {
            throw new \InvalidArgumentException('El maximo de puntos por compra debe ser mayor a cero.');
        }
        $this->strictNonNegativeInteger($earning['maximumPointsPerMemberPerDay'] ?? 0, 'maximo diario de puntos por socio');

        $redemption = $settings['redemption'] ?? [];
        $maxDailyRedemptions = $this->strictNonNegativeInteger(
            $redemption['maximumRedemptionsPerMemberPerDay'] ?? 0,
            'maximo diario de canjes por socio'
        );
        $maxSameReward = $this->strictNonNegativeInteger(
            $redemption['maximumSameRewardPerMemberPerDay'] ?? 0,
            'maximo diario del mismo premio'
        );
        if ($maxDailyRedemptions < 1 || $maxSameReward < 1) {
            throw new \InvalidArgumentException('Los limites diarios de canje deben ser mayores a cero.');
        }
        if ($maxSameReward > $maxDailyRedemptions) {
            throw new \InvalidArgumentException('El limite del mismo premio no puede superar el total diario de canjes.');
        }
        $this->strictNonNegativeInteger($redemption['manualApprovalThresholdPoints'] ?? 0, 'umbral de aprobacion manual');
        $this->strictNonNegativeInteger($redemption['minimumRewardStockAlert'] ?? 0, 'alerta minima de stock');

        $expiration = $settings['expiration'] ?? [];
        $this->strictNonNegativeInteger($expiration['pointsExpireAfterDays'] ?? 0, 'dias para expiracion');
        $this->strictNonNegativeInteger($expiration['warningDays'] ?? 0, 'dias de aviso de expiracion');
        if (filter_var($expiration['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new \InvalidArgumentException('La expiracion de puntos no puede activarse hasta disponer del motor FIFO completo.');
        }

        $security = $settings['security'] ?? [];
        $auditRetentionDays = $this->strictNonNegativeInteger($security['auditRetentionDays'] ?? 0, 'dias de retencion de auditoria');
        if ($auditRetentionDays < 30) {
            throw new \InvalidArgumentException('La retencion minima de auditoria es 30 dias.');
        }
        $riskBlockThreshold = $this->strictNonNegativeInteger($security['riskBlockThreshold'] ?? 0, 'umbral antifraude');
        if ($riskBlockThreshold < 1) {
            throw new \InvalidArgumentException('El umbral antifraude debe ser mayor a cero.');
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
                throw new \InvalidArgumentException('googleWallet.hexBackgroundColor debe ser un color hex de 6 digitos, por ejemplo #2b648f.');
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
                'actor_type' => str_starts_with((string)$userId, 'api:')
                    ? 'api'
                    : (str_starts_with((string)$userId, 'customer:') ? 'customer' : ((string)$userId === 'system' ? 'system' : 'dashboard')),
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
        if ($memberId !== null && in_array($severity, ['high', 'critical'], true)) {
            $this->autoBlockMemberForRiskThreshold($memberId);
        }
    }

    private function autoBlockMemberForRiskThreshold(string $memberId): void {
        $settings = $this->settings()['settings'];
        $threshold = (int)($settings['security']['riskBlockThreshold'] ?? 0);
        if ($threshold < 1) {
            return;
        }

        $tenantId = $this->tenantId();
        $openHighRiskEvents = (int)$this->scalar(
            "SELECT COUNT(*)
             FROM loyalty_risk_events
             WHERE tenant_id = :tenant_id
               AND member_id = :member_id
               AND status = 'open'
               AND severity IN ('high', 'critical')",
            ['tenant_id' => $tenantId, 'member_id' => $memberId]
        );
        if ($openHighRiskEvents < $threshold) {
            return;
        }

        $before = $this->memberById($memberId);
        if (!$before || (string)($before['status'] ?? '') === 'blocked') {
            return;
        }

        $reason = sprintf('Bloqueo automatico por %d eventos de riesgo alto abiertos.', $openHighRiskEvents);
        $this->execute(
            'UPDATE loyalty_members
             SET status = :status,
                 blocked_reason = :blocked_reason,
                 blocked_at = COALESCE(blocked_at, NOW()),
                 updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id',
            [
                'status' => 'blocked',
                'blocked_reason' => $reason,
                'tenant_id' => $tenantId,
                'id' => $memberId,
            ]
        );
        $after = $this->memberById($memberId) ?? [];
        $this->recordAudit('member.auto_blocked_by_risk', 'member', $memberId, $before, $after, $reason, 'system');
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
        $exactAccountId = trim((string)($filters['account_id_exact'] ?? ''));
        if ($exactAccountId !== '') {
            $params['account_id_exact'] = $exactAccountId;
            $where .= ' AND m.account_id = :account_id_exact';
        }
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
            'SELECT m.*, COALESCE(a.balance, 0) AS points, COALESCE(a.points_debt, 0) AS points_debt
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
                    r.points_cost, r.status, r.source, r.fulfillment_type, r.code_expires_at,
                    r.expires_at, r.resolved_at, r.resolved_by_user_id, r.resolution_note,
                    r.metadata, r.created_at, r.updated_at
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.id = r.member_id AND m.tenant_id = r.tenant_id
             JOIN loyalty_rewards w ON w.id = r.reward_id AND w.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant_id AND r.id = :id
             LIMIT 1",
            ['tenant_id' => $this->tenantId(), 'id' => $redemptionId]
        );

        return $rows[0] ?? [];
    }

    private function redemptionForUpdate(string $redemptionId): array {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('El bloqueo de solicitud requiere una transaccion activa.');
        }

        $rows = $this->fetchAll(
            "SELECT r.id, r.member_id, m.account_name AS customer, r.reward_id, w.name AS reward,
                    r.points_cost, r.status, r.source, r.fulfillment_type, r.code_expires_at,
                    r.expires_at, r.resolved_at, r.resolved_by_user_id, r.resolution_note,
                    r.metadata, r.created_at, r.updated_at
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.id = r.member_id AND m.tenant_id = r.tenant_id
             JOIN loyalty_rewards w ON w.id = r.reward_id AND w.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant_id AND r.id = :id
             LIMIT 1
             FOR UPDATE OF r",
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
                (:id, :tenant_id, :name, :status, :points_per_currency, :currency_code, :wallet_issuer_name, :wallet_program_name, :brand_color, :logo_url)
             ON CONFLICT DO NOTHING',
            [
                'id' => $programId,
                'tenant_id' => $tenantId,
                'name' => TenantContext::name() ?: 'Programa de fidelizacion',
                'status' => 'active',
                'points_per_currency' => 1,
                'currency_code' => 'USD',
                'wallet_issuer_name' => 'TECNOLTS',
                'wallet_program_name' => TenantContext::name() ?: 'Programa de fidelizacion',
                'brand_color' => '#1D4ED8',
                'logo_url' => '',
            ]
        );

        return $this->program($tenantId);
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

    private function sessionAdvisoryLock(string $key): void {
        $stmt = $this->pdo->prepare('SELECT pg_advisory_lock(hashtextextended(:lock_key, 0))');
        $stmt->execute(['lock_key' => $key]);
    }

    private function sessionAdvisoryUnlock(string $key): void {
        $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(:lock_key, 0))');
        $stmt->execute(['lock_key' => $key]);
    }

    private function transactionAdvisoryLock(string $scope, string $key): void {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('El bloqueo transaccional requiere una transaccion activa.');
        }

        $stmt = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))');
        $stmt->execute(['lock_key' => implode('|', [$this->tenantId(), $scope, $key])]);
    }

    private function normalizeRow(array $row): array {
        foreach ($row as $key => $value) {
            if (is_string($value) && in_array($key, ['metadata', 'last_payload', 'settings', 'benefits', 'scopes', 'before_state', 'after_state', 'response_payload', 'claim_delivery_options'], true)) {
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
        $tenantId = TenantContext::id() ?: TenantContext::slug();
        if (!is_string($tenantId) || trim($tenantId) === '') {
            throw new \RuntimeException('Tenant Loyalty no resuelto.');
        }

        return trim($tenantId);
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
                 AND COALESCE(p.status, '') NOT IN ('inactive', 'revoked', 'deleted')
                LEFT JOIN loyalty_point_accounts a
                  ON a.member_id = m.id AND a.tenant_id = m.tenant_id
                WHERE m.tenant_id = :tenant_id";

        $type = (string)($filter['audience_type'] ?? 'segment');
        $wallet = strtolower(trim((string)($filter['wallet'] ?? $filter['wallet_platform'] ?? 'all')));
        if (in_array($wallet, ['google', 'apple', 'none'], true)) {
            $sql .= ' AND m.wallet_platform = :wallet';
            $params['wallet'] = $wallet;
        }

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
                throw new LoyaltyResourceNotFoundException('Socio no encontrado.');
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
        $wallet = strtolower(trim((string)($payload['wallet'] ?? $payload['wallet_platform'] ?? 'all')));
        if (in_array($wallet, ['all', 'google', 'apple', 'none'], true)) {
            $filter['wallet'] = $wallet;
        }
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
                    status, total_recipients, sent_count, failed_count, skipped_count, delivery_unknown_count,
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
                    status, total_recipients, sent_count, failed_count, skipped_count, delivery_unknown_count,
                    created_at, started_at, finished_at
             FROM loyalty_wallet_campaigns
             WHERE tenant_id = :tenant_id AND id = :id LIMIT 1',
            ['tenant_id' => $this->tenantId(), 'id' => $campaignId]
        );
        if ($rows === []) {
            throw new LoyaltyResourceNotFoundException('Campaña no encontrada.');
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
            'delivery_unknown_count' => (int)($row['delivery_unknown_count'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
        ];
    }
}
