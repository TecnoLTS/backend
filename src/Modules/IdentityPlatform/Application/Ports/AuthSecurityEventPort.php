<?php

namespace App\Modules\IdentityPlatform\Application\Ports;

interface AuthSecurityEventPort
{
    public function recordEvent(
        string $eventType,
        string $status = 'info',
        ?string $userId = null,
        ?string $email = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $metadata = []
    ): void;

    public function countRecentEventsByEmail(
        string $eventType,
        string $email,
        string $interval = '1 hour'
    ): int;

    public function countRecentEventsByEmailForTypes(
        array $eventTypes,
        string $email,
        string $interval = '1 hour'
    ): int;

    public function countRecentEventsByIp(
        string $eventType,
        string $ipAddress,
        string $interval = '1 hour'
    ): int;

    public function countRecentEventsByIpForTypes(
        array $eventTypes,
        string $ipAddress,
        string $interval = '1 hour'
    ): int;
}
