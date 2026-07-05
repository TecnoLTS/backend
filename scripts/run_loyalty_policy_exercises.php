<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyRepository;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

TenantContext::set([
    'id' => 'fidepuntos',
    'slug' => 'fidepuntos',
    'name' => 'Fidepuntos QA',
]);

$repository = new LoyaltyRepository();
$token = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
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

try {
    $member = $repository->createMember([
        'name' => 'QA Fidelizacion ' . $token,
        'email' => "qa.loyalty.{$token}@tecnolts.com",
        'walletPlatform' => 'none',
    ], 'policy-script');
    $report['checks']['member_created'] = [
        'passed' => isset($member['id']) && ($member['points'] ?? -1) === 0,
        'account_id' => $member['account_id'] ?? null,
    ];

    $duplicateInvoice = $expectException(
        function () use ($repository, $member, $token): void {
            $repository->registerPurchase([
                'memberId' => $member['id'],
                'invoiceNumber' => 'LOY-QA-' . $token,
                'invoiceAmount' => 100,
            ], 'policy-script');
            $repository->registerPurchase([
                'memberId' => $member['id'],
                'invoiceNumber' => 'LOY-QA-' . $token,
                'invoiceAmount' => 100,
            ], 'policy-script');
        },
        'factura'
    );
    $report['checks']['duplicate_invoice_blocked'] = $duplicateInvoice;

    $purchase = $repository->registerPurchase([
        'memberId' => $member['id'],
        'invoiceNumber' => 'LOY-QA-FORMULA-' . $token,
        'invoiceAmount' => 25.75,
    ], 'policy-script');
    $report['checks']['purchase_formula'] = [
        'passed' => ($purchase['pointsEarned'] ?? 0) === 25,
        'points_earned' => $purchase['pointsEarned'] ?? null,
    ];

    $reward = $repository->createReward([
        'name' => 'Premio QA ' . $token,
        'description' => 'Premio de prueba antifraude',
        'pointsCost' => 10,
        'stock' => 1,
    ]);

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
        'name' => 'QA Bloqueado ' . $token,
        'email' => "qa.loyalty.blocked.{$token}@tecnolts.com",
        'walletPlatform' => 'google',
    ], 'policy-script');
    $repository->updateMember((string)$blockedMember['id'], [
        'status' => 'blocked',
        'reason' => 'Prueba antifraude',
    ], 'policy-script');
    $blockedOperation = $expectException(
        fn() => $repository->registerPurchase([
            'memberId' => $blockedMember['id'],
            'invoiceNumber' => 'LOY-QA-BLOCKED-' . $token,
            'invoiceAmount' => 50,
        ], 'policy-script'),
        'no esta activo'
    );
    $report['checks']['blocked_member_operation'] = $blockedOperation;

    $reverse = $repository->reversePurchase('LOY-QA-FORMULA-' . $token, [
        'reason' => 'Prueba de reversa QA',
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

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed === [] ? 0 : 1);
} catch (Throwable $exception) {
    $report['ok'] = false;
    $report['fatal'] = $exception->getMessage();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
