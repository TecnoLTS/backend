<?php

namespace App\Core;

class Response {
    private static function authCookieName(): string {
        return trim((string)($_ENV['AUTH_COOKIE_NAME'] ?? 'pm_auth')) ?: 'pm_auth';
    }

    private static function csrfCookieName(): string {
        return trim((string)($_ENV['AUTH_CSRF_COOKIE_NAME'] ?? 'pm_csrf')) ?: 'pm_csrf';
    }

    private static function isSecureRequest(): bool {
        $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwardedProto !== '') {
            return $forwardedProto === 'https';
        }

        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    private static function shouldExposeServerMessage(?string $code): bool {
        if ($code === null) {
            return false;
        }
        $allowed = [
            'STORE_SALES_DISABLED',
        ];
        return in_array($code, $allowed, true);
    }

    private static function isDevelopment(): bool {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        if ($env === 'development' || $env === 'dev' || $env === 'local') {
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
        $isDev = self::isDevelopment();
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
        setcookie(self::authCookieName(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => self::isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
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
