<?php

namespace App\Modules\IdentityPlatform\Application\Ports;

interface AuthContactRequestPort
{
    public function create(array $payload): array;

    public function countRecentByEmail(string $email): int;

    public function countRecentByIp(?string $ipAddress): int;
}
