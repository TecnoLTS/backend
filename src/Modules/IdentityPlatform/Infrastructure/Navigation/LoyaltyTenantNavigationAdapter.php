<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Infrastructure\Navigation;

use App\Modules\IdentityPlatform\Application\Ports\TenantNavigationPort;
use App\Modules\LoyaltyRewards\Application\LoyaltyNavigationService;
use App\Modules\LoyaltyRewards\Domain\LoyaltyNavigationCatalog;

/** Cross-module adapter; Identity application code depends only on its port. */
final class LoyaltyTenantNavigationAdapter implements TenantNavigationPort
{
    public function __construct(private readonly LoyaltyNavigationService $service = new LoyaltyNavigationService())
    {
    }

    public function supportsTenant(string $tenantId): bool
    {
        return strtolower(trim($tenantId)) === LoyaltyNavigationCatalog::INITIAL_TENANT_ID;
    }

    public function effectiveNavigation(string $tenantId, array $permissions): array
    {
        return $this->service->effectiveNavigation($tenantId, $permissions);
    }

    public function requiredPermissionForRequest(string $method, string $uri): string
    {
        return $this->service->requiredPermissionForRequest($method, $uri)
            ?? LoyaltyNavigationService::DENY_PERMISSION;
    }

    public function unavailableVersion(): string
    {
        return LoyaltyNavigationCatalog::VERSION . '-unavailable';
    }
}
