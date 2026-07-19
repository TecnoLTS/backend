<?php

namespace App\Modules\IdentityPlatform\Application\Ports;

/**
 * Persistence contract used by the authentication use cases.
 *
 * Return types intentionally remain compatible with the legacy repositories
 * (array|false|null in several reads). Tightening those contracts belongs to a
 * separate repository migration and must not alter the current HTTP behavior.
 */
interface AuthPrincipalPort
{
    public function getByEmail($email);

    public function getByEmailWithOtp($email);

    public function getById($id);

    public function getByDocumentNumber(string $documentNumber);

    public function create($data, $options = []);

    public function replaceRegistrationData(string $id, array $data, array $options = []);

    public function deleteById(string $id): void;

    public function verifyToken($token);

    public function getPasswordHash($userId);

    public function setLoginFailureState(string $userId, int $attempts, ?string $lockedUntil): void;

    public function clearLoginFailures(string $userId): void;

    public function markSuccessfulLogin(string $userId): void;

    public function setActiveTokenId($userId, $tokenId);

    public function registerSession(
        string $userId,
        string $sessionId,
        int $expiresAt,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void;

    public function refreshSessionExpiry(string $userId, string $sessionId, int $expiresAt): void;

    public function revokeSession(string $userId, string $sessionId): int;

    public function setOtpForEmail($email, $code, $expiresAt);

    public function incrementOtpAttempts($userId);

    public function markEmailVerifiedByOtp($userId);

    public function resetPasswordAfterRecovery(string $userId, string $newPasswordHash, string $newTokenId): void;

    public function markManagedEmailVerified(string $userId): void;
}
