<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Infrastructure\PurchaseSources;

use App\Core\Database;
use App\Modules\Billing\Domain\BillingDomain;
use App\Modules\LoyaltyRewards\Application\Ports\BillingPurchaseSource;
use App\Modules\LoyaltyRewards\Application\Ports\PurchaseSourceMatches;
use PDO;

final class PostgresBillingPurchaseSource implements BillingPurchaseSource
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
            : Database::getModuleInstance(BillingDomain::KEY);
        $statement = $pdo->prepare(
            "SELECT ih.id, ih.tenant_id AS source_tenant_id,
                    bc.tenant_id AS billing_customer_tenant_id,
                    ih.source_reference, ih.access_key, ih.authorization_number,
                    ih.total_with_tax, ih.sri_status, ih.authorized_xml_received,
                    bc.identification, bc.email, bc.id AS billing_customer_id
             FROM invoice_headers ih
             JOIN billing_customers bc
               ON bc.id = ih.billing_customer_id
              AND bc.tenant_id = :billing_customer_tenant_id
             WHERE ih.tenant_id = :invoice_tenant_id
               AND (
                    UPPER(regexp_replace(BTRIM(COALESCE(ih.source_reference, ih.access_key, ih.authorization_number)), '\\s+', ' ', 'g')) = :reference
                 OR UPPER(regexp_replace(BTRIM(COALESCE(ih.access_key, '')), '\\s+', ' ', 'g')) = :reference_access
                 OR UPPER(regexp_replace(BTRIM(COALESCE(ih.authorization_number, '')), '\\s+', ' ', 'g')) = :reference_authorization
               )
             ORDER BY ih.created_at DESC
             LIMIT 2"
        );
        $statement->execute([
            'billing_customer_tenant_id' => $tenantId,
            'invoice_tenant_id' => $tenantId,
            'reference' => $normalizedReference,
            'reference_access' => $normalizedReference,
            'reference_authorization' => $normalizedReference,
        ]);

        return new PurchaseSourceMatches($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
