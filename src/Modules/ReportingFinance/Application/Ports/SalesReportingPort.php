<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Application\Ports;

/**
 * Read-only Commerce projection consumed by ReportingFinance.
 */
interface SalesReportingPort
{
    public function periodSummary(
        ?string $selectedMonth = null,
        ?string $selectedDate = null,
        ?string $scope = null,
        ?string $selectedYear = null,
        array $options = []
    ): array;

    public function recentOrders(int $limit): array;
}
