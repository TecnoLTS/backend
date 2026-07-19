<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Application\Ports;

/**
 * Stable analytics projection exposed to ReportingFinance controllers.
 */
interface DashboardAnalyticsPort
{
    public function fullDashboardStats(
        ?string $selectedMonth = null,
        ?string $selectedDate = null,
        ?string $scope = null,
        bool $includeReport = true
    ): array;

    public function productRankingStats(?string $selectedMonth = null, ?string $selectedDate = null): array;

    public function operationalAlertStats(): array;

    public function financialOverviewStats(): array;
}
