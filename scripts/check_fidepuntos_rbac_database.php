<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\ConnectionRegistry;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\IdentityPlatform\Infrastructure\IdentityAccessRepository;
use App\Modules\LoyaltyRewards\Application\LoyaltyNavigationService;
use App\Modules\LoyaltyRewards\Domain\LoyaltyNavigationCatalog;
use App\Modules\LoyaltyRewards\Domain\LoyaltyRewardsDomain;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyNavigationRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\UserRepository;
use Dotenv\Dotenv;

/**
 * PDO de integracion con una transaccion raiz que nunca puede confirmarse.
 *
 * Los repositorios de IdentityPlatform abren sus propias transacciones. Durante
 * este check esas transacciones se convierten en savepoints, de modo que se
 * ejecuta la logica real y al final una sola operacion ROLLBACK elimina todos
 * los fixtures.
 */
final class RbacRollbackOnlyPdo extends PDO
{
    /** @var list<string> */
    private array $savepoints = [];
    private int $savepointSequence = 0;

    /** @param array{host:string,port:int,database:string,username:string,password:string} $config */
    public function __construct(array $config)
    {
        parent::__construct(
            sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'],
                $config['port'],
                $config['database']
            ),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public function beginRootTransaction(): void
    {
        if (parent::inTransaction()) {
            throw new LogicException('La transaccion raiz del check ya esta activa.');
        }
        if (!parent::beginTransaction()) {
            throw new RuntimeException('No se pudo iniciar la transaccion raiz del check.');
        }
    }

    public function beginTransaction(): bool
    {
        if (!parent::inTransaction()) {
            throw new LogicException('Este check solo permite transacciones anidadas dentro de su transaccion raiz.');
        }

        $savepoint = 'rbac_check_sp_' . (++$this->savepointSequence);
        if (parent::exec("SAVEPOINT {$savepoint}") === false) {
            return false;
        }
        $this->savepoints[] = $savepoint;
        return true;
    }

    public function commit(): bool
    {
        $savepoint = $this->savepoints === [] ? null : end($this->savepoints);
        if ($savepoint === null) {
            throw new LogicException('La transaccion raiz del check es rollback-only.');
        }

        if (parent::exec("RELEASE SAVEPOINT {$savepoint}") === false) {
            return false;
        }
        array_pop($this->savepoints);
        return true;
    }

    public function rollBack(): bool
    {
        $savepoint = $this->savepoints === [] ? null : end($this->savepoints);
        if ($savepoint !== null) {
            $rolledBack = parent::exec("ROLLBACK TO SAVEPOINT {$savepoint}") !== false;
            $released = parent::exec("RELEASE SAVEPOINT {$savepoint}") !== false;
            if ($rolledBack && $released) {
                array_pop($this->savepoints);
            }
            return $rolledBack && $released;
        }

        // Los repositorios legacy pueden intentar rollBack() aun cuando la
        // excepcion ocurrio antes de abrir su propia transaccion. Nunca se les
        // permite consumir la transaccion raiz del check.
        return false;
    }

    public function rollBackEverything(): void
    {
        while (($savepoint = array_pop($this->savepoints)) !== null && parent::inTransaction()) {
            parent::exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            parent::exec("RELEASE SAVEPOINT {$savepoint}");
        }
        if (parent::inTransaction()) {
            parent::rollBack();
        }
    }
}

/** @return never */
function failRbacDatabaseCheck(string $message): void
{
    throw new RuntimeException($message);
}

function assertRbacDatabaseCheck(bool $condition, string $message): void
{
    if (!$condition) {
        failRbacDatabaseCheck($message);
    }
}

function databaseBoolean(mixed $value): bool
{
    return $value === true || in_array(strtolower(trim((string)$value)), ['1', 't', 'true'], true);
}

/**
 * @param class-string<Throwable> $exceptionClass
 */
function expectRbacDatabaseException(
    callable $operation,
    string $exceptionClass,
    string $messageFragment,
    string $failureMessage
): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        assertRbacDatabaseCheck(
            $exception instanceof $exceptionClass,
            $failureMessage . ' (tipo de excepcion inesperado)'
        );
        assertRbacDatabaseCheck(
            str_contains(mb_strtolower($exception->getMessage()), mb_strtolower($messageFragment)),
            $failureMessage . ' (mensaje de proteccion inesperado)'
        );
        return;
    }

    failRbacDatabaseCheck($failureMessage . ' (la operacion fue aceptada)');
}

/**
 * @param object $target
 */
function injectRbacDatabaseConnection(object $target, PDO $pdo): void
{
    $reflection = new ReflectionObject($target);
    while (!$reflection->hasProperty('db') && ($parent = $reflection->getParentClass()) !== false) {
        $reflection = $parent;
    }
    if (!$reflection->hasProperty('db')) {
        throw new LogicException('No se encontro la conexion del repositorio bajo prueba.');
    }
    $property = $reflection->getProperty('db');
    $property->setValue($target, $pdo);
}

/**
 * @param list<array{menuOptionKey:string,actions:list<string>}> $grants
 * @return list<string>
 */
function flattenNavigationGrantPairs(array $grants): array
{
    $pairs = [];
    foreach ($grants as $grant) {
        $optionKey = (string)($grant['menuOptionKey'] ?? '');
        foreach (is_array($grant['actions'] ?? null) ? $grant['actions'] : [] as $action) {
            $pairs[] = $optionKey . ':' . (string)$action;
        }
    }
    sort($pairs);
    return array_values(array_unique($pairs));
}

/** @param list<string> $expected @param list<string> $actual */
function assertSameStringSet(array $expected, array $actual, string $message): void
{
    $expected = array_values(array_unique($expected));
    $actual = array_values(array_unique($actual));
    sort($expected);
    sort($actual);
    assertRbacDatabaseCheck($expected === $actual, $message);
}

function countFixtureFootprint(PDO $pdo, string $marker, string $ephemeralTenant): int
{
    $like = $marker . '%';
    $queries = [
        [
            'SELECT COUNT(*) FROM "User" WHERE id LIKE :marker OR tenant_id = :tenant_id',
            ['marker' => $like, 'tenant_id' => $ephemeralTenant],
        ],
        [
            'SELECT COUNT(*) FROM tenant_memberships WHERE user_id LIKE :marker OR tenant_id = :tenant_id',
            ['marker' => $like, 'tenant_id' => $ephemeralTenant],
        ],
        [
            'SELECT COUNT(*) FROM tenant_roles WHERE role_id LIKE :marker OR tenant_id = :tenant_id',
            ['marker' => $like, 'tenant_id' => $ephemeralTenant],
        ],
        [
            'SELECT COUNT(*) FROM tenant_user_roles
             WHERE user_id LIKE :user_marker OR role_id LIKE :role_marker OR tenant_id = :tenant_id',
            ['user_marker' => $like, 'role_marker' => $like, 'tenant_id' => $ephemeralTenant],
        ],
        [
            'SELECT COUNT(*) FROM tenant_role_navigation_grants WHERE role_id LIKE :marker OR tenant_id = :tenant_id',
            ['marker' => $like, 'tenant_id' => $ephemeralTenant],
        ],
        [
            'SELECT COUNT(*) FROM tenant_user_sessions
             WHERE user_id LIKE :user_marker OR session_id LIKE :session_marker OR tenant_id = :tenant_id',
            ['user_marker' => $like, 'session_marker' => $like, 'tenant_id' => $ephemeralTenant],
        ],
        [
            'SELECT COUNT(*) FROM tenant_access_audit_events WHERE target_id LIKE :marker OR tenant_id = :tenant_id',
            ['marker' => $like, 'tenant_id' => $ephemeralTenant],
        ],
        [
            'SELECT COUNT(*) FROM "PasswordResetToken" WHERE user_id LIKE :marker OR tenant_id = :tenant_id',
            ['marker' => $like, 'tenant_id' => $ephemeralTenant],
        ],
    ];

    $count = 0;
    foreach ($queries as [$sql, $parameters]) {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $count += (int)$statement->fetchColumn();
    }
    return $count;
}

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->load();
}

$tenantId = LoyaltyNavigationCatalog::INITIAL_TENANT_ID;
$marker = 'rbac_check_' . bin2hex(random_bytes(6));
$ephemeralTenant = $marker . '_tenant';
$fixtureUser = $marker . '_union_user';
$accountLinkUser = $marker . '_account_link_user';
$firstAdminUser = $marker . '_admin_one';
$secondAdminUser = $marker . '_admin_two';
$unionRoleA = $marker . '_purchase_role';
$unionRoleB = $marker . '_redeem_role';
$ephemeralAdminRole = $ephemeralTenant . '_admin';
$ephemeralReaderRole = $ephemeralTenant . '_reader';
$sessionOne = $marker . '_session_one';
$sessionTwo = $marker . '_session_two';
$sessionThree = $marker . '_session_three';
$resetSessionOne = $marker . '_reset_session_one';
$resetSessionTwo = $marker . '_reset_session_two';
$changeSessionOne = $marker . '_change_session_one';
$changeSessionTwo = $marker . '_change_session_two';

TenantContext::set([
    'id' => $tenantId,
    'slug' => $tenantId,
    'name' => 'Fidepuntos',
]);

$pdo = null;
$checkPassed = false;

try {
    $dashboardConfig = ConnectionRegistry::resolveDatabaseConfig(IdentityPlatformDomain::KEY);
    $pdo = new RbacRollbackOnlyPdo($dashboardConfig);
    assertRbacDatabaseCheck(
        $pdo->query('SELECT current_database()')->fetchColumn() === 'dashboard',
        'IdentityPlatform no resolvio la base dashboard.'
    );

    $loyaltyConfig = ConnectionRegistry::resolveDatabaseConfig(LoyaltyRewardsDomain::KEY);
    $loyaltyPdo = new PDO(
        sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $loyaltyConfig['host'],
            $loyaltyConfig['port'],
            $loyaltyConfig['database']
        ),
        $loyaltyConfig['username'],
        $loyaltyConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    assertRbacDatabaseCheck(
        $loyaltyPdo->query('SELECT current_database()')->fetchColumn() === 'loyalty',
        'LoyaltyRewards no resolvio la base loyalty.'
    );

    $navigationRepository = new LoyaltyNavigationRepository($loyaltyPdo);
    $navigationService = new LoyaltyNavigationService($navigationRepository);
    $catalog = $navigationService->catalog($tenantId);
    $rawItems = $navigationRepository->activeItems($tenantId);
    $rawActions = $navigationRepository->activeActions($tenantId);
    $storedDepth = array_reduce(
        $rawItems,
        static fn (int $max, array $item): int => max($max, (int)($item['depth'] ?? -1)),
        -1
    );
    $publishedItems = count($catalog['sections']) + count($catalog['options']);
    $publishedActions = array_sum(array_map(
        static fn (array $option): int => count(is_array($option['actions'] ?? null) ? $option['actions'] : []),
        $catalog['options']
    ));
    assertRbacDatabaseCheck(count($rawItems) === 32, 'El catalogo Loyalty activo no contiene 32 items.');
    assertRbacDatabaseCheck(count($rawActions) === 62, 'El catalogo Loyalty activo no contiene 62 acciones.');
    assertRbacDatabaseCheck($storedDepth === 3, 'La profundidad maxima del catalogo Loyalty no es tres.');
    assertRbacDatabaseCheck($publishedItems === 32, 'El servicio omitio items sembrados del catalogo Loyalty.');
    assertRbacDatabaseCheck($publishedActions === 62, 'El servicio omitio acciones sembradas del catalogo Loyalty.');
    fwrite(STDOUT, "[OK] catalogo Loyalty: 32 items, 62 acciones y profundidad maxima 3.\n");

    $pdo->beginRootTransaction();
    $pdo->exec("SET LOCAL lock_timeout = '5s'");
    $pdo->exec("SET LOCAL statement_timeout = '30s'");

    $identityRepository = new IdentityAccessRepository();
    injectRbacDatabaseConnection($identityRepository, $pdo);

    $catalogPairs = [];
    $readerPairs = [];
    foreach ($catalog['options'] as $option) {
        $optionKey = (string)$option['key'];
        foreach ($option['actions'] as $action) {
            $pair = $optionKey . ':' . (string)$action['key'];
            $catalogPairs[] = $pair;
            if ((string)$action['key'] === 'view' || !empty($option['mandatory'])) {
                $readerPairs[] = $pair;
            }
        }
    }
    $adminRoleId = $tenantId . '_admin';
    $readerRoleId = $tenantId . '_reader';
    $roles = $identityRepository->roles($tenantId);
    $systemRoles = [];
    foreach ($roles as $role) {
        if (in_array((string)$role['id'], [$adminRoleId, $readerRoleId], true)) {
            $systemRoles[(string)$role['id']] = $role;
        }
    }
    assertRbacDatabaseCheck(isset($systemRoles[$adminRoleId]), 'No existe el rol administrador de Fidepuntos.');
    assertRbacDatabaseCheck(isset($systemRoles[$readerRoleId]), 'No existe el rol lector de Fidepuntos.');
    assertRbacDatabaseCheck(!empty($systemRoles[$adminRoleId]['system']), 'El rol administrador no es inmutable.');
    assertRbacDatabaseCheck(!empty($systemRoles[$readerRoleId]['system']), 'El rol lector no es inmutable.');
    assertSameStringSet(
        $catalogPairs,
        flattenNavigationGrantPairs($identityRepository->navigationGrantsForRole($adminRoleId, $tenantId)),
        'El rol administrador no tiene exactamente todos los grants del catalogo.'
    );
    assertSameStringSet(
        $readerPairs,
        flattenNavigationGrantPairs($identityRepository->navigationGrantsForRole($readerRoleId, $tenantId)),
        'El rol lector contiene escrituras o no cubre todas las vistas.'
    );
    fwrite(STDOUT, "[OK] roles del sistema: administrador completo y lector de solo consulta.\n");

    $orphanMemberships = $pdo->prepare('
        SELECT COUNT(*)
        FROM tenant_memberships membership
        LEFT JOIN "User" user_row
          ON user_row.tenant_id = membership.tenant_id
         AND user_row.id = membership.user_id
        WHERE membership.tenant_id = :tenant_id
          AND user_row.id IS NULL
    ');
    $orphanMemberships->execute(['tenant_id' => $tenantId]);
    $orphanAssignments = $pdo->prepare('
        SELECT COUNT(*)
        FROM tenant_user_roles assignment
        LEFT JOIN "User" user_row
          ON user_row.tenant_id = assignment.tenant_id
         AND user_row.id = assignment.user_id
        LEFT JOIN tenant_memberships membership
          ON membership.tenant_id = assignment.tenant_id
         AND membership.user_id = assignment.user_id
        LEFT JOIN tenant_roles role_row
          ON role_row.tenant_id = assignment.tenant_id
         AND role_row.role_id = assignment.role_id
        WHERE assignment.tenant_id = :tenant_id
          AND (user_row.id IS NULL OR membership.user_id IS NULL OR role_row.role_id IS NULL)
    ');
    $orphanAssignments->execute(['tenant_id' => $tenantId]);
    assertRbacDatabaseCheck((int)$orphanMemberships->fetchColumn() === 0, 'Persisten memberships huerfanas en Fidepuntos.');
    assertRbacDatabaseCheck((int)$orphanAssignments->fetchColumn() === 0, 'Persisten asignaciones huerfanas en Fidepuntos.');
    fwrite(STDOUT, "[OK] integridad Fidepuntos: cero memberships y asignaciones huerfanas.\n");

    $insertUser = $pdo->prepare('
        INSERT INTO "User" (id, tenant_id, email, name, password, role, email_verified, created_at, updated_at)
        VALUES (:id, :tenant_id, :email, :name, :password, :role, :email_verified, NOW(), NOW())
    ');
    $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $initialAccountPassword = 'RbacInitial!' . bin2hex(random_bytes(16));
    $initialAccountPasswordHash = password_hash($initialAccountPassword, PASSWORD_DEFAULT);
    foreach ([
        [$fixtureUser, $tenantId, 'Union RBAC', $passwordHash, true],
        [$accountLinkUser, $tenantId, 'Cuenta efimera con enlace', $initialAccountPasswordHash, false],
        [$firstAdminUser, $ephemeralTenant, 'Administrador efimero uno', $passwordHash, true],
        [$secondAdminUser, $ephemeralTenant, 'Administrador efimero dos', $passwordHash, true],
    ] as [$userId, $userTenant, $name, $userPasswordHash, $emailVerified]) {
        $insertUser->execute([
            'id' => $userId,
            'tenant_id' => $userTenant,
            'email' => $userId . '@example.invalid',
            'name' => $name,
            'password' => $userPasswordHash,
            'role' => 'admin',
            'email_verified' => $emailVerified ? 1 : 0,
        ]);
    }
    unset($passwordHash, $initialAccountPasswordHash);

    $insertRole = $pdo->prepare('
        INSERT INTO tenant_roles (
            tenant_id, role_id, name, description, permissions, system_role, created_at, updated_at
        ) VALUES (
            :tenant_id, :role_id, :name, :description, \'[]\'::jsonb, :system_role, NOW(), NOW()
        )
    ');
    foreach ([
        [$tenantId, $unionRoleA, 'Compra efimero', false],
        [$tenantId, $unionRoleB, 'Canje efimero', false],
        [$ephemeralTenant, $ephemeralAdminRole, 'Administrador efimero', true],
        [$ephemeralTenant, $ephemeralReaderRole, 'Lector efimero', true],
    ] as [$roleTenant, $roleId, $name, $system]) {
        $insertRole->execute([
            'tenant_id' => $roleTenant,
            'role_id' => $roleId,
            'name' => $name,
            'description' => 'Fixture transaccional del check RBAC.',
            'system_role' => $system ? 1 : 0,
        ]);
    }

    $insertMembership = $pdo->prepare('
        INSERT INTO tenant_memberships (
            tenant_id, user_id, identity_type, status, created_at, updated_at
        ) VALUES (
            :tenant_id, :user_id, \'tenant_staff\', :status, NOW(), NOW()
        )
    ');
    foreach ([
        [$tenantId, $fixtureUser, 'active'],
        [$tenantId, $accountLinkUser, 'invited'],
        [$ephemeralTenant, $firstAdminUser, 'active'],
        [$ephemeralTenant, $secondAdminUser, 'active'],
    ] as [$membershipTenant, $userId, $status]) {
        $insertMembership->execute([
            'tenant_id' => $membershipTenant,
            'user_id' => $userId,
            'status' => $status,
        ]);
    }

    $insertAssignment = $pdo->prepare('
        INSERT INTO tenant_user_roles (tenant_id, user_id, role_id, assigned_at)
        VALUES (:tenant_id, :user_id, :role_id, NOW())
    ');
    foreach ([
        [$tenantId, $fixtureUser, $unionRoleA],
        [$tenantId, $fixtureUser, $unionRoleB],
        [$tenantId, $accountLinkUser, $readerRoleId],
        [$ephemeralTenant, $firstAdminUser, $ephemeralAdminRole],
        [$ephemeralTenant, $secondAdminUser, $ephemeralReaderRole],
    ] as [$assignmentTenant, $userId, $roleId]) {
        $insertAssignment->execute([
            'tenant_id' => $assignmentTenant,
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    $insertGrant = $pdo->prepare('
        INSERT INTO tenant_role_navigation_grants (
            tenant_id, role_id, menu_option_key, action_key, granted_at, updated_at
        ) VALUES (
            :tenant_id, :role_id, :menu_option_key, :action_key, NOW(), NOW()
        )
    ');
    foreach ([
        [$unionRoleA, 'loyalty.purchase', 'view'],
        [$unionRoleA, 'loyalty.purchase', 'create'],
        [$unionRoleB, 'loyalty.redeem', 'view'],
        [$unionRoleB, 'loyalty.redeem', 'create'],
    ] as [$roleId, $optionKey, $actionKey]) {
        $insertGrant->execute([
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'menu_option_key' => $optionKey,
            'action_key' => $actionKey,
        ]);
    }

    assertSameStringSet(
        [
            'loyalty.register-purchase.view',
            'loyalty.register-purchase.create',
            'loyalty.redeem-reward.view',
            'loyalty.redeem-reward.create',
        ],
        $identityRepository->navigationPermissionsForUser($fixtureUser, $tenantId),
        'La union de permisos de multiples roles no coincide con sus grants.'
    );
    $normalizedGrant = $identityRepository->validateRoleNavigationGrants([[
        'menuOptionKey' => 'loyalty.purchase',
        'actions' => ['create'],
    ]]);
    assertSameStringSet(
        ['view', 'create'],
        $normalizedGrant[0]['actions'] ?? [],
        'Seleccionar create no agrego view de forma automatica.'
    );
    fwrite(STDOUT, "[OK] composicion de acceso: union de roles e implicacion automatica de view.\n");

    assertSameStringSet(
        [$unionRoleA, $unionRoleB],
        $identityRepository->validateAssignableRoleIds([$unionRoleA, $unionRoleB], $tenantId),
        'Roles validos del tenant fueron rechazados.'
    );
    expectRbacDatabaseException(
        static fn () => $identityRepository->validateAssignableRoleIds([$ephemeralAdminRole], $tenantId),
        InvalidArgumentException::class,
        'no pertenecen',
        'Se acepto un rol de otro tenant.'
    );
    expectRbacDatabaseException(
        static fn () => $identityRepository->validateAssignableRoleIds([$marker . '_inventado'], $tenantId),
        InvalidArgumentException::class,
        'no pertenecen',
        'Se acepto un rol inexistente.'
    );
    foreach (['platform_admin', 'superadmin'] as $reservedRole) {
        expectRbacDatabaseException(
            static fn () => $identityRepository->validateAssignableRoleIds([$reservedRole], $tenantId),
            InvalidArgumentException::class,
            'plataforma',
            'Se acepto un rol reservado de plataforma.'
        );
    }
    fwrite(STDOUT, "[OK] aislamiento: roles cross-tenant, inventados y reservados son rechazados.\n");

    $userRepository = new UserRepository(
        IdentityPlatformDomain::KEY,
        '"User"',
        '"AuthSecurityEvent"',
        false
    );
    injectRbacDatabaseConnection($userRepository, $pdo);
    $expiresAt = time() + 3600;
    $userRepository->registerSession($fixtureUser, $sessionOne, $expiresAt, '127.0.0.1', 'rbac-db-check');
    $userRepository->registerSession($fixtureUser, $sessionTwo, $expiresAt, '127.0.0.1', 'rbac-db-check');
    $userRepository->registerSession($fixtureUser, $sessionThree, $expiresAt, '127.0.0.1', 'rbac-db-check');
    $summary = $userRepository->sessionSummary($fixtureUser, $sessionOne);
    assertRbacDatabaseCheck((int)$summary['activeSessions'] === 3, 'No se registraron tres sesiones activas.');
    assertRbacDatabaseCheck((int)$summary['otherActiveSessions'] === 2, 'No se detectaron dos sesiones adicionales.');
    assertRbacDatabaseCheck($userRepository->revokeOtherSessions($fixtureUser, $sessionOne) === 2, 'No se revocaron exactamente las otras dos sesiones.');
    assertRbacDatabaseCheck($userRepository->relationalSessionIsActive($fixtureUser, $sessionOne) === true, 'La sesion actual fue revocada por error.');
    assertRbacDatabaseCheck($userRepository->relationalSessionIsActive($fixtureUser, $sessionTwo) === false, 'Una sesion secundaria continuo activa.');
    assertRbacDatabaseCheck($userRepository->relationalSessionIsActive($fixtureUser, $sessionThree) === false, 'Una sesion secundaria continuo activa.');
    fwrite(STDOUT, "[OK] sesiones: tres activas y revocacion selectiva de las otras dos.\n");

    $accountLinkRepository = new PasswordResetTokenRepository();
    injectRbacDatabaseConnection($accountLinkRepository, $pdo);
    $assertTokenStoredAsHash = static function (
        string $rawToken,
        string $tokenHash,
        string $purpose
    ) use ($pdo, $tenantId, $accountLinkUser): void {
        $storedHash = $pdo->prepare('
            SELECT token_hash
            FROM "PasswordResetToken"
            WHERE tenant_id = :tenant_id
              AND user_id = :user_id
              AND purpose = :purpose
              AND token_hash = :token_hash
            LIMIT 1
        ');
        $storedHash->execute([
            'tenant_id' => $tenantId,
            'user_id' => $accountLinkUser,
            'purpose' => $purpose,
            'token_hash' => $tokenHash,
        ]);
        $storedValue = $storedHash->fetchColumn();
        assertRbacDatabaseCheck(
            is_string($storedValue) && hash_equals($tokenHash, $storedValue),
            'El enlace de cuenta no se almaceno mediante su hash esperado.'
        );

        $rawStored = $pdo->prepare('
            SELECT COUNT(*)
            FROM "PasswordResetToken"
            WHERE tenant_id = :tenant_id
              AND user_id = :user_id
              AND token_hash = :raw_token
        ');
        $rawStored->execute([
            'tenant_id' => $tenantId,
            'user_id' => $accountLinkUser,
            'raw_token' => $rawToken,
        ]);
        assertRbacDatabaseCheck(
            (int)$rawStored->fetchColumn() === 0,
            'El token sin hash quedo expuesto en la persistencia.'
        );
    };

    $futureExpiry = (string)$pdo->query(
        "SELECT TO_CHAR(NOW() + INTERVAL '30 minutes', 'YYYY-MM-DD HH24:MI:SS')"
    )->fetchColumn();
    $pastExpiry = (string)$pdo->query(
        "SELECT TO_CHAR(NOW() - INTERVAL '1 minute', 'YYYY-MM-DD HH24:MI:SS')"
    )->fetchColumn();
    $invitationTokenOne = bin2hex(random_bytes(32));
    $invitationHashOne = hash('sha256', $invitationTokenOne);
    $accountLinkRepository->create(
        $accountLinkUser,
        $invitationHashOne,
        $futureExpiry,
        '127.0.0.1',
        'rbac-db-check',
        'invitation',
        $fixtureUser
    );
    $assertTokenStoredAsHash($invitationTokenOne, $invitationHashOne, 'invitation');
    assertRbacDatabaseCheck(
        $accountLinkRepository->getValidToken($invitationTokenOne) === null,
        'El repositorio acepto el token de invitacion sin aplicar hash.'
    );

    $invitationTokenTwo = bin2hex(random_bytes(32));
    $invitationHashTwo = hash('sha256', $invitationTokenTwo);
    $accountLinkRepository->create(
        $accountLinkUser,
        $invitationHashTwo,
        $futureExpiry,
        '127.0.0.1',
        'rbac-db-check',
        'invitation',
        $fixtureUser
    );
    $assertTokenStoredAsHash($invitationTokenTwo, $invitationHashTwo, 'invitation');
    assertRbacDatabaseCheck(
        $accountLinkRepository->getValidToken($invitationHashOne) === null,
        'Reenviar la invitacion no invalido el enlace anterior.'
    );
    assertRbacDatabaseCheck(
        ($accountLinkRepository->getValidToken($invitationHashTwo)['purpose'] ?? null) === 'invitation',
        'La invitacion reenviada no quedo vigente.'
    );

    $pdo->beginTransaction();
    try {
        $consumedInvitation = $accountLinkRepository->consumeValidToken(
            $invitationHashTwo,
            '127.0.0.1',
            'rbac-db-check'
        );
        assertRbacDatabaseCheck(
            ($consumedInvitation['purpose'] ?? null) === 'invitation',
            'No se pudo consumir la invitacion vigente.'
        );
        assertRbacDatabaseCheck(
            $identityRepository->activateInvitedMembership($accountLinkUser, $fixtureUser, $tenantId),
            'Consumir la invitacion no activo la membership invitada.'
        );
        $userRepository->markManagedEmailVerified($accountLinkUser);
        assertRbacDatabaseCheck($pdo->commit(), 'No se pudo confirmar el savepoint de invitacion.');
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    assertRbacDatabaseCheck(
        $accountLinkRepository->consumeValidToken($invitationHashTwo, null, null) === null,
        'La invitacion pudo consumirse mas de una vez.'
    );
    assertRbacDatabaseCheck(
        ($identityRepository->membershipForUser($accountLinkUser, $tenantId)['status'] ?? null) === 'active',
        'La cuenta no quedo activa despues de aceptar la invitacion.'
    );
    $verifiedAccount = $pdo->prepare('SELECT email_verified FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
    $verifiedAccount->execute(['id' => $accountLinkUser, 'tenant_id' => $tenantId]);
    assertRbacDatabaseCheck(
        databaseBoolean($verifiedAccount->fetchColumn()),
        'Aceptar la invitacion no verifico el correo de la cuenta.'
    );

    $expiredInvitation = bin2hex(random_bytes(32));
    $expiredInvitationHash = hash('sha256', $expiredInvitation);
    $accountLinkRepository->create(
        $accountLinkUser,
        $expiredInvitationHash,
        $pastExpiry,
        null,
        'rbac-db-check',
        'invitation',
        $fixtureUser
    );
    $assertTokenStoredAsHash($expiredInvitation, $expiredInvitationHash, 'invitation');
    assertRbacDatabaseCheck(
        $accountLinkRepository->getValidToken($expiredInvitationHash) === null
            && $accountLinkRepository->consumeValidToken($expiredInvitationHash, null, null) === null,
        'Una invitacion expirada fue aceptada.'
    );

    $resetTokenOne = bin2hex(random_bytes(32));
    $resetHashOne = hash('sha256', $resetTokenOne);
    $accountLinkRepository->create(
        $accountLinkUser,
        $resetHashOne,
        $futureExpiry,
        null,
        'rbac-db-check',
        'password_reset',
        $fixtureUser
    );
    $assertTokenStoredAsHash($resetTokenOne, $resetHashOne, 'password_reset');
    $resetTokenTwo = bin2hex(random_bytes(32));
    $resetHashTwo = hash('sha256', $resetTokenTwo);
    $accountLinkRepository->create(
        $accountLinkUser,
        $resetHashTwo,
        $futureExpiry,
        null,
        'rbac-db-check',
        'password_reset',
        $fixtureUser
    );
    $assertTokenStoredAsHash($resetTokenTwo, $resetHashTwo, 'password_reset');
    assertRbacDatabaseCheck(
        $accountLinkRepository->getValidToken($resetHashOne) === null,
        'Reenviar el reset no invalido el enlace anterior.'
    );
    assertRbacDatabaseCheck(
        ($accountLinkRepository->getValidToken($resetHashTwo)['purpose'] ?? null) === 'password_reset',
        'El reset reenviado no quedo vigente.'
    );
    fwrite(STDOUT, "[OK] enlaces: solo hash, reenvio invalida, expiracion y consumo unico.\n");

    $userRepository->registerSession($accountLinkUser, $resetSessionOne, time() + 3600, '127.0.0.1', 'rbac-db-check');
    $userRepository->registerSession($accountLinkUser, $resetSessionTwo, time() + 3600, '127.0.0.1', 'rbac-db-check');
    $userRepository->setActiveTokenId($accountLinkUser, $resetSessionOne);
    $resetPassword = 'RbacReset!' . bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try {
        $consumedReset = $accountLinkRepository->consumeValidToken(
            $resetHashTwo,
            '127.0.0.1',
            'rbac-db-check'
        );
        assertRbacDatabaseCheck(
            ($consumedReset['purpose'] ?? null) === 'password_reset',
            'No se pudo consumir el reset vigente.'
        );
        $userRepository->resetPasswordAfterRecovery(
            $accountLinkUser,
            password_hash($resetPassword, PASSWORD_DEFAULT),
            bin2hex(random_bytes(16))
        );
        assertRbacDatabaseCheck($pdo->commit(), 'No se pudo confirmar el savepoint del reset.');
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    $hashAfterReset = $userRepository->getPasswordHash($accountLinkUser);
    assertRbacDatabaseCheck(
        is_string($hashAfterReset)
            && password_verify($resetPassword, $hashAfterReset)
            && !password_verify($initialAccountPassword, $hashAfterReset),
        'El reset no reemplazo la contrasena anterior.'
    );
    assertRbacDatabaseCheck(
        $accountLinkRepository->consumeValidToken($resetHashTwo, null, null) === null,
        'El reset pudo consumirse mas de una vez.'
    );
    assertRbacDatabaseCheck(
        $userRepository->relationalSessionIsActive($accountLinkUser, $resetSessionOne) === false
            && $userRepository->relationalSessionIsActive($accountLinkUser, $resetSessionTwo) === false,
        'El reset no revoco todas las sesiones existentes.'
    );

    $expiredResetToken = bin2hex(random_bytes(32));
    $expiredResetHash = hash('sha256', $expiredResetToken);
    $accountLinkRepository->create(
        $accountLinkUser,
        $expiredResetHash,
        $pastExpiry,
        null,
        'rbac-db-check',
        'password_reset',
        $fixtureUser
    );
    $assertTokenStoredAsHash($expiredResetToken, $expiredResetHash, 'password_reset');
    assertRbacDatabaseCheck(
        $accountLinkRepository->getValidToken($expiredResetHash) === null
            && $accountLinkRepository->consumeValidToken($expiredResetHash, null, null) === null,
        'Un reset expirado fue aceptado.'
    );

    $pendingResetToken = bin2hex(random_bytes(32));
    $pendingResetHash = hash('sha256', $pendingResetToken);
    $accountLinkRepository->create(
        $accountLinkUser,
        $pendingResetHash,
        $futureExpiry,
        null,
        'rbac-db-check',
        'password_reset',
        $fixtureUser
    );
    $assertTokenStoredAsHash($pendingResetToken, $pendingResetHash, 'password_reset');
    $userRepository->registerSession($accountLinkUser, $changeSessionOne, time() + 3600, '127.0.0.1', 'rbac-db-check');
    $userRepository->registerSession($accountLinkUser, $changeSessionTwo, time() + 3600, '127.0.0.1', 'rbac-db-check');
    $userRepository->setActiveTokenId($accountLinkUser, $changeSessionOne);
    $changedPassword = 'RbacChanged!' . bin2hex(random_bytes(16));
    $userRepository->updatePassword(
        $accountLinkUser,
        password_hash($changedPassword, PASSWORD_DEFAULT),
        bin2hex(random_bytes(16))
    );
    assertRbacDatabaseCheck(
        $accountLinkRepository->invalidateForUser($accountLinkUser) >= 1,
        'El cambio propio no invalido los enlaces de cuenta pendientes.'
    );
    $hashAfterChange = $userRepository->getPasswordHash($accountLinkUser);
    assertRbacDatabaseCheck(
        is_string($hashAfterChange)
            && password_verify($changedPassword, $hashAfterChange)
            && !password_verify($resetPassword, $hashAfterChange),
        'El cambio propio de contrasena no se persistio correctamente.'
    );
    assertRbacDatabaseCheck(
        $accountLinkRepository->getValidToken($pendingResetHash) === null,
        'El enlace pendiente continuo vigente despues del cambio de contrasena.'
    );
    assertRbacDatabaseCheck(
        $userRepository->relationalSessionIsActive($accountLinkUser, $changeSessionOne) === false
            && $userRepository->relationalSessionIsActive($accountLinkUser, $changeSessionTwo) === false,
        'El cambio propio no revoco todas las sesiones existentes.'
    );
    unset(
        $initialAccountPassword,
        $resetPassword,
        $changedPassword,
        $invitationTokenOne,
        $invitationTokenTwo,
        $expiredInvitation,
        $resetTokenOne,
        $resetTokenTwo,
        $expiredResetToken,
        $pendingResetToken
    );
    fwrite(STDOUT, "[OK] contrasenas: invitacion, reset y cambio propio revocan acceso previo.\n");

    TenantContext::set([
        'id' => $ephemeralTenant,
        'slug' => $ephemeralTenant,
        'name' => 'Tenant efimero RBAC',
    ]);
    expectRbacDatabaseException(
        static fn () => $identityRepository->replaceUserRoles(
            $firstAdminUser,
            [$ephemeralReaderRole],
            $firstAdminUser,
            $ephemeralTenant
        ),
        RuntimeException::class,
        'propio rol',
        'El administrador pudo degradarse a si mismo.'
    );
    expectRbacDatabaseException(
        static fn () => $identityRepository->replaceUserRoles(
            $firstAdminUser,
            [$ephemeralReaderRole],
            $secondAdminUser,
            $ephemeralTenant
        ),
        RuntimeException::class,
        'al menos un administrador',
        'Se pudo retirar el rol del ultimo administrador activo.'
    );
    expectRbacDatabaseException(
        static fn () => $identityRepository->updateMembershipStatus(
            $firstAdminUser,
            'inactive',
            $secondAdminUser,
            $ephemeralTenant
        ),
        RuntimeException::class,
        'al menos un administrador',
        'Se pudo inactivar al ultimo administrador activo.'
    );
    expectRbacDatabaseException(
        static fn () => $identityRepository->updateMembershipStatus(
            $firstAdminUser,
            'blocked',
            $firstAdminUser,
            $ephemeralTenant
        ),
        RuntimeException::class,
        'propia cuenta',
        'El administrador pudo bloquearse a si mismo.'
    );
    expectRbacDatabaseException(
        static fn () => $identityRepository->deleteRole($ephemeralAdminRole, $ephemeralTenant),
        RuntimeException::class,
        'inmutables',
        'Se pudo eliminar el rol administrador del sistema.'
    );

    $insertAssignment->execute([
        'tenant_id' => $ephemeralTenant,
        'user_id' => $secondAdminUser,
        'role_id' => $ephemeralAdminRole,
    ]);
    $identityRepository->replaceUserRoles(
        $firstAdminUser,
        [$ephemeralReaderRole],
        $secondAdminUser,
        $ephemeralTenant
    );
    assertRbacDatabaseCheck(
        $identityRepository->countActiveUsersWithRole($ephemeralAdminRole, $ephemeralTenant) === 1,
        'La mutacion valida no conservo exactamente un administrador activo.'
    );
    expectRbacDatabaseException(
        static fn () => $identityRepository->updateMembershipStatus(
            $secondAdminUser,
            'inactive',
            $firstAdminUser,
            $ephemeralTenant
        ),
        RuntimeException::class,
        'al menos un administrador',
        'Se pudo inactivar al nuevo ultimo administrador activo.'
    );
    fwrite(STDOUT, "[OK] protecciones: auto-degradacion, auto-bloqueo y ultimo administrador.\n");

    TenantContext::set([
        'id' => $tenantId,
        'slug' => $tenantId,
        'name' => 'Fidepuntos',
    ]);
    expectRbacDatabaseException(
        static fn () => $identityRepository->deleteRole($unionRoleA, $tenantId),
        RuntimeException::class,
        'usuarios asignados',
        'Se pudo eliminar un rol que aun tiene usuarios asignados.'
    );

    $pdo->rollBackEverything();
    assertRbacDatabaseCheck(
        countFixtureFootprint($pdo, 'rbac_check_', 'rbac_check_nonexistent_tenant') === 0,
        'El ROLLBACK dejo fixtures del check en dashboard.'
    );
    $checkPassed = true;
    fwrite(STDOUT, "[OK] limpieza: ROLLBACK confirmado y cero datos efimeros persistidos.\n");
    fwrite(STDOUT, "[fidepuntos-rbac-db] OK\n");
} catch (Throwable $exception) {
    if ($pdo instanceof RbacRollbackOnlyPdo) {
        $pdo->rollBackEverything();
    }
    fwrite(STDERR, '[fidepuntos-rbac-db] FAIL: ' . $exception->getMessage() . PHP_EOL);
}

exit($checkPassed ? 0 : 1);
