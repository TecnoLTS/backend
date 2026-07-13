<?php

namespace App\Modules\IdentityPlatform\Application;

use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Infrastructure\IdentityAccessRepository;
use App\Modules\LoyaltyRewards\Application\LoyaltyNavigationService;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;

class TenantAccessService {
    public const ROLES_KEY = 'dashboard_roles';
    public const TENANT_ADMIN_OVERRIDES_KEY = 'dashboard_tenant_admin_overrides';
    public const PLATFORM_ADMIN_PERMISSION = 'platform-admin';

    private const MODULE_PERMISSION_ACTIONS = [
        'dashboard' => ['read'],
        'ecommerce' => ['read', 'create', 'update', 'delete'],
        'users' => ['read', 'create', 'update', 'delete'],
        'billing-sri' => ['read', 'create', 'update'],
        'loyalty-points' => ['read', 'create', 'update', 'delete'],
        'tenant-admin' => ['read', 'update'],
    ];

    private const PLATFORM_ADMIN_CONTEXT_MODULES = [
        'tenant-admin',
        'users',
    ];

    private const CUSTOMER_ROLE_IDS = [
        'buyer',
        'customer',
        'guest',
        'shopper',
    ];
    private const DEFAULT_ENABLED_MODULES = [
        'dashboard',
        'ecommerce',
        'users',
    ];

    private const GRANULAR_ACCESS_TENANT_IDS = [
        'fidepuntos',
    ];

    private SettingsRepository $settingsRepository;
    private IdentityAccessRepository $identityAccessRepository;

    public function __construct(
        ?SettingsRepository $settingsRepository = null,
        ?IdentityAccessRepository $identityAccessRepository = null
    ) {
        $this->settingsRepository = $settingsRepository ?? new SettingsRepository();
        $this->identityAccessRepository = $identityAccessRepository ?? new IdentityAccessRepository();
    }

    public function modulePermissionActions(): array {
        return self::MODULE_PERMISSION_ACTIONS;
    }

    public function knownModuleKeys(): array {
        return array_keys(self::MODULE_PERMISSION_ACTIONS);
    }

    public function enabledModulesForTenant(array $tenant): array {
        $configured = $tenant['enabled_modules'] ?? self::DEFAULT_ENABLED_MODULES;
        if (!is_array($configured)) {
            $configured = self::DEFAULT_ENABLED_MODULES;
        }

        $configuredModules = $this->normalizeKnownModuleList($configured);
        $tenantId = (string)($tenant['id'] ?? $tenant['slug'] ?? TenantContext::id() ?? '');
        $storedModules = $tenantId !== ''
            ? $this->identityAccessRepository->activeTenantModules($tenantId)
            : null;

        if ($storedModules !== null) {
            return $this->normalizeKnownModuleList($storedModules);
        }

        if ($tenantId !== '') {
            $this->identityAccessRepository->syncTenantModules($configuredModules, $tenantId, 'configured-tenant');
        }

        return $configuredModules;
    }

    public function syncTenantModuleEntitlements(array $tenant, array $modules, string $source = 'tenant-admin'): void {
        $tenantId = (string)($tenant['id'] ?? $tenant['slug'] ?? TenantContext::id() ?? '');
        if ($tenantId === '') {
            return;
        }

        $this->identityAccessRepository->syncTenantModules($this->normalizeKnownModuleList($modules), $tenantId, $source);
    }

    public function platformAdminContextModules(array $tenantModules): array {
        return array_values(array_unique([
            ...$tenantModules,
            ...self::PLATFORM_ADMIN_CONTEXT_MODULES,
        ]));
    }

    public function normalizeEnabledModulePayload($rawModules): array {
        if (!is_array($rawModules)) {
            return [
                'modules' => [],
                'invalidModules' => ['payload'],
            ];
        }

        $modules = [];
        $invalidModules = [];
        foreach ($rawModules as $moduleKey) {
            $normalized = strtolower(trim((string)$moduleKey));
            if ($normalized === '' || !array_key_exists($normalized, self::MODULE_PERMISSION_ACTIONS)) {
                $invalidModules[] = (string)$moduleKey;
                continue;
            }
            $modules[] = $normalized;
        }

        return [
            'modules' => $this->normalizeKnownModuleList($modules),
            'invalidModules' => array_values(array_unique($invalidModules)),
        ];
    }

    public function tenantAdminPermissions(array $enabledModules): array {
        $permissions = [];
        $normalizedModules = $this->normalizeKnownModuleList($enabledModules);

        foreach ($normalizedModules as $moduleKey) {
            foreach (self::MODULE_PERMISSION_ACTIONS[$moduleKey] ?? [] as $action) {
                $permissions[] = "{$moduleKey}.{$action}";
            }
        }

        if (in_array('users', $normalizedModules, true)) {
            foreach (['read', 'create', 'update', 'delete'] as $action) {
                $permissions[] = "roles.{$action}";
            }
        }

        return array_values(array_unique($permissions));
    }

    public function readOnlyPermissions(array $enabledModules): array {
        $permissions = [];

        foreach ($this->normalizeKnownModuleList($enabledModules) as $moduleKey) {
            if (in_array('read', self::MODULE_PERMISSION_ACTIONS[$moduleKey] ?? [], true)) {
                $permissions[] = "{$moduleKey}.read";
            }
        }

        return array_values(array_unique($permissions));
    }

    public function normalizePermissions($permissions, array $enabledModules): array {
        if (!is_array($permissions)) {
            return [];
        }

        $allowed = array_flip($this->tenantAdminPermissions($enabledModules));
        $normalized = [];
        foreach ($permissions as $permission) {
            $permission = strtolower(trim((string)$permission));
            if ($permission !== self::PLATFORM_ADMIN_PERMISSION && isset($allowed[$permission])) {
                $normalized[] = $permission;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function systemRoles(array $enabledModules, ?string $tenantSlug = null): array {
        $tenantSlug = $tenantSlug ?: (TenantContext::slug() ?: 'tenant');
        $now = gmdate('c');

        return [
            [
                'id' => "{$tenantSlug}_admin",
                'name' => 'Administrador tenant',
                'description' => 'Puede operar los modulos habilitados de este tenant.',
                'permissions' => $this->tenantAdminPermissions($enabledModules),
                'system' => true,
                'createdAt' => $now,
                'updatedAt' => $now,
            ],
            [
                'id' => "{$tenantSlug}_reader",
                'name' => 'Consulta',
                'description' => 'Solo puede consultar informacion de los modulos habilitados.',
                'permissions' => $this->readOnlyPermissions($enabledModules),
                'system' => true,
                'createdAt' => $now,
                'updatedAt' => $now,
            ],
        ];
    }

    public function tenantRole(string $tenantSlug, array $enabledModules, bool $admin): array {
        $roles = $this->systemRoles($enabledModules, $tenantSlug);
        return $admin ? $roles[0] : $roles[1];
    }

    public function platformAdminRole(): array {
        return [
            'id' => 'platform_admin',
            'name' => 'Superadmin plataforma',
            'description' => 'Gestiona tenants, modulos y configuracion global del dashboard.',
            'permissions' => [self::PLATFORM_ADMIN_PERMISSION],
            'system' => true,
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c'),
        ];
    }

    public function customRoles(array $enabledModules): array {
        foreach ($this->systemRoles($enabledModules) as $systemRole) {
            $this->identityAccessRepository->syncRole($systemRole);
        }

        return array_values(array_filter(
            $this->identityAccessRepository->roles(),
            static fn (array $role): bool => empty($role['system'])
        ));
    }

    public function allRoles(array $enabledModules): array {
        $this->customRoles($enabledModules);
        return $this->identityAccessRepository->roles();
    }

    public function saveCustomRoles(array $roles): void {
        foreach ($roles as $role) {
            if (is_array($role) && empty($role['system'])) {
                $this->identityAccessRepository->syncRole($role);
            }
        }
    }

    public function deleteCustomRole(string $roleId): void {
        $this->identityAccessRepository->deleteRole($roleId);
    }

    public function rolesForTenantUser(array $user, array $enabledModules): array {
        $userId = trim((string)($user['id'] ?? $user['sub'] ?? ''));
        if ($userId === '') {
            return [];
        }

        $storedRoles = $this->identityAccessRepository->rolesForUser($userId);
        $membership = $this->identityAccessRepository->membershipForUser($userId);
        if ($membership === null || strtolower(trim((string)($membership['status'] ?? ''))) !== 'active') {
            return [];
        }

        // Una membresía sin asignaciones es un estado relacional incompleto, no
        // una migración terminada. Las lecturas de contexto fallan cerradas y no
        // escriben roles; la reparación legacy se ejecuta solo mediante el
        // reconciliador explícito y auditable.
        return $storedRoles;
    }

    public static function tenantUsesGranularNavigationAccess(string $tenantId): bool {
        return in_array(
            strtolower(trim($tenantId)),
            self::GRANULAR_ACCESS_TENANT_IDS,
            true
        );
    }

    public static function isLegacyTenantAdminRoleReconciliationCandidate(
        string $tenantId,
        array $user,
        ?array $membership,
        array $assignedRoleIds
    ): bool {
        $tenantId = strtolower(trim($tenantId));
        if ($tenantId === '' || self::tenantUsesGranularNavigationAccess($tenantId)) {
            return false;
        }

        $userTenantId = strtolower(trim((string)($user['tenant_id'] ?? $tenantId)));
        return $userTenantId === $tenantId
            && strtolower(trim((string)($user['role'] ?? ''))) === 'admin'
            && strtolower(trim((string)($membership['identity_type'] ?? ''))) === 'tenant_staff'
            && strtolower(trim((string)($membership['status'] ?? ''))) === 'active'
            && $assignedRoleIds === [];
    }

    public function permissionsFromRoles(array $roles): array {
        $permissions = [];
        foreach ($roles as $role) {
            foreach (is_array($role['permissions'] ?? null) ? $role['permissions'] : [] as $permission) {
                $permission = strtolower(trim((string)$permission));
                if ($permission !== '') {
                    $permissions[] = $permission;
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    public function identityTypeForUser(array $user, array $tenant = []): string {
        $userId = trim((string)($user['id'] ?? $user['sub'] ?? ''));
        if ($userId !== '') {
            $membership = $this->identityAccessRepository->membershipForUser($userId);
            $storedType = strtolower(trim((string)($membership['identity_type'] ?? '')));
            if (in_array($storedType, ['platform', 'tenant_staff', 'customer', 'service'], true)) {
                return $storedType;
            }
        }

        $profile = $this->decodeProfile($user['profile'] ?? null);
        $explicitType = strtolower(trim((string)($profile['identityType'] ?? $profile['identity_type'] ?? '')));
        if ($this->usesGranularNavigationAccess()) {
            if ($explicitType === 'platform' && $this->isPlatformAdmin($user, null, $tenant)) {
                return 'platform';
            }
            if ($explicitType === 'service' && strtolower(trim((string)($user['role'] ?? ''))) === 'service') {
                return 'service';
            }
            return 'customer';
        }
        if (in_array($explicitType, ['platform', 'tenant_staff', 'customer', 'service'], true)) {
            return $explicitType;
        }

        $role = strtolower(trim((string)($user['role'] ?? 'customer')));
        if ($role === 'service') {
            return 'service';
        }

        if ($this->isPlatformAdmin($user, $role, $tenant)) {
            return 'platform';
        }

        if ($this->isManagedTenantStaff($user)) {
            return 'tenant_staff';
        }

        return 'customer';
    }

    public function isPlatformAdmin(array $user, ?string $role = null, array $tenant = []): bool {
        $role = strtolower(trim((string)($role ?? ($user['role'] ?? 'customer'))));
        if ($role === 'service') {
            return true;
        }

        if ($this->usesGranularNavigationAccess()) {
            // En Fidepuntos la plataforma se reconoce por su identidad global
            // central, nunca por roleIds/position/dominio conservados en profile.
            return strtolower(trim((string)($user['tenant_id'] ?? ''))) === 'platform';
        }

        $profile = $this->decodeProfile($user['profile'] ?? null);
        $explicitType = strtolower(trim((string)($profile['identityType'] ?? $profile['identity_type'] ?? '')));
        if (in_array($explicitType, ['tenant_staff', 'customer'], true)) {
            return false;
        }

        $roleIds = $this->normalizeRoleIds($profile['roleIds'] ?? null);
        foreach ($roleIds as $roleId) {
            if (in_array($roleId, ['platform_admin', 'superadmin'], true)) {
                return true;
            }
        }

        $position = strtolower(trim((string)($profile['position'] ?? '')));
        if (str_contains($position, 'superadmin') || str_contains($position, 'plataforma')) {
            return true;
        }

        $email = strtolower(trim((string)($user['email'] ?? '')));
        $explicitEmails = array_filter(array_map(
            static fn ($value) => strtolower(trim((string)$value)),
            is_array($tenant['platform_admin_emails'] ?? null) ? $tenant['platform_admin_emails'] : []
        ));

        return $this->emailMatchesPlatformDomain($email, $tenant)
            || in_array($email, $explicitEmails, true);
    }

    public function isManagedTenantStaff(array $user): bool {
        $userId = trim((string)($user['id'] ?? $user['sub'] ?? ''));
        if ($userId !== '') {
            $membership = $this->identityAccessRepository->membershipForUser($userId);
            if ($membership !== null) {
                return ($membership['identity_type'] ?? '') === 'tenant_staff';
            }
        }
        if ($this->usesGranularNavigationAccess()) {
            return false;
        }

        $profile = $this->decodeProfile($user['profile'] ?? null);
        $roleIds = $this->normalizeRoleIds($profile['roleIds'] ?? null);

        if ($roleIds !== []) {
            return !array_reduce(
                $roleIds,
                static fn (bool $onlyCustomers, string $roleId): bool => $onlyCustomers && in_array($roleId, self::CUSTOMER_ROLE_IDS, true),
                true
            );
        }

        if (strtolower(trim((string)($user['role'] ?? ''))) === 'admin') {
            return true;
        }

        return $this->nullableText($profile['department'] ?? null, 120) !== null
            || $this->nullableText($profile['position'] ?? null, 120) !== null
            || $this->nullableText($profile['description'] ?? null, 500) !== null;
    }

    public function syncUserMembership(array $user, array $tenant = [], ?string $status = null): void {
        $userId = (string)($user['id'] ?? $user['sub'] ?? '');
        if ($userId === '') {
            return;
        }

        $identityType = $this->identityTypeForUser($user, $tenant);
        if ($this->usesGranularNavigationAccess()) {
            $profile = $this->decodeProfile($user['profile'] ?? null);
            $explicitType = strtolower(trim((string)($profile['identityType'] ?? $profile['identity_type'] ?? '')));
            if (in_array($explicitType, ['platform', 'tenant_staff', 'customer', 'service'], true)) {
                $identityType = $explicitType;
            }
        }
        $existingMembership = $this->identityAccessRepository->membershipForUser($userId);
        $roleIds = $this->identityAccessRepository->roleIdsForUser($userId);
        if ($roleIds === [] && $identityType !== 'customer') {
            foreach ($this->systemRoles($this->enabledModulesForTenant($tenant), TenantContext::slug() ?: 'tenant') as $systemRole) {
                $this->identityAccessRepository->syncRole($systemRole);
            }
            $profile = $this->decodeProfile($user['profile'] ?? null);
            $roleIds = $this->normalizeRoleIds($profile['roleIds'] ?? null);
        }
        if ($roleIds === [] && $identityType !== 'customer') {
            $databaseRole = strtolower(trim((string)($user['role'] ?? 'customer')));
            $roleIds = [
                $databaseRole === 'admin' ? $this->defaultRoleId('admin') : $this->defaultRoleId('reader')
            ];
        }

        $this->identityAccessRepository->syncMembership(
            $userId,
            $identityType,
            $roleIds,
            (string)($existingMembership['status'] ?? ($status ?? 'active'))
        );
    }

    public function routeAccessDecision(?string $capability, string $method, string $uri): array {
        $capability = strtolower(trim((string)$capability));
        $method = strtoupper($method);
        if ($capability === '' || ($this->isCapabilityPublic($capability) && !$this->isAdminCatalogProjection($capability))) {
            return ['requiresPermission' => false];
        }

        if ($capability === 'admin.tenants') {
            return [
                'requiresPermission' => true,
                'permission' => self::PLATFORM_ADMIN_PERMISSION,
                'module' => null,
            ];
        }

        if ($capability === 'users.admin') {
            if (str_starts_with($uri, '/api/access/audit')) {
                return [
                    'requiresPermission' => true,
                    'permission' => 'identity.users.view',
                    'module' => 'users',
                ];
            }
            if (str_starts_with($uri, '/api/roles')) {
                if ($method === 'POST' && $uri === '/api/roles') {
                    $decision = [
                        'requiresPermission' => true,
                        'permission' => 'identity.roles.create',
                        'module' => 'users',
                    ];
                    if ($this->usesGranularNavigationAccess()) {
                        $decision['permissions'] = ['identity.roles.create', 'identity.roles.assign_roles'];
                    }
                    return $decision;
                }
                $action = match (true) {
                    $method === 'DELETE' => 'delete',
                    $method === 'PUT' && str_ends_with($uri, '/navigation-grants') => 'assign_roles',
                    in_array($method, ['PUT', 'PATCH'], true) => 'update',
                    default => 'view',
                };
                return [
                    'requiresPermission' => true,
                    'permission' => "identity.roles.{$action}",
                    'module' => 'users',
                ];
            }
            if ($method === 'POST' && $uri === '/api/users') {
                $decision = [
                    'requiresPermission' => true,
                    'permission' => 'identity.users.create',
                    'module' => 'users',
                ];
                if ($this->usesGranularNavigationAccess()) {
                    $decision['permissions'] = ['identity.users.create', 'identity.users.assign_roles'];
                }
                return $decision;
            }
            $action = match (true) {
                str_ends_with($uri, '/roles') => 'assign_roles',
                str_ends_with($uri, '/unlock') => 'unlock',
                str_ends_with($uri, '/invitation') => 'invite',
                str_ends_with($uri, '/sessions/revoke') => 'revoke_sessions',
                str_ends_with($uri, '/password-reset') => 'invite',
                in_array($method, ['PUT', 'PATCH'], true) => 'update',
                default => 'view',
            };
            return [
                'requiresPermission' => true,
                'permission' => "identity.users.{$action}",
                'module' => 'users',
            ];
        }

        if (str_starts_with($uri, '/api/admin/billing')) {
            return [
                'requiresPermission' => true,
                'permission' => $this->billingPermissionForRoute($method, $uri),
                'module' => 'billing-sri',
            ];
        }

        if ($this->isCommerceAdminRoute($capability, $uri)) {
            return [
                'requiresPermission' => true,
                'permission' => $this->permissionForMethod('ecommerce', $method),
                'module' => 'ecommerce',
            ];
        }

        if ($this->isProductAdminRoute($capability, $uri)) {
            return [
                'requiresPermission' => true,
                'permission' => $this->permissionForMethod('ecommerce', $method),
                'module' => 'ecommerce',
            ];
        }

        if ($this->isInventoryAdminRoute($capability, $uri)) {
            return [
                'requiresPermission' => true,
                'permission' => $this->permissionForMethod('ecommerce', $method),
                'module' => 'ecommerce',
            ];
        }

        if ($this->isReportingRoute($capability, $uri)) {
            return [
                'requiresPermission' => true,
                'permission' => $this->permissionForMethod('ecommerce', $method),
                'module' => 'ecommerce',
            ];
        }

        if ($capability === 'mail.service' || str_starts_with($uri, '/api/admin/mailer')) {
            return [
                'requiresPermission' => true,
                'permission' => $this->permissionForMethod('email-service', $method),
                'module' => 'email-service',
            ];
        }

        if ($capability === 'loyalty.admin' || str_starts_with($uri, '/api/admin/loyalty')) {
            $permission = (new LoyaltyNavigationService())->requiredPermissionForRequest($method, $uri)
                ?? LoyaltyNavigationService::DENY_PERMISSION;
            return [
                'requiresPermission' => true,
                'permission' => $permission,
                'module' => str_starts_with($uri, '/api/admin/loyalty/navigation/catalog')
                    ? 'users'
                    : 'loyalty-points',
            ];
        }

        if ($capability === 'admin.settings') {
            if (str_starts_with($uri, '/api/admin/settings/session')) {
                return [
                    'requiresPermission' => true,
                    'permission' => $this->permissionForMethod('users', $method),
                    'module' => 'users',
                ];
            }

            return [
                'requiresPermission' => true,
                'permission' => $this->permissionForMethod('ecommerce', $method),
                'module' => 'ecommerce',
            ];
        }

        return ['requiresPermission' => false];
    }

    public function userPermissions(array $user, array $tenant): array {
        $enabledModules = $this->enabledModulesForTenant($tenant);
        $role = strtolower(trim((string)($user['role'] ?? 'customer')));
        if ($this->isPlatformAdmin($user, $role, $tenant)) {
            return [self::PLATFORM_ADMIN_PERMISSION];
        }

        $permissions = $this->permissionsFromRoles($this->rolesForTenantUser($user, $enabledModules));
        $userId = trim((string)($user['id'] ?? $user['sub'] ?? ''));
        if ($userId !== '') {
            $permissions = array_merge(
                $permissions,
                $this->identityAccessRepository->navigationPermissionsForUser($userId)
            );
        }
        $legacyAliases = [
            'users.read' => 'identity.users.view',
            'users.create' => 'identity.users.create',
            'users.update' => 'identity.users.update',
            'users.delete' => 'identity.users.delete',
            'roles.read' => 'identity.roles.view',
            'roles.create' => 'identity.roles.create',
            'roles.update' => 'identity.roles.update',
            'roles.delete' => 'identity.roles.delete',
        ];
        $tenantId = strtolower(trim((string)($tenant['id'] ?? $tenant['slug'] ?? TenantContext::id() ?? '')));
        if ($tenantId === 'fidepuntos') {
            $permissions = array_merge($permissions, [
                'identity.account-security.view',
                'identity.account-security.update',
                'identity.account-security.revoke_sessions',
            ]);
        } else {
            foreach ($legacyAliases as $legacy => $current) {
                if (in_array($legacy, $permissions, true)) {
                    $permissions[] = $current;
                }
            }
            if (in_array('users.update', $permissions, true)) {
                $permissions = array_merge($permissions, [
                    'identity.users.assign_roles',
                    'identity.users.unlock',
                    'identity.users.invite',
                    'identity.users.revoke_sessions',
                ]);
            }
        }
        return array_values(array_unique($permissions));
    }

    public function moduleEnabledForTenant(?string $moduleKey, array $tenant): bool {
        if ($moduleKey === null || $moduleKey === '') {
            return true;
        }

        return in_array($moduleKey, $this->enabledModulesForTenant($tenant), true);
    }

    public function userHasPermission(array $user, array $tenant, string $permission): bool {
        $permission = strtolower(trim($permission));
        if ($permission === '') {
            return true;
        }

        $permissions = $this->userPermissions($user, $tenant);
        return in_array(self::PLATFORM_ADMIN_PERMISSION, $permissions, true)
            || in_array($permission, $permissions, true);
    }

    public function accessVersion(?string $tenantId = null): string {
        return $this->identityAccessRepository->accessVersion($tenantId);
    }

    public function navigationGrantsForUser(string $userId, ?string $tenantId = null): array {
        return $this->identityAccessRepository->navigationGrantsForUser($userId, $tenantId);
    }

    private function normalizeKnownModuleList(array $modules): array {
        $normalized = [];
        foreach ($modules as $moduleKey) {
            $moduleKey = strtolower(trim((string)$moduleKey));
            if ($moduleKey !== '' && array_key_exists($moduleKey, self::MODULE_PERMISSION_ACTIONS)) {
                $normalized[] = $moduleKey;
            }
        }

        foreach (['users', 'dashboard'] as $baseModule) {
            if (!in_array($baseModule, $normalized, true)) {
                array_unshift($normalized, $baseModule);
            }
        }

        return array_values(array_unique($normalized));
    }

    private function usesGranularNavigationAccess(): bool {
        return self::tenantUsesGranularNavigationAccess(
            (string)(TenantContext::slug() ?? TenantContext::id() ?? '')
        );
    }

    private function permissionForMethod(string $resource, string $method): string {
        $action = match ($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'read',
        };

        return "{$resource}.{$action}";
    }

    private function billingPermissionForRoute(string $method, string $uri): string {
        if ($method === 'GET') {
            return 'billing-sri.read';
        }

        if (str_contains($uri, '/configuration') || str_contains($uri, '/branches')) {
            return 'billing-sri.update';
        }

        return 'billing-sri.create';
    }

    private function isCapabilityPublic(string $capability): bool {
        return in_array($capability, [
            'catalog.public',
            'catalog.reviews',
            'content.contact',
            'orders.checkout',
            'loyalty.public',
            'security.telemetry',
            'system.health',
            'users.auth',
            'users.profile',
        ], true);
    }

    private function isCommerceAdminRoute(string $capability, string $uri): bool {
        return in_array($capability, ['admin.pos', 'admin.quotes', 'admin.discounts', 'admin.operations', 'admin.ecommerce-users'], true)
            || str_starts_with($uri, '/api/admin/pos')
            || str_starts_with($uri, '/api/admin/quotes')
            || str_starts_with($uri, '/api/admin/discounts')
            || str_starts_with($uri, '/api/admin/ecommerce-users')
            || str_starts_with($uri, '/api/admin/historical-sales')
            || str_starts_with($uri, '/api/shipments');
    }

    private function isProductAdminRoute(string $capability, string $uri): bool {
        return in_array($capability, ['catalog.admin', 'catalog.reviews.admin'], true)
            || (str_starts_with($uri, '/api/products') && $this->isAdminCatalogProjection($capability))
            || str_starts_with($uri, '/api/admin/products')
            || str_starts_with($uri, '/api/admin/reviews');
    }

    private function isAdminCatalogProjection(string $capability): bool {
        return $capability === 'catalog.public'
            && strtolower(trim((string)($_GET['scope'] ?? ''))) === 'admin';
    }

    private function isInventoryAdminRoute(string $capability, string $uri): bool {
        return $capability === 'admin.procurement'
            || str_starts_with($uri, '/api/admin/purchase-invoices')
            || str_contains($uri, '/inventory');
    }

    private function isReportingRoute(string $capability, string $uri): bool {
        return in_array($capability, ['admin.reporting', 'admin.finance'], true)
            || str_starts_with($uri, '/api/reports/')
            || str_starts_with($uri, '/api/admin/dashboard')
            || str_starts_with($uri, '/api/admin/expenses')
            || str_starts_with($uri, '/api/admin/financial');
    }

    private function emailMatchesPlatformDomain(string $email, array $tenant): bool {
        if ($email === '' || !str_contains($email, '@')) {
            return false;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domain === false || $domain === '') {
            return false;
        }

        $configuredDomains = is_array($tenant['platform_admin_domains'] ?? null)
            ? $tenant['platform_admin_domains']
            : [];
        if ($configuredDomains === []) {
            $configuredDomains = ['tecnolts.com'];
        }

        $allowedDomains = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(ltrim(trim((string)$value), '@')),
            $configuredDomains
        ))));

        return in_array(strtolower($domain), $allowedDomains, true);
    }

    private function decodeProfile($profile): array {
        if (is_array($profile)) {
            return $profile;
        }

        if (!is_string($profile) || trim($profile) === '') {
            return [];
        }

        $decoded = json_decode($profile, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeRoleIds($value): array {
        if (!is_array($value)) {
            return [];
        }

        $roleIds = [];
        foreach ($value as $roleId) {
            $normalized = strtolower(trim((string)$roleId));
            if ($normalized !== '') {
                $roleIds[] = $normalized;
            }
        }

        return array_values(array_unique($roleIds));
    }

    private function defaultRoleId(string $type): string {
        $tenantSlug = TenantContext::slug() ?: 'tenant';
        return "{$tenantSlug}_{$type}";
    }

    private function nullableText($value, int $maxLength = 255): ?string {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
        }

        return $text;
    }
}
