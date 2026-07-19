<?php

declare(strict_types=1);

namespace App\Http\Pagination;

final class BoundedPageRequest
{
    /**
     * @param array<string,mixed> $query
     */
    public static function pageSize(array $query, int $default = 100, int $maximum = 100): int
    {
        $raw = $query['page_size'] ?? ($query['pageSize'] ?? ($query['limit'] ?? $default));
        if (is_array($raw) || preg_match('/^[1-9][0-9]*$/', trim((string)$raw)) !== 1) {
            throw new \InvalidArgumentException("page_size debe ser un entero entre 1 y {$maximum}.");
        }

        $value = (int)$raw;
        if ($value < 1 || $value > $maximum) {
            throw new \InvalidArgumentException("page_size debe estar entre 1 y {$maximum}.");
        }
        return $value;
    }

    /**
     * Compatibilidad acotada para clientes antiguos basados en page/OFFSET.
     * Las integraciones nuevas deben usar cursor.
     *
     * @param array<string,mixed> $query
     */
    public static function legacyPage(array $query, int $maximum = 50): int
    {
        $raw = $query['page'] ?? 1;
        if (is_array($raw) || preg_match('/^[1-9][0-9]*$/', trim((string)$raw)) !== 1) {
            throw new \InvalidArgumentException("page debe ser un entero entre 1 y {$maximum}.");
        }
        $value = (int)$raw;
        if ($value < 1 || $value > $maximum) {
            throw new \InvalidArgumentException("page debe estar entre 1 y {$maximum}; usa cursor para continuar.");
        }
        return $value;
    }

    /** @param array<string,mixed> $query */
    public static function cursor(array $query, string $resource): ?array
    {
        $raw = $query['cursor'] ?? null;
        if (is_array($raw)) {
            throw new \InvalidArgumentException("Cursor de {$resource} invalido.");
        }
        return CreatedAtIdCursor::decode($raw === null ? null : (string)$raw, $resource);
    }
}
