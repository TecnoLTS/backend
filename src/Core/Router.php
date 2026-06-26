<?php

namespace App\Core;

class Router {
    protected $routes = [];

    public function add($method, $path, $handler, ?string $capabilityId = null) {
        // Convert path like /api/products/{id} to regex
        $path = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[a-zA-Z0-9_.-]+)', $path);
        $path = '#^' . $path . '$#';

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'capability' => $capabilityId
        ];
    }

    public function dispatch($method, $uri) {
        $matched = $this->match($method, $uri);
        if ($matched !== null) {
            $route = $matched['route'];
            [$controller, $action] = explode('@', $route['handler']);
            $controllerClass = $this->resolveControllerClass($controller);
            if (!class_exists($controllerClass)) {
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'error' => [
                        'message' => 'Route handler controller not found',
                        'code' => 'ROUTE_HANDLER_CONTROLLER_NOT_FOUND',
                        'details' => ['controller' => $controllerClass],
                    ],
                ]);
                return;
            }

            $instance = new $controllerClass();
            if (!method_exists($instance, $action)) {
                http_response_code(500);
                echo json_encode([
                    'ok' => false,
                    'error' => [
                        'message' => 'Route handler action not found',
                        'code' => 'ROUTE_HANDLER_ACTION_NOT_FOUND',
                        'details' => ['controller' => $controllerClass, 'action' => $action],
                    ],
                ]);
                return;
            }

            $params = $matched['params'];

            return call_user_func_array([$instance, $action], $params);
        }
        
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'error' => [
                'message' => 'Route not found',
                'code' => 'ROUTE_NOT_FOUND',
                'details' => ['uri' => $uri, 'method' => $method]
            ]
        ]);
    }

    public function match($method, $uri): ?array {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['path'], $uri, $matches)) {
                return [
                    'route' => $route,
                    'params' => array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY),
                ];
            }
        }

        return null;
    }

    private function resolveControllerClass(string $controller): string {
        $normalized = ltrim($controller, '\\');
        if (str_contains($normalized, '\\')) {
            return $normalized;
        }

        return "App\\Controllers\\$normalized";
    }
}
