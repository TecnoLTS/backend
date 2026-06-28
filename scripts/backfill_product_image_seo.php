<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

$apply = in_array('--apply', $argv, true);
$dryRun = !$apply;
$tenantArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantArg = trim(substr($arg, strlen('--tenant=')));
    }
}

$tenantId = $tenantArg ?: ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
$defaultPublicDir = realpath(__DIR__ . '/../../webparamascotas/app/public') ?: (__DIR__ . '/../../webparamascotas/app/public');
$publicDir = rtrim((string)($_ENV['UPLOADS_PUBLIC_DIR'] ?? $defaultPublicDir), '/');

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_PORT'] ?? '5432',
    $_ENV['DB_DATABASE'] ?? 'paramascotasec'
);
$db = new PDO($dsn, $_ENV['DB_USERNAME'] ?? 'postgres', $_ENV['DB_PASSWORD'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$slugify = static function (string $value): string {
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $normalized = strtolower($normalized);
    $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
    $normalized = trim($normalized, '-');
    $normalized = preg_replace('/-{2,}/', '-', $normalized) ?? $normalized;
    return $normalized;
};

$clip = static function (string $value, int $length = 110): string {
    return rtrim(substr($value, 0, $length), '-');
};

$text = static function ($value): string {
    return trim(preg_replace('/\s+/', ' ', (string)$value) ?? '');
};

$stmt = $db->prepare('
    SELECT
        i.id AS image_id,
        i.url,
        i.kind,
        i.alt_text,
        p.id AS product_id,
        p.name,
        p.brand,
        p.category,
        p.product_type,
        COALESCE(p.attributes, \'{}\'::jsonb) AS attributes
    FROM "Image" i
    INNER JOIN "Product" p ON p.id = i.product_id
    WHERE p.tenant_id = :tenant_id
    ORDER BY p.created_at DESC, i.id ASC
');
$stmt->execute(['tenant_id' => $tenantId]);

$updates = [];
$skipped = [];

foreach ($stmt->fetchAll() ?: [] as $row) {
    $url = $text($row['url'] ?? '');
    if ($url === '' || !str_starts_with($url, '/uploads/products/')) {
        $skipped[] = ['imageId' => $row['image_id'], 'reason' => 'non_product_upload', 'url' => $url];
        continue;
    }

    $relativePath = ltrim($url, '/');
    $sourcePath = $publicDir . '/' . $relativePath;
    if (!is_file($sourcePath)) {
        $skipped[] = ['imageId' => $row['image_id'], 'reason' => 'missing_file', 'url' => $url];
        continue;
    }

    $attributes = json_decode($row['attributes'] ?? '{}', true) ?: [];
    $variant = $text($attributes['variantLabel'] ?? $attributes['presentation'] ?? $attributes['size'] ?? $attributes['weight'] ?? $attributes['color'] ?? '');
    $species = $text($attributes['species'] ?? '');
    $kind = $text($row['kind'] ?? 'gallery') ?: 'gallery';
    $altText = $text($row['alt_text'] ?? '');
    if ($altText === '') {
        $altParts = array_values(array_unique(array_filter([
            $text($row['brand'] ?? ''),
            $text($row['name'] ?? ''),
            $variant,
            $text($row['category'] ?? $row['product_type'] ?? ''),
            $species,
        ])));
        $altText = ($altParts ? implode(' ', $altParts) : 'Producto para mascotas') . ' en ParaMascotasEC';
    }

    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'webp');
    $baseParts = array_values(array_unique(array_filter([
        'paramascotasec',
        $text($row['brand'] ?? ''),
        $text($row['name'] ?? ''),
        $variant,
        $species,
        $kind === 'thumb' ? 'miniatura' : 'ficha',
    ])));
    $baseName = $clip($slugify(implode(' ', $baseParts)));
    if ($baseName === '') {
        $baseName = 'paramascotasec-producto';
    }
    $suffix = strtolower(substr(preg_replace('/[^a-zA-Z0-9]/', '', (string)$row['image_id']), -8)) ?: substr(sha1($url), 0, 8);
    $newFileName = $baseName . '-' . $suffix . '.' . $extension;
    $newRelativePath = 'uploads/products/' . $newFileName;
    $targetPath = $publicDir . '/' . $newRelativePath;
    $newUrl = '/' . $newRelativePath;

    if ($targetPath !== $sourcePath && file_exists($targetPath)) {
        $newFileName = $baseName . '-' . $suffix . '-' . substr(sha1($url), 0, 6) . '.' . $extension;
        $newRelativePath = 'uploads/products/' . $newFileName;
        $targetPath = $publicDir . '/' . $newRelativePath;
        $newUrl = '/' . $newRelativePath;
    }

    $updates[] = [
        'imageId' => $row['image_id'],
        'productId' => $row['product_id'],
        'from' => $url,
        'to' => $newUrl,
        'altText' => $altText,
        'sourcePath' => $sourcePath,
        'targetPath' => $targetPath,
    ];
}

if ($apply && $updates) {
    $db->beginTransaction();
    try {
        $updateStmt = $db->prepare('UPDATE "Image" SET url = :url, alt_text = :alt_text WHERE id = :id');
        foreach ($updates as $update) {
            if ($update['from'] !== $update['to']) {
                rename($update['sourcePath'], $update['targetPath']);
                foreach ([220, 360] as $width) {
                    $sourceVariant = preg_replace('/\.webp$/i', '-' . $width . '.webp', $update['sourcePath']);
                    $targetVariant = preg_replace('/\.webp$/i', '-' . $width . '.webp', $update['targetPath']);
                    if ($sourceVariant && $targetVariant && is_file($sourceVariant)) {
                        rename($sourceVariant, $targetVariant);
                    }
                }
            }
            $updateStmt->execute([
                'id' => $update['imageId'],
                'url' => $update['to'],
                'alt_text' => $update['altText'],
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

$publicUpdates = array_map(static fn(array $item) => [
    'imageId' => $item['imageId'],
    'productId' => $item['productId'],
    'from' => $item['from'],
    'to' => $item['to'],
    'altText' => $item['altText'],
], $updates);

echo json_encode([
    'mode' => $dryRun ? 'dry-run' : 'apply',
    'tenantId' => $tenantId,
    'publicDir' => $publicDir,
    'updates' => count($updates),
    'skipped' => count($skipped),
    'items' => $publicUpdates,
    'skippedItems' => $skipped,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
