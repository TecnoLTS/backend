<?php

namespace App\Modules\IdentityPlatform\Infrastructure;

/**
 * Signed, bounded last-known-good copy of the tenant control plane.
 *
 * This is not a second source of truth. It only prevents a registry outage
 * from reverting suspended/deprovisioned tenants to static active defaults.
 */
final class TenantRuntimeRegistrySnapshot
{
    private const VERSION = 2;
    private const DEFAULT_MAX_AGE_SECONDS = 86400;
    private const MAX_BYTES = 2097152;

    /**
     * Persist a database revision without ever replacing a newer signed state.
     *
     * The lock lives beside the snapshot so every replica sharing the RWX
     * volume participates in the same compare-and-swap boundary. Equal
     * revisions are idempotent only when their registry payload is identical.
     */
    public static function save(array $registry, int $revision, bool $forceHeartbeat = false): void
    {
        self::assertRegistry($registry);
        if ($revision < 1) {
            throw new \InvalidArgumentException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_REVISION_INVALID');
        }
        $secret = self::secret();
        $path = self::path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_DIRECTORY_UNAVAILABLE');
        }
        @chmod($directory, 0700);

        $lockPath = $path . '.lock';
        $lock = @fopen($lockPath, 'c+b');
        if (!is_resource($lock)) {
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_LOCK_UNAVAILABLE');
        }
        @chmod($lockPath, 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_LOCK_FAILED');
            }

            $current = self::readState(false, false);
            if ($current !== null) {
                $currentRevision = $current['revision'];
                if ($currentRevision > $revision) {
                    throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_REVISION_DOWNGRADE');
                }
                if ($currentRevision === $revision) {
                    if (!hash_equals(self::registryHash($current['registry']), self::registryHash($registry))) {
                        throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_REVISION_CONFLICT');
                    }
                    // Readiness still checks PostgreSQL on every invocation,
                    // while many tenant/domain probes can share this snapshot.
                    // Bound the authenticated heartbeat instead of fsync/rename
                    // on every otherwise identical readiness request.
                    if (!$forceHeartbeat
                        && $current['captured_at'] >= time() - self::heartbeatSeconds()) {
                        return;
                    }
                    self::writeEnvelope($path, $registry, $revision, $secret);
                    return;
                }
            }

            self::writeEnvelope($path, $registry, $revision, $secret);
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    public static function load(): array
    {
        return self::loadState()['registry'];
    }

    /** @return array{captured_at:int,revision:int,registry:array} */
    public static function loadState(): array
    {
        $state = self::readState(true, true);
        if ($state === null) {
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_MISSING');
        }
        return $state;
    }

    /** @return array{captured_at:int,revision:int,registry:array}|null */
    private static function readState(bool $enforceAge, bool $failOnInvalid): ?array
    {
        $path = self::path();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $size = @filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_BYTES) {
            if (!$failOnInvalid) {
                return null;
            }
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_SIZE_INVALID');
        }
        $raw = @file_get_contents($path);
        $envelope = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($envelope)
            || ($envelope['version'] ?? null) !== self::VERSION
            || !is_int($envelope['captured_at'] ?? null)
            || !is_int($envelope['revision'] ?? null)
            || $envelope['revision'] < 1
            || !is_array($envelope['registry'] ?? null)
            || !is_string($envelope['payload_sha256'] ?? null)
            || !is_string($envelope['signature'] ?? null)) {
            if (!$failOnInvalid) {
                return null;
            }
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_INVALID');
        }

        $now = time();
        $capturedAt = $envelope['captured_at'];
        if ($enforceAge && ($capturedAt > $now + 300 || $capturedAt < $now - self::maxAgeSeconds())) {
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_EXPIRED');
        }

        $signed = [
            'version' => $envelope['version'],
            'captured_at' => $capturedAt,
            'revision' => $envelope['revision'],
            'registry' => $envelope['registry'],
        ];
        $signedJson = self::encode($signed);
        if (!hash_equals(hash('sha256', $signedJson), strtolower($envelope['payload_sha256']))
            || !hash_equals(hash_hmac('sha256', $signedJson, self::secret()), strtolower($envelope['signature']))) {
            if (!$failOnInvalid) {
                return null;
            }
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_SIGNATURE_INVALID');
        }
        self::assertRegistry($envelope['registry']);

        return [
            'captured_at' => $capturedAt,
            'revision' => $envelope['revision'],
            'registry' => $envelope['registry'],
        ];
    }

    private static function writeEnvelope(string $path, array $registry, int $revision, string $secret): void
    {
        $signed = [
            'version' => self::VERSION,
            'captured_at' => time(),
            'revision' => $revision,
            'registry' => $registry,
        ];
        $signedJson = self::encode($signed);
        $envelope = $signed + [
            'payload_sha256' => hash('sha256', $signedJson),
            'signature' => hash_hmac('sha256', $signedJson, $secret),
        ];
        $encoded = self::encode($envelope);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_TOO_LARGE');
        }

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        $handle = null;
        try {
            $handle = @fopen($temporary, 'xb');
            if (!is_resource($handle)) {
                throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_WRITE_FAILED');
            }
            $remaining = $encoded;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_WRITE_FAILED');
                }
                $remaining = (string)substr($remaining, $written);
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_SYNC_FAILED');
            }
            fclose($handle);
            $handle = null;
            @chmod($temporary, 0600);
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_RENAME_FAILED');
            }
            @chmod($path, 0600);
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function registryHash(array $registry): string
    {
        return hash('sha256', self::encode($registry));
    }

    private static function assertRegistry(array $registry): void
    {
        $unexpected = array_diff(array_keys($registry), ['version', 'tenants']);
        $legacyShape = !array_key_exists('version', $registry);
        if ($unexpected !== []
            || !is_array($registry['tenants'] ?? null)
            || (!$legacyShape && ($registry['version'] ?? null) !== 1)) {
            throw new \InvalidArgumentException('TENANT_RUNTIME_REGISTRY_SHAPE_INVALID');
        }
    }

    private static function path(): string
    {
        $configured = trim((string)($_ENV['TENANT_RUNTIME_REGISTRY_SNAPSHOT_PATH']
            ?? getenv('TENANT_RUNTIME_REGISTRY_SNAPSHOT_PATH') ?: ''));
        return $configured !== ''
            ? $configured
            : dirname(__DIR__, 4) . '/storage/runtime/tenant-registry.snapshot.json';
    }

    private static function secret(): string
    {
        $secret = trim((string)($_ENV['TENANT_RUNTIME_REGISTRY_SNAPSHOT_SECRET']
            ?? getenv('TENANT_RUNTIME_REGISTRY_SNAPSHOT_SECRET') ?: ''));
        if (strlen($secret) < 64) {
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_SNAPSHOT_SECRET_INVALID');
        }
        return $secret;
    }

    private static function maxAgeSeconds(): int
    {
        $raw = (int)($_ENV['TENANT_RUNTIME_REGISTRY_SNAPSHOT_MAX_AGE_SECONDS']
            ?? getenv('TENANT_RUNTIME_REGISTRY_SNAPSHOT_MAX_AGE_SECONDS') ?: self::DEFAULT_MAX_AGE_SECONDS);
        return max(300, min($raw, 604800));
    }

    private static function heartbeatSeconds(): int
    {
        $maxAge = self::maxAgeSeconds();
        $upperBound = max(60, min(3600, intdiv($maxAge, 2)));
        $raw = (int)($_ENV['TENANT_RUNTIME_REGISTRY_SNAPSHOT_HEARTBEAT_SECONDS']
            ?? getenv('TENANT_RUNTIME_REGISTRY_SNAPSHOT_HEARTBEAT_SECONDS') ?: 300);

        return max(60, min($raw, $upperBound));
    }

    private static function encode(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
