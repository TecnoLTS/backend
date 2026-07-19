<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

final class LocalToObjectStorageMigrator
{
    /** @param array{artifacts:ObjectStorage,uploads:ObjectStorage} $targets */
    public function __construct(
        private readonly array $targets,
        private readonly string $journalPath
    ) {
        if ($journalPath === '' || !str_starts_with($journalPath, '/')) {
            throw new StorageException('El journal de migracion debe usar una ruta absoluta.');
        }
        foreach (['artifacts', 'uploads'] as $scope) {
            if (!($targets[$scope] ?? null) instanceof ObjectStorage
                || $targets[$scope]->driver() !== 's3') {
                throw new StorageException('La migracion exige targets S3 para ambos scopes.');
            }
        }
    }

    /**
     * @param array{version:int,plan_sha256:string,total_files:int,total_bytes:int,entries:list<array<string,mixed>>} $inventory
     * @return array<string,mixed>
     */
    public function run(array $inventory, bool $verifyOnly = false): array
    {
        if (($inventory['version'] ?? null) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($inventory['plan_sha256'] ?? '')) !== 1
            || !is_array($inventory['entries'] ?? null)) {
            throw new StorageException('Inventario de migracion invalido.');
        }
        $directory = dirname($this->journalPath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException('No se pudo crear el directorio del journal.');
        }
        $handle = fopen($this->journalPath, 'c+b');
        if (!is_resource($handle)) {
            throw new StorageException('No se pudo abrir el journal de migracion.');
        }
        @chmod($this->journalPath, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new StorageException('Otra migracion usa el mismo journal.');
        }

        try {
            $completed = $this->loadJournal($handle, (string) $inventory['plan_sha256']);
            if (ftell($handle) === 0) {
                $this->append($handle, [
                    'type' => 'header',
                    'version' => 1,
                    'plan_sha256' => $inventory['plan_sha256'],
                    'total_files' => $inventory['total_files'],
                    'total_bytes' => $inventory['total_bytes'],
                    'created_at' => gmdate('c'),
                ]);
            }

            $migrated = 0;
            $existing = 0;
            $resumed = 0;
            foreach ($inventory['entries'] as $entry) {
                $scope = (string) ($entry['scope'] ?? '');
                $key = StorageKey::normalize((string) ($entry['key'] ?? ''));
                $expectedHash = (string) ($entry['sha256'] ?? '');
                $expectedSize = (int) ($entry['size'] ?? -1);
                $sourcePath = (string) ($entry['source_path'] ?? '');
                $entryId = hash('sha256', $scope . "\0" . $key . "\0" . $expectedHash);
                if (isset($completed[$entryId])) {
                    $this->verifyRemote($scope, $key, $expectedHash, $expectedSize);
                    $resumed++;
                    continue;
                }

                $target = $this->target($scope);
                if ($target->exists($key)) {
                    $this->verifyRemote($scope, $key, $expectedHash, $expectedSize);
                    $status = 'verified-existing';
                    $existing++;
                } elseif ($verifyOnly) {
                    throw new StorageException(sprintf('Falta el objeto S3 %s/%s.', $scope, $key));
                } else {
                    $contents = $this->readSource($sourcePath, $expectedHash, $expectedSize);
                    $target->put($key, $contents, (string) ($entry['content_type'] ?? 'application/octet-stream'));
                    $this->verifyRemote($scope, $key, $expectedHash, $expectedSize);
                    $status = 'migrated';
                    $migrated++;
                }
                $this->append($handle, [
                    'type' => 'object',
                    'entry_id' => $entryId,
                    'scope' => $scope,
                    'key' => $key,
                    'size' => $expectedSize,
                    'sha256' => $expectedHash,
                    'status' => $status,
                    'verified_at' => gmdate('c'),
                ]);
            }

            $receipt = [
                'type' => 'complete',
                'version' => 1,
                'plan_sha256' => $inventory['plan_sha256'],
                'verified_files' => count($inventory['entries']),
                'verified_bytes' => $inventory['total_bytes'],
                'migrated' => $migrated,
                'verified_existing' => $existing,
                'resumed' => $resumed,
                'source_deleted' => false,
                'completed_at' => gmdate('c'),
            ];
            $this->append($handle, $receipt);
            return $receipt;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,true> */
    private function loadJournal($handle, string $planHash): array
    {
        rewind($handle);
        $completed = [];
        $lineNumber = 0;
        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $decoded = json_decode(trim($line), true);
            if (!is_array($decoded)) {
                throw new StorageException(sprintf('Journal corrupto en linea %d.', $lineNumber));
            }
            if ($lineNumber === 1
                && (($decoded['type'] ?? null) !== 'header'
                    || !hash_equals($planHash, (string) ($decoded['plan_sha256'] ?? '')))) {
                throw new StorageException('El journal pertenece a otro plan; no se puede reanudar.');
            }
            if (($decoded['type'] ?? null) === 'object'
                && preg_match('/^[a-f0-9]{64}$/', (string) ($decoded['entry_id'] ?? '')) === 1) {
                $completed[(string) $decoded['entry_id']] = true;
            }
        }
        fseek($handle, 0, SEEK_END);
        return $completed;
    }

    private function append($handle, array $record): void
    {
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
            throw new StorageException('No se pudo persistir el journal de migracion.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new StorageException('No se pudo sincronizar el journal de migracion.');
        }
    }

    private function readSource(string $path, string $expectedHash, int $expectedSize): string
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new StorageException('El objeto local cambio o dejo de estar disponible.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)
            || strlen($contents) !== $expectedSize
            || !hash_equals($expectedHash, hash('sha256', $contents))) {
            throw new StorageException('El objeto local cambio despues de generar el plan.');
        }
        return $contents;
    }

    private function verifyRemote(string $scope, string $key, string $expectedHash, int $expectedSize): void
    {
        $target = $this->target($scope);
        $metadata = $target->metadata($key);
        if (!is_array($metadata) || (int) ($metadata['size'] ?? -1) !== $expectedSize) {
            throw new StorageException(sprintf('Tamano S3 no coincide para %s/%s.', $scope, $key));
        }
        $remote = $target->get($key);
        if (strlen($remote) !== $expectedSize || !hash_equals($expectedHash, hash('sha256', $remote))) {
            throw new StorageException(sprintf('SHA-256 S3 no coincide para %s/%s.', $scope, $key));
        }
    }

    private function target(string $scope): ObjectStorage
    {
        if (!in_array($scope, ['artifacts', 'uploads'], true)) {
            throw new StorageException('Scope de objeto invalido en el plan.');
        }
        return $this->targets[$scope];
    }
}
