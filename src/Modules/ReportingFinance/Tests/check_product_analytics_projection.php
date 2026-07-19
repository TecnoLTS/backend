<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$service = file_get_contents($root . '/src/Services/BusinessIntelligenceService.php');
$repository = file_get_contents($root . '/src/Repositories/ProductRepository.php');

$checks = [
    'BI does not hydrate full catalog' => is_string($service)
        && !str_contains($service, '->getAll()')
        && str_contains($service, 'getProductAnalyticsAggregate'),
    'analytics is aggregated in SQL' => is_string($repository)
        && str_contains($repository, 'function getProductAnalyticsAggregate')
        && str_contains($repository, 'AVG(')
        && str_contains($repository, 'SUM(')
        && str_contains($repository, 'tenant_id = :tenant_id'),
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Product analytics projection failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Product analytics SQL projection: OK\n";
