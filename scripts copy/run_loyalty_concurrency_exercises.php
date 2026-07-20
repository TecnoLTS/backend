<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyRepository;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}
if (strtolower(trim((string)($_ENV['APP_ENV'] ?? $_ENV['ENTORNO_MODE'] ?? ''))) !== 'qa') {
    fwrite(STDERR, "Este ejercicio de concurrencia solo puede ejecutarse en QA.\n");
    exit(2);
}

TenantContext::set(['id' => 'fidepuntos', 'slug' => 'fidepuntos', 'name' => 'Fidepuntos']);
$pdo = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
$repository = new LoyaltyRepository($pdo);

if (($argv[1] ?? '') === '--worker') {
    $operation = (string)($argv[2] ?? '');
    $payload = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($payload)) {
        fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'invalid_payload']) . PHP_EOL);
        exit(1);
    }
    $pdo->exec("SET application_name = 'loyalty_concurrency_exercise'");
    $startAt = (float)($payload['startAt'] ?? 0);
    while ($startAt > microtime(true)) {
        usleep(1000);
    }

    try {
        $result = match ($operation) {
            'purchase' => $repository->registerPurchase(
                (array)$payload['data'],
                (string)$payload['actor'],
                ['verified' => true, 'type' => 'qa_fixture', 'actorId' => 'system:test:loyalty-concurrency']
            ),
            'redeem' => $repository->redeemReward((array)$payload['data'], (string)$payload['actor']),
            'adjust' => $repository->adjustPoints((array)$payload['data'], (string)$payload['actor']),
            'otp' => $repository->verifyPortalAccess((array)$payload['data']),
            'approve' => $repository->approveRedemptionClaim((string)$payload['redemptionId'], [], (string)$payload['actor']),
            'cancel' => $repository->cancelRedemptionClaim(
                (string)$payload['redemptionId'],
                ['reason' => 'Carrera QA cancel vs approve'],
                (string)$payload['actor']
            ),
            default => throw new InvalidArgumentException('Operacion worker desconocida.'),
        };
        $safe = match ($operation) {
            'purchase' => ['commandId' => $result['commandId'] ?? null],
            'redeem' => ['redemptionId' => $result['redemption']['id'] ?? null],
            'adjust' => ['commandId' => $result['commandId'] ?? null],
            'otp' => ['verified' => true],
            'approve', 'cancel' => ['status' => $result['status'] ?? $result['redemption']['status'] ?? null],
            default => [],
        };
        fwrite(STDOUT, json_encode(['ok' => true, 'result' => $safe], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDOUT, json_encode([
            'ok' => false,
            'error' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(1);
    }
}

if (!function_exists('proc_open')) {
    fwrite(STDERR, "proc_open es obligatorio para este ejercicio.\n");
    exit(2);
}

$spawn = static function (string $operation, array $payload): array {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--worker', $operation],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        throw new RuntimeException("No se pudo iniciar worker {$operation}.");
    }
    fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);

    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
};

$collect = static function (array $worker): array {
    $stdout = trim((string)stream_get_contents($worker['stdout']));
    $stderr = trim((string)stream_get_contents($worker['stderr']));
    fclose($worker['stdout']);
    fclose($worker['stderr']);
    $exitCode = proc_close($worker['process']);
    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'invalid_worker_response', 'message' => $stderr, 'exitCode' => $exitCode];
    }
    $decoded['exitCode'] = $exitCode;

    return $decoded;
};

$race = static function (array $calls) use ($spawn, $collect): array {
    $startAt = microtime(true) + 0.5;
    $workers = [];
    foreach ($calls as $call) {
        $call['payload']['startAt'] = $startAt;
        $workers[] = $spawn($call['operation'], $call['payload']);
    }

    return array_map($collect, $workers);
};

$deleteIds = static function (PDO $connection, string $table, string $column, array $ids): void {
    $ids = array_values(array_unique(array_filter($ids, static fn(mixed $id): bool => is_string($id) && $id !== '')));
    if ($ids === []) {
        return;
    }
    $params = ['tenant_id' => 'fidepuntos'];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $key = 'id_' . $index;
        $params[$key] = $id;
        $placeholders[] = ':' . $key;
    }
    $connection->prepare(sprintf(
        'DELETE FROM %s WHERE tenant_id = :tenant_id AND %s IN (%s)',
        $table,
        $column,
        implode(', ', $placeholders)
    ))->execute($params);
};

$token = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$memberIds = [];
$rewardIds = [];
$references = [];
$actors = [
    'concurrency-purchase-a', 'concurrency-purchase-b',
    'concurrency-redeem-a', 'concurrency-redeem-b',
    'concurrency-adjust', 'concurrency-main',
    'concurrency-transition-approve', 'concurrency-transition-cancel',
];
$report = ['tenantId' => 'fidepuntos', 'startedAt' => date(DATE_ATOM), 'checks' => []];
$failure = null;

try {
    $member = $repository->createMember([
        'name' => 'Concurrencia ' . $token,
        'accountId' => 'CONC-' . $token,
        'email' => "concurrency.{$token}@tecnolts.com",
        'phone' => '095' . random_int(1000000, 9999999),
        'walletPlatform' => 'google',
    ], 'concurrency-main');
    $memberIds[] = (string)$member['id'];
    $repository->updateWallet((string)$member['id'], ['platform' => 'google']);

    $purchaseReference = 'CONC-PURCHASE-' . $token;
    $references[] = $purchaseReference;
    $purchaseResults = $race([
        ['operation' => 'purchase', 'payload' => [
            'actor' => 'concurrency-purchase-a',
            'data' => ['memberId' => $member['id'], 'invoiceNumber' => $purchaseReference, 'invoiceAmount' => '100.00', 'commandId' => 'conc-purchase-a-' . $token],
        ]],
        ['operation' => 'purchase', 'payload' => [
            'actor' => 'concurrency-purchase-b',
            'data' => ['memberId' => $member['id'], 'invoiceNumber' => '  ' . strtolower($purchaseReference) . '  ', 'invoiceAmount' => '100.00', 'commandId' => 'conc-purchase-b-' . $token],
        ]],
    ]);
    $purchaseCountStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM loyalty_point_ledger
         WHERE tenant_id = :tenant_id AND member_id = :member_id AND entry_type = 'purchase'"
    );
    $purchaseCountStmt->execute([
        'tenant_id' => 'fidepuntos',
        'member_id' => $member['id'],
    ]);
    $purchaseCount = (int)$purchaseCountStmt->fetchColumn();
    $report['checks']['duplicate_purchase'] = [
        'passed' => count(array_filter($purchaseResults, static fn(array $row): bool => !empty($row['ok']))) === 1 && $purchaseCount === 1,
        'workers' => $purchaseResults,
        'ledgerRows' => $purchaseCount,
    ];

    $accountStmt = $pdo->prepare('SELECT balance FROM loyalty_point_accounts WHERE tenant_id = :tenant_id AND member_id = :member_id');
    $accountStmt->execute(['tenant_id' => 'fidepuntos', 'member_id' => $member['id']]);
    $balance = (int)$accountStmt->fetchColumn();
    if ($balance <= 0) {
        throw new RuntimeException('La compra concurrente no dejo saldo para probar canjes.');
    }
    $reward = $repository->createReward([
        'name' => 'Stock concurrente ' . $token,
        'description' => 'Un solo canje permitido',
        'pointsCost' => $balance,
        'stock' => 1,
    ]);
    $rewardIds[] = (string)$reward['id'];
    $redemptionResults = $race([
        ['operation' => 'redeem', 'payload' => ['actor' => 'concurrency-redeem-a', 'data' => ['memberId' => $member['id'], 'rewardId' => $reward['id'], 'commandId' => 'conc-redeem-a-' . $token]]],
        ['operation' => 'redeem', 'payload' => ['actor' => 'concurrency-redeem-b', 'data' => ['memberId' => $member['id'], 'rewardId' => $reward['id'], 'commandId' => 'conc-redeem-b-' . $token]]],
    ]);
    $redemptionStateStmt = $pdo->prepare(
        'SELECT w.stock, a.balance,
                (SELECT COUNT(*) FROM loyalty_redemptions r WHERE r.tenant_id = w.tenant_id AND r.reward_id = w.id) AS redemption_count
         FROM loyalty_rewards w
         JOIN loyalty_point_accounts a ON a.tenant_id = w.tenant_id AND a.member_id = :member_id
         WHERE w.tenant_id = :tenant_id AND w.id = :reward_id'
    );
    $redemptionStateStmt->execute(['tenant_id' => 'fidepuntos', 'member_id' => $member['id'], 'reward_id' => $reward['id']]);
    $redemptionState = $redemptionStateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $report['checks']['single_balance_and_stock_redemption'] = [
        'passed' => count(array_filter($redemptionResults, static fn(array $row): bool => !empty($row['ok']))) === 1
            && (int)($redemptionState['stock'] ?? -1) === 0
            && (int)($redemptionState['balance'] ?? -1) === 0
            && (int)($redemptionState['redemption_count'] ?? -1) === 1,
        'workers' => $redemptionResults,
        'state' => $redemptionState,
    ];

    $adjustCommand = 'conc-adjust-' . $token;
    $adjustPayload = [
        'memberId' => $member['id'], 'points' => 7, 'adjustmentType' => 'correction',
        'reason' => 'Ejercicio de concurrencia', 'evidence' => 'QA automated concurrency exercise',
        'commandId' => $adjustCommand,
    ];
    $adjustResults = $race([
        ['operation' => 'adjust', 'payload' => ['actor' => 'concurrency-adjust', 'data' => $adjustPayload]],
        ['operation' => 'adjust', 'payload' => ['actor' => 'concurrency-adjust', 'data' => $adjustPayload]],
    ]);
    $adjustCountStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM loyalty_point_ledger
         WHERE tenant_id = :tenant_id AND member_id = :member_id AND entry_type = 'adjustment' AND reference = :reference"
    );
    $adjustCountStmt->execute(['tenant_id' => 'fidepuntos', 'member_id' => $member['id'], 'reference' => $adjustCommand]);
    $report['checks']['adjustment_command_replay'] = [
        'passed' => count(array_filter($adjustResults, static fn(array $row): bool => !empty($row['ok']))) === 2
            && (int)$adjustCountStmt->fetchColumn() === 1,
        'workers' => $adjustResults,
    ];

    $challengeId = 'otp_concurrency_' . bin2hex(random_bytes(8));
    $otpCode = (string)random_int(100000, 999999);
    $otpHash = (new ReflectionMethod($repository, 'portalOtpHash'))->invoke($repository, $challengeId, $otpCode);
    $pdo->prepare(
        'INSERT INTO loyalty_portal_otp_challenges
            (id, tenant_id, member_id, channel, destination, code_hash, expires_at)
         VALUES (:id, :tenant_id, :member_id, \'email\', :destination, :code_hash, NOW() + INTERVAL \'10 minutes\')'
    )->execute([
        'id' => $challengeId, 'tenant_id' => 'fidepuntos', 'member_id' => $member['id'],
        'destination' => $member['email'], 'code_hash' => $otpHash,
    ]);
    $otpResults = $race([
        ['operation' => 'otp', 'payload' => ['data' => ['challengeId' => $challengeId, 'code' => $otpCode]]],
        ['operation' => 'otp', 'payload' => ['data' => ['challengeId' => $challengeId, 'code' => $otpCode]]],
    ]);
    $sessionStmt = $pdo->prepare('SELECT COUNT(*) FROM loyalty_portal_sessions WHERE tenant_id = :tenant_id AND member_id = :member_id');
    $sessionStmt->execute(['tenant_id' => 'fidepuntos', 'member_id' => $member['id']]);
    $report['checks']['single_otp_session'] = [
        'passed' => count(array_filter($otpResults, static fn(array $row): bool => !empty($row['ok']))) === 1
            && (int)$sessionStmt->fetchColumn() === 1,
        'workers' => $otpResults,
    ];

    $transitionMember = $repository->createMember([
        'name' => 'Transicion concurrente ' . $token,
        'accountId' => 'TRANS-' . $token,
        'email' => "transition.{$token}@tecnolts.com",
        'phone' => '094' . random_int(1000000, 9999999),
        'walletPlatform' => 'google',
    ], 'concurrency-main');
    $memberIds[] = (string)$transitionMember['id'];
    $repository->updateWallet((string)$transitionMember['id'], ['platform' => 'google']);
    $transitionReference = 'CONC-TRANSITION-' . $token;
    $references[] = $transitionReference;
    $transitionPurchase = $repository->registerPurchase([
        'memberId' => $transitionMember['id'], 'invoiceNumber' => $transitionReference,
        'invoiceAmount' => '20.00', 'commandId' => 'conc-transition-purchase-' . $token,
    ], 'concurrency-main', ['verified' => true, 'type' => 'qa_fixture', 'actorId' => 'system:test:loyalty-concurrency']);
    $transitionReward = $repository->createReward([
        'name' => 'Transicion ' . $token, 'description' => 'Cancel vs approve',
        'pointsCost' => (int)$transitionPurchase['pointsEarned'], 'stock' => 1, 'claimMode' => 'managed',
    ]);
    $rewardIds[] = (string)$transitionReward['id'];
    $transitionCommand = 'conc-transition-reserve-' . $token;
    $redemptionId = (string)(new ReflectionMethod($repository, 'reservePortalRedemption'))->invoke(
        $repository, $transitionMember, $transitionReward, 'pending_review', 'managed',
        ['exercise' => 'cancel_vs_approve'], gmdate(DATE_ATOM, time() + 900), null,
        $transitionCommand, ['exercise' => 'cancel_vs_approve']
    );

    $pdo->beginTransaction();
    $pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))')->execute([
        'key' => 'fidepuntos|redemption-member|' . $transitionMember['id'],
    ]);
    $startAt = microtime(true) + 0.2;
    $transitionWorkers = [
        $spawn('approve', ['startAt' => $startAt, 'redemptionId' => $redemptionId, 'actor' => 'concurrency-transition-approve']),
        $spawn('cancel', ['startAt' => $startAt, 'redemptionId' => $redemptionId, 'actor' => 'concurrency-transition-cancel']),
    ];
    $waiting = 0;
    $deadline = microtime(true) + 5;
    $waitingStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM pg_stat_activity
         WHERE datname = current_database()
           AND application_name = 'loyalty_concurrency_exercise'
           AND wait_event_type = 'Lock' AND wait_event = 'advisory'"
    );
    while (microtime(true) < $deadline) {
        $waitingStmt->execute();
        $waiting = (int)$waitingStmt->fetchColumn();
        if ($waiting >= 2) {
            break;
        }
        usleep(50000);
    }
    $pdo->commit();
    $transitionResults = array_map($collect, $transitionWorkers);
    $transitionStateStmt = $pdo->prepare(
        'SELECT r.status, w.stock, a.balance,
                (SELECT COUNT(*) FROM loyalty_point_ledger l
                 WHERE l.tenant_id = r.tenant_id AND l.member_id = r.member_id
                   AND l.entry_type = \'redemption_reversal\' AND l.reference = r.id) AS restoration_count
         FROM loyalty_redemptions r
         JOIN loyalty_rewards w ON w.tenant_id = r.tenant_id AND w.id = r.reward_id
         JOIN loyalty_point_accounts a ON a.tenant_id = r.tenant_id AND a.member_id = r.member_id
         WHERE r.tenant_id = :tenant_id AND r.id = :id'
    );
    $transitionStateStmt->execute(['tenant_id' => 'fidepuntos', 'id' => $redemptionId]);
    $transitionState = $transitionStateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cancelled = ($transitionState['status'] ?? '') === 'cancelled';
    $report['checks']['cancel_vs_approve_transition'] = [
        'passed' => count($transitionResults) === 2
            && count(array_filter($transitionResults, static fn(array $row): bool => !empty($row['ok']))) === 1
            && in_array($transitionState['status'] ?? null, ['approved', 'cancelled'], true)
            && (int)($transitionState['stock'] ?? -1) === ($cancelled ? 1 : 0)
            && (int)($transitionState['balance'] ?? -1) === ($cancelled ? (int)$transitionPurchase['pointsEarned'] : 0)
            && (int)($transitionState['restoration_count'] ?? -1) === ($cancelled ? 1 : 0),
        'workersWaiting' => $waiting,
        'workers' => $transitionResults,
        'state' => $transitionState,
    ];
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $failure = $exception;
} finally {
    try {
        $deleteIds($pdo, 'loyalty_risk_events', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_audit_events', 'subject_id', $memberIds);
        $deleteIds($pdo, 'loyalty_audit_events', 'subject_id', $rewardIds);
        $deleteIds($pdo, 'loyalty_audit_events', 'actor_user_id', array_merge(
            $actors,
            array_map(static fn(string $id): string => 'customer:' . $id, $memberIds)
        ));
        $deleteIds($pdo, 'loyalty_portal_form_nonces', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_portal_sessions', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_portal_otp_challenges', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_command_journal', 'actor_id', array_merge(
            $actors,
            array_map(static fn(string $id): string => 'customer:' . $id, $memberIds)
        ));
        $deleteIds($pdo, 'loyalty_point_expirations', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_reversals', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_debt_ledger', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_redemptions', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_wallet_passes', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_point_ledger', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_point_accounts', 'member_id', $memberIds);
        $deleteIds($pdo, 'loyalty_members', 'id', $memberIds);
        $deleteIds($pdo, 'loyalty_rewards', 'id', $rewardIds);
    } catch (Throwable $cleanupError) {
        $report['cleanupError'] = $cleanupError->getMessage();
    }
}

if ($failure !== null) {
    $report['fatal'] = $failure->getMessage();
}
$failedChecks = array_keys(array_filter(
    $report['checks'],
    static fn(array $check): bool => empty($check['passed'])
));
$report['finishedAt'] = date(DATE_ATOM);
$report['failed'] = $failedChecks;
$report['ok'] = $failure === null && $failedChecks === [] && !isset($report['cleanupError']);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? 0 : 1);
