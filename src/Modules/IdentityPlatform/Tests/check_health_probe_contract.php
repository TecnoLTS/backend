<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use App\Modules\IdentityPlatform\Controllers\HealthController;

/** @return string */
function healthMethodSource(ReflectionMethod $method): string
{
    $lines = file($method->getFileName());
    if (!is_array($lines)) {
        return '';
    }

    return implode('', array_slice(
        $lines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));
}

$reflection = new ReflectionClass(HealthController::class);
$live = healthMethodSource($reflection->getMethod('live'));
$ready = healthMethodSource($reflection->getMethod('ready'));
$status = healthMethodSource($reflection->getMethod('status'));
$routes = require dirname(__DIR__) . '/routes.php';
$entrypoint = file_get_contents($root . '/public/index.php');
$databaseSource = file_get_contents($root . '/src/Core/Database.php');
$registryStoreSource = file_get_contents($root . '/src/Modules/IdentityPlatform/Infrastructure/TenantRuntimeRegistryStore.php');
$composeSource = file_get_contents($root . '/docker-compose.yml');
$liveFastPathPosition = is_string($entrypoint)
    ? strpos($entrypoint, "\$bootstrapLivenessPaths = ['/api/livez', '/api/health']")
    : false;
$tenantRegistryPosition = is_string($entrypoint)
    ? strpos($entrypoint, 'TenantRuntimeRegistry::mergeConfigured')
    : false;

$handlers = [];
foreach ($routes as $route) {
    $handlers[strtoupper((string)$route['method']) . ' ' . (string)$route['path']] = (string)$route['handler'];
}

$checks = [
    'liveness has no database dependency' => !str_contains($live, 'Database::')
        && !str_contains($live, 'SELECT ')
        && !str_contains($live, '->query('),
    'entrypoint dispatches liveness before tenant registry or database access' => $liveFastPathPosition !== false
        && $tenantRegistryPosition !== false
        && $liveFastPathPosition < $tenantRegistryPosition
        && str_contains((string)$entrypoint, "in_array(\$bootstrapRequestMethod, ['GET', 'HEAD'], true)")
        && str_contains((string)$entrypoint, 'in_array($bootstrapRequestPath, $bootstrapLivenessPaths, true)')
        && str_contains((string)$entrypoint, '(new HealthController())->live();'),
    'readiness checks the database' => str_contains($ready, 'Database::getInstance()')
        && str_contains($ready, "SELECT 1")
        && str_contains($ready, 'TenantRuntimeRegistry::verifyCanonicalStore()')
        && str_contains($ready, 'HEALTH_DB_UNAVAILABLE'),
    'readiness attests the resolved tenant identity' => str_contains($ready, "'tenant_id' => TenantContext::id()")
        && str_contains($ready, "'tenant_slug' => TenantContext::slug()")
        && str_contains($ready, "'tenant_desired_state_hash' => TenantRuntimeRegistry::desiredStateHash(\$tenant)"),
    'tenant registry uses an allowlisted pre-tenant capability connection' => is_string($databaseSource)
        && is_string($registryStoreSource)
        && str_contains($databaseSource, "\$normalizedModule !== 'identity-platform'")
        && str_contains($databaseSource, "\$normalizedCapability !== 'tenant-runtime-registry'")
        && str_contains($databaseSource, "\$config['connection_role'] = 'platform_capability'")
        && str_contains($registryStoreSource, 'Database::getModuleCapabilityInstance('),
    'readiness uses owned dependency port' => str_contains($ready, 'RuntimeReadinessFactory::dependencies()')
        && !str_contains($ready, 'App\\Modules\\Billing\\')
        && !str_contains($ready, 'BillingSecretStorageAttestor'),
    'readiness factory covers databases and storage' => str_contains(
        (string)file_get_contents($root . '/src/Modules/IdentityPlatform/Infrastructure/Readiness/RuntimeReadinessFactory.php'),
        'ModuleDatabaseReadinessAdapter'
    ) && str_contains(
        (string)file_get_contents($root . '/src/Modules/IdentityPlatform/Infrastructure/Readiness/RuntimeReadinessFactory.php'),
        'StorageReadinessAdapter'
    ),
    'docker health uses lightweight liveness instead of deep readiness' => is_string($composeSource)
        && str_contains($composeSource, 'http://127.0.0.1:8080/internal/fpm-ping')
        && str_contains($composeSource, 'http://127.0.0.1:8080/api/livez || exit 1')
        && !str_contains($composeSource, 'http://127.0.0.1:8080/api/readyz || exit 1'),
    'legacy health is a liveness alias' => str_contains($status, '$this->live()')
        && !str_contains($status, '$this->ready()'),
    'live route is registered' => ($handlers['GET /api/livez'] ?? null)
        === HealthController::class . '@live',
    'ready route is registered' => ($handlers['GET /api/readyz'] ?? null)
        === HealthController::class . '@ready',
    'health compatibility route remains registered' => ($handlers['GET /api/health'] ?? null)
        === HealthController::class . '@status',
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Health probe contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Health probe contract: OK\n";
