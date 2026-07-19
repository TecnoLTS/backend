<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\Commerce\Application\OrderListSummary;
use App\Modules\Commerce\Controllers\OrderController;
use App\Repositories\OrderRepository;

/** @return string */
$methodSource = static function (string $className, string $methodName): string {
    $method = new ReflectionMethod($className, $methodName);
    $lines = file((string)$method->getFileName());
    if (!is_array($lines)) {
        return '';
    }
    return implode('', array_slice(
        $lines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));
};

$row = [
    'id' => 'ord-summary-1',
    'user_id' => 'customer-1',
    'total' => '123.45',
    'status' => 'processing',
    'created_at' => '2026-07-15 10:30:00',
    'delivery_method' => 'delivery',
    'payment_method' => 'transfer',
    'user_name' => 'Cliente QA',
    'user_email' => 'cliente@example.test',
    'customer_name' => 'Cliente snapshot',
    'customer_email' => 'snapshot@example.test',
    'customer_phone' => '+593999999999',
    'customer_document_type' => 'cedula',
    'customer_document_number' => '1712345678',
    'customer_company' => 'Compania QA',
    'sales_channel' => 'local_pos',
    'items_count' => '3',
    'units_count' => '7',
    'mixed_vat_rates' => 't',
    'items_subtotal' => '110.00',
    'vat_subtotal' => '100.00',
    'vat_rate' => '15.00',
    'vat_amount' => '15.00',
    'shipping' => '3.45',
    'shipping_base' => '3.00',
    'shipping_tax_rate' => '15.00',
    'shipping_tax_amount' => '0.45',
    'discount_code' => 'QA10',
    'discount_total' => '5.00',
    'invoice_number' => '001-002-000000123',
    'invoice_created_at' => '2026-07-15 10:35:00',
    // Una fila contaminada no debe poder filtrar estos campos al DTO.
    'order_notes' => str_repeat('n', 10000),
    'shipping_address' => ['address' => str_repeat('a', 10000)],
    'billing_address' => ['address' => str_repeat('b', 10000)],
    'payment_details' => ['payload' => str_repeat('p', 10000)],
    'invoice_data' => ['xml' => str_repeat('x', 10000)],
    'items' => [['product_name' => str_repeat('i', 10000)]],
];
$summary = OrderListSummary::fromDatabaseRow($row);

$forbiddenFields = [
    'order_notes',
    'shipping_address',
    'billing_address',
    'payment_details',
    'invoice_data',
    'invoice_html',
    'discount_snapshot',
    'items',
];
$summaryQuery = $methodSource(OrderRepository::class, 'getOrderSummaryPage');
$adminWrapper = $methodSource(OrderRepository::class, 'getPageResult');
$customerWrapper = $methodSource(OrderRepository::class, 'getByUserIdPage');
$detailQuery = $methodSource(OrderRepository::class, 'getById');
$createSource = $methodSource(OrderRepository::class, 'create');
$controllerList = $methodSource(OrderController::class, 'respondWithOrderPage');
$bootstrapSource = file_get_contents(dirname(__DIR__, 4) . '/scripts/bootstrap_schema.php');
$bootstrapSource = is_string($bootstrapSource) ? $bootstrapSource : '';

$checks = [
    'projection has exact field allowlist' => array_keys($summary) === OrderListSummary::FIELDS,
    'projection coerces monetary total to number' => $summary['total'] === 123.45,
    'projection exposes aggregate line and unit counts' => $summary['items_count'] === 3
        && $summary['units_count'] === 7,
    'projection preserves bounded operational scalars' => $summary['customer_phone'] === '+593999999999'
        && $summary['sales_channel'] === 'local_pos'
        && $summary['shipping'] === 3.45
        && $summary['shipping_tax_amount'] === 0.45
        && $summary['vat_amount'] === 15.0
        && $summary['discount_total'] === 5.0
        && $summary['invoice_number'] === '001-002-000000123'
        && $summary['mixed_vat_rates'] === true,
    'admin list delegates to summary query' => str_contains($adminWrapper, 'getOrderSummaryPage(null'),
    'customer list delegates to summary query' => str_contains($customerWrapper, 'getOrderSummaryPage((string)$userId'),
    'summary query uses keyset and bounded overfetch' => str_contains($summaryQuery, '(o.created_at, o.id) <')
        && str_contains($summaryQuery, '$safeLimit + 1'),
    'summary query aggregates instead of loading lines' => str_contains($summaryQuery, 'COUNT(*)::bigint AS items_count')
        && str_contains($summaryQuery, 'SUM(GREATEST(oi.quantity, 0))')
        && !preg_match('/SELECT\s+oi\.\*/i', $summaryQuery),
    'summary aggregate has tenant-order index' => str_contains($bootstrapSource, "'child_parent_index' => 'OrderItem_tenant_order_idx'"),
    'contact snapshot migration is versioned' => str_contains($bootstrapSource, 'customer_snapshot_version smallint NOT NULL DEFAULT 0')
        && str_contains($bootstrapSource, 'SET customer_snapshot_version = 1 WHERE customer_snapshot_version < 1'),
    'new orders persist versioned scalar snapshots' => str_contains($createSource, '$this->resolveCustomerSnapshot($data)')
        && str_contains($createSource, '"customer_snapshot_version"')
        && str_contains($createSource, ':customer_phone')
        && str_contains($createSource, ':sales_channel'),
    'summary query never selects the complete order' => !preg_match('/SELECT\s+o\.\*/i', $summaryQuery),
    'detail query retains the complete aggregate' => preg_match('/SELECT\s+o\.\*/i', $detailQuery) === 1
        && preg_match('/SELECT\s+oi\.\*/i', $detailQuery) === 1,
    'controller verifies the encoded response budget' => str_contains($controllerList, 'assertResponseBudget')
        && str_contains($controllerList, 'X-Orders-Response-Bytes')
        && str_contains($controllerList, 'X-Orders-Max-Response-Bytes'),
];

$repositoryWithoutDatabase = (new ReflectionClass(OrderRepository::class))->newInstanceWithoutConstructor();
$snapshotMethod = new ReflectionMethod(OrderRepository::class, 'resolveCustomerSnapshot');
$snapshotMethod->setAccessible(true);
$snapshot = $snapshotMethod->invoke($repositoryWithoutDatabase, [
    'shipping_address' => [
        'firstName' => 'Cliente',
        'lastName' => 'Snapshot',
        'email' => 'snapshot@example.test',
        'phone' => '+593999999999',
    ],
    'billing_address' => [
        'documentType' => 'RUC',
        'documentNumber' => '1790012345001',
        'company' => 'Compania Snapshot',
    ],
    'payment_details' => ['channel' => 'local_pos'],
]);
$checks['snapshot resolver extracts only bounded scalars'] = $snapshot === [
    'customer_name' => 'Cliente Snapshot',
    'customer_email' => 'snapshot@example.test',
    'customer_phone' => '+593999999999',
    'customer_document_type' => 'RUC',
    'customer_document_number' => '1790012345001',
    'customer_company' => 'Compania Snapshot',
    'sales_channel' => 'local_pos',
];

foreach ($forbiddenFields as $field) {
    $checks["projection omits {$field}"] = !array_key_exists($field, $summary);
    if ($field !== 'items') {
        $checks["summary SQL omits {$field}"] = !preg_match('/\b' . preg_quote($field, '/') . '\b/i', $summaryQuery);
    }
}

$maxRow = [
    'id' => str_repeat('i', 128),
    'user_id' => str_repeat('u', 128),
    'total' => '99999999.99',
    'status' => str_repeat('s', 32),
    'created_at' => str_repeat('t', 40),
    'delivery_method' => str_repeat('d', 32),
    'payment_method' => str_repeat('p', 64),
    'user_name' => str_repeat('n', 320),
    'user_email' => str_repeat('e', 254),
    'customer_name' => str_repeat('c', 320),
    'customer_email' => str_repeat('m', 254),
    'customer_phone' => str_repeat('p', 40),
    'customer_document_type' => str_repeat('d', 32),
    'customer_document_number' => str_repeat('x', 64),
    'customer_company' => str_repeat('b', 320),
    'sales_channel' => str_repeat('s', 32),
    'items_count' => PHP_INT_MAX,
    'units_count' => PHP_INT_MAX,
    'mixed_vat_rates' => true,
    'items_subtotal' => '99999999.99',
    'vat_subtotal' => '99999999.99',
    'vat_rate' => '9999.99',
    'vat_amount' => '99999999.99',
    'shipping' => '99999999.99',
    'shipping_base' => '99999999.99',
    'shipping_tax_rate' => '9999.99',
    'shipping_tax_amount' => '99999999.99',
    'discount_code' => str_repeat('q', 80),
    'discount_total' => '99999999.99',
    'invoice_number' => str_repeat('v', 80),
    'invoice_created_at' => str_repeat('z', 40),
];
$maxPage = OrderListSummary::projectRows(array_fill(0, OrderListSummary::MAX_PAGE_SIZE, $maxRow));
$maxMeta = [
    'pagination' => ['pageSize' => 100, 'hasMore' => true, 'nextCursor' => str_repeat('c', 512)],
    'payload' => [
        'projection' => OrderListSummary::CONTRACT,
        'maxResponseBytes' => OrderListSummary::MAX_RESPONSE_BYTES,
    ],
];
$maxPageBytes = OrderListSummary::assertResponseBudget($maxPage, $maxMeta);
$checks['maximum valid page fits byte budget'] = $maxPageBytes <= OrderListSummary::MAX_RESPONSE_BYTES;

try {
    OrderListSummary::projectRows(array_fill(0, OrderListSummary::MAX_PAGE_SIZE + 1, $row));
    $checks['page row overflow fails closed'] = false;
} catch (OverflowException) {
    $checks['page row overflow fails closed'] = true;
}

try {
    $oversized = $summary;
    $oversized['unexpected'] = str_repeat('x', OrderListSummary::MAX_RESPONSE_BYTES + 1);
    OrderListSummary::assertResponseBudget([$oversized], []);
    $checks['encoded byte overflow fails closed'] = false;
} catch (OverflowException) {
    $checks['encoded byte overflow fails closed'] = true;
}

try {
    $oversizedId = $row;
    $oversizedId['id'] = str_repeat('x', 129);
    OrderListSummary::fromDatabaseRow($oversizedId);
    $checks['identifier overflow fails closed'] = false;
} catch (OverflowException) {
    $checks['identifier overflow fails closed'] = true;
}

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Order list summary contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo sprintf(
    "Order list summary: OK (%d fields, %d rows, %d/%d bytes)\n",
    count(OrderListSummary::FIELDS),
    count($maxPage),
    $maxPageBytes,
    OrderListSummary::MAX_RESPONSE_BYTES
);
