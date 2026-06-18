<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Repositories\UserRepository;

class TenantController {
    private UserRepository $userRepository;

    private const MODULE_PERMISSION_ACTIONS = [
        'dashboard' => ['read'],
        'ecommerce' => ['read', 'create', 'update', 'delete'],
        'users' => ['read', 'create', 'update', 'delete'],
        'invoicing' => ['read', 'create', 'update', 'delete'],
        'products' => ['read', 'create', 'update', 'delete'],
        'inventory' => ['read', 'create', 'update', 'delete'],
        'monitoring' => ['read', 'create', 'update'],
        'workspace' => ['read', 'create', 'update'],
        'ui-kit' => ['read'],
        'tenant-admin' => ['read', 'update'],
        'email-service' => ['read', 'create', 'update'],
        'medical-office' => ['read', 'create', 'update', 'delete'],
    ];

    public function __construct() {
        $this->userRepository = new UserRepository();
    }

    public function context() {
        $payload = Auth::requireUser();
        $tenant = TenantContext::get();

        if (!$tenant) {
            Response::error('Tenant no disponible', 404, 'TENANT_NOT_FOUND');
            return;
        }

        $enabledModules = $this->enabledModules($tenant);
        $branding = $this->branding($tenant);

        if (!empty($payload['service_auth'])) {
            Response::noStore();
            Response::json([
                'tenant' => $this->tenantIdentity($tenant),
                'enabledModules' => $enabledModules,
                'permissions' => ['platform-admin'],
                'roles' => [$this->platformAdminRole()],
                'currentUser' => [
                    'id' => 'service',
                    'name' => (string)($payload['name'] ?? 'Dashboard Internal Proxy'),
                    'email' => (string)($payload['email'] ?? 'dashboard-internal@service.local'),
                    'roleIds' => ['platform_admin'],
                    'permissions' => ['platform-admin'],
                ],
                'branding' => $branding,
            ]);
            return;
        }

        $user = $this->userRepository->getById((string)($payload['sub'] ?? ''));
        if (!$user) {
            Response::clearAuthCookie();
            Response::clearCsrfCookie();
            Response::error('Sesión inválida', 401, 'AUTH_TOKEN_REVOKED');
            return;
        }

        $role = strtolower(trim((string)($user['role'] ?? 'customer')));
        $platformAdmin = $this->isPlatformAdmin($user, $role, $tenant);
        $permissions = $platformAdmin
            ? ['platform-admin']
            : ($role === 'admin'
                ? $this->tenantAdminPermissions($enabledModules)
                : $this->readOnlyPermissions($enabledModules));

        $roles = [$platformAdmin
            ? $this->platformAdminRole()
            : $this->tenantRole(TenantContext::slug() ?: 'tenant', $enabledModules, $role === 'admin')];

        Response::noStore();
        Response::json([
            'tenant' => $this->tenantIdentity($tenant),
            'enabledModules' => $enabledModules,
            'permissions' => $permissions,
            'roles' => $roles,
            'currentUser' => [
                'id' => (string)$user['id'],
                'name' => (string)$user['name'],
                'email' => (string)$user['email'],
                'roleIds' => [$roles[0]['id']],
                'permissions' => $permissions,
            ],
            'branding' => $branding,
        ]);
    }

    private function tenantIdentity(array $tenant): array {
        return [
            'id' => (string)($tenant['id'] ?? TenantContext::id() ?? 'tenant'),
            'slug' => (string)($tenant['slug'] ?? TenantContext::slug() ?? 'tenant'),
            'name' => (string)($tenant['name'] ?? TenantContext::name() ?? 'Tenant'),
            'status' => 'active',
        ];
    }

    private function enabledModules(array $tenant): array {
        $configured = $tenant['enabled_modules'] ?? ['dashboard', 'ecommerce', 'tenant-admin'];
        if (!is_array($configured)) {
            $configured = ['dashboard', 'ecommerce', 'tenant-admin'];
        }

        $modules = [];
        foreach ($configured as $moduleKey) {
            $normalized = strtolower(trim((string)$moduleKey));
            if ($normalized === '' || !array_key_exists($normalized, self::MODULE_PERMISSION_ACTIONS)) {
                continue;
            }
            $modules[] = $normalized;
        }

        if (!in_array('dashboard', $modules, true)) {
            array_unshift($modules, 'dashboard');
        }

        return array_values(array_unique($modules));
    }

    private function branding(array $tenant): array {
        $branding = $tenant['branding'] ?? [];
        return [
            'logoUrl' => (string)($branding['logo_url'] ?? 'assets/images/logo.png'),
            'logoLightUrl' => (string)($branding['logo_light_url'] ?? 'assets/images/logo-light.png'),
            'logoIconUrl' => (string)($branding['logo_icon_url'] ?? 'assets/images/logo-icon.png'),
            'primaryColor' => (string)($branding['primary_color'] ?? '#f97316'),
        ];
    }

    private function isPlatformAdmin(array $user, string $role, array $tenant): bool {
        if ($role === 'service') {
            return true;
        }

        if ($role !== 'admin') {
            return false;
        }

        $email = strtolower(trim((string)($user['email'] ?? '')));
        $configured = array_filter(array_map(
            static fn ($value) => strtolower(trim((string)$value)),
            is_array($tenant['platform_admin_emails'] ?? null) ? $tenant['platform_admin_emails'] : []
        ));

        if ($email !== '' && in_array($email, $configured, true)) {
            return true;
        }

        return false;
    }

    private function tenantAdminPermissions(array $enabledModules): array {
        $permissions = [];

        foreach ($enabledModules as $moduleKey) {
            foreach (self::MODULE_PERMISSION_ACTIONS[$moduleKey] ?? [] as $action) {
                $permissions[] = "{$moduleKey}.{$action}";
            }
        }

        if (in_array('users', $enabledModules, true)) {
            foreach (['read', 'create', 'update', 'delete'] as $action) {
                $permissions[] = "roles.{$action}";
            }
        }

        return array_values(array_unique($permissions));
    }

    private function readOnlyPermissions(array $enabledModules): array {
        $permissions = [];

        foreach ($enabledModules as $moduleKey) {
            if (in_array('read', self::MODULE_PERMISSION_ACTIONS[$moduleKey] ?? [], true)) {
                $permissions[] = "{$moduleKey}.read";
            }
        }

        return array_values(array_unique($permissions));
    }

    private function tenantRole(string $tenantSlug, array $enabledModules, bool $admin): array {
        $roleId = $admin ? "{$tenantSlug}_admin" : "{$tenantSlug}_reader";
        return [
            'id' => $roleId,
            'name' => $admin ? 'Administrador tenant' : 'Consulta',
            'description' => $admin
                ? 'Puede operar los modulos habilitados de este tenant.'
                : 'Solo puede consultar informacion de los modulos habilitados.',
            'permissions' => $admin
                ? $this->tenantAdminPermissions($enabledModules)
                : $this->readOnlyPermissions($enabledModules),
            'system' => true,
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c'),
        ];
    }

    private function platformAdminRole(): array {
        return [
            'id' => 'platform_admin',
            'name' => 'Superadmin plataforma',
            'description' => 'Gestiona tenants, modulos y configuracion global del dashboard.',
            'permissions' => ['platform-admin'],
            'system' => true,
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c'),
        ];
    }
}
