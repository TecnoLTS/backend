<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;

class TenantController {
    private UserRepository $userRepository;
    private SettingsRepository $settingsRepository;
    private TenantAccessService $tenantAccessService;

    private const TENANT_ADMIN_OVERRIDES_KEY = TenantAccessService::TENANT_ADMIN_OVERRIDES_KEY;
    private const DASHBOARD_DEFAULT_LOGO_URL = 'assets/images/logo.png';
    private const DASHBOARD_DEFAULT_LOGO_LIGHT_URL = 'assets/images/logo-light.png';
    private const DASHBOARD_DEFAULT_LOGO_ICON_URL = 'assets/images/logo-icon.png';
    private const PARAMASCOTAS_LOGO_URL = 'assets/images/tenants/paramascotasec-logo.svg';
    private const PARAMASCOTAS_LOGO_ICON_URL = 'assets/images/tenants/paramascotasec-logo.png';

    public function __construct() {
        $this->userRepository = new UserRepository();
        $this->settingsRepository = new SettingsRepository();
        $this->tenantAccessService = new TenantAccessService($this->settingsRepository);
    }

    public function context() {
        $payload = Auth::requireUser();
        $tenant = $this->withStoredAdminConfig(TenantContext::get() ?? []);

        if (!$tenant) {
            Response::error('Tenant no disponible', 404, 'TENANT_NOT_FOUND');
            return;
        }

        $tenantModules = $this->enabledModules($tenant);
        $branding = $this->branding($tenant);

        if (!empty($payload['service_auth'])) {
            Response::noStore();
            Response::json([
                'tenant' => $this->tenantIdentity($tenant),
                'enabledModules' => $this->platformAdminContextModules($tenantModules),
                'permissions' => ['platform-admin'],
                'roles' => [$this->platformAdminRole()],
                'currentUser' => [
                    'id' => 'service',
                    'name' => (string)($payload['name'] ?? 'dashboard internal proxy'),
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
        if ($platformAdmin) {
            $enabledModules = $this->platformAdminContextModules($tenantModules);
            $roles = [$this->platformAdminRole()];
            $permissions = ['platform-admin'];
        } elseif ($role === 'admin' && $this->usesImplicitTenantAdminRole($user)) {
            $enabledModules = $tenantModules;
            $roles = [$this->tenantRole(TenantContext::slug() ?: 'tenant', $enabledModules, true)];
            $permissions = $roles[0]['permissions'];
        } else {
            $enabledModules = $tenantModules;
            $roles = $this->rolesForTenantUser($user, $enabledModules);
            $permissions = $this->permissionsFromRoles($roles);
        }

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
                'roleIds' => array_values(array_map(static fn (array $role): string => (string)($role['id'] ?? ''), $roles)),
                'permissions' => $permissions,
            ],
            'branding' => $branding,
        ]);
    }

    public function adminIndex() {
        $this->requirePlatformAdmin();

        Response::noStore();
        Response::json(array_map(
            fn (array $tenant): array => $this->tenantAdminSummary($tenant),
            $this->allAdminTenants()
        ));
    }

    public function adminCreate() {
        $this->requirePlatformAdmin();

        $data = $this->requestJson();
        $name = trim((string)($data['name'] ?? ''));
        $slug = $this->normalizeSlug((string)($data['slug'] ?? ''));
        if ($name === '' || $slug === '') {
            Response::error('Nombre y slug del tenant son obligatorios.', 400, 'TENANT_INVALID');
            return;
        }

        if ($this->findAdminTenant($slug) !== null) {
            Response::error('Ya existe un tenant con ese slug.', 409, 'TENANT_SLUG_EXISTS');
            return;
        }

        $modules = $this->normalizeEnabledModulePayload($data['enabledModules'] ?? []);
        if ($modules === null) {
            return;
        }

        $tenant = [
            'id' => $slug,
            'slug' => $slug,
            'name' => $name,
            'status' => 'active',
            'enabled_modules' => $modules,
            'ecommerce_configuration' => $this->sanitizeEcommerceConfiguration(
                $data['ecommerceConfiguration'] ?? null,
                in_array('ecommerce', $modules, true)
            ),
            'branding' => [
                'logo_url' => 'assets/images/logo.png',
                'logo_light_url' => 'assets/images/logo-light.png',
                'logo_icon_url' => 'assets/images/logo-icon.png',
                'primary_color' => '#2563eb',
            ],
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];

        $overrides = $this->tenantAdminOverrides();
        $overrides['tenants'][$slug] = $tenant;
        $this->saveTenantAdminOverrides($overrides);
        $this->tenantAccessService->syncTenantModuleEntitlements($tenant, $modules);

        Response::json($this->tenantAdminSummary($tenant), 201);
    }

    public function adminUpdateModules(string $tenantId) {
        $this->requirePlatformAdmin();

        $tenant = $this->findAdminTenant($tenantId);
        if ($tenant === null) {
            Response::error('Tenant no encontrado.', 404, 'TENANT_NOT_FOUND');
            return;
        }

        $data = $this->requestJson();
        $modules = $this->normalizeEnabledModulePayload($data['enabledModules'] ?? null);
        if ($modules === null) {
            return;
        }

        $tenant['enabled_modules'] = $modules;
        if (!in_array('ecommerce', $modules, true)) {
            $tenant['ecommerce_configuration'] = null;
        } elseif (!isset($tenant['ecommerce_configuration']) || !is_array($tenant['ecommerce_configuration'])) {
            $tenant['ecommerce_configuration'] = $this->defaultEcommerceConfiguration();
        }
        $tenant['updated_at'] = gmdate('c');

        $this->persistAdminTenant($tenant);
        $this->tenantAccessService->syncTenantModuleEntitlements($tenant, $modules);

        Response::json($this->tenantAdminSummary($tenant));
    }

    public function adminUpdateConfiguration(string $tenantId) {
        $this->requirePlatformAdmin();

        $tenant = $this->findAdminTenant($tenantId);
        if ($tenant === null) {
            Response::error('Tenant no encontrado.', 404, 'TENANT_NOT_FOUND');
            return;
        }

        $modules = $this->enabledModules($tenant);
        if (!in_array('ecommerce', $modules, true)) {
            Response::error('El tenant no tiene contratado el modulo Ecommerce.', 409, 'TENANT_ECOMMERCE_NOT_ENABLED');
            return;
        }

        $data = $this->requestJson();
        $tenant['ecommerce_configuration'] = $this->sanitizeEcommerceConfiguration($data['ecommerceConfiguration'] ?? null, true);
        $tenant['updated_at'] = gmdate('c');

        $this->persistAdminTenant($tenant);

        Response::json($this->tenantAdminSummary($tenant));
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
        return $this->tenantAccessService->enabledModulesForTenant($tenant);
    }

    private function platformAdminContextModules(array $tenantModules): array {
        return $this->tenantAccessService->platformAdminContextModules($tenantModules);
    }

    private function branding(array $tenant): array {
        $branding = $tenant['branding'] ?? [];
        $tenantSlug = (string)($tenant['slug'] ?? TenantContext::slug() ?? '');
        $knownBranding = $this->knownTenantBranding($tenantSlug);

        return [
            'logoUrl' => $this->resolveBrandingLogo($branding['logo_url'] ?? null, $knownBranding['logo_url'] ?? self::DASHBOARD_DEFAULT_LOGO_URL),
            'logoLightUrl' => $this->resolveBrandingLogo($branding['logo_light_url'] ?? null, $knownBranding['logo_light_url'] ?? self::DASHBOARD_DEFAULT_LOGO_LIGHT_URL),
            'logoIconUrl' => $this->resolveBrandingLogo($branding['logo_icon_url'] ?? null, $knownBranding['logo_icon_url'] ?? self::DASHBOARD_DEFAULT_LOGO_ICON_URL),
            'primaryColor' => (string)($branding['primary_color'] ?? $knownBranding['primary_color'] ?? '#f97316'),
        ];
    }

    private function knownTenantBranding(string $tenantSlug): ?array {
        if ($tenantSlug !== 'paramascotasec') {
            return null;
        }

        return [
            'logo_url' => self::PARAMASCOTAS_LOGO_URL,
            'logo_light_url' => self::PARAMASCOTAS_LOGO_URL,
            'logo_icon_url' => self::PARAMASCOTAS_LOGO_ICON_URL,
            'primary_color' => '#0a7b8f',
        ];
    }

    private function resolveBrandingLogo($value, string $fallback): string {
        $logoUrl = trim((string)($value ?? ''));
        if ($logoUrl === '' || $this->isDefaultDashboardLogo($logoUrl)) {
            return $fallback;
        }

        return $logoUrl;
    }

    private function isDefaultDashboardLogo(string $value): bool {
        return in_array($value, [
            self::DASHBOARD_DEFAULT_LOGO_URL,
            self::DASHBOARD_DEFAULT_LOGO_LIGHT_URL,
            self::DASHBOARD_DEFAULT_LOGO_ICON_URL,
            '/' . self::DASHBOARD_DEFAULT_LOGO_URL,
            '/' . self::DASHBOARD_DEFAULT_LOGO_LIGHT_URL,
            '/' . self::DASHBOARD_DEFAULT_LOGO_ICON_URL,
        ], true);
    }

    private function tenantAdminSummary(array $tenant): array {
        $enabledModules = $this->enabledModules($tenant);

        return [
            'id' => (string)($tenant['id'] ?? $tenant['slug'] ?? 'tenant'),
            'slug' => (string)($tenant['slug'] ?? $tenant['id'] ?? 'tenant'),
            'name' => (string)($tenant['name'] ?? 'Tenant'),
            'status' => (string)($tenant['status'] ?? 'active'),
            'enabledModules' => $enabledModules,
            'ecommerceConfiguration' => in_array('ecommerce', $enabledModules, true)
                ? $this->sanitizeEcommerceConfiguration($tenant['ecommerce_configuration'] ?? null, true)
                : null,
            'branding' => $this->branding($tenant),
        ];
    }

    private function isPlatformAdmin(array $user, string $role, array $tenant): bool {
        return $this->tenantAccessService->isPlatformAdmin($user, $role, $tenant);
    }

    private function requirePlatformAdmin(): array {
        $payload = Auth::requireAdmin();
        if (!empty($payload['service_auth'])) {
            return $payload;
        }

        $tenant = $this->withStoredAdminConfig(TenantContext::get() ?? []);
        $user = $this->userRepository->getById((string)($payload['sub'] ?? ''));
        $role = strtolower(trim((string)($user['role'] ?? $payload['role'] ?? 'customer')));
        if (!$user || !$this->isPlatformAdmin($user, $role, $tenant)) {
            Response::error('No tienes permiso para administrar tenants.', 403, 'PLATFORM_ADMIN_REQUIRED');
            exit;
        }

        return $payload;
    }

    private function requestJson(): array {
        $rawInput = file_get_contents('php://input');
        $data = is_string($rawInput) && trim($rawInput) !== '' ? json_decode($rawInput, true) : [];
        if (!is_array($data)) {
            Response::error('JSON invalido.', 400, 'INVALID_JSON');
            exit;
        }

        return $data;
    }

    private function normalizeEnabledModulePayload($rawModules): ?array {
        $normalized = $this->tenantAccessService->normalizeEnabledModulePayload($rawModules);
        if (!is_array($rawModules)) {
            Response::error('La lista de modulos contratados debe ser un arreglo.', 400, 'TENANT_MODULES_INVALID');
            return null;
        }

        if ($normalized['invalidModules'] !== []) {
            Response::error(
                'Uno o mas modulos no estan registrados en el catalogo central.',
                400,
                'TENANT_MODULES_INVALID',
                ['invalidModules' => $normalized['invalidModules']]
            );
            return null;
        }

        return $normalized['modules'];
    }

    private function allAdminTenants(): array {
        $tenants = array_values($this->configuredTenants());
        $configuredIds = [];
        foreach ($tenants as $tenant) {
            $configuredIds[] = (string)($tenant['id'] ?? $tenant['slug'] ?? '');
        }

        $overrides = $this->tenantAdminOverrides();
        foreach (($overrides['tenants'] ?? []) as $tenantId => $tenantOverride) {
            if (!is_array($tenantOverride)) {
                continue;
            }
            if (in_array((string)$tenantId, $configuredIds, true)) {
                continue;
            }
            $tenants[] = $tenantOverride;
        }

        return array_values(array_map(fn (array $tenant): array => $this->withStoredAdminConfig($tenant), $tenants));
    }

    private function configuredTenants(): array {
        $path = __DIR__ . '/../../config/tenants.php';
        $tenants = is_readable($path) ? require $path : [];
        return is_array($tenants) ? $tenants : [];
    }

    private function findAdminTenant(string $tenantId): ?array {
        $needle = strtolower(trim($tenantId));
        if ($needle === '') {
            return null;
        }

        foreach ($this->allAdminTenants() as $tenant) {
            $id = strtolower((string)($tenant['id'] ?? ''));
            $slug = strtolower((string)($tenant['slug'] ?? ''));
            if ($needle === $id || $needle === $slug) {
                return $tenant;
            }
        }

        return null;
    }

    private function persistAdminTenant(array $tenant): void {
        $tenantId = (string)($tenant['id'] ?? $tenant['slug'] ?? '');
        if ($tenantId === '') {
            return;
        }

        $overrides = $this->tenantAdminOverrides();
        $storedTenant = $overrides['tenants'][$tenantId] ?? [];
        if (!is_array($storedTenant)) {
            $storedTenant = [];
        }
        $overrides['tenants'][$tenantId] = array_replace($storedTenant, [
            'id' => (string)($tenant['id'] ?? $tenantId),
            'slug' => (string)($tenant['slug'] ?? $tenantId),
            'name' => (string)($tenant['name'] ?? 'Tenant'),
            'status' => (string)($tenant['status'] ?? 'active'),
            'enabled_modules' => $this->enabledModules($tenant),
            'ecommerce_configuration' => $tenant['ecommerce_configuration'] ?? null,
            'branding' => $tenant['branding'] ?? [],
            'updated_at' => gmdate('c'),
        ]);

        $this->saveTenantAdminOverrides($overrides);
    }

    private function withStoredAdminConfig(array $tenant): array {
        if ($tenant === []) {
            return [];
        }

        $tenantId = (string)($tenant['id'] ?? $tenant['slug'] ?? '');
        if ($tenantId === '') {
            return $tenant;
        }

        $overrides = $this->tenantAdminOverrides();
        $storedTenant = $overrides['tenants'][$tenantId] ?? null;
        if (!is_array($storedTenant)) {
            return $tenant;
        }

        $tenant['status'] = $storedTenant['status'] ?? ($tenant['status'] ?? 'active');
        $tenant['enabled_modules'] = $storedTenant['enabled_modules'] ?? ($tenant['enabled_modules'] ?? []);
        $tenant['ecommerce_configuration'] = $storedTenant['ecommerce_configuration'] ?? ($tenant['ecommerce_configuration'] ?? null);
        $tenant['branding'] = array_replace(
            is_array($tenant['branding'] ?? null) ? $tenant['branding'] : [],
            is_array($storedTenant['branding'] ?? null) ? $storedTenant['branding'] : []
        );

        return $tenant;
    }

    private function tenantAdminOverrides(): array {
        $overrides = $this->settingsRepository->getJson(self::TENANT_ADMIN_OVERRIDES_KEY, ['tenants' => []]);
        if (!is_array($overrides)) {
            return ['tenants' => []];
        }
        if (!isset($overrides['tenants']) || !is_array($overrides['tenants'])) {
            $overrides['tenants'] = [];
        }
        return $overrides;
    }

    private function saveTenantAdminOverrides(array $overrides): void {
        if (!isset($overrides['tenants']) || !is_array($overrides['tenants'])) {
            $overrides['tenants'] = [];
        }
        $this->settingsRepository->setJson(self::TENANT_ADMIN_OVERRIDES_KEY, $overrides);
    }

    private function normalizeSlug(string $value): string {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug;
    }

    private function sanitizeEcommerceConfiguration($configuration, bool $ecommerceEnabled): ?array {
        if (!$ecommerceEnabled) {
            return null;
        }

        if (!is_array($configuration)) {
            return $this->defaultEcommerceConfiguration();
        }

        $verticals = ['petshop', 'technology', 'fashion', 'hardware', 'supermarket', 'pharmacy', 'other'];
        $capabilities = [
            'products',
            'categories',
            'attributes',
            'variants',
            'images',
            'inventory',
            'pricing',
            'orders',
            'invoicing',
            'payments',
            'customers',
            'shipping',
            'reporting',
        ];

        $vertical = strtolower(trim((string)($configuration['vertical'] ?? 'other')));
        if (!in_array($vertical, $verticals, true)) {
            $vertical = 'other';
        }

        $enabledCapabilities = [];
        foreach (($configuration['enabledCapabilities'] ?? []) as $capability) {
            $normalized = strtolower(trim((string)$capability));
            if ($normalized !== '' && in_array($normalized, $capabilities, true)) {
                $enabledCapabilities[] = $normalized;
            }
        }

        if ($enabledCapabilities === []) {
            $enabledCapabilities = ['products', 'categories', 'attributes', 'variants', 'images', 'inventory', 'pricing', 'orders', 'invoicing', 'payments', 'customers', 'shipping', 'reporting'];
        }

        return [
            'vertical' => $vertical,
            'businessLabel' => $this->nullableTrimmedString($configuration['businessLabel'] ?? null),
            'enabledCapabilities' => array_values(array_unique($enabledCapabilities)),
            'notes' => $this->nullableTrimmedString($configuration['notes'] ?? null),
        ];
    }

    private function defaultEcommerceConfiguration(): array {
        return [
            'vertical' => 'petshop',
            'businessLabel' => 'Petshop omnicanal',
            'enabledCapabilities' => ['products', 'categories', 'attributes', 'variants', 'images', 'inventory', 'pricing', 'orders', 'invoicing', 'payments', 'customers', 'shipping', 'reporting'],
            'notes' => null,
        ];
    }

    private function nullableTrimmedString($value): ?string {
        $trimmed = trim((string)($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }

    private function tenantAdminPermissions(array $enabledModules): array {
        return $this->tenantAccessService->tenantAdminPermissions($enabledModules);
    }

    private function readOnlyPermissions(array $enabledModules): array {
        return $this->tenantAccessService->readOnlyPermissions($enabledModules);
    }

    private function rolesForTenantUser(array $user, array $enabledModules): array {
        return $this->tenantAccessService->rolesForTenantUser($user, $enabledModules);
    }

    private function usesImplicitTenantAdminRole(array $user): bool {
        $profile = $this->decodeProfile($user['profile'] ?? null);
        $roleIds = $this->normalizeRoleIds($profile['roleIds'] ?? null);
        if ($roleIds === []) {
            return true;
        }

        return in_array($this->defaultRoleId('admin'), $roleIds, true);
    }

    private function customRoles(array $enabledModules): array {
        return $this->tenantAccessService->customRoles($enabledModules);
    }

    private function permissionsFromRoles(array $roles): array {
        return $this->tenantAccessService->permissionsFromRoles($roles);
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

    private function tenantRole(string $tenantSlug, array $enabledModules, bool $admin): array {
        return $this->tenantAccessService->tenantRole($tenantSlug, $enabledModules, $admin);
    }

    private function defaultRoleId(string $type): string {
        $tenantSlug = TenantContext::slug() ?: 'tenant';
        return "{$tenantSlug}_{$type}";
    }

    private function platformAdminRole(): array {
        return $this->tenantAccessService->platformAdminRole();
    }
}
