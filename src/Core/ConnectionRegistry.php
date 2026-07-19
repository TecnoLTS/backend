<?php

namespace App\Core;

final class ConnectionRegistry {
    private static ?array $moduleDatabaseMap = null;

    public static function resolveDatabaseConfig(?string $moduleKey = null): array {
        $normalizedKey = self::normalizeKey($moduleKey);
        // A dedicated module worker must not receive the generic audit worker
        // credential merely to construct defaults for its owned module.
        $baseConfig = self::resolvePrimaryConfig($normalizedKey === null);
        if ($normalizedKey === null) {
            return $baseConfig;
        }

        $entry = self::resolveEntry($normalizedKey);
        if ($entry === null) {
            return $baseConfig;
        }

        $envSuffix = strtoupper(str_replace('-', '_', $normalizedKey));
        $database = self::env("DB_DATABASE_{$envSuffix}", (string)($entry['database'] ?? $baseConfig['database']));
        $host = self::env("DB_HOST_{$envSuffix}", (string)($entry['host'] ?? $baseConfig['host']));
        $port = (int)self::env("DB_PORT_{$envSuffix}", (string)($entry['port'] ?? $baseConfig['port']));
        $username = self::env("DB_USERNAME_{$envSuffix}", (string)($entry['username'] ?? $baseConfig['username']));
        $password = self::env("DB_PASSWORD_{$envSuffix}", (string)($entry['password'] ?? $baseConfig['password']));
        $sslmode = self::env("DB_SSLMODE_{$envSuffix}", (string)($entry['sslmode'] ?? $baseConfig['sslmode']));
        $sslrootcert = self::env("DB_SSLROOTCERT_{$envSuffix}", (string)($entry['sslrootcert'] ?? $baseConfig['sslrootcert']));

        return self::applyConnectionRole([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'sslmode' => $sslmode,
            'sslrootcert' => $sslrootcert,
            'module' => $normalizedKey,
            'target_database' => (string)($entry['target_database'] ?? $database),
            'mode' => (string)($entry['mode'] ?? 'legacy-shared'),
        ], $normalizedKey);
    }

    public static function moduleDatabaseMap(): array {
        if (self::$moduleDatabaseMap !== null) {
            return self::$moduleDatabaseMap;
        }

        $registryPath = dirname(__DIR__, 2) . '/config/module-databases.php';
        if (!is_readable($registryPath)) {
            self::$moduleDatabaseMap = [];
            return self::$moduleDatabaseMap;
        }

        $registry = require $registryPath;
        self::$moduleDatabaseMap = is_array($registry) ? $registry : [];

        return self::$moduleDatabaseMap;
    }

    private static function resolvePrimaryConfig(bool $applyConnectionRole = true): array {
        $tenant = TenantContext::get();
        $tenantDb = is_array($tenant['db'] ?? null) ? $tenant['db'] : [];

        $config = [
            'host' => $tenantDb['host'] ?? self::env('DB_HOST', 'localhost'),
            'port' => (int)($tenantDb['port'] ?? self::env('DB_PORT', '5432')),
            'database' => $tenantDb['database'] ?? self::env('DB_DATABASE', 'ecommerce'),
            'username' => $tenantDb['username'] ?? self::env('DB_USERNAME', 'postgres'),
            'password' => $tenantDb['password'] ?? self::env('DB_PASSWORD', ''),
            'sslmode' => $tenantDb['sslmode'] ?? self::env('DB_SSLMODE', 'prefer'),
            'sslrootcert' => $tenantDb['sslrootcert'] ?? self::env('DB_SSLROOTCERT', ''),
            'module' => 'primary',
            'target_database' => $tenantDb['database'] ?? self::env('DB_DATABASE', 'ecommerce'),
            'mode' => 'primary',
        ];

        return $applyConnectionRole ? self::applyConnectionRole($config, null) : $config;
    }

    private static function resolveEntry(string $normalizedKey): ?array {
        $tenant = TenantContext::get();
        $tenantOverrides = is_array($tenant['db_modules'] ?? null) ? $tenant['db_modules'] : [];
        foreach ($tenantOverrides as $overrideKey => $overrideConfig) {
            if (self::normalizeKey((string)$overrideKey) !== $normalizedKey || !is_array($overrideConfig)) {
                continue;
            }

            return $overrideConfig;
        }

        foreach (self::moduleDatabaseMap() as $registryKey => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $aliases = array_map([self::class, 'normalizeKey'], array_merge([$registryKey], $entry['aliases'] ?? []));
            if (in_array($normalizedKey, array_filter($aliases), true)) {
                return $entry;
            }
        }

        return null;
    }

    private static function normalizeKey(?string $value): ?string {
        $normalized = strtolower(trim((string)$value));
        if ($normalized === '' || $normalized === 'default' || $normalized === 'primary' || $normalized === 'legacy') {
            return null;
        }

        return str_replace('_', '-', $normalized);
    }

    private static function env(string $key, string $default = ''): string {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || trim((string)$value) === '') {
            return $default;
        }

        return trim((string)$value);
    }

    private static function applyConnectionRole(array $config, ?string $moduleKey): array
    {
        $role = strtolower(self::env('DB_CONNECTION_ROLE', 'app'));
        if ($role === '' || $role === 'app') {
            $config['connection_role'] = 'app';
            return $config;
        }
        if ($role !== 'worker') {
            throw new \RuntimeException('DB_CONNECTION_ROLE solo admite app o worker.');
        }

        $suffix = $moduleKey !== null ? '_' . strtoupper(str_replace('-', '_', $moduleKey)) : '';
        $username = self::env('DB_WORKER_USERNAME' . $suffix, self::env('DB_WORKER_USERNAME'));
        $password = self::env('DB_WORKER_PASSWORD' . $suffix, self::env('DB_WORKER_PASSWORD'));
        if ($username === '' || $password === '') {
            throw new \RuntimeException(sprintf(
                'Faltan credenciales DB worker%s; RLS no permite reutilizar el rol de la API.',
                $suffix
            ));
        }

        $config['username'] = $username;
        $config['password'] = $password;
        $config['connection_role'] = 'worker';

        return $config;
    }
}
