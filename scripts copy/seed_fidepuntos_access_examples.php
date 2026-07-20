<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

function accessExampleEnv(string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    $value = trim((string)$value);
    return $value !== '' ? $value : $default;
}

$appEnv = strtolower((string)accessExampleEnv('APP_ENV', 'production'));
$enabled = strtolower((string)accessExampleEnv('SEED_FIDEPUNTOS_ACCESS_EXAMPLES', '0'));
if ($appEnv !== 'qa' || !in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
    fwrite(STDERR, "Este sembrado requiere APP_ENV=qa y SEED_FIDEPUNTOS_ACCESS_EXAMPLES=1.\n");
    exit(1);
}

$tenantId = 'fidepuntos';
$origin = 'seed_fidepuntos_access_examples';
TenantContext::set(['id' => $tenantId, 'slug' => $tenantId, 'name' => 'Fidepuntos Demo']);

$roles = [
    'fidepuntos_cashier' => [
        'name' => 'Operador de caja',
        'description' => 'Registra compras y canjes de mostrador con acceso de consulta a clientes, tarjetas y premios.',
        'grants' => [
            'loyalty.summary' => ['view'],
            'loyalty.purchase' => ['view', 'create'],
            'loyalty.redeem' => ['view', 'create'],
            'loyalty.customers' => ['view'],
            'loyalty.customer-card' => ['view', 'create'],
            'loyalty.rewards' => ['view'],
        ],
    ],
    'fidepuntos_supervisor' => [
        'name' => 'Supervisor de fidelización',
        'description' => 'Supervisa caja, clientes, premios, reglas y reportes operativos con acciones sensibles controladas.',
        'grants' => [
            'loyalty.summary' => ['view'],
            'loyalty.purchase' => ['view', 'create', 'reverse'],
            'loyalty.redeem' => ['view', 'create'],
            'loyalty.customers' => ['view', 'create', 'update'],
            'loyalty.customer-card' => ['view', 'create', 'update'],
            'loyalty.notifications' => ['view', 'create'],
            'loyalty.rewards' => ['view', 'create', 'update'],
            'loyalty.redemption-claims' => ['view', 'approve', 'deliver', 'cancel'],
            'loyalty.rules' => ['view', 'update'],
            'loyalty.settings' => ['view'],
            'loyalty.reports' => ['view'],
            'loyalty.report.executive-summary' => ['view', 'export'],
            'loyalty.report.point-activity' => ['view', 'export'],
            'loyalty.report.redemptions-rewards' => ['view', 'export'],
            'loyalty.report.risk-events' => ['view', 'export'],
        ],
    ],
    'fidepuntos_customer_service' => [
        'name' => 'Atención al cliente',
        'description' => 'Gestiona datos y tarjetas de clientes, consulta operaciones y revisa su actividad de puntos.',
        'grants' => [
            'loyalty.summary' => ['view'],
            'loyalty.customers' => ['view', 'create', 'update'],
            'loyalty.customer-card' => ['view', 'create', 'update'],
            'loyalty.purchase' => ['view'],
            'loyalty.redeem' => ['view'],
            'loyalty.notifications' => ['view'],
            'loyalty.report.point-activity' => ['view'],
        ],
    ],
    'fidepuntos_rewards_manager' => [
        'name' => 'Gestor de premios',
        'description' => 'Administra el catálogo de premios y el ciclo de aprobación, entrega y cancelación de solicitudes.',
        'grants' => [
            'loyalty.summary' => ['view'],
            'loyalty.rewards' => ['view', 'create', 'update'],
            'loyalty.redemption-claims' => ['view', 'approve', 'deliver', 'cancel'],
            'loyalty.redeem' => ['view'],
            'loyalty.notifications' => ['view', 'create'],
            'loyalty.report.redemptions-rewards' => ['view', 'export'],
            'loyalty.report.members-tiers' => ['view'],
        ],
    ],
    'fidepuntos_analyst' => [
        'name' => 'Analista de reportes',
        'description' => 'Consulta el resumen y exporta los nueve reportes sin permisos para modificar la operación.',
        'grants' => [
            'loyalty.summary' => ['view'],
            'loyalty.reports' => ['view'],
            'loyalty.report.executive-summary' => ['view', 'export'],
            'loyalty.report.point-activity' => ['view', 'export'],
            'loyalty.report.members-tiers' => ['view', 'export'],
            'loyalty.report.card-adoption' => ['view', 'export'],
            'loyalty.report.redemptions-rewards' => ['view', 'export'],
            'loyalty.report.risk-events' => ['view', 'export'],
            'loyalty.report.audit-events' => ['view', 'export'],
            'loyalty.report.api-usage' => ['view', 'export'],
            'loyalty.report.ledger-reconciliation' => ['view', 'export'],
        ],
    ],
];

$users = [
    [
        'id' => 'fidepuntos_demo_valeria_cashier',
        'name' => 'Valeria Andrade',
        'email' => 'valeria.andrade@fidepuntos.example.invalid',
        'department' => 'Caja Central',
        'position' => 'Operador de caja',
        'status' => 'active',
        'roles' => ['fidepuntos_cashier'],
    ],
    [
        'id' => 'fidepuntos_demo_martin_supervisor',
        'name' => 'Martín Cedeño',
        'email' => 'martin.cedeno@fidepuntos.example.invalid',
        'department' => 'Operaciones',
        'position' => 'Supervisor de fidelización',
        'status' => 'active',
        'roles' => ['fidepuntos_supervisor', 'fidepuntos_analyst'],
    ],
    [
        'id' => 'fidepuntos_demo_camila_service',
        'name' => 'Camila Ruiz',
        'email' => 'camila.ruiz@fidepuntos.example.invalid',
        'department' => 'Atención al cliente',
        'position' => 'Agente de atención',
        'status' => 'active',
        'roles' => ['fidepuntos_customer_service'],
    ],
    [
        'id' => 'fidepuntos_demo_diego_rewards',
        'name' => 'Diego Morales',
        'email' => 'diego.morales@fidepuntos.example.invalid',
        'department' => 'Gestión de premios',
        'position' => 'Gestor de premios',
        'status' => 'active',
        'roles' => ['fidepuntos_rewards_manager'],
    ],
    [
        'id' => 'fidepuntos_demo_sofia_analyst',
        'name' => 'Sofía Vega',
        'email' => 'sofia.vega@fidepuntos.example.invalid',
        'department' => 'Analítica',
        'position' => 'Analista de reportes',
        'status' => 'invited',
        'roles' => ['fidepuntos_analyst'],
    ],
];

$loyalty = Database::getModuleInstance(LoyaltyRewardsDomain::KEY);
$catalogRows = $loyalty->query("
    SELECT item.item_key, action.action_key
    FROM loyalty_navigation_items item
    JOIN loyalty_navigation_item_actions action
      ON action.tenant_id = item.tenant_id
     AND action.item_key = item.item_key
    WHERE item.tenant_id = 'fidepuntos'
      AND item.status = 'active'
      AND action.status = 'active'
")->fetchAll();
$catalog = [];
foreach ($catalogRows ?: [] as $row) {
    $catalog[(string)$row['item_key']][(string)$row['action_key']] = true;
}
foreach ($roles as $role) {
    foreach ($role['grants'] as $itemKey => $actions) {
        foreach ($actions as $action) {
            if (empty($catalog[$itemKey][$action])) {
                throw new RuntimeException("El catálogo Loyalty no publica {$itemKey}.{$action}.");
            }
        }
    }
}

$dashboard = Database::getModuleInstance(IdentityPlatformDomain::KEY);
$dashboard->beginTransaction();
$createdRoles = 0;
$createdUsers = 0;
try {
    $insertRole = $dashboard->prepare('
        INSERT INTO tenant_roles (
            tenant_id, role_id, name, description, permissions,
            system_role, created_at, updated_at
        ) VALUES (
            :tenant_id, :role_id, :name, :description, \'[]\'::jsonb,
            FALSE, NOW(), NOW()
        )
        ON CONFLICT (tenant_id, role_id) DO NOTHING
    ');
    $insertGrant = $dashboard->prepare('
        INSERT INTO tenant_role_navigation_grants (
            tenant_id, role_id, menu_option_key, action_key,
            assigned_by_user_id, granted_at, updated_at
        ) VALUES (
            :tenant_id, :role_id, :menu_option_key, :action_key,
            NULL, NOW(), NOW()
        )
        ON CONFLICT (tenant_id, role_id, menu_option_key, action_key) DO NOTHING
    ');
    foreach ($roles as $roleId => $role) {
        $insertRole->execute([
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'name' => $role['name'],
            'description' => $role['description'],
        ]);
        $newRole = $insertRole->rowCount() > 0;
        $createdRoles += $newRole ? 1 : 0;
        if (!$newRole) {
            continue;
        }
        foreach ($role['grants'] as $itemKey => $actions) {
            foreach ($actions as $action) {
                $insertGrant->execute([
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'menu_option_key' => $itemKey,
                    'action_key' => $action,
                ]);
            }
        }
    }

    $insertUser = $dashboard->prepare('
        INSERT INTO "User" (
            id, tenant_id, email, name, password, role, email_verified,
            profile, created_at, updated_at
        ) VALUES (
            :id, :tenant_id, :email, :name, :password, \'admin\', :email_verified,
            CAST(:profile AS jsonb), NOW(), NOW()
        )
        ON CONFLICT (tenant_id, email) DO NOTHING
    ');
    $findUser = $dashboard->prepare('
        SELECT id, profile->>\'origin\' AS origin
        FROM "User"
        WHERE tenant_id = :tenant_id AND email = :email
        LIMIT 1
    ');
    $insertMembership = $dashboard->prepare('
        INSERT INTO tenant_memberships (
            tenant_id, user_id, identity_type, status, created_at, updated_at
        ) VALUES (
            :tenant_id, :user_id, \'tenant_staff\', :status, NOW(), NOW()
        )
        ON CONFLICT (tenant_id, user_id) DO NOTHING
    ');
    $insertAssignment = $dashboard->prepare('
        INSERT INTO tenant_user_roles (tenant_id, user_id, role_id, assigned_at)
        VALUES (:tenant_id, :user_id, :role_id, NOW())
        ON CONFLICT (tenant_id, user_id, role_id) DO NOTHING
    ');

    foreach ($users as $user) {
        $profile = [
            'identityType' => 'tenant_staff',
            'department' => $user['department'],
            'position' => $user['position'],
            'description' => 'Cuenta ficticia de operación QA. No recibe correo ni tiene una contraseña conocida.',
            'origin' => $origin,
        ];
        $insertUser->execute([
            'id' => $user['id'],
            'tenant_id' => $tenantId,
            'email' => $user['email'],
            'name' => $user['name'],
            'password' => password_hash(bin2hex(random_bytes(48)), PASSWORD_DEFAULT),
            'email_verified' => $user['status'] === 'active' ? 1 : 0,
            'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $createdUsers += $insertUser->rowCount() > 0 ? 1 : 0;

        $findUser->execute(['tenant_id' => $tenantId, 'email' => $user['email']]);
        $stored = $findUser->fetch();
        if (!$stored || (string)$stored['id'] !== $user['id'] || (string)$stored['origin'] !== $origin) {
            throw new RuntimeException('Un correo demo coincide con una identidad que no pertenece al sembrado QA.');
        }
        $insertMembership->execute([
            'tenant_id' => $tenantId,
            'user_id' => $user['id'],
            'status' => $user['status'],
        ]);
        foreach ($user['roles'] as $roleId) {
            $insertAssignment->execute([
                'tenant_id' => $tenantId,
                'user_id' => $user['id'],
                'role_id' => $roleId,
            ]);
        }
    }

    $dashboard->commit();
} catch (Throwable $e) {
    if ($dashboard->inTransaction()) {
        $dashboard->rollBack();
    }
    throw $e;
}

fwrite(STDOUT, json_encode([
    'ok' => true,
    'tenant' => $tenantId,
    'createdRoles' => $createdRoles,
    'createdUsers' => $createdUsers,
    'configuredRoles' => count($roles),
    'configuredUsers' => count($users),
    'mailSent' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
