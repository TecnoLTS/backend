<?php

declare(strict_types=1);

namespace App\Modules\CatalogInventory\Application;

/**
 * Validates the bounded public catalogue query contract before it reaches SQL.
 * Interactive consumers may narrow a cursor page, but cannot request an
 * unbounded projection or arbitrary database expressions.
 */
final class PublicCatalogFilters
{
    private const MAX_IDS = 100;

    /**
     * @param array<string, mixed> $query
     * @return array{
     *   search?: string,
     *   category?: string,
     *   productType?: string,
     *   gender?: 'dog'|'cat',
     *   brandSlug?: string,
     *   variantGroup?: string,
     *   ids?: list<string>,
     *   saleOnly?: bool
     * }
     */
    public static function fromQuery(array $query): array
    {
        $filters = [];

        self::copyText($filters, 'search', $query['q'] ?? $query['search'] ?? null, 80, false);
        self::copyText($filters, 'category', $query['category'] ?? null, 80, true);
        self::copyText($filters, 'productType', $query['product_type'] ?? $query['productType'] ?? null, 80, true);
        self::copyText($filters, 'brandSlug', $query['brand_slug'] ?? $query['brandSlug'] ?? null, 100, true);
        self::copyText($filters, 'variantGroup', $query['variant_group'] ?? $query['variantGroup'] ?? null, 160, false);

        $gender = self::text($query['gender'] ?? null, 16);
        if ($gender !== '') {
            $normalizedGender = strtolower($gender);
            $genderAliases = [
                'dog' => 'dog', 'perro' => 'dog', 'perros' => 'dog',
                'cat' => 'cat', 'gato' => 'cat', 'gatos' => 'cat',
            ];
            if (!isset($genderAliases[$normalizedGender])) {
                throw new \InvalidArgumentException('gender debe ser dog o cat.');
            }
            $filters['gender'] = $genderAliases[$normalizedGender];
        }

        $sale = $query['sale_only'] ?? $query['saleOnly'] ?? null;
        if ($sale !== null && $sale !== '') {
            if (is_array($sale)) {
                throw new \InvalidArgumentException('sale_only inválido.');
            }
            $normalizedSale = filter_var($sale, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($normalizedSale === null) {
                throw new \InvalidArgumentException('sale_only debe ser booleano.');
            }
            if ($normalizedSale) {
                $filters['saleOnly'] = true;
            }
        }

        $rawIds = $query['ids'] ?? null;
        if ($rawIds !== null && $rawIds !== '') {
            if (is_array($rawIds)) {
                $rawIds = implode(',', array_map(static fn($value): string => (string)$value, $rawIds));
            }
            $ids = array_values(array_unique(array_filter(array_map(
                static fn(string $value): string => trim($value),
                explode(',', (string)$rawIds)
            ), static fn(string $value): bool => $value !== '')));
            if (count($ids) > self::MAX_IDS) {
                throw new \InvalidArgumentException('ids admite como máximo 100 identificadores.');
            }
            foreach ($ids as $id) {
                if (strlen($id) > 160 || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
                    throw new \InvalidArgumentException('ids contiene un identificador inválido.');
                }
            }
            if ($ids !== []) {
                $filters['ids'] = $ids;
            }
        }

        return $filters;
    }

    public static function slug(string $value): string
    {
        $ascii = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
            'ñ' => 'n', 'Ñ' => 'n',
        ]);

        return trim((string)(preg_replace('/[^a-z0-9]+/i', '-', strtolower(trim($ascii))) ?? ''), '-');
    }

    private static function copyText(array &$filters, string $key, mixed $value, int $maxLength, bool $slug): void
    {
        $text = self::text($value, $maxLength);
        if ($text === '') {
            return;
        }
        $filters[$key] = $slug ? self::slug($text) : $text;
    }

    private static function text(mixed $value, int $maxLength): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_array($value) || is_object($value)) {
            throw new \InvalidArgumentException('Filtro de catálogo inválido.');
        }
        $text = trim((string)$value);
        if (strlen($text) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
            throw new \InvalidArgumentException('Filtro de catálogo inválido.');
        }

        return $text;
    }
}
