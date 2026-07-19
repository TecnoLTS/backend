<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\ModularControllerBoundary;

$root = dirname(__DIR__);
$routes = require $root . '/config/routes.php';
$failures = [];
$scanned = 0;
$getRoutes = 0;

/** @return string */
$methodSource = static function (ReflectionMethod $method): string {
    $lines = file($method->getFileName());
    if (!is_array($lines)) {
        return '';
    }
    return implode('', array_slice(
        $lines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));
};

/** @return list<string> */
$transitiveCalls = static function (ReflectionClass $class, string $entryMethod) use (&$transitiveCalls, $methodSource): array {
    $pending = [$entryMethod];
    $visited = [];
    $calls = [];
    while ($pending !== []) {
        $name = array_pop($pending);
        if (isset($visited[$name]) || !$class->hasMethod($name)) {
            continue;
        }
        $visited[$name] = true;
        $method = $class->getMethod($name);
        foreach (ModularControllerBoundary::methodCallNames($methodSource($method)) as $call) {
            $calls[$call] = true;
            if ($class->hasMethod($call) && !isset($visited[$call])) {
                $pending[] = $call;
            }
        }
    }
    return array_keys($calls);
};

$requiredKeysetCalls = [
    '/api/products' => ['getPublicPage', 'encode'],
    '/api/admin/products' => ['getAdminPage', 'encode'],
    '/api/products/{id}/reviews' => ['approvedPageForProduct', 'encode'],
    '/api/orders' => ['getPageResult', 'getByUserIdPage', 'encode'],
    '/api/orders/my-orders' => ['getByUserIdPage', 'encode'],
    '/api/admin/ecommerce-users' => ['getPage', 'encode'],
];
$boundedReferenceDictionaryRoutes = [
    '/api/admin/settings/product-reference-data' => true,
    '/api/settings/brand-logos' => true,
    '/api/settings/product-categories' => true,
    '/api/settings/product-category-references' => true,
];

foreach ($routes as $route) {
    $scanned++;
    $method = strtoupper((string)($route['method'] ?? ''));
    $path = (string)($route['path'] ?? '');
    $handler = (string)($route['handler'] ?? '');
    if ($method === 'GET') {
        $getRoutes++;
    }
    if ($method === '' || $path === '' || !str_contains($handler, '@')) {
        $failures[] = "route #{$scanned} has an incomplete HTTP contract";
        continue;
    }
    [$className, $methodName] = explode('@', $handler, 2);
    try {
        $class = new ReflectionClass($className);
        $handlerMethod = $class->getMethod($methodName);
    } catch (Throwable $exception) {
        $failures[] = "{$method} {$path} does not resolve {$handler}: {$exception->getMessage()}";
        continue;
    }

    $calls = $transitiveCalls($class, $handlerMethod->getName());
    foreach (['getAll', 'getByUserId', 'listApprovedForProduct'] as $forbiddenCall) {
        if (in_array($forbiddenCall, $calls, true)) {
            $isBoundedReferenceDictionary = $method === 'GET'
                && $forbiddenCall === 'getAll'
                && isset($boundedReferenceDictionaryRoutes[$path]);
            if ($method === 'GET' && !$isBoundedReferenceDictionary) {
                $failures[] = "{$method} {$path} reaches unbounded {$forbiddenCall}()";
            }
        }
    }
    if ($method === 'GET' && isset($requiredKeysetCalls[$path])) {
        foreach ($requiredKeysetCalls[$path] as $requiredCall) {
            if (!in_array($requiredCall, $calls, true)) {
                $failures[] = "GET {$path} is missing keyset call {$requiredCall}()";
            }
        }
    }
}

if ($scanned < 250 || $getRoutes < 100) {
    $failures[] = "route inventory is incomplete (routes={$scanned}, GET={$getRoutes})";
}
foreach (array_keys($requiredKeysetCalls) as $path) {
    $exists = array_filter($routes, static fn(array $route): bool =>
        strtoupper((string)($route['method'] ?? '')) === 'GET' && ($route['path'] ?? '') === $path
    );
    if ($exists === []) {
        $failures[] = "required keyset route is absent: GET {$path}";
    }
}
$referenceRepository = file_get_contents($root . '/src/Repositories/ProductReferenceCatalogRepository.php');
if (!is_string($referenceRepository) || !preg_match('/ORDER BY catalog_key.*?LIMIT 2000/s', $referenceRepository)) {
    $failures[] = 'the non-paginated product reference dictionary is not hard-capped at 2000 rows';
}

if ($failures !== []) {
    fwrite(STDERR, "HTTP list boundary audit failed:\n- " . implode("\n- ", array_unique($failures)) . "\n");
    exit(1);
}

echo "HTTP list boundary audit: OK ({$scanned} routes, {$getRoutes} GET handlers inspected semantically)\n";
