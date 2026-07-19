<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Adapters;

use App\Modules\Commerce\Application\CommerceBillingTransportException;
use App\Modules\Commerce\Application\Ports\CommerceBillingHttpPort;
use App\Modules\Commerce\Infrastructure\Security\CommerceBillingCredentialRegistry;

final class AuthenticatedCommerceBillingHttpAdapter implements CommerceBillingHttpPort
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly CommerceBillingCredentialRegistry $credentials,
        private readonly int $timeoutSeconds = 45
    ) {
        $parts = parse_url($this->baseUrl);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = trim((string)($parts['path'] ?? ''), '/');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $path !== '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid fixed Billing internal base URL.');
        }
        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 120) {
            throw new \InvalidArgumentException('Billing HTTP timeout is outside production bounds.');
        }
    }

    public function findBySourceReference(
        string $tenantId,
        string $tenantHost,
        string $apiMode,
        string $sourceReference
    ): array
    {
        $response = $this->request(
            'GET',
            $tenantId,
            $tenantHost,
            $apiMode,
            '/invoices/source/' . rawurlencode($sourceReference),
            null,
            null
        );
        if ($response['status'] === 404) {
            return ['found' => false, 'http_status' => 404, 'invoice' => null];
        }
        $invoice = $this->successData($response, 'BILLING_LOOKUP_FAILED', false);

        return ['found' => true, 'http_status' => $response['status'], 'invoice' => $invoice];
    }

    public function emit(
        string $tenantId,
        string $tenantHost,
        string $apiMode,
        string $idempotencyKey,
        array $payload
    ): array
    {
        $response = $this->request('POST', $tenantId, $tenantHost, $apiMode, '/invoices', $payload, $idempotencyKey);
        $invoice = $this->successData($response, 'BILLING_EMIT_FAILED', $response['status'] >= 500);

        return ['http_status' => $response['status'], 'invoice' => $invoice];
    }

    /** @return array{status:int,body:array,raw:string} */
    private function request(
        string $method,
        string $tenantId,
        string $tenantHost,
        string $apiMode,
        string $suffix,
        ?array $body,
        ?string $idempotencyKey
    ): array {
        $tenantId = strtolower(trim($tenantId));
        $tenantHost = strtolower(rtrim(trim($tenantHost), '.'));
        // Resolving the credential before building headers is the mandatory
        // tenant+host authorization boundary. No global fallback exists.
        $apiKey = $this->credentials->credentialFor($tenantId, $tenantHost);
        $apiMode = strtolower(trim($apiMode));
        if (!in_array($apiMode, ['test', 'production'], true)) {
            throw new CommerceBillingTransportException('Invalid Billing API mode in durable command.', false, null, 'BILLING_API_MODE_INVALID');
        }
        $url = rtrim($this->baseUrl, '/') . '/api/' . $apiMode . '/v1' . $suffix;
        $headers = [
            'Host: ' . $tenantHost,
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Request-ID: cbout-' . bin2hex(random_bytes(8)),
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . substr($idempotencyKey, 0, 128);
        }
        $payload = $body !== null
            ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload,
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'protocol_version' => 1.1,
        ]]);
        $previous = set_error_handler(static fn(): bool => true);
        try {
            $raw = file_get_contents($url, false, $context, 0, 1048577);
        } finally {
            restore_error_handler();
        }
        if ($raw === false) {
            throw new CommerceBillingTransportException(
                'Billing transport failed before a definitive response.',
                $method === 'POST',
                null,
                'BILLING_TRANSPORT_FAILED'
            );
        }
        if (strlen($raw) > 1048576) {
            throw new CommerceBillingTransportException(
                'Billing response exceeded the worker safety limit.',
                $method === 'POST',
                null,
                'BILLING_RESPONSE_TOO_LARGE'
            );
        }
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string)$header, $matches) === 1) {
                $status = (int)$matches[1];
            }
        }
        if ($status < 100 || $status > 599) {
            throw new CommerceBillingTransportException('Billing returned no valid HTTP status.', $method === 'POST', null, 'BILLING_HTTP_STATUS_MISSING');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new CommerceBillingTransportException('Billing returned invalid JSON.', $method === 'POST' && $status >= 200 && $status < 500, $status, 'BILLING_RESPONSE_INVALID');
        }

        return ['status' => $status, 'body' => $decoded, 'raw' => $raw];
    }

    /** @return array<string,mixed> */
    private function successData(array $response, string $errorCode, bool $deliveryUnknown): array
    {
        $status = (int)$response['status'];
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        if ($status >= 200 && $status < 300 && ($body['success'] ?? false) === true && is_array($body['data'] ?? null)) {
            return $body['data'];
        }
        $message = trim((string)($body['error']['message'] ?? 'Billing rejected the command.'));
        throw new CommerceBillingTransportException(
            $message !== '' ? $message : 'Billing rejected the command.',
            $deliveryUnknown,
            $status,
            $errorCode . '_' . $status
        );
    }
}
