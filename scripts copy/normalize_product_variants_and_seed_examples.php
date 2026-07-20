<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Support\ProductVariantMetadata;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    $dotenv = Dotenv::createImmutable($envDir);
    $dotenv->load();
}

$options = getopt('', ['tenant::', 'dry-run', 'force-all']);
$tenantId = trim((string)($options['tenant'] ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec')));
$dryRun = array_key_exists('dry-run', $options);
$forceAll = array_key_exists('force-all', $options);

function parseAttributesForVariants(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function usesFoodPricing(array $row): bool
{
    $productType = strtolower(trim((string)($row['product_type'] ?? '')));
    $category = strtolower(trim((string)($row['category'] ?? '')));

    return $productType === 'Alimento'
        || str_contains($category, 'Alimento')
        || str_contains($category, 'alimento')
        || str_contains($category, 'snack')
        || str_contains($category, 'premio');
}

function demoSeed(array $row, array $attributes): int
{
    $key = implode('|', [
        (string)($attributes['variantGroupKey'] ?? ''),
        (string)($row['brand'] ?? ''),
        (string)($row['legacy_id'] ?? ''),
        (string)($row['id'] ?? ''),
    ]);

    return (int)abs(crc32($key));
}

function deriveExamplePrice(array $row, array $attributes): float
{
    $label = ProductVariantMetadata::resolveVariantLabel($row, $attributes);
    $descriptor = ProductVariantMetadata::describeLabel($label);
    $seed = demoSeed($row, $attributes);
    $food = usesFoodPricing($row);

    $price = match ($descriptor['dimension']) {
        'weight' => deriveWeightPrice((float)$descriptor['value'], $seed, $food),
        'volume' => deriveVolumePrice((float)$descriptor['value'], $seed, $food),
        'count' => deriveCountPrice((float)$descriptor['value'], $seed, $food),
        'size' => deriveNamedSizePrice((float)$descriptor['value'], $seed, $food),
        default => deriveFallbackPrice($seed, $food),
    };

    return round(max(1.25, $price), 2);
}

function deriveWeightPrice(float $grams, int $seed, bool $food): float
{
    $grams = max(50.0, $grams);
    $kg = $grams / 1000;

    if ($food) {
        $ratePerKg = 4.80 + (($seed % 300) / 100); // 4.80 .. 7.79
        $packaging = $kg <= 0.2 ? 0.70 : ($kg <= 1 ? 1.10 : ($kg <= 5 ? 1.90 : 3.40));
        return ($kg * $ratePerKg) + $packaging;
    }

    $ratePerKg = 9.50 + (($seed % 450) / 100); // 9.50 .. 13.99
    return ($kg * $ratePerKg) + 2.50;
}

function deriveVolumePrice(float $ml, int $seed, bool $food): float
{
    $ml = max(30.0, $ml);
    if ($food) {
        $ratePerMl = 0.018 + (($seed % 9) / 1000); // 0.018 .. 0.026
        return ($ml * $ratePerMl) + 0.90;
    }

    $ratePerMl = 0.020 + (($seed % 12) / 1000); // 0.020 .. 0.031
    return ($ml * $ratePerMl) + 1.80;
}

function deriveCountPrice(float $units, int $seed, bool $food): float
{
    $units = max(1.0, $units);
    $unitPrice = $food
        ? (0.90 + (($seed % 30) / 100))
        : (2.80 + (($seed % 120) / 100));

    return $units * $unitPrice;
}

function deriveNamedSizePrice(float $sizeValue, int $seed, bool $food): float
{
    $base = $food ? 4.50 : 6.50;
    $step = $food ? 2.25 : 3.10;
    $variation = ($seed % 80) / 100;
    return $base + ($sizeValue * $step) + $variation;
}

function deriveFallbackPrice(int $seed, bool $food): float
{
    return $food
        ? (5.50 + (($seed % 420) / 100))
        : (8.50 + (($seed % 650) / 100));
}

function deriveExampleQuantity(array $row, array $attributes): int
{
    $label = ProductVariantMetadata::resolveVariantLabel($row, $attributes);
    $descriptor = ProductVariantMetadata::describeLabel($label);

    return match ($descriptor['dimension']) {
        'weight' => deriveWeightQuantity((float)$descriptor['value']),
        'volume' => deriveVolumeQuantity((float)$descriptor['value']),
        'count' => deriveCountQuantity((float)$descriptor['value']),
        'size' => deriveNamedSizeQuantity((float)$descriptor['value']),
        default => 14,
    };
}

function deriveWeightQuantity(float $grams): int
{
    if ($grams <= 100) return 48;
    if ($grams <= 250) return 36;
    if ($grams <= 1000) return 24;
    if ($grams <= 4000) return 18;
    if ($grams <= 10000) return 12;
    return 8;
}

function deriveVolumeQuantity(float $ml): int
{
    if ($ml <= 100) return 30;
    if ($ml <= 500) return 20;
    return 12;
}

function deriveCountQuantity(float $units): int
{
    if ($units <= 1) return 24;
    if ($units <= 10) return 18;
    if ($units <= 25) return 12;
    return 8;
}

function deriveNamedSizeQuantity(float $sizeValue): int
{
    if ($sizeValue <= 1) return 18;
    if ($sizeValue <= 2) return 16;
    if ($sizeValue <= 3) return 14;
    if ($sizeValue <= 4) return 12;
    return 10;
}

function deriveExampleCost(float $price, int $seed): float
{
    $ratio = 0.52 + (($seed % 14) / 100); // 0.52 .. 0.65
    return round(max(0.50, $price * $ratio), 2);
}

$db = Database::getInstance();

$select = $db->prepare('
    SELECT id, legacy_id, tenant_id, name, brand, category, gender, product_type, price, original_price, cost, quantity, sold, is_published, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
    ORDER BY name ASC
');
$select->execute(['tenant_id' => $tenantId]);
$products = $select->fetchAll();

if (!$products) {
    echo "No hay productos para tenant {$tenantId}.\n";
    exit(0);
}

$update = $db->prepare('
    UPDATE "Product"
    SET
        price = :price,
        cost = :cost,
        quantity = :quantity,
        attributes = :attributes,
        updated_at = NOW()
    WHERE id = :id AND tenant_id = :tenant_id
');

$reviewed = 0;
$updated = 0;
$variantMetadataUpdated = 0;
$priceSeeded = 0;
$quantitySeeded = 0;
$costSeeded = 0;
$samples = [];

if (!$dryRun) {
    $db->beginTransaction();
}

try {
    foreach ($products as $row) {
        $reviewed++;
        $attributes = parseAttributesForVariants($row['attributes'] ?? null);
        $normalizedAttributes = ProductVariantMetadata::apply($row, $attributes);

        $currentPrice = (float)($row['price'] ?? 0);
        $currentQuantity = (int)($row['quantity'] ?? 0);
        $currentCost = (float)($row['cost'] ?? 0);
        $seed = demoSeed($row, $normalizedAttributes);

        $finalPrice = $forceAll
            ? deriveExamplePrice($row, $normalizedAttributes)
            : ($currentPrice > 0 ? round($currentPrice, 2) : deriveExamplePrice($row, $normalizedAttributes));
        $finalQuantity = $forceAll
            ? deriveExampleQuantity($row, $normalizedAttributes)
            : ($currentQuantity > 0 ? $currentQuantity : deriveExampleQuantity($row, $normalizedAttributes));
        $finalCost = $forceAll
            ? deriveExampleCost($finalPrice, $seed)
            : ($currentCost > 0 ? round($currentCost, 2) : deriveExampleCost($finalPrice, $seed));

        $changedVariantMetadata = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            !== json_encode($normalizedAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($changedVariantMetadata) {
            $variantMetadataUpdated++;
        }
        if (($forceAll || $currentPrice <= 0) && $finalPrice > 0) {
            $priceSeeded++;
        }
        if (($forceAll || $currentQuantity <= 0) && $finalQuantity > 0) {
            $quantitySeeded++;
        }
        if (($forceAll || $currentCost <= 0) && $finalCost > 0) {
            $costSeeded++;
        }

        $needsUpdate = $changedVariantMetadata
            || round($currentPrice, 2) !== $finalPrice
            || $currentQuantity !== $finalQuantity
            || round($currentCost, 2) !== $finalCost;

        if (!$needsUpdate) {
            continue;
        }

        if (!$dryRun) {
            $update->execute([
                'id' => $row['id'],
                'tenant_id' => $tenantId,
                'price' => number_format($finalPrice, 2, '.', ''),
                'cost' => number_format($finalCost, 2, '.', ''),
                'quantity' => $finalQuantity,
                'attributes' => json_encode($normalizedAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        if (count($samples) < 12) {
            $samples[] = sprintf(
                '%s | base=%s | label=%s | price=%s | qty=%d',
                (string)$row['name'],
                (string)($normalizedAttributes['variantBaseName'] ?? '-'),
                (string)($normalizedAttributes['variantLabel'] ?? '-'),
                number_format($finalPrice, 2, '.', ''),
                $finalQuantity
            );
        }

        $updated++;
    }

    if (!$dryRun) {
        $db->commit();
    }
} catch (Throwable $exception) {
    if (!$dryRun && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Normalizacion fallida: {$exception->getMessage()}\n");
    exit(1);
}

echo "Tenant: {$tenantId}\n";
echo "Productos revisados: {$reviewed}\n";
echo "Productos actualizados: {$updated}\n";
echo "Variant metadata actualizada: {$variantMetadataUpdated}\n";
echo "Precios de ejemplo sembrados: {$priceSeeded}\n";
echo "Cantidades de ejemplo sembradas: {$quantitySeeded}\n";
echo "Costos de ejemplo sembrados: {$costSeeded}\n";

if ($samples !== []) {
    echo "Muestras:\n";
    foreach ($samples as $sample) {
        echo "- {$sample}\n";
    }
}
