<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Application\Ports;

interface TenantNavigationPort
{
    public function supportsTenant(string $tenantId): bool;

    /** @return array{version:string,sections:array} */
    public function effectiveNavigation(string $tenantId, array $permissions): array;

    public function requiredPermissionForRequest(string $method, string $uri): string;

    public function unavailableVersion(): string;
}
