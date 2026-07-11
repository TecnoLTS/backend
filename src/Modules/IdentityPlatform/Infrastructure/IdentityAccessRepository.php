<?php

namespace App\Modules\IdentityPlatform\Infrastructure;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\LoyaltyRewards\Application\LoyaltyNavigationService;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

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
        if ($identityType === 'platform' && $tenantId !== 'platform') {
            $roleIds = [];
        }
        if ($roleIds !== []) {
            $this->assertAssignableRoleIds($roleIds, $tenantId);
        }

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
        if ($tenantId === 'fidepuntos') {
            $permissions = array_values(array_filter(
                $permissions,
                static fn ($permission): bool => preg_match('/^(identity|loyalty)\./', strtolower(trim((string)$permission))) !== 1
            ));
        }
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
                WHERE tenant_roles.name IS DISTINCT FROM EXCLUDED.name
                   OR tenant_roles.description IS DISTINCT FROM EXCLUDED.description
                   OR tenant_roles.permissions IS DISTINCT FROM EXCLUDED.permissions
                   OR tenant_roles.system_role IS DISTINCT FROM EXCLUDED.system_role
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

    public function createCustomRoleWithNavigationGrants(
        array $role,
        array $grants,
        ?string $actorUserId = null,
        ?string $tenantId = null
    ): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $roleId = strtolower(trim((string)($role['id'] ?? '')));
        if ($tenantId === '' || $roleId === '') {
            throw new InvalidArgumentException('El rol no tiene un identificador válido.');
        }
        if (!empty($role['system'])) {
            throw new InvalidArgumentException('Los roles operativos no pueden convertirse en roles del sistema.');
        }

        $normalized = $this->normalizeNavigationGrants($grants);
        $this->assertOperationalRoleGrants($normalized, $tenantId);
        $this->db->beginTransaction();
        try {
            $insertRole = $this->db->prepare('
                INSERT INTO tenant_roles (
                    tenant_id, role_id, name, description, permissions,
                    system_role, created_at, updated_at
                ) VALUES (
                    :tenant_id, :role_id, :name, :description, \'[]\'::jsonb,
                    FALSE, NOW(), NOW()
                )
            ');
            $insertRole->execute([
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'name' => trim((string)($role['name'] ?? $roleId)),
                'description' => trim((string)($role['description'] ?? 'Rol operativo del tenant.')),
            ]);

            $insertGrant = $this->db->prepare('
                INSERT INTO tenant_role_navigation_grants (
                    tenant_id, role_id, menu_option_key, action_key,
                    assigned_by_user_id, granted_at, updated_at
                ) VALUES (
                    :tenant_id, :role_id, :menu_option_key, :action_key,
                    :assigned_by_user_id, NOW(), NOW()
                )
            ');
            foreach ($normalized as $grant) {
                foreach ($grant['actions'] as $action) {
                    $insertGrant->execute([
                        'tenant_id' => $tenantId,
                        'role_id' => $roleId,
                        'menu_option_key' => $grant['menuOptionKey'],
                        'action_key' => $action,
                        'assigned_by_user_id' => $actorUserId,
                    ]);
                }
            }

            $this->insertAuditEvent(
                $tenantId,
                $actorUserId,
                'role.created',
                'role',
                $roleId,
                [
                    'name' => trim((string)($role['name'] ?? $roleId)),
                    'navigationGrants' => $normalized,
                ]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->role($roleId, $tenantId) ?? throw new RuntimeException('No se pudo recuperar el rol creado.');
    }

    public function roles(?string $tenantId = null): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $stmt = $this->db->prepare('
            SELECT
                role.tenant_id,
                role.role_id,
                role.name,
                role.description,
                role.permissions,
                role.system_role,
                role.created_at,
                role.updated_at,
                COUNT(DISTINCT assignment.user_id)::int AS assigned_users_count,
                COUNT(DISTINCT grant_row.menu_option_key)::int AS navigation_grants_count
            FROM tenant_roles role
            LEFT JOIN tenant_user_roles assignment
              ON assignment.tenant_id = role.tenant_id
             AND assignment.role_id = role.role_id
            LEFT JOIN tenant_role_navigation_grants grant_row
              ON grant_row.tenant_id = role.tenant_id
             AND grant_row.role_id = role.role_id
            WHERE role.tenant_id = :tenant_id
            GROUP BY role.tenant_id, role.role_id, role.name, role.description,
                     role.permissions, role.system_role, role.created_at, role.updated_at
            ORDER BY role.system_role DESC, LOWER(role.name), role.role_id
        ');
        $stmt->execute(['tenant_id' => $tenantId]);

        return array_map(fn (array $row): array => $this->roleRecord($row), $stmt->fetchAll() ?: []);
    }

    public function role(string $roleId, ?string $tenantId = null): ?array {
        $roleId = strtolower(trim($roleId));
        foreach ($this->roles($tenantId) as $role) {
            if (($role['id'] ?? '') === $roleId) {
                $role['navigationGrants'] = $this->navigationGrantsForRole($roleId, $tenantId);
                return $role;
            }
        }

        return null;
    }

    public function rolesForUser(string $userId, ?string $tenantId = null): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $stmt = $this->db->prepare('
            SELECT
                role.tenant_id,
                role.role_id,
                role.name,
                role.description,
                role.permissions,
                role.system_role,
                role.created_at,
                role.updated_at,
                0::int AS assigned_users_count,
                0::int AS navigation_grants_count
            FROM tenant_user_roles assignment
            JOIN tenant_roles role
              ON role.tenant_id = assignment.tenant_id
             AND role.role_id = assignment.role_id
            WHERE assignment.tenant_id = :tenant_id
              AND assignment.user_id = :user_id
            ORDER BY role.system_role DESC, LOWER(role.name), role.role_id
        ');
        $stmt->execute([
            'tenant_id' => $tenantId,
            'user_id' => trim($userId),
        ]);

        return array_map(fn (array $row): array => $this->roleRecord($row), $stmt->fetchAll() ?: []);
    }

    public function roleIdsForUser(string $userId, ?string $tenantId = null): array {
        return array_values(array_map(
            static fn (array $role): string => (string)$role['id'],
            $this->rolesForUser($userId, $tenantId)
        ));
    }

    public function validateAssignableRoleIds(array $roleIds, ?string $tenantId = null): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $normalized = $this->normalizeRoleIds($roleIds);
        if ($normalized === []) {
            throw new InvalidArgumentException('Debes asignar al menos un rol operativo del tenant.');
        }
        $this->assertAssignableRoleIds($normalized, $tenantId);
        $systemRoleIds = $this->systemRoleIds($normalized, $tenantId);
        if ($systemRoleIds !== []) {
            throw new InvalidArgumentException('Los roles base del sistema no se pueden asignar desde un tenant.');
        }
        return $normalized;
    }

    public function membershipForUser(string $userId, ?string $tenantId = null): ?array {
        $tenantId = $tenantId ?: $this->tenantId();
        $stmt = $this->db->prepare('
            SELECT tenant_id, user_id, identity_type, status, created_at, updated_at
            FROM tenant_memberships
            WHERE tenant_id = :tenant_id AND user_id = :user_id
            LIMIT 1
        ');
        $stmt->execute([
            'tenant_id' => $tenantId,
            'user_id' => trim($userId),
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function replaceUserRoles(
        string $userId,
        array $roleIds,
        ?string $actorUserId = null,
        ?string $tenantId = null
    ): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $userId = trim($userId);
        $roleIds = $this->normalizeRoleIds($roleIds);
        if ($userId === '') {
            throw new InvalidArgumentException('Usuario no válido para asignación de roles.');
        }

        if ($roleIds !== []) {
            $this->assertAssignableRoleIds($roleIds, $tenantId);
        }

        $this->db->beginTransaction();
        try {
            $this->lockTenantAccessMutation($tenantId);
            $membership = $this->db->prepare('
                SELECT status
                FROM tenant_memberships
                WHERE tenant_id = :tenant_id AND user_id = :user_id
                FOR UPDATE
            ');
            $membership->execute(['tenant_id' => $tenantId, 'user_id' => $userId]);
            $membershipStatus = $membership->fetchColumn();
            if ($membershipStatus === false) {
                throw new InvalidArgumentException('El usuario no pertenece al tenant activo.');
            }

            $adminRoleId = "{$tenantId}_admin";
            $currentRoleIds = $this->roleIdsForUser($userId, $tenantId);
            $currentSystemRoleIds = $this->systemRoleIds($currentRoleIds, $tenantId);
            $requestedSystemRoleIds = $this->systemRoleIds($roleIds, $tenantId);
            $newSystemRoleIds = array_values(array_diff($requestedSystemRoleIds, $currentSystemRoleIds));
            if ($newSystemRoleIds !== []) {
                throw new InvalidArgumentException('Los roles base del sistema no se pueden asignar desde un tenant.');
            }
            $customRoleIds = array_values(array_diff($roleIds, $requestedSystemRoleIds));
            $roleIds = array_values(array_unique([...$currentSystemRoleIds, ...$customRoleIds]));
            if ($roleIds === []) {
                throw new InvalidArgumentException('El usuario debe conservar al menos un rol del tenant.');
            }
            $removesAdmin = in_array($adminRoleId, $currentRoleIds, true)
                && !in_array($adminRoleId, $roleIds, true);
            if ($removesAdmin && trim((string)$actorUserId) === $userId) {
                throw new RuntimeException('No puedes retirar tu propio rol de administrador.');
            }
            if (
                $removesAdmin
                && strtolower((string)$membershipStatus) === 'active'
                && $this->countActiveUsersWithRole($adminRoleId, $tenantId, $userId) < 1
            ) {
                throw new RuntimeException('El tenant debe conservar al menos un administrador activo.');
            }

            $delete = $this->db->prepare('
                DELETE FROM tenant_user_roles
                WHERE tenant_id = :tenant_id AND user_id = :user_id
            ');
            $delete->execute(['tenant_id' => $tenantId, 'user_id' => $userId]);

            $insert = $this->db->prepare('
                INSERT INTO tenant_user_roles (tenant_id, user_id, role_id, assigned_at)
                VALUES (:tenant_id, :user_id, :role_id, NOW())
            ');
            foreach ($roleIds as $roleId) {
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'role_id' => $roleId,
                ]);
            }

            $touch = $this->db->prepare('
                UPDATE tenant_memberships SET updated_at = NOW()
                WHERE tenant_id = :tenant_id AND user_id = :user_id
            ');
            $touch->execute(['tenant_id' => $tenantId, 'user_id' => $userId]);
            $this->insertAuditEvent(
                $tenantId,
                $actorUserId,
                'user.roles.updated',
                'user',
                $userId,
                ['roleIds' => $roleIds]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->rolesForUser($userId, $tenantId);
    }

    public function updateMembershipStatus(
        string $userId,
        string $status,
        ?string $actorUserId = null,
        ?string $tenantId = null
    ): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $status = strtolower(trim($status));
        if (!in_array($status, ['invited', 'active', 'inactive', 'blocked'], true)) {
            throw new InvalidArgumentException('Estado de cuenta no permitido.');
        }

        $userId = trim($userId);
        $this->db->beginTransaction();
        try {
            $this->lockTenantAccessMutation($tenantId);
            $current = $this->db->prepare('
                SELECT status
                FROM tenant_memberships
                WHERE tenant_id = :tenant_id AND user_id = :user_id
                FOR UPDATE
            ');
            $current->execute(['tenant_id' => $tenantId, 'user_id' => $userId]);
            $currentStatus = $current->fetchColumn();
            if ($currentStatus === false) {
                throw new InvalidArgumentException('El usuario no pertenece al tenant activo.');
            }
            if ($status !== 'active' && trim((string)$actorUserId) === $userId) {
                throw new RuntimeException('No puedes bloquear o desactivar tu propia cuenta.');
            }

            $adminRoleId = "{$tenantId}_admin";
            if (
                strtolower((string)$currentStatus) === 'active'
                && $status !== 'active'
                && in_array($adminRoleId, $this->roleIdsForUser($userId, $tenantId), true)
                && $this->countActiveUsersWithRole($adminRoleId, $tenantId, $userId) < 1
            ) {
                throw new RuntimeException('El tenant debe conservar al menos un administrador activo.');
            }

            $stmt = $this->db->prepare('
                UPDATE tenant_memberships
                SET status = :status, updated_at = NOW()
                WHERE tenant_id = :tenant_id AND user_id = :user_id
                RETURNING tenant_id, user_id, identity_type, status, created_at, updated_at
            ');
            $stmt->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'status' => $status,
            ]);
            $membership = $stmt->fetch();
            $this->insertAuditEvent(
                $tenantId,
                $actorUserId,
                'user.status.updated',
                'user',
                $userId,
                ['accountStatus' => $status]
            );
            $this->db->commit();
            return $membership;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function activateInvitedMembership(
        string $userId,
        ?string $actorUserId = null,
        ?string $tenantId = null
    ): bool {
        $tenantId = $tenantId ?: $this->tenantId();
        $stmt = $this->db->prepare('
            UPDATE tenant_memberships
            SET status = \'active\', updated_at = NOW()
            WHERE tenant_id = :tenant_id
              AND user_id = :user_id
              AND status = \'invited\'
        ');
        $stmt->execute([
            'tenant_id' => $tenantId,
            'user_id' => trim($userId),
        ]);
        if ($stmt->rowCount() < 1) {
            return false;
        }
        $this->recordAuditEvent(
            $actorUserId,
            'user.invitation.accepted',
            'user',
            trim($userId),
            [],
            $tenantId
        );
        return true;
    }

    public function countActiveUsersWithRole(string $roleId, ?string $tenantId = null, ?string $excludeUserId = null): int {
        $tenantId = $tenantId ?: $this->tenantId();
        $sql = '
            SELECT COUNT(DISTINCT assignment.user_id)
            FROM tenant_user_roles assignment
            JOIN tenant_memberships membership
              ON membership.tenant_id = assignment.tenant_id
             AND membership.user_id = assignment.user_id
            WHERE assignment.tenant_id = :tenant_id
              AND assignment.role_id = :role_id
              AND membership.status = \'active\'
        ';
        $params = ['tenant_id' => $tenantId, 'role_id' => strtolower(trim($roleId))];
        if ($excludeUserId !== null && trim($excludeUserId) !== '') {
            $sql .= ' AND assignment.user_id <> :exclude_user_id';
            $params['exclude_user_id'] = trim($excludeUserId);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function roleAssignedCount(string $roleId, ?string $tenantId = null): int {
        $tenantId = $tenantId ?: $this->tenantId();
        $stmt = $this->db->prepare('
            SELECT COUNT(DISTINCT user_id)
            FROM tenant_user_roles
            WHERE tenant_id = :tenant_id AND role_id = :role_id
        ');
        $stmt->execute(['tenant_id' => $tenantId, 'role_id' => strtolower(trim($roleId))]);
        return (int)$stmt->fetchColumn();
    }

    public function navigationGrantsForRole(string $roleId, ?string $tenantId = null): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $stmt = $this->db->prepare('
            SELECT menu_option_key, action_key
            FROM tenant_role_navigation_grants
            WHERE tenant_id = :tenant_id AND role_id = :role_id
            ORDER BY menu_option_key, action_key
        ');
        $stmt->execute([
            'tenant_id' => $tenantId,
            'role_id' => strtolower(trim($roleId)),
        ]);

        $grouped = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $menuOptionKey = (string)$row['menu_option_key'];
            $grouped[$menuOptionKey] ??= [];
            $grouped[$menuOptionKey][] = (string)$row['action_key'];
        }

        $result = [];
        foreach ($grouped as $menuOptionKey => $actions) {
            $result[] = [
                'menuOptionKey' => $menuOptionKey,
                'actions' => array_values(array_unique($actions)),
            ];
        }
        return $result;
    }

    public function navigationGrantsForUser(string $userId, ?string $tenantId = null): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $stmt = $this->db->prepare('
            SELECT DISTINCT grant_row.menu_option_key, grant_row.action_key
            FROM tenant_user_roles assignment
            JOIN tenant_role_navigation_grants grant_row
              ON grant_row.tenant_id = assignment.tenant_id
             AND grant_row.role_id = assignment.role_id
            WHERE assignment.tenant_id = :tenant_id
              AND assignment.user_id = :user_id
            ORDER BY grant_row.menu_option_key, grant_row.action_key
        ');
        $stmt->execute([
            'tenant_id' => $tenantId,
            'user_id' => trim($userId),
        ]);

        $grouped = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $menuOptionKey = (string)$row['menu_option_key'];
            $grouped[$menuOptionKey] ??= [];
            $grouped[$menuOptionKey][] = (string)$row['action_key'];
        }

        $result = [];
        foreach ($grouped as $menuOptionKey => $actions) {
            $result[] = [
                'menuOptionKey' => $menuOptionKey,
                'actions' => array_values(array_unique($actions)),
            ];
        }
        return $result;
    }

    public function navigationPermissionsForUser(string $userId, ?string $tenantId = null): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $permissionsByGrant = $this->navigationPermissionMap($tenantId);
        $permissions = [];
        foreach ($this->navigationGrantsForUser($userId, $tenantId) as $grant) {
            $menuOptionKey = (string)($grant['menuOptionKey'] ?? '');
            foreach ($grant['actions'] ?? [] as $actionKey) {
                $permission = $permissionsByGrant[$menuOptionKey][(string)$actionKey] ?? null;
                if (is_string($permission) && $permission !== '') {
                    $permissions[] = $permission;
                }
            }
        }
        return array_values(array_unique($permissions));
    }

    public function replaceRoleNavigationGrants(
        string $roleId,
        array $grants,
        ?string $actorUserId = null,
        ?string $tenantId = null
    ): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $roleId = strtolower(trim($roleId));
        $role = $this->role($roleId, $tenantId);
        if (!$role) {
            throw new InvalidArgumentException('Rol no encontrado en el tenant activo.');
        }
        if (!empty($role['system'])) {
            throw new RuntimeException('Los roles de sistema son inmutables.');
        }

        $normalized = $this->normalizeNavigationGrants($grants);
        $this->assertOperationalRoleGrants($normalized, $tenantId);
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('
                DELETE FROM tenant_role_navigation_grants
                WHERE tenant_id = :tenant_id AND role_id = :role_id
            ');
            $delete->execute(['tenant_id' => $tenantId, 'role_id' => $roleId]);

            $insert = $this->db->prepare('
                INSERT INTO tenant_role_navigation_grants (
                    tenant_id, role_id, menu_option_key, action_key,
                    assigned_by_user_id, granted_at, updated_at
                ) VALUES (
                    :tenant_id, :role_id, :menu_option_key, :action_key,
                    :assigned_by_user_id, NOW(), NOW()
                )
            ');
            foreach ($normalized as $grant) {
                foreach ($grant['actions'] as $action) {
                    $insert->execute([
                        'tenant_id' => $tenantId,
                        'role_id' => $roleId,
                        'menu_option_key' => $grant['menuOptionKey'],
                        'action_key' => $action,
                        'assigned_by_user_id' => $actorUserId,
                    ]);
                }
            }

            $touch = $this->db->prepare('
                UPDATE tenant_roles SET updated_at = NOW()
                WHERE tenant_id = :tenant_id AND role_id = :role_id
            ');
            $touch->execute(['tenant_id' => $tenantId, 'role_id' => $roleId]);
            $this->revokeSessionsForRoleRows($roleId, $tenantId);
            $this->insertAuditEvent(
                $tenantId,
                $actorUserId,
                'role.navigation_grants.updated',
                'role',
                $roleId,
                ['navigationGrants' => $normalized]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->navigationGrantsForRole($roleId, $tenantId);
    }

    public function validateRoleNavigationGrants(array $grants): array {
        return $this->normalizeNavigationGrants($grants);
    }

    public function usersForRole(string $roleId, ?string $tenantId = null): array {
        $result = $this->searchTenantUsers([
            'roleId' => strtolower(trim($roleId)),
            'page' => 1,
            'pageSize' => 100,
        ], $tenantId);

        return $result['data'];
    }

    public function searchTenantUsers(array $filters = [], ?string $tenantId = null): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($filters['pageSize'] ?? 20)));
        $search = trim((string)($filters['search'] ?? ''));
        $status = strtolower(trim((string)($filters['status'] ?? 'all')));
        $roleId = strtolower(trim((string)($filters['roleId'] ?? '')));
        $conditions = [
            'u.tenant_id = :tenant_id',
            "membership.identity_type = 'tenant_staff'",
        ];
        $params = ['tenant_id' => $tenantId];

        if ($search !== '') {
            $conditions[] = "CONCAT_WS(' ', u.name, u.email, u.profile->>'department', u.profile->>'position', role_data.role_names) ILIKE :search";
            $params['search'] = '%' . $search . '%';
        }
        if (in_array($status, ['invited', 'active', 'inactive', 'blocked'], true)) {
            $conditions[] = 'membership.status = :status';
            $params['status'] = $status;
        }
        if ($roleId !== '') {
            $conditions[] = 'EXISTS (
                SELECT 1
                FROM tenant_user_roles role_filter
                WHERE role_filter.tenant_id = u.tenant_id
                  AND role_filter.user_id = u.id
                  AND role_filter.role_id = :role_id
            )';
            $params['role_id'] = $roleId;
        }

        $from = '
            FROM "User" u
            JOIN tenant_memberships membership
              ON membership.tenant_id = u.tenant_id
             AND membership.user_id = u.id
            LEFT JOIN LATERAL (
                SELECT
                    COALESCE(
                        jsonb_agg(
                            jsonb_build_object(
                                \'id\', assigned_role.role_id,
                                \'name\', assigned_role.name,
                                \'system\', assigned_role.system_role
                            ) ORDER BY assigned_role.system_role DESC, LOWER(assigned_role.name), assigned_role.role_id
                        ),
                        \'[]\'::jsonb
                    ) AS assignments,
                    COALESCE(string_agg(assigned_role.name, \' \' ORDER BY LOWER(assigned_role.name)), \'\') AS role_names
                FROM tenant_user_roles assignment
                JOIN tenant_roles assigned_role
                  ON assigned_role.tenant_id = assignment.tenant_id
                 AND assigned_role.role_id = assignment.role_id
                WHERE assignment.tenant_id = u.tenant_id
                  AND assignment.user_id = u.id
            ) role_data ON TRUE
        ';
        $where = ' WHERE ' . implode(' AND ', $conditions);

        $count = $this->db->prepare('SELECT COUNT(*) ' . $from . $where);
        $count->execute($params);
        $totalItems = (int)$count->fetchColumn();
        $totalPages = max(1, (int)ceil($totalItems / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;

        $data = $this->db->prepare('
            SELECT
                u.id,
                u.name,
                u.email,
                u.email_verified,
                u.profile,
                u.created_at,
                u.updated_at,
                u.last_login_at,
                u.failed_login_attempts,
                u.login_locked_until,
                membership.status AS account_status,
                membership.identity_type,
                role_data.assignments AS role_assignments
            ' . $from . $where . '
            ORDER BY LOWER(u.name), LOWER(u.email), u.id
            LIMIT ' . $pageSize . ' OFFSET ' . $offset . '
        ');
        $data->execute($params);

        $summary = $this->db->prepare('
            SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE membership.status = \'active\')::int AS active,
                COUNT(*) FILTER (WHERE membership.status = \'invited\')::int AS invited,
                COUNT(*) FILTER (WHERE membership.status = \'inactive\')::int AS inactive,
                COUNT(*) FILTER (WHERE membership.status = \'blocked\')::int AS blocked,
                COUNT(*) FILTER (WHERE u.login_locked_until > NOW())::int AS security_locked
            FROM "User" u
            JOIN tenant_memberships membership
              ON membership.tenant_id = u.tenant_id
             AND membership.user_id = u.id
            WHERE u.tenant_id = :tenant_id
              AND membership.identity_type = \'tenant_staff\'
        ');
        $summary->execute(['tenant_id' => $tenantId]);
        $summaryRow = $summary->fetch() ?: [];

        return [
            'data' => array_map(fn (array $row): array => $this->tenantUserRecord($row), $data->fetchAll() ?: []),
            'meta' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
                'summary' => [
                    'total' => (int)($summaryRow['total'] ?? 0),
                    'active' => (int)($summaryRow['active'] ?? 0),
                    'invited' => (int)($summaryRow['invited'] ?? 0),
                    'inactive' => (int)($summaryRow['inactive'] ?? 0),
                    'blocked' => (int)($summaryRow['blocked'] ?? 0),
                    'securityLocked' => (int)($summaryRow['security_locked'] ?? 0),
                ],
            ],
        ];
    }

    public function revokeSessionsForRole(string $roleId, ?string $tenantId = null): int {
        $tenantId = $tenantId ?: $this->tenantId();
        $roleId = strtolower(trim($roleId));
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $affectedUsers = $this->revokeSessionsForRoleRows($roleId, $tenantId);
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $affectedUsers;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function recordAuditEvent(
        ?string $actorUserId,
        string $eventType,
        string $targetType,
        ?string $targetId = null,
        array $metadata = [],
        ?string $tenantId = null
    ): void {
        $this->insertAuditEvent(
            $tenantId ?: $this->tenantId(),
            $actorUserId,
            $eventType,
            $targetType,
            $targetId,
            $metadata
        );
    }

    public function auditEvents(array $filters = [], ?string $tenantId = null): array {
        $tenantId = $tenantId ?: $this->tenantId();
        $conditions = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];
        foreach (['actorUserId' => 'actor_user_id', 'targetType' => 'target_type', 'targetId' => 'target_id', 'eventType' => 'event_type'] as $filter => $column) {
            $value = trim((string)($filters[$filter] ?? ''));
            if ($value !== '') {
                $conditions[] = "{$column} = :{$filter}";
                $params[$filter] = $value;
            }
        }
        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sql = sprintf(
            'SELECT id, tenant_id, actor_user_id, event_type, target_type, target_id, metadata, created_at
             FROM tenant_access_audit_events
             WHERE %s
             ORDER BY created_at DESC, id DESC
             LIMIT %d OFFSET %d',
            implode(' AND ', $conditions),
            $limit,
            $offset
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map(static function (array $row): array {
            $metadata = $row['metadata'] ?? [];
            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
            $eventType = (string)$row['event_type'];
            $targetType = (string)$row['target_type'];
            $targetId = $row['target_id'] !== null ? (string)$row['target_id'] : null;
            $summary = match ($eventType) {
                'user.roles.updated' => 'Roles del usuario actualizados',
                'user.status.updated' => 'Estado de cuenta actualizado',
                'user.security_lock.cleared' => 'Bloqueo automático eliminado',
                'user.invitation.sent' => 'Invitación enviada',
                'user.password_reset.sent' => 'Restablecimiento de contraseña enviado',
                'user.sessions.revoked', 'user.sessions.revoked.self' => 'Sesiones revocadas',
                'user.password.changed.self' => 'Contraseña actualizada',
                'role.created' => 'Rol creado',
                'role.updated' => 'Rol actualizado',
                'role.deleted' => 'Rol eliminado',
                'role.navigation_grants.updated' => 'Opciones de menú del rol actualizadas',
                default => 'Actividad de acceso registrada',
            };
            return [
                'id' => (string)$row['id'],
                'tenantId' => (string)$row['tenant_id'],
                'actorUserId' => $row['actor_user_id'] !== null ? (string)$row['actor_user_id'] : null,
                'eventType' => $eventType,
                'action' => $eventType,
                'summary' => $summary,
                'targetType' => $targetType,
                'targetId' => $targetId,
                'subjectUserId' => $targetType === 'user' ? $targetId : null,
                'roleId' => $targetType === 'role' ? $targetId : null,
                'metadata' => is_array($metadata) ? $metadata : [],
                'createdAt' => gmdate('c', strtotime((string)$row['created_at']) ?: 0),
            ];
        }, $stmt->fetchAll() ?: []);
    }

    public function accessVersion(?string $tenantId = null): string {
        $tenantId = $tenantId ?: $this->tenantId();
        $stmt = $this->db->prepare('
            SELECT GREATEST(
                COALESCE((SELECT MAX(updated_at) FROM tenant_roles WHERE tenant_id = :tenant_roles), TIMESTAMP \'epoch\'),
                COALESCE((SELECT MAX(updated_at) FROM tenant_memberships WHERE tenant_id = :tenant_memberships), TIMESTAMP \'epoch\'),
                COALESCE((SELECT MAX(updated_at) FROM tenant_role_navigation_grants WHERE tenant_id = :tenant_grants), TIMESTAMP \'epoch\'),
                COALESCE((SELECT MAX(created_at) FROM tenant_access_audit_events WHERE tenant_id = :tenant_audit), TIMESTAMP \'epoch\')
            ) AS access_version
        ');
        $stmt->execute([
            'tenant_roles' => $tenantId,
            'tenant_memberships' => $tenantId,
            'tenant_grants' => $tenantId,
            'tenant_audit' => $tenantId,
        ]);
        $value = $stmt->fetchColumn();
        return $value ? gmdate('c', strtotime((string)$value) ?: 0) : gmdate('c', 0);
    }

    public function deleteRole(string $roleId, ?string $tenantId = null): bool {
        $tenantId = $tenantId ?: $this->tenantId();
        $roleId = strtolower(trim($roleId));
        if ($tenantId === '' || $roleId === '') {
            return false;
        }

        try {
            $role = $this->role($roleId, $tenantId);
            if (!$role) {
                return false;
            }
            if (!empty($role['system'])) {
                throw new RuntimeException('Los roles de sistema son inmutables.');
            }
            if ($this->roleAssignedCount($roleId, $tenantId) > 0) {
                throw new RuntimeException('El rol tiene usuarios asignados y no puede eliminarse.');
            }

            $this->db->beginTransaction();
            $deleteGrants = $this->db->prepare('
                DELETE FROM tenant_role_navigation_grants
                WHERE tenant_id = :tenant_id AND role_id = :role_id
            ');
            $deleteGrants->execute(['tenant_id' => $tenantId, 'role_id' => $roleId]);

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
            $deleted = $deleteRole->rowCount() > 0;
            $this->db->commit();
            return $deleted;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($this->isMissingContractTable($e)) {
                return false;
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function revokeSessionsForRoleRows(string $roleId, string $tenantId): int {
        $sessions = $this->db->prepare('
            UPDATE tenant_user_sessions
            SET revoked_at = COALESCE(revoked_at, NOW()),
                last_seen_at = NOW()
            WHERE tenant_id = :tenant_id
              AND user_id IN (
                  SELECT user_id
                  FROM tenant_user_roles
                  WHERE tenant_id = :assignment_tenant_id
                    AND role_id = :role_id
              )
              AND revoked_at IS NULL
        ');
        $sessions->execute([
            'tenant_id' => $tenantId,
            'assignment_tenant_id' => $tenantId,
            'role_id' => $roleId,
        ]);

        $tokens = $this->db->prepare('
            UPDATE "User"
            SET active_token_id = NULL,
                updated_at = NOW()
            WHERE tenant_id = :tenant_id
              AND id IN (
                  SELECT user_id
                  FROM tenant_user_roles
                  WHERE tenant_id = :assignment_tenant_id
                    AND role_id = :role_id
              )
        ');
        $tokens->execute([
            'tenant_id' => $tenantId,
            'assignment_tenant_id' => $tenantId,
            'role_id' => $roleId,
        ]);

        return $tokens->rowCount();
    }

    private function roleRecord(array $row): array {
        $permissions = $row['permissions'] ?? [];
        if (is_string($permissions)) {
            $decoded = json_decode($permissions, true);
            $permissions = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (string)$row['role_id'],
            'name' => (string)$row['name'],
            'description' => (string)($row['description'] ?? ''),
            'permissions' => array_values(array_unique(array_filter(array_map('strval', is_array($permissions) ? $permissions : [])))),
            'system' => filter_var($row['system_role'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'assignedUsersCount' => (int)($row['assigned_users_count'] ?? 0),
            'navigationGrantsCount' => (int)($row['navigation_grants_count'] ?? 0),
            'createdAt' => (string)($row['created_at'] ?? ''),
            'updatedAt' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function tenantUserRecord(array $row): array {
        $profile = $row['profile'] ?? [];
        if (is_string($profile)) {
            $decoded = json_decode($profile, true);
            $profile = is_array($decoded) ? $decoded : [];
        }

        $roleAssignments = $row['role_assignments'] ?? [];
        if (is_string($roleAssignments)) {
            $decoded = json_decode($roleAssignments, true);
            $roleAssignments = is_array($decoded) ? $decoded : [];
        }
        $roleAssignments = array_values(array_filter(array_map(static function ($assignment): ?array {
            if (!is_array($assignment)) {
                return null;
            }
            $id = strtolower(trim((string)($assignment['id'] ?? '')));
            if ($id === '') {
                return null;
            }
            return [
                'id' => $id,
                'name' => trim((string)($assignment['name'] ?? $id)),
                'system' => filter_var($assignment['system'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }, is_array($roleAssignments) ? $roleAssignments : [])));

        $lockedUntilTimestamp = strtotime((string)($row['login_locked_until'] ?? ''));
        $securityLocked = $lockedUntilTimestamp !== false && $lockedUntilTimestamp > time();
        $accountStatus = strtolower(trim((string)($row['account_status'] ?? 'inactive')));
        if (!in_array($accountStatus, ['invited', 'active', 'inactive', 'blocked'], true)) {
            $accountStatus = 'inactive';
        }

        return [
            'id' => (string)($row['id'] ?? ''),
            'name' => (string)($row['name'] ?? 'Usuario'),
            'email' => (string)($row['email'] ?? ''),
            'emailVerified' => filter_var($row['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'phone' => trim((string)($profile['phone'] ?? '')) ?: null,
            'department' => trim((string)($profile['department'] ?? '')),
            'position' => trim((string)($profile['position'] ?? '')),
            'description' => trim((string)($profile['description'] ?? '')) ?: null,
            'roles' => array_values(array_map(static fn (array $assignment): string => $assignment['id'], $roleAssignments)),
            'roleAssignments' => $roleAssignments,
            'status' => $accountStatus,
            'accountStatus' => $accountStatus,
            'identityType' => (string)($row['identity_type'] ?? 'tenant_staff'),
            'securityLock' => [
                'isLocked' => $securityLocked,
                'locked' => $securityLocked,
                'failedAttempts' => (int)($row['failed_login_attempts'] ?? 0),
                'lockedUntil' => $securityLocked ? gmdate('c', $lockedUntilTimestamp) : null,
            ],
            'createdAt' => $this->nullableIsoDate($row['created_at'] ?? null),
            'updatedAt' => $this->nullableIsoDate($row['updated_at'] ?? null),
            'lastLoginAt' => $this->nullableIsoDate($row['last_login_at'] ?? null),
            'invitationStatus' => $accountStatus === 'invited' ? 'pending' : null,
        ];
    }

    private function nullableIsoDate($value): ?string {
        $timestamp = strtotime((string)($value ?? ''));
        return $timestamp !== false ? gmdate('c', $timestamp) : null;
    }

    private function normalizeRoleIds(array $roleIds): array {
        return array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string)$value)),
            $roleIds
        ))));
    }

    private function assertAssignableRoleIds(array $roleIds, string $tenantId): void {
        foreach ($roleIds as $roleId) {
            if (in_array($roleId, ['platform_admin', 'superadmin'], true)) {
                throw new InvalidArgumentException('Los roles de plataforma no se pueden asignar desde un tenant.');
            }
        }

        $placeholders = [];
        $params = ['tenant_id' => $tenantId];
        foreach ($roleIds as $index => $roleId) {
            $key = "role_{$index}";
            $placeholders[] = ":{$key}";
            $params[$key] = $roleId;
        }
        $stmt = $this->db->prepare(sprintf(
            'SELECT role_id FROM tenant_roles WHERE tenant_id = :tenant_id AND role_id IN (%s)',
            implode(', ', $placeholders)
        ));
        $stmt->execute($params);
        $found = array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []));
        $invalid = array_values(array_diff($roleIds, $found));
        if ($invalid !== []) {
            throw new InvalidArgumentException('Uno o más roles no pertenecen al tenant activo.');
        }
    }

    private function systemRoleIds(array $roleIds, string $tenantId): array {
        $roleIds = $this->normalizeRoleIds($roleIds);
        if ($roleIds === []) {
            return [];
        }

        $placeholders = [];
        $params = ['tenant_id' => $tenantId];
        foreach ($roleIds as $index => $roleId) {
            $key = "system_role_{$index}";
            $placeholders[] = ":{$key}";
            $params[$key] = $roleId;
        }
        $stmt = $this->db->prepare(sprintf(
            'SELECT role_id FROM tenant_roles WHERE tenant_id = :tenant_id AND system_role = TRUE AND role_id IN (%s)',
            implode(', ', $placeholders)
        ));
        $stmt->execute($params);

        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []));
    }

    private function normalizeNavigationGrants(array $grants): array {
        $allowedActions = [
            'view', 'create', 'update', 'delete', 'reverse', 'approve', 'deliver',
            'cancel', 'export', 'assign_roles', 'unlock', 'invite', 'revoke_sessions',
        ];
        $permissionMap = $this->navigationPermissionMap($this->tenantId());
        $normalized = [];
        foreach ($grants as $grant) {
            if (!is_array($grant)) {
                continue;
            }
            $menuOptionKey = strtolower(trim((string)($grant['menuOptionKey'] ?? $grant['menu_option_key'] ?? '')));
            if ($menuOptionKey === '' || preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/', $menuOptionKey) !== 1) {
                throw new InvalidArgumentException('La opción de menú indicada no es válida.');
            }
            if (!isset($permissionMap[$menuOptionKey])) {
                throw new InvalidArgumentException('La opción de menú no está publicada por el catálogo Loyalty activo.');
            }
            $actions = is_array($grant['actions'] ?? null) ? $grant['actions'] : [];
            $actions = array_values(array_unique(array_filter(array_map(
                static fn ($action): string => strtolower(trim((string)$action)),
                $actions
            ))));
            foreach ($actions as $action) {
                if (!in_array($action, $allowedActions, true) || !isset($permissionMap[$menuOptionKey][$action])) {
                    throw new InvalidArgumentException('La acción no está publicada para la opción de menú indicada.');
                }
            }
            if ($actions !== [] && !in_array('view', $actions, true)) {
                array_unshift($actions, 'view');
            }
            if ($actions !== []) {
                $normalized[$menuOptionKey] = [
                    'menuOptionKey' => $menuOptionKey,
                    'actions' => array_values(array_unique($actions)),
                ];
            }
        }
        ksort($normalized);
        return array_values($normalized);
    }

    private function assertOperationalRoleGrants(array $grants, string $tenantId): void {
        $options = [];
        $catalog = (new LoyaltyNavigationService())->catalog($tenantId);
        foreach (is_array($catalog['options'] ?? null) ? $catalog['options'] : [] as $option) {
            $key = strtolower(trim((string)($option['key'] ?? '')));
            if ($key !== '') {
                $options[$key] = $option;
            }
        }

        foreach ($grants as $grant) {
            $key = strtolower(trim((string)($grant['menuOptionKey'] ?? '')));
            $option = $options[$key] ?? null;
            if (
                is_array($option)
                && ($option['kind'] ?? '') === 'page'
                && empty($option['mandatory'])
                && in_array('view', $grant['actions'] ?? [], true)
            ) {
                return;
            }
        }

        throw new InvalidArgumentException('Selecciona al menos una pantalla operativa para el rol.');
    }

    private function navigationPermissionMap(?string $tenantId = null): array {
        $map = [];
        $catalog = (new LoyaltyNavigationService())->catalog($tenantId ?: $this->tenantId());
        foreach (is_array($catalog['options'] ?? null) ? $catalog['options'] : [] as $definition) {
            $menuOptionKey = trim((string)($definition['key'] ?? ''));
            if ($menuOptionKey === '') {
                continue;
            }
            foreach (is_array($definition['actions'] ?? null) ? $definition['actions'] : [] as $action) {
                $actionKey = trim((string)($action['key'] ?? ''));
                $permissionKey = trim((string)($action['permissionKey'] ?? ''));
                if ($actionKey !== '' && $permissionKey !== '') {
                    $map[$menuOptionKey][$actionKey] = $permissionKey;
                }
            }
        }
        return $map;
    }

    private function insertAuditEvent(
        string $tenantId,
        ?string $actorUserId,
        string $eventType,
        string $targetType,
        ?string $targetId,
        array $metadata
    ): void {
        $stmt = $this->db->prepare('
            INSERT INTO tenant_access_audit_events (
                tenant_id, actor_user_id, event_type, target_type, target_id, metadata, created_at
            ) VALUES (
                :tenant_id, :actor_user_id, :event_type, :target_type, :target_id,
                CAST(:metadata AS jsonb), NOW()
            )
        ');
        $encoded = json_encode($this->sanitizeAuditMetadata($metadata), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->execute([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId !== null && trim($actorUserId) !== '' ? trim($actorUserId) : null,
            'event_type' => strtolower(trim($eventType)),
            'target_type' => strtolower(trim($targetType)),
            'target_id' => $targetId !== null && trim($targetId) !== '' ? trim($targetId) : null,
            'metadata' => $encoded ?: '{}',
        ]);
    }

    private function lockTenantAccessMutation(string $tenantId): void {
        $stmt = $this->db->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
        $stmt->execute(['lock_key' => 'tenant-access:' . $tenantId]);
    }

    private function sanitizeAuditMetadata(array $metadata): array {
        $clean = [];
        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string)$key);
            if (preg_match('/password|passwd|secret|token|authorization|cookie|certificate|private.?key/', $normalizedKey) === 1) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitizeAuditMetadata($value) : $value;
        }
        return $clean;
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
            || str_contains($message, 'tenant_user_roles')
            || str_contains($message, 'tenant_role_navigation_grants')
            || str_contains($message, 'tenant_access_audit_events');
    }
}
