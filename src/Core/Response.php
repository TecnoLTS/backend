<?php

namespace App\Core;

class Response {
    private static function authCookieName(): string {
        $baseName = trim((string)($_ENV['AUTH_COOKIE_NAME'] ?? 'pm_auth')) ?: 'pm_auth';
        return AuthSurface::authCookieName($baseName);
    }

    private static function authCookieNames(): array {
        $baseName = trim((string)($_ENV['AUTH_COOKIE_NAME'] ?? 'pm_auth')) ?: 'pm_auth';
        return AuthSurface::authCookieCandidates($baseName);
    }

    private static function csrfCookieName(): string {
        return trim((string)($_ENV['AUTH_CSRF_COOKIE_NAME'] ?? 'pm_csrf')) ?: 'pm_csrf';
    }

    private static function envFlag(string $key, ?bool $default = null): ?bool {
        $value = strtolower(trim((string)($_ENV[$key] ?? '')));
        if ($value === '') {
            return $default;
        }
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private static function isSecureRequest(): bool {
        $forcedSecure = self::envFlag('AUTH_COOKIE_SECURE');
        if ($forcedSecure !== null) {
            return $forcedSecure;
        }

        $appUrl = trim((string)($_ENV['APP_URL'] ?? ''));
        if ($appUrl !== '' && strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) === 'https') {
            return true;
        }

        $trustProxyHeaders = (bool)($GLOBALS['trust_proxy_headers'] ?? false);
        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($trustProxyHeaders && $forwardedProto !== '') {
            return $forwardedProto === 'https';
        }

        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    private static function shouldExposeServerMessage(?string $code): bool {
        if ($code === null) {
            return false;
        }
        $allowed = [
            'BILLING_RIDE_PDF_FAILED',
            'BILLING_XML_FAILED',
            'STORE_SALES_DISABLED',
        ];
        return in_array($code, $allowed, true);
    }

    private static function isQa(): bool {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        if ($env === 'qa') {
            return true;
        }
        $debug = strtolower((string)($_ENV['APP_DEBUG'] ?? 'false'));
        return in_array($debug, ['1', 'true', 'yes', 'on'], true);
    }

    private static function authCookieLifetimeSeconds(): int {
        $ttl = (int)($_ENV['AUTH_COOKIE_TTL_SECONDS'] ?? 10800);
        return $ttl > 0 ? $ttl : 10800;
    }

    public static function noStore(): void {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    public static function json($data = null, int $status = 200, ?array $meta = null, ?string $message = null): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $payload = [
            'ok' => true,
            'data' => $data,
        ];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        if ($message !== null) {
            $payload['message'] = $message;
        }
        echo json_encode($payload);
    }

    public static function error(string $message, int $status = 400, ?string $code = null, $details = null): void {
        $isDev = self::isQa();
        if ($status >= 500 && !$isDev && !self::shouldExposeServerMessage($code)) {
            error_log(sprintf(
                '[API_ERROR] status=%d code=%s message=%s details=%s',
                $status,
                $code ?? 'N/A',
                $message,
                $details !== null ? json_encode($details) : 'null'
            ));
            $message = 'Error interno del servidor';
            $details = null;
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $error = ['message' => $message];
        if ($code !== null) {
            $error['code'] = $code;
        }
        if ($details !== null) {
            $error['details'] = $details;
        }
        echo json_encode([
            'ok' => false,
            'error' => $error,
        ]);
    }

    public static function setAuthCookie(string $token, int $expiresAt): void {
        setcookie(self::authCookieName(), $token, [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => self::isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function setCsrfCookie(string $token, int $expiresAt): void {
        setcookie(self::csrfCookieName(), $token, [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => self::isSecureRequest(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::csrfCookieName()] = $token;
    }

    public static function ensureCsrfCookie(?int $expiresAt = null): string {
        $existing = trim((string)($_COOKIE[self::csrfCookieName()] ?? ''));
        if ($existing !== '') {
            if ($expiresAt !== null) {
                self::setCsrfCookie($existing, $expiresAt);
            }
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $ttl = $expiresAt ?? (time() + self::authCookieLifetimeSeconds());
        self::setCsrfCookie($token, $ttl);
        return $token;
    }

    public static function clearAuthCookie(): void {
        foreach (self::authCookieNames() as $cookieName) {
            setcookie($cookieName, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => self::isSecureRequest(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE[$cookieName]);
        }
    }

    public static function clearCsrfCookie(): void {
        setcookie(self::csrfCookieName(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::isSecureRequest(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::csrfCookieName()]);
    }
}
