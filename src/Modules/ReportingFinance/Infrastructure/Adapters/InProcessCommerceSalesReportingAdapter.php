<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Infrastructure\Adapters;

use App\Modules\ReportingFinance\Application\Ports\SalesReportingPort;
use App\Repositories\OrderRepository;

final class InProcessCommerceSalesReportingAdapter implements SalesReportingPort
{
    public function __construct(private readonly OrderRepository $repository = new OrderRepository())
    {
    }

    public function periodSummary(
        ?string $selectedMonth = null,
        ?string $selectedDate = null,
        ?string $scope = null,
        ?string $selectedYear = null,
        array $options = []
    ): array {
        return $this->repository->getReportPeriodSummary(
            $selectedMonth,
            $selectedDate,
            $scope,
            $selectedYear,
            $options
        );
    }

    public function recentOrders(int $limit): array
    {
        return $this->repository->getRecentOrders($limit);
    }
}
