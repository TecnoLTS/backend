<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\LoyaltyRewards\Application\LoyaltyNavigationService;

/** @param array<string, mixed> $actual */
function assertDecision(array $actual, string $permission, ?string $module): void
{
    if (($actual['permission'] ?? null) !== $permission || ($actual['module'] ?? null) !== $module) {
        throw new RuntimeException(sprintf(
            'Decision inesperada. Esperado %s/%s; recibido %s',
            $permission,
            $module ?? 'null',
            json_encode($actual, JSON_UNESCAPED_SLASHES)
        ));
    }
}

/** @param mixed $actual */
function assertSameValue(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . json_encode($actual));
    }
}

$reflection = new ReflectionClass(TenantAccessService::class);
/** @var TenantAccessService $access */
$access = $reflection->newInstanceWithoutConstructor();

TenantContext::set(['id' => 'fidepuntos', 'slug' => 'fidepuntos', 'name' => 'Fidepuntos']);

assertSameValue(
    $access->isPlatformAdmin([
        'tenant_id' => 'fidepuntos',
        'role' => 'admin',
        'profile' => json_encode(['roleIds' => ['platform_admin']]),
        'email' => 'legacy@tecnolts.com',
    ], 'admin', []),
    false,
    'Fidepuntos no debe elevar una identidad tenant desde profile.roleIds legacy'
);
assertSameValue(
    $access->isPlatformAdmin([
        'tenant_id' => 'platform',
        'role' => 'admin',
        'profile' => json_encode(['identityType' => 'platform']),
        'email' => 'platform@tecnolts.com',
    ], 'admin', []),
    true,
    'Fidepuntos debe reconocer la identidad global persistida por plataforma'
);

assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'GET', '/api/admin/loyalty/purchases/context'),
    'loyalty.register-purchase.view',
    'loyalty-points'
);
assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'POST', '/api/admin/loyalty/purchases'),
    'loyalty.register-purchase.create',
    'loyalty-points'
);
assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'GET', '/api/admin/loyalty/rewards'),
    'loyalty.rewards.view',
    'loyalty-points'
);
assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'POST', '/api/admin/loyalty/redemptions'),
    'loyalty.redeem-reward.create',
    'loyalty-points'
);
assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'POST', '/api/admin/loyalty/redemption-claims/validate-code'),
    'loyalty.redemption-claims.deliver',
    'loyalty-points'
);
assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'POST', '/api/admin/loyalty/adjustments'),
    'loyalty.customers.update',
    'loyalty-points'
);
assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'GET', '/api/admin/loyalty/reports/risk-events'),
    'loyalty.report-risk-events.view',
    'loyalty-points'
);
assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'GET', '/api/admin/loyalty/reports/risk-events/export'),
    'loyalty.report-risk-events.export',
    'loyalty-points'
);
assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'GET', '/api/admin/loyalty/navigation/catalog'),
    'identity.roles.view',
    'users'
);
assertDecision(
    $access->routeAccessDecision('loyalty.admin', 'GET', '/api/admin/loyalty/not-published'),
    LoyaltyNavigationService::DENY_PERMISSION,
    'loyalty-points'
);

$roleGrantDecision = $access->routeAccessDecision('users.admin', 'PUT', '/api/roles/custom/navigation-grants');
assertDecision($roleGrantDecision, 'identity.roles.assign_roles', 'users');
$userCreateDecision = $access->routeAccessDecision('users.admin', 'POST', '/api/users');
assertSameValue(
    $userCreateDecision['permissions'] ?? null,
    ['identity.users.create', 'identity.users.assign_roles'],
    'Crear usuario Fidepuntos debe exigir alta y asignacion de roles'
);
$roleCreateDecision = $access->routeAccessDecision('users.admin', 'POST', '/api/roles');
assertSameValue(
    $roleCreateDecision['permissions'] ?? null,
    ['identity.roles.create', 'identity.roles.assign_roles'],
    'Crear rol Fidepuntos debe exigir alta y asignacion de opciones'
);

assertSameValue(
    $access->normalizePermissions(
        ['dashboard.read', 'loyalty.rewards.delete', 'identity.users.assign_roles', 'platform-admin'],
        ['dashboard', 'users', 'loyalty-points']
    ),
    ['dashboard.read'],
    'Permisos granulares no deben persistirse en tenant_roles.permissions'
);

TenantContext::set(['id' => 'paramascotasec', 'slug' => 'paramascotasec', 'name' => 'ParaMascotasEC']);
$legacyCreateDecision = $access->routeAccessDecision('users.admin', 'POST', '/api/users');
if (isset($legacyCreateDecision['permissions'])) {
    throw new RuntimeException('Otros tenants no deben activar el contrato compuesto Fidepuntos.');
}

fwrite(STDOUT, "RBAC route policy OK\n");
