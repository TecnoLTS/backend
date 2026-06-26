<?php

declare(strict_types=1);

namespace BillingService\Shared\Domain\Events;

use DateTimeImmutable;

abstract class DomainEvent
{
    private string $eventId;
    private DateTimeImmutable $occurredOn;

    public function __construct()
    {
        $this->eventId = $this->generateUuid();
        $this->occurredOn = new DateTimeImmutable();
    }

    abstract public function eventName(): string;

    abstract public function toPrimitives(): array;

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
