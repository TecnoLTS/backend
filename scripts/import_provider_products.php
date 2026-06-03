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

$options = getopt('', ['tenant::', 'file::', 'replace', 'dry-run']);
$tenantId = trim((string)($options['tenant'] ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec')));
$defaultFile = __DIR__ . '/../storage/imports/agripac_catalog_2025_03_products.json';
$filePath = trim((string)($options['file'] ?? $defaultFile));
$replace = array_key_exists('replace', $options);
$dryRun = array_key_exists('dry-run', $options);

$tenants = require __DIR__ . '/../config/tenants.php';
if (!isset($tenants[$tenantId])) {
    fwrite(STDERR, "Tenant no configurado: {$tenantId}\n");
    exit(1);
}
if (!is_file($filePath)) {
    fwrite(STDERR, "Archivo de dataset no encontrado: {$filePath}\n");
    exit(1);
}

$raw = file_get_contents($filePath);
$items = json_decode((string)$raw, true);
if (!is_array($items)) {
    fwrite(STDERR, "Dataset invalido: {$filePath}\n");
    exit(1);
}

TenantContext::set($tenants[$tenantId]);
$db = Database::getInstance();

$normalizeBool = static function ($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
};

$normalizeNumber = static function ($value, float $default = 0.0): float {
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        return $default;
    }
    return round((float)$value, 2);
};

$normalizeInt = static function ($value, int $default = 0): int {
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        return $default;
    }
    return max(0, (int)$value);
};

$normalizeString = static function ($value, string $default = ''): string {
    $normalized = trim((string)$value);
    return $normalized !== '' ? $normalized : $default;
};

$normalizeImages = static function ($images, string $fallback): array {
    $entries = [];
    if (is_array($images)) {
        foreach ($images as $image) {
            if (is_string($image)) {
                $url = trim($image);
                if ($url !== '') {
                    $entries[] = [
                        'url' => $url,
                        'width' => null,
                        'height' => null,
                    ];
                }
                continue;
            }
            if (is_array($image)) {
                $url = trim((string)($image['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $entries[] = [
                    'url' => $url,
                    'width' => isset($image['width']) && is_numeric($image['width']) ? (int)$image['width'] : null,
                    'height' => isset($image['height']) && is_numeric($image['height']) ? (int)$image['height'] : null,
                ];
            }
        }
    }
    if ($entries === []) {
        $entries[] = [
            'url' => $fallback,
            'width' => null,
            'height' => null,
        ];
    }
    return $entries;
};

$placeholderByGender = static function (?string $gender, string $category): string {
    if ($category === 'cuidado') {
        return '/images/product/3.jpg';
    }
    return $gender === 'cat' ? '/images/product/2.jpg' : '/images/product/1.jpg';
};

$selectExisting = $db->prepare('
    SELECT id
    FROM "Product"
    WHERE tenant_id = :tenant_id AND legacy_id = :legacy_id
    LIMIT 1
');

$insertProduct = $db->prepare('
    INSERT INTO "Product" (
        id,
        legacy_id,
        tenant_id,
        category,
        product_type,
        name,
        gender,
        is_new,
        is_sale,
        is_published,
        price,
        original_price,
        cost,
        brand,
        sold,
        quantity,
        description,
        action,
        slug,
        attributes,
        created_at,
        updated_at
    ) VALUES (
        :id,
        :legacy_id,
        :tenant_id,
        :category,
        :product_type,
        :name,
        :gender,
        :is_new,
        :is_sale,
        :is_published,
        :price,
        :original_price,
        :cost,
        :brand,
        :sold,
        :quantity,
        :description,
        :action,
        :slug,
        :attributes,
        NOW(),
        NOW()
    )
');

$updateProduct = $db->prepare('
    UPDATE "Product"
    SET
        category = :category,
        product_type = :product_type,
        name = :name,
        gender = :gender,
        is_new = :is_new,
        is_sale = :is_sale,
        is_published = :is_published,
        price = :price,
        original_price = :original_price,
        cost = :cost,
        brand = :brand,
        sold = :sold,
        quantity = :quantity,
        description = :description,
        action = :action,
        slug = :slug,
        attributes = :attributes,
        updated_at = NOW()
    WHERE id = :id AND tenant_id = :tenant_id
');

$deleteImages = $db->prepare('DELETE FROM "Image" WHERE product_id = :product_id');
$insertImage = $db->prepare('
    INSERT INTO "Image" (id, url, product_id, kind, width, height)
    VALUES (:id, :url, :product_id, :kind, :width, :height)
');

$deleteTenantImages = $db->prepare('
    DELETE FROM "Image"
    WHERE product_id IN (
        SELECT id FROM "Product" WHERE tenant_id = :tenant_id
    )
');
$deleteTenantVariations = $db->prepare('
    DELETE FROM "Variation"
    WHERE product_id IN (
        SELECT id FROM "Product" WHERE tenant_id = :tenant_id
    )
');
$deleteTenantProducts = $db->prepare('DELETE FROM "Product" WHERE tenant_id = :tenant_id');

$inserted = 0;
$updated = 0;
$processed = 0;

if ($dryRun) {
    echo "Dry run tenant={$tenantId} file={$filePath} replace=" . ($replace ? 'yes' : 'no') . "\n";
    echo "Productos listos para importar: " . count($items) . "\n";
    exit(0);
}

$db->beginTransaction();

try {
    if ($replace) {
        $deleteTenantImages->execute(['tenant_id' => $tenantId]);
        $deleteTenantVariations->execute(['tenant_id' => $tenantId]);
        $deleteTenantProducts->execute(['tenant_id' => $tenantId]);
    }

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $item = CatalogProductTextNormalizer::normalizeItem($item);

        $legacyId = $normalizeString($item['legacyId'] ?? '', '');
        $name = $normalizeString($item['name'] ?? '', '');
        if ($legacyId === '' || $name === '') {
            continue;
        }

        $gender = $normalizeString($item['gender'] ?? '', '');
        $category = $normalizeString($item['category'] ?? '', 'General');
        $productType = $normalizeString($item['productType'] ?? '', 'accesorios');
        $brand = $normalizeString($item['brand'] ?? '', 'Generico');
        $description = $normalizeString($item['description'] ?? '', $name);
        $action = $normalizeString($item['action'] ?? '', 'view');
        $slug = $normalizeString($item['slug'] ?? '', strtolower(preg_replace('/[^a-z0-9]+/i', '-', $legacyId . '-' . $name) ?? ''));
        $price = $normalizeNumber($item['price'] ?? 0);
        $originPrice = $normalizeNumber($item['originPrice'] ?? $price, $price);
        $cost = $normalizeNumber($item['cost'] ?? 0);
        $sold = $normalizeInt($item['sold'] ?? 0);
        $quantity = $normalizeInt($item['quantity'] ?? 0);
        $isNew = $normalizeBool($item['new'] ?? false);
        $isSale = $normalizeBool($item['sale'] ?? false);
        $isPublished = $normalizeBool($item['published'] ?? true);
        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];

        $fallbackImage = $placeholderByGender($gender !== '' ? $gender : null, $category);
        $thumbImages = $normalizeImages($item['thumbImages'] ?? [], $fallbackImage);
        $galleryImages = $normalizeImages($item['galleryImages'] ?? [], $fallbackImage);

        if ($originPrice < $price) {
            $originPrice = $price;
        }
        if ($cost > $price) {
            $cost = $price;
        }

        $params = [
            'tenant_id' => $tenantId,
            'legacy_id' => $legacyId,
            'category' => $category,
            'product_type' => $productType,
            'name' => $name,
            'gender' => $gender !== '' ? $gender : null,
            'is_new' => $isNew ? 'true' : 'false',
            'is_sale' => $isSale ? 'true' : 'false',
            'is_published' => $isPublished ? 'true' : 'false',
            'price' => number_format($price, 2, '.', ''),
            'original_price' => number_format($originPrice, 2, '.', ''),
            'cost' => number_format($cost, 2, '.', ''),
            'brand' => $brand,
            'sold' => $sold,
            'quantity' => $quantity,
            'description' => $description,
            'action' => $action,
            'slug' => $slug,
            'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $selectExisting->execute([
            'tenant_id' => $tenantId,
            'legacy_id' => $legacyId,
        ]);
        $existing = $selectExisting->fetch();

        if ($existing) {
            $productId = (string)$existing['id'];
            $updateProduct->execute($params + [
                'id' => $productId,
            ]);
            $updated++;
        } else {
            $productId = uniqid('prod_imp_', true);
            $insertProduct->execute($params + [
                'id' => $productId,
            ]);
            $inserted++;
        }

        $deleteImages->execute(['product_id' => $productId]);
        foreach ($galleryImages as $image) {
            $insertImage->execute([
                'id' => uniqid('img_', true),
                'url' => $image['url'],
                'product_id' => $productId,
                'kind' => 'gallery',
                'width' => $image['width'],
                'height' => $image['height'],
            ]);
        }
        foreach ($thumbImages as $image) {
            $insertImage->execute([
                'id' => uniqid('img_', true),
                'url' => $image['url'],
                'product_id' => $productId,
                'kind' => 'thumb',
                'width' => $image['width'],
                'height' => $image['height'],
            ]);
        }

        $processed++;
    }

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Importacion fallida: {$exception->getMessage()}\n");
    exit(1);
}

echo "Tenant: {$tenantId}\n";
echo "Dataset: {$filePath}\n";
echo "Procesados: {$processed}\n";
echo "Insertados: {$inserted}\n";
echo "Actualizados: {$updated}\n";
