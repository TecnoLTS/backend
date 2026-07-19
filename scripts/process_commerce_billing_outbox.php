<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Modules\Commerce\Application\CommerceBillingOutboxProcessor;
use App\Modules\Commerce\Application\CommerceBillingOutboxRetryPolicy;
use App\Modules\Commerce\Application\CommerceBillingPayloadBuilder;
use App\Modules\Commerce\Infrastructure\Adapters\AuthenticatedCommerceBillingHttpAdapter;
use App\Modules\Commerce\Infrastructure\CommerceBillingOutboxRepository;
use App\Modules\Commerce\Infrastructure\Security\CommerceBillingCredentialRegistry;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

$env = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null || trim((string)$value) === '' ? $default : trim((string)$value);
};
$integer = static function (string $key, int $default, int $min, int $max) use ($env): int {
    $value = filter_var($env($key, (string)$default), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max],
    ]);
    if ($value === false) {
        throw new RuntimeException("{$key} must be between {$min} and {$max}.");
    }
    return (int)$value;
};
$option = static function (string $name): ?string {
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if ($argument === '--' . $name) {
            return 'true';
        }
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen($name) + 3);
        }
    }
    return null;
};
try {
    if (strtolower($env('DB_CONNECTION_ROLE', '')) !== 'worker') {
        throw new RuntimeException('Commerce Billing outbox requires DB_CONNECTION_ROLE=worker.');
    }
    $repository = new CommerceBillingOutboxRepository();
    $credentialRegistry = new CommerceBillingCredentialRegistry(
        $env('BILLING_OUTBOX_CREDENTIALS_FILE', '/run/secrets/backend/commerce-billing-credentials.json')
    );
    // A malformed registry or a tenant/host binding currently present in the
    // durable backlog without an exact credential makes metrics/health fail
    // closed before the worker can claim anything.
    foreach ($repository->requiredCredentialBindings() as $binding) {
        $credential = $credentialRegistry->credentialFor($binding['tenant_id'], $binding['target_host']);
        unset($credential);
    }
    $leaseSeconds = $integer('BILLING_OUTBOX_LEASE_SECONDS', 300, 30, 3600);

    if ($option('metrics') !== null || $option('health-only') !== null) {
        $metrics = $repository->metrics($leaseSeconds);
        if ($option('metrics') !== null) {
            foreach (['pending', 'retry', 'delivery_unknown', 'processing', 'dead_letter', 'sent', 'stale_leases', 'oldest_due_age_seconds', 'active_tenants'] as $name) {
                printf("paramascotasec_commerce_billing_outbox_%s %d\n", $name, (int)($metrics[$name] ?? 0));
            }
        } else {
            $maxOldestAge = $integer('BILLING_OUTBOX_HEALTH_MAX_DUE_AGE_SECONDS', 7200, 60, 604800);
            $healthy = (int)($metrics['stale_leases'] ?? 0) === 0
                && (int)($metrics['oldest_due_age_seconds'] ?? 0) <= $maxOldestAge;
            echo json_encode([
                'event' => 'commerce_billing_outbox_health',
                'healthy' => $healthy,
                'metrics' => $metrics,
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit($healthy ? 0 : 1);
        }
        exit(0);
    }

    $requeueOrder = trim((string)($option('requeue-order') ?? ''));
    if ($requeueOrder !== '') {
        $tenant = trim((string)($option('tenant') ?? ''));
        $reason = trim((string)($option('reason') ?? ''));
        $actor = $env('BILLING_OUTBOX_REQUEUE_ACTOR', 'operator');
        if ($tenant === '' || $reason === '') {
            throw new InvalidArgumentException('--tenant and --reason are required for auditable requeue.');
        }
        $requeued = $repository->requeueDeadLetter($tenant, $requeueOrder, $actor, $reason);
        echo json_encode([
            'event' => 'commerce_billing_outbox_requeue',
            'tenant_id' => $tenant,
            'order_id' => $requeueOrder,
            'requeued' => $requeued,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($requeued ? 0 : 2);
    }

    $baseDelay = $integer('BILLING_OUTBOX_RETRY_BASE_SECONDS', 15, 1, 3600);
    $maxDelay = $integer('BILLING_OUTBOX_RETRY_MAX_SECONDS', 3600, $baseDelay, 86400);
    $processor = new CommerceBillingOutboxProcessor(
        $repository,
        new AuthenticatedCommerceBillingHttpAdapter(
            $env('BILLING_OUTBOX_INTERNAL_BASE_URL', 'http://backend-http:8080'),
            $credentialRegistry,
            $integer('BILLING_OUTBOX_HTTP_TIMEOUT_SECONDS', 45, 1, 120)
        ),
        new CommerceBillingPayloadBuilder(),
        new CommerceBillingOutboxRetryPolicy($baseDelay, $maxDelay)
    );
    $workerId = sprintf('%s:%d', gethostname() ?: 'worker', getmypid());
    $summary = $processor->process(
        $integer('BILLING_OUTBOX_BATCH_SIZE', 50, 1, 500),
        $integer('BILLING_OUTBOX_PER_TENANT', 5, 1, 25),
        $leaseSeconds,
        $workerId,
        $integer('BILLING_OUTBOX_MAX_SECONDS', 240, 1, 1800)
    );
    $metrics = $repository->metrics($leaseSeconds);
    echo json_encode([
        'event' => 'commerce_billing_outbox_cycle',
        'worker_id' => $workerId,
        'summary' => $summary,
        'metrics' => $metrics,
        'timestamp' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    error_log(json_encode([
        'event' => 'commerce_billing_outbox_cycle_failed',
        'error_type' => $exception::class,
        'error_code' => (int)$exception->getCode(),
    ], JSON_UNESCAPED_SLASHES));
    exit(1);
}
