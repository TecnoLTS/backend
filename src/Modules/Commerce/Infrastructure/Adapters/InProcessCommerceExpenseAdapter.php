<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Adapters;

use App\Modules\Commerce\Application\Ports\CommerceExpensePort;
use App\Repositories\BusinessExpenseRepository;

final class InProcessCommerceExpenseAdapter implements CommerceExpensePort
{
    public function __construct(private readonly BusinessExpenseRepository $expenses = new BusinessExpenseRepository())
    {
    }

    public function markPaid(string $expenseId, string $actorId): void
    {
        $this->expenses->updateStatus($expenseId, 'paid', [
            'payment_method' => 'cash',
            'reference' => 'POS',
        ], $actorId);
    }

    public function createPaidCashExpense(array $payload, string $actorId): array
    {
        return $this->expenses->create([
            ...$payload,
            'status' => 'paid',
            'payment_method' => 'cash',
            'reference' => 'POS',
            'source' => 'pos',
        ], $actorId);
    }
}
