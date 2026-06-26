<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;

class AuthSecurityRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
    }

    public function recordEvent(
        string $eventType,
        string $status = 'info',
        ?string $userId = null,
        ?string $email = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $metadata = []
    ): void {
        $stmt = $this->db->prepare('
            INSERT INTO "AuthSecurityEvent" (
                id,
                tenant_id,
                user_id,
                email,
                event_type,
                status,
                ip_address,
                user_agent,
                metadata,
                created_at
            ) VALUES (
                :id,
                :tenant_id,
                :user_id,
                :email,
                :event_type,
                :status,
                :ip_address,
                :user_agent,
                :metadata,
                NOW()
            )
        ');

        $stmt->execute([
            'id' => bin2hex(random_bytes(10)),
            'tenant_id' => $this->getTenantId(),
            'user_id' => $userId,
            'email' => $email ? strtolower(trim($email)) : null,
            'event_type' => trim($eventType),
            'status' => trim($status) !== '' ? trim($status) : 'info',
            'ip_address' => $ipAddress ? trim($ipAddress) : null,
            'user_agent' => $userAgent ? trim($userAgent) : null,
            'metadata' => !empty($metadata)
                ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '{}',
        ]);
    }

    private function intervalSql(string $interval): string {
        $normalized = strtolower(trim($interval));
        $allowed = [
            '15 minutes' => '15 minutes',
            '1 hour' => '1 hour',
            '24 hours' => '24 hours',
        ];

        return $allowed[$normalized] ?? '1 hour';
    }

    public function countRecentEventsByEmail(string $eventType, string $email, string $interval = '1 hour'): int {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return 0;
        }

        $intervalSql = $this->intervalSql($interval);
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM "AuthSecurityEvent"
            WHERE tenant_id = :tenant_id
              AND event_type = :event_type
              AND email = :email
              AND created_at >= NOW() - INTERVAL \'' . $intervalSql . '\'
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'event_type' => $eventType,
            'email' => $normalizedEmail,
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function countRecentEventsByIp(string $eventType, string $ipAddress, string $interval = '1 hour'): int {
        $ip = trim($ipAddress);
        if ($ip === '') {
            return 0;
        }

        $intervalSql = $this->intervalSql($interval);
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM "AuthSecurityEvent"
            WHERE tenant_id = :tenant_id
              AND event_type = :event_type
              AND ip_address = :ip_address
              AND created_at >= NOW() - INTERVAL \'' . $intervalSql . '\'
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'event_type' => $eventType,
            'ip_address' => $ip,
        ]);

        return (int)$stmt->fetchColumn();
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}
