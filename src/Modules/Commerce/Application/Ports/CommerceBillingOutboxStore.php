<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Ports;

use App\Modules\Commerce\Application\CommerceBillingOutboxRetryPolicy;

interface CommerceBillingOutboxStore
{
    /** @return array<int,array<string,mixed>> */
    public function claimFairBatch(int $limit, int $perTenant, int $leaseSeconds, string $workerId): array;

    /** @return array<string,mixed>|null */
    public function loadOrderForClaim(array $outbox): ?array;

    public function recordPhase(
        array $outbox,
        string $phase,
        string $outcome,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        array $metadata = []
    ): void;

    public function markSucceeded(array $outbox, array $invoice, array $billingMetadata, ?int $httpStatus): void;

    public function markFailed(
        array $outbox,
        string $errorCode,
        string $errorMessage,
        bool $deliveryUnknown,
        ?int $httpStatus,
        CommerceBillingOutboxRetryPolicy $retryPolicy
    ): string;
}
