<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;

try {
    $tenantId = trim((string)($_ENV['DEFAULT_TENANT'] ?? getenv('DEFAULT_TENANT') ?: 'paramascotasec'));
    $tenants = require __DIR__ . '/config/tenants.php';
    if (!isset($tenants[$tenantId])) {
        throw new RuntimeException('Tenant no configurado para seed analytics: ' . $tenantId);
    }
    TenantContext::set($tenants[$tenantId]);
    $db = Database::getInstance();

    echo "Seeding Analytics Data...\n";

    // 1. Ensure we have some users
    // Create users 2, 3, 4 if they don't exist to simulate clients
    $users = [
        ['id' => 'analytics_customer_2', 'name' => 'Juan Perez', 'email' => 'juan@example.com', 'password' => password_hash('password', PASSWORD_DEFAULT)],
        ['id' => 'analytics_customer_3', 'name' => 'Maria Lopez', 'email' => 'maria@example.com', 'password' => password_hash('password', PASSWORD_DEFAULT)],
        ['id' => 'analytics_customer_4', 'name' => 'Carlos Andrade', 'email' => 'carlos@example.com', 'password' => password_hash('password', PASSWORD_DEFAULT)],
    ];

    $stmtUserCheck = $db->prepare('SELECT id FROM "Customer" WHERE tenant_id = :tenant_id AND id = :id');
    $stmtUserInsert = $db->prepare('INSERT INTO "Customer" (id, tenant_id, name, email, password, role, email_verified, updated_at, created_at) VALUES (:id, :tenant_id, :name, :email, :password, \'customer\', TRUE, NOW(), NOW())');

    foreach ($users as $u) {
        $stmtUserCheck->execute(['tenant_id' => $tenantId, 'id' => $u['id']]);
        if (!$stmtUserCheck->fetch()) {
            $stmtUserInsert->execute([
                'id' => $u['id'],
                'tenant_id' => $tenantId,
                'name' => $u['name'],
                'email' => $u['email'],
                'password' => $u['password']
            ]);
            echo "Created User: {$u['name']}\n";
        }
    }

    // Fetch all valid user IDs
    $stmtUsers = $db->prepare('SELECT id FROM "Customer" WHERE tenant_id = :tenant_id');
    $stmtUsers->execute(['tenant_id' => $tenantId]);
    $availableUserIds = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
    
    // 2. Clear existing orders (optional, but good for clean slate if requested)
    // Uncomment the next line if you want to wipe orders first
    // $db->exec('TRUNCATE TABLE "Order" CASCADE'); 

    // 3. Generate Orders for the last 10 days
    $statuses = ['pending', 'processing', 'completed', 'shipped', 'delivered'];
    
    $stmtProducts = $db->prepare('SELECT id, name, price FROM "Product" WHERE tenant_id = :tenant_id AND is_published = TRUE LIMIT 30');
    $stmtProducts->execute(['tenant_id' => $tenantId]);
    $products = $stmtProducts->fetchAll();
    if ($products === []) {
        throw new RuntimeException('No hay productos del tenant para generar analytics.');
    }

    $orderCount = 35;

    for ($i = 0; $i < $orderCount; $i++) {
        // Random date within last 10 days
        $daysAgo = rand(0, 9); 
        $hour = rand(8, 20);
        $minute = rand(0, 59);
        $dateStr = date('Y-m-d H:i:s', strtotime("-$daysAgo days $hour:$minute:00")); // e.g. "2023-10-27 14:30:00"

        $orderId = uniqid('ORD-');
        $userId = $availableUserIds[array_rand($availableUserIds)];
        $status = $statuses[array_rand($statuses)];
        
        // Random items
        $numItems = rand(1, 4);
        $orderTotal = 0;
        $orderItems = [];

        for ($j = 0; $j < $numItems; $j++) {
            $prod = $products[array_rand($products)];
            $qty = rand(1, 2);
            $lineTotal = $prod['price'] * $qty;
            $orderTotal += $lineTotal;
            $orderItems[] = [
                'product_id' => $prod['id'],
                'product_name' => $prod['name'],
                'quantity' => $qty,
                'price' => $prod['price']
            ];
        }

        // Insert Order
        $stmtOrder = $db->prepare('INSERT INTO "Order" ("id", "tenant_id", "user_id", "customer_id", "total", "status", "created_at", "shipping_address", "billing_address") VALUES (:id, :tenant_id, :user_id, :customer_id, :total, :status, :created_at, :shipping_address, :billing_address)');
        
        $stmtOrder->execute([
            'id' => $orderId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'customer_id' => $userId,
            'total' => $orderTotal,
            'status' => $status,
            'created_at' => $dateStr,
            'shipping_address' => json_encode(['address' => '123 Fake St', 'city' => 'Quito']),
            'billing_address' => json_encode(['address' => '123 Fake St', 'city' => 'Quito'])
        ]);

        // Insert Items
        $stmtItem = $db->prepare('INSERT INTO "OrderItem" ("id", "tenant_id", "order_id", "product_id", "product_name", "product_image", "quantity", "price") VALUES (:id, :tenant_id, :order_id, :product_id, :product_name, :product_image, :quantity, :price)');
        
        foreach ($orderItems as $item) {
            $stmtItem->execute([
                'id' => uniqid('analytics_item_', true),
                'tenant_id' => $tenantId,
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'product_image' => '/images/product/1000x1000.png', // Placeholder
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ]);
        }
        
        echo "Created Order $orderId ($dateStr) - Total: $$orderTotal\n";
    }

    echo "Done! Created $orderCount orders.\n";

} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
