<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    $dotenv = Dotenv::createImmutable($envDir);
    $dotenv->load();
}

function mapProductType(string $category, ?string $current = null): string {
    $normalized = strtolower(trim($category));

    if (
        str_contains($normalized, 'Alimento')
        || str_contains($normalized, 'alimento')
        || str_contains($normalized, 'snack')
        || str_contains($normalized, 'premio')
    ) {
        return 'Alimento';
    }
    if (str_contains($normalized, 'ropa') || str_contains($normalized, 'vestimenta')) {
        return 'ropa';
    }
    $currentType = strtolower(trim((string)$current));
    if (in_array($currentType, ['Alimento', 'ropa', 'accesorios'], true)) {
        return $currentType;
    }
    return 'accesorios';
}

function parseAttributes($raw): array {
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizePositiveInt($value, int $default, int $min = 0, int $max = 100000): int {
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function safeLegacySeed(array $row): int {
    $legacy = trim((string)($row['legacy_id'] ?? ''));
    if ($legacy !== '' && ctype_digit($legacy)) {
        return (int)$legacy;
    }
    return (int)abs(crc32((string)($row['id'] ?? uniqid('seed_', true))));
}

$tenantId = $_ENV['DEFAULT_TENANT'] ?? 'paramascotasec';
$db = Database::getInstance();

$stmt = $db->prepare('
    SELECT id, legacy_id, name, category, product_type, price, cost, quantity, sold, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
    ORDER BY COALESCE(NULLIF(legacy_id, \'\'), id) ASC
');
$stmt->execute(['tenant_id' => $tenantId]);
$products = $stmt->fetchAll();

if (!$products) {
    echo "No hay productos para tenant {$tenantId}.\n";
    exit(0);
}

$update = $db->prepare('
    UPDATE "Product"
    SET
        product_type = :product_type,
        cost = :cost,
        quantity = :quantity,
        sold = :sold,
        attributes = :attributes,
        updated_at = NOW()
    WHERE id = :id AND tenant_id = :tenant_id
');

$today = new DateTimeImmutable('today');
$updated = 0;
$foodWithExpiry = 0;
$expiredWithStock = 0;
$expiringWithStock = 0;
$costUpdated = 0;

foreach ($products as $row) {
    $seed = safeLegacySeed($row);
    $productType = mapProductType((string)($row['category'] ?? ''), (string)($row['product_type'] ?? ''));
    $price = (float)($row['price'] ?? 0);
    $currentCost = (float)($row['cost'] ?? 0);
    $attributes = parseAttributes($row['attributes'] ?? null);

    if (!isset($attributes['sku']) || trim((string)$attributes['sku']) === '') {
        $attributes['sku'] = trim((string)($row['legacy_id'] ?? '')) !== '' ? (string)$row['legacy_id'] : (string)$row['id'];
    }
    if (!isset($attributes['tag']) || trim((string)$attributes['tag']) === '') {
        $attributes['tag'] = strtolower((string)($row['category'] ?? 'general'));
    }
    if (!isset($attributes['species']) || trim((string)$attributes['species']) === '') {
        $attributes['species'] = str_contains(strtolower((string)$row['name']), 'gato') ? 'cat' : 'dog';
    }

    // Cost normalization to make valuation metrics useful.
    $costRatio = 0.52 + (($seed % 7) * 0.04); // 0.52 .. 0.76
    $computedCost = round(max(0.5, $price * $costRatio), 2);
    $finalCost = $currentCost > 0 ? $currentCost : $computedCost;
    if ($currentCost <= 0) {
        $costUpdated++;
    }

    $reorderPointDefault = $productType === 'Alimento' ? 15 : (6 + ($seed % 5));
    $overstockThresholdDefault = $productType === 'Alimento' ? (90 + (($seed % 4) * 25)) : (70 + (($seed % 6) * 20));
    $stockMaxDefault = $overstockThresholdDefault + max(20, (int)round($overstockThresholdDefault * 0.25));

    $reorderPoint = normalizePositiveInt($attributes['reorderPoint'] ?? null, $reorderPointDefault, 1, 5000);
    $overstockThreshold = normalizePositiveInt($attributes['overstockThreshold'] ?? null, $overstockThresholdDefault, $reorderPoint + 1, 10000);
    $stockMax = normalizePositiveInt($attributes['stockMax'] ?? null, $stockMaxDefault, $overstockThreshold, 15000);
    if ($productType === 'Alimento') {
        $reorderPoint = max(12, $reorderPoint);
        $overstockThreshold = max(90, $overstockThreshold);
        $stockMax = max($stockMax, $overstockThreshold + 20);
    }

    $attributes['reorderPoint'] = (string)$reorderPoint;
    $attributes['overstockThreshold'] = (string)$overstockThreshold;
    $attributes['stockMax'] = (string)$stockMax;
    $attributes['lotCode'] = $attributes['lotCode'] ?? ('LOT-' . $today->format('Ym') . '-' . str_pad((string)($seed % 1000), 3, '0', STR_PAD_LEFT));
    $attributes['storageLocation'] = $attributes['storageLocation'] ?? ('Bodega ' . chr(65 + ($seed % 4)) . '-' . (1 + ($seed % 8)));
    $attributes['supplier'] = $attributes['supplier'] ?? 'Proveedor Local';

    $currentQuantity = normalizePositiveInt($row['quantity'] ?? null, 0, 0, 20000);
    $finalQuantity = $currentQuantity;
    $finalSold = normalizePositiveInt($row['sold'] ?? null, 0, 0, 1000000);

    if ($productType === 'Alimento') {
        $foodWithExpiry++;
        $bucket = $seed % 4;
        if ($bucket === 0) {
            $expiryDate = $today->modify('-6 days');
            $finalQuantity = max(8, $finalQuantity > 0 ? min($finalQuantity, 18) : 12);
            $expiredWithStock++;
        } elseif ($bucket === 1) {
            $expiryDate = $today->modify('+9 days');
            $finalQuantity = max(6, $finalQuantity > 0 ? min($finalQuantity, 16) : 9);
            $expiringWithStock++;
        } elseif ($bucket === 2) {
            $expiryDate = $today->modify('+45 days');
            $finalQuantity = max(20, $finalQuantity > 0 ? $finalQuantity : 36);
        } else {
            $expiryDate = $today->modify('+120 days');
            $finalQuantity = max(30, $finalQuantity > 0 ? $finalQuantity : 52);
        }
        $attributes['expirationDate'] = $expiryDate->format('Y-m-d');
        $attributes['expirationAlertDays'] = (string)21;
    } else {
        $stockBucket = $seed % 5;
        if ($stockBucket === 0) {
            $finalQuantity = 0; // sin stock
        } elseif ($stockBucket === 1) {
            $finalQuantity = max(1, $reorderPoint - 1); // bajo stock
        } elseif ($stockBucket === 2) {
            $finalQuantity = max($reorderPoint + 8, 18 + (($seed % 5) * 4)); // saludable
        } elseif ($stockBucket === 3) {
            $finalQuantity = max($reorderPoint + 20, 45 + (($seed % 6) * 5)); // saludable alto
        } else {
            $finalQuantity = max($overstockThreshold + 10, 90 + (($seed % 5) * 12)); // sobre stock
        }
        unset($attributes['expirationDate'], $attributes['expiryDate'], $attributes['expirationAlertDays'], $attributes['expiryAlertDays']);
    }

    $update->execute([
        'id' => $row['id'],
        'tenant_id' => $tenantId,
        'product_type' => $productType,
        'cost' => number_format($finalCost, 2, '.', ''),
        'quantity' => $finalQuantity,
        'sold' => $finalSold,
        'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $updated++;
}

echo "Tenant: {$tenantId}\n";
echo "Productos actualizados: {$updated}\n";
echo "Productos con costo normalizado: {$costUpdated}\n";
echo "Productos Alimento con vencimiento: {$foodWithExpiry}\n";
echo "Alimento vencida con stock (demo): {$expiredWithStock}\n";
echo "Alimento por vencer con stock (demo): {$expiringWithStock}\n";
