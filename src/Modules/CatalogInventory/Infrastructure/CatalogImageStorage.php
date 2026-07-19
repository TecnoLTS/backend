<?php

declare(strict_types=1);

namespace App\Modules\CatalogInventory\Infrastructure;

use App\Infrastructure\Storage\ObjectStorage;
use App\Infrastructure\Storage\StorageConfiguration;
use App\Infrastructure\Storage\StorageManager;
use Closure;
use InvalidArgumentException;
use Throwable;

final class CatalogImageStorage
{
    private const MAX_FILE_BYTES = 8 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 16 * 1024 * 1024;
    private const MAX_FILE_NAME_BYTES = 140;
    private const MAX_PIXELS = 40_000_000;
    private const PRODUCT_VARIANT_WIDTHS = [220, 360];
    private const FOLDERS = ['products', 'brands', 'categories'];

    private readonly ObjectStorage $storage;
    private readonly StorageConfiguration $configuration;
    private readonly Closure $uploadedFileValidator;

    public function __construct(
        ?ObjectStorage $storage = null,
        ?StorageConfiguration $configuration = null,
        ?Closure $uploadedFileValidator = null
    ) {
        $manager = ($storage === null || $configuration === null) ? StorageManager::instance() : null;
        $this->storage = $storage ?? $manager?->uploads()
            ?? throw new \LogicException('No se pudo resolver el almacenamiento de uploads.');
        $this->configuration = $configuration ?? $manager?->configuration()
            ?? throw new \LogicException('No se pudo resolver la configuracion de almacenamiento.');
        $this->uploadedFileValidator = $uploadedFileValidator ?? static fn(string $path): bool => is_uploaded_file($path);
    }

    /**
     * @param array<string, mixed> $mainUpload
     * @param array<int, array<string, mixed>> $variantUploads
     * @return array{url: string, fileName: string, storageKey: string, variants: array<string, string>, size: int}
     */
    public function store(
        array $mainUpload,
        array $variantUploads,
        string $folder,
        string $fileName,
        string $tenantId
    ): array {
        $folder = strtolower(trim($folder));
        if (!in_array($folder, self::FOLDERS, true)) {
            throw new InvalidArgumentException('La carpeta de imagen no es valida.');
        }
        $fileName = $this->validateFileName($fileName);
        $tenantSegment = $this->validateTenantSegment($tenantId);

        $expectedWidths = $folder === 'products' ? self::PRODUCT_VARIANT_WIDTHS : [];
        $providedWidths = array_map('intval', array_keys($variantUploads));
        sort($providedWidths);
        if ($providedWidths !== $expectedWidths) {
            throw new InvalidArgumentException($folder === 'products'
                ? 'Las imagenes de producto requieren variantes WebP de 220 y 360 pixeles.'
                : 'Las imagenes de marca o categoria no aceptan variantes de producto.');
        }

        $objects = [];
        $mainKey = $this->storageKey($tenantSegment, $folder, $fileName);
        $objects[$mainKey] = $this->readWebpUpload($mainUpload, $fileName);
        foreach ($expectedWidths as $width) {
            $variantName = $this->variantFileName($fileName, $width);
            $objects[$this->storageKey($tenantSegment, $folder, $variantName)] = $this->readWebpUpload(
                $variantUploads[$width],
                $variantName
            );
        }

        $totalBytes = array_sum(array_map('strlen', $objects));
        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            throw new InvalidArgumentException('El lote de imagenes procesadas supera 16MB.');
        }

        foreach (array_keys($objects) as $key) {
            if ($this->storage->exists($key)) {
                throw new InvalidArgumentException('Ya existe una imagen con el nombre generado; intenta nuevamente.');
            }
        }

        $attempted = [];
        try {
            foreach ($objects as $key => $contents) {
                $attempted[] = $key;
                $this->storage->put($key, $contents, 'image/webp');
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($attempted) as $key) {
                try {
                    $this->storage->delete($key);
                } catch (Throwable $cleanupException) {
                    error_log(sprintf(
                        '[CATALOG_IMAGE_CLEANUP_FAILED] tenant=%s key_hash=%s error=%s',
                        $tenantSegment,
                        hash('sha256', $key),
                        $cleanupException->getMessage()
                    ));
                }
            }
            throw $exception;
        }

        $variantUrls = [];
        foreach ($expectedWidths as $width) {
            $variantUrls[(string)$width] = $this->publicUrl(
                $this->storageKey($tenantSegment, $folder, $this->variantFileName($fileName, $width))
            );
        }

        return [
            'url' => $this->publicUrl($mainKey),
            'fileName' => $fileName,
            'storageKey' => $mainKey,
            'variants' => $variantUrls,
            'size' => strlen($objects[$mainKey]),
        ];
    }

    /** @param array<string, mixed> $upload */
    private function readWebpUpload(array $upload, string $expectedName): string
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage($error));
        }

        $providedName = (string)($upload['name'] ?? '');
        if ($providedName === '' || basename($providedName) !== $providedName || !hash_equals($expectedName, $providedName)) {
            throw new InvalidArgumentException('El nombre del archivo WebP no coincide con el lote solicitado.');
        }
        $declaredType = strtolower(trim((string)($upload['type'] ?? '')));
        if ($declaredType !== '' && $declaredType !== 'image/webp') {
            throw new InvalidArgumentException('El archivo procesado debe declarar image/webp.');
        }

        $temporaryPath = (string)($upload['tmp_name'] ?? '');
        if ($temporaryPath === '' || !($this->uploadedFileValidator)($temporaryPath)) {
            throw new InvalidArgumentException('No se recibio un archivo WebP valido.');
        }
        $reportedSize = (int)($upload['size'] ?? 0);
        $actualSize = filesize($temporaryPath);
        if ($reportedSize <= 0
            || $actualSize === false
            || $reportedSize !== (int)$actualSize
            || $reportedSize > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('Cada imagen WebP debe pesar entre 1 byte y 8MB.');
        }

        $contents = file_get_contents($temporaryPath);
        if (!is_string($contents) || strlen($contents) !== $reportedSize) {
            throw new InvalidArgumentException('La imagen WebP se recibio incompleta.');
        }
        if (!$this->hasWebpSignature($contents)) {
            throw new InvalidArgumentException('La firma del archivo no corresponde a WebP.');
        }
        $imageInfo = @getimagesizefromstring($contents);
        if (!is_array($imageInfo)
            || (int)($imageInfo[2] ?? 0) !== IMAGETYPE_WEBP
            || (int)($imageInfo[0] ?? 0) < 1
            || (int)($imageInfo[1] ?? 0) < 1
            || ((int)$imageInfo[0] * (int)$imageInfo[1]) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('El WebP esta danado o excede las dimensiones permitidas.');
        }

        return $contents;
    }

    private function validateFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if (strlen($fileName) > self::MAX_FILE_NAME_BYTES
            || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.webp$/D', $fileName) !== 1) {
            throw new InvalidArgumentException('El nombre WebP no cumple el formato SEO permitido.');
        }

        return $fileName;
    }

    private function validateTenantSegment(string $tenantId): string
    {
        $tenantId = strtolower(trim($tenantId));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $tenantId) !== 1) {
            throw new InvalidArgumentException('El tenant no tiene un identificador valido para almacenamiento.');
        }

        return $tenantId;
    }

    private function storageKey(string $tenant, string $folder, string $fileName): string
    {
        return sprintf('tenants/%s/%s/%s', $tenant, $folder, $fileName);
    }

    private function publicUrl(string $key): string
    {
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        if ($this->configuration->driver === 'local') {
            return '/uploads/' . $encodedKey;
        }

        return rtrim($this->configuration->publicBaseUrl, '/') . '/' . $encodedKey;
    }

    private function variantFileName(string $fileName, int $width): string
    {
        return preg_replace('/\.webp$/D', '-' . $width . '.webp', $fileName) ?? $fileName;
    }

    private function hasWebpSignature(string $contents): bool
    {
        return strlen($contents) >= 12
            && substr($contents, 0, 4) === 'RIFF'
            && substr($contents, 8, 4) === 'WEBP';
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La imagen WebP supera el tamano permitido.',
            UPLOAD_ERR_PARTIAL => 'La imagen WebP se recibio parcialmente.',
            UPLOAD_ERR_NO_FILE => 'Falta una imagen WebP requerida.',
            default => 'No se pudo recibir la imagen WebP.',
        };
    }
}
