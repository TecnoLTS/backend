<?php

declare(strict_types=1);

namespace BillingService\Shared\Application\Events;

use BillingService\Shared\Domain\Events\DomainEvent;

interface DomainEventDispatcher
{
    public function dispatch(DomainEvent $event, array $context = []): void;
}
