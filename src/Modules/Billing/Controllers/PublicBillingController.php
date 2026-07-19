<?php

namespace App\Modules\Billing\Controllers;

use App\Modules\Billing\Application\Ports\BillingOrderAccountingPort;
use App\Modules\Billing\Infrastructure\BillingCommercePortsFactory;
use App\Modules\Billing\Infrastructure\NativeBillingGateway;
use App\Services\BillingApiException;

final class PublicBillingController {
    private const CERTIFICATE_MAX_BYTES = 10485760;

    private ?BillingOrderAccountingPort $orderAccounting;

    public function __construct(?BillingOrderAccountingPort $orderAccounting = null) {
        $this->orderAccounting = $orderAccounting;
    }

    public function health(): void {
        $this->jsonSuccess([
            'status' => 'healthy',
            'service' => 'billing-service',
            'driver' => 'platform-core',
            'version' => '1.0.0',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    public function configuration(string $apiMode): void {
        $this->runJson(fn() => $this->gateway($apiMode)->configuration($this->environment($apiMode)));
    }

    public function updateConfiguration(string $apiMode): void {
        $this->runJson(fn() => $this->gateway($apiMode)->updateConfiguration($this->jsonBody(), $this->environment($apiMode)));
    }

    public function uploadCertificate(string $apiMode): void {
        $this->runJson(function () use ($apiMode): array {
            $upload = is_array($_FILES['certificate'] ?? null) ? $_FILES['certificate'] : null;
            if ($upload === null || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new \InvalidArgumentException('Selecciona un archivo .p12 para cargar.');
            }

            $tmpName = (string)($upload['tmp_name'] ?? '');
            $fileName = basename((string)($upload['name'] ?? 'certificado.p12'));
            $password = trim((string)($_POST['certificate_password'] ?? $_POST['password'] ?? ''));
            if ($password === '') {
                throw new \InvalidArgumentException('Ingresa la contrasena del certificado .p12.');
            }
            if (!preg_match('/\.p12$/i', $fileName)) {
                throw new \InvalidArgumentException('El certificado debe tener extension .p12.');
            }
            $size = (int)($upload['size'] ?? 0);
            if ($size <= 0 || $size > self::CERTIFICATE_MAX_BYTES) {
                throw new \InvalidArgumentException('El certificado .p12 debe pesar hasta 10 MB.');
            }
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new \InvalidArgumentException('El certificado no llego correctamente al servidor.');
            }

            return $this->gateway($apiMode)->uploadCertificate($tmpName, $fileName, $password, $this->environment($apiMode));
        });
    }

    public function createBranch(string $apiMode): void {
        $this->runJson(fn() => $this->gateway($apiMode)->createBranch($this->jsonBody(), $this->environment($apiMode)));
    }

    public function updateBranch(string $apiMode, string $branchId): void {
        $this->runJson(fn() => $this->gateway($apiMode)->updateBranch((int)$branchId, $this->jsonBody(), $this->environment($apiMode)));
    }

    public function emit(string $apiMode): void {
        $this->runJson(fn() => $this->gateway($apiMode)->emitInvoice([
            ...$this->jsonBody(),
            'ambiente' => $this->environment($apiMode),
        ]), 201);
    }

    public function rides(string $apiMode): void {
        $this->runJson(function () use ($apiMode): array {
            $limit = max(1, min(300, (int)($_GET['limit'] ?? 100)));
            $includeCancelled = filter_var(
                $_GET['include_cancelled'] ?? $_GET['includeCancelled'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            return $this->gateway($apiMode)->listRidePdfs($limit, $includeCancelled);
        });
    }

    public function source(string $apiMode, string $sourceReference): void {
        $this->runJson(function () use ($apiMode, $sourceReference): array {
            $ride = $this->gateway($apiMode)->findRideBySourceReference(rawurldecode($sourceReference));
            if (!is_array($ride)) {
                throw new BillingApiException('No existe una factura activa para la referencia indicada.', 404, 'native://billing/source');
            }

            return $ride;
        });
    }

    public function status(string $apiMode, string $accessKey): void {
        $this->runJson(fn() => $this->gateway($apiMode)->getInvoiceStatus($accessKey, $this->environment($apiMode)));
    }

    public function xml(string $apiMode, string $accessKey): void {
        $this->runFile(function () use ($apiMode, $accessKey): array {
            $xml = $this->gateway($apiMode)->getInvoiceXml($accessKey, $this->environment($apiMode));

            return [
                'content' => (string)($xml['content'] ?? ''),
                'filename' => (string)($xml['filename'] ?? preg_replace('/[^0-9]/', '', $accessKey) . '.xml'),
                'content_type' => 'application/xml; charset=UTF-8',
                'disposition' => 'inline',
            ];
        }, 'xml');
    }

    public function ridePdf(string $apiMode, string $accessKey): void {
        $this->runFile(function () use ($apiMode, $accessKey): array {
            $pdf = $this->gateway($apiMode)->getRidePdf($accessKey);

            return [
                'content' => (string)($pdf['content'] ?? ''),
                'filename' => (string)($pdf['filename'] ?? 'RIDE-' . preg_replace('/[^0-9]/', '', $accessKey) . '.pdf'),
                'content_type' => 'application/pdf',
                'disposition' => 'inline',
            ];
        }, 'ride-pdf');
    }

    public function mailTest(string $apiMode, string $accessKey): void {
        $this->runJson(fn() => $this->gateway($apiMode)->sendMailTest($accessKey, $this->environment($apiMode)));
    }

    public function cancelAndReissue(string $apiMode, string $accessKey): void {
        $this->runJson(function () use ($apiMode, $accessKey): array {
            $payload = $this->jsonBody(false);
            $reason = trim((string)($payload['reason'] ?? ''));

            $result = $this->gateway($apiMode)->cancelAndReissueInvoice($accessKey, $reason, $this->environment($apiMode));
            $this->syncOrderBillingMetadata($result);

            return $result;
        });
    }

    private function syncOrderBillingMetadata(array $result): void {
        $oldInvoice = is_array($result['old_invoice'] ?? null) ? $result['old_invoice'] : [];
        $newInvoice = is_array($result['new_invoice'] ?? null) ? $result['new_invoice'] : [];
        $orderId = trim((string)($newInvoice['source_reference'] ?? $oldInvoice['source_reference'] ?? ''));
        if ($orderId === '') {
            return;
        }

        $this->orderAccounting()->updateBillingMetadata($orderId, [
            'provider' => 'billing-sri',
            'status' => 'reissued',
            'invoice_status' => $newInvoice['sri_status'] ?? null,
            'access_key' => $newInvoice['access_key'] ?? null,
            'sequential' => $this->formatSequential($newInvoice),
            'issue_date' => $newInvoice['issue_date'] ?? null,
            'total' => $newInvoice['total'] ?? null,
            'authorization_number' => $newInvoice['authorization_number'] ?? null,
            'authorization_date' => $newInvoice['authorization_date'] ?? null,
            'reissued_at' => date('c'),
            'reissued_from_access_key' => $oldInvoice['access_key'] ?? null,
            'last_attempt_at' => date('c'),
            'last_error' => null,
        ]);
    }

    private function orderAccounting(): BillingOrderAccountingPort {
        return $this->orderAccounting ??= BillingCommercePortsFactory::orders();
    }

    private function formatSequential(array $invoice): ?string {
        $establishment = trim((string)($invoice['establishment_code'] ?? ''));
        $emissionPoint = trim((string)($invoice['emission_point'] ?? ''));
        $sequential = trim((string)($invoice['sequential'] ?? ''));
        if ($establishment === '' || $emissionPoint === '' || $sequential === '') {
            return null;
        }

        return sprintf('%s-%s-%s', $establishment, $emissionPoint, str_pad($sequential, 9, '0', STR_PAD_LEFT));
    }

    private function gateway(string $apiMode): NativeBillingGateway {
        $gateway = new NativeBillingGateway(null, $this->rawApiKey(), $this->environment($apiMode));
        $gateway->assertReady();

        return $gateway;
    }

    private function rawApiKey(): string {
        $apiKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
        if ($apiKey !== '') {
            return $apiKey;
        }

        $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            $token = trim((string)($matches[1] ?? ''));
            if ($token !== '') {
                return $token;
            }
        }

        throw new \InvalidArgumentException('Credencial de facturacion requerida. Usa X-API-Key o Authorization Bearer.');
    }

    private function environment(string $apiMode): string {
        $normalized = strtolower(trim($apiMode));
        if ($normalized === 'test') {
            return 'pruebas';
        }
        if ($normalized === 'production') {
            return 'produccion';
        }

        throw new BillingApiException('Ambiente de facturacion no registrado.', 404, 'native://billing/environment');
    }

    private function jsonBody(bool $required = true): array {
        $raw = file_get_contents('php://input');
        if ((!is_string($raw) || trim($raw) === '') && !$required) {
            return [];
        }

        $data = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON invalido');
        }

        return $data;
    }

    private function runJson(callable $callback, int $successStatus = 200): void {
        try {
            $this->jsonSuccess($callback(), $successStatus);
        } catch (BillingApiException $exception) {
            $this->jsonError($exception->getMessage(), $exception->httpStatusCode());
        } catch (\InvalidArgumentException $exception) {
            $this->jsonError($exception->getMessage(), $this->isAuthFailure($exception->getMessage()) ? 401 : 400);
        } catch (\PDOException $exception) {
            $this->logInternalFailure('PUBLIC_BILLING_DATABASE_ERROR', $exception);
            $this->jsonError('Error interno del servidor', 500);
        } catch (\RuntimeException $exception) {
            $this->logInternalFailure('PUBLIC_BILLING_RUNTIME_ERROR', $exception);
            $this->jsonError('No se pudo completar la operacion de facturacion.', 409);
        } catch (\Throwable $exception) {
            $this->logInternalFailure('PUBLIC_BILLING_ERROR', $exception);
            $this->jsonError('Error interno del servidor', 500);
        }
    }

    private function runFile(callable $callback, string $documentType = 'document'): void {
        try {
            $file = $callback();
            $content = (string)($file['content'] ?? '');
            if ($content === '') {
                throw new BillingApiException($this->fileUnavailableMessage($documentType), 404, 'native://billing/' . $documentType);
            }

            http_response_code(200);
            header('Content-Type: ' . (string)($file['content_type'] ?? 'application/octet-stream'));
            header(sprintf(
                'Content-Disposition: %s; filename="%s"',
                (string)($file['disposition'] ?? 'inline'),
                addcslashes((string)($file['filename'] ?? 'documento'), '"\\')
            ));
            header('Content-Length: ' . strlen($content));
            echo $content;
        } catch (BillingApiException $exception) {
            $this->jsonError(
                $this->fileErrorMessage($exception, $documentType),
                $this->fileErrorStatus($exception),
                $this->fileErrorCode($exception, $documentType),
                $this->fileErrorDetails($exception, $documentType)
            );
        } catch (\InvalidArgumentException $exception) {
            $this->jsonError($exception->getMessage(), $this->isAuthFailure($exception->getMessage()) ? 401 : 400);
        } catch (\RuntimeException $exception) {
            $this->jsonError(
                $this->fileUnavailableMessage($documentType),
                404,
                $this->fileUnavailableCode($documentType),
                $this->fileUnavailableDetails($documentType)
            );
        } catch (\Throwable $exception) {
            $this->logInternalFailure('PUBLIC_BILLING_FILE_ERROR', $exception);
            $this->jsonError('Error interno del servidor', 500);
        }
    }

    private function logInternalFailure(string $event, \Throwable $exception): void {
        error_log(json_encode([
            'event' => $event,
            'error_type' => $exception::class,
            'exception_code' => (int)$exception->getCode(),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function fileErrorStatus(BillingApiException $exception): int {
        $status = $exception->httpStatusCode();
        if ($status >= 400 && $status < 500) {
            return $status;
        }

        return 502;
    }

    private function fileErrorCode(BillingApiException $exception, string $documentType): string {
        $status = $exception->httpStatusCode();
        $prefix = $this->fileErrorCodePrefix($documentType);
        if ($status === 400) {
            return $prefix . '_INVALID_KEY';
        }
        if ($status === 404 || $status === 409) {
            return $prefix . '_NOT_AVAILABLE';
        }

        return $prefix . '_FAILED';
    }

    private function fileErrorCodePrefix(string $documentType): string {
        return match ($documentType) {
            'xml' => 'BILLING_XML',
            'ride-pdf' => 'BILLING_RIDE_PDF',
            default => 'BILLING_DOCUMENT',
        };
    }

    private function fileUnavailableCode(string $documentType): string {
        return $this->fileErrorCodePrefix($documentType) . '_NOT_AVAILABLE';
    }

    private function fileErrorMessage(BillingApiException $exception, string $documentType): string {
        $status = $exception->httpStatusCode();
        if ($status === 400) {
            return 'La clave de acceso del comprobante no es valida.';
        }

        $endpoint = strtolower($exception->endpoint());
        if ($status === 404 || $status === 409) {
            if ($documentType === 'xml' || str_contains($endpoint, 'xml')) {
                return $this->fileUnavailableMessage('xml');
            }
            if ($documentType === 'ride-pdf' || str_contains($endpoint, 'ride')) {
                return $this->fileUnavailableMessage('ride-pdf');
            }

            return $this->fileUnavailableMessage('document');
        }

        return 'No se pudo preparar el documento solicitado. Consulta el estado del comprobante o intenta nuevamente mas tarde.';
    }

    private function fileErrorDetails(BillingApiException $exception, string $documentType): array {
        $status = $exception->httpStatusCode();
        if ($status === 404 || $status === 409) {
            return $this->fileUnavailableDetails($documentType);
        }

        return [
            'document' => $documentType,
            'status' => 'failed',
            'action' => 'check_status',
        ];
    }

    private function fileUnavailableDetails(string $documentType): array {
        return [
            'document' => $documentType,
            'status' => 'pending',
            'action' => 'check_status',
            'retry_after_seconds' => 3600,
        ];
    }

    private function fileUnavailableMessage(string $documentType): string {
        if ($documentType === 'xml') {
            return 'El XML autorizado aun no esta disponible para descarga. Consulta el estado del comprobante o intenta nuevamente mas tarde.';
        }

        if ($documentType === 'ride-pdf') {
            return 'El RIDE PDF aun no esta disponible para este comprobante. Consulta el estado o intenta nuevamente mas tarde.';
        }

        return 'Documento no disponible. Consulta el estado del comprobante o intenta nuevamente mas tarde.';
    }

    private function jsonSuccess(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function jsonError(string $message, int $statusCode, string|int|null $code = null, mixed $details = null): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        $error = [
            'message' => $message,
            'code' => $code ?? $statusCode,
        ];
        if ($code !== null && $code !== $statusCode) {
            $error['status_code'] = $statusCode;
        }
        if ($details !== null) {
            $error['details'] = $details;
        }

        echo json_encode([
            'success' => false,
            'error' => $error,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isAuthFailure(string $message): bool {
        $message = strtolower($message);

        return str_contains($message, 'api key')
            || str_contains($message, 'credencial de facturacion')
            || str_contains($message, 'api test')
            || str_contains($message, 'api produccion');
    }
}
