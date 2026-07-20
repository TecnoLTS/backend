<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\IdentityPlatform\Infrastructure\IdentityAccessRepository;
use App\Modules\LoyaltyRewards\Domain\LoyaltyNavigationCatalog;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->load();
}

$apply = in_array('--apply', $argv, true);
$tenantId = 'fidepuntos';
TenantContext::set([
    'id' => $tenantId,
    'slug' => $tenantId,
    'name' => 'Fidepuntos',
]);

$pdo = Database::getModuleInstance(IdentityPlatformDomain::KEY);
$repository = new IdentityAccessRepository();
$access = new TenantAccessService(null, $repository);
$systemRoles = $access->systemRoles(['dashboard', 'users', 'loyalty-points'], $tenantId);
$catalogGrantByPermission = [];
foreach (LoyaltyNavigationCatalog::definitions() as $definition) {
    $menuOptionKey = trim((string)($definition['key'] ?? ''));
    foreach (is_array($definition['actions'] ?? null) ? $definition['actions'] : [] as $action) {
        $permissionKey = strtolower(trim((string)($action['permissionKey'] ?? '')));
        $actionKey = strtolower(trim((string)($action['key'] ?? '')));
        if ($permissionKey !== '' && $menuOptionKey !== '' && $actionKey !== '') {
            $catalogGrantByPermission[$permissionKey] = [$menuOptionKey, $actionKey];
        }
    }
}

$readJson = static function (mixed $value): array {
    if (is_array($value)) {
        return $value;
    }
    $decoded = is_string($value) ? json_decode($value, true) : null;
    return is_array($decoded) ? $decoded : [];
};

$legacyRoles = [];
$setting = $pdo->prepare('
    SELECT value
    FROM "Setting"
    WHERE key = :key AND (tenant_id = :tenant_id OR tenant_id IS NULL)
    ORDER BY CASE WHEN tenant_id = :tenant_id_order THEN 0 ELSE 1 END
    LIMIT 1
');
$setting->execute([
    'key' => TenantAccessService::ROLES_KEY,
    'tenant_id' => $tenantId,
    'tenant_id_order' => $tenantId,
]);
$stored = $readJson($setting->fetchColumn());
foreach (is_array($stored['roles'] ?? null) ? $stored['roles'] : [] as $role) {
    if (!is_array($role) || !empty($role['system'])) {
        continue;
    }
    $roleId = strtolower(trim((string)($role['id'] ?? '')));
    if ($roleId === '' || in_array($roleId, [
        'platform_admin',
        'superadmin',
        "{$tenantId}_admin",
        "{$tenantId}_reader",
    ], true)) {
        continue;
    }
    $legacyRoles[] = [
        'id' => $roleId,
        'name' => trim((string)($role['name'] ?? '')) ?: $roleId,
        'description' => trim((string)($role['description'] ?? '')) ?: 'Rol migrado desde configuración legacy.',
        'permissions' => $access->normalizePermissions($role['permissions'] ?? [], ['dashboard', 'users', 'loyalty-points']),
        'catalogPermissions' => array_values(array_filter(array_map(
            static fn ($permission): string => strtolower(trim((string)$permission)),
            is_array($role['permissions'] ?? null) ? $role['permissions'] : []
        ), static fn (string $permission): bool => isset($catalogGrantByPermission[$permission]))),
        'system' => false,
    ];
}
$legacyRolesDiscovered = count($legacyRoles);
$legacyMarkerStmt = $pdo->prepare('
    SELECT 1
    FROM tenant_access_audit_events
    WHERE tenant_id = :tenant_id
      AND event_type = \'migration.fidepuntos_rbac.applied\'
      AND target_type = \'migration\'
      AND target_id = \'fidepuntos-rbac-v1\'
    LIMIT 1
');
$legacyMarkerStmt->execute(['tenant_id' => $tenantId]);
$legacyImportApplied = (bool)$legacyMarkerStmt->fetchColumn();
if ($legacyImportApplied) {
    // El rollback legacy queda disponible, pero nunca vuelve a sobrescribir la
    // fuente relacional después del primer cutover exitoso.
    $legacyRoles = [];
}

$orphanMembershipStmt = $pdo->prepare('
    SELECT membership.user_id
    FROM tenant_memberships membership
    LEFT JOIN "User" u
      ON u.tenant_id = membership.tenant_id AND u.id = membership.user_id
    WHERE membership.tenant_id = :tenant_id AND u.id IS NULL
    ORDER BY membership.user_id
');
$orphanMembershipStmt->execute(['tenant_id' => $tenantId]);
$orphanMembershipIds = array_values(array_map('strval', $orphanMembershipStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

$orphanAssignmentStmt = $pdo->prepare('
    SELECT assignment.user_id || \':\' || assignment.role_id
    FROM tenant_user_roles assignment
    LEFT JOIN "User" u
      ON u.tenant_id = assignment.tenant_id AND u.id = assignment.user_id
    LEFT JOIN tenant_roles role
      ON role.tenant_id = assignment.tenant_id AND role.role_id = assignment.role_id
    WHERE assignment.tenant_id = :tenant_id
      AND (u.id IS NULL OR role.role_id IS NULL)
    ORDER BY assignment.user_id, assignment.role_id
');
$orphanAssignmentStmt->execute(['tenant_id' => $tenantId]);
$orphanAssignments = array_values(array_map('strval', $orphanAssignmentStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

$usersStmt = $pdo->prepare('
    SELECT id, role, profile, email_verified
    FROM "User"
    WHERE tenant_id = :tenant_id
    ORDER BY id
');
$usersStmt->execute(['tenant_id' => $tenantId]);
$users = $usersStmt->fetchAll() ?: [];

$report = [
    'tenantId' => $tenantId,
    'mode' => $apply ? 'apply' : 'dry-run',
    'usersFound' => count($users),
    'legacyRolesFound' => $legacyRolesDiscovered,
    'legacyImportAlreadyApplied' => $legacyImportApplied,
    'orphanMemberships' => $orphanMembershipIds,
    'orphanAssignments' => $orphanAssignments,
];
$constraintValidationPending = false;

if (!$apply) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fwrite(STDOUT, "Dry-run: vuelve a ejecutar con --apply después del backup canónico.\n");
    exit(0);
}

$pdo->beginTransaction();
try {
    foreach ([...$systemRoles, ...$legacyRoles] as $role) {
        $permissions = json_encode(array_values($role['permissions'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $upsertRole = $pdo->prepare('
            INSERT INTO tenant_roles (
                tenant_id, role_id, name, description, permissions, system_role, created_at, updated_at
            ) VALUES (
                :tenant_id, :role_id, :name, :description, CAST(:permissions AS jsonb), :system_role, NOW(), NOW()
            )
            ON CONFLICT (tenant_id, role_id) DO UPDATE SET
                name = EXCLUDED.name,
                description = EXCLUDED.description,
                permissions = EXCLUDED.permissions,
                system_role = EXCLUDED.system_role,
                updated_at = NOW()
        ');
        $upsertRole->execute([
            'tenant_id' => $tenantId,
            'role_id' => $role['id'],
            'name' => $role['name'],
            'description' => $role['description'],
            'permissions' => $permissions,
            'system_role' => !empty($role['system']) ? 1 : 0,
        ]);
    }

    $deleteSystemGrants = $pdo->prepare('
        DELETE FROM tenant_role_navigation_grants
        WHERE tenant_id = :tenant_id AND role_id IN (:admin_role_id, :reader_role_id)
    ');
    $deleteSystemGrants->execute([
        'tenant_id' => $tenantId,
        'admin_role_id' => "{$tenantId}_admin",
        'reader_role_id' => "{$tenantId}_reader",
    ]);
    $insertSystemGrant = $pdo->prepare('
        INSERT INTO tenant_role_navigation_grants (
            tenant_id, role_id, menu_option_key, action_key,
            assigned_by_user_id, granted_at, updated_at
        ) VALUES (
            :tenant_id, :role_id, :menu_option_key, :action_key,
            NULL, NOW(), NOW()
        )
        ON CONFLICT (tenant_id, role_id, menu_option_key, action_key)
        DO UPDATE SET updated_at = NOW()
    ');
    foreach (LoyaltyNavigationCatalog::definitions() as $option) {
        $menuOptionKey = trim((string)($option['key'] ?? ''));
        foreach (is_array($option['actions'] ?? null) ? $option['actions'] : [] as $action) {
            $actionKey = trim((string)($action['key'] ?? ''));
            if ($menuOptionKey === '' || $actionKey === '') {
                continue;
            }
            $insertSystemGrant->execute([
                'tenant_id' => $tenantId,
                'role_id' => "{$tenantId}_admin",
                'menu_option_key' => $menuOptionKey,
                'action_key' => $actionKey,
            ]);
            if ($actionKey === 'view' || !empty($option['mandatory'])) {
                $insertSystemGrant->execute([
                    'tenant_id' => $tenantId,
                    'role_id' => "{$tenantId}_reader",
                    'menu_option_key' => $menuOptionKey,
                    'action_key' => $actionKey,
                ]);
            }
        }
    }
    if (!$legacyImportApplied) {
        $legacyCatalogPermissions = [];
        foreach ($legacyRoles as $legacyRole) {
            $legacyCatalogPermissions[(string)$legacyRole['id']] = $legacyRole['catalogPermissions'] ?? [];
        }
        $rolePermissionsStmt = $pdo->prepare('
            SELECT role_id, permissions, system_role
            FROM tenant_roles
            WHERE tenant_id = :tenant_id
        ');
        $rolePermissionsStmt->execute(['tenant_id' => $tenantId]);
        $updateLegacyPermissions = $pdo->prepare('
            UPDATE tenant_roles
            SET permissions = CAST(:permissions AS jsonb), updated_at = NOW()
            WHERE tenant_id = :tenant_id AND role_id = :role_id
        ');
        foreach ($rolePermissionsStmt->fetchAll() ?: [] as $storedRole) {
            $roleId = (string)$storedRole['role_id'];
            $rawPermissions = $readJson($storedRole['permissions'] ?? null);
            $catalogPermissions = $legacyCatalogPermissions[$roleId] ?? [];
            foreach ($rawPermissions as $permission) {
                $permission = strtolower(trim((string)$permission));
                if (isset($catalogGrantByPermission[$permission])) {
                    $catalogPermissions[] = $permission;
                }
            }
            foreach (array_values(array_unique($catalogPermissions)) as $permissionKey) {
                [$menuOptionKey, $actionKey] = $catalogGrantByPermission[$permissionKey];
                $insertSystemGrant->execute([
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'menu_option_key' => $menuOptionKey,
                    'action_key' => $actionKey,
                ]);
                if ($actionKey !== 'view') {
                    $insertSystemGrant->execute([
                        'tenant_id' => $tenantId,
                        'role_id' => $roleId,
                        'menu_option_key' => $menuOptionKey,
                        'action_key' => 'view',
                    ]);
                }
            }
            $legacyPermissions = $access->normalizePermissions(
                $rawPermissions,
                ['dashboard', 'users', 'loyalty-points']
            );
            $updateLegacyPermissions->execute([
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'permissions' => json_encode($legacyPermissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            ]);
        }
    }

    $deleteAssignments = $pdo->prepare('
        DELETE FROM tenant_user_roles assignment
        WHERE assignment.tenant_id = :tenant_id
          AND (
            NOT EXISTS (
                SELECT 1 FROM "User" u
                WHERE u.tenant_id = assignment.tenant_id AND u.id = assignment.user_id
            )
            OR NOT EXISTS (
                SELECT 1 FROM tenant_roles role
                WHERE role.tenant_id = assignment.tenant_id AND role.role_id = assignment.role_id
            )
          )
    ');
    $deleteAssignments->execute(['tenant_id' => $tenantId]);

    $deleteMemberships = $pdo->prepare('
        DELETE FROM tenant_memberships membership
        WHERE membership.tenant_id = :tenant_id
          AND NOT EXISTS (
              SELECT 1 FROM "User" u
              WHERE u.tenant_id = membership.tenant_id AND u.id = membership.user_id
          )
    ');
    $deleteMemberships->execute(['tenant_id' => $tenantId]);

    $availableRoleStmt = $pdo->prepare('SELECT role_id FROM tenant_roles WHERE tenant_id = :tenant_id');
    $availableRoleStmt->execute(['tenant_id' => $tenantId]);
    $availableRoleIds = array_flip(array_map('strval', $availableRoleStmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

    $upsertMembership = $pdo->prepare('
        INSERT INTO tenant_memberships (
            tenant_id, user_id, identity_type, status, created_at, updated_at
        ) VALUES (
            :tenant_id, :user_id, \'tenant_staff\', :status, NOW(), NOW()
        )
        ON CONFLICT (tenant_id, user_id) DO UPDATE SET
            identity_type = \'tenant_staff\',
            status = CASE
                WHEN tenant_memberships.status IN (\'invited\', \'active\', \'inactive\', \'blocked\')
                    THEN tenant_memberships.status
                ELSE EXCLUDED.status
            END,
            updated_at = NOW()
    ');
    $assignmentExists = $pdo->prepare('
        SELECT 1 FROM tenant_user_roles
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        LIMIT 1
    ');
    $insertAssignment = $pdo->prepare('
        INSERT INTO tenant_user_roles (tenant_id, user_id, role_id, assigned_at)
        VALUES (:tenant_id, :user_id, :role_id, NOW())
        ON CONFLICT (tenant_id, user_id, role_id) DO NOTHING
    ');

    foreach ($users as $user) {
        $userId = (string)$user['id'];
        $profile = $readJson($user['profile'] ?? null);
        $identityType = strtolower(trim((string)($profile['identityType'] ?? $profile['identity_type'] ?? '')));
        $managed = in_array($identityType, ['tenant_staff', 'platform'], true)
            || strtolower((string)($user['role'] ?? '')) === 'admin'
            || trim((string)($profile['department'] ?? $profile['position'] ?? '')) !== '';
        if (!$managed || $identityType === 'platform') {
            continue;
        }
        $upsertMembership->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => filter_var($user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'active' : 'inactive',
        ]);

        // La identidad administrativa real conserva acceso completo incluso si
        // una asignacion legacy valida la hubiera dejado solo como lector.
        if (strtolower((string)($user['role'] ?? '')) === 'admin') {
            $insertAssignment->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => "{$tenantId}_admin",
            ]);
        }

        $assignmentExists->execute(['tenant_id' => $tenantId, 'user_id' => $userId]);
        if ($assignmentExists->fetchColumn()) {
            continue;
        }
        $roleIds = [];
        foreach (is_array($profile['roleIds'] ?? null) ? $profile['roleIds'] : [] as $roleId) {
            $roleId = strtolower(trim((string)$roleId));
            if (isset($availableRoleIds[$roleId]) && !in_array($roleId, ['platform_admin', 'superadmin'], true)) {
                $roleIds[] = $roleId;
            }
        }
        if ($roleIds === []) {
            $roleIds = [strtolower((string)($user['role'] ?? '')) === 'admin'
                ? "{$tenantId}_admin"
                : "{$tenantId}_reader"];
        }
        foreach (array_values(array_unique($roleIds)) as $roleId) {
            $insertAssignment->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);
        }
    }

    $constraintState = $pdo->query('
        SELECT constraint_row.convalidated,
               pg_get_userbyid(table_row.relowner) = CURRENT_USER AS current_user_is_owner
        FROM pg_constraint constraint_row
        JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
        WHERE constraint_row.conname = \'tenant_user_roles_role_fk\'
        LIMIT 1
    ')->fetch();
    if ($constraintState && !filter_var($constraintState['convalidated'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        if (filter_var($constraintState['current_user_is_owner'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $pdo->exec('ALTER TABLE tenant_user_roles VALIDATE CONSTRAINT tenant_user_roles_role_fk');
        } else {
            $constraintValidationPending = true;
        }
    }

    if (!$legacyImportApplied) {
        $audit = $pdo->prepare('
            INSERT INTO tenant_access_audit_events (
                tenant_id, actor_user_id, event_type, target_type, target_id, metadata, created_at
            ) VALUES (
                :tenant_id, NULL, \'migration.fidepuntos_rbac.applied\', \'migration\',
                \'fidepuntos-rbac-v1\', CAST(:metadata AS jsonb), NOW()
            )
        ');
        $audit->execute([
            'tenant_id' => $tenantId,
            'metadata' => json_encode([
                'orphanMembershipsRemoved' => count($orphanMembershipIds),
                'orphanAssignmentsRemoved' => count($orphanAssignments),
                'usersReconciled' => count($users),
                'legacyRolesMigrated' => count($legacyRoles),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[fidepuntos-rbac] error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$remaining = $pdo->prepare('
    SELECT
      (SELECT COUNT(*) FROM tenant_memberships membership
       WHERE membership.tenant_id = :tenant_memberships
         AND NOT EXISTS (SELECT 1 FROM "User" u WHERE u.tenant_id = membership.tenant_id AND u.id = membership.user_id))
      +
      (SELECT COUNT(*) FROM tenant_user_roles assignment
       WHERE assignment.tenant_id = :tenant_assignments
         AND NOT EXISTS (SELECT 1 FROM "User" u WHERE u.tenant_id = assignment.tenant_id AND u.id = assignment.user_id))
');
$remaining->execute([
    'tenant_memberships' => $tenantId,
    'tenant_assignments' => $tenantId,
]);
$report['remainingOrphans'] = (int)$remaining->fetchColumn();
$report['applied'] = true;
$report['constraintValidationPending'] = $constraintValidationPending;
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($report['remainingOrphans'] === 0 ? 0 : 1);
