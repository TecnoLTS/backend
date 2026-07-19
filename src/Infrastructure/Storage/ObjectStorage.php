<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

interface ObjectStorage
{
    public function driver(): string;

    /**
     * @return array{key: string, size: int, content_type: string, etag: ?string, modified_at: ?int}
     */
    public function put(string $key, string $contents, string $contentType = 'application/octet-stream'): array;

    public function get(string $key): string;

    public function exists(string $key): bool;

    /**
     * @return array{key: string, size: int, content_type: ?string, etag: ?string, modified_at: ?int}|null
     */
    public function metadata(string $key): ?array;

    public function delete(string $key): void;

    /**
     * Returns a readable local path. S3 implementations download to a process-local
     * temporary file and remove it at shutdown.
     */
    public function materialize(string $key): string;

    /** Returns the canonical local path when this is a local driver. */
    public function localPath(string $key): ?string;
}
