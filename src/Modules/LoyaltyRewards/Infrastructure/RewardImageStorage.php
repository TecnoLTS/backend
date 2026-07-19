<?php

namespace App\Modules\LoyaltyRewards\Infrastructure;

use App\Infrastructure\Storage\ObjectStorage;
use App\Infrastructure\Storage\StorageManager;

final class RewardImageStorage {
    private const PUBLIC_ROUTE_PREFIX = '/api/l/reward-images';
    private const STORAGE_PREFIX = 'loyalty/rewards';
    private const MAX_DIMENSION = 1200;
    private const MAX_PIXELS = 25000000;
    private const WEBP_QUALITY = 84;

    private readonly ObjectStorage $storage;

    public function __construct(?ObjectStorage $storage = null) {
        $this->storage = $storage ?? StorageManager::instance()->uploads();
    }

    /**
     * @param array<string, mixed> $upload
     * @return array{imageUrl: string, fileName: string, mimeType: string, size: int, width: int, height: int}
     */
    public function store(array $upload, string $tenantId): array {
        if (!function_exists('imagewebp')) {
            throw new \RuntimeException('El servidor no tiene soporte WebP habilitado.');
        }

        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->uploadErrorMessage($error));
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \InvalidArgumentException('No se recibio una imagen valida.');
        }

        $maxBytes = $this->maxBytes();
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new \InvalidArgumentException(sprintf('La imagen debe pesar maximo %.1f MB.', $maxBytes / 1048576));
        }

        $mimeType = $this->detectMimeType($tmpName);
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Formato no permitido. Usa JPG, PNG o WebP.');
        }

        $imageInfo = @getimagesize($tmpName);
        if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
            throw new \InvalidArgumentException('La imagen esta danada o no se puede leer.');
        }

        $sourceWidth = (int)$imageInfo[0];
        $sourceHeight = (int)$imageInfo[1];
        if ($sourceWidth < 80 || $sourceHeight < 80) {
            throw new \InvalidArgumentException('La imagen es demasiado pequena. Usa al menos 80 x 80 px.');
        }
        if (($sourceWidth * $sourceHeight) > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('La imagen es demasiado grande en dimensiones.');
        }

        $contents = file_get_contents($tmpName);
        if (!is_string($contents) || $contents === '') {
            throw new \InvalidArgumentException('No se pudo leer la imagen subida.');
        }

        $source = @imagecreatefromstring($contents);
        if (!$source instanceof \GdImage) {
            throw new \InvalidArgumentException('La imagen no se pudo procesar.');
        }

        [$targetWidth, $targetHeight] = $this->targetDimensions($sourceWidth, $sourceHeight);
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target instanceof \GdImage) {
            imagedestroy($source);
            throw new \RuntimeException('No se pudo preparar la conversion de imagen.');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        if ($transparent === false) {
            imagedestroy($source);
            imagedestroy($target);
            throw new \RuntimeException('No se pudo preparar la transparencia de la imagen.');
        }
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($source);

        $tenantSegment = $this->safeSegment($tenantId);
        $fileName = 'reward-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(8)) . '.webp';
        ob_start();
        $encoded = imagewebp($target, null, self::WEBP_QUALITY);
        $webp = ob_get_clean();
        if (!$encoded || !is_string($webp) || $webp === '') {
            imagedestroy($target);
            throw new \RuntimeException('No se pudo convertir la imagen a WebP.');
        }
        imagedestroy($target);
        $this->storage->put($this->storageKey($tenantSegment, $fileName), $webp, 'image/webp');

        return [
            'imageUrl' => self::PUBLIC_ROUTE_PREFIX . '/' . $tenantSegment . '/' . $fileName,
            'fileName' => $fileName,
            'mimeType' => 'image/webp',
            'size' => strlen($webp),
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    public function send(string $tenantId, string $fileName): void {
        $tenantSegment = $this->safeSegment($tenantId);
        if (!preg_match('/^reward-[0-9]{14}-[a-f0-9]{16}\.webp$/', $fileName)) {
            throw new \RuntimeException('Imagen no encontrada.');
        }

        $key = $this->storageKey($tenantSegment, $fileName);
        $metadata = $this->storage->metadata($key);
        if ($metadata === null) {
            throw new \RuntimeException('Imagen no encontrada.');
        }

        http_response_code(200);
        header('Content-Type: image/webp');
        header('Content-Length: ' . (string)$metadata['size']);
        header('Cache-Control: public, max-age=2592000, immutable');
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-site');

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            return;
        }

        echo $this->storage->get($key);
    }

    private function detectMimeType(string $path): string {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        return is_string($mimeType) ? $mimeType : '';
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function targetDimensions(int $width, int $height): array {
        $longestSide = max($width, $height);
        if ($longestSide <= self::MAX_DIMENSION) {
            return [$width, $height];
        }

        $ratio = self::MAX_DIMENSION / $longestSide;

        return [
            max(1, (int)round($width * $ratio)),
            max(1, (int)round($height * $ratio)),
        ];
    }

    private function maxBytes(): int {
        $configured = (int)($_ENV['LOYALTY_REWARD_IMAGE_MAX_BYTES'] ?? 0);

        return $configured > 0 ? $configured : 5 * 1024 * 1024;
    }

    private function safeSegment(string $value): string {
        $segment = strtolower(trim($value));
        $segment = preg_replace('/[^a-z0-9_-]+/', '-', $segment) ?? '';
        $segment = trim($segment, '-_');

        return $segment !== '' ? $segment : 'default';
    }

    private function storageKey(string $tenantSegment, string $fileName): string {
        return self::STORAGE_PREFIX . '/' . $tenantSegment . '/' . $fileName;
    }

    private function uploadErrorMessage(int $error): string {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La imagen supera el tamano permitido.',
            UPLOAD_ERR_PARTIAL => 'La imagen se subio incompleta. Intenta nuevamente.',
            UPLOAD_ERR_NO_FILE => 'Selecciona una imagen para subir.',
            default => 'No se pudo recibir la imagen.',
        };
    }
}
