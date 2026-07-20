<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
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
$db = Database::getInstance();

function repairNormalizeText(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $ascii = strtr($value, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
        'ñ' => 'n', 'Ñ' => 'n',
    ]);
    $ascii = preg_replace('/[^a-z0-9]+/i', ' ', $ascii) ?? $ascii;

    return strtolower(trim((string)(preg_replace('/\s+/', ' ', $ascii) ?? $ascii)));
}

function repairSlugify(string $value): string
{
    return str_replace(' ', '-', repairNormalizeText($value)) ?: 'group';
}

function repairNormalizePayload(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = repairNormalizePayload($item);
    }

    $keys = array_keys($value);
    if ($keys !== range(0, count($value) - 1)) {
        ksort($value);
    }

    return $value;
}

function repairIsSizeValue(string $value): bool
{
    return preg_match('/^(?:XXS|XS|S|M|L|XL|XXL|STANDARD|\d+(?:[.,]\d+)?\s?CM|X?\d+)$/iu', trim($value)) === 1;
}

function repairFlexibleSuffixPattern(string $label): string
{
    $label = ProductFieldValueNormalizer::normalizeDisplayValue($label);
    if ($label === '') {
        return '';
    }

    $escaped = preg_quote($label, '/');
    $escaped = preg_replace('/\s+/', '\\s*', $escaped) ?? $escaped;

    return str_replace('\-', '\s*-\s*', $escaped);
}

function repairStripSizeSuffix(string $name, string $size): string
{
    $baseName = trim($name);
    $pattern = repairFlexibleSuffixPattern($size);
    if ($baseName === '' || $pattern === '') {
        return $baseName;
    }

    $separator = preg_match('/^(XXS|XS|S|M|L|XL|XXL|STANDARD)$/iu', $size) === 1
        ? '(?:\s+|-)'
        : '(?:\s+|-)?';

    return trim((string)(preg_replace('/' . $separator . $pattern . '$/iu', '', $baseName) ?? $baseName)) ?: trim($name);
}

function repairPluralizeSpanishColor(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return $value;
    }

    return preg_match('/[aeiouáéíóú]$/iu', $value) === 1 ? $value . 's' : $value;
}

function repairColorAliases(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $genderPairs = [
        'amarillo' => 'amarilla',
        'amarilla' => 'amarillo',
        'blanco' => 'blanca',
        'blanca' => 'blanco',
        'morado' => 'morada',
        'morada' => 'morado',
        'negro' => 'negra',
        'negra' => 'negro',
        'rosa' => 'rosado',
        'rosado' => 'rosa',
        'rosada' => 'rosa',
        'rojo' => 'roja',
        'roja' => 'rojo',
    ];

    $aliases = [$value];
    $identity = repairNormalizeText($value);
    if (isset($genderPairs[$identity])) {
        $aliases[] = $genderPairs[$identity];
        $aliases[] = mb_convert_case($genderPairs[$identity], MB_CASE_TITLE, 'UTF-8');
    }

    return array_values(array_unique($aliases));
}

function repairDisplayColorAliases(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    if (!str_contains($value, '/')) {
        return repairColorAliases($value);
    }

    $parts = array_values(array_filter(array_map(
        static fn(string $part): string => trim($part),
        explode('/', $value)
    )));
    if (count($parts) < 2) {
        return [$value];
    }

    [$primary, $secondary] = [$parts[0], $parts[1]];
    $aliases = [$value];
    foreach (repairColorAliases($primary) as $primaryAlias) {
        foreach (repairColorAliases($secondary) as $secondaryAlias) {
            $aliases[] = $primaryAlias . ' ' . $secondaryAlias;
            $aliases[] = $primaryAlias . ' con Detalles ' . $secondaryAlias;
            $aliases[] = $primaryAlias . ' con detalles ' . $secondaryAlias;
            $aliases[] = $primaryAlias . ' con Detalles ' . repairPluralizeSpanishColor($secondaryAlias);
            $aliases[] = $primaryAlias . ' con detalles ' . repairPluralizeSpanishColor($secondaryAlias);
        }
    }

    return array_values(array_unique($aliases));
}

function repairBaseNameForColorSize(string $name, string $color, string $size): string
{
    $baseName = repairStripSizeSuffix($name, $size);
    $baseIdentity = repairNormalizeText($baseName);
    foreach (repairDisplayColorAliases($color) as $alias) {
        if ($alias !== '' && str_contains($baseIdentity, repairNormalizeText($alias))) {
            return $baseName;
        }
    }

    return trim($baseName . ' ' . $color);
}

function repairGroupKey(array $row, array $attributes, string $baseName): string
{
    $parts = array_filter([
        trim((string)($row['brand'] ?? '')),
        trim((string)($row['category'] ?? '')),
        trim((string)($row['gender'] ?? '')),
        $baseName,
        trim((string)($attributes['target'] ?? '')),
        trim((string)($attributes['flavor'] ?? '')),
        trim((string)($attributes['line'] ?? '')),
        trim((string)($attributes['species'] ?? '')),
    ], static fn(string $value): bool => $value !== '');

    return repairSlugify(implode('|', $parts));
}

$select = $db->prepare('
    SELECT id, name, brand, category, gender, product_type, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
      AND product_type IN (\'ropa\', \'accesorios\')
      AND COALESCE(NULLIF(trim(attributes->>\'color\'), \'\'), \'\') <> \'\'
      AND COALESCE(NULLIF(trim(attributes->>\'size\'), \'\'), \'\') <> \'\'
      AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'
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

$groups = [];
$reviewed = 0;

foreach ($rows as $row) {
    $attributes = json_decode((string)($row['attributes'] ?? '{}'), true);
    if (!is_array($attributes)) {
        $attributes = [];
    }

    $size = ProductFieldValueNormalizer::normalizeDisplayValue((string)($attributes['size'] ?? ''));
    $color = trim((string)($attributes['color'] ?? ''));
    if ($size === '' || $color === '' || !repairIsSizeValue($size)) {
        continue;
    }

    $reviewed++;
    $baseName = repairBaseNameForColorSize((string)($row['name'] ?? ''), $color, $size);
    $groupKey = repairGroupKey($row, $attributes, $baseName);
    $groups[$groupKey][] = [
        'row' => $row,
        'attributes' => $attributes,
        'baseName' => $baseName,
        'groupKey' => $groupKey,
        'size' => $size,
    ];
}

$updates = [];
$skippedDuplicateGroups = 0;
$candidateGroups = 0;

foreach ($groups as $groupRows) {
    $sizes = [];
    $hasSizeAxis = false;
    foreach ($groupRows as $item) {
        $sizes[repairNormalizeText($item['size'])] = true;
        $attributes = $item['attributes'];
        $row = $item['row'];
        $axis = strtolower(trim((string)($attributes['displayAxis'] ?? $attributes['variantAxis'] ?? $attributes['variantDefinitionField'] ?? '')));
        $type = strtolower(trim((string)($row['product_type'] ?? '')));
        $hasSizeAxis = $hasSizeAxis || $axis === 'size' || $type === 'ropa';
    }
    if (count($sizes) < 2 && !$hasSizeAxis) {
        continue;
    }

    $candidateGroups++;
    $duplicateLabels = false;
    $labels = [];
    foreach ($groupRows as $item) {
        $labelKey = repairNormalizeText($item['size']);
        if (isset($labels[$labelKey])) {
            $duplicateLabels = true;
            break;
        }
        $labels[$labelKey] = true;
    }
    if ($duplicateLabels) {
        $skippedDuplicateGroups++;
        continue;
    }

    foreach ($groupRows as $item) {
        $attributes = $item['attributes'];
        $nextAttributes = $attributes;
        $nextAttributes['size'] = $item['size'];
        $nextAttributes['displayAxis'] = 'size';
        $nextAttributes['variantAxis'] = 'size';
        $nextAttributes['variantDefinitionField'] = 'size';
        $nextAttributes['catalogDisplayMode'] = 'grouped';
        $nextAttributes['variantLabel'] = ProductFieldValueNormalizer::normalizeVariantLabelValue($item['size']);
        $nextAttributes['variantBaseName'] = $item['baseName'];
        $nextAttributes['variantGroupKey'] = $item['groupKey'];

        $currentJson = json_encode(repairNormalizePayload($attributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nextJson = json_encode(repairNormalizePayload($nextAttributes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($currentJson === $nextJson) {
            continue;
        }

        $updates[] = [
            'id' => (string)($item['row']['id'] ?? ''),
            'name' => (string)($item['row']['name'] ?? ''),
            'before' => $attributes,
            'after' => $nextAttributes,
        ];
    }
}

if (!$dryRun && count($updates) > 0 && !$db->inTransaction()) {
    $db->beginTransaction();
}

try {
    foreach ($updates as $item) {
        if ($dryRun) {
            echo json_encode([
                'id' => $item['id'],
                'name' => $item['name'],
                'mode_before' => (string)($item['before']['catalogDisplayMode'] ?? ''),
                'mode_after' => (string)($item['after']['catalogDisplayMode'] ?? ''),
                'axis_before' => (string)($item['before']['displayAxis'] ?? ''),
                'axis_after' => (string)($item['after']['displayAxis'] ?? ''),
                'label_before' => (string)($item['before']['variantLabel'] ?? ''),
                'label_after' => (string)($item['after']['variantLabel'] ?? ''),
                'base_before' => (string)($item['before']['variantBaseName'] ?? ''),
                'base_after' => (string)($item['after']['variantBaseName'] ?? ''),
                'group_before' => (string)($item['before']['variantGroupKey'] ?? ''),
                'group_after' => (string)($item['after']['variantGroupKey'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            continue;
        }

        $update->execute([
            'id' => $item['id'],
            'tenant_id' => $tenantId,
            'attributes' => json_encode($item['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
echo "Productos revisados con color y talla: {$reviewed}\n";
echo "Grupos candidatos con varias tallas: {$candidateGroups}\n";
echo "Grupos omitidos por talla duplicada: {$skippedDuplicateGroups}\n";
echo "Productos corregidos: " . count($updates) . "\n";
echo $dryRun ? "Modo: dry-run\n" : "Modo: aplicado\n";
