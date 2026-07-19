<?php

namespace App\Modules\ReportingFinance\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Exceptions\FinancialPeriodClosedException;
use App\Modules\ReportingFinance\Application\Ports\FinancialPeriodPort;
use App\Modules\ReportingFinance\Infrastructure\ReportingFinancePortsFactory;

class FinancialPeriodController {
    private FinancialPeriodPort $periods;

    public function __construct(?FinancialPeriodPort $periods = null) {
        $this->periods = $periods ?? ReportingFinancePortsFactory::financialPeriods();
    }

    private function adminUser(): array {
        return Auth::requireAdmin();
    }

    private function currentUserId(array $user): string {
        return (string)($user['sub'] ?? 'service');
    }

    private function input(): array {
        $decoded = json_decode(file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function index(): void {
        $this->adminUser();
        try {
            $currentKey = $this->periods->periodForDate()['period_key'];
            $current = $this->periods->getByPeriodKey($currentKey);
            Response::json([
                'current_period' => $current,
                'periods' => $this->periods->listRecent(24),
                'adjustments' => $this->periods->listAdjustments(null, 100),
                'adjustment_summary' => $this->periods->adjustmentSummary(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'FINANCIAL_PERIODS_LIST_FAILED');
        }
    }

    public function close($period): void {
        $user = $this->adminUser();
        $input = $this->input();
        try {
            $closed = $this->periods->closePeriod(
                (string)$period,
                trim((string)($input['notes'] ?? '')),
                $this->currentUserId($user)
            );
            Response::json([
                'period' => $closed,
                'periods' => $this->periods->listRecent(24),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400, 'FINANCIAL_PERIOD_CLOSE_FAILED');
        }
    }

    public function preview($period): void {
        $this->adminUser();
        try {
            Response::json($this->periods->previewPeriod((string)$period));
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400, 'FINANCIAL_PERIOD_PREVIEW_FAILED');
        }
    }

    public function storeAdjustment(): void {
        $user = $this->adminUser();
        try {
            $adjustment = $this->periods->createAdjustment($this->input(), $this->currentUserId($user));
            Response::json([
                'adjustment' => $adjustment,
                'adjustments' => $this->periods->listAdjustments(null, 100),
                'adjustment_summary' => $this->periods->adjustmentSummary(),
            ], 201);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400, 'FINANCIAL_ADJUSTMENT_CREATE_FAILED');
        }
    }
}
