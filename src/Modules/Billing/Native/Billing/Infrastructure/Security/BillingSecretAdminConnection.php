<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Security;

use PDO;
use RuntimeException;

final class BillingSecretAdminConnection
{
    public static function fromEnvironment(): PDO
    {
        $username = self::env('DB_ADMIN_USERNAME');
        $passwordFile = self::env('DB_ADMIN_PASSWORD_FILE');
        $environmentPassword = $_ENV['DB_ADMIN_PASSWORD'] ?? getenv('DB_ADMIN_PASSWORD');
        $environmentPassword = is_string($environmentPassword) ? $environmentPassword : '';
        if ($environmentPassword !== '') {
            throw new RuntimeException('Billing migration DB password must not be injected through the environment.');
        }
        $password = $passwordFile !== '' ? self::readSecretFile($passwordFile) : '';
        if ($username === '' || $password === ''
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $username)
        ) {
            throw new RuntimeException('Billing migration DB administrator credentials are unavailable.');
        }

        $host = self::env('DB_HOST_BILLING', self::env('DB_HOST', 'db'));
        $port = self::env('DB_PORT_BILLING', self::env('DB_PORT', '5432'));
        $database = self::env('DB_DATABASE_BILLING', 'facturacion');
        $sslMode = self::env('DB_SSLMODE_BILLING', self::env('DB_SSLMODE', 'prefer'));
        $rootCertificate = self::env('DB_SSLROOTCERT_BILLING', self::env('DB_SSLROOTCERT'));
        if ($host === '' || preg_match('/[;\r\n]/', $host)
            || !preg_match('/^[1-9][0-9]{0,4}$/D', $port)
            || (int)$port > 65535
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $database)
            || !in_array($sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)
            || str_contains($rootCertificate, ';')
        ) {
            throw new RuntimeException('Billing migration DB target is invalid.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $host,
            $port,
            $database,
            $sslMode
        );
        if ($rootCertificate !== '') {
            $dsn .= ';sslrootcert=' . $rootCertificate;
        }

        $connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $canSeeEveryTenant = $connection->query(
            'SELECT rolsuper OR rolbypassrls
             FROM pg_roles
             WHERE rolname = current_user'
        )->fetchColumn();
        if (!in_array($canSeeEveryTenant, [true, 1, '1', 't'], true)) {
            throw new RuntimeException('Billing migration DB administrator cannot bypass tenant row security.');
        }
        $connection->exec('SET row_security = off');

        return $connection;
    }

    private static function readSecretFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Billing migration DB password file is unavailable.');
        }
        clearstatcache(true, $path);
        $permissions = @fileperms($path);
        if (is_int($permissions) && (($permissions & 0o022) !== 0)) {
            throw new RuntimeException('Billing migration DB password file has unsafe write permissions.');
        }
        $value = @file_get_contents($path);
        if (!is_string($value)) {
            throw new RuntimeException('Billing migration DB password file could not be read.');
        }
        $value = rtrim($value, "\r\n");
        if ($value === '' || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('Billing migration DB password file has an invalid format.');
        }

        return $value;
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = $_ENV[$name] ?? getenv($name);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
