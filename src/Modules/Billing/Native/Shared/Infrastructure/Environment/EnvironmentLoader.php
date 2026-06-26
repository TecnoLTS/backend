<?php

declare(strict_types=1);

namespace BillingService\Shared\Infrastructure\Environment;

use Dotenv\Dotenv;

final class EnvironmentLoader
{
    public static function load(string $rootDir): void
    {
        self::hydrateProcessEnvironment();

        $rootDir = rtrim($rootDir, '/');
        $envDir = $rootDir . '/entorno';
        $envFile = '.env';
        $envPath = $envDir . '/' . $envFile;
        if (is_readable($envPath)) {
            Dotenv::createImmutable($envDir, $envFile)->safeLoad();
        } elseif (!self::hasProcessEnvironment()) {
            error_log('[ENV_WARNING] entorno/.env no es legible; no hay variables de entorno de proceso disponibles.');
        }

        self::hydrateProcessEnvironment();
    }

    private static function hydrateProcessEnvironment(): void
    {
        $values = getenv();
        if (!is_array($values)) {
            return;
        }

        foreach ($values as $key => $value) {
            if (is_string($key) && !array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }

    private static function hasProcessEnvironment(): bool
    {
        return getenv('APP_ENV') !== false
            || getenv('DB_HOST') !== false
            || isset($_ENV['APP_ENV'])
            || isset($_ENV['DB_HOST']);
    }
}
