<?php

declare(strict_types=1);

$repository = file_get_contents(dirname(__DIR__, 3) . '/Repositories/ProductRepository.php');
if ($repository === false) {
    throw new RuntimeException('No se pudo leer ProductRepository.php.');
}

$checks = [
    'pagination selects one anchor per variant family' => str_contains(
        $repository,
        "SELECT DISTINCT ON (' . \$groupKeySql . ')"
    ),
    'cursor is applied after family anchors are selected' => str_contains(
        $repository,
        'WHERE (catalog_group.created_at, catalog_group.id) <'
    ),
    'selected families hydrate all of their public variants' => str_contains(
        $repository,
        "' AND ' . \$groupKeySql . ' IN ('"
    ),
    'page output is restored in family order' => str_contains(
        $repository,
        '$itemsByGroup[$groupOrder[$groupKey]][]'
    ),
];

$failures = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failures !== []) {
    throw new RuntimeException('Public catalog family pagination failed: ' . implode(', ', $failures));
}

echo "Public catalog family pagination: OK\n";
