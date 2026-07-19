<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resolves the authenticated internal hop without sharing one credential
 * across the edge gateway and the storefront runtime.
 */
final class InternalProxyTrust
{
    public const EDGE = 'edge';
    public const STOREFRONT = 'storefront';

    /** @param array<string, mixed> $environment */
    public static function resolveScope(array $environment, string $providedToken): ?string
    {
        $providedToken = trim($providedToken);
        if ($providedToken === '') {
            return null;
        }

        $matches = [];
        foreach (self::tokensByScope($environment) as $scope => $tokens) {
            foreach ($tokens as $token) {
                if (hash_equals($token, $providedToken)) {
                    $matches[$scope] = true;
                    break;
                }
            }
        }

        return count($matches) === 1 ? (string)array_key_first($matches) : null;
    }

    /** @param array<string, mixed> $environment */
    public static function configurationError(array $environment): ?string
    {
        $tokens = self::tokensByScope($environment);
        foreach ([self::EDGE, self::STOREFRONT] as $scope) {
            $current = $tokens[$scope][0] ?? '';
            if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $current)) {
                return sprintf('Missing or invalid current %s proxy credential.', $scope);
            }
        }

        if (array_intersect($tokens[self::EDGE], $tokens[self::STOREFRONT]) !== []) {
            return 'Internal proxy credentials overlap across consumer scopes.';
        }

        return null;
    }

    public static function allowsAuthSurface(
        ?string $scope,
        string $surface,
        string $method,
        string $path
    ): bool {
        $surface = strtolower(trim($surface));
        if ($scope === self::EDGE) {
            return in_array($surface, [AuthSurface::DASHBOARD, AuthSurface::ECOMMERCE], true);
        }
        if ($scope !== self::STOREFRONT) {
            return false;
        }
        if ($surface === AuthSurface::ECOMMERCE) {
            return true;
        }

        // The storefront runtime also owns the isolated Sharp transformer used
        // by one Dashboard upload route. Its Dashboard assertion is limited to
        // the two backend operations needed by that flow.
        $operation = strtoupper(trim($method)) . ' ' . (parse_url($path, PHP_URL_PATH) ?: '/');
        return $surface === AuthSurface::DASHBOARD && in_array($operation, [
            'GET /api/admin/dashboard/stats',
            'POST /api/admin/catalog/images',
        ], true);
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, list<string>>
     */
    private static function tokensByScope(array $environment): array
    {
        return [
            self::EDGE => self::normalizedTokens($environment, [
                'EDGE_BACKEND_PROXY_TOKEN',
                'EDGE_BACKEND_PROXY_TOKEN_PREVIOUS',
            ]),
            self::STOREFRONT => self::normalizedTokens($environment, [
                'STOREFRONT_BACKEND_PROXY_TOKEN',
                'STOREFRONT_BACKEND_PROXY_TOKEN_PREVIOUS',
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $environment
     * @param list<string> $keys
     * @return list<string>
     */
    private static function normalizedTokens(array $environment, array $keys): array
    {
        $tokens = [];
        foreach ($keys as $key) {
            $value = trim((string)($environment[$key] ?? ''));
            if ($value !== '' && !in_array($value, $tokens, true)) {
                $tokens[] = $value;
            }
        }

        return $tokens;
    }
}
