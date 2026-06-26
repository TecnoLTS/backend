<?php

namespace App\Core;

final class ConnectionRegistry {
    private static ?array $moduleDatabaseMap = null;

    public static function resolveDatabaseConfig(?string $moduleKey = null): array {
        $baseConfig = self::resolvePrimaryConfig();
        $normalizedKey = self::normalizeKey($moduleKey);
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

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'module' => $normalizedKey,
            'target_database' => (string)($entry['target_database'] ?? $database),
            'mode' => (string)($entry['mode'] ?? 'legacy-shared'),
        ];
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

    private static function resolvePrimaryConfig(): array {
        $tenant = TenantContext::get();
        $tenantDb = is_array($tenant['db'] ?? null) ? $tenant['db'] : [];

        return [
            'host' => $tenantDb['host'] ?? self::env('DB_HOST', 'localhost'),
            'port' => (int)($tenantDb['port'] ?? self::env('DB_PORT', '5432')),
            'database' => $tenantDb['database'] ?? self::env('DB_DATABASE', 'paramascotasec'),
            'username' => $tenantDb['username'] ?? self::env('DB_USERNAME', 'postgres'),
            'password' => $tenantDb['password'] ?? self::env('DB_PASSWORD', ''),
            'module' => 'primary',
            'target_database' => $tenantDb['database'] ?? self::env('DB_DATABASE', 'paramascotasec'),
            'mode' => 'primary',
        ];
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
}
