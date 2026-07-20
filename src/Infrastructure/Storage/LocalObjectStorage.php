<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

final class LocalObjectStorage implements ObjectStorage
{
    public function __construct(
        private readonly string $root,
        private readonly int $directoryMode = 0750,
        private readonly int $fileMode = 0640
    ) {
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new StorageException('La raíz del almacenamiento local debe ser absoluta.');
        }
    }

    public function driver(): string
    {
        return 'local';
    }

    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): array
    {
        $key = StorageKey::normalize($key);
        $path = $this->path($key);
        $directory = dirname($path);
        $this->ensureDirectory($directory);

        $temporary = tempnam($directory, '.storage-');
        if ($temporary === false) {
            throw new StorageException('No se pudo crear el archivo temporal de almacenamiento.');
        }

        try {
            $written = file_put_contents($temporary, $contents, LOCK_EX);
            if ($written === false || $written !== strlen($contents)) {
                throw new StorageException('No se pudo escribir el objeto completo en almacenamiento local.');
            }
            @chmod($temporary, $this->fileMode);
            if (!rename($temporary, $path)) {
                throw new StorageException('No se pudo publicar el objeto en almacenamiento local.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        clearstatcache(true, $path);

        return [
            'key' => $key,
            'size' => strlen($contents),
            'content_type' => $contentType,
            'etag' => hash('sha256', $contents),
            'modified_at' => filemtime($path) ?: null,
        ];
    }

    public function get(string $key): string
    {
        $key = StorageKey::normalize($key);
        $path = $this->path($key);
        if (!is_file($path) || !is_readable($path)) {
            throw new StorageException(sprintf('Objeto no encontrado: %s', $key));
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new StorageException(sprintf('No se pudo leer el objeto: %s', $key));
        }

        return $contents;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path(StorageKey::normalize($key)));
    }

    public function metadata(string $key): ?array
    {
        $key = StorageKey::normalize($key);
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        $modifiedAt = filemtime($path);

        return [
            'key' => $key,
            'size' => $size === false ? 0 : (int) $size,
            'content_type' => $this->detectContentType($path),
            'etag' => null,
            'modified_at' => $modifiedAt === false ? null : (int) $modifiedAt,
        ];
    }

    public function delete(string $key): void
    {
        $key = StorageKey::normalize($key);
        $path = $this->path($key);
        if (is_file($path) && !unlink($path)) {
            throw new StorageException(sprintf('No se pudo eliminar el objeto: %s', $key));
        }
    }

    public function materialize(string $key): string
    {
        $key = StorageKey::normalize($key);
        $path = $this->path($key);
        if (!is_file($path) || !is_readable($path)) {
            throw new StorageException(sprintf('Objeto no encontrado: %s', $key));
        }

        return $path;
    }

    public function localPath(string $key): ?string
    {
        return $this->path(StorageKey::normalize($key));
    }

    private function path(string $key): string
    {
        return rtrim($this->root, '/') . '/' . $key;
    }

    private function ensureDirectory(string $directory): void
    {
        $root = rtrim($this->root, '/');
        if (!is_dir($directory) && !mkdir($directory, $this->directoryMode, true) && !is_dir($directory)) {
            throw new StorageException('No se pudo crear el directorio de almacenamiento local.');
        }

        // PHP-FPM usa umask 0077 para proteger artefactos fiscales. mkdir()
        // aplica ese umask incluso cuando uploads solicita 0755, por lo que
        // Nginx no puede atravesar directorios nuevos. chmod() explicito sobre
        // cada nivel conserva 0750 para artefactos y 0755 para uploads.
        $relative = ltrim(substr($directory, strlen($root)), '/');
        $paths = [$root];
        $current = $root;
        foreach (array_filter(explode('/', $relative), static fn(string $part): bool => $part !== '') as $part) {
            $current .= '/' . $part;
            $paths[] = $current;
        }

        foreach ($paths as $path) {
            if (is_link($path) || !is_dir($path) || !chmod($path, $this->directoryMode)) {
                throw new StorageException('No se pudieron aplicar permisos seguros al directorio de almacenamiento local.');
            }
        }
    }

    private function detectContentType(string $path): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }
}
