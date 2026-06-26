<?php

declare(strict_types=1);

namespace BillingService\Billing\Domain\Events;

use BillingService\Shared\Domain\Events\DomainEvent;

class InvoiceAuthorized extends DomainEvent
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $authorizationNumber,
        private readonly string $authorizationDate
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'invoice.authorized';
    }

    public function toPrimitives(): array
    {
        return [
            'access_key' => $this->accessKey,
            'authorization_number' => $this->authorizationNumber,
            'authorization_date' => $this->authorizationDate,
            'event_id' => $this->eventId(),
            'occurred_on' => $this->occurredOn()->format('Y-m-d H:i:s'),
        ];
    }
}
