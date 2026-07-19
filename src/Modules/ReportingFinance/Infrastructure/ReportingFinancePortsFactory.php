<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Infrastructure;

use App\Modules\ReportingFinance\Application\Ports\BusinessExpensePort;
use App\Modules\ReportingFinance\Application\Ports\DashboardAnalyticsPort;
use App\Modules\ReportingFinance\Application\Ports\FinancialPeriodPort;
use App\Modules\ReportingFinance\Application\Ports\InventoryAnalyticsPort;
use App\Modules\ReportingFinance\Application\Ports\SalesReportingPort;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessBusinessExpenseAdapter;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessCatalogInventoryAnalyticsAdapter;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessCommerceSalesReportingAdapter;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessDashboardAnalyticsAdapter;
use App\Modules\ReportingFinance\Infrastructure\Adapters\InProcessFinancialPeriodAdapter;

/**
 * Composition root for ReportingFinance's in-process contracts.
 */
final class ReportingFinancePortsFactory
{
    public static function salesReporting(): SalesReportingPort
    {
        return new InProcessCommerceSalesReportingAdapter();
    }

    public static function dashboardAnalytics(): DashboardAnalyticsPort
    {
        return new InProcessDashboardAnalyticsAdapter();
    }

    public static function inventoryAnalytics(): InventoryAnalyticsPort
    {
        return new InProcessCatalogInventoryAnalyticsAdapter();
    }

    public static function financialPeriods(): FinancialPeriodPort
    {
        return new InProcessFinancialPeriodAdapter();
    }

    public static function businessExpenses(): BusinessExpensePort
    {
        return new InProcessBusinessExpenseAdapter();
    }
}
