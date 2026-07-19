<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class LocalStorageMigrationInventory
{
    /** @param array{artifacts:string,uploads:string} $roots */
    public function __construct(private readonly array $roots)
    {
    }

    /**
     * @param list<string> $scopes
     * @return array{version:int,generated_at:string,plan_sha256:string,total_files:int,total_bytes:int,sensitive_files:int,entries:list<array<string,mixed>>,excluded:list<array<string,string>>}
     */
    public function build(array $scopes, int $maxFiles = 100000, int $maxFileBytes = 67108864): array
    {
        if ($maxFiles < 1 || $maxFileBytes < 1) {
            throw new StorageException('Los limites del inventario deben ser positivos.');
        }

        $entries = [];
        $excluded = [];
        $totalBytes = 0;
        $sensitiveFiles = 0;
        foreach (array_values(array_unique($scopes)) as $scope) {
            if (!in_array($scope, ['artifacts', 'uploads'], true)) {
                throw new StorageException('Scope de migracion invalido.');
            }
            $root = rtrim((string) ($this->roots[$scope] ?? ''), '/');
            if ($root === '' || !str_starts_with($root, '/')) {
                throw new StorageException(sprintf('La raiz local de %s debe ser absoluta.', $scope));
            }
            if (!is_dir($root)) {
                continue;
            }
            $realRoot = realpath($root);
            if (!is_string($realRoot)) {
                throw new StorageException(sprintf('No se pudo resolver la raiz local de %s.', $scope));
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    $excluded[] = ['scope' => $scope, 'key' => $file->getFilename(), 'reason' => 'symlink'];
                    continue;
                }
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $key = StorageKey::normalize(substr($path, strlen($realRoot) + 1));
                $excludeReason = $this->excludeReason($key);
                if ($excludeReason !== null) {
                    $excluded[] = ['scope' => $scope, 'key' => $key, 'reason' => $excludeReason];
                    continue;
                }
                if (count($entries) >= $maxFiles) {
                    throw new StorageException('El inventario supera el maximo de archivos permitido.');
                }
                $size = $file->getSize();
                if ($size < 0 || $size > $maxFileBytes) {
                    throw new StorageException(sprintf('El objeto %s/%s supera el limite por archivo.', $scope, $key));
                }
                $sha256 = hash_file('sha256', $path);
                if (!is_string($sha256)) {
                    throw new StorageException(sprintf('No se pudo calcular SHA-256 de %s/%s.', $scope, $key));
                }
                $sensitive = preg_match('/\.(?:p12|pfx)$/i', $key) === 1;
                $entries[] = [
                    'scope' => $scope,
                    'key' => $key,
                    'source_path' => $path,
                    'size' => $size,
                    'sha256' => $sha256,
                    'content_type' => $this->contentType($path),
                    'sensitive' => $sensitive,
                ];
                $totalBytes += $size;
                $sensitiveFiles += $sensitive ? 1 : 0;
            }
        }

        usort($entries, static fn(array $left, array $right): int => strcmp(
            $left['scope'] . ':' . $left['key'],
            $right['scope'] . ':' . $right['key']
        ));
        usort($excluded, static fn(array $left, array $right): int => strcmp(
            $left['scope'] . ':' . $left['key'],
            $right['scope'] . ':' . $right['key']
        ));
        $canonical = array_map(static fn(array $entry): array => [
            'scope' => $entry['scope'],
            'key' => $entry['key'],
            'size' => $entry['size'],
            'sha256' => $entry['sha256'],
            'sensitive' => $entry['sensitive'],
        ], $entries);

        return [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'plan_sha256' => hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'total_files' => count($entries),
            'total_bytes' => $totalBytes,
            'sensitive_files' => $sensitiveFiles,
            'entries' => $entries,
            'excluded' => $excluded,
        ];
    }

    private function excludeReason(string $key): ?string
    {
        $base = strtolower(basename($key));
        if (str_starts_with($base, '.git') || in_array($base, ['.ds_store', 'thumbs.db'], true)) {
            return 'metadata';
        }
        if (preg_match('#(?:^|/)wallet/service-account\.json$#i', $key) === 1) {
            return 'runtime-secret';
        }
        if (preg_match('/\.(?:key|pem)$/i', $key) === 1) {
            return 'private-key';
        }

        return null;
    }

    private function contentType(string $path): string
    {
        if (class_exists(\finfo::class)) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }
}
