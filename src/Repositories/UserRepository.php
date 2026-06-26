<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;

class UserRepository {
    private $db;
    private TenantAccessService $tenantAccessService;

    public function __construct() {
        $this->db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
        $this->tenantAccessService = new TenantAccessService();
    }

    public function getAll() {
        $stmt = $this->db->prepare('
            SELECT
                u.id,
                u.name,
                u.email,
                u.role,
                u.email_verified,
                u.document_type,
                u.document_number,
                u.business_name,
                u.profile,
                u.addresses,
                u.created_at,
                u.updated_at,
                u.failed_login_attempts,
                u.login_locked_until,
                u.last_login_at,
                security_block.event_type AS security_block_event_type,
                security_block.status AS security_block_status,
                security_block.created_at AS security_blocked_at,
                security_block.metadata AS security_block_metadata,
                COALESCE(stats.orders_total, 0)::int AS orders_total,
                COALESCE(stats.orders_active, 0)::int AS orders_active,
                COALESCE(stats.orders_completed, 0)::int AS orders_completed,
                COALESCE(stats.total_spent, 0)::numeric(12,2) AS total_spent,
                stats.last_order_at,
                stats.last_order_id,
                o_last.shipping_address AS last_shipping_address,
                o_last.billing_address AS last_billing_address
            FROM "User" u
            LEFT JOIN (
                SELECT
                    o.user_id,
                    COUNT(*) AS orders_total,
                    COUNT(*) FILTER (
                        WHERE LOWER(COALESCE(o.status, \'pending\')) NOT IN (\'canceled\', \'cancelled\')
                    ) AS orders_active,
                    COUNT(*) FILTER (
                        WHERE LOWER(COALESCE(o.status, \'pending\')) IN (\'completed\', \'delivered\')
                    ) AS orders_completed,
                    SUM(
                        CASE
                            WHEN LOWER(COALESCE(o.status, \'pending\')) NOT IN (\'canceled\', \'cancelled\')
                            THEN COALESCE(o.total, 0)
                            ELSE 0
                        END
                    ) AS total_spent,
                    MAX(o.created_at) AS last_order_at,
                    (ARRAY_AGG(o.id ORDER BY o.created_at DESC))[1] AS last_order_id
                FROM "Order" o
                WHERE o.tenant_id = :tenant_id_orders
                GROUP BY o.user_id
            ) stats ON stats.user_id = u.id
            LEFT JOIN "Order" o_last
                ON o_last.id = stats.last_order_id
                AND o_last.tenant_id = :tenant_id_orders_latest
            LEFT JOIN LATERAL (
                SELECT
                    ase.event_type,
                    ase.status,
                    ase.created_at,
                    ase.metadata
                FROM "AuthSecurityEvent" ase
                WHERE ase.user_id = u.id
                  AND ase.tenant_id = :tenant_id_security
                  AND LOWER(COALESCE(ase.status, \'info\')) = \'blocked\'
                ORDER BY ase.created_at DESC
                LIMIT 1
            ) security_block ON TRUE
            WHERE u.tenant_id = :tenant_id_users
            ORDER BY u.created_at DESC, u.name ASC
        ');
        $tenantId = $this->getTenantId();
        $stmt->execute([
            'tenant_id_orders' => $tenantId,
            'tenant_id_orders_latest' => $tenantId,
            'tenant_id_security' => $tenantId,
            'tenant_id_users' => $tenantId
        ]);
        return $stmt->fetchAll();
    }

    public function getByEmail($email) {
        $stmt = $this->db->prepare('SELECT id, name, email, password, email_verified, role, document_type, document_number, business_name, profile, addresses, failed_login_attempts, login_locked_until FROM "User" WHERE email = :email AND tenant_id = :tenant_id');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function getByEmailWithOtp($email) {
        $stmt = $this->db->prepare('SELECT id, name, email, password, email_verified, role, document_type, document_number, business_name, profile, addresses, otp_code, otp_expires_at, otp_attempts, failed_login_attempts, login_locked_until FROM "User" WHERE email = :email AND tenant_id = :tenant_id');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function getById($id) {
        $stmt = $this->db->prepare('
            SELECT
                id,
                name,
                email,
                role,
                email_verified,
                document_type,
                document_number,
                business_name,
                profile,
                addresses,
                failed_login_attempts,
                login_locked_until
            FROM "User"
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function getAdminUserById($id) {
        $stmt = $this->db->prepare('
            SELECT
                "User".id,
                "User".name,
                "User".email,
                "User".role,
                "User".email_verified,
                "User".document_type,
                "User".document_number,
                "User".business_name,
                "User".profile,
                "User".addresses,
                "User".created_at,
                "User".updated_at,
                "User".failed_login_attempts,
                "User".login_locked_until,
                "User".last_login_at,
                security_block.event_type AS security_block_event_type,
                security_block.status AS security_block_status,
                security_block.created_at AS security_blocked_at,
                security_block.metadata AS security_block_metadata
            FROM "User"
            LEFT JOIN LATERAL (
                SELECT
                    ase.event_type,
                    ase.status,
                    ase.created_at,
                    ase.metadata
                FROM "AuthSecurityEvent" ase
                WHERE ase.user_id = "User".id
                  AND ase.tenant_id = :tenant_id_security
                  AND LOWER(COALESCE(ase.status, \'info\')) = \'blocked\'
                ORDER BY ase.created_at DESC
                LIMIT 1
            ) security_block ON TRUE
            WHERE "User".id = :id AND "User".tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id_security' => $this->getTenantId(),
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function getByDocumentNumber(string $documentNumber) {
        $normalized = trim($documentNumber);
        if ($normalized === '') {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT
                id,
                name,
                email,
                password,
                email_verified,
                role,
                document_type,
                document_number,
                business_name,
                profile,
                addresses,
                failed_login_attempts,
                login_locked_until
            FROM "User"
            WHERE document_number = :document_number
              AND tenant_id = :tenant_id
            ORDER BY created_at DESC
            LIMIT 1
        ');
        $stmt->execute([
            'document_number' => $normalized,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function emailExists(string $email, ?string $excludeId = null): bool {
        $sql = 'SELECT 1 FROM "User" WHERE email = :email AND tenant_id = :tenant_id';
        $params = [
            'email' => $email,
            'tenant_id' => $this->getTenantId()
        ];

        if ($excludeId) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public function getAuthState($id) {
        $stmt = $this->db->prepare('SELECT id, name, email, role, profile, active_token_id FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function getAddresses($userId) {
        $stmt = $this->db->prepare('SELECT addresses FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return json_encode($this->normalizeAddressesPayload($row['addresses']));
    }

    public function updateAddresses($userId, $addresses) {
        $normalizedAddresses = $this->normalizeAddressesPayload($addresses);
        $stmt = $this->db->prepare('UPDATE "User" SET addresses = :addresses, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'addresses' => json_encode($normalizedAddresses)
        ]);
        return $this->getAddresses($userId);
    }

    public function getProfile($userId) {
        $stmt = $this->db->prepare('SELECT name, email, profile, document_type, document_number, business_name FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function updateProfile($userId, $name, $profile) {
        $docType = $profile['documentType'] ?? ($profile['document_type'] ?? null);
        $docNumber = $profile['documentNumber'] ?? ($profile['document_number'] ?? null);
        $businessName = $profile['businessName'] ?? ($profile['business_name'] ?? ($profile['company'] ?? null));
        $stmt = $this->db->prepare('UPDATE "User" SET name = :name, profile = :profile, document_type = :document_type, document_number = :document_number, business_name = :business_name, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'name' => $name,
            'profile' => json_encode($profile),
            'document_type' => $docType,
            'document_number' => $docNumber,
            'business_name' => $businessName
        ]);
        return $this->getProfile($userId);
    }

    public function getPasswordHash($userId) {
        $stmt = $this->db->prepare('SELECT password FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        return $row ? $row['password'] : null;
    }

    public function updatePassword($userId, $newPasswordHash, $newTokenId) {
        $stmt = $this->db->prepare('UPDATE "User" SET password = :password, active_token_id = :token_id, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'password' => $newPasswordHash,
            'token_id' => $newTokenId
        ]);
    }

    public function resetPasswordAfterRecovery(string $userId, string $newPasswordHash, string $newTokenId): void {
        $stmt = $this->db->prepare('
            UPDATE "User"
            SET password = :password,
                active_token_id = :token_id,
                failed_login_attempts = 0,
                login_locked_until = NULL,
                otp_code = NULL,
                otp_expires_at = NULL,
                otp_attempts = 0,
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'password' => $newPasswordHash,
            'token_id' => $newTokenId
        ]);
    }

    public function setOtpForEmail($email, $code, $expiresAt) {
        $stmt = $this->db->prepare('UPDATE "User" SET otp_code = :code, otp_expires_at = :expires_at, otp_attempts = 0, updated_at = NOW() WHERE email = :email AND tenant_id = :tenant_id');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId(),
            'code' => $code,
            'expires_at' => $expiresAt
        ]);
    }

    public function markEmailVerifiedByOtp($userId) {
        $stmt = $this->db->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL, otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        return $this->getById($userId);
    }

    public function incrementOtpAttempts($userId) {
        $stmt = $this->db->prepare('UPDATE "User" SET otp_attempts = COALESCE(otp_attempts, 0) + 1 WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function setLoginFailureState(string $userId, int $attempts, ?string $lockedUntil): void {
        $stmt = $this->db->prepare('
            UPDATE "User"
            SET failed_login_attempts = :attempts,
                login_locked_until = :locked_until,
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'attempts' => max(0, $attempts),
            'locked_until' => $lockedUntil
        ]);
    }

    public function clearLoginFailures(string $userId): void {
        $stmt = $this->db->prepare('
            UPDATE "User"
            SET failed_login_attempts = 0,
                login_locked_until = NULL,
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function unlockManagedUser(string $userId) {
        $this->clearLoginFailures($userId);
        return $this->getAdminUserById($userId);
    }

    public function markSuccessfulLogin(string $userId): void {
        $stmt = $this->db->prepare('
            UPDATE "User"
            SET failed_login_attempts = 0,
                login_locked_until = NULL,
                last_login_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function setActiveTokenId($userId, $tokenId) {
        $stmt = $this->db->prepare('UPDATE "User" SET active_token_id = :tokenId, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'tokenId' => $tokenId
        ]);
    }

    public function clearActiveTokenId($userId) {
        $stmt = $this->db->prepare('UPDATE "User" SET active_token_id = NULL, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
        ]);
    }

    public function getActiveTokenId($userId) {
        $stmt = $this->db->prepare('SELECT active_token_id FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        return $row ? $row['active_token_id'] : null;
    }

    public function create($data, $options = []) {
        $skipToken = (bool)($options['skip_verification_token'] ?? false);
        $profile = $this->buildRegistrationProfile($data);
        $addresses = $this->normalizeAddressesPayload($data['addresses'] ?? null);
        $sql = 'INSERT INTO "User" (id, tenant_id, name, email, password, role, email_verified, updated_at, verification_token, document_type, document_number, business_name, profile, addresses) VALUES (:id, :tenant_id, :name, :email, :password, :role, :email_verified, NOW(), :token, :document_type, :document_number, :business_name, :profile, :addresses)';
        $stmt = $this->db->prepare($sql);
        $id = bin2hex(random_bytes(10));
        $token = $skipToken ? null : bin2hex(random_bytes(32));
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => $data['role'] ?? 'customer',
            'email_verified' => !empty($options['email_verified']) ? 1 : 0,
            'token' => $token,
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'profile' => !empty($profile) ? json_encode($profile) : null,
            'addresses' => !empty($addresses) ? json_encode($addresses) : null,
        ]);
        $this->syncIdentityMembership([
            'id' => $id,
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'] ?? 'customer',
            'profile' => !empty($profile) ? json_encode($profile) : null,
        ]);
        return ['id' => $id, 'token' => $token];
    }

    public function replaceRegistrationData(string $id, array $data, array $options = []) {
        $skipToken = (bool)($options['skip_verification_token'] ?? false);
        $existing = $this->getAdminUserById($id) ?: [];
        $existingProfile = $this->decodeJsonObject($existing['profile'] ?? null);
        $existingAddresses = $this->normalizeAddressesPayload($existing['addresses'] ?? null);
        $profile = $this->buildRegistrationProfile($data, $existingProfile);
        unset($profile['syntheticEmail']);
        $profile['origin'] = 'website_registration';
        $addresses = $this->normalizeAddressesPayload($data['addresses'] ?? null, $existingAddresses);
        $token = $skipToken ? null : bin2hex(random_bytes(32));

        $stmt = $this->db->prepare('
            UPDATE "User"
            SET
                name = :name,
                email = :email,
                password = :password,
                role = :role,
                email_verified = :email_verified,
                verification_token = :token,
                document_type = :document_type,
                document_number = :document_number,
                business_name = :business_name,
                profile = :profile,
                addresses = :addresses,
                otp_code = NULL,
                otp_expires_at = NULL,
                otp_attempts = 0,
                failed_login_attempts = 0,
                login_locked_until = NULL,
                active_token_id = NULL,
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => $data['role'] ?? 'customer',
            'email_verified' => !empty($options['email_verified']) ? 1 : 0,
            'token' => $token,
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'profile' => !empty($profile) ? json_encode($profile) : null,
            'addresses' => !empty($addresses) ? json_encode($addresses) : null,
        ]);
        $this->syncIdentityMembership([
            'id' => $id,
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'] ?? 'customer',
            'profile' => !empty($profile) ? json_encode($profile) : null,
        ]);

        return ['id' => $id, 'token' => $token];
    }

    public function deleteById(string $id): void {
        $stmt = $this->db->prepare('DELETE FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function createManaged(array $data) {
        $id = bin2hex(random_bytes(10));
        $stmt = $this->db->prepare('
            INSERT INTO "User" (
                id,
                tenant_id,
                name,
                email,
                password,
                role,
                email_verified,
                verification_token,
                document_type,
                document_number,
                business_name,
                profile,
                updated_at
            ) VALUES (
                :id,
                :tenant_id,
                :name,
                :email,
                :password,
                :role,
                :email_verified,
                NULL,
                :document_type,
                :document_number,
                :business_name,
                :profile,
                NOW()
            )
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role' => $data['role'],
            'email_verified' => !empty($data['email_verified']) ? 1 : 0,
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'profile' => json_encode($data['profile'] ?? (object)[]),
        ]);
        $this->syncIdentityMembership([
            'id' => $id,
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'profile' => json_encode($data['profile'] ?? (object)[]),
        ], !empty($data['email_verified']) ? 'active' : 'inactive');

        return $this->getAdminUserById($id);
    }

    public function upsertLocalSaleCustomer(array $customer): ?array {
        $name = trim((string)($customer['name'] ?? ''));
        $email = strtolower(trim((string)($customer['email'] ?? '')));
        $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        $documentType = trim((string)($customer['document_type'] ?? $customer['documentType'] ?? ''));
        $documentNumber = trim((string)($customer['document_number'] ?? $customer['documentNumber'] ?? ''));

        if ($name === '') {
            return null;
        }

        $existing = null;
        if ($documentType !== '' && strtolower($documentType) !== 'consumidor_final' && $documentNumber !== '') {
            $existing = $this->getByDocumentNumber($documentNumber);
        }

        if (!$existing && $validEmail) {
            $existing = $this->getByEmail($validEmail);
        }

        $address = $this->buildLocalSaleAddress($customer);
        $addresses = $address ? [$address] : [];

        if ($existing) {
            $existingProfile = $this->decodeJsonObject($existing['profile'] ?? null);
            $existingAddresses = $this->normalizeAddressesPayload($existing['addresses'] ?? null);
            $profile = $this->buildRegistrationProfile([
                'phone' => $customer['phone'] ?? null,
                'business_name' => $customer['business_name'] ?? null,
                'profile' => array_filter([
                    'firstName' => $customer['first_name'] ?? null,
                    'lastName' => $customer['last_name'] ?? null,
                    'origin' => 'local_pos',
                    'syntheticEmail' => $this->isLocalPosSyntheticEmail((string)($existing['email'] ?? '')) && !$validEmail,
                ], static fn ($value) => $value !== null && $value !== ''),
            ], $existingProfile);

            $emailToPersist = $validEmail ?: (string)($existing['email'] ?? '');
            if ($emailToPersist === '') {
                $emailToPersist = $this->buildSyntheticLocalPosEmail($documentNumber);
            }

            $stmt = $this->db->prepare('
                UPDATE "User"
                SET
                    name = :name,
                    email = :email,
                    document_type = :document_type,
                    document_number = :document_number,
                    business_name = :business_name,
                    profile = :profile,
                    addresses = :addresses,
                    updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant_id
            ');
            $stmt->execute([
                'id' => $existing['id'],
                'tenant_id' => $this->getTenantId(),
                'name' => $name,
                'email' => $emailToPersist,
                'document_type' => $documentType !== '' ? $documentType : ($existing['document_type'] ?? null),
                'document_number' => $documentNumber !== '' ? $documentNumber : ($existing['document_number'] ?? null),
                'business_name' => $customer['business_name'] ?? ($existing['business_name'] ?? null),
                'profile' => !empty($profile) ? json_encode($profile) : null,
                'addresses' => !empty($addresses) ? json_encode($addresses) : (!empty($existingAddresses) ? json_encode($existingAddresses) : null),
            ]);

            return $this->getAdminUserById((string)$existing['id']);
        }

        if ($validEmail === null && $documentNumber === '') {
            return null;
        }

        $profile = $this->buildRegistrationProfile([
            'phone' => $customer['phone'] ?? null,
            'business_name' => $customer['business_name'] ?? null,
            'profile' => array_filter([
                'firstName' => $customer['first_name'] ?? null,
                'lastName' => $customer['last_name'] ?? null,
                'origin' => 'local_pos',
                'syntheticEmail' => $validEmail === null,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]);

        $created = $this->create([
            'name' => $name,
            'email' => $validEmail ?: $this->buildSyntheticLocalPosEmail($documentNumber),
            'password' => bin2hex(random_bytes(24)),
            'role' => 'customer',
            'document_type' => $documentType !== '' ? $documentType : null,
            'document_number' => $documentNumber !== '' ? $documentNumber : null,
            'business_name' => $customer['business_name'] ?? null,
            'profile' => $profile,
            'addresses' => $addresses,
            'phone' => $customer['phone'] ?? null,
        ], [
            'skip_verification_token' => true,
            'email_verified' => false,
        ]);

        return $this->getAdminUserById($created['id']);
    }

    public function updateManaged(string $id, array $data) {
        $fields = [
            'name = :name',
            'email = :email',
            'role = :role',
            'email_verified = :email_verified',
            'verification_token = NULL',
            'document_type = :document_type',
            'document_number = :document_number',
            'business_name = :business_name',
            'profile = :profile',
            'updated_at = NOW()',
        ];

        $params = [
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'email_verified' => !empty($data['email_verified']) ? 1 : 0,
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'business_name' => $data['business_name'] ?? null,
            'profile' => json_encode($data['profile'] ?? (object)[]),
        ];

        if (!empty($data['password'])) {
            $fields[] = 'password = :password';
            $fields[] = 'active_token_id = :active_token_id';
            $params['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            $params['active_token_id'] = bin2hex(random_bytes(16));
        }

        $sql = sprintf(
            'UPDATE "User" SET %s WHERE id = :id AND tenant_id = :tenant_id',
            implode(', ', $fields)
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $updated = $this->getAdminUserById($id);
        if ($updated) {
            $this->syncIdentityMembership($updated, !empty($data['email_verified']) ? 'active' : 'inactive');
        }

        return $updated;
    }

    public function setManagedStatus(string $id, string $status) {
        $normalized = strtolower(trim($status));
        if (!in_array($normalized, ['active', 'inactive', 'blocked'], true)) {
            return $this->getAdminUserById($id);
        }

        if ($normalized === 'blocked') {
            $stmt = $this->db->prepare('
                UPDATE "User"
                SET failed_login_attempts = GREATEST(COALESCE(failed_login_attempts, 0), 999),
                    login_locked_until = :locked_until,
                    updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant_id
            ');
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $this->getTenantId(),
                'locked_until' => '2099-12-31 23:59:59',
            ]);

            $updated = $this->getAdminUserById($id);
            if ($updated) {
                $this->syncIdentityMembership($updated, 'blocked');
            }

            return $updated;
        }

        $stmt = $this->db->prepare('
            UPDATE "User"
            SET email_verified = :email_verified,
                failed_login_attempts = 0,
                login_locked_until = NULL,
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'email_verified' => $normalized === 'active' ? 1 : 0,
        ]);

        $updated = $this->getAdminUserById($id);
        if ($updated) {
            $this->syncIdentityMembership($updated, $normalized);
        }

        return $updated;
    }

    public function verifyToken($token) {
        $stmt = $this->db->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL WHERE verification_token = :token AND tenant_id = :tenant_id RETURNING id');
        $stmt->execute([
            'token' => $token,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function markEmailVerifiedById($id) {
        $stmt = $this->db->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        return $this->getById($id);
    }

    public function getNewUsersCount() {
        $stmt = $this->db->prepare('SELECT COUNT(*) as count FROM "User" WHERE tenant_id = :tenant_id AND created_at >= NOW() - INTERVAL \'7 days\'');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }

    public function getClientsProgress() {
        $stmtThis = $this->db->prepare('SELECT COUNT(*) FROM "User" WHERE tenant_id = :tenant_id AND created_at >= DATE_TRUNC(\'week\', NOW())');
        $stmtThis->execute(['tenant_id' => $this->getTenantId()]);
        $thisWeek = $stmtThis->fetchColumn() ?: 0;
        $stmtLast = $this->db->prepare('SELECT COUNT(*) FROM "User" WHERE tenant_id = :tenant_id AND created_at >= DATE_TRUNC(\'week\', NOW() - INTERVAL \'1 week\') AND created_at < DATE_TRUNC(\'week\', NOW())');
        $stmtLast->execute(['tenant_id' => $this->getTenantId()]);
        $lastWeek = $stmtLast->fetchColumn() ?: 0;
        
        $percentage = $lastWeek > 0
            ? (($thisWeek - $lastWeek) / $lastWeek) * 100
            : ($thisWeek > 0 ? 100 : 0);
        return [
            'current' => $thisWeek,
            'previous' => $lastWeek,
            'percentage' => round($percentage, 1)
        ];
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function syncIdentityMembership(array $user, string $status = 'active'): void {
        try {
            $this->tenantAccessService->syncUserMembership($user, TenantContext::get() ?? [], $status);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[IDENTITY_MEMBERSHIP_SYNC_FAILED] tenant=%s user=%s error=%s',
                $this->getTenantId(),
                (string)($user['id'] ?? ''),
                $e->getMessage()
            ));
        }
    }

    private function decodeJsonObject($value): array {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeAddressFields($value, array $fallback = []): array {
        $source = is_array($value) ? $value : $this->decodeJsonObject($value);
        if (!is_array($source)) {
            $source = [];
        }

        $normalizeNumeric = static function ($value) {
            if ($value === null || $value === '') {
                return null;
            }
            return is_numeric($value) ? (float)$value : null;
        };

        return [
            'firstName' => trim((string)($source['firstName'] ?? $source['first_name'] ?? ($fallback['firstName'] ?? ''))),
            'lastName' => trim((string)($source['lastName'] ?? $source['last_name'] ?? ($fallback['lastName'] ?? ''))),
            'company' => trim((string)($source['company'] ?? $source['businessName'] ?? $source['business_name'] ?? ($fallback['company'] ?? ''))),
            'country' => trim((string)($source['country'] ?? ($fallback['country'] ?? ''))),
            'street' => trim((string)($source['street'] ?? $source['address'] ?? $source['line1'] ?? $source['address1'] ?? ($fallback['street'] ?? ''))),
            'city' => trim((string)($source['city'] ?? ($fallback['city'] ?? ''))),
            'state' => trim((string)($source['state'] ?? $source['province'] ?? ($fallback['state'] ?? ''))),
            'zip' => trim((string)($source['zip'] ?? $source['postalCode'] ?? $source['postal_code'] ?? ($fallback['zip'] ?? ''))),
            'phone' => trim((string)($source['phone'] ?? $source['mobile'] ?? ($fallback['phone'] ?? ''))),
            'email' => trim((string)($source['email'] ?? ($fallback['email'] ?? ''))),
            'documentType' => trim((string)($source['documentType'] ?? $source['document_type'] ?? ($fallback['documentType'] ?? ''))),
            'documentNumber' => trim((string)($source['documentNumber'] ?? $source['document_number'] ?? ($fallback['documentNumber'] ?? ''))),
            'latitude' => $normalizeNumeric($source['latitude'] ?? $source['lat'] ?? ($fallback['latitude'] ?? null)),
            'longitude' => $normalizeNumeric($source['longitude'] ?? $source['lng'] ?? ($fallback['longitude'] ?? null)),
            'formattedAddress' => trim((string)($source['formattedAddress'] ?? $source['formatted_address'] ?? ($fallback['formattedAddress'] ?? ''))),
            'placeId' => trim((string)($source['placeId'] ?? $source['place_id'] ?? ($fallback['placeId'] ?? ''))),
            'distanceKm' => $normalizeNumeric($source['distanceKm'] ?? $source['distance_km'] ?? ($fallback['distanceKm'] ?? null)),
            'shippingZone' => trim((string)($source['shippingZone'] ?? $source['shipping_zone'] ?? ($fallback['shippingZone'] ?? ''))),
            'shippingRule' => trim((string)($source['shippingRule'] ?? $source['shipping_rule'] ?? ($fallback['shippingRule'] ?? ''))),
            'isFreeShipping' => filter_var($source['isFreeShipping'] ?? $source['is_free_shipping'] ?? ($fallback['isFreeShipping'] ?? false), FILTER_VALIDATE_BOOLEAN),
            'storeAddress' => trim((string)($source['storeAddress'] ?? $source['store_address'] ?? ($fallback['storeAddress'] ?? ''))),
            'storeLatitude' => $normalizeNumeric($source['storeLatitude'] ?? $source['store_latitude'] ?? ($fallback['storeLatitude'] ?? null)),
            'storeLongitude' => $normalizeNumeric($source['storeLongitude'] ?? $source['store_longitude'] ?? ($fallback['storeLongitude'] ?? null)),
            'freeShippingRadiusKm' => $normalizeNumeric($source['freeShippingRadiusKm'] ?? $source['free_shipping_radius_km'] ?? ($fallback['freeShippingRadiusKm'] ?? null)),
        ];
    }

    private function hasAddressData(array $address): bool {
        foreach ($address as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function normalizeAddressesPayload($value, array $fallback = []): array {
        $addresses = is_array($value) ? $value : $this->decodeJsonObject($value);
        if (!is_array($addresses) || $addresses === []) {
            return $fallback;
        }

        $normalized = [];
        foreach ($addresses as $index => $address) {
            if (!is_array($address) || $address === []) {
                continue;
            }

            $flatAddress = $this->normalizeAddressFields($address);
            $shippingAddress = $this->normalizeAddressFields($address['shipping'] ?? null);
            $billingAddress = $this->normalizeAddressFields($address['billing'] ?? null);

            if (!$this->hasAddressData($shippingAddress)) {
                $shippingAddress = $this->hasAddressData($flatAddress) ? $flatAddress : $billingAddress;
            }

            if (!$this->hasAddressData($billingAddress)) {
                $billingAddress = $this->hasAddressData($flatAddress) ? $flatAddress : $shippingAddress;
            }

            if (!$this->hasAddressData($shippingAddress) && !$this->hasAddressData($billingAddress)) {
                continue;
            }

            $explicitIsSame = $address['isSame'] ?? null;
            $isSame = is_bool($explicitIsSame)
                ? $explicitIsSame
                : (!$this->hasAddressData($billingAddress) || $shippingAddress === $billingAddress);

            if ($isSame) {
                $billingAddress = $shippingAddress;
            }

            $normalized[] = [
                'id' => $address['id'] ?? round(microtime(true) * 1000) + $index,
                'title' => trim((string)($address['title'] ?? '')) ?: ($index === 0 ? 'Dirección principal' : sprintf('Dirección %d', $index + 1)),
                'shipping' => $shippingAddress,
                'billing' => $billingAddress,
                'isSame' => $isSame,
            ];
        }

        return $normalized === [] ? $fallback : $normalized;
    }

    private function buildRegistrationProfile(array $data, array $existing = []): array {
        $profile = $existing;

        if (!empty($data['profile']) && is_array($data['profile'])) {
            $profile = array_replace_recursive($profile, $data['profile']);
        }

        $phone = trim((string)($data['phone'] ?? ($profile['phone'] ?? '')));
        if ($phone !== '') {
            $profile['phone'] = $phone;
        }

        $businessName = trim((string)($data['business_name'] ?? ($data['businessName'] ?? ($profile['businessName'] ?? ''))));
        if ($businessName !== '') {
            $profile['businessName'] = $businessName;
        }

        $documentType = trim((string)($data['document_type'] ?? ($data['documentType'] ?? ($profile['documentType'] ?? ''))));
        if ($documentType !== '') {
            $profile['documentType'] = $documentType;
        }

        $documentNumber = trim((string)($data['document_number'] ?? ($data['documentNumber'] ?? ($profile['documentNumber'] ?? ''))));
        if ($documentNumber !== '') {
            $profile['documentNumber'] = $documentNumber;
        }

        return $profile;
    }

    private function isLocalPosSyntheticEmail(string $email): bool {
        $normalized = strtolower(trim($email));
        return $normalized !== '' && str_ends_with($normalized, '@local-pos.invalid');
    }

    private function buildSyntheticLocalPosEmail(string $documentNumber = ''): string {
        $base = preg_replace('/[^a-z0-9]+/i', '', strtolower($documentNumber));
        $base = is_string($base) ? $base : '';
        if ($base === '') {
            $base = bin2hex(random_bytes(6));
        }

        $candidate = sprintf('local-pos+%s@local-pos.invalid', $base);
        while ($this->emailExists($candidate)) {
            $candidate = sprintf('local-pos+%s-%s@local-pos.invalid', $base, strtolower(bin2hex(random_bytes(2))));
        }

        return $candidate;
    }

    private function buildLocalSaleAddress(array $customer): ?array {
        $firstName = trim((string)($customer['first_name'] ?? ''));
        $lastName = trim((string)($customer['last_name'] ?? ''));
        $email = trim((string)($customer['email'] ?? ''));
        $phone = trim((string)($customer['phone'] ?? ''));
        $street = trim((string)($customer['street'] ?? ''));
        $city = trim((string)($customer['city'] ?? ''));
        $documentType = trim((string)($customer['document_type'] ?? $customer['documentType'] ?? ''));
        $documentNumber = trim((string)($customer['document_number'] ?? $customer['documentNumber'] ?? ''));

        if ($street === '' && $city === '' && $phone === '' && $email === '') {
            return null;
        }

        $base = [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'street' => $street !== '' ? $street : null,
            'city' => $city !== '' ? $city : null,
            'state' => null,
            'country' => 'EC',
            'zip' => null,
            'documentType' => $documentType !== '' ? $documentType : null,
            'documentNumber' => $documentNumber !== '' ? $documentNumber : null,
        ];

        return [
            'id' => (string) round(microtime(true) * 1000),
            'title' => 'Dirección principal',
            'billing' => $base,
            'shipping' => $base,
            'isSame' => true,
        ];
    }
}
