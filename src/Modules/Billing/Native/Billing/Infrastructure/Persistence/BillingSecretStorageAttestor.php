<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Persistence;

use BillingService\Billing\Infrastructure\Security\BillingSecretCipher;
use PDO;
use RuntimeException;
use Throwable;

final class BillingSecretStorageAttestor
{
    private const MIGRATION_FILE = '002_enforce_billing_secret_ciphertexts.sql';
    private const CONSTRAINTS = [
        'client_branches_certificate_password_ciphertext_check',
        'client_branches_mail_password_ciphertext_check',
    ];

    public function __construct(
        private readonly PDO $connection,
        private readonly BillingSecretCipher $cipher
    ) {
    }

    /** @return array{rows:int,secrets:int,key_id:string,constraints:int} */
    public function requireContract(): array
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }
        try {
            $this->assertMigrationReceipt();
            $validated = $this->validatedConstraintCount();
            if ($validated !== count(self::CONSTRAINTS)) {
                throw new RuntimeException('Billing ciphertext constraints are not validated.');
            }

            $this->connection->exec('LOCK TABLE public.client_branches IN SHARE MODE');
            $rows = $this->connection->query(
                'SELECT id, tenant_id, certificate_password, mail_password
                 FROM public.client_branches
                 ORDER BY id'
            )->fetchAll();
            $attestation = [
                'rows' => 0,
                'secrets' => 0,
                'key_id' => $this->cipher->activeKeyId(),
                'constraints' => $validated,
            ];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('Billing secret attestation read an invalid row.');
                }
                $tenantId = trim((string)($row['tenant_id'] ?? ''));
                $branchId = (int)($row['id'] ?? 0);
                if ($tenantId === '' || $branchId <= 0) {
                    throw new RuntimeException('Billing secret attestation found incomplete tenant context.');
                }
                $attestation['rows']++;
                foreach (['certificate_password', 'mail_password'] as $field) {
                    $value = $row[$field] ?? null;
                    if ($value === null && $field === 'mail_password') {
                        continue;
                    }
                    if (!is_string($value) || !$this->cipher->isEncrypted($value)) {
                        throw new RuntimeException('Billing plaintext secret remains in storage.');
                    }
                    if (!hash_equals($this->cipher->activeKeyId(), $this->cipher->keyId($value))) {
                        throw new RuntimeException('Billing secret rotation to the active key is incomplete.');
                    }
                    // Strict decrypt authenticates both envelope and row-bound AAD.
                    $plaintext = $this->cipher->decrypt($value, $tenantId, $branchId, $field);
                    $plaintext = str_repeat("\0", strlen($plaintext));
                    $attestation['secrets']++;
                }
            }

            if ($ownsTransaction) {
                $this->connection->commit();
            }
            return $attestation;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function requireValidatedSchema(): void
    {
        if ($this->validatedConstraintCount() !== count(self::CONSTRAINTS)) {
            throw new RuntimeException('Billing secret storage contract is not active.');
        }
    }

    private function assertMigrationReceipt(): void
    {
        $path = __DIR__ . '/Migrations/' . self::MIGRATION_FILE;
        $sql = file_get_contents($path);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Billing V002 source is unavailable.');
        }
        $statement = $this->connection->prepare(
            'SELECT checksum_sha256
             FROM public.billing_schema_migrations
             WHERE version = :version'
        );
        $statement->execute(['version' => self::MIGRATION_FILE]);
        $recorded = $statement->fetchColumn();
        if (!is_string($recorded) || !hash_equals($recorded, hash('sha256', $sql))) {
            throw new RuntimeException('Billing V002 checksum receipt is absent or invalid.');
        }
    }

    private function validatedConstraintCount(): int
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*)
             FROM pg_constraint constraint_info
             JOIN pg_class relation ON relation.oid = constraint_info.conrelid
             JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
             WHERE namespace.nspname = 'public'
               AND relation.relname = 'client_branches'
               AND constraint_info.convalidated = TRUE
               AND constraint_info.conname = ANY(CAST(:constraints AS text[]))"
        );
        $statement->execute(['constraints' => '{' . implode(',', self::CONSTRAINTS) . '}']);
        return (int)$statement->fetchColumn();
    }
}
