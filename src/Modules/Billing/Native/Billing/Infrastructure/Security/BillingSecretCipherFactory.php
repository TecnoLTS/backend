<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Security;

use RuntimeException;

final class BillingSecretCipherFactory
{
    private static ?BillingSecretCipher $instance = null;

    public static function fromEnvironment(): BillingSecretCipher
    {
        if (self::$instance instanceof BillingSecretCipher) {
            return self::$instance;
        }

        $path = $_ENV['BILLING_SECRET_KEYRING_FILE'] ?? getenv('BILLING_SECRET_KEYRING_FILE');
        if (!is_string($path) || trim($path) === '') {
            throw new RuntimeException('BILLING_SECRET_KEYRING_FILE is required.');
        }

        $legacyRead = $_ENV['BILLING_SECRET_LEGACY_READ_ENABLED']
            ?? getenv('BILLING_SECRET_LEGACY_READ_ENABLED');
        $legacyRead = is_string($legacyRead) ? strtolower(trim($legacyRead)) : '';
        if (!in_array($legacyRead, ['', 'false', 'true', '0', '1'], true)) {
            throw new RuntimeException('BILLING_SECRET_LEGACY_READ_ENABLED must be true or false.');
        }

        $writeMode = $_ENV['BILLING_SECRET_WRITE_MODE'] ?? getenv('BILLING_SECRET_WRITE_MODE');
        $writeMode = is_string($writeMode) ? strtolower(trim($writeMode)) : '';
        $writeMode = $writeMode === '' ? BillingSecretCipher::WRITE_MODE_ENCRYPTED : $writeMode;
        if (!in_array($writeMode, [BillingSecretCipher::WRITE_MODE_LEGACY, BillingSecretCipher::WRITE_MODE_ENCRYPTED], true)) {
            throw new RuntimeException('BILLING_SECRET_WRITE_MODE must be legacy or encrypted.');
        }

        self::$instance = new BillingSecretCipher(
            FileKeyringDataKeyWrapper::fromFile($path),
            in_array($legacyRead, ['true', '1'], true),
            $writeMode
        );
        return self::$instance;
    }

    public static function resetForTests(): void
    {
        self::$instance = null;
    }
}
