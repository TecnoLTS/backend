<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$controller = file_get_contents($root . '/src/Modules/Commerce/Controllers/OrderController.php');
$port = file_get_contents($root . '/src/Modules/Commerce/Application/Ports/CommerceBillingHttpPort.php');
$processor = file_get_contents($root . '/src/Modules/Commerce/Application/CommerceBillingOutboxProcessor.php');
$adapter = file_get_contents($root . '/src/Modules/Commerce/Infrastructure/Adapters/AuthenticatedCommerceBillingHttpAdapter.php');

$checks = [
    'order controller does not import Billing module' => is_string($controller)
        && !str_contains($controller, 'App\\Modules\\Billing\\'),
    'request controller performs no Billing side effect' => is_string($controller)
        && !str_contains($controller, 'dispatchBillingInvoiceAfterResponse('),
    'port is transport neutral' => is_string($port)
        && !str_contains($port, 'Infrastructure')
        && !str_contains($port, 'BillingGateway'),
    'durable processor consumes owned HTTP port' => is_string($processor)
        && str_contains($processor, 'CommerceBillingHttpPort'),
    'adapter crosses boundary only through authenticated HTTP' => is_string($adapter)
        && str_contains($adapter, 'X-API-Key: ')
        && !str_contains($adapter, 'App\\Modules\\Billing'),
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Commerce/Billing port boundary failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Commerce/Billing port boundary: OK\n";
