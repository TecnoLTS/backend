<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

function demoEnv(string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }

    $value = trim((string)$value);
    return $value === '' ? $default : $value;
}

function demoConnect(): PDO {
    $host = demoEnv('DB_HOST_DASHBOARD', demoEnv('DB_HOST', 'db'));
    $port = demoEnv('DB_PORT_DASHBOARD', demoEnv('DB_PORT', '5432'));
    $database = demoEnv('DB_DATABASE_DASHBOARD', 'dashboard');
    $username = demoEnv('DB_USERNAME_DASHBOARD', demoEnv('DB_USERNAME', 'postgres'));
    $password = demoEnv('DB_PASSWORD_DASHBOARD', demoEnv('DB_PASSWORD', 'postgres'));

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database),
        (string)$username,
        (string)$password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

$password = demoEnv('FIDEPUNTOS_DEMO_ADMIN_PASSWORD');
if ($password === null || strlen($password) < 12) {
    fwrite(STDERR, "FIDEPUNTOS_DEMO_ADMIN_PASSWORD debe tener al menos 12 caracteres.\n");
    exit(1);
}

$tenantId = 'fidepuntos';
$adminUserId = 'tenant_fidepuntos_demo_admin';
$adminEmail = strtolower(demoEnv('FIDEPUNTOS_DEMO_ADMIN_EMAIL', 'dev@tecnolts.com') ?? 'dev@tecnolts.com');
$adminName = demoEnv('FIDEPUNTOS_DEMO_ADMIN_NAME', 'Demo Fidepuntos TECNOLTS') ?? 'Demo Fidepuntos TECNOLTS';
$roleId = "{$tenantId}_admin";
$now = gmdate('c');
$profile = [
    'identityType' => 'tenant_staff',
    'roleIds' => [$roleId],
    'loginAliases' => ['demo@tecnolts.com'],
    'department' => 'Demo TECNOLTS',
    'position' => 'Administrador tenant demo',
    'description' => 'Usuario operativo TECNOLTS asignado al tenant Fidepuntos Demo. No es superadmin de plataforma.',
    'origin' => 'seed_fidepuntos_demo',
];
$permissions = [
    'dashboard.read',
    'users.read',
    'users.create',
    'users.update',
    'users.delete',
    'roles.read',
    'roles.create',
    'roles.update',
    'roles.delete',
    'loyalty-points.read',
    'loyalty-points.create',
    'loyalty-points.update',
    'loyalty-points.delete',
];

$pdo = demoConnect();
$pdo->beginTransaction();

try {
    $pdo->prepare('
        INSERT INTO "Tenant" (id, name, created_at)
        VALUES (:id, :name, NOW())
        ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name
    ')->execute([
        'id' => $tenantId,
        'name' => 'Fidepuntos Demo',
    ]);

    $upsertModule = $pdo->prepare('
        INSERT INTO tenant_module_entitlements (tenant_id, module_key, status, source, granted_at, updated_at)
        VALUES (:tenant_id, :module_key, :status, :source, NOW(), NOW())
        ON CONFLICT (tenant_id, module_key)
        DO UPDATE SET status = EXCLUDED.status, source = EXCLUDED.source, updated_at = NOW()
    ');
    foreach (['dashboard', 'users', 'loyalty-points'] as $moduleKey) {
        $upsertModule->execute([
            'tenant_id' => $tenantId,
            'module_key' => $moduleKey,
            'status' => 'active',
            'source' => 'fidepuntos-demo-seed',
        ]);
    }

    $pdo->prepare('
        INSERT INTO tenant_roles (tenant_id, role_id, name, description, permissions, system_role, created_at, updated_at)
        VALUES (:tenant_id, :role_id, :name, :description, CAST(:permissions AS jsonb), true, NOW(), NOW())
        ON CONFLICT (tenant_id, role_id)
        DO UPDATE SET
            name = EXCLUDED.name,
            description = EXCLUDED.description,
            permissions = EXCLUDED.permissions,
            system_role = EXCLUDED.system_role,
            updated_at = NOW()
    ')->execute([
        'tenant_id' => $tenantId,
        'role_id' => $roleId,
        'name' => 'Administrador Fidepuntos',
        'description' => 'Administra usuarios, roles y fidelizacion del tenant demo.',
        'permissions' => json_encode($permissions, JSON_UNESCAPED_SLASHES),
    ]);

    $pdo->prepare('
        INSERT INTO "User" (
            id,
            tenant_id,
            email,
            name,
            password,
            role,
            email_verified,
            profile,
            created_at,
            updated_at
        ) VALUES (
            :id,
            :tenant_id,
            :email,
            :name,
            :password,
            :role,
            true,
            CAST(:profile AS jsonb),
            NOW(),
            NOW()
        )
        ON CONFLICT (tenant_id, email)
        DO UPDATE SET
            id = EXCLUDED.id,
            name = EXCLUDED.name,
            password = EXCLUDED.password,
            role = EXCLUDED.role,
            email_verified = true,
            profile = EXCLUDED.profile,
            updated_at = NOW()
    ')->execute([
        'id' => $adminUserId,
        'tenant_id' => $tenantId,
        'email' => $adminEmail,
        'name' => $adminName,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin',
        'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $pdo->prepare('
        INSERT INTO tenant_memberships (tenant_id, user_id, identity_type, status, created_at, updated_at)
        VALUES (:tenant_id, :user_id, :identity_type, :status, NOW(), NOW())
        ON CONFLICT (tenant_id, user_id)
        DO UPDATE SET identity_type = EXCLUDED.identity_type, status = EXCLUDED.status, updated_at = NOW()
    ')->execute([
        'tenant_id' => $tenantId,
        'user_id' => $adminUserId,
        'identity_type' => 'tenant_staff',
        'status' => 'active',
    ]);

    $pdo->prepare('
        INSERT INTO tenant_user_roles (tenant_id, user_id, role_id, assigned_at)
        VALUES (:tenant_id, :user_id, :role_id, NOW())
        ON CONFLICT (tenant_id, user_id, role_id) DO NOTHING
    ')->execute([
        'tenant_id' => $tenantId,
        'user_id' => $adminUserId,
        'role_id' => $roleId,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

fwrite(STDOUT, json_encode([
    'ok' => true,
    'tenant' => $tenantId,
    'domain' => demoEnv('FIDEPUNTOS_DEMO_DOMAIN', 'fidepuntos.tecnolts.com'),
    'adminEmail' => $adminEmail,
    'identityType' => 'tenant_staff',
    'roleId' => $roleId,
    'seededAt' => $now,
], JSON_UNESCAPED_SLASHES) . PHP_EOL);
