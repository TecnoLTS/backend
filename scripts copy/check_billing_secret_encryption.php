<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BillingService\Billing\Infrastructure\Security\BillingSecretCipher;
use BillingService\Billing\Infrastructure\Security\FileKeyringDataKeyWrapper;
use BillingService\Billing\Infrastructure\Support\ClientConfigurationResolver;

/** @param callable():void $operation */
function expectSecretFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

/** @param array<string,string> $keys */
function writeProbeKeyring(string $path, string $activeKeyId, array $keys, int $mode = 0600): void
{
    $document = [
        'version' => 1,
        'active_key_id' => $activeKeyId,
        'keys' => $keys,
    ];
    file_put_contents(
        $path,
        json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    chmod($path, $mode);
}

$directory = sys_get_temp_dir() . '/billing-secret-probe-' . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Could not create Billing secret probe directory.');
}
$keyringPath = $directory . '/keyring.json';
$oldKey = base64_encode(random_bytes(32));
$newKey = base64_encode(random_bytes(32));
$plaintext = 'probe-secret-value-that-must-not-leak';

try {
    writeProbeKeyring($keyringPath, 'old-key', ['old-key' => $oldKey]);
    $oldCipher = new BillingSecretCipher(FileKeyringDataKeyWrapper::fromFile($keyringPath));
    $compatibilityCipher = new BillingSecretCipher(
        FileKeyringDataKeyWrapper::fromFile($keyringPath),
        true,
        BillingSecretCipher::WRITE_MODE_LEGACY
    );
    $expandCipher = new BillingSecretCipher(
        FileKeyringDataKeyWrapper::fromFile($keyringPath),
        true,
        BillingSecretCipher::WRITE_MODE_ENCRYPTED
    );
    if ($compatibilityCipher->decryptStored($plaintext, 'tenant-a', 41, 'certificate_password') !== $plaintext
        || $compatibilityCipher->prepareForStorage($plaintext, 'tenant-a', 41, 'certificate_password') !== $plaintext
        || !$expandCipher->isEncrypted($expandCipher->prepareForStorage($plaintext, 'tenant-a', 41, 'certificate_password'))
    ) {
        throw new RuntimeException('Billing compatibility and encrypted-write phases are not separated.');
    }
    expectSecretFailure(
        static fn() => new BillingSecretCipher(
            FileKeyringDataKeyWrapper::fromFile($keyringPath),
            false,
            BillingSecretCipher::WRITE_MODE_LEGACY
        ),
        'Billing accepted legacy writes without the compatibility read boundary.'
    );
    expectSecretFailure(
        static fn() => $oldCipher->decryptStored($plaintext, 'tenant-a', 41, 'certificate_password'),
        'Billing contract phase accepted legacy plaintext.'
    );
    $envelope = $oldCipher->encrypt($plaintext, 'tenant-a', 41, 'certificate_password');

    if (!str_starts_with($envelope, BillingSecretCipher::PREFIX)
        || str_contains($envelope, $plaintext)
        || $oldCipher->decrypt($envelope, 'tenant-a', 41, 'certificate_password') !== $plaintext
        || $oldCipher->keyId($envelope) !== 'old-key'
    ) {
        throw new RuntimeException('Billing envelope round-trip or ciphertext marker failed.');
    }

    expectSecretFailure(
        static fn() => $oldCipher->decrypt($envelope, 'tenant-b', 41, 'certificate_password'),
        'Billing ciphertext could be moved across tenants.'
    );
    expectSecretFailure(
        static fn() => $oldCipher->decrypt($envelope, 'tenant-a', 42, 'certificate_password'),
        'Billing ciphertext could be moved across branches.'
    );
    expectSecretFailure(
        static fn() => $oldCipher->decrypt($envelope, 'tenant-a', 41, 'mail_password'),
        'Billing ciphertext could be moved across secret fields.'
    );

    $tampered = $envelope;
    $tampered[strlen($tampered) - 1] = $tampered[strlen($tampered) - 1] === 'A' ? 'B' : 'A';
    expectSecretFailure(
        static fn() => $oldCipher->decrypt($tampered, 'tenant-a', 41, 'certificate_password'),
        'Billing ciphertext tampering was not rejected.'
    );

    // Rotation is deliberately two rollouts: first distribute an inactive key
    // to every process, then activate it. A process with the old active id can
    // already decrypt envelopes written by a newly activated peer.
    writeProbeKeyring($keyringPath, 'old-key', [
        'old-key' => $oldKey,
        'new-key' => $newKey,
    ]);
    $distributedOldActiveCipher = new BillingSecretCipher(FileKeyringDataKeyWrapper::fromFile($keyringPath));
    if ($distributedOldActiveCipher->keyId(
        $distributedOldActiveCipher->encrypt($plaintext, 'tenant-a', 41, 'certificate_password')
    ) !== 'old-key') {
        throw new RuntimeException('Inactive Billing key distribution changed the active key.');
    }
    writeProbeKeyring($keyringPath, 'new-key', [
        'old-key' => $oldKey,
        'new-key' => $newKey,
    ]);
    $newCipher = new BillingSecretCipher(FileKeyringDataKeyWrapper::fromFile($keyringPath));
    $rotated = $newCipher->rotate($envelope, 'tenant-a', 41, 'certificate_password');
    if ($newCipher->keyId($rotated) !== 'new-key'
        || $newCipher->decrypt($rotated, 'tenant-a', 41, 'certificate_password') !== $plaintext
        || $distributedOldActiveCipher->decrypt($rotated, 'tenant-a', 41, 'certificate_password') !== $plaintext
        || str_contains($rotated, $plaintext)
    ) {
        throw new RuntimeException('Billing envelope key rotation failed.');
    }

    writeProbeKeyring($keyringPath, 'new-key', ['new-key' => $newKey]);
    $withoutOldKey = new BillingSecretCipher(FileKeyringDataKeyWrapper::fromFile($keyringPath));
    expectSecretFailure(
        static fn() => $withoutOldKey->decrypt($envelope, 'tenant-a', 41, 'certificate_password'),
        'Billing envelope did not fail closed when its old key was unavailable.'
    );

    chmod($keyringPath, 0666);
    expectSecretFailure(
        static fn() => FileKeyringDataKeyWrapper::fromFile($keyringPath),
        'Billing keyring accepted unsafe write permissions.'
    );
    chmod($keyringPath, 0600);

    $resolver = new ClientConfigurationResolver([
        'environment' => 'pruebas',
        'empresa' => [
            'ruc' => '',
            'razon_social' => '',
            'nombre_comercial' => '',
            'direccion_matriz' => '',
        ],
        'direccion_establecimiento' => '',
        'establecimiento' => '001',
        'punto_emision' => '001',
        'certificate' => ['path' => '', 'password' => ''],
        'mail' => ['enabled' => false],
        'retry' => [],
    ]);
    expectSecretFailure(
        static fn() => $resolver->resolve(['certificate_password' => $rotated]),
        'Billing use boundary accepted ciphertext instead of repository-decrypted plaintext.'
    );

    fwrite(STDOUT, "Billing staged writes, authenticated envelope, context binding, safe rotation and fail-closed checks: OK\n");
} finally {
    @chmod($keyringPath, 0600);
    @unlink($keyringPath);
    @rmdir($directory);
    $oldKey = $newKey = $plaintext = '';
}
