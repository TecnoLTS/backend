<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Infrastructure\Navigation;

use App\Modules\IdentityPlatform\Application\Ports\TenantNavigationPort;

final class TenantNavigationPortFactory
{
    public static function create(): TenantNavigationPort
    {
        return new LoyaltyTenantNavigationAdapter();
    }
}
