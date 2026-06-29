<?php

namespace App\Core;

use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Repositories\CustomerRepository;
use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
    private static ?array $payload = null;

    private static function trustedInternalProxyToken(): bool {
        return (bool)($GLOBALS['trusted_internal_proxy_service_auth'] ?? false);
    }

    private static function internalProxyPayload(): array {
        return [
            'iat' => time(),
            'sub' => 'service',
            'email' => 'dashboard-internal@' . (TenantContext::slug() ?: 'tenant') . '.local',
            'name' => 'dashboard internal proxy',
            'role' => 'admin',
            'tenant_id' => TenantContext::id(),
            'aud' => AuthSurface::DASHBOARD,
            'auth_surface' => AuthSurface::DASHBOARD,
            'service_auth' => true,
        ];
    }

    private static function authCookieNames(): array {
        $baseName = trim((string)($_ENV['AUTH_COOKIE_NAME'] ?? 'pm_auth')) ?: 'pm_auth';
        return AuthSurface::authCookieCandidates($baseName);
    }

    private static function isWeakJwtSecret(string $secretKey): bool {
        $weakValues = [
            'default_secret',
            'super-secret-key-change-this-in-production',
            'change-me-to-a-long-random-secret',
        ];

        return strlen($secretKey) < 32 || in_array($secretKey, $weakValues, true);
    }

    private static function getJwtSecretOrFail(): string {
        $secretKey = (string)($_ENV['JWT_SECRET'] ?? '');
        if ($secretKey === '' || self::isWeakJwtSecret($secretKey)) {
            Response::error('Configuración JWT inválida', 500, 'AUTH_CONFIG_INVALID');
            exit;
        }
        return $secretKey;
    }

    private static function getJwtSecretsForDecodeOrFail(): array {
        $current = self::getJwtSecretOrFail();
        $secrets = [$current];

        $previous = trim((string)($_ENV['JWT_SECRET_PREVIOUS'] ?? ''));
        if ($previous !== '' && !self::isWeakJwtSecret($previous) && !in_array($previous, $secrets, true)) {
            $secrets[] = $previous;
        }

        return $secrets;
    }

    public static function requireUser(): array {
        $payload = self::decodeFromRequest(true);
        if (!$payload) {
            Response::error('No autorizado', 401, 'AUTH_REQUIRED');
            exit;
        }
        return $payload;
    }

    public static function requireAdmin(): array {
        $payload = self::requireUser();
        if (!empty($payload['service_auth'])) {
            self::$payload = $payload;
            return $payload;
        }
        $sub = $payload['sub'] ?? null;
        if (!$sub || !is_string($sub)) {
            Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
            exit;
        }

        $repo = new UserRepository();
        $state = $repo->getAuthState($sub ?? '');
        $dbRole = strtolower((string)($state['role'] ?? 'customer'));
        $tenantAccess = new TenantAccessService();
        $platformAdmin = $state
            ? $tenantAccess->isPlatformAdmin($state, $dbRole, TenantContext::get() ?? [])
            : false;
        $managedTenantStaff = $state
            ? $tenantAccess->identityTypeForUser($state, TenantContext::get() ?? []) === 'tenant_staff'
            : false;
        if ($dbRole !== 'admin' && !$platformAdmin && !$managedTenantStaff) {
            Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
            exit;
        }

        $payload['role'] = 'admin';
        self::$payload = $payload;
        return $payload;
    }

    public static function optionalUser(): ?array {
        return self::decodeFromRequest(false);
    }

    public static function decodeFromRequest(bool $required): ?array {
        if (self::$payload !== null) {
            return self::$payload;
        }
        if (self::trustedInternalProxyToken()) {
            self::$payload = self::internalProxyPayload();
            return self::$payload;
        }
        $candidates = self::extractTokenCandidates();
        if ($candidates === []) {
            return null;
        }

        $secretKeys = self::getJwtSecretsForDecodeOrFail();
        foreach ($candidates as $jwt) {
            foreach ($secretKeys as $secretKey) {
                try {
                    $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
                    $payload = (array)$decoded;
                    self::validateTenantOrThrow($payload);
                    self::validateSurfaceOrThrow($payload);
                    self::validateActiveTokenOrThrow($payload);
                    self::$payload = $payload;
                    return $payload;
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        if ($required) {
            Response::error('Token inválido', 401, 'AUTH_TOKEN_INVALID');
            exit;
        }

        return null;
    }

    public static function validateRequestOrFail(): void {
        $payload = self::decodeFromRequest(false);
        if ($payload === null) {
            Response::error('No autorizado', 401, 'AUTH_REQUIRED');
            exit;
        }
        self::$payload = $payload;
    }

    private static function extractTokenCandidates(): array {
        $candidates = [];

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if ($authHeader && preg_match('/Bearer\\s(\\S+)/', $authHeader, $matches)) {
            $token = trim((string)$matches[1]);
            if ($token !== '') {
                $candidates[] = $token;
            }
        }

        foreach (self::authCookieNames() as $cookieName) {
            $cookieToken = trim((string)($_COOKIE[$cookieName] ?? ''));
            if ($cookieToken !== '' && !in_array($cookieToken, $candidates, true)) {
                $candidates[] = $cookieToken;
            }
        }

        return $candidates;
    }

    private static function assertTenant(array $payload): void {
        try {
            self::validateTenantOrThrow($payload);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'TENANT_REQUIRED') {
                Response::error('Tenant requerido', 400, 'TENANT_REQUIRED');
            } else {
                Response::error('Tenant inválido', 403, 'TENANT_FORBIDDEN');
            }
            exit;
        }
    }

    private static function validateTenantOrThrow(array $payload): void {
        $tenantId = TenantContext::id();
        if (!$tenantId) {
            throw new \RuntimeException('TENANT_REQUIRED');
        }
        $payloadTenant = $payload['tenant_id'] ?? null;
        if (!$payloadTenant || $payloadTenant !== $tenantId) {
            throw new \RuntimeException('TENANT_FORBIDDEN');
        }
    }

    private static function validateSurfaceOrThrow(array $payload): void {
        if (!empty($payload['service_auth'])) {
            return;
        }

        $payloadSurface = strtolower(trim((string)($payload['auth_surface'] ?? $payload['aud'] ?? '')));
        if ($payloadSurface === '') {
            return;
        }

        if (!in_array($payloadSurface, [AuthSurface::DASHBOARD, AuthSurface::ECOMMERCE], true)) {
            throw new \RuntimeException('AUTH_SURFACE_INVALID');
        }

        if ($payloadSurface !== AuthSurface::current()) {
            throw new \RuntimeException('AUTH_SURFACE_MISMATCH');
        }
    }

    private static function assertActiveToken(array &$payload): void {
        try {
            self::validateActiveTokenOrThrow($payload);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'AUTH_TOKEN_REVOKED') {
                Response::error('Token inválido', 401, 'AUTH_TOKEN_REVOKED');
            } else {
                Response::error('Token inválido', 401, 'AUTH_TOKEN_INVALID');
            }
            exit;
        }
    }

    private static function validateActiveTokenOrThrow(array &$payload): void {
        $sub = $payload['sub'] ?? null;
        $jti = $payload['jti'] ?? null;
        if (!$sub) {
            throw new \RuntimeException('AUTH_TOKEN_INVALID');
        }

        if ($sub === 'service') {
            throw new \RuntimeException('AUTH_TOKEN_INVALID');
        }

        $surface = AuthSurface::fromPayload($payload);
        $repo = $surface === AuthSurface::ECOMMERCE
            ? new CustomerRepository()
            : new UserRepository();
        $state = $repo->getAuthState($sub);
        if (!$state) {
            throw new \RuntimeException('AUTH_TOKEN_REVOKED');
        }

        $activeTokenId = $state['active_token_id'] ?? null;
        if (!$activeTokenId || !$jti || $activeTokenId !== $jti) {
            throw new \RuntimeException('AUTH_TOKEN_REVOKED');
        }

        $tokenRole = strtolower((string)($payload['role'] ?? 'customer'));
        $dbRole = strtolower((string)($state['role'] ?? 'customer'));
        if ($tokenRole !== $dbRole) {
            throw new \RuntimeException('AUTH_TOKEN_REVOKED');
        }

        $payload['role'] = $dbRole;
    }
}
