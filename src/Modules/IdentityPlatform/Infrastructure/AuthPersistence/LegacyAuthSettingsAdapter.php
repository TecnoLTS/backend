<?php

namespace App\Modules\IdentityPlatform\Infrastructure\AuthPersistence;

use App\Modules\IdentityPlatform\Application\Ports\AuthSettingsPort;
use App\Repositories\SettingsRepository;
use App\Services\SessionSettingsService;

final class LegacyAuthSettingsAdapter implements AuthSettingsPort
{
    private readonly SessionSettingsService $sessionSettings;

    public function __construct(private readonly SettingsRepository $repository)
    {
        $this->sessionSettings = new SessionSettingsService($repository);
    }

    public function get($key) { return $this->repository->get($key); }

    public function set($key, $value) { return $this->repository->set($key, $value); }

    public function sessionTtlSecondsForRole(?string $role): int
    {
        return $this->sessionSettings->ttlSecondsForRole($role);
    }
}
