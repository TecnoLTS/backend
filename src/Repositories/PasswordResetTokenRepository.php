<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;
use PDOStatement;

class PasswordResetTokenRepository {
    private $db;
    private string $tableName;
    private static array $schemaEnsured = [];

    public function __construct(
        string $moduleKey = IdentityPlatformDomain::KEY,
        string $tableName = '"PasswordResetToken"'
    ) {
        $this->db = Database::getModuleInstance($moduleKey);
        $this->tableName = $tableName;
    }

    protected function rewriteSql(string $sql): string {
        if ($this->tableName !== '"PasswordResetToken"') {
            $sql = str_replace('"PasswordResetToken"', $this->tableName, $sql);
            $sql = str_replace('PasswordResetToken_', trim($this->tableName, '"') . '_', $sql);
        }

        return $sql;
    }

    protected function prepare(string $sql): PDOStatement {
        return $this->db->prepare($this->rewriteSql($sql));
    }

    protected function exec(string $sql): int|false {
        return $this->db->exec($this->rewriteSql($sql));
    }

    public function beginTransaction(): void {
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
    }

    public function commit(): void {
        if ($this->db->inTransaction()) {
            $this->db->commit();
        }
    }

    public function rollBack(): void {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function normalizedTableName(): string {
        return trim($this->tableName, '"');
    }

    private function schemaTableExists(): bool {
        $stmt = $this->db->prepare('SELECT to_regclass(:table_name)');
        $stmt->execute([
            'table_name' => sprintf('public."%s"', $this->normalizedTableName()),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function ensureSchema(): void {
        if (self::$schemaEnsured[$this->tableName] ?? false) {
            return;
        }

        if ($this->schemaTableExists()) {
            self::$schemaEnsured[$this->tableName] = true;
            return;
        }

        try {
            $this->exec('
                CREATE TABLE IF NOT EXISTS "PasswordResetToken" (
                    id text PRIMARY KEY,
                    tenant_id text NOT NULL,
                    user_id text NOT NULL,
                    token_hash text NOT NULL,
                    purpose text NOT NULL DEFAULT \'password_reset\',
                    created_by_user_id text,
                    expires_at timestamp without time zone NOT NULL,
                    used_at timestamp without time zone,
                    request_ip text,
                    request_user_agent text,
                    used_ip text,
                    used_user_agent text,
                    created_at timestamp without time zone DEFAULT NOW() NOT NULL,
                    updated_at timestamp without time zone DEFAULT NOW() NOT NULL
                )
            ');
            $this->exec('CREATE UNIQUE INDEX IF NOT EXISTS "PasswordResetToken_tenant_hash_uidx" ON "PasswordResetToken" (tenant_id, token_hash)');
            $this->exec('CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_user_idx" ON "PasswordResetToken" (tenant_id, user_id, created_at DESC)');
            $this->exec('CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_expires_idx" ON "PasswordResetToken" (tenant_id, expires_at)');
        } catch (\Throwable $exception) {
            if (!$this->schemaTableExists()) {
                throw $exception;
            }
        }

        self::$schemaEnsured[$this->tableName] = true;
    }

    public function create(
        string $userId,
        string $tokenHash,
        string $expiresAt,
        ?string $requestIp,
        ?string $requestUserAgent,
        string $purpose = 'password_reset',
        ?string $createdByUserId = null
    ): void {
        $this->ensureSchema();
        $purpose = in_array($purpose, ['password_reset', 'invitation'], true) ? $purpose : 'password_reset';
        $invalidate = $this->prepare('
            UPDATE "PasswordResetToken"
            SET used_at = NOW(), updated_at = NOW()
            WHERE tenant_id = :tenant_id
              AND user_id = :user_id
              AND purpose = :purpose
              AND used_at IS NULL
        ');
        $invalidate->execute([
            'tenant_id' => $this->getTenantId(),
            'user_id' => $userId,
            'purpose' => $purpose,
        ]);
        $stmt = $this->prepare('
            INSERT INTO "PasswordResetToken" (
                id,
                tenant_id,
                user_id,
                token_hash,
                purpose,
                created_by_user_id,
                expires_at,
                request_ip,
                request_user_agent,
                created_at,
                updated_at
            ) VALUES (
                :id,
                :tenant_id,
                :user_id,
                :token_hash,
                :purpose,
                :created_by_user_id,
                :expires_at,
                :request_ip,
                :request_user_agent,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'id' => bin2hex(random_bytes(10)),
            'tenant_id' => $this->getTenantId(),
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'purpose' => $purpose,
            'created_by_user_id' => $createdByUserId,
            'expires_at' => $expiresAt,
            'request_ip' => $requestIp,
            'request_user_agent' => $requestUserAgent,
        ]);
    }

    public function consumeValidToken(string $tokenHash, ?string $usedIp, ?string $usedUserAgent): ?array {
        $this->ensureSchema();
        $stmt = $this->prepare('
            UPDATE "PasswordResetToken"
            SET used_at = NOW(),
                used_ip = :used_ip,
                used_user_agent = :used_user_agent,
                updated_at = NOW()
            WHERE token_hash = :token_hash
              AND tenant_id = :tenant_id
              AND used_at IS NULL
              AND expires_at > NOW()
            RETURNING id, user_id, purpose, created_by_user_id, expires_at
        ');

        $stmt->execute([
            'token_hash' => $tokenHash,
            'tenant_id' => $this->getTenantId(),
            'used_ip' => $usedIp,
            'used_user_agent' => $usedUserAgent,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function invalidateForUser(string $userId, ?string $purpose = null): int {
        $this->ensureSchema();
        $purpose = $purpose !== null ? strtolower(trim($purpose)) : null;
        if ($purpose !== null && !in_array($purpose, ['password_reset', 'invitation'], true)) {
            throw new \InvalidArgumentException('Propósito de enlace de cuenta no permitido.');
        }

        $sql = '
            UPDATE "PasswordResetToken"
            SET used_at = COALESCE(used_at, NOW()), updated_at = NOW()
            WHERE tenant_id = :tenant_id
              AND user_id = :user_id
              AND used_at IS NULL
        ';
        $params = [
            'tenant_id' => $this->getTenantId(),
            'user_id' => trim($userId),
        ];
        if ($purpose !== null) {
            $sql .= ' AND purpose = :purpose';
            $params['purpose'] = $purpose;
        }

        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function getValidToken(string $tokenHash): ?array {
        $this->ensureSchema();
        $stmt = $this->prepare('
            SELECT id, user_id, purpose, created_by_user_id, expires_at
            FROM "PasswordResetToken"
            WHERE token_hash = :token_hash
              AND tenant_id = :tenant_id
              AND used_at IS NULL
              AND expires_at > NOW()
            LIMIT 1
        ');

        $stmt->execute([
            'token_hash' => $tokenHash,
            'tenant_id' => $this->getTenantId(),
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markUsed(string $id, ?string $usedIp, ?string $usedUserAgent): void {
        $this->ensureSchema();
        $stmt = $this->prepare('
            UPDATE "PasswordResetToken"
            SET used_at = COALESCE(used_at, NOW()),
                used_ip = COALESCE(used_ip, :used_ip),
                used_user_agent = COALESCE(used_user_agent, :used_user_agent),
                updated_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
        ');

        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'used_ip' => $usedIp,
            'used_user_agent' => $usedUserAgent,
        ]);
    }

    public function deleteExpired(): void {
        $this->ensureSchema();
        $stmt = $this->prepare('
            DELETE FROM "PasswordResetToken"
            WHERE tenant_id = :tenant_id
              AND expires_at < NOW() - INTERVAL \'7 days\'
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}
