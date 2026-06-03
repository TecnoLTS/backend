<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Support\CatalogProductTextNormalizer;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    $dotenv = Dotenv::createImmutable($envDir);
    $dotenv->load();
}

$options = getopt('', ['tenant::', 'dry-run']);
$tenantId = trim((string)($options['tenant'] ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec')));
$dryRun = array_key_exists('dry-run', $options);

$tenants = require __DIR__ . '/../config/tenants.php';
if (!isset($tenants[$tenantId])) {
    fwrite(STDERR, "Tenant no configurado: {$tenantId}\n");
    exit(1);
}

$datasetPaths = [
    __DIR__ . '/../storage/imports/agripac_catalog_2025_03_products.json',
    __DIR__ . '/../storage/imports/agripac_portafolio_2025_07_zero_prices.json',
];

$catalog = [];
foreach ($datasetPaths as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Dataset no encontrado: {$path}\n");
        exit(1);
    }

    $raw = file_get_contents($path);
    $items = json_decode((string)$raw, true);
    if (!is_array($items)) {
        fwrite(STDERR, "Dataset invalido: {$path}\n");
        exit(1);
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $legacyId = trim((string)($item['legacyId'] ?? ''));
        if ($legacyId === '') {
            continue;
        }
        $catalog[$legacyId] = CatalogProductTextNormalizer::normalizeItem($item);
    }
}

TenantContext::set($tenants[$tenantId]);
$db = Database::getInstance();

$selectProducts = $db->prepare('
    SELECT id, legacy_id, name, description
    FROM "Product"
    WHERE tenant_id = :tenant_id
    ORDER BY legacy_id
');

$updateProduct = $db->prepare('
    UPDATE "Product"
    SET name = :name, description = :description, updated_at = NOW()
    WHERE id = :id AND tenant_id = :tenant_id
');

$selectProducts->execute(['tenant_id' => $tenantId]);
$rows = $selectProducts->fetchAll();

$updated = 0;
$skipped = 0;
$missing = 0;
$changes = [];

if (!$dryRun) {
    $db->beginTransaction();
}

try {
    foreach ($rows as $row) {
        $legacyId = trim((string)($row['legacy_id'] ?? ''));
        if ($legacyId === '' || !isset($catalog[$legacyId])) {
            $missing++;
            continue;
        }

        $normalized = $catalog[$legacyId];
        $nextName = trim((string)($normalized['name'] ?? ''));
        $nextDescription = trim((string)($normalized['description'] ?? ''));

        if ($nextName === '' || $nextDescription === '') {
            $skipped++;
            continue;
        }

        $currentName = trim((string)($row['name'] ?? ''));
        $currentDescription = trim((string)($row['description'] ?? ''));
        if ($currentName === $nextName && $currentDescription === $nextDescription) {
            $skipped++;
            continue;
        }

        if (!$dryRun) {
            $updateProduct->execute([
                'id' => $row['id'],
                'tenant_id' => $tenantId,
                'name' => $nextName,
                'description' => $nextDescription,
            ]);
        }

        $changes[] = [
            'legacy_id' => $legacyId,
            'before_name' => $currentName,
            'after_name' => $nextName,
        ];
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
echo "Productos revisados: " . count($rows) . "\n";
echo "Actualizados: {$updated}\n";
echo "Sin cambios: {$skipped}\n";
echo "Sin dataset: {$missing}\n";

if ($changes !== []) {
    echo "Muestras:\n";
    foreach (array_slice($changes, 0, 12) as $change) {
        echo "- {$change['legacy_id']}: {$change['before_name']} => {$change['after_name']}\n";
    }
}
