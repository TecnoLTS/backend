<?php

declare(strict_types=1);

use App\Support\ModuleOpenApiSchemaCatalog;

$moduleRoot = dirname(__DIR__);
require_once dirname($moduleRoot, 2) . '/Support/ModuleOpenApiSchemaCatalog.php';

$routes = require $moduleRoot . '/routes.php';
$expectedHandler = 'App\\Modules\\LoyaltyRewards\\Controllers\\LoyaltyHealthController@health';
$healthRoutes = array_values(array_filter(
    $routes,
    static fn(array $route): bool => ($route['method'] ?? null) === 'GET'
        && ($route['path'] ?? null) === '/api/loyalty/v1/health'
));
$healthRoute = $healthRoutes[0] ?? null;
$openApiContract = is_array($healthRoute)
    ? ModuleOpenApiSchemaCatalog::contractFor($healthRoute, 'public')
    : null;

$controllerPath = $moduleRoot . '/Controllers/LoyaltyHealthController.php';
$controllerSource = file_get_contents($controllerPath);
$legacyControllerSource = file_get_contents($moduleRoot . '/Controllers/LoyaltyController.php');

$checks = [
    count($healthRoutes) === 1,
    ($healthRoutes[0]['handler'] ?? null) === $expectedHandler,
    ($healthRoutes[0]['capability'] ?? null) === 'loyalty.public',
    ($openApiContract['required'] ?? null) === true,
    ($openApiContract['responseMode'] ?? null) === 'core-json',
    ($openApiContract['responseSchema'] ?? null) === 'LoyaltyHealthData',
    is_string($controllerSource) && str_contains($controllerSource, "'status' => 'ok'"),
    is_string($controllerSource) && !preg_match('/LoyaltyRepository|ConnectionRegistry|Database::|new\\s+PDO/', $controllerSource),
    is_string($legacyControllerSource) && !str_contains($legacyControllerSource, 'function externalHealth'),
];

if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Loyalty lightweight health contract: FAIL\n");
    exit(1);
}

fwrite(STDOUT, "Loyalty lightweight health contract: OK\n");
