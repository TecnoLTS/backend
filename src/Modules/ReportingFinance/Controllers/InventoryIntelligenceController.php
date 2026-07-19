<?php

namespace App\Modules\ReportingFinance\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Modules\ReportingFinance\Application\Ports\InventoryAnalyticsPort;
use App\Modules\ReportingFinance\Infrastructure\ReportingFinancePortsFactory;

class InventoryIntelligenceController {
    private InventoryAnalyticsPort $analytics;

    public function __construct(?InventoryAnalyticsPort $analytics = null) {
        $this->analytics = $analytics ?? ReportingFinancePortsFactory::inventoryAnalytics();
    }

    public function intelligence(): void {
        Auth::requireAdmin();

        try {
            $windowDays = isset($_GET['window_days']) && is_numeric($_GET['window_days'])
                ? (int)$_GET['window_days']
                : 30;
            $targetDays = isset($_GET['target_days']) && is_numeric($_GET['target_days'])
                ? (int)$_GET['target_days']
                : 30;

            Response::json($this->analytics->intelligence($windowDays, $targetDays));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'INVENTORY_INTELLIGENCE_FAILED');
        }
    }
}
