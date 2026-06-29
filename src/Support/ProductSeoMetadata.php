<?php

namespace App\Support;

final class ProductSeoMetadata {
    public static function applyDefaults(array &$data, ?array $currentProduct = null): void {
        $attributes = self::effectiveAttributes($data, $currentProduct);
        $profile = self::buildProfile($data, $currentProduct, $attributes);

        $seoTitle = self::text($attributes['seoTitle'] ?? '');
        if (mb_strlen($seoTitle) < 20 || mb_strlen($seoTitle) > 70) {
            $attributes['seoTitle'] = $profile['title'];
        }

        $seoDescription = self::text($attributes['seoDescription'] ?? '');
        if (mb_strlen($seoDescription) < 70 || mb_strlen($seoDescription) > 160) {
            $attributes['seoDescription'] = $profile['description'];
        }

        if (mb_strlen(self::text($attributes['seoImageAlt'] ?? '')) < 20) {
            $attributes['seoImageAlt'] = $profile['imageAlt'];
        }

        if (self::text($attributes['seoSearchTerms'] ?? '') === '') {
            $attributes['seoSearchTerms'] = $profile['searchTerms'];
        }

        $data['attributes'] = $attributes;
        self::applyImageAltDefaults($data, (string)$attributes['seoImageAlt']);
    }

    public static function seoFieldGaps(array $data, ?array $currentProduct = null): array {
        $attributes = self::effectiveAttributes($data, $currentProduct);
        $titleLength = mb_strlen(self::text($attributes['seoTitle'] ?? ''));
        $descriptionLength = mb_strlen(self::text($attributes['seoDescription'] ?? ''));
        $missing = [];

        if ($titleLength < 20 || $titleLength > 70) {
            $missing[] = 'seoTitle';
        }
        if ($descriptionLength < 70 || $descriptionLength > 160) {
            $missing[] = 'seoDescription';
        }
        if (!self::hasEffectiveImageAlt($data, $currentProduct, $attributes)) {
            $missing[] = 'image_alt';
        }

        return $missing;
    }

    private static function buildProfile(array $data, ?array $currentProduct, array $attributes): array {
        $name = self::text(self::value($data, $currentProduct, 'name')) ?: 'Producto para mascotas';
        $brand = self::text(self::value($data, $currentProduct, 'brand'));
        $category = self::categoryLabel(self::text(self::value($data, $currentProduct, 'category')) ?: self::text(self::value($data, $currentProduct, 'productType')));
        $audience = self::audienceWord($attributes, self::text(self::value($data, $currentProduct, 'gender')));
        $price = (float)(self::value($data, $currentProduct, 'price') ?? 0);
        $quantity = (int)(self::value($data, $currentProduct, 'quantity') ?? 0);
        $sku = self::text($attributes['sku'] ?? '');
        $brandPrefix = $brand !== '' && !self::nameIncludesBrand($name, $brand) ? "{$brand} " : '';
        $brandText = $brand !== '' && !self::nameIncludesBrand($name, $brand) ? " marca {$brand}" : '';
        $stockText = $quantity > 0 ? 'con stock publicado' : 'según disponibilidad';
        $skuText = $sku !== '' ? " SKU {$sku}." : '';

        $titleBase = self::ensureMinimum("{$brandPrefix}{$name} para {$audience}", 20, 'Disponible en Ecuador');
        $titleWithPrice = $price > 0 ? "{$titleBase} desde USD " . number_format($price, 2, '.', '') : $titleBase;
        $title = self::limit($titleWithPrice, 70);
        if (mb_strlen($title) < 20 || mb_strlen($titleWithPrice) > 70) {
            $title = self::limit($titleBase, 70);
        }

        $description = self::ensureMinimum(
            "Compra {$name}{$brandText} en ParaMascotasEC. Producto de " . mb_strtolower($category) . " para {$audience} en Ecuador, {$stockText}.{$skuText}",
            70,
            'Compra online con información actualizada para clientes de Ecuador.'
        );
        $description = self::limit($description, 160);

        $imageAlt = self::limit(trim("{$name}" . ($brandText !== '' ? " {$brand}" : '') . " para {$audience} en ParaMascotasEC"), 125);
        $searchTerms = implode(', ', self::uniqueText([
            $name,
            $brand,
            $category,
            self::text(self::value($data, $currentProduct, 'productType')),
            self::text($attributes['species'] ?? ''),
            $audience,
            $sku,
            'mascotas Ecuador',
        ]));

        return [
            'title' => $title,
            'description' => $description,
            'imageAlt' => self::ensureMinimum($imageAlt, 20, 'Producto para mascotas en Ecuador'),
            'searchTerms' => $searchTerms,
        ];
    }

    private static function applyImageAltDefaults(array &$data, string $altText): void {
        if (self::text($altText) === '') {
            return;
        }

        foreach (['images', 'galleryImages', 'image', 'thumbImages', 'thumbImage'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $data[$key] = self::withImageAlt($data[$key], $altText);
        }
    }

    private static function withImageAlt($value, string $altText) {
        if (is_string($value)) {
            $url = trim($value);
            return $url === '' ? [] : [['url' => $url, 'altText' => $altText]];
        }
        if (!is_array($value)) {
            return $value;
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $url = trim($item);
                if ($url !== '') {
                    $items[] = ['url' => $url, 'altText' => $altText];
                }
                continue;
            }
            if (is_array($item)) {
                $url = self::text($item['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                if (self::text($item['altText'] ?? ($item['alt_text'] ?? '')) === '') {
                    $item['altText'] = $altText;
                }
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function hasEffectiveImageAlt(array $data, ?array $currentProduct, array $attributes): bool {
        if (mb_strlen(self::text($attributes['seoImageAlt'] ?? '')) >= 20) {
            return true;
        }

        foreach (['images', 'galleryImages', 'image', 'thumbImages', 'thumbImage'] as $key) {
            if (array_key_exists($key, $data) && self::imageInputHasAlt($data[$key])) {
                return true;
            }
        }

        foreach (($currentProduct['imageMeta'] ?? []) as $image) {
            if (is_array($image) && mb_strlen(self::text($image['altText'] ?? ($image['alt_text'] ?? ''))) >= 20) {
                return true;
            }
        }

        return false;
    }

    private static function imageInputHasAlt($value): bool {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (is_array($item) && mb_strlen(self::text($item['altText'] ?? ($item['alt_text'] ?? ''))) >= 20) {
                return true;
            }
        }
        return false;
    }

    private static function effectiveAttributes(array $data, ?array $currentProduct): array {
        $current = self::decodeAttributes($currentProduct['attributes'] ?? []);
        $incoming = self::decodeAttributes($data['attributes'] ?? []);
        if (array_key_exists('attributes', $data) && self::isFullProductMutation($data)) {
            return $incoming;
        }
        return array_replace($current, $incoming);
    }

    private static function isFullProductMutation(array $data): bool {
        foreach (['name', 'category', 'productType', 'product_type', 'brand', 'description', 'price', 'originPrice', 'cost', 'quantity'] as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
    }

    private static function decodeAttributes($value): array {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function value(array $data, ?array $currentProduct, string $key) {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        if ($key === 'productType' && array_key_exists('product_type', $data)) {
            return $data['product_type'];
        }
        return $currentProduct[$key] ?? ($key === 'productType' ? ($currentProduct['product_type'] ?? null) : null);
    }

    private static function audienceWord(array $attributes, string $gender): string {
        $species = mb_strtolower(self::text($attributes['species'] ?? ''));
        $gender = mb_strtolower($gender);
        if (str_contains($species, 'gato') || $gender === 'cat') {
            return 'gatos';
        }
        if (str_contains($species, 'perro') || $gender === 'dog') {
            return 'perros';
        }
        return 'mascotas';
    }

    private static function categoryLabel(string $category): string {
        $labels = [
            'alimento' => 'Alimento',
            'ropa' => 'Ropa',
            'cuidado' => 'Salud y cuidado',
            'salud' => 'Salud y cuidado',
            'accesorios' => 'Accesorios',
        ];
        $key = mb_strtolower($category);
        return $labels[$key] ?? ($category !== '' ? $category : 'productos para mascotas');
    }

    private static function nameIncludesBrand(string $name, string $brand): bool {
        $normalizedName = self::slug($name);
        $normalizedBrand = self::slug($brand);
        return $normalizedBrand !== '' && ($normalizedName === $normalizedBrand || str_starts_with($normalizedName, "{$normalizedBrand}-"));
    }

    private static function uniqueText(array $values): array {
        $seen = [];
        $items = [];
        foreach ($values as $value) {
            $text = self::text($value);
            $key = mb_strtolower($text);
            if ($text !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $items[] = $text;
            }
        }
        return $items;
    }

    private static function ensureMinimum(string $value, int $minimum, string $suffix): string {
        $text = self::text($value);
        if (mb_strlen($text) >= $minimum) {
            return $text;
        }
        return self::text("{$text}. {$suffix}");
    }

    private static function limit(string $value, int $maxLength): string {
        $text = self::text($value);
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        $trimmed = mb_substr($text, 0, $maxLength + 1);
        $lastSpace = mb_strrpos($trimmed, ' ');
        $candidate = $lastSpace !== false && $lastSpace > 40 ? mb_substr($trimmed, 0, $lastSpace) : mb_substr($text, 0, $maxLength);
        return rtrim(self::text($candidate), " ,.;:-");
    }

    private static function slug(string $value): string {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        return trim($normalized, '-');
    }

    private static function text($value): string {
        return trim(preg_replace('/\s+/', ' ', (string)$value) ?? '');
    }
}
