<?php

namespace App\Controllers;

use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Core\Response;
use App\Core\Auth;

class DashboardController {
    private $orderRepo;
    private $userRepo;

    public function __construct() {
        $this->orderRepo = new OrderRepository();
        $this->userRepo = new UserRepository();
    }

    private function authenticate() {
        Auth::requireAdmin();
    }

    public function stats() {
        $this->authenticate();
        
        try {
            $selectedMonth = isset($_GET['period'])
                ? (string)$_GET['period']
                : (isset($_GET['month']) ? (string)$_GET['month'] : null);
            $selectedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) === 1
                ? (string)$_GET['date']
                : null;
            $selectedYear = isset($_GET['year']) && preg_match('/^\d{4}$/', (string)$_GET['year']) === 1
                ? (string)$_GET['year']
                : null;
            $scope = isset($_GET['scope']) && in_array($_GET['scope'], ['day', 'week', 'year', 'historical'], true) ? (string)$_GET['scope'] : null;
            $includeReportRaw = strtolower(trim((string)($_GET['include_report'] ?? '1')));
            $includeReport = !in_array($includeReportRaw, ['0', 'false', 'no', 'off'], true);
            
            if ($scope === 'historical' || $scope === 'week' || $scope === 'year' || $scope === 'day' || $selectedDate) {
                $report = $this->orderRepo->getReportPeriodSummary($selectedMonth, $selectedDate, $scope, $selectedYear);
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
                $response = [
                    'totalSales' => ['amount' => $salesData['total'] ?? 0, 'progress' => ['percentage' => 0, 'current' => 0, 'previous' => 0]],
                    'newOrders' => ['count' => $salesData['orders_count'] ?? 0, 'progress' => ['percentage' => 0, 'current' => 0, 'previous' => 0]],
                    'newClients' => ['count' => 0, 'progress' => ['percentage' => 0, 'current' => 0, 'previous' => 0]],
                    'monthlyPerformance' => [],
                    'businessMetrics' => [
                        'report' => $report,
                        'averageOrderValue' => 0,
                        'salesSummary' => $mappedSales,
                        'profitStats' => $mappedProfit,
                    ],
                ];
            } else {
                $biService = new \App\Services\BusinessIntelligenceService();
                $response = $biService->getFullDashboardStats($selectedMonth, $selectedDate, $scope, $includeReport);
            }
            Response::json($response);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'DASHBOARD_STATS_FAILED');
        }
    }

    public function report() {
        $this->authenticate();
        
        try {
            $selectedMonth = isset($_GET['period'])
                ? (string)$_GET['period']
                : (isset($_GET['month']) ? (string)$_GET['month'] : null);
            $selectedDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) === 1
                ? (string)$_GET['date']
                : null;
            $selectedYear = isset($_GET['year']) && preg_match('/^\d{4}$/', (string)$_GET['year']) === 1
                ? (string)$_GET['year']
                : null;
            $scope = isset($_GET['scope']) && in_array($_GET['scope'], ['day', 'week', 'year', 'historical'], true) ? (string)$_GET['scope'] : null;
            $report = $this->orderRepo->getReportPeriodSummary($selectedMonth, $selectedDate, $scope, $selectedYear);
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
            Response::json($report);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'REPORT_SUMMARY_FAILED');
        }
    }
}
