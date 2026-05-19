<?php

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SettingsRepository;

class BusinessIntelligenceService {
    private $orderRepo;
    private $userRepo;

    public function __construct() {
        $this->orderRepo = new OrderRepository();
        $this->userRepo = new UserRepository();
    }

    public function getFullDashboardStats(?string $selectedMonth = null, ?string $selectedDate = null, ?string $scope = null) {
        $settings = new SettingsRepository();
        $vatRate = $settings->get('vat_rate');
        $vatRate = is_numeric($vatRate) ? floatval($vatRate) : 0;
        $salesProgress = $this->orderRepo->getSalesProgress();
        $ordersProgress = $this->orderRepo->getOrdersProgress();
        $clientsProgress = $this->userRepo->getClientsProgress();
        $inventoryDeepDive = $this->inventoryHealthCheck();
        $productAnalysis = $this->productAnalytics();
        $profitStats = $this->profitAnalysis();
        $ordersByStatus = $this->orderRepo->getOrdersByStatus();
        $recentOrders = $this->orderRepo->getRecentOrders(8);
        $salesDeepDive = $this->orderRepo->getSalesDeepDive();
        $aovDeepDive = $this->orderRepo->getAOVDeepDive();
        $salesSummary = $this->orderRepo->getSalesSummary();
        $traceability = $this->orderRepo->getKpiTraceability();
        $productSalesRanking = $this->orderRepo->getProductSalesRanking($selectedMonth, $selectedDate);
        $periodReport = $this->orderRepo->getReportPeriodSummary($selectedMonth, $selectedDate, $scope);
        $financialTrends = $this->orderRepo->getFinancialTrends();

        return [
            'tax' => [
                'rate' => $vatRate,
                'multiplier' => round(1 + ($vatRate / 100), 4)
            ],
            'totalSales' => [
                'amount' => (float)$this->orderRepo->getTotalSales(),
                'progress' => $salesProgress
            ],
            'newOrders' => [
                'count' => (int)$this->orderRepo->getNewOrdersCount(),
                'progress' => $ordersProgress
            ],
            'newClients' => [
                'count' => (int)$this->userRepo->getNewUsersCount(),
                'progress' => $clientsProgress
            ],
            'monthlyPerformance' => $this->orderRepo->getMonthlyPerformance(),
            'salesTrend30Days' => $this->orderRepo->getSalesTrend30Days(),
            'topProducts' => $this->orderRepo->getTopProducts(),
            'salesByCategory' => $this->orderRepo->getSalesByCategory(),
            'productAnalysis' => $productAnalysis,
            'businessMetrics' => [
                'averageOrderValue' => $this->orderRepo->getAverageOrderValue(),
                'profitStats' => $profitStats,
                'inventoryValue' => $this->orderRepo->getInventoryValue(),
                'ordersByStatus' => $ordersByStatus,
                'recentOrders' => $recentOrders,
                'salesDeepDive' => $salesDeepDive,
                'inventoryDeepDive' => $inventoryDeepDive,
                'aovDeepDive' => $aovDeepDive,
                'salesSummary' => $salesSummary,
                'financialTrends' => $financialTrends,
                'traceability' => $traceability,
                'productSalesRanking' => $productSalesRanking,
                'report' => $periodReport
            ],
            'strategicAlerts' => $this->generateAlerts($inventoryDeepDive, $salesProgress, $productAnalysis, $ordersByStatus)
        ];
    }

    private function profitAnalysis() {
        $raw = $this->orderRepo->getProfitStats();
        $grossProfit = (float)($raw['gross_profit'] ?? $raw['profit'] ?? 0);
        $netProfit = (float)($raw['net_profit'] ?? $grossProfit);
        $netCommittedProfit = (float)($raw['net_committed_profit'] ?? $netProfit);
        $cost = (float)($raw['cost'] ?? 0);
        $paidExpenses = (float)($raw['paid_expenses'] ?? $raw['operating_expenses'] ?? 0);
        $periodExpenses = (float)($raw['period_expenses'] ?? $raw['operating_expenses'] ?? $raw['committed_expenses'] ?? $paidExpenses);
        $committedExpenses = (float)($raw['committed_expenses'] ?? $periodExpenses);
        $raw['roi'] = $cost > 0 ? round(($grossProfit / $cost) * 100, 1) : 0;
        $cashInvestmentBase = $cost + $paidExpenses;
        $raw['cash_net_roi'] = $cashInvestmentBase > 0 ? round(((float)($raw['net_cash_profit'] ?? $netProfit) / $cashInvestmentBase) * 100, 1) : 0;
        $netInvestmentBase = $cost + $periodExpenses;
        $raw['net_roi'] = $netInvestmentBase > 0 ? round(($netProfit / $netInvestmentBase) * 100, 1) : 0;
        $committedInvestmentBase = $cost + $committedExpenses;
        $raw['committed_net_roi'] = $committedInvestmentBase > 0 ? round(($netCommittedProfit / $committedInvestmentBase) * 100, 1) : 0;
        return $raw;
    }

    private function inventoryHealthCheck() {
        return $this->orderRepo->getInventoryDeepDive();
    }

    private function productAnalytics() {
        $pRepo = new \App\Repositories\ProductRepository();
        $products = $pRepo->getAll();

        $totalMargin = 0.0;
        $marginSampleCount = 0;
        $weightedProfit = 0.0;
        $weightedRevenue = 0.0;
        $lowMarginCount = 0;
        $missingCostCount = 0;
        $stockValueAtCost = 0.0;

        foreach ($products as $p) {
            $price = (float)($p['price'] ?? 0);
            $cost = (float)($p['cost'] ?? ($p['business']['cost'] ?? 0));
            $quantity = max(0, (int)($p['quantity'] ?? 0));

            if ($cost <= 0) {
                $missingCostCount++;
            }

            if ($price <= 0 || $cost <= 0) {
                continue;
            }

            if (isset($p['business']['margin']) && is_numeric($p['business']['margin'])) {
                $margin = (float)$p['business']['margin'];
            } else {
                $margin = (($price - $cost) / $price) * 100;
            }

            $totalMargin += $margin;
            $marginSampleCount++;
            if ($margin < 25) {
                $lowMarginCount++;
            }

            $stockRevenue = $price * $quantity;
            $stockCost = $cost * $quantity;
            $weightedRevenue += $stockRevenue;
            $weightedProfit += ($stockRevenue - $stockCost);
            $stockValueAtCost += $stockCost;
        }

        $avgMargin = $marginSampleCount > 0 ? round($totalMargin / $marginSampleCount, 1) : 0;
        $weightedMargin = $weightedRevenue > 0 ? round(($weightedProfit / $weightedRevenue) * 100, 1) : 0;
        
        return [
            'averageMargin' => $avgMargin,
            'weightedMargin' => $weightedMargin,
            'lowMarginOpportunities' => $lowMarginCount,
            'missingCostCount' => $missingCostCount,
            'stockValueAtCost' => round($stockValueAtCost, 2),
            'totalMonitored' => count($products),
            'pricedCostedProducts' => $marginSampleCount
        ];
    }

    private function generateAlerts($inventory = null, $salesProgress = null, $productAnalysis = null, $ordersByStatus = null) {
        $alerts = [];
        $inventory = is_array($inventory) ? $inventory : $this->orderRepo->getInventoryDeepDive();
        $health = is_array($inventory['health'] ?? null) ? $inventory['health'] : [];
        $expiredCount = (int)($health['expired_products'] ?? 0);
        $expiringCount = (int)($health['expiring_products'] ?? 0);
        
        if ((int)($health['out_of_stock'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'critical',
                'message' => "Tienes " . (int)$health['out_of_stock'] . " productos sin stock. Riesgo de pérdida de ventas.",
                'action' => 'Ver inventario'
            ];
        }

        if ($expiredCount > 0) {
            $alerts[] = [
                'type' => 'critical',
                'message' => "Hay {$expiredCount} productos vencidos con stock. Retíralos de venta y gestiona devolución o merma.",
                'action' => 'Revisar vencimientos'
            ];
        }

        if ($expiringCount > 0) {
            $nearestExpiry = null;
            if (!empty($inventory['expiringItems']) && is_array($inventory['expiringItems'])) {
                $nearestExpiry = $inventory['expiringItems'][0];
            }
            $daysToExpire = isset($nearestExpiry['days_to_expire']) ? (int)$nearestExpiry['days_to_expire'] : null;
            $soonText = ($daysToExpire !== null && $daysToExpire >= 0)
                ? " El más próximo vence en {$daysToExpire} día(s)."
                : '';

            $alerts[] = [
                'type' => 'warning',
                'message' => "Tienes {$expiringCount} productos próximos a vencer." . $soonText,
                'action' => 'Planificar rotación'
            ];
        }

        $salesProgress = is_array($salesProgress) ? $salesProgress : $this->orderRepo->getSalesProgress();
        if ($salesProgress['percentage'] < -10) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "Las ventas han bajado un " . abs($salesProgress['percentage']) . "% respecto al mes pasado.",
                'action' => 'Analizar campañas'
            ];
        }

        $productAnalysis = is_array($productAnalysis) ? $productAnalysis : $this->productAnalytics();
        $lowMargin = (int)($productAnalysis['lowMarginOpportunities'] ?? 0);
        if ($lowMargin > 0) {
            $alerts[] = [
                'type' => 'info',
                'message' => "Tienes {$lowMargin} productos con margen por debajo del 25%.",
                'action' => 'Ajustar márgenes'
            ];
        }

        $ordersByStatus = is_array($ordersByStatus) ? $ordersByStatus : $this->orderRepo->getOrdersByStatus();
        $pendingOps = 0;
        foreach ($ordersByStatus as $row) {
            $status = strtolower((string)($row['status'] ?? ''));
            if (in_array($status, ['pending', 'processing', 'in_process', 'in-process'], true)) {
                $pendingOps += (int)($row['count'] ?? 0);
            }
        }
        if ($pendingOps > 0) {
            $alerts[] = [
                'type' => $pendingOps >= 10 ? 'warning' : 'info',
                'message' => "Hay {$pendingOps} pedidos pendientes/en proceso. Revisa operación y despacho.",
                'action' => 'Ver pedidos'
            ];
        }

        return $alerts;
    }
}
