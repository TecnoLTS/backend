<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\IdentityPlatform\Infrastructure\IdentityAccessRepository;
use App\Repositories\SettingsRepository;
use InvalidArgumentException;
use RuntimeException;

class RoleController {
    private SettingsRepository $settingsRepository;
    private TenantAccessService $tenantAccessService;
    private IdentityAccessRepository $identityAccessRepository;

    private const TENANT_ADMIN_OVERRIDES_KEY = TenantAccessService::TENANT_ADMIN_OVERRIDES_KEY;

    public function __construct() {
        $this->settingsRepository = new SettingsRepository();
        $this->identityAccessRepository = new IdentityAccessRepository();
        $this->tenantAccessService = new TenantAccessService(
            $this->settingsRepository,
            $this->identityAccessRepository
        );
    }

    public function index(): void {
        Auth::requireAdmin();
        Response::noStore();

        $search = strtolower(trim((string)($_GET['search'] ?? '')));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 20)));
        $roles = $this->allRoles();

        if ($search !== '') {
            $roles = array_values(array_filter($roles, static function (array $role) use ($search): bool {
                return str_contains(strtolower(implode(' ', [
                    (string)($role['name'] ?? ''),
                    (string)($role['description'] ?? ''),
                    (string)($role['id'] ?? ''),
                ])), $search);
            }));
        }

        $totalItems = count($roles);
        $totalPages = max(1, (int)ceil($totalItems / $pageSize));
        $page = min($page, $totalPages);
        Response::json(
            array_slice($roles, ($page - 1) * $pageSize, $pageSize),
            200,
            compact('page', 'pageSize', 'totalItems', 'totalPages')
        );
    }

    public function show(string $roleId): void {
        Auth::requireAdmin();
        Response::noStore();
        $role = $this->identityAccessRepository->role($this->normalizeId($roleId));
        if (!$role) {
            Response::error('Rol no encontrado.', 404, 'ROLE_NOT_FOUND');
            return;
        }
        Response::json($role);
    }

    public function store(): void {
        $actor = Auth::requireAdmin();
        $data = $this->requestJson();
        $name = $this->requiredText($data['name'] ?? null, 'El nombre del rol es obligatorio.');
        if ($name === null) {
            return;
        }

        $role = [
            'id' => $this->newRoleId($name),
            'name' => $name,
            'description' => $this->nullableText($data['description'] ?? null, 500) ?? 'Rol personalizado del tenant.',
            // Fidepuntos escribe acceso granular solo en navigation grants. Los
            // demas tenants conservan el contrato legacy durante esta primera fase.
            'permissions' => $this->isFidepuntos()
                ? []
                : $this->normalizePermissions($data['permissions'] ?? []),
            'system' => false,
        ];

        try {
            if ($this->isFidepuntos()) {
                if (!is_array($data['navigationGrants'] ?? null)) {
                    Response::error('Selecciona al menos una pantalla operativa.', 400, 'ROLE_GRANTS_INVALID');
                    return;
                }
                $created = $this->identityAccessRepository->createCustomRoleWithNavigationGrants(
                    $role,
                    $data['navigationGrants'],
                    (string)($actor['sub'] ?? '')
                );
                Response::json($created, 201);
                return;
            }

            $this->identityAccessRepository->syncRole($role);
            $this->identityAccessRepository->recordAuditEvent(
                (string)($actor['sub'] ?? ''),
                'role.created',
                'role',
                $role['id'],
                ['name' => $role['name'], 'permissions' => $role['permissions']]
            );
            $created = $this->identityAccessRepository->role($role['id']);
            Response::json($created ?: $role, 201);
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'ROLE_PAYLOAD_INVALID');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'ROLE_CREATE_FAILED');
        }
    }

    public function update(string $roleId): void {
        $actor = Auth::requireAdmin();
        $data = $this->requestJson();
        $roleId = $this->normalizeId($roleId);
        $existing = $this->identityAccessRepository->role($roleId);
        if (!$existing) {
            Response::error('Rol no encontrado.', 404, 'ROLE_NOT_FOUND');
            return;
        }
        if (!empty($existing['system'])) {
            Response::error('Los roles de sistema no se pueden modificar.', 409, 'ROLE_SYSTEM_IMMUTABLE');
            return;
        }

        $role = $existing;
        if (array_key_exists('name', $data)) {
            $name = $this->requiredText($data['name'], 'El nombre del rol es obligatorio.');
            if ($name === null) {
                return;
            }
            $role['name'] = $name;
        }
        if (array_key_exists('description', $data)) {
            $role['description'] = $this->nullableText($data['description'], 500) ?? 'Rol personalizado del tenant.';
        }
        if (!$this->isFidepuntos() && array_key_exists('permissions', $data)) {
            $role['permissions'] = $this->normalizePermissions($data['permissions']);
        }
        try {
            $this->identityAccessRepository->syncRole($role);
            $this->identityAccessRepository->recordAuditEvent(
                (string)($actor['sub'] ?? ''),
                'role.updated',
                'role',
                $roleId,
                ['name' => $role['name'], 'permissions' => $role['permissions']]
            );
            Response::json($this->identityAccessRepository->role($roleId));
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'ROLE_UPDATE_FAILED');
        }
    }

    public function destroy(string $roleId): void {
        $actor = Auth::requireAdmin();
        $roleId = $this->normalizeId($roleId);
        $existing = $this->identityAccessRepository->role($roleId);
        if (!$existing) {
            Response::error('Rol no encontrado.', 404, 'ROLE_NOT_FOUND');
            return;
        }
        if (!empty($existing['system'])) {
            Response::error('Los roles de sistema no se pueden eliminar.', 409, 'ROLE_SYSTEM_IMMUTABLE');
            return;
        }

        try {
            if (!$this->identityAccessRepository->deleteRole($roleId)) {
                Response::error('Rol no encontrado.', 404, 'ROLE_NOT_FOUND');
                return;
            }
            $this->identityAccessRepository->recordAuditEvent(
                (string)($actor['sub'] ?? ''),
                'role.deleted',
                'role',
                $roleId,
                ['name' => $existing['name'] ?? $roleId]
            );
            http_response_code(204);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 409, 'ROLE_IN_USE');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'ROLE_DELETE_FAILED');
        }
    }

    public function updateNavigationGrants(string $roleId): void {
        $actor = Auth::requireAdmin();
        $data = $this->requestJson();
        $grants = $data['navigationGrants'] ?? $data['grants'] ?? null;
        if (!is_array($grants)) {
            Response::error('navigationGrants debe ser una lista.', 400, 'ROLE_GRANTS_INVALID');
            return;
        }

        try {
            $this->identityAccessRepository->replaceRoleNavigationGrants(
                $this->normalizeId($roleId),
                $grants,
                (string)($actor['sub'] ?? '')
            );
            Response::json($this->identityAccessRepository->role($this->normalizeId($roleId)));
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'ROLE_GRANTS_INVALID');
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 409, 'ROLE_SYSTEM_IMMUTABLE');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'ROLE_GRANTS_UPDATE_FAILED');
        }
    }

    public function users(string $roleId): void {
        Auth::requireAdmin();
        $roleId = $this->normalizeId($roleId);
        if (!$this->identityAccessRepository->role($roleId)) {
            Response::error('Rol no encontrado.', 404, 'ROLE_NOT_FOUND');
            return;
        }
        Response::noStore();
        $result = $this->identityAccessRepository->searchTenantUsers([
            'roleId' => $roleId,
            'search' => $_GET['search'] ?? '',
            'page' => $_GET['page'] ?? 1,
            'pageSize' => $_GET['pageSize'] ?? 20,
        ]);
        Response::json($result['data'], 200, $result['meta']);
    }

    private function requestJson(): array {
        $rawInput = file_get_contents('php://input');
        $data = is_string($rawInput) && trim($rawInput) !== '' ? json_decode($rawInput, true) : [];
        if (!is_array($data)) {
            Response::error('JSON inválido.', 400, 'INVALID_JSON');
            exit;
        }
        return $data;
    }

    private function allRoles(): array {
        return $this->tenantAccessService->allRoles($this->enabledModules());
    }

    private function enabledModules(): array {
        $tenant = $this->tenantWithStoredAdminConfig(TenantContext::get() ?? []);
        return $this->tenantAccessService->enabledModulesForTenant($tenant);
    }

    private function tenantWithStoredAdminConfig(array $tenant): array {
        $tenantId = (string)($tenant['id'] ?? $tenant['slug'] ?? TenantContext::id() ?? '');
        if ($tenantId === '') {
            return $tenant;
        }
        $overrides = $this->settingsRepository->getJson(self::TENANT_ADMIN_OVERRIDES_KEY, ['tenants' => []]);
        $storedTenant = is_array($overrides) && is_array($overrides['tenants'] ?? null)
            ? ($overrides['tenants'][$tenantId] ?? null)
            : null;
        if (is_array($storedTenant) && is_array($storedTenant['enabled_modules'] ?? null)) {
            $tenant['enabled_modules'] = $storedTenant['enabled_modules'];
        }
        return $tenant;
    }

    private function normalizePermissions($permissions): array {
        return $this->tenantAccessService->normalizePermissions($permissions, $this->enabledModules());
    }

    private function isFidepuntos(): bool {
        return strtolower((string)(TenantContext::slug() ?? TenantContext::id() ?? '')) === 'fidepuntos';
    }

    private function requiredText($value, string $message): ?string {
        $text = $this->nullableText($value, 160);
        if ($text === null) {
            Response::error($message, 400, 'ROLE_FIELD_REQUIRED');
        }
        return $text;
    }

    private function nullableText($value, int $maxLength = 255): ?string {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return null;
        }
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) : $text;
    }

    private function normalizeId($value): string {
        return strtolower(trim((string)$value));
    }

    private function newRoleId(string $name): string {
        $tenantSlug = TenantContext::slug() ?: 'tenant';
        $slug = trim((string)(preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)) ?? ''), '-');
        $slug = $slug !== '' ? $slug : 'rol';
        $base = "{$tenantSlug}_{$slug}";
        if (in_array($base, ['platform_admin', 'superadmin'], true)) {
            $base = "{$tenantSlug}_rol";
        }
        $existingIds = array_flip(array_map(
            static fn (array $role): string => (string)($role['id'] ?? ''),
            $this->allRoles()
        ));
        $candidate = $base;
        for ($suffix = 2; isset($existingIds[$candidate]); $suffix++) {
            $candidate = "{$base}-{$suffix}";
        }
        return $candidate;
    }
}
