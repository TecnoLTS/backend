<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Repositories\SettingsRepository;

class RoleController {
    private SettingsRepository $settingsRepository;
    private TenantAccessService $tenantAccessService;

    private const TENANT_ADMIN_OVERRIDES_KEY = TenantAccessService::TENANT_ADMIN_OVERRIDES_KEY;

    public function __construct() {
        $this->settingsRepository = new SettingsRepository();
        $this->tenantAccessService = new TenantAccessService($this->settingsRepository);
    }

    public function index() {
        Auth::requireAdmin();
        Response::noStore();

        $query = $_GET;
        $search = strtolower(trim((string)($query['search'] ?? '')));
        $page = max(1, (int)($query['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($query['pageSize'] ?? 20)));
        $roles = $this->allRoles();

        if ($search !== '') {
            $roles = array_values(array_filter($roles, static function (array $role) use ($search): bool {
                $haystack = strtolower(($role['name'] ?? '') . ' ' . ($role['description'] ?? ''));
                return str_contains($haystack, $search);
            }));
        }

        $totalItems = count($roles);
        $totalPages = max(1, (int)ceil($totalItems / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;

        Response::json(
            array_slice($roles, $offset, $pageSize),
            200,
            [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ]
        );
    }

    public function show(string $roleId) {
        Auth::requireAdmin();
        Response::noStore();

        $role = $this->findRole($roleId);
        if ($role === null) {
            Response::error('Rol no encontrado.', 404, 'ROLE_NOT_FOUND');
            return;
        }

        Response::json($role);
    }

    public function store() {
        Auth::requireAdmin();
        $data = $this->requestJson();
        $now = gmdate('c');
        $name = $this->requiredText($data['name'] ?? null, 'El nombre del rol es obligatorio.');
        if ($name === null) {
            return;
        }

        $description = $this->nullableText($data['description'] ?? null) ?? 'Rol personalizado del tenant.';
        $permissions = $this->normalizePermissions($data['permissions'] ?? []);

        $customRoles = $this->customRoles();
        $role = [
            'id' => $this->newRoleId($name),
            'name' => $name,
            'description' => $description,
            'permissions' => $permissions,
            'system' => false,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];

        $customRoles[] = $role;
        $this->saveCustomRoles($customRoles);

        Response::json($role, 201);
    }

    public function update(string $roleId) {
        Auth::requireAdmin();
        $data = $this->requestJson();
        $customRoles = $this->customRoles();
        $index = $this->customRoleIndex($customRoles, $roleId);

        if ($index === null) {
            if ($this->findSystemRole($roleId) !== null) {
                Response::error('Los roles de sistema no se pueden modificar desde esta pantalla.', 409, 'ROLE_SYSTEM_IMMUTABLE');
                return;
            }
            Response::error('Rol no encontrado.', 404, 'ROLE_NOT_FOUND');
            return;
        }

        $role = $customRoles[$index];
        if (array_key_exists('name', $data)) {
            $name = $this->requiredText($data['name'], 'El nombre del rol es obligatorio.');
            if ($name === null) {
                return;
            }
            $role['name'] = $name;
        }
        if (array_key_exists('description', $data)) {
            $role['description'] = $this->nullableText($data['description']) ?? 'Rol personalizado del tenant.';
        }
        if (array_key_exists('permissions', $data)) {
            $role['permissions'] = $this->normalizePermissions($data['permissions']);
        }
        $role['updatedAt'] = gmdate('c');

        $customRoles[$index] = $role;
        $this->saveCustomRoles($customRoles);

        Response::json($role);
    }

    public function destroy(string $roleId) {
        Auth::requireAdmin();
        $customRoles = $this->customRoles();
        $index = $this->customRoleIndex($customRoles, $roleId);

        if ($index === null) {
            if ($this->findSystemRole($roleId) !== null) {
                Response::error('Los roles de sistema no se pueden eliminar desde esta pantalla.', 409, 'ROLE_SYSTEM_IMMUTABLE');
                return;
            }
            Response::error('Rol no encontrado.', 404, 'ROLE_NOT_FOUND');
            return;
        }

        array_splice($customRoles, $index, 1);
        $this->saveCustomRoles($customRoles);
        $this->tenantAccessService->deleteCustomRole($roleId);

        http_response_code(204);
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

    private function allRoles(): array {
        return array_values(array_merge($this->systemRoles(), $this->customRoles()));
    }

    private function findRole(string $roleId): ?array {
        foreach ($this->allRoles() as $role) {
            if (($role['id'] ?? '') === $roleId) {
                return $role;
            }
        }

        return null;
    }

    private function findSystemRole(string $roleId): ?array {
        foreach ($this->systemRoles() as $role) {
            if (($role['id'] ?? '') === $roleId) {
                return $role;
            }
        }

        return null;
    }

    private function customRoleIndex(array $roles, string $roleId): ?int {
        foreach ($roles as $index => $role) {
            if (($role['id'] ?? '') === $roleId) {
                return $index;
            }
        }

        return null;
    }

    private function systemRoles(): array {
        return $this->tenantAccessService->systemRoles($this->enabledModules(), TenantContext::slug() ?: 'tenant');
    }

    private function customRoles(): array {
        return $this->tenantAccessService->customRoles($this->enabledModules());
    }

    private function saveCustomRoles(array $roles): void {
        $this->tenantAccessService->saveCustomRoles(array_values($roles));
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
        if (!is_array($overrides) || !is_array($overrides['tenants'] ?? null)) {
            return $tenant;
        }

        $storedTenant = $overrides['tenants'][$tenantId] ?? null;
        if (!is_array($storedTenant)) {
            return $tenant;
        }

        if (is_array($storedTenant['enabled_modules'] ?? null)) {
            $tenant['enabled_modules'] = $storedTenant['enabled_modules'];
        }

        return $tenant;
    }

    private function tenantAdminPermissions(array $enabledModules): array {
        return $this->tenantAccessService->tenantAdminPermissions($enabledModules);
    }

    private function readOnlyPermissions(array $enabledModules): array {
        return $this->tenantAccessService->readOnlyPermissions($enabledModules);
    }

    private function allowedPermissions(): array {
        return $this->tenantAdminPermissions($this->enabledModules());
    }

    private function normalizePermissions($permissions): array {
        return $this->tenantAccessService->normalizePermissions($permissions, $this->enabledModules());
    }

    private function requiredText($value, string $message): ?string {
        $text = $this->nullableText($value, 160);
        if ($text === null) {
            Response::error($message, 400, 'ROLE_FIELD_REQUIRED');
            return null;
        }

        return $text;
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

    private function normalizeId($value): string {
        return strtolower(trim((string)$value));
    }

    private function newRoleId(string $name): string {
        $tenantSlug = TenantContext::slug() ?: 'tenant';
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'rol';
        }

        $base = "{$tenantSlug}_{$slug}";
        $existingIds = array_flip(array_map(static fn (array $role): string => (string)($role['id'] ?? ''), $this->allRoles()));
        $candidate = $base;
        $suffix = 2;
        while (isset($existingIds[$candidate])) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
