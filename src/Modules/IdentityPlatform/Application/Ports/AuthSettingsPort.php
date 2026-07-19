<?php

namespace App\Modules\IdentityPlatform\Application\Ports;

interface AuthSettingsPort
{
    public function get($key);

    public function set($key, $value);

    public function sessionTtlSecondsForRole(?string $role): int;
}
