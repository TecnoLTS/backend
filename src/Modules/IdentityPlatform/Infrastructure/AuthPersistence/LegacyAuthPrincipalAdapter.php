<?php

namespace App\Modules\IdentityPlatform\Infrastructure\AuthPersistence;

use App\Modules\IdentityPlatform\Application\Ports\AuthPrincipalPort;
use App\Repositories\UserRepository;

/**
 * Transitional adapter around the current dashboard/customer repositories.
 * Cross-domain repository knowledge is intentionally confined to this layer.
 */
final class LegacyAuthPrincipalAdapter implements AuthPrincipalPort
{
    public function __construct(private readonly UserRepository $repository)
    {
    }

    public function getByEmail($email) { return $this->repository->getByEmail($email); }

    public function getByEmailWithOtp($email) { return $this->repository->getByEmailWithOtp($email); }

    public function getById($id) { return $this->repository->getById($id); }

    public function getByDocumentNumber(string $documentNumber) { return $this->repository->getByDocumentNumber($documentNumber); }

    public function create($data, $options = []) { return $this->repository->create($data, $options); }

    public function replaceRegistrationData(string $id, array $data, array $options = []) { return $this->repository->replaceRegistrationData($id, $data, $options); }

    public function deleteById(string $id): void { $this->repository->deleteById($id); }

    public function verifyToken($token) { return $this->repository->verifyToken($token); }

    public function getPasswordHash($userId) { return $this->repository->getPasswordHash($userId); }

    public function setLoginFailureState(string $userId, int $attempts, ?string $lockedUntil): void { $this->repository->setLoginFailureState($userId, $attempts, $lockedUntil); }

    public function clearLoginFailures(string $userId): void { $this->repository->clearLoginFailures($userId); }

    public function markSuccessfulLogin(string $userId): void { $this->repository->markSuccessfulLogin($userId); }

    public function setActiveTokenId($userId, $tokenId) { return $this->repository->setActiveTokenId($userId, $tokenId); }

    public function registerSession(
        string $userId,
        string $sessionId,
        int $expiresAt,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $this->repository->registerSession($userId, $sessionId, $expiresAt, $ipAddress, $userAgent);
    }

    public function refreshSessionExpiry(string $userId, string $sessionId, int $expiresAt): void { $this->repository->refreshSessionExpiry($userId, $sessionId, $expiresAt); }

    public function revokeSession(string $userId, string $sessionId): int { return $this->repository->revokeSession($userId, $sessionId); }

    public function setOtpForEmail($email, $code, $expiresAt) { return $this->repository->setOtpForEmail($email, $code, $expiresAt); }

    public function incrementOtpAttempts($userId) { return $this->repository->incrementOtpAttempts($userId); }

    public function markEmailVerifiedByOtp($userId) { return $this->repository->markEmailVerifiedByOtp($userId); }

    public function resetPasswordAfterRecovery(string $userId, string $newPasswordHash, string $newTokenId): void { $this->repository->resetPasswordAfterRecovery($userId, $newPasswordHash, $newTokenId); }

    public function markManagedEmailVerified(string $userId): void { $this->repository->markManagedEmailVerified($userId); }
}
