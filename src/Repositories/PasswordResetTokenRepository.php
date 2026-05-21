<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;

class PasswordResetTokenRepository {
    private $db;
    private static bool $schemaEnsured = false;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    private function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS "PasswordResetToken" (
                id text PRIMARY KEY,
                tenant_id text NOT NULL,
                user_id text NOT NULL,
                token_hash text NOT NULL,
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
        $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS "PasswordResetToken_tenant_hash_uidx" ON "PasswordResetToken" (tenant_id, token_hash)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_user_idx" ON "PasswordResetToken" (tenant_id, user_id, created_at DESC)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_expires_idx" ON "PasswordResetToken" (tenant_id, expires_at)');

        self::$schemaEnsured = true;
    }

    public function create(
        string $userId,
        string $tokenHash,
        string $expiresAt,
        ?string $requestIp,
        ?string $requestUserAgent
    ): void {
        $this->ensureSchema();
        $stmt = $this->db->prepare('
            INSERT INTO "PasswordResetToken" (
                id,
                tenant_id,
                user_id,
                token_hash,
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
            'expires_at' => $expiresAt,
            'request_ip' => $requestIp,
            'request_user_agent' => $requestUserAgent,
        ]);
    }

    public function consumeValidToken(string $tokenHash, ?string $usedIp, ?string $usedUserAgent): ?array {
        $this->ensureSchema();
        $stmt = $this->db->prepare('
            UPDATE "PasswordResetToken"
            SET used_at = NOW(),
                used_ip = :used_ip,
                used_user_agent = :used_user_agent,
                updated_at = NOW()
            WHERE token_hash = :token_hash
              AND tenant_id = :tenant_id
              AND used_at IS NULL
              AND expires_at > NOW()
            RETURNING id, user_id, expires_at
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

    public function getValidToken(string $tokenHash): ?array {
        $this->ensureSchema();
        $stmt = $this->db->prepare('
            SELECT id, user_id, expires_at
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
        $stmt = $this->db->prepare('
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
        $stmt = $this->db->prepare('
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
