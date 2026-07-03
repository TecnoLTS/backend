<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\Commerce\Domain\CommerceDomain;
use App\Repositories\OrderRepository;
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
$db = Database::getModuleInstance(CommerceDomain::KEY);
$repo = new OrderRepository();

$report = [
    'tenant_id' => $tenantId,
    'started_at' => date('c'),
    'created_orders' => [],
    'failed_scenarios' => [],
    'validations' => [],
];

$selectUsers = $db->prepare('
    SELECT id, email, role
    FROM "Customer"
    WHERE tenant_id = :tenant_id AND role = :role
    ORDER BY email ASC
');
$selectUsers->execute([
    'tenant_id' => $tenantId,
    'role' => 'customer',
]);
$users = $selectUsers->fetchAll(PDO::FETCH_ASSOC);
if (count($users) < 3) {
    throw new RuntimeException('Se requieren al menos 3 usuarios customer para ejecutar escenarios.');
}

$catalogStmt = $db->prepare('
    SELECT id, name, quantity, sold, price, cost, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
      AND COALESCE(quantity, 0) > 0
      AND COALESCE(price, 0) > 0
    ORDER BY price DESC, quantity DESC, name ASC
');
$catalogStmt->execute([
    'tenant_id' => $tenantId,
]);
$catalog = $catalogStmt->fetchAll(PDO::FETCH_ASSOC);
if (count($catalog) < 10) {
    throw new RuntimeException('Se requieren al menos 10 productos con stock y precio para ejecutar escenarios.');
}

$catalogAsc = $catalog;
usort($catalogAsc, static fn (array $a, array $b): int => ((float)$a['price'] <=> (float)$b['price']) ?: strcmp((string)$a['id'], (string)$b['id']));

$reservedProductIds = [];
$reserveProduct = static function (array $product) use (&$reservedProductIds): array {
    $reservedProductIds[(string)$product['id']] = true;
    return $product;
};

$takeProduct = static function (array $source, callable $predicate) use (&$reservedProductIds, $reserveProduct): array {
    foreach ($source as $product) {
        $productId = (string)($product['id'] ?? '');
        if ($productId === '' || isset($reservedProductIds[$productId])) {
            continue;
        }
        if ($predicate($product)) {
            return $reserveProduct($product);
        }
    }

    throw new RuntimeException('No hay suficientes productos compatibles para construir escenarios de pedidos.');
};

$takeAnyProduct = static function (array $source) use ($takeProduct): array {
    return $takeProduct($source, static fn (array $product): bool => true);
};

$reuseProduct = static function (array $source, callable $predicate): array {
    foreach ($source as $product) {
        if ($predicate($product)) {
            return $product;
        }
    }

    throw new RuntimeException('No existe un producto compatible para escenario de validación negativa.');
};

$productAttributes = static function (array $product): array {
    $decoded = json_decode((string)($product['attributes'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
};

$productTaxRate = static function (array $product) use ($productAttributes): float {
    $attributes = $productAttributes($product);
    $rawValue = $attributes['taxRate'] ?? $attributes['tax_rate'] ?? 0;
    return is_numeric($rawValue) ? round((float)$rawValue, 2) : 0.0;
};

$productTaxExempt = static function (array $product) use ($productAttributes): bool {
    $attributes = $productAttributes($product);
    $rawValue = strtolower(trim((string)($attributes['taxExempt'] ?? $attributes['tax_exempt'] ?? 'false')));
    return in_array($rawValue, ['1', 'true', 't', 'yes', 'on'], true);
};

$premiumA = $takeAnyProduct($catalog);
$premiumB = $takeAnyProduct($catalog);
$premiumC = $takeAnyProduct($catalog);
$premiumD = $takeAnyProduct($catalog);
$premiumE = $takeAnyProduct($catalog);
$taxedA = $takeProduct($catalog, static fn (array $product): bool => $productTaxRate($product) > 0 && !$productTaxExempt($product));
$taxedB = $takeProduct($catalog, static fn (array $product): bool => $productTaxRate($product) > 0 && !$productTaxExempt($product));
$taxedC = $takeProduct($catalog, static fn (array $product): bool => $productTaxRate($product) > 0 && !$productTaxExempt($product));
$standardA = $takeProduct($catalog, static fn (array $product): bool => (float)$product['price'] >= 15);
$standardB = $takeProduct($catalog, static fn (array $product): bool => (float)$product['price'] >= 10);
$standardC = $takeProduct($catalog, static fn (array $product): bool => (float)$product['price'] >= 5);
$standardD = $takeProduct($catalog, static fn (array $product): bool => (float)$product['price'] >= 5);
$standardE = $takeProduct($catalog, static fn (array $product): bool => (float)$product['price'] >= 5);
$canceledProduct = $takeProduct($catalog, static fn (array $product): bool => (float)$product['price'] >= 5);
$cheapNegative = $reuseProduct($catalogAsc, static fn (array $product): bool => (float)$product['price'] < 30);
$canceledInitialState = [
    'id' => (string)$canceledProduct['id'],
    'name' => (string)$canceledProduct['name'],
    'quantity' => (int)$canceledProduct['quantity'],
    'sold' => (int)$canceledProduct['sold'],
];

$upsertDiscount = $db->prepare('
    INSERT INTO "DiscountCode" (
        id, tenant_id, code, name, description, type, value, min_subtotal, max_discount,
        max_uses, used_count, starts_at, ends_at, is_active, created_by, metadata, created_at, updated_at
    ) VALUES (
        :id, :tenant_id, :code, :name, :description, :type, :value, :min_subtotal, :max_discount,
        :max_uses, 0, :starts_at, :ends_at, :is_active, :created_by, :metadata, NOW(), NOW()
    )
    ON CONFLICT (tenant_id, code)
    DO UPDATE SET
        name = EXCLUDED.name,
        description = EXCLUDED.description,
        type = EXCLUDED.type,
        value = EXCLUDED.value,
        min_subtotal = EXCLUDED.min_subtotal,
        max_discount = EXCLUDED.max_discount,
        max_uses = EXCLUDED.max_uses,
        is_active = EXCLUDED.is_active,
        used_count = 0,
        metadata = EXCLUDED.metadata,
        updated_at = NOW()
');

$upsertDiscount->execute([
    'id' => 'dc_test10',
    'tenant_id' => $tenantId,
    'code' => 'TEST10',
    'name' => 'Descuento 10% pruebas',
    'description' => 'Escenario QA percent con tope.',
    'type' => 'percent',
    'value' => 10.00,
    'min_subtotal' => 20.00,
    'max_discount' => 40.00,
    'max_uses' => 1000,
    'starts_at' => null,
    'ends_at' => null,
    'is_active' => 1,
    'created_by' => 'script',
    'metadata' => json_encode(['source' => 'run_order_exercises']),
]);

$upsertDiscount->execute([
    'id' => 'dc_fix5',
    'tenant_id' => $tenantId,
    'code' => 'FIX5',
    'name' => 'Descuento fijo $5 pruebas',
    'description' => 'Escenario QA fixed.',
    'type' => 'fixed',
    'value' => 5.00,
    'min_subtotal' => 30.00,
    'max_discount' => null,
    'max_uses' => 1000,
    'starts_at' => null,
    'ends_at' => null,
    'is_active' => 1,
    'created_by' => 'script',
    'metadata' => json_encode(['source' => 'run_order_exercises']),
]);

$baseAddress = [
    'firstName' => 'QA',
    'lastName' => 'Tester',
    'company' => 'Paramascotas QA',
    'documentType' => 'RUC',
    'documentNumber' => '1799999999001',
    'country' => 'Ecuador',
    'street' => 'Av. Pruebas 123',
    'city' => 'Quito',
    'state' => 'Pichincha',
    'zip' => '170101',
    'phone' => '0999999999',
    'email' => 'qa@paramascotasec.com',
    'formattedAddress' => 'Av. Pruebas 123, Quito, Ecuador',
    'latitude' => -0.088306,
    'longitude' => -78.490870,
];

$runToken = gmdate('YmdHis');

$scenarios = [
    [
        'id' => "TST-{$runToken}-001",
        'user_idx' => 0,
        'status' => 'delivered',
        'delivery_method' => 'delivery',
        'payment_method' => 'credit',
        'coupon_code' => null,
        'created_at' => '2026-03-01 10:30:00',
        'items' => [
            ['product' => $premiumA, 'qty' => 1],
            ['product' => $standardA, 'qty' => 1],
        ],
    ],
    [
        'id' => "TST-{$runToken}-002",
        'user_idx' => 1,
        'status' => 'pending',
        'delivery_method' => 'pickup',
        'payment_method' => 'transfer',
        'coupon_code' => null,
        'created_at' => '2026-03-02 11:00:00',
        'items' => [
            ['product' => $taxedA, 'qty' => 1],
            ['product' => $taxedB, 'qty' => 1],
        ],
    ],
    [
        'id' => "TST-{$runToken}-003",
        'user_idx' => 2,
        'status' => 'completed',
        'delivery_method' => 'delivery',
        'payment_method' => 'cash',
        'coupon_code' => 'TEST10',
        'created_at' => '2026-02-15 09:15:00',
        'items' => [
            ['product' => $premiumB, 'qty' => 1],
            ['product' => $premiumC, 'qty' => 1],
        ],
    ],
    [
        'id' => "TST-{$runToken}-004",
        'user_idx' => 0,
        'status' => 'canceled',
        'delivery_method' => 'delivery',
        'payment_method' => 'credit',
        'coupon_code' => null,
        'created_at' => '2026-02-20 14:45:00',
        'items' => [
            ['product' => $canceledProduct, 'qty' => 1],
        ],
    ],
    [
        'id' => "TST-{$runToken}-005",
        'user_idx' => 1,
        'status' => 'processing',
        'delivery_method' => 'pickup',
        'payment_method' => 'credit',
        'coupon_code' => 'FIX5',
        'created_at' => '2026-01-28 08:20:00',
        'items' => [
            ['product' => $premiumD, 'qty' => 1],
            ['product' => $premiumE, 'qty' => 1],
        ],
    ],
    [
        'id' => "TST-{$runToken}-006",
        'user_idx' => 2,
        'status' => 'delivered',
        'delivery_method' => 'delivery',
        'payment_method' => 'transfer',
        'coupon_code' => null,
        'created_at' => '2026-03-02 12:40:00',
        'items' => [
            ['product' => $taxedC, 'qty' => 1],
            ['product' => $standardE, 'qty' => 1],
        ],
    ],
    [
        'id' => "TST-{$runToken}-007",
        'user_idx' => 0,
        'status' => 'pending',
        'delivery_method' => 'delivery',
        'payment_method' => 'cash',
        'coupon_code' => 'NOEXISTE',
        'expect_fail' => true,
        'expected_error_contains' => 'no registrado',
        'items' => [
            ['product' => $cheapNegative, 'qty' => 1],
        ],
    ],
    [
        'id' => "TST-{$runToken}-008",
        'user_idx' => 1,
        'status' => 'pending',
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
        'coupon_code' => 'FIX5',
        'expect_fail' => true,
        'expected_error_contains' => 'mínimo',
        'items' => [
            ['product' => $cheapNegative, 'qty' => 1],
        ],
    ],
];

$setCreatedAt = $db->prepare('
    UPDATE "Order"
    SET created_at = :created_at
    WHERE id = :id AND tenant_id = :tenant_id
');

foreach ($scenarios as $scenario) {
    $items = [];
    foreach ($scenario['items'] as $itemDef) {
        $product = $itemDef['product'];
        $items[] = [
            'product_id' => $product['id'],
            'quantity' => (int)$itemDef['qty'],
        ];
    }

    $user = $users[(int)$scenario['user_idx']];
    $address = $baseAddress;
    $address['email'] = $user['email'];
    $address['firstName'] = explode('@', $user['email'])[0];

    $payload = [
        'id' => $scenario['id'],
        'user_id' => $user['id'],
        'status' => $scenario['status'],
        'delivery_method' => $scenario['delivery_method'],
        'payment_method' => $scenario['payment_method'],
        'coupon_code' => $scenario['coupon_code'] ?? null,
        'shipping_address' => $address,
        'billing_address' => $address,
        'order_notes' => 'QA scenario ' . $scenario['id'],
        'items' => $items,
    ];

    try {
        $created = $repo->create($payload, 'https://paramascotasec.com');

        if (!empty($scenario['expect_fail'])) {
            $report['failed_scenarios'][] = [
                'scenario' => $scenario['id'],
                'status' => 'unexpected_success',
            ];
            continue;
        }

        if (!empty($scenario['created_at'])) {
            $setCreatedAt->execute([
                'created_at' => $scenario['created_at'],
                'id' => $scenario['id'],
                'tenant_id' => $tenantId,
            ]);
            $created = $repo->getById($scenario['id']);
        }

        $total = (float)($created['total'] ?? 0);
        $itemsSubtotal = (float)($created['items_subtotal'] ?? 0);
        $shipping = (float)($created['shipping'] ?? 0);
        $vatSubtotal = (float)($created['vat_subtotal'] ?? 0);
        $vatAmount = (float)($created['vat_amount'] ?? 0);
        $discountTotal = (float)($created['discount_total'] ?? 0);
        $calc1 = abs($total - ($itemsSubtotal + $shipping)) <= 0.02;
        $calc2 = abs($itemsSubtotal - ($vatSubtotal + $vatAmount)) <= 0.02;

        $report['created_orders'][] = [
            'id' => $scenario['id'],
            'user_id' => $user['id'],
            'email' => $user['email'],
            'status' => $created['status'] ?? $scenario['status'],
            'delivery_method' => $scenario['delivery_method'],
            'payment_method' => $scenario['payment_method'],
            'discount_code' => $created['discount_code'] ?? null,
            'discount_total' => round($discountTotal, 2),
            'items_subtotal' => round($itemsSubtotal, 2),
            'vat_subtotal' => round($vatSubtotal, 2),
            'vat_amount' => round($vatAmount, 2),
            'mixed_vat_rates' => !empty($created['mixed_vat_rates']),
            'shipping' => round($shipping, 2),
            'total' => round($total, 2),
            'created_at' => $scenario['created_at'] ?? ($created['created_at'] ?? null),
            'checks' => [
                'total_equals_items_plus_shipping' => $calc1,
                'items_equals_vat_subtotal_plus_vat_amount' => $calc2,
            ],
        ];
    } catch (Throwable $e) {
        if (!empty($scenario['expect_fail'])) {
            $expected = strtolower((string)($scenario['expected_error_contains'] ?? ''));
            $actual = strtolower($e->getMessage());
            $report['failed_scenarios'][] = [
                'scenario' => $scenario['id'],
                'status' => 'expected_failure',
                'message' => $e->getMessage(),
                'matches_expected' => $expected === '' ? true : (strpos($actual, $expected) !== false),
            ];
            continue;
        }
        throw $e;
    }
}

$summaryStmt = $db->prepare(<<<'SQL'
    SELECT
        COUNT(*) AS orders_total,
        COUNT(*) FILTER (WHERE LOWER(COALESCE(status, 'pending')) IN ('canceled', 'cancelled')) AS orders_canceled,
        COUNT(*) FILTER (WHERE LOWER(COALESCE(status, 'pending')) NOT IN ('canceled', 'cancelled')) AS orders_active,
        ROUND(COALESCE(SUM(total), 0)::numeric, 2) AS gross_total,
        ROUND(COALESCE(SUM(items_subtotal), 0)::numeric, 2) AS items_subtotal_total,
        ROUND(COALESCE(SUM(vat_amount), 0)::numeric, 2) AS vat_total,
        ROUND(COALESCE(SUM(shipping), 0)::numeric, 2) AS shipping_total,
        ROUND(COALESCE(SUM(discount_total), 0)::numeric, 2) AS discount_total
    FROM "Order"
    WHERE tenant_id = :tenant_id
SQL);
$summaryStmt->execute(['tenant_id' => $tenantId]);
$report['order_summary'] = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$checkCanceledProduct = $db->prepare('
    SELECT name, quantity, sold
    FROM "Product"
    WHERE tenant_id = :tenant_id AND name = :name
    LIMIT 1
');
$checkCanceledProduct->execute([
    'tenant_id' => $tenantId,
    'name' => $canceledProduct['name'],
]);
$canceledCurrentState = $checkCanceledProduct->fetch(PDO::FETCH_ASSOC) ?: [];
$report['validations']['canceled_order_inventory_check'] = [
    'product_id' => $canceledInitialState['id'],
    'name' => $canceledInitialState['name'],
    'initial_quantity' => $canceledInitialState['quantity'],
    'current_quantity' => isset($canceledCurrentState['quantity']) ? (int)$canceledCurrentState['quantity'] : null,
    'initial_sold' => $canceledInitialState['sold'],
    'current_sold' => isset($canceledCurrentState['sold']) ? (int)$canceledCurrentState['sold'] : null,
    'quantity_restored' => isset($canceledCurrentState['quantity']) ? ((int)$canceledCurrentState['quantity'] === $canceledInitialState['quantity']) : false,
    'sold_restored' => isset($canceledCurrentState['sold']) ? ((int)$canceledCurrentState['sold'] === $canceledInitialState['sold']) : false,
];

$discountStateStmt = $db->prepare(<<<'SQL'
    SELECT code, is_active, used_count
    FROM "DiscountCode"
    WHERE tenant_id = :tenant_id AND code IN ('TEST10', 'FIX5')
    ORDER BY code
SQL);
$discountStateStmt->execute(['tenant_id' => $tenantId]);
$report['validations']['discount_usage'] = $discountStateStmt->fetchAll(PDO::FETCH_ASSOC);

$report['finished_at'] = date('c');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
