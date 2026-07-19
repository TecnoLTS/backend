<?php

declare(strict_types=1);

namespace App\Modules\CatalogInventory\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Infrastructure\Storage\StorageException;
use App\Modules\CatalogInventory\Infrastructure\CatalogImageStorage;
use InvalidArgumentException;
use Throwable;

final class CatalogImageUploadController
{
    public function store(): void
    {
        Auth::requireAdmin();

        $mainUpload = $_FILES['image'] ?? null;
        if (!is_array($mainUpload)) {
            Response::error('Falta la imagen WebP principal.', 422, 'CATALOG_IMAGE_UPLOAD_INVALID');
            return;
        }

        $variants = [];
        foreach ([220, 360] as $width) {
            $upload = $_FILES['variant' . $width] ?? null;
            if (is_array($upload)) {
                $variants[$width] = $upload;
            }
        }

        try {
            $tenantId = trim((string)(TenantContext::id() ?? ''));
            if ($tenantId === '') {
                throw new InvalidArgumentException('No se pudo resolver el tenant del upload.');
            }
            $result = (new CatalogImageStorage())->store(
                $mainUpload,
                $variants,
                (string)($_POST['folder'] ?? ''),
                (string)($_POST['fileName'] ?? ''),
                $tenantId
            );
            Response::json($result, 201);
        } catch (InvalidArgumentException $exception) {
            Response::error($exception->getMessage(), 422, 'CATALOG_IMAGE_UPLOAD_INVALID');
        } catch (StorageException $exception) {
            error_log(sprintf(
                '[CATALOG_IMAGE_STORAGE_FAILED] tenant=%s error=%s',
                (string)(TenantContext::id() ?? 'unknown'),
                $exception->getMessage()
            ));
            Response::error('No se pudo almacenar la imagen procesada.', 503, 'CATALOG_IMAGE_STORAGE_UNAVAILABLE');
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[CATALOG_IMAGE_UPLOAD_FAILED] tenant=%s error=%s',
                (string)(TenantContext::id() ?? 'unknown'),
                $exception->getMessage()
            ));
            Response::error('No se pudo completar el upload de imagen.', 500, 'CATALOG_IMAGE_UPLOAD_FAILED');
        }
    }
}
