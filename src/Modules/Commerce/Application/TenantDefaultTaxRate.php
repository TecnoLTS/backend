<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application;

use App\Core\TenantContext;
use App\Shared\Tax\EcuadorSriVatCatalog;

/**
 * Canonical tenant default used whenever a product does not declare its own
 * tax rate. The value belongs to the signed tenant ecommerce configuration;
 * the legacy Setting row is deliberately not consulted.
 */
final class TenantDefaultTaxRate
{
    public static function current(): float
    {
        return self::fromTenant(TenantContext::get());
    }

    public static function fromTenant(?array $tenant): float
    {
        $configuration = is_array($tenant['ecommerce_configuration'] ?? null)
            ? $tenant['ecommerce_configuration']
            : [];
        $rawRate = $configuration['defaultTaxRate'] ?? null;
        $rate = self::normalize($rawRate);
        if ($rate === null) {
            throw new \DomainException('TENANT_TAX_CONFIGURATION_REQUIRED');
        }
        try {
            return EcuadorSriVatCatalog::assertSupportedRate($rate);
        } catch (\InvalidArgumentException $exception) {
            throw new \DomainException('TENANT_TAX_RATE_UNSUPPORTED', previous: $exception);
        }
    }

    /** @return array{rate:float,credit_current_rate:float,credit_carryforward_rate:float} */
    public static function currentConfiguration(): array
    {
        $tenant = TenantContext::get();
        $configuration = is_array($tenant['ecommerce_configuration'] ?? null)
            ? $tenant['ecommerce_configuration']
            : [];

        $creditCurrentRate = self::normalize($configuration['purchaseVatCreditCurrentRate'] ?? null);
        $creditCarryforwardRate = self::normalize($configuration['purchaseVatCreditCarryforwardRate'] ?? null);
        if ($creditCurrentRate === null || $creditCarryforwardRate === null) {
            throw new \DomainException('TENANT_TAX_CONFIGURATION_REQUIRED');
        }

        return [
            'rate' => self::fromTenant($tenant),
            'credit_current_rate' => $creditCurrentRate,
            'credit_carryforward_rate' => $creditCarryforwardRate,
        ];
    }

    public static function normalize(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = is_string($value) ? trim(str_replace(',', '.', $value)) : $value;
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $rate = round((float)$normalized, 2);
        return is_finite($rate) && $rate >= 0.0 && $rate <= 100.0 ? $rate : null;
    }

    public static function normalizeVatRate(mixed $value): ?float
    {
        try {
            return EcuadorSriVatCatalog::assertSupportedRate($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
