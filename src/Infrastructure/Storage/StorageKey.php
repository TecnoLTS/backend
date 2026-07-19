<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

final class StorageKey
{
    public static function normalize(string $key): string
    {
        $key = trim($key);
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, '\\')) {
            throw new StorageException('La clave de almacenamiento debe ser relativa.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new StorageException('La clave de almacenamiento contiene caracteres de control.');
        }

        $segments = explode('/', $key);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new StorageException('La clave de almacenamiento contiene un segmento inseguro.');
            }
        }

        return implode('/', $segments);
    }

    public static function prefix(string $prefix, string $key): string
    {
        $key = self::normalize($key);
        $prefix = trim($prefix, " \t\n\r\0\x0B/");
        if ($prefix === '') {
            return $key;
        }

        return self::normalize($prefix . '/' . $key);
    }
}
