<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Security;

use JsonException;
use RuntimeException;

final class BillingSecretCipher
{
    public const PREFIX = 'pmbillenc:v1:';
    public const WRITE_MODE_LEGACY = 'legacy';
    public const WRITE_MODE_ENCRYPTED = 'encrypted';

    private const VERSION = 1;
    private const DATA_KEY_BYTES = 32;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const MAX_ENVELOPE_BYTES = 16384;
    private const ALLOWED_FIELDS = [
        'certificate_password',
        'mail_password',
    ];

    public function __construct(
        private readonly DataKeyWrapper $keyWrapper,
        private readonly bool $legacyPlaintextReadEnabled = false,
        private readonly string $writeMode = self::WRITE_MODE_ENCRYPTED
    )
    {
        if (!in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('AES-256-GCM is unavailable for Billing secret encryption.');
        }
        if (!in_array($this->writeMode, [self::WRITE_MODE_LEGACY, self::WRITE_MODE_ENCRYPTED], true)) {
            throw new RuntimeException('Billing secret write mode is invalid.');
        }
        if ($this->writeMode === self::WRITE_MODE_LEGACY && !$this->legacyPlaintextReadEnabled) {
            throw new RuntimeException('Legacy Billing secret writes require the explicit legacy-read compatibility phase.');
        }
    }

    /**
     * Runtime persistence boundary. The legacy result exists only for the
     * first compatibility rollout, before every old image has left service.
     * Migration code must call encrypt() directly and never this method.
     */
    public function prepareForStorage(string $plaintext, string $tenantId, int $branchId, string $field): string
    {
        if ($this->writeMode === self::WRITE_MODE_LEGACY) {
            // Validate the exact same AAD context even though the compatibility
            // value is temporarily not encrypted.
            $this->associatedData($tenantId, $branchId, $field);
            return $plaintext;
        }

        return $this->encrypt($plaintext, $tenantId, $branchId, $field);
    }

    public function encrypt(string $plaintext, string $tenantId, int $branchId, string $field): string
    {
        $context = $this->associatedData($tenantId, $branchId, $field);
        $dataKey = random_bytes(self::DATA_KEY_BYTES);
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        try {
            $ciphertext = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $dataKey,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $context . '|purpose=data',
                self::TAG_BYTES
            );
            if (!is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
                throw new RuntimeException('Billing secret encryption failed.');
            }

            $wrapped = $this->keyWrapper->wrap($dataKey, $context . '|purpose=dek-wrap');
            $document = [
                'v' => self::VERSION,
                'alg' => 'A256GCM',
                'kw' => 'A256GCM',
                'kid' => $wrapped['kid'],
                'wn' => self::base64UrlEncode($wrapped['nonce']),
                'wt' => self::base64UrlEncode($wrapped['tag']),
                'wk' => self::base64UrlEncode($wrapped['ciphertext']),
                'n' => self::base64UrlEncode($nonce),
                't' => self::base64UrlEncode($tag),
                'ct' => self::base64UrlEncode($ciphertext),
            ];
            $encoded = self::base64UrlEncode(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $envelope = self::PREFIX . $encoded;
            if (strlen($envelope) > self::MAX_ENVELOPE_BYTES) {
                throw new RuntimeException('Billing secret envelope exceeds its storage limit.');
            }

            return $envelope;
        } catch (JsonException $exception) {
            throw new RuntimeException('Billing secret envelope serialization failed.', 0, $exception);
        } finally {
            $dataKey = str_repeat("\0", strlen($dataKey));
            $plaintext = str_repeat("\0", strlen($plaintext));
        }
    }

    public function decrypt(string $envelope, string $tenantId, int $branchId, string $field): string
    {
        if (!$this->isEncrypted($envelope) || strlen($envelope) > self::MAX_ENVELOPE_BYTES) {
            throw new RuntimeException('Billing secret is not an authenticated ciphertext envelope.');
        }

        $documentBytes = self::base64UrlDecode(substr($envelope, strlen(self::PREFIX)));
        try {
            $document = json_decode($documentBytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Billing secret envelope is malformed.', 0, $exception);
        }

        if (!is_array($document)
            || ($document['v'] ?? null) !== self::VERSION
            || ($document['alg'] ?? null) !== 'A256GCM'
            || ($document['kw'] ?? null) !== 'A256GCM'
        ) {
            throw new RuntimeException('Billing secret envelope algorithm is not supported.');
        }
        foreach (['kid', 'wn', 'wt', 'wk', 'n', 't', 'ct'] as $required) {
            if (!array_key_exists($required, $document) || !is_string($document[$required])) {
                throw new RuntimeException('Billing secret envelope is incomplete.');
            }
        }

        $context = $this->associatedData($tenantId, $branchId, $field);
        $wrapped = [
            'kid' => $document['kid'],
            'nonce' => self::base64UrlDecode($document['wn']),
            'tag' => self::base64UrlDecode($document['wt']),
            'ciphertext' => self::base64UrlDecode($document['wk']),
        ];
        $nonce = self::base64UrlDecode($document['n']);
        $tag = self::base64UrlDecode($document['t']);
        $ciphertext = self::base64UrlDecode($document['ct']);
        if (strlen($nonce) !== self::NONCE_BYTES || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Billing secret envelope parameters are malformed.');
        }

        $dataKey = $this->keyWrapper->unwrap($wrapped, $context . '|purpose=dek-wrap');
        try {
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $dataKey,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $context . '|purpose=data'
            );
            if (!is_string($plaintext)) {
                throw new RuntimeException('Billing secret authentication failed.');
            }

            return $plaintext;
        } finally {
            $dataKey = str_repeat("\0", strlen($dataKey));
        }
    }

    /** Transitional compatibility read for the staged first adoption only. */
    public function decryptStored(string $storedValue, string $tenantId, int $branchId, string $field): string
    {
        if ($this->isEncrypted($storedValue)) {
            return $this->decrypt($storedValue, $tenantId, $branchId, $field);
        }
        if (!$this->legacyPlaintextReadEnabled) {
            throw new RuntimeException('Billing secret is not an authenticated ciphertext envelope.');
        }

        return $storedValue;
    }

    public function rotate(string $envelope, string $tenantId, int $branchId, string $field): string
    {
        if (hash_equals($this->keyWrapper->activeKeyId(), $this->keyId($envelope))) {
            // Authentication is still verified; a marker alone is never trusted.
            $this->decrypt($envelope, $tenantId, $branchId, $field);
            return $envelope;
        }

        $plaintext = $this->decrypt($envelope, $tenantId, $branchId, $field);
        try {
            return $this->encrypt($plaintext, $tenantId, $branchId, $field);
        } finally {
            $plaintext = str_repeat("\0", strlen($plaintext));
        }
    }

    public function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public function keyId(string $envelope): string
    {
        if (!$this->isEncrypted($envelope)) {
            throw new RuntimeException('Billing secret is not encrypted.');
        }
        $document = json_decode(
            self::base64UrlDecode(substr($envelope, strlen(self::PREFIX))),
            true,
            16,
            JSON_THROW_ON_ERROR
        );
        $keyId = is_array($document) ? ($document['kid'] ?? null) : null;
        if (!is_string($keyId) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $keyId)) {
            throw new RuntimeException('Billing secret key id is malformed.');
        }

        return $keyId;
    }

    public function activeKeyId(): string
    {
        return $this->keyWrapper->activeKeyId();
    }

    public function legacyPlaintextReadEnabled(): bool
    {
        return $this->legacyPlaintextReadEnabled;
    }

    public function encryptedWritesEnabled(): bool
    {
        return $this->writeMode === self::WRITE_MODE_ENCRYPTED;
    }

    public function writeMode(): string
    {
        return $this->writeMode;
    }

    public function storagePhase(): string
    {
        if (!$this->legacyPlaintextReadEnabled) {
            return 'ciphertext_only';
        }

        return $this->encryptedWritesEnabled()
            ? 'expand_legacy_read_encrypted_write'
            : 'compatibility_legacy_read_legacy_write';
    }

    private function associatedData(string $tenantId, int $branchId, string $field): string
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '' || strlen($tenantId) > 128 || $branchId <= 0 || !in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new RuntimeException('Billing secret encryption context is invalid.');
        }

        return sprintf(
            'paramascotasec:billing-secret:v1|tenant=%s|branch=%d|field=%s',
            self::base64UrlEncode($tenantId),
            $branchId,
            $field
        );
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value !== '' && !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new RuntimeException('Billing secret envelope contains invalid base64url.');
        }
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new RuntimeException('Billing secret envelope base64url decoding failed.');
        }

        return $decoded;
    }
}
