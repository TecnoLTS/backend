<?php

declare(strict_types=1);

namespace App\Support;

final class ProductVariantMetadata
{
    private const CARE_VARIANT_FIELDS = ['range', 'weight', 'presentation', 'dosage', 'volume', 'packaging'];
    private const VARIANT_DEFINITION_FIELDS = [
        'weight',
        'volume',
        'presentation',
        'packaging',
        'size',
        'color',
        'flavor',
        'target',
        'age',
        'range',
        'dosage',
        'material',
    ];
    private const CONTENT_VARIANT_FIELDS = ['weight', 'volume', 'presentation', 'packaging'];
    private const DISPLAY_AXIS_FIELDS = ['presentation', 'size', 'color', 'range', 'dosage', 'flavor', 'target', 'age', 'material'];

    private const ATTRIBUTE_LABEL_KEYS = [
        'variantLabel',
        'size',
        'weight',
        'range',
        'presentation',
        'packaging',
        'dosage',
        'volume',
        'range',
        'color',
        'flavor',
        'target',
        'age',
        'material',
        'line',
    ];

    private const NAMED_SIZE_VALUES = [
        'XXS' => 0.5,
        'XS' => 1.0,
        'S' => 2.0,
        'M' => 3.0,
        'L' => 4.0,
        'XL' => 5.0,
        'XXL' => 6.0,
        'STANDARD' => 2.5,
    ];

    public static function apply(array $product, array $attributes): array
    {
        $attributes = ProductFieldValueNormalizer::normalizeVariantAttributeMap($attributes);
        $normalizedType = self::normalizeProductType(
            (string)($product['productType'] ?? $product['product_type'] ?? ''),
            (string)($product['category'] ?? '')
        );
        if ($normalizedType === 'cuidado') {
            $attributes = self::normalizeLegacyCareAttributes($attributes);
            unset($attributes['size']);
            $variantAxis = trim((string)($attributes['variantAxis'] ?? $attributes['variantDefinitionField'] ?? ''));
            if ($variantAxis !== '' && !in_array($variantAxis, self::CARE_VARIANT_FIELDS, true)) {
                unset($attributes['variantAxis'], $attributes['variantDefinitionField']);
            }
        }
        if ($normalizedType === 'alimento') {
            $attributes = self::normalizeFoodMeasurementAttributes($attributes);
        }
        $variantDefinitionField = self::resolveVariantDefinitionFieldByType($normalizedType, $attributes);
        if ($variantDefinitionField !== '') {
            $attributes['variantAxis'] = $variantDefinitionField;
            $attributes['variantDefinitionField'] = $variantDefinitionField;
        } else {
            unset($attributes['variantAxis'], $attributes['variantDefinitionField']);
        }

        $displayAxis = self::resolveDisplayAxisByType($normalizedType, $attributes);
        $requestedCatalogDisplayMode = self::normalizeCatalogDisplayMode(
            $attributes['catalogDisplayMode'] ?? ($attributes['variantDisplayMode'] ?? '')
        );
        if ($displayAxis !== '') {
            $attributes['displayAxis'] = $displayAxis;
            if (
                in_array($normalizedType, ['ropa', 'accesorios'], true)
                && $displayAxis === 'size'
                && trim((string)($attributes['color'] ?? '')) !== ''
                && self::isAccessorySizeVariantValue((string)($attributes['size'] ?? ''))
            ) {
                $attributes['catalogDisplayMode'] = $requestedCatalogDisplayMode === 'separate' ? 'separate' : 'grouped';
            } elseif ($displayAxis === 'color') {
                $attributes['catalogDisplayMode'] = $requestedCatalogDisplayMode !== '' ? $requestedCatalogDisplayMode : 'grouped';
            } elseif ($requestedCatalogDisplayMode !== '') {
                $attributes['catalogDisplayMode'] = $requestedCatalogDisplayMode;
            }
        } else {
            unset($attributes['displayAxis']);
        }

        $label = self::resolveVariantLabel($product, $attributes);
        if ($label === '') {
            unset($attributes['variantLabel'], $attributes['variantBaseName'], $attributes['variantGroupKey']);
            return $attributes;
        }

        $catalogDisplayMode = self::normalizeCatalogDisplayMode(
            $attributes['catalogDisplayMode'] ?? ($attributes['variantDisplayMode'] ?? '')
        );
        $productId = trim((string)($product['id'] ?? $product['internalId'] ?? $product['legacyId'] ?? ''));
        $baseName = self::resolveVariantBaseName($product, $attributes, $label);
        $groupKey = self::buildGroupKey($product, $attributes, $baseName);
        if ($catalogDisplayMode === 'separate' && $productId !== '') {
            $baseName = self::cleanWhitespace((string)($product['name'] ?? '')) ?: $baseName;
            $groupKey = 'single:' . $productId;
        }

        $attributes['variantLabel'] = ProductFieldValueNormalizer::normalizeVariantLabelValue($label);
        $attributes['variantBaseName'] = $baseName;
        $attributes['variantGroupKey'] = $groupKey;

        if ($normalizedType === 'alimento' && self::isContentMeasurementValue($label) && trim((string)($attributes['weight'] ?? '')) === '') {
            $attributes['weight'] = ProductFieldValueNormalizer::normalizeDisplayValue($label);
        } elseif ($normalizedType !== 'cuidado' && $normalizedType !== 'alimento' && self::isMeasurementLikeLabel($label) && trim((string)($attributes['size'] ?? '')) === '') {
            $attributes['size'] = ProductFieldValueNormalizer::normalizeDisplayValue($label);
        }

        return $attributes;
    }

    public static function resolveVariantLabel(array $product, array $attributes): string
    {
        $normalizedType = self::normalizeProductType(
            (string)($product['productType'] ?? $product['product_type'] ?? ''),
            (string)($product['category'] ?? '')
        );
        if ($normalizedType === 'cuidado') {
            $attributes = self::normalizeLegacyCareAttributes(
                ProductFieldValueNormalizer::normalizeVariantAttributeMap($attributes)
            );
        }
        if ($normalizedType === 'alimento') {
            $attributes = self::normalizeFoodMeasurementAttributes(
                ProductFieldValueNormalizer::normalizeVariantAttributeMap($attributes)
            );
        }
        $canonicalLabel = self::resolveCanonicalLabelByType($product, $attributes);
        if ($canonicalLabel !== '') {
            return $canonicalLabel;
        }

        if ($normalizedType === 'cuidado') {
            return '';
        }

        $explicit = trim((string)($product['variantLabel'] ?? ''));
        if ($explicit !== '') {
            return self::normalizeLabel($explicit);
        }

        foreach (self::attributeLabelKeysForType($normalizedType) as $key) {
            $value = trim((string)($attributes[$key] ?? ''));
            if ($value !== '') {
                return self::normalizeLabel($value);
            }
        }

        $name = trim((string)($product['name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $rangePattern = '/(\d+(?:[.,]\d+)?\s*(?:(?:KGS?|KG|K|GR|G|LB|L|ML|MG|OZ)\s*)?(?:a|hasta|-)\s*\d+(?:[.,]\d+)?\s*(?:KGS?|KG|K|GR|G|LB|L|ML|MG|OZ))$/iu';
        $patterns = $normalizedType === 'cuidado' ? [$rangePattern] : [
            $rangePattern,
            '/((?:\d+(?:[.,]\d+)?\s?(?:KGS?|KG|K|GR|G|LB|L|ML|MG|OZ))(?:\s*-\s*(?:\d+(?:[.,]\d+)?\s?(?:KGS?|KG|K|GR|G|LB|L|ML|MG|OZ)))?)$/iu',
            '/((?:\d+\s*x\s*\d+\s*(?:TABS?|DS|UN|UNI|PACK|PZA|PZ))|(?:x\s*\d+)|(?:\d+\s*(?:TABS?|DS|UN|UNI|PACK|PZA|PZ)))$/iu',
            '/((?:XXS|XS|S|M|L|XL|XXL|STANDARD))$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name, $matches) === 1) {
                return self::normalizeLabel((string)($matches[1] ?? ''));
            }
        }

        return '';
    }

    private static function resolveCanonicalLabelByType(array $product, array $attributes): string
    {
        $normalizedType = self::normalizeProductType(
            (string)($product['productType'] ?? $product['product_type'] ?? ''),
            (string)($product['category'] ?? '')
        );
        if ($normalizedType === 'cuidado') {
            $attributes = self::normalizeLegacyCareAttributes($attributes);
        }
        if ($normalizedType === 'alimento') {
            $attributes = self::normalizeFoodMeasurementAttributes($attributes);
        }

        $size = self::normalizeLabel((string)($attributes['size'] ?? ''));
        $weight = self::normalizeLabel((string)($attributes['weight'] ?? ''));
        $range = self::normalizeLabel((string)($attributes['range'] ?? ''));
        $presentation = self::normalizeLabel((string)($attributes['presentation'] ?? ''));
        $dosage = self::normalizeLabel((string)($attributes['dosage'] ?? ''));
        $volume = self::normalizeLabel((string)($attributes['volume'] ?? ''));
        $packaging = self::normalizeLabel((string)($attributes['packaging'] ?? ''));
        $color = self::cleanWhitespace((string)($attributes['color'] ?? ''));
        $normalizedColor = self::normalizeLabel($color);
        $explicit = self::normalizeLabel((string)($attributes['variantLabel'] ?? $product['variantLabel'] ?? ''));
        $variantAxis = self::resolveVariantDefinitionFieldByType($normalizedType, $attributes);
        $hasExplicitVariantAxis = trim((string)($attributes['variantDefinitionField'] ?? $attributes['variantAxis'] ?? '')) !== '';
        if ($normalizedType === 'ropa' && $variantAxis === 'size' && $size !== '') {
            return $size;
        }
        if ($normalizedType === 'ropa' && $size !== '' && $normalizedColor !== '') {
            return trim($size . ' ' . $normalizedColor);
        }
        if ($normalizedType === 'accesorios' && $variantAxis === 'size' && $size !== '') {
            return $size;
        }
        if ($normalizedType === 'accesorios' && $normalizedColor !== '' && $size !== '') {
            return trim($normalizedColor . ' ' . $size);
        }
        if ($normalizedType === 'accesorios' && $variantAxis === 'color' && $normalizedColor !== '') {
            return $normalizedColor;
        }

        if ($variantAxis !== '' && ($normalizedType !== 'cuidado' || in_array($variantAxis, self::CARE_VARIANT_FIELDS, true))) {
            $axisValue = self::normalizeLabel((string)($attributes[$variantAxis] ?? ''));
            if ($axisValue !== '') {
                return $axisValue;
            }
            if ($hasExplicitVariantAxis) {
                return '';
            }
        }

        return match ($normalizedType) {
            'ropa' => self::shouldPreserveDetailedExplicitLabel($explicit, $size, $normalizedColor)
                ? $explicit
                : ($size !== '' ? $size : ($normalizedColor !== '' ? $normalizedColor : $explicit)),
            'accesorios' => self::shouldPreserveDetailedExplicitLabel($explicit, $size, $normalizedColor)
                ? $explicit
                : ($normalizedColor !== '' ? $normalizedColor : ($size !== '' ? $size : ($presentation !== '' ? $presentation : $explicit))),
            'cuidado' => $weight !== ''
                ? $weight
                : ($volume !== ''
                    ? $volume
                    : ($dosage !== ''
                        ? $dosage
                        : ($presentation !== ''
                            ? $presentation
                            : ($packaging !== ''
                                ? $packaging
                                : $range)))),
            'alimento' => $weight !== '' ? $weight : ($presentation !== '' ? $presentation : $explicit),
            default => $explicit,
        };
    }

    private static function attributeLabelKeysForType(string $normalizedType): array
    {
        if ($normalizedType === 'cuidado') {
            return ['weight', 'volume', 'dosage', 'presentation', 'packaging', 'range'];
        }
        if ($normalizedType === 'alimento') {
            return ['weight', 'volume', 'presentation', 'packaging', 'flavor', 'target', 'age'];
        }

        return self::ATTRIBUTE_LABEL_KEYS;
    }

    private static function normalizeLegacyCareAttributes(array $attributes): array
    {
        $variantAxis = trim((string)($attributes['variantAxis'] ?? $attributes['variantDefinitionField'] ?? ''));
        if ($variantAxis !== '' && !in_array($variantAxis, self::CARE_VARIANT_FIELDS, true)) {
            unset($attributes['variantAxis'], $attributes['variantDefinitionField']);
        }

        unset($attributes['size']);

        return $attributes;
    }

    private static function normalizeFoodMeasurementAttributes(array $attributes): array
    {
        if (isset($attributes['presentation']) && self::isGenericPresentationValue((string)$attributes['presentation'])) {
            unset($attributes['presentation']);
        }

        $size = trim((string)($attributes['size'] ?? ''));
        $hasExplicitDefinitionField = trim((string)(
            $attributes['__variantDefinitionField'] ?? ($attributes['variantDefinitionField'] ?? '')
        )) !== '';
        $selectedVariantField = self::normalizeVariantDefinitionField(
            $attributes['__variantDefinitionField']
                ?? ($attributes['variantDefinitionField'] ?? ($attributes['variantAxis'] ?? ''))
        );
        if (
            !$hasExplicitDefinitionField
            && $selectedVariantField === 'size'
            && (
                ($size !== '' && self::isContentMeasurementValue($size))
                || trim((string)($attributes['weight'] ?? '')) !== ''
            )
        ) {
            $selectedVariantField = 'weight';
        }
        if ($size !== '') {
            if (self::isContentMeasurementValue($size)) {
                $normalizedSize = ProductFieldValueNormalizer::normalizeDisplayValue($size);
                if ($selectedVariantField === 'size') {
                    $attributes['size'] = $normalizedSize;
                } elseif (trim((string)($attributes['weight'] ?? '')) === '') {
                    $attributes['weight'] = $normalizedSize;
                }
                if ($selectedVariantField !== 'size') {
                    unset($attributes['size']);
                }
            } elseif ($selectedVariantField !== 'size') {
                unset($attributes['size']);
            }
        }

        $volume = trim((string)($attributes['volume'] ?? ''));
        if ($volume !== '' && self::isContentMeasurementValue($volume)) {
            $normalizedVolume = ProductFieldValueNormalizer::normalizeDisplayValue($volume);
            if ($selectedVariantField === 'volume') {
                $attributes['volume'] = $normalizedVolume;
            } elseif (trim((string)($attributes['weight'] ?? '')) === '') {
                $attributes['weight'] = $normalizedVolume;
            }
            if ($selectedVariantField !== 'volume') {
                unset($attributes['volume']);
            }
        }

        if (
            trim((string)($attributes['presentation'] ?? '')) === ''
            && trim((string)($attributes['packaging'] ?? '')) !== ''
        ) {
            $attributes['presentation'] = ProductFieldValueNormalizer::normalizeDisplayValue((string)$attributes['packaging']);
        }

        return $attributes;
    }

    private static function normalizeVariantDefinitionField(mixed $value): string
    {
        $field = strtolower(trim((string)$value));
        return in_array($field, self::VARIANT_DEFINITION_FIELDS, true) ? $field : '';
    }

    private static function attributeHasValue(array $attributes, string $field): bool
    {
        return $field !== '' && trim((string)($attributes[$field] ?? '')) !== '';
    }

    private static function displayAxisForVariantDefinitionField(string $field, string $normalizedType): string
    {
        $field = self::normalizeVariantDefinitionField($field);
        if ($field === '') {
            return '';
        }
        if (in_array($field, self::CONTENT_VARIANT_FIELDS, true)) {
            return 'presentation';
        }
        if ($normalizedType === 'cuidado' && !in_array($field, ['range', 'dosage'], true)) {
            return 'presentation';
        }
        return in_array($field, self::DISPLAY_AXIS_FIELDS, true) ? $field : '';
    }

    private static function resolveVariantDefinitionFieldByType(string $normalizedType, array $attributes): string
    {
        $explicit = '';
        foreach (['__variantDefinitionField', 'variantDefinitionField', 'variantAxis'] as $key) {
            $candidate = self::normalizeVariantDefinitionField($attributes[$key] ?? '');
            if ($candidate !== '') {
                if (
                    $key === 'variantAxis'
                    && $normalizedType === 'alimento'
                    && $candidate === 'size'
                    && (
                        self::isContentMeasurementValue((string)($attributes['size'] ?? ''))
                        || trim((string)($attributes['weight'] ?? '')) !== ''
                    )
                ) {
                    $candidate = 'weight';
                }
                $explicit = $candidate;
                break;
            }
        }

        if ($explicit !== '') {
            if ($normalizedType === 'cuidado' && !in_array($explicit, self::CARE_VARIANT_FIELDS, true)) {
                return '';
            }
            return $explicit;
        }

        $publicAxis = self::normalizeVariantDefinitionField($attributes['displayAxis'] ?? '');
        if (
            $publicAxis !== ''
            && !in_array($publicAxis, self::CONTENT_VARIANT_FIELDS, true)
            && self::attributeHasValue($attributes, $publicAxis)
        ) {
            return $publicAxis;
        }

        $preferredByType = match ($normalizedType) {
            'alimento' => ['weight', 'volume', 'packaging', 'presentation', 'flavor', 'target', 'age'],
            'cuidado' => ['weight', 'volume', 'dosage', 'presentation', 'packaging', 'range'],
            'ropa' => ['size', 'color', 'material'],
            'accesorios' => ['color', 'size', 'presentation', 'packaging', 'material'],
            default => ['size', 'color', 'weight', 'presentation'],
        };

        foreach ($preferredByType as $field) {
            if (self::attributeHasValue($attributes, $field)) {
                return $field;
            }
        }

        return '';
    }

    private static function isGenericPresentationValue(string $value): bool
    {
        return in_array(self::slugify($value), [
            'selecciona-presentacion',
            'crear-o-seleccionar-presentacion',
        ], true);
    }

    private static function resolveDisplayAxisByType(string $normalizedType, array $attributes): string
    {
        $hasValue = static fn(string $key): bool => trim((string)($attributes[$key] ?? '')) !== '';
        $requestedAxis = self::resolveRequestedDisplayAxis($attributes, $normalizedType);
        if ($requestedAxis !== '') {
            return $requestedAxis;
        }

        if ($normalizedType === 'ropa') {
            if ($hasValue('size')) {
                return 'size';
            }
            if ($hasValue('color')) {
                return 'color';
            }
        }

        if ($normalizedType === 'accesorios') {
            if ($hasValue('color')) {
                return 'color';
            }
            if ($hasValue('size')) {
                return 'size';
            }
            if ($hasValue('presentation') || $hasValue('packaging')) {
                return 'presentation';
            }
        }

        if ($normalizedType === 'cuidado') {
            if ($hasValue('weight') || $hasValue('volume') || $hasValue('presentation') || $hasValue('packaging')) {
                return 'presentation';
            }
            if ($hasValue('dosage')) {
                return 'dosage';
            }
            if ($hasValue('range')) {
                return 'range';
            }
        }

        if ($normalizedType === 'alimento') {
            if ($hasValue('weight') || $hasValue('presentation') || $hasValue('packaging') || $hasValue('volume')) {
                return 'presentation';
            }
        }

        return '';
    }

    private static function resolveRequestedDisplayAxis(array $attributes, string $normalizedType): string
    {
        $variantField = self::resolveVariantDefinitionFieldByType($normalizedType, $attributes);
        if ($variantField !== '') {
            return self::displayAxisForVariantDefinitionField($variantField, $normalizedType);
        }

        $rawAxis = '';
        foreach (['displayAxis', 'publicVariantAxis', 'catalogDisplayAxis'] as $key) {
            $candidate = trim((string)($attributes[$key] ?? ''));
            if ($candidate !== '') {
                $rawAxis = $candidate;
                break;
            }
        }

        $rawAxis = strtolower($rawAxis);
        $axis = in_array($rawAxis, self::DISPLAY_AXIS_FIELDS, true) ? $rawAxis : '';
        if ($axis === '') {
            return '';
        }

        if ($normalizedType === 'cuidado' && !in_array($axis, ['presentation', 'range', 'dosage'], true)) {
            return '';
        }

        $hasValue = static fn(string $key): bool => trim((string)($attributes[$key] ?? '')) !== '';

        return match ($axis) {
            'color' => $hasValue('color') ? 'color' : '',
            'size' => $hasValue('size') && !in_array($normalizedType, ['cuidado', 'alimento'], true) ? 'size' : '',
            'presentation' => ($hasValue('weight') || $hasValue('volume') || $hasValue('presentation') || $hasValue('packaging')) ? 'presentation' : '',
            'range' => $hasValue('range') ? 'range' : '',
            'dosage' => $hasValue('dosage') ? 'dosage' : '',
            'flavor' => $hasValue('flavor') ? 'flavor' : '',
            'target' => $hasValue('target') ? 'target' : '',
            'age' => $hasValue('age') ? 'age' : '',
            'material' => $hasValue('material') ? 'material' : '',
            default => '',
        };
    }

    private static function shouldPreserveDetailedExplicitLabel(string $explicit, string $size, string $color): bool
    {
        if ($explicit === '' || $size === '' || $color === '') {
            return false;
        }

        if ($explicit === $size || $explicit === $color) {
            return false;
        }

        return str_contains($explicit, $size) && str_contains($explicit, $color);
    }

    private static function normalizeProductType(string $type, string $category = ''): string
    {
        $normalize = static fn(string $value): string => match (strtolower(trim($value))) {
            'alimento', 'food' => 'alimento',
            'ropa', 'apparel' => 'ropa',
            'accesorios', 'accessories' => 'accesorios',
            'cuidado', 'care', 'salud', 'higiene', 'medicina', 'medicinas', 'farmacia' => 'cuidado',
            default => strtolower(trim($value)),
        };
        $typeValue = $normalize($type);
        $categoryValue = $normalize($category);
        if ($categoryValue === 'cuidado' && $typeValue === 'accesorios') {
            return 'cuidado';
        }
        return $typeValue !== '' ? $typeValue : $categoryValue;
    }

    public static function resolveVariantBaseName(array $product, array $attributes, ?string $label = null): string
    {
        $name = self::cleanWhitespace((string)($product['name'] ?? ''));
        $label = $label ?? self::resolveVariantLabel($product, $attributes);
        $normalizedType = self::normalizeProductType(
            (string)($product['productType'] ?? $product['product_type'] ?? ''),
            (string)($product['category'] ?? '')
        );
        if ($name === '') {
            return $name;
        }

        $displayAxisBaseName = self::resolveDisplayAxisBaseName($name, $attributes, $normalizedType);
        if ($displayAxisBaseName !== '') {
            return $displayAxisBaseName;
        }

        $explicitCandidates = array_values(array_filter([
            self::cleanWhitespace((string)($product['variantBaseName'] ?? '')),
            self::cleanWhitespace((string)($attributes['variantBaseName'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));

        $candidateLabels = self::resolveVariantBaseNameCandidateLabels($product, $attributes, $label);

        foreach ($explicitCandidates as $candidate) {
            if (self::isBaseNameConsistentWithName($name, $candidate, $candidateLabels)) {
                return $candidate;
            }
        }

        if ($label === '') {
            return $name;
        }

        $candidates = array_values(array_unique(array_filter([
            $label,
            preg_replace('/\s+/', '', $label) ?: '',
        ])));

        $base = $name;
        foreach ($candidates as $candidate) {
            $escaped = self::buildFlexibleSuffixPattern($candidate);
            if ($escaped === '') {
                continue;
            }
            $separator = self::requiresSeparatedSuffix($candidate)
                ? '(?:\s+|-)'
                : '(?:\s+|-)?';
            $base = preg_replace('/' . $separator . $escaped . '$/iu', '', $base) ?? $base;
            $base = self::cleanWhitespace($base);
        }

        return $base !== '' ? $base : $name;
    }

    private static function pluralizeSpanishColor(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        return preg_match('/[aeiouáéíóú]$/iu', $value) === 1 ? $value . 's' : $value;
    }

    private static function displayAxisValueAliases(string $axis, string $value): array
    {
        $value = self::cleanWhitespace($value);
        if ($value === '') {
            return [];
        }

        if ($axis !== 'color' || !str_contains($value, '/')) {
            return self::colorValueAliases($value);
        }

        $parts = array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            explode('/', $value)
        )));
        if (count($parts) < 2) {
            return [$value];
        }

        [$primary, $secondary] = [$parts[0], $parts[1]];
        $primaryAliases = self::colorValueAliases($primary);
        $secondaryAliases = self::colorValueAliases($secondary);
        $secondaryPluralAliases = array_map(
            static fn(string $alias): string => self::pluralizeSpanishColor($alias),
            $secondaryAliases
        );

        $aliases = [
            $value,
        ];

        foreach ($primaryAliases as $primaryAlias) {
            foreach ($secondaryAliases as $secondaryAlias) {
                $aliases[] = $primaryAlias . ' ' . $secondaryAlias;
                $aliases[] = $primaryAlias . ' con Detalles ' . $secondaryAlias;
                $aliases[] = $primaryAlias . ' con detalles ' . $secondaryAlias;
            }
            foreach ($secondaryPluralAliases as $secondaryPluralAlias) {
                $aliases[] = $primaryAlias . ' con Detalles ' . $secondaryPluralAlias;
                $aliases[] = $primaryAlias . ' con detalles ' . $secondaryPluralAlias;
            }
        }

        return array_values(array_unique($aliases));
    }

    private static function colorValueAliases(string $value): array
    {
        $value = self::cleanWhitespace($value);
        if ($value === '') {
            return [];
        }

        $normalized = mb_strtolower($value);
        $genderPairs = [
            'amarillo' => 'amarilla',
            'amarilla' => 'amarillo',
            'blanco' => 'blanca',
            'blanca' => 'blanco',
            'morado' => 'morada',
            'morada' => 'morado',
            'negro' => 'negra',
            'negra' => 'negro',
            'rosa' => 'rosado',
            'rosado' => 'rosa',
            'rosada' => 'rosa',
            'rojo' => 'roja',
            'roja' => 'rojo',
        ];

        $aliases = [$value];
        if (isset($genderPairs[$normalized])) {
            $aliases[] = $genderPairs[$normalized];
            $aliases[] = mb_convert_case($genderPairs[$normalized], MB_CASE_TITLE, 'UTF-8');
        }

        return array_values(array_unique($aliases));
    }

    private static function stripSuffixesFromName(string $name, array $labels): string
    {
        $base = self::cleanWhitespace($name);
        $labels = array_values(array_unique(array_filter(array_map(
            static fn(mixed $label): string => self::cleanWhitespace((string)$label),
            $labels
        ), static fn(string $label): bool => $label !== '')));
        usort($labels, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));

        for ($pass = 0; $pass < 4; $pass++) {
            $beforePass = $base;
            foreach ($labels as $label) {
                $escaped = self::buildFlexibleSuffixPattern($label);
                if ($escaped === '') {
                    continue;
                }
                $separator = self::requiresSeparatedSuffix($label)
                    ? '(?:\s+|-)'
                    : '(?:\s+|-)?';
                $base = preg_replace('/' . $separator . $escaped . '$/iu', '', $base) ?? $base;
                $base = self::cleanWhitespace($base);
            }
            if ($base === $beforePass) {
                break;
            }
        }

        return $base;
    }

    private static function resolveDisplayAxisBaseName(string $name, array $attributes, string $normalizedType): string
    {
        $displayAxis = trim((string)($attributes['displayAxis'] ?? $attributes['publicVariantAxis'] ?? $attributes['catalogDisplayAxis'] ?? ''));
        if ($displayAxis === '') {
            return '';
        }

        $variantField = self::resolveVariantDefinitionFieldByType($normalizedType, $attributes);
        $displayValue = $displayAxis === 'color'
            ? (string)($attributes['color'] ?? '')
            : (string)($attributes[$variantField !== '' ? $variantField : $displayAxis] ?? '');
        $color = self::cleanWhitespace((string)($attributes['color'] ?? ''));
        $keepColorInBase = in_array($normalizedType, ['ropa', 'accesorios'], true)
            && $displayAxis === 'size'
            && $color !== ''
            && self::isAccessorySizeVariantValue((string)($attributes['size'] ?? ''));
        $labels = array_merge(
            [
                $keepColorInBase ? '' : $color,
                (string)($attributes['size'] ?? ''),
                (string)($attributes['weight'] ?? ''),
                (string)($attributes['presentation'] ?? ''),
                (string)($attributes['packaging'] ?? ''),
                (string)($attributes['dosage'] ?? ''),
                (string)($attributes['volume'] ?? ''),
                (string)($attributes['range'] ?? ''),
            ],
            self::displayAxisValueAliases($displayAxis, $displayValue)
        );
        $base = self::stripSuffixesFromName($name, $labels);

        if ($keepColorInBase && $base !== '') {
            $baseIdentity = self::normalizeLabel($base);
            $hasColorInBase = false;
            foreach (self::displayAxisValueAliases('color', $color) as $colorAlias) {
                $aliasIdentity = self::normalizeLabel($colorAlias);
                if ($aliasIdentity !== '' && str_contains($baseIdentity, $aliasIdentity)) {
                    $hasColorInBase = true;
                    break;
                }
            }

            if ($hasColorInBase) {
                return $base;
            }

            return self::cleanWhitespace($base . ' ' . $color);
        }

        return $base !== '' && $base !== self::cleanWhitespace($name) ? $base : '';
    }

    private static function resolveVariantBaseNameCandidateLabels(array $product, array $attributes, string $label): array
    {
        $labels = [
            $label,
            (string)($product['variantLabel'] ?? ''),
        ];

        foreach (self::ATTRIBUTE_LABEL_KEYS as $key) {
            $labels[] = (string)($attributes[$key] ?? '');
        }

        $normalizedLabels = [];
        foreach ($labels as $candidate) {
            $normalized = self::normalizeLabel($candidate);
            if ($normalized !== '') {
                $normalizedLabels[$normalized] = $normalized;
            }
        }

        return array_values($normalizedLabels);
    }

    private static function labelMatchesEntireValue(string $value, string $label): bool
    {
        $value = self::cleanWhitespace($value);
        $label = self::normalizeLabel($label);
        if ($value === '' || $label === '') {
            return false;
        }

        $escaped = self::buildFlexibleSuffixPattern($label);
        if ($escaped === '') {
            return false;
        }

        return preg_match('/^' . $escaped . '$/iu', $value) === 1;
    }

    private static function isBaseNameConsistentWithName(string $name, string $baseName, array $labels): bool
    {
        $normalizedName = mb_strtolower(self::cleanWhitespace($name));
        $normalizedBase = mb_strtolower(self::cleanWhitespace($baseName));

        if ($normalizedName === '' || $normalizedBase === '') {
            return false;
        }

        if ($normalizedName === $normalizedBase) {
            return true;
        }

        if (str_starts_with($normalizedName, $normalizedBase)) {
            $suffix = trim((string)mb_substr($name, mb_strlen($baseName)));
            $suffix = preg_replace('/^(?:\s+|-)+/u', '', $suffix) ?? $suffix;
            $suffix = self::cleanWhitespace($suffix);

            foreach ($labels as $candidateLabel) {
                if (self::labelMatchesEntireValue($suffix, $candidateLabel)) {
                    return true;
                }
            }
        }

        if ($labels === []) {
            return false;
        }

        foreach ($labels as $candidateLabel) {
            $normalizedLabel = self::normalizeLabel($candidateLabel);
            if ($normalizedLabel === '') {
                continue;
            }

            $escaped = self::buildFlexibleSuffixPattern($normalizedLabel);
            if ($escaped === '') {
                continue;
            }

            $separator = self::requiresSeparatedSuffix($normalizedLabel)
                ? '(?:\s+|-)'
                : '(?:\s+|-)?';

            $derived = preg_replace('/' . $separator . $escaped . '$/iu', '', $name) ?? $name;
            $derived = mb_strtolower(self::cleanWhitespace($derived));

            if ($derived !== '' && $derived === $normalizedBase) {
                return true;
            }
        }

        return false;
    }

    public static function describeLabel(string $label): array
    {
        $normalized = self::normalizeLabel($label);
        if ($normalized === '') {
            return ['dimension' => 'unknown', 'value' => 0.0];
        }

        if (preg_match('/^(XXS|XS|S|M|L|XL|XXL|STANDARD)$/', $normalized, $named) === 1) {
            return [
                'dimension' => 'size',
                'value' => self::NAMED_SIZE_VALUES[$named[1]] ?? 0.0,
            ];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(?:KG|KGS?|K)-(\d+(?:\.\d+)?)(?:KG|KGS?|K)$/', $normalized, $rangeKg) === 1) {
            $midpoint = ((float)$rangeKg[1] + (float)$rangeKg[2]) / 2;
            return ['dimension' => 'weight', 'value' => $midpoint * 1000];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(?:GR|G|MG|ML|L|LB|OZ|KG|KGS?|K)$/', $normalized, $amount) === 1) {
            preg_match('/(KG|KGS?|K|GR|G|MG|ML|L|LB|OZ)$/', $normalized, $unitMatch);
            $unit = $unitMatch[1] ?? '';
            $value = (float)$amount[1];
            return self::convertUnitToDescriptor($value, $unit);
        }

        if (preg_match('/^(?:X)?(\d+(?:\.\d+)?)(?:X1)?(TABS?|DS|UN|UNI|PACK|PZA|PZ)$/', $normalized, $count) === 1) {
            return ['dimension' => 'count', 'value' => (float)$count[1]];
        }

        return ['dimension' => 'unknown', 'value' => 0.0];
    }

    public static function normalizeLabel(string $value): string
    {
        $normalized = mb_strtoupper(trim($value), 'UTF-8');
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/\s*-\s*/', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/(\d)\s+(KGS?|KG|K|GR|G|LB|L|ML|MG|OZ|TABS?|DS|UN|UNI|PACK|PZA|PZ)\b/u', '$1$2', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private static function isAccessorySizeVariantValue(string $value): bool
    {
        return preg_match('/^(?:XXS|XS|S|M|L|XL|XXL|STANDARD|\d+(?:[.,]\d+)?\s?CM|X?\d+)$/iu', trim($value)) === 1;
    }

    private static function normalizeCatalogDisplayMode(mixed $value): string
    {
        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['separate', 'individual', 'standalone', 'true', '1', 'yes', 'si', 'sí'], true)) {
            return 'separate';
        }
        if (in_array($normalized, ['grouped', 'group', 'false', '0', 'no'], true)) {
            return 'grouped';
        }
        return '';
    }

    private static function buildGroupKey(array $product, array $attributes, string $baseName): string
    {
        $normalizedType = self::normalizeProductType(
            (string)($product['productType'] ?? $product['product_type'] ?? ''),
            (string)($product['category'] ?? '')
        );
        $variantField = self::resolveVariantDefinitionFieldByType($normalizedType, $attributes);
        $familyAttributeKeys = array_values(array_filter(
            ['target', 'flavor', 'line'],
            static fn(string $key): bool => $key !== $variantField
        ));
        $parts = [
            trim((string)($product['brand'] ?? '')),
            trim((string)($product['category'] ?? '')),
            trim((string)($product['gender'] ?? '')),
            trim($baseName),
        ];
        foreach ($familyAttributeKeys as $key) {
            $parts[] = trim((string)($attributes[$key] ?? ''));
        }
        $parts[] = trim((string)($attributes['species'] ?? ''));
        $parts = array_filter($parts, static fn ($value) => $value !== '');

        return self::slugify(implode('|', $parts));
    }

    private static function slugify(string $value): string
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
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($ascii)) ?? '';
        return trim($slug, '-') ?: 'group';
    }

    private static function convertUnitToDescriptor(float $value, string $unit): array
    {
        return match ($unit) {
            'KG', 'KGS', 'K' => ['dimension' => 'weight', 'value' => $value * 1000],
            'LB' => ['dimension' => 'weight', 'value' => $value * 453.592],
            'L' => ['dimension' => 'volume', 'value' => $value * 1000],
            'GR', 'G' => ['dimension' => 'weight', 'value' => $value],
            'MG' => ['dimension' => 'count', 'value' => $value],
            'ML' => ['dimension' => 'volume', 'value' => $value],
            'OZ' => ['dimension' => 'weight', 'value' => $value * 28.3495],
            default => ['dimension' => 'unknown', 'value' => $value],
        };
    }

    private static function buildFlexibleSuffixPattern(string $label): string
    {
        $normalized = self::normalizeLabel($label);
        if ($normalized === '') {
            return '';
        }

        $parts = preg_split(
            '/(\d+(?:\.\d+)?(?:KGS?|KG|K|GR|G|LB|L|ML|MG|OZ|TABS?|DS|UN|UNI|PACK|PZA|PZ)\b)/u',
            $normalized,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        ) ?: [$normalized];

        $pattern = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^(\d+(?:\.\d+)?)(KGS?|KG|K|GR|G|LB|L|ML|MG|OZ|TABS?|DS|UN|UNI|PACK|PZA|PZ)\b/u', $part, $matches) === 1) {
                $pattern .= preg_quote((string)$matches[1], '/') . '\s*' . self::buildFlexibleUnitPattern((string)$matches[2]);
                continue;
            }

            $escaped = preg_quote($part, '/');
            $escaped = preg_replace('/\s+/', '\\s*', $escaped) ?? $escaped;
            $pattern .= str_replace('\-', '\s*-\s*', $escaped);
        }

        return $pattern;
    }

    private static function buildFlexibleUnitPattern(string $unit): string
    {
        return match (strtoupper($unit)) {
            'KG', 'KGS', 'K' => '(?:KGS?|KG|K)',
            'GR', 'G' => '(?:GR|G)',
            'ML' => '(?:MLS?|ML)',
            'TABS', 'TAB' => 'TABS?',
            'UN', 'UNI' => '(?:UN|UNI)',
            default => preg_quote(strtoupper($unit), '/'),
        };
    }

    private static function requiresSeparatedSuffix(string $label): bool
    {
        return preg_match('/^(XXS|XS|S|M|L|XL|XXL|STANDARD)$/', self::normalizeLabel($label)) === 1;
    }

    private static function isMeasurementLikeLabel(string $label): bool
    {
        $dimension = self::describeLabel($label)['dimension'] ?? 'unknown';
        return in_array($dimension, ['weight', 'volume'], true);
    }

    private static function isContentMeasurementValue(string $value): bool
    {
        return preg_match('/^\d+(?:[.,]\d+)?\s?(?:KGS?|KG|K|GR|G|LB|LBS?|L|ML|MG|OZ)$/iu', trim($value)) === 1;
    }

    private static function cleanWhitespace(string $value): string
    {
        return trim((string)(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
