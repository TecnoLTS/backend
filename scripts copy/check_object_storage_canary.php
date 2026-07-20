<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Storage\StorageConfiguration;
use App\Infrastructure\Storage\StorageManager;

/** @return string */
function canaryEnv(string $name, string $default = ''): string
{
    $value = $_ENV[$name] ?? getenv($name);
    return $value === false || $value === null ? $default : trim((string)$value);
}

/** @param array<string, mixed> $payload */
function writeCanaryEvidence(?string $path, array $payload): void
{
    if ($path === null) {
        return;
    }
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
        throw new RuntimeException('--evidence debe ser una ruta absoluta.');
    }
    if (is_link($path)) {
        throw new RuntimeException('La evidencia no puede reemplazar un symlink.');
    }
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('El directorio de evidencia no es escribible.');
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporary = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) {
        @unlink($temporary);
        throw new RuntimeException('No se pudo escribir evidencia atomica.');
    }
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No se pudo publicar evidencia atomica.');
    }
}

$execute = false;
$evidencePath = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--execute') {
        $execute = true;
        continue;
    }
    if (str_starts_with($argument, '--evidence=')) {
        if ($evidencePath !== null) {
            fwrite(STDERR, "--evidence no puede repetirse.\n");
            exit(64);
        }
        $evidencePath = substr($argument, strlen('--evidence='));
        continue;
    }
    fwrite(STDERR, "Uso: php scripts/check_object_storage_canary.php --execute [--evidence=/ruta/absoluta.json]\n");
    exit(64);
}

$started = microtime(true);
$runId = bin2hex(random_bytes(16));
$stage = 'authorization';
$created = false;
$cleanupSucceeded = true;
$storage = null;
$key = '';
$payload = '';
$evidence = [
    'version' => 1,
    'status' => 'failed',
    'run_id' => $runId,
    'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'operations' => ['put' => false, 'get' => false, 'metadata' => false, 'delete' => false, 'absence' => false],
];

try {
    if (!$execute) {
        throw new RuntimeException('Canary opt-in: falta --execute.');
    }
    $environment = strtolower(canaryEnv('APP_ENV', canaryEnv('ENTORNO_MODE', 'qa')));
    if (!in_array($environment, ['production', 'prod'], true)) {
        throw new RuntimeException('El canary real solo se permite en production.');
    }
    if (filter_var(canaryEnv('OBJECT_STORAGE_CANARY_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN) !== true) {
        throw new RuntimeException('OBJECT_STORAGE_CANARY_ENABLED=true es obligatorio.');
    }

    $configuration = StorageConfiguration::fromEnvironment();
    if ($configuration->driver !== 's3' || !$configuration->requireHa || !$configuration->verifyTls) {
        throw new RuntimeException('El canary exige S3, REQUIRE_HA=true y TLS verificado.');
    }
    $host = parse_url($configuration->endpoint, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        throw new RuntimeException('Endpoint S3 sin host verificable.');
    }

    $storage = (new StorageManager($configuration))->artifacts();
    $payload = json_encode([
        'run_id' => $runId,
        'nonce' => bin2hex(random_bytes(32)),
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $key = sprintf('canary/%s/%s.json', gmdate('Y/m/d/H'), $runId);
    $evidence['endpoint_host'] = $host;
    $evidence['bucket_sha256'] = hash('sha256', $configuration->bucket);
    $evidence['object_key_sha256'] = hash('sha256', $key);
    $evidence['payload_sha256'] = hash('sha256', $payload);

    $stage = 'put';
    $written = $storage->put($key, $payload, 'application/json');
    $created = true;
    if (($written['size'] ?? -1) !== strlen($payload)) {
        throw new RuntimeException('PUT no confirmo el tamano exacto.');
    }
    $evidence['operations']['put'] = true;

    $stage = 'get';
    $read = $storage->get($key);
    if (!hash_equals(hash('sha256', $payload), hash('sha256', $read))) {
        throw new RuntimeException('GET no conservo el payload exacto.');
    }
    $evidence['operations']['get'] = true;

    $stage = 'metadata';
    $metadata = $storage->metadata($key);
    if (!is_array($metadata) || ($metadata['size'] ?? -1) !== strlen($payload)) {
        throw new RuntimeException('HEAD no confirmo metadata exacta.');
    }
    $evidence['operations']['metadata'] = true;

    $stage = 'delete';
    $storage->delete($key);
    $evidence['operations']['delete'] = true;

    $stage = 'absence';
    if ($storage->exists($key)) {
        throw new RuntimeException('El objeto sigue visible despues de DELETE.');
    }
    $created = false;
    $evidence['operations']['absence'] = true;
    $evidence['status'] = 'ok';
} catch (Throwable $exception) {
    $evidence['failure_stage'] = $stage;
    $evidence['error_type'] = (new ReflectionClass($exception))->getShortName();
} finally {
    if ($created && $storage !== null && $key !== '') {
        try {
            $storage->delete($key);
            $cleanupSucceeded = !$storage->exists($key);
        } catch (Throwable) {
            $cleanupSucceeded = false;
        }
        if (!$cleanupSucceeded) {
            $evidence['status'] = 'failed';
            $evidence['failure_stage'] = 'cleanup';
        }
    }
    $evidence['cleanup_succeeded'] = $cleanupSucceeded;
    $evidence['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $evidence['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
}

try {
    writeCanaryEvidence($evidencePath, $evidence);
} catch (Throwable $exception) {
    $evidence['status'] = 'failed';
    $evidence['failure_stage'] = 'evidence';
    $evidence['error_type'] = (new ReflectionClass($exception))->getShortName();
}

fwrite(STDOUT, json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
exit($evidence['status'] === 'ok' ? 0 : 1);
