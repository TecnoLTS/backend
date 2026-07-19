<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Conserva el contrato historico /uploads/... cuando el backend cambia de
 * filesystem local al prefijo publico del object storage.
 */
final class PublicUploadUrlResolver
{
    public function __construct(private readonly StorageConfiguration $configuration)
    {
    }

    public static function runtime(): self
    {
        return new self(StorageManager::instance()->configuration());
    }

    public function resolve(string $url): string
    {
        $url = trim($url);
        if ($url === ''
            || $this->configuration->driver !== 's3'
            || !str_starts_with($url, '/uploads/')) {
            return $url;
        }

        $relative = ltrim(substr($url, strlen('/uploads/')), '/');
        if ($relative === '') {
            return $url;
        }

        return rtrim($this->configuration->publicBaseUrl, '/') . '/' . $relative;
    }
}
