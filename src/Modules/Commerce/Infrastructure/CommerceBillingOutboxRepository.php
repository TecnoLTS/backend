<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure;

use App\Core\Database;
use App\Modules\Commerce\Application\CommerceBillingOutboxRetryPolicy;
use App\Modules\Commerce\Application\Ports\CommerceBillingOutboxStore;
use App\Modules\Commerce\Domain\CommerceDomain;
use PDO;

final class CommerceBillingOutboxRepository implements CommerceBillingOutboxStore
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getModuleInstance(CommerceDomain::KEY);
    }

    /**
     * Must be called on the same PDO transaction that changes the Order.
     * The deterministic id and tenant/order unique key make repeat transitions
     * idempotent without resurrecting a dead-letter or a delivered command.
     *
     * @param array<string,mixed> $command
     */
    public function enqueueForOrder(
        string $tenantId,
        string $orderId,
        string $triggerStatus,
        array $command,
        int $maxAttempts = 12
    ): void {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Commerce Billing outbox enqueue requires the active Order transaction.');
        }
        $tenantId = trim($tenantId);
        $orderId = trim($orderId);
        if ($tenantId === '' || $orderId === '') {
            throw new \InvalidArgumentException('Tenant and order are required for Billing outbox enqueue.');
        }

        $payload = array_merge($command, [
            'version' => 1,
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'trigger_status' => strtolower(trim($triggerStatus)),
        ]);
        $statement = $this->db->prepare(<<<'SQL'
            INSERT INTO "CommerceBillingOutbox" (
                id, tenant_id, order_id, event_type, command, status,
                delivery_state, attempts, max_attempts, available_at,
                created_at, updated_at
            ) VALUES (
                :id, :tenant_id, :order_id, 'commerce.order.ready_for_billing.v1',
                CAST(:command AS jsonb), 'pending', 'not_started', 0,
                :max_attempts, NOW(), NOW(), NOW()
            )
            ON CONFLICT (tenant_id, order_id) DO NOTHING
            SQL);
        $statement->execute([
            'id' => self::outboxId($tenantId, $orderId),
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'command' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'max_attempts' => max(1, min(100, $maxAttempts)),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function claimFairBatch(
        int $limit,
        int $perTenant,
        int $leaseSeconds,
        string $workerId
    ): array {
        $limit = max(1, min(500, $limit));
        $perTenant = max(1, min(25, $perTenant));
        $tenantLimit = max(1, min($limit, (int) ceil($limit / $perTenant)));
        $leaseSeconds = max(30, min(3600, $leaseSeconds));
        $workerId = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '-', $workerId) ?: 'worker', 0, 120);
        $tokenPrefix = bin2hex(random_bytes(16));

        $this->db->beginTransaction();
        try {
            $sql = <<<SQL
                WITH due_tenants AS (
                    SELECT tenant_id, MIN(COALESCE(available_at, created_at)) AS due_at
                    FROM "CommerceBillingOutbox"
                    WHERE (
                        status IN ('pending', 'retry', 'delivery_unknown')
                        AND available_at <= NOW()
                    ) OR (
                        status = 'processing'
                        AND locked_at < NOW() - make_interval(secs => {$leaseSeconds})
                    )
                    GROUP BY tenant_id
                    ORDER BY due_at, tenant_id
                    LIMIT {$tenantLimit}
                ), candidates AS (
                    SELECT candidate.id
                    FROM due_tenants tenant_queue
                    CROSS JOIN LATERAL (
                        SELECT queued.id
                        FROM "CommerceBillingOutbox" queued
                        WHERE queued.tenant_id = tenant_queue.tenant_id
                          AND (
                            (queued.status IN ('pending', 'retry', 'delivery_unknown') AND queued.available_at <= NOW())
                            OR (queued.status = 'processing' AND queued.locked_at < NOW() - make_interval(secs => {$leaseSeconds}))
                          )
                        ORDER BY queued.available_at, queued.created_at, queued.id
                        LIMIT {$perTenant}
                        FOR UPDATE SKIP LOCKED
                    ) candidate
                    ORDER BY tenant_queue.due_at, candidate.id
                    LIMIT {$limit}
                )
                UPDATE "CommerceBillingOutbox" outbox
                   SET status = 'processing',
                       attempts = outbox.attempts + 1,
                       locked_at = NOW(),
                       locked_by = :worker_id,
                       lock_token = :token_prefix || ':' || outbox.id,
                       updated_at = NOW(),
                       last_error_code = CASE WHEN outbox.status = 'processing' THEN 'LEASE_RECOVERED' ELSE outbox.last_error_code END
                  FROM candidates
                 WHERE outbox.id = candidates.id
                RETURNING outbox.*
                SQL;
            $statement = $this->db->prepare($sql);
            $statement->execute([
                'worker_id' => $workerId,
                'token_prefix' => $tokenPrefix,
            ]);
            $claimed = $statement->fetchAll() ?: [];
            foreach ($claimed as $row) {
                $this->insertAttempt($row, 'claim', 'claimed', null, null, null, [
                    'worker_id' => $workerId,
                    'recovered_lease' => ($row['last_error_code'] ?? null) === 'LEASE_RECOVERED',
                ]);
            }
            $this->db->commit();

            return $claimed;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function loadOrderForClaim(array $outbox): ?array
    {
        $this->assertClaimIdentity($outbox);
        $statement = $this->db->prepare(<<<'SQL'
            SELECT
                o.id, o.tenant_id, o.status, o.total, o.created_at,
                o.shipping_address, o.billing_address, o.payment_method,
                o.delivery_method, o.payment_details, o.items_subtotal,
                o.vat_subtotal, o.vat_rate, o.vat_amount, o.shipping,
                o.shipping_base, o.shipping_tax_rate, o.shipping_tax_amount,
                o.discount_code, o.discount_total, o.order_notes, o.invoice_data,
                o.customer_name AS user_name, o.customer_email AS user_email,
                o.customer_document_type, o.customer_document_number
            FROM "Order" o
            JOIN "CommerceBillingOutbox" outbox
              ON outbox.tenant_id = o.tenant_id
             AND outbox.order_id = o.id
            WHERE outbox.id = :outbox_id
              AND outbox.tenant_id = :tenant_id
              AND outbox.status = 'processing'
              AND outbox.lock_token = :lock_token
            LIMIT 1
            SQL);
        $statement->execute([
            'outbox_id' => $outbox['id'],
            'tenant_id' => $outbox['tenant_id'],
            'lock_token' => $outbox['lock_token'],
        ]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            return null;
        }

        $items = $this->db->prepare(<<<'SQL'
            SELECT product_id, product_name, quantity, price, price_net,
                   net_total, tax_rate, tax_amount
            FROM "OrderItem"
            WHERE tenant_id = :tenant_id AND order_id = :order_id
            ORDER BY id
            SQL);
        $items->execute([
            'tenant_id' => $outbox['tenant_id'],
            'order_id' => $outbox['order_id'],
        ]);
        $order['items'] = $items->fetchAll() ?: [];

        return $order;
    }

    /** @param array<string,mixed> $metadata */
    public function recordPhase(
        array $outbox,
        string $phase,
        string $outcome,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        array $metadata = []
    ): void {
        $this->assertClaimIdentity($outbox);
        $this->db->beginTransaction();
        try {
            $lock = $this->lockActiveClaim($outbox);
            $this->insertAttempt($lock, $phase, $outcome, $httpStatus, $errorCode, $errorMessage, $metadata);
            $statement = $this->db->prepare(<<<'SQL'
                UPDATE "CommerceBillingOutbox"
                   SET delivery_state = CASE :phase
                           WHEN 'lookup' THEN 'lookup'
                           WHEN 'emit' THEN 'emit_requested'
                           ELSE delivery_state
                       END,
                       last_http_status = COALESCE(:http_status, last_http_status),
                       updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id AND lock_token = :lock_token
                SQL);
            $statement->execute([
                'phase' => $phase,
                'http_status' => $httpStatus,
                'id' => $outbox['id'],
                'tenant_id' => $outbox['tenant_id'],
                'lock_token' => $outbox['lock_token'],
            ]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $invoice */
    public function markSucceeded(array $outbox, array $invoice, array $billingMetadata, ?int $httpStatus): void
    {
        $this->assertClaimIdentity($outbox);
        $safeInvoice = $this->boundedJsonObject($invoice, 65536);
        $safeMetadata = $this->boundedJsonObject($billingMetadata, 32768);
        $accessKey = substr(trim((string)($invoice['access_key'] ?? '')), 0, 64);

        $this->db->beginTransaction();
        try {
            $lock = $this->lockActiveClaim($outbox);
            $order = $this->db->prepare(<<<'SQL'
                UPDATE "Order"
                   SET invoice_data = jsonb_set(
                       COALESCE(invoice_data, '{}'::jsonb),
                       '{billing}',
                       COALESCE(invoice_data->'billing', '{}'::jsonb) || CAST(:billing_metadata AS jsonb),
                       true
                   )
                 WHERE tenant_id = :tenant_id AND id = :order_id
                SQL);
            $order->execute([
                'billing_metadata' => json_encode($safeMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'tenant_id' => $outbox['tenant_id'],
                'order_id' => $outbox['order_id'],
            ]);
            if ($order->rowCount() !== 1) {
                throw new \RuntimeException('Billing outbox cannot synchronize missing order metadata.');
            }

            $done = $this->db->prepare(<<<'SQL'
                UPDATE "CommerceBillingOutbox"
                   SET status = 'sent', delivery_state = 'confirmed',
                       billing_access_key = NULLIF(:access_key, ''),
                       billing_response = CAST(:billing_response AS jsonb),
                       last_http_status = :http_status,
                       last_error_code = NULL, last_error = NULL,
                       locked_at = NULL, locked_by = NULL, lock_token = NULL,
                       completed_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id
                   AND status = 'processing' AND lock_token = :lock_token
                SQL);
            $done->execute([
                'access_key' => $accessKey,
                'billing_response' => json_encode($safeInvoice, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'http_status' => $httpStatus,
                'id' => $outbox['id'],
                'tenant_id' => $outbox['tenant_id'],
                'lock_token' => $outbox['lock_token'],
            ]);
            if ($done->rowCount() !== 1) {
                throw new \RuntimeException('Billing outbox claim was lost before success commit.');
            }
            $this->insertAttempt($lock, 'finalize', 'sent', $httpStatus, null, null, [
                'access_key_present' => $accessKey !== '',
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
        array $outbox,
        string $errorCode,
        string $errorMessage,
        bool $deliveryUnknown,
        ?int $httpStatus,
        CommerceBillingOutboxRetryPolicy $retryPolicy
    ): string {
        $this->assertClaimIdentity($outbox);
        $this->db->beginTransaction();
        try {
            $lock = $this->lockActiveClaim($outbox);
            $attempts = (int)($lock['attempts'] ?? 0);
            $maxAttempts = (int)($lock['max_attempts'] ?? 1);
            $dead = $attempts >= $maxAttempts;
            $nextStatus = $dead ? 'dead_letter' : ($deliveryUnknown ? 'delivery_unknown' : 'retry');
            $delay = $dead ? 0 : $retryPolicy->delaySeconds($attempts, (string)$lock['id']);
            $errorCode = substr(trim($errorCode) ?: 'BILLING_OUTBOX_FAILED', 0, 80);
            $errorMessage = $this->sanitizeMessage($errorMessage);

            $statement = $this->db->prepare(<<<'SQL'
                UPDATE "CommerceBillingOutbox"
                   SET status = :status,
                       delivery_state = CASE WHEN CAST(:delivery_unknown AS integer) = 1 THEN 'delivery_unknown' ELSE delivery_state END,
                       available_at = CASE WHEN CAST(:dead AS integer) = 1 THEN available_at ELSE NOW() + make_interval(secs => :delay_seconds) END,
                       last_http_status = :http_status,
                       last_error_code = :error_code,
                       last_error = :error_message,
                       locked_at = NULL, locked_by = NULL, lock_token = NULL,
                       completed_at = CASE WHEN attempts >= max_attempts THEN NOW() ELSE NULL END,
                       updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id
                   AND status = 'processing' AND lock_token = :lock_token
                SQL);
            $statement->execute([
                'status' => $nextStatus,
                'delivery_unknown' => $deliveryUnknown ? 1 : 0,
                'dead' => $dead ? 1 : 0,
                'delay_seconds' => $delay,
                'http_status' => $httpStatus,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'id' => $outbox['id'],
                'tenant_id' => $outbox['tenant_id'],
                'lock_token' => $outbox['lock_token'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Billing outbox claim was lost before failure commit.');
            }
            $this->insertAttempt($lock, 'finalize', $nextStatus, $httpStatus, $errorCode, $errorMessage, [
                'delivery_unknown' => $deliveryUnknown,
                'retry_delay_seconds' => $delay,
                'max_attempts' => $maxAttempts,
            ]);
            $this->db->commit();

            return $nextStatus;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function requeueDeadLetter(string $tenantId, string $orderId, string $actor, string $reason): bool
    {
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare(<<<'SQL'
                SELECT * FROM "CommerceBillingOutbox"
                WHERE tenant_id = :tenant_id AND order_id = :order_id
                FOR UPDATE
                SQL);
            $select->execute(['tenant_id' => $tenantId, 'order_id' => $orderId]);
            $row = $select->fetch();
            if (!is_array($row) || ($row['status'] ?? null) !== 'dead_letter') {
                $this->db->rollBack();
                return false;
            }
            $statement = $this->db->prepare(<<<'SQL'
                UPDATE "CommerceBillingOutbox"
                   SET status = 'retry', delivery_state = 'not_started', attempts = 0,
                       requeue_count = requeue_count + 1, available_at = NOW(),
                       locked_at = NULL, locked_by = NULL, lock_token = NULL,
                       last_error_code = NULL, last_error = NULL,
                       completed_at = NULL, updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id AND status = 'dead_letter'
                SQL);
            $statement->execute(['id' => $row['id'], 'tenant_id' => $tenantId]);
            $this->insertAttempt($row, 'manual_requeue', 'retry', null, null, null, [
                'actor' => substr(trim($actor) ?: 'operator', 0, 120),
                'reason' => substr(trim($reason), 0, 500),
                'previous_attempts' => (int)($row['attempts'] ?? 0),
            ]);
            $this->db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,int|string|null> */
    public function metrics(int $leaseSeconds = 300): array
    {
        $leaseSeconds = max(30, min(3600, $leaseSeconds));
        $row = $this->db->query(<<<SQL
            SELECT
                COUNT(*) FILTER (WHERE status = 'pending')::bigint AS pending,
                COUNT(*) FILTER (WHERE status = 'retry')::bigint AS retry,
                COUNT(*) FILTER (WHERE status = 'delivery_unknown')::bigint AS delivery_unknown,
                COUNT(*) FILTER (WHERE status = 'processing')::bigint AS processing,
                COUNT(*) FILTER (WHERE status = 'dead_letter')::bigint AS dead_letter,
                COUNT(*) FILTER (WHERE status = 'sent')::bigint AS sent,
                COUNT(*) FILTER (WHERE status = 'processing' AND locked_at < NOW() - make_interval(secs => {$leaseSeconds}))::bigint AS stale_leases,
                COALESCE(EXTRACT(EPOCH FROM NOW() - MIN(created_at) FILTER (WHERE status IN ('pending','retry','delivery_unknown') AND available_at <= NOW()))::bigint, 0) AS oldest_due_age_seconds,
                COUNT(DISTINCT tenant_id) FILTER (WHERE status IN ('pending','retry','delivery_unknown','processing'))::bigint AS active_tenants
            FROM "CommerceBillingOutbox"
            SQL)->fetch();

        return is_array($row) ? $row : [];
    }

    /** @return list<array{tenant_id:string,target_host:string}> */
    public function requiredCredentialBindings(): array
    {
        $rows = $this->db->query(<<<'SQL'
            SELECT DISTINCT
                tenant_id,
                LOWER(BTRIM(command->>'target_host')) AS target_host
            FROM "CommerceBillingOutbox"
            WHERE status IN ('pending', 'retry', 'delivery_unknown', 'processing', 'dead_letter')
            ORDER BY tenant_id, target_host
            SQL)->fetchAll() ?: [];

        return array_values(array_filter(array_map(
            static fn(array $row): array => [
                'tenant_id' => trim((string)($row['tenant_id'] ?? '')),
                'target_host' => trim((string)($row['target_host'] ?? '')),
            ],
            $rows
        ), static fn(array $row): bool => $row['tenant_id'] !== '' || $row['target_host'] !== ''));
    }

    private function lockActiveClaim(array $outbox): array
    {
        $statement = $this->db->prepare(<<<'SQL'
            SELECT * FROM "CommerceBillingOutbox"
            WHERE id = :id AND tenant_id = :tenant_id
              AND status = 'processing' AND lock_token = :lock_token
            FOR UPDATE
            SQL);
        $statement->execute([
            'id' => $outbox['id'],
            'tenant_id' => $outbox['tenant_id'],
            'lock_token' => $outbox['lock_token'],
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('Commerce Billing outbox lease is no longer owned by this worker.');
        }

        return $row;
    }

    /** @param array<string,mixed> $metadata */
    private function insertAttempt(
        array $outbox,
        string $phase,
        string $outcome,
        ?int $httpStatus,
        ?string $errorCode,
        ?string $errorMessage,
        array $metadata
    ): void {
        $attempt = (int)($outbox['attempts'] ?? 0);
        $id = 'cboatt_' . substr(hash('sha256', implode('|', [
            (string)$outbox['id'],
            (string)$attempt,
            $phase,
            $outcome,
            bin2hex(random_bytes(8)),
        ])), 0, 40);
        $statement = $this->db->prepare(<<<'SQL'
            INSERT INTO "CommerceBillingOutboxAttempt" (
                id, tenant_id, outbox_id, order_id, attempt_number,
                phase, outcome, http_status, error_code, error_message,
                metadata, created_at
            ) VALUES (
                :id, :tenant_id, :outbox_id, :order_id, :attempt_number,
                :phase, :outcome, :http_status, :error_code, :error_message,
                CAST(:metadata AS jsonb), NOW()
            )
            SQL);
        $statement->execute([
            'id' => $id,
            'tenant_id' => $outbox['tenant_id'],
            'outbox_id' => $outbox['id'],
            'order_id' => $outbox['order_id'],
            'attempt_number' => $attempt,
            'phase' => substr($phase, 0, 80),
            'outcome' => substr($outcome, 0, 80),
            'http_status' => $httpStatus,
            'error_code' => $errorCode !== null ? substr($errorCode, 0, 80) : null,
            'error_message' => $errorMessage !== null ? $this->sanitizeMessage($errorMessage) : null,
            'metadata' => json_encode($this->boundedJsonObject($metadata, 16384), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function assertClaimIdentity(array $outbox): void
    {
        foreach (['id', 'tenant_id', 'order_id', 'lock_token'] as $field) {
            if (trim((string)($outbox[$field] ?? '')) === '') {
                throw new \InvalidArgumentException('Invalid Commerce Billing outbox claim.');
            }
        }
    }

    /** @return array<string,mixed> */
    private function boundedJsonObject(array $value, int $maxBytes): array
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) <= $maxBytes) {
            return $value;
        }

        return [
            'truncated' => true,
            'sha256' => hash('sha256', $encoded),
            'original_bytes' => strlen($encoded),
        ];
    }

    private function sanitizeMessage(string $message): string
    {
        $singleLine = preg_replace('/[\r\n\t]+/', ' ', trim($message)) ?? 'Billing outbox failure';
        $singleLine = preg_replace('/(?i)(x-api-key|authorization|bearer)\s*[:=]?\s*[^\s,;]+/', '$1=[REDACTED]', $singleLine) ?? $singleLine;

        return substr($singleLine, 0, 1000);
    }

    private static function outboxId(string $tenantId, string $orderId): string
    {
        return 'cbout_' . substr(hash('sha256', $tenantId . '|' . $orderId), 0, 40);
    }
}
