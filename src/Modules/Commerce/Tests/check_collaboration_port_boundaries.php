<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$contact = file_get_contents($root . '/src/Modules/Commerce/Controllers/ContactController.php');
$pos = file_get_contents($root . '/src/Modules/Commerce/Controllers/PosController.php');
$order = file_get_contents($root . '/src/Modules/Commerce/Controllers/OrderController.php');
$shipping = file_get_contents($root . '/src/Modules/Commerce/Controllers/ShippingController.php');

$checks = [
    'contact controller uses owned port' => is_string($contact)
        && str_contains($contact, 'CommerceContactPort')
        && !str_contains($contact, 'ContactMessageRepository')
        && !str_contains($contact, 'MailService'),
    'pos controller uses owned expense port' => is_string($pos)
        && str_contains($pos, 'CommerceExpensePort')
        && !str_contains($pos, 'BusinessExpenseRepository'),
    'commerce controllers use owned settings port' => is_string($order)
        && is_string($shipping)
        && str_contains($order, 'CommerceSettingsPort')
        && !str_contains($order, 'SettingsRepository')
        && !str_contains($shipping, 'SettingsRepository'),
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Commerce collaboration boundary failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Commerce collaboration boundaries: OK\n";
