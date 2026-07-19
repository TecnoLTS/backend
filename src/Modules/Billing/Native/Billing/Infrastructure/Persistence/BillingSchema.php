<?php

declare(strict_types=1);

namespace App\Modules\Billing\Native\Billing\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Installs the Billing-owned relational schema from immutable migrations.
 *
 * Migrations are checksum-pinned. A changed migration must be added with a new
 * version instead of silently rewriting an installation that already ran.
 */
final class BillingSchema
{
    /** @var list<string> */
    private const MIGRATIONS = [
        '001_create_billing_core.sql',
        '003_add_invoice_detail_tax_identity.sql',
    ];

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'clients',
        'client_branches',
        'billing_customers',
        'api_keys',
        'invoice_retry_settings',
        'branch_sequences',
        'invoice_headers',
        'invoice_details',
        'billing_domain_events',
    ];

    public function __construct(private readonly PDO $connection)
    {
    }

    public function ensure(): void
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $this->ensureMigrationLedger();
            foreach (self::MIGRATIONS as $migrationFile) {
                $this->applyMigration($migrationFile);
            }
            $this->assertRequiredTables();
            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function ensureMigrationLedger(): void
    {
        $this->connection->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS billing_schema_migrations (
                version text PRIMARY KEY,
                checksum_sha256 text NOT NULL
                    CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$'),
                applied_at timestamp with time zone NOT NULL DEFAULT clock_timestamp()
            )
            SQL
        );
        $this->connection->exec('REVOKE ALL PRIVILEGES ON billing_schema_migrations FROM PUBLIC');
    }

    private function applyMigration(string $migrationFile): void
    {
        $path = __DIR__ . '/Migrations/' . $migrationFile;
        $sql = file_get_contents($path);
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException("Billing migration is missing or empty: {$migrationFile}");
        }
        $checksum = hash('sha256', $sql);

        $statement = $this->connection->prepare(
            'SELECT checksum_sha256 FROM billing_schema_migrations WHERE version = :version'
        );
        $statement->execute(['version' => $migrationFile]);
        $recorded = $statement->fetchColumn();
        if (is_string($recorded)) {
            if (!hash_equals($recorded, $checksum)) {
                throw new RuntimeException(
                    "Billing migration checksum mismatch: {$migrationFile}; add a new version instead of editing history"
                );
            }
            return;
        }

        $this->connection->exec($sql);
        $receipt = $this->connection->prepare(
            'INSERT INTO billing_schema_migrations (version, checksum_sha256)
             VALUES (:version, :checksum)'
        );
        $receipt->execute([
            'version' => $migrationFile,
            'checksum' => $checksum,
        ]);
    }

    private function assertRequiredTables(): void
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*)
             FROM pg_class relation
             JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
             WHERE namespace.nspname = 'public'
               AND relation.relkind IN ('r', 'p')
               AND relation.relname = ANY(CAST(:table_names AS text[]))"
        );
        $literal = '{' . implode(',', self::REQUIRED_TABLES) . '}';
        $statement->execute(['table_names' => $literal]);
        $found = (int)$statement->fetchColumn();
        if ($found !== count(self::REQUIRED_TABLES)) {
            throw new RuntimeException(sprintf(
                'Billing schema is incomplete: expected %d owner tables, found %d',
                count(self::REQUIRED_TABLES),
                $found
            ));
        }
    }
}
