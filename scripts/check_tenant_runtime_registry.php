<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry;

$configured = require __DIR__ . '/../config/tenants.php';
$registry = TenantRuntimeRegistry::export(is_array($configured) ? $configured : []);
$failures = [];
$domainOwners = [];
$registryHealth = TenantRuntimeRegistry::healthStatus();

if (($registry['version'] ?? null) !== 1 || !is_array($registry['tenants'] ?? null)) {
    $failures[] = 'registry shape is invalid';
}
if (($registryHealth['ready'] ?? false) !== true || ($registryHealth['source'] ?? '') === 'fail_closed') {
    $failures[] = 'tenant registry control plane is not safely available';
}

foreach (($registry['tenants'] ?? []) as $tenant) {
    $id = trim((string)($tenant['id'] ?? ''));
    $slug = trim((string)($tenant['slug'] ?? ''));
    $domains = $tenant['domains'] ?? null;
    if ($id === '' || $slug === '' || !is_array($domains) || $domains === []) {
        $failures[] = 'tenant identity/domains are incomplete';
        continue;
    }
    $desiredHash = strtolower(trim((string)($tenant['desiredStateHash'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $desiredHash)
        || !hash_equals(TenantRuntimeRegistry::desiredStateHash($tenant), $desiredHash)) {
        $failures[] = "tenant {$id} has an invalid desired-state hash";
    }
    foreach ($domains as $domain) {
        if (isset($domainOwners[$domain]) && $domainOwners[$domain] !== $id) {
            $failures[] = "domain collision {$domain}";
        }
        $domainOwners[$domain] = $id;
    }
}

$controller = file_get_contents(__DIR__ . '/../src/Modules/IdentityPlatform/Controllers/TenantController.php');
if (!is_string($controller)
    || substr_count($controller, '$this->lockTenantRuntimeRegistry($db);') < 3
    || !str_contains($controller, 'pg_advisory_xact_lock')) {
    $failures[] = 'tenant create/update operations are not serialized by a transaction advisory lock';
}
$receiptConsumer = file_get_contents(__DIR__ . '/mark_tenant_runtime_provisioned.php');
$registryExporter = file_get_contents(__DIR__ . '/export_tenant_runtime_registry.php');
foreach ([
    "stream_get_contents(STDIN",
    "DB_CONNECTION_ROLE",
    "desiredStateHash",
    "gatewayApplied",
    "tlsVerified",
    "STALE_TENANT_RECONCILIATION_RECEIPT",
    "TenantRuntimeRegistry::exportWithOverrides(\$configuredTenants, \$overrides)",
    "array_keys(\$effectiveTenants) !== array_keys(\$receiptTenants)",
    "\$effectiveTenant['desiredStateHash']",
    "\$effectiveTenant['enabledModules']",
] as $requiredReceiptContract) {
    if (!is_string($receiptConsumer) || !str_contains($receiptConsumer, $requiredReceiptContract)) {
        $failures[] = "tenant receipt consumer lacks {$requiredReceiptContract}";
    }
}
foreach ([
    'TenantRuntimeRegistryStore())->getState()',
    "TenantRuntimeRegistry::exportWithOverrides(\$configuredTenants, \$overrides)",
    "\$registry['registryRevision'] = \$revision",
] as $requiredExporterContract) {
    if (!is_string($registryExporter) || !str_contains($registryExporter, $requiredExporterContract)) {
        $failures[] = "tenant registry exporter lacks {$requiredExporterContract}";
    }
}
if (is_string($registryExporter)
    && (str_contains($registryExporter, 'TenantRuntimeRegistry::export($configuredTenants)')
        || !str_contains($registryExporter, 'if (!is_int($revision) || $revision < 1)'))) {
    $failures[] = 'tenant registry exporter may omit or bypass the exact DB revision';
}

$registryStore = file_get_contents(__DIR__ . '/../src/Modules/IdentityPlatform/Infrastructure/TenantRuntimeRegistryStore.php');
$registryLoader = file_get_contents(__DIR__ . '/../src/Modules/IdentityPlatform/Infrastructure/TenantRuntimeRegistry.php');
$tenantAccess = file_get_contents(__DIR__ . '/../src/Modules/IdentityPlatform/Application/TenantAccessService.php');
$capabilitySqlPath = __DIR__ . '/../../basesdedatos/scripts/platform-auth-capability.sql';
$capabilitySql = is_readable($capabilitySqlPath)
    ? file_get_contents($capabilitySqlPath)
    : null;
foreach ([
    'typed central reader' => 'platform_auth.get_tenant_runtime_registry_state()',
    'typed central writer' => 'platform_auth.set_tenant_runtime_registry(',
    'pre-tenant capability connection' => 'Database::getModuleCapabilityInstance(',
] as $description => $contract) {
    if (!is_string($registryStore) || !str_contains($registryStore, $contract)) {
        $failures[] = "tenant registry store lacks {$description}";
    }
}
// The backend runtime image intentionally contains only the backend build
// context, so the workspace-owned SQL source is unavailable when this check
// runs through `docker exec` (the canonical runtime verification path). Always
// verify that the PHP writer consumes the atomic receipt; additionally verify
// the SQL producer whenever the complete workspace source tree is present.
if (!is_string($registryStore)
    || !str_contains($registryStore, "'revision' => \$result['revision']")
    || (is_string($capabilitySql) && !str_contains($capabilitySql, "'revision', next_revision"))) {
    $failures[] = 'tenant registry store lacks atomic applied revision receipt';
}
if (is_string($registryStore)
    && preg_match('/\$rawResult\s*=\s*\$statement->fetchColumn\(\);(?:(?!public function).)*\$this->getState\(\)/s', $registryStore) === 1) {
    $failures[] = 'tenant registry writer still performs a racy read-after-write';
}
if (!is_string($registryLoader)
    || !str_contains($registryLoader, '(new TenantRuntimeRegistryStore())->getState()')
    || !str_contains($registryLoader, 'TenantRuntimeRegistrySnapshot::loadState()')
    || !str_contains($registryLoader, "self::\$source = 'fail_closed'")
    || str_contains($registryLoader, 'SettingsRepository')) {
    $failures[] = 'tenant registry loader does not use only the central typed store';
}
$snapshotReadPosition = is_string($registryLoader)
    ? strpos($registryLoader, 'TenantRuntimeRegistrySnapshot::loadState()')
    : false;
$canonicalReadPosition = is_string($registryLoader)
    ? strpos($registryLoader, '(new TenantRuntimeRegistryStore())->getState()', (int)$snapshotReadPosition)
    : false;
if ($snapshotReadPosition === false
    || $canonicalReadPosition === false
    || $snapshotReadPosition >= $canonicalReadPosition
    || !str_contains((string)$registryLoader, "self::\$source = 'signed_snapshot_cache'")) {
    $failures[] = 'tenant registry request path is not signed-snapshot-first';
}
foreach ([
    'pure effective merge' => 'mergeConfiguredWithOverrides',
    'pure effective export' => 'exportWithOverrides',
] as $description => $contract) {
    if (!is_string($registryLoader) || !str_contains($registryLoader, $contract)) {
        $failures[] = "tenant registry lacks {$description}";
    }
}
foreach ([
    "foreach (['users', 'dashboard'] as \$baseModule)",
    'array_unshift($normalized, $baseModule)',
] as $baseModuleContract) {
    if (!is_string($tenantAccess) || !str_contains($tenantAccess, $baseModuleContract)) {
        $failures[] = "tenant module normalization lacks mandatory dashboard/users contract: {$baseModuleContract}";
    }
}
if (is_string($capabilitySql)) {
    foreach ([
        "value IS JSON OBJECT",
        "dashboard_tenant_admin_overrides",
        "count(*) FROM jsonb_object_keys(p_payload->'tenants')",
        'REVOKE ALL PRIVILEGES ON public.tenant_runtime_registry',
        'Duplicate tenant runtime registry domain.',
    ] as $sqlContract) {
        if (!str_contains($capabilitySql, $sqlContract)) {
            $failures[] = "central tenant registry capability lacks {$sqlContract}";
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo sprintf("Tenant runtime registry: OK (%d tenants, %d domains)\n", count($registry['tenants']), count($domainOwners));
