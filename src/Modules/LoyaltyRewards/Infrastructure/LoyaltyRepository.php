<?php

namespace App\Modules\LoyaltyRewards\Infrastructure;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use PDO;

final class LoyaltyRepository {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
        $this->ensureSchema();
        $this->ensureDemoData($this->tenantId());
    }

    public function dashboard(): array {
        $tenantId = $this->tenantId();
        $program = $this->program($tenantId);

        $metrics = [
            'activeMembers' => (int)$this->scalar(
                'SELECT COUNT(*) FROM loyalty_members WHERE tenant_id = :tenant_id AND status = :status',
                ['tenant_id' => $tenantId, 'status' => 'active']
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
            'topCustomers' => $this->fetchAll(
                "SELECT m.id, m.account_name AS name, m.email, m.tier, m.wallet_platform AS wallet_platform,
                        COALESCE(a.balance, 0) AS points, m.last_activity_at
                 FROM loyalty_members m
                 LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
                 WHERE m.tenant_id = :tenant_id
                 ORDER BY COALESCE(a.balance, 0) DESC, m.last_activity_at DESC NULLS LAST
                 LIMIT 8",
                ['tenant_id' => $tenantId]
            ),
            'recentConsumptions' => $this->fetchAll(
                "SELECT l.id, l.member_id, m.account_name AS customer, l.reference AS invoice_number,
                        l.points, l.metadata, l.created_at
                 FROM loyalty_point_ledger l
                 JOIN loyalty_members m ON m.id = l.member_id AND m.tenant_id = l.tenant_id
                 WHERE l.tenant_id = :tenant_id AND l.entry_type = 'purchase'
                 ORDER BY l.created_at DESC
                LIMIT 12",
                ['tenant_id' => $tenantId]
            ),
            'walletSummary' => $this->walletSummary($tenantId),
            'recentRedemptions' => $this->recentRedemptions($tenantId),
            'recommendedActions' => $this->recommendedActions($tenantId),
            'analytics' => $this->dashboardAnalytics($tenantId, $metrics),
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

        $total = (int)$this->scalar(
            "SELECT COUNT(*)
             FROM loyalty_members m
             LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
             WHERE {$where}",
            $params
        );

        $items = $this->fetchAll(
            "SELECT m.id, m.account_name AS name, m.account_id, m.email, m.phone, m.tier, m.status,
                    m.wallet_platform, COALESCE(a.balance, 0) AS points, m.last_activity_at, m.created_at
             FROM loyalty_members m
             LEFT JOIN loyalty_point_accounts a ON a.member_id = m.id AND a.tenant_id = m.tenant_id
             WHERE {$where}
             ORDER BY {$orderBy}
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total,
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

    public function rewards(): array {
        return $this->fetchAll(
            "SELECT id, name, description, points_cost, stock, status, image_url, metadata, created_at, updated_at
             FROM loyalty_rewards
             WHERE tenant_id = :tenant_id
             ORDER BY status ASC, points_cost ASC, name ASC",
            ['tenant_id' => $this->tenantId()]
        );
    }

    public function createReward(array $payload): array {
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

        $stmt = $this->pdo->prepare(
            'INSERT INTO loyalty_rewards (id, tenant_id, name, description, points_cost, stock, status, image_url, metadata)
             VALUES (:id, :tenant_id, :name, :description, :points_cost, :stock, :status, :image_url, :metadata)'
        );
        $stmt->execute($reward);

        return $this->rewardById($reward['id']);
    }

    public function registerPurchase(array $payload, ?string $userId = null): array {
        $tenantId = $this->tenantId();
        $amount = round((float)($payload['invoiceAmount'] ?? $payload['amount'] ?? 0), 2);
        $invoiceNumber = trim((string)($payload['invoiceNumber'] ?? $payload['invoice_number'] ?? ''));
        if ($amount <= 0 || $invoiceNumber === '') {
            throw new \InvalidArgumentException('Monto de factura y numero de factura son obligatorios.');
        }

        $member = $this->resolveMember($tenantId, $payload);
        $program = $this->program($tenantId);
        $points = max(1, (int)round($amount * (float)($program['points_per_currency'] ?? 1)));
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
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :metadata, :created_by_user_id)',
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
                    'metadata' => json_encode([
                        'invoiceAmount' => $amount,
                        'invoiceNumber' => $invoiceNumber,
                    ]),
                    'created_by_user_id' => $userId,
                ]
            );
            $this->execute(
                'UPDATE loyalty_members SET last_activity_at = NOW(), updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $member['id']]
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'member' => $this->memberById($member['id']),
            'pointsEarned' => $points,
            'balanceAfter' => $balanceAfter,
            'invoiceNumber' => $invoiceNumber,
            'invoiceAmount' => $amount,
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
        if (($reward['status'] ?? '') !== 'active') {
            throw new \InvalidArgumentException('El premio no esta activo.');
        }
        if ((int)($reward['stock'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('El premio no tiene stock disponible.');
        }

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
                    (id, tenant_id, member_id, reward_id, points_cost, status, metadata)
                 VALUES
                    (:id, :tenant_id, :member_id, :reward_id, :points_cost, :status, :metadata)',
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
                ]
            );
            $this->execute(
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, metadata, created_by_user_id)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :metadata, :created_by_user_id)',
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
                    'metadata' => json_encode(['rewardId' => $rewardId, 'rewardName' => $reward['name']]),
                    'created_by_user_id' => $userId,
                ]
            );
            $this->execute(
                'UPDATE loyalty_members SET last_activity_at = NOW(), updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id',
                ['tenant_id' => $tenantId, 'id' => $memberId]
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

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
                            'external_object_id' => sprintf('%s.%s', $tenantId, $member['account_id'] ?? $memberId),
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
                            'external_object_id' => sprintf('%s.%s', $tenantId, $member['account_id'] ?? $memberId),
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

    public function googleWalletLinkPlan(array $payload): array {
        $memberId = trim((string)($payload['memberId'] ?? $payload['member_id'] ?? ''));
        $member = $memberId !== '' ? $this->memberById($memberId) : null;
        if (!$member) {
            throw new \RuntimeException('Socio no encontrado para generar pase.');
        }

        return [
            'configured' => false,
            'status' => 'requires-wallet-issuer',
            'member' => $member,
            'todo' => [
                'Configure ISSUER_ID y service-account.json en la herramienta demo.',
                'Promueva la firma JWT a servicio backend cuando el issuer de Google Wallet este aprobado.',
            ],
            'script' => 'backend/tools/loyalty-google-wallet-demo/generate-google-wallet-link.js',
        ];
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

    private function recentRedemptions(string $tenantId): array {
        return $this->fetchAll(
            "SELECT r.id, r.member_id, m.account_name AS customer, r.reward_id, w.name AS reward,
                    r.points_cost, r.status, r.metadata, r.created_at
             FROM loyalty_redemptions r
             JOIN loyalty_members m ON m.id = r.member_id AND m.tenant_id = r.tenant_id
             JOIN loyalty_rewards w ON w.id = r.reward_id AND w.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant_id
             ORDER BY r.created_at DESC
             LIMIT 10",
            ['tenant_id' => $tenantId]
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

    private function dashboardAnalytics(string $tenantId, array $metrics): array {
        $activeMembers = max(0, (int)($metrics['activeMembers'] ?? 0));
        $withDigitalCard = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_members
             WHERE tenant_id = :tenant_id
               AND status = 'active'
               AND wallet_platform IN ('google', 'apple')",
            ['tenant_id' => $tenantId]
        );
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
            'activityTrend' => $this->activityTrend($tenantId),
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
        ];
    }

    private function activityTrend(string $tenantId): array {
        return $this->fetchAll(
            "WITH days AS (
                SELECT generate_series(CURRENT_DATE - INTERVAL '13 days', CURRENT_DATE, INTERVAL '1 day')::date AS day
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

        return round(($value / $total) * 100, 1);
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
            ['Maria Hidalgo', 'maria.hidalgo@example.com', 'FID-1001', 'Oro', 'google', 12450, 149.90],
            ['Carlos Mejia', 'carlos.mejia@example.com', 'FID-1002', 'Plata', 'apple', 8120, 82.40],
            ['Ana Salazar', 'ana.salazar@example.com', 'FID-1003', 'Bronce', 'none', 3680, 45.10],
            ['Daniel Castro', 'daniel.castro@example.com', 'FID-1004', 'Plata', 'google', 5940, 63.75],
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
                    'reference' => 'FAC-DEMO-' . substr($accountId, -4),
                    'source' => 'demo',
                    'metadata' => json_encode(['invoiceAmount' => $amount, 'invoiceNumber' => 'FAC-DEMO-' . substr($accountId, -4)]),
                ]
            );
        }

        foreach ([
            ['Descuento $10', 'Cupon aplicable en la siguiente compra.', 1000, 25],
            ['Envio gratis', 'Envio sin costo en zona configurada.', 450, 100],
            ['Producto sorpresa', 'Premio promocional definido por el comercio.', 1800, 12],
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
            ['Lucia Paredes', 'lucia.paredes@example.com', 'FID-1005', 'Oro', 'apple', 15320, 120.30],
            ['Jorge Andrade', 'jorge.andrade@example.com', 'FID-1006', 'Bronce', 'none', 980, 18.75],
            ['Paola Vera', 'paola.vera@example.com', 'FID-1007', 'Plata', 'google', 6420, 70.25],
            ['Rafael Cardenas', 'rafael.cardenas@example.com', 'FID-1008', 'Bronce', 'none', 240, 12.00],
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
                    'reference' => 'FAC-DEMO-' . substr($accountId, -4),
                    'source' => 'demo',
                    'metadata' => json_encode(['invoiceAmount' => $amount, 'invoiceNumber' => 'FAC-DEMO-' . substr($accountId, -4)]),
                ]
            );
        }

        foreach ([
            ['Cafe gratis', 'Beneficio para punto fisico o cafeteria aliada.', 300, 50],
            ['Upgrade VIP', 'Sube al socio a beneficios VIP por 30 dias.', 2500, 8],
            ['Cashback $5', 'Credito comercial aplicable en la siguiente factura.', 700, 40],
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
            $this->seedDemoRedemption($tenantId, 'FID-1001', 'Envio gratis');
            $this->seedDemoRedemption($tenantId, 'FID-1002', 'Cafe gratis');
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
        if ($this->schemaExists()) {
            return;
        }

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
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS loyalty_members_tenant_search_idx ON loyalty_members (tenant_id, lower(account_name), lower(email))');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS loyalty_ledger_tenant_created_idx ON loyalty_point_ledger (tenant_id, created_at DESC)');
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
            if (is_string($value) && in_array($key, ['metadata', 'last_payload'], true)) {
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

    private function tenantId(): string {
        return TenantContext::id() ?: (TenantContext::slug() ?: 'default');
    }

    private function id(string $prefix): string {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }
}
