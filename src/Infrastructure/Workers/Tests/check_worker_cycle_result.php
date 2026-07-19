<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Infrastructure\Workers\WorkerCycleResult;

$healthy = new WorkerCycleResult('billing-recovery', [
    'attempted' => 5,
    'succeeded' => 3,
    'skipped' => 2,
    'failed' => 0,
    'unknown' => 0,
]);
if ($healthy->exitCode() !== 0 || $healthy->isDegraded()) {
    fwrite(STDERR, "Worker cycle result rejected a healthy/skipped-only cycle.\n");
    exit(1);
}

foreach ([
    ['failed' => 1, 'unknown' => 0],
    ['failed' => 0, 'unknown' => 1],
] as $failure) {
    $result = new WorkerCycleResult('wallet-notifications', [
        'attempted' => 1,
        'succeeded' => 0,
        'skipped' => 0,
        'failed' => $failure['failed'],
        'unknown' => $failure['unknown'],
    ]);
    $payload = $result->toArray();
    if ($result->exitCode() !== 1 || ($payload['status'] ?? null) !== 'degraded') {
        fwrite(STDERR, "Worker cycle result did not fail closed.\n");
        exit(1);
    }
}

echo "Worker cycle result contract: OK\n";
