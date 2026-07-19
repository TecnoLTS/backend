<?php

declare(strict_types=1);

namespace App\Modules\ReportingFinance\Infrastructure\Adapters;

use App\Modules\ReportingFinance\Application\Ports\FinancialPeriodPort;
use App\Repositories\FinancialPeriodRepository;

final class InProcessFinancialPeriodAdapter implements FinancialPeriodPort
{
    public function __construct(private readonly FinancialPeriodRepository $repository = new FinancialPeriodRepository())
    {
    }

    public function periodForDate(?string $date = null): array
    {
        return $this->repository->periodForDate($date);
    }

    public function getByPeriodKey(string $periodKey): ?array
    {
        return $this->repository->getByPeriodKey($periodKey);
    }

    public function listRecent(int $months = 14): array
    {
        return $this->repository->listRecent($months);
    }

    public function listAdjustments(?string $periodKey = null, int $limit = 100): array
    {
        return $this->repository->listAdjustments($periodKey, $limit);
    }

    public function adjustmentSummary(?string $periodKey = null, bool $excludeClosedPeriods = false): array
    {
        return $this->repository->adjustmentSummary($periodKey, $excludeClosedPeriods);
    }

    public function createAdjustment(array $data, string $userId): array
    {
        return $this->repository->createAdjustment($data, $userId);
    }

    public function closePeriod(string $periodKey, string $notes, string $userId): array
    {
        return $this->repository->closePeriod($periodKey, $notes, $userId);
    }

    public function previewPeriod(string $periodKey): array
    {
        return $this->repository->previewPeriod($periodKey);
    }
}
