<?php

declare(strict_types=1);

namespace App\Http\Pagination;

/**
 * Cursor opaco y estable para listados ordenados por created_at DESC, id DESC.
 */
final class CreatedAtIdCursor
{
    private const VERSION = 1;
    private const MAX_TOKEN_LENGTH = 512;

    /** @return array{createdAt:string,id:string}|null */
    public static function decode(?string $token, string $resource = 'lista'): ?array
    {
        $token = trim((string)$token);
        if ($token === '') {
            return null;
        }
        if (strlen($token) > self::MAX_TOKEN_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw new \InvalidArgumentException("Cursor de {$resource} invalido.");
        }

        $padding = (4 - (strlen($token) % 4)) % 4;
        $decoded = base64_decode(strtr($token . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new \InvalidArgumentException("Cursor de {$resource} invalido.");
        }

        try {
            $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException("Cursor de {$resource} invalido.");
        }

        if (!is_array($payload) || ($payload['v'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException("Version de cursor de {$resource} no soportada.");
        }

        $createdAt = trim((string)($payload['createdAt'] ?? ''));
        $id = trim((string)($payload['id'] ?? ''));
        if ($createdAt === '' || $id === '' || strlen($createdAt) > 64 || strlen($id) > 255) {
            throw new \InvalidArgumentException("Cursor de {$resource} incompleto.");
        }
        try {
            new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Fecha de cursor de {$resource} invalida.");
        }

        return ['createdAt' => $createdAt, 'id' => $id];
    }

    /** @param array{createdAt:string,id:string} $position */
    public static function encode(array $position): string
    {
        $createdAt = trim((string)($position['createdAt'] ?? ''));
        $id = trim((string)($position['id'] ?? ''));
        if ($createdAt === '' || $id === '') {
            throw new \InvalidArgumentException('No se puede codificar un cursor incompleto.');
        }

        $json = json_encode([
            'v' => self::VERSION,
            'createdAt' => $createdAt,
            'id' => $id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
