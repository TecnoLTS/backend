<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Persistence;

use BillingService\Billing\Domain\Entities\Invoice;
use DateTimeImmutable;
use PDO;

class InvoiceRepository
{
    public function __construct(private readonly PDO $connection) {}

    public function tryAcquireMaintenanceLock(string $accessKey): bool
    {
        $statement = $this->connection->prepare('SELECT pg_try_advisory_lock(hashtext(:lock_key))');
        $statement->execute(['lock_key' => 'invoice-maintenance:' . $accessKey]);

        return filter_var($statement->fetchColumn(), FILTER_VALIDATE_BOOLEAN);
    }

    public function releaseMaintenanceLock(string $accessKey): void
    {
        $statement = $this->connection->prepare('SELECT pg_advisory_unlock(hashtext(:lock_key))');
        $statement->execute(['lock_key' => 'invoice-maintenance:' . $accessKey]);
    }

    public function acquireSourceReferenceLock(array $clientContext, string $sourceReference): void
    {
        $statement = $this->connection->prepare('SELECT pg_advisory_lock(hashtext(:lock_key))');
        $statement->execute(['lock_key' => $this->sourceReferenceLockKey($clientContext, $sourceReference)]);
    }

    public function releaseSourceReferenceLock(array $clientContext, string $sourceReference): void
    {
        $statement = $this->connection->prepare('SELECT pg_advisory_unlock(hashtext(:lock_key))');
        $statement->execute(['lock_key' => $this->sourceReferenceLockKey($clientContext, $sourceReference)]);
    }

    public function acquireSequentialLock(int $branchId, string $environment): void
    {
        $statement = $this->connection->prepare('SELECT pg_advisory_lock(hashtext(:lock_key))');
        $statement->execute(['lock_key' => $this->sequentialLockKey($branchId, $environment)]);
    }

    public function releaseSequentialLock(int $branchId, string $environment): void
    {
        $statement = $this->connection->prepare('SELECT pg_advisory_unlock(hashtext(:lock_key))');
        $statement->execute(['lock_key' => $this->sequentialLockKey($branchId, $environment)]);
    }

    private function sourceReferenceLockKey(array $clientContext, string $sourceReference): string
    {
        return sprintf(
            'invoice-source-reference:%d:%d:%s',
            (int) ($clientContext['client_id'] ?? 0),
            (int) ($clientContext['resolved_branch_id'] ?? $clientContext['branch_id'] ?? 0),
            trim($sourceReference)
        );
    }

    private function sequentialLockKey(int $branchId, string $environment): string
    {
        return sprintf('invoice-sequential:%d:%s', $branchId, trim($environment));
    }

    public function disableRetriesOutsideConfiguredWindow(): int
    {
        $statement = $this->connection->prepare(<<<'SQL'
            UPDATE invoice_headers AS ih
            SET reintento = FALSE,
                updated_at = NOW()
            FROM invoice_retry_settings AS irs
            WHERE COALESCE(ih.reintento, FALSE) = TRUE
              AND irs.ambiente = COALESCE(ih.ambiente, 'pruebas')
              AND (
                    irs.is_active = FALSE
                    OR CURRENT_DATE > ih.issue_date + (irs.max_retry_days * INTERVAL '1 day')
              )
            SQL
        );
        $statement->execute();

        return $statement->rowCount();
    }

    public function disableRetriesExhaustedByAttempts(int $defaultMaxAttempts): int
    {
        $statement = $this->connection->prepare(<<<'SQL'
            UPDATE invoice_headers AS ih
            SET reintento = FALSE,
                updated_at = NOW()
            FROM client_branches AS b
            LEFT JOIN invoice_retry_settings AS irs ON TRUE
            WHERE COALESCE(ih.reintento, FALSE) = TRUE
              AND b.id = ih.branch_id
              AND irs.ambiente = COALESCE(ih.ambiente, 'pruebas')
              AND (
                    COALESCE(ih.intentos, 0) >= COALESCE(irs.max_attempts, :default_max_attempts)
                    OR (
                        COALESCE(ih.ambiente, 'pruebas') = 'produccion'
                        AND COALESCE(b.reintentos_produccion, FALSE) = FALSE
                    )
                    OR (
                        COALESCE(ih.ambiente, 'pruebas') <> 'produccion'
                        AND COALESCE(b.reintentos_test, FALSE) = FALSE
                    )
                )
            SQL
        );
        $statement->bindValue(':default_max_attempts', $defaultMaxAttempts, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    public function incrementRetryAttempts(string $accessKey, array $clientContext): void
    {
        $scopeBranchId = isset($clientContext['branch_id']) && $clientContext['branch_id'] !== null
            ? (int) $clientContext['branch_id']
            : null;

        $sql = 'UPDATE invoice_headers
                SET intentos = COALESCE(intentos, 0) + 1,
                    updated_at = NOW()
                WHERE access_key = :access_key
                  AND client_id = :client_id';

        $parameters = [
            'access_key' => $accessKey,
            'client_id' => (int) $clientContext['client_id'],
        ];

        if ($scopeBranchId !== null) {
            $sql .= '
                  AND branch_id = :resolved_branch_id';
            $parameters['resolved_branch_id'] = (int) $clientContext['resolved_branch_id'];
        }

        $statement = $this->connection->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
    }

    public function findPendingXmlRecoveryCandidates(int $limit, int $minAgeSeconds): array
    {
        $statement = $this->connection->prepare(<<<'SQL'
            SELECT
                ih.access_key,
                ih.issue_date,
                ih.sri_status,
                COALESCE(ih.intentos, 0) AS intentos,
                COALESCE(ih.reintento, FALSE) AS reintento,
                ih.last_sri_check_at,
                ih.authorized_xml_received,
                ih.ambiente AS invoice_environment,
                CASE
                    WHEN COALESCE(ih.ambiente, 'pruebas') = 'produccion' THEN COALESCE(b.reintentos_produccion, FALSE)
                    ELSE COALESCE(b.reintentos_test, FALSE)
                END AS branch_retry_enabled,
                irs.max_retry_days,
                irs.max_attempts,
                irs.delay_seconds,
                ak.id AS api_key_id,
                ak.client_id,
                ak.branch_id AS api_key_branch_id,
                NULL::BIGINT AS branch_id,
                ak.name AS api_key_name,
                c.ruc AS client_ruc,
                c.business_name AS client_business_name,
                c.trade_name AS client_trade_name,
                c.phone AS client_phone,
                c.email AS client_email,
                c.address AS client_address,
                b.id AS resolved_branch_id,
                b.code AS branch_code,
                b.emission_point,
                b.branch_name,
                b.address AS branch_address,
                b.reintentos_test,
                b.reintentos_produccion,
                b.logo_path,
                b.certificate_path,
                b.certificate_password,
                b.mail_enabled,
                b.mail_host,
                b.mail_port,
                b.mail_encryption,
                b.mail_username,
                b.mail_password,
                b.mail_from_address,
                b.mail_from_name,
                b.reply_to_address,
                b.reply_to_name
            FROM invoice_headers ih
            INNER JOIN clients c ON c.id = ih.client_id AND c.is_active = TRUE
            LEFT JOIN api_keys ak ON ak.id = ih.api_key_id
            LEFT JOIN client_branches b ON b.id = ih.branch_id
            LEFT JOIN invoice_retry_settings irs
                ON irs.ambiente = COALESCE(ih.ambiente, 'pruebas')
               AND irs.is_active = TRUE
            WHERE COALESCE(ih.reintento, FALSE) = TRUE
              AND COALESCE(ih.authorized_xml_received, FALSE) = FALSE
              AND ih.sri_status IN ('RECIBIDA', 'EN PROCESAMIENTO', 'AUTORIZADO', 'PENDING', 'UNKNOWN')
              AND (
                    (COALESCE(ih.ambiente, 'pruebas') = 'produccion' AND COALESCE(b.reintentos_produccion, FALSE) = TRUE)
                    OR (COALESCE(ih.ambiente, 'pruebas') <> 'produccion' AND COALESCE(b.reintentos_test, FALSE) = TRUE)
              )
              AND irs.max_retry_days IS NOT NULL
              AND CURRENT_DATE <= ih.issue_date + (irs.max_retry_days * INTERVAL '1 day')
              AND (
                    ih.last_sri_check_at IS NULL
                    OR ih.last_sri_check_at <= NOW() - (GREATEST(COALESCE(irs.delay_seconds, :min_age_seconds), :min_age_seconds) * INTERVAL '1 second')
              )
            ORDER BY ih.last_sri_check_at ASC NULLS FIRST, ih.created_at ASC
            LIMIT :limit
            SQL
        );

        $statement->bindValue(':min_age_seconds', $minAgeSeconds, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function nextSequentialForBranchAndEnvironment(int $branchId, string $environment): string
    {
        $this->connection->beginTransaction();

        try {
            $ensureStatement = $this->connection->prepare(
                'INSERT INTO branch_sequences (branch_id, ambiente, current_value, updated_at)
                 VALUES (:branch_id, :ambiente, 0, NOW())
                 ON CONFLICT (branch_id, ambiente) DO NOTHING'
            );
            $ensureStatement->execute([
                'branch_id' => $branchId,
                'ambiente' => $environment,
            ]);

            $lockStatement = $this->connection->prepare(
                'SELECT current_value
                   FROM branch_sequences
                  WHERE branch_id = :branch_id
                    AND ambiente = :ambiente
                  FOR UPDATE'
            );
            $lockStatement->execute([
                'branch_id' => $branchId,
                'ambiente' => $environment,
            ]);

            $candidate = max(1, ((int) $lockStatement->fetchColumn()) + 1);
            $usageStatement = $this->connection->prepare(
                'SELECT access_key,
                        source_reference,
                        UPPER(COALESCE(sri_status, \'\')) AS sri_status
                   FROM invoice_headers
                  WHERE branch_id = :branch_id
                    AND ambiente = :ambiente
                    AND sequential = :sequential
                    AND cancelled_at IS NULL
                    AND replacement_access_key IS NULL
                    AND (
                        UPPER(COALESCE(sri_status, \'\')) IN (
                            \'AUTORIZADO\',
                            \'AUTHORIZED\',
                            \'DEVUELTA\',
                            \'EN PROCESAMIENTO\',
                            \'NO AUTORIZADO\',
                            \'RECIBIDA\',
                            \'RECHAZADO\',
                            \'REJECTED\',
                            \'UNKNOWN\'
                        )
                        OR (
                            UPPER(COALESCE(sri_status, \'\')) = \'PENDING\'
                            AND raw_response IS NOT NULL
                        )
                    )
                  ORDER BY CASE
                        WHEN UPPER(COALESCE(sri_status, \'\')) IN (\'AUTORIZADO\', \'AUTHORIZED\') THEN 0
                        ELSE 1
                    END,
                    created_at DESC,
                    id DESC
                  LIMIT 1'
            );

            while (true) {
                $sequential = str_pad((string) $candidate, 9, '0', STR_PAD_LEFT);
                $usageStatement->execute([
                    'branch_id' => $branchId,
                    'ambiente' => $environment,
                    'sequential' => $sequential,
                ]);
                $usage = $usageStatement->fetch();

                if (!is_array($usage)) {
                    break;
                }

                $candidate++;
            }

            $this->connection->commit();

            return str_pad((string) $candidate, 9, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    public function markSequentialConsumed(int $branchId, string $environment, string $sequential): void
    {
        $consumedValue = (int) ltrim($sequential, '0');
        if ($consumedValue < 1) {
            return;
        }

        $statement = $this->connection->prepare(
            'INSERT INTO branch_sequences (branch_id, ambiente, current_value, updated_at)
             VALUES (:branch_id, :ambiente, :current_value, NOW())
             ON CONFLICT (branch_id, ambiente)
             DO UPDATE SET current_value = GREATEST(branch_sequences.current_value, EXCLUDED.current_value),
                           updated_at = NOW()'
        );
        $statement->execute([
            'branch_id' => $branchId,
            'ambiente' => $environment,
            'current_value' => $consumedValue,
        ]);
    }

    public function createDraftInvoice(array $clientContext, Invoice $invoice, array $requestPayload, string $signedXmlPath): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO invoice_headers (
                client_id,
                branch_id,
                api_key_id,
                source_reference,
                access_key,
                issue_date,
                customer_name,
                customer_identification,
                customer_email,
                customer_address,
                subtotal_without_tax,
                total_tax,
                total_with_tax,
                payment_method_code,
                payment_method_label,
                establishment_code,
                emission_point,
                sequential,
                ambiente,
                sri_status,
                reintento,
                intentos,
                raw_request,
                signed_xml_path
            ) VALUES (
                :client_id,
                :branch_id,
                :api_key_id,
                :source_reference,
                :access_key,
                :issue_date,
                :customer_name,
                :customer_identification,
                :customer_email,
                :customer_address,
                :subtotal_without_tax,
                :total_tax,
                :total_with_tax,
                :payment_method_code,
                :payment_method_label,
                :establishment_code,
                :emission_point,
                :sequential,
                :ambiente,
                :sri_status,
                :reintento,
                :intentos,
                CAST(:raw_request AS JSONB),
                :signed_xml_path
            ) RETURNING id'
        );

        $params = [
            'client_id' => (int) $clientContext['client_id'],
            'branch_id' => (int) $clientContext['resolved_branch_id'],
            'api_key_id' => (int) $clientContext['api_key_id'],
            'source_reference' => $requestPayload['additional_info']['order_id'] ?? null,
            'access_key' => $invoice->accessKey()->value(),
            'issue_date' => $invoice->issueDate()->format('Y-m-d'),
            'customer_name' => $invoice->customerName(),
            'customer_identification' => $invoice->customerIdentification()->value(),
            'customer_email' => $invoice->customerEmail() !== '' ? $invoice->customerEmail() : null,
            'customer_address' => $invoice->customerAddress() !== '' ? $invoice->customerAddress() : null,
            'subtotal_without_tax' => $invoice->subtotal()->amount(),
            'total_tax' => $invoice->totalTax()->amount(),
            'total_with_tax' => $invoice->total()->amount(),
            'payment_method_code' => $invoice->paymentMethodCode(),
            'payment_method_label' => $invoice->paymentMethodLabel(),
            'establishment_code' => $invoice->establishment(),
            'emission_point' => $invoice->emissionPoint(),
            'sequential' => $invoice->sequential(),
            'ambiente' => $invoice->environment()->isProduccion() ? 'produccion' : 'pruebas',
            'sri_status' => $invoice->status(),
            'reintento' => 'false',
            'intentos' => 0,
            'raw_request' => json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'signed_xml_path' => $signedXmlPath,
        ];

        $statement->execute($params);

        $invoiceId = (int) $statement->fetchColumn();
        $this->insertInvoiceDetails($invoiceId, $invoice);

        return $invoiceId;
    }

    public function markInvoiceAsManualReplacement(
        string $oldAccessKey,
        array $clientContext,
        string $newAccessKey,
        string $reason
    ): void {
        $scopeBranchId = isset($clientContext['branch_id']) && $clientContext['branch_id'] !== null
            ? (int) $clientContext['branch_id']
            : null;

        $patch = [
            'manual_reissue' => true,
            'manual_reissue_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'replacement_access_key' => $newAccessKey,
            'cancellation_reason' => $reason,
        ];

        $sql = 'UPDATE invoice_headers
                SET sri_status = :sri_status,
                    reintento = FALSE,
                    cancelled_at = NOW(),
                    cancellation_reason = :reason,
                    replacement_access_key = :new_access_key,
                    raw_response = COALESCE(raw_response, \'{}\'::jsonb) || CAST(:raw_response_patch AS JSONB),
                    updated_at = NOW()
                WHERE access_key = :old_access_key
                  AND client_id = :client_id
                  AND UPPER(COALESCE(sri_status, \'\')) <> \'AUTORIZADO\'';

        $parameters = [
            'sri_status' => 'ANULADA_LOCAL',
            'reason' => $reason,
            'new_access_key' => $newAccessKey,
            'raw_response_patch' => json_encode($patch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'old_access_key' => $oldAccessKey,
            'client_id' => (int) $clientContext['client_id'],
        ];

        if ($scopeBranchId !== null) {
            $sql .= '
                  AND branch_id = :resolved_branch_id';
            $parameters['resolved_branch_id'] = (int) $clientContext['resolved_branch_id'];
        }

        $statement = $this->connection->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('No se pudo marcar la factura original como anulada localmente.');
        }
    }

    public function linkReplacementToOriginal(string $newAccessKey, array $clientContext, string $oldAccessKey): void
    {
        $scopeBranchId = isset($clientContext['branch_id']) && $clientContext['branch_id'] !== null
            ? (int) $clientContext['branch_id']
            : null;

        $sql = 'UPDATE invoice_headers
                SET replaced_access_key = :old_access_key,
                    updated_at = NOW()
                WHERE access_key = :new_access_key
                  AND client_id = :client_id';

        $parameters = [
            'old_access_key' => $oldAccessKey,
            'new_access_key' => $newAccessKey,
            'client_id' => (int) $clientContext['client_id'],
        ];

        if ($scopeBranchId !== null) {
            $sql .= '
                  AND branch_id = :resolved_branch_id';
            $parameters['resolved_branch_id'] = (int) $clientContext['resolved_branch_id'];
        }

        $statement = $this->connection->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
    }

    public function updateStatus(string $accessKey, array $clientContext, array $data): void
    {
        $scopeBranchId = isset($clientContext['branch_id']) && $clientContext['branch_id'] !== null
            ? (int) $clientContext['branch_id']
            : null;

        $sql = 'UPDATE invoice_headers
                SET sri_status = :sri_status,
                    authorization_number = COALESCE(:authorization_number, authorization_number),
                    authorization_date = COALESCE(:authorization_date, authorization_date),
                    sri_messages = COALESCE(CAST(:sri_messages AS JSONB), sri_messages),
                    raw_response = COALESCE(CAST(:raw_response AS JSONB), raw_response),
                    authorized_xml_path = COALESCE(:authorized_xml_path, authorized_xml_path),
                    authorized_xml_received = :authorized_xml_received,
                    reintento = COALESCE(:reintento, reintento),
                    last_sri_check_at = NOW(),
                    updated_at = NOW()
                WHERE access_key = :access_key
                  AND client_id = :client_id';

        $parameters = [
            'sri_status' => $data['sri_status'],
            'authorization_number' => $data['authorization_number'] ?? null,
            'authorization_date' => $this->normalizeDate($data['authorization_date'] ?? null),
            'sri_messages' => isset($data['sri_messages']) ? json_encode($data['sri_messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'raw_response' => isset($data['raw_response']) ? json_encode($data['raw_response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'authorized_xml_path' => $data['authorized_xml_path'] ?? null,
            'authorized_xml_received' => (bool) ($data['authorized_xml_received'] ?? false),
            'reintento' => array_key_exists('reintento', $data) ? (bool) $data['reintento'] : null,
            'access_key' => $accessKey,
            'client_id' => (int) $clientContext['client_id'],
        ];

        if ($scopeBranchId !== null) {
            $sql .= '
                  AND branch_id = :resolved_branch_id';
            $parameters['resolved_branch_id'] = (int) $clientContext['resolved_branch_id'];
        }

        $statement = $this->connection->prepare($sql);

        foreach ($parameters as $name => $value) {
            $type = match (true) {
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue(':' . $name, $value, $type);
        }

        $statement->execute();
    }

    public function hasMailBeenSent(string $accessKey, array $clientContext): bool
    {
        $invoice = $this->findInvoiceForClient($accessKey, $clientContext);

        return is_array($invoice) && !empty($invoice['mail_sent_at']);
    }

    public function markMailAsSent(string $accessKey, array $clientContext): void
    {
        $scopeBranchId = isset($clientContext['branch_id']) && $clientContext['branch_id'] !== null
            ? (int) $clientContext['branch_id']
            : null;

        $sql = 'UPDATE invoice_headers
                SET mail_sent_at = COALESCE(mail_sent_at, NOW()),
                    updated_at = NOW()
                WHERE access_key = :access_key
                  AND client_id = :client_id';

        $parameters = [
            'access_key' => $accessKey,
            'client_id' => (int) $clientContext['client_id'],
        ];

        if ($scopeBranchId !== null) {
            $sql .= '
                  AND branch_id = :resolved_branch_id';
            $parameters['resolved_branch_id'] = (int) $clientContext['resolved_branch_id'];
        }

        $statement = $this->connection->prepare($sql);

        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->execute();
    }

    public function findInvoiceForClient(string $accessKey, array $clientContext): ?array
    {
        $branchId = isset($clientContext['branch_id']) && $clientContext['branch_id'] !== null
            ? (int) $clientContext['branch_id']
            : null;

        $sql = 'SELECT *
                FROM invoice_headers
                WHERE access_key = :access_key
                  AND client_id = :client_id';

        $parameters = [
            'access_key' => $accessKey,
            'client_id' => (int) $clientContext['client_id'],
        ];

        if ($branchId !== null) {
            $sql .= '
                  AND branch_id = :branch_id';
            $parameters['branch_id'] = $branchId;
        }

        $sql .= '
                LIMIT 1';

        $statement = $this->connection->prepare($sql);

        foreach ($parameters as $name => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $statement->bindValue(':' . $name, $value, $type);
        }

        $statement->execute();

        $invoice = $statement->fetch();

        return is_array($invoice) ? $invoice : null;
    }

    public function findActiveInvoiceBySourceReference(array $clientContext, string $sourceReference): ?array
    {
        $sourceReference = trim($sourceReference);
        if ($sourceReference === '') {
            return null;
        }

        $branchId = isset($clientContext['branch_id']) && $clientContext['branch_id'] !== null
            ? (int) $clientContext['branch_id']
            : null;

        $sql = 'SELECT *
                FROM invoice_headers
                WHERE client_id = :client_id
                  AND source_reference = :source_reference
                  AND cancelled_at IS NULL
                  AND replacement_access_key IS NULL
                  AND (
                      UPPER(COALESCE(sri_status, \'\')) IN (
                          \'AUTORIZADO\',
                          \'AUTHORIZED\',
                          \'DEVUELTA\',
                          \'EN PROCESAMIENTO\',
                          \'NO AUTORIZADO\',
                          \'RECIBIDA\',
                          \'RECHAZADO\',
                          \'REJECTED\',
                          \'UNKNOWN\'
                      )
                      OR (
                          UPPER(COALESCE(sri_status, \'\')) = \'PENDING\'
                          AND raw_response IS NOT NULL
                      )
                  )';

        $parameters = [
            'client_id' => (int) $clientContext['client_id'],
            'source_reference' => $sourceReference,
        ];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = :branch_id';
            $parameters['branch_id'] = $branchId;
        }

        $sql .= ' ORDER BY
                    CASE WHEN UPPER(COALESCE(sri_status, \'\')) = \'AUTORIZADO\' THEN 0 ELSE 1 END,
                    issue_date DESC,
                    created_at DESC,
                    id DESC
                  LIMIT 1';

        $statement = $this->connection->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        $invoice = $statement->fetch();

        return is_array($invoice) ? $invoice : null;
    }

    public function listRideInvoicesForClient(array $clientContext, int $limit = 100, bool $includeCancelled = false): array
    {
        $branchId = isset($clientContext['branch_id']) && $clientContext['branch_id'] !== null
            ? (int) $clientContext['branch_id']
            : null;

        $limit = max(1, min(300, $limit));
        $sql = 'SELECT
                    id,
                    source_reference,
                    access_key,
                    authorization_number,
                    authorization_date,
                    issue_date,
                    customer_name,
                    customer_identification,
                    customer_email,
                    total_tax,
                    total_with_tax,
                    establishment_code,
                    emission_point,
                    sequential,
                    ambiente,
                    sri_status,
                    cancelled_at,
                    cancellation_reason,
                    replacement_access_key,
                    replaced_access_key,
                    mail_sent_at,
                    raw_request,
                    created_at,
                    updated_at
                FROM invoice_headers
                WHERE client_id = :client_id';

        $parameters = [
            'client_id' => (int) $clientContext['client_id'],
        ];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = :branch_id';
            $parameters['branch_id'] = $branchId;
        }

        if (!$includeCancelled) {
            $sql .= ' AND cancelled_at IS NULL
                      AND replacement_access_key IS NULL
                      AND UPPER(COALESCE(sri_status, \'\')) NOT IN (
                          \'ANULADA_LOCAL\',
                          \'CANCELADA_LOCAL\',
                          \'CANCELLED\',
                          \'CANCELED\'
                      )';
        }

        $sql .= ' ORDER BY issue_date DESC,
                           establishment_code DESC,
                           emission_point DESC,
                           sequential DESC,
                           created_at DESC,
                           id DESC
                  LIMIT :limit';

        $statement = $this->connection->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function findInvoiceDetailsForHeader(int $invoiceHeaderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT
                line_number,
                product_code,
                auxiliary_code,
                description,
                additional_detail,
                quantity,
                unit_price,
                discount,
                line_subtotal,
                tax_amount,
                tax_rate
            FROM invoice_details
            WHERE invoice_header_id = :invoice_header_id
            ORDER BY line_number ASC'
        );
        $statement->bindValue(':invoice_header_id', $invoiceHeaderId, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function insertInvoiceDetails(int $invoiceId, Invoice $invoice): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO invoice_details (
                invoice_header_id,
                line_number,
                product_code,
                auxiliary_code,
                description,
                additional_detail,
                quantity,
                unit_price,
                discount,
                line_subtotal,
                tax_amount,
                tax_rate
            ) VALUES (
                :invoice_header_id,
                :line_number,
                :product_code,
                :auxiliary_code,
                :description,
                :additional_detail,
                :quantity,
                :unit_price,
                :discount,
                :line_subtotal,
                :tax_amount,
                :tax_rate
            )'
        );

        foreach ($invoice->items() as $index => $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unitPrice'] ?? 0);
            $discount = (float) ($item['discount'] ?? 0);
            $lineSubtotal = (float) ($item['lineSubtotal'] ?? (($quantity * $unitPrice) - $discount));
            $taxAmount = (float) ($item['taxAmount'] ?? ($lineSubtotal * ((float) ($item['taxRate'] ?? 15) / 100)));

            $statement->execute([
                'invoice_header_id' => $invoiceId,
                'line_number' => $index + 1,
                'product_code' => $item['code'] ?? null,
                'auxiliary_code' => $item['auxiliary_code'] ?? null,
                'description' => $item['description'] ?? '',
                'additional_detail' => $item['additional_detail'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'line_subtotal' => round($lineSubtotal, 6),
                'tax_amount' => round($taxAmount, 6),
                'tax_rate' => round((float) ($item['taxRate'] ?? 15), 2),
            ]);
        }
    }

    private function normalizeDate(DateTimeImmutable|string|null $value): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }
}
