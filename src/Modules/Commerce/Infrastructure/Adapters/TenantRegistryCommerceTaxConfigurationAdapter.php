<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Adapters;

use App\Core\TenantContext;
use App\Core\ModuleControlPlaneFactory;
use App\Modules\Commerce\Application\Ports\CommerceTaxConfigurationPort;
use App\Modules\Commerce\Application\TenantDefaultTaxRate;
use App\Modules\IdentityPlatform\Application\Ports\TenantTaxRegistryControlPlanePort;

/**
 * Writes Commerce's tenant default through the IdentityPlatform control-plane
 * capability. The registry is authoritative; its signed snapshot is the
 * bounded runtime projection consumed by pricing hot paths.
 */
final class TenantRegistryCommerceTaxConfigurationAdapter implements CommerceTaxConfigurationPort
{
    public function __construct(private readonly ?TenantTaxRegistryControlPlanePort $controlPlane = null)
    {
    }

    public function getTaxConfiguration(): array
    {
        $state = $this->controlPlane()->getState();
        $configuration = $this->storedConfiguration($state['registry'], $this->tenantId());

        return [
            ...$this->normalizeCompleteConfiguration($configuration),
            'revision' => $state['revision'],
        ];
    }

    public function updateTaxConfiguration(
        float $rate,
        float $creditCurrentRate,
        float $creditCarryforwardRate,
        string $actorUserId,
        int $expectedRevision
    ): array {
        $rate = TenantDefaultTaxRate::normalizeVatRate($rate);
        $creditCurrentRate = TenantDefaultTaxRate::normalize($creditCurrentRate);
        $creditCarryforwardRate = TenantDefaultTaxRate::normalize($creditCarryforwardRate);
        if ($rate === null || $creditCurrentRate === null || $creditCarryforwardRate === null) {
            throw new \InvalidArgumentException('SETTINGS_VAT_INVALID');
        }

        $tenantId = $this->tenantId();
        $controlPlane = $this->controlPlane();
        $actorUserId = trim($actorUserId) ?: 'tenant-settings-admin';
        if ($expectedRevision < 1) {
            throw new \InvalidArgumentException('TENANT_TAX_EXPECTED_REVISION_REQUIRED');
        }

        $state = $controlPlane->getState();
        if ($state['revision'] !== $expectedRevision) {
            throw new \DomainException('TENANT_TAX_CONFIGURATION_REVISION_CONFLICT');
        }
        $registry = $state['registry'];
        $storedTenant = $registry['tenants'][$tenantId] ?? null;
        if (!is_array($storedTenant)) {
            // The database registry is canonical. Never recreate a tenant
            // from a possibly stale request snapshot after offboarding.
            throw new \DomainException('TENANT_NOT_FOUND');
        }
        $configuration = is_array($storedTenant['ecommerce_configuration'] ?? null)
            ? $storedTenant['ecommerce_configuration']
            : [];

        $existingRate = TenantDefaultTaxRate::normalizeVatRate($configuration['defaultTaxRate'] ?? null);
        $existingCreditCurrentRate = TenantDefaultTaxRate::normalize($configuration['purchaseVatCreditCurrentRate'] ?? null);
        $existingCreditCarryforwardRate = TenantDefaultTaxRate::normalize($configuration['purchaseVatCreditCarryforwardRate'] ?? null);
        if ($existingRate !== null && $existingRate === $rate
            && $existingCreditCurrentRate !== null && $existingCreditCurrentRate === $creditCurrentRate
            && $existingCreditCarryforwardRate !== null && $existingCreditCarryforwardRate === $creditCarryforwardRate) {
            return [
                'rate' => $rate,
                'credit_current_rate' => $creditCurrentRate,
                'credit_carryforward_rate' => $creditCarryforwardRate,
                'revision' => $state['revision'],
                'applied' => false,
                'projection_synchronized' => $this->synchronizeRuntimeProjection(),
            ];
        }

            unset($configuration['default_tax_rate']);
            $configuration['defaultTaxRate'] = $rate;
            $configuration['purchaseVatCreditCurrentRate'] = $creditCurrentRate;
            $configuration['purchaseVatCreditCarryforwardRate'] = $creditCarryforwardRate;
            $storedTenant['ecommerce_configuration'] = $configuration;
            $storedTenant['updated_at'] = gmdate('c');
            $registry['version'] = 1;
            $registry['tenants'][$tenantId] = $storedTenant;

            $requestId = sprintf('vat.%s.%s', $tenantId, bin2hex(random_bytes(10)));
            $requestHash = hash('sha256', json_encode([
                'operation' => 'tenant.ecommerce-tax.update',
                'tenantId' => $tenantId,
                'expectedRevision' => $state['revision'],
                'defaultTaxRate' => $rate,
                'purchaseVatCreditCurrentRate' => $creditCurrentRate,
                'purchaseVatCreditCarryforwardRate' => $creditCarryforwardRate,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $result = $controlPlane->compareAndSet(
                $registry,
                $state['revision'],
                $requestId,
                $requestHash,
                'tenant.ecommerce-tax.update',
                $tenantId,
                $tenantId,
                $actorUserId
            );
        } catch (\Throwable $exception) {
            if (str_contains($exception->getMessage(), 'TENANT_REGISTRY_REVISION_CONFLICT')) {
                throw new \DomainException(
                    'TENANT_TAX_CONFIGURATION_REVISION_CONFLICT',
                    previous: $exception
                );
            }
            throw $exception;
        }

            // The canonical CAS mutation is already committed. A projection
            // failure must never be reported as if the database rolled back;
            // readiness is marked degraded and the next health reconciliation
            // retries the signed snapshot from the canonical store.
        return [
            'rate' => $rate,
            'credit_current_rate' => $creditCurrentRate,
            'credit_carryforward_rate' => $creditCarryforwardRate,
            'revision' => $result['revision'],
            'applied' => (bool)$result['applied'],
            'projection_synchronized' => $this->synchronizeRuntimeProjection(),
        ];
    }

    private function tenantId(): string
    {
        $tenantId = strtolower(trim((string)(TenantContext::get()['id'] ?? '')));
        if ($tenantId === '') {
            throw new \RuntimeException('TENANT_CONTEXT_REQUIRED');
        }
        return $tenantId;
    }

    /** @return array<string,mixed> */
    private function storedConfiguration(array $registry, string $tenantId): array
    {
        $tenant = $registry['tenants'][$tenantId] ?? null;
        if (!is_array($tenant)) {
            throw new \DomainException('TENANT_NOT_FOUND');
        }
        return is_array($tenant['ecommerce_configuration'] ?? null)
            ? $tenant['ecommerce_configuration']
            : [];
    }

    /** @return array{rate:float,credit_current_rate:float,credit_carryforward_rate:float} */
    private function normalizeCompleteConfiguration(array $configuration): array
    {
        $rate = TenantDefaultTaxRate::normalizeVatRate($configuration['defaultTaxRate'] ?? null);
        $creditCurrentRate = TenantDefaultTaxRate::normalize($configuration['purchaseVatCreditCurrentRate'] ?? null);
        $creditCarryforwardRate = TenantDefaultTaxRate::normalize($configuration['purchaseVatCreditCarryforwardRate'] ?? null);
        if ($rate === null || $creditCurrentRate === null || $creditCarryforwardRate === null) {
            throw new \DomainException('TENANT_TAX_CONFIGURATION_REQUIRED');
        }
        return [
            'rate' => $rate,
            'credit_current_rate' => $creditCurrentRate,
            'credit_carryforward_rate' => $creditCarryforwardRate,
        ];
    }

    private function synchronizeRuntimeProjection(): bool
    {
        try {
            $this->controlPlane()->refreshRuntimeProjection();
            return true;
        } catch (\Throwable $exception) {
            error_log('[TENANT_TAX_PROJECTION_REFRESH_FAILED] ' . $exception->getMessage());
            return false;
        }
    }

    private function controlPlane(): TenantTaxRegistryControlPlanePort
    {
        return $this->controlPlane ?? ModuleControlPlaneFactory::tenantTaxRegistry();
    }

}
