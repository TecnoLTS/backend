<?php

declare(strict_types=1);

namespace BillingService\Billing\Domain\Events;

use BillingService\Shared\Domain\Events\DomainEvent;

class InvoiceEmitted extends DomainEvent
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $issuerRuc,
        private readonly string $customerIdentification,
        private readonly float $total
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'invoice.emitted';
    }

    public function toPrimitives(): array
    {
        return [
            'access_key' => $this->accessKey,
            'issuer_ruc' => $this->issuerRuc,
            'customer_identification' => $this->customerIdentification,
            'total' => $this->total,
            'event_id' => $this->eventId(),
            'occurred_on' => $this->occurredOn()->format('Y-m-d H:i:s'),
        ];
    }
}
