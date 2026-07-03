<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\CatalogInventory\Domain\CatalogInventoryDomain;
use App\Repositories\InventoryLotRepository;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->load();
}

$tenantId = 'paramascotasec';
TenantContext::set([
    'id' => $tenantId,
    'name' => 'Para Mascotas EC',
]);

/** @var PDO $db */
$db = Database::getModuleInstance(CatalogInventoryDomain::KEY);
$lots = new InventoryLotRepository($db);

$runToken = gmdate('YmdHis');
$productId = 'prod_fifo_' . strtolower(bin2hex(random_bytes(5)));
$orderItemId = 'orditem_fifo_' . strtolower(bin2hex(random_bytes(5)));

$report = [
    'tenant_id' => $tenantId,
    'started_at' => date('c'),
    'product_id' => $productId,
    'order_item_id' => $orderItemId,
];

$fetchLots = static function () use ($db, $tenantId, $productId): array {
    $stmt = $db->prepare('
        SELECT id, source_type, source_ref, unit_cost, initial_quantity, remaining_quantity, received_at
        FROM "InventoryLot"
        WHERE tenant_id = :tenant_id
          AND product_id = :product_id
        ORDER BY received_at ASC NULLS LAST, created_at ASC NULLS LAST, id ASC
    ');
    $stmt->execute([
        'tenant_id' => $tenantId,
        'product_id' => $productId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$fetchAllocations = static function () use ($db, $tenantId, $orderItemId): array {
    $stmt = $db->prepare('
        SELECT lot_id, quantity, unit_cost
        FROM "InventoryLotAllocation"
        WHERE tenant_id = :tenant_id
          AND order_item_id = :order_item_id
        ORDER BY created_at ASC, id ASC
    ');
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_item_id' => $orderItemId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$insertProduct = $db->prepare('
    INSERT INTO "Product" (
        id, legacy_id, tenant_id, category, product_type, name, gender, is_new, is_sale, is_published,
        price, original_price, cost, brand, sold, quantity, description, action, slug, attributes, created_at, updated_at
    ) VALUES (
        :id, :legacy_id, :tenant_id, :category, :product_type, :name, :gender, true, false, false,
        :price, :original_price, :cost, :brand, 0, :quantity, :description, :action, :slug, CAST(:attributes AS jsonb), NOW(), NOW()
    )
');

$report['checks'] = [];

$startedTransaction = false;
try {
    if (!$db->inTransaction()) {
        $db->beginTransaction();
        $startedTransaction = true;
    }

    $insertProduct->execute([
        'id' => $productId,
        'legacy_id' => $productId . '_legacy',
        'tenant_id' => $tenantId,
        'category' => 'accesorios',
        'product_type' => 'accesorios',
        'name' => 'QA FIFO Product ' . $runToken,
        'gender' => 'dog',
        'price' => 25.0000,
        'original_price' => 25.0000,
        'cost' => 10.0000,
        'brand' => 'QA',
        'quantity' => 7,
        'description' => 'Producto temporal para validacion FIFO.',
        'action' => 'view',
        'slug' => 'qa-fifo-' . strtolower($runToken),
        'attributes' => json_encode([
            'sku' => 'QA-FIFO-' . $runToken,
            'taxRate' => '15.00',
            'taxExempt' => 'false',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $lots->recordStockIncrease(
        $productId,
        3,
        10.0000,
        'qa_fifo_seed',
        'qa-fifo-lot-1',
        ['label' => 'oldest']
    );
    usleep(200000);
    $lots->recordStockIncrease(
        $productId,
        4,
        20.0000,
        'qa_fifo_seed',
        'qa-fifo-lot-2',
        ['label' => 'newest']
    );

    $seedLots = $fetchLots();
    if (count($seedLots) !== 2) {
        throw new RuntimeException('Se esperaban exactamente 2 lotes QA para la prueba FIFO.');
    }

    $allocation = $lots->consumeForOrderItem(
        $productId,
        $orderItemId,
        5,
        7,
        10.0000,
        ['reason' => 'qa_fifo_check']
    );

    $allocationRows = $fetchAllocations();
    $consumedLots = $fetchLots();
    $restore = $lots->restoreForOrderItem($orderItemId);
    $restoredLots = $fetchLots();
    $remainingAllocations = $fetchAllocations();

    $report['checks']['seed_lot_order'] = [
        'passed' => ($seedLots[0]['source_ref'] ?? null) === 'qa-fifo-lot-1'
            && ($seedLots[1]['source_ref'] ?? null) === 'qa-fifo-lot-2',
        'lots' => array_map(static fn (array $lot): array => [
            'source_ref' => $lot['source_ref'] ?? null,
            'unit_cost' => (float)($lot['unit_cost'] ?? 0),
            'remaining_quantity' => (int)($lot['remaining_quantity'] ?? 0),
        ], $seedLots),
    ];

    $report['checks']['fifo_allocation'] = [
        'passed' => count($allocationRows) === 2
            && ($allocationRows[0]['lot_id'] ?? null) === ($seedLots[0]['id'] ?? null)
            && (int)($allocationRows[0]['quantity'] ?? 0) === 3
            && ($allocationRows[1]['lot_id'] ?? null) === ($seedLots[1]['id'] ?? null)
            && (int)($allocationRows[1]['quantity'] ?? 0) === 2,
        'allocation' => $allocation,
        'rows' => $allocationRows,
    ];

    $report['checks']['weighted_cost'] = [
        'passed' => abs((float)($allocation['unit_cost'] ?? 0) - 14.0) < 0.0001
            && abs((float)($allocation['cost_total'] ?? 0) - 70.0) < 0.0001,
        'unit_cost' => (float)($allocation['unit_cost'] ?? 0),
        'cost_total' => (float)($allocation['cost_total'] ?? 0),
    ];

    $report['checks']['remaining_after_consume'] = [
        'passed' => count($consumedLots) === 2
            && (int)($consumedLots[0]['remaining_quantity'] ?? -1) === 0
            && (int)($consumedLots[1]['remaining_quantity'] ?? -1) === 2,
        'lots' => array_map(static fn (array $lot): array => [
            'source_ref' => $lot['source_ref'] ?? null,
            'remaining_quantity' => (int)($lot['remaining_quantity'] ?? 0),
        ], $consumedLots),
    ];

    $report['checks']['restore'] = [
        'passed' => (int)($restore['restored_quantity'] ?? 0) === 5
            && (int)($restore['allocations_count'] ?? 0) === 2
            && count($remainingAllocations) === 0
            && count($restoredLots) === 2
            && (int)($restoredLots[0]['remaining_quantity'] ?? -1) === 3
            && (int)($restoredLots[1]['remaining_quantity'] ?? -1) === 4,
        'result' => $restore,
        'lots' => array_map(static fn (array $lot): array => [
            'source_ref' => $lot['source_ref'] ?? null,
            'remaining_quantity' => (int)($lot['remaining_quantity'] ?? 0),
        ], $restoredLots),
    ];
} finally {
    if ($startedTransaction && $db->inTransaction()) {
        $db->rollBack();
    }
}

$report['finished_at'] = date('c');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
