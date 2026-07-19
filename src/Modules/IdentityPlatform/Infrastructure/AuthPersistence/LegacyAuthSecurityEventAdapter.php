<?php

namespace App\Modules\IdentityPlatform\Infrastructure\AuthPersistence;

use App\Modules\IdentityPlatform\Application\Ports\AuthSecurityEventPort;
use App\Repositories\AuthSecurityRepository;

final class LegacyAuthSecurityEventAdapter implements AuthSecurityEventPort
{
    public function __construct(private readonly AuthSecurityRepository $repository)
    {
    }

    public function recordEvent(
        string $eventType,
        string $status = 'info',
        ?string $userId = null,
        ?string $email = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $metadata = []
    ): void {
        $this->repository->recordEvent($eventType, $status, $userId, $email, $ipAddress, $userAgent, $metadata);
    }

    public function countRecentEventsByEmail(string $eventType, string $email, string $interval = '1 hour'): int
    {
        return $this->repository->countRecentEventsByEmail($eventType, $email, $interval);
    }

    public function countRecentEventsByEmailForTypes(array $eventTypes, string $email, string $interval = '1 hour'): int
    {
        return $this->repository->countRecentEventsByEmailForTypes($eventTypes, $email, $interval);
    }

    public function countRecentEventsByIp(string $eventType, string $ipAddress, string $interval = '1 hour'): int
    {
        return $this->repository->countRecentEventsByIp($eventType, $ipAddress, $interval);
    }

    public function countRecentEventsByIpForTypes(array $eventTypes, string $ipAddress, string $interval = '1 hour'): int
    {
        return $this->repository->countRecentEventsByIpForTypes($eventTypes, $ipAddress, $interval);
    }
}
