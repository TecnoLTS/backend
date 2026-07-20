<?php

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

use App\Support\ModularControllerBoundary;

function fail(string $message): void {
    fwrite(STDERR, "[module-routes-check] {$message}\n");
    exit(1);
}

function assertRouteShape(array $route, string $source, int $index): void {
    foreach (['method', 'path', 'handler', 'capability'] as $field) {
        if (!array_key_exists($field, $route) || trim((string)$route[$field]) === '') {
            fail(sprintf('%s route #%d no declara %s', $source, $index, $field));
        }
    }

    if (!str_contains((string)$route['handler'], '@')) {
        fail(sprintf('%s route #%d tiene handler invalido: %s', $source, $index, (string)$route['handler']));
    }
}

function reflectionMethodSource(ReflectionMethod $method): string {
    $fileName = $method->getFileName();
    if (!is_string($fileName)) {
        return '';
    }
    $lines = file($fileName);
    if (!is_array($lines)) {
        return '';
    }

    return implode('', array_slice(
        $lines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));
}

$moduleRouteFiles = glob($root . '/src/Modules/*/routes.php') ?: [];
sort($moduleRouteFiles);
if ($moduleRouteFiles === []) {
    fail('No se encontraron registries src/Modules/*/routes.php');
}

$allModuleRoutes = [];
$seenRoutes = [];

$moduleControllerFiles = glob($root . '/src/Modules/*/Controllers/*.php') ?: [];
sort($moduleControllerFiles);
foreach ($moduleControllerFiles as $controllerFile) {
    $moduleName = basename(dirname($controllerFile, 2));
    $controllerClass = 'App\\Modules\\' . $moduleName . '\\Controllers\\' . basename($controllerFile, '.php');
    $source = file_get_contents($controllerFile);
    if (!is_string($source)) {
        fail(sprintf('No se pudo leer %s', $controllerFile));
    }

    foreach (ModularControllerBoundary::sourceViolations($source) as $violation) {
        fail(sprintf('%s %s', $controllerFile, $violation));
    }

    if (!class_exists($controllerClass)) {
        fail(sprintf('%s no declara la clase esperada %s', $controllerFile, $controllerClass));
    }

    $controllerReflection = new ReflectionClass($controllerClass);
    foreach (ModularControllerBoundary::reflectionViolations($controllerReflection) as $violation) {
        fail(sprintf('%s %s', $controllerClass, $violation));
    }
}

foreach ($moduleRouteFiles as $routeFile) {
    $moduleName = basename(dirname($routeFile));
    $expectedPrefix = 'App\\Modules\\' . $moduleName . '\\Controllers\\';
    $registry = require $routeFile;
    if (!is_array($registry)) {
        fail(sprintf('%s no devuelve un array', $routeFile));
    }

    foreach ($registry as $index => $route) {
        if (!is_array($route)) {
            fail(sprintf('%s route #%d no es array', $routeFile, $index));
        }

        assertRouteShape($route, $routeFile, $index);

        [$handlerClass, $handlerMethod] = explode('@', (string)$route['handler'], 2);
        $handlerClass = ltrim($handlerClass, '\\');
        $handlerMethod = trim($handlerMethod);

        if (str_starts_with($handlerClass, 'App\\Controllers\\')) {
            fail(sprintf(
                '%s route %s %s expone handler legacy %s; use un controlador App\\Modules\\%s\\Controllers\\*',
                $routeFile,
                (string)$route['method'],
                (string)$route['path'],
                $handlerClass,
                $moduleName
            ));
        }

        if (!str_starts_with($handlerClass, $expectedPrefix)) {
            fail(sprintf(
                '%s route %s %s debe apuntar a %s*, recibido %s',
                $routeFile,
                (string)$route['method'],
                (string)$route['path'],
                $expectedPrefix,
                $handlerClass
            ));
        }

        if (!class_exists($handlerClass)) {
            fail(sprintf('%s route %s %s apunta a clase inexistente %s', $routeFile, (string)$route['method'], (string)$route['path'], $handlerClass));
        }

        if ($handlerMethod === '' || !method_exists($handlerClass, $handlerMethod)) {
            fail(sprintf('%s route %s %s apunta a metodo inexistente %s@%s', $routeFile, (string)$route['method'], (string)$route['path'], $handlerClass, $handlerMethod));
        }

        $methodReflection = new ReflectionMethod($handlerClass, $handlerMethod);
        if (str_starts_with($methodReflection->getDeclaringClass()->getName(), 'App\\Controllers\\')) {
            fail(sprintf(
                '%s route %s %s hereda el metodo %s desde el controlador legacy %s',
                $routeFile,
                (string)$route['method'],
                (string)$route['path'],
                $handlerMethod,
                $methodReflection->getDeclaringClass()->getName()
            ));
        }

        foreach (ModularControllerBoundary::httpHandlerListViolations(reflectionMethodSource($methodReflection)) as $violation) {
            fail(sprintf(
                '%s route %s %s handler %s@%s %s',
                $routeFile,
                (string)$route['method'],
                (string)$route['path'],
                $handlerClass,
                $handlerMethod,
                $violation
            ));
        }

        $routeKey = strtoupper((string)$route['method']) . ' ' . (string)$route['path'];
        if (isset($seenRoutes[$routeKey])) {
            fail(sprintf('%s duplica la ruta %s ya declarada en %s', $routeFile, $routeKey, $seenRoutes[$routeKey]));
        }
        $seenRoutes[$routeKey] = $routeFile;

        $allModuleRoutes[] = $route;
    }
}

$aggregatedRoutes = require $root . '/config/routes.php';
if (!is_array($aggregatedRoutes)) {
    fail('config/routes.php no devuelve un array');
}

if (count($aggregatedRoutes) !== count($allModuleRoutes)) {
    fail(sprintf(
        'config/routes.php carga %d rutas, pero los registries modulares declaran %d',
        count($aggregatedRoutes),
        count($allModuleRoutes)
    ));
}

foreach ($aggregatedRoutes as $index => $route) {
    if (!is_array($route)) {
        fail(sprintf('config/routes.php route #%d no es array', $index));
    }
    assertRouteShape($route, 'config/routes.php', $index);
}

printf(
    "[module-routes-check] OK modules=%d controllers=%d routes=%d\n",
    count($moduleRouteFiles),
    count($moduleControllerFiles),
    count($allModuleRoutes)
);
