<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Application\Ports;

interface EcommercePurchaseSource
{
    public function findMatches(string $tenantId, string $normalizedReference): PurchaseSourceMatches;
}
