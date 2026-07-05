<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\TenantContext;
use App\Core\Database;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyRepository;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
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
$repository = new LoyaltyRepository($pdo);
$token = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$createdMemberIds = [];
$createdRewardIds = [];
$report = [
    'tenant_id' => 'fidepuntos',
    'started_at' => date('c'),
    'checks' => [],
];

$expectException = static function (callable $callback, string $contains): array {
    try {
        $callback();
    } catch (Throwable $exception) {
        return [
            'blocked' => str_contains($exception->getMessage(), $contains),
            'message' => $exception->getMessage(),
        ];
    }

    return ['blocked' => false, 'message' => null];
};

$expectedPurchasePoints = static function (float $amount, array $member, array $rules): int {
    $settings = is_array($rules['settings'] ?? null) ? $rules['settings'] : [];
    $earning = is_array($settings['earning'] ?? null) ? $settings['earning'] : [];
    $pointsPerUnit = max(0.0001, (float)($earning['pointsPerUnit'] ?? 1));
    $amountPerUnit = max(0.0001, (float)($earning['amountPerUnit'] ?? 1));
    $roundingMode = (string)($earning['roundingMode'] ?? 'floor');
    $maximum = max(1, (int)($earning['maximumPointsPerPurchase'] ?? 20000));
    $tierName = (string)($member['tier'] ?? 'Bronce');
    $multiplier = 1.0;

    foreach (is_array($rules['tiers'] ?? null) ? $rules['tiers'] : [] as $tier) {
        if (strcasecmp((string)($tier['name'] ?? ''), $tierName) === 0) {
            $multiplier = max(0.01, (float)($tier['multiplier'] ?? 1));
            break;
        }
    }

    $raw = ($amount / $amountPerUnit) * $pointsPerUnit * $multiplier;
    $points = match ($roundingMode) {
        'ceil' => (int)ceil($raw),
        'round' => (int)round($raw),
        default => (int)floor($raw),
    };

    return min(max(1, $points), $maximum);
};

$deleteByIds = static function (PDO $pdo, string $table, string $column, array $ids): void {
    $ids = array_values(array_filter(array_unique($ids)));
    if ($ids === []) {
        return;
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $index => $id) {
        $key = ':id' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }

    $pdo->prepare(sprintf(
        'DELETE FROM %s WHERE tenant_id = :tenant_id AND %s IN (%s)',
        $table,
        $column,
        implode(', ', $placeholders)
    ))->execute([':tenant_id' => 'fidepuntos'] + $params);
};

$cleanup = static function () use ($pdo, &$createdMemberIds, &$createdRewardIds, $deleteByIds): void {
    $deleteByIds($pdo, 'loyalty_risk_events', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_audit_events', 'subject_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_point_expirations', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_reversals', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_redemptions', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_wallet_passes', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_point_ledger', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_point_accounts', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_members', 'id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_rewards', 'id', $createdRewardIds);
};

try {
    $member = $repository->createMember([
        'name' => 'Control Politicas ' . $token,
        'email' => "control.politicas.{$token}@tecnolts.com",
        'walletPlatform' => 'none',
    ], 'policy-script');
    $createdMemberIds[] = (string)$member['id'];
    $report['checks']['member_created'] = [
        'passed' => isset($member['id']) && ($member['points'] ?? -1) === 0,
        'account_id' => $member['account_id'] ?? null,
    ];

    $duplicateInvoice = $expectException(
        function () use ($repository, $member, $token): void {
            $repository->registerPurchase([
                'memberId' => $member['id'],
                'invoiceNumber' => 'POL-DUP-' . $token,
                'invoiceAmount' => 100,
            ], 'policy-script');
            $repository->registerPurchase([
                'memberId' => $member['id'],
                'invoiceNumber' => 'POL-DUP-' . $token,
                'invoiceAmount' => 100,
            ], 'policy-script');
        },
        'factura'
    );
    $report['checks']['duplicate_invoice_blocked'] = $duplicateInvoice;

    $rulesBeforeFormulaPurchase = $repository->rules();
    $memberBeforeFormulaPurchase = $repository->customerDetail((string)$member['id'])['member'] ?? $member;
    $formulaAmount = 25.75;
    $purchase = $repository->registerPurchase([
        'memberId' => $member['id'],
        'invoiceNumber' => 'POL-FORMULA-' . $token,
        'invoiceAmount' => $formulaAmount,
    ], 'policy-script');
    $expectedPoints = $expectedPurchasePoints($formulaAmount, $memberBeforeFormulaPurchase, $rulesBeforeFormulaPurchase);
    $report['checks']['purchase_formula'] = [
        'passed' => ($purchase['pointsEarned'] ?? 0) === $expectedPoints,
        'points_earned' => $purchase['pointsEarned'] ?? null,
        'expected' => $expectedPoints,
    ];

    $reward = $repository->createReward([
        'name' => 'Control antifraude ' . $token,
        'description' => 'Premio temporal para validar reglas internas',
        'pointsCost' => 10,
        'stock' => 1,
    ]);
    $createdRewardIds[] = (string)$reward['id'];

    $noCard = $expectException(
        fn() => $repository->redeemReward(['memberId' => $member['id'], 'rewardId' => $reward['id']], 'policy-script'),
        'tarjeta digital'
    );
    $report['checks']['redemption_without_card_blocked'] = $noCard;

    $repository->updateWallet((string)$member['id'], ['platform' => 'google']);
    $redemption = $repository->redeemReward(['memberId' => $member['id'], 'rewardId' => $reward['id']], 'policy-script');
    $report['checks']['redemption_success'] = [
        'passed' => ($redemption['redemption']['status'] ?? null) === 'approved',
        'balance_after' => $redemption['balanceAfter'] ?? null,
    ];

    $outOfStock = $expectException(
        fn() => $repository->redeemReward(['memberId' => $member['id'], 'rewardId' => $reward['id']], 'policy-script'),
        'stock'
    );
    $report['checks']['stock_blocked'] = $outOfStock;

    $blockedMember = $repository->createMember([
        'name' => 'Control Bloqueado ' . $token,
        'email' => "control.bloqueado.{$token}@tecnolts.com",
        'walletPlatform' => 'google',
    ], 'policy-script');
    $createdMemberIds[] = (string)$blockedMember['id'];
    $repository->updateMember((string)$blockedMember['id'], [
        'status' => 'blocked',
        'reason' => 'Ejercicio interno antifraude',
    ], 'policy-script');
    $blockedOperation = $expectException(
        fn() => $repository->registerPurchase([
            'memberId' => $blockedMember['id'],
            'invoiceNumber' => 'POL-BLOCKED-' . $token,
            'invoiceAmount' => 50,
        ], 'policy-script'),
        'no esta activo'
    );
    $report['checks']['blocked_member_operation'] = $blockedOperation;

    $reverse = $repository->reversePurchase('POL-FORMULA-' . $token, [
        'reason' => 'Ejercicio interno de reversa',
    ], 'policy-script');
    $report['checks']['purchase_reversal'] = [
        'passed' => ($reverse['pointsReversed'] ?? 0) > 0,
        'balance_after' => $reverse['balanceAfter'] ?? null,
    ];

    $failed = [];
    foreach ($report['checks'] as $name => $check) {
        $passed = (bool)($check['passed'] ?? $check['blocked'] ?? false);
        if (!$passed) {
            $failed[] = $name;
        }
    }

    $report['finished_at'] = date('c');
    $report['ok'] = $failed === [];
    $report['failed'] = $failed;
    $cleanup();

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed === [] ? 0 : 1);
} catch (Throwable $exception) {
    $cleanup();
    $report['ok'] = false;
    $report['fatal'] = $exception->getMessage();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
