<?php

declare(strict_types=1);

namespace App\Support;

final class ProductFieldValueNormalizer
{
    private const NAMED_SIZES = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'STANDARD'];

    public static function normalizeDisplayValue(?string $value): string
    {
        $text = self::clean((string)$value);
        if ($text === '') {
            return '';
        }

        $upper = strtoupper(str_replace(',', '.', $text));
        if (in_array($upper, ['N/A', 'NA', 'NONE', 'NINGUNO', 'SIN TALLA'], true)) {
            return '';
        }

        if (in_array($upper, self::NAMED_SIZES, true)) {
            return $upper;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:(KGS?|KG|K|GR|G|LB|L|ML|MG|OZ)\s*)?(?:A|HASTA|-)\s*(\d+(?:\.\d+)?)\s*(KGS?|KG|K|GR|G|LB|L|ML|MG|OZ)$/i', $upper, $matches) === 1) {
            $unit = $matches[4];
            $fromUnit = trim((string)($matches[2] ?? ''));
            $from = self::normalizeAmount($matches[1]) . ($fromUnit !== '' ? ' ' . self::normalizeUnit($fromUnit) : '');
            return $from . ' a ' . self::normalizeAmount($matches[3]) . ' ' . self::normalizeUnit($unit);
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(KGS?|KG|K)$/i', $upper, $matches) === 1) {
            return self::normalizeAmount($matches[1]) . ' kg';
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(GR|G)$/i', $upper, $matches) === 1) {
            return self::normalizeAmount($matches[1]) . ' gr';
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(MLS?|ML)$/i', $upper, $matches) === 1) {
            return self::normalizeAmount($matches[1]) . ' ml';
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(LTS?|LT|L)$/i', $upper, $matches) === 1) {
            return self::normalizeAmount($matches[1]) . ' l';
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(CM)$/i', $upper, $matches) === 1) {
            return self::normalizeAmount($matches[1]) . ' cm';
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(MM)$/i', $upper, $matches) === 1) {
            return self::normalizeAmount($matches[1]) . ' mm';
        }

        return $text;
    }

    public static function normalizeVariantAttributeMap(array $attributes): array
    {
        $normalized = $attributes;
        foreach (['size', 'weight', 'target', 'range', 'presentation', 'packaging', 'dosage', 'volume'] as $key) {
            if (!array_key_exists($key, $normalized)) {
                continue;
            }

            $value = self::normalizeDisplayValue((string)$normalized[$key]);
            if ($value === '') {
                unset($normalized[$key]);
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public static function normalizeVariantLabelValue(?string $value): string
    {
        return self::normalizeDisplayValue($value);
    }

    private static function clean(string $value): string
    {
        return trim((string)(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private static function normalizeAmount(string $value): string
    {
        $numeric = str_replace(',', '.', trim($value));
        if (!is_numeric($numeric)) {
            return trim($value);
        }

        $formatted = number_format((float)$numeric, 3, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private static function normalizeUnit(string $unit): string
    {
        $upper = strtoupper(trim($unit));
        return match (true) {
            in_array($upper, ['KGS', 'KG', 'K'], true) => 'kg',
            in_array($upper, ['GR', 'G'], true) => 'gr',
            $upper === 'LB' => 'lb',
            $upper === 'L' => 'l',
            $upper === 'ML' => 'ml',
            $upper === 'MG' => 'mg',
            $upper === 'OZ' => 'oz',
            default => strtolower($upper),
        };
    }
}
