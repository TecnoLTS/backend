<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

foreach (getenv() ?: [] as $key => $value) {
    if (is_string($key) && !array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
    }
}

$tenantId = trim((string)($argv[1] ?? 'fidepuntos')) ?: 'fidepuntos';
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $_ENV['DB_HOST_LOYALTY'] ?? $_ENV['DB_HOST'] ?? 'db',
    $_ENV['DB_PORT_LOYALTY'] ?? $_ENV['DB_PORT'] ?? '5432',
    $_ENV['DB_DATABASE_LOYALTY'] ?? 'loyalty'
);

$pdo = new PDO(
    $dsn,
    $_ENV['DB_USERNAME_LOYALTY'] ?? $_ENV['DB_USERNAME'] ?? '',
    $_ENV['DB_PASSWORD_LOYALTY'] ?? $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$programStmt = $pdo->prepare('SELECT id FROM loyalty_programs WHERE tenant_id = :tenant_id ORDER BY created_at ASC LIMIT 1');
$programStmt->execute(['tenant_id' => $tenantId]);
$programId = (string)($programStmt->fetchColumn() ?: '');
if ($programId === '') {
    fwrite(STDERR, "[wallet-catalog-seed] tenant {$tenantId} no tiene programa loyalty.\n");
    exit(1);
}

$rewards = [
    [
        'slug' => 'cafe_cortesia',
        'name' => 'Cafe de cortesia',
        'description' => 'Bebida de cortesia para retirar en el local.',
        'points' => 300,
        'stock' => 37,
        'mode' => 'in_store',
        'instructions' => 'Solicitalo en caja antes de pagar.',
        'options' => ['in_store'],
        'image' => 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=900&q=80',
        'category' => 'Retiro rapido',
    ],
    [
        'slug' => 'envio_gratis',
        'name' => 'Envio a domicilio sin costo',
        'description' => 'Cubre el costo de envio en Quito urbano.',
        'points' => 450,
        'stock' => 84,
        'mode' => 'managed',
        'instructions' => 'El gestor coordina direccion y horario.',
        'options' => ['delivery'],
        'image' => 'https://images.unsplash.com/photo-1580674285054-bed31e145f59?auto=format&fit=crop&w=900&q=80',
        'category' => 'Delivery',
    ],
    [
        'slug' => 'bono_5',
        'name' => 'Bono de compra $5',
        'description' => 'Descuento directo para usar en una compra presencial.',
        'points' => 700,
        'stock' => 32,
        'mode' => 'in_store',
        'instructions' => 'Presenta el codigo al equipo de caja.',
        'options' => ['in_store'],
        'image' => 'https://images.unsplash.com/photo-1513201099705-a9746e1e201f?auto=format&fit=crop&w=900&q=80',
        'category' => 'Bono',
    ],
    [
        'slug' => 'juguete_interactivo',
        'name' => 'Juguete interactivo',
        'description' => 'Premio sorpresa para entretenimiento de mascotas.',
        'points' => 950,
        'stock' => 18,
        'mode' => 'managed',
        'instructions' => 'Sujeto a disponibilidad de color y tamano.',
        'options' => ['pickup', 'delivery'],
        'image' => 'https://images.unsplash.com/photo-1601758124510-52d02ddb7cbd?auto=format&fit=crop&w=900&q=80',
        'category' => 'Mascotas',
    ],
    [
        'slug' => 'bono_10',
        'name' => 'Bono de compra $10',
        'description' => 'Ahorro preferente para socios frecuentes.',
        'points' => 1200,
        'stock' => 17,
        'mode' => 'in_store',
        'instructions' => 'Valido para compras mayores a $20.',
        'options' => ['in_store'],
        'image' => 'https://images.unsplash.com/photo-1607082349566-187342175e2f?auto=format&fit=crop&w=900&q=80',
        'category' => 'Bono',
    ],
    [
        'slug' => 'snack_premium',
        'name' => 'Snack premium',
        'description' => 'Snack seleccionado para consentir a tu mascota.',
        'points' => 1500,
        'stock' => 24,
        'mode' => 'managed',
        'instructions' => 'El gestor confirma sabor disponible.',
        'options' => ['pickup', 'delivery'],
        'image' => 'https://images.unsplash.com/photo-1589924691995-400dc9ecc119?auto=format&fit=crop&w=900&q=80',
        'category' => 'Alimento',
    ],
    [
        'slug' => 'producto_mes',
        'name' => 'Producto seleccionado del mes',
        'description' => 'Producto destacado elegido por el equipo.',
        'points' => 1800,
        'stock' => 10,
        'mode' => 'managed',
        'instructions' => 'Se reserva por 48 horas tras la solicitud.',
        'options' => ['pickup', 'delivery'],
        'image' => 'https://images.unsplash.com/photo-1583337130417-3346a1be7dee?auto=format&fit=crop&w=900&q=80',
        'category' => 'Destacado',
    ],
    [
        'slug' => 'kit_paseo',
        'name' => 'Kit de cuidado y paseo',
        'description' => 'Set util para salidas, higiene y cuidado diario.',
        'points' => 2200,
        'stock' => 8,
        'mode' => 'managed',
        'instructions' => 'Incluye articulos segun inventario disponible.',
        'options' => ['pickup', 'delivery'],
        'image' => 'https://images.unsplash.com/photo-1548199973-03cce0bbc87b?auto=format&fit=crop&w=900&q=80',
        'category' => 'Kit',
    ],
    [
        'slug' => 'atencion_preferente',
        'name' => 'Atencion preferente 30 dias',
        'description' => 'Prioridad de atencion para solicitudes y entregas.',
        'points' => 2500,
        'stock' => 6,
        'mode' => 'managed',
        'instructions' => 'El beneficio se activa despues de la aprobacion.',
        'options' => ['pickup'],
        'image' => 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?auto=format&fit=crop&w=900&q=80',
        'category' => 'Servicio',
    ],
    [
        'slug' => 'consulta_bienestar',
        'name' => 'Consulta de bienestar',
        'description' => 'Orientacion personalizada para cuidado de mascota.',
        'points' => 3200,
        'stock' => 5,
        'mode' => 'managed',
        'instructions' => 'El gestor agenda fecha y canal de atencion.',
        'options' => ['pickup'],
        'image' => 'https://images.unsplash.com/photo-1516734212186-a967f81ad0d7?auto=format&fit=crop&w=900&q=80',
        'category' => 'Asesoria',
    ],
    [
        'slug' => 'experiencia_premium',
        'name' => 'Experiencia premium',
        'description' => 'Premio de alto valor para clientes Oro.',
        'points' => 5000,
        'stock' => 3,
        'mode' => 'managed',
        'instructions' => 'Coordinamos retiro o entrega segun disponibilidad.',
        'options' => ['pickup', 'delivery'],
        'image' => 'https://images.unsplash.com/photo-1601758177266-bc599de87707?auto=format&fit=crop&w=900&q=80',
        'category' => 'Premium',
    ],
];

$selectStmt = $pdo->prepare('SELECT id FROM loyalty_rewards WHERE tenant_id = :tenant_id AND lower(name) = lower(:name) LIMIT 1');
$insertStmt = $pdo->prepare(
    'INSERT INTO loyalty_rewards
        (id, tenant_id, program_id, name, description, points_cost, stock, status, claim_mode, claim_instructions, claim_delivery_options, image_url, metadata)
     VALUES
        (:id, :tenant_id, :program_id, :name, :description, :points_cost, :stock, :status, :claim_mode, :claim_instructions, :claim_delivery_options, :image_url, :metadata)'
);
$updateStmt = $pdo->prepare(
    'UPDATE loyalty_rewards
     SET program_id = :program_id,
         description = :description,
         points_cost = :points_cost,
         stock = :stock,
         status = :status,
         claim_mode = :claim_mode,
         claim_instructions = :claim_instructions,
         claim_delivery_options = :claim_delivery_options,
         image_url = :image_url,
         metadata = :metadata,
         updated_at = NOW()
     WHERE tenant_id = :tenant_id AND id = :id'
);

$created = 0;
$updated = 0;
foreach ($rewards as $reward) {
    $selectStmt->execute(['tenant_id' => $tenantId, 'name' => $reward['name']]);
    $existingId = (string)($selectStmt->fetchColumn() ?: '');
    $id = $existingId !== '' ? $existingId : 'reward_wallet_' . $reward['slug'];
    $payload = [
        'id' => $id,
        'tenant_id' => $tenantId,
        'program_id' => $programId,
        'name' => $reward['name'],
        'description' => $reward['description'],
        'points_cost' => $reward['points'],
        'stock' => $reward['stock'],
        'status' => 'active',
        'claim_mode' => $reward['mode'],
        'claim_instructions' => $reward['instructions'],
        'claim_delivery_options' => json_encode($reward['options'], JSON_UNESCAPED_UNICODE),
        'image_url' => $reward['image'],
        'metadata' => json_encode([
            'category' => $reward['category'],
            'demoCatalog' => true,
        ], JSON_UNESCAPED_UNICODE),
    ];

    if ($existingId !== '') {
        $updateStmt->execute([
            'id' => $payload['id'],
            'tenant_id' => $payload['tenant_id'],
            'program_id' => $payload['program_id'],
            'description' => $payload['description'],
            'points_cost' => $payload['points_cost'],
            'stock' => $payload['stock'],
            'status' => $payload['status'],
            'claim_mode' => $payload['claim_mode'],
            'claim_instructions' => $payload['claim_instructions'],
            'claim_delivery_options' => $payload['claim_delivery_options'],
            'image_url' => $payload['image_url'],
            'metadata' => $payload['metadata'],
        ]);
        $updated++;
    } else {
        $insertStmt->execute($payload);
        $created++;
    }
}

fwrite(STDOUT, "[wallet-catalog-seed] tenant={$tenantId} creados={$created} actualizados={$updated}\n");
