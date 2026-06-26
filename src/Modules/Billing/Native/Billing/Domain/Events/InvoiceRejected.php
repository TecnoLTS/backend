<?php

declare(strict_types=1);

namespace BillingService\Billing\Domain\Events;

use BillingService\Shared\Domain\Events\DomainEvent;

class InvoiceRejected extends DomainEvent
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $reason
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'invoice.rejected';
    }

    public function toPrimitives(): array
    {
        return [
            'access_key' => $this->accessKey,
            'reason' => $this->reason,
            'event_id' => $this->eventId(),
            'occurred_on' => $this->occurredOn()->format('Y-m-d H:i:s'),
        ];
    }
}
