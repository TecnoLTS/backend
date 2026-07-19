<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Application\Ports;

interface FinancialPeriodPort
{
    public function periodForDate(?string $date = null): array;

    public function getByPeriodKey(string $periodKey): ?array;

    public function listRecent(int $months = 14): array;

    public function listAdjustments(?string $periodKey = null, int $limit = 100): array;

    public function adjustmentSummary(?string $periodKey = null, bool $excludeClosedPeriods = false): array;

    public function createAdjustment(array $data, string $userId): array;

    public function closePeriod(string $periodKey, string $notes, string $userId): array;

    public function previewPeriod(string $periodKey): array;
}
