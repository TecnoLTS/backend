<?php

namespace App\Core;

final class AuthSurface {
    public const DASHBOARD = 'dashboard';
    public const ECOMMERCE = 'ecommerce';

    public static function current(): string {
        $raw = strtolower(trim((string)($_SERVER['HTTP_X_AUTH_SURFACE'] ?? $_GET['auth_surface'] ?? '')));
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

    public static function authCookieCandidates(string $baseName): array {
        $baseName = trim($baseName) !== '' ? trim($baseName) : 'pm_auth';
        $current = self::current();
        $ordered = [
            self::authCookieName($baseName, $current),
            self::authCookieName($baseName, $current === self::DASHBOARD ? self::ECOMMERCE : self::DASHBOARD),
            $baseName,
        ];

        return array_values(array_unique($ordered));
    }
}
