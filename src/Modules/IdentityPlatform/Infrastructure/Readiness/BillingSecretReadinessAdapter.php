<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Infrastructure\Readiness;

use App\Core\Database;
use App\Modules\Billing\Domain\BillingDomain;
use App\Modules\IdentityPlatform\Application\Ports\RuntimeDependencyReadinessPort;
use BillingService\Billing\Infrastructure\Persistence\BillingSecretStorageAttestor;
use BillingService\Billing\Infrastructure\Security\BillingSecretCipherFactory;

final class BillingSecretReadinessAdapter implements RuntimeDependencyReadinessPort
{
    public function assertReady(): array
    {
        $cipher = BillingSecretCipherFactory::fromEnvironment();
        if (!$cipher->legacyPlaintextReadEnabled()) {
            (new BillingSecretStorageAttestor(
                Database::getModuleInstance(BillingDomain::KEY),
                $cipher
            ))->requireValidatedSchema();
        }

        return ['secretos_facturacion' => $cipher->storagePhase()];
    }
}
