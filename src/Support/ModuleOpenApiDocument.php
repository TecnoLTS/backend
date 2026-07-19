<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds an OpenAPI operation inventory from the modular route registries.
 *
 * Route/method/capability/security inventory comes from the modular registries.
 * Public, external and critical mutation payloads are enriched by the semantic
 * schema catalog, which is derived from the runtime and validated fail closed.
 */
final class ModuleOpenApiDocument
{
    private const OPENAPI_VERSION = '3.1.0';
    private const JSON_SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';
    private const HTTP_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var array<string, string> */
    private const TAG_DESCRIPTIONS = [
        'identity-platform' => 'Identidad, autenticacion, tenants, usuarios, roles y sesiones.',
        'catalog-inventory' => 'Catalogo, referencias, resenas, compras e inventario.',
        'commerce' => 'Pedidos, checkout, configuracion comercial, POS, cotizaciones y clientes ecommerce.',
        'billing' => 'Facturacion SRI y documentos fiscales dentro de platform-core.',
        'mailer' => 'Salud, outbox y auditoria de entregas del correo interno.',
        'reporting-finance' => 'Reportes, periodos, gastos e inteligencia financiera y operativa.',
        'loyalty-rewards' => 'Programas, socios, puntos, canjes, reportes y Wallet de fidelizacion.',
    ];

    /**
     * @return list<array{
     *   method: string,
     *   path: string,
     *   handler: string,
     *   capability: string,
     *   module: string,
     *   tag: string,
     *   source: string
     * }>
     */
    public static function routeInventory(string $backendRoot): array
    {
        $backendRoot = rtrim($backendRoot, '/');
        $routeFiles = glob($backendRoot . '/src/Modules/*/routes.php') ?: [];
        sort($routeFiles, SORT_STRING);
        if ($routeFiles === []) {
            throw new \RuntimeException('No se encontraron registries src/Modules/*/routes.php.');
        }

        $inventory = [];
        $seen = [];
        foreach ($routeFiles as $routeFile) {
            $module = basename(dirname($routeFile));
            $tag = self::tagForModule($module);
            $registry = require $routeFile;
            if (!is_array($registry)) {
                throw new \RuntimeException(sprintf('%s no devuelve un array.', $routeFile));
            }

            foreach ($registry as $index => $route) {
                if (!is_array($route)) {
                    throw new \RuntimeException(sprintf('%s route #%d no es un array.', $routeFile, $index));
                }

                $errors = self::routeDefinitionErrors($route, $module);
                if ($errors !== []) {
                    throw new \RuntimeException(sprintf(
                        '%s route #%d invalida: %s',
                        $routeFile,
                        $index,
                        implode(' ', $errors)
                    ));
                }

                $method = (string)$route['method'];
                $path = (string)$route['path'];
                $key = self::routeKey($method, $path);
                if (isset($seen[$key])) {
                    throw new \RuntimeException(sprintf(
                        '%s duplica %s, ya declarado en %s.',
                        $routeFile,
                        $key,
                        $seen[$key]
                    ));
                }
                $seen[$key] = $routeFile;

                $inventory[] = [
                    'method' => $method,
                    'path' => $path,
                    'handler' => (string)$route['handler'],
                    'capability' => (string)$route['capability'],
                    'module' => $module,
                    'tag' => $tag,
                    'source' => self::relativeSource($backendRoot, $routeFile),
                ];
            }
        }

        return $inventory;
    }

    /** @return list<string> */
    public static function routeDefinitionErrors(array $route, string $module): array
    {
        $errors = [];
        foreach (['method', 'path', 'handler', 'capability'] as $field) {
            if (!array_key_exists($field, $route) || !is_string($route[$field]) || trim($route[$field]) === '') {
                $errors[] = sprintf('Falta %s no vacio.', $field);
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $method = (string)$route['method'];
        $path = (string)$route['path'];
        $handler = (string)$route['handler'];
        $capability = (string)$route['capability'];

        if (!in_array($method, self::HTTP_METHODS, true)) {
            $errors[] = sprintf('Metodo HTTP no soportado o no canonico: %s.', $method);
        }
        if (!str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
            $errors[] = sprintf('Path invalido: %s.', $path);
        }

        preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', $path, $placeholderMatches);
        $placeholders = $placeholderMatches[1] ?? [];
        $pathWithoutPlaceholders = preg_replace('/\{[A-Za-z][A-Za-z0-9_]*\}/', '', $path);
        if (is_string($pathWithoutPlaceholders) && (str_contains($pathWithoutPlaceholders, '{') || str_contains($pathWithoutPlaceholders, '}'))) {
            $errors[] = sprintf('Placeholder de path invalido: %s.', $path);
        }
        if (count($placeholders) !== count(array_unique($placeholders))) {
            $errors[] = sprintf('Placeholder repetido en path: %s.', $path);
        }

        if (substr_count($handler, '@') !== 1) {
            $errors[] = sprintf('Handler invalido: %s.', $handler);
            return $errors;
        }
        [$handlerClass, $handlerMethod] = explode('@', $handler, 2);
        $handlerClass = ltrim(trim($handlerClass), '\\');
        $handlerMethod = trim($handlerMethod);
        $expectedPrefix = 'App\\Modules\\' . $module . '\\Controllers\\';
        if (!str_starts_with($handlerClass, $expectedPrefix)) {
            $errors[] = sprintf('Handler fuera del bounded context %s: %s.', $module, $handlerClass);
        } elseif (!class_exists($handlerClass)) {
            $errors[] = sprintf('Clase handler inexistente: %s.', $handlerClass);
        } elseif ($handlerMethod === '' || !method_exists($handlerClass, $handlerMethod)) {
            $errors[] = sprintf('Metodo handler inexistente: %s@%s.', $handlerClass, $handlerMethod);
        } else {
            $reflection = new \ReflectionMethod($handlerClass, $handlerMethod);
            if (!$reflection->isPublic() || $reflection->isAbstract()) {
                $errors[] = sprintf('Metodo handler no invocable publicamente: %s@%s.', $handlerClass, $handlerMethod);
            }
            $pathParameterCount = count($placeholders);
            if (
                $reflection->getNumberOfRequiredParameters() > $pathParameterCount
                || $reflection->getNumberOfParameters() < $pathParameterCount
            ) {
                $errors[] = sprintf(
                    'Firma %s@%s incompatible con %d parametros de path.',
                    $handlerClass,
                    $handlerMethod,
                    $pathParameterCount
                );
            }
        }

        if (preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/', $capability) !== 1) {
            $errors[] = sprintf('Capability invalida: %s.', $capability);
        }

        return $errors;
    }

    public static function build(string $backendRoot): array
    {
        $inventory = self::routeInventory($backendRoot);
        $paths = [];
        $tags = [];
        foreach ($inventory as $route) {
            $tags[$route['tag']] = true;
            $operation = self::operation($route);
            $method = strtolower($route['method']);
            $publicPath = self::publicPathForRoute($route);
            if (isset($paths[$publicPath][$method])) {
                throw new \RuntimeException(sprintf('Operacion OpenAPI publica duplicada: %s %s.', $route['method'], $publicPath));
            }
            $paths[$publicPath][$method] = $operation;
        }
        ksort($paths, SORT_STRING);
        foreach ($paths as &$pathItem) {
            uksort($pathItem, static function (string $left, string $right): int {
                return array_search(strtoupper($left), self::HTTP_METHODS, true)
                    <=> array_search(strtoupper($right), self::HTTP_METHODS, true);
            });
        }
        unset($pathItem);

        $tagObjects = [];
        foreach (array_keys($tags) as $tag) {
            $tagObjects[] = [
                'name' => $tag,
                'description' => self::TAG_DESCRIPTIONS[$tag]
                    ?? sprintf('Bounded context modular %s.', $tag),
            ];
        }
        usort($tagObjects, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

        $document = [
            'openapi' => self::OPENAPI_VERSION,
            'jsonSchemaDialect' => self::JSON_SCHEMA_DIALECT,
            'info' => [
                'title' => 'Paramascotas Core API - inventario modular',
                'version' => '1.0.0',
                'description' => 'Contrato OpenAPI 3.1 generado desde todos los registries src/Modules/*/routes.php y proyectado exclusivamente sobre contratos publicos APISIX tenantizados. x-internal-path conserva la ruta real del backend sin publicarla como servidor. Las APIs publicas, externas y las mutaciones criticas de identidad, tenants, usuarios, catalogo, pedidos, Billing y Loyalty usan DTO semanticos trazables al runtime y verificados fail closed.',
            ],
            'servers' => [[
                'url' => 'https://{tenantHost}',
                'description' => 'Host publico tenant-aware servido exclusivamente por APISIX.',
                'variables' => [
                    'tenantHost' => [
                        'default' => 'paramascotasec.com',
                        'description' => 'Dominio publico registrado para el tenant en APISIX.',
                    ],
                ],
            ]],
            'tags' => $tagObjects,
            'paths' => $paths,
            'components' => self::components(),
            'x-route-inventory' => [
                'sourcePattern' => 'src/Modules/*/routes.php',
                'moduleCount' => count($tags),
                'operationCount' => count($inventory),
                'coverage' => 'complete',
                'pathProjection' => 'public-apisix-with-x-internal-path',
            ],
            'x-public-contract' => [
                'gateway' => 'APISIX',
                'tenantAware' => true,
                'legacyPublicPrefixesBlocked' => ['/api', '/facturador', '/uploads-api'],
                'backendInternalPathsPublishedAsServers' => false,
            ],
            'x-schema-coverage' => [
                'operationInventory' => 'complete',
                'pathParameters' => 'complete',
                'queryParameters' => 'conservative-static-handler-scan',
                'security' => 'inferred-from-runtime-policy',
                'requestDtos' => 'typed-for-public-external-critical-mutations',
                'responseDtos' => 'typed-for-public-external-critical-mutations',
                'businessDtoFieldsTyped' => 'required-surfaces-complete',
            ],
        ];
        $document['x-schema-coverage']['semantic'] = ModuleOpenApiSchemaCatalog::coverage($document, $inventory);

        return $document;
    }

    /** @return list<string> */
    public static function validationErrors(array $document, array $inventory): array
    {
        $errors = [];
        if (preg_match('/^3\.1\.\d+$/', (string)($document['openapi'] ?? '')) !== 1) {
            $errors[] = 'openapi debe declarar una version 3.1.x.';
        }
        if (($document['jsonSchemaDialect'] ?? null) !== self::JSON_SCHEMA_DIALECT) {
            $errors[] = 'jsonSchemaDialect debe usar JSON Schema 2020-12.';
        }
        if (!is_array($document['info'] ?? null)) {
            $errors[] = 'info es obligatorio.';
        } else {
            foreach (['title', 'version'] as $field) {
                if (!is_string($document['info'][$field] ?? null) || trim((string)$document['info'][$field]) === '') {
                    $errors[] = sprintf('info.%s es obligatorio.', $field);
                }
            }
        }
        if (!is_array($document['paths'] ?? null)) {
            $errors[] = 'paths debe ser un objeto.';
            return $errors;
        }
        self::validatePublicServers($document['servers'] ?? null, 'documento', $errors);

        $declaredTags = [];
        if (!is_array($document['tags'] ?? null)) {
            $errors[] = 'tags debe ser un array.';
        } else {
            foreach ($document['tags'] as $tag) {
                if (!is_array($tag) || !is_string($tag['name'] ?? null) || trim((string)$tag['name']) === '') {
                    $errors[] = 'Cada tag debe declarar name no vacio.';
                    continue;
                }
                $declaredTags[(string)$tag['name']] = true;
            }
        }

        $components = is_array($document['components'] ?? null) ? $document['components'] : [];
        $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        $securitySchemes = is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : [];
        if ($schemas === []) {
            $errors[] = 'components.schemas no puede estar vacio.';
        }
        if ($securitySchemes === []) {
            $errors[] = 'components.securitySchemes no puede estar vacio.';
        } else {
            foreach ($securitySchemes as $name => $scheme) {
                if (!is_array($scheme) || !is_string($scheme['type'] ?? null)) {
                    $errors[] = sprintf('Security scheme %s invalido.', $name);
                    continue;
                }
                if ($scheme['type'] === 'http' && !is_string($scheme['scheme'] ?? null)) {
                    $errors[] = sprintf('Security scheme HTTP %s no declara scheme.', $name);
                }
                if ($scheme['type'] === 'apiKey'
                    && (!in_array($scheme['in'] ?? null, ['header', 'query', 'cookie'], true)
                        || !is_string($scheme['name'] ?? null))) {
                    $errors[] = sprintf('Security scheme apiKey %s no declara in/name validos.', $name);
                }
            }
        }
        self::collectInvalidRefs($components, $document, 'components', $errors);

        $expected = [];
        foreach ($inventory as $route) {
            $expected[self::routeKey($route['method'], $route['path'])] = $route;
        }
        $actual = [];
        $operationIds = [];
        foreach ($document['paths'] as $path => $pathItem) {
            if (!is_string($path) || !str_starts_with($path, '/') || !is_array($pathItem)) {
                $errors[] = sprintf('Path OpenAPI invalido: %s.', is_string($path) ? $path : gettype($path));
                continue;
            }
            if (preg_match('#^/(?:api|facturador|uploads-api)(?:/|$)#', $path) === 1) {
                $errors[] = sprintf('El documento publica un prefijo legacy/bloqueado: %s.', $path);
            }
            foreach ($pathItem as $method => $operation) {
                $methodUpper = strtoupper((string)$method);
                if (!in_array($methodUpper, self::HTTP_METHODS, true)) {
                    $errors[] = sprintf('Operacion extra o metodo invalido: %s %s.', $methodUpper, $path);
                    continue;
                }
                if (!is_array($operation)) {
                    $errors[] = sprintf('%s %s no define un Operation Object.', $methodUpper, $path);
                    continue;
                }

                $internalPath = $operation['x-internal-path'] ?? null;
                $key = is_string($internalPath)
                    ? self::routeKey($methodUpper, $internalPath)
                    : self::routeKey($methodUpper, '[x-internal-path-ausente]');
                if (!is_string($internalPath) || !str_starts_with($internalPath, '/')) {
                    $errors[] = sprintf('%s %s no declara x-internal-path valido.', $methodUpper, $path);
                } elseif (isset($actual[$key])) {
                    $errors[] = sprintf('Dos operaciones publicas proyectan el mismo inventario interno %s.', $key);
                }
                $actual[$key] = true;

                $operationId = $operation['operationId'] ?? null;
                if (!is_string($operationId) || preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $operationId) !== 1) {
                    $errors[] = sprintf('%s tiene operationId invalido.', $key);
                } elseif (isset($operationIds[$operationId])) {
                    $errors[] = sprintf('operationId duplicado %s en %s y %s.', $operationId, $operationIds[$operationId], $key);
                } else {
                    $operationIds[$operationId] = $key;
                }

                if (!isset($expected[$key])) {
                    $errors[] = sprintf('El documento contiene operacion sobrante %s.', $key);
                } elseif (($operation['x-capability'] ?? null) !== $expected[$key]['capability']) {
                    $errors[] = sprintf('%s no conserva x-capability=%s.', $key, $expected[$key]['capability']);
                } elseif ($path !== self::publicPathForRoute($expected[$key])) {
                    $errors[] = sprintf('%s se proyecta fuera de su contrato APISIX canonico: %s.', $key, $path);
                }

                if (!in_array($operation['x-exposure'] ?? null, ['public', 'external', 'admin', 'tenant-session'], true)) {
                    $errors[] = sprintf('%s no declara x-exposure valido.', $key);
                }
                if (!is_string($operation['x-auth-surface'] ?? null) || trim((string)$operation['x-auth-surface']) === '') {
                    $errors[] = sprintf('%s no declara x-auth-surface.', $key);
                }
                if (isset($expected[$key])) {
                    $expectedSecurity = self::securityFor($expected[$key]);
                    if (($operation['x-exposure'] ?? null) !== $expectedSecurity['exposure']) {
                        $errors[] = sprintf('%s deriva x-exposure=%s.', $key, $expectedSecurity['exposure']);
                    }
                    if (($operation['x-auth-surface'] ?? null) !== $expectedSecurity['surface']) {
                        $errors[] = sprintf('%s deriva x-auth-surface=%s.', $key, $expectedSecurity['surface']);
                    }
                    if (($operation['security'] ?? null) !== $expectedSecurity['requirements']) {
                        $errors[] = sprintf('%s deriva sus Security Requirement Objects.', $key);
                    }
                    if (($operation['x-security-policy']['classification'] ?? null) !== $expectedSecurity['classification']) {
                        $errors[] = sprintf('%s deriva x-security-policy.classification.', $key);
                    }
                }
                if (($operation['x-exposure'] ?? null) === 'admin') {
                    self::validatePublicServers($operation['servers'] ?? null, $key, $errors);
                } elseif (isset($operation['servers'])) {
                    $errors[] = sprintf('%s no debe sobreescribir servers fuera de la superficie admin.', $key);
                }

                $operationTags = $operation['tags'] ?? null;
                if (!is_array($operationTags) || count($operationTags) !== 1 || !is_string($operationTags[0] ?? null)) {
                    $errors[] = sprintf('%s debe declarar exactamente un tag de bounded context.', $key);
                } elseif (!isset($declaredTags[$operationTags[0]])) {
                    $errors[] = sprintf('%s usa tag no declarado %s.', $key, $operationTags[0]);
                } elseif (isset($expected[$key]) && $operationTags[0] !== $expected[$key]['tag']) {
                    $errors[] = sprintf('%s usa tag incorrecto %s.', $key, $operationTags[0]);
                }

                self::validateParameters($path, $key, $operation['parameters'] ?? [], $errors);
                self::validateSecurity($key, $operation['security'] ?? null, $securitySchemes, $errors);
                self::validateResponses($key, $operation['responses'] ?? null, $schemas, $errors);
                self::collectInvalidRefs($operation, $document, $key, $errors);
            }
        }

        foreach (array_diff_key($expected, $actual) as $key => $_route) {
            $errors[] = sprintf('El documento OpenAPI omite la ruta registrada %s.', $key);
        }

        if (!is_array($document['x-route-inventory'] ?? null)) {
            $errors[] = 'Falta x-route-inventory.';
        } elseif (($document['x-route-inventory']['operationCount'] ?? null) !== count($inventory)) {
            $errors[] = 'x-route-inventory.operationCount no coincide con el inventario.';
        }
        $schemaCoverage = $document['x-schema-coverage'] ?? null;
        if (!is_array($schemaCoverage) || ($schemaCoverage['businessDtoFieldsTyped'] ?? null) !== 'required-surfaces-complete') {
            $errors[] = 'x-schema-coverage debe declarar businessDtoFieldsTyped=required-surfaces-complete.';
        }
        $errors = array_merge($errors, ModuleOpenApiSchemaCatalog::validationErrors($document, $inventory));

        try {
            $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                $errors[] = 'El documento no completa un round-trip JSON valido.';
            }
        } catch (\JsonException $exception) {
            $errors[] = 'El documento no es JSON serializable: ' . $exception->getMessage();
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    public static function aggregatedRegistryErrors(string $backendRoot, array $inventory): array
    {
        $aggregatePath = rtrim($backendRoot, '/') . '/config/routes.php';
        $aggregated = require $aggregatePath;
        if (!is_array($aggregated)) {
            return ['config/routes.php no devuelve un array.'];
        }

        $expected = [];
        foreach ($inventory as $route) {
            $expected[self::routeKey($route['method'], $route['path'])] = [
                'handler' => $route['handler'],
                'capability' => $route['capability'],
            ];
        }
        $actual = [];
        $errors = [];
        foreach ($aggregated as $index => $route) {
            if (!is_array($route)) {
                $errors[] = sprintf('config/routes.php route #%d no es array.', $index);
                continue;
            }
            foreach (['method', 'path', 'handler', 'capability'] as $field) {
                if (!is_string($route[$field] ?? null) || trim((string)$route[$field]) === '') {
                    $errors[] = sprintf('config/routes.php route #%d no declara %s.', $index, $field);
                }
            }
            if ($errors !== [] && (!isset($route['method']) || !isset($route['path']))) {
                continue;
            }
            $key = self::routeKey((string)($route['method'] ?? ''), (string)($route['path'] ?? ''));
            if (isset($actual[$key])) {
                $errors[] = sprintf('config/routes.php duplica %s.', $key);
                continue;
            }
            $actual[$key] = [
                'handler' => (string)($route['handler'] ?? ''),
                'capability' => (string)($route['capability'] ?? ''),
            ];
        }

        foreach (array_diff_key($expected, $actual) as $key => $_definition) {
            $errors[] = sprintf('config/routes.php omite %s.', $key);
        }
        foreach (array_diff_key($actual, $expected) as $key => $_definition) {
            $errors[] = sprintf('config/routes.php agrega una ruta no modular: %s.', $key);
        }
        foreach (array_intersect_key($expected, $actual) as $key => $definition) {
            if ($actual[$key] !== $definition) {
                $errors[] = sprintf('config/routes.php deriva handler/capability de %s.', $key);
            }
        }

        return $errors;
    }

    private static function operation(array $route): array
    {
        $security = self::securityFor($route);
        $semanticContract = ModuleOpenApiSchemaCatalog::contractFor($route, $security['exposure']);
        $queryParameters = self::queryParameters($route['handler']);
        $publicPath = self::publicPathForRoute($route);
        $parameters = array_merge(
            self::pathParameters($publicPath),
            $queryParameters,
            self::tenantRegistryMutationParameters($route)
        );
        $operation = [
            'tags' => [$route['tag']],
            'summary' => sprintf('%s %s', $route['method'], $publicPath),
            'description' => $semanticContract['required']
                ? 'Operacion registrada en el bounded context con DTO semantico trazable al runtime.'
                : 'Operacion registrada en el bounded context. El inventario HTTP es completo; esta proyeccion administrativa no critica conserva un envelope de transporte conservador.',
            'operationId' => self::operationId($route['tag'], $route['method'], $route['path']),
            'parameters' => $parameters,
            'security' => $security['requirements'],
            'responses' => self::responsesFor($route, $semanticContract),
            'x-capability' => $route['capability'],
            'x-route-source' => $route['source'],
            'x-internal-path' => $route['path'],
            'x-exposure' => $security['exposure'],
            'x-auth-surface' => $security['surface'],
            'x-contract-coverage' => [
                'operation' => 'complete',
                'businessDto' => $semanticContract['required'] ? 'typed' : 'untyped',
                'queryParameters' => 'conservative',
                'schemaSource' => $semanticContract['source'],
            ],
            'x-security-policy' => [
                'classification' => $security['classification'],
                'basis' => $security['basis'],
                'notes' => $security['notes'],
            ],
            'x-query-coverage' => [
                'strategy' => 'conservative-static-handler-scan',
                'inferredParameterCount' => count($queryParameters),
            ],
        ];

        if ($security['exposure'] === 'admin') {
            $operation['servers'] = [[
                'url' => 'https://{dashboardHost}',
                'description' => 'Host de administracion tenant-aware servido por APISIX; no es el upstream dashboard directo.',
                'variables' => [
                    'dashboardHost' => [
                        'default' => 'admin.paramascotasec.com',
                        'description' => 'Dominio dashboard/admin registrado para el tenant en APISIX.',
                    ],
                ],
            ]];
        }
        if (str_starts_with((string)$route['path'], '/api/auth/')) {
            $operation['x-alternate-apisix-contracts'] = [[
                'server' => 'https://{dashboardHost}',
                'path' => '/{dashboardSegment}/api' . substr((string)$route['path'], strlen('/api')),
                'authSurface' => 'dashboard',
                'notes' => 'Mismo handler, pero APISIX fija la superficie dashboard y el backend usa cookies dashboard aisladas.',
            ]];
        }
        if (str_starts_with((string)$route['path'], '/api/{apiMode}/v1/')) {
            $operation['x-internal-parameter-map'] = [
                'billingEnvironment' => 'apiMode',
            ];
        }

        $requestBody = self::requestBodyFor($route, $semanticContract);
        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        return $operation;
    }

    private static function components(): array
    {
        $components = [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'JWT tenant-aware para operaciones de usuario o administracion.',
                ],
                'billingApiKey' => [
                    'type' => 'apiKey',
                    'in' => 'header',
                    'name' => 'X-API-Key',
                    'description' => 'Credencial opaca de integracion para el contrato publico Billing.',
                ],
                'billingBearerCredential' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'description' => 'Credencial Billing opaca alternativa a X-API-Key; no implica JWT de sesion.',
                ],
                'loyaltyApiKey' => [
                    'type' => 'apiKey',
                    'in' => 'header',
                    'name' => 'X-API-Key',
                    'description' => 'Credencial opaca de integracion con scopes para el contrato publico Loyalty.',
                ],
                'loyaltyBearerCredential' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'description' => 'Credencial Loyalty opaca alternativa a X-API-Key; no implica JWT de sesion.',
                ],
                'dashboardSessionCookie' => [
                    'type' => 'apiKey',
                    'in' => 'cookie',
                    'name' => 'pm_auth_dashboard',
                    'description' => 'Nombre por defecto; AUTH_COOKIE_NAME puede cambiar la base en runtime.',
                ],
                'ecommerceSessionCookie' => [
                    'type' => 'apiKey',
                    'in' => 'cookie',
                    'name' => 'pm_auth_ecommerce',
                    'description' => 'Nombre por defecto; AUTH_COOKIE_NAME puede cambiar la base en runtime.',
                ],
                'csrfToken' => [
                    'type' => 'apiKey',
                    'in' => 'header',
                    'name' => 'X-CSRF-Token',
                    'description' => 'Requerido para mutaciones autenticadas mediante cookie; debe coincidir con la cookie CSRF de la superficie.',
                ],
            ],
            'schemas' => [
                'UntypedBusinessData' => [
                    'description' => 'Payload de negocio deliberadamente no tipado. No implica campos ni estabilidad de DTO.',
                ],
                'CoreSuccessEnvelope' => [
                    'type' => 'object',
                    'required' => ['ok', 'data'],
                    'properties' => [
                        'ok' => ['type' => 'boolean', 'const' => true],
                        'data' => ['$ref' => '#/components/schemas/UntypedBusinessData'],
                        'meta' => ['type' => 'object', 'additionalProperties' => true],
                        'message' => ['type' => 'string'],
                    ],
                    'additionalProperties' => true,
                ],
                'BillingSuccessEnvelope' => [
                    'type' => 'object',
                    'required' => ['success', 'data'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'const' => true],
                        'data' => ['$ref' => '#/components/schemas/UntypedBusinessData'],
                    ],
                    'additionalProperties' => true,
                ],
                'StandardSuccessEnvelope' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/CoreSuccessEnvelope'],
                        ['$ref' => '#/components/schemas/BillingSuccessEnvelope'],
                    ],
                ],
                'ErrorDetail' => [
                    'type' => 'object',
                    'required' => ['message'],
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'code' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                        'status_code' => ['type' => 'integer', 'minimum' => 400, 'maximum' => 599],
                        'details' => ['$ref' => '#/components/schemas/UntypedBusinessData'],
                    ],
                    'additionalProperties' => true,
                ],
                'CoreErrorEnvelope' => [
                    'type' => 'object',
                    'required' => ['ok', 'error'],
                    'properties' => [
                        'ok' => ['type' => 'boolean', 'const' => false],
                        'error' => ['$ref' => '#/components/schemas/ErrorDetail'],
                    ],
                    'additionalProperties' => false,
                ],
                'BillingErrorEnvelope' => [
                    'type' => 'object',
                    'required' => ['success', 'error'],
                    'properties' => [
                        'success' => ['type' => 'boolean', 'const' => false],
                        'error' => ['$ref' => '#/components/schemas/ErrorDetail'],
                    ],
                    'additionalProperties' => false,
                ],
                'StandardErrorEnvelope' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/CoreErrorEnvelope'],
                        ['$ref' => '#/components/schemas/BillingErrorEnvelope'],
                    ],
                ],
                'GenericRequestObject' => [
                    'type' => 'object',
                    'description' => 'Request de negocio no tipado; consultar el contrato del bounded context antes de integrar.',
                    'additionalProperties' => true,
                ],
                'BinaryPayload' => [
                    'type' => 'string',
                    'format' => 'binary',
                ],
            ],
            'responses' => [
                'StandardError' => [
                    'description' => 'Error estandar del Core o de la frontera Billing.',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/StandardErrorEnvelope'],
                        ],
                    ],
                ],
            ],
        ];
        $components['schemas'] = array_merge(
            $components['schemas'],
            [
                'StringList' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'LoyaltyRiskEvent' => [
                    'type' => 'object',
                    'required' => ['id', 'severity', 'event_type', 'status', 'message', 'created_at'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'severity' => ['type' => 'string'],
                        'event_type' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'member_id' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                        'reference' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                        'message' => ['type' => 'string'],
                        'metadata' => ['type' => 'object', 'additionalProperties' => true],
                        'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        'resolved_at' => ['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]],
                        'resolved_by_user_id' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                        'resolution_note' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            ModuleOpenApiSchemaCatalog::schemas()
        );

        return $components;
    }

    private static function responsesFor(array $route, array $semanticContract): array
    {
        $responses = [
            '2XX' => [
                'description' => $semanticContract['required']
                    ? 'Operacion procesada conforme al DTO semantico publicado.'
                    : 'Operacion procesada. Proyeccion administrativa conservadora.',
            ],
            '400' => ['$ref' => '#/components/responses/StandardError'],
            '403' => ['$ref' => '#/components/responses/StandardError'],
            '404' => ['$ref' => '#/components/responses/StandardError'],
            '500' => ['$ref' => '#/components/responses/StandardError'],
        ];

        if ($route['method'] !== 'HEAD') {
            $responses['2XX']['content'] = self::successContentFor($route, $semanticContract);
        }
        if ((string)$route['path'] === '/api/admin/settings/tax') {
            $responses['2XX']['headers'] = [
                'ETag' => [
                    'description' => 'Revisión fuerte de la política fiscal canónica; usarla en If-Match.',
                    'schema' => ['type' => 'string', 'pattern' => '^"tenant-tax-[1-9][0-9]*"$'],
                ],
                'X-Tenant-Registry-Revision' => [
                    'description' => 'Revisión numérica canónica incluida también en data/meta.',
                    'schema' => ['type' => 'integer', 'minimum' => 1],
                ],
            ];
            if ((string)$route['method'] === 'PUT') {
                $responses['412'] = ['$ref' => '#/components/responses/StandardError'];
                $responses['428'] = ['$ref' => '#/components/responses/StandardError'];
            }
        }
        $security = self::securityFor($route);
        if ($security['classification'] !== 'public') {
            $responses['401'] = ['$ref' => '#/components/responses/StandardError'];
        }
        if ($security['classification'] === 'external-api-credential') {
            $responses['409'] = ['$ref' => '#/components/responses/StandardError'];
            $responses['422'] = ['$ref' => '#/components/responses/StandardError'];
            $responses['429'] = ['$ref' => '#/components/responses/StandardError'];
        }
        if (str_starts_with((string)$route['path'], '/api/admin/tenants')
            && in_array((string)$route['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $responses['409'] = ['$ref' => '#/components/responses/StandardError'];
            $responses['428'] = ['$ref' => '#/components/responses/StandardError'];
        }
        ksort($responses, SORT_STRING);

        return $responses;
    }

    private static function successContentFor(array $route, array $semanticContract): array
    {
        $semanticMode = (string)($semanticContract['responseMode'] ?? 'generic');
        $semanticSchema = $semanticContract['responseSchema'] ?? null;
        if ($semanticMode === 'head') {
            return [];
        }
        if ($semanticMode === 'html') {
            return ['text/html' => ['schema' => ['type' => 'string']]];
        }
        if ($semanticMode === 'xml') {
            return ['application/xml' => ['schema' => ['type' => 'string']]];
        }
        if ($semanticMode === 'pdf') {
            return ['application/pdf' => ['schema' => ['$ref' => '#/components/schemas/BinaryPayload']]];
        }
        if ($semanticMode === 'binary') {
            return ['application/octet-stream' => ['schema' => ['$ref' => '#/components/schemas/BinaryPayload']]];
        }
        if ($semanticMode === 'invoice-document') {
            return [
                'text/html' => ['schema' => ['type' => 'string']],
                'application/pdf' => ['schema' => ['$ref' => '#/components/schemas/BinaryPayload']],
            ];
        }
        if (is_string($semanticSchema) && in_array($semanticMode, ['core-json', 'billing-json'], true)) {
            return [
                'application/json' => [
                    'schema' => self::typedSuccessEnvelope($semanticSchema, $semanticMode === 'billing-json'),
                ],
            ];
        }

        $handlerMethod = strtolower(substr((string)$route['handler'], strrpos((string)$route['handler'], '@') + 1));
        $path = (string)$route['path'];
        $binarySchema = ['$ref' => '#/components/schemas/BinaryPayload'];
        if ($handlerMethod === 'xml' || str_ends_with($path, '/xml')) {
            return ['application/xml' => ['schema' => ['type' => 'string']]];
        }
        if (in_array($handlerMethod, ['ridepdf', 'pdf'], true) || str_ends_with($path, '.pdf') || str_ends_with($path, '/pdf')) {
            return ['application/pdf' => ['schema' => $binarySchema]];
        }
        if ($handlerMethod === 'invoice') {
            return [
                'text/html' => ['schema' => ['type' => 'string']],
                'application/pdf' => ['schema' => $binarySchema],
            ];
        }
        if ($handlerMethod === 'exportreport') {
            return [
                'text/csv' => ['schema' => ['type' => 'string']],
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['schema' => $binarySchema],
            ];
        }
        if ($handlerMethod === 'rewardimage') {
            return ['application/octet-stream' => ['schema' => $binarySchema]];
        }
        if (in_array($handlerMethod, [
            'publicgooglewalletlanding',
            'publiccatalog',
            'publicrewardsaccess',
            'publicrewardsportal',
            'publicrewardsportalsession',
        ], true)) {
            return ['text/html' => ['schema' => ['type' => 'string']]];
        }

        return [
            'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/StandardSuccessEnvelope'],
            ],
        ];
    }

    private static function requestBodyFor(array $route, array $semanticContract): ?array
    {
        $semanticSchema = $semanticContract['requestSchema'] ?? null;
        if (is_string($semanticSchema)) {
            $content = [];
            foreach ($semanticContract['requestMediaTypes'] as $mediaType) {
                $content[$mediaType] = [
                    'schema' => ['$ref' => '#/components/schemas/' . $semanticSchema],
                ];
            }
            return [
                'required' => (bool)$semanticContract['requestRequired'],
                'description' => 'DTO semantico derivado del handler, repositorio y consumidores versionados del bounded context.',
                'content' => $content,
                'x-dto-coverage' => 'typed',
                'x-schema-source' => 'runtime-derived-semantic-catalog',
            ];
        }

        // A required semantic surface without a mapped body is a genuine
        // no-body operation. Never let the conservative static scanner attach
        // GenericRequestObject to public, external or critical operations.
        if (($semanticContract['required'] ?? false) === true) {
            return null;
        }

        if (!in_array($route['method'], ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        $source = self::handlerSource((string)$route['handler']);
        $hasFiles = str_contains($source, '$_FILES');
        $hasForms = str_contains($source, '$_POST');
        $hasJson = str_contains($source, 'php://input')
            || str_contains($source, 'jsonPayload(')
            || str_contains($source, 'jsonBody(')
            || str_contains($source, 'json_decode(');
        if (!$hasFiles && !$hasForms && !$hasJson) {
            return null;
        }

        $content = [];
        if ($hasJson) {
            $content['application/json'] = [
                'schema' => ['$ref' => '#/components/schemas/GenericRequestObject'],
            ];
        }
        if ($hasFiles) {
            $content['multipart/form-data'] = [
                'schema' => ['$ref' => '#/components/schemas/GenericRequestObject'],
            ];
        } elseif ($hasForms) {
            $content['application/x-www-form-urlencoded'] = [
                'schema' => ['$ref' => '#/components/schemas/GenericRequestObject'],
            ];
        }

        return [
            'required' => false,
            'description' => 'Payload aceptado por el handler. Esquema de transporte generico; DTO de negocio aun no tipado.',
            'content' => $content,
            'x-dto-coverage' => 'untyped',
        ];
    }

    private static function typedSuccessEnvelope(string $dataSchema, bool $billing): array
    {
        return [
            'type' => 'object',
            'required' => [$billing ? 'success' : 'ok', 'data'],
            'properties' => [
                $billing ? 'success' : 'ok' => ['type' => 'boolean', 'const' => true],
                'data' => ['$ref' => '#/components/schemas/' . $dataSchema],
                ...($billing ? [] : [
                    'meta' => ['type' => 'object', 'additionalProperties' => true],
                    'message' => ['type' => 'string'],
                ]),
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Projects an internal backend route onto its canonical APISIX contract.
     * The internal path remains available only as x-internal-path.
     */
    public static function publicPathForRoute(array $route): string
    {
        $path = (string)$route['path'];
        if ($path === '/health') {
            return '/{tenantSlug}/{billingSegment}/health';
        }
        if (preg_match('#^/api/\{apiMode\}/v1(?:/(.*))?$#', $path, $matches) === 1) {
            $suffix = trim((string)($matches[1] ?? ''), '/');
            return '/{tenantSlug}/{billingSegment}/{billingEnvironment}/v1'
                . ($suffix !== '' ? '/' . $suffix : '');
        }
        if ($path === '/api/loyalty/v1/health') {
            return '/{tenantSlug}/{loyaltySegment}/health';
        }
        if (str_starts_with($path, '/api/loyalty/v1/')) {
            return '/{tenantSlug}/{loyaltySegment}/v1/' . substr($path, strlen('/api/loyalty/v1/'));
        }
        if (!str_starts_with($path, '/api')) {
            throw new \RuntimeException(sprintf('Ruta sin proyeccion APISIX canonica: %s.', $path));
        }

        $suffix = substr($path, strlen('/api'));
        if (self::isAdminRuntimeRoute($path, (string)$route['method'])) {
            return '/{dashboardSegment}/api' . $suffix;
        }

        return '/{tenantSlug}/{apiSegment}' . $suffix;
    }

    /** @return list<array<string, mixed>> */
    private static function pathParameters(string $path): array
    {
        preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', $path, $matches);
        $parameters = [];
        foreach ($matches[1] ?? [] as $name) {
            $schema = ['type' => 'string'];
            if ($name === 'billingEnvironment') {
                $schema['enum'] = ['test', 'production'];
            }
            $deploymentParameter = in_array($name, [
                'tenantSlug',
                'apiSegment',
                'dashboardSegment',
                'billingSegment',
                'billingEnvironment',
                'loyaltySegment',
            ], true);
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'description' => $deploymentParameter
                    ? 'Segmento publico tenant-aware configurado y registrado por APISIX.'
                    : 'Parametro de path registrado por el Router; semantica detallada pertenece al bounded context.',
                'schema' => $schema,
                'x-origin' => $deploymentParameter ? 'apisix-contract' : 'backend-route-registry',
            ];
        }

        return $parameters;
    }

    /** @return list<array<string, mixed>> */
    private static function queryParameters(string $handler): array
    {
        $source = self::handlerSource($handler);
        preg_match_all('/\$_GET\s*\[\s*[\'\"]([A-Za-z][A-Za-z0-9_.-]*)[\'\"]\s*\]/', $source, $matches);
        $names = array_values(array_unique($matches[1] ?? []));
        sort($names, SORT_STRING);

        $integerNames = ['limit', 'offset', 'page', 'pageSize', 'page_size', 'year', 'window_days', 'target_days'];
        $booleanNames = ['includeCancelled', 'include_cancelled', 'include_report', 'paged', 'procurementDetail', 'procurement_detail', 'saleOnly', 'sale_only'];
        $parameters = [];
        foreach ($names as $name) {
            $schema = ['type' => 'string'];
            if (in_array($name, $integerNames, true)) {
                $schema = ['type' => 'integer'];
            } elseif (in_array($name, $booleanNames, true)) {
                $schema = ['type' => 'boolean'];
            }
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => false,
                'description' => 'Parametro opcional detectado por analisis estatico conservador del handler; no implica un DTO de negocio tipado.',
                'schema' => $schema,
                'x-inferred-from' => 'handler-source',
            ];
        }

        return $parameters;
    }

    /** @return list<array<string,mixed>> */
    private static function tenantRegistryMutationParameters(array $route): array
    {
        $path = (string)$route['path'];
        $method = (string)$route['method'];
        if ($path === '/api/admin/settings/tax' && $method === 'PUT') {
            return [[
                'name' => 'If-Match',
                'in' => 'header',
                'required' => true,
                'description' => 'ETag fuerte tenant-tax obtenido en GET; evita lost updates de la politica fiscal atomica.',
                'schema' => [
                    'type' => 'string',
                    'pattern' => '^"tenant-tax-[1-9][0-9]*"$',
                    'example' => '"tenant-tax-1720000000000000"',
                ],
                'x-concurrency-control' => 'optimistic-cas',
            ]];
        }
        if (!str_starts_with($path, '/api/admin/tenants')
            || !in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return [];
        }

        return [
            [
                'name' => 'If-Match',
                'in' => 'header',
                'required' => true,
                'description' => 'ETag fuerte del TenantRuntimeRegistry obtenido en la lectura anterior; evita lost updates.',
                'schema' => [
                    'type' => 'string',
                    'pattern' => '^"tenant-registry-[1-9][0-9]*"$',
                    'example' => '"tenant-registry-1720000000000000"',
                ],
                'x-concurrency-control' => 'optimistic-cas',
            ],
            [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => true,
                'description' => 'Identificador unico de la intencion; reintentos identicos no vuelven a mutar el registro.',
                'schema' => [
                    'type' => 'string',
                    'minLength' => 8,
                    'maxLength' => 128,
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$',
                ],
                'x-idempotency-boundary' => 'tenant-runtime-registry-journal',
            ],
        ];
    }

    /** @return array{requirements: array, classification: string, basis: string, notes: string, exposure: string, surface: string} */
    private static function securityFor(array $route): array
    {
        $method = (string)$route['method'];
        $path = (string)$route['path'];
        $mutating = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if ($path === '/health' || (in_array($path, ['/api/health', '/api/livez', '/api/readyz'], true) && in_array($method, ['GET', 'HEAD'], true))) {
            return self::publicSecurity('Health publico registrado fuera del auth global.');
        }
        if ($path === '/api/auth/verify' && in_array($method, ['GET', 'HEAD'], true)) {
            return self::publicSecurity('Verificacion publica mediante token opaco de un solo uso.');
        }
        if ($path === '/api/auth/session' && in_array($method, ['GET', 'HEAD'], true)) {
            return [
                'requirements' => [
                    ['bearerAuth' => []],
                    ['dashboardSessionCookie' => []],
                    ['ecommerceSessionCookie' => []],
                ],
                'classification' => 'tenant-user-session',
                'basis' => 'AuthController::session llama Auth::requireUser',
                'notes' => 'La sesion es obligatoria; acepta Bearer o la cookie aislada de dashboard/ecommerce.',
                'exposure' => 'tenant-session',
                'surface' => 'ecommerce-or-dashboard',
            ];
        }
        if (preg_match('#^/api/\{apiMode\}/v1/#', $path) === 1) {
            return [
                'requirements' => [
                    ['billingApiKey' => []],
                    ['billingBearerCredential' => []],
                ],
                'classification' => 'external-api-credential',
                'basis' => 'PublicBillingController::rawApiKey',
                'notes' => 'X-API-Key o Bearer opaco. No es una sesion JWT de dashboard/ecommerce.',
                'exposure' => 'external',
                'surface' => 'billing-api',
            ];
        }
        if (str_starts_with($path, '/api/loyalty/v1/')) {
            if ($path === '/api/loyalty/v1/health' || str_starts_with($path, '/api/loyalty/v1/wallet/google/')) {
                return self::publicSecurity('Health o landing con token opaco validado por Loyalty.');
            }

            return [
                'requirements' => [
                    ['loyaltyApiKey' => []],
                    ['loyaltyBearerCredential' => []],
                ],
                'classification' => 'external-api-credential',
                'basis' => 'LoyaltyController::externalClient',
                'notes' => 'X-API-Key o Bearer opaco con scopes; mutaciones POS pueden exigir firma e idempotencia adicionales.',
                'exposure' => 'external',
                'surface' => 'loyalty-api',
            ];
        }
        if (self::isAnonymousRuntimeRoute($method, $path)) {
            $public = self::publicSecurity('Ruta incluida en la allowlist publica de public/index.php o protegida por token/sesion propios del bounded context.');
            if (str_starts_with($path, '/api/auth/')) {
                $public['surface'] = 'ecommerce';
            }
            return $public;
        }

        $admin = self::isAdminRuntimeRoute($path, $method);
        $cookieScheme = $admin ? 'dashboardSessionCookie' : 'ecommerceSessionCookie';
        $cookieRequirement = [$cookieScheme => []];
        if ($mutating) {
            $cookieRequirement['csrfToken'] = [];
        }
        $requirements = [
            ['bearerAuth' => []],
            $cookieRequirement,
        ];
        $surface = $admin ? 'dashboard' : 'ecommerce';
        if (str_starts_with($path, '/api/auth/')) {
            $dashboardCookieRequirement = ['dashboardSessionCookie' => []];
            if ($mutating) {
                $dashboardCookieRequirement['csrfToken'] = [];
            }
            $requirements[] = $dashboardCookieRequirement;
            $surface = 'ecommerce-or-dashboard';
        }

        return [
            'requirements' => $requirements,
            'classification' => $admin ? 'tenant-admin-session' : 'tenant-user-session',
            'basis' => 'public/index.php global auth, AuthSurface and TenantAccessService',
            'notes' => $admin
                ? 'Ademas de auth, runtime aplica tenant, identidad administrada, capability/RBAC y allowlist IP cuando esta configurada.'
                : 'Runtime valida tenant y superficie. Con cookie, las mutaciones exigen X-CSRF-Token coincidente.',
            'exposure' => $admin ? 'admin' : 'tenant-session',
            'surface' => $surface,
        ];
    }

    private static function publicSecurity(string $notes): array
    {
        return [
            'requirements' => [],
            'classification' => 'public',
            'basis' => 'public/index.php public request policy',
            'notes' => $notes,
            'exposure' => 'public',
            'surface' => 'public',
        ];
    }

    private static function isAnonymousRuntimeRoute(string $method, string $path): bool
    {
        if (in_array($method, ['GET', 'HEAD'], true)) {
            if (in_array($path, [
                '/api/settings/shipping',
                '/api/settings/store-status',
                '/api/settings/brand-logos',
                '/api/settings/product-categories',
                '/api/settings/product-category-references',
            ], true)) {
                return true;
            }
            if ($path === '/api/products'
                || $path === '/api/products/{id}'
                || $path === '/api/products/{id}/reviews') {
                return true;
            }
            foreach (['/api/l/w/', '/api/l/c/', '/api/l/r/', '/api/l/reward-images/'] as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return true;
                }
            }
            if (str_starts_with($path, '/api/l/access') || str_starts_with($path, '/api/l/portal')) {
                return true;
            }
        }
        if ($method === 'POST') {
            if (in_array($path, [
                '/api/auth/login',
                '/api/auth/register',
                '/api/auth/request-otp',
                '/api/auth/verify-otp',
                '/api/auth/access-requests',
                '/api/auth/password-reset/request',
                '/api/auth/password-reset/confirm',
                '/api/orders/quote',
                '/api/contact',
                '/api/security/csp-report',
            ], true)) {
                return true;
            }
            if (str_starts_with($path, '/api/l/access') || str_starts_with($path, '/api/l/portal')) {
                return true;
            }
        }

        return false;
    }

    private static function isAdminRuntimeRoute(string $path, string $method = 'GET'): bool
    {
        $method = strtoupper($method);
        $productAdminOperation = $path === '/api/products/{id}/movement'
            || ($path === '/api/products' && $method === 'POST')
            || ($path === '/api/products/{id}' && in_array($method, ['PUT', 'PATCH', 'DELETE'], true));

        return $productAdminOperation
            || str_starts_with($path, '/api/admin/')
            || str_starts_with($path, '/api/reports/')
            || $path === '/api/users'
            || str_starts_with($path, '/api/users/')
            || $path === '/api/roles'
            || str_starts_with($path, '/api/roles/')
            || $path === '/api/access/audit'
            || $path === '/api/shipments';
    }

    private static function handlerSource(string $handler): string
    {
        if (substr_count($handler, '@') !== 1) {
            return '';
        }
        [$class, $method] = explode('@', $handler, 2);
        if (!class_exists($class) || !method_exists($class, $method)) {
            return '';
        }

        return self::methodSource(new \ReflectionMethod($class, $method), [], 0);
    }

    /** @param array<string, true> $visited */
    private static function methodSource(\ReflectionMethod $method, array $visited, int $depth): string
    {
        if ($depth > 8) {
            return '';
        }
        $key = $method->getDeclaringClass()->getName() . '::' . $method->getName();
        if (isset($visited[$key])) {
            return '';
        }
        $visited[$key] = true;

        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if (!is_string($file) || $start === false || $end === false || !is_readable($file)) {
            return '';
        }
        $lines = file($file);
        if (!is_array($lines)) {
            return '';
        }
        $source = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $calls);
        $declaringClass = $method->getDeclaringClass();
        foreach (array_unique($calls[1] ?? []) as $calledMethod) {
            if (!$declaringClass->hasMethod($calledMethod)) {
                continue;
            }
            $source .= "\n" . self::methodSource($declaringClass->getMethod($calledMethod), $visited, $depth + 1);
        }

        return $source;
    }

    private static function operationId(string $tag, string $method, string $path): string
    {
        $tokens = [];
        foreach (array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== '')) as $part) {
            if (preg_match('/^\{([A-Za-z][A-Za-z0-9_]*)\}$/', $part, $matches) === 1) {
                $tokens[] = 'by-' . self::kebab($matches[1]);
            } else {
                $tokens[] = self::kebab($part);
            }
        }
        $suffix = $tokens === [] ? 'root' : implode('-', $tokens);

        return $tag . '.' . strtolower($method) . '.' . $suffix;
    }

    private static function tagForModule(string $module): string
    {
        return self::kebab($module);
    }

    private static function kebab(string $value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? $value;

        return strtolower(trim($value, '-'));
    }

    private static function routeKey(string $method, string $path): string
    {
        return strtoupper($method) . ' ' . $path;
    }

    private static function relativeSource(string $backendRoot, string $source): string
    {
        $prefix = rtrim($backendRoot, '/') . '/';

        return str_starts_with($source, $prefix) ? substr($source, strlen($prefix)) : $source;
    }

    /** @param list<string> $errors */
    private static function validatePublicServers(mixed $servers, string $context, array &$errors): void
    {
        if (!is_array($servers) || $servers === []) {
            $errors[] = sprintf('%s debe declarar al menos un server APISIX publico.', $context);
            return;
        }
        foreach ($servers as $server) {
            $url = is_array($server) ? ($server['url'] ?? null) : null;
            if (!is_string($url) || !str_starts_with($url, 'https://')) {
                $errors[] = sprintf('%s contiene server no HTTPS.', $context);
                continue;
            }
            $lower = strtolower($url);
            foreach (['backend-http', 'localhost', '127.0.0.1', ':8080', ':9000'] as $internalMarker) {
                if (str_contains($lower, $internalMarker)) {
                    $errors[] = sprintf('%s publica un server interno: %s.', $context, $url);
                }
            }
            if (!str_contains($url, '{tenantHost}') && !str_contains($url, '{dashboardHost}')) {
                $errors[] = sprintf('%s debe usar un host APISIX tenant-aware.', $context);
            }
        }
    }

    /** @param list<string> $errors */
    private static function validateParameters(string $path, string $key, mixed $parameters, array &$errors): void
    {
        if (!is_array($parameters)) {
            $errors[] = sprintf('%s parameters debe ser array.', $key);
            return;
        }
        preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', $path, $matches);
        $expectedPathNames = array_values($matches[1] ?? []);
        $actualPathNames = [];
        $seen = [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                $errors[] = sprintf('%s contiene Parameter Object invalido.', $key);
                continue;
            }
            $name = $parameter['name'] ?? null;
            $in = $parameter['in'] ?? null;
            if (!is_string($name) || !in_array($in, ['path', 'query', 'header'], true)) {
                $errors[] = sprintf('%s contiene parametro name/in invalido.', $key);
                continue;
            }
            $parameterKey = $in . ':' . $name;
            if (isset($seen[$parameterKey])) {
                $errors[] = sprintf('%s duplica parametro %s.', $key, $parameterKey);
            }
            $seen[$parameterKey] = true;
            if (!is_array($parameter['schema'] ?? null)) {
                $errors[] = sprintf('%s parametro %s no declara schema.', $key, $parameterKey);
            }
            if ($in === 'path') {
                $actualPathNames[] = $name;
                if (($parameter['required'] ?? null) !== true) {
                    $errors[] = sprintf('%s parametro path %s debe ser required.', $key, $name);
                }
            } elseif ($in === 'query' && ($parameter['required'] ?? false) !== false) {
                $errors[] = sprintf('%s parametro query %s debe ser opcional en el inventario conservador.', $key, $name);
            } elseif ($in === 'header' && ($parameter['required'] ?? null) !== true) {
                $errors[] = sprintf('%s parametro header %s debe declarar required.', $key, $name);
            }
        }
        sort($expectedPathNames, SORT_STRING);
        sort($actualPathNames, SORT_STRING);
        if ($expectedPathNames !== $actualPathNames) {
            $errors[] = sprintf('%s no conserva exactamente sus parametros de path.', $key);
        }
    }

    /** @param array<string, mixed> $securitySchemes @param list<string> $errors */
    private static function validateSecurity(string $key, mixed $security, array $securitySchemes, array &$errors): void
    {
        if (!is_array($security)) {
            $errors[] = sprintf('%s debe declarar security explicitamente.', $key);
            return;
        }
        foreach ($security as $requirement) {
            if (!is_array($requirement)) {
                $errors[] = sprintf('%s tiene Security Requirement invalido.', $key);
                continue;
            }
            foreach ($requirement as $scheme => $scopes) {
                if (!isset($securitySchemes[$scheme]) || !is_array($scopes)) {
                    $errors[] = sprintf('%s usa security scheme inexistente o scopes invalidos: %s.', $key, $scheme);
                }
            }
        }
    }

    /** @param array<string, mixed> $schemas @param list<string> $errors */
    private static function validateResponses(string $key, mixed $responses, array $schemas, array &$errors): void
    {
        if (!is_array($responses) || $responses === []) {
            $errors[] = sprintf('%s debe declarar responses.', $key);
            return;
        }
        if (!isset($responses['2XX'])) {
            $errors[] = sprintf('%s no declara respuesta 2XX.', $key);
        }
        foreach ($responses as $status => $response) {
            if (preg_match('/^[1-5](?:[0-9]{2}|XX)$/', (string)$status) !== 1 && $status !== 'default') {
                $errors[] = sprintf('%s usa status response invalido %s.', $key, $status);
            }
            if (!is_array($response)) {
                $errors[] = sprintf('%s response %s invalida.', $key, $status);
            }
        }
        if (!isset($schemas['StandardErrorEnvelope'], $schemas['StandardSuccessEnvelope'])) {
            $errors[] = 'Faltan schemas estandar de success/error.';
        }
    }

    /** @param list<string> $errors */
    private static function collectInvalidRefs(mixed $value, array $document, string $context, array &$errors): void
    {
        if (!is_array($value)) {
            return;
        }
        if (isset($value['$ref'])) {
            $ref = $value['$ref'];
            if (!is_string($ref) || !str_starts_with($ref, '#/')) {
                $errors[] = sprintf('%s contiene $ref externo/no valido.', $context);
            } elseif (!self::localRefExists($document, $ref)) {
                $errors[] = sprintf('%s contiene $ref inexistente %s.', $context, $ref);
            }
        }
        foreach ($value as $child) {
            self::collectInvalidRefs($child, $document, $context, $errors);
        }
    }

    private static function localRefExists(array $document, string $ref): bool
    {
        $segments = array_slice(explode('/', $ref), 1);
        $cursor = $document;
        foreach ($segments as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }

        return true;
    }
}
