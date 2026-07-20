<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Modules\Mailer\Application\MailerOutboxProcessor;
use App\Modules\Mailer\Application\MailerRetryPolicy;
use App\Modules\Mailer\Infrastructure\Persistence\EmailOutboxRepository;
use App\Modules\Mailer\Infrastructure\Transport\SmtpMailTransport;
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
    if (strtolower($env('DB_CONNECTION_ROLE')) !== 'worker') {
        throw new RuntimeException('Mailer outbox requires DB_CONNECTION_ROLE=worker.');
    }
    if ($env('DB_WORKER_USERNAME_MAILER_SERVICE') === '' || $env('DB_WORKER_PASSWORD_MAILER_SERVICE') === '') {
        throw new RuntimeException('Mailer outbox requires its dedicated dashboard worker role.');
    }

    $repository = new EmailOutboxRepository();
    $leaseSeconds = $integer('MAILER_OUTBOX_LEASE_SECONDS', 120, 30, 3600);
    if ($option('metrics') !== null || $option('health-only') !== null) {
        $metrics = $repository->stats(null, $leaseSeconds);
        if ($option('metrics') !== null) {
            foreach (['pending', 'retry', 'processing', 'sent', 'dead_letter', 'dead_letter_acknowledged', 'stale_leases', 'oldest_due_age_seconds', 'active_tenants'] as $name) {
                printf("paramascotasec_mailer_outbox_%s %d\n", $name, (int)($metrics[$name] ?? 0));
            }
            exit(0);
        }

        (new SmtpMailTransport())->assertConfigured();
        $healthy = (int)($metrics['stale_leases'] ?? 0) === 0
            && (int)($metrics['oldest_due_age_seconds'] ?? 0) <= $integer('MAILER_OUTBOX_HEALTH_MAX_DUE_AGE_SECONDS', 300, 30, 604800);
        $degraded = (int)($metrics['dead_letter'] ?? 0)
            > $integer('MAILER_OUTBOX_HEALTH_MAX_DEAD_LETTER', 0, 0, 1000000);
        echo json_encode([
            'event' => 'mailer_outbox_health',
            'healthy' => $healthy,
            'degraded' => $degraded,
            'availability' => $healthy ? 'ready' : 'unavailable',
            'operational_state' => $degraded ? 'degraded' : 'normal',
            'metrics' => $metrics,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($healthy ? 0 : 1);
    }

    $acknowledgeId = trim((string)($option('acknowledge-id') ?? ''));
    $requeueId = trim((string)($option('requeue-id') ?? ''));
    if ($acknowledgeId !== '' && $requeueId !== '') {
        throw new InvalidArgumentException('--acknowledge-id and --requeue-id are mutually exclusive.');
    }
    if ($acknowledgeId !== '') {
        $tenantId = trim((string)($option('tenant') ?? ''));
        $actor = trim((string)($option('actor') ?? ''));
        $reason = trim((string)($option('reason') ?? ''));
        if ($tenantId === '' || $actor === '' || $reason === '') {
            throw new InvalidArgumentException('--tenant, --actor and --reason are required for audited acknowledgement.');
        }
        $acknowledged = $repository->acknowledgeDeadLetter($tenantId, $acknowledgeId, $actor, $reason);
        echo json_encode([
            'event' => 'mailer_outbox_acknowledgement',
            'tenant_id' => $tenantId,
            'message_id' => $acknowledgeId,
            'acknowledged' => $acknowledged,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($acknowledged ? 0 : 2);
    }

    if ($requeueId !== '') {
        $tenantId = trim((string)($option('tenant') ?? ''));
        $actor = trim((string)($option('actor') ?? ''));
        $reason = trim((string)($option('reason') ?? ''));
        if ($tenantId === '' || $actor === '' || $reason === '') {
            throw new InvalidArgumentException('--tenant, --actor and --reason are required for audited requeue.');
        }
        $requeued = $repository->requeueDeadLetter($tenantId, $requeueId, $actor, $reason);
        echo json_encode([
            'event' => 'mailer_outbox_requeue',
            'tenant_id' => $tenantId,
            'message_id' => $requeueId,
            'requeued' => $requeued,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($requeued ? 0 : 2);
    }

    $transport = new SmtpMailTransport();
    $transport->assertConfigured();
    $processor = new MailerOutboxProcessor(
        $repository,
        $transport,
        new MailerRetryPolicy(
            $integer('MAILER_OUTBOX_RETRY_BASE_SECONDS', 15, 1, 3600),
            $integer('MAILER_OUTBOX_RETRY_MAX_SECONDS', 1800, 1, 86400),
            $integer('MAILER_OUTBOX_RETRY_JITTER_PERCENT', 20, 0, 50)
        )
    );
    $workerId = sprintf('%s:%d', gethostname() ?: 'mailer-worker', getmypid());
    $summary = $processor->process(
        $integer('MAILER_OUTBOX_BATCH_SIZE', 100, 1, 500),
        $integer('MAILER_OUTBOX_PER_TENANT', 10, 1, 25),
        $leaseSeconds,
        $workerId,
        $integer('MAILER_OUTBOX_MAX_SECONDS', 50, 1, 1800)
    );
    echo json_encode([
        'event' => 'mailer_outbox_cycle',
        'worker_id' => $workerId,
        'summary' => $summary,
        'metrics' => $repository->stats(null, $leaseSeconds),
        'timestamp' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    error_log(json_encode([
        'event' => 'mailer_outbox_cycle_failed',
        'error_type' => $exception::class,
        'error_code' => (int)$exception->getCode(),
    ], JSON_UNESCAPED_SLASHES));
    exit(1);
}
