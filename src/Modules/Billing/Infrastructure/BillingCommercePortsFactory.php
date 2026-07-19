<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Application\Ports\BillingOrderAccountingPort;
use App\Modules\Billing\Application\Ports\BillingProductCatalogPort;
use App\Modules\Billing\Infrastructure\Adapters\InProcessBillingOrderAccountingAdapter;
use App\Modules\Billing\Infrastructure\Adapters\InProcessBillingProductCatalogAdapter;

final class BillingCommercePortsFactory
{
    public static function products(): BillingProductCatalogPort
    {
        return new InProcessBillingProductCatalogAdapter();
    }

    public static function orders(): BillingOrderAccountingPort
    {
        return new InProcessBillingOrderAccountingAdapter();
    }
}
