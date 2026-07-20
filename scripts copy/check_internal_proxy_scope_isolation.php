<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\AuthSurface;
use App\Core\InternalProxyTrust;

$edge = str_repeat('e', 64);
$storefront = str_repeat('s', 64);
$environment = [
    'EDGE_BACKEND_PROXY_TOKEN' => $edge,
    'EDGE_BACKEND_PROXY_TOKEN_PREVIOUS' => str_repeat('p', 64),
    'STOREFRONT_BACKEND_PROXY_TOKEN' => $storefront,
    'STOREFRONT_BACKEND_PROXY_TOKEN_PREVIOUS' => str_repeat('q', 64),
];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(InternalProxyTrust::configurationError($environment) === null, 'Valid split credentials were rejected.');
$assert(InternalProxyTrust::resolveScope($environment, $edge) === InternalProxyTrust::EDGE, 'Edge token scope mismatch.');
$assert(InternalProxyTrust::resolveScope($environment, $storefront) === InternalProxyTrust::STOREFRONT, 'Storefront token scope mismatch.');
$assert(InternalProxyTrust::resolveScope($environment, str_repeat('x', 64)) === null, 'Unknown token was trusted.');
$assert(InternalProxyTrust::allowsAuthSurface(InternalProxyTrust::EDGE, AuthSurface::DASHBOARD, 'GET', '/api/users'), 'Edge cannot assert Dashboard.');
$assert(InternalProxyTrust::allowsAuthSurface(InternalProxyTrust::STOREFRONT, AuthSurface::ECOMMERCE, 'GET', '/api/products'), 'Storefront cannot assert ecommerce.');
$assert(!InternalProxyTrust::allowsAuthSurface(InternalProxyTrust::STOREFRONT, AuthSurface::DASHBOARD, 'GET', '/api/users'), 'Storefront can assert arbitrary Dashboard routes.');
$assert(InternalProxyTrust::allowsAuthSurface(InternalProxyTrust::STOREFRONT, AuthSurface::DASHBOARD, 'POST', '/api/admin/catalog/images'), 'Dashboard image transformer route was not allowed.');
$assert(!InternalProxyTrust::allowsAuthSurface(InternalProxyTrust::STOREFRONT, AuthSurface::DASHBOARD, 'DELETE', '/api/admin/catalog/images'), 'Transformer whitelist ignores method.');

$overlap = $environment;
$overlap['STOREFRONT_BACKEND_PROXY_TOKEN_PREVIOUS'] = $edge;
$assert(InternalProxyTrust::configurationError($overlap) !== null, 'Cross-scope overlap was accepted.');
$assert(InternalProxyTrust::resolveScope($overlap, $edge) === null, 'Ambiguous cross-scope token was trusted.');

echo "Internal proxy consumer scopes: OK\n";
