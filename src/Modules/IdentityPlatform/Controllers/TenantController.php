<?php

namespace App\Modules\IdentityPlatform\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\IdentityPlatform\Application\Ports\TenantNavigationPort;
use App\Modules\IdentityPlatform\Application\TenantRuntimeMutationPolicy;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistryStore;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry;
use App\Modules\IdentityPlatform\Infrastructure\Navigation\TenantNavigationPortFactory;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Shared\Tax\EcuadorSriVatCatalog;

class TenantController {
    private UserRepository $userRepository;
    private SettingsRepository $settingsRepository;
    private TenantAccessService $tenantAccessService;
    private TenantRuntimeRegistryStore $tenantRuntimeRegistryStore;
    private TenantNavigationPort $tenantNavigation;
    private ?array $platformRegistryActor = null;

    private const DASHBOARD_DEFAULT_LOGO_URL = 'assets/images/logo.png';
    private const DASHBOARD_DEFAULT_LOGO_LIGHT_URL = 'assets/images/logo-light.png';
    private const DASHBOARD_DEFAULT_LOGO_ICON_URL = 'assets/images/logo-icon.png';
    private const PARAMASCOTAS_LOGO_URL = 'assets/images/tenants/paramascotasec-logo.svg';
    private const PARAMASCOTAS_LOGO_ICON_URL = 'assets/images/tenants/paramascotasec-logo.png';

    public function __construct() {
        $this->userRepository = new UserRepository();
        $this->settingsRepository = new SettingsRepository();
        $this->tenantNavigation = TenantNavigationPortFactory::create();
        $this->tenantAccessService = new TenantAccessService($this->settingsRepository, null, $this->tenantNavigation);
        $this->tenantRuntimeRegistryStore = new TenantRuntimeRegistryStore();
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
        } else {
            $enabledModules = $tenantModules;
            $roles = $this->rolesForTenantUser($user, $enabledModules);
            $permissions = $this->tenantAccessService->userPermissions($user, $tenant);
        }

        Response::noStore();
        Response::json(array_merge([
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
        ], $this->dynamicAccessContext($tenant, $permissions)));
    }

    public function adminIndex() {
        $this->requirePlatformAdmin();

        try {
            $state = $this->tenantRuntimeRegistryStore->getState();
            $this->setRegistryResponseHeaders($state['revision']);
            Response::noStore();
            Response::json(array_map(
                fn (array $tenant): array => $this->tenantAdminSummary($tenant),
                $this->allAdminTenants($state['registry'])
            ), 200, $this->registryMeta($state['revision']));
        } catch (\Throwable $exception) {
            error_log('[TENANT_REGISTRY_READ_FAILED] ' . $exception->getMessage());
            Response::error('El registro de tenants no esta disponible.', 503, 'TENANT_REGISTRY_UNAVAILABLE');
        }
    }

    public function adminCreate() {
        $this->requirePlatformAdmin();

        $data = $this->requestJson();
        $name = trim((string)($data['name'] ?? ''));
        $slug = $this->normalizeSlug((string)($data['slug'] ?? ''));
        $primaryDomain = $this->normalizeDomain((string)($data['primaryDomain'] ?? $data['primary_domain'] ?? ''));
        if ($name === '' || $slug === '' || $primaryDomain === null) {
            Response::error('Nombre, slug y dominio principal validos son obligatorios.', 400, 'TENANT_INVALID');
            return;
        }

        $modules = $this->normalizeEnabledModulePayload($data['enabledModules'] ?? []);
        if ($modules === null) {
            return;
        }
        try {
            $ecommerceConfiguration = $this->sanitizeEcommerceConfiguration(
                $data['ecommerceConfiguration'] ?? null,
                true,
                true
            );
        } catch (\InvalidArgumentException) {
            Response::error(
                'Tarifa IVA no soportada por el catálogo SRI.',
                400,
                'TENANT_TAX_RATE_UNSUPPORTED'
            );
            return;
        }
        $mutation = $this->mutationRequest($data, 'tenant.create', $slug);
        if ($mutation === null || $this->respondKnownMutation($mutation, $slug, 200)) {
            return;
        }

        $tenant = [
            'id' => $slug,
            'slug' => $slug,
            'name' => $name,
            'status' => 'active',
            'domains' => [$primaryDomain],
            'allowed_origins' => ["https://{$primaryDomain}"],
            'app_url' => "https://{$primaryDomain}",
            'public_base_url' => "https://{$primaryDomain}",
            'provisioning_status' => 'pending_gateway',
            'enabled_modules' => $modules,
            // Keep a complete dormant policy even when ecommerce is not
            // entitled yet, so a later module toggle never invents 15%.
            'ecommerce_configuration' => $ecommerceConfiguration,
            'branding' => [
                'logo_url' => 'assets/images/logo.png',
                'logo_light_url' => 'assets/images/logo-light.png',
                'logo_icon_url' => 'assets/images/logo-icon.png',
                'primary_color' => '#2563eb',
            ],
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];

        $db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
        $writeResult = null;
        try {
            $db->beginTransaction();
            $this->lockTenantRuntimeRegistry($db);
            $state = $this->tenantRuntimeRegistryStore->getState();
            $this->assertExpectedRevision($state['revision'], $mutation['expectedRevision']);
            $overrides = $this->normalizeRegistry($state['registry']);
            if ($this->findAdminTenant($slug, $overrides) !== null) {
                throw new \DomainException('TENANT_SLUG_EXISTS');
            }
            if ($this->findTenantByDomain($primaryDomain, $overrides) !== null) {
                throw new \DomainException('TENANT_DOMAIN_EXISTS');
            }
            $overrides['tenants'][$slug] = $tenant;
            $writeResult = $this->saveTenantAdminOverrides($overrides, $mutation);
            if ($this->tenantAccessService->canSynchronizeTenantEntitlements($slug)) {
                $this->tenantAccessService->syncTenantModuleEntitlements($tenant, $modules);
            }
            $db->commit();
            $this->refreshTenantSnapshotAfterCommit();
        } catch (\InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error(
                'Tarifa IVA no soportada por el catálogo SRI.',
                400,
                'TENANT_TAX_RATE_UNSUPPORTED'
            );
            return;
        } catch (\DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception->getMessage() === 'TENANT_SLUG_EXISTS') {
                Response::error('Ya existe un tenant con ese slug.', 409, 'TENANT_SLUG_EXISTS');
            } elseif ($exception->getMessage() === 'TENANT_REGISTRY_REVISION_CONFLICT') {
                $this->respondRevisionConflict();
            } elseif ($exception->getMessage() === 'TENANT_TAX_CONFIGURATION_INVALID') {
                Response::error('La politica tributaria del tenant no es valida.', 400, 'TENANT_TAX_CONFIGURATION_INVALID');
            } else {
                Response::error('El dominio ya pertenece a otro tenant.', 409, 'TENANT_DOMAIN_EXISTS');
            }
            return;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($this->respondRegistryWriteException($exception)) {
                return;
            }
            error_log('[TENANT_PROVISIONING_FAILED] ' . $exception->getMessage());
            Response::error('No se pudo aprovisionar el tenant.', 500, 'TENANT_PROVISIONING_FAILED');
            return;
        }

        $this->respondTenantMutation($tenant, $writeResult, 201);
    }

    public function adminUpdateModules(string $tenantId) {
        $this->requirePlatformAdmin();

        $data = $this->requestJson();
        $modules = $this->normalizeEnabledModulePayload($data['enabledModules'] ?? null);
        if ($modules === null) {
            return;
        }
        $tenantId = $this->normalizeSlug($tenantId);
        $mutation = $this->mutationRequest($data, 'tenant.modules', $tenantId);
        if ($mutation === null || $this->respondKnownMutation($mutation, $tenantId)) {
            return;
        }

        $db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
        $writeResult = null;
        try {
            $db->beginTransaction();
            $this->lockTenantRuntimeRegistry($db);
            $state = $this->tenantRuntimeRegistryStore->getState();
            $this->assertExpectedRevision($state['revision'], $mutation['expectedRevision']);
            $overrides = $this->normalizeRegistry($state['registry']);
            $tenant = $this->findAdminTenant($tenantId, $overrides);
            if ($tenant === null) {
                throw new \DomainException('TENANT_NOT_FOUND');
            }
            $tenant['enabled_modules'] = $modules;
            $tenant['provisioning_status'] = 'pending_gateway';
            $tenant['ecommerce_configuration'] = $this->preserveEcommerceConfigurationForModuleChange(
                $tenant['ecommerce_configuration'] ?? null,
                $modules
            );
            $tenant['updated_at'] = gmdate('c');
            $this->persistAdminTenant($tenant, $overrides);
            $writeResult = $this->saveTenantAdminOverrides($overrides, $mutation);
            if ($this->tenantAccessService->canSynchronizeTenantEntitlements((string)($tenant['id'] ?? $tenantId))) {
                $this->tenantAccessService->syncTenantModuleEntitlements($tenant, $modules);
            }
            $db->commit();
            $this->refreshTenantSnapshotAfterCommit();
        } catch (\DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception->getMessage() === 'TENANT_REGISTRY_REVISION_CONFLICT') {
                $this->respondRevisionConflict();
            } elseif ($exception->getMessage() === 'TENANT_TAX_CONFIGURATION_REQUIRED') {
                Response::error(
                    'El tenant no tiene una politica fiscal canonica; completa el cutover antes de activar ecommerce.',
                    409,
                    'TENANT_TAX_CONFIGURATION_REQUIRED'
                );
            } else {
                Response::error('Tenant no encontrado.', 404, 'TENANT_NOT_FOUND');
            }
            return;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($this->respondRegistryWriteException($exception)) {
                return;
            }
            error_log('[TENANT_MODULE_SYNC_FAILED] ' . $exception->getMessage());
            Response::error('No se pudieron actualizar los modulos del tenant.', 500, 'TENANT_MODULE_SYNC_FAILED');
            return;
        }

        $this->respondTenantMutation($tenant, $writeResult);
    }

    public function adminUpdateConfiguration(string $tenantId) {
        $this->requirePlatformAdmin();

        $data = $this->requestJson();
        $tenantId = $this->normalizeSlug($tenantId);
        $mutation = $this->mutationRequest($data, 'tenant.configuration', $tenantId);
        if ($mutation === null || $this->respondKnownMutation($mutation, $tenantId)) {
            return;
        }
        $db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
        $writeResult = null;
        try {
            $db->beginTransaction();
            $this->lockTenantRuntimeRegistry($db);
            $state = $this->tenantRuntimeRegistryStore->getState();
            $this->assertExpectedRevision($state['revision'], $mutation['expectedRevision']);
            $overrides = $this->normalizeRegistry($state['registry']);
            $tenant = $this->findAdminTenant($tenantId, $overrides);
            if ($tenant === null) {
                throw new \DomainException('TENANT_NOT_FOUND');
            }
            $modules = $this->enabledModules($tenant);
            if (!in_array('ecommerce', $modules, true)) {
                throw new \DomainException('TENANT_ECOMMERCE_NOT_ENABLED');
            }
            $tenant['ecommerce_configuration'] = $this->sanitizeEcommerceConfiguration(
                $this->preserveStoredTaxConfiguration(
                    $data['ecommerceConfiguration'] ?? null,
                    $tenant['ecommerce_configuration'] ?? null
                ),
                true
            );
            $tenant['updated_at'] = gmdate('c');
            $this->persistAdminTenant($tenant, $overrides);
            $writeResult = $this->saveTenantAdminOverrides($overrides, $mutation);
            $db->commit();
            $this->refreshTenantSnapshotAfterCommit();
        } catch (\InvalidArgumentException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error(
                'Tarifa IVA no soportada por el catálogo SRI.',
                400,
                'TENANT_TAX_RATE_UNSUPPORTED'
            );
            return;
        } catch (\DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception->getMessage() === 'TENANT_NOT_FOUND') {
                Response::error('Tenant no encontrado.', 404, 'TENANT_NOT_FOUND');
            } elseif ($exception->getMessage() === 'TENANT_REGISTRY_REVISION_CONFLICT') {
                $this->respondRevisionConflict();
            } elseif (in_array($exception->getMessage(), [
                'TENANT_TAX_CONFIGURATION_REQUIRED',
                'TENANT_TAX_CONFIGURATION_INVALID',
            ], true)) {
                Response::error(
                    'La politica tributaria canonica esta incompleta o no es valida.',
                    409,
                    $exception->getMessage()
                );
            } else {
                Response::error('El tenant no tiene contratado el modulo Ecommerce.', 409, 'TENANT_ECOMMERCE_NOT_ENABLED');
            }
            return;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($this->respondRegistryWriteException($exception)) {
                return;
            }
            error_log('[TENANT_CONFIGURATION_SYNC_FAILED] ' . $exception->getMessage());
            Response::error('No se pudo actualizar la configuracion del tenant.', 500, 'TENANT_CONFIGURATION_SYNC_FAILED');
            return;
        }

        $this->respondTenantMutation($tenant, $writeResult);
    }

    public function adminLifecycle(string $tenantId) {
        $this->requirePlatformAdmin();
        $data = $this->requestJson();
        $tenantId = $this->normalizeSlug($tenantId);
        $action = strtolower(trim((string)($data['action'] ?? '')));
        $mutation = $this->mutationRequest($data, 'tenant.lifecycle.' . $action, $tenantId);
        if ($mutation === null || $this->respondKnownMutation($mutation, $tenantId)) {
            return;
        }

        $db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
        $writeResult = null;
        try {
            $db->beginTransaction();
            $this->lockTenantRuntimeRegistry($db);
            $state = $this->tenantRuntimeRegistryStore->getState();
            $this->assertExpectedRevision($state['revision'], $mutation['expectedRevision']);
            $overrides = $this->normalizeRegistry($state['registry']);
            $tenant = $this->findAdminTenant($tenantId, $overrides);
            if ($tenant === null) {
                throw new \DomainException('TENANT_NOT_FOUND');
            }
            $tenant = TenantRuntimeMutationPolicy::transition(
                $tenant,
                $action,
                (string)($data['reason'] ?? ''),
                $this->registryActorUserId(),
                gmdate('c')
            );
            $this->persistAdminTenant($tenant, $overrides);
            $writeResult = $this->saveTenantAdminOverrides($overrides, $mutation);
            $db->commit();
            $this->refreshTenantSnapshotAfterCommit();
        } catch (\DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->respondTenantDomainException($exception);
            return;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($this->respondRegistryWriteException($exception)) {
                return;
            }
            error_log('[TENANT_LIFECYCLE_FAILED] ' . $exception->getMessage());
            Response::error('No se pudo cambiar el ciclo de vida del tenant.', 500, 'TENANT_LIFECYCLE_FAILED');
            return;
        }

        $this->respondTenantMutation($tenant, $writeResult);
    }

    public function adminUpdateDomains(string $tenantId) {
        $this->requirePlatformAdmin();
        $data = $this->requestJson();
        $tenantId = $this->normalizeSlug($tenantId);
        $mutation = $this->mutationRequest($data, 'tenant.domains', $tenantId);
        if ($mutation === null || $this->respondKnownMutation($mutation, $tenantId)) {
            return;
        }

        $db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
        $writeResult = null;
        try {
            $db->beginTransaction();
            $this->lockTenantRuntimeRegistry($db);
            $state = $this->tenantRuntimeRegistryStore->getState();
            $this->assertExpectedRevision($state['revision'], $mutation['expectedRevision']);
            $overrides = $this->normalizeRegistry($state['registry']);
            $tenant = $this->findAdminTenant($tenantId, $overrides);
            if ($tenant === null) {
                throw new \DomainException('TENANT_NOT_FOUND');
            }
            $tenant = TenantRuntimeMutationPolicy::updateDomains(
                $tenant,
                is_array($data['domains'] ?? null) ? $data['domains'] : [],
                (string)($data['reason'] ?? ''),
                $this->registryActorUserId(),
                gmdate('c')
            );
            foreach ($tenant['domains'] as $domain) {
                $owner = $this->findTenantByDomain((string)$domain, $overrides);
                if ($owner !== null && (string)($owner['id'] ?? '') !== (string)($tenant['id'] ?? '')) {
                    throw new \DomainException('TENANT_DOMAIN_EXISTS');
                }
            }
            $this->persistAdminTenant($tenant, $overrides);
            $writeResult = $this->saveTenantAdminOverrides($overrides, $mutation);
            $db->commit();
            $this->refreshTenantSnapshotAfterCommit();
        } catch (\DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->respondTenantDomainException($exception);
            return;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($this->respondRegistryWriteException($exception)) {
                return;
            }
            error_log('[TENANT_DOMAINS_FAILED] ' . $exception->getMessage());
            Response::error('No se pudieron actualizar los dominios del tenant.', 500, 'TENANT_DOMAINS_FAILED');
            return;
        }

        $this->respondTenantMutation($tenant, $writeResult);
    }

    public function adminReconcile(string $tenantId) {
        $this->requirePlatformAdmin();
        $data = $this->requestJson();
        $tenantId = $this->normalizeSlug($tenantId);
        $mutation = $this->mutationRequest($data, 'tenant.reconcile', $tenantId);
        if ($mutation === null || $this->respondKnownMutation($mutation, $tenantId)) {
            return;
        }
        $reason = trim((string)($data['reason'] ?? ''));
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 500) {
            Response::error('La razon de reconciliacion debe tener entre 8 y 500 caracteres.', 400, 'TENANT_RECONCILIATION_REASON_INVALID');
            return;
        }

        $db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
        $writeResult = null;
        try {
            $db->beginTransaction();
            $this->lockTenantRuntimeRegistry($db);
            $state = $this->tenantRuntimeRegistryStore->getState();
            $this->assertExpectedRevision($state['revision'], $mutation['expectedRevision']);
            $overrides = $this->normalizeRegistry($state['registry']);
            $tenant = $this->findAdminTenant($tenantId, $overrides);
            if ($tenant === null) {
                throw new \DomainException('TENANT_NOT_FOUND');
            }
            $now = gmdate('c');
            $tenant['provisioning_status'] = 'pending_gateway';
            $tenant['reconciliation_request'] = [
                'reason' => $reason,
                'actorUserId' => $this->registryActorUserId(),
                'requestedAt' => $now,
            ];
            $tenant['updated_at'] = $now;
            $this->persistAdminTenant($tenant, $overrides);
            $writeResult = $this->saveTenantAdminOverrides($overrides, $mutation);
            $db->commit();
            $this->refreshTenantSnapshotAfterCommit();
        } catch (\DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->respondTenantDomainException($exception);
            return;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($this->respondRegistryWriteException($exception)) {
                return;
            }
            error_log('[TENANT_RECONCILIATION_REQUEST_FAILED] ' . $exception->getMessage());
            Response::error('No se pudo solicitar la reconciliacion.', 500, 'TENANT_RECONCILIATION_REQUEST_FAILED');
            return;
        }

        $this->respondTenantMutation($tenant, $writeResult, 202);
    }

    public function adminRollback(string $tenantId) {
        $this->requirePlatformAdmin();
        $data = $this->requestJson();
        $tenantId = $this->normalizeSlug($tenantId);
        $mutation = $this->mutationRequest($data, 'tenant.rollback', $tenantId);
        if ($mutation === null || $this->respondKnownMutation($mutation, $tenantId)) {
            return;
        }
        $targetRevision = filter_var($data['targetRevision'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string)($data['reason'] ?? ''));
        if (!is_int($targetRevision) || $targetRevision < 1 || $targetRevision >= $mutation['expectedRevision']) {
            Response::error('La revision de rollback debe ser anterior a la revision actual.', 400, 'TENANT_ROLLBACK_REVISION_INVALID');
            return;
        }
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 500) {
            Response::error('La razon de rollback debe tener entre 8 y 500 caracteres.', 400, 'TENANT_ROLLBACK_REASON_INVALID');
            return;
        }

        $db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
        $writeResult = null;
        try {
            $db->beginTransaction();
            $this->lockTenantRuntimeRegistry($db);
            $state = $this->tenantRuntimeRegistryStore->getState();
            $this->assertExpectedRevision($state['revision'], $mutation['expectedRevision']);
            $overrides = $this->normalizeRegistry($state['registry']);
            if ($this->findAdminTenant($tenantId, $overrides) === null) {
                throw new \DomainException('TENANT_NOT_FOUND');
            }
            $tenant = $this->tenantRuntimeRegistryStore->tenantAtRevision($tenantId, $targetRevision);
            if ($tenant === null || (string)($tenant['id'] ?? $tenantId) !== $tenantId) {
                throw new \DomainException('TENANT_ROLLBACK_SNAPSHOT_NOT_FOUND');
            }
            foreach (TenantRuntimeMutationPolicy::normalizeDomains((array)($tenant['domains'] ?? [])) as $domain) {
                $owner = $this->findTenantByDomain($domain, $overrides);
                if ($owner !== null && (string)($owner['id'] ?? '') !== $tenantId) {
                    throw new \DomainException('TENANT_DOMAIN_EXISTS');
                }
            }
            $now = gmdate('c');
            $tenant['provisioning_status'] = 'pending_gateway';
            $tenant['rollback'] = [
                'targetRevision' => $targetRevision,
                'reason' => $reason,
                'actorUserId' => $this->registryActorUserId(),
                'occurredAt' => $now,
            ];
            $tenant['updated_at'] = $now;
            $this->persistAdminTenant($tenant, $overrides);
            $writeResult = $this->saveTenantAdminOverrides($overrides, $mutation);
            if ($this->tenantAccessService->canSynchronizeTenantEntitlements($tenantId)) {
                $this->tenantAccessService->syncTenantModuleEntitlements(
                    $tenant,
                    is_array($tenant['enabled_modules'] ?? null) ? $tenant['enabled_modules'] : [],
                    'tenant-rollback'
                );
            }
            $db->commit();
            $this->refreshTenantSnapshotAfterCommit();
        } catch (\DomainException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->respondTenantDomainException($exception);
            return;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($this->respondRegistryWriteException($exception)) {
                return;
            }
            error_log('[TENANT_ROLLBACK_FAILED] ' . $exception->getMessage());
            Response::error('No se pudo restaurar la revision del tenant.', 500, 'TENANT_ROLLBACK_FAILED');
            return;
        }

        $this->respondTenantMutation($tenant, $writeResult);
    }

    public function adminEvents(string $tenantId) {
        $this->requirePlatformAdmin();
        $tenantId = $this->normalizeSlug($tenantId);
        try {
            $state = $this->tenantRuntimeRegistryStore->getState();
            if ($this->findAdminTenant($tenantId, $state['registry']) === null) {
                Response::error('Tenant no encontrado.', 404, 'TENANT_NOT_FOUND');
                return;
            }
            $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
            $events = $this->tenantRuntimeRegistryStore->events($tenantId, $limit);
            $this->setRegistryResponseHeaders($state['revision']);
            Response::noStore();
            Response::json($events, 200, $this->registryMeta($state['revision']));
        } catch (\Throwable $exception) {
            error_log('[TENANT_EVENTS_FAILED] ' . $exception->getMessage());
            Response::error('No se pudo leer el historial del tenant.', 503, 'TENANT_REGISTRY_UNAVAILABLE');
        }
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
            'domains' => array_values($tenant['domains'] ?? []),
            'provisioningStatus' => (string)($tenant['provisioning_status'] ?? 'ready'),
            'lifecycle' => is_array($tenant['lifecycle'] ?? null) ? $tenant['lifecycle'] : null,
            'domainChange' => is_array($tenant['domain_change'] ?? null) ? $tenant['domain_change'] : null,
            'rollback' => is_array($tenant['rollback'] ?? null) ? $tenant['rollback'] : null,
            'createdAt' => isset($tenant['created_at']) ? (string)$tenant['created_at'] : null,
            'updatedAt' => isset($tenant['updated_at']) ? (string)$tenant['updated_at'] : null,
        ];
    }

    private function isPlatformAdmin(array $user, string $role, array $tenant): bool {
        return $this->tenantAccessService->isPlatformAdmin($user, $role, $tenant);
    }

    private function requirePlatformAdmin(): array {
        $payload = Auth::requireAdmin();
        $tenant = $this->withStoredAdminConfig(TenantContext::get() ?? []);
        $user = $this->userRepository->getById((string)($payload['sub'] ?? ''));
        $role = strtolower(trim((string)($user['role'] ?? $payload['role'] ?? 'customer')));
        if (!$user || !$this->isPlatformAdmin($user, $role, $tenant)) {
            Response::error('No tienes permiso para administrar tenants.', 403, 'PLATFORM_ADMIN_REQUIRED');
            exit;
        }

        $this->platformRegistryActor = $payload;
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

    private function allAdminTenants(?array $registry = null): array {
        $tenants = array_values($this->configuredTenants());
        $configuredIds = [];
        foreach ($tenants as $tenant) {
            $configuredIds[] = (string)($tenant['id'] ?? $tenant['slug'] ?? '');
        }

        $overrides = $this->tenantAdminOverrides($registry);
        foreach (($overrides['tenants'] ?? []) as $tenantId => $tenantOverride) {
            if (!is_array($tenantOverride)) {
                continue;
            }
            if (in_array((string)$tenantId, $configuredIds, true)) {
                continue;
            }
            $tenants[] = $tenantOverride;
        }

        return array_values(array_map(fn (array $tenant): array => $this->withStoredAdminConfig($tenant, $overrides), $tenants));
    }

    private function configuredTenants(): array {
        $path = __DIR__ . '/../../config/tenants.php';
        $tenants = is_readable($path) ? require $path : [];
        return is_array($tenants) ? $tenants : [];
    }

    private function findAdminTenant(string $tenantId, ?array $registry = null): ?array {
        $needle = strtolower(trim($tenantId));
        if ($needle === '') {
            return null;
        }

        foreach ($this->allAdminTenants($registry) as $tenant) {
            $id = strtolower((string)($tenant['id'] ?? ''));
            $slug = strtolower((string)($tenant['slug'] ?? ''));
            if ($needle === $id || $needle === $slug) {
                return $tenant;
            }
        }

        return null;
    }

    private function findTenantByDomain(string $domain, ?array $registry = null): ?array {
        foreach ($this->allAdminTenants($registry) as $tenant) {
            foreach (($tenant['domains'] ?? []) as $candidate) {
                if ($this->normalizeDomain((string)$candidate) === $domain) {
                    return $tenant;
                }
            }
        }

        return null;
    }

    private function persistAdminTenant(array $tenant, array &$overrides): void {
        $tenantId = (string)($tenant['id'] ?? $tenant['slug'] ?? '');
        if ($tenantId === '') {
            return;
        }

        $storedTenant = $overrides['tenants'][$tenantId] ?? [];
        if (!is_array($storedTenant)) {
            $storedTenant = [];
        }
        $overrides['tenants'][$tenantId] = array_replace($storedTenant, [
            'id' => (string)($tenant['id'] ?? $tenantId),
            'slug' => (string)($tenant['slug'] ?? $tenantId),
            'name' => (string)($tenant['name'] ?? 'Tenant'),
            'status' => (string)($tenant['status'] ?? 'active'),
            'domains' => array_values($tenant['domains'] ?? ($storedTenant['domains'] ?? [])),
            'allowed_origins' => array_values($tenant['allowed_origins'] ?? ($storedTenant['allowed_origins'] ?? [])),
            'app_url' => $tenant['app_url'] ?? ($storedTenant['app_url'] ?? null),
            'public_base_url' => $tenant['public_base_url'] ?? ($storedTenant['public_base_url'] ?? null),
            'provisioning_status' => (string)($tenant['provisioning_status'] ?? ($storedTenant['provisioning_status'] ?? 'pending_gateway')),
            'enabled_modules' => $this->enabledModules($tenant),
            'ecommerce_configuration' => $tenant['ecommerce_configuration'] ?? null,
            'branding' => $tenant['branding'] ?? [],
            'created_at' => $tenant['created_at'] ?? ($storedTenant['created_at'] ?? null),
            'updated_at' => $tenant['updated_at'] ?? gmdate('c'),
        ]);
        foreach ([
            'suspended_at', 'resumed_at', 'offboarded_at', 'retention_until',
            'lifecycle', 'domain_change', 'reconciliation_request', 'rollback',
            'provisioned_at', 'provisioning_error_code', 'provisioning_desired_hash',
            'provisioning_updated_at', 'provisioning_audit',
        ] as $controlKey) {
            if (array_key_exists($controlKey, $tenant)) {
                $overrides['tenants'][$tenantId][$controlKey] = $tenant[$controlKey];
            } else {
                unset($overrides['tenants'][$tenantId][$controlKey]);
            }
        }

    }

    private function withStoredAdminConfig(array $tenant, ?array $registry = null): array {
        if ($tenant === []) {
            return [];
        }

        $tenantId = (string)($tenant['id'] ?? $tenant['slug'] ?? '');
        if ($tenantId === '') {
            return $tenant;
        }

        $overrides = $this->tenantAdminOverrides($registry);
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
        foreach ([
            'domains', 'allowed_origins', 'app_url', 'public_base_url', 'provisioning_status',
            'created_at', 'updated_at', 'suspended_at', 'resumed_at', 'offboarded_at',
            'retention_until', 'lifecycle', 'domain_change', 'reconciliation_request',
            'rollback', 'provisioned_at', 'provisioning_error_code',
            'provisioning_desired_hash', 'provisioning_updated_at', 'provisioning_audit',
        ] as $runtimeKey) {
            if (array_key_exists($runtimeKey, $storedTenant)) {
                $tenant[$runtimeKey] = $storedTenant[$runtimeKey];
            }
        }

        return $tenant;
    }

    private function tenantAdminOverrides(?array $registry = null): array {
        $overrides = $registry ?? $this->tenantRuntimeRegistryStore->get();
        if (!is_array($overrides)) {
            return ['version' => 1, 'tenants' => []];
        }
        $overrides['version'] = 1;
        if (!isset($overrides['tenants']) || !is_array($overrides['tenants'])) {
            $overrides['tenants'] = [];
        }
        return $overrides;
    }

    /** @return array{revision:int,applied:bool,idempotent:bool} */
    private function saveTenantAdminOverrides(array $overrides, array $mutation): array {
        $overrides['version'] = 1;
        if (!isset($overrides['tenants']) || !is_array($overrides['tenants'])) {
            $overrides['tenants'] = [];
        }
        $actorTenantId = trim((string)($this->platformRegistryActor['tenant_id'] ?? TenantContext::id() ?? 'platform'));
        return $this->tenantRuntimeRegistryStore->set(
            $overrides,
            $mutation['expectedRevision'],
            $mutation['requestId'],
            $mutation['requestHash'],
            $mutation['operation'],
            $mutation['targetTenantId'],
            $actorTenantId,
            $this->registryActorUserId()
        );
    }

    private function normalizeRegistry(array $registry): array {
        return $this->tenantAdminOverrides($registry);
    }

    /** @return array{expectedRevision:int,requestId:string,requestHash:string,operation:string,targetTenantId:string}|null */
    private function mutationRequest(array $data, string $operation, string $targetTenantId): ?array {
        $operation = strtolower(trim($operation));
        $targetTenantId = $this->normalizeSlug($targetTenantId);
        $ifMatch = trim((string)($_SERVER['HTTP_IF_MATCH'] ?? ''));
        $expectedRevision = null;
        if (preg_match('/^(?:W\/)?"tenant-registry-([1-9][0-9]*)"$/', $ifMatch, $matches) === 1) {
            $expectedRevision = filter_var($matches[1], FILTER_VALIDATE_INT);
        } elseif ($ifMatch === '' && array_key_exists('expectedRevision', $data)) {
            // Transitional compatibility for non-browser automation. Public
            // OpenAPI and dashboard always use If-Match.
            $expectedRevision = filter_var($data['expectedRevision'], FILTER_VALIDATE_INT);
        }
        if (!is_int($expectedRevision) || $expectedRevision < 1) {
            Response::error(
                'If-Match con el ETag vigente del registro es obligatorio.',
                428,
                'TENANT_REGISTRY_PRECONDITION_REQUIRED'
            );
            return null;
        }

        $requestId = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($data['idempotencyKey'] ?? '')));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $requestId) !== 1) {
            Response::error(
                'Idempotency-Key valido es obligatorio para mutaciones de tenants.',
                400,
                'TENANT_IDEMPOTENCY_KEY_INVALID'
            );
            return null;
        }
        if ($targetTenantId === '' || preg_match('/^tenant\.[a-z0-9][a-z0-9.-]{1,62}$/', $operation) !== 1) {
            Response::error('Operacion tenant invalida.', 400, 'TENANT_MUTATION_INVALID');
            return null;
        }

        $fingerprintPayload = $data;
        unset($fingerprintPayload['expectedRevision'], $fingerprintPayload['idempotencyKey']);
        $fingerprintPayload = $this->canonicalizeJsonValue($fingerprintPayload);
        $requestHash = hash('sha256', json_encode([
            'operation' => $operation,
            'targetTenantId' => $targetTenantId,
            'expectedRevision' => $expectedRevision,
            'payload' => $fingerprintPayload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return [
            'expectedRevision' => $expectedRevision,
            'requestId' => $requestId,
            'requestHash' => $requestHash,
            'operation' => $operation,
            'targetTenantId' => $targetTenantId,
        ];
    }

    private function canonicalizeJsonValue($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeJsonValue($item);
        }
        return $value;
    }

    private function respondKnownMutation(array $mutation, string $tenantId, int $status = 200): bool {
        try {
            $existing = $this->tenantRuntimeRegistryStore->mutation($mutation['requestId']);
            if ($existing === null) {
                return false;
            }
            $matches = hash_equals((string)($existing['requestHash'] ?? ''), $mutation['requestHash'])
                && (string)($existing['operation'] ?? '') === $mutation['operation']
                && (string)($existing['targetTenantId'] ?? '') === $mutation['targetTenantId']
                && (int)($existing['expectedRevision'] ?? 0) === $mutation['expectedRevision'];
            if (!$matches) {
                Response::error(
                    'Idempotency-Key ya fue usada para otra mutacion.',
                    409,
                    'TENANT_IDEMPOTENCY_CONFLICT'
                );
                return true;
            }

            $state = $this->tenantRuntimeRegistryStore->getState();
            $tenant = $this->findAdminTenant($tenantId, $state['registry']);
            if ($tenant === null) {
                Response::error('Tenant no encontrado.', 404, 'TENANT_NOT_FOUND');
                return true;
            }
            $this->setRegistryResponseHeaders($state['revision']);
            Response::json(
                $this->tenantAdminSummary($tenant),
                $status,
                $this->registryMeta($state['revision'], true)
            );
            return true;
        } catch (\Throwable $exception) {
            error_log('[TENANT_IDEMPOTENCY_LOOKUP_FAILED] ' . $exception->getMessage());
            Response::error('No se pudo verificar la idempotencia.', 503, 'TENANT_REGISTRY_UNAVAILABLE');
            return true;
        }
    }

    private function assertExpectedRevision(int $currentRevision, int $expectedRevision): void {
        if ($currentRevision !== $expectedRevision) {
            throw new \DomainException('TENANT_REGISTRY_REVISION_CONFLICT');
        }
    }

    private function respondRevisionConflict(): void {
        try {
            $state = $this->tenantRuntimeRegistryStore->getState();
            $this->setRegistryResponseHeaders($state['revision']);
            Response::error(
                'El registro cambio; vuelve a cargar antes de reintentar.',
                409,
                'TENANT_REGISTRY_REVISION_CONFLICT',
                $this->registryMeta($state['revision'])
            );
        } catch (\Throwable) {
            Response::error('El registro cambio; vuelve a cargar antes de reintentar.', 409, 'TENANT_REGISTRY_REVISION_CONFLICT');
        }
    }

    private function respondRegistryWriteException(\Throwable $exception): bool {
        $message = $exception->getMessage();
        if (str_contains($message, 'TENANT_REGISTRY_REVISION_CONFLICT')) {
            $this->respondRevisionConflict();
            return true;
        }
        if (str_contains($message, 'TENANT_REGISTRY_IDEMPOTENCY_CONFLICT')) {
            Response::error(
                'Idempotency-Key ya fue usada para otra mutacion.',
                409,
                'TENANT_IDEMPOTENCY_CONFLICT'
            );
            return true;
        }
        return false;
    }

    private function respondTenantDomainException(\DomainException $exception): void {
        match ($exception->getMessage()) {
            'TENANT_NOT_FOUND' => Response::error('Tenant no encontrado.', 404, 'TENANT_NOT_FOUND'),
            'TENANT_REGISTRY_REVISION_CONFLICT' => $this->respondRevisionConflict(),
            'TENANT_LIFECYCLE_ACTION_INVALID' => Response::error('Accion de ciclo de vida invalida.', 400, 'TENANT_LIFECYCLE_ACTION_INVALID'),
            'TENANT_LIFECYCLE_REASON_INVALID' => Response::error('La razon debe tener entre 8 y 500 caracteres.', 400, 'TENANT_LIFECYCLE_REASON_INVALID'),
            'TENANT_LIFECYCLE_TRANSITION_INVALID' => Response::error('Transicion de ciclo de vida no permitida.', 409, 'TENANT_LIFECYCLE_TRANSITION_INVALID'),
            'TENANT_OFFBOARDED_IMMUTABLE' => Response::error('Un tenant offboarded solo puede restaurarse por rollback.', 409, 'TENANT_OFFBOARDED_IMMUTABLE'),
            'TENANT_DOMAIN_REASON_INVALID' => Response::error('La razon de dominios debe tener entre 8 y 500 caracteres.', 400, 'TENANT_DOMAIN_REASON_INVALID'),
            'TENANT_DOMAINS_INVALID' => Response::error('La lista de dominios no es valida.', 400, 'TENANT_DOMAINS_INVALID'),
            'TENANT_DOMAINS_UNCHANGED' => Response::error('La lista de dominios no contiene cambios.', 409, 'TENANT_DOMAINS_UNCHANGED'),
            'TENANT_DOMAIN_EXISTS' => Response::error('Un dominio ya pertenece a otro tenant.', 409, 'TENANT_DOMAIN_EXISTS'),
            'TENANT_ROLLBACK_SNAPSHOT_NOT_FOUND' => Response::error('No existe un snapshot auditable para esa revision.', 404, 'TENANT_ROLLBACK_SNAPSHOT_NOT_FOUND'),
            default => Response::error('Mutacion tenant rechazada.', 409, 'TENANT_MUTATION_REJECTED'),
        };
    }

    private function respondTenantMutation(array $tenant, ?array $writeResult, int $status = 200): void {
        if (!is_array($writeResult) || !is_int($writeResult['revision'] ?? null)) {
            Response::error('No se obtuvo confirmacion de la mutacion.', 500, 'TENANT_REGISTRY_WRITE_FAILED');
            return;
        }
        $this->setRegistryResponseHeaders($writeResult['revision']);
        Response::json(
            $this->tenantAdminSummary($tenant),
            $status,
            $this->registryMeta($writeResult['revision'], (bool)($writeResult['idempotent'] ?? false))
        );
    }

    private function registryMeta(int $revision, bool $idempotent = false): array {
        return [
            'registryRevision' => $revision,
            'etag' => '"tenant-registry-' . $revision . '"',
            'idempotentReplay' => $idempotent,
        ];
    }

    private function setRegistryResponseHeaders(int $revision): void {
        header('ETag: "tenant-registry-' . $revision . '"');
        header('X-Tenant-Registry-Revision: ' . $revision);
    }

    private function registryActorUserId(): string {
        return trim((string)($this->platformRegistryActor['sub'] ?? 'platform-service'));
    }

    private function refreshTenantSnapshotAfterCommit(): void {
        try {
            TenantRuntimeRegistry::refreshSnapshot();
        } catch (\Throwable $exception) {
            // The registry mutation is already committed and remains canonical.
            // Readiness is degraded by TenantRuntimeRegistry until a signed
            // snapshot can be persisted; never misreport the mutation as rolled back.
            error_log('[TENANT_RUNTIME_REGISTRY_SNAPSHOT_REFRESH_FAILED] ' . $exception->getMessage());
        }
    }

    private function lockTenantRuntimeRegistry(\PDO $db): void {
        if (!$db->inTransaction()) {
            throw new \LogicException('Tenant registry lock requires an active transaction.');
        }
        $db->query("SELECT pg_advisory_xact_lock(hashtextextended('paramascotasec:tenant-runtime-registry:v1', 0))");
    }

    private function normalizeSlug(string $value): string {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug;
    }

    private function normalizeDomain(string $value): ?string {
        $domain = strtolower(rtrim(trim($value), '.'));
        if (
            $domain === ''
            || strlen($domain) > 253
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)
        ) {
            return null;
        }

        return $domain;
    }

    private function sanitizeEcommerceConfiguration(
        $configuration,
        bool $ecommerceEnabled,
        bool $allowBootstrapDefaults = false
    ): ?array {
        if (!$ecommerceEnabled) {
            return null;
        }

        if (!is_array($configuration)) {
            if ($allowBootstrapDefaults) {
                return $this->defaultEcommerceConfiguration();
            }
            throw new \DomainException('TENANT_TAX_CONFIGURATION_REQUIRED');
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

        $bootstrap = $this->defaultEcommerceConfiguration();
        $hasDefaultTaxRate = array_key_exists('defaultTaxRate', $configuration)
            || array_key_exists('default_tax_rate', $configuration);
        if (!$hasDefaultTaxRate && !$allowBootstrapDefaults) {
            throw new \DomainException('TENANT_TAX_CONFIGURATION_REQUIRED');
        }
        $defaultTaxRate = EcuadorSriVatCatalog::assertSupportedRate(
            $hasDefaultTaxRate
                ? ($configuration['defaultTaxRate'] ?? $configuration['default_tax_rate'])
                : $bootstrap['defaultTaxRate']
        );

        $creditCurrentRate = $this->canonicalTaxCreditRate(
            $configuration,
            'purchaseVatCreditCurrentRate',
            $allowBootstrapDefaults ? $bootstrap['purchaseVatCreditCurrentRate'] : null
        );
        $creditCarryforwardRate = $this->canonicalTaxCreditRate(
            $configuration,
            'purchaseVatCreditCarryforwardRate',
            $allowBootstrapDefaults ? $bootstrap['purchaseVatCreditCarryforwardRate'] : null
        );

        return [
            'vertical' => $vertical,
            'businessLabel' => $this->nullableTrimmedString($configuration['businessLabel'] ?? null),
            'enabledCapabilities' => array_values(array_unique($enabledCapabilities)),
            'defaultTaxRate' => $defaultTaxRate,
            'purchaseVatCreditCurrentRate' => $creditCurrentRate,
            'purchaseVatCreditCarryforwardRate' => $creditCarryforwardRate,
            'notes' => $this->nullableTrimmedString($configuration['notes'] ?? null),
        ];
    }

    private function canonicalTaxCreditRate(array $configuration, string $field, ?float $bootstrapDefault): float {
        if (!array_key_exists($field, $configuration)) {
            if ($bootstrapDefault !== null) {
                return $bootstrapDefault;
            }
            throw new \DomainException('TENANT_TAX_CONFIGURATION_REQUIRED');
        }
        $value = $configuration[$field];
        if (!is_numeric($value)) {
            throw new \DomainException('TENANT_TAX_CONFIGURATION_INVALID');
        }
        $rate = round((float)$value, 2);
        if (!is_finite($rate) || $rate < 0.0 || $rate > 100.0) {
            throw new \DomainException('TENANT_TAX_CONFIGURATION_INVALID');
        }
        return $rate;
    }

    /**
     * Tax policy is also edited from the tenant tax workspace. An older or
     * concurrent Tenant Admin PATCH may omit those additive fields, but must
     * never reset them to defaults.
     */
    private function preserveStoredTaxConfiguration($incoming, $stored): array {
        $incomingConfiguration = is_array($incoming) ? $incoming : [];
        $storedConfiguration = is_array($stored) ? $stored : [];
        foreach ([
            'defaultTaxRate',
            'purchaseVatCreditCurrentRate',
            'purchaseVatCreditCarryforwardRate',
        ] as $taxField) {
            if (!array_key_exists($taxField, $incomingConfiguration)
                && array_key_exists($taxField, $storedConfiguration)) {
                $incomingConfiguration[$taxField] = $storedConfiguration[$taxField];
            }
        }
        if (!array_key_exists('defaultTaxRate', $incomingConfiguration)
            && array_key_exists('default_tax_rate', $incomingConfiguration)) {
            $incomingConfiguration['defaultTaxRate'] = $incomingConfiguration['default_tax_rate'];
        }
        unset($incomingConfiguration['default_tax_rate']);
        return $incomingConfiguration;
    }

    private function preserveEcommerceConfigurationForModuleChange($stored, array $enabledModules): ?array {
        if (is_array($stored)) {
            return $stored;
        }
        if (in_array('ecommerce', $enabledModules, true)) {
            throw new \DomainException('TENANT_TAX_CONFIGURATION_REQUIRED');
        }
        return null;
    }

    private function defaultEcommerceConfiguration(): array {
        return [
            'vertical' => 'petshop',
            'businessLabel' => 'Petshop omnicanal',
            'enabledCapabilities' => ['products', 'categories', 'attributes', 'variants', 'images', 'inventory', 'pricing', 'orders', 'invoicing', 'payments', 'customers', 'shipping', 'reporting'],
            'defaultTaxRate' => 15.0,
            'purchaseVatCreditCurrentRate' => 60.0,
            'purchaseVatCreditCarryforwardRate' => 40.0,
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

    /**
     * Fidepuntos obtiene su menu operativo desde Loyalty y sus concesiones desde
     * IdentityPlatform. Cualquier falla de lectura devuelve un arbol vacio para
     * que el dashboard falle cerrado y conserve solo su salida local de cuenta.
     */
    private function dynamicAccessContext(array $tenant, array $permissions): array {
        $tenantId = strtolower(trim((string)($tenant['id'] ?? $tenant['slug'] ?? TenantContext::id() ?? '')));
        if (!$this->tenantNavigation->supportsTenant($tenantId)) {
            return ['effectivePermissions' => array_values(array_unique($permissions))];
        }

        try {
            $navigation = $this->tenantNavigation->effectiveNavigation($tenantId, $permissions);

            return [
                'navigation' => [
                    'version' => (string)$navigation['version'],
                    'sections' => $navigation['sections'],
                ],
                'accessVersion' => $this->tenantAccessService->accessVersion($tenantId),
                'effectivePermissions' => array_values(array_unique($permissions)),
            ];
        } catch (\Throwable $exception) {
            error_log(sprintf(
                '[TENANT_NAVIGATION_UNAVAILABLE] tenant=%s error=%s',
                $tenantId,
                $exception->getMessage()
            ));

            $safePermissions = ['identity.account-security.view'];
            foreach (['identity.account-security.update', 'identity.account-security.revoke_sessions'] as $permission) {
                if (in_array('platform-admin', $permissions, true) || in_array($permission, $permissions, true)) {
                    $safePermissions[] = $permission;
                }
            }
            return [
                'navigation' => [
                    'version' => $this->tenantNavigation->unavailableVersion(),
                    'sections' => [],
                ],
                'accessVersion' => 'unavailable',
                'effectivePermissions' => $safePermissions,
            ];
        }
    }

    private function platformAdminRole(): array {
        return $this->tenantAccessService->platformAdminRole();
    }
}
