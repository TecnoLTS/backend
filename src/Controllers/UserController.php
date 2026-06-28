<?php

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Core\Response;
use App\Core\Auth;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;

class UserController {
    private $userRepository;
    private TenantAccessService $tenantAccessService;

    public function __construct() {
        $this->userRepository = new UserRepository();
        $this->tenantAccessService = new TenantAccessService();
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

        $roleIds = $this->normalizeRoleIds($data['roles'] ?? ($data['roleIds'] ?? ($data['profile']['roleIds'] ?? null)));
        if ($roleIds !== []) {
            $profile['roleIds'] = $roleIds;
        } elseif (empty($profile['roleIds'])) {
            $profile['roleIds'] = [
                $databaseRole === 'admin'
                    ? $this->defaultRoleId('admin')
                    : $this->defaultRoleId('reader')
            ];
        }
        $profile['identityType'] = $this->managedIdentityTypeFromRoleIds($profile['roleIds']);

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

        $roleIds = $this->normalizeRoleIds($data['roles'] ?? ($data['roleIds'] ?? ($data['profile']['roleIds'] ?? null)));
        $role = $roleIds !== [] ? $this->databaseRoleFromRoleIds($roleIds) : $this->normalizeRole($data['role'] ?? 'customer');
        $password = trim((string)($data['password'] ?? ''));
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
            'email_verified' => $this->isTruthyDbValue($data['emailVerified'] ?? ($data['email_verified'] ?? true)),
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'business_name' => $this->normalizeText($data['businessName'] ?? ($data['business_name'] ?? null), 180),
            'profile' => $this->normalizeManagedProfile($data, $existingProfile, $role),
        ];
    }

    public function index() {
        Auth::requireAdmin();
        try {
            Response::noStore();
            $query = $_GET;
            $search = strtolower(trim((string)($query['search'] ?? '')));
            $scope = strtolower(trim((string)($query['scope'] ?? 'tenant')));
            $status = strtolower(trim((string)($query['status'] ?? 'all')));
            $page = max(1, (int)($query['page'] ?? 1));
            $pageSize = max(1, min(100, (int)($query['pageSize'] ?? 10)));
            $managedUsers = array_values(array_filter(
                $this->userRepository->getAll(),
                fn (array $user): bool => $this->matchesDashboardScope($user, $scope)
            ));
            $users = array_map(fn (array $user): array => $this->dashboardUser($user), $managedUsers);

            if ($search !== '') {
                $users = array_values(array_filter($users, static function (array $user) use ($search): bool {
                    $haystack = strtolower(implode(' ', [
                        $user['name'] ?? '',
                        $user['email'] ?? '',
                        $user['department'] ?? '',
                        $user['position'] ?? '',
                        implode(' ', $user['roles'] ?? []),
                    ]));
                    return str_contains($haystack, $search);
                }));
            }

            if (in_array($status, ['active', 'inactive', 'blocked'], true)) {
                $users = array_values(array_filter($users, static fn (array $user): bool => ($user['status'] ?? '') === $status));
            }

            $totalItems = count($users);
            $totalPages = max(1, (int)ceil($totalItems / $pageSize));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $pageSize;

            Response::json(array_slice($users, $offset, $pageSize), 200, [
                'page' => $page,
                'pageSize' => $pageSize,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ]);
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
                $this->userRepository->getAll(),
                fn (array $user): bool => in_array($this->tenantAccessService->identityTypeForUser($user, TenantContext::get() ?? []), ['customer', 'tenant_staff'], true)
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

        $user = $this->userRepository->getAdminUserById($id);
        if (!$user || !$this->isManagedWorkspaceUserRecord($user)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        Response::noStore();
        Response::json($this->dashboardUser($user));
    }

    public function store() {
        $admin = Auth::requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            Response::error('Carga inválida', 400, 'USER_PAYLOAD_INVALID');
            return;
        }

        $payload = $this->validateManagedPayload($data, true);

        if ($this->userRepository->emailExists($payload['email'])) {
            Response::error('Ya existe un usuario con ese correo', 409, 'USER_EMAIL_EXISTS');
            return;
        }

        try {
            $created = $this->userRepository->createManaged($payload);
            Response::json($this->dashboardUser($created), 201, null, sprintf('Usuario creado correctamente por %s.', $admin['name'] ?? 'administrador'));
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

        $existingUser = $this->userRepository->getAdminUserById($id);
        if (!$existingUser || !$this->isManagedWorkspaceUserRecord($existingUser)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        $payload = $this->validateManagedPayload($data, false, $existingUser);

        if (($admin['sub'] ?? null) === $id && $payload['role'] !== 'admin') {
            Response::error('No puedes quitarte tu propio rol de administrador desde aquí', 400, 'USER_SELF_ROLE_CHANGE_FORBIDDEN');
            return;
        }

        if ($this->userRepository->emailExists($payload['email'], $id)) {
            Response::error('Ya existe otro usuario con ese correo', 409, 'USER_EMAIL_EXISTS');
            return;
        }

        try {
            $updated = $this->userRepository->updateManaged($id, $payload);
            Response::json($this->dashboardUser($updated), 200, null, 'Usuario actualizado correctamente.');
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

        $existingUser = $this->userRepository->getAdminUserById($id);
        if (!$existingUser || !$this->isManagedWorkspaceUserRecord($existingUser)) {
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
            'roles' => $existingProfile['roleIds'] ?? null,
        ];
        $merged = array_replace($merged, $data);

        if (isset($data['status']) && in_array($data['status'], ['active', 'inactive'], true)) {
            $merged['email_verified'] = $data['status'] === 'active';
        }

        $payload = $this->validateManagedPayload($merged, false, $existingUser);

        if (($admin['sub'] ?? null) === $id && $payload['role'] !== 'admin') {
            Response::error('No puedes quitarte tu propio rol de administrador desde aquí', 400, 'USER_SELF_ROLE_CHANGE_FORBIDDEN');
            return;
        }

        if ($this->userRepository->emailExists($payload['email'], $id)) {
            Response::error('Ya existe otro usuario con ese correo', 409, 'USER_EMAIL_EXISTS');
            return;
        }

        try {
            $updated = $this->userRepository->updateManaged($id, $payload);
            if (isset($data['status'])) {
                $updated = $this->userRepository->setManagedStatus($id, (string)$data['status']);
            }
            Response::json($this->dashboardUser($updated), 200, null, 'Usuario actualizado correctamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_UPDATE_FAILED');
        }
    }

    public function unlock($id) {
        Auth::requireAdmin();

        $existingUser = $this->userRepository->getAdminUserById($id);
        if (!$existingUser || !$this->isManagedWorkspaceUserRecord($existingUser)) {
            Response::error('Usuario no encontrado', 404, 'USER_NOT_FOUND');
            return;
        }

        try {
            $updated = $this->userRepository->unlockManagedUser($id);
            Response::json($this->dashboardUser($updated), 200, null, 'Usuario desbloqueado correctamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_UNLOCK_FAILED');
        }
    }

    public function getAddresses() {
        $user = $this->authenticate();
        try {
            $addresses = $this->userRepository->getAddresses($user['sub']);
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
            $addresses = $this->userRepository->updateAddresses($user['sub'], $data['addresses']);
            Response::json(['addresses' => $addresses ? json_decode($addresses, true) : []]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_ADDRESSES_UPDATE_FAILED');
        }
    }

    public function getProfile() {
        $user = $this->authenticate();
        try {
            $profileData = $this->userRepository->getProfile($user['sub']);
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
            $updated = $this->userRepository->updateProfile($user['sub'], $name, $data['profile']);
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

        try {
            $passwordHash = $this->userRepository->getPasswordHash($user['sub']);
            if (!$passwordHash || !password_verify($currentPassword, $passwordHash)) {
                Response::error('La contraseña actual es incorrecta', 400, 'USER_PASSWORD_INVALID_CURRENT');
                return;
            }

            $newTokenId = bin2hex(random_bytes(16));
            $this->userRepository->updatePassword(
                $user['sub'],
                password_hash($newPassword, PASSWORD_DEFAULT),
                $newTokenId
            );

            Response::json(['passwordUpdated' => true], 200, null, 'Contraseña actualizada correctamente.');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'USER_PASSWORD_UPDATE_FAILED');
        }
    }

    private function dashboardUser(array $user): array {
        $managedUser = $this->isManagedWorkspaceUserRecord($user);
        $profile = $this->decodeProfile($user['profile'] ?? null);
        $roleIds = $this->normalizeRoleIds($profile['roleIds'] ?? null);
        if ($roleIds === []) {
            $roleIds = $managedUser
                ? [($user['role'] ?? 'customer') === 'admin' ? $this->defaultRoleId('admin') : $this->defaultRoleId('reader')]
                : ['customer'];
        }

        return [
            'id' => (string)($user['id'] ?? ''),
            'name' => (string)($user['name'] ?? 'Usuario'),
            'email' => (string)($user['email'] ?? ''),
            'phone' => $this->normalizeText($profile['phone'] ?? null, 60),
            'department' => $this->normalizeText($profile['department'] ?? null, 120)
                ?? ($managedUser
                    ? (($user['role'] ?? '') === 'admin' ? 'Administracion' : 'Operacion')
                    : 'Clientes'),
            'position' => $this->normalizeText($profile['position'] ?? null, 120)
                ?? ($managedUser
                    ? (($user['role'] ?? '') === 'admin' ? 'Administrador' : 'Usuario gestionado')
                    : 'Cliente'),
            'status' => $this->dashboardUserStatus($user),
            'roles' => $roleIds,
            'avatarUrl' => 'assets/images/user.png',
            'coverUrl' => 'assets/images/user-grid/user-grid-bg1.png',
            'description' => $this->normalizeText($profile['description'] ?? null, 500),
            'createdAt' => $this->formatDate($user['created_at'] ?? null),
            'updatedAt' => $this->formatDate($user['updated_at'] ?? null),
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
