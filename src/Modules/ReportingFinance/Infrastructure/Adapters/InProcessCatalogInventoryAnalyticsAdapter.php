<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Infrastructure\Adapters;

use App\Modules\ReportingFinance\Application\Ports\InventoryAnalyticsPort;
use App\Services\InventoryIntelligenceService;

final class InProcessCatalogInventoryAnalyticsAdapter implements InventoryAnalyticsPort
{
    public function __construct(private readonly InventoryIntelligenceService $service = new InventoryIntelligenceService())
    {
    }

    public function intelligence(?int $windowDays = null, ?int $targetDays = null): array
    {
        return $this->service->getIntelligence($windowDays, $targetDays);
    }
}
