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

function normalizeVariantBackfillPayload(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = normalizeVariantBackfillPayload($item);
    }

    $keys = array_keys($value);
    $isList = $keys === range(0, count($value) - 1);
    if (!$isList) {
        ksort($value);
    }

    return $value;
}

function isArchivedVariantBackfillRow(array $attributes): bool
{
    return strtolower(trim((string)($attributes['archived'] ?? 'false'))) === 'true';
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

$fieldsToSync = [
    'variantDefinitionField',
    'variantAxis',
    'displayAxis',
    'variantLabel',
    'variantBaseName',
    'weight',
    'volume',
    'size',
    'presentation',
    'packaging',
];

$preparedRows = [];
$proposedVariantKeys = [];
$reviewed = 0;
$changed = 0;
$skippedConflicts = 0;

foreach ($rows as $row) {
    $reviewed++;
    $attributes = json_decode((string)($row['attributes'] ?? '{}'), true);
    if (!is_array($attributes)) {
        $attributes = [];
    }

    $normalizedAttributes = ProductVariantMetadata::apply([
        'id' => (string)($row['id'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'brand' => (string)($row['brand'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'gender' => (string)($row['gender'] ?? ''),
        'productType' => (string)($row['product_type'] ?? ''),
    ], $attributes);

    $nextAttributes = $attributes;
    foreach ($fieldsToSync as $field) {
        if (array_key_exists($field, $normalizedAttributes)) {
            $nextAttributes[$field] = $normalizedAttributes[$field];
        } else {
            unset($nextAttributes[$field]);
        }
    }

    $currentGroupKey = trim((string)($attributes['variantGroupKey'] ?? ''));
    $normalizedGroupKey = trim((string)($normalizedAttributes['variantGroupKey'] ?? ''));
    if ($currentGroupKey === '' && $normalizedGroupKey !== '') {
        $currentMode = strtolower(trim((string)($attributes['catalogDisplayMode'] ?? '')));
        if ($currentMode === 'grouped') {
            $nextAttributes['variantGroupKey'] = $normalizedGroupKey;
        }
    } elseif ($currentGroupKey !== '') {
        $nextAttributes['variantGroupKey'] = $currentGroupKey;
    }

    $currentMode = trim((string)($attributes['catalogDisplayMode'] ?? ''));
    $normalizedMode = trim((string)($normalizedAttributes['catalogDisplayMode'] ?? ''));
    if ($currentMode !== '') {
        $nextAttributes['catalogDisplayMode'] = $currentMode;
    } elseif ($normalizedMode !== '' && trim((string)($nextAttributes['variantGroupKey'] ?? '')) !== '') {
        $nextAttributes['catalogDisplayMode'] = $normalizedMode;
    }

    $currentJson = json_encode(normalizeVariantBackfillPayload($attributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $nextJson = json_encode(normalizeVariantBackfillPayload($nextAttributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($currentJson === $nextJson) {
        continue;
    }

    $conflictKey = null;
    $nextGroupKey = trim((string)($nextAttributes['variantGroupKey'] ?? ''));
    $nextVariantLabel = trim((string)($nextAttributes['variantLabel'] ?? ''));
    if (!isArchivedVariantBackfillRow($nextAttributes) && $nextGroupKey !== '' && $nextVariantLabel !== '') {
        $conflictKey = $nextGroupKey . '|' . $nextVariantLabel;
        $proposedVariantKeys[$conflictKey] = ($proposedVariantKeys[$conflictKey] ?? 0) + 1;
    }

    $preparedRows[] = [
        'row' => $row,
        'attributes' => $attributes,
        'nextAttributes' => $nextAttributes,
        'nextJson' => $nextJson,
        'conflictKey' => $conflictKey,
    ];
}

if (!$dryRun && !$db->inTransaction()) {
    $db->beginTransaction();
}

try {
    foreach ($preparedRows as $item) {
        $conflictKey = $item['conflictKey'];
        if ($conflictKey !== null && ($proposedVariantKeys[$conflictKey] ?? 0) > 1) {
            $skippedConflicts++;
            continue;
        }

        $changed++;
        if ($dryRun) {
            echo json_encode([
                'id' => (string)($item['row']['id'] ?? ''),
                'name' => (string)($item['row']['name'] ?? ''),
                'definition_before' => (string)($item['attributes']['variantDefinitionField'] ?? ''),
                'definition_after' => (string)($item['nextAttributes']['variantDefinitionField'] ?? ''),
                'axis_before' => (string)($item['attributes']['variantAxis'] ?? ''),
                'axis_after' => (string)($item['nextAttributes']['variantAxis'] ?? ''),
                'label_before' => (string)($item['attributes']['variantLabel'] ?? ''),
                'label_after' => (string)($item['nextAttributes']['variantLabel'] ?? ''),
                'group_preserved' => (string)($item['attributes']['variantGroupKey'] ?? '') === (string)($item['nextAttributes']['variantGroupKey'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            continue;
        }

        $update->execute([
            'id' => (string)($item['row']['id'] ?? ''),
            'tenant_id' => $tenantId,
            'attributes' => $item['nextJson'],
        ]);
    }

    if (!$dryRun && $db->inTransaction()) {
        $db->commit();
    }
} catch (Throwable $exception) {
    if (!$dryRun && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Backfill fallido: {$exception->getMessage()}\n");
    exit(1);
}

echo "Tenant: {$tenantId}\n";
echo "Productos revisados: {$reviewed}\n";
echo "Productos normalizados: {$changed}\n";
echo "Productos omitidos por conflicto: {$skippedConflicts}\n";
echo $dryRun ? "Modo: dry-run\n" : "Modo: aplicado\n";
