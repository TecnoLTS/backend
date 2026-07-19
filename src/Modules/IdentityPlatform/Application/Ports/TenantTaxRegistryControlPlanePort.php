<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Application\Ports;

/** Narrow IdentityPlatform capability exposed to collaborating modules. */
interface TenantTaxRegistryControlPlanePort
{
    /** @return array{revision:int,registry:array} */
    public function getState(): array;

    /** @return array{revision:int,applied:bool,idempotent:bool} */
    public function compareAndSet(
        array $registry,
        int $expectedRevision,
        string $requestId,
        string $requestHash,
        string $operation,
        string $targetTenantId,
        string $actorTenantId,
        string $actorUserId
    ): array;

    public function refreshRuntimeProjection(): void;
}
