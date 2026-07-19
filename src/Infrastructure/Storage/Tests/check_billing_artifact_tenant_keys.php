<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$source = file_get_contents($root . '/src/Infrastructure/Storage/Billing/BillingArtifactStorage.php');

$checks = [
    'new keys include explicit tenant segment' => is_string($source)
        && str_contains($source, "billing/tenants/"),
    'missing tenant fails closed' => is_string($source)
        && str_contains($source, 'Tenant Billing requerido para aislar artefactos.'),
    'legacy local references remain read compatible' => is_string($source)
        && str_contains($source, "StorageKey::prefix('billing', substr(\$reference"),
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Billing artifact tenant key contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Billing artifact tenant key contract: OK\n";
