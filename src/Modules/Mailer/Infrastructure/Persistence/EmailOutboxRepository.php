<?php

namespace App\Modules\Mailer\Infrastructure\Persistence;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\Mailer\Domain\MailerDomain;
use PDO;

final class EmailOutboxRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getModuleInstance(MailerDomain::KEY);
    }

    public function createPending(array $message): array
    {
        $id = $message['id'] ?? ('mail_' . bin2hex(random_bytes(10)));
        $stmt = $this->db->prepare('
            INSERT INTO "EmailOutbox" (
                id,
                tenant_id,
                recipient_email,
                subject,
                body,
                status,
                attempts,
                metadata
            ) VALUES (
                :id,
                :tenant_id,
                :recipient_email,
                :subject,
                :body,
                \'pending\',
                0,
                :metadata
            )
            RETURNING id, tenant_id, recipient_email, subject, status, attempts, created_at, updated_at, sent_at
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->tenantId(),
            'recipient_email' => (string)$message['to'],
            'subject' => (string)$message['subject'],
            'body' => (string)$message['body'],
            'metadata' => $this->json($message['metadata'] ?? []),
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id];
    }

    public function markDelivered(string $outboxId, ?string $providerMessageId = null, array $metadata = []): void
    {
        $stmt = $this->db->prepare('
            UPDATE "EmailOutbox"
            SET status = \'sent\',
                attempts = attempts + 1,
                last_error = NULL,
                updated_at = NOW(),
                sent_at = COALESCE(sent_at, NOW())
            WHERE id = :id
        ');
        $stmt->execute(['id' => $outboxId]);

        $this->logDelivery($outboxId, 'sent', null, $providerMessageId, $metadata);
    }

    public function markFailed(string $outboxId, string $errorMessage, array $metadata = []): void
    {
        $stmt = $this->db->prepare('
            UPDATE "EmailOutbox"
            SET status = \'failed\',
                attempts = attempts + 1,
                last_error = :last_error,
                updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $outboxId,
            'last_error' => mb_substr($errorMessage, 0, 2000),
        ]);

        $this->logDelivery($outboxId, 'failed', $errorMessage, null, $metadata);
    }

    public function listOutbox(int $limit = 50, ?string $status = null): array
    {
        $limit = max(1, min(200, $limit));
        $params = ['tenant_id' => $this->tenantId()];
        $where = 'tenant_id = :tenant_id';
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare(sprintf(
            'SELECT id, recipient_email, subject, status, attempts, last_error, metadata, created_at, updated_at, sent_at
             FROM "EmailOutbox"
             WHERE %s
             ORDER BY created_at DESC
             LIMIT %d',
            $where,
            $limit
        ));
        $stmt->execute($params);

        return array_map([$this, 'decodeRowMetadata'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function listDeliveryLog(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare(sprintf(
            'SELECT id, outbox_id, recipient_email, status, provider_message_id, error_message, metadata, created_at
             FROM "EmailDeliveryLog"
             WHERE tenant_id = :tenant_id
             ORDER BY created_at DESC
             LIMIT %d',
            $limit
        ));
        $stmt->execute(['tenant_id' => $this->tenantId()]);

        return array_map([$this, 'decodeRowMetadata'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function stats(): array
    {
        $stmt = $this->db->prepare('
            SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE status = \'pending\')::int AS pending,
                COUNT(*) FILTER (WHERE status = \'sent\')::int AS sent,
                COUNT(*) FILTER (WHERE status = \'failed\')::int AS failed,
                MAX(created_at) AS last_created_at,
                MAX(sent_at) AS last_sent_at
            FROM "EmailOutbox"
            WHERE tenant_id = :tenant_id
        ');
        $stmt->execute(['tenant_id' => $this->tenantId()]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total' => 0,
            'pending' => 0,
            'sent' => 0,
            'failed' => 0,
            'last_created_at' => null,
            'last_sent_at' => null,
        ];
    }

    public function assertReady(): void
    {
        $this->db->query('SELECT 1 FROM "EmailOutbox" LIMIT 1');
        $this->db->query('SELECT 1 FROM "EmailDeliveryLog" LIMIT 1');
    }

    private function logDelivery(
        string $outboxId,
        string $status,
        ?string $errorMessage = null,
        ?string $providerMessageId = null,
        array $metadata = []
    ): void {
        $outbox = $this->findOutbox($outboxId);
        $recipient = (string)($outbox['recipient_email'] ?? '');
        if ($recipient === '') {
            $recipient = (string)($metadata['recipient_email'] ?? '');
        }

        $stmt = $this->db->prepare('
            INSERT INTO "EmailDeliveryLog" (
                id,
                tenant_id,
                outbox_id,
                recipient_email,
                status,
                provider_message_id,
                error_message,
                metadata
            ) VALUES (
                :id,
                :tenant_id,
                :outbox_id,
                :recipient_email,
                :status,
                :provider_message_id,
                :error_message,
                :metadata
            )
        ');
        $stmt->execute([
            'id' => 'mail_log_' . bin2hex(random_bytes(10)),
            'tenant_id' => $this->tenantId(),
            'outbox_id' => $outboxId,
            'recipient_email' => $recipient,
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 2000) : null,
            'metadata' => $this->json($metadata),
        ]);
    }

    private function findOutbox(string $outboxId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT recipient_email
            FROM "EmailOutbox"
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $outboxId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function decodeRowMetadata(array $row): array
    {
        if (isset($row['metadata']) && is_string($row['metadata'])) {
            $decoded = json_decode($row['metadata'], true);
            $row['metadata'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    private function tenantId(): string
    {
        $tenant = TenantContext::get();
        return (string)($tenant['id'] ?? 'default');
    }

    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
