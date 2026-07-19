<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Ports;

interface CommerceExpensePort
{
    public function markPaid(string $expenseId, string $actorId): void;

    public function createPaidCashExpense(array $payload, string $actorId): array;
}
