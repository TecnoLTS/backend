<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage\Http;

interface StorageHttpTransport
{
    /** @param array<string, string> $headers */
    public function request(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $timeoutSeconds,
        bool $verifyTls
    ): StorageHttpResponse;
}
