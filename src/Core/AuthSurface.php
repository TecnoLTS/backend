<?php

namespace App\Core;

final class AuthSurface {
    public const DASHBOARD = 'dashboard';
    public const ECOMMERCE = 'ecommerce';

    private static function envFlag(string $key, bool $default = false): bool {
        $raw = $_ENV[$key] ?? getenv($key);
        $value = strtolower(trim((string)($raw === false ? '' : $raw)));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function legacyCookieFallbackEnabled(): bool {
        return self::envFlag('AUTH_LEGACY_COOKIE_FALLBACK_ENABLED');
    }

    public static function current(): string {
        $raw = '';
        $providedHeader = strtolower(trim((string)($_SERVER['HTTP_X_AUTH_SURFACE'] ?? '')));
        if (($GLOBALS['trusted_internal_proxy_token'] ?? false) && $providedHeader !== '') {
            $raw = $providedHeader;
        } elseif (self::envFlag('AUTH_LEGACY_SURFACE_QUERY_ENABLED')) {
            $raw = strtolower(trim((string)($_GET['auth_surface'] ?? '')));
        }

        if (in_array($raw, [self::DASHBOARD, self::ECOMMERCE], true)) {
            return $raw;
        }

        $uri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));
        $referer = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));
        $origin = strtolower((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if (
            str_starts_with($uri, '/dashboard/')
            || str_starts_with($uri, '/api/admin/')
            || str_starts_with($uri, '/api/users')
            || str_starts_with($uri, '/api/reports/')
            || str_starts_with($uri, '/api/shipments')
            || str_contains($referer, '/dashboard')
            || str_contains($origin, '/dashboard')
        ) {
            return self::DASHBOARD;
        }

        return self::ECOMMERCE;
    }

    public static function fromPayload(array $payload): string {
        $raw = strtolower(trim((string)($payload['auth_surface'] ?? $payload['aud'] ?? '')));
        if (in_array($raw, [self::DASHBOARD, self::ECOMMERCE], true)) {
            return $raw;
        }

        return self::current();
    }

    public static function authCookieName(string $baseName, ?string $surface = null): string {
        $baseName = trim($baseName) !== '' ? trim($baseName) : 'pm_auth';
        $surface = $surface ?? self::current();

        return $surface === self::DASHBOARD
            ? "{$baseName}_dashboard"
            : "{$baseName}_ecommerce";
    }

    public static function authCookieCandidates(string $baseName, ?string $surface = null): array {
        $baseName = trim($baseName) !== '' ? trim($baseName) : 'pm_auth';
        $ordered = [self::authCookieName($baseName, $surface ?? self::current())];
        if (self::legacyCookieFallbackEnabled()) {
            $ordered[] = $baseName;
        }

        return array_values(array_unique($ordered));
    }

    public static function csrfCookieName(string $baseName, ?string $surface = null): string {
        $baseName = trim($baseName) !== '' ? trim($baseName) : 'pm_csrf';
        $surface = $surface ?? self::current();

        return $surface === self::DASHBOARD
            ? "{$baseName}_dashboard"
            : "{$baseName}_ecommerce";
    }

    public static function csrfCookieCandidates(string $baseName, ?string $surface = null): array {
        $baseName = trim($baseName) !== '' ? trim($baseName) : 'pm_csrf';
        $ordered = [self::csrfCookieName($baseName, $surface ?? self::current())];
        if (self::legacyCookieFallbackEnabled()) {
            $ordered[] = $baseName;
        }

        return array_values(array_unique($ordered));
    }
}
