<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Http\Pagination\BoundedPageRequest;
use App\Http\Pagination\CreatedAtIdCursor;

$position = ['createdAt' => '2026-07-15T10:30:00-05:00', 'id' => 'ord-123'];
$token = CreatedAtIdCursor::encode($position);
$checks = [
    'cursor round trip' => CreatedAtIdCursor::decode($token, 'pedidos') === $position,
    'default page size' => BoundedPageRequest::pageSize([], 48, 100) === 48,
    'page_size alias' => BoundedPageRequest::pageSize(['page_size' => '100']) === 100,
    'pageSize alias' => BoundedPageRequest::pageSize(['pageSize' => '25']) === 25,
    'legacy page maximum' => BoundedPageRequest::legacyPage(['page' => '50'], 50) === 50,
];

foreach ([
    fn() => BoundedPageRequest::pageSize(['page_size' => '0']),
    fn() => BoundedPageRequest::pageSize(['page_size' => '101']),
    fn() => BoundedPageRequest::pageSize(['page_size' => ['100']]),
    fn() => BoundedPageRequest::legacyPage(['page' => '51'], 50),
    fn() => CreatedAtIdCursor::decode('invalid***', 'pedidos'),
] as $index => $operation) {
    try {
        $operation();
        $checks['invalid input rejected #' . $index] = false;
    } catch (InvalidArgumentException) {
        $checks['invalid input rejected #' . $index] = true;
    }
}

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "HTTP list pagination test failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "HTTP list pagination: OK\n";
