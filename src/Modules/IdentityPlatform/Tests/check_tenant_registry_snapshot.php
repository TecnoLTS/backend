<?php

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistrySnapshot;

$directory = sys_get_temp_dir() . '/tenant-registry-snapshot-' . bin2hex(random_bytes(8));
$path = $directory . '/snapshot.json';
$secret = str_repeat('a1', 32);
$_ENV['TENANT_RUNTIME_REGISTRY_SNAPSHOT_PATH'] = $path;
$_ENV['TENANT_RUNTIME_REGISTRY_SNAPSHOT_SECRET'] = $secret;
$_ENV['TENANT_RUNTIME_REGISTRY_SNAPSHOT_MAX_AGE_SECONDS'] = '300';
$_ENV['TENANT_RUNTIME_REGISTRY_SNAPSHOT_HEARTBEAT_SECONDS'] = '60';
putenv('TENANT_RUNTIME_REGISTRY_SNAPSHOT_PATH=' . $path);
putenv('TENANT_RUNTIME_REGISTRY_SNAPSHOT_SECRET=' . $secret);
putenv('TENANT_RUNTIME_REGISTRY_SNAPSHOT_MAX_AGE_SECONDS=300');
putenv('TENANT_RUNTIME_REGISTRY_SNAPSHOT_HEARTBEAT_SECONDS=60');

$legacyRegistry = [
    'tenants' => [
        'suspended-probe' => [
            'id' => 'suspended-probe',
            'slug' => 'suspended-probe',
            'status' => 'suspended',
            'domains' => ['suspended.invalid'],
        ],
    ],
];
$registry = ['version' => 1] + $legacyRegistry;

try {
    TenantRuntimeRegistrySnapshot::save($legacyRegistry, 99);
    if (TenantRuntimeRegistrySnapshot::load() !== $legacyRegistry) {
        throw new RuntimeException('legacy signed snapshot compatibility was lost');
    }
    TenantRuntimeRegistrySnapshot::save($registry, 100);
    if (TenantRuntimeRegistrySnapshot::load() !== $registry) {
        throw new RuntimeException('signed snapshot round-trip changed the registry');
    }
    if (TenantRuntimeRegistrySnapshot::loadState()['revision'] !== 100) {
        throw new RuntimeException('signed snapshot lost its database revision');
    }
    $beforeIdempotentHeartbeat = (string)file_get_contents($path);
    TenantRuntimeRegistrySnapshot::save($registry, 100);
    $afterIdempotentHeartbeat = (string)file_get_contents($path);
    if ($beforeIdempotentHeartbeat !== $afterIdempotentHeartbeat) {
        throw new RuntimeException('identical snapshot was rewritten inside the heartbeat window');
    }
    if (!is_int(TenantRuntimeRegistrySnapshot::loadState()['captured_at'] ?? null)) {
        throw new RuntimeException('signed snapshot does not expose its authenticated heartbeat');
    }

    $newerRegistry = $registry;
    $newerRegistry['tenants']['suspended-probe']['status'] = 'inactive';
    TenantRuntimeRegistrySnapshot::save($newerRegistry, 101);
    try {
        TenantRuntimeRegistrySnapshot::save($registry, 100);
        throw new RuntimeException('older registry revision overwrote the newer snapshot');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'TENANT_RUNTIME_REGISTRY_SNAPSHOT_REVISION_DOWNGRADE') {
            throw $exception;
        }
    }
    if (TenantRuntimeRegistrySnapshot::load() !== $newerRegistry) {
        throw new RuntimeException('CAS did not preserve the newest registry state');
    }

    if (function_exists('proc_open')) {
        $concurrentOlder = $newerRegistry;
        $concurrentOlder['tenants']['suspended-probe']['status'] = 'suspended';
        $concurrentNewer = $newerRegistry;
        $concurrentNewer['tenants']['suspended-probe']['status'] = 'inactive';
        $writer = <<<'PHP'
require $argv[1];
usleep((int)$argv[4]);
$registry = json_decode(base64_decode($argv[3], true), true, 512, JSON_THROW_ON_ERROR);
try {
    \App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistrySnapshot::save(
        $registry,
        (int)$argv[2]
    );
    exit(in_array($argv[5], ['ok', 'ok_or_downgrade'], true) ? 0 : 21);
} catch (RuntimeException $exception) {
    $accepted = $argv[5] === $exception->getMessage()
        || ($argv[5] === 'ok_or_downgrade'
            && $exception->getMessage() === 'TENANT_RUNTIME_REGISTRY_SNAPSHOT_REVISION_DOWNGRADE');
    exit($accepted ? 0 : 22);
}
PHP;
        $launch = static function (int $revision, array $value, int $delay, string $expected) use ($writer): array {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-r',
                $writer,
                dirname(__DIR__, 4) . '/vendor/autoload.php',
                (string)$revision,
                base64_encode(json_encode($value, JSON_THROW_ON_ERROR)),
                (string)$delay,
                $expected,
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('could not launch concurrent snapshot writer');
            }
            fclose($pipes[0]);
            return [$process, $pipes];
        };
        [$olderProcess, $olderPipes] = $launch(
            102,
            $concurrentOlder,
            200000,
            'ok_or_downgrade'
        );
        [$newerProcess, $newerPipes] = $launch(103, $concurrentNewer, 0, 'ok');
        foreach ([[$newerProcess, $newerPipes], [$olderProcess, $olderPipes]] as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                throw new RuntimeException(
                    'concurrent snapshot writer failed: ' . trim((string)$stdout . ' ' . (string)$stderr)
                );
            }
        }
        $concurrentState = TenantRuntimeRegistrySnapshot::loadState();
        if ($concurrentState['revision'] !== 103 || $concurrentState['registry'] !== $concurrentNewer) {
            throw new RuntimeException('concurrent stale writer replaced the newest snapshot');
        }
        $newerRegistry = $concurrentNewer;
    }

    $conflictingRegistry = $newerRegistry;
    $conflictingRegistry['tenants']['suspended-probe']['status'] = 'active';
    try {
        $currentRevision = TenantRuntimeRegistrySnapshot::loadState()['revision'];
        TenantRuntimeRegistrySnapshot::save($conflictingRegistry, $currentRevision);
        throw new RuntimeException('conflicting payload reused an existing registry revision');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'TENANT_RUNTIME_REGISTRY_SNAPSHOT_REVISION_CONFLICT') {
            throw $exception;
        }
    }

    $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $decoded['registry']['tenants']['suspended-probe']['status'] = 'active';
    file_put_contents($path, json_encode($decoded, JSON_THROW_ON_ERROR));
    try {
        TenantRuntimeRegistrySnapshot::load();
        throw new RuntimeException('tampered snapshot was accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'tampered snapshot was accepted') {
            throw $exception;
        }
    }
} finally {
    @unlink($path);
    @unlink($path . '.lock');
    @rmdir($directory);
}

echo "Tenant registry signed snapshot: OK\n";
