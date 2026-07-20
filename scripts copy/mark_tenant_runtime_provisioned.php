<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistryStore;
use App\Repositories\SettingsRepository;

function failReconciliation(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

if (strtolower(trim((string)($_ENV['DB_CONNECTION_ROLE'] ?? getenv('DB_CONNECTION_ROLE') ?: ''))) !== 'worker') {
    failReconciliation('Tenant reconciliation receipts require the dedicated DB worker role.');
}
$identityWorker = trim((string)($_ENV['DB_WORKER_USERNAME_IDENTITY_PLATFORM'] ?? getenv('DB_WORKER_USERNAME_IDENTITY_PLATFORM') ?: ''));
if ($identityWorker === ''
    || trim((string)($_ENV['DB_WORKER_PASSWORD_IDENTITY_PLATFORM'] ?? getenv('DB_WORKER_PASSWORD_IDENTITY_PLATFORM') ?: '')) === '') {
    failReconciliation('Tenant reconciliation requires the dedicated IdentityPlatform worker credential.');
}

$rawReceipt = stream_get_contents(STDIN, 1024 * 1024 + 1);
if (!is_string($rawReceipt) || $rawReceipt === '' || strlen($rawReceipt) > 1024 * 1024) {
    failReconciliation('Missing or oversized tenant reconciliation receipt.');
}
$receipt = json_decode($rawReceipt, true);
if (!is_array($receipt)
    || ($receipt['version'] ?? null) !== 2
    || !is_array($receipt['tenants'] ?? null)) {
    failReconciliation('Invalid tenant reconciliation receipt.');
}
$state = strtolower(trim((string)($receipt['state'] ?? '')));
if (!in_array($state, ['ready', 'partial', 'pending_dns', 'error'], true)) {
    failReconciliation('Unsupported tenant reconciliation state.');
}
$receiptRegistryRevision = $receipt['registryRevision'] ?? null;
if (!is_int($receiptRegistryRevision) || $receiptRegistryRevision < 1) {
    failReconciliation('Receipt v2 requires a positive registryRevision.');
}
if (($receipt['gatewayApplied'] ?? null) !== true) {
    failReconciliation('Reconciliation receipts require applied gateway state.');
}
if (($receipt['syncContractVersion'] ?? null) !== 2
    || !preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($receipt['gatewayDesiredHash'] ?? ''))))
    || !preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($receipt['managedInventoryIdHash'] ?? ''))))
    || !preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($receipt['managedInventoryContentHash'] ?? ''))))
    || !preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($receipt['syncInputHash'] ?? ''))))
    || !is_int($receipt['managedResourceCount'] ?? null)
    || $receipt['managedResourceCount'] < 1
    || !is_int($receipt['applyWrites'] ?? null)
    || $receipt['applyWrites'] < 0
    || !is_int($receipt['routeCount'] ?? null)
    || $receipt['routeCount'] < 1
    || ($receipt['streamRoutesVerifiedEmpty'] ?? null) !== true) {
    failReconciliation('Receipt v2 lacks exact managed gateway evidence.');
}
try {
    $appliedAt = new DateTimeImmutable((string)($receipt['appliedAt'] ?? ''));
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $receiptAge = $nowUtc->getTimestamp() - $appliedAt->getTimestamp();
    if ($receiptAge < -300 || $receiptAge > 900) {
        failReconciliation('Reconciliation receipt is stale or in the future.');
    }
} catch (Throwable) {
    failReconciliation('Reconciliation receipt timestamp is invalid.');
}

$allowedFailureCodes = [
    'EDGE_DNS_UNVERIFIED',
    'EDGE_TLS_UNVERIFIED',
    'EDGE_READINESS_UNVERIFIED',
    'EDGE_VERIFICATION_FAILED',
    'DNS_OR_ACME_PENDING',
    'GATEWAY_RECONCILIATION_FAILED',
];
$receiptTenants = [];
foreach ($receipt['tenants'] as $entry) {
    if (!is_array($entry)) {
        failReconciliation('Receipt contains an invalid tenant result.');
    }
    $tenantId = strtolower(trim((string)($entry['id'] ?? '')));
    $desiredHash = strtolower(trim((string)($entry['desiredStateHash'] ?? '')));
    $tenantState = strtolower(trim((string)($entry['state'] ?? '')));
    $failureCode = strtoupper(trim((string)($entry['failureCode'] ?? '')));
    if ($tenantId === ''
        || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $tenantId)
        || !preg_match('/^[a-f0-9]{64}$/', $desiredHash)
        || !in_array($tenantState, ['ready', 'pending_dns', 'error'], true)
        || ($tenantState === 'ready' && $failureCode !== '')
        || ($failureCode !== '' && !in_array($failureCode, $allowedFailureCodes, true))
        || isset($receiptTenants[$tenantId])) {
        failReconciliation('Receipt contains an invalid/duplicate tenant identity, hash or state.');
    }
    $receiptTenants[$tenantId] = [
        'desiredStateHash' => $desiredHash,
        'state' => $tenantState,
        'failureCode' => $failureCode,
    ];
}
if ($receiptTenants === []) {
    failReconciliation('Receipt must contain at least one active tenant result.');
}
$readyTenantIds = array_keys(array_filter(
    $receiptTenants,
    static fn(array $entry): bool => $entry['state'] === 'ready'
));
$errorTenantCount = count(array_filter(
    $receiptTenants,
    static fn(array $entry): bool => $entry['state'] === 'error'
));
$tenantCount = count($receiptTenants);
$readyTenantCount = count($readyTenantIds);
$expectedOverallState = $readyTenantCount === $tenantCount
    ? 'ready'
    : ($readyTenantCount > 0
        ? 'partial'
        : ($errorTenantCount === $tenantCount ? 'error' : 'pending_dns'));
if ($state !== $expectedOverallState
    || (($receipt['tlsVerified'] ?? null) === true) !== ($expectedOverallState === 'ready')) {
    failReconciliation('Receipt v2 aggregate state/TLS result differs from tenant results.');
}
if (!preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)($receipt['registryDesiredSetHash'] ?? ''))))) {
    failReconciliation('Receipt v2 requires a canonical registry desired-set hash.');
}

$normalizeEnvironment = static function (string $value): string {
    $normalized = strtolower(trim($value));
    return in_array($normalized, ['prod', 'production'], true) ? 'production' : $normalized;
};
$receiptEnvironment = $normalizeEnvironment((string)($receipt['environment'] ?? ''));
$runtimeEnvironment = $normalizeEnvironment((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: ''));
if (!in_array($receiptEnvironment, ['qa', 'production'], true) || $receiptEnvironment !== $runtimeEnvironment) {
    failReconciliation('Tenant reconciliation receipt environment does not match runtime.');
}

$edgeDomainsByTenant = [];
if ($readyTenantIds !== []) {
    $edge = $receipt['edgeVerification'] ?? null;
    if (
        !is_array($edge)
        || ($edge['version'] ?? null) !== 2
        || ($edge['registryRevision'] ?? null) !== $receiptRegistryRevision
        || !is_array($edge['domains'] ?? null)
    ) {
        failReconciliation('Ready receipts require DNS, HTTPS and SNI edge evidence.');
    }
    try {
        $verifiedAt = new DateTimeImmutable((string)($edge['verifiedAt'] ?? ''));
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $age = $nowUtc->getTimestamp() - $verifiedAt->getTimestamp();
        if ($age < -300 || $age > 900) {
            failReconciliation('Edge verification timestamp is stale or in the future.');
        }
    } catch (Throwable $exception) {
        failReconciliation('Edge verification timestamp is invalid.');
    }

    if ($receiptEnvironment === 'qa') {
        $expectedGatewayIps = is_array($edge['expectedGatewayIps'] ?? null) ? $edge['expectedGatewayIps'] : [];
        if (
            ($edge['verificationScope'] ?? null) !== 'qa-local-explicit-resolve'
            || ($edge['routeVerified'] ?? null) !== true
            || $expectedGatewayIps === []
        ) {
            failReconciliation('QA ready receipts require explicit local HTTPS/SNI route evidence.');
        }
        $qaGatewayIps = [];
        foreach ($expectedGatewayIps as $ip) {
            $ip = trim((string)$ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || isset($qaGatewayIps[$ip])) {
                failReconciliation('QA edge evidence contains an invalid/duplicate gateway IP.');
            }
            $qaGatewayIps[$ip] = true;
        }
        foreach ($edge['domains'] as $domainEvidence) {
            if (!is_array($domainEvidence)) {
                failReconciliation('QA edge domain evidence is invalid.');
            }
            $tenantId = strtolower(trim((string)($domainEvidence['tenantId'] ?? '')));
            $domain = strtolower(trim((string)($domainEvidence['domain'] ?? '')));
            $desiredHash = strtolower(trim((string)($domainEvidence['desiredStateHash'] ?? '')));
            $probe = is_array($domainEvidence['probe'] ?? null) ? $domainEvidence['probe'] : [];
            $connectIp = trim((string)($probe['connectIp'] ?? ''));
            $certificateHash = strtolower(trim((string)($probe['certificateSha256'] ?? '')));
            $registryStatus = strtolower(trim((string)($probe['tenantRegistryStatus'] ?? '')));
            $resolvedTenantId = strtolower(trim((string)($probe['resolvedTenantId'] ?? '')));
            $resolvedDesiredHash = strtolower(trim((string)($probe['resolvedDesiredStateHash'] ?? '')));
            $resolvedRegistryRevision = $probe['resolvedRegistryRevision'] ?? null;
            $httpStatus = (int)($probe['httpStatus'] ?? 0);
            $canonicalHost = strtolower(trim((string)($domainEvidence['canonicalHost'] ?? '')));
            $domainRole = strtolower(trim((string)($domainEvidence['domainRole'] ?? '')));
            $redirectLocation = trim((string)($probe['redirectLocation'] ?? ''));
            $redirectParts = $redirectLocation !== '' ? parse_url($redirectLocation) : false;
            $directHealthVerified = $httpStatus === 200
                && in_array($registryStatus, ['confirmado', 'snapshot_degradado'], true)
                && $resolvedTenantId === $tenantId
                && hash_equals($desiredHash, $resolvedDesiredHash)
                && $resolvedRegistryRevision === $receiptRegistryRevision;
            $canonicalRedirectVerified = in_array($httpStatus, [301, 308], true)
                && $registryStatus === 'redirect-verified'
                && $domainRole === 'canonical-redirect'
                && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $canonicalHost)
                && is_array($redirectParts)
                && strtolower((string)($redirectParts['scheme'] ?? '')) === 'https'
                && strtolower((string)($redirectParts['host'] ?? '')) === $canonicalHost;
            if (
                !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $tenantId)
                || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)
                || !preg_match('/^[a-f0-9]{64}$/', $desiredHash)
                || !isset($receiptTenants[$tenantId])
                || !hash_equals($receiptTenants[$tenantId]['desiredStateHash'], $desiredHash)
                || !isset($qaGatewayIps[$connectIp])
                || strtolower(trim((string)($probe['sni'] ?? ''))) !== $domain
                || !preg_match('/^[a-f0-9]{64}$/', $certificateHash)
                || (!$directHealthVerified && !$canonicalRedirectVerified)
                || isset($edgeDomainsByTenant[$tenantId][$domain])
            ) {
                failReconciliation('QA HTTPS/SNI route evidence is incomplete.');
            }
            foreach ((array)($domainEvidence['resolvedIps'] ?? []) as $resolvedIp) {
                if (filter_var(trim((string)$resolvedIp), FILTER_VALIDATE_IP) === false) {
                    failReconciliation('QA DNS evidence contains an invalid address.');
                }
            }
            $edgeDomainsByTenant[$tenantId][$domain] = $desiredHash;
        }
    } else {
        if (
            !is_array($edge['expectedLoadBalancerIps'] ?? null)
        ) {
            failReconciliation('Ready receipts require DNS, HTTPS and SNI edge evidence.');
        }
        $expectedIps = [];
        foreach ($edge['expectedLoadBalancerIps'] as $ip) {
            $ip = trim((string)$ip);
            if (
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                || isset($expectedIps[$ip])
            ) {
                failReconciliation('Edge evidence contains invalid/duplicate public LB IP.');
            }
            $expectedIps[$ip] = true;
        }
        if (count($expectedIps) < 2) {
            failReconciliation('Edge evidence requires at least two public LB IPs.');
        }

        foreach ($edge['domains'] as $domainEvidence) {
            if (!is_array($domainEvidence)) {
                failReconciliation('Edge domain evidence is invalid.');
            }
            $tenantId = strtolower(trim((string)($domainEvidence['tenantId'] ?? '')));
            $domain = strtolower(trim((string)($domainEvidence['domain'] ?? '')));
            $desiredHash = strtolower(trim((string)($domainEvidence['desiredStateHash'] ?? '')));
            $resolvedIps = is_array($domainEvidence['resolvedIps'] ?? null) ? $domainEvidence['resolvedIps'] : [];
            $probes = is_array($domainEvidence['probes'] ?? null) ? $domainEvidence['probes'] : [];
            $resolverEvidence = is_array($domainEvidence['resolverEvidence'] ?? null) ? $domainEvidence['resolverEvidence'] : [];
            $canonicalHost = strtolower(trim((string)($domainEvidence['canonicalHost'] ?? '')));
            $domainRole = strtolower(trim((string)($domainEvidence['domainRole'] ?? '')));
            if (
                !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $tenantId)
                || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)
                || !preg_match('/^[a-f0-9]{64}$/', $desiredHash)
                || !isset($receiptTenants[$tenantId])
                || !hash_equals($receiptTenants[$tenantId]['desiredStateHash'], $desiredHash)
                || count($resolvedIps) < 2
                || count($resolverEvidence) < 2
            ) {
                failReconciliation('Edge domain evidence lacks tenant/hash/DNS quorum.');
            }
            $resolved = [];
            foreach ($resolvedIps as $ip) {
                $ip = trim((string)$ip);
                if (!isset($expectedIps[$ip]) || isset($resolved[$ip])) {
                    failReconciliation('Edge DNS resolved outside the authorized LB set.');
                }
                $resolved[$ip] = true;
            }
            $resolverNames = [];
            foreach ($resolverEvidence as $resolver) {
                $name = strtolower(trim((string)($resolver['resolver'] ?? '')));
                $answers = is_array($resolver['resolvedIps'] ?? null) ? $resolver['resolvedIps'] : [];
                if ($name === '' || isset($resolverNames[$name]) || count($answers) < 2) {
                    failReconciliation('Edge evidence requires independent DNS resolvers.');
                }
                foreach ($answers as $answer) {
                    if (!isset($resolved[trim((string)$answer)])) {
                        failReconciliation('Resolver evidence does not match authorized DNS results.');
                    }
                }
                $resolverNames[$name] = true;
            }
            $probed = [];
            foreach ($probes as $probe) {
                $ip = trim((string)($probe['ip'] ?? ''));
                $certificateHash = strtolower(trim((string)($probe['certificateSha256'] ?? '')));
                $registryStatus = strtolower(trim((string)($probe['tenantRegistryStatus'] ?? '')));
                $resolvedTenantId = strtolower(trim((string)($probe['resolvedTenantId'] ?? '')));
                $resolvedDesiredHash = strtolower(trim((string)($probe['resolvedDesiredStateHash'] ?? '')));
                $resolvedRegistryRevision = $probe['resolvedRegistryRevision'] ?? null;
                $httpStatus = (int)($probe['httpStatus'] ?? 0);
                $redirectLocation = trim((string)($probe['redirectLocation'] ?? ''));
                $redirectParts = $redirectLocation !== '' ? parse_url($redirectLocation) : false;
                $directHealthVerified = $httpStatus === 200
                    && in_array($registryStatus, ['confirmado', 'snapshot_degradado'], true)
                    && $resolvedTenantId === $tenantId
                    && hash_equals($desiredHash, $resolvedDesiredHash)
                    && $resolvedRegistryRevision === $receiptRegistryRevision;
                $canonicalRedirectVerified = in_array($httpStatus, [301, 308], true)
                    && $registryStatus === 'redirect-verified'
                    && $domainRole === 'canonical-redirect'
                    && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $canonicalHost)
                    && is_array($redirectParts)
                    && strtolower((string)($redirectParts['scheme'] ?? '')) === 'https'
                    && strtolower((string)($redirectParts['host'] ?? '')) === $canonicalHost;
                if (
                    !isset($resolved[$ip])
                    || isset($probed[$ip])
                    || !preg_match('/^[a-f0-9]{64}$/', $certificateHash)
                    || (!$directHealthVerified && !$canonicalRedirectVerified)
                ) {
                    failReconciliation('Edge HTTPS/SNI probe evidence is incomplete.');
                }
                $probed[$ip] = true;
            }
            if (array_keys($probed) !== array_keys($resolved)) {
                failReconciliation('Every resolved LB IP must pass HTTPS/SNI.');
            }
            if (isset($edgeDomainsByTenant[$tenantId][$domain])) {
                failReconciliation('Edge evidence contains duplicate tenant/domain.');
            }
            $edgeDomainsByTenant[$tenantId][$domain] = $desiredHash;
        }
    }
}

foreach ($readyTenantIds as $tenantId) {
    $desiredHash = $receiptTenants[$tenantId]['desiredStateHash'];
    if (!isset($edgeDomainsByTenant[$tenantId]) || $edgeDomainsByTenant[$tenantId] === []) {
        failReconciliation('Ready tenant result lacks edge evidence.');
    }
    foreach ($edgeDomainsByTenant[$tenantId] as $edgeHash) {
        if (!hash_equals($desiredHash, $edgeHash)) {
            failReconciliation('Edge evidence desired hash differs from receipt.');
        }
    }
}

$db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
$db->beginTransaction();
try {
    $db->query("SELECT pg_advisory_xact_lock(hashtextextended('paramascotasec:tenant-runtime-registry:v1', 0))");
    $settings = new SettingsRepository();
    $tenantAccess = new TenantAccessService($settings);
    $registryStore = new TenantRuntimeRegistryStore();
    $registryState = $registryStore->getState();
    if ($registryState['revision'] !== $receiptRegistryRevision) {
        throw new RuntimeException('STALE_TENANT_RECONCILIATION_RECEIPT');
    }
    $overrides = $registryState['registry'];
    if (!is_array($overrides)) {
        $overrides = ['version' => 1, 'tenants' => []];
    }
    $overrides['version'] = 1;
    if (!isset($overrides['tenants']) || !is_array($overrides['tenants'])) {
        $overrides['tenants'] = [];
    }

    // The gateway receipt is built from the effective registry (static tenant
    // defaults merged with the persisted overrides). Recompute that exact view
    // while holding the same advisory lock used by tenant mutations. Hashing a
    // sparse override directly would reject valid receipts whenever domains or
    // mandatory modules are inherited from config/tenants.php.
    $configuredPath = __DIR__ . '/../config/tenants.php';
    $configuredTenants = is_readable($configuredPath) ? require $configuredPath : [];
    if (!is_array($configuredTenants)) {
        throw new RuntimeException('TENANT_CONFIG_INVALID');
    }
    $effectiveRegistry = TenantRuntimeRegistry::exportWithOverrides($configuredTenants, $overrides);
    $effectiveTenants = [];
    foreach (($effectiveRegistry['tenants'] ?? []) as $effectiveTenant) {
        if (!is_array($effectiveTenant)) {
            throw new RuntimeException('TENANT_EFFECTIVE_REGISTRY_INVALID');
        }
        $effectiveTenantId = strtolower(trim((string)($effectiveTenant['id'] ?? '')));
        $effectiveHash = strtolower(trim((string)($effectiveTenant['desiredStateHash'] ?? '')));
        if ($effectiveTenantId === ''
            || !preg_match('/^[a-z0-9-]+$/', $effectiveTenantId)
            || !preg_match('/^[a-f0-9]{64}$/', $effectiveHash)
            || isset($effectiveTenants[$effectiveTenantId])) {
            throw new RuntimeException('TENANT_EFFECTIVE_REGISTRY_INVALID');
        }
        $effectiveTenants[$effectiveTenantId] = $effectiveTenant;
    }
    ksort($effectiveTenants, SORT_STRING);
    ksort($receiptTenants, SORT_STRING);
    if (array_keys($effectiveTenants) !== array_keys($receiptTenants)) {
        throw new RuntimeException('STALE_TENANT_RECONCILIATION_RECEIPT');
    }

    $effectiveSetParts = [];
    foreach ($effectiveTenants as $effectiveTenantId => $effectiveTenant) {
        $effectiveSetParts[] = $effectiveTenantId . ':'
            . strtolower(trim((string)($effectiveTenant['desiredStateHash'] ?? '')));
    }
    $effectiveSetHash = hash('sha256', implode("\n", $effectiveSetParts));
    if (!hash_equals(
            $effectiveSetHash,
            strtolower(trim((string)($receipt['registryDesiredSetHash'] ?? '')))
        )) {
        throw new RuntimeException('STALE_TENANT_RECONCILIATION_RECEIPT');
    }

    $updated = [];
    $entitlementsChanged = [];
    $registryWrite = false;
    $now = gmdate('c');
    foreach ($receiptTenants as $tenantId => $tenantResult) {
        $receiptDesiredHash = $tenantResult['desiredStateHash'];
        $tenantState = $tenantResult['state'];
        $effectiveTenant = $effectiveTenants[$tenantId];
        $expectedHash = strtolower(trim((string)$effectiveTenant['desiredStateHash']));
        if (!hash_equals($expectedHash, $receiptDesiredHash)) {
            throw new RuntimeException('STALE_TENANT_RECONCILIATION_RECEIPT');
        }

        $expectedDomains = array_values(array_unique(array_filter(array_map(
            static fn ($domain): string => strtolower(trim((string)$domain)),
            is_array($effectiveTenant['domains'] ?? null) ? $effectiveTenant['domains'] : []
        ))));
        sort($expectedDomains, SORT_STRING);
        if ($tenantState === 'ready') {
            $verifiedDomains = array_keys($edgeDomainsByTenant[$tenantId] ?? []);
            sort($verifiedDomains, SORT_STRING);
            if ($expectedDomains === [] || $verifiedDomains !== $expectedDomains) {
                throw new RuntimeException('TENANT_EDGE_DOMAIN_EVIDENCE_MISMATCH');
            }
        }

        $existingTenant = isset($overrides['tenants'][$tenantId])
            && is_array($overrides['tenants'][$tenantId])
            ? $overrides['tenants'][$tenantId]
            : null;
        if ($tenantState === 'ready') {
            $effectiveModules = is_array($effectiveTenant['enabledModules'] ?? null)
                ? $effectiveTenant['enabledModules']
                : [];
            if ($tenantAccess->syncTenantModuleEntitlements(
                array_replace($existingTenant ?? [], [
                    'id' => $tenantId,
                    'slug' => (string)($effectiveTenant['slug'] ?? $tenantId),
                    'status' => (string)($effectiveTenant['status'] ?? 'active'),
                    'domains' => $expectedDomains,
                    'enabled_modules' => $effectiveModules,
                ]),
                $effectiveModules,
                'gateway-reconciler'
            )) {
                $entitlementsChanged[] = $tenantId;
            }

            $alreadyReady = is_array($existingTenant)
                && strtolower(trim((string)($existingTenant['provisioning_status'] ?? ''))) === 'ready'
                && hash_equals(
                    $expectedHash,
                    strtolower(trim((string)($existingTenant['provisioning_desired_hash'] ?? '')))
                )
                && trim((string)($existingTenant['provisioned_at'] ?? '')) !== ''
                && trim((string)($existingTenant['provisioning_error_code'] ?? '')) === '';
            if ($alreadyReady) {
                continue;
            }
        } else {
            // A transient edge failure for the same desired state must not
            // demote a tenant which was already proven ready. A changed desired
            // hash does move it back to a fail-closed pending/error state.
            $alreadyReadyForDesiredState = is_array($existingTenant)
                && strtolower(trim((string)($existingTenant['provisioning_status'] ?? ''))) === 'ready'
                && hash_equals(
                    $expectedHash,
                    strtolower(trim((string)($existingTenant['provisioning_desired_hash'] ?? '')))
                );
            $preservesReadyService = in_array(
                $tenantResult['failureCode'],
                ['EDGE_DNS_UNVERIFIED', 'EDGE_TLS_UNVERIFIED'],
                true
            );
            if ($alreadyReadyForDesiredState && $preservesReadyService) {
                continue;
            }
            $desiredError = $tenantResult['failureCode'] !== ''
                ? $tenantResult['failureCode']
                : ($tenantState === 'error'
                    ? 'GATEWAY_RECONCILIATION_FAILED'
                    : 'DNS_OR_ACME_PENDING');
            $alreadyPending = is_array($existingTenant)
                && strtolower(trim((string)($existingTenant['provisioning_status'] ?? ''))) === $tenantState
                && hash_equals(
                    $expectedHash,
                    strtolower(trim((string)($existingTenant['provisioning_desired_hash'] ?? '')))
                )
                && strtoupper(trim((string)($existingTenant['provisioning_error_code'] ?? ''))) === $desiredError;
            if ($alreadyPending) {
                continue;
            }
        }

        if (!is_array($existingTenant)) {
            $overrides['tenants'][$tenantId] = [
                'id' => $tenantId,
                'slug' => (string)($effectiveTenant['slug'] ?? $tenantId),
            ];
        }
        $tenant =& $overrides['tenants'][$tenantId];
        if ($tenantState === 'ready') {
            $tenant['provisioned_at'] = $now;
            unset($tenant['provisioning_error_code']);
        } else {
            $tenant['provisioning_error_code'] = $desiredError;
        }

        $tenant['provisioning_status'] = $tenantState;
        $tenant['provisioning_desired_hash'] = $expectedHash;
        $tenant['provisioning_updated_at'] = $now;
        $tenant['updated_at'] = $now;
        $audit = is_array($tenant['provisioning_audit'] ?? null) ? $tenant['provisioning_audit'] : [];
        $audit[] = [
            'at' => $now,
            'state' => $tenantState,
            'desiredStateHash' => $expectedHash,
            'environment' => $receiptEnvironment,
            'edgeVerified' => $tenantState === 'ready',
            'verificationScope' => (string)(($receipt['edgeVerification'] ?? [])['verificationScope'] ?? 'external'),
        ];
        $tenant['provisioning_audit'] = array_slice($audit, -20);
        $updated[] = $tenantId;
        unset($tenant);
    }

    if ($updated !== []) {
        $receiptHash = hash('sha256', json_encode(
            $receipt,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        $registryStore->set(
            $overrides,
            $registryState['revision'],
            'gateway-reconcile:' . $receiptHash,
            $receiptHash,
            'tenant.gateway-reconcile',
            '*',
            'worker',
            'gateway-tenant-reconciler'
        );
        $registryWrite = true;
    }
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[TENANT_RECONCILIATION_RECEIPT_REJECTED] code=' . $exception->getMessage());
    failReconciliation('Could not apply tenant reconciliation receipt.');
}

echo json_encode([
    'ok' => true,
    'state' => $state,
    'updatedTenants' => $updated,
    'entitlementsChanged' => $entitlementsChanged,
    'registryWrite' => $registryWrite,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
