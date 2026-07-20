<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BillingService\Billing\Infrastructure\Security\FileKeyringDataKeyWrapper;

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, sprintf(
        "Billing keyring operation failed_closed error_type=%s error_code=%d\n",
        $exception::class,
        (int)$exception->getCode()
    ));
    exit(1);
});

function usage(): never
{
    fwrite(STDERR, "Usage: php scripts/manage_billing_secret_keyring.php init|add-key|activate-key|retire-key|validate [--file=/absolute/path] [--key-id=id] [--attestation-file=/path/to/gate.log] [--confirm-backup-retention-expired]\n");
    exit(2);
}

/** @return array<string,mixed> */
function readKeyring(string $path): array
{
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Billing keyring could not be read.');
    }
    $document = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($document)) {
        throw new RuntimeException('Billing keyring document is invalid.');
    }
    return $document;
}

/** @param array<string,mixed> $document */
function writeKeyring(string $path, array $document): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Billing keyring directory could not be created.');
    }
    @chmod($directory, 0700);
    $temporary = tempnam($directory, '.billing-keyring-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Billing keyring temporary file could not be created.');
    }
    try {
        chmod($temporary, 0600);
        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)) {
            throw new RuntimeException('Billing keyring write was incomplete.');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Billing keyring atomic replacement failed.');
        }
        chmod($path, 0600);
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

$command = $argv[1] ?? '';
if (!in_array($command, ['init', 'add-key', 'activate-key', 'retire-key', 'validate'], true)) {
    usage();
}
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--file=')) {
        $options['file'] = substr($argument, strlen('--file='));
        continue;
    }
    if (str_starts_with($argument, '--key-id=')) {
        $options['key-id'] = substr($argument, strlen('--key-id='));
        continue;
    }
    if ($argument === '--confirm-backup-retention-expired') {
        $options['confirm-retention'] = true;
        continue;
    }
    if (str_starts_with($argument, '--attestation-file=')) {
        $options['attestation-file'] = substr($argument, strlen('--attestation-file='));
        continue;
    }
    usage();
}
$path = trim((string)($options['file'] ?? ($_ENV['BILLING_SECRET_KEYRING_HOST_PATH'] ?? getenv('BILLING_SECRET_KEYRING_HOST_PATH') ?: '')));
if ($path === '') {
    $path = dirname(__DIR__) . '/entorno/.secrets/billing-secret-keyring.json';
}
if (!str_starts_with($path, '/')) {
    $path = dirname(__DIR__) . '/' . ltrim($path, '/');
}
$keyId = trim((string)($options['key-id'] ?? ''));
if ($keyId === '' && in_array($command, ['init', 'add-key'], true)) {
    $keyId = 'local-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
}
if (($keyId !== '' || in_array($command, ['init', 'add-key', 'activate-key', 'retire-key'], true))
    && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $keyId)) {
    throw new RuntimeException('Billing key id is invalid.');
}

if ($command === 'init') {
    if (file_exists($path)) {
        throw new RuntimeException('Billing keyring already exists; use the staged add-key/activate-key flow or validate.');
    }
    writeKeyring($path, [
        'version' => 1,
        'active_key_id' => $keyId,
        'keys' => [$keyId => base64_encode(random_bytes(32))],
    ]);
} elseif ($command === 'add-key') {
    FileKeyringDataKeyWrapper::fromFile($path);
    $document = readKeyring($path);
    $keys = $document['keys'] ?? null;
    if (!is_array($keys)) {
        throw new RuntimeException('Billing keyring keys are invalid.');
    }
    if (array_key_exists($keyId, $keys)) {
        throw new RuntimeException('Billing key id already exists.');
    }
    if (count($keys) >= 32) {
        throw new RuntimeException('Billing keyring reached its safe key-retention limit.');
    }
    $keys[$keyId] = base64_encode(random_bytes(32));
    $document['keys'] = $keys;
    writeKeyring($path, $document);
} elseif ($command === 'activate-key') {
    FileKeyringDataKeyWrapper::fromFile($path);
    $document = readKeyring($path);
    $keys = $document['keys'] ?? null;
    if (!is_array($keys) || !array_key_exists($keyId, $keys)) {
        throw new RuntimeException('Billing key id must be added and distributed before activation.');
    }
    $document['active_key_id'] = $keyId;
    writeKeyring($path, $document);
} elseif ($command === 'retire-key') {
    if (($options['confirm-retention'] ?? false) !== true) {
        throw new RuntimeException('Retiring a Billing key requires --confirm-backup-retention-expired.');
    }
    FileKeyringDataKeyWrapper::fromFile($path);
    $document = readKeyring($path);
    $keys = $document['keys'] ?? null;
    if (!is_array($keys) || !array_key_exists($keyId, $keys)) {
        throw new RuntimeException('Billing key id does not exist.');
    }
    $activeKeyId = (string)($document['active_key_id'] ?? '');
    if (hash_equals($activeKeyId, $keyId)) {
        throw new RuntimeException('The active Billing key cannot be retired.');
    }
    if (count($keys) <= 1) {
        throw new RuntimeException('Billing keyring cannot retire its last key.');
    }
    $attestationPath = trim((string)($options['attestation-file'] ?? ''));
    $attestation = $attestationPath !== '' ? @file_get_contents($attestationPath) : false;
    if (!is_string($attestation)
        || !preg_match(
            '/^BILLING_SECRET_STORAGE_GATE version=2 constraints_validated=1 plaintext=0 envelopes_verified=[0-9]+ key_id=' . preg_quote($activeKeyId, '/') . '$/mD',
            $attestation
        )
    ) {
        throw new RuntimeException('Retiring a Billing key requires a current ciphertext storage-gate attestation file for the active key.');
    }
    unset($keys[$keyId]);
    $document['keys'] = $keys;
    writeKeyring($path, $document);
}

$provider = FileKeyringDataKeyWrapper::fromFile($path);
fwrite(STDOUT, sprintf(
    "Billing keyring %s: OK active_key_id=%s key_count=%d (key values hidden)\n",
    $command,
    $provider->activeKeyId(),
    count(readKeyring($path)['keys'] ?? [])
));
