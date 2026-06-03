<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Support\ProductVariantMetadata;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->load();
}

$options = getopt('', ['tenant::', 'supplier::', 'legacy-prefix::', 'dry-run']);
$tenantId = trim((string)($options['tenant'] ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec')));
$supplierName = trim((string)($options['supplier'] ?? 'Misha Fashion Pets'));
$legacyPrefix = trim((string)($options['legacy-prefix'] ?? 'misha-fashion-pets-'));
$dryRun = array_key_exists('dry-run', $options);

$tenants = require __DIR__ . '/../config/tenants.php';
if (!isset($tenants[$tenantId])) {
    fwrite(STDERR, "Tenant no configurado: {$tenantId}\n");
    exit(1);
}

TenantContext::set($tenants[$tenantId]);
$db = Database::getInstance();

$select = $db->prepare('
    SELECT id, name, brand, category, gender, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
      AND legacy_id LIKE :legacy_prefix
    ORDER BY legacy_id ASC
');

$update = $db->prepare('
    UPDATE "Product"
    SET brand = :brand,
        attributes = :attributes::jsonb,
        updated_at = NOW()
    WHERE id = :id
      AND tenant_id = :tenant_id
');

$select->execute([
    'tenant_id' => $tenantId,
    'legacy_prefix' => $legacyPrefix . '%',
]);

$rows = $select->fetchAll() ?: [];
$reviewed = 0;
$updatedCount = 0;

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
        $attributesForRepair = $attributes;
        unset($attributesForRepair['variantBaseName'], $attributesForRepair['variantGroupKey']);

        $normalizedBrand = trim((string)($attributes['supplier'] ?? ''));
        if ($normalizedBrand === '') {
            $normalizedBrand = $supplierName;
        }

        $normalizedAttributes = ProductVariantMetadata::apply([
            'name' => (string)($row['name'] ?? ''),
            'brand' => $normalizedBrand,
            'category' => (string)($row['category'] ?? ''),
            'gender' => (string)($row['gender'] ?? ''),
        ], $attributesForRepair);

        $currentBrand = trim((string)($row['brand'] ?? ''));
        $currentAttributesJson = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nextAttributesJson = json_encode($normalizedAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($currentBrand === $normalizedBrand && $currentAttributesJson === $nextAttributesJson) {
            continue;
        }

        $updatedCount++;

        if ($dryRun) {
            echo json_encode([
                'id' => (string)($row['id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'brand_before' => $currentBrand,
                'brand_after' => $normalizedBrand,
                'base_before' => (string)($attributes['variantBaseName'] ?? ''),
                'base_after' => (string)($normalizedAttributes['variantBaseName'] ?? ''),
                'group_before' => (string)($attributes['variantGroupKey'] ?? ''),
                'group_after' => (string)($normalizedAttributes['variantGroupKey'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            continue;
        }

        $update->execute([
            'id' => (string)($row['id'] ?? ''),
            'tenant_id' => $tenantId,
            'brand' => $normalizedBrand,
            'attributes' => $nextAttributesJson,
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
echo "Proveedor base: {$supplierName}\n";
echo "Productos revisados: {$reviewed}\n";
echo "Productos corregidos: {$updatedCount}\n";
echo $dryRun ? "Modo: dry-run\n" : "Modo: aplicado\n";
