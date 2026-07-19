<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain;

use App\Shared\Tax\EcuadorSriVatCatalog;

/** Pure sale-tax precedence shared by catalog, checkout and inventory. */
final class ProductTaxPolicy
{
    public const TREATMENT_TAXED = EcuadorSriVatCatalog::TREATMENT_TAXED;
    public const TREATMENT_ZERO_RATED = EcuadorSriVatCatalog::TREATMENT_ZERO_RATED;
    public const TREATMENT_EXEMPT = EcuadorSriVatCatalog::TREATMENT_EXEMPT;

    public static function rate(array $attributes, float $tenantDefaultRate): float
    {
        if (self::isExempt($attributes)) {
            return 0.0;
        }

        foreach (['taxRate', 'tax_rate'] as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }
            $explicit = self::normalizeRate($attributes[$key]);
            if ($explicit === null) {
                throw new \InvalidArgumentException('PRODUCT_TAX_RATE_INVALID');
            }
            return EcuadorSriVatCatalog::assertSupportedRate($explicit);
        }

        return EcuadorSriVatCatalog::assertSupportedRate($tenantDefaultRate);
    }

    public static function isExempt(array $attributes): bool
    {
        return self::boolean(
            $attributes['taxExempt'] ?? $attributes['tax_exempt'] ?? false
        );
    }

    public static function treatment(array $attributes, float $tenantDefaultRate): string
    {
        if (self::isExempt($attributes)) {
            return self::TREATMENT_EXEMPT;
        }

        return self::rate($attributes, $tenantDefaultRate) === 0.0
            ? self::TREATMENT_ZERO_RATED
            : self::TREATMENT_TAXED;
    }

    public static function normalizeRate(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $normalized = is_string($value) ? trim(str_replace(',', '.', $value)) : $value;
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }
        return EcuadorSriVatCatalog::normalizeRate($normalized);
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float)$value !== 0.0;
        }
        return in_array(strtolower(trim((string)$value)), [
            '1', 'true', 't', 'yes', 'y', 'on', 'si', 'sí',
        ], true);
    }
}
