<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Persistence;

use BillingService\Billing\Infrastructure\Security\BillingSecretCipher;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Converts legacy plaintext and rotates stale envelopes in one DB transaction.
 *
 * No plaintext is written to a side table, receipt or log. On any failure the
 * transaction rolls back and the validated constraints are not attested.
 */
final class BillingSecretMigrator
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

    /** @return array{rows:int,migrated:int,rotated:int,verified:int,key_id:string} */
    public function migrateAndRotate(): array
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $this->installConstraintMigration();
            $this->assertConstraintsExist();
            $this->connection->exec("SELECT pg_advisory_xact_lock(hashtext('paramascotasec:billing-secret-migration:v1'))");
            $this->connection->exec('LOCK TABLE public.client_branches IN SHARE ROW EXCLUSIVE MODE');

            $rows = $this->connection->query(
                'SELECT id, tenant_id, certificate_password, mail_password
                 FROM public.client_branches
                 ORDER BY id
                 FOR UPDATE'
            )->fetchAll();

            $result = [
                'rows' => 0,
                'migrated' => 0,
                'rotated' => 0,
                'verified' => 0,
                'key_id' => $this->cipher->activeKeyId(),
            ];
            $update = $this->connection->prepare(
                'UPDATE public.client_branches
                 SET certificate_password = :certificate_password,
                     mail_password = :mail_password,
                     updated_at = NOW()
                 WHERE id = :branch_id'
            );

            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('Billing secret migration read an invalid branch row.');
                }
                $branchId = (int)($row['id'] ?? 0);
                $tenantId = trim((string)($row['tenant_id'] ?? ''));
                if ($branchId <= 0 || $tenantId === '') {
                    throw new RuntimeException('Billing secret migration found a branch without tenant ownership.');
                }

                $result['rows']++;
                $certificate = $this->transform(
                    (string)($row['certificate_password'] ?? ''),
                    $tenantId,
                    $branchId,
                    'certificate_password',
                    false,
                    $result
                );
                $mail = $this->transform(
                    $row['mail_password'] === null ? null : (string)$row['mail_password'],
                    $tenantId,
                    $branchId,
                    'mail_password',
                    true,
                    $result
                );

                if (!hash_equals((string)$row['certificate_password'], $certificate)
                    || ($row['mail_password'] !== $mail)
                ) {
                    $update->execute([
                        'branch_id' => $branchId,
                        'certificate_password' => $certificate,
                        'mail_password' => $mail,
                    ]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Billing secret migration lost its locked branch row.');
                    }
                }
            }

            foreach (self::CONSTRAINTS as $constraint) {
                $this->connection->exec(sprintf(
                    'ALTER TABLE public.client_branches VALIDATE CONSTRAINT %s',
                    $this->quoteIdentifier($constraint)
                ));
            }
            $this->assertNoPlaintextRows();

            if ($ownsTransaction) {
                $this->connection->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array{rows:int,migrated:int,rotated:int,verified:int,key_id:string} $result */
    private function transform(
        ?string $value,
        string $tenantId,
        int $branchId,
        string $field,
        bool $nullable,
        array &$result
    ): ?string {
        if ($value === null) {
            if (!$nullable) {
                throw new RuntimeException('Billing required secret unexpectedly contains NULL.');
            }
            return null;
        }

        if (!$this->cipher->isEncrypted($value)) {
            $result['migrated']++;
            return $this->cipher->encrypt($value, $tenantId, $branchId, $field);
        }

        $keyId = $this->cipher->keyId($value);
        $rotated = $this->cipher->rotate($value, $tenantId, $branchId, $field);
        if (!hash_equals($keyId, $this->cipher->activeKeyId())) {
            $result['rotated']++;
        } else {
            $result['verified']++;
        }

        return $rotated;
    }

    private function assertConstraintsExist(): void
    {
        $statement = $this->connection->prepare(
            "SELECT conname
             FROM pg_constraint constraint_info
             JOIN pg_class relation ON relation.oid = constraint_info.conrelid
             JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
             WHERE namespace.nspname = 'public'
               AND relation.relname = 'client_branches'
               AND conname = ANY(CAST(:constraints AS text[]))"
        );
        $statement->execute(['constraints' => '{' . implode(',', self::CONSTRAINTS) . '}']);
        $found = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($found) || count(array_unique($found)) !== count(self::CONSTRAINTS)) {
            throw new RuntimeException('Billing ciphertext constraints are not installed.');
        }
    }

    private function installConstraintMigration(): void
    {
        $path = __DIR__ . '/Migrations/' . self::MIGRATION_FILE;
        $sql = file_get_contents($path);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Billing secret constraint migration is missing.');
        }
        $checksum = hash('sha256', $sql);
        $statement = $this->connection->prepare(
            'SELECT checksum_sha256 FROM public.billing_schema_migrations WHERE version = :version'
        );
        $statement->execute(['version' => self::MIGRATION_FILE]);
        $recorded = $statement->fetchColumn();
        if (is_string($recorded)) {
            if (!hash_equals($recorded, $checksum)) {
                throw new RuntimeException('Billing secret constraint migration checksum mismatch.');
            }
            return;
        }

        $this->connection->exec($sql);
        $receipt = $this->connection->prepare(
            'INSERT INTO public.billing_schema_migrations (version, checksum_sha256)
             VALUES (:version, :checksum)'
        );
        $receipt->execute([
            'version' => self::MIGRATION_FILE,
            'checksum' => $checksum,
        ]);
    }

    private function assertNoPlaintextRows(): void
    {
        $count = (int)$this->connection->query(
            "SELECT COUNT(*)
             FROM public.client_branches
             WHERE certificate_password !~ '^pmbillenc:v1:[A-Za-z0-9_-]+$'
                OR (mail_password IS NOT NULL AND mail_password !~ '^pmbillenc:v1:[A-Za-z0-9_-]+$')"
        )->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException('Billing secret migration plaintext postcondition failed.');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
