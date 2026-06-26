<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Modules\Billing\Domain\BillingDomain;
use App\Modules\Billing\Infrastructure\NativeBillingGateway;
use BillingService\Billing\Application\UseCases\CheckInvoiceStatus;
use BillingService\Billing\Infrastructure\Persistence\InvoiceRepository;
use BillingService\Billing\Infrastructure\Services\AuthorizedInvoiceMailer;
use BillingService\Billing\Infrastructure\Services\RidePdfGenerator;
use BillingService\Billing\Infrastructure\Services\SoapSriConnector;
use BillingService\Billing\Infrastructure\Support\ClientConfigurationResolver;
use Dotenv\Dotenv;
use Psr\Log\NullLogger;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

foreach (getenv() ?: [] as $key => $value) {
    if (is_string($key) && !array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}

$options = getopt('', ['limit::', 'min-age-seconds::']);
$limit = max(1, (int)($options['limit'] ?? ($_ENV['BILLING_RECOVERY_SWEEP_BATCH_SIZE'] ?? 50)));
$minAgeSeconds = max(3600, (int)($options['min-age-seconds'] ?? ($_ENV['BILLING_RECOVERY_SWEEP_MIN_AGE_SECONDS'] ?? 3600)));

if (!NativeBillingGateway::dependenciesAvailable()) {
    fwrite(STDERR, "[billing-recovery] skipped: native Billing dependencies unavailable\n");
    exit(0);
}

try {
    $connection = Database::getModuleInstance(BillingDomain::KEY);
    $invoiceRepository = new InvoiceRepository($connection);
    $logger = new NullLogger();
    $baseConfig = NativeBillingGateway::defaultSriConfig();
    $resolver = new ClientConfigurationResolver($baseConfig);

    $invoiceRepository->disableRetriesOutsideConfiguredWindow();
    $invoiceRepository->disableRetriesExhaustedByAttempts(max(1, (int)($baseConfig['retry']['max_attempts'] ?? 3)));
    $candidates = $invoiceRepository->findPendingXmlRecoveryCandidates($limit, $minAgeSeconds);
} catch (Throwable $e) {
    fwrite(STDERR, '[billing-recovery] skipped: ' . $e->getMessage() . PHP_EOL);
    exit(0);
}

if (count($candidates) === 0) {
    fwrite(STDOUT, "[billing-recovery] ok candidates=0\n");
    exit(0);
}

$received = 0;
foreach ($candidates as $candidate) {
    if (!is_array($candidate) || empty($candidate['access_key']) || empty($candidate['resolved_branch_id'])) {
        continue;
    }

    $accessKey = (string)$candidate['access_key'];
    if (!$invoiceRepository->tryAcquireMaintenanceLock($accessKey)) {
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
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("[billing-recovery] candidate=%s error=%s\n", $accessKey, $e->getMessage()));
    } finally {
        $invoiceRepository->releaseMaintenanceLock($accessKey);
    }
}

fwrite(STDOUT, sprintf(
    "[billing-recovery] ok candidates=%d authorized_xml_received=%d min_age_seconds=%d\n",
    count($candidates),
    $received,
    $minAgeSeconds
));
