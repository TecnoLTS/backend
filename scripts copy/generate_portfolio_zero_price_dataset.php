<?php

declare(strict_types=1);

$outputPath = $argv[1] ?? (__DIR__ . '/../storage/imports/agripac_portafolio_2025_07_zero_prices.json');

/**
 * @param array<int, array<string, mixed>> $items
 * @param array<int, string> $usedLegacyIds
 */
function addFamily(array &$items, array &$usedLegacyIds, array $family): void
{
    $variants = $family['variants'] ?? [['label' => '']];
    foreach ($variants as $variant) {
        $label = trim((string)($variant['label'] ?? ''));
        $name = trim($family['name'] . ($label !== '' ? ' ' . $label : ''));
        $slug = slugify($name);
        $legacyId = uniqueLegacyId($usedLegacyIds, 'PORT-' . strtoupper($slug));
        $species = (string)($family['species'] ?? 'general');
        $category = (string)($family['category'] ?? 'cuidado');
        $size = trim((string)($variant['size'] ?? $label));
        $description = sprintf(
            'Producto cargado desde portafolio Agripac (julio de 2025) sin precio visible en el material fuente. Precio, costo y stock inicial establecidos en 0 para correccion manual. Nombre de portafolio: %s.',
            $name
        );

        $items[] = [
            'legacyId' => $legacyId,
            'name' => $name,
            'brand' => (string)$family['brand'],
            'category' => $category,
            'productType' => (string)($family['productType'] ?? 'accesorios'),
            'gender' => $species === 'general' ? '' : $species,
            'price' => 0,
            'originPrice' => 0,
            'cost' => 0,
            'quantity' => 0,
            'description' => $description,
            'sale' => false,
            'new' => false,
            'slug' => $slug,
            'thumbImages' => [placeholderImage($species, $category)],
            'galleryImages' => [placeholderImage($species, $category)],
            'attributes' => array_filter([
                'sku' => $legacyId,
                'tag' => $slug,
                'species' => $species,
                'supplier' => 'Agripac',
                'sourceCatalog' => 'PPT_Portafolio_Consumo_Julio_2025',
                'sourceCatalogDate' => '2025-07',
                'importNotes' => 'Sin precio visible en el portafolio; cargado en 0 para correccion manual.',
                'priceMode' => 'missing_in_source',
                'size' => $size !== '' ? $size : null,
                'target' => $family['target'] ?? null,
                'flavor' => $family['flavor'] ?? null,
                'presentation' => $variant['presentation'] ?? null,
                'range' => $variant['range'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'sourcePage' => (int)($family['page'] ?? 0),
            'rawPrices' => [],
        ];
    }
}

/**
 * @param array<int, string> $usedLegacyIds
 */
function uniqueLegacyId(array &$usedLegacyIds, string $base): string
{
    $candidate = preg_replace('/-+/', '-', trim($base, '-')) ?: 'PORT-ITEM';
    $suffix = 2;
    while (in_array($candidate, $usedLegacyIds, true)) {
        $candidate = $base . '-' . $suffix;
        $suffix++;
    }
    $usedLegacyIds[] = $candidate;
    return $candidate;
}

function slugify(string $value): string
{
    $map = [
        'a' => ['a', 'A', 'á', 'Á', 'à', 'À', 'ä', 'Ä', 'â', 'Â'],
        'e' => ['e', 'E', 'é', 'É', 'è', 'È', 'ë', 'Ë', 'ê', 'Ê'],
        'i' => ['i', 'I', 'í', 'Í', 'ì', 'Ì', 'ï', 'Ï', 'î', 'Î'],
        'o' => ['o', 'O', 'ó', 'Ó', 'ò', 'Ò', 'ö', 'Ö', 'ô', 'Ô'],
        'u' => ['u', 'U', 'ú', 'Ú', 'ù', 'Ù', 'ü', 'Ü', 'û', 'Û'],
        'n' => ['n', 'N', 'ñ', 'Ñ'],
    ];
    $ascii = $value;
    foreach ($map as $replacement => $chars) {
        $ascii = str_replace($chars, $replacement, $ascii);
    }
    $ascii = preg_replace('/[^a-z0-9]+/i', '-', strtolower($ascii)) ?? '';
    return trim($ascii, '-') ?: 'item';
}

function placeholderImage(string $species, string $category): string
{
    if ($category === 'cuidado') {
        return '/images/product/3.jpg';
    }
    return $species === 'cat' ? '/images/product/2.jpg' : '/images/product/1.jpg';
}

$items = [];
$usedLegacyIds = [];

$families = [
    ['brand' => 'ALCON', 'name' => 'ALCON Adultos Pollo', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos', 'flavor' => 'pollo', 'page' => 3, 'variants' => [['label' => '2KG'], ['label' => '30KG']]],
    ['brand' => 'ALCON', 'name' => 'ALCON Cachorros Pollo', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'cachorros', 'flavor' => 'pollo', 'page' => 3, 'variants' => [['label' => '2KG'], ['label' => '30KG']]],
    ['brand' => 'BALANCAN', 'name' => 'BALANCAN Adultos Pollo', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos', 'flavor' => 'pollo', 'page' => 3, 'variants' => [['label' => '30KG']]],

    ['brand' => 'BUEN CAN', 'name' => 'BUEN CAN Adultos Razas Medianas y Grandes', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-rmg', 'page' => 4, 'variants' => [['label' => '450GR'], ['label' => '2KG'], ['label' => '4KG'], ['label' => '8KG'], ['label' => '15KG'], ['label' => '30KG']]],
    ['brand' => 'BUEN CAN', 'name' => 'BUEN CAN Adultos Razas Medianas y Pequenas', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-rmp', 'page' => 4, 'variants' => [['label' => '2KG'], ['label' => '4KG'], ['label' => '8KG']]],
    ['brand' => 'BUEN CAN', 'name' => 'BUEN CAN Cachorros Razas Medianas y Grandes', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'cachorros-rmg', 'page' => 4, 'variants' => [['label' => '450GR'], ['label' => '2KG'], ['label' => '4KG'], ['label' => '15KG'], ['label' => '30KG']]],
    ['brand' => 'BUEN CAN', 'name' => 'BUEN CAN Cachorros Razas Pequenas y Medianas', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'cachorros-rpm', 'page' => 4, 'variants' => [['label' => '450GR'], ['label' => '2KG'], ['label' => '4KG'], ['label' => '15KG']]],

    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Adulto Razas Pequenas y Medianas', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-rpm', 'page' => 5, 'variants' => [['label' => '2KG'], ['label' => '4KG'], ['label' => '7.5KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Cachorros Razas Medianas y Grandes', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'cachorros-rmg', 'page' => 5, 'variants' => [['label' => '2KG'], ['label' => '4KG'], ['label' => '7.5KG'], ['label' => '20KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Cachorros Razas Pequenas y Medianas', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'cachorros-rpm', 'page' => 5, 'variants' => [['label' => '2KG'], ['label' => '4KG'], ['label' => '7.5KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Adulto Razas Medianas y Grandes', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-rmg', 'page' => 5, 'variants' => [['label' => '2KG'], ['label' => '4KG'], ['label' => '7.5KG'], ['label' => '15KG'], ['label' => '20KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Adulto Light', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-light', 'page' => 6, 'variants' => [['label' => '2KG'], ['label' => '7.5KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Adulto Senior', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-senior', 'page' => 6, 'variants' => [['label' => '2KG'], ['label' => '7.5KG']]],

    ['brand' => 'RAZZA', 'name' => 'RAZZA Cachorro Razas Medianas y Grandes', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'cachorros-rmg', 'page' => 7, 'variants' => [['label' => '1.8KG'], ['label' => '18KG']]],
    ['brand' => 'RAZZA', 'name' => 'RAZZA Cachorros Razas Pequenas y Miniaturas', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'cachorros-rpm', 'page' => 7, 'variants' => [['label' => '1.8KG']]],
    ['brand' => 'RAZZA', 'name' => 'RAZZA Adulto Razas Pequenas y Miniaturas', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-rpm', 'page' => 7, 'variants' => [['label' => '1.8KG'], ['label' => '7.5KG']]],
    ['brand' => 'RAZZA', 'name' => 'RAZZA Adulto Razas Medianas y Grandes', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-rmg', 'page' => 7, 'variants' => [['label' => '1.8KG'], ['label' => '7.5KG'], ['label' => '18KG']]],

    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Cachorros Razas Medianas y Grandes', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'cachorros-rmg', 'page' => 8, 'variants' => [['label' => '2.5KG'], ['label' => '15KG']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Cachorros Razas Pequenas y Miniaturas', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'cachorros-rpm', 'page' => 8, 'variants' => [['label' => '2.5KG'], ['label' => '7.5KG']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Cordero Adultos Razas Medianas y Grandes', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-rmg', 'flavor' => 'cordero', 'page' => 8, 'variants' => [['label' => '2.5KG'], ['label' => '15KG']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Adultos Razas Medianas y Grandes', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-rmg', 'page' => 9, 'variants' => [['label' => '2.5KG'], ['label' => '15KG']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Adultos Razas Pequenas y Miniaturas', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'adultos-rpm', 'page' => 9, 'variants' => [['label' => '2.5KG'], ['label' => '7.5KG']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Weight Control', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'weight-control', 'page' => 9, 'variants' => [['label' => '2.5KG'], ['label' => '7.5KG']]],

    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Latas Pollo y Arroz', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'humedo', 'flavor' => 'pollo y arroz', 'page' => 10, 'variants' => [['label' => '170GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Pate Cachorros Pollo', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'humedo', 'flavor' => 'pollo', 'page' => 10, 'variants' => [['label' => '85GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Pate Pollo Carne e Higado', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 10, 'variants' => [['label' => '85GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Latas Carne y Vegetales', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'humedo', 'flavor' => 'carne y vegetales', 'page' => 10, 'variants' => [['label' => '170GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Latas Pollo y Vegetales', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'humedo', 'flavor' => 'pollo y vegetales', 'page' => 10, 'variants' => [['label' => '170GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Pate Cordero y Vegetales', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 10, 'variants' => [['label' => '85GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Pate Cerdo y Salmon', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 10, 'variants' => [['label' => '85GR']]],

    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Snacks Pollo', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'flavor' => 'pollo', 'page' => 11, 'variants' => [['label' => '200GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Snacks Mantequilla Mani', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'flavor' => 'mantequilla mani', 'page' => 11, 'variants' => [['label' => '200GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Snacks Carne', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'flavor' => 'carne', 'page' => 11, 'variants' => [['label' => '200GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Delibites Junior RPM Vainilla', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 12, 'variants' => [['label' => '200GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Delibites Combo RMG Pollo', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'flavor' => 'pollo', 'page' => 12, 'variants' => [['label' => '200GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Snack Dental Razas Pequenas y Medianas', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks-dental', 'page' => 13, 'variants' => [['label' => '200GR']]],

    ['brand' => 'SMARTBONES', 'name' => 'SMARTBONES Dental Pollo Palitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 14, 'variants' => [['label' => 'X5', 'presentation' => '5 unidades']]],
    ['brand' => 'SMARTBONES', 'name' => 'SMARTBONES Mantequilla Mani Palitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 14, 'variants' => [['label' => 'X5', 'presentation' => '5 unidades']]],
    ['brand' => 'SMARTBONES', 'name' => 'SMARTBONES Mantequilla Mani Huesitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 14, 'variants' => [['label' => 'X8', 'presentation' => '8 unidades']]],
    ['brand' => 'SMARTBONES', 'name' => 'SMARTBONES Dental Pollo Huesitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 14, 'variants' => [['label' => 'X8', 'presentation' => '8 unidades']]],
    ['brand' => 'SMARTBONES', 'name' => 'SMARTBONES Camote Palitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 14, 'variants' => [['label' => 'X5', 'presentation' => '5 unidades']]],
    ['brand' => 'SMARTBONES', 'name' => 'SMARTBONES Pollo Palitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 14, 'variants' => [['label' => 'X5', 'presentation' => '5 unidades']]],
    ['brand' => 'SMARTBONES', 'name' => 'SMARTBONES Pollo Huesitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 14, 'variants' => [['label' => 'X8', 'presentation' => '8 unidades']]],
    ['brand' => 'SMARTBONES', 'name' => 'SMARTBONES Camote Huesitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 14, 'variants' => [['label' => 'X8', 'presentation' => '8 unidades']]],

    ['brand' => 'DREAMBONES', 'name' => 'DREAMBONES Pollo Huesitos Mini', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 15, 'variants' => [['label' => 'X8', 'presentation' => '8 unidades']]],
    ['brand' => 'DREAMBONES', 'name' => 'DREAMBONES Dental Palitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 15, 'variants' => [['label' => 'X5', 'presentation' => '5 unidades']]],
    ['brand' => 'DREAMBONES', 'name' => 'DREAMBONES Pollo Palitos', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 15, 'variants' => [['label' => 'X2', 'presentation' => '2 unidades'], ['label' => 'X5', 'presentation' => '5 unidades']]],
    ['brand' => 'DREAMBONES', 'name' => 'DREAMBONES Pollo Hueso Mediano', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 15, 'variants' => [['label' => 'X1', 'presentation' => '1 unidad']]],
    ['brand' => 'DREAMBONES', 'name' => 'DREAMBONES Dental Huesos Mini', 'species' => 'dog', 'category' => 'Alimento para perros', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 16, 'variants' => [['label' => 'X8', 'presentation' => '8 unidades']]],

    ['brand' => 'BALANCAT', 'name' => 'BALANCAT Pollo', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'adultos', 'flavor' => 'pollo', 'page' => 17, 'variants' => [['label' => '450GR'], ['label' => '18KG']]],
    ['brand' => 'MICHU', 'name' => 'MICHU Pollo', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'adultos', 'flavor' => 'pollo', 'page' => 18, 'variants' => [['label' => '450GR'], ['label' => '2KG'], ['label' => '18KG']]],
    ['brand' => 'MICHU', 'name' => 'MICHU Carne', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'adultos', 'flavor' => 'carne', 'page' => 18, 'variants' => [['label' => '450GR'], ['label' => '2KG']]],
    ['brand' => 'MICHU', 'name' => 'MICHU Delicias del Mar', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'adultos', 'flavor' => 'delicias del mar', 'page' => 18, 'variants' => [['label' => '450GR'], ['label' => '2KG'], ['label' => '18KG']]],

    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Nuggets Adultos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'adultos', 'page' => 19, 'variants' => [['label' => '500GR'], ['label' => '2KG'], ['label' => '7.5KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Esterilizados Pollo', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'esterilizados', 'flavor' => 'pollo', 'page' => 19, 'variants' => [['label' => '500GR'], ['label' => '2KG'], ['label' => '7.5KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Adultos Pescado', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'adultos', 'flavor' => 'pescado', 'page' => 19, 'variants' => [['label' => '500GR'], ['label' => '2KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Gatitos Pollo', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'gatitos', 'flavor' => 'pollo', 'page' => 19, 'variants' => [['label' => '500GR'], ['label' => '2KG'], ['label' => '7.5KG'], ['label' => '18KG']]],

    ['brand' => 'RAZZA', 'name' => 'RAZZA Adultos Control de Peso', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'weight-control', 'page' => 20, 'variants' => [['label' => '1.8KG']]],
    ['brand' => 'RAZZA', 'name' => 'RAZZA Adultos y Gatitos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'adultos-y-gatitos', 'page' => 20, 'variants' => [['label' => '1.8KG']]],

    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Esterilizados y Control de Peso', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'esterilizados-weight-control', 'page' => 21, 'variants' => [['label' => '1.5KG']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Adultos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'adultos', 'page' => 21, 'variants' => [['label' => '2KG']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Urinary', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'urinary', 'page' => 21, 'variants' => [['label' => '2KG']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Gatitos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'gatitos', 'page' => 21, 'variants' => [['label' => '2KG']]],

    ['brand' => 'MICHU', 'name' => 'MICHU Latas Festival Marino', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'humedo', 'flavor' => 'festival marino', 'page' => 22, 'variants' => [['label' => '85GR']]],
    ['brand' => 'MICHU', 'name' => 'MICHU Latas Pollo y Atun', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 22, 'variants' => [['label' => '85GR']]],

    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Latas Atun y Tilapia', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 23, 'variants' => [['label' => '80GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Latas Pollo e Higado', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 23, 'variants' => [['label' => '80GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Latas Carne e Higado', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 23, 'variants' => [['label' => '80GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Pate Salmon', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 23, 'variants' => [['label' => '85GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Pate Atun y Sardina', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 23, 'variants' => [['label' => '85GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Pate Pollo y Atun', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 23, 'variants' => [['label' => '85GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Pate Pollo y Arandanos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'humedo', 'page' => 23, 'variants' => [['label' => '85GR']]],

    ['brand' => 'DELIBITES', 'name' => 'DELIBITES Sabores del Mar', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 24, 'variants' => [['label' => '100GR']]],
    ['brand' => 'DELIBITES', 'name' => 'DELIBITES Carne e Higado', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 24, 'variants' => [['label' => '100GR']]],
    ['brand' => 'DELIBITES', 'name' => 'DELIBITES Pollo e Higado', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 24, 'variants' => [['label' => '100GR']]],
    ['brand' => 'DELIBITES', 'name' => 'DELIBITES Hairball Pollo', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 24, 'variants' => [['label' => '100GR']]],
    ['brand' => 'DELIBITES', 'name' => 'DELIBITES Aroma Catnip', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks', 'page' => 24, 'variants' => [['label' => '100GR']]],

    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Cat Chup Atun Salmon y Prebioticos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks-liquidos', 'page' => 25, 'variants' => [['label' => '56GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Cat Chup Pollo y Arandanos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks-liquidos', 'page' => 25, 'variants' => [['label' => '56GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Cat Chup Pollo y Prebioticos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks-liquidos', 'page' => 25, 'variants' => [['label' => '56GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Cat Chup Atun Cangrejo y Prebioticos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks-liquidos', 'page' => 26, 'variants' => [['label' => '56GR']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Cat Chup Jar Pollo y Prebioticos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks-liquidos', 'page' => 26, 'variants' => [['label' => '700GR', 'presentation' => '50 unidades']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Cat Chup Jar Atun Salmon y Prebioticos', 'species' => 'cat', 'category' => 'Alimento para gatos', 'productType' => 'Alimento', 'target' => 'snacks-liquidos', 'page' => 26, 'variants' => [['label' => '700GR', 'presentation' => '50 unidades']]],

    ['brand' => 'FRONTLINE', 'name' => 'FRONTLINE Spray', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 28, 'variants' => [['label' => '100ML'], ['label' => '250ML']]],
    ['brand' => 'FRONTLINE', 'name' => 'FRONTLINE Pipetas Gatos', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 29, 'variants' => [['label' => 'STANDARD']]],
    ['brand' => 'FRONTLINE', 'name' => 'FRONTLINE Pipetas Perros', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 30, 'variants' => [['label' => 'S'], ['label' => 'M'], ['label' => 'L'], ['label' => 'XL']]],

    ['brand' => 'NEXGARD', 'name' => 'NEXGARD Unidosis', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 31, 'variants' => [['label' => 'XS'], ['label' => 'S'], ['label' => 'M'], ['label' => 'XL']]],
    ['brand' => 'NEXGARD', 'name' => 'NEXGARD Spectra', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 32, 'variants' => [['label' => 'XS'], ['label' => 'S'], ['label' => 'M'], ['label' => 'XL']]],
    ['brand' => 'NEXGARD', 'name' => 'NEXGARD Tripack', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 33, 'variants' => [['label' => '2KG-4KG', 'range' => '2kg-4kg'], ['label' => '4.1KG-10KG', 'range' => '4.1kg-10kg'], ['label' => '10.1KG-25KG', 'range' => '10.1kg-25kg'], ['label' => '25.1KG-50KG', 'range' => '25.1kg-50kg']]],
    ['brand' => 'NEXGARD', 'name' => 'NEXGARD Combo Gatos 0.3ML', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 34, 'variants' => [['label' => '2.5KG', 'range' => 'hasta 2.5kg']]],
    ['brand' => 'NEXGARD', 'name' => 'NEXGARD Combo Gatos 0.9ML', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 34, 'variants' => [['label' => '2.5KG-7.5KG', 'range' => '2.5kg-7.5kg']]],

    ['brand' => 'RECOMBITEK', 'name' => 'RECOMBITEK C4', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 35, 'variants' => [['label' => 'X25 DOSIS', 'presentation' => '25 dosis']]],
    ['brand' => 'RECOMBITEK', 'name' => 'RECOMBITEK C6', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 36, 'variants' => [['label' => 'X25 DOSIS', 'presentation' => '25 dosis']]],
    ['brand' => 'RECOMBITEK', 'name' => 'RECOMBITEK C8', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 37, 'variants' => [['label' => 'X25 DOSIS', 'presentation' => '25 dosis']]],
    ['brand' => 'RABISIN', 'name' => 'RABISIN', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 38, 'variants' => [['label' => '100 UNIDOSIS', 'presentation' => '100 unidosis']]],
    ['brand' => 'PREVICOX', 'name' => 'PREVICOX', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 39, 'variants' => [['label' => '57MG'], ['label' => '227MG']]],
    ['brand' => 'VETMEDIN', 'name' => 'VETMEDIN', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 40, 'variants' => [['label' => '1.25MG X50 COMPRIMIDOS'], ['label' => '5MG X50 COMPRIMIDOS']]],

    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Multi Vitamin Adultos', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 42, 'variants' => [['label' => '180GR']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Skin and Coat', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 42, 'variants' => [['label' => '180GR']]],
    ['brand' => 'WELLNESS', 'name' => 'WELLNESS Artro Flex Adultos', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 42, 'variants' => [['label' => '180GR']]],

    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Gatos Pelo Corto', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 43, 'variants' => [['label' => 'S'], ['label' => 'L']]],
    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Gatos Pelo Largo', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 43, 'variants' => [['label' => 'S'], ['label' => 'L']]],
    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Perros Pelo Corto', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 43, 'variants' => [['label' => 'S'], ['label' => 'L']]],
    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Perros Pelo Largo', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 43, 'variants' => [['label' => 'S'], ['label' => 'L']]],
    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Recogedor de Pelo', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 43, 'variants' => [['label' => 'S'], ['label' => 'L']]],
    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Cepillo', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 44, 'variants' => [['label' => '']]],
    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Rake', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 44, 'variants' => [['label' => '']]],
    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Saca Nudos', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 44, 'variants' => [['label' => '']]],
    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Cepillo Redondo', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 44, 'variants' => [['label' => '']]],
    ['brand' => 'FURMINATOR', 'name' => 'FURMINATOR Peine Masajeador Perro Gato', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 44, 'variants' => [['label' => '']]],

    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Cristales', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 45, 'variants' => [['label' => '4.7KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Arena Lavanda', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 45, 'variants' => [['label' => '1KG'], ['label' => '4KG'], ['label' => '10KG'], ['label' => '18KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Arena Manzana', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 45, 'variants' => [['label' => '4KG'], ['label' => '18KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Arena Active Carbon Activado', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 45, 'variants' => [['label' => '4KG'], ['label' => '18KG']]],
    ['brand' => 'NUTRAPRO', 'name' => 'NUTRAPRO Medicheck', 'species' => 'cat', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 45, 'variants' => [['label' => '2.7KG']]],
    ['brand' => 'AMONEX', 'name' => 'AMONEX Neutralizador de Olores Spray', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 46, 'variants' => [['label' => '490ML']]],

    ['brand' => 'PET CARE', 'name' => 'PET CARE Con Clorhexidina 4%', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 47, 'variants' => [['label' => '200ML'], ['label' => '500ML']]],
    ['brand' => 'PET CARE', 'name' => 'PET CARE Con Miconazol', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 47, 'variants' => [['label' => '200ML'], ['label' => '500ML']]],
    ['brand' => 'PET CARE', 'name' => 'PET CARE Fibropinil', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 47, 'variants' => [['label' => '200ML'], ['label' => '1GL']]],
    ['brand' => 'PET CARE', 'name' => 'PET CARE Clorhexidina Spray', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 47, 'variants' => [['label' => '120ML']]],
    ['brand' => 'PET CARE', 'name' => 'PET CARE Cachorros', 'species' => 'dog', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 48, 'variants' => [['label' => '200ML']]],
    ['brand' => 'PET CARE', 'name' => 'PET CARE Con Clorhexidina', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 48, 'variants' => [['label' => '200ML']]],
    ['brand' => 'PET CARE', 'name' => 'PET CARE 2 en 1', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 48, 'variants' => [['label' => '200ML'], ['label' => '1GL']]],
    ['brand' => 'PET CARE', 'name' => 'PET CARE Colonia', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 48, 'variants' => [['label' => '240ML']]],
    ['brand' => 'PET CARE', 'name' => 'PET CARE Bano en Seco', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 48, 'variants' => [['label' => '200ML']]],

    ['brand' => 'KLERAT', 'name' => 'KLERAT Bloque', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 50, 'variants' => [['label' => '50GR']]],
    ['brand' => 'KLERAT', 'name' => 'KLERAT Gel Hormigas', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 50, 'variants' => [['label' => '10GR']]],
    ['brand' => 'KLERAT', 'name' => 'KLERAT Pellets', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 50, 'variants' => [['label' => '50GR']]],
    ['brand' => 'ZAP', 'name' => 'ZAP Mata Moscas y Mosquitos', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 51, 'variants' => [['label' => '360ML']]],
    ['brand' => 'ZAP', 'name' => 'ZAP Mata Mosquitos y Cucarachas', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 51, 'variants' => [['label' => '360ML']]],
    ['brand' => 'ZAP', 'name' => 'ZAP Mata Cucarachas y Hormigas', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 51, 'variants' => [['label' => '360ML']]],
    ['brand' => 'DRAGON', 'name' => 'DRAGON Insecticida Liquido', 'species' => 'general', 'category' => 'cuidado', 'productType' => 'accesorios', 'page' => 52, 'variants' => [['label' => '230ML'], ['label' => '475ML'], ['label' => '950ML']]],
];

foreach ($families as $family) {
    addFamily($items, $usedLegacyIds, $family);
}

$json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "No se pudo serializar el dataset.\n");
    exit(1);
}

$directory = dirname($outputPath);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "No se pudo crear el directorio de salida: {$directory}\n");
    exit(1);
}

if (file_put_contents($outputPath, $json . PHP_EOL) === false) {
    fwrite(STDERR, "No se pudo escribir el archivo: {$outputPath}\n");
    exit(1);
}

echo "Archivo generado: {$outputPath}\n";
echo "Productos generados: " . count($items) . "\n";
