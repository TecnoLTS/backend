<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Infrastructure;

use App\Modules\IdentityPlatform\Application\Ports\TenantTaxRegistryControlPlanePort;

/** Database + signed-snapshot implementation owned by IdentityPlatform. */
final class DatabaseTenantTaxRegistryControlPlane implements TenantTaxRegistryControlPlanePort
{
    public function __construct(private readonly ?TenantRuntimeRegistryStore $store = null)
    {
    }

    public function getState(): array
    {
        return ($this->store ?? new TenantRuntimeRegistryStore())->getState();
    }

    public function compareAndSet(
        array $registry,
        int $expectedRevision,
        string $requestId,
        string $requestHash,
        string $operation,
        string $targetTenantId,
        string $actorTenantId,
        string $actorUserId
    ): array {
        return ($this->store ?? new TenantRuntimeRegistryStore())->set(
            $registry,
            $expectedRevision,
            $requestId,
            $requestHash,
            $operation,
            $targetTenantId,
            $actorTenantId,
            $actorUserId
        );
    }

    public function refreshRuntimeProjection(): void
    {
        TenantRuntimeRegistry::refreshSnapshot();
    }
}
