<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Cache;

class SimpleFileCache
{
    private string $cacheDir;
    private int $ttl;

    public function __construct(string $cacheDir = '/var/www/html/storage/billing/cache', int $ttl = 3600)
    {
        $this->cacheDir = $cacheDir;
        $this->ttl = $ttl;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->getCacheFile($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = file_get_contents($file);
        $cached = unserialize($data);

        if ($cached['expires'] < time()) {
            unlink($file);
            return null;
        }

        return $cached['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $file = $this->getCacheFile($key);
        $ttl = $ttl ?? $this->ttl;

        $cached = [
            'value' => $value,
            'expires' => time() + $ttl,
        ];

        file_put_contents($file, serialize($cached), LOCK_EX);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): void
    {
        $file = $this->getCacheFile($key);

        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }
}
