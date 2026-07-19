<?php

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\CatalogInventory\Application\PublicProductProjection;
use App\Core\TenantContext;
use App\Repositories\ProductRepository;

$projected = PublicProductProjection::one([
    'id' => 'product-1',
    'quantity' => 7,
    'cost' => 4.25,
    'business' => ['margin' => 37.5, 'profit' => 2.5],
    'lastPurchaseInvoice' => ['supplierDocument' => 'secret'],
    'thumbImage' => ['/uploads/products/product-1-thumb.webp'],
    'thumbs' => '["/uploads/products/product-1-thumb.webp"]',
    'attributes' => [
        'sku' => 'SKU-1',
        'species' => 'perro',
        'supplier' => 'Proveedor privado',
        'supplierDocument' => '0999999999001',
        'purchaseInvoiceNumber' => '001-001-1',
        'storageLocation' => 'Bodega A',
    ],
    'inventory' => [
        'onHand' => 7,
        'available' => 5,
        'status' => 'healthy',
        'valuation' => ['costTotal' => 29.75],
        'lot' => ['supplier' => 'Proveedor privado'],
    ],
]);

$failures = [];
foreach (['cost', 'business', 'lastPurchaseInvoice', 'thumbs'] as $privateKey) {
    if (array_key_exists($privateKey, $projected)) {
        $failures[] = "top-level private key leaked: {$privateKey}";
    }
}

foreach (['supplier', 'supplierDocument', 'purchaseInvoiceNumber', 'storageLocation'] as $privateKey) {
    if (array_key_exists($privateKey, $projected['attributes'] ?? [])) {
        $failures[] = "private attribute leaked: {$privateKey}";
    }
}

if (($projected['attributes']['sku'] ?? null) !== 'SKU-1') {
    $failures[] = 'public SKU was removed';
}
if (($projected['thumbImage'] ?? null) !== ['/uploads/products/product-1-thumb.webp']) {
    $failures[] = 'canonical public thumbnail field was removed';
}
if (($projected['inventory'] ?? null) !== ['onHand' => 7, 'available' => 5, 'status' => 'healthy']) {
    $failures[] = 'public inventory projection is not minimal';
}

// Construct without the repository constructor to prove the public default is
// resolved entirely from TenantContext and does not open IdentityPlatform DB.
TenantContext::set([
    'id' => 'tenant-public-tax-test',
    'ecommerce_configuration' => ['defaultTaxRate' => 15],
]);
$repositoryReflection = new ReflectionClass(ProductRepository::class);
$repository = $repositoryReflection->newInstanceWithoutConstructor();
$taxRateMethod = $repositoryReflection->getMethod('getProductTaxRateForAttributes');
$runtimeDefault = $taxRateMethod->invoke($repository, [], true);
$explicitRate = $taxRateMethod->invoke($repository, ['taxRate' => '12'], true);
$exemptRate = $taxRateMethod->invoke($repository, ['taxExempt' => 'true'], true);
TenantContext::clear();
if ($runtimeDefault !== 15.0 || $explicitRate !== 12.0 || $exemptRate !== 0.0) {
    $failures[] = 'public tax projection does not use the tenant runtime snapshot safely';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Public product projection: OK\n";
