<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    $dotenv = Dotenv::createImmutable($envDir);
    $dotenv->load();
}

$db = Database::getInstance();

$stmt = $db->query('SELECT id, legacy_id, category, gender, action, product_type, attributes FROM "Product"');
$rows = $stmt->fetchAll();

$mapProductType = function ($category) {
    $cat = strtolower(trim((string)$category));
    if (in_array($cat, ['Alimento', 'alimentos', 'alimento', 'food'])) return 'Alimento';
    if (in_array($cat, ['ropa', 'vestimenta', 'moda'])) return 'ropa';
    if (in_array($cat, ['accesorios', 'juguetes', 'higiene', 'salud'])) return 'accesorios';
    return 'accesorios';
};

$updated = 0;

foreach ($rows as $row) {
    $attributes = [];
    if (!empty($row['attributes'])) {
        $decoded = json_decode($row['attributes'], true);
        if (is_array($decoded)) {
            $attributes = $decoded;
        }
    }

    $needsUpdate = false;

    $productType = $row['product_type'] ?? null;
    if (!$productType) {
        $productType = $mapProductType($row['category']);
        $needsUpdate = true;
    }

    if (empty($attributes['sku'])) {
        $attributes['sku'] = $row['legacy_id'] ?: $row['id'];
        $needsUpdate = true;
    }
    if (empty($attributes['tag'])) {
        $action = strtolower(trim((string)($row['action'] ?? '')));
        $attributes['tag'] = ($action && $action !== 'view')
            ? $row['action']
            : strtolower((string)($row['category'] ?? 'general'));
        $needsUpdate = true;
    }
    if (empty($attributes['species'])) {
        $attributes['species'] = $row['gender'] ?: 'general';
        $needsUpdate = true;
    }

    if ($needsUpdate) {
        $update = $db->prepare('UPDATE "Product" SET product_type = :product_type, attributes = :attributes WHERE id = :id');
        $update->execute([
            'id' => $row['id'],
            'product_type' => $productType,
            'attributes' => json_encode($attributes),
        ]);
        $updated++;
    }
}

echo "Productos actualizados: {$updated}\n";
