<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\CatalogInventory\Application\PublicCatalogFilters;

$filters = PublicCatalogFilters::fromQuery([
    'q' => 'comida húmeda',
    'category' => 'Alimento para Gatos',
    'gender' => 'gatos',
    'brand_slug' => 'Purina Pro-Plan',
    'ids' => 'one,two,one',
    'sale_only' => 'true',
]);

if (($filters['category'] ?? '') !== 'alimento-para-gatos'
    || ($filters['brandSlug'] ?? '') !== 'purina-pro-plan'
    || ($filters['gender'] ?? '') !== 'cat'
    || ($filters['ids'] ?? []) !== ['one', 'two']
    || ($filters['saleOnly'] ?? false) !== true) {
    throw new RuntimeException('Public catalog filters were not normalized safely.');
}

foreach ([
    ['gender' => 'bird'],
    ['sale_only' => 'sometimes'],
    ['ids' => implode(',', range(1, 101))],
] as $invalidQuery) {
    try {
        PublicCatalogFilters::fromQuery($invalidQuery);
        throw new RuntimeException('Invalid public catalog filter was accepted.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

fwrite(STDOUT, "[public-catalog-filters] OK\n");
