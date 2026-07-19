<?php

declare(strict_types=1);

namespace App\Core;

use App\Modules\IdentityPlatform\Application\Ports\TenantTaxRegistryControlPlanePort;
use App\Modules\IdentityPlatform\Infrastructure\DatabaseTenantTaxRegistryControlPlane;

/** Explicit cross-module composition root; domain/application layers stay pure. */
final class ModuleControlPlaneFactory
{
    public static function tenantTaxRegistry(): TenantTaxRegistryControlPlanePort
    {
        return new DatabaseTenantTaxRegistryControlPlane();
    }
}
