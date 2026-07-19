<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Security;

use JsonException;
use RuntimeException;

final class FileKeyringDataKeyWrapper implements DataKeyWrapper
{
    private const KEY_BYTES = 32;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    /** @var array<string,string> */
    private array $keys;

    private function __construct(private readonly string $activeKeyId, array $keys)
    {
        $this->keys = $keys;
    }

    public static function fromFile(string $path): self
    {
        $path = trim($path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Billing secret keyring is not available.');
        }

        clearstatcache(true, $path);
        $permissions = @fileperms($path);
        if (is_int($permissions) && (($permissions & 0o022) !== 0)) {
            throw new RuntimeException('Billing secret keyring has unsafe write permissions.');
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            throw new RuntimeException('Billing secret keyring is empty.');
        }

        try {
            $document = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Billing secret keyring is not valid JSON.');
        } finally {
            $contents = '';
        }

        if (!is_array($document) || ($document['version'] ?? null) !== 1) {
            throw new RuntimeException('Billing secret keyring version is not supported.');
        }

        $activeKeyId = self::validateKeyId($document['active_key_id'] ?? null);
        $encodedKeys = $document['keys'] ?? null;
        if (!is_array($encodedKeys) || count($encodedKeys) === 0 || count($encodedKeys) > 32) {
            throw new RuntimeException('Billing secret keyring must contain between 1 and 32 keys.');
        }

        $keys = [];
        foreach ($encodedKeys as $keyId => $encodedKey) {
            $validatedKeyId = self::validateKeyId($keyId);
            if (!is_string($encodedKey)) {
                throw new RuntimeException('Billing secret keyring contains an invalid key.');
            }
            $key = base64_decode($encodedKey, true);
            if (!is_string($key) || strlen($key) !== self::KEY_BYTES) {
                throw new RuntimeException('Billing secret keyring keys must be 32-byte base64 values.');
            }
            $keys[$validatedKeyId] = $key;
        }
        unset($document, $encodedKeys);

        if (!array_key_exists($activeKeyId, $keys)) {
            throw new RuntimeException('Billing secret keyring active key is missing.');
        }

        return new self($activeKeyId, $keys);
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    public function wrap(string $dataKey, string $associatedData): array
    {
        if (strlen($dataKey) !== self::KEY_BYTES) {
            throw new RuntimeException('Billing data key has an invalid length.');
        }

        $keyId = $this->activeKeyId;
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $dataKey,
            'aes-256-gcm',
            $this->keys[$keyId],
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData . '|kid=' . $keyId,
            self::TAG_BYTES
        );
        if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Billing data key wrapping failed.');
        }

        return [
            'kid' => $keyId,
            'nonce' => $nonce,
            'tag' => $tag,
            'ciphertext' => $ciphertext,
        ];
    }

    public function unwrap(array $wrappedKey, string $associatedData): string
    {
        $keyId = self::validateKeyId($wrappedKey['kid'] ?? null);
        $key = $this->keys[$keyId] ?? null;
        if (!is_string($key)) {
            throw new RuntimeException('Billing secret references an unavailable key id.');
        }

        foreach (['nonce', 'tag', 'ciphertext'] as $field) {
            if (!isset($wrappedKey[$field]) || !is_string($wrappedKey[$field])) {
                throw new RuntimeException('Billing wrapped data key is malformed.');
            }
        }
        if (strlen($wrappedKey['nonce']) !== self::NONCE_BYTES || strlen($wrappedKey['tag']) !== self::TAG_BYTES) {
            throw new RuntimeException('Billing wrapped data key parameters are malformed.');
        }

        $dataKey = openssl_decrypt(
            $wrappedKey['ciphertext'],
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $wrappedKey['nonce'],
            $wrappedKey['tag'],
            $associatedData . '|kid=' . $keyId
        );
        if (!is_string($dataKey) || strlen($dataKey) !== self::KEY_BYTES) {
            throw new RuntimeException('Billing wrapped data key authentication failed.');
        }

        return $dataKey;
    }

    private static function validateKeyId(mixed $keyId): string
    {
        if (!is_string($keyId) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $keyId)) {
            throw new RuntimeException('Billing secret key id is invalid.');
        }

        return $keyId;
    }
}
