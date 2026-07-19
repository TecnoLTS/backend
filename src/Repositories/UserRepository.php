<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use App\Modules\Commerce\Domain\CommerceDomain;
use PDOStatement;

class UserRepository {
    private const PLATFORM_TENANT_ID = 'platform';

    protected $db;
    private TenantAccessService $tenantAccessService;
    private string $userTable;
    private string $securityTable;
    private bool $syncMemberships;

    public function __construct(
        string $moduleKey = IdentityPlatformDomain::KEY,
        string $userTable = '"User"',
        string $securityTable = '"AuthSecurityEvent"',
        bool $syncMemberships = true
    ) {
        $this->db = Database::getModuleInstance($moduleKey);
        $this->tenantAccessService = new TenantAccessService();
        $this->userTable = $userTable;
        $this->securityTable = $securityTable;
        $this->syncMemberships = $syncMemberships;
    }

    protected function prepare(string $sql): PDOStatement {
        return $this->db->prepare($this->rewriteSql($sql));
    }

    protected function rewriteSql(string $sql): string {
        if ($this->userTable !== '"User"') {
            $sql = str_replace('"User"', $this->userTable, $sql);
        }
        if ($this->securityTable !== '"AuthSecurityEvent"') {
            $sql = str_replace('"AuthSecurityEvent"', $this->securityTable, $sql);
        }

        // Customer identities live entirely in ecommerce. Membership state is
        // owned by IdentityPlatform/dashboard and must never be queried from a
        // customer connection (the ecommerce role deliberately has no access
        // to that foreign table). Customer activation is represented by the
        // local Customer row and email_verified flag.
        if (!$this->syncMemberships) {
            $sql = preg_replace(
                '/\(SELECT\s+membership\.status\s+FROM\s+tenant_memberships\s+membership\s+WHERE\s+membership\.tenant_id\s*=\s*[^\n]+\s+AND\s+membership\.user_id\s*=\s*[^\n]+\s+LIMIT\s+1\)\s+AS\s+account_status/mi',
                "'active' AS account_status",
                $sql
            ) ?? $sql;
        }

        return $sql;
    }

    public function getPage(array $options = []) {
        $limit = filter_var($options['limit'] ?? 100, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 101],
        ]);
        $offset = filter_var($options['offset'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 4900],
        ]);
        $safeLimit = $limit === false ? 100 : (int)$limit;
        $safeOffset = $offset === false ? 0 : (int)$offset;
        $cursor = is_array($options['cursor'] ?? null) ? $options['cursor'] : null;
        $search = trim((string)($options['search'] ?? ''));
        if (mb_strlen($search) > 190) {
            $search = mb_substr($search, 0, 190);
        }
        $role = strtolower(trim((string)($options['role'] ?? 'all')));
        $roleIdentitySql = "COALESCE("
            . "NULLIF(LOWER(COALESCE(u.profile->>'identityType', u.profile->>'identity_type', '')), ''), "
            . "CASE WHEN LOWER(COALESCE(u.role, 'customer')) IN ('admin', 'service') THEN 'admin' ELSE 'customer' END"
            . ")";
        $roleFilterSql = match ($role) {
            'customer' => " AND {$roleIdentitySql} = 'customer'",
            'admin' => " AND {$roleIdentitySql} <> 'customer'",
            default => '',
        };
        $cursorFilterSql = $cursor !== null
            ? ' AND (u.created_at, u.id) < (:cursor_created_at, :cursor_id)'
            : '';
        $legacyOffsetSql = $cursor === null && $safeOffset > 0 ? ' OFFSET :offset' : '';

        $stmt = $this->prepare('
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
                security_block.metadata AS security_block_metadata
            FROM "User" u
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
              AND (
                :search_empty = TRUE
                OR LOWER(CONCAT_WS(\' \',
                    COALESCE(u.name, \'\'),
                    COALESCE(u.email, \'\'),
                    COALESCE(u.document_number, \'\'),
                    COALESCE(u.business_name, \'\'),
                    COALESCE(u.profile::text, \'\'),
                    COALESCE(u.addresses::text, \'\')
                )) LIKE :search_like
              )' . $roleFilterSql . $cursorFilterSql . '
            ORDER BY u.created_at DESC, u.id DESC
            LIMIT :limit
            ' . $legacyOffsetSql . '
        ');
        $tenantId = $this->getTenantId();
        $stmt->bindValue(':tenant_id_security', $tenantId, \PDO::PARAM_STR);
        $stmt->bindValue(':tenant_id_users', $tenantId, \PDO::PARAM_STR);
        $stmt->bindValue(':search_empty', $search === '', \PDO::PARAM_BOOL);
        $stmt->bindValue(':search_like', '%' . strtolower($search) . '%', \PDO::PARAM_STR);
        if ($cursor !== null) {
            $stmt->bindValue(':cursor_created_at', (string)$cursor['createdAt'], \PDO::PARAM_STR);
            $stmt->bindValue(':cursor_id', (string)$cursor['id'], \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $safeLimit, \PDO::PARAM_INT);
        if ($legacyOffsetSql !== '') {
            $stmt->bindValue(':offset', $safeOffset, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $users = $stmt->fetchAll();
        if (!is_array($users) || $users === []) {
            return [];
        }

        $userIds = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['id'] ?? '')),
            $users
        )));
        $orderStats = [];
        if ($userIds !== []) {
            $commerce = Database::getModuleInstance(CommerceDomain::KEY);
            $placeholders = [];
            $params = [
                'tenant_id_orders' => $tenantId,
                'tenant_id_latest' => $tenantId,
            ];
            foreach ($userIds as $index => $userId) {
                $key = 'user_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $userId;
            }
            $orders = $commerce->prepare('
                SELECT
                    stats.user_id,
                    stats.orders_total::int AS orders_total,
                    stats.orders_active::int AS orders_active,
                    stats.orders_completed::int AS orders_completed,
                    stats.total_spent::numeric(12,2) AS total_spent,
                    stats.last_order_at,
                    stats.last_order_id,
                    latest.shipping_address AS last_shipping_address,
                    latest.billing_address AS last_billing_address
                FROM (
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
                      AND o.user_id IN (' . implode(', ', $placeholders) . ')
                    GROUP BY o.user_id
                ) stats
                LEFT JOIN "Order" latest
                  ON latest.id = stats.last_order_id
                 AND latest.tenant_id = :tenant_id_latest
            ');
            $orders->execute($params);
            foreach ($orders->fetchAll() ?: [] as $row) {
                $orderStats[(string)$row['user_id']] = $row;
            }
        }

        foreach ($users as &$user) {
            $stats = $orderStats[(string)($user['id'] ?? '')] ?? [];
            $user['orders_total'] = (int)($stats['orders_total'] ?? 0);
            $user['orders_active'] = (int)($stats['orders_active'] ?? 0);
            $user['orders_completed'] = (int)($stats['orders_completed'] ?? 0);
            $user['total_spent'] = $stats['total_spent'] ?? 0;
            $user['last_order_at'] = $stats['last_order_at'] ?? null;
            $user['last_order_id'] = $stats['last_order_id'] ?? null;
            $user['last_shipping_address'] = $stats['last_shipping_address'] ?? null;
            $user['last_billing_address'] = $stats['last_billing_address'] ?? null;
        }
        unset($user);

        return $users;
    }

    /**
     * @deprecated HTTP list handlers must call getPage() explicitly.
     */
    public function getAll(array $options = []) {
        return $this->getPage($options);
    }

    public function getByEmail($email) {
        $email = strtolower(trim((string)$email));
        $stmt = $this->prepare('
            SELECT "User".id, "User".tenant_id, "User".name, "User".email, "User".password,
                   "User".email_verified, "User".role, "User".document_type,
                   "User".document_number, "User".business_name, "User".profile,
                   "User".addresses, "User".failed_login_attempts, "User".login_locked_until,
                   (SELECT membership.status
                    FROM tenant_memberships membership
                    WHERE membership.tenant_id = "User".tenant_id
                      AND membership.user_id = "User".id
                    LIMIT 1) AS account_status
            FROM "User"
            WHERE (
                lower(email) = :email
                OR (
                    jsonb_typeof(COALESCE(profile, \'{}\'::jsonb)->\'loginAliases\') = \'array\'
                    AND jsonb_exists(COALESCE(profile, \'{}\'::jsonb)->\'loginAliases\', :email)
                )
            )
              AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId(),
        ]);
        $tenantUser = $stmt->fetch();
        return $tenantUser ?: $this->readPlatformAuthentication('lookup_login', ['email' => $email]);
    }

    public function getByEmailWithOtp($email) {
        $email = strtolower(trim((string)$email));
        $stmt = $this->prepare('
            SELECT "User".id, "User".tenant_id, "User".name, "User".email, "User".password,
                   "User".email_verified, "User".role, "User".document_type,
                   "User".document_number, "User".business_name, "User".profile,
                   "User".addresses, "User".otp_code, "User".otp_expires_at,
                   "User".otp_attempts, "User".failed_login_attempts, "User".login_locked_until,
                   (SELECT membership.status
                    FROM tenant_memberships membership
                    WHERE membership.tenant_id = "User".tenant_id
                      AND membership.user_id = "User".id
                    LIMIT 1) AS account_status
            FROM "User"
            WHERE (
                lower(email) = :email
                OR (
                    jsonb_typeof(COALESCE(profile, \'{}\'::jsonb)->\'loginAliases\') = \'array\'
                    AND jsonb_exists(COALESCE(profile, \'{}\'::jsonb)->\'loginAliases\', :email)
                )
            )
              AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId(),
        ]);
        $tenantUser = $stmt->fetch();
        return $tenantUser ?: $this->readPlatformAuthentication('lookup_login_otp', ['email' => $email]);
    }

    public function getById($id) {
        $stmt = $this->prepare('
            SELECT
                id,
                tenant_id,
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
            WHERE id = :id
              AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
        ]);
        $tenantUser = $stmt->fetch();
        return $tenantUser ?: $this->readPlatformAuthentication('get_identity', ['id' => $id]);
    }

    public function getAdminUserById($id) {
        $stmt = $this->prepare('
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

        $stmt = $this->prepare('
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
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    public function getAuthState($id) {
        $stmt = $this->prepare('
            SELECT "User".id, "User".tenant_id, "User".name, "User".email,
                   "User".role, "User".profile, "User".active_token_id,
                   (SELECT membership.status
                    FROM tenant_memberships membership
                    WHERE membership.tenant_id = "User".tenant_id
                      AND membership.user_id = "User".id
                    LIMIT 1) AS account_status
            FROM "User"
            WHERE id = :id
              AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
        ]);
        $tenantState = $stmt->fetch();
        return $tenantState ?: $this->readPlatformAuthentication('get_auth_state', ['id' => $id]);
    }

    public function getAddresses($userId) {
        $stmt = $this->prepare('SELECT addresses FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
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
        $stmt = $this->prepare('UPDATE "User" SET addresses = :addresses, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'addresses' => json_encode($normalizedAddresses)
        ]);
        return $this->getAddresses($userId);
    }

    public function getProfile($userId) {
        $stmt = $this->prepare('SELECT name, email, profile, document_type, document_number, business_name FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
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
        $stmt = $this->prepare('UPDATE "User" SET name = :name, profile = :profile, document_type = :document_type, document_number = :document_number, business_name = :business_name, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
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
        $stmt = $this->prepare('
            SELECT password
            FROM "User"
            WHERE id = :id
              AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
        ]);
        $row = $stmt->fetch();
        return $row ? $row['password'] : $this->readPlatformAuthentication('get_password_hash', ['id' => $userId]);
    }

    public function updatePassword($userId, $newPasswordHash, $newTokenId) {
        $stmt = $this->prepare('
            UPDATE "User"
            SET password = :password, active_token_id = :token_id, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'password' => $newPasswordHash,
            'token_id' => $newTokenId
        ]);
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('update_password', [
                'id' => (string)$userId,
                'password' => (string)$newPasswordHash,
                'token_id' => (string)$newTokenId,
            ]);
        }
        $this->revokeRelationalSessions((string)$userId);
    }

    public function resetPasswordAfterRecovery(string $userId, string $newPasswordHash, string $newTokenId): void {
        $stmt = $this->prepare('
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
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('reset_password', [
                'id' => $userId,
                'password' => $newPasswordHash,
                'token_id' => $newTokenId,
            ]);
        }
        $this->revokeRelationalSessions($userId);
    }

    public function setOtpForEmail($email, $code, $expiresAt) {
        $stmt = $this->prepare('
            UPDATE "User"
            SET otp_code = :code, otp_expires_at = :expires_at, otp_attempts = 0, updated_at = NOW()
            WHERE LOWER(email) = LOWER(:email) AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'email' => $email,
            'tenant_id' => $this->getTenantId(),
            'code' => $code,
            'expires_at' => $expiresAt
        ]);
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('set_otp', [
                'email' => strtolower(trim((string)$email)),
                'code' => (string)$code,
                'expires_at' => (string)$expiresAt,
            ]);
        }
    }

    public function markEmailVerifiedByOtp($userId) {
        $stmt = $this->prepare('
            UPDATE "User"
            SET email_verified = TRUE, verification_token = NULL, otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
        ]);
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('verify_otp', ['id' => (string)$userId]);
        }
        return $this->getById($userId);
    }

    public function incrementOtpAttempts($userId) {
        $stmt = $this->prepare('
            UPDATE "User"
            SET otp_attempts = COALESCE(otp_attempts, 0) + 1
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
        ]);
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('increment_otp_attempts', ['id' => (string)$userId]);
        }
    }

    public function setLoginFailureState(string $userId, int $attempts, ?string $lockedUntil): void {
        $stmt = $this->prepare('
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
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('set_login_failure', [
                'id' => $userId,
                'attempts' => max(0, $attempts),
                'locked_until' => $lockedUntil,
            ]);
        }
    }

    public function clearLoginFailures(string $userId): void {
        $stmt = $this->prepare('
            UPDATE "User"
            SET failed_login_attempts = 0,
                login_locked_until = NULL,
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
        ]);
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('clear_login_failures', ['id' => $userId]);
        }
    }

    public function unlockManagedUser(string $userId) {
        $this->clearLoginFailures($userId);
        return $this->getAdminUserById($userId);
    }

    public function markSuccessfulLogin(string $userId): void {
        $stmt = $this->prepare('
            UPDATE "User"
            SET failed_login_attempts = 0,
                login_locked_until = NULL,
                last_login_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
        ]);
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('mark_successful_login', ['id' => $userId]);
        }
    }

    public function setActiveTokenId($userId, $tokenId) {
        $stmt = $this->prepare('
            UPDATE "User"
            SET active_token_id = :tokenId, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
            'tokenId' => $tokenId
        ]);
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('set_active_token', [
                'id' => (string)$userId,
                'token_id' => (string)$tokenId,
            ]);
        }
    }

    public function registerSession(
        string $userId,
        string $sessionId,
        int $expiresAt,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        if (!$this->usesRelationalDashboardSessions()) {
            return;
        }
        $stmt = $this->prepare('
            INSERT INTO tenant_user_sessions (
                tenant_id, user_id, session_id, auth_surface, ip_address,
                user_agent, expires_at, revoked_at, created_at, last_seen_at
            ) VALUES (
                :tenant_id, :user_id, :session_id, \'dashboard\', :ip_address,
                :user_agent, :expires_at, NULL, NOW(), NOW()
            )
            ON CONFLICT (tenant_id, user_id, session_id) DO UPDATE SET
                ip_address = EXCLUDED.ip_address,
                user_agent = EXCLUDED.user_agent,
                expires_at = EXCLUDED.expires_at,
                revoked_at = NULL,
                last_seen_at = NOW()
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'user_id' => trim($userId),
            'session_id' => trim($sessionId),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
        ]);
    }

    public function relationalSessionIsActive(string $userId, string $sessionId): ?bool {
        if (!$this->usesRelationalDashboardSessions()) {
            return null;
        }
        try {
            $stmt = $this->prepare('
                SELECT revoked_at IS NULL AND expires_at > NOW() AS active
                FROM tenant_user_sessions
                WHERE tenant_id = :tenant_id AND user_id = :user_id AND session_id = :session_id
                LIMIT 1
            ');
            $stmt->execute([
                'tenant_id' => $this->getTenantId(),
                'user_id' => trim($userId),
                'session_id' => trim($sessionId),
            ]);
            $active = $stmt->fetchColumn();
            if ($active !== false) {
                return in_array($active, [true, 1, '1', 't', 'true'], true);
            }

            $existing = $this->prepare('
                SELECT 1 FROM tenant_user_sessions
                WHERE tenant_id = :tenant_id AND user_id = :user_id
                LIMIT 1
            ');
            $existing->execute([
                'tenant_id' => $this->getTenantId(),
                'user_id' => trim($userId),
            ]);
            // Sin filas aún se acepta el active_token_id previo al despliegue.
            return $existing->fetchColumn() === false ? null : false;
        } catch (\PDOException $e) {
            if ((string)$e->getCode() === '42P01') {
                return null;
            }
            throw $e;
        }
    }

    public function refreshSessionExpiry(string $userId, string $sessionId, int $expiresAt): void {
        if (!$this->usesRelationalDashboardSessions()) {
            return;
        }
        $stmt = $this->prepare('
            UPDATE tenant_user_sessions
            SET expires_at = :expires_at, last_seen_at = NOW()
            WHERE tenant_id = :tenant_id
              AND user_id = :user_id
              AND session_id = :session_id
              AND revoked_at IS NULL
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'user_id' => trim($userId),
            'session_id' => trim($sessionId),
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
        ]);
    }

    public function revokeSession(string $userId, string $sessionId): int {
        if (!$this->usesRelationalDashboardSessions()) {
            $this->clearActiveTokenId($userId);
            return 1;
        }
        $stmt = $this->prepare('
            UPDATE tenant_user_sessions
            SET revoked_at = COALESCE(revoked_at, NOW()), last_seen_at = NOW()
            WHERE tenant_id = :tenant_id
              AND user_id = :user_id
              AND session_id = :session_id
              AND revoked_at IS NULL
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'user_id' => trim($userId),
            'session_id' => trim($sessionId),
        ]);
        $revoked = $stmt->rowCount();
        if ($revoked < 1) {
            // Compatibilidad de rollout: un JWT emitido antes de crear la tabla
            // solo vive en active_token_id y también debe quedar invalidado.
            $this->clearActiveTokenIdIfMatches($userId, $sessionId);
        }
        return $revoked;
    }

    public function revokeOtherSessions(string $userId, string $currentSessionId): int {
        if (!$this->usesRelationalDashboardSessions()) {
            return 0;
        }
        $stmt = $this->prepare('
            UPDATE tenant_user_sessions
            SET revoked_at = COALESCE(revoked_at, NOW()), last_seen_at = NOW()
            WHERE tenant_id = :tenant_id
              AND user_id = :user_id
              AND session_id <> :current_session_id
              AND revoked_at IS NULL
              AND expires_at > NOW()
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'user_id' => trim($userId),
            'current_session_id' => trim($currentSessionId),
        ]);
        return $stmt->rowCount();
    }

    public function sessionSummary(string $userId, string $currentSessionId): array {
        if (!$this->usesRelationalDashboardSessions()) {
            $active = $this->getActiveTokenId($userId);
            $currentActive = $active !== null && $active !== '' && hash_equals((string)$active, $currentSessionId);
            return [
                'currentSessionActive' => $currentActive,
                'activeSessions' => $currentActive ? 1 : 0,
                'otherActiveSessions' => 0,
                'currentExpiresAt' => null,
            ];
        }

        $stmt = $this->prepare('
            SELECT
                COUNT(*) FILTER (WHERE revoked_at IS NULL AND expires_at > NOW())::int AS active_sessions,
                COUNT(*) FILTER (
                    WHERE revoked_at IS NULL AND expires_at > NOW() AND session_id <> :current_session_count
                )::int AS other_active_sessions,
                BOOL_OR(
                    session_id = :current_session_active AND revoked_at IS NULL AND expires_at > NOW()
                ) AS current_active,
                MAX(expires_at) FILTER (WHERE session_id = :current_session_expiry) AS current_expires_at
            FROM tenant_user_sessions
            WHERE tenant_id = :tenant_id AND user_id = :user_id
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'user_id' => trim($userId),
            'current_session_count' => trim($currentSessionId),
            'current_session_active' => trim($currentSessionId),
            'current_session_expiry' => trim($currentSessionId),
        ]);
        $row = $stmt->fetch() ?: [];
        $activeSessions = (int)($row['active_sessions'] ?? 0);
        if ($activeSessions === 0 && $this->relationalSessionIsActive($userId, $currentSessionId) === null) {
            $legacyActive = (string)($this->getActiveTokenId($userId) ?? '') === trim($currentSessionId);
            return [
                'currentSessionActive' => $legacyActive,
                'activeSessions' => $legacyActive ? 1 : 0,
                'otherActiveSessions' => 0,
                'currentExpiresAt' => null,
            ];
        }

        $expiresAt = strtotime((string)($row['current_expires_at'] ?? ''));
        return [
            'currentSessionActive' => in_array($row['current_active'] ?? false, [true, 1, '1', 't', 'true'], true),
            'activeSessions' => $activeSessions,
            'otherActiveSessions' => (int)($row['other_active_sessions'] ?? 0),
            'currentExpiresAt' => $expiresAt !== false ? gmdate('c', $expiresAt) : null,
        ];
    }

    public function clearActiveTokenId($userId) {
        $stmt = $this->prepare('
            UPDATE "User"
            SET active_token_id = NULL, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
        ]);
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('clear_active_token', [
                'id' => (string)$userId,
                'expected_token_id' => null,
            ]);
        }
    }

    public function revokeSessions(string $userId): void {
        $this->clearActiveTokenId($userId);
        $this->revokeRelationalSessions($userId);
    }

    public function markManagedEmailVerified(string $userId): void {
        $stmt = $this->prepare('
            UPDATE "User"
            SET email_verified = TRUE, verification_token = NULL, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
        ]);
    }

    public function getActiveTokenId($userId) {
        $stmt = $this->prepare('
            SELECT active_token_id
            FROM "User"
            WHERE id = :id
              AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId(),
        ]);
        $row = $stmt->fetch();
        if ($row) {
            return $row['active_token_id'];
        }
        $platformState = $this->readPlatformAuthentication('get_auth_state', ['id' => $userId]);
        return is_array($platformState) ? ($platformState['active_token_id'] ?? null) : null;
    }

    public function create($data, $options = []) {
        $skipToken = (bool)($options['skip_verification_token'] ?? false);
        $profile = $this->buildRegistrationProfile($data);
        $addresses = $this->normalizeAddressesPayload($data['addresses'] ?? null);
        $sql = 'INSERT INTO "User" (id, tenant_id, name, email, password, role, email_verified, updated_at, verification_token, document_type, document_number, business_name, profile, addresses) VALUES (:id, :tenant_id, :name, :email, :password, :role, :email_verified, NOW(), :token, :document_type, :document_number, :business_name, :profile, :addresses)';
        $stmt = $this->prepare($sql);
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

        $stmt = $this->prepare('
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
        $stmt = $this->prepare('DELETE FROM "User" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
    }

    public function createManaged(array $data) {
        $id = bin2hex(random_bytes(10));
        $stmt = $this->prepare('
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
        $membershipProfile = is_array($data['profile'] ?? null) ? $data['profile'] : [];
        if (is_array($data['roleIds'] ?? null)) {
            // La asignación efectiva se persiste en tenant_user_roles. Este valor
            // solo evita que el alta transitoria caiga en el rol admin por el
            // campo legacy User.role antes de que el controlador reconcilie roles.
            $membershipProfile['roleIds'] = $data['roleIds'];
        }
        $this->syncIdentityMembership([
            'id' => $id,
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'profile' => json_encode($membershipProfile ?: (object)[]),
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

            $stmt = $this->prepare('
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
        $stmt = $this->prepare($sql);
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
            $stmt = $this->prepare('
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

        $stmt = $this->prepare('
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
        $stmt = $this->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL WHERE verification_token = :token AND tenant_id = :tenant_id RETURNING id');
        $stmt->execute([
            'token' => $token,
            'tenant_id' => $this->getTenantId()
        ]);
        return $stmt->fetch();
    }

    public function markEmailVerifiedById($id) {
        $stmt = $this->prepare('UPDATE "User" SET email_verified = TRUE, verification_token = NULL WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        return $this->getById($id);
    }

    public function getNewUsersCount() {
        $stmt = $this->prepare('SELECT COUNT(*) as count FROM "User" WHERE tenant_id = :tenant_id AND created_at >= NOW() - INTERVAL \'7 days\'');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return $result['count'] ?? 0;
    }

    public function getClientsProgress() {
        $stmtThis = $this->prepare('SELECT COUNT(*) FROM "User" WHERE tenant_id = :tenant_id AND created_at >= DATE_TRUNC(\'week\', NOW())');
        $stmtThis->execute(['tenant_id' => $this->getTenantId()]);
        $thisWeek = $stmtThis->fetchColumn() ?: 0;
        $stmtLast = $this->prepare('SELECT COUNT(*) FROM "User" WHERE tenant_id = :tenant_id AND created_at >= DATE_TRUNC(\'week\', NOW() - INTERVAL \'1 week\') AND created_at < DATE_TRUNC(\'week\', NOW())');
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

    /**
     * Read a global platform identity only after the tenant-scoped SELECT
     * returned no row. FORCE RLS callers use narrowly typed SECURITY DEFINER
     * functions; non-RLS development keeps an explicit platform-only query.
     *
     * @return array<string, mixed>|string|null
     */
    private function readPlatformAuthentication(string $operation, array $params) {
        if ($this->userTable !== '"User"') {
            return null;
        }

        $rlsMode = strtolower(trim((string)($_ENV['TENANT_RLS_MODE'] ?? getenv('TENANT_RLS_MODE') ?: 'off')));
        $capabilitySql = [
            'lookup_login' => 'SELECT platform_auth.lookup_login_candidate(:email, FALSE)',
            'lookup_login_otp' => 'SELECT platform_auth.lookup_login_candidate(:email, TRUE)',
            'get_identity' => 'SELECT platform_auth.get_identity(:id)',
            'get_auth_state' => 'SELECT platform_auth.get_auth_state(:id)',
            'get_password_hash' => 'SELECT platform_auth.get_password_hash(:id)',
        ];
        $legacySql = [
            'lookup_login' => '
                SELECT id, tenant_id, name, email, password, email_verified, role,
                       document_type, document_number, business_name, profile, addresses,
                       failed_login_attempts, login_locked_until, NULL AS otp_code,
                       NULL AS otp_expires_at, NULL AS otp_attempts, \'active\' AS account_status
                FROM "User"
                WHERE tenant_id = :platform_tenant_id
                  AND (
                      lower(email) = :email
                      OR (
                          jsonb_typeof(COALESCE(profile, \'{}\'::jsonb)->\'loginAliases\') = \'array\'
                          AND jsonb_exists(COALESCE(profile, \'{}\'::jsonb)->\'loginAliases\', :email)
                      )
                  )
                ORDER BY id
                LIMIT 1
            ',
            'lookup_login_otp' => '
                SELECT id, tenant_id, name, email, password, email_verified, role,
                       document_type, document_number, business_name, profile, addresses,
                       failed_login_attempts, login_locked_until, otp_code, otp_expires_at,
                       otp_attempts, \'active\' AS account_status
                FROM "User"
                WHERE tenant_id = :platform_tenant_id
                  AND (
                      lower(email) = :email
                      OR (
                          jsonb_typeof(COALESCE(profile, \'{}\'::jsonb)->\'loginAliases\') = \'array\'
                          AND jsonb_exists(COALESCE(profile, \'{}\'::jsonb)->\'loginAliases\', :email)
                      )
                  )
                ORDER BY id
                LIMIT 1
            ',
            'get_identity' => '
                SELECT id, tenant_id, name, email, role, email_verified, document_type,
                       document_number, business_name, profile, addresses,
                       failed_login_attempts, login_locked_until
                FROM "User"
                WHERE tenant_id = :platform_tenant_id AND id = :id
                LIMIT 1
            ',
            'get_auth_state' => '
                SELECT id, tenant_id, name, email, role, profile, active_token_id,
                       \'active\' AS account_status
                FROM "User"
                WHERE tenant_id = :platform_tenant_id AND id = :id
                LIMIT 1
            ',
            'get_password_hash' => '
                SELECT password
                FROM "User"
                WHERE tenant_id = :platform_tenant_id AND id = :id
                LIMIT 1
            ',
        ];

        if (!isset($capabilitySql[$operation], $legacySql[$operation])) {
            throw new \LogicException('Unsupported platform authentication read.');
        }

        if ($rlsMode !== 'enforce') {
            $legacyParams = $params + ['platform_tenant_id' => self::PLATFORM_TENANT_ID];
            $stmt = $this->db->prepare($legacySql[$operation]);
            $stmt->execute($legacyParams);
            if ($operation === 'get_password_hash') {
                $value = $stmt->fetchColumn();
                return $value === false ? null : (string)$value;
            }
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        }

        $stmt = $this->db->prepare($capabilitySql[$operation]);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        if ($operation === 'get_password_hash') {
            return $value === false || $value === null ? null : (string)$value;
        }
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        try {
            $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Invalid platform authentication capability payload.', 0, $exception);
        }
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Mutate a global platform identity only after the tenant-scoped UPDATE
     * affected zero rows. Under FORCE RLS this invokes the narrow SQL
     * capability owned by a dedicated NOLOGIN/BYPASSRLS role. In non-RLS
     * development mode the same operation remains explicit and platform-only;
     * the broad `(tenant OR platform)` UPDATE contract is never used.
     */
    private function mutatePlatformAuthentication(string $operation, array $params): bool {
        if ($this->userTable !== '"User"') {
            return false;
        }

        $rlsMode = strtolower(trim((string)($_ENV['TENANT_RLS_MODE'] ?? getenv('TENANT_RLS_MODE') ?: 'off')));
        $capabilitySql = [
            'update_password' => 'SELECT platform_auth.update_password(:id, :password, :token_id)',
            'reset_password' => 'SELECT platform_auth.reset_password(:id, :password, :token_id)',
            'set_otp' => 'SELECT platform_auth.set_otp(:email, :code, CAST(:expires_at AS timestamp without time zone))',
            'verify_otp' => 'SELECT platform_auth.verify_otp(:id)',
            'increment_otp_attempts' => 'SELECT platform_auth.increment_otp_attempts(:id)',
            'set_login_failure' => 'SELECT platform_auth.set_login_failure(:id, CAST(:attempts AS integer), CAST(:locked_until AS timestamp without time zone))',
            'clear_login_failures' => 'SELECT platform_auth.clear_login_failures(:id)',
            'mark_successful_login' => 'SELECT platform_auth.mark_successful_login(:id)',
            'set_active_token' => 'SELECT platform_auth.set_active_token(:id, :token_id)',
            'clear_active_token' => 'SELECT platform_auth.clear_active_token(:id, CAST(:expected_token_id AS text))',
        ];
        $legacySql = [
            'update_password' => '
                UPDATE "User"
                SET password = :password, active_token_id = :token_id, updated_at = NOW()
                WHERE id = :id AND tenant_id = \'platform\'
            ',
            'reset_password' => '
                UPDATE "User"
                SET password = :password,
                    active_token_id = :token_id,
                    failed_login_attempts = 0,
                    login_locked_until = NULL,
                    otp_code = NULL,
                    otp_expires_at = NULL,
                    otp_attempts = 0,
                    updated_at = NOW()
                WHERE id = :id AND tenant_id = \'platform\'
            ',
            'set_otp' => '
                UPDATE "User"
                SET otp_code = :code, otp_expires_at = :expires_at, otp_attempts = 0, updated_at = NOW()
                WHERE tenant_id = \'platform\'
                  AND id = (
                      SELECT id FROM "User"
                      WHERE tenant_id = \'platform\' AND LOWER(email) = LOWER(:email)
                      ORDER BY id LIMIT 1
                  )
            ',
            'verify_otp' => '
                UPDATE "User"
                SET email_verified = TRUE, verification_token = NULL,
                    otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0, updated_at = NOW()
                WHERE id = :id AND tenant_id = \'platform\'
            ',
            'increment_otp_attempts' => '
                UPDATE "User"
                SET otp_attempts = COALESCE(otp_attempts, 0) + 1, updated_at = NOW()
                WHERE id = :id AND tenant_id = \'platform\'
            ',
            'set_login_failure' => '
                UPDATE "User"
                SET failed_login_attempts = :attempts, login_locked_until = :locked_until, updated_at = NOW()
                WHERE id = :id AND tenant_id = \'platform\'
            ',
            'clear_login_failures' => '
                UPDATE "User"
                SET failed_login_attempts = 0, login_locked_until = NULL, updated_at = NOW()
                WHERE id = :id AND tenant_id = \'platform\'
            ',
            'mark_successful_login' => '
                UPDATE "User"
                SET failed_login_attempts = 0, login_locked_until = NULL,
                    last_login_at = NOW(), updated_at = NOW()
                WHERE id = :id AND tenant_id = \'platform\'
            ',
            'set_active_token' => '
                UPDATE "User"
                SET active_token_id = :token_id, updated_at = NOW()
                WHERE id = :id AND tenant_id = \'platform\'
            ',
            'clear_active_token' => '
                WITH expected AS (SELECT CAST(:expected_token_id AS text) AS token_id)
                UPDATE "User"
                SET active_token_id = NULL, updated_at = NOW()
                FROM expected
                WHERE id = :id AND tenant_id = \'platform\'
                  AND (expected.token_id IS NULL OR active_token_id = expected.token_id)
            ',
        ];

        if (!isset($capabilitySql[$operation], $legacySql[$operation])) {
            throw new \LogicException('Unsupported platform authentication mutation.');
        }

        $stmt = $this->db->prepare($rlsMode === 'enforce' ? $capabilitySql[$operation] : $legacySql[$operation]);
        $stmt->execute($params);
        if ($rlsMode !== 'enforce') {
            return $stmt->rowCount() === 1;
        }

        return in_array($stmt->fetchColumn(), [true, 1, '1', 't', 'true'], true);
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function usesRelationalDashboardSessions(): bool {
        return $this->userTable === '"User"'
            && strtolower((string)$this->getTenantId()) === 'fidepuntos';
    }

    private function revokeRelationalSessions(string $userId): void {
        if (!$this->usesRelationalDashboardSessions()) {
            return;
        }
        try {
            $stmt = $this->prepare('
                UPDATE tenant_user_sessions
                SET revoked_at = COALESCE(revoked_at, NOW()), last_seen_at = NOW()
                WHERE tenant_id = :tenant_id AND user_id = :user_id AND revoked_at IS NULL
            ');
            $stmt->execute([
                'tenant_id' => $this->getTenantId(),
                'user_id' => trim($userId),
            ]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode() !== '42P01') {
                throw $e;
            }
        }
    }

    private function clearActiveTokenIdIfMatches(string $userId, string $sessionId): void {
        if (trim($sessionId) === '') {
            return;
        }
        $stmt = $this->prepare('
            UPDATE "User"
            SET active_token_id = NULL, updated_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND active_token_id = :session_id
        ');
        $stmt->execute([
            'id' => trim($userId),
            'tenant_id' => $this->getTenantId(),
            'session_id' => trim($sessionId),
        ]);
        if ($stmt->rowCount() === 0) {
            $this->mutatePlatformAuthentication('clear_active_token', [
                'id' => trim($userId),
                'expected_token_id' => trim($sessionId),
            ]);
        }
    }

    private function syncIdentityMembership(array $user, string $status = 'active'): void {
        if (!$this->syncMemberships) {
            return;
        }

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
