<?php

declare(strict_types=1);

use App\Support\ModuleOpenApiDocument;
use App\Support\ModuleOpenApiSchemaCatalog;

$backendRoot = dirname(__DIR__);
require_once $backendRoot . '/vendor/autoload.php';

function openapiFail(string $message): never
{
    fwrite(STDERR, "[openapi-check] {$message}\n");
    exit(1);
}

/** @param list<string> $errors */
function openapiAssertNoErrors(array $errors, string $scope): void
{
    if ($errors === []) {
        return;
    }
    openapiFail($scope . ': ' . implode(' | ', $errors));
}

/** @return array{path: string, method: string} */
function openapiOperationLocation(array $document, array $route): array
{
    $method = strtolower((string)$route['method']);
    foreach ($document['paths'] ?? [] as $path => $pathItem) {
        if (is_array($pathItem)
            && is_array($pathItem[$method] ?? null)
            && ($pathItem[$method]['x-internal-path'] ?? null) === $route['path']) {
            return ['path' => (string)$path, 'method' => $method];
        }
    }
    openapiFail(sprintf('No se encontro la operacion %s %s para el self-test.', $route['method'], $route['path']));
}

/** @return array<string, string> */
function openapiInventoryRoute(array $inventory, string $method, string $path): array
{
    foreach ($inventory as $route) {
        if (($route['method'] ?? null) === $method && ($route['path'] ?? null) === $path) {
            return $route;
        }
    }
    openapiFail(sprintf('No se encontro %s %s en el inventario modular.', $method, $path));
}

/** @param array<string, mixed> $expected */
function openapiAssertExactPolicy(array $document, array $inventory, string $method, string $path, array $expected): void
{
    $route = openapiInventoryRoute($inventory, $method, $path);
    $location = openapiOperationLocation($document, $route);
    $operation = $document['paths'][$location['path']][$location['method']];
    foreach ($expected as $field => $value) {
        $actual = match ($field) {
            'classification' => $operation['x-security-policy']['classification'] ?? null,
            'publicPath' => $location['path'],
            default => $operation[$field] ?? null,
        };
        if ($actual !== $value) {
            openapiFail(sprintf(
                '%s %s debe declarar %s=%s; obtuvo %s.',
                $method,
                $path,
                $field,
                json_encode($value, JSON_UNESCAPED_SLASHES),
                json_encode($actual, JSON_UNESCAPED_SLASHES)
            ));
        }
    }
}

try {
    $inventory = ModuleOpenApiDocument::routeInventory($backendRoot);
    openapiAssertNoErrors(
        ModuleOpenApiDocument::aggregatedRegistryErrors($backendRoot, $inventory),
        'config/routes.php deriva de los registries modulares'
    );

    $document = ModuleOpenApiDocument::build($backendRoot);
    openapiAssertNoErrors(
        ModuleOpenApiDocument::validationErrors($document, $inventory),
        'documento OpenAPI 3.1 invalido'
    );
    openapiAssertExactPolicy($document, $inventory, 'GET', '/api/auth/session', [
        'x-exposure' => 'tenant-session',
        'x-auth-surface' => 'ecommerce-or-dashboard',
        'classification' => 'tenant-user-session',
        'security' => [
            ['bearerAuth' => []],
            ['dashboardSessionCookie' => []],
            ['ecommerceSessionCookie' => []],
        ],
        'publicPath' => '/{tenantSlug}/{apiSegment}/auth/session',
    ]);
    openapiAssertExactPolicy($document, $inventory, 'GET', '/api/products/{id}/movement', [
        'x-exposure' => 'admin',
        'x-auth-surface' => 'dashboard',
        'classification' => 'tenant-admin-session',
        'security' => [
            ['bearerAuth' => []],
            ['dashboardSessionCookie' => []],
        ],
        'publicPath' => '/{dashboardSegment}/api/products/{id}/movement',
    ]);
    foreach ([
        ['POST', '/api/products'],
        ['PUT', '/api/products/{id}'],
        ['DELETE', '/api/products/{id}'],
    ] as [$productMethod, $productPath]) {
        openapiAssertExactPolicy($document, $inventory, $productMethod, $productPath, [
            'x-exposure' => 'admin',
            'x-auth-surface' => 'dashboard',
            'classification' => 'tenant-admin-session',
            'security' => [
                ['bearerAuth' => []],
                ['dashboardSessionCookie' => [], 'csrfToken' => []],
            ],
            'publicPath' => '/{dashboardSegment}' . $productPath,
        ]);
    }

    $manifestPath = $backendRoot . '/module.json';
    $manifestContents = file_get_contents($manifestPath);
    $manifest = is_string($manifestContents)
        ? json_decode($manifestContents, true, 512, JSON_THROW_ON_ERROR)
        : null;
    if (!is_array($manifest)) {
        openapiFail('module.json no contiene un objeto JSON.');
    }
    $coverage = $manifest['contracts']['openApiCoverage'] ?? null;
    if (($manifest['contracts']['openApi'] ?? null) !== true || !is_array($coverage)) {
        openapiFail('module.json no declara contracts.openApi=true y openApiCoverage.');
    }
    $semanticCoverage = ModuleOpenApiSchemaCatalog::coverage($document, $inventory);
    $expectedCoverage = [
        'endpoint' => '/openapi.json',
        'specVersion' => '3.1.0',
        'operationInventory' => 'complete',
        'requestDtos' => 'typed-for-public-external-critical-mutations',
        'responseDtos' => 'typed-for-public-external-critical-mutations',
        'businessDtoFieldsTyped' => 'required-surfaces-complete',
        'publicOperations' => $semanticCoverage['publicOperations'] ?? null,
        'externalOperations' => $semanticCoverage['externalOperations'] ?? null,
        'criticalDomainMutations' => $semanticCoverage['criticalDomainMutations'] ?? null,
        'semanticRequiredOperations' => $semanticCoverage['requiredSemanticOperations'] ?? null,
        'semanticTypedOperations' => $semanticCoverage['typedSemanticOperations'] ?? null,
        'semanticRequiredRequests' => $semanticCoverage['requiredSemanticRequests'] ?? null,
        'semanticTypedRequests' => $semanticCoverage['typedSemanticRequests'] ?? null,
        'requiredSurfacesComplete' => true,
    ];
    foreach ($expectedCoverage as $key => $expected) {
        if (($coverage[$key] ?? null) !== $expected) {
            openapiFail(sprintf('module.json contracts.openApiCoverage.%s debe ser %s.', $key, json_encode($expected)));
        }
    }
    if (($manifest['http']['openApiEndpoint'] ?? null) !== '/openapi.json') {
        openapiFail('module.json http.openApiEndpoint debe ser /openapi.json.');
    }

    $publicIndex = file_get_contents($backendRoot . '/public/index.php');
    if (!is_string($publicIndex)
        || !str_contains($publicIndex, "\$requestPath === '/openapi.json'")
        || !str_contains($publicIndex, 'ModuleOpenApiDocument::build')) {
        openapiFail('public/index.php no publica el documento generado en /openapi.json.');
    }

    if (in_array('--self-test', $argv, true)) {
        $missing = $document;
        $firstRoute = $inventory[0];
        $firstLocation = openapiOperationLocation($document, $firstRoute);
        unset($missing['paths'][$firstLocation['path']][$firstLocation['method']]);
        if (ModuleOpenApiDocument::validationErrors($missing, $inventory) === []) {
            openapiFail('self-test: el verificador acepto una ruta faltante.');
        }

        $extra = $document;
        $extraOperation = $document['paths'][$firstLocation['path']][$firstLocation['method']];
        $extraOperation['operationId'] .= '.unexpected';
        $extraOperation['x-internal-path'] = '/api/__openapi_unexpected_self_test';
        $extra['paths']['/{tenantSlug}/{apiSegment}/__openapi_unexpected_self_test']['get'] = $extraOperation;
        if (ModuleOpenApiDocument::validationErrors($extra, $inventory) === []) {
            openapiFail('self-test: el verificador acepto una ruta sobrante.');
        }

        $duplicateOperationId = $document;
        $routes = array_values($inventory);
        $firstLocation = openapiOperationLocation($duplicateOperationId, $routes[0]);
        $secondLocation = openapiOperationLocation($duplicateOperationId, $routes[1]);
        $firstOperationId = $duplicateOperationId['paths'][$firstLocation['path']][$firstLocation['method']]['operationId'];
        $duplicateOperationId['paths'][$secondLocation['path']][$secondLocation['method']]['operationId'] = $firstOperationId;
        if (ModuleOpenApiDocument::validationErrors($duplicateOperationId, $inventory) === []) {
            openapiFail('self-test: el verificador acepto operationId duplicado.');
        }

        $securityDrift = $document;
        $externalMutated = false;
        foreach ($securityDrift['paths'] as &$pathItem) {
            foreach ($pathItem as &$operation) {
                if (($operation['x-exposure'] ?? null) === 'external') {
                    $operation['security'] = [];
                    $operation['x-exposure'] = 'public';
                    $externalMutated = true;
                    break 2;
                }
            }
        }
        unset($operation, $pathItem);
        if (!$externalMutated || ModuleOpenApiDocument::validationErrors($securityDrift, $inventory) === []) {
            openapiFail('self-test: el verificador acepto deriva de security/x-exposure externo.');
        }

        $sessionSecurityDrift = $document;
        $sessionRoute = openapiInventoryRoute($inventory, 'GET', '/api/auth/session');
        $sessionLocation = openapiOperationLocation($sessionSecurityDrift, $sessionRoute);
        $sessionOperation = &$sessionSecurityDrift['paths'][$sessionLocation['path']][$sessionLocation['method']];
        $sessionOperation['security'] = [[]];
        $sessionOperation['x-exposure'] = 'public';
        $sessionOperation['x-auth-surface'] = 'public';
        $sessionOperation['x-security-policy']['classification'] = 'public';
        unset($sessionOperation);
        if (ModuleOpenApiDocument::validationErrors($sessionSecurityDrift, $inventory) === []) {
            openapiFail('self-test: el verificador acepto /api/auth/session como anonimo u opcional.');
        }

        $movementSecurityDrift = $document;
        $movementRoute = openapiInventoryRoute($inventory, 'GET', '/api/products/{id}/movement');
        $movementLocation = openapiOperationLocation($movementSecurityDrift, $movementRoute);
        $movementOperation = &$movementSecurityDrift['paths'][$movementLocation['path']][$movementLocation['method']];
        $movementOperation['security'] = [];
        $movementOperation['x-exposure'] = 'public';
        $movementOperation['x-auth-surface'] = 'public';
        $movementOperation['x-security-policy']['classification'] = 'public';
        unset($movementOperation['servers'], $movementOperation);
        if (ModuleOpenApiDocument::validationErrors($movementSecurityDrift, $inventory) === []) {
            openapiFail('self-test: el verificador acepto movement de producto como publico.');
        }

        $genericSemanticResponse = $document;
        $genericResponseMutated = false;
        foreach ($genericSemanticResponse['paths'] as &$pathItem) {
            foreach ($pathItem as &$operation) {
                if (($operation['x-contract-coverage']['businessDto'] ?? null) !== 'typed'
                    || !isset($operation['responses']['2XX']['content']['application/json'])) {
                    continue;
                }
                $operation['responses']['2XX']['content']['application/json']['schema'] = [
                    '$ref' => '#/components/schemas/StandardSuccessEnvelope',
                ];
                $genericResponseMutated = true;
                break 2;
            }
        }
        unset($operation, $pathItem);
        if (!$genericResponseMutated || ModuleOpenApiDocument::validationErrors($genericSemanticResponse, $inventory) === []) {
            openapiFail('self-test: el verificador acepto un response generico en una superficie semantica exigible.');
        }

        $genericSemanticRequest = $document;
        $genericRequestMutated = false;
        foreach ($genericSemanticRequest['paths'] as &$pathItem) {
            foreach ($pathItem as &$operation) {
                if (($operation['x-contract-coverage']['businessDto'] ?? null) !== 'typed'
                    || ($operation['requestBody']['x-dto-coverage'] ?? null) !== 'typed'
                    || !is_array($operation['requestBody']['content'] ?? null)) {
                    continue;
                }
                foreach ($operation['requestBody']['content'] as &$mediaType) {
                    $mediaType['schema'] = ['$ref' => '#/components/schemas/GenericRequestObject'];
                }
                unset($mediaType);
                $genericRequestMutated = true;
                break 2;
            }
        }
        unset($operation, $pathItem);
        if (!$genericRequestMutated || ModuleOpenApiDocument::validationErrors($genericSemanticRequest, $inventory) === []) {
            openapiFail('self-test: el verificador acepto un request generico en una superficie semantica exigible.');
        }

        $semanticCoverageDrift = $document;
        $semanticCoverageDrift['x-schema-coverage']['semantic']['typedSemanticOperations'] = 0;
        if (ModuleOpenApiDocument::validationErrors($semanticCoverageDrift, $inventory) === []) {
            openapiFail('self-test: el verificador acepto deriva de la cobertura semantica declarada.');
        }

        $legacyPublicPath = $document;
        $legacyOperation = $legacyPublicPath['paths'][$firstLocation['path']][$firstLocation['method']];
        unset($legacyPublicPath['paths'][$firstLocation['path']][$firstLocation['method']]);
        $legacyPublicPath['paths']['/api/__legacy_self_test']['get'] = $legacyOperation;
        if (ModuleOpenApiDocument::validationErrors($legacyPublicPath, $inventory) === []) {
            openapiFail('self-test: el verificador acepto un prefijo publico legacy.');
        }

        $invalidCapability = $inventory[0];
        $invalidCapability['capability'] = 'INVALID CAPABILITY';
        if (ModuleOpenApiDocument::routeDefinitionErrors($invalidCapability, $invalidCapability['module']) === []) {
            openapiFail('self-test: el verificador acepto capability invalida.');
        }

        $invalidHandler = $inventory[0];
        $invalidHandler['handler'] = 'App\\Modules\\' . $invalidHandler['module'] . '\\Controllers\\MissingController@index';
        if (ModuleOpenApiDocument::routeDefinitionErrors($invalidHandler, $invalidHandler['module']) === []) {
            openapiFail('self-test: el verificador acepto handler inexistente.');
        }
    }

    printf(
        "[openapi-check] OK openapi=%s modules=%d operations=%d operation_inventory=complete semantic_operations=%d/%d semantic_requests=%d/%d public=%d external=%d critical_mutations=%d required_surfaces_complete=true%s\n",
        $document['openapi'],
        $document['x-route-inventory']['moduleCount'],
        $document['x-route-inventory']['operationCount'],
        $semanticCoverage['typedSemanticOperations'],
        $semanticCoverage['requiredSemanticOperations'],
        $semanticCoverage['typedSemanticRequests'],
        $semanticCoverage['requiredSemanticRequests'],
        $semanticCoverage['publicOperations'],
        $semanticCoverage['externalOperations'],
        $semanticCoverage['criticalDomainMutations'],
        in_array('--self-test', $argv, true) ? ' fail_closed_self_test=passed' : ''
    );
} catch (Throwable $exception) {
    openapiFail($exception->getMessage());
}
