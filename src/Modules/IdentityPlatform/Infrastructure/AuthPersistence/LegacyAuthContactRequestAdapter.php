<?php

namespace App\Modules\IdentityPlatform\Infrastructure\AuthPersistence;

use App\Modules\IdentityPlatform\Application\Ports\AuthContactRequestPort;
use App\Repositories\ContactMessageRepository;

final class LegacyAuthContactRequestAdapter implements AuthContactRequestPort
{
    public function __construct(private readonly ContactMessageRepository $repository)
    {
    }

    public function create(array $payload): array { return $this->repository->create($payload); }

    public function countRecentByEmail(string $email): int { return $this->repository->countRecentByEmail($email); }

    public function countRecentByIp(?string $ipAddress): int { return $this->repository->countRecentByIp($ipAddress); }
}
