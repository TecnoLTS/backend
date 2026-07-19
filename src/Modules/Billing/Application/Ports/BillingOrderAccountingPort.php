<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Ports;

/**
 * Minimal Commerce projection consumed by Billing.
 */
interface BillingOrderAccountingPort
{
    public function accountingDates(array $orderIds): array;

    public function updateBillingMetadata(string $orderId, array $metadata): void;
}
