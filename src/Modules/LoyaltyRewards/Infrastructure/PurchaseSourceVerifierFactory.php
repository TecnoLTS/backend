<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Infrastructure;

use App\Modules\LoyaltyRewards\Application\PurchaseSourceVerifier;
use App\Modules\LoyaltyRewards\Infrastructure\PurchaseSources\PostgresBillingPurchaseSource;
use App\Modules\LoyaltyRewards\Infrastructure\PurchaseSources\PostgresEcommercePurchaseSource;

final class PurchaseSourceVerifierFactory
{
    public static function create(): PurchaseSourceVerifier
    {
        return new PurchaseSourceVerifier(
            new PostgresEcommercePurchaseSource(),
            new PostgresBillingPurchaseSource()
        );
    }
}
