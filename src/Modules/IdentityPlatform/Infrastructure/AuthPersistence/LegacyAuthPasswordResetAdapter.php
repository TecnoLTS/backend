<?php

namespace App\Modules\IdentityPlatform\Infrastructure\AuthPersistence;

use App\Modules\IdentityPlatform\Application\Ports\AuthPasswordResetPort;
use App\Repositories\PasswordResetTokenRepository;

final class LegacyAuthPasswordResetAdapter implements AuthPasswordResetPort
{
    public function __construct(private readonly PasswordResetTokenRepository $repository)
    {
    }

    public function beginTransaction(): void { $this->repository->beginTransaction(); }

    public function commit(): void { $this->repository->commit(); }

    public function rollBack(): void { $this->repository->rollBack(); }

    public function create(
        string $userId,
        string $tokenHash,
        string $expiresAt,
        ?string $requestIp,
        ?string $requestUserAgent,
        string $purpose = 'password_reset',
        ?string $createdByUserId = null
    ): void {
        $this->repository->create(
            $userId,
            $tokenHash,
            $expiresAt,
            $requestIp,
            $requestUserAgent,
            $purpose,
            $createdByUserId
        );
    }

    public function consumeValidToken(string $tokenHash, ?string $usedIp, ?string $usedUserAgent): ?array
    {
        return $this->repository->consumeValidToken($tokenHash, $usedIp, $usedUserAgent);
    }

    public function getValidToken(string $tokenHash): ?array { return $this->repository->getValidToken($tokenHash); }

    public function deleteExpired(): void { $this->repository->deleteExpired(); }
}
