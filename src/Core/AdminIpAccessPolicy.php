<?php

namespace App\Core;

final class AdminIpAccessPolicy
{
    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (in_array($mode, ['private', 'private-lan', 'lan'], true)) {
            return 'private';
        }
        return $mode === 'custom' ? 'custom' : 'off';
    }

    public static function productionConfigurationError(
        string $appEnvironment,
        string $mode,
        string $allowlist
    ): ?string {
        if (!in_array(strtolower(trim($appEnvironment)), ['production', 'prod'], true)) {
            return null;
        }
        if (self::normalizeMode($mode) !== 'custom') {
            return 'ADMIN_IP_MODE_MUST_BE_CUSTOM';
        }

        $rules = array_values(array_unique(self::configuredRules($allowlist)));
        if (count($rules) < 2) {
            return 'ADMIN_IP_ALLOWLIST_REQUIRES_TWO_CIDRS';
        }
        foreach ($rules as $rule) {
            if (!self::isValidCidr($rule, false)) {
                return 'ADMIN_IP_ALLOWLIST_CIDR_INVALID';
            }
        }

        return null;
    }

    public static function matches(string $ip, string $allowlist, string $mode = 'off'): bool
    {
        if (@inet_pton($ip) === false) {
            return false;
        }
        $normalizedMode = self::normalizeMode($mode);
        $rules = self::rules($mode, $allowlist);
        if ($rules === []) {
            return $normalizedMode === 'off';
        }
        foreach ($rules as $rule) {
            if (self::ipInCidr($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    public static function rules(string $mode, string $allowlist): array
    {
        $configured = self::configuredRules($allowlist);
        return match (self::normalizeMode($mode)) {
            'private' => array_values(array_unique(array_merge(self::privateRules(), $configured))),
            default => $configured,
        };
    }

    public static function ipInCidr(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            $ipBinary = @inet_pton($ip);
            $ruleBinary = @inet_pton($rule);
            return $ipBinary !== false && $ruleBinary !== false && hash_equals($ruleBinary, $ipBinary);
        }
        if (!self::isValidCidr($rule, true)) {
            return false;
        }

        [$subnet, $prefixLength] = explode('/', $rule, 2);
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $prefix = (int)$prefixLength;
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;
        if ($bytes > 0 && !hash_equals(substr($subnetBinary, 0, $bytes), substr($ipBinary, 0, $bytes))) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $mask = (~(0xff >> $bits)) & 0xff;
        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function configuredRules(string $allowlist): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $allowlist))));
    }

    private static function isValidCidr(string $rule, bool $allowZeroPrefix): bool
    {
        if (substr_count($rule, '/') !== 1) {
            return false;
        }
        [$subnet, $prefixLength] = explode('/', $rule, 2);
        $binary = @inet_pton($subnet);
        if ($binary === false || $prefixLength === '' || !ctype_digit($prefixLength)) {
            return false;
        }
        $prefix = (int)$prefixLength;
        $maximum = strlen($binary) === 4 ? 32 : 128;
        return $prefix <= $maximum && ($allowZeroPrefix ? $prefix >= 0 : $prefix >= 1);
    }

    /** @return list<string> */
    private static function privateRules(): array
    {
        return [
            '127.0.0.1/32',
            '::1/128',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            'fc00::/7',
        ];
    }
}
