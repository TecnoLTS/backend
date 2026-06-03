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

$options = getopt('', ['tenant::', 'dry-run', 'include-separate']);
$tenantId = trim((string)($options['tenant'] ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec')));
$dryRun = array_key_exists('dry-run', $options);
$includeSeparate = array_key_exists('include-separate', $options);

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
    SELECT id, name, brand, category, gender, product_type, is_published, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
      AND product_type = \'accesorios\'
      AND COALESCE(NULLIF(trim(attributes->>\'color\'), \'\'), \'\') <> \'\'
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

$preparedRows = [];
$proposedKeys = [];
$reviewed = 0;
$updated = 0;
$keptGroupKeyForConflict = 0;

foreach ($rows as $row) {
    $reviewed++;
    $attributes = json_decode((string)($row['attributes'] ?? '{}'), true);
    if (!is_array($attributes)) {
        $attributes = [];
    }

    $repairAttributes = $attributes;
    if ($includeSeparate && strtolower(trim((string)($repairAttributes['catalogDisplayMode'] ?? ''))) === 'separate') {
        $repairAttributes['catalogDisplayMode'] = 'grouped';
    }

    $normalizedAttributes = ProductVariantMetadata::apply([
        'name' => (string)($row['name'] ?? ''),
        'brand' => (string)($row['brand'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'gender' => (string)($row['gender'] ?? ''),
        'productType' => (string)($row['product_type'] ?? ''),
    ], $repairAttributes);

    if (
        ($normalizedAttributes['displayAxis'] ?? '') !== 'color'
        || ($normalizedAttributes['catalogDisplayMode'] ?? '') !== 'grouped'
    ) {
        continue;
    }

    $isArchived = strtolower(trim((string)($attributes['archived'] ?? 'false'))) === 'true';
    $proposedGroupKey = trim((string)($normalizedAttributes['variantGroupKey'] ?? ''));
    $proposedVariantLabel = trim((string)($normalizedAttributes['variantLabel'] ?? ''));
    $conflictKey = null;

    if (!$isArchived && $proposedGroupKey !== '' && $proposedVariantLabel !== '') {
        $conflictKey = $proposedGroupKey . '|' . $proposedVariantLabel;
        $proposedKeys[$conflictKey] = ($proposedKeys[$conflictKey] ?? 0) + 1;
    }

    $preparedRows[] = [
        'row' => $row,
        'attributes' => $attributes,
        'normalizedAttributes' => $normalizedAttributes,
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
        $nextAttributes = $attributes;

        foreach (['displayAxis', 'catalogDisplayMode', 'variantLabel', 'variantBaseName'] as $key) {
            if (array_key_exists($key, $normalizedAttributes)) {
                $nextAttributes[$key] = $normalizedAttributes[$key];
            }
        }

        $conflictKey = $item['conflictKey'];
        if ($conflictKey !== null && ($proposedKeys[$conflictKey] ?? 0) > 1) {
            $keptGroupKeyForConflict++;
        } elseif (isset($normalizedAttributes['variantGroupKey'])) {
            $nextAttributes['variantGroupKey'] = $normalizedAttributes['variantGroupKey'];
        }

        $currentAttributesJson = json_encode(normalizeRepairPayload($attributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nextAttributesJson = json_encode(normalizeRepairPayload($nextAttributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($currentAttributesJson === $nextAttributesJson) {
            continue;
        }

        $updated++;
        if ($dryRun) {
            echo json_encode([
                'id' => (string)($item['row']['id'] ?? ''),
                'name' => (string)($item['row']['name'] ?? ''),
                'color' => (string)($attributes['color'] ?? ''),
                'size' => (string)($attributes['size'] ?? ''),
                'display_axis_before' => (string)($attributes['displayAxis'] ?? ''),
                'display_axis_after' => (string)($nextAttributes['displayAxis'] ?? ''),
                'mode_before' => (string)($attributes['catalogDisplayMode'] ?? ''),
                'mode_after' => (string)($nextAttributes['catalogDisplayMode'] ?? ''),
                'base_before' => (string)($attributes['variantBaseName'] ?? ''),
                'base_after' => (string)($nextAttributes['variantBaseName'] ?? ''),
                'group_key_updated' => isset($normalizedAttributes['variantGroupKey'])
                    && (string)($nextAttributes['variantGroupKey'] ?? '') === (string)$normalizedAttributes['variantGroupKey'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            continue;
        }

        $update->execute([
            'id' => (string)($item['row']['id'] ?? ''),
            'tenant_id' => $tenantId,
            'attributes' => json_encode($nextAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
echo "Accesorios con color revisados: {$reviewed}\n";
echo "Productos corregidos: {$updated}\n";
echo "Group keys conservados por duplicado historico: {$keptGroupKeyForConflict}\n";
echo $dryRun ? "Modo: dry-run\n" : "Modo: aplicado\n";
