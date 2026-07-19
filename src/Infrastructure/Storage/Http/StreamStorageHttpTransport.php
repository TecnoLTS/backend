<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage\Http;

use App\Infrastructure\Storage\StorageException;

final class StreamStorageHttpTransport implements StorageHttpTransport
{
    public function request(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $timeoutSeconds,
        bool $verifyTls
    ): StorageHttpResponse {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => $verifyTls,
                'verify_peer_name' => $verifyTls,
            ],
        ]);

        $previous = set_error_handler(static fn(int $severity, string $message): bool => true);
        try {
            $responseBody = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
        /** @var list<string> $http_response_header */
        $rawHeaders = $http_response_header ?? [];
        if ($responseBody === false && $rawHeaders === []) {
            throw new StorageException('No fue posible conectar con el almacenamiento S3-compatible.');
        }

        $status = 0;
        $parsedHeaders = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $parsedHeaders = [];
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            $parsedHeaders[$name] = isset($parsedHeaders[$name])
                ? $parsedHeaders[$name] . ', ' . $value
                : $value;
        }
        if ($status === 0) {
            throw new StorageException('El almacenamiento S3-compatible devolvió una respuesta HTTP inválida.');
        }

        return new StorageHttpResponse($status, $parsedHeaders, is_string($responseBody) ? $responseBody : '');
    }
}
