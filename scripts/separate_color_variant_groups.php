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

TenantContext::set($tenants[$tenantId]);
$db = Database::getInstance();

$select = $db->prepare('
    SELECT id, name, tenant_id, attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
      AND attributes IS NOT NULL
      AND COALESCE(attributes->>\'variantGroupKey\', \'\') <> \'\'
    ORDER BY attributes->>\'variantGroupKey\' ASC, name ASC, id ASC
');

$update = $db->prepare('
    UPDATE "Product"
    SET attributes = :attributes::jsonb,
        updated_at = NOW()
    WHERE id = :id
      AND tenant_id = :tenant_id
');

$normalize = static function (?string $value): string {
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

    $upper = strtoupper($ascii);
    $upper = preg_replace('/\s+/', ' ', $upper) ?? $upper;

    return trim($upper);
};

$select->execute(['tenant_id' => $tenantId]);
$rows = $select->fetchAll() ?: [];

$groups = [];
foreach ($rows as $row) {
    $attributes = json_decode((string)($row['attributes'] ?? '{}'), true);
    if (!is_array($attributes)) {
        $attributes = [];
    }

    $groupKey = trim((string)($attributes['variantGroupKey'] ?? ''));
    if ($groupKey === '') {
        continue;
    }

    $groups[$groupKey][] = [
        'id' => (string)($row['id'] ?? ''),
        'name' => trim((string)($row['name'] ?? '')),
        'tenant_id' => (string)($row['tenant_id'] ?? ''),
        'attributes' => $attributes,
    ];
}

$productsToUpdate = [];
$reports = [];

foreach ($groups as $groupKey => $groupRows) {
    if (count($groupRows) < 2) {
        continue;
    }

    $distinctColors = [];
    $allMatchColorAxis = true;

    foreach ($groupRows as $groupRow) {
        $attributes = $groupRow['attributes'];
        $color = $normalize((string)($attributes['color'] ?? ''));
        $label = $normalize((string)($attributes['variantLabel'] ?? ''));

        if ($color === '' || $label === '' || $color !== $label) {
            $allMatchColorAxis = false;
            break;
        }

        $distinctColors[$color] = true;
    }

    if (!$allMatchColorAxis || count($distinctColors) < 2) {
        continue;
    }

    foreach ($groupRows as $groupRow) {
        $attributes = $groupRow['attributes'];
        $beforeBaseName = (string)($attributes['variantBaseName'] ?? '');
        $beforeGroupKey = (string)($attributes['variantGroupKey'] ?? '');

        $attributes['variantBaseName'] = $groupRow['name'];
        $attributes['variantGroupKey'] = 'single:' . $groupRow['id'];

        $productsToUpdate[] = [
            'id' => $groupRow['id'],
            'tenant_id' => $groupRow['tenant_id'],
            'attributes' => $attributes,
        ];

        $reports[] = [
            'id' => $groupRow['id'],
            'name' => $groupRow['name'],
            'color' => (string)($groupRow['attributes']['color'] ?? ''),
            'variant_label' => (string)($groupRow['attributes']['variantLabel'] ?? ''),
            'group_before' => $beforeGroupKey,
            'group_after' => 'single:' . $groupRow['id'],
            'base_before' => $beforeBaseName,
            'base_after' => $groupRow['name'],
        ];
    }
}

$reportDir = __DIR__ . '/../storage/repairs';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}

$reportPath = sprintf(
    '%s/color_variant_group_separation_%s_%s.json',
    $reportDir,
    $tenantId,
    date('Ymd_His')
);

file_put_contents(
    $reportPath,
    json_encode([
        'tenant' => $tenantId,
        'dry_run' => $dryRun,
        'updated_count' => count($productsToUpdate),
        'products' => $reports,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

if (!$dryRun && count($productsToUpdate) > 0 && !$db->inTransaction()) {
    $db->beginTransaction();
}

try {
    if (!$dryRun) {
        foreach ($productsToUpdate as $product) {
            $update->execute([
                'id' => $product['id'],
                'tenant_id' => $product['tenant_id'],
                'attributes' => json_encode($product['attributes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    if (!$dryRun && $db->inTransaction()) {
        $db->commit();
    }
} catch (Throwable $exception) {
    if (!$dryRun && $db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, "Separación fallida: {$exception->getMessage()}\n");
    exit(1);
}

echo "Tenant: {$tenantId}\n";
echo "Productos separados por color: " . count($productsToUpdate) . "\n";
echo "Reporte: {$reportPath}\n";
echo $dryRun ? "Modo: dry-run\n" : "Modo: aplicado\n";
