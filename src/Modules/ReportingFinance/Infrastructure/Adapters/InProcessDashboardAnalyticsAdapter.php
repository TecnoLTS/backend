<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Infrastructure\Adapters;

use App\Modules\ReportingFinance\Application\Ports\DashboardAnalyticsPort;
use App\Services\BusinessIntelligenceService;

final class InProcessDashboardAnalyticsAdapter implements DashboardAnalyticsPort
{
    public function __construct(private readonly BusinessIntelligenceService $service = new BusinessIntelligenceService())
    {
    }

    public function fullDashboardStats(
        ?string $selectedMonth = null,
        ?string $selectedDate = null,
        ?string $scope = null,
        bool $includeReport = true
    ): array {
        return $this->service->getFullDashboardStats(
            $selectedMonth,
            $selectedDate,
            $scope,
            $includeReport
        );
    }

    public function productRankingStats(?string $selectedMonth = null, ?string $selectedDate = null): array
    {
        return $this->service->getProductRankingStats($selectedMonth, $selectedDate);
    }

    public function operationalAlertStats(): array
    {
        return $this->service->getOperationalAlertStats();
    }

    public function financialOverviewStats(): array
    {
        return $this->service->getFinancialOverviewStats();
    }
}
