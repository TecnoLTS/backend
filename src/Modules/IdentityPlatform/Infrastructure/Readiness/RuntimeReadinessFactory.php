<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Infrastructure\Readiness;

use App\Modules\IdentityPlatform\Application\Ports\RuntimeDependencyReadinessPort;

final class RuntimeReadinessFactory
{
    /** @return list<RuntimeDependencyReadinessPort> */
    public static function dependencies(): array
    {
        return [
            new ModuleDatabaseReadinessAdapter(),
            new StorageReadinessAdapter(),
            new BillingSecretReadinessAdapter(),
        ];
    }
}
