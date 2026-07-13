<?php

namespace App\Controllers;

use App\Repositories\CustomerRepository;
use App\Repositories\UserRepository;
use App\Core\Response;
use App\Core\Auth;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\IdentityPlatform\Infrastructure\IdentityAccessRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Services\MailService;
use InvalidArgumentException;

class UserController {
    protected $userRepository;
    protected $customerRepository;
    protected TenantAccessService $tenantAccessService;
    protected IdentityAccessRepository $identityAccessRepository;
    protected bool $manageEcommerceCustomers = false;

    public function __construct() {
        $this->userRepository = new UserRepository();
        $this->customerRepository = new CustomerRepository();
        $this->tenantAccessService = new TenantAccessService();
        $this->identityAccessRepository = new IdentityAccessRepository();
        $enabledModules = $this->tenantAccessService->enabledModulesForTenant(TenantContext::get() ?? []);
        foreach ($this->tenantAccessService->systemRoles($enabledModules, TenantContext::slug() ?: 'tenant') as $systemRole) {
            $this->identityAccessRepository->syncRole($systemRole);
        }
    }

    private function managementRepository(): UserRepository {
        return $this->manageEcommerceCustomers ? $this->customerRepository : $this->userRepository;
    }

    private function managedUserResponse(array $user): array {
        return $this->manageEcommerceCustomers
            ? $this->ecommerceUser($user)
            : $this->dashboardUser($user);
    }

    private function isAllowedManagedRecord(array $user): bool {
        return $this->manageEcommerceCustomers
            ? $this->isEcommerceCustomerRecord($user)
            : $this->isManagedWorkspaceUserRecord($user);
    }

    private function authenticate() {
        return Auth::requireUser();
    }

    private function normalizeText($value, int $maxLength = 255): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength);
        }

        return $normalized;
    }

    private function normalizeRole($value): string {
        $normalized = strtolower(trim((string)$value));
        if ($normalized === 'admin') {
            return 'admin';
        }
        return 'customer';
    }

    private function normalizeManagedProfile(array $data, array $existingProfile = [], string $databaseRole = 'customer'): array {
        $profile = $existingProfile;
        $phone = $this->normalizeText($data['phone'] ?? ($data['profile']['phone'] ?? null), 60);

        if ($phone !== null) {
            $profile['phone'] = $phone;
        } else {
            unset($profile['phone']);
        }

        $department = $this->normalizeText($data['department'] ?? ($data['profile']['department'] ?? null), 120);
        if ($department !== null) {
            $profile['department'] = $department;
        }

        $position = $this->normalizeText($data['position'] ?? ($data['profile']['position'] ?? null), 120);
        if ($position !== null) {
            $profile['position'] = $position;
        }

        $description = $this->normalizeText($data['description'] ?? ($data['profile']['description'] ?? null), 500);
        if ($description !== null) {
            $profile['description'] = $description;
        } else {
            unset($profile['description']);
        }

        if ($this->manageEcommerceCustomers) {
            $profile['identityType'] = 'customer';
            $profile['roleIds'] = ['customer'];
            unset($profile['department'], $profile['position'], $profile['description']);
            return $profile;
        }

        // roleIds se conserva únicamente como dato legacy para rollback; las escrituras
        // nuevas usan tenant_user_roles como única fuente efectiva.
        $profile['identityType'] = 'tenant_staff';

        return $profile;
    }

    private function validateManagedPayload(array $data, bool $isCreate, array $existingUser = []): array {
        $name = $this->normalizeText($data['name'] ?? null, 160);
        if ($name === null || mb_strlen($name) < 3) {
            Response::error('El nombre debe tener al menos 3 caracteres', 400, 'USER_NAME_INVALID');
            exit;
        }

        $email = strtolower((string)$this->normalizeText($data['email'] ?? null, 190));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Correo electrónico inválido', 400, 'USER_EMAIL_INVALID');
            exit;
        }

        $roleIdsWereProvided = array_key_exists('roles', $data)
            || array_key_exists('roleIds', $data)
            || (isset($data['profile']) && is_array($data['profile']) && array_key_exists('roleIds', $data['profile']));
        $roleIds = $this->normalizeRoleIds($data['roles'] ?? ($data['roleIds'] ?? ($data['profile']['roleIds'] ?? null)));
        $tenantId = (string)(TenantContext::slug() ?? TenantContext::id() ?? '');
        $usesImplicitTenantAdminRole = !$this->manageEcommerceCustomers
            && $isCreate
            && !$roleIdsWereProvided
            && !TenantAccessService::tenantUsesGranularNavigationAccess($tenantId);
        if (!$this->manageEcommerceCustomers && !$isCreate && !$roleIdsWereProvided && $roleIds === [] && !empty($existingUser['id'])) {
            $roleIds = $this->identityAccessRepository->roleIdsForUser((string)$existingUser['id']);
        }
        if ($usesImplicitTenantAdminRole && $roleIds === []) {
            // Compatibilidad controlada para tenants anteriores al cutover RBAC:
            // el backend selecciona el rol base; el cliente nunca puede pedirlo.
            $roleIds = [$this->defaultRoleId('admin')];
        }
        if (!$this->manageEcommerceCustomers && $roleIds === []) {
            Response::error('Debes asignar al menos un rol operativo del tenant.', 400, 'USER_ROLES_INVALID');
            exit;
        }
        if (!$this->manageEcommerceCustomers && ($isCreate || $roleIdsWereProvided) && !$usesImplicitTenantAdminRole) {
            try {
                $roleIds = $this->identityAccessRepository->validateAssignableRoleIds($roleIds);
            } catch (InvalidArgumentException $e) {
                Response::error($e->getMessage(), 400, 'USER_ROLES_INVALID');
                exit;
            }
        }
        $role = $this->manageEcommerceCustomers ? 'customer' : 'admin';
        $password = $this->manageEcommerceCustomers ? trim((string)($data['password'] ?? '')) : '';
        if ($isCreate && $password === '') {
            $password = bin2hex(random_bytes(24));
        }

        if ($password !== '' && mb_strlen($password) < 12) {
            Response::error('La contraseña debe tener al menos 12 caracteres', 400, 'USER_PASSWORD_WEAK');
            exit;
        }

        $documentType = $this->normalizeText($data['documentType'] ?? ($data['document_type'] ?? null), 40);
        $documentNumber = $this->normalizeText($data['documentNumber'] ?? ($data['document_number'] ?? null), 80);

        if (($documentType && !$documentNumber) || ($documentNumber && !$documentType)) {
            Response::error('Tipo y número de documento deben completarse juntos', 400, 'USER_DOCUMENT_INCOMPLETE');
            exit;
        }

        $existingProfile = [];
        if (!empty($existingUser['profile'])) {
            $decoded = json_decode((string)$existingUser['profile'], true);
            if (is_array($decoded)) {
                $existingProfile = $decoded;
            }
        }

        return [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => $password,
            'email_verified' => $this->manageEcommerceCustomers
                ? $this->isTruthyDbValue($data['emailVerified'] ?? ($data['email_verified'] ?? true))
                : (!$isCreate && $this->isTruthyDbValue($existingUser['email_verified'] ?? false)),
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'business_name' => $this->normalizeText($data['businessName'] ?? ($data['business_name'] ?? null), 180),
            'profile' => $this->normalizeManagedProfile($data, $existingProfile, $role),
            'roleIds' => $roleIds,
        ];
    }

    public function index() {
        Auth::requireAdmin();
        try {
            Response::noStore();
            $result = $this->identityAccessRepository->searchTenantUsers([
                'search' => $_GET['search'] ?? '',
                'status' => $_GET['status'] ?? 'all',
                'roleId' => $_GET['roleId'] ?? '',
                'page' => $_GET['page'] ?? 1,
                'pageSize' => $_GET['pageSize'] ?? 20,
            ]);
            Response::json($result['data'], 200, $result['meta']);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USERS_LIST_FAILED');
        }
    }

    public function ecommerceUsers() {
        Auth::requireAdmin();
        try {
            Response::noStore();
            $query = $_GET;
            $search = strtolower(trim((string)($query['search'] ?? '')));
            $role = strtolower(trim((string)($query['role'] ?? 'all')));
            $users = array_values(array_filter(
                $this->customerRepository->getAll(),
                fn (array $user): bool => $this->isEcommerceCustomerRecord($user)
            ));
            $users = array_map(fn (array $user): array => $this->ecommerceUser($user), $users);

            if (in_array($role, ['customer', 'admin'], true)) {
                $users = array_values(array_filter($users, static fn (array $user): bool => ($user['role'] ?? '') === $role));
            }

            if ($search !== '') {
                $users = array_values(array_filter($users, static function (array $user) use ($search): bool {
                    $haystack = strtolower(implode(' ', [
                        $user['name'] ?? '',
                        $user['email'] ?? '',
                        $user['resolvedEmail'] ?? '',
                        $user['resolvedPhone'] ?? '',
                        $user['document_number'] ?? '',
                        $user['business_name'] ?? '',
                        $user['resolvedCompany'] ?? '',
                    ]));
                    return str_contains($haystack, $search);
                }));
            }

            Response::json($users);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ECOMMERCE_USERS_LIST_FAILED');
        }
    }

    public function show($id) {
        Auth::requireAdmin();

        $repository = $this->managementRepository();
        $user = $repository->getAdminUserById($id);
        if (!$user || !$this->isAllowedManagedRecord($user)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        Response::noStore();
        Response::json($this->managedUserResponse($user));
    }

    public function store() {
        $admin = Auth::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Carga inválida', 400, 'USER_PAYLOAD_INVALID');
            return;
        }

        $payload = $this->validateManagedPayload($data, true);

        $repository = $this->managementRepository();
        if ($repository->emailExists($payload['email'])) {
            Response::error('Ya existe un usuario con ese correo', 409, 'USER_EMAIL_EXISTS');
            return;
        }

        try {
            $created = $repository->createManaged($payload);
            $delivery = null;
            if (!$this->manageEcommerceCustomers) {
                $this->identityAccessRepository->replaceUserRoles(
                    (string)$created['id'],
                    $payload['roleIds'],
                    (string)($admin['sub'] ?? '')
                );
                $this->identityAccessRepository->updateMembershipStatus(
                    (string)$created['id'],
                    'invited',
                    (string)($admin['sub'] ?? '')
                );
                $delivery = $this->issueAccountLink($created, 'invitation', (string)($admin['sub'] ?? ''));
                $created = $repository->getAdminUserById((string)$created['id']);
            }
            $response = $this->managedUserResponse($created);
            if ($delivery !== null) {
                $response['invitation'] = $delivery;
            }
            Response::json($response, 201, null, sprintf('Usuario creado correctamente por %s.', $admin['name'] ?? 'administrador'));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_CREATE_FAILED');
        }
    }

    public function update($id) {
        $admin = Auth::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Carga inválida', 400, 'USER_PAYLOAD_INVALID');
            return;
        }
        $this->requireRoleAssignmentPermissionIfRequested($admin, $data);

        $repository = $this->managementRepository();
        $existingUser = $repository->getAdminUserById($id);
        if (!$existingUser || !$this->isAllowedManagedRecord($existingUser)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        $payload = $this->validateManagedPayload($data, false, $existingUser);

        if (!$this->manageEcommerceCustomers) {
            $this->assertRoleTransitionAllowed($admin, (string)$id, $payload['roleIds']);
        }

        if (!$this->manageEcommerceCustomers && ($admin['sub'] ?? null) === $id && $payload['role'] !== 'admin') {
            Response::error('No puedes quitarte tu propio rol de administrador desde aquí', 400, 'USER_SELF_ROLE_CHANGE_FORBIDDEN');
            return;
        }

        if ($repository->emailExists($payload['email'], $id)) {
            Response::error('Ya existe otro usuario con ese correo', 409, 'USER_EMAIL_EXISTS');
            return;
        }

        try {
            $updated = $repository->updateManaged($id, $payload);
            if (!$this->manageEcommerceCustomers) {
                $this->identityAccessRepository->replaceUserRoles(
                    (string)$id,
                    $payload['roleIds'],
                    (string)($admin['sub'] ?? '')
                );
                if (
                    array_key_exists('roles', $data)
                    || array_key_exists('roleIds', $data)
                    || strtolower((string)($existingUser['email'] ?? '')) !== strtolower($payload['email'])
                ) {
                    $repository->revokeSessions((string)$id);
                }
                if (strtolower((string)($existingUser['email'] ?? '')) !== strtolower($payload['email'])) {
                    $this->invalidateAccountLinks((string)$id);
                }
                $updated = $repository->getAdminUserById((string)$id);
            }
            Response::json($this->managedUserResponse($updated), 200, null, 'Usuario actualizado correctamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_UPDATE_FAILED');
        }
    }

    public function patch($id) {
        $admin = Auth::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Carga inválida', 400, 'USER_PAYLOAD_INVALID');
            return;
        }
        $this->requireRoleAssignmentPermissionIfRequested($admin, $data);

        $repository = $this->managementRepository();
        $existingUser = $repository->getAdminUserById($id);
        if (!$existingUser || !$this->isAllowedManagedRecord($existingUser)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        $existingProfile = $this->decodeProfile($existingUser['profile'] ?? null);
        $merged = [
            'name' => $existingUser['name'] ?? null,
            'email' => $existingUser['email'] ?? null,
            'role' => $existingUser['role'] ?? 'customer',
            'email_verified' => $this->isTruthyDbValue($existingUser['email_verified'] ?? false),
            'document_type' => $existingUser['document_type'] ?? null,
            'document_number' => $existingUser['document_number'] ?? null,
            'business_name' => $existingUser['business_name'] ?? null,
            'phone' => $existingProfile['phone'] ?? null,
            'department' => $existingProfile['department'] ?? null,
            'position' => $existingProfile['position'] ?? null,
            'description' => $existingProfile['description'] ?? null,
        ];
        if (array_key_exists('roles', $data) || array_key_exists('roleIds', $data)) {
            $merged['roles'] = $data['roles'] ?? $data['roleIds'];
        }
        $merged = array_replace($merged, $data);

        $payload = $this->validateManagedPayload($merged, false, $existingUser);

        if (!$this->manageEcommerceCustomers) {
            $this->assertRoleTransitionAllowed($admin, (string)$id, $payload['roleIds']);
        }

        if (!$this->manageEcommerceCustomers && ($admin['sub'] ?? null) === $id && $payload['role'] !== 'admin') {
            Response::error('No puedes quitarte tu propio rol de administrador desde aquí', 400, 'USER_SELF_ROLE_CHANGE_FORBIDDEN');
            return;
        }

        if ($repository->emailExists($payload['email'], $id)) {
            Response::error('Ya existe otro usuario con ese correo', 409, 'USER_EMAIL_EXISTS');
            return;
        }

        try {
            $updated = $repository->updateManaged($id, $payload);
            if (!$this->manageEcommerceCustomers) {
                $this->identityAccessRepository->replaceUserRoles(
                    (string)$id,
                    $payload['roleIds'],
                    (string)($admin['sub'] ?? '')
                );
                if (isset($data['status'])) {
                    $this->applyAccountStatus($admin, (string)$id, (string)$data['status']);
                }
                if (
                    array_key_exists('roles', $data)
                    || array_key_exists('roleIds', $data)
                    || strtolower((string)($existingUser['email'] ?? '')) !== strtolower($payload['email'])
                ) {
                    $repository->revokeSessions((string)$id);
                }
                if (strtolower((string)($existingUser['email'] ?? '')) !== strtolower($payload['email'])) {
                    $this->invalidateAccountLinks((string)$id);
                }
                $updated = $repository->getAdminUserById((string)$id);
            } elseif (isset($data['status'])) {
                $updated = $repository->setManagedStatus($id, (string)$data['status']);
            }
            Response::json($this->managedUserResponse($updated), 200, null, 'Usuario actualizado correctamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_UPDATE_FAILED');
        }
    }

    public function unlock($id) {
        $actor = Auth::requireAdmin();

        $repository = $this->managementRepository();
        $existingUser = $repository->getAdminUserById($id);
        if (!$existingUser || !$this->isAllowedManagedRecord($existingUser)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        try {
            $updated = $repository->unlockManagedUser($id);
            if (!$this->manageEcommerceCustomers) {
                $this->identityAccessRepository->recordAuditEvent(
                    (string)($actor['sub'] ?? ''),
                    'user.security_lock.cleared',
                    'user',
                    (string)$id
                );
            }
            Response::json($this->managedUserResponse($updated), 200, null, 'Usuario desbloqueado correctamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_UNLOCK_FAILED');
        }
    }

    public function updateRoles($id): void {
        $actor = Auth::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || !is_array($data['roleIds'] ?? $data['roles'] ?? null)) {
            Response::error('Debes enviar roleIds como una lista.', 400, 'USER_ROLES_INVALID');
            return;
        }
        $user = $this->userRepository->getAdminUserById((string)$id);
        if (!$user || !$this->isManagedWorkspaceUserRecord($user)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        try {
            $requestedRoleIds = $this->normalizeRoleIds($data['roleIds'] ?? $data['roles']);
            $roleIds = $requestedRoleIds === []
                ? []
                : $this->identityAccessRepository->validateAssignableRoleIds($requestedRoleIds);
            $this->assertRoleTransitionAllowed($actor, (string)$id, $roleIds);
            $roles = $this->identityAccessRepository->replaceUserRoles(
                (string)$id,
                $roleIds,
                (string)($actor['sub'] ?? '')
            );
            $this->userRepository->revokeSessions((string)$id);
            Response::json([
                'userId' => (string)$id,
                'roleIds' => array_values(array_map(static fn (array $role): string => (string)$role['id'], $roles)),
                'roles' => $roles,
                'sessionsRevoked' => true,
            ], 200, null, 'Roles actualizados. El usuario deberá iniciar sesión nuevamente.');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'USER_ROLES_INVALID');
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 409, 'LAST_TENANT_ADMIN_REQUIRED');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'USER_ROLES_UPDATE_FAILED');
        }
    }

    public function updateStatus($id): void {
        $actor = Auth::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            Response::error('Carga inválida', 400, 'USER_STATUS_INVALID');
            return;
        }
        try {
            $membership = $this->applyAccountStatus($actor, (string)$id, (string)($data['accountStatus'] ?? $data['status'] ?? ''));
            $updated = $this->userRepository->getAdminUserById((string)$id);
            $response = $updated ? $this->dashboardUser($updated) : ['id' => (string)$id];
            $response['accountStatus'] = $membership['status'];
            Response::json($response, 200, null, 'Estado de cuenta actualizado.');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'USER_STATUS_INVALID');
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 409, 'LAST_TENANT_ADMIN_REQUIRED');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'USER_STATUS_UPDATE_FAILED');
        }
    }

    public function invitation($id): void {
        $actor = Auth::requireAdmin();
        $user = $this->userRepository->getAdminUserById((string)$id);
        if (!$user || !$this->isManagedWorkspaceUserRecord($user)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        try {
            $currentMembership = $this->identityAccessRepository->membershipForUser((string)$id);
            if (($currentMembership['status'] ?? '') !== 'invited') {
                Response::error(
                    'Solo se puede reenviar una invitación mientras la cuenta está invitada.',
                    409,
                    'USER_NOT_INVITED'
                );
                return;
            }
            $this->userRepository->revokeSessions((string)$id);
            $delivery = $this->issueAccountLink($user, 'invitation', (string)($actor['sub'] ?? ''));
            Response::json($delivery, 200, null, 'Invitación generada.');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'USER_INVITATION_FAILED');
        }
    }

    public function passwordReset($id): void {
        $actor = Auth::requireAdmin();
        $user = $this->userRepository->getAdminUserById((string)$id);
        if (!$user || !$this->isManagedWorkspaceUserRecord($user)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        try {
            $this->userRepository->revokeSessions((string)$id);
            $delivery = $this->issueAccountLink($user, 'password_reset', (string)($actor['sub'] ?? ''));
            Response::json($delivery, 200, null, 'Enlace de restablecimiento generado.');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'USER_PASSWORD_RESET_FAILED');
        }
    }

    public function revokeSessions($id): void {
        $actor = Auth::requireAdmin();
        $user = $this->userRepository->getAdminUserById((string)$id);
        if (!$user || !$this->isManagedWorkspaceUserRecord($user)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }
        $this->userRepository->revokeSessions((string)$id);
        $this->identityAccessRepository->recordAuditEvent(
            (string)($actor['sub'] ?? ''),
            'user.sessions.revoked',
            'user',
            (string)$id
        );
        Response::json(['sessionsRevoked' => true, 'requiresLogin' => true]);
    }

    public function revokeOwnSessions(): void {
        $user = $this->authenticate();
        $repository = $this->accountRepositoryForPayload($user);
        $isFidepuntosDashboard = ($user['auth_surface'] ?? $user['aud'] ?? '') === 'dashboard'
            && strtolower((string)(TenantContext::slug() ?? TenantContext::id() ?? '')) === 'fidepuntos';
        $revokedCount = $isFidepuntosDashboard
            ? $repository->revokeOtherSessions((string)$user['sub'], (string)($user['jti'] ?? ''))
            : 0;
        if (!$isFidepuntosDashboard) {
            $repository->revokeSessions((string)$user['sub']);
        }
        if (($user['auth_surface'] ?? $user['aud'] ?? '') === 'dashboard') {
            $this->identityAccessRepository->recordAuditEvent(
                (string)$user['sub'],
                'user.sessions.revoked.self',
                'user',
                (string)$user['sub'],
                ['scope' => $isFidepuntosDashboard ? 'other-sessions' : 'all', 'revokedCount' => $revokedCount]
            );
        }
        Response::json([
            'sessionsRevoked' => true,
            'revokedCount' => $revokedCount,
            'requiresLogin' => !$isFidepuntosDashboard,
            'scope' => $isFidepuntosDashboard ? 'other-sessions' : 'all',
        ], 200, null, $isFidepuntosDashboard
            ? 'Las demás sesiones activas fueron cerradas.'
            : 'Las sesiones fueron revocadas. Inicia sesión nuevamente.');
    }

    public function ownSessions(): void {
        $user = $this->authenticate();
        $repository = $this->accountRepositoryForPayload($user);
        Response::noStore();
        Response::json($repository->sessionSummary(
            (string)$user['sub'],
            (string)($user['jti'] ?? '')
        ));
    }

    public function accessAudit(): void {
        Auth::requireAdmin();
        Response::noStore();
        $filters = [
            'actorUserId' => $_GET['actorUserId'] ?? null,
            'targetType' => $_GET['targetType'] ?? null,
            'targetId' => $_GET['targetId'] ?? null,
            'eventType' => $_GET['eventType'] ?? null,
            'limit' => $_GET['pageSize'] ?? $_GET['limit'] ?? 50,
            'offset' => max(0, ((int)($_GET['page'] ?? 1) - 1) * max(1, (int)($_GET['pageSize'] ?? 50))),
        ];
        if (trim((string)($_GET['userId'] ?? '')) !== '') {
            $filters['targetType'] = 'user';
            $filters['targetId'] = $_GET['userId'];
        } elseif (trim((string)($_GET['roleId'] ?? '')) !== '') {
            $filters['targetType'] = 'role';
            $filters['targetId'] = $_GET['roleId'];
        }
        Response::json($this->identityAccessRepository->auditEvents($filters));
    }

    public function getAddresses() {
        $user = $this->authenticate();
        try {
            $addresses = $this->accountRepositoryForPayload($user)->getAddresses($user['sub']);
            Response::json(['addresses' => $addresses ? json_decode($addresses, true) : []]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_ADDRESSES_FETCH_FAILED');
        }
    }

    public function updateAddresses() {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['addresses'])) {
            Response::error('Direcciones requeridas', 400, 'USER_ADDRESSES_REQUIRED');
            return;
        }

        try {
            $addresses = $this->accountRepositoryForPayload($user)->updateAddresses($user['sub'], $data['addresses']);
            Response::json(['addresses' => $addresses ? json_decode($addresses, true) : []]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_ADDRESSES_UPDATE_FAILED');
        }
    }

    public function getProfile() {
        $user = $this->authenticate();
        try {
            $profileData = $this->accountRepositoryForPayload($user)->getProfile($user['sub']);
            $profile = [];
            $name = null;
            $email = null;
            $phone = null;
            if ($profileData) {
                $name = $profileData['name'] ?? null;
                $email = $profileData['email'] ?? null;
                if (!empty($profileData['profile'])) {
                    $profile = json_decode($profileData['profile'], true) ?: [];
                }
                if (!empty($profile['phone'])) {
                    $phone = trim((string)$profile['phone']);
                }
                if (!empty($profileData['document_type'])) {
                    $profile['documentType'] = $profileData['document_type'];
                }
                if (!empty($profileData['document_number'])) {
                    $profile['documentNumber'] = $profileData['document_number'];
                }
                if (!empty($profileData['business_name'])) {
                    $profile['businessName'] = $profileData['business_name'];
                }
            }
            Response::json([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'profile' => $profile,
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_PROFILE_FETCH_FAILED');
        }
    }

    public function updateProfile() {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['profile']) || !is_array($data['profile'])) {
            Response::error('Perfil requerido', 400, 'USER_PROFILE_REQUIRED');
            return;
        }

        $name = $data['name'] ?? null;
        if (!$name) {
            $first = trim($data['profile']['firstName'] ?? '');
            $last = trim($data['profile']['lastName'] ?? '');
            $name = trim($first . ' ' . $last);
        }

        try {
            $updated = $this->accountRepositoryForPayload($user)->updateProfile($user['sub'], $name, $data['profile']);
            $profile = [];
            $savedName = null;
            if ($updated) {
                $savedName = $updated['name'] ?? null;
                if (!empty($updated['profile'])) {
                    $profile = json_decode($updated['profile'], true) ?: [];
                }
                if (!empty($updated['document_type'])) {
                    $profile['documentType'] = $updated['document_type'];
                }
                if (!empty($updated['document_number'])) {
                    $profile['documentNumber'] = $updated['document_number'];
                }
                if (!empty($updated['business_name'])) {
                    $profile['businessName'] = $updated['business_name'];
                }
            }
            Response::json(['name' => $savedName, 'profile' => $profile]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_PROFILE_UPDATE_FAILED');
        }
    }

    public function updatePassword() {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        $currentPassword = trim((string)($data['currentPassword'] ?? ''));
        $newPassword = trim((string)($data['newPassword'] ?? ''));
        $confirmation = trim((string)($data['confirmPassword'] ?? $data['newPasswordConfirmation'] ?? ''));

        if ($currentPassword === '' || $newPassword === '') {
            Response::error('La contraseña actual y la nueva son obligatorias', 400, 'USER_PASSWORD_REQUIRED');
            return;
        }

        if (mb_strlen($newPassword) < 12) {
            Response::error('La nueva contraseña debe tener al menos 12 caracteres', 400, 'USER_PASSWORD_WEAK');
            return;
        }

        if ($currentPassword === $newPassword) {
            Response::error('La nueva contraseña debe ser diferente a la actual', 400, 'USER_PASSWORD_SAME');
            return;
        }

        if ($confirmation === '' || !hash_equals($newPassword, $confirmation)) {
            Response::error('La confirmación no coincide con la nueva contraseña', 400, 'USER_PASSWORD_CONFIRMATION_MISMATCH');
            return;
        }

        try {
            $repository = $this->accountRepositoryForPayload($user);
            $passwordHash = $repository->getPasswordHash($user['sub']);
            if (!$passwordHash || !password_verify($currentPassword, $passwordHash)) {
                Response::error('La contraseña actual es incorrecta', 400, 'USER_PASSWORD_INVALID_CURRENT');
                return;
            }

            $newTokenId = bin2hex(random_bytes(16));
            $repository->updatePassword(
                $user['sub'],
                password_hash($newPassword, PASSWORD_DEFAULT),
                $newTokenId
            );
            if (($user['auth_surface'] ?? $user['aud'] ?? '') === 'dashboard') {
                $this->invalidateAccountLinks((string)$user['sub']);
            }

            if (($user['auth_surface'] ?? $user['aud'] ?? '') === 'dashboard') {
                $this->identityAccessRepository->recordAuditEvent(
                    (string)$user['sub'],
                    'user.password.changed.self',
                    'user',
                    (string)$user['sub']
                );
            }

            Response::json([
                'passwordUpdated' => true,
                'sessionsRevoked' => true,
                'requiresLogin' => true,
            ], 200, null, 'Contraseña actualizada. Inicia sesión nuevamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_PASSWORD_UPDATE_FAILED');
        }
    }

    private function assertRoleTransitionAllowed(array $actor, string $userId, array $nextRoleIds): void {
        $adminRoleId = $this->defaultRoleId('admin');
        $currentRoleIds = $this->identityAccessRepository->roleIdsForUser($userId);
        $removesAdmin = in_array($adminRoleId, $currentRoleIds, true)
            && !in_array($adminRoleId, $nextRoleIds, true);
        if (!$removesAdmin) {
            return;
        }
        // Los roles base del sistema se preservan en el repositorio y ya no se
        // envian desde los selectores tenant, asi que su ausencia en el payload
        // no representa una degradacion real.
    }

    private function requireRoleAssignmentPermissionIfRequested(array $actor, array $payload): void {
        if ($this->manageEcommerceCustomers || (!array_key_exists('roles', $payload) && !array_key_exists('roleIds', $payload))) {
            return;
        }
        $actorUser = $this->userRepository->getById((string)($actor['sub'] ?? ''));
        if (
            !$actorUser
            || !$this->tenantAccessService->userHasPermission(
                $actorUser,
                TenantContext::get() ?? [],
                'identity.users.assign_roles'
            )
        ) {
            Response::error('No tienes permiso para asignar roles.', 403, 'USER_ROLE_ASSIGNMENT_FORBIDDEN');
            exit;
        }
    }

    private function applyAccountStatus(array $actor, string $userId, string $status): array {
        $status = strtolower(trim($status));
        if (!in_array($status, ['invited', 'active', 'inactive', 'blocked'], true)) {
            throw new InvalidArgumentException('Estado de cuenta no permitido.');
        }
        $user = $this->userRepository->getAdminUserById($userId);
        if (!$user || !$this->isManagedWorkspaceUserRecord($user)) {
            throw new InvalidArgumentException('Usuario no encontrado en el tenant activo.');
        }
        $currentMembership = $this->identityAccessRepository->membershipForUser($userId);
        if (
            $status === 'active'
            && (
                strtolower((string)($currentMembership['status'] ?? '')) === 'invited'
                || !$this->isTruthyDbValue($user['email_verified'] ?? false)
            )
        ) {
            throw new InvalidArgumentException(
                'Una cuenta invitada o sin correo verificado solo puede activarse al completar su enlace seguro.'
            );
        }
        if ((string)($actor['sub'] ?? '') === $userId && $status !== 'active') {
            throw new \RuntimeException('No puedes bloquear o desactivar tu propia cuenta.');
        }

        $adminRoleId = $this->defaultRoleId('admin');
        $roleIds = $this->identityAccessRepository->roleIdsForUser($userId);
        if (
            $status !== 'active'
            && in_array($adminRoleId, $roleIds, true)
            && $this->identityAccessRepository->countActiveUsersWithRole($adminRoleId, null, $userId) < 1
        ) {
            throw new \RuntimeException('El tenant debe conservar al menos un administrador activo.');
        }

        $membership = $this->identityAccessRepository->updateMembershipStatus(
            $userId,
            $status,
            (string)($actor['sub'] ?? '')
        );
        $this->invalidateAccountLinks($userId);
        if ($status !== 'active') {
            $this->userRepository->revokeSessions($userId);
        }
        return $membership;
    }

    private function invalidateAccountLinks(string $userId): void {
        (new PasswordResetTokenRepository())->invalidateForUser($userId);
    }

    private function issueAccountLink(array $user, string $purpose, string $actorUserId): array {
        $purpose = $purpose === 'invitation' ? 'invitation' : 'password_reset';
        $email = strtolower(trim((string)($user['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El usuario no tiene un correo válido para recibir el enlace.');
        }

        $ttlMinutes = max(10, min(1440, (int)($_ENV['PASSWORD_RESET_TTL_MINUTES'] ?? 30)));
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60));
        $tokens = new PasswordResetTokenRepository();
        $tokens->deleteExpired();
        $tokens->create(
            (string)$user['id'],
            hash('sha256', $token),
            $expiresAt,
            $this->clientIp(),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
            $purpose,
            $actorUserId !== '' ? $actorUserId : null
        );

        $baseUrl = TenantContext::publicBaseUrl()
            ?? TenantContext::appUrl()
            ?? ($_ENV['APP_URL'] ?? '');
        if (trim((string)$baseUrl) === '') {
            throw new \RuntimeException('No existe una URL pública configurada para el tenant.');
        }
        $resetPath = strtolower((string)(TenantContext::slug() ?? '')) === 'fidepuntos'
            ? '/reset-password'
            : '/dashboard/reset-password';
        $url = rtrim((string)$baseUrl, '/') . $resetPath . '?token=' . urlencode($token);
        $name = trim((string)($user['name'] ?? '')) ?: 'Usuario';
        $subject = $purpose === 'invitation'
            ? 'Completa tu acceso a Fidepuntos'
            : 'Restablece tu contraseña de Fidepuntos';
        $message = "Hola {$name},\n\n";
        $message .= $purpose === 'invitation'
            ? "Te invitaron a administrar Fidepuntos. Define tu contraseña desde este enlace:\n"
            : "Un administrador solicitó restablecer tu contraseña. Usa este enlace:\n";
        $message .= "{$url}\n\nEl enlace vence en {$ttlMinutes} minutos y solo puede usarse una vez.\n";
        $sent = MailService::send($email, $subject, $message, null, null, [
            'category' => $purpose === 'invitation' ? 'identity-invitation' : 'identity-password-reset',
            'tenant_id' => TenantContext::id(),
            'user_id' => (string)$user['id'],
        ]);

        $this->identityAccessRepository->recordAuditEvent(
            $actorUserId,
            $purpose === 'invitation' ? 'user.invitation.sent' : 'user.password_reset.sent',
            'user',
            (string)$user['id'],
            ['delivery' => $sent ? 'sent' : 'failed', 'expiresAt' => $expiresAt]
        );

        return [
            'sent' => $sent,
            'purpose' => $purpose,
            'expiresAt' => gmdate('c', strtotime($expiresAt) ?: time()),
        ];
    }

    private function clientIp(): ?string {
        $candidate = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
        if (str_contains($candidate, ',')) {
            $candidate = trim(explode(',', $candidate)[0]);
        }
        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
    }

    private function dashboardUser(array $user): array {
        $managedUser = $this->isManagedWorkspaceUserRecord($user);
        $profile = $this->decodeProfile($user['profile'] ?? null);
        $userId = (string)($user['id'] ?? '');
        $roleAssignments = $managedUser ? $this->identityAccessRepository->rolesForUser($userId) : [];
        $roleIds = $managedUser
            ? array_values(array_map(static fn (array $role): string => (string)$role['id'], $roleAssignments))
            : ['customer'];
        $membership = $managedUser ? $this->identityAccessRepository->membershipForUser($userId) : null;
        $accountStatus = strtolower(trim((string)($membership['status'] ?? '')));
        if (!in_array($accountStatus, ['invited', 'active', 'inactive', 'blocked'], true)) {
            $accountStatus = $this->dashboardUserStatus($user);
        }
        $lockedUntil = strtotime((string)($user['login_locked_until'] ?? ''));
        $securityLocked = $lockedUntil !== false && $lockedUntil > time();

        return [
            'id' => $userId,
            'name' => (string)($user['name'] ?? 'Usuario'),
            'email' => (string)($user['email'] ?? ''),
            'emailVerified' => $this->isTruthyDbValue($user['email_verified'] ?? false),
            'phone' => $this->normalizeText($profile['phone'] ?? null, 60),
            'department' => $this->normalizeText($profile['department'] ?? null, 120)
                ?? ($managedUser
                    ? (($user['role'] ?? '') === 'admin' ? 'Administracion' : 'Operacion')
                    : 'Clientes'),
            'position' => $this->normalizeText($profile['position'] ?? null, 120)
                ?? ($managedUser
                    ? (($user['role'] ?? '') === 'admin' ? 'Administrador' : 'Usuario gestionado')
                    : 'Cliente'),
            'status' => $accountStatus,
            'accountStatus' => $accountStatus,
            'securityLock' => [
                'isLocked' => $securityLocked,
                'failedAttempts' => (int)($user['failed_login_attempts'] ?? 0),
                'lockedUntil' => $securityLocked ? gmdate('c', $lockedUntil) : null,
            ],
            'roles' => $roleIds,
            'roleAssignments' => array_values(array_map(static fn (array $role): array => [
                'id' => (string)$role['id'],
                'name' => (string)$role['name'],
                'system' => !empty($role['system']),
            ], $roleAssignments)),
            'avatarUrl' => 'assets/images/user.png',
            'coverUrl' => 'assets/images/user-grid/user-grid-bg1.png',
            'description' => $this->normalizeText($profile['description'] ?? null, 500),
            'createdAt' => $this->formatDate($user['created_at'] ?? null),
            'updatedAt' => $this->formatDate($user['updated_at'] ?? null),
            'lastLoginAt' => $this->nullableDate($user['last_login_at'] ?? null),
            'invitationStatus' => $accountStatus === 'invited' ? 'pending' : null,
        ];
    }

    private function ecommerceUser(array $user): array {
        $profile = $this->decodeProfile($user['profile'] ?? null);
        $identityType = $this->tenantAccessService->identityTypeForUser($user, TenantContext::get() ?? []);
        $lastShippingAddress = $this->decodeStructuredValue($user['last_shipping_address'] ?? null);
        $lastBillingAddress = $this->decodeStructuredValue($user['last_billing_address'] ?? null);
        $addresses = $this->decodeStructuredValue($user['addresses'] ?? null);
        $primaryAddress = $lastShippingAddress ?: $lastBillingAddress ?: $this->firstAddress($addresses);
        $resolvedEmail = $this->normalizeText($user['email'] ?? null, 190)
            ?? $this->normalizeText($primaryAddress['email'] ?? null, 190);
        $resolvedPhone = $this->normalizeText($profile['phone'] ?? null, 60)
            ?? $this->normalizeText($primaryAddress['phone'] ?? null, 60);
        $resolvedCompany = $this->normalizeText($user['business_name'] ?? null, 180)
            ?? $this->normalizeText($primaryAddress['company'] ?? ($primaryAddress['businessName'] ?? null), 180);

        return [
            'id' => (string)($user['id'] ?? ''),
            'name' => (string)($user['name'] ?? 'Usuario ecommerce'),
            'email' => (string)($user['email'] ?? ''),
            'resolvedEmail' => $resolvedEmail,
            'role' => $identityType === 'customer' ? 'customer' : 'admin',
            'email_verified' => $this->isTruthyDbValue($user['email_verified'] ?? false),
            'document_type' => $this->normalizeText($user['document_type'] ?? null, 40),
            'document_number' => $this->normalizeText($user['document_number'] ?? null, 80),
            'business_name' => $this->normalizeText($user['business_name'] ?? null, 180),
            'resolvedCompany' => $resolvedCompany,
            'resolvedPhone' => $resolvedPhone,
            'resolvedAddressText' => $this->formatAddressText($primaryAddress),
            'profile' => $profile,
            'orders_total' => (int)($user['orders_total'] ?? 0),
            'orders_completed' => (int)($user['orders_completed'] ?? 0),
            'total_spent' => (string)($user['total_spent'] ?? '0.00'),
            'last_order_at' => $this->formatDate($user['last_order_at'] ?? null),
            'failed_login_attempts' => (int)($user['failed_login_attempts'] ?? 0),
            'login_locked_until' => $this->formatDate($user['login_locked_until'] ?? null),
            'security_block_event_type' => $this->normalizeText($user['security_block_event_type'] ?? null, 120),
            'security_blocked_at' => $this->formatDate($user['security_blocked_at'] ?? null),
        ];
    }

    private function matchesDashboardScope(array $user, string $scope): bool {
        if (!$this->isManagedWorkspaceUserRecord($user)) {
            return false;
        }

        if ($scope === 'platform') {
            return $this->isPlatformWorkspaceUserRecord($user);
        }

        if ($scope === 'all') {
            return true;
        }

        return !$this->isPlatformWorkspaceUserRecord($user);
    }

    private function isManagedWorkspaceUserRecord(array $user): bool {
        return in_array(
            $this->tenantAccessService->identityTypeForUser($user, TenantContext::get() ?? []),
            ['platform', 'tenant_staff'],
            true
        );
    }

    private function isEcommerceCustomerRecord(array $user): bool {
        return $this->tenantAccessService->identityTypeForUser($user, TenantContext::get() ?? []) === 'customer';
    }

    private function accountRepositoryForPayload(array $payload): UserRepository {
        $surface = strtolower(trim((string)($payload['auth_surface'] ?? $payload['aud'] ?? '')));
        return $surface === 'dashboard' ? $this->userRepository : $this->customerRepository;
    }

    private function isPlatformWorkspaceUserRecord(array $user): bool {
        return $this->tenantAccessService->identityTypeForUser($user, TenantContext::get() ?? []) === 'platform';
    }

    private function dashboardUserStatus(array $user): string {
        $lockedUntil = strtotime((string)($user['login_locked_until'] ?? ''));
        if ($lockedUntil !== false && $lockedUntil > time()) {
            return 'blocked';
        }

        return $this->isTruthyDbValue($user['email_verified'] ?? false) ? 'active' : 'inactive';
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

    private function decodeStructuredValue($value): array {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function firstAddress(array $addresses): array {
        if (array_is_list($addresses)) {
            foreach ($addresses as $address) {
                if (is_array($address)) {
                    return $address;
                }
            }
        }

        foreach (['default', 'shipping', 'billing'] as $key) {
            if (isset($addresses[$key]) && is_array($addresses[$key])) {
                return $addresses[$key];
            }
        }

        return [];
    }

    private function formatAddressText(array $address): ?string {
        $parts = [];
        foreach (['street', 'address', 'line1', 'city', 'province', 'country'] as $key) {
            $value = $this->normalizeText($address[$key] ?? null, 160);
            if ($value !== null) {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(', ', array_values(array_unique($parts)));
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

    private function databaseRoleFromRoleIds(array $roleIds): string {
        if ($roleIds === []) {
            return 'customer';
        }

        foreach ($roleIds as $roleId) {
            if (!in_array($roleId, ['buyer', 'customer', 'guest', 'shopper'], true)) {
                return 'admin';
            }
        }

        return 'customer';
    }

    private function managedIdentityTypeFromRoleIds(array $roleIds): string {
        foreach ($roleIds as $roleId) {
            if (in_array($roleId, ['platform_admin', 'superadmin'], true)) {
                return 'platform';
            }
        }

        return 'tenant_staff';
    }

    private function defaultRoleId(string $type): string {
        $tenantSlug = TenantContext::slug() ?: 'tenant';
        return "{$tenantSlug}_{$type}";
    }

    private function formatDate($value): string {
        $timestamp = strtotime((string)($value ?? ''));
        return $timestamp !== false ? gmdate('c', $timestamp) : gmdate('c');
    }

    private function nullableDate($value): ?string {
        $timestamp = strtotime((string)($value ?? ''));
        return $timestamp !== false ? gmdate('c', $timestamp) : null;
    }

    private function isTruthyDbValue($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on'], true);
    }
}
