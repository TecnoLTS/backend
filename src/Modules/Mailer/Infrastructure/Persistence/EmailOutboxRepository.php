<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Infrastructure\Persistence;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\Mailer\Application\MailPayloadSanitizer;
use App\Modules\Mailer\Application\MailerRetryPolicy;
use App\Modules\Mailer\Application\Ports\MailerOutboxStore;
use App\Modules\Mailer\Application\QueuedMailMessage;
use App\Modules\Mailer\Domain\MailerDomain;
use PDO;

final class EmailOutboxRepository implements MailerOutboxStore
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getModuleInstance(MailerDomain::KEY);
    }

    /** @return array<string,mixed> */
    public function enqueue(QueuedMailMessage $message): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            INSERT INTO "EmailOutbox" (
                id, tenant_id, idempotency_key, payload_fingerprint,
                recipient_email, subject, body, plain_body, html_body,
                message_format, reply_to_email, reply_to_name, audit_preview,
                status, attempts, max_attempts, available_at, expires_at,
                metadata, created_at, updated_at
            ) VALUES (
                :id, :tenant_id, :idempotency_key, :payload_fingerprint,
                :recipient_email, :subject, :body, :plain_body, :html_body,
                :message_format, :reply_to_email, :reply_to_name, :audit_preview,
                'pending', 0, :max_attempts, NOW(),
                NOW() + make_interval(secs => :ttl_seconds),
                CAST(:metadata AS jsonb), NOW(), NOW()
            )
            ON CONFLICT (tenant_id, idempotency_key) DO NOTHING
            RETURNING id, tenant_id, status, attempts, max_attempts, created_at, updated_at
            SQL);
        $statement->execute([
            'id' => $message->id,
            'tenant_id' => $message->tenantId,
            'idempotency_key' => $message->idempotencyKey,
            'payload_fingerprint' => $message->payloadFingerprint,
            'recipient_email' => $message->recipientEmail,
            'subject' => $message->subject,
            'body' => $message->plainBody,
            'plain_body' => $message->plainBody,
            'html_body' => $message->htmlBody,
            'message_format' => $message->format,
            'reply_to_email' => $message->replyToEmail,
            'reply_to_name' => $message->replyToName,
            'audit_preview' => $message->auditPreview,
            'max_attempts' => $message->maxAttempts,
            'ttl_seconds' => $message->ttlSeconds,
            'metadata' => $this->json($message->metadata),
        ]);
        $created = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($created)) {
            return $created + ['accepted' => true, 'deduplicated' => false];
        }

        $existing = $this->db->prepare(<<<'SQL'
            SELECT id, tenant_id, status, attempts, max_attempts,
                   payload_fingerprint, created_at, updated_at
            FROM "EmailOutbox"
            WHERE tenant_id = :tenant_id AND idempotency_key = :idempotency_key
            LIMIT 1
            SQL);
        $existing->execute([
            'tenant_id' => $message->tenantId,
            'idempotency_key' => $message->idempotencyKey,
        ]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string)$row['payload_fingerprint'], $message->payloadFingerprint)) {
            throw new \LogicException('Mailer idempotency key was reused with a different payload.');
        }

        return $row + ['accepted' => true, 'deduplicated' => true];
    }

    /**
     * Synchronous attachments are audit-only: their binary content is never
     * persisted. A transport failure therefore terminates in dead-letter and
     * cannot be picked up by the queue worker.
     *
     * @param array<string,mixed> $metadata
     * @return array{id:string,tenant_id:string,status:string}
     */
    public function createAttachmentAudit(
        string $tenantId,
        string $recipient,
        string $subject,
        string $auditPreview,
        array $metadata = []
    ): array {
        $tenantId = $this->validateTenantId($tenantId);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Attachment recipient is invalid.');
        }
        $id = 'mail_attachment_' . bin2hex(random_bytes(12));
        $statement = $this->db->prepare(<<<'SQL'
            INSERT INTO "EmailOutbox" (
                id, tenant_id, idempotency_key, payload_fingerprint,
                recipient_email, subject, body, plain_body, message_format,
                audit_preview, status, attempts, max_attempts, available_at,
                expires_at, metadata, locked_at, locked_by, lock_token,
                created_at, updated_at
            ) VALUES (
                :id, :tenant_id, :idempotency_key, :payload_fingerprint,
                :recipient_email, :subject, '', '', 'attachment_audit',
                :audit_preview, 'processing', 1, 1, NOW(), NOW(),
                CAST(:metadata AS jsonb), NOW(), 'request', :lock_token,
                NOW(), NOW()
            )
            RETURNING id, tenant_id, status
            SQL);
        $statement->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'idempotency_key' => 'attachment:' . $id,
            'payload_fingerprint' => hash('sha256', $tenantId . '|' . $id),
            'recipient_email' => strtolower(trim($recipient)),
            'subject' => mb_substr(trim(str_replace(["\r", "\n"], ' ', $subject)), 0, 255),
            'audit_preview' => mb_substr(trim($auditPreview), 0, 2000),
            'metadata' => $this->json(MailPayloadSanitizer::metadata($metadata)),
            'lock_token' => $id . ':request',
        ]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id, 'tenant_id' => $tenantId, 'status' => 'processing'];
    }

    /** @return array<int,array<string,mixed>> */
    public function claimFairBatch(int $limit, int $perTenant, int $leaseSeconds, string $workerId): array
    {
        $limit = max(1, min(500, $limit));
        $perTenant = max(1, min(25, $perTenant));
        $tenantLimit = max(1, min($limit, (int)ceil($limit / $perTenant)));
        $leaseSeconds = max(30, min(3600, $leaseSeconds));
        $workerId = mb_substr(preg_replace('/[^A-Za-z0-9_.:\-]/', '-', $workerId) ?: 'mailer-worker', 0, 120);
        $tokenPrefix = bin2hex(random_bytes(16));

        $this->db->beginTransaction();
        try {
            $expire = $this->db->query(<<<'SQL'
                UPDATE "EmailOutbox"
                   SET status = 'dead_letter',
                       last_error_code = 'MAIL_EXPIRED',
                       last_error = 'Message expired before SMTP acceptance.',
                       locked_at = NULL, locked_by = NULL, lock_token = NULL,
                       completed_at = NOW(), updated_at = NOW()
                 WHERE message_format IN ('plain', 'html')
                   AND status IN ('pending', 'retry')
                   AND expires_at <= NOW()
                RETURNING *
                SQL);
            foreach ($expire->fetchAll(PDO::FETCH_ASSOC) ?: [] as $expired) {
                $this->insertDeliveryLog(
                    $expired,
                    'expiry',
                    'dead_letter',
                    'MAIL_EXPIRED',
                    'Message expired before SMTP acceptance.',
                    null,
                    ['expired' => true]
                );
            }
            $statement = $this->db->prepare(<<<SQL
                WITH due_tenants AS (
                    SELECT tenant_id, MIN(COALESCE(available_at, created_at)) AS due_at
                    FROM "EmailOutbox"
                    WHERE message_format IN ('plain', 'html')
                      AND expires_at > NOW()
                      AND (
                        (status IN ('pending', 'retry') AND available_at <= NOW())
                        OR (status = 'processing' AND locked_at < NOW() - make_interval(secs => {$leaseSeconds}))
                      )
                    GROUP BY tenant_id
                    ORDER BY due_at, tenant_id
                    LIMIT {$tenantLimit}
                ), candidates AS (
                    SELECT candidate.id
                    FROM due_tenants tenant_queue
                    CROSS JOIN LATERAL (
                        SELECT queued.id
                        FROM "EmailOutbox" queued
                        WHERE queued.tenant_id = tenant_queue.tenant_id
                          AND queued.message_format IN ('plain', 'html')
                          AND queued.expires_at > NOW()
                          AND (
                            (queued.status IN ('pending', 'retry') AND queued.available_at <= NOW())
                            OR (queued.status = 'processing' AND queued.locked_at < NOW() - make_interval(secs => {$leaseSeconds}))
                          )
                        ORDER BY queued.available_at, queued.created_at, queued.id
                        LIMIT {$perTenant}
                        FOR UPDATE SKIP LOCKED
                    ) candidate
                    ORDER BY tenant_queue.due_at, tenant_queue.tenant_id, candidate.id
                    LIMIT {$limit}
                )
                UPDATE "EmailOutbox" outbox
                   SET status = 'processing', attempts = outbox.attempts + 1,
                       locked_at = NOW(), locked_by = :worker_id,
                       lock_token = :token_prefix || ':' || outbox.id,
                       last_error_code = CASE WHEN outbox.status = 'processing' THEN 'LEASE_RECOVERED' ELSE outbox.last_error_code END,
                       updated_at = NOW()
                  FROM candidates
                 WHERE outbox.id = candidates.id
                RETURNING outbox.*
                SQL);
            $statement->execute(['worker_id' => $workerId, 'token_prefix' => $tokenPrefix]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $this->insertDeliveryLog(
                    $row,
                    'claim',
                    'processing',
                    null,
                    null,
                    null,
                    ['lease_recovered' => ($row['last_error_code'] ?? null) === 'LEASE_RECOVERED']
                );
            }
            $this->db->commit();

            return array_map([$this, 'decodeRowMetadata'], $rows);
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function markDelivered(array $claim, ?string $providerMessageId, string $transport): void
    {
        $this->assertClaimIdentity($claim);
        $this->db->beginTransaction();
        try {
            $locked = $this->lockActiveClaim($claim);
            $statement = $this->db->prepare(<<<'SQL'
                UPDATE "EmailOutbox"
                   SET status = 'sent', last_error_code = NULL, last_error = NULL,
                       locked_at = NULL, locked_by = NULL, lock_token = NULL,
                       sent_at = COALESCE(sent_at, NOW()), completed_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id
                   AND status = 'processing' AND lock_token = :lock_token
                SQL);
            $statement->execute([
                'id' => $claim['id'],
                'tenant_id' => $claim['tenant_id'],
                'lock_token' => $claim['lock_token'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Mailer claim was lost before delivery commit.');
            }
            $this->insertDeliveryLog($locked, 'delivery', 'sent', null, null, $providerMessageId, [
                'transport' => mb_substr($transport, 0, 32),
            ]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function markFailed(
        array $claim,
        string $errorCode,
        string $errorMessage,
        MailerRetryPolicy $retryPolicy
    ): string {
        $this->assertClaimIdentity($claim);
        $this->db->beginTransaction();
        try {
            $locked = $this->lockActiveClaim($claim);
            $attempts = (int)($locked['attempts'] ?? 0);
            $maxAttempts = (int)($locked['max_attempts'] ?? 1);
            $expired = isset($locked['expires_at']) && strtotime((string)$locked['expires_at']) <= time();
            $dead = $expired || $attempts >= $maxAttempts;
            $state = $dead ? 'dead_letter' : 'retry';
            $delay = $dead ? 0 : $retryPolicy->delaySeconds($attempts, (string)$locked['id']);
            $errorCode = mb_substr(preg_replace('/[^A-Z0-9_\-]/', '_', strtoupper($errorCode)) ?: 'MAIL_DELIVERY_FAILED', 0, 80);
            $errorMessage = MailPayloadSanitizer::error($errorMessage);

            $statement = $this->db->prepare(<<<'SQL'
                UPDATE "EmailOutbox"
                   SET status = :status,
                       available_at = CASE WHEN :dead THEN available_at ELSE NOW() + make_interval(secs => :delay_seconds) END,
                       last_error_code = :error_code, last_error = :last_error,
                       locked_at = NULL, locked_by = NULL, lock_token = NULL,
                       completed_at = CASE WHEN :dead THEN NOW() ELSE NULL END,
                       updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id
                   AND status = 'processing' AND lock_token = :lock_token
                SQL);
            $statement->execute([
                'status' => $state,
                'dead' => $dead ? 'true' : 'false',
                'delay_seconds' => $delay,
                'error_code' => $errorCode,
                'last_error' => $errorMessage,
                'id' => $claim['id'],
                'tenant_id' => $claim['tenant_id'],
                'lock_token' => $claim['lock_token'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Mailer claim was lost before failure commit.');
            }
            $this->insertDeliveryLog($locked, 'delivery', $state, $errorCode, $errorMessage, null, [
                'retry_delay_seconds' => $delay,
                'max_attempts' => $maxAttempts,
                'expired' => $expired,
            ]);
            $this->db->commit();

            return $state;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function completeAttachmentAudit(
        array $audit,
        bool $delivered,
        ?string $providerMessageId = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): void {
        $id = trim((string)($audit['id'] ?? ''));
        $tenantId = $this->validateTenantId((string)($audit['tenant_id'] ?? ''));
        if ($id === '') {
            throw new \InvalidArgumentException('Attachment audit identity is invalid.');
        }
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT * FROM "EmailOutbox" WHERE id = :id AND tenant_id = :tenant_id AND message_format = \'attachment_audit\' AND status = \'processing\' FOR UPDATE');
            $select->execute(['id' => $id, 'tenant_id' => $tenantId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('Attachment audit claim is unavailable.');
            }
            $state = $delivered ? 'sent' : 'dead_letter';
            $errorCode = $delivered ? null : mb_substr((string)($errorCode ?: 'SMTP_ATTACHMENT_DELIVERY_FAILED'), 0, 80);
            $errorMessage = $delivered ? null : MailPayloadSanitizer::error((string)$errorMessage);
            $update = $this->db->prepare(<<<'SQL'
                UPDATE "EmailOutbox"
                   SET status = :status, last_error_code = :error_code, last_error = :last_error,
                       locked_at = NULL, locked_by = NULL, lock_token = NULL,
                       sent_at = CASE WHEN :delivered THEN NOW() ELSE sent_at END,
                       completed_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id AND status = 'processing'
                SQL);
            $update->execute([
                'status' => $state,
                'error_code' => $errorCode,
                'last_error' => $errorMessage,
                'delivered' => $delivered ? 'true' : 'false',
                'id' => $id,
                'tenant_id' => $tenantId,
            ]);
            $this->insertDeliveryLog($row, 'attachment_delivery', $state, $errorCode, $errorMessage, $providerMessageId, [
                'transport' => 'smtp_attachment',
                'binary_persisted' => false,
            ]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function requeueDeadLetter(string $tenantId, string $outboxId, string $actor, string $reason): bool
    {
        $tenantId = $this->validateTenantId($tenantId);
        $outboxId = trim($outboxId);
        $actor = trim($actor);
        $reason = trim($reason);
        if ($outboxId === '' || $actor === '' || $reason === '') {
            throw new \InvalidArgumentException('Audited Mailer requeue requires message, actor and reason.');
        }

        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT * FROM "EmailOutbox" WHERE id = :id AND tenant_id = :tenant_id FOR UPDATE');
            $select->execute(['id' => $outboxId, 'tenant_id' => $tenantId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || ($row['status'] ?? null) !== 'dead_letter'
                || ($row['resolved_at'] ?? null) !== null
                || !in_array($row['message_format'] ?? null, ['plain', 'html'], true)) {
                $this->db->rollBack();
                return false;
            }
            $update = $this->db->prepare(<<<'SQL'
                UPDATE "EmailOutbox"
                   SET status = 'retry', attempts = 0,
                       requeue_count = requeue_count + 1,
                       available_at = NOW(), expires_at = NOW() + make_interval(secs => :ttl_seconds),
                       locked_at = NULL, locked_by = NULL, lock_token = NULL,
                       last_error_code = NULL, last_error = NULL,
                       completed_at = NULL, updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id AND status = 'dead_letter'
                   AND resolved_at IS NULL
                SQL);
            $update->execute([
                'ttl_seconds' => $this->boundedInteger('MAILER_OUTBOX_REQUEUE_TTL_SECONDS', 3600, 60, 604800),
                'id' => $outboxId,
                'tenant_id' => $tenantId,
            ]);
            $this->insertDeliveryLog($row, 'manual_requeue', 'retry', null, null, null, [
                'actor' => mb_substr($actor, 0, 120),
                'reason' => mb_substr($reason, 0, 500),
                'previous_attempts' => (int)($row['attempts'] ?? 0),
            ], mb_substr($actor, 0, 120));
            $this->db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function acknowledgeDeadLetter(
        string $tenantId,
        string $outboxId,
        string $actor,
        string $reason
    ): bool {
        $tenantId = $this->validateTenantId($tenantId);
        $outboxId = trim($outboxId);
        $actor = trim($actor);
        $reason = trim($reason);
        if ($outboxId === '' || $actor === '' || $reason === '') {
            throw new \InvalidArgumentException('Audited Mailer acknowledgement requires message, actor and reason.');
        }

        $actor = mb_substr($actor, 0, 120);
        $reason = mb_substr($reason, 0, 500);
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare(<<<'SQL'
                SELECT *
                  FROM "EmailOutbox"
                 WHERE id = :id AND tenant_id = :tenant_id
                 FOR UPDATE
                SQL);
            $select->execute(['id' => $outboxId, 'tenant_id' => $tenantId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || ($row['status'] ?? null) !== 'dead_letter'
                || ($row['resolved_at'] ?? null) !== null) {
                $this->db->rollBack();
                return false;
            }

            $update = $this->db->prepare(<<<'SQL'
                UPDATE "EmailOutbox"
                   SET resolved_at = NOW(), resolved_by = :actor,
                       resolution_reason = :reason,
                       locked_at = NULL, locked_by = NULL, lock_token = NULL,
                       completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id
                   AND status = 'dead_letter' AND resolved_at IS NULL
                SQL);
            $update->execute([
                'actor' => $actor,
                'reason' => $reason,
                'id' => $outboxId,
                'tenant_id' => $tenantId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Mailer dead-letter acknowledgement lost its row lock.');
            }
            $this->insertDeliveryLog(
                $row,
                'manual_acknowledge',
                'dead_letter_acknowledged',
                isset($row['last_error_code']) ? (string)$row['last_error_code'] : null,
                isset($row['last_error']) ? (string)$row['last_error'] : null,
                null,
                [
                    'actor' => $actor,
                    'reason' => $reason,
                    'delivery_outcome_preserved' => true,
                ],
                $actor
            );
            $this->db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function listOutbox(int $limit = 50, ?string $status = null): array
    {
        $limit = max(1, min(200, $limit));
        $params = ['tenant_id' => $this->tenantId()];
        $where = 'tenant_id = :tenant_id';
        if ($status !== null && $status !== '') {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        $statement = $this->db->prepare(sprintf(
            'SELECT id, recipient_email, subject, message_format, audit_preview, status,
                    attempts, max_attempts, requeue_count, available_at, expires_at,
                    last_error_code, last_error, metadata, created_at, updated_at, sent_at, completed_at,
                    resolved_at, resolved_by, resolution_reason
             FROM "EmailOutbox" WHERE %s ORDER BY created_at DESC LIMIT %d',
            $where,
            $limit
        ));
        $statement->execute($params);

        return array_map([$this, 'decodeRowMetadata'], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<int,array<string,mixed>> */
    public function listDeliveryLog(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->db->prepare(sprintf(
            'SELECT id, outbox_id, recipient_email, attempt_number, phase, status,
                    provider_message_id, error_code, error_message, actor_id, metadata, created_at
             FROM "EmailDeliveryLog"
             WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT %d',
            $limit
        ));
        $statement->execute(['tenant_id' => $this->tenantId()]);

        return array_map([$this, 'decodeRowMetadata'], $statement->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,int|string|null> */
    public function stats(?string $tenantId = null, int $leaseSeconds = 300): array
    {
        $leaseSeconds = max(30, min(3600, $leaseSeconds));
        $where = '';
        $params = [];
        if ($tenantId !== null) {
            $where = 'WHERE tenant_id = :tenant_id';
            $params['tenant_id'] = $this->validateTenantId($tenantId);
        }
        $statement = $this->db->prepare(<<<SQL
            SELECT
                COUNT(*)::bigint AS total,
                COUNT(*) FILTER (WHERE status = 'pending')::bigint AS pending,
                COUNT(*) FILTER (WHERE status = 'retry')::bigint AS retry,
                COUNT(*) FILTER (WHERE status = 'processing')::bigint AS processing,
                COUNT(*) FILTER (WHERE status = 'sent')::bigint AS sent,
                COUNT(*) FILTER (WHERE status = 'dead_letter' AND resolved_at IS NULL)::bigint AS dead_letter,
                COUNT(*) FILTER (WHERE status = 'dead_letter' AND resolved_at IS NOT NULL)::bigint AS dead_letter_acknowledged,
                COUNT(*) FILTER (WHERE status = 'processing' AND locked_at < NOW() - make_interval(secs => {$leaseSeconds}))::bigint AS stale_leases,
                COALESCE(EXTRACT(EPOCH FROM NOW() - MIN(created_at) FILTER (WHERE status IN ('pending','retry') AND available_at <= NOW()))::bigint, 0) AS oldest_due_age_seconds,
                COUNT(DISTINCT tenant_id) FILTER (WHERE status IN ('pending','retry','processing'))::bigint AS active_tenants,
                MAX(created_at) AS last_created_at,
                MAX(sent_at) AS last_sent_at
            FROM "EmailOutbox" {$where}
            SQL);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    public function assertReady(): void
    {
        $this->db->query('SELECT idempotency_key, lock_token, expires_at, resolved_at, resolved_by, resolution_reason FROM "EmailOutbox" LIMIT 0');
        $this->db->query('SELECT attempt_number, phase, actor_id FROM "EmailDeliveryLog" LIMIT 0');
    }

    /** @param array<string,mixed> $row */
    private function lockActiveClaim(array $row): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT * FROM "EmailOutbox"
            WHERE id = :id AND tenant_id = :tenant_id
              AND status = 'processing' AND lock_token = :lock_token
            FOR UPDATE
            SQL);
        $statement->execute([
            'id' => $row['id'],
            'tenant_id' => $row['tenant_id'],
            'lock_token' => $row['lock_token'],
        ]);
        $locked = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($locked)) {
            throw new \RuntimeException('Mailer outbox lease is no longer owned by this worker.');
        }

        return $locked;
    }

    /**
     * @param array<string,mixed> $outbox
     * @param array<string,mixed> $metadata
     */
    private function insertDeliveryLog(
        array $outbox,
        string $phase,
        string $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?string $providerMessageId,
        array $metadata,
        ?string $actorId = null
    ): void {
        $statement = $this->db->prepare(<<<'SQL'
            INSERT INTO "EmailDeliveryLog" (
                id, tenant_id, outbox_id, recipient_email, attempt_number,
                phase, status, provider_message_id, error_code, error_message,
                actor_id, metadata, created_at
            ) VALUES (
                :id, :tenant_id, :outbox_id, :recipient_email, :attempt_number,
                :phase, :status, :provider_message_id, :error_code, :error_message,
                :actor_id, CAST(:metadata AS jsonb), NOW()
            )
            SQL);
        $statement->execute([
            'id' => 'mail_log_' . bin2hex(random_bytes(12)),
            'tenant_id' => (string)$outbox['tenant_id'],
            'outbox_id' => (string)$outbox['id'],
            'recipient_email' => (string)$outbox['recipient_email'],
            'attempt_number' => max(0, (int)($outbox['attempts'] ?? 0)),
            'phase' => mb_substr($phase, 0, 64),
            'status' => mb_substr($status, 0, 32),
            'provider_message_id' => $providerMessageId !== null ? mb_substr($providerMessageId, 0, 512) : null,
            'error_code' => $errorCode !== null ? mb_substr($errorCode, 0, 80) : null,
            'error_message' => $errorMessage !== null ? MailPayloadSanitizer::error($errorMessage) : null,
            'actor_id' => $actorId,
            'metadata' => $this->json(MailPayloadSanitizer::metadata($metadata)),
        ]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeRowMetadata(array $row): array
    {
        if (isset($row['metadata']) && is_string($row['metadata'])) {
            $decoded = json_decode($row['metadata'], true);
            $row['metadata'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    /** @param array<string,mixed> $claim */
    private function assertClaimIdentity(array $claim): void
    {
        foreach (['id', 'tenant_id', 'lock_token'] as $key) {
            if (!isset($claim[$key]) || trim((string)$claim[$key]) === '') {
                throw new \InvalidArgumentException('Invalid Mailer outbox claim.');
            }
        }
        $this->validateTenantId((string)$claim['tenant_id']);
    }

    private function tenantId(): string
    {
        return $this->validateTenantId((string)(TenantContext::id() ?? ''));
    }

    private function validateTenantId(string $tenantId): string
    {
        $tenantId = trim($tenantId);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $tenantId)) {
            throw new \LogicException('Mailer persistence requires an explicit safe tenant context.');
        }

        return $tenantId;
    }

    private function boundedInteger(string $key, int $default, int $min, int $max): int
    {
        $raw = $_ENV[$key] ?? getenv($key);
        $value = filter_var($raw === false || $raw === null || trim((string)$raw) === '' ? $default : $raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);
        if ($value === false) {
            throw new \RuntimeException("{$key} is outside its safe range.");
        }

        return (int)$value;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
