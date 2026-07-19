<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage\Billing;

use App\Core\TenantContext;
use App\Infrastructure\Storage\ObjectStorage;
use App\Infrastructure\Storage\StorageException;
use App\Infrastructure\Storage\StorageKey;
use App\Infrastructure\Storage\StorageManager;

final class BillingArtifactStorage
{
    private const REFERENCE_PREFIX = 'storage://artifacts/';

    private readonly ObjectStorage $storage;
    private readonly string $tenantId;

    public function __construct(?ObjectStorage $storage = null, ?string $tenantId = null)
    {
        $this->storage = $storage ?? StorageManager::instance()->artifacts();
        $resolvedTenantId = trim((string)($tenantId ?? TenantContext::id() ?? ''));
        if ($resolvedTenantId === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $resolvedTenantId) !== 1) {
            throw new StorageException('Tenant Billing requerido para aislar artefactos.');
        }
        $this->tenantId = strtolower($resolvedTenantId);
    }

    public function put(string $relativeBillingKey, string $contents, string $contentType): string
    {
        $key = $this->billingKey($relativeBillingKey);
        $this->storage->put($key, $contents, $contentType);

        return $this->referenceForKey($key);
    }

    public function putXml(string $stage, string $accessKey, string $contents): string
    {
        $stage = trim($stage);
        if (!in_array($stage, ['generados', 'firmados', 'autorizados'], true)) {
            throw new StorageException('Etapa XML de Billing inválida.');
        }
        $accessKey = $this->accessKey($accessKey);

        return $this->put(sprintf('xml/%s/%s.xml', $stage, $accessKey), $contents, 'application/xml');
    }

    public function putRide(string $accessKey, string $contents): string
    {
        return $this->put(sprintf('pdf/rides/%s.pdf', $this->accessKey($accessKey)), $contents, 'application/pdf');
    }

    public function putCertificate(string $fileName, string $contents): string
    {
        $fileName = basename($fileName);
        if (preg_match('/^[a-zA-Z0-9._-]+\.p12$/', $fileName) !== 1) {
            throw new StorageException('Nombre de certificado Billing inválido.');
        }

        return $this->put('certs/' . $fileName, $contents, 'application/x-pkcs12');
    }

    public function xmlReference(string $stage, string $accessKey): string
    {
        return $this->referenceForKey($this->billingKey(sprintf(
            'xml/%s/%s.xml',
            $stage,
            $this->accessKey($accessKey)
        )));
    }

    public function rideReference(string $accessKey): string
    {
        return $this->referenceForKey($this->billingKey(sprintf(
            'pdf/rides/%s.pdf',
            $this->accessKey($accessKey)
        )));
    }

    public function exists(string $reference): bool
    {
        $key = $this->keyFromReference($reference);
        if ($key !== null) {
            return $this->storage->exists($key);
        }

        return is_file($reference);
    }

    public function read(string $reference): string
    {
        $key = $this->keyFromReference($reference);
        if ($key !== null) {
            return $this->storage->get($key);
        }
        if (!is_file($reference) || !is_readable($reference)) {
            throw new StorageException('Artefacto Billing no encontrado.');
        }
        $contents = file_get_contents($reference);
        if (!is_string($contents)) {
            throw new StorageException('No se pudo leer el artefacto Billing.');
        }

        return $contents;
    }

    /** @return array{key: string, size: int, content_type: ?string, etag: ?string, modified_at: ?int}|null */
    public function metadata(string $reference): ?array
    {
        $key = $this->keyFromReference($reference);
        if ($key !== null) {
            return $this->storage->metadata($key);
        }
        if (!is_file($reference)) {
            return null;
        }
        $size = filesize($reference);
        $modified = filemtime($reference);

        return [
            'key' => $reference,
            'size' => $size === false ? 0 : (int) $size,
            'content_type' => null,
            'etag' => null,
            'modified_at' => $modified === false ? null : (int) $modified,
        ];
    }

    public function materialize(string $reference): string
    {
        $key = $this->keyFromReference($reference);
        if ($key !== null) {
            return $this->storage->materialize($key);
        }
        if (!is_file($reference) || !is_readable($reference)) {
            throw new StorageException('Artefacto Billing no disponible para materialización.');
        }

        return $reference;
    }

    public function referenceForKey(string $key): string
    {
        $key = StorageKey::normalize($key);
        $localPath = $this->storage->localPath($key);

        return $localPath ?? self::REFERENCE_PREFIX . $key;
    }

    public function keyFromReference(string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        if (str_starts_with($reference, self::REFERENCE_PREFIX)) {
            return StorageKey::normalize(substr($reference, strlen(self::REFERENCE_PREFIX)));
        }

        $artifactRoot = rtrim(StorageManager::instance()->configuration()->localArtifactRoot, '/');
        if (str_starts_with($reference, $artifactRoot . '/')) {
            return StorageKey::normalize(substr($reference, strlen($artifactRoot) + 1));
        }
        if (str_starts_with($reference, '/var/www/html/storage/')) {
            return StorageKey::normalize(substr($reference, strlen('/var/www/html/storage/')));
        }
        if (str_starts_with($reference, '/app/storage/')) {
            // Compatibilidad de solo lectura con el layout local anterior a la
            // segmentacion por tenant. Toda escritura nueva usa billing/tenants/*.
            return StorageKey::prefix('billing', substr($reference, strlen('/app/storage/')));
        }

        return null;
    }

    private function billingKey(string $relativeKey): string
    {
        return StorageKey::prefix('billing/tenants/' . $this->tenantId, $relativeKey);
    }

    private function accessKey(string $accessKey): string
    {
        $normalized = preg_replace('/[^0-9]/', '', $accessKey);
        if (!is_string($normalized) || strlen($normalized) !== 49) {
            throw new StorageException('Clave de acceso Billing inválida para almacenamiento.');
        }

        return $normalized;
    }
}
