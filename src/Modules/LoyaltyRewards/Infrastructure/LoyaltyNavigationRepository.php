<?php

namespace App\Modules\LoyaltyRewards\Infrastructure;

use App\Core\Database;
use App\Modules\LoyaltyRewards\Domain\LoyaltyNavigationCatalog;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use PDO;

final class LoyaltyNavigationRepository {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeItems(string $tenantId): array {
        $statement = $this->pdo->prepare(
            'SELECT item_key, parent_item_key, item_kind, label, icon, route_key,
                    sort_order, depth, mandatory
             FROM loyalty_navigation_items
             WHERE tenant_id = :tenant_id
               AND status = \'active\'
               AND catalog_version = :catalog_version
             ORDER BY depth ASC, sort_order ASC, item_key ASC'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'catalog_version' => LoyaltyNavigationCatalog::VERSION,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeActions(string $tenantId): array {
        $statement = $this->pdo->prepare(
            'SELECT item_key, action_key, permission_key, label, dangerous, sort_order
             FROM loyalty_navigation_item_actions
             WHERE tenant_id = :tenant_id
               AND status = \'active\'
               AND catalog_version = :catalog_version
             ORDER BY item_key ASC, sort_order ASC, action_key ASC'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'catalog_version' => LoyaltyNavigationCatalog::VERSION,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
