<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Infrastructure\Storage\LocalStorageMigrationInventory;
use App\Infrastructure\Storage\LocalToObjectStorageMigrator;
use App\Infrastructure\Storage\ObjectStorage;
use App\Infrastructure\Storage\StorageException;

final class MigrationMemoryStorage implements ObjectStorage
{
    /** @var array<string,string> */
    public array $objects = [];
    public int $puts = 0;

    public function driver(): string { return 's3'; }
    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): array {
        $this->puts++;
        $this->objects[$key] = $contents;
        return ['key' => $key, 'size' => strlen($contents), 'content_type' => $contentType, 'etag' => hash('sha256', $contents), 'modified_at' => null];
    }
    public function get(string $key): string {
        if (!isset($this->objects[$key])) { throw new StorageException('missing'); }
        return $this->objects[$key];
    }
    public function exists(string $key): bool { return isset($this->objects[$key]); }
    public function metadata(string $key): ?array {
        return isset($this->objects[$key])
            ? ['key' => $key, 'size' => strlen($this->objects[$key]), 'content_type' => 'application/octet-stream', 'etag' => hash('sha256', $this->objects[$key]), 'modified_at' => null]
            : null;
    }
    public function delete(string $key): void { unset($this->objects[$key]); }
    public function materialize(string $key): string { throw new LogicException('unused'); }
    public function localPath(string $key): ?string { return null; }
}

$fail = static function (string $message): never {
    fwrite(STDERR, '[storage-migration-test] ' . $message . PHP_EOL);
    exit(1);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) { $fail($message); }
};
$root = sys_get_temp_dir() . '/pm-storage-migration-' . bin2hex(random_bytes(5));
$artifacts = $root . '/artifacts';
$uploads = $root . '/uploads';
mkdir($artifacts . '/billing/xml', 0700, true);
mkdir($artifacts . '/billing/certs', 0700, true);
mkdir($artifacts . '/wallet', 0700, true);
mkdir($uploads . '/tenants/t1/products', 0700, true);
file_put_contents($artifacts . '/billing/xml/a.xml', '<a/>');
file_put_contents($artifacts . '/billing/certs/a.p12', 'private-certificate-fixture');
file_put_contents($artifacts . '/wallet/service-account.json', '{"secret":true}');
file_put_contents($uploads . '/tenants/t1/products/a.webp', 'webp-fixture');

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { $remove($path . '/' . $entry); }
        }
        @rmdir($path);
    } else { @unlink($path); }
};

try {
    $inventoryBuilder = new LocalStorageMigrationInventory(['artifacts' => $artifacts, 'uploads' => $uploads]);
    $inventory = $inventoryBuilder->build(['artifacts', 'uploads']);
    $assert($inventory['total_files'] === 3, 'El inventario no incluyo exactamente artefactos+upload.');
    $assert($inventory['sensitive_files'] === 1, 'El certificado no fue clasificado sensible.');
    $assert(count($inventory['excluded']) === 1 && $inventory['excluded'][0]['reason'] === 'runtime-secret', 'El service account no fue excluido.');

    $artifactTarget = new MigrationMemoryStorage();
    $uploadTarget = new MigrationMemoryStorage();
    $journal = $root . '/journal/migration.jsonl';
    $migrator = new LocalToObjectStorageMigrator(['artifacts' => $artifactTarget, 'uploads' => $uploadTarget], $journal);
    $first = $migrator->run($inventory);
    $assert($first['migrated'] === 3 && $first['source_deleted'] === false, 'Primera migracion incompleta o destructiva.');
    $assert(is_file($artifacts . '/billing/xml/a.xml'), 'La migracion borro el origen.');

    $second = $migrator->run($inventory);
    $assert($second['resumed'] === 3 && $artifactTarget->puts + $uploadTarget->puts === 3, 'Resume no fue idempotente.');

    $artifactTarget->objects['billing/xml/a.xml'] = 'tampered';
    $tamperRejected = false;
    try { $migrator->run($inventory); } catch (StorageException) { $tamperRejected = true; }
    $assert($tamperRejected, 'Resume acepto un objeto remoto alterado.');
    $artifactTarget->objects['billing/xml/a.xml'] = '<a/>';

    file_put_contents($uploads . '/tenants/t1/products/a.webp', 'changed');
    $changedInventory = $inventoryBuilder->build(['artifacts', 'uploads']);
    $stalePlanRejected = false;
    try { $migrator->run($changedInventory); } catch (StorageException) { $stalePlanRejected = true; }
    $assert($stalePlanRejected, 'El journal acepto un plan cambiado.');

    fwrite(STDOUT, "Local-to-S3 storage migration: OK\n");
} finally {
    $remove($root);
}
