<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure\Adapters;

use App\Modules\Billing\Application\Ports\BillingOrderAccountingPort;
use App\Repositories\OrderRepository;

final class InProcessBillingOrderAccountingAdapter implements BillingOrderAccountingPort
{
    public function __construct(private readonly OrderRepository $repository = new OrderRepository())
    {
    }

    public function accountingDates(array $orderIds): array
    {
        return $this->repository->getAccountingDatesByOrderIds($orderIds);
    }

    public function updateBillingMetadata(string $orderId, array $metadata): void
    {
        $this->repository->updateBillingMetadata($orderId, $metadata);
    }
}
