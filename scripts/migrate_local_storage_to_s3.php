<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Storage\LocalStorageMigrationInventory;
use App\Infrastructure\Storage\LocalToObjectStorageMigrator;
use App\Infrastructure\Storage\StorageManager;

$options = getopt('', [
    'dry-run',
    'execute',
    'verify-only',
    'scope:',
    'manifest:',
    'max-files:',
    'max-file-bytes:',
    'help',
]);
if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Uso:
  php scripts/migrate_local_storage_to_s3.php --dry-run [--scope=all|artifacts|uploads]
  php scripts/migrate_local_storage_to_s3.php --execute --manifest=/ruta/durable/migration.jsonl
  php scripts/migrate_local_storage_to_s3.php --verify-only [--manifest=/ruta/receipt.jsonl]

--execute reanuda el mismo journal, verifica SHA-256 mediante GET y nunca borra
ni sobrescribe silenciosamente el origen. Si hay certificados .p12/.pfx exige
OBJECT_STORAGE_PRIVATE_KMS_VERIFIED=true.
HELP
    );
    exit(0);
}

$modes = array_values(array_filter([
    isset($options['dry-run']) ? 'dry-run' : null,
    isset($options['execute']) ? 'execute' : null,
    isset($options['verify-only']) ? 'verify-only' : null,
]));
if (count($modes) !== 1) {
    fwrite(STDERR, "Selecciona exactamente uno: --dry-run, --execute o --verify-only.\n");
    exit(2);
}

try {
    $scope = strtolower(trim((string) ($options['scope'] ?? 'all')));
    $scopes = match ($scope) {
        'all' => ['artifacts', 'uploads'],
        'artifacts', 'uploads' => [$scope],
        default => throw new InvalidArgumentException('Scope invalido.'),
    };
    $roots = [
        'artifacts' => trim((string) ($_ENV['STORAGE_LOCAL_ARTIFACT_ROOT'] ?? getenv('STORAGE_LOCAL_ARTIFACT_ROOT') ?: '/var/www/html/storage')),
        'uploads' => trim((string) ($_ENV['STORAGE_LOCAL_UPLOAD_ROOT'] ?? getenv('STORAGE_LOCAL_UPLOAD_ROOT') ?: '/var/www/html/public/uploads')),
    ];
    $inventory = (new LocalStorageMigrationInventory($roots))->build(
        $scopes,
        max(1, (int) ($options['max-files'] ?? 100000)),
        max(1, (int) ($options['max-file-bytes'] ?? 67108864))
    );

    $publicSummary = [
        'version' => 1,
        'mode' => $modes[0],
        'plan_sha256' => $inventory['plan_sha256'],
        'total_files' => $inventory['total_files'],
        'total_bytes' => $inventory['total_bytes'],
        'sensitive_files' => $inventory['sensitive_files'],
        'excluded' => $inventory['excluded'],
        'source_deleted' => false,
    ];
    if ($modes[0] === 'dry-run') {
        fwrite(STDOUT, json_encode($publicSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(0);
    }

    if ($modes[0] === 'execute' && trim((string) ($options['manifest'] ?? '')) === '') {
        throw new InvalidArgumentException('--execute exige --manifest en almacenamiento durable.');
    }
    if ($modes[0] === 'execute' && $inventory['sensitive_files'] > 0) {
        $kmsVerified = filter_var(
            $_ENV['OBJECT_STORAGE_PRIVATE_KMS_VERIFIED'] ?? getenv('OBJECT_STORAGE_PRIVATE_KMS_VERIFIED') ?: false,
            FILTER_VALIDATE_BOOLEAN
        );
        if (!$kmsVerified) {
            throw new RuntimeException('Hay certificados privados; verifica KMS/versioning y define OBJECT_STORAGE_PRIVATE_KMS_VERIFIED=true.');
        }
    }

    $manager = StorageManager::instance();
    if ($manager->configuration()->driver !== 's3') {
        throw new RuntimeException('La migracion/validacion exige STORAGE_DRIVER=s3.');
    }
    $manifest = trim((string) ($options['manifest'] ?? ''));
    if ($manifest === '') {
        $manifest = sys_get_temp_dir() . '/paramascotasec-storage-verify-' . $inventory['plan_sha256'] . '.jsonl';
    }
    if (!str_starts_with($manifest, '/')) {
        throw new InvalidArgumentException('--manifest debe ser una ruta absoluta.');
    }
    foreach ($roots as $root) {
        if (str_starts_with($manifest, rtrim($root, '/') . '/')) {
            throw new InvalidArgumentException('--manifest debe quedar fuera de los arboles migrados.');
        }
    }

    $receipt = (new LocalToObjectStorageMigrator(
        ['artifacts' => $manager->artifacts(), 'uploads' => $manager->uploads()],
        $manifest
    ))->run($inventory, $modes[0] === 'verify-only');
    fwrite(STDOUT, json_encode(
        $publicSummary + ['manifest' => $manifest, 'receipt' => $receipt],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[storage-migration] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
