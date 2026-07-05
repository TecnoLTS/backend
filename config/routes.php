<?php

$routeRegistryPaths = [
    dirname(__DIR__) . '/src/Modules/IdentityPlatform/routes.php',
    dirname(__DIR__) . '/src/Modules/CatalogInventory/routes.php',
    dirname(__DIR__) . '/src/Modules/Commerce/routes.php',
    dirname(__DIR__) . '/src/Modules/Billing/routes.php',
    dirname(__DIR__) . '/src/Modules/Mailer/routes.php',
    dirname(__DIR__) . '/src/Modules/ReportingFinance/routes.php',
    dirname(__DIR__) . '/src/Modules/LoyaltyRewards/routes.php',
];

$routes = [];
foreach ($routeRegistryPaths as $registryPath) {
    $registry = require $registryPath;
    if (!is_array($registry)) {
        throw new \RuntimeException(sprintf('Route registry inválido: %s', $registryPath));
    }

    $routes = array_merge($routes, $registry);
}

return $routes;
