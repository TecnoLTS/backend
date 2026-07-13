<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyRepository;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltySchema;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

TenantContext::set([
    'id' => 'fidepuntos',
    'slug' => 'fidepuntos',
    'name' => 'Fidepuntos',
]);

$pdo = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
(new LoyaltySchema($pdo))->ensure();
$repository = new LoyaltyRepository($pdo);
$repository->settings();

$tenantId = 'fidepuntos';
$program = fetchOne($pdo, 'SELECT id FROM loyalty_programs WHERE tenant_id = :tenant_id LIMIT 1', ['tenant_id' => $tenantId]);
if (!$program) {
    throw new RuntimeException('No se encontro el programa Fidepuntos.');
}
$programId = (string)$program['id'];

$pdo->beginTransaction();
try {
    clearOperationalData($pdo, $tenantId);

    $rewards = seedRewards($pdo, $tenantId, $programId);
    $members = realisticMembers();
    $summary = [
        'members' => 0,
        'purchases' => 0,
        'redemptions' => 0,
        'walletPasses' => 0,
    ];

    foreach ($members as $member) {
        seedMember($pdo, $tenantId, $programId, $member, $rewards, $summary);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}

echo json_encode([
    'ok' => true,
    'tenant_id' => $tenantId,
    'summary' => $summary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function clearOperationalData(PDO $pdo, string $tenantId): void {
    foreach ([
        'loyalty_point_expirations',
        'loyalty_reversals',
        'loyalty_risk_events',
        'loyalty_audit_events',
        'loyalty_redemptions',
        'loyalty_wallet_passes',
        'loyalty_point_ledger',
        'loyalty_point_accounts',
        'loyalty_rewards',
        'loyalty_members',
        'loyalty_idempotency_keys',
    ] as $table) {
        execute($pdo, "DELETE FROM {$table} WHERE tenant_id = :tenant_id", ['tenant_id' => $tenantId]);
    }
}

function seedRewards(PDO $pdo, string $tenantId, string $programId): array {
    $items = [
        ['Cafe de cortesia', 'Bebida gratis en puntos fisicos o aliados.', 300, 42],
        ['Envio a domicilio sin costo', 'Entrega gratis dentro de la zona configurada.', 450, 86],
        ['Bono de compra $5', 'Credito comercial aplicable en la siguiente factura.', 700, 33],
        ['Bono de compra $10', 'Credito comercial para clientes recurrentes.', 1200, 18],
        ['Producto seleccionado del mes', 'Producto promocional definido por el comercio.', 1800, 11],
        ['Atencion preferente 30 dias', 'Prioridad de atencion para socios frecuentes.', 2500, 7],
        ['Experiencia premium', 'Premio de alto valor para clientes Oro.', 5000, 3],
    ];
    $rewards = [];

    foreach ($items as [$name, $description, $pointsCost, $stock]) {
        $id = id('reward');
        execute(
            $pdo,
            'INSERT INTO loyalty_rewards (id, tenant_id, program_id, name, description, points_cost, stock, status, metadata, created_at, updated_at)
             VALUES (:id, :tenant_id, :program_id, :name, :description, :points_cost, :stock, :status, :metadata, NOW() - INTERVAL \'20 days\', NOW())',
            [
                'id' => $id,
                'tenant_id' => $tenantId,
                'program_id' => $programId,
                'name' => $name,
                'description' => $description,
                'points_cost' => $pointsCost,
                'stock' => $stock,
                'status' => 'active',
                'metadata' => json_encode(['category' => rewardCategory((int)$pointsCost)]),
            ]
        );
        $rewards[$name] = ['id' => $id, 'pointsCost' => (int)$pointsCost];
    }

    return $rewards;
}

function realisticMembers(): array {
    return [
        [
            'accountId' => 'FID-1001',
            'name' => 'Mariana Hidalgo',
            'email' => 'mariana.hidalgo@andesshop.ec',
            'phone' => '099 482 1190',
            'wallet' => 'google',
            'status' => 'active',
            'purchases' => [
                ['2026-06-18 10:42:00', '001-001-000184212', 7420.35, 7420, 'Sucursal Norte'],
                ['2026-06-26 17:18:00', '001-001-000185904', 5350.80, 5351, 'Sucursal Cumbaya'],
                ['2026-07-04 12:05:00', '001-001-000187530', 4910.20, 4910, 'Sucursal Norte'],
            ],
            'redemptions' => [
                ['2026-06-28 16:10:00', 'Bono de compra $10'],
                ['2026-07-04 18:24:00', 'Envio a domicilio sin costo'],
            ],
        ],
        [
            'accountId' => 'FID-1002',
            'name' => 'Carlos Mejia',
            'email' => 'carlos.mejia@marketquito.ec',
            'phone' => '098 771 3204',
            'wallet' => 'apple',
            'status' => 'active',
            'purchases' => [
                ['2026-06-16 09:30:00', '001-002-000092118', 4680.40, 4680, 'Sucursal Centro'],
                ['2026-06-24 14:45:00', '001-002-000092991', 3985.60, 3986, 'Sucursal Centro'],
                ['2026-07-03 19:15:00', '001-001-000187020', 1640.00, 1640, 'Sucursal Norte'],
            ],
            'redemptions' => [
                ['2026-06-25 11:20:00', 'Cafe de cortesia'],
            ],
        ],
        [
            'accountId' => 'FID-1003',
            'name' => 'Ana Salazar',
            'email' => 'ana.salazar@familiasalazar.ec',
            'phone' => '096 130 4498',
            'wallet' => 'none',
            'status' => 'active',
            'purchases' => [
                ['2026-06-19 13:12:00', '001-001-000184508', 320.90, 321, 'Sucursal Norte'],
                ['2026-07-02 16:40:00', '001-002-000093604', 415.25, 415, 'Sucursal Centro'],
            ],
            'redemptions' => [],
        ],
        [
            'accountId' => 'FID-1004',
            'name' => 'Daniel Castro',
            'email' => 'daniel.castro@castroretail.ec',
            'phone' => '099 601 5531',
            'wallet' => 'google',
            'status' => 'active',
            'purchases' => [
                ['2026-06-21 15:22:00', '001-003-000041872', 3040.75, 3041, 'Sucursal Sur'],
                ['2026-06-30 18:03:00', '001-003-000042110', 2480.10, 2480, 'Sucursal Sur'],
                ['2026-07-05 10:08:00', '001-001-000187884', 1265.00, 1265, 'Sucursal Norte'],
            ],
            'redemptions' => [
                ['2026-07-01 10:00:00', 'Bono de compra $5'],
            ],
        ],
        [
            'accountId' => 'FID-1005',
            'name' => 'Lucia Paredes',
            'email' => 'lucia.paredes@paredesstore.ec',
            'phone' => '097 220 8045',
            'wallet' => 'apple',
            'status' => 'active',
            'purchases' => [
                ['2026-06-17 12:14:00', '001-002-000092330', 6120.00, 6120, 'Sucursal Centro'],
                ['2026-06-26 20:35:00', '001-001-000185932', 7350.30, 7350, 'Sucursal Norte'],
                ['2026-07-04 13:44:00', '001-002-000094002', 4180.60, 4181, 'Sucursal Centro'],
            ],
            'redemptions' => [
                ['2026-06-27 09:18:00', 'Producto seleccionado del mes'],
            ],
        ],
        [
            'accountId' => 'FID-1006',
            'name' => 'Jorge Andrade',
            'email' => 'jorge.andrade@andradehogar.ec',
            'phone' => '095 884 6612',
            'wallet' => 'none',
            'status' => 'active',
            'purchases' => [
                ['2026-06-23 11:09:00', '001-003-000041996', 190.75, 191, 'Sucursal Sur'],
                ['2026-07-01 17:58:00', '001-001-000186902', 260.20, 260, 'Sucursal Norte'],
            ],
            'redemptions' => [],
        ],
        [
            'accountId' => 'FID-1007',
            'name' => 'Paola Vera',
            'email' => 'paola.vera@veracompras.ec',
            'phone' => '098 411 7750',
            'wallet' => 'google',
            'status' => 'active',
            'purchases' => [
                ['2026-06-15 16:50:00', '001-001-000183880', 2920.25, 2920, 'Sucursal Norte'],
                ['2026-06-29 12:28:00', '001-002-000093221', 3430.00, 3430, 'Sucursal Centro'],
                ['2026-07-05 09:35:00', '001-003-000042460', 1975.45, 1975, 'Sucursal Sur'],
            ],
            'redemptions' => [
                ['2026-07-05 10:20:00', 'Envio a domicilio sin costo'],
            ],
        ],
        [
            'accountId' => 'FID-1008',
            'name' => 'Rafael Cardenas',
            'email' => 'rafael.cardenas@cardenas.ec',
            'phone' => '096 905 1127',
            'wallet' => 'none',
            'status' => 'inactive',
            'purchases' => [
                ['2026-05-22 18:30:00', '001-002-000089820', 120.00, 120, 'Sucursal Centro'],
            ],
            'redemptions' => [],
        ],
        [
            'accountId' => 'FID-1009',
            'name' => 'Valeria Molina',
            'email' => 'valeria.molina@molinafamilia.ec',
            'phone' => '099 318 4472',
            'wallet' => 'apple',
            'status' => 'active',
            'purchases' => [
                ['2026-06-20 10:05:00', '001-001-000184650', 860.00, 860, 'Sucursal Norte'],
                ['2026-07-04 19:02:00', '001-003-000042410', 520.80, 521, 'Sucursal Sur'],
            ],
            'redemptions' => [
                ['2026-07-04 19:30:00', 'Cafe de cortesia'],
            ],
        ],
        [
            'accountId' => 'FID-1010',
            'name' => 'Santiago Rivas',
            'email' => 'santiago.rivas@rivas.ec',
            'phone' => '097 411 9033',
            'wallet' => 'google',
            'status' => 'blocked',
            'blockedReason' => 'Revision por canjes repetidos en mostrador.',
            'purchases' => [
                ['2026-06-12 13:55:00', '001-003-000041540', 715.25, 715, 'Sucursal Sur'],
                ['2026-06-22 13:35:00', '001-003-000041940', 410.00, 410, 'Sucursal Sur'],
            ],
            'redemptions' => [],
        ],
        [
            'accountId' => 'FID-1011',
            'name' => 'Camila Torres',
            'email' => 'camila.torres@torres.ec',
            'phone' => '098 205 7421',
            'wallet' => 'apple',
            'status' => 'active',
            'purchases' => [
                ['2026-06-25 11:42:00', '001-001-000185701', 8200.00, 8200, 'Sucursal Norte'],
                ['2026-07-04 20:16:00', '001-001-000187604', 6500.30, 6500, 'Sucursal Norte'],
            ],
            'redemptions' => [
                ['2026-07-05 09:45:00', 'Atencion preferente 30 dias'],
            ],
        ],
        [
            'accountId' => 'FID-1012',
            'name' => 'Esteban Naranjo',
            'email' => 'esteban.naranjo@naranjo.ec',
            'phone' => '095 731 6408',
            'wallet' => 'none',
            'status' => 'active',
            'purchases' => [
                ['2026-06-27 17:00:00', '001-002-000093100', 310.75, 311, 'Sucursal Centro'],
                ['2026-07-03 15:21:00', '001-002-000093882', 225.90, 226, 'Sucursal Centro'],
            ],
            'redemptions' => [],
        ],
    ];
}

function seedMember(PDO $pdo, string $tenantId, string $programId, array $member, array $rewards, array &$summary): void {
    $memberId = id('member');
    $events = [];
    $lifetime = 0;

    foreach ($member['purchases'] as [$date, $invoice, $amount, $points, $store]) {
        $events[] = [
            'type' => 'purchase',
            'date' => $date,
            'invoice' => $invoice,
            'amount' => (float)$amount,
            'points' => (int)$points,
            'store' => $store,
        ];
        $lifetime += (int)$points;
    }

    foreach ($member['redemptions'] as [$date, $rewardName]) {
        if (!isset($rewards[$rewardName])) {
            continue;
        }
        $events[] = [
            'type' => 'redemption',
            'date' => $date,
            'rewardName' => $rewardName,
            'rewardId' => $rewards[$rewardName]['id'],
            'points' => (int)$rewards[$rewardName]['pointsCost'],
        ];
    }

    usort($events, static fn(array $left, array $right): int => strcmp($left['date'], $right['date']));
    $balance = 0;
    $lastActivity = $events[count($events) - 1]['date'] ?? date('Y-m-d H:i:s');
    $tier = tierForLifetime($lifetime);

    execute(
        $pdo,
        'INSERT INTO loyalty_members
            (id, tenant_id, program_id, account_id, account_name, email, phone, tier, status, wallet_platform, blocked_reason, blocked_at, metadata, last_activity_at, created_at, updated_at)
         VALUES
            (:id, :tenant_id, :program_id, :account_id, :account_name, :email, :phone, :tier, :status, :wallet_platform, :blocked_reason, :blocked_at, :metadata, :last_activity_at, :created_at, NOW())',
        [
            'id' => $memberId,
            'tenant_id' => $tenantId,
            'program_id' => $programId,
            'account_id' => $member['accountId'],
            'account_name' => $member['name'],
            'email' => $member['email'],
            'phone' => $member['phone'],
            'tier' => $tier,
            'status' => $member['status'],
            'wallet_platform' => $member['wallet'],
            'blocked_reason' => $member['blockedReason'] ?? null,
            'blocked_at' => ($member['status'] ?? '') === 'blocked' ? $lastActivity : null,
            'metadata' => json_encode(['segment' => memberSegment($lifetime), 'source' => 'realistic-demo']),
            'last_activity_at' => $lastActivity,
            'created_at' => '2026-05-10 09:00:00',
        ]
    );

    foreach ($events as $event) {
        if ($event['type'] === 'purchase') {
            $balance += (int)$event['points'];
            execute(
                $pdo,
                'INSERT INTO loyalty_point_ledger
                    (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, metadata, created_at)
                 VALUES
                    (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :metadata, :created_at)',
                [
                    'id' => id('ledger'),
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'program_id' => $programId,
                    'entry_type' => 'purchase',
                    'points' => $event['points'],
                    'balance_after' => $balance,
                    'reference' => $event['invoice'],
                    'source' => 'pos',
                    'metadata' => json_encode([
                        'invoiceAmount' => $event['amount'],
                        'invoiceNumber' => $event['invoice'],
                        'store' => $event['store'],
                    ]),
                    'created_at' => $event['date'],
                ]
            );
            $summary['purchases']++;
            continue;
        }

        if ($balance < (int)$event['points']) {
            continue;
        }
        $balance -= (int)$event['points'];
        $redemptionId = id('redemption');
        execute(
            $pdo,
            'INSERT INTO loyalty_redemptions
                (id, tenant_id, member_id, reward_id, points_cost, status, metadata, created_at, updated_at)
             VALUES
                (:id, :tenant_id, :member_id, :reward_id, :points_cost, :status, :metadata, :created_at, :updated_at)',
            [
                'id' => $redemptionId,
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'reward_id' => $event['rewardId'],
                'points_cost' => $event['points'],
                'status' => 'approved',
                'metadata' => json_encode(['rewardName' => $event['rewardName'], 'channel' => 'mostrador']),
                'created_at' => $event['date'],
                'updated_at' => $event['date'],
            ]
        );
        execute(
            $pdo,
            'INSERT INTO loyalty_point_ledger
                (id, tenant_id, member_id, program_id, entry_type, points, balance_after, reference, source, source_reference, metadata, created_at)
             VALUES
                (:id, :tenant_id, :member_id, :program_id, :entry_type, :points, :balance_after, :reference, :source, :source_reference, :metadata, :created_at)',
            [
                'id' => id('ledger'),
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'program_id' => $programId,
                'entry_type' => 'redemption',
                'points' => -((int)$event['points']),
                'balance_after' => $balance,
                'reference' => $redemptionId,
                'source' => 'pos',
                'source_reference' => $event['rewardId'],
                'metadata' => json_encode(['rewardName' => $event['rewardName']]),
                'created_at' => $event['date'],
            ]
        );
        execute(
            $pdo,
            'UPDATE loyalty_rewards SET stock = GREATEST(stock - 1, 0), updated_at = :updated_at WHERE tenant_id = :tenant_id AND id = :id',
            ['updated_at' => $event['date'], 'tenant_id' => $tenantId, 'id' => $event['rewardId']]
        );
        $summary['redemptions']++;
    }

    execute(
        $pdo,
        'INSERT INTO loyalty_point_accounts (id, tenant_id, member_id, program_id, balance, lifetime_points, updated_at)
         VALUES (:id, :tenant_id, :member_id, :program_id, :balance, :lifetime_points, :updated_at)',
        [
            'id' => id('account'),
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'program_id' => $programId,
            'balance' => $balance,
            'lifetime_points' => $lifetime,
            'updated_at' => $lastActivity,
        ]
    );

    if (in_array($member['wallet'], ['google', 'apple'], true)) {
        execute(
            $pdo,
            'INSERT INTO loyalty_wallet_passes (id, tenant_id, member_id, platform, external_object_id, status, last_payload, created_at, updated_at)
             VALUES (:id, :tenant_id, :member_id, :platform, :external_object_id, :status, :last_payload, :created_at, :updated_at)',
            [
                'id' => id('pass'),
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'platform' => $member['wallet'],
                'external_object_id' => $tenantId . '.' . $member['accountId'],
                'status' => 'ready-for-issuer',
                'last_payload' => json_encode(['source' => 'realistic-demo', 'accountId' => $member['accountId']]),
                'created_at' => '2026-06-10 09:00:00',
                'updated_at' => $lastActivity,
            ]
        );
        $summary['walletPasses']++;
    }

    $summary['members']++;
}

function tierForLifetime(int $lifetime): string {
    if ($lifetime >= 15000) {
        return 'Oro';
    }
    if ($lifetime >= 5000) {
        return 'Plata';
    }

    return 'Bronce';
}

function memberSegment(int $lifetime): string {
    if ($lifetime >= 15000) {
        return 'alto valor';
    }
    if ($lifetime >= 5000) {
        return 'recurrente';
    }

    return 'crecimiento';
}

function rewardCategory(int $pointsCost): string {
    if ($pointsCost >= 2500) {
        return 'premium';
    }
    if ($pointsCost >= 1000) {
        return 'beneficio comercial';
    }

    return 'rapido';
}

function id(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(8));
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function execute(PDO $pdo, string $sql, array $params = []): void {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
