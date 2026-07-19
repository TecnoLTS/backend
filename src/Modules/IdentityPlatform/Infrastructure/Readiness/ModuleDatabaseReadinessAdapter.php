<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Infrastructure\Readiness;

use App\Core\Database;
use App\Modules\Billing\Domain\BillingDomain;
use App\Modules\Commerce\Domain\CommerceDomain;
use App\Modules\IdentityPlatform\Application\Ports\RuntimeDependencyReadinessPort;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;

final class ModuleDatabaseReadinessAdapter implements RuntimeDependencyReadinessPort
{
    /** @var array<string, string> */
    private const DATABASES = [
        'dashboard' => IdentityPlatformDomain::KEY,
        'ecommerce' => CommerceDomain::KEY,
        'facturacion' => BillingDomain::KEY,
        'loyalty' => LoyaltyRewardsDomain::KEY,
    ];

    public function assertReady(): array
    {
        $confirmed = [];
        foreach (self::DATABASES as $expectedDatabase => $moduleKey) {
            $connection = Database::getModuleInstance($moduleKey);
            $statement = $connection->query('SELECT current_database()');
            $actualDatabase = trim((string)$statement->fetchColumn());
            if (!hash_equals($expectedDatabase, $actualDatabase)) {
                throw new \RuntimeException('La conexion de modulo no apunta a su base owner.');
            }
            $confirmed[] = $expectedDatabase;
        }

        return [
            'bases_logicas' => count($confirmed),
            'bases_logicas_estado' => 'conectadas',
        ];
    }
}
