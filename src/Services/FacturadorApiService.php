<?php

namespace App\Services;

class FacturadorApiService {
    private string $baseUrl;
    private string $invoiceEndpoint;
    private int $timeoutSeconds;
    private string $apiKey;

    public function __construct(?string $baseUrl = null, ?int $timeoutSeconds = null) {
        $resolvedBaseUrl = rtrim((string)($baseUrl ?? ($_ENV['FACTURADOR_API_URL'] ?? getenv('FACTURADOR_API_URL') ?: 'http://facturador:8080')), '/');
        if ($resolvedBaseUrl === '') {
            throw new \RuntimeException('FACTURADOR_API_URL no configurado');
        }

        $this->baseUrl = $resolvedBaseUrl;
        $configuredInvoicesPath = trim((string)($_ENV['FACTURADOR_API_INVOICES_PATH'] ?? getenv('FACTURADOR_API_INVOICES_PATH') ?: ''));
        if (preg_match('#/invoices/?$#', $resolvedBaseUrl) === 1) {
            $this->invoiceEndpoint = $resolvedBaseUrl;
        } else {
            $invoicesPath = $configuredInvoicesPath !== ''
                ? $configuredInvoicesPath
                : $this->defaultInvoicesPath();
            $this->invoiceEndpoint = $resolvedBaseUrl . $this->normalizeInvoicesPath($invoicesPath);
        }
        $this->timeoutSeconds = max(1, (int)($timeoutSeconds ?? ($_ENV['FACTURADOR_TIMEOUT'] ?? getenv('FACTURADOR_TIMEOUT') ?: 20)));
        $this->apiKey = trim((string)($_ENV['FACTURADOR_API_KEY'] ?? getenv('FACTURADOR_API_KEY') ?: ''));
        if ($this->apiKey === '') {
            throw new \RuntimeException('FACTURADOR_API_KEY no configurado');
        }
    }

    public function emitInvoice(array $payload): array {
        $requestBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($requestBody === false) {
            throw new \RuntimeException('No se pudo serializar la solicitud para facturador');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-API-Key: ' . $this->apiKey,
                    'Content-Length: ' . strlen($requestBody),
                ]),
                'content' => $requestBody,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($this->invoiceEndpoint, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->extractStatusCode($responseHeaders);

        if ($responseBody === false && $statusCode === 0) {
            throw new \RuntimeException('No fue posible conectar con el facturador');
        }

        $decoded = is_string($responseBody) && $responseBody !== '' ? json_decode($responseBody, true) : null;
        if ($statusCode >= 200 && $statusCode < 300 && is_array($decoded) && ($decoded['success'] ?? false) === true && is_array($decoded['data'] ?? null)) {
            return $decoded['data'];
        }

        $message = null;
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? null;
        }
        if (!is_string($message) || trim($message) === '') {
            $message = 'Respuesta inválida del facturador';
        }

        throw new \RuntimeException(sprintf('Facturador respondió con error (%d): %s', $statusCode ?: 500, $message));
    }

    public function listRidePdfs(int $limit = 100): array {
        $endpoint = $this->invoiceEndpoint . '/rides?limit=' . max(1, min(300, $limit));
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'X-API-Key: ' . $this->apiKey,
                ]),
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($endpoint, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->extractStatusCode($responseHeaders);
        $decoded = is_string($responseBody) && $responseBody !== '' ? json_decode($responseBody, true) : null;

        if ($statusCode >= 200 && $statusCode < 300 && is_array($decoded) && ($decoded['success'] ?? false) === true && is_array($decoded['data'] ?? null)) {
            return $decoded['data'];
        }

        $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? null) : null;
        throw new \RuntimeException(sprintf(
            'No se pudo listar RIDE PDF del facturador (%d) en %s: %s',
            $statusCode ?: 500,
            $endpoint,
            is_string($message) && trim($message) !== '' ? $message : 'respuesta inválida'
        ));
    }

    public function getRidePdf(string $accessKey): array {
        $normalizedAccessKey = preg_replace('/[^0-9]/', '', $accessKey);
        if (!is_string($normalizedAccessKey) || $normalizedAccessKey === '') {
            throw new \InvalidArgumentException('Clave de acceso inválida');
        }

        $endpoint = $this->invoiceEndpoint . '/' . rawurlencode($normalizedAccessKey) . '/ride.pdf';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: application/pdf',
                    'X-API-Key: ' . $this->apiKey,
                ]),
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($endpoint, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->extractStatusCode($responseHeaders);

        if ($statusCode >= 200 && $statusCode < 300 && is_string($body) && $body !== '') {
            return [
                'filename' => 'RIDE-' . $normalizedAccessKey . '.pdf',
                'content' => $body,
            ];
        }

        $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? null) : null;
        throw new \RuntimeException(sprintf(
            'No se pudo obtener RIDE PDF del facturador (%d) en %s: %s',
            $statusCode ?: 500,
            $endpoint,
            is_string($message) && trim($message) !== '' ? $message : 'respuesta inválida'
        ));
    }

    public function cancelAndReissueInvoice(string $accessKey, string $reason = '', ?string $ambiente = null): array {
        $normalizedAccessKey = preg_replace('/[^0-9]/', '', $accessKey);
        if (!is_string($normalizedAccessKey) || $normalizedAccessKey === '') {
            throw new \InvalidArgumentException('Clave de acceso inválida');
        }

        $invoiceEndpoint = $this->invoiceEndpointForAmbiente($ambiente);
        $endpoint = $invoiceEndpoint . '/' . rawurlencode($normalizedAccessKey) . '/cancel-and-reissue';
        $requestBody = json_encode([
            'reason' => trim($reason) !== '' ? trim($reason) : 'Reemision manual solicitada desde panel administrativo.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($requestBody === false) {
            throw new \RuntimeException('No se pudo serializar la solicitud de reemision');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-API-Key: ' . $this->apiKey,
                    'Content-Length: ' . strlen($requestBody),
                ]),
                'content' => $requestBody,
                'timeout' => max($this->timeoutSeconds, 60),
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($endpoint, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = $this->extractStatusCode($responseHeaders);
        $decoded = is_string($responseBody) && $responseBody !== '' ? json_decode($responseBody, true) : null;

        if ($statusCode >= 200 && $statusCode < 300 && is_array($decoded) && ($decoded['success'] ?? false) === true && is_array($decoded['data'] ?? null)) {
            return $decoded['data'];
        }

        $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? null) : null;
        throw new \RuntimeException(sprintf(
            'No se pudo anular y reemitir la factura (%d) en %s: %s',
            $statusCode ?: 500,
            $endpoint,
            is_string($message) && trim($message) !== '' ? $message : 'respuesta inválida'
        ));
    }

    private function extractStatusCode(array $headers): int {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$header, $matches) === 1) {
                return (int)$matches[1];
            }
        }

        return 0;
    }

    private function normalizeInvoicesPath(string $path): string
    {
        $normalized = '/' . ltrim(trim($path), '/');
        return rtrim($normalized, '/');
    }

    private function defaultInvoicesPath(): string
    {
        $env = strtolower(trim((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production')));
        if (in_array($env, ['development', 'dev', 'local'], true)) {
            return '/api/test/v1/invoices';
        }

        return '/api/production/v1/invoices';
    }

    private function invoiceEndpointForAmbiente(?string $ambiente): string
    {
        $normalized = strtolower(trim((string)($ambiente ?? '')));
        if ($normalized === 'produccion' || $normalized === 'production') {
            return $this->baseUrl . '/api/production/v1/invoices';
        }
        if ($normalized === 'pruebas' || $normalized === 'test' || $normalized === 'testing') {
            return $this->baseUrl . '/api/test/v1/invoices';
        }

        return $this->invoiceEndpoint;
    }
}
