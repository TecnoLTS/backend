<?php

namespace App\Modules\IdentityPlatform\Application\Ports;

interface AuthPasswordResetPort
{
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function create(
        string $userId,
        string $tokenHash,
        string $expiresAt,
        ?string $requestIp,
        ?string $requestUserAgent,
        string $purpose = 'password_reset',
        ?string $createdByUserId = null
    ): void;

    public function consumeValidToken(string $tokenHash, ?string $usedIp, ?string $usedUserAgent): ?array;

    public function getValidToken(string $tokenHash): ?array;

    public function deleteExpired(): void;
}
