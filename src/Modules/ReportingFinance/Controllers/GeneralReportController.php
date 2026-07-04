<?php

namespace App\Modules\ReportingFinance\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\OrderRepository;

final class GeneralReportController {
    private OrderRepository $orders;

    public function __construct() {
        $this->orders = new OrderRepository();
    }

    public function show(): void {
        Auth::requireAdmin();

        try {
            $query = $this->reportQuery();
            $projection = $this->projection();
            $report = $this->orders->getReportPeriodSummary(
                $query['period'],
                $query['date'],
                $query['scope'],
                $query['year'],
                [
                    'projection' => $projection,
                    'include_financial_trends' => $projection === 'screen',
                    'orders_limit' => 5,
                    'products_limit' => 8,
                    'categories_limit' => 8,
                ]
            );

            Response::json($this->withDashboardCompatibilityAliases($report));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'GENERAL_REPORT_FAILED');
        }
    }

    private function reportQuery(): array {
        $period = isset($_GET['period'])
            ? (string)$_GET['period']
            : (isset($_GET['month']) ? (string)$_GET['month'] : null);
        $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) === 1
            ? (string)$_GET['date']
            : null;
        $year = isset($_GET['year']) && preg_match('/^\d{4}$/', (string)$_GET['year']) === 1
            ? (string)$_GET['year']
            : null;
        $scope = isset($_GET['scope']) && in_array($_GET['scope'], ['day', 'week', 'year', 'historical'], true)
            ? (string)$_GET['scope']
            : null;

        return [
            'period' => $period,
            'date' => $date,
            'year' => $year,
            'scope' => $scope,
        ];
    }

    private function projection(): string {
        $raw = strtolower(trim((string)($_GET['projection'] ?? 'screen')));
        return $raw === 'export' ? 'export' : 'screen';
    }

    private function withDashboardCompatibilityAliases(array $report): array {
        $salesData = $report['sales'] ?? [];
        $profitData = $report['profit'] ?? [];
        $mappedSales = [
            'orders_count' => $salesData['orders_count'] ?? 0,
            'gross' => $salesData['total'] ?? 0,
            'net' => $salesData['net'] ?? 0,
            'vat' => $salesData['tax'] ?? 0,
            'shipping' => $salesData['shipping'] ?? 0,
            'cost' => $profitData['cost'] ?? 0,
            'profit' => $profitData['gross_profit'] ?? 0,
            'margin' => $profitData['gross_margin'] ?? 0,
        ];
        $mappedProfit = [
            'cost' => $profitData['cost'] ?? 0,
            'gross_profit' => $profitData['gross_profit'] ?? 0,
            'gross_margin' => $profitData['gross_margin'] ?? 0,
            'net_cash_profit' => $profitData['net_cash_profit'] ?? 0,
            'net_cash_margin' => $profitData['net_cash_margin'] ?? 0,
            'net_period_profit' => $profitData['net_period_profit'] ?? 0,
            'net_period_margin' => $profitData['net_period_margin'] ?? 0,
        ];

        $report['sales'] = array_merge($salesData, $mappedSales);
        $report['profit'] = array_merge($profitData, $mappedProfit);

        return $report;
    }
}
