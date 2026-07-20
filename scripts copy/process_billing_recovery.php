<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Infrastructure\Workers\WorkerCycleResult;
use App\Modules\Billing\Domain\BillingDomain;
use App\Modules\Billing\Infrastructure\NativeBillingGateway;
use BillingService\Billing\Application\UseCases\CheckInvoiceStatus;
use BillingService\Billing\Infrastructure\Persistence\InvoiceRepository;
use BillingService\Billing\Infrastructure\Security\BillingSecretCipherFactory;
use BillingService\Billing\Infrastructure\Services\AuthorizedInvoiceMailer;
use BillingService\Billing\Infrastructure\Services\RidePdfGenerator;
use BillingService\Billing\Infrastructure\Services\SoapSriConnector;
use BillingService\Billing\Infrastructure\Support\ClientConfigurationResolver;
use Dotenv\Dotenv;
use Psr\Log\NullLogger;

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, json_encode([
        'event' => 'billing_recovery_uncaught_failure',
        'error_type' => (new ReflectionClass($exception))->getShortName(),
        'error_code' => (int)$exception->getCode(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
});

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

foreach (getenv() ?: [] as $key => $value) {
    if (is_string($key) && !array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}
$_ENV['DB_CONNECTION_ROLE'] = strtolower(trim((string)($_ENV['DB_CONNECTION_ROLE'] ?? 'worker')));
if ($_ENV['DB_CONNECTION_ROLE'] !== 'worker') {
    fwrite(STDERR, "[billing-recovery] DB_CONNECTION_ROLE debe ser worker\n");
    exit(1);
}

$options = getopt('', ['limit::', 'min-age-seconds::']);
$limit = max(1, (int)($options['limit'] ?? ($_ENV['BILLING_RECOVERY_SWEEP_BATCH_SIZE'] ?? 50)));
$minAgeSeconds = max(3600, (int)($options['min-age-seconds'] ?? ($_ENV['BILLING_RECOVERY_SWEEP_MIN_AGE_SECONDS'] ?? 3600)));

if (!NativeBillingGateway::dependenciesAvailable()) {
    fwrite(STDERR, "[billing-recovery] failed: native Billing dependencies unavailable\n");
    exit(1);
}

try {
    $connection = Database::getModuleInstance(BillingDomain::KEY);
    $invoiceRepository = new InvoiceRepository($connection, BillingSecretCipherFactory::fromEnvironment());
    $logger = new NullLogger();
    $baseConfig = NativeBillingGateway::defaultSriConfig();
    $resolver = new ClientConfigurationResolver($baseConfig);

    $invoiceRepository->disableRetriesOutsideConfiguredWindow();
    $invoiceRepository->disableRetriesExhaustedByAttempts(max(1, (int)($baseConfig['retry']['max_attempts'] ?? 3)));
    $candidates = $invoiceRepository->findPendingXmlRecoveryCandidates($limit, $minAgeSeconds);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'event' => 'billing_recovery_start_failed',
        'error_type' => (new ReflectionClass($e))->getShortName(),
        'error_code' => (int)$e->getCode(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

if (count($candidates) === 0) {
    $cycle = new WorkerCycleResult('billing-recovery', [
        'attempted' => 0,
        'succeeded' => 0,
        'skipped' => 0,
        'failed' => 0,
        'unknown' => 0,
    ], ['candidates' => 0, 'authorized_xml_received' => 0, 'min_age_seconds' => $minAgeSeconds]);
    $cycle->emit();
    exit($cycle->exitCode());
}

$received = 0;
$attempted = 0;
$succeeded = 0;
$skipped = 0;
$failed = 0;
foreach ($candidates as $candidate) {
    $attempted++;
    if (!is_array($candidate) || empty($candidate['access_key']) || empty($candidate['resolved_branch_id'])) {
        $failed++;
        fwrite(STDERR, json_encode([
            'event' => 'billing_recovery_candidate_failed',
            'error_type' => 'InvalidCandidate',
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        continue;
    }

    $accessKey = (string)$candidate['access_key'];
    if (!$invoiceRepository->tryAcquireMaintenanceLock($accessKey)) {
        $skipped++;
        continue;
    }

    try {
        $invoiceEnvironment = isset($candidate['invoice_environment']) && is_string($candidate['invoice_environment']) && trim($candidate['invoice_environment']) !== ''
            ? trim($candidate['invoice_environment'])
            : null;
        $resolvedConfig = $resolver->resolve($candidate, $invoiceEnvironment);
        $sriGateway = new SoapSriConnector($resolvedConfig, $logger);
        $ridePdfGenerator = new RidePdfGenerator($resolvedConfig['logo_path'] ?? '/var/www/html/public/LogoVerde150.png');
        $authorizedInvoiceMailer = new AuthorizedInvoiceMailer($ridePdfGenerator, $logger, $resolvedConfig['mail'], $invoiceRepository, $candidate);

        $invoiceRepository->incrementRetryAttempts($accessKey, $candidate);
        $checkStatus = new CheckInvoiceStatus(
            $sriGateway,
            $logger,
            $authorizedInvoiceMailer,
            $invoiceRepository,
            $candidate,
            $resolvedConfig
        );

        $result = $checkStatus->execute($accessKey);
        if ((string)($result['status'] ?? '') === 'AUTORIZADO') {
            $received++;
        }
        $succeeded++;
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, json_encode([
            'event' => 'billing_recovery_candidate_failed',
            'access_key_sha256' => hash('sha256', $accessKey),
            'error_type' => (new ReflectionClass($e))->getShortName(),
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } finally {
        $invoiceRepository->releaseMaintenanceLock($accessKey);
    }
}

$cycle = new WorkerCycleResult('billing-recovery', [
    'attempted' => $attempted,
    'succeeded' => $succeeded,
    'skipped' => $skipped,
    'failed' => $failed,
    'unknown' => 0,
], [
    'candidates' => count($candidates),
    'authorized_xml_received' => $received,
    'min_age_seconds' => $minAgeSeconds,
]);
$cycle->emit();
exit($cycle->exitCode());
