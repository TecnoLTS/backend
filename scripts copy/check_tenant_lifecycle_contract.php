<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'controller' => $root . '/backend/src/Modules/IdentityPlatform/Controllers/TenantController.php',
    'routes' => $root . '/backend/src/Modules/IdentityPlatform/routes.php',
    'store' => $root . '/backend/src/Modules/IdentityPlatform/Infrastructure/TenantRuntimeRegistryStore.php',
    'policy' => $root . '/backend/src/Modules/IdentityPlatform/Application/TenantRuntimeMutationPolicy.php',
    'access' => $root . '/backend/src/Modules/IdentityPlatform/Application/TenantAccessService.php',
    'adapter' => $root . '/backend/src/Modules/IdentityPlatform/Infrastructure/Navigation/LoyaltyTenantNavigationAdapter.php',
    'sql' => $root . '/basesdedatos/scripts/platform-auth-capability.sql',
    'sqlCheck' => $root . '/basesdedatos/scripts/check-platform-auth-capability.sql',
    'openapi' => $root . '/backend/src/Support/ModuleOpenApiDocument.php',
    'schemas' => $root . '/backend/src/Support/ModuleOpenApiSchemaCatalog.php',
    'dashboard' => $root . '/dashboard/src/app/features/tenant-admin/data/tenant-admin-api.service.ts',
    'backendEntry' => $root . '/backend/public/index.php',
    'gatewaySync' => $root . '/gatewayapisix/scripts/sync-apisix.sh',
];
$source = [];
foreach ($files as $name => $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        fwrite(STDERR, "No se pudo leer {$path}.\n");
        exit(1);
    }
    $source[$name] = $contents;
}

$required = [
    'routes' => [
        '/api/admin/tenants/{tenantId}/lifecycle',
        '/api/admin/tenants/{tenantId}/domains',
        '/api/admin/tenants/{tenantId}/reconcile',
        '/api/admin/tenants/{tenantId}/rollback',
        '/api/admin/tenants/{tenantId}/events',
    ],
    'controller' => [
        "\$_SERVER['HTTP_IF_MATCH']",
        "\$_SERVER['HTTP_IDEMPOTENCY_KEY']",
        'TENANT_REGISTRY_PRECONDITION_REQUIRED',
        'TENANT_REGISTRY_REVISION_CONFLICT',
        'tenantAtRevision(',
        'adminLifecycle(',
        'adminUpdateDomains(',
        'adminReconcile(',
        'adminRollback(',
        'adminEvents(',
    ],
    'store' => [
        ':expected_revision',
        'platform_auth.get_tenant_runtime_registry_mutation',
        'platform_auth.get_tenant_runtime_registry_events',
        'platform_auth.get_tenant_runtime_registry_tenant_at_revision',
    ],
    'policy' => [
        "'suspend' => 'suspended'",
        "'resume' => 'active'",
        "'offboard' => 'inactive'",
        'TENANT_OFFBOARDED_IMMUTABLE',
        'previousDomains',
    ],
    'sql' => [
        'tenant_runtime_registry_mutations',
        'p_expected_revision bigint',
        'FOR UPDATE',
        'TENANT_REGISTRY_IDEMPOTENCY_CONFLICT',
        'TENANT_REGISTRY_REVISION_CONFLICT',
        'previous_tenant',
        'desired_tenant',
    ],
    'sqlCheck' => [
        'Tenant registry journal column ACL drift',
        'did not replay idempotently',
        'Runtime role % has direct tenant registry journal privileges',
    ],
    'openapi' => [
        "'name' => 'If-Match'",
        "'name' => 'Idempotency-Key'",
        "'428'",
        'optimistic-cas',
    ],
    'schemas' => [
        "'TenantLifecycleRequest'",
        "'TenantDomainsRequest'",
        "'TenantRollbackRequest'",
        "'TenantRegistryEventList'",
    ],
    'dashboard' => [
        "'If-Match': `\"tenant-registry-\${revision}\"`",
        "'Idempotency-Key': tenantMutationId()",
        'updateLifecycle(',
        'updateDomains(',
        'reconcile(',
        'rollback(',
        'events(',
    ],
    'backendEntry' => [
        'Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant, X-CSRF-Token, X-API-Key, Idempotency-Key, If-Match',
        'Access-Control-Expose-Headers: ETag',
    ],
    'gatewaySync' => [
        'X-Requested-With, X-Request-ID, Idempotency-Key, If-Match, X-Loyalty-Timestamp',
        'X-Report-Row-Count, X-Report-Generated-At, ETag',
    ],
];

$failures = [];
foreach ($required as $file => $snippets) {
    foreach ($snippets as $snippet) {
        if (!str_contains($source[$file], $snippet)) {
            $failures[] = "{$file} no contiene contrato requerido: {$snippet}";
        }
    }
}
foreach (['controller', 'access'] as $applicationFile) {
    if (str_contains($source[$applicationFile], 'App\\Modules\\LoyaltyRewards\\')) {
        $failures[] = "{$applicationFile} conserva dependencia directa Identity->Loyalty";
    }
}
if (!str_contains($source['adapter'], 'App\\Modules\\LoyaltyRewards\\Application\\LoyaltyNavigationService')) {
    $failures[] = 'el adapter Infrastructure no implementa la colaboracion Loyalty';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Tenant lifecycle/CAS contract: OK\n";
