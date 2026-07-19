<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure;

use App\Modules\Commerce\Application\Ports\CommerceContactPort;
use App\Modules\Commerce\Application\Ports\CommerceExpensePort;
use App\Modules\Commerce\Application\Ports\CommerceSettingsPort;
use App\Modules\Commerce\Application\Ports\CommerceTaxConfigurationPort;
use App\Modules\Commerce\Infrastructure\Adapters\InProcessCommerceContactAdapter;
use App\Modules\Commerce\Infrastructure\Adapters\InProcessCommerceExpenseAdapter;
use App\Modules\Commerce\Infrastructure\Adapters\InProcessCommerceSettingsAdapter;
use App\Modules\Commerce\Infrastructure\Adapters\TenantRegistryCommerceTaxConfigurationAdapter;

final class CommerceCollaborationPortsFactory
{
    public static function contact(): CommerceContactPort
    {
        return new InProcessCommerceContactAdapter();
    }

    public static function expenses(): CommerceExpensePort
    {
        return new InProcessCommerceExpenseAdapter();
    }

    public static function settings(): CommerceSettingsPort
    {
        return new InProcessCommerceSettingsAdapter();
    }

    public static function taxConfiguration(): CommerceTaxConfigurationPort
    {
        return new TenantRegistryCommerceTaxConfigurationAdapter();
    }
}
