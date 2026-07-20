<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\IdentityPlatform\Infrastructure\IdentityAccessRepository;
use Dotenv\Dotenv;

/** @return never */
function failLegacyTenantAdminReconciliation(string $message, int $exitCode = 2): void
{
    fwrite(STDERR, '[legacy-tenant-admin-roles] ' . $message . PHP_EOL);
    exit($exitCode);
}

$options = getopt('', ['tenant:', 'apply', 'check']);
$tenantId = strtolower(trim((string)($options['tenant'] ?? '')));
$apply = array_key_exists('apply', $options);
$check = array_key_exists('check', $options);

if ($tenantId === '') {
    failLegacyTenantAdminReconciliation('Debes indicar --tenant=<tenant_id>.');
}
if ($apply && $check) {
    failLegacyTenantAdminReconciliation('--apply y --check son modos excluyentes.');
}
if (TenantAccessService::tenantUsesGranularNavigationAccess($tenantId)) {
    failLegacyTenantAdminReconciliation(
        "El tenant {$tenantId} usa acceso granular y queda excluido de esta migración legacy."
    );
}

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->load();
}

$tenantsFile = __DIR__ . '/../config/tenants.php';
$tenants = is_readable($tenantsFile) ? require $tenantsFile : [];
$tenant = is_array($tenants) ? ($tenants[$tenantId] ?? null) : null;
if (!is_array($tenant)) {
    failLegacyTenantAdminReconciliation("El tenant {$tenantId} no existe en config/tenants.php.");
}

$tenantSlug = strtolower(trim((string)($tenant['slug'] ?? $tenantId)));
TenantContext::set($tenant);

$pdo = Database::getModuleInstance(IdentityPlatformDomain::KEY);
$repository = new IdentityAccessRepository();
$access = new TenantAccessService(null, $repository);
$enabledModules = $repository->activeTenantModules($tenantId);
if ($enabledModules === null) {
    $enabledModules = is_array($tenant['enabled_modules'] ?? null)
        ? $tenant['enabled_modules']
        : ['dashboard', 'users', 'ecommerce'];
}
$adminRole = $access->tenantRole($tenantSlug, $enabledModules, true);
$adminRoleId = (string)$adminRole['id'];

$candidateSql = '
    SELECT
        users.id AS user_id,
        users.tenant_id,
        users.role,
        membership.identity_type,
        membership.status
    FROM "User" users
    JOIN tenant_memberships membership
      ON membership.tenant_id = users.tenant_id
     AND membership.user_id = users.id
    WHERE users.tenant_id = :tenant_id
      AND LOWER(COALESCE(users.role, \'\')) = \'admin\'
      AND membership.identity_type = \'tenant_staff\'
      AND membership.status = \'active\'
      AND NOT EXISTS (
          SELECT 1
          FROM tenant_user_roles assignment
          WHERE assignment.tenant_id = membership.tenant_id
            AND assignment.user_id = membership.user_id
      )
    ORDER BY users.id
';

$loadCandidates = static function (bool $lockRows = false) use ($pdo, $candidateSql, $tenantId): array {
    $sql = $candidateSql . ($lockRows ? ' FOR UPDATE OF users, membership' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['tenant_id' => $tenantId]);
    return $stmt->fetchAll() ?: [];
};

$reportFor = static function (array $candidates, bool $applied, int $materialized) use (
    $tenantId,
    $adminRoleId,
    $apply,
    $check
): array {
    return [
        'tenantId' => $tenantId,
        'mode' => $apply ? 'apply' : ($check ? 'check' : 'dry-run'),
        'granularAccess' => false,
        'adminRoleId' => $adminRoleId,
        'candidateCount' => count($candidates),
        'candidateUserIds' => array_values(array_map(
            static fn (array $candidate): string => (string)$candidate['user_id'],
            $candidates
        )),
        'materializedCount' => $materialized,
        'applied' => $applied,
        'consistent' => count($candidates) === 0 || $materialized === count($candidates),
    ];
};

$candidates = $loadCandidates(false);
foreach ($candidates as $candidate) {
    $eligible = TenantAccessService::isLegacyTenantAdminRoleReconciliationCandidate(
        $tenantId,
        [
            'tenant_id' => $candidate['tenant_id'] ?? null,
            'role' => $candidate['role'] ?? null,
        ],
        [
            'identity_type' => $candidate['identity_type'] ?? null,
            'status' => $candidate['status'] ?? null,
        ],
        []
    );
    if (!$eligible) {
        failLegacyTenantAdminReconciliation('La consulta produjo un candidato fuera de la política permitida.');
    }
}

if (!$apply) {
    fwrite(
        STDOUT,
        json_encode($reportFor($candidates, false, 0), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . PHP_EOL
    );
    exit($check && $candidates !== [] ? 1 : 0);
}

$materialized = 0;
try {
    // Crear o actualizar el rol base por sí solo no concede acceso. Se hace
    // antes de la transacción de asignaciones porque syncRole no administra una
    // transacción anidada y el reconciliador debe controlar la suya.
    $repository->syncRole($adminRole, $tenantId);

    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
    $lock->execute(['lock_key' => 'tenant-access:' . $tenantId]);

    $candidates = $loadCandidates(true);

    $insert = $pdo->prepare('
        INSERT INTO tenant_user_roles (tenant_id, user_id, role_id, assigned_at)
        VALUES (:tenant_id, :user_id, :role_id, NOW())
        ON CONFLICT (tenant_id, user_id, role_id) DO NOTHING
        RETURNING user_id
    ');
    foreach ($candidates as $candidate) {
        if (!TenantAccessService::isLegacyTenantAdminRoleReconciliationCandidate(
            $tenantId,
            ['tenant_id' => $candidate['tenant_id'] ?? null, 'role' => $candidate['role'] ?? null],
            [
                'identity_type' => $candidate['identity_type'] ?? null,
                'status' => $candidate['status'] ?? null,
            ],
            []
        )) {
            throw new RuntimeException('Un candidato dejó de cumplir la política durante la reconciliación.');
        }

        $userId = (string)$candidate['user_id'];
        $insert->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $adminRoleId,
        ]);
        if ($insert->fetchColumn() === false) {
            continue;
        }

        $materialized++;
        $repository->recordAuditEvent(
            null,
            'migration.legacy_tenant_admin_role.materialized',
            'user',
            $userId,
            [
                'roleId' => $adminRoleId,
                'legacyDatabaseRole' => 'admin',
                'membershipIdentityType' => 'tenant_staff',
                'membershipStatus' => 'active',
                'source' => 'reconcile_legacy_tenant_admin_roles.php',
            ],
            $tenantId
        );
    }

    $remainingCandidates = $loadCandidates(false);
    if ($materialized !== count($candidates) || $remainingCandidates !== []) {
        throw new RuntimeException('La reconciliación no materializó todos los candidatos de forma atómica.');
    }

    $repository->recordAuditEvent(
        null,
        'migration.legacy_tenant_admin_roles.applied',
        'migration',
        'legacy-tenant-admin-role-v1',
        [
            'roleId' => $adminRoleId,
            'candidateCount' => count($candidates),
            'materializedCount' => $materialized,
            'source' => 'reconcile_legacy_tenant_admin_roles.php',
        ],
        $tenantId
    );
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    failLegacyTenantAdminReconciliation($exception->getMessage(), 1);
}

fwrite(
    STDOUT,
    json_encode(
        $reportFor($candidates, true, $materialized),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL
);
