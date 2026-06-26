<?php

namespace App\Modules\IdentityPlatform\Infrastructure;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use PDOException;

class IdentityAccessRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
    }

    public function activeTenantModules(?string $tenantId = null): ?array {
        try {
            $stmt = $this->db->prepare('
                SELECT module_key
                FROM tenant_module_entitlements
                WHERE tenant_id = :tenant_id
                  AND status = :status
                ORDER BY module_key ASC
            ');
            $stmt->execute([
                'tenant_id' => $tenantId ?: $this->tenantId(),
                'status' => 'active',
            ]);

            $modules = array_values(array_filter(array_map(
                static fn ($value): string => strtolower(trim((string)$value)),
                $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []
            )));

            return $modules !== [] ? $modules : null;
        } catch (PDOException $e) {
            if ($this->isMissingContractTable($e)) {
                return null;
            }
            throw $e;
        }
    }

    public function syncTenantModules(array $modules, ?string $tenantId = null, string $source = 'tenant-admin'): void {
        $tenantId = $tenantId ?: $this->tenantId();
        if ($tenantId === '') {
            return;
        }

        $modules = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string)$value)),
            $modules
        ))));

        try {
            $this->db->beginTransaction();

            $deactivate = $this->db->prepare('
                UPDATE tenant_module_entitlements
                SET status = :inactive,
                    updated_at = NOW()
                WHERE tenant_id = :tenant_id
            ');
            $deactivate->execute([
                'tenant_id' => $tenantId,
                'inactive' => 'inactive',
            ]);

            $upsert = $this->db->prepare('
                INSERT INTO tenant_module_entitlements (
                    tenant_id,
                    module_key,
                    status,
                    source,
                    granted_at,
                    updated_at
                ) VALUES (
                    :tenant_id,
                    :module_key,
                    :status,
                    :source,
                    NOW(),
                    NOW()
                )
                ON CONFLICT (tenant_id, module_key)
                DO UPDATE SET
                    status = EXCLUDED.status,
                    source = EXCLUDED.source,
                    updated_at = NOW()
            ');

            foreach ($modules as $moduleKey) {
                $upsert->execute([
                    'tenant_id' => $tenantId,
                    'module_key' => $moduleKey,
                    'status' => 'active',
                    'source' => $source,
                ]);
            }

            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($this->isMissingContractTable($e)) {
                return;
            }
            throw $e;
        }
    }

    public function syncMembership(string $userId, string $identityType, array $roleIds = [], string $status = 'active', ?string $tenantId = null): void {
        $tenantId = $tenantId ?: $this->tenantId();
        $userId = trim($userId);
        if ($tenantId === '' || $userId === '') {
            return;
        }

        $identityType = strtolower(trim($identityType));
        if (!in_array($identityType, ['platform', 'tenant_staff', 'customer', 'service'], true)) {
            $identityType = 'customer';
        }

        $roleIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string)$value)),
            $roleIds
        ))));

        try {
            $this->db->beginTransaction();

            $membership = $this->db->prepare('
                INSERT INTO tenant_memberships (
                    tenant_id,
                    user_id,
                    identity_type,
                    status,
                    created_at,
                    updated_at
                ) VALUES (
                    :tenant_id,
                    :user_id,
                    :identity_type,
                    :status,
                    NOW(),
                    NOW()
                )
                ON CONFLICT (tenant_id, user_id)
                DO UPDATE SET
                    identity_type = EXCLUDED.identity_type,
                    status = EXCLUDED.status,
                    updated_at = NOW()
            ');
            $membership->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'identity_type' => $identityType,
                'status' => $status,
            ]);

            $deleteRoles = $this->db->prepare('
                DELETE FROM tenant_user_roles
                WHERE tenant_id = :tenant_id
                  AND user_id = :user_id
            ');
            $deleteRoles->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);

            if ($roleIds !== []) {
                $insertRole = $this->db->prepare('
                    INSERT INTO tenant_user_roles (
                        tenant_id,
                        user_id,
                        role_id,
                        assigned_at
                    ) VALUES (
                        :tenant_id,
                        :user_id,
                        :role_id,
                        NOW()
                    )
                    ON CONFLICT (tenant_id, user_id, role_id) DO NOTHING
                ');

                foreach ($roleIds as $roleId) {
                    $insertRole->execute([
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'role_id' => $roleId,
                    ]);
                }
            }

            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($this->isMissingContractTable($e)) {
                return;
            }
            throw $e;
        }
    }

    public function syncRole(array $role, ?string $tenantId = null): void {
        $tenantId = $tenantId ?: $this->tenantId();
        $roleId = strtolower(trim((string)($role['id'] ?? '')));
        if ($tenantId === '' || $roleId === '') {
            return;
        }

        $permissions = is_array($role['permissions'] ?? null) ? $role['permissions'] : [];
        $encodedPermissions = json_encode(array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string)$value)),
            $permissions
        )))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $stmt = $this->db->prepare('
                INSERT INTO tenant_roles (
                    tenant_id,
                    role_id,
                    name,
                    description,
                    permissions,
                    system_role,
                    created_at,
                    updated_at
                ) VALUES (
                    :tenant_id,
                    :role_id,
                    :name,
                    :description,
                    CAST(:permissions AS jsonb),
                    :system_role,
                    NOW(),
                    NOW()
                )
                ON CONFLICT (tenant_id, role_id)
                DO UPDATE SET
                    name = EXCLUDED.name,
                    description = EXCLUDED.description,
                    permissions = EXCLUDED.permissions,
                    system_role = EXCLUDED.system_role,
                    updated_at = NOW()
            ');
            $stmt->execute([
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'name' => (string)($role['name'] ?? $roleId),
                'description' => (string)($role['description'] ?? 'Rol del tenant.'),
                'permissions' => $encodedPermissions ?: '[]',
                'system_role' => !empty($role['system']) ? 1 : 0,
            ]);
        } catch (PDOException $e) {
            if ($this->isMissingContractTable($e)) {
                return;
            }
            throw $e;
        }
    }

    public function deleteRole(string $roleId, ?string $tenantId = null): void {
        $tenantId = $tenantId ?: $this->tenantId();
        $roleId = strtolower(trim($roleId));
        if ($tenantId === '' || $roleId === '') {
            return;
        }

        try {
            $deleteAssignments = $this->db->prepare('
                DELETE FROM tenant_user_roles
                WHERE tenant_id = :tenant_id
                  AND role_id = :role_id
            ');
            $deleteAssignments->execute([
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
            ]);

            $deleteRole = $this->db->prepare('
                DELETE FROM tenant_roles
                WHERE tenant_id = :tenant_id
                  AND role_id = :role_id
                  AND system_role = FALSE
            ');
            $deleteRole->execute([
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
            ]);
        } catch (PDOException $e) {
            if ($this->isMissingContractTable($e)) {
                return;
            }
            throw $e;
        }
    }

    private function tenantId(): string {
        return (string)(TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec'));
    }

    private function isMissingContractTable(PDOException $e): bool {
        $sqlState = (string)$e->getCode();
        $message = strtolower($e->getMessage());

        return $sqlState === '42P01'
            || str_contains($message, 'tenant_module_entitlements')
            || str_contains($message, 'tenant_memberships')
            || str_contains($message, 'tenant_roles')
            || str_contains($message, 'tenant_user_roles');
    }
}
