<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Support\ProductSeoMetadata;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

$options = getopt('', ['tenant::', 'apply', 'dry-run']);
$apply = array_key_exists('apply', $options);
$tenantId = trim((string)($options['tenant'] ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec')));

$tenants = require __DIR__ . '/../config/tenants.php';
if (!isset($tenants[$tenantId])) {
    fwrite(STDERR, "Tenant no configurado: {$tenantId}\n");
    exit(1);
}

TenantContext::set($tenants[$tenantId]);
$db = Database::getInstance();

$decodeAttributes = static function ($value): array {
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
};

$stmt = $db->prepare('
    SELECT
        id,
        name,
        brand,
        category,
        product_type,
        gender,
        price,
        quantity,
        description,
        COALESCE(attributes, \'{}\'::jsonb) AS attributes
    FROM "Product"
    WHERE tenant_id = :tenant_id
    ORDER BY created_at DESC, id ASC
');
$stmt->execute(['tenant_id' => $tenantId]);
$rows = $stmt->fetchAll() ?: [];

$updates = [];
foreach ($rows as $row) {
    $attributes = $decodeAttributes($row['attributes'] ?? []);
    $data = [
        'name' => $row['name'] ?? '',
        'brand' => $row['brand'] ?? '',
        'category' => $row['category'] ?? '',
        'productType' => $row['product_type'] ?? '',
        'gender' => $row['gender'] ?? '',
        'price' => $row['price'] ?? 0,
        'quantity' => $row['quantity'] ?? 0,
        'description' => $row['description'] ?? '',
        'attributes' => $attributes,
    ];

    ProductSeoMetadata::applyDefaults($data, null);
    $nextAttributes = $data['attributes'] ?? [];
    if ($nextAttributes !== $attributes) {
        $updates[] = [
            'id' => $row['id'],
            'attributes' => $nextAttributes,
        ];
    }
}

if ($apply && $updates !== []) {
    $db->beginTransaction();
    try {
        $updateStmt = $db->prepare('UPDATE "Product" SET attributes = :attributes::jsonb, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        foreach ($updates as $update) {
            $updateStmt->execute([
                'id' => $update['id'],
                'tenant_id' => $tenantId,
                'attributes' => json_encode($update['attributes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'tenantId' => $tenantId,
    'productsScanned' => count($rows),
    'productsNeedingSeoAttributes' => count($updates),
    'updated' => $apply ? count($updates) : 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
