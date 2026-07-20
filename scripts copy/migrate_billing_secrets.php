<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Modules\Billing\Native\Billing\Infrastructure\Persistence\BillingSchema;
use BillingService\Billing\Infrastructure\Persistence\BillingSecretMigrator;
use BillingService\Billing\Infrastructure\Persistence\BillingSecretStorageAttestor;
use BillingService\Billing\Infrastructure\Security\BillingSecretAdminConnection;
use BillingService\Billing\Infrastructure\Security\BillingSecretCipherFactory;
use Dotenv\Dotenv;

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, sprintf(
        "[billing-secret-migration] failed_closed error_type=%s error_code=%d\n",
        $exception::class,
        (int)$exception->getCode()
    ));
    exit(1);
});

if (($argv[1] ?? '') !== '--execute' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php scripts/migrate_billing_secrets.php --execute\n");
    exit(2);
}

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}
$legacyRead = strtolower(trim((string)($_ENV['BILLING_SECRET_LEGACY_READ_ENABLED'] ?? getenv('BILLING_SECRET_LEGACY_READ_ENABLED') ?: '')));
if (!in_array($legacyRead, ['true', '1'], true)) {
    fwrite(STDERR, "Billing secret migration requires the completed expand rollout with BILLING_SECRET_LEGACY_READ_ENABLED=true.\n");
    exit(1);
}

try {
    BillingSecretCipherFactory::resetForTests();
    $cipher = BillingSecretCipherFactory::fromEnvironment();
    if (!$cipher->encryptedWritesEnabled()) {
        throw new RuntimeException('Billing secret migration requires the completed encrypted-write rollout.');
    }
    $connection = BillingSecretAdminConnection::fromEnvironment();
    (new BillingSchema($connection))->ensure();
    $migration = (new BillingSecretMigrator($connection, $cipher))->migrateAndRotate();
    $attestation = (new BillingSecretStorageAttestor($connection, $cipher))->requireContract();
    fwrite(STDOUT, json_encode([
        'event' => 'billing_secret_migration_complete',
        'migration' => $migration,
        'attestation' => $attestation,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fwrite(STDOUT, sprintf(
        "BILLING_SECRET_MIGRATION_RECEIPT version=2 key_id=%s migrated=%d rotated=%d remaining_plaintext=0 envelopes_verified=%d\n",
        $attestation['key_id'],
        $migration['migrated'],
        $migration['rotated'],
        $attestation['secrets']
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "[billing-secret-migration] failed_closed error_type=%s error_code=%d\n",
        $exception::class,
        (int)$exception->getCode()
    ));
    exit(1);
}
