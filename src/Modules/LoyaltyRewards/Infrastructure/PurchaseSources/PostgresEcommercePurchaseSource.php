<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Infrastructure\PurchaseSources;

use App\Core\Database;
use App\Modules\Commerce\Domain\CommerceDomain;
use App\Modules\LoyaltyRewards\Application\Ports\EcommercePurchaseSource;
use App\Modules\LoyaltyRewards\Application\Ports\PurchaseSourceMatches;
use PDO;

final class PostgresEcommercePurchaseSource implements EcommercePurchaseSource
{
    /** @var (callable(): PDO)|null */
    private $connectionFactory;

    /** @param (callable(): PDO)|null $connectionFactory */
    public function __construct(?callable $connectionFactory = null)
    {
        $this->connectionFactory = $connectionFactory;
    }

    public function findMatches(string $tenantId, string $normalizedReference): PurchaseSourceMatches
    {
        $pdo = $this->connectionFactory !== null
            ? ($this->connectionFactory)()
            : Database::getModuleInstance(CommerceDomain::KEY);
        $statement = $pdo->prepare(
            "SELECT id, tenant_id AS source_tenant_id, customer_id, user_id, status, total, invoice_number, billing_address
             FROM \"Order\"
             WHERE tenant_id = :tenant_id
               AND UPPER(regexp_replace(BTRIM(COALESCE(invoice_number, id)), '\\s+', ' ', 'g')) = :reference
             ORDER BY created_at DESC
             LIMIT 2"
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'reference' => $normalizedReference,
        ]);

        return new PurchaseSourceMatches($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
