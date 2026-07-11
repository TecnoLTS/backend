<?php

declare(strict_types=1);

use App\Modules\LoyaltyRewards\Application\LoyaltyNavigationService;
use App\Modules\LoyaltyRewards\Domain\LoyaltyNavigationCatalog;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

/** @throws RuntimeException */
function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$definitions = LoyaltyNavigationCatalog::definitions();
$items = [];
$publishedPermissions = [];
$reportCount = 0;

foreach ($definitions as $definition) {
    $key = (string)$definition['key'];
    check(!isset($items[$key]), "Item duplicado: {$key}");
    check(in_array($definition['kind'], ['section', 'group', 'page'], true), "Tipo invalido: {$key}");
    check((int)$definition['depth'] >= 0 && (int)$definition['depth'] <= 3, "Profundidad invalida: {$key}");

    $routeKey = $definition['routeKey'];
    if ($routeKey !== null) {
        check(LoyaltyNavigationCatalog::resolveRoute($routeKey) !== null, "Route key desconocida: {$routeKey}");
    }

    $hasView = false;
    foreach ($definition['actions'] as $action) {
        $expected = LoyaltyNavigationCatalog::expectedPermissionKey((string)$routeKey, (string)$action['key']);
        check($expected !== null && $expected === $action['permissionKey'], "Permiso invalido: {$key}.{$action['key']}");
        check(!isset($publishedPermissions[$expected]), "Permiso duplicado: {$expected}");
        $publishedPermissions[$expected] = true;
        $hasView = $hasView || $action['key'] === 'view';
    }
    check($definition['actions'] === [] || $hasView, "Opcion con acciones sin view: {$key}");

    if (str_starts_with($key, 'loyalty.report.')) {
        $reportCount++;
    }
    $items[$key] = $definition;
}

foreach ($items as $key => $definition) {
    if ($definition['kind'] === 'section') {
        check($definition['parentKey'] === null && $definition['depth'] === 0, "Seccion invalida: {$key}");
        continue;
    }

    $parentKey = (string)$definition['parentKey'];
    check(isset($items[$parentKey]), "Padre inexistente: {$key}");
    check($items[$parentKey]['kind'] !== 'page', "Una pagina no puede tener hijos: {$parentKey}");
    check((int)$definition['depth'] === (int)$items[$parentKey]['depth'] + 1, "Profundidad incoherente: {$key}");
}
check($reportCount === 9, "Se esperaban 9 reportes y se encontraron {$reportCount}");

$service = new LoyaltyNavigationService();
$moduleRoutes = require dirname(__DIR__) . '/routes.php';
$adminRoutes = [];
foreach ($moduleRoutes as $route) {
    if (!str_starts_with((string)$route['path'], '/api/admin/loyalty')) {
        continue;
    }
    $path = str_replace('{reportKey}', 'executive-summary', (string)$route['path']);
    $path = preg_replace('/\{[^}]+\}/', 'sample-id', $path) ?? $path;
    $adminRoutes[] = [(string)$route['method'], $path];
}

foreach ($adminRoutes as [$method, $path]) {
    $permission = $service->requiredPermissionForRequest($method, $path);
    check($permission !== null, "Ruta admin sin permiso: {$method} {$path}");
    check($permission !== LoyaltyNavigationService::DENY_PERMISSION, "Ruta admin denegada por falta de mapa: {$method} {$path}");
    check(isset($publishedPermissions[$permission]), "Ruta admin usa permiso no publicado: {$method} {$path} -> {$permission}");
}

check($service->requiredPermissionForRequest('GET', '/api/loyalty/v1/health') === null, 'La API externa no debe usar permisos de menu.');
check($service->requiredPermissionForRequest('GET', '/api/l/access') === null, 'El portal publico no debe usar permisos de menu.');
check(
    $service->requiredPermissionForRequest('POST', '/api/admin/loyalty/unknown') === LoyaltyNavigationService::DENY_PERMISSION,
    'Una ruta admin desconocida debe fallar cerrada.'
);

fwrite(STDOUT, sprintf(
    "Loyalty navigation catalog OK: %d items, %d permissions, %d admin routes.\n",
    count($items),
    count($publishedPermissions),
    count($adminRoutes)
));
