<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Repositories\ProductReferenceCatalogRepository;
use App\Repositories\SettingsRepository;
use App\Support\ProductFieldValueNormalizer;
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

function normalizeListValues(array $values): array
{
    $normalized = [];
    $seen = [];

    foreach ($values as $value) {
        $text = ProductFieldValueNormalizer::normalizeDisplayValue((string)$value);
        if ($text === '') {
            continue;
        }

        $key = function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $normalized[] = $text;
    }

    return $normalized;
}

function normalizeStructuredValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = normalizeStructuredValue($item);
    }

    $keys = array_keys($value);
    $isList = $keys === range(0, count($value) - 1);
    if (!$isList) {
        ksort($value);
    }

    return $value;
}

function normalizeProductAttributes(array $attributes): array
{
    $normalized = ProductFieldValueNormalizer::normalizeVariantAttributeMap($attributes);

    $variantLabel = ProductFieldValueNormalizer::normalizeVariantLabelValue((string)($attributes['variantLabel'] ?? ''));

    if ($variantLabel === '') {
        unset($normalized['variantLabel']);
    } else {
        $normalized['variantLabel'] = $variantLabel;
    }

    return $normalized;
}

$db = Database::getInstance();
$catalogRepository = new ProductReferenceCatalogRepository();
$settingsRepository = new SettingsRepository();

$catalogBefore = $catalogRepository->getAll();
$catalogAfter = $catalogBefore;
$catalogAfter['sizes'] = normalizeListValues($catalogBefore['sizes'] ?? []);
$catalogAfter['presentations'] = normalizeListValues($catalogBefore['presentations'] ?? []);

$legacyBefore = $settingsRepository->getJson('product_reference_data', []);
$legacyAfter = is_array($legacyBefore) ? $legacyBefore : [];
if ($legacyAfter !== []) {
    if (isset($legacyAfter['sizes']) && is_array($legacyAfter['sizes'])) {
        $legacyAfter['sizes'] = normalizeListValues($legacyAfter['sizes']);
    }
    if (isset($legacyAfter['presentations']) && is_array($legacyAfter['presentations'])) {
        $legacyAfter['presentations'] = normalizeListValues($legacyAfter['presentations']);
    }
}

$selectProducts = $db->prepare('
    SELECT id, name, category, product_type, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
    ORDER BY id ASC
');
$updateProduct = $db->prepare('
    UPDATE "Product"
    SET attributes = :attributes::jsonb,
        updated_at = NOW()
    WHERE tenant_id = :tenant_id
      AND id = :id
');

$selectProducts->execute(['tenant_id' => $tenantId]);
$rows = $selectProducts->fetchAll() ?: [];

$productReviewed = 0;
$productUpdated = 0;
$catalogUpdated = normalizeStructuredValue($catalogBefore) !== normalizeStructuredValue($catalogAfter);
$legacyUpdated = normalizeStructuredValue($legacyBefore) !== normalizeStructuredValue($legacyAfter);
$samples = [];

if (!$dryRun && !$db->inTransaction()) {
    $db->beginTransaction();
}

try {
    foreach ($rows as $row) {
        $productReviewed++;
        $attributes = json_decode((string)($row['attributes'] ?? '{}'), true);
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $normalized = normalizeProductAttributes($attributes);
        $beforeJson = json_encode(normalizeStructuredValue($attributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $afterJson = json_encode(normalizeStructuredValue($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($beforeJson === $afterJson) {
            continue;
        }

        $productUpdated++;
        if (count($samples) < 25) {
            $samples[] = [
                'id' => (string)($row['id'] ?? ''),
                'name' => (string)($row['name'] ?? ''),
                'size_before' => (string)($attributes['size'] ?? ''),
                'size_after' => (string)($normalized['size'] ?? ''),
                'variant_before' => (string)($attributes['variantLabel'] ?? ''),
                'variant_after' => (string)($normalized['variantLabel'] ?? ''),
            ];
        }

        if ($dryRun) {
            continue;
        }

        $updateProduct->execute([
            'tenant_id' => $tenantId,
            'id' => (string)($row['id'] ?? ''),
            'attributes' => $afterJson,
        ]);
    }

    if (!$dryRun) {
        if ($catalogUpdated) {
            $catalogRepository->replaceAll($catalogAfter);
        }

        if ($legacyUpdated) {
            $settingsRepository->setJson('product_reference_data', $legacyAfter);
        }
    }

    if (!$dryRun && $db->inTransaction()) {
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
echo "Catalogo actualizado: " . ($catalogUpdated ? 'si' : 'no') . "\n";
echo "Legacy setting actualizado: " . ($legacyUpdated ? 'si' : 'no') . "\n";
echo "Productos revisados: {$productReviewed}\n";
echo "Productos corregidos: {$productUpdated}\n";
echo $dryRun ? "Modo: dry-run\n" : "Modo: aplicado\n";

foreach ($samples as $sample) {
    echo json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
