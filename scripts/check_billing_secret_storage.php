<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BillingService\Billing\Infrastructure\Persistence\BillingSecretStorageAttestor;
use BillingService\Billing\Infrastructure\Security\BillingSecretAdminConnection;
use BillingService\Billing\Infrastructure\Security\BillingSecretCipherFactory;
use Dotenv\Dotenv;

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, sprintf(
        "[billing-secret-storage] failed_closed error_type=%s error_code=%d\n",
        $exception::class,
        (int)$exception->getCode()
    ));
    exit(1);
});

if (($argv[1] ?? '') !== '--require-contract' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php scripts/check_billing_secret_storage.php --require-contract\n");
    exit(2);
}

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

try {
    BillingSecretCipherFactory::resetForTests();
    $cipher = BillingSecretCipherFactory::fromEnvironment();
    if ($cipher->legacyPlaintextReadEnabled()) {
        throw new RuntimeException('Billing secret contract gate requires legacy plaintext reads to be disabled.');
    }
    if (!$cipher->encryptedWritesEnabled()) {
        throw new RuntimeException('Billing secret contract gate requires encrypted writes.');
    }
    $connection = BillingSecretAdminConnection::fromEnvironment();
    $attestation = (new BillingSecretStorageAttestor($connection, $cipher))->requireContract();
    fwrite(STDOUT, json_encode([
        'event' => 'billing_secret_storage_contract_verified',
        'attestation' => $attestation,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    fwrite(STDOUT, sprintf(
        "BILLING_SECRET_STORAGE_GATE version=2 constraints_validated=1 plaintext=0 envelopes_verified=%d key_id=%s\n",
        $attestation['secrets'],
        $attestation['key_id']
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "[billing-secret-storage] failed_closed error_type=%s error_code=%d\n",
        $exception::class,
        (int)$exception->getCode()
    ));
    exit(1);
}
