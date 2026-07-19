<?php

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\CatalogInventory\Application\PublicCatalogCursor;

$position = ['createdAt' => '2026-07-15 01:02:03.456', 'id' => 'product-01'];
$token = PublicCatalogCursor::encode($position);
$decoded = PublicCatalogCursor::decode($token);

$failures = [];
if ($decoded !== $position) {
    $failures[] = 'cursor round-trip mismatch';
}
if (PublicCatalogCursor::decode(null) !== null || PublicCatalogCursor::decode('') !== null) {
    $failures[] = 'empty cursor must resolve to null';
}

foreach (['not+base64', 'e30', str_repeat('a', 513)] as $invalid) {
    try {
        PublicCatalogCursor::decode($invalid);
        $failures[] = "invalid cursor accepted: {$invalid}";
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Public catalog cursor: OK\n";
