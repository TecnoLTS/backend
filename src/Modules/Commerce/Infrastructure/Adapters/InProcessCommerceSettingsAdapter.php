<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Adapters;

use App\Modules\Commerce\Application\Ports\CommerceSettingsPort;
use App\Repositories\SettingsRepository;

final class InProcessCommerceSettingsAdapter implements CommerceSettingsPort
{
    public function __construct(private readonly SettingsRepository $settings = new SettingsRepository())
    {
    }

    public function get(string $key): mixed
    {
        return $this->settings->get($key);
    }
}
