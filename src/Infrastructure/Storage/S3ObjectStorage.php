<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Infrastructure\Storage\Http\StorageHttpResponse;
use App\Infrastructure\Storage\Http\StorageHttpTransport;
use App\Infrastructure\Storage\Http\StreamStorageHttpTransport;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

final class S3ObjectStorage implements ObjectStorage
{
    private readonly StorageHttpTransport $transport;
    private readonly Closure $clock;

    public function __construct(
        private readonly StorageConfiguration $configuration,
        private readonly string $scopePrefix,
        ?StorageHttpTransport $transport = null,
        ?Closure $clock = null
    ) {
        if ($configuration->driver !== 's3') {
            throw new StorageException('S3ObjectStorage requiere una configuración con driver s3.');
        }
        $this->transport = $transport ?? new StreamStorageHttpTransport();
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function driver(): string
    {
        return 's3';
    }

    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): array
    {
        $key = StorageKey::normalize($key);
        $response = $this->request('PUT', $key, $contents, $contentType);
        $this->assertSuccess($response, 'escribir', $key);

        return [
            'key' => $key,
            'size' => strlen($contents),
            'content_type' => $contentType,
            'etag' => $this->cleanEtag($response->header('etag')),
            'modified_at' => null,
        ];
    }

    public function get(string $key): string
    {
        $key = StorageKey::normalize($key);
        $response = $this->request('GET', $key);
        if ($response->status === 404) {
            throw new StorageException(sprintf('Objeto no encontrado: %s', $key));
        }
        $this->assertSuccess($response, 'leer', $key);

        return $response->body;
    }

    public function exists(string $key): bool
    {
        return $this->metadata($key) !== null;
    }

    public function metadata(string $key): ?array
    {
        $key = StorageKey::normalize($key);
        $response = $this->request('HEAD', $key);
        if ($response->status === 404) {
            return null;
        }
        $this->assertSuccess($response, 'consultar', $key);

        $size = $response->header('content-length');
        $modified = $response->header('last-modified');
        $modifiedAt = is_string($modified) ? strtotime($modified) : false;

        return [
            'key' => $key,
            'size' => is_string($size) && ctype_digit($size) ? (int) $size : 0,
            'content_type' => $response->header('content-type'),
            'etag' => $this->cleanEtag($response->header('etag')),
            'modified_at' => $modifiedAt === false ? null : $modifiedAt,
        ];
    }

    public function delete(string $key): void
    {
        $key = StorageKey::normalize($key);
        $response = $this->request('DELETE', $key);
        if ($response->status === 404) {
            return;
        }
        $this->assertSuccess($response, 'eliminar', $key);
    }

    public function materialize(string $key): string
    {
        $key = StorageKey::normalize($key);
        $directory = rtrim($this->configuration->temporaryRoot, '/') . '/' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException('No se pudo preparar el directorio temporal para materializar un objeto.');
        }

        $suffix = pathinfo($key, PATHINFO_EXTENSION);
        $path = $directory . '/object' . ($suffix !== '' ? '.' . preg_replace('/[^a-zA-Z0-9]/', '', $suffix) : '');
        $contents = $this->get($key);
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            @unlink($path);
            @rmdir($directory);
            throw new StorageException('No se pudo materializar el objeto en el filesystem temporal.');
        }
        @chmod($path, 0600);
        register_shutdown_function(static function () use ($path, $directory): void {
            @unlink($path);
            @rmdir($directory);
        });

        return $path;
    }

    public function localPath(string $key): ?string
    {
        StorageKey::normalize($key);
        return null;
    }

    private function request(
        string $method,
        string $key,
        string $body = '',
        string $contentType = ''
    ): StorageHttpResponse {
        $objectKey = StorageKey::prefix($this->fullPrefix(), $key);
        [$url, $canonicalUri, $host] = $this->objectUrl($objectKey);
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new StorageException('El reloj del cliente S3 devolvió un valor inválido.');
        }
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $payloadHash = hash('sha256', $body);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        if ($this->configuration->sessionToken !== '') {
            $headers['x-amz-security-token'] = $this->configuration->sessionToken;
        }
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . preg_replace('/\s+/', ' ', trim($value)) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $credentialScope = implode('/', [$date, $this->configuration->region, 's3', 'aws4_request']);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $this->configuration->secretKey, true);
        $regionKey = hash_hmac('sha256', $this->configuration->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->configuration->accessKey,
            $credentialScope,
            $signedHeaders,
            $signature
        );
        $transportHeaders = [];
        foreach ($headers as $name => $value) {
            $transportHeaders[$this->headerName($name)] = $value;
        }

        return $this->transport->request(
            $method,
            $url,
            $transportHeaders,
            $body,
            $this->configuration->timeoutSeconds,
            $this->configuration->verifyTls
        );
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function objectUrl(string $objectKey): array
    {
        $parts = parse_url($this->configuration->endpoint);
        if (!is_array($parts)) {
            throw new StorageException('Endpoint S3 inválido.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $basePath = trim((string) ($parts['path'] ?? ''), '/');
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $objectKey)));
        $encodedBucket = rawurlencode($this->configuration->bucket);

        if ($this->configuration->pathStyle) {
            $path = '/' . implode('/', array_filter([$basePath, $encodedBucket, $encodedKey], static fn(string $part): bool => $part !== ''));
            $requestHost = $host;
        } else {
            $path = '/' . implode('/', array_filter([$basePath, $encodedKey], static fn(string $part): bool => $part !== ''));
            $requestHost = $this->configuration->bucket . '.' . $host;
        }
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $authority = $requestHost . ($port !== null && !$defaultPort ? ':' . $port : '');

        return [$scheme . '://' . $authority . $path, $path, $authority];
    }

    private function fullPrefix(): string
    {
        $parts = array_filter([
            trim($this->configuration->prefix, '/'),
            trim($this->scopePrefix, '/'),
        ], static fn(string $part): bool => $part !== '');

        return implode('/', $parts);
    }

    private function assertSuccess(StorageHttpResponse $response, string $operation, string $key): void
    {
        if ($response->status >= 200 && $response->status < 300) {
            return;
        }

        $requestId = $response->header('x-amz-request-id') ?? $response->header('x-request-id');
        throw new StorageException(sprintf(
            'No se pudo %s el objeto %s en S3 (HTTP %d%s).',
            $operation,
            $key,
            $response->status,
            $requestId !== null ? ', request_id=' . preg_replace('/[^a-zA-Z0-9._:-]/', '', $requestId) : ''
        ));
    }

    private function cleanEtag(?string $etag): ?string
    {
        if ($etag === null || trim($etag) === '') {
            return null;
        }

        return trim($etag, " \t\n\r\0\x0B\"");
    }

    private function headerName(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
