<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->load();
}

$options = getopt('', ['tenant::', 'dry-run']);
$tenantId = trim((string)($options['tenant'] ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec')));
$dryRun = array_key_exists('dry-run', $options);

$tenants = require __DIR__ . '/../config/tenants.php';
if (!isset($tenants[$tenantId])) {
    fwrite(STDERR, "Tenant no configurado: {$tenantId}\n");
    exit(1);
}

$normalizeText = static function (?string $value): string {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii === false) {
        $ascii = $text;
    }

    $ascii = strtolower($ascii);
    $ascii = preg_replace('/[^a-z0-9\s-]+/', '', $ascii) ?? $ascii;
    $ascii = preg_replace('/\s+/', ' ', $ascii) ?? $ascii;
    return trim($ascii);
};

$extractBaseName = static function (string $name, string $size) use ($normalizeText): string {
    $normalized = $normalizeText($name);
    $normalizedSize = strtoupper(trim($size));
    if ($normalized === '') {
        return '';
    }

    if ($normalizedSize !== '') {
        $suffixPattern = '/(?:\s+|-)' . preg_quote(strtolower($normalizedSize), '/') . '$/';
        $normalized = preg_replace($suffixPattern, '', $normalized) ?? $normalized;
        $normalized = trim($normalized);
    }

    $aliases = [
        'camiseta polo azu' => 'camiseta polo azul',
        'chaleco rayita rosa' => 'chaleco rayita rosa',
    ];

    return $aliases[$normalized] ?? $normalized;
};

$pricingTable = [
    'camiseta polo love rosa|S' => 6.34,
    'camiseta polo love rosa|M' => 7.84,
    'camiseta polo love rosa|L' => 9.33,
    'camiseta polo kisses rosa|S' => 6.34,
    'camiseta polo azul|S' => 6.34,
    'camiseta polo azul|L' => 9.33,
    'camiseta polo verde militar|S' => 6.34,
    'camiseta polo corazon morada|M' => 7.84,
    'camiseta seleccion amarilla|XS' => '9.6957',
    'camiseta seleccion amarilla|S' => 10.45,
    'camiseta seleccion amarilla|M' => 11.94,
    'camiseta seleccion amarilla|L' => 13.44,
    'camiseta seleccion azul|S' => 10.45,
    'camiseta seleccion azul|M' => 11.94,
    'camiseta seleccion azul|L' => 13.44,
    'camiseta seleccion blanca|S' => 10.45,
    'camiseta seleccion blanca|M' => 11.94,
    'camiseta seleccion blanca|L' => 13.44,
    'camiseta monster|XS' => 6.75,
    'chaleco rayita azul|XS' => 7.50,
    'chaleco rayita rosa|S' => 8.25,
    'saco sv hearts|S' => 7.50,
    'chaleco lazo|S' => 8.25,
    'basic camiseta|' => 7.50,
    'saco woof|M' => 10.50,
    'hoodie huellas|M' => 9.00,
    'chaleco peluche|M' => 10.50,
    'camiseta i love mommy|L' => 10.50,
    'vestido|L' => 11.25,
];

$mishaBaseNames = [
    'camiseta polo love rosa',
    'camiseta polo kisses rosa',
    'camiseta polo azul',
    'camiseta polo verde militar',
    'camiseta polo corazon morada',
    'camiseta seleccion amarilla',
    'camiseta seleccion azul',
    'camiseta seleccion blanca',
];

$petsFactoryBaseNames = [
    'camiseta monster',
    'chaleco rayita azul',
    'chaleco rayita rosa',
    'saco sv hearts',
    'chaleco lazo',
    'basic camiseta',
    'saco woof',
    'hoodie huellas',
    'chaleco peluche',
    'camiseta i love mommy',
    'vestido',
];

$normalizeRate = static function ($value): string {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '';
    }

    return number_format((float)$value, 2, '.', '');
};

$normalizeDecimal = static function ($value): string {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '0';
    }

    $normalized = number_format((float)$value, 10, '.', '');
    $normalized = rtrim(rtrim($normalized, '0'), '.');

    return $normalized === '' ? '0' : $normalized;
};

$normalizeBoolean = static function ($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (float)$value !== 0.0;
    }
    if (is_string($value)) {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($normalized !== null) {
            return $normalized;
        }
    }

    return false;
};

$resolvePurchaseTaxRate = static function (string $baseName) use ($mishaBaseNames, $petsFactoryBaseNames): string {
    if (in_array($baseName, $mishaBaseNames, true)) {
        return '0.00';
    }

    if (in_array($baseName, $petsFactoryBaseNames, true)) {
        return '15.00';
    }

    return '';
};

TenantContext::set($tenants[$tenantId]);
$db = Database::getInstance();

$select = $db->prepare('
    SELECT id, name, price, original_price, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
    ORDER BY name ASC, id ASC
');

$update = $db->prepare('
    UPDATE "Product"
    SET price = :price,
        original_price = :original_price,
        attributes = CAST(:attributes AS JSONB),
        updated_at = NOW()
    WHERE id = :id
      AND tenant_id = :tenant_id
');

$select->execute(['tenant_id' => $tenantId]);
$rows = $select->fetchAll() ?: [];

$reviewed = 0;
$matched = 0;
$updated = 0;
$unmatched = [];

if (!$dryRun && !$db->inTransaction()) {
    $db->beginTransaction();
}

try {
    foreach ($rows as $row) {
        $reviewed++;
        $attributes = json_decode((string)($row['attributes'] ?? '{}'), true);
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $size = strtoupper(trim((string)($attributes['size'] ?? '')));
        $baseName = $extractBaseName((string)($row['name'] ?? ''), $size);
        $key = $baseName . '|' . $size;

        if (!array_key_exists($key, $pricingTable)) {
            $fallbackKey = $baseName . '|';
            if (!array_key_exists($fallbackKey, $pricingTable)) {
                $unmatched[] = [
                    'id' => (string)($row['id'] ?? ''),
                    'name' => (string)($row['name'] ?? ''),
                    'size' => $size,
                    'key' => $key,
                ];
                continue;
            }
            $key = $fallbackKey;
        }

        $matched++;
        $targetPrice = $normalizeDecimal($pricingTable[$key]);
        $targetTaxRate = '15.00';
        $targetPurchaseTaxRate = $resolvePurchaseTaxRate($baseName);
        $targetAttributes = $attributes;
        $targetAttributes['taxRate'] = $targetTaxRate;
        $targetAttributes['purchaseTaxRate'] = $targetPurchaseTaxRate;
        $targetAttributes['taxExempt'] = 'false';
        unset(
            $targetAttributes['tax_exempt'],
            $targetAttributes['purchase_tax_rate'],
            $targetAttributes['purchase_tax_exempt']
        );

        $currentPrice = $normalizeDecimal($row['price'] ?? 0);
        $currentOriginalPrice = $normalizeDecimal($row['original_price'] ?? 0);
        $currentTaxRate = $normalizeRate($attributes['taxRate'] ?? null);
        $currentPurchaseTaxRate = $normalizeRate($attributes['purchaseTaxRate'] ?? ($attributes['purchase_tax_rate'] ?? null));
        $currentTaxExempt = $normalizeBoolean($attributes['taxExempt'] ?? ($attributes['tax_exempt'] ?? false));

        if (
            $currentPrice === $targetPrice
            && $currentOriginalPrice === $targetPrice
            && $currentTaxRate === $targetTaxRate
            && $currentPurchaseTaxRate === $targetPurchaseTaxRate
            && $currentTaxExempt === false
        ) {
            continue;
        }

        $updated++;

        if ($dryRun) {
            echo json_encode([
                'id' => (string)($row['id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'size' => $size,
                'key' => $key,
                'price_before' => $currentPrice,
                'price_after' => $targetPrice,
                'original_price_before' => $currentOriginalPrice,
                'original_price_after' => $targetPrice,
                'tax_rate_before' => $currentTaxRate,
                'tax_rate_after' => $targetTaxRate,
                'purchase_tax_rate_before' => $currentPurchaseTaxRate,
                'purchase_tax_rate_after' => $targetPurchaseTaxRate,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            continue;
        }

        $update->execute([
            'id' => (string)($row['id'] ?? ''),
            'tenant_id' => $tenantId,
            'price' => $targetPrice,
            'original_price' => $targetPrice,
            'attributes' => json_encode($targetAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    if (!$dryRun && $db->inTransaction()) {
        $db->commit();
    }
} catch (Throwable $exception) {
    if (!$dryRun && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Repair fallida: {$exception->getMessage()}\n");
    exit(1);
}

echo "Tenant: {$tenantId}\n";
echo "Productos revisados: {$reviewed}\n";
echo "Productos con mapping: {$matched}\n";
echo "Productos actualizados: {$updated}\n";
echo "Productos sin mapping: " . count($unmatched) . "\n";
if ($dryRun && $unmatched !== []) {
    foreach ($unmatched as $item) {
        echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
echo $dryRun ? "Modo: dry-run\n" : "Modo: aplicado\n";
