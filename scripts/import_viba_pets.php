<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Repositories\InventoryLotRepository;
use App\Repositories\PurchaseInvoiceRepository;
use App\Support\ProductAudience;
use App\Support\ProductVariantMetadata;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->load();
}

$options = getopt('', ['tenant::', 'file::', 'dry-run']);
$tenantId = trim((string)($options['tenant'] ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec')));
$defaultFile = __DIR__ . '/../storage/imports/viba_pets_accessories_2026_04_11.json';
$filePath = trim((string)($options['file'] ?? $defaultFile));
$dryRun = array_key_exists('dry-run', $options);

$tenants = require __DIR__ . '/../config/tenants.php';
if (!isset($tenants[$tenantId])) {
    fwrite(STDERR, "Tenant no configurado: {$tenantId}\n");
    exit(1);
}
if (!is_file($filePath)) {
    fwrite(STDERR, "Archivo de dataset no encontrado: {$filePath}\n");
    exit(1);
}

$raw = file_get_contents($filePath);
$dataset = json_decode((string)$raw, true);
if (!is_array($dataset)) {
    fwrite(STDERR, "Dataset invalido: {$filePath}\n");
    exit(1);
}

$products = $dataset['products'] ?? null;
$supplier = $dataset['supplier'] ?? null;
if (!is_array($products) || !is_array($supplier)) {
    fwrite(STDERR, "El dataset debe incluir supplier y products.\n");
    exit(1);
}

$brand = trim((string)($dataset['brand'] ?? 'ParaMascotas'));
$category = trim((string)($dataset['category'] ?? 'accesorios'));
$productType = trim((string)($dataset['productType'] ?? 'accesorios'));
$saleTaxRate = isset($dataset['saleTaxRate']) && is_numeric($dataset['saleTaxRate'])
    ? round((float)$dataset['saleTaxRate'], 2)
    : 15.0;
$purchaseTaxRate = isset($dataset['purchaseTaxRate']) && is_numeric($dataset['purchaseTaxRate'])
    ? round((float)$dataset['purchaseTaxRate'], 2)
    : 15.0;
$storageLocation = trim((string)($dataset['storageLocation'] ?? 'Principal'));
$defaultInvoiceNumber = trim((string)($dataset['defaultInvoiceNumber'] ?? 'SIN-FACTURA-VIBA-2026-04-11'));
$defaultIssuedAt = trim((string)($dataset['defaultIssuedAt'] ?? date('Y-m-d')));
$defaultSpecies = trim((string)($dataset['defaultSpecies'] ?? 'Perro y gato'));

TenantContext::set($tenants[$tenantId]);
$db = Database::getInstance();
$purchaseInvoices = new PurchaseInvoiceRepository($db);
$inventoryLots = new InventoryLotRepository($db);

$selectProductByLegacy = $db->prepare('
    SELECT id, legacy_id, quantity, sold
    FROM "Product"
    WHERE tenant_id = :tenant_id
      AND legacy_id = :legacy_id
    LIMIT 1
    FOR UPDATE
');

$selectProductByMatch = $db->prepare('
    SELECT id, legacy_id, quantity, sold
    FROM "Product"
    WHERE tenant_id = :tenant_id
      AND brand = :brand
      AND LOWER(name) = LOWER(:name)
      AND COALESCE(attributes->>\'size\', \'\') = :size
    ORDER BY created_at ASC, id ASC
    FOR UPDATE
');

$insertProduct = $db->prepare('
    INSERT INTO "Product" (
        id,
        legacy_id,
        tenant_id,
        category,
        product_type,
        name,
        gender,
        is_new,
        is_sale,
        is_published,
        price,
        original_price,
        cost,
        brand,
        sold,
        quantity,
        description,
        action,
        slug,
        attributes,
        created_at,
        updated_at
    ) VALUES (
        :id,
        :legacy_id,
        :tenant_id,
        :category,
        :product_type,
        :name,
        :gender,
        :is_new,
        :is_sale,
        :is_published,
        :price,
        :original_price,
        :cost,
        :brand,
        :sold,
        :quantity,
        :description,
        :action,
        :slug,
        :attributes::jsonb,
        NOW(),
        NOW()
    )
');

$updateProduct = $db->prepare('
    UPDATE "Product"
    SET
        legacy_id = :legacy_id,
        category = :category,
        product_type = :product_type,
        name = :name,
        gender = :gender,
        is_new = :is_new,
        is_sale = :is_sale,
        is_published = :is_published,
        price = :price,
        original_price = :original_price,
        cost = :cost,
        brand = :brand,
        quantity = :quantity,
        description = :description,
        action = :action,
        slug = :slug,
        attributes = :attributes::jsonb,
        updated_at = NOW()
    WHERE id = :id
      AND tenant_id = :tenant_id
');

$selectExistingLine = $db->prepare('
    SELECT pii.id
    FROM "PurchaseInvoiceItem" pii
    INNER JOIN "PurchaseInvoice" pi
      ON pi.id = pii.purchase_invoice_id
     AND pi.tenant_id = pii.tenant_id
    WHERE pii.tenant_id = :tenant_id
      AND pii.product_id = :product_id
      AND pi.invoice_number = :invoice_number
      AND pi.supplier_document = :supplier_document
      AND (
        pii.metadata->>\'import_line_key\' = :import_line_key
        OR (
          pii.quantity = :quantity
          AND ABS(COALESCE(pii.unit_cost, 0) - :unit_cost) < 0.0001
        )
      )
    LIMIT 1
');

$selectCatalogEntry = $db->prepare('
    SELECT id
    FROM "ProductReferenceCatalog"
    WHERE tenant_id = :tenant_id
      AND catalog_key = :catalog_key
      AND LOWER(label) = LOWER(:label)
    LIMIT 1
');

$updateCatalogEntry = $db->prepare('
    UPDATE "ProductReferenceCatalog"
    SET payload = :payload::jsonb,
        updated_at = NOW()
    WHERE id = :id
      AND tenant_id = :tenant_id
');

$nextCatalogSortOrder = $db->prepare('
    SELECT COALESCE(MAX(sort_order), -1) + 1
    FROM "ProductReferenceCatalog"
    WHERE tenant_id = :tenant_id
      AND catalog_key = :catalog_key
');

$insertCatalogEntry = $db->prepare('
    INSERT INTO "ProductReferenceCatalog" (
        id,
        tenant_id,
        catalog_key,
        label,
        payload,
        sort_order,
        created_at,
        updated_at
    ) VALUES (
        :id,
        :tenant_id,
        :catalog_key,
        :label,
        :payload::jsonb,
        :sort_order,
        NOW(),
        NOW()
    )
');

$slugify = static function (string $value): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii === false) {
        $ascii = $value;
    }
    $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($ascii)) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'item';
};

$buildSku = static function (string $name, string $variantLabel, string $color, string $speciesLabel) use ($slugify): string {
    $parts = [
        'ACC',
        'VIBA',
        strtoupper(str_replace('-', '', $slugify($name))),
        strtoupper(str_replace('-', '', $slugify($variantLabel !== '' ? $variantLabel : $color))),
        strtoupper(str_replace('-', '', $slugify($speciesLabel))),
    ];

    return implode('-', array_filter($parts, static fn ($part) => $part !== ''));
};

$resolveSpeciesAndGender = static function (?string $rawSpecies, string $fallbackSpecies = 'Perro y gato'): array {
    $species = trim((string)$rawSpecies);
    if ($species === '' || strcasecmp($species, 'N/A') === 0) {
        $species = $fallbackSpecies;
    }

    $normalized = strtolower($species);
    $hasDog = str_contains($normalized, 'perro') || str_contains($normalized, 'dog') || str_contains($normalized, 'canin');
    $hasCat = str_contains($normalized, 'gato') || str_contains($normalized, 'cat') || str_contains($normalized, 'felin');

    if ($hasDog && $hasCat) {
        return [
            'species' => 'Perro y gato',
            'gender' => 'Unisex',
        ];
    }

    $speciesLabel = ProductAudience::normalizeSpeciesLabel($species, '');
    $gender = ProductAudience::resolveGender($speciesLabel, '');

    return [
        'species' => $speciesLabel !== '' ? $speciesLabel : $fallbackSpecies,
        'gender' => $gender,
    ];
};

$formatDecimal = static function (float $value): string {
    return number_format($value, 4, '.', '');
};

$buildImportLineKey = static function (string $supplierName, string $invoiceNumber, string $legacyId, int $quantity, float $unitCost): string {
    return implode('|', [
        $supplierName,
        $invoiceNumber,
        $legacyId,
        (string)$quantity,
        number_format($unitCost, 4, '.', ''),
    ]);
};

$ensureCatalogEntry = static function (
    string $catalogKey,
    string $label,
    array $payload = []
) use (
    $tenantId,
    $selectCatalogEntry,
    $updateCatalogEntry,
    $nextCatalogSortOrder,
    $insertCatalogEntry
): void {
    $label = trim($label);
    if ($label === '') {
        return;
    }

    $selectCatalogEntry->execute([
        'tenant_id' => $tenantId,
        'catalog_key' => $catalogKey,
        'label' => $label,
    ]);
    $existingEntry = $selectCatalogEntry->fetch();
    if ($existingEntry) {
        if ($payload !== []) {
            $updateCatalogEntry->execute([
                'id' => (string)$existingEntry['id'],
                'tenant_id' => $tenantId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        return;
    }

    $nextCatalogSortOrder->execute([
        'tenant_id' => $tenantId,
        'catalog_key' => $catalogKey,
    ]);
    $sortOrder = (int)($nextCatalogSortOrder->fetchColumn() ?: 0);

    $normalizedPayload = $payload !== [] ? $payload : ['label' => $label];
    $insertCatalogEntry->execute([
        'id' => 'prc_' . substr(hash('sha256', $tenantId . '|' . $catalogKey . '|' . $label), 0, 28),
        'tenant_id' => $tenantId,
        'catalog_key' => $catalogKey,
        'label' => $label,
        'payload' => json_encode($normalizedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'sort_order' => $sortOrder,
    ]);
};

$buildAttributes = static function (array $product) use (
    $brand,
    $category,
    $saleTaxRate,
    $purchaseTaxRate,
    $defaultSpecies,
    $storageLocation,
    $supplier,
    $buildSku,
    $resolveSpeciesAndGender
): array {
    $color = trim((string)($product['color'] ?? ''));
    $variantLabel = trim((string)($product['variantLabel'] ?? ''));
    $variantBaseName = trim((string)($product['variantBaseName'] ?? ''));
    $audience = $resolveSpeciesAndGender((string)($product['species'] ?? $defaultSpecies), $defaultSpecies);
    $speciesLabel = $audience['species'];
    $resolvedGender = $audience['gender'];

    $attributes = [
        'sku' => $buildSku((string)$product['name'], $variantLabel, $color, $speciesLabel),
        'color' => $color,
        'species' => $speciesLabel,
        'supplier' => trim((string)($supplier['name'] ?? '')),
        'supplierDocument' => trim((string)($supplier['document'] ?? '')),
        'purchaseTaxRate' => number_format($purchaseTaxRate, 2, '.', ''),
        'storageLocation' => $storageLocation,
        'taxExempt' => 'false',
        'taxRate' => number_format($saleTaxRate, 2, '.', ''),
    ];

    return ProductVariantMetadata::apply([
        'name' => (string)$product['name'],
        'brand' => $brand,
        'category' => $category,
        'gender' => $resolvedGender,
        'variantLabel' => $variantLabel,
        'variantBaseName' => $variantBaseName,
    ], $attributes);
};

$summarizeDataset = static function (array $items) use ($defaultInvoiceNumber, $purchaseTaxRate): array {
    $productCount = count($items);
    $lineCount = 0;
    $invoiceTotals = [];

    foreach ($items as $product) {
        foreach (($product['purchaseLines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $invoiceNumber = trim((string)($line['invoiceNumber'] ?? $defaultInvoiceNumber));
            $quantity = max(0, (int)($line['quantity'] ?? 0));
            $unitCost = round((float)($line['unitCost'] ?? 0), 4);
            if ($invoiceNumber === '' || $quantity <= 0 || $unitCost < 0) {
                continue;
            }

            $lineCount++;
            $invoiceTotals[$invoiceNumber] = $invoiceTotals[$invoiceNumber] ?? [
                'subtotal' => 0.0,
                'tax' => 0.0,
                'total' => 0.0,
            ];
            $lineSubtotal = $quantity * $unitCost;
            $lineTax = $lineSubtotal * ($purchaseTaxRate / 100);
            $invoiceTotals[$invoiceNumber]['subtotal'] += $lineSubtotal;
            $invoiceTotals[$invoiceNumber]['tax'] += $lineTax;
            $invoiceTotals[$invoiceNumber]['total'] += $lineSubtotal + $lineTax;
        }
    }

    return [
        'products' => $productCount,
        'lines' => $lineCount,
        'invoiceTotals' => $invoiceTotals,
    ];
};

$summary = $summarizeDataset($products);
if ($dryRun) {
    echo "Dry run tenant={$tenantId} file={$filePath}\n";
    echo "Productos: {$summary['products']}\n";
    echo "Lineas de compra: {$summary['lines']}\n";
    foreach ($summary['invoiceTotals'] as $invoiceNumber => $totals) {
        echo "Factura {$invoiceNumber}: subtotal=" . number_format((float)$totals['subtotal'], 2, '.', '')
            . " tax=" . number_format((float)$totals['tax'], 2, '.', '')
            . " total=" . number_format((float)$totals['total'], 2, '.', '') . "\n";
    }
    exit(0);
}

$insertedProducts = 0;
$updatedProducts = 0;
$insertedPurchaseLines = 0;
$skippedPurchaseLines = 0;
$touchedProducts = [];

try {
    $db->beginTransaction();

    $ensureCatalogEntry('brands', $brand);
    $ensureCatalogEntry('storageLocations', $storageLocation);
    $ensureCatalogEntry('suppliers', (string)$supplier['name'], [
        'id' => 'supplier-' . preg_replace('/\D+/', '', (string)($supplier['document'] ?? '')),
        'name' => trim((string)($supplier['name'] ?? '')),
        'document' => trim((string)($supplier['document'] ?? '')),
        'purchaseTaxRate' => number_format($purchaseTaxRate, 2, '.', ''),
        'email' => trim((string)($supplier['email'] ?? '')),
        'phone' => trim((string)($supplier['phone'] ?? '')),
        'contactName' => trim((string)($supplier['contactName'] ?? '')),
        'address' => trim((string)($supplier['address'] ?? '')),
        'notes' => trim((string)($supplier['notes'] ?? '')),
    ]);

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $legacyId = trim((string)($product['legacyId'] ?? ''));
        $name = trim((string)($product['name'] ?? ''));
        $description = trim((string)($product['description'] ?? $name));
        $priceNet = round((float)($product['priceNet'] ?? 0), 4);
        $purchaseLines = is_array($product['purchaseLines'] ?? null) ? $product['purchaseLines'] : [];
        $color = trim((string)($product['color'] ?? ''));

        if ($legacyId === '' || $name === '' || $priceNet <= 0 || $purchaseLines === []) {
            throw new RuntimeException("Producto invalido en dataset: " . json_encode($product, JSON_UNESCAPED_UNICODE));
        }

        $attributes = $buildAttributes($product);
        $audience = $resolveSpeciesAndGender((string)($attributes['species'] ?? $defaultSpecies), $defaultSpecies);
        $speciesLabel = $audience['species'];
        $resolvedGender = $audience['gender'];
        $slug = $slugify($legacyId . '-' . $name);

        if ($color !== '') {
            $ensureCatalogEntry('colors', $color);
        }

        $selectProductByLegacy->execute([
            'tenant_id' => $tenantId,
            'legacy_id' => $legacyId,
        ]);
        $existingProduct = $selectProductByLegacy->fetch();

        if (!$existingProduct) {
            $selectProductByMatch->execute([
                'tenant_id' => $tenantId,
                'brand' => $brand,
                'name' => $name,
                'size' => '',
            ]);
            $matches = $selectProductByMatch->fetchAll() ?: [];
            if (count($matches) > 1) {
                throw new RuntimeException("Se encontraron multiples coincidencias para {$name}.");
            }
            if (count($matches) === 1) {
                $existingProduct = $matches[0];
            }
        }

        $productId = '';
        $currentQuantity = 0;
        if ($existingProduct) {
            $productId = (string)$existingProduct['id'];
            $currentQuantity = max(0, (int)($existingProduct['quantity'] ?? 0));
            $updatedProducts++;
        } else {
            $productId = uniqid('prod_imp_');
            $insertProduct->execute([
                'id' => $productId,
                'legacy_id' => $legacyId,
                'tenant_id' => $tenantId,
                'category' => $category,
                'product_type' => $productType,
                'name' => $name,
                'gender' => $resolvedGender,
                'is_new' => 'false',
                'is_sale' => 'false',
                'is_published' => 'false',
                'price' => $formatDecimal($priceNet),
                'original_price' => $formatDecimal($priceNet),
                'cost' => '0.00',
                'brand' => $brand,
                'sold' => 0,
                'quantity' => 0,
                'description' => $description,
                'action' => 'view',
                'slug' => $slug,
                'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $insertedProducts++;
        }

        $lastUnitCost = 0.0;
        foreach ($purchaseLines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $invoiceNumber = trim((string)($line['invoiceNumber'] ?? $defaultInvoiceNumber));
            $issuedAt = trim((string)($line['issuedAt'] ?? $defaultIssuedAt));
            $quantity = max(0, (int)($line['quantity'] ?? 0));
            $unitCost = round((float)($line['unitCost'] ?? 0), 4);
            $lastUnitCost = $unitCost;

            if ($invoiceNumber === '' || $issuedAt === '' || $quantity <= 0 || $unitCost < 0) {
                throw new RuntimeException("Linea de compra invalida para {$legacyId}.");
            }

            $importLineKey = $buildImportLineKey((string)$supplier['name'], $invoiceNumber, $legacyId, $quantity, $unitCost);
            $selectExistingLine->execute([
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'invoice_number' => $invoiceNumber,
                'supplier_document' => trim((string)($supplier['document'] ?? '')),
                'import_line_key' => $importLineKey,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]);
            if ($selectExistingLine->fetch()) {
                $skippedPurchaseLines++;
                continue;
            }

            $purchaseEntry = $purchaseInvoices->recordStockEntry([
                'invoiceNumber' => $invoiceNumber,
                'supplierName' => trim((string)($supplier['name'] ?? 'Viba Pets')),
                'supplierDocument' => trim((string)($supplier['document'] ?? '')),
                'issuedAt' => $issuedAt,
                'notes' => 'Carga puntual sin imagenes desde dataset Viba Pets 2026-04-11. Hoja fuente sin numero de factura ni fecha; se registro una factura interna.',
                'metadata' => [
                    'import_batch' => 'viba_pets_accessories_2026_04_11',
                    'source_file' => basename($filePath),
                    'supplier_notes' => trim((string)($supplier['notes'] ?? '')),
                    'source_invoice_missing' => true,
                    'source_issued_at_missing' => true,
                ],
            ], $productId, $name, $quantity, $unitCost, [
                'import_line_key' => $importLineKey,
                'color' => $color,
                'species' => $speciesLabel,
                'variant_label' => trim((string)($product['variantLabel'] ?? '')),
                'purchase_tax_rate' => $purchaseTaxRate,
                'sale_tax_rate' => $saleTaxRate,
                'tax_rate' => $purchaseTaxRate,
                'tax_exempt' => $purchaseTaxRate <= 0,
            ]);

            $inventoryLots->recordStockIncrease(
                $productId,
                $quantity,
                $unitCost,
                'purchase_invoice',
                (string)($purchaseEntry['item']['id'] ?? $productId),
                [
                    'reason' => 'viba_pets_import',
                    'import_line_key' => $importLineKey,
                    'invoice_number' => $invoiceNumber,
                ],
                (string)($purchaseEntry['invoice']['id'] ?? ''),
                (string)($purchaseEntry['item']['id'] ?? '')
            );

            $currentQuantity += $quantity;
            $insertedPurchaseLines++;
        }

        $updateProduct->execute([
            'id' => $productId,
            'tenant_id' => $tenantId,
            'legacy_id' => $legacyId,
            'category' => $category,
            'product_type' => $productType,
            'name' => $name,
            'gender' => $resolvedGender,
            'is_new' => 'false',
            'is_sale' => 'false',
            'is_published' => $currentQuantity > 0 ? 'true' : 'false',
            'price' => $formatDecimal($priceNet),
            'original_price' => $formatDecimal($priceNet),
            'cost' => number_format($lastUnitCost, 2, '.', ''),
            'brand' => $brand,
            'quantity' => $currentQuantity,
            'description' => $description,
            'action' => 'view',
            'slug' => $slug,
            'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $touchedProducts[] = $legacyId;
    }

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Importacion fallida: {$exception->getMessage()}\n");
    exit(1);
}

$touchedProducts = array_values(array_unique($touchedProducts));
echo "Tenant: {$tenantId}\n";
echo "Dataset: {$filePath}\n";
echo "Productos insertados: {$insertedProducts}\n";
echo "Productos actualizados: {$updatedProducts}\n";
echo "Productos tocados: " . count($touchedProducts) . "\n";
echo "Lineas de compra insertadas: {$insertedPurchaseLines}\n";
echo "Lineas de compra omitidas: {$skippedPurchaseLines}\n";
