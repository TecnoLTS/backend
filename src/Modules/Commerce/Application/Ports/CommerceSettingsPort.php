<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Ports;

interface CommerceSettingsPort
{
    public function get(string $key): mixed;
}
