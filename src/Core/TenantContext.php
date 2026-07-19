<?php

namespace App\Core;

class TenantContext {
    private static ?array $tenant = null;

    public static function set(array $tenant): void {
        self::$tenant = $tenant;
    }

    public static function clear(): void {
        self::$tenant = null;
    }

    public static function get(): ?array {
        return self::$tenant;
    }

    public static function id(): ?string {
        return self::$tenant['id'] ?? null;
    }

    public static function slug(): ?string {
        return self::$tenant['slug'] ?? null;
    }

    public static function name(): ?string {
        return self::$tenant['name'] ?? null;
    }

    public static function appUrl(): ?string {
        return self::$tenant['app_url'] ?? null;
    }

    public static function publicBaseUrl(): ?string {
        return self::$tenant['public_base_url'] ?? null;
    }

    public static function isOriginAllowed(?string $origin): bool {
        if (!$origin) {
            return false;
        }
        $allowed = self::$tenant['allowed_origins'] ?? [];
        return in_array($origin, $allowed, true);
    }
}
