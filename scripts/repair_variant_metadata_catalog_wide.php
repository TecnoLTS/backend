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

$options = getopt('', ['tenant::', 'dry-run']);
$tenantId = trim((string)($options['tenant'] ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec')));
$dryRun = array_key_exists('dry-run', $options);

$tenants = require __DIR__ . '/../config/tenants.php';
if (!isset($tenants[$tenantId])) {
    fwrite(STDERR, "Tenant no configurado: {$tenantId}\n");
    exit(1);
}

TenantContext::set($tenants[$tenantId]);
$db = Database::getInstance();

function normalizeRepairPayload(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = normalizeRepairPayload($item);
    }

    $keys = array_keys($value);
    $isList = $keys === range(0, count($value) - 1);
    if (!$isList) {
        ksort($value);
    }

    return $value;
}

$select = $db->prepare('
    SELECT id, name, brand, category, gender, product_type, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
    ORDER BY name ASC, id ASC
');

$update = $db->prepare('
    UPDATE "Product"
    SET attributes = :attributes::jsonb,
        updated_at = NOW()
    WHERE id = :id
      AND tenant_id = :tenant_id
');

$select->execute(['tenant_id' => $tenantId]);
$rows = $select->fetchAll() ?: [];

$reviewed = 0;
$updated = 0;
$skippedConflicts = 0;
$preparedRows = [];
$proposedVariantKeys = [];

foreach ($rows as $row) {
    $reviewed++;

    $attributes = json_decode((string)($row['attributes'] ?? '{}'), true);
    if (!is_array($attributes)) {
        $attributes = [];
    }

    $repairInput = $attributes;
    unset($repairInput['variantBaseName'], $repairInput['variantGroupKey']);

    $normalizedAttributes = ProductVariantMetadata::apply([
        'name' => (string)($row['name'] ?? ''),
        'brand' => (string)($row['brand'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'gender' => (string)($row['gender'] ?? ''),
        'productType' => (string)($row['product_type'] ?? ''),
    ], $repairInput);

    $currentAttributesJson = json_encode(normalizeRepairPayload($attributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $nextAttributesJson = json_encode(normalizeRepairPayload($normalizedAttributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $isArchived = strtolower(trim((string)($attributes['archived'] ?? 'false'))) === 'true';
    $variantGroupKey = trim((string)($normalizedAttributes['variantGroupKey'] ?? ''));
    $variantLabel = trim((string)($normalizedAttributes['variantLabel'] ?? ''));
    $conflictKey = null;

    if (!$isArchived && $variantGroupKey !== '' && $variantLabel !== '') {
        $conflictKey = $variantGroupKey . '|' . $variantLabel;
        $proposedVariantKeys[$conflictKey] = ($proposedVariantKeys[$conflictKey] ?? 0) + 1;
    }

    $preparedRows[] = [
        'row' => $row,
        'attributes' => $attributes,
        'normalizedAttributes' => $normalizedAttributes,
        'currentAttributesJson' => $currentAttributesJson,
        'nextAttributesJson' => $nextAttributesJson,
        'conflictKey' => $conflictKey,
    ];
}

if (!$dryRun && !$db->inTransaction()) {
    $db->beginTransaction();
}

try {
    foreach ($preparedRows as $item) {
        $attributes = $item['attributes'];
        $normalizedAttributes = $item['normalizedAttributes'];
        $currentAttributesJson = $item['currentAttributesJson'];
        $nextAttributesJson = $item['nextAttributesJson'];

        if ($currentAttributesJson === $nextAttributesJson) {
            continue;
        }

        $conflictKey = $item['conflictKey'];
        if ($conflictKey !== null && ($proposedVariantKeys[$conflictKey] ?? 0) > 1) {
            $skippedConflicts++;
            continue;
        }

        $updated++;

        if ($dryRun) {
            echo json_encode([
                'id' => (string)($item['row']['id'] ?? ''),
                'name' => (string)($item['row']['name'] ?? ''),
                'variant_label_before' => (string)($attributes['variantLabel'] ?? ''),
                'variant_label_after' => (string)($normalizedAttributes['variantLabel'] ?? ''),
                'base_before' => (string)($attributes['variantBaseName'] ?? ''),
                'base_after' => (string)($normalizedAttributes['variantBaseName'] ?? ''),
                'group_before' => (string)($attributes['variantGroupKey'] ?? ''),
                'group_after' => (string)($normalizedAttributes['variantGroupKey'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            continue;
        }

        $update->execute([
            'id' => (string)($item['row']['id'] ?? ''),
            'tenant_id' => $tenantId,
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
echo "Productos revisados: {$reviewed}\n";
echo "Productos corregidos: {$updated}\n";
echo "Productos omitidos por conflicto: {$skippedConflicts}\n";
echo $dryRun ? "Modo: dry-run\n" : "Modo: aplicado\n";
