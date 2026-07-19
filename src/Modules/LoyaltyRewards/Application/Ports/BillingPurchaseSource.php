<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Application\Ports;

interface BillingPurchaseSource
{
    public function findMatches(string $tenantId, string $normalizedReference): PurchaseSourceMatches;
}
