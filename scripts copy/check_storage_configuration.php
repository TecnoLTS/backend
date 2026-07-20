<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Storage\StorageConfiguration;

$quiet = in_array('--quiet', $argv, true);

try {
    $configuration = StorageConfiguration::fromEnvironment();
    $appEnv = strtolower(trim((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'qa')));
    if (in_array($appEnv, ['production', 'prod'], true)
        && ($configuration->driver !== 's3' || !$configuration->requireHa)) {
        throw new RuntimeException('APP_ENV=production exige STORAGE_DRIVER=s3 y REQUIRE_HA=true.');
    }
    if (!$quiet) {
        fwrite(STDOUT, json_encode($configuration->summary(), JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '[storage-preflight] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
