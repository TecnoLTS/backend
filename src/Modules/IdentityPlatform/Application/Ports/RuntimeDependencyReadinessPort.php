<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Application\Ports;

/**
 * Readiness contribution supplied by a runtime dependency.
 */
interface RuntimeDependencyReadinessPort
{
    /** @return array<string, scalar|null> */
    public function assertReady(): array;
}
