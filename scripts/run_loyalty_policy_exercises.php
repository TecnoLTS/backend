<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\TenantContext;
use App\Core\Database;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyRepository;
use App\Modules\LoyaltyRewards\Domain\DecimalMath;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}
if (strtolower(trim((string)($_ENV['APP_ENV'] ?? $_ENV['ENTORNO_MODE'] ?? ''))) !== 'qa') {
    fwrite(STDERR, "Este ejercicio financiero solo puede ejecutarse en QA.\n");
    exit(2);
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
$createdIdempotencyKeys = [];
$createdApiClientIds = [];
$createdReferences = [];
$createdCommandIds = [];
$trustedFixture = ['verified' => true, 'type' => 'qa_fixture', 'actorId' => 'system:test:loyalty-policy'];
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

$expectedPurchasePoints = static function (string $amount, array $member, array $rules): int {
    $settings = is_array($rules['settings'] ?? null) ? $rules['settings'] : [];
    $earning = is_array($settings['earning'] ?? null) ? $settings['earning'] : [];
    $pointsPerUnit = DecimalMath::factor($earning['pointsPerUnit'] ?? '1');
    $amountPerUnit = DecimalMath::factor($earning['amountPerUnit'] ?? '1');
    $roundingMode = (string)($earning['roundingMode'] ?? 'floor');
    $maximum = max(1, (int)($earning['maximumPointsPerPurchase'] ?? 20000));
    $tierName = (string)($member['tier'] ?? 'Bronce');
    $multiplier = '1.0000';

    foreach (is_array($rules['tiers'] ?? null) ? $rules['tiers'] : [] as $tier) {
        if (strcasecmp((string)($tier['name'] ?? ''), $tierName) === 0) {
            $multiplier = DecimalMath::factor($tier['multiplier'] ?? '1');
            break;
        }
    }

    $points = DecimalMath::calculatePoints(
        DecimalMath::money($amount),
        $amountPerUnit,
        $pointsPerUnit,
        $multiplier,
        $roundingMode === 'round' ? DecimalMath::ROUND_HALF_UP : $roundingMode
    );

    return DecimalMath::capPoints(max(1, $points), $maximum);
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

$cleanup = static function () use ($pdo, &$createdMemberIds, &$createdRewardIds, &$createdIdempotencyKeys, &$createdApiClientIds, &$createdReferences, &$createdCommandIds, $deleteByIds): void {
    $deleteByIds($pdo, 'loyalty_idempotency_keys', 'idempotency_key', $createdIdempotencyKeys);
    $deleteByIds($pdo, 'loyalty_api_rate_limit_counters', 'api_client_id', $createdApiClientIds);
    $deleteByIds($pdo, 'loyalty_api_usage_daily', 'api_client_id', $createdApiClientIds);
    $deleteByIds($pdo, 'loyalty_api_clients', 'id', $createdApiClientIds);
    $deleteByIds($pdo, 'loyalty_risk_events', 'reference', $createdReferences);
    $deleteByIds($pdo, 'loyalty_risk_events', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_audit_events', 'subject_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_audit_events', 'subject_id', $createdRewardIds);
    $deleteByIds($pdo, 'loyalty_portal_form_nonces', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_portal_sessions', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_command_journal', 'command_id', $createdCommandIds);
    $deleteByIds(
        $pdo,
        'loyalty_command_journal',
        'actor_id',
        array_map(static fn(string $memberId): string => 'customer:' . $memberId, $createdMemberIds)
    );
    $pdo->prepare(
        "DELETE FROM loyalty_command_journal
         WHERE tenant_id = :tenant_id
           AND actor_id IN ('policy-script', 'api:policy-client')"
    )->execute(['tenant_id' => 'fidepuntos']);
    $deleteByIds($pdo, 'loyalty_point_expirations', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_reversals', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_debt_ledger', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_redemptions', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_wallet_passes', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_point_ledger', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_point_accounts', 'member_id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_members', 'id', $createdMemberIds);
    $deleteByIds($pdo, 'loyalty_rewards', 'id', $createdRewardIds);
};

try {
    $createdReferences[] = 'POL-DUP-' . $token;
    $createdReferences[] = 'POL-FORMULA-' . $token;
    $createdReferences[] = 'POL-IDEM-INVOICE-' . $token;
    $member = $repository->createMember([
        'name' => 'Control Politicas ' . $token,
        'accountId' => 'POL-' . $token,
        'email' => "control.politicas.{$token}@tecnolts.com",
        'phone' => '099' . random_int(1000000, 9999999),
        'walletPlatform' => 'none',
    ], 'policy-script');
    $createdMemberIds[] = (string)$member['id'];
    $report['checks']['member_created'] = [
        'passed' => isset($member['id']) && ($member['points'] ?? -1) === 0,
        'account_id' => $member['account_id'] ?? null,
    ];

    $settingsWithExpiration = $repository->settings()['settings'];
    $settingsWithExpiration['expiration']['enabled'] = true;
    $validateSettings = new ReflectionMethod($repository, 'validateSettings');
    $expirationBlocked = $expectException(
        fn() => $validateSettings->invoke($repository, $settingsWithExpiration),
        'motor FIFO'
    );
    $report['checks']['expiration_fail_closed'] = $expirationBlocked;

    $validateSettingsIntegers = new ReflectionMethod($repository, 'validateSettings');
    $invalidIntegerCases = [
        'coercive_suffix' => '1abc',
        'decimal' => '1.5',
        'leading_zero' => '01',
        'overflow' => '2147483648',
    ];
    $integerValidationPassed = true;
    $integerValidationMessages = [];
    foreach ($invalidIntegerCases as $case => $invalidValue) {
        $invalidSettings = $repository->settings()['settings'];
        $invalidSettings['earning']['maximumPointsPerPurchase'] = $invalidValue;
        $result = $expectException(
            fn() => $validateSettingsIntegers->invoke($repository, $invalidSettings),
            'entero'
        );
        $integerValidationPassed = $integerValidationPassed && $result['blocked'];
        $integerValidationMessages[$case] = $result['message'];
    }
    $normalizeTierRules = new ReflectionMethod($repository, 'normalizeTierRules');
    $invalidTiers = $repository->rules()['tiers'];
    $invalidTiers[0]['minLifetimePoints'] = '1abc';
    $invalidTierResult = $expectException(
        fn() => $normalizeTierRules->invoke($repository, $invalidTiers),
        'entero'
    );
    $report['checks']['strict_integer_settings_and_tiers'] = [
        'passed' => $integerValidationPassed && $invalidTierResult['blocked'],
        'settings' => $integerValidationMessages,
        'tier' => $invalidTierResult['message'],
    ];

    $atomicReason = 'qa-atomic-rules-' . $token;
    $atomicRules = $repository->rules();
    $pdo->beginTransaction();
    try {
        $repository->updateRules([
            'settings' => $atomicRules['settings'],
            'tiers' => $atomicRules['tiers'],
            'reason' => $atomicReason,
        ], 'policy-script');
        $auditsInsideTransaction = (int)$pdo->query(
            "SELECT COUNT(*) FROM loyalty_audit_events WHERE reason = " . $pdo->quote($atomicReason)
        )->fetchColumn();
        $pdo->rollBack();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $atomicAuditStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM loyalty_audit_events WHERE tenant_id = :tenant_id AND reason = :reason'
    );
    $atomicAuditStmt->execute(['tenant_id' => 'fidepuntos', 'reason' => $atomicReason]);
    $auditsAfterRollback = (int)$atomicAuditStmt->fetchColumn();
    $report['checks']['rules_atomic_transaction'] = [
        'passed' => $auditsInsideTransaction === 2 && $auditsAfterRollback === 0,
        'audits_inside_transaction' => $auditsInsideTransaction,
        'audits_after_rollback' => $auditsAfterRollback,
    ];

    $adjustCommand = 'policy-adjust-' . $token;
    $createdCommandIds[] = $adjustCommand;
    $adjustPayload = [
        'memberId' => $member['id'],
        'points' => 5,
        'adjustmentType' => 'correction',
        'reason' => 'Correccion controlada de ejercicio QA',
        'evidence' => 'ticket:POL-' . $token,
        'commandId' => $adjustCommand,
    ];
    $lifetimeBeforeAdjust = (int)($repository->customerDetail((string)$member['id'])['member']['lifetime_points'] ?? 0);
    $adjustment = $repository->adjustPoints($adjustPayload, 'policy-script');
    $adjustmentReplay = $repository->adjustPoints($adjustPayload, 'policy-script');
    $adjustmentMismatch = $expectException(
        fn() => $repository->adjustPoints(array_replace($adjustPayload, ['points' => 6]), 'policy-script'),
        'payload diferente'
    );
    $missingEvidence = $expectException(
        fn() => $repository->adjustPoints([
            'memberId' => $member['id'],
            'points' => 1,
            'adjustmentType' => 'correction',
            'reason' => 'Sin evidencia',
            'commandId' => 'policy-adjust-no-evidence-' . $token,
        ], 'policy-script'),
        'evidencia'
    );
    $adjustLedgerStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM loyalty_point_ledger
         WHERE tenant_id = :tenant_id AND member_id = :member_id
           AND entry_type = 'adjustment' AND reference = :reference"
    );
    $adjustLedgerStmt->execute([
        'tenant_id' => 'fidepuntos',
        'member_id' => $member['id'],
        'reference' => $adjustCommand,
    ]);
    $lifetimeAfterAdjust = (int)($repository->customerDetail((string)$member['id'])['member']['lifetime_points'] ?? 0);
    $report['checks']['adjustment_command_and_lifetime'] = [
        'passed' => ($adjustmentReplay['commandId'] ?? null) === ($adjustment['commandId'] ?? null)
            && !empty($adjustmentMismatch['blocked'])
            && !empty($missingEvidence['blocked'])
            && (int)$adjustLedgerStmt->fetchColumn() === 1
            && $lifetimeAfterAdjust === $lifetimeBeforeAdjust,
    ];

    $duplicateCommandOne = 'policy-duplicate-one-' . $token;
    $duplicateCommandTwo = 'policy-duplicate-two-' . $token;
    $createdCommandIds[] = $duplicateCommandOne;
    $createdCommandIds[] = $duplicateCommandTwo;
    $duplicateInvoice = $expectException(
        function () use ($repository, $member, $token, $trustedFixture, $duplicateCommandOne, $duplicateCommandTwo): void {
            $repository->registerPurchase([
                'memberId' => $member['id'],
                'invoiceNumber' => '  pol-dup-' . $token . '  ',
                'invoiceAmount' => '100.00',
                'commandId' => $duplicateCommandOne,
            ], 'policy-script', $trustedFixture);
            $repository->registerPurchase([
                'memberId' => $member['id'],
                'invoiceNumber' => 'POL-DUP-' . $token,
                'invoiceAmount' => '100.00',
                'commandId' => $duplicateCommandTwo,
            ], 'policy-script', $trustedFixture);
        },
        'factura'
    );
    $report['checks']['duplicate_invoice_blocked'] = $duplicateInvoice;

    $rulesBeforeFormulaPurchase = $repository->rules();
    $memberBeforeFormulaPurchase = $repository->customerDetail((string)$member['id'])['member'] ?? $member;
    $formulaAmount = '25.75';
    $formulaCommand = 'policy-formula-' . $token;
    $createdCommandIds[] = $formulaCommand;
    $purchase = $repository->registerPurchase([
        'memberId' => $member['id'],
        'invoiceNumber' => 'POL-FORMULA-' . $token,
        'invoiceAmount' => $formulaAmount,
        'commandId' => $formulaCommand,
    ], 'policy-script', $trustedFixture);
    $expectedPoints = $expectedPurchasePoints($formulaAmount, $memberBeforeFormulaPurchase, $rulesBeforeFormulaPurchase);
    $report['checks']['purchase_formula'] = [
        'passed' => ($purchase['pointsEarned'] ?? 0) === $expectedPoints,
        'points_earned' => $purchase['pointsEarned'] ?? null,
        'expected' => $expectedPoints,
    ];

    $formulaStmt = $pdo->prepare(
        'SELECT l.formula_snapshot, l.rules_version, v.rule_hash
         FROM loyalty_point_ledger l
         JOIN loyalty_earning_rule_versions v
           ON v.tenant_id = l.tenant_id AND v.version = l.rules_version
         WHERE l.tenant_id = :tenant_id
           AND l.id = :ledger_id
           AND l.entry_type = :entry_type
         LIMIT 1'
    );
    $formulaStmt->execute([
        'tenant_id' => 'fidepuntos',
        'ledger_id' => $purchase['ledgerId'] ?? '',
        'entry_type' => 'purchase',
    ]);
    $storedFormulaRow = $formulaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $storedFormula = json_decode((string)($storedFormulaRow['formula_snapshot'] ?? '{}'), true);
    $storedFormula = is_array($storedFormula) ? $storedFormula : [];
    $requiredFormulaKeys = [
        'schemaVersion',
        'eligibleAmountSource',
        'minimumPurchaseAmount',
        'pointsPerUnit',
        'amountPerUnit',
        'roundingMode',
        'minimumPointsPerPurchase',
        'maximumPointsPerPurchase',
        'maximumPointsPerMemberPerDay',
        'dailyLimitZeroMeansUnlimited',
        'dailyLimitLedgerEntryType',
        'dailyWindowTimezone',
        'tier',
        'tierMultiplier',
        'calculationSteps',
    ];
    $canonicalJson = new ReflectionMethod($repository, 'canonicalJson');
    $formulaVariants = [$storedFormula];
    foreach ([
        'minimumPurchaseAmount' => '2.00',
        'maximumPointsPerPurchase' => ((int)($storedFormula['maximumPointsPerPurchase'] ?? 0)) + 1,
        'maximumPointsPerMemberPerDay' => ((int)($storedFormula['maximumPointsPerMemberPerDay'] ?? 0)) + 1,
        'minimumPointsPerPurchase' => 2,
    ] as $key => $changedValue) {
        $variant = $storedFormula;
        $variant[$key] = $changedValue;
        $formulaVariants[] = $variant;
    }
    $formulaHashes = array_map(
        fn(array $formula): string => hash('sha256', $canonicalJson->invoke($repository, $formula)),
        $formulaVariants
    );
    $storedRuleHash = (string)($storedFormulaRow['rule_hash'] ?? '');
    $report['checks']['purchase_rule_snapshot_versioning'] = [
        'passed' => array_diff($requiredFormulaKeys, array_keys($storedFormula)) === []
            && $storedRuleHash !== ''
            && hash_equals($storedRuleHash, $formulaHashes[0])
            && count(array_unique($formulaHashes)) === count($formulaHashes)
            && (int)($storedFormulaRow['rules_version'] ?? 0) > 0,
        'rules_version' => (int)($storedFormulaRow['rules_version'] ?? 0),
        'missing_keys' => array_values(array_diff($requiredFormulaKeys, array_keys($storedFormula))),
        'variant_hashes_are_unique' => count(array_unique($formulaHashes)) === count($formulaHashes),
    ];

    $idempotencyKey = 'POL-IDEM-' . $token;
    $createdIdempotencyKeys[] = $idempotencyKey;
    $idempotentPayload = [
        'memberId' => $member['id'],
        'invoiceNumber' => 'POL-IDEM-INVOICE-' . $token,
        'invoiceAmount' => '12.50',
        'commandId' => 'policy-idem-command-' . $token,
    ];
    $createdCommandIds[] = $idempotentPayload['commandId'];
    $idempotentOperation = 'policy.purchase.' . $token;
    $firstIdempotentResult = $repository->idempotentExternalMutation(
        $idempotentOperation,
        $idempotencyKey,
        $idempotentPayload,
        fn() => $repository->registerPurchase($idempotentPayload, 'api:policy-client', $trustedFixture),
        'policy-client'
    );
    $reorderedIdempotentPayload = array_reverse($idempotentPayload, true);
    $replayedIdempotentResult = $repository->idempotentExternalMutation(
        $idempotentOperation,
        $idempotencyKey,
        $reorderedIdempotentPayload,
        static fn() => throw new RuntimeException('La repeticion no debe ejecutar el callback.'),
        'policy-client'
    );
    $idempotencyMismatch = $expectException(
        fn() => $repository->idempotentExternalMutation(
            $idempotentOperation,
            $idempotencyKey,
            array_replace($idempotentPayload, ['invoiceAmount' => '12.51']),
            static fn() => throw new RuntimeException('El payload distinto no debe ejecutar el callback.'),
            'policy-client'
        ),
        'Idempotency-Key'
    );
    $isolatedClientResult = $repository->idempotentExternalMutation(
        $idempotentOperation,
        $idempotencyKey,
        $idempotentPayload,
        static fn() => ['isolatedClient' => true],
        'policy-client-2'
    );
    $idempotencyStmt = $pdo->prepare(
        "SELECT COUNT(*) AS key_count,
                MAX(api_client_id) AS api_client_id,
                (SELECT COUNT(*) FROM loyalty_point_ledger
                 WHERE tenant_id = :ledger_tenant_id
                   AND entry_type = 'purchase'
                   AND id = :ledger_id) AS ledger_count
         FROM loyalty_idempotency_keys
         WHERE tenant_id = :idempotency_tenant_id
           AND api_client_id = :api_client_id
           AND idempotency_key = :idempotency_key
           AND operation = :operation"
    );
    $idempotencyStmt->execute([
        'ledger_tenant_id' => 'fidepuntos',
        'ledger_id' => $firstIdempotentResult['payload']['ledgerId'] ?? '',
        'idempotency_tenant_id' => 'fidepuntos',
        'api_client_id' => 'policy-client',
        'idempotency_key' => $idempotencyKey,
        'operation' => $idempotentOperation,
    ]);
    $idempotencyState = $idempotencyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $report['checks']['external_idempotency_replay'] = [
        'passed' => empty($firstIdempotentResult['replayed'])
            && !empty($replayedIdempotentResult['replayed'])
            && $idempotencyMismatch['blocked']
            && empty($isolatedClientResult['replayed'])
            && !empty($isolatedClientResult['payload']['isolatedClient'])
            && (int)($idempotencyState['key_count'] ?? 0) === 1
            && (int)($idempotencyState['ledger_count'] ?? 0) === 1
            && ($idempotencyState['api_client_id'] ?? null) === 'policy-client',
        'state' => [
            'first_replayed' => !empty($firstIdempotentResult['replayed']),
            'replay_replayed' => !empty($replayedIdempotentResult['replayed']),
            'mismatch_blocked' => $idempotencyMismatch['blocked'],
            'isolated_replayed' => !empty($isolatedClientResult['replayed']),
            'isolated_payload' => $isolatedClientResult['payload'] ?? null,
            'key_count' => (int)($idempotencyState['key_count'] ?? 0),
            'ledger_count' => (int)($idempotencyState['ledger_count'] ?? 0),
            'api_client_id' => $idempotencyState['api_client_id'] ?? null,
        ],
    ];

    $apiClientId = 'policy-rate-' . $token;
    $apiKey = 'policy_' . bin2hex(random_bytes(18));
    $createdApiClientIds[] = $apiClientId;
    $pdo->prepare(
        'INSERT INTO loyalty_api_clients
            (id, tenant_id, name, source, key_hash, scopes, status, rate_limit_per_minute)
         VALUES
            (:id, :tenant_id, :name, :source, :key_hash, :scopes, :status, :rate_limit_per_minute)'
    )->execute([
        'id' => $apiClientId,
        'tenant_id' => 'fidepuntos',
        'name' => 'Policy rate limiter',
        'source' => 'pos',
        'key_hash' => hash('sha256', $apiKey),
        'scopes' => json_encode(['program:read']),
        'status' => 'active',
        'rate_limit_per_minute' => 2,
    ]);
    $repository->authenticateExternalClient($apiKey, 'program:read');
    $repository->authenticateExternalClient($apiKey, 'program:read');
    $rateLimited = $expectException(
        fn() => $repository->authenticateExternalClient($apiKey, 'program:read'),
        'limite de solicitudes'
    );
    $rateStateStmt = $pdo->prepare(
        'SELECT c.request_count,
                COALESCE(u.request_count, 0) AS usage_count
         FROM loyalty_api_rate_limit_counters c
         LEFT JOIN loyalty_api_usage_daily u
           ON u.tenant_id = c.tenant_id
          AND u.api_client_id = c.api_client_id
          AND u.usage_date = CURRENT_DATE
         WHERE c.tenant_id = :tenant_id AND c.api_client_id = :api_client_id'
    );
    $rateStateStmt->execute(['tenant_id' => 'fidepuntos', 'api_client_id' => $apiClientId]);
    $rateState = $rateStateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $report['checks']['external_client_rate_limit'] = [
        'passed' => !empty($rateLimited['blocked'])
            && (int)($rateState['request_count'] ?? 0) === 3
            && (int)($rateState['usage_count'] ?? 0) === 3,
    ];

    $invalidRewardCases = [
        'missing_points' => [['name' => 'Invalido', 'stock' => 1], 'obligatorios'],
        'missing_stock' => [['name' => 'Invalido', 'pointsCost' => 1], 'obligatorios'],
        'coercive_points' => [['name' => 'Invalido', 'pointsCost' => '1abc', 'stock' => 1], 'entero'],
        'decimal_points' => [['name' => 'Invalido', 'pointsCost' => '1.5', 'stock' => 1], 'entero'],
        'negative_points' => [['name' => 'Invalido', 'pointsCost' => -1, 'stock' => 1], 'entre'],
        'overflow_points' => [['name' => 'Invalido', 'pointsCost' => '2147483648', 'stock' => 1], 'limite'],
        'coercive_stock' => [['name' => 'Invalido', 'pointsCost' => 1, 'stock' => '2abc'], 'entero'],
        'negative_stock' => [['name' => 'Invalido', 'pointsCost' => 1, 'stock' => -1], 'entre'],
    ];
    $invalidRewardResults = [];
    foreach ($invalidRewardCases as $case => [$invalidPayload, $message]) {
        $invalidRewardResults[$case] = $expectException(
            fn() => $repository->createReward($invalidPayload, 'policy-script'),
            $message
        );
    }
    $report['checks']['reward_create_strict_integers'] = [
        'passed' => array_reduce(
            $invalidRewardResults,
            static fn(bool $passed, array $result): bool => $passed && $result['blocked'],
            true
        ),
        'cases' => $invalidRewardResults,
    ];

    $pdo->beginTransaction();
    try {
        $atomicReward = $repository->createReward([
            'name' => 'Premio atomico temporal ' . $token,
            'pointsCost' => 1,
            'stock' => 0,
            'reason' => 'qa-atomic-reward-create-' . $token,
        ], 'policy-script');
        $atomicRewardId = (string)$atomicReward['id'];
        $atomicRewardAuditInside = (int)$pdo->query(
            "SELECT COUNT(*) FROM loyalty_audit_events WHERE subject_id = " . $pdo->quote($atomicRewardId)
        )->fetchColumn();
        $pdo->rollBack();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $atomicRewardAfterStmt = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM loyalty_rewards WHERE tenant_id = :reward_tenant_id AND id = :reward_id)
              + (SELECT COUNT(*) FROM loyalty_audit_events WHERE tenant_id = :audit_tenant_id AND subject_id = :audit_subject_id)'
    );
    $atomicRewardAfterStmt->execute([
        'reward_tenant_id' => 'fidepuntos',
        'reward_id' => $atomicRewardId,
        'audit_tenant_id' => 'fidepuntos',
        'audit_subject_id' => $atomicRewardId,
    ]);
    $report['checks']['reward_create_atomic_transaction'] = [
        'passed' => $atomicRewardAuditInside === 1 && (int)$atomicRewardAfterStmt->fetchColumn() === 0,
    ];

    $reward = $repository->createReward([
        'name' => 'Control antifraude ' . $token,
        'description' => 'Premio temporal para validar reglas internas',
        'pointsCost' => 10,
        'stock' => 1,
    ]);
    $createdRewardIds[] = (string)$reward['id'];

    $atomicUpdateReason = 'qa-atomic-reward-update-' . $token;
    $pdo->beginTransaction();
    try {
        $atomicUpdatedReward = $repository->updateReward((string)$reward['id'], [
            'stock' => 2,
            'reason' => $atomicUpdateReason,
        ], 'policy-script');
        $atomicUpdateAuditInside = (int)$pdo->query(
            "SELECT COUNT(*) FROM loyalty_audit_events WHERE reason = " . $pdo->quote($atomicUpdateReason)
        )->fetchColumn();
        $pdo->rollBack();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $atomicRewardAfterUpdate = $repository->rewardDetail((string)$reward['id'])['reward'] ?? [];
    $atomicUpdateAuditStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM loyalty_audit_events WHERE tenant_id = :tenant_id AND reason = :reason'
    );
    $atomicUpdateAuditStmt->execute(['tenant_id' => 'fidepuntos', 'reason' => $atomicUpdateReason]);
    $report['checks']['reward_update_atomic_transaction'] = [
        'passed' => (int)($atomicUpdatedReward['stock'] ?? -1) === 2
            && $atomicUpdateAuditInside === 1
            && (int)($atomicRewardAfterUpdate['stock'] ?? -1) === 1
            && (int)$atomicUpdateAuditStmt->fetchColumn() === 0,
    ];

    $invalidRewardUpdates = [];
    foreach ([
        'coercive_points' => [['pointsCost' => '10abc'], 'entero'],
        'decimal_points' => [['pointsCost' => '10.5'], 'entero'],
        'negative_points' => [['pointsCost' => -1], 'entre'],
        'overflow_points' => [['pointsCost' => '2147483648'], 'limite'],
        'coercive_stock' => [['stock' => '1abc'], 'entero'],
        'negative_stock' => [['stock' => -1], 'entre'],
    ] as $case => [$invalidPayload, $message]) {
        $invalidRewardUpdates[$case] = $expectException(
            fn() => $repository->updateReward((string)$reward['id'], $invalidPayload, 'policy-script'),
            $message
        );
    }
    $rewardAfterInvalidUpdates = $repository->rewardDetail((string)$reward['id'])['reward'] ?? [];
    $report['checks']['reward_update_strict_integers'] = [
        'passed' => array_reduce(
            $invalidRewardUpdates,
            static fn(bool $passed, array $result): bool => $passed && $result['blocked'],
            true
        )
            && (int)($rewardAfterInvalidUpdates['points_cost'] ?? 0) === 10
            && (int)($rewardAfterInvalidUpdates['stock'] ?? -1) === 1,
        'cases' => $invalidRewardUpdates,
    ];

    $noCard = $expectException(
        fn() => $repository->redeemReward([
            'memberId' => $member['id'],
            'rewardId' => $reward['id'],
            'commandId' => 'policy-no-card-' . $token,
        ], 'policy-script'),
        'tarjeta digital'
    );
    $report['checks']['redemption_without_card_blocked'] = $noCard;

    $repository->updateWallet((string)$member['id'], ['platform' => 'google']);

    $portalToken = 'lps_' . bin2hex(random_bytes(32));
    $portalSessionId = 'policy_portal_' . bin2hex(random_bytes(8));
    $pdo->prepare(
        'INSERT INTO loyalty_portal_sessions
            (id, tenant_id, member_id, token_hash, expires_at, exchanged_at)
         VALUES (:id, :tenant_id, :member_id, :token_hash, NOW() + INTERVAL \'15 minutes\', NOW())'
    )->execute([
        'id' => $portalSessionId,
        'tenant_id' => 'fidepuntos',
        'member_id' => $member['id'],
        'token_hash' => hash('sha256', $portalToken),
    ]);
    $issueNonce = new ReflectionMethod($repository, 'issuePortalFormNonce');
    $consumeNonce = new ReflectionMethod($repository, 'consumePortalFormNonce');
    $formNonce = $issueNonce->invoke($repository, $portalToken, 'claim:policy');
    $consumeNonce->invoke($repository, $portalToken, 'claim:policy', $formNonce);
    $nonceReplay = $expectException(
        fn() => $consumeNonce->invoke($repository, $portalToken, 'claim:policy', $formNonce),
        'utilizado'
    );
    $report['checks']['portal_form_nonce_one_time'] = [
        'passed' => str_starts_with((string)$formNonce, 'lfn_') && !empty($nonceReplay['blocked']),
    ];

    $redeemCommand = 'policy-redeem-' . $token;
    $createdCommandIds[] = $redeemCommand;
    $redemptionPayload = ['memberId' => $member['id'], 'rewardId' => $reward['id'], 'commandId' => $redeemCommand];
    $redemption = $repository->redeemReward($redemptionPayload, 'policy-script');
    $report['checks']['redemption_success'] = [
        'passed' => ($redemption['redemption']['status'] ?? null) === 'approved',
        'balance_after' => $redemption['balanceAfter'] ?? null,
    ];
    $redemptionReplay = $repository->redeemReward($redemptionPayload, 'policy-script');
    $redemptionMismatch = $expectException(
        fn() => $repository->redeemReward($redemptionPayload + ['unexpected' => 'payload-mismatch'], 'policy-script'),
        'payload diferente'
    );
    $redemptionCountStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM loyalty_redemptions
         WHERE tenant_id = :tenant_id AND member_id = :member_id AND reward_id = :reward_id'
    );
    $redemptionCountStmt->execute([
        'tenant_id' => 'fidepuntos',
        'member_id' => $member['id'],
        'reward_id' => $reward['id'],
    ]);
    $report['checks']['redemption_command_journal'] = [
        'passed' => ($redemptionReplay['redemption']['id'] ?? null) === ($redemption['redemption']['id'] ?? null)
            && !empty($redemptionMismatch['blocked'])
            && (int)$redemptionCountStmt->fetchColumn() === 1,
    ];
    $redemptionSource = new ReflectionMethod($repository, 'redemptionOperationSource');
    $redemptionLedgerSourceStmt = $pdo->prepare(
        'SELECT source FROM loyalty_point_ledger
         WHERE tenant_id = :tenant_id AND entry_type = :entry_type AND reference = :reference
         LIMIT 1'
    );
    $redemptionLedgerSourceStmt->execute([
        'tenant_id' => 'fidepuntos',
        'entry_type' => 'redemption',
        'reference' => (string)($redemption['redemption']['id'] ?? ''),
    ]);
    $report['checks']['redemption_source_attribution'] = [
        'passed' => $redemptionLedgerSourceStmt->fetchColumn() === 'dashboard'
            && $redemptionSource->invoke($repository, 'api:pos-client', 'pos') === 'pos'
            && $redemptionSource->invoke($repository, 'api:external-client', 'external') === 'api'
            && $redemptionSource->invoke($repository, 'customer:member', null) === 'customer_portal'
            && $redemptionSource->invoke($repository, 'tenant-user', null) === 'dashboard',
    ];

    $stockMember = $repository->createMember([
        'name' => 'Control Stock ' . $token,
        'accountId' => 'STK-' . $token,
        'email' => "control.stock.{$token}@tecnolts.com",
        'phone' => '097' . random_int(1000000, 9999999),
        'walletPlatform' => 'google',
    ], 'policy-script');
    $createdMemberIds[] = (string)$stockMember['id'];
    $repository->updateWallet((string)$stockMember['id'], ['platform' => 'google']);
    $stockAdjustmentCommand = 'policy-stock-fund-' . $token;
    $createdCommandIds[] = $stockAdjustmentCommand;
    $repository->adjustPoints([
        'memberId' => $stockMember['id'],
        'points' => 10,
        'adjustmentType' => 'correction',
        'reason' => 'Saldo temporal para validar stock agotado',
        'evidence' => 'qa-exercise:' . $token,
        'commandId' => $stockAdjustmentCommand,
    ], 'policy-script');
    $outOfStock = $expectException(
        fn() => $repository->redeemReward([
            'memberId' => $stockMember['id'],
            'rewardId' => $reward['id'],
            'commandId' => 'policy-stock-' . $token,
        ], 'policy-script'),
        'stock'
    );
    $report['checks']['stock_blocked'] = $outOfStock;

    $blockedMember = $repository->createMember([
        'name' => 'Control Bloqueado ' . $token,
        'accountId' => 'BLK-' . $token,
        'email' => "control.bloqueado.{$token}@tecnolts.com",
        'phone' => '098' . random_int(1000000, 9999999),
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
            'invoiceAmount' => '50.00',
            'commandId' => 'policy-blocked-' . $token,
        ], 'policy-script', $trustedFixture),
        'no esta activo'
    );
    $report['checks']['blocked_member_operation'] = $blockedOperation;

    $reverseCommand = 'policy-reverse-' . $token;
    $createdCommandIds[] = $reverseCommand;
    $reverse = $repository->reversePurchase('POL-FORMULA-' . $token, [
        'reason' => 'Ejercicio interno de reversa',
        'commandId' => $reverseCommand,
    ], 'policy-script');
    $report['checks']['purchase_reversal'] = [
        'passed' => ($reverse['pointsReversed'] ?? 0) > 0,
        'balance_after' => $reverse['balanceAfter'] ?? null,
    ];

    $debtMember = $repository->createMember([
        'name' => 'Control Deuda ' . $token,
        'accountId' => 'DEBT-' . $token,
        'email' => "control.deuda.{$token}@tecnolts.com",
        'phone' => '097' . random_int(1000000, 9999999),
        'walletPlatform' => 'none',
    ], 'policy-script');
    $createdMemberIds[] = (string)$debtMember['id'];
    $repository->updateWallet((string)$debtMember['id'], ['platform' => 'google']);
    $debtPurchaseReference = 'POL-DEBT-PURCHASE-' . $token;
    $debtFutureReference = 'POL-DEBT-FUTURE-' . $token;
    $createdReferences[] = $debtPurchaseReference;
    $createdReferences[] = $debtFutureReference;
    $debtPurchaseCommand = 'policy-debt-purchase-' . $token;
    $debtRedeemCommand = 'policy-debt-redeem-' . $token;
    $debtReverseCommand = 'policy-debt-reverse-' . $token;
    $debtFutureCommand = 'policy-debt-future-' . $token;
    array_push($createdCommandIds, $debtPurchaseCommand, $debtRedeemCommand, $debtReverseCommand, $debtFutureCommand);
    $debtPurchase = $repository->registerPurchase([
        'memberId' => $debtMember['id'],
        'invoiceNumber' => $debtPurchaseReference,
        'invoiceAmount' => '20.00',
        'commandId' => $debtPurchaseCommand,
    ], 'policy-script', $trustedFixture);
    $debtPoints = (int)$debtPurchase['pointsEarned'];
    $debtReward = $repository->createReward([
        'name' => 'Consumir saldo para deuda ' . $token,
        'description' => 'Ejercicio de reversa total',
        'pointsCost' => $debtPoints,
        'stock' => 1,
    ]);
    $createdRewardIds[] = (string)$debtReward['id'];
    $repository->redeemReward([
        'memberId' => $debtMember['id'],
        'rewardId' => $debtReward['id'],
        'commandId' => $debtRedeemCommand,
    ], 'policy-script');
    $debtReverse = $repository->reversePurchase($debtPurchaseReference, [
        'reason' => 'Reversa total luego de consumir los puntos',
        'commandId' => $debtReverseCommand,
    ], 'policy-script');
    $blockedDebtReward = $repository->createReward([
        'name' => 'Bloqueo por deuda ' . $token,
        'description' => 'No debe poder canjearse',
        'pointsCost' => 1,
        'stock' => 1,
    ]);
    $createdRewardIds[] = (string)$blockedDebtReward['id'];
    $debtBlocksRedemption = $expectException(
        fn() => $repository->redeemReward([
            'memberId' => $debtMember['id'],
            'rewardId' => $blockedDebtReward['id'],
            'commandId' => 'policy-debt-blocked-' . $token,
        ], 'policy-script'),
        'deuda de puntos'
    );
    $futurePurchase = $repository->registerPurchase([
        'memberId' => $debtMember['id'],
        'invoiceNumber' => $debtFutureReference,
        'invoiceAmount' => '20.00',
        'commandId' => $debtFutureCommand,
    ], 'policy-script', $trustedFixture);
    $reconciliationStmt = $pdo->prepare(
        'SELECT a.balance, a.points_debt, a.lifetime_points,
                COALESCE((SELECT SUM(l.points) FROM loyalty_point_ledger l
                          WHERE l.tenant_id = a.tenant_id AND l.member_id = a.member_id), 0) AS ledger_balance,
                COALESCE((SELECT SUM(d.points) FROM loyalty_debt_ledger d
                          WHERE d.tenant_id = a.tenant_id AND d.member_id = a.member_id), 0) AS ledger_debt
         FROM loyalty_point_accounts a
         WHERE a.tenant_id = :tenant_id AND a.member_id = :member_id'
    );
    $reconciliationStmt->execute(['tenant_id' => 'fidepuntos', 'member_id' => $debtMember['id']]);
    $reconciliation = $reconciliationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $report['checks']['reversal_debt_and_amortization'] = [
        'passed' => (int)($debtReverse['pointsReversed'] ?? -1) === 0
            && (int)($debtReverse['debtCreated'] ?? -1) === $debtPoints
            && (int)($debtReverse['balanceAfter'] ?? -1) === 0
            && !empty($debtBlocksRedemption['blocked'])
            && (int)($futurePurchase['debtPaid'] ?? -1) === $debtPoints
            && (int)($futurePurchase['pointsAvailable'] ?? -1) === 0
            && (int)($reconciliation['balance'] ?? -1) === (int)($reconciliation['ledger_balance'] ?? -2)
            && (int)($reconciliation['points_debt'] ?? -1) === (int)($reconciliation['ledger_debt'] ?? -2)
            && (int)($reconciliation['points_debt'] ?? -1) === 0,
        'state' => $reconciliation,
    ];

    $approvedMember = $repository->createMember([
        'name' => 'Control Reserva Aprobada ' . $token,
        'accountId' => 'APPROVED-' . $token,
        'email' => "control.approved.{$token}@tecnolts.com",
        'phone' => '096' . random_int(1000000, 9999999),
        'walletPlatform' => 'google',
    ], 'policy-script');
    $createdMemberIds[] = (string)$approvedMember['id'];
    $approvedReference = 'POL-APPROVED-REVERSAL-' . $token;
    $approvedPurchaseCommand = 'policy-approved-purchase-' . $token;
    $approvedReservationCommand = 'policy-approved-reservation-' . $token;
    $approvedReverseCommand = 'policy-approved-reverse-' . $token;
    $createdReferences[] = $approvedReference;
    array_push(
        $createdCommandIds,
        $approvedPurchaseCommand,
        $approvedReservationCommand,
        $approvedReverseCommand
    );
    $approvedPurchase = $repository->registerPurchase([
        'memberId' => $approvedMember['id'],
        'invoiceNumber' => $approvedReference,
        'invoiceAmount' => '25.00',
        'commandId' => $approvedPurchaseCommand,
    ], 'policy-script', $trustedFixture);
    $approvedReward = $repository->createReward([
        'name' => 'Reserva aprobada reversible ' . $token,
        'description' => 'Valida restauracion atomica de puntos y stock',
        'pointsCost' => (int)$approvedPurchase['pointsEarned'],
        'stock' => 1,
        'claimMode' => 'managed',
    ]);
    $createdRewardIds[] = (string)$approvedReward['id'];
    $reservePortal = new ReflectionMethod($repository, 'reservePortalRedemption');
    $approvedRedemptionId = (string)$reservePortal->invoke(
        $repository,
        $approvedMember,
        $approvedReward,
        'approved',
        'managed',
        ['exercise' => 'approved_reversal'],
        gmdate('c', time() + 900),
        null,
        $approvedReservationCommand,
        ['exercise' => 'approved_reversal']
    );
    $approvedReverse = $repository->reversePurchase($approvedReference, [
        'reason' => 'Reversa con reserva aprobada aun no entregada',
        'commandId' => $approvedReverseCommand,
    ], 'policy-script');
    $approvedStateStmt = $pdo->prepare(
        'SELECT r.status, w.stock, a.balance, a.points_debt,
                COALESCE((SELECT SUM(l.points) FROM loyalty_point_ledger l
                          WHERE l.tenant_id = a.tenant_id AND l.member_id = a.member_id), 0) AS ledger_balance
         FROM loyalty_redemptions r
         JOIN loyalty_rewards w ON w.tenant_id = r.tenant_id AND w.id = r.reward_id
         JOIN loyalty_point_accounts a ON a.tenant_id = r.tenant_id AND a.member_id = r.member_id
         WHERE r.tenant_id = :tenant_id AND r.id = :redemption_id'
    );
    $approvedStateStmt->execute([
        'tenant_id' => 'fidepuntos',
        'redemption_id' => $approvedRedemptionId,
    ]);
    $approvedState = $approvedStateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $report['checks']['approved_reservation_cancelled_on_purchase_reversal'] = [
        'passed' => in_array($approvedRedemptionId, $approvedReverse['cancelledReservations'] ?? [], true)
            && ($approvedState['status'] ?? null) === 'cancelled'
            && (int)($approvedState['stock'] ?? -1) === 1
            && (int)($approvedState['balance'] ?? -1) === 0
            && (int)($approvedState['points_debt'] ?? -1) === 0
            && (int)($approvedState['ledger_balance'] ?? -1) === 0,
        'state' => $approvedState,
    ];

    $rewardDeletion = $repository->deleteReward((string)$reward['id'], 'policy-script');
    $rewardAfterDeletion = $repository->rewardDetail((string)$reward['id'])['reward'] ?? [];
    $report['checks']['used_reward_archived_not_deleted'] = [
        'passed' => empty($rewardDeletion['deleted'])
            && !empty($rewardDeletion['archived'])
            && ($rewardAfterDeletion['status'] ?? null) === 'deleted',
        'result' => $rewardDeletion,
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
