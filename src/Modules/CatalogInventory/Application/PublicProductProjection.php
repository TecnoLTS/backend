<?php

namespace App\Modules\CatalogInventory\Application;

final class PublicProductProjection
{
    private const PUBLIC_ATTRIBUTE_KEYS = [
        'age',
        'catalogCategories',
        'catalogDisplayMode',
        'catalogFamilyBaseName',
        'catalogFamilyKey',
        'color',
        'displayAxis',
        'flavor',
        'gender',
        'presentation',
        'range',
        'seoDescription',
        'seoImageAlt',
        'seoSearchTerms',
        'seoTitle',
        'size',
        'sku',
        'species',
        'tag',
        'target',
        'taxExempt',
        'taxRate',
        'variantAxis',
        'variantBaseName',
        'variantDefinitionField',
        'variantDisplayMode',
        'variantGroupKey',
        'variantLabel',
        'volume',
        'weight',
    ];

    private const PRIVATE_TOP_LEVEL_KEYS = [
        'business',
        'cost',
        'daysToExpire',
        'expirationAlertDays',
        'expirationDate',
        'expirationStatus',
        'lastPurchaseInvoice',
        // Internal SQL aggregate consumed by ProductRepository to build
        // `thumbImage`. Keeping it in the public DTO duplicates every
        // thumbnail URL as a JSON-encoded string.
        'thumbs',
    ];

    public static function one(array $product): array
    {
        foreach (self::PRIVATE_TOP_LEVEL_KEYS as $key) {
            unset($product[$key]);
        }

        $attributes = is_array($product['attributes'] ?? null) ? $product['attributes'] : [];
        $product['attributes'] = array_intersect_key(
            $attributes,
            array_fill_keys(self::PUBLIC_ATTRIBUTE_KEYS, true)
        );

        $inventory = is_array($product['inventory'] ?? null) ? $product['inventory'] : [];
        $product['inventory'] = [
            'onHand' => max(0, (int)($inventory['onHand'] ?? ($product['quantity'] ?? 0))),
            'available' => max(0, (int)($inventory['available'] ?? ($product['quantity'] ?? 0))),
            'status' => (string)($inventory['status'] ?? ($product['inventoryStatus'] ?? 'unknown')),
        ];

        return $product;
    }

    public static function many(array $products): array
    {
        return array_map([self::class, 'one'], $products);
    }
}
