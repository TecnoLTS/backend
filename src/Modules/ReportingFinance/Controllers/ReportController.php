<?php

namespace App\Modules\ReportingFinance\Controllers;

use App\Core\Response;
use App\Core\Auth;
use App\Modules\ReportingFinance\Application\Ports\SalesReportingPort;
use App\Modules\ReportingFinance\Infrastructure\ReportingFinancePortsFactory;

class ReportController {
    private SalesReportingPort $reports;

    public function __construct(?SalesReportingPort $reports = null) {
        $this->reports = $reports ?? ReportingFinancePortsFactory::salesReporting();
    }

    public function recentOrders() {
        Auth::requireAdmin();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;
        if ($limit <= 0 || $limit > 50) {
            Response::error('Limit inválido', 400, 'REPORTS_LIMIT_INVALID');
            return;
        }

        try {
            $orders = $this->reports->recentOrders($limit);
            Response::json([
                'orders' => $orders,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'REPORTS_RECENT_FAILED');
        }
    }
}
