<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Domain\ExternalApiAccessException;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use App\Modules\LoyaltyRewards\Domain\PurchaseVerificationException;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyRepository;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}
if (strtolower(trim((string)($_ENV['APP_ENV'] ?? $_ENV['ENTORNO_MODE'] ?? ''))) !== 'qa') {
    fwrite(STDERR, "Este ejercicio de seguridad externa solo puede ejecutarse en QA.\n");
    exit(2);
}

$tenantId = 'fidepuntos';
$tenantSlug = 'fidepuntos';
$segment = trim((string)($_ENV['PUBLIC_LOYALTY_SERVICE_SEGMENT'] ?? 'fidelizacion'), '/ ');
TenantContext::set(['id' => $tenantId, 'slug' => $tenantSlug, 'name' => 'Fidepuntos']);

$pdo = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
$repository = new LoyaltyRepository($pdo);
$token = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
$clientIds = [];
$memberIds = [];
$references = [];
$report = ['tenantId' => $tenantId, 'startedAt' => gmdate(DATE_ATOM), 'checks' => []];
$exitCode = 0;

$riskType = static function (callable $callback): ?string {
    try {
        $callback();
    } catch (PurchaseVerificationException $exception) {
        return $exception->riskType();
    }

    return null;
};
$accessBlocked = static function (callable $callback): bool {
    try {
        $callback();
    } catch (ExternalApiAccessException) {
        return true;
    }

    return false;
};
$signature = static function (
    string $credential,
    string $method,
    string $path,
    string $tenant,
    string $timestamp,
    string $nonce,
    string $body
): string {
    $canonical = implode("\n", [strtoupper($method), $path, $tenant, $timestamp, $nonce, hash('sha256', $body)]);

    return 'v1=' . hash_hmac('sha256', $canonical, $credential);
};
$deleteForIds = static function (\PDO $pdo, string $table, string $column, array $ids) use ($tenantId): void {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
    if ($ids === []) {
        return;
    }
    $holders = [];
    $params = ['tenant_id' => $tenantId];
    foreach ($ids as $index => $id) {
        $key = 'id_' . $index;
        $holders[] = ':' . $key;
        $params[$key] = $id;
    }
    $pdo->prepare(sprintf(
        'DELETE FROM %s WHERE tenant_id = :tenant_id AND %s IN (%s)',
        $table,
        $column,
        implode(', ', $holders)
    ))->execute($params);
};

$cleanup = static function () use ($pdo, &$clientIds, &$memberIds, &$references, $deleteForIds, $tenantId): void {
    $deleteForIds($pdo, 'loyalty_api_request_nonces', 'api_client_id', $clientIds);
    $deleteForIds($pdo, 'loyalty_idempotency_keys', 'api_client_id', $clientIds);
    $deleteForIds($pdo, 'loyalty_api_rate_limit_counters', 'api_client_id', $clientIds);
    $deleteForIds($pdo, 'loyalty_api_usage_daily', 'api_client_id', $clientIds);
    $deleteForIds($pdo, 'loyalty_command_journal', 'actor_id', array_map(static fn(string $id): string => 'api:' . $id, $clientIds));
    $deleteForIds($pdo, 'loyalty_risk_events', 'member_id', $memberIds);
    $deleteForIds($pdo, 'loyalty_risk_events', 'reference', $references);
    if ($clientIds !== []) {
        $holders = [];
        $params = ['tenant_id' => $tenantId];
        foreach ($clientIds as $index => $id) {
            $key = 'client_' . $index;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }
        $pdo->prepare(
            'DELETE FROM loyalty_risk_events
             WHERE tenant_id = :tenant_id
               AND metadata->>\'apiClientId\' IN (' . implode(', ', $holders) . ')'
        )->execute($params);
    }
    $deleteForIds($pdo, 'loyalty_audit_events', 'subject_id', array_merge($memberIds, $clientIds));
    $deleteForIds($pdo, 'loyalty_reversals', 'member_id', $memberIds);
    $deleteForIds($pdo, 'loyalty_debt_ledger', 'member_id', $memberIds);
    $deleteForIds($pdo, 'loyalty_point_ledger', 'member_id', $memberIds);
    $deleteForIds($pdo, 'loyalty_point_accounts', 'member_id', $memberIds);
    $deleteForIds($pdo, 'loyalty_members', 'id', $memberIds);
    $deleteForIds($pdo, 'loyalty_api_clients', 'id', $clientIds);
};

try {
    $posA = $repository->createApiClient([
        'name' => 'QA POS owner ' . $token,
        'source' => 'pos',
        'scopes' => ['purchases:write', 'purchases:reverse'],
        'rateLimitPerMinute' => 60,
    ], 'system:test:external-security');
    $posB = $repository->createApiClient([
        'name' => 'QA POS other ' . $token,
        'source' => 'pos',
        'scopes' => ['purchases:reverse'],
        'rateLimitPerMinute' => 60,
    ], 'system:test:external-security');
    $billing = $repository->createApiClient([
        'name' => 'QA Billing other ' . $token,
        'source' => 'billing',
        'scopes' => ['purchases:reverse'],
        'rateLimitPerMinute' => 60,
    ], 'system:test:external-security');
    $ecommerce = $repository->createApiClient([
        'name' => 'QA Ecommerce source ' . $token,
        'source' => 'ecommerce',
        'scopes' => ['purchases:write'],
        'rateLimitPerMinute' => 60,
    ], 'system:test:external-security');
    foreach ([$posA, $posB, $billing, $ecommerce] as $client) {
        $clientIds[] = (string)$client['id'];
    }

    $method = 'POST';
    $path = '/' . $tenantSlug . '/' . $segment . '/v1/purchases';
    $body = '{"exercise":"' . $token . '"}';
    $timestamp = (string)time();
    $nonce = 'qa-valid-' . bin2hex(random_bytes(12));
    $validSignature = $signature((string)$posA['apiKey'], $method, $path, $tenantId, $timestamp, $nonce, $body);
    $repository->verifySignedPosRequest($posA, (string)$posA['apiKey'], $method, $path, $body, $timestamp, $nonce, $validSignature);
    $report['checks']['valid_hmac'] = true;
    $report['checks']['nonce_replay'] = $riskType(fn() => $repository->verifySignedPosRequest(
        $posA,
        (string)$posA['apiKey'],
        $method,
        $path,
        $body,
        $timestamp,
        $nonce,
        $validSignature
    )) === 'pos_nonce_replayed';

    $expiredTimestamp = (string)(time() - 301);
    $expiredNonce = 'qa-expired-' . bin2hex(random_bytes(12));
    $report['checks']['expired_signature'] = $riskType(fn() => $repository->verifySignedPosRequest(
        $posA,
        (string)$posA['apiKey'],
        $method,
        $path,
        $body,
        $expiredTimestamp,
        $expiredNonce,
        $signature((string)$posA['apiKey'], $method, $path, $tenantId, $expiredTimestamp, $expiredNonce, $body)
    )) === 'pos_signature_expired';

    $invalidSignatureTimestamp = (string)time();
    $invalidSignatureNonce = 'qa-signature-' . bin2hex(random_bytes(12));
    $report['checks']['invalid_signature'] = $riskType(fn() => $repository->verifySignedPosRequest(
        $posA,
        (string)$posA['apiKey'],
        $method,
        $path,
        $body,
        $invalidSignatureTimestamp,
        $invalidSignatureNonce,
        'v1=' . str_repeat('0', 64)
    )) === 'pos_signature_invalid';

    foreach ([
        'cross_tenant_path' => '/otro-tenant/' . $segment . '/v1/purchases',
        'internal_path' => '/api/loyalty/v1/purchases',
    ] as $check => $invalidPath) {
        $invalidNonce = 'qa-path-' . bin2hex(random_bytes(12));
        $invalidTimestamp = (string)time();
        $report['checks'][$check] = $riskType(fn() => $repository->verifySignedPosRequest(
            $posA,
            (string)$posA['apiKey'],
            $method,
            $invalidPath,
            $body,
            $invalidTimestamp,
            $invalidNonce,
            $signature((string)$posA['apiKey'], $method, $invalidPath, $tenantId, $invalidTimestamp, $invalidNonce, $body)
        )) === 'pos_signature_tenant_mismatch';
    }
    $report['checks']['insufficient_scope'] = $accessBlocked(
        fn() => $repository->authenticateExternalClient((string)$posA['apiKey'], 'reports:read')
    );

    $member = $repository->createMember([
        'name' => 'QA External Security ' . $token,
        'accountId' => 'QASEC-' . $token,
        'email' => 'qa.external.' . $token . '@example.invalid',
        'phone' => '099' . random_int(1000000, 9999999),
        'walletPlatform' => 'none',
    ], 'system:test:external-security');
    $memberIds[] = (string)$member['id'];
    $crossSourceReference = 'QA-EXT-SOURCE-' . $token;
    $references[] = $crossSourceReference;
    $report['checks']['cross_source_purchase_blocked'] = $riskType(fn() => $repository->registerPurchase([
        'memberId' => $member['id'],
        'invoiceAmount' => '10.00',
        'invoiceNumber' => $crossSourceReference,
        'currency' => 'USD',
        'purchaseSource' => 'billing',
        'commandId' => 'qa-cross-source-purchase-' . $token,
    ], 'api:' . $ecommerce['id'], [
        'verified' => false,
        'type' => 'ecommerce',
        'clientId' => (string)$ecommerce['id'],
    ])) === 'purchase_source_client_mismatch';
    $reference = 'QA-EXT-' . $token;
    $references[] = $reference;
    $purchase = $repository->registerPurchase([
        'memberId' => $member['id'],
        'invoiceAmount' => '10.00',
        'invoiceNumber' => $reference,
        'currency' => 'USD',
        'commandId' => 'qa-external-purchase-' . $token,
    ], 'api:' . $posA['id'], [
        'verified' => true,
        'type' => 'pos',
        'clientId' => (string)$posA['id'],
    ]);
    $report['checks']['pos_purchase_attributed'] = ($purchase['sourceVerification']['clientId'] ?? null) === $posA['id'];

    $report['checks']['cross_client_reversal_blocked'] = $riskType(fn() => $repository->reversePurchase(
        $reference,
        ['reason' => 'Intento cruzado QA', 'commandId' => 'qa-cross-client-' . $token],
        'api:' . $posB['id'],
        ['clientId' => (string)$posB['id'], 'source' => 'pos']
    )) === 'purchase_reversal_source_mismatch';
    $report['checks']['cross_source_reversal_blocked'] = $riskType(fn() => $repository->reversePurchase(
        $reference,
        ['reason' => 'Intento fuente cruzada QA', 'commandId' => 'qa-cross-source-' . $token],
        'api:' . $billing['id'],
        ['clientId' => (string)$billing['id'], 'source' => 'billing']
    )) === 'purchase_reversal_source_mismatch';

    $rotated = $repository->rotateApiClient((string)$posA['id'], ['reason' => 'Rotacion controlada ejercicio QA'], 'system:test:external-security');
    $replacement = $rotated['replacement'];
    $clientIds[] = (string)$replacement['id'];
    $report['checks']['revoked_key_blocked'] = $accessBlocked(
        fn() => $repository->authenticateExternalClient((string)$posA['apiKey'], 'purchases:reverse')
    );
    $reversal = $repository->reversePurchase(
        $reference,
        ['reason' => 'Reversa por descendiente rotado QA', 'commandId' => 'qa-rotation-reversal-' . $token],
        'api:' . $replacement['id'],
        ['clientId' => (string)$replacement['id'], 'source' => 'pos']
    );
    $report['checks']['rotation_descendant_reversal_allowed'] = ($reversal['apiClientId'] ?? null) === $replacement['id']
        && ($reversal['source'] ?? null) === 'pos';

    $failed = array_keys(array_filter($report['checks'], static fn(bool $passed): bool => !$passed));
    $report['passed'] = $failed === [];
    $report['failed'] = $failed;
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    if ($failed !== []) {
        $exitCode = 1;
    }
} finally {
    $cleanup();
}

exit($exitCode);
