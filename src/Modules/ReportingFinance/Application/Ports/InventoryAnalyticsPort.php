<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Application\Ports;

/**
 * CatalogInventory intelligence projection consumed by ReportingFinance.
 */
interface InventoryAnalyticsPort
{
    public function intelligence(?int $windowDays = null, ?int $targetDays = null): array;
}
