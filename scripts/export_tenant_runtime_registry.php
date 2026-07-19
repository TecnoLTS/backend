<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistryStore;

$configuredPath = __DIR__ . '/../config/tenants.php';
$configuredTenants = is_readable($configuredPath) ? require $configuredPath : [];
if (!is_array($configuredTenants)) {
    fwrite(STDERR, "Tenant config is not an array.\n");
    exit(1);
}

$state = (new TenantRuntimeRegistryStore())->getState();
$revision = $state['revision'] ?? null;
$overrides = is_array($state['registry'] ?? null)
    ? $state['registry']
    : ['version' => 1, 'tenants' => []];
if (!is_int($revision) || $revision < 1) {
    fwrite(STDERR, "Tenant registry revision is invalid.\n");
    exit(1);
}

// The edge controller must reconcile one exact database generation. Exporting
// through the API snapshot would omit that generation and would make an old
// tenant/hash set replayable after an intervening control-plane mutation.
$registry = TenantRuntimeRegistry::exportWithOverrides($configuredTenants, $overrides);
$registry['registryRevision'] = $revision;
$encoded = json_encode($registry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if (!is_string($encoded)) {
    fwrite(STDERR, "Could not encode tenant runtime registry.\n");
    exit(1);
}

echo $encoded . PHP_EOL;
