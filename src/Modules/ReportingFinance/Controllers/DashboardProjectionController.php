<?php

namespace App\Modules\ReportingFinance\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Services\BusinessIntelligenceService;

final class DashboardProjectionController {
    private BusinessIntelligenceService $intelligence;

    public function __construct() {
        $this->intelligence = new BusinessIntelligenceService();
    }

    public function productRanking(): void {
        Auth::requireAdmin();

        try {
            $query = $this->reportQuery();
            Response::json($this->intelligence->getProductRankingStats($query['period'], $query['date']));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_RANKING_REPORT_FAILED');
        }
    }

    public function operationalAlerts(): void {
        Auth::requireAdmin();

        try {
            Response::json($this->intelligence->getOperationalAlertStats());
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'OPERATIONAL_ALERTS_REPORT_FAILED');
        }
    }

    public function financialOverview(): void {
        Auth::requireAdmin();

        try {
            Response::json($this->intelligence->getFinancialOverviewStats());
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'FINANCIAL_OVERVIEW_REPORT_FAILED');
        }
    }

    private function reportQuery(): array {
        $period = isset($_GET['period'])
            ? (string)$_GET['period']
            : (isset($_GET['month']) ? (string)$_GET['month'] : null);
        $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) === 1
            ? (string)$_GET['date']
            : null;

        return [
            'period' => $period,
            'date' => $date,
        ];
    }
}
