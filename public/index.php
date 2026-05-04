<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Router;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\TenantResolver;
use App\Core\Auth;

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!function_exists('respond_with_json_error')) {
    function respond_with_json_error(string $message, int $status, string $code): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }

        echo json_encode([
            'ok' => false,
            'error' => [
                'message' => $message,
                'code' => $code,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

set_exception_handler(static function (\Throwable $e): void {
    error_log(sprintf(
        '[UNCAUGHT_EXCEPTION] %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    respond_with_json_error('Error interno del servidor', 500, 'INTERNAL_SERVER_ERROR');
});

set_error_handler(static function (
    int $severity,
    string $message,
    string $file,
    int $line
): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new \ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    error_log(sprintf(
        '[FATAL_ERROR] %s in %s:%d',
        $error['message'] ?? 'Unknown fatal error',
        $error['file'] ?? 'unknown',
        $error['line'] ?? 0
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    respond_with_json_error('Error interno del servidor', 500, 'INTERNAL_SERVER_ERROR');
});

// Load .env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

header_remove('X-Powered-By');

$tenants = require __DIR__ . '/../config/tenants.php';
$host = null;
$rawHttpHost = $_SERVER['HTTP_HOST'] ?? null;
$normalizedHttpHost = is_string($rawHttpHost) ? preg_replace('/:\d+$/', '', strtolower(trim($rawHttpHost))) : null;
$expectedInternalProxyToken = trim((string)($_ENV['INTERNAL_PROXY_TOKEN'] ?? ''));
$previousInternalProxyToken = trim((string)($_ENV['INTERNAL_PROXY_TOKEN_PREVIOUS'] ?? ''));
$providedInternalProxyToken = trim((string)($_SERVER['HTTP_X_INTERNAL_PROXY_TOKEN'] ?? ''));
$trustedInternalProxyTokens = array_values(array_filter([$expectedInternalProxyToken, $previousInternalProxyToken], static fn($value) => $value !== ''));
$hasTrustedInternalProxyToken = false;
if ($providedInternalProxyToken !== '') {
    foreach ($trustedInternalProxyTokens as $trustedToken) {
        if (hash_equals($trustedToken, $providedInternalProxyToken)) {
            $hasTrustedInternalProxyToken = true;
            break;
        }
    }
}
$trustProxyHeaders = in_array(strtolower((string)($_ENV['TRUST_PROXY_HEADERS'] ?? 'false')), ['1', 'true', 'yes', 'on'], true)
    || $hasTrustedInternalProxyToken;
$GLOBALS['trust_proxy_headers'] = $trustProxyHeaders;
$hostCandidates = [$rawHttpHost];
if ($trustProxyHeaders) {
    array_unshift(
        $hostCandidates,
        $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null,
        $_SERVER['HTTP_X_ORIGINAL_HOST'] ?? null
    );
}
foreach ($hostCandidates as $candidate) {
    if (!is_string($candidate)) {
        continue;
    }
    $candidate = trim($candidate);
    if ($candidate !== '') {
        $host = $candidate;
        break;
    }
}
if ($host && strpos($host, ',') !== false) {
    $host = trim(explode(',', $host)[0]);
}
$tenant = TenantResolver::resolveFromHost($tenants, $host);
if (!$tenant) {
    $localHosts = ['localhost', '127.0.0.1'];
    $normalizedHost = $host ? preg_replace('/:\\d+$/', '', strtolower($host)) : null;
    $fallbackSlug = $_ENV['DEFAULT_TENANT'] ?? 'paramascotasec';
    $isInternalHost = is_string($normalizedHost) && (
        $normalizedHost === 'backend-web'
        || str_ends_with($normalizedHost, '-backend-web')
    );
    if ($normalizedHost && (in_array($normalizedHost, $localHosts, true) || filter_var($normalizedHost, FILTER_VALIDATE_IP) || $isInternalHost)) {
        $tenant = $tenants[$fallbackSlug] ?? null;
    }
}
if (!$tenant) {
    header('Content-Type: application/json');
    Response::error('Tenant no encontrado', 404, 'TENANT_NOT_FOUND');
    exit;
}
TenantContext::set($tenant);

$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$isDev = in_array(strtolower((string)($_ENV['APP_ENV'] ?? 'production')), ['development', 'dev', 'local'], true);
$localHosts = ['localhost', '127.0.0.1'];
$normalizedHost = $host ? preg_replace('/:\\d+$/', '', strtolower($host)) : null;
$isLocalHostRequest = $normalizedHost && (in_array($normalizedHost, $localHosts, true) || (bool)filter_var($normalizedHost, FILTER_VALIDATE_IP));
$isLocalOrigin = false;
if ($origin) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    if ($originHost) {
        $originHost = strtolower($originHost);
        $isLocalOrigin = in_array($originHost, ['localhost', '127.0.0.1'], true) || (bool)filter_var($originHost, FILTER_VALIDATE_IP);
    }
}
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant');
header('Access-Control-Max-Age: 600');
header('Vary: Origin');
Response::noStore();
if ($origin && TenantContext::isOriginAllowed($origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} elseif ($origin && $isLocalOrigin && ($isDev || $isLocalHostRequest)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: ' . ($tenant['app_url'] ?? 'null'));
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin && !TenantContext::isOriginAllowed($origin) && !($isLocalOrigin && ($isDev || $isLocalHostRequest))) {
        Response::error('Origen no permitido', 403, 'CORS_FORBIDDEN');
    }
    exit;
}

if (!function_exists('client_ip_matches_allowlist')) {
    function normalize_ip_access_mode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (in_array($mode, ['private', 'private-lan', 'lan'], true)) {
            return 'private';
        }
        if ($mode === 'custom') {
            return 'custom';
        }
        return 'off';
    }

    function private_ip_rules(): array
    {
        return [
            '127.0.0.1/32',
            '::1/128',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            'fc00::/7',
        ];
    }

    function ip_in_cidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $prefixLength] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        $prefix = (int)$prefixLength;
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $mask = (~(0xff >> $bits)) & 0xff;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    function get_client_ip(): string
    {
        $trustProxyHeaders = (bool)($GLOBALS['trust_proxy_headers'] ?? false);
        $candidates = [];

        if ($trustProxyHeaders) {
            $candidates[] = $_SERVER['HTTP_X_REAL_IP'] ?? null;
            $candidates[] = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        }

        $candidates[] = $_SERVER['REMOTE_ADDR'] ?? null;

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim(explode(',', $candidate)[0] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    function ip_allowlist_rules(string $mode, string $allowlist): array
    {
        $rules = array_values(array_filter(array_map('trim', explode(',', $allowlist))));
        $normalizedMode = normalize_ip_access_mode($mode);
        if ($normalizedMode === 'private') {
            return array_values(array_unique(array_merge(private_ip_rules(), $rules)));
        }
        if ($normalizedMode === 'custom') {
            return $rules;
        }
        return [];
    }

    function client_ip_matches_allowlist(string $ip, string $allowlist, string $mode = 'off'): bool
    {
        $rules = ip_allowlist_rules($mode, $allowlist);
        if ($rules === []) {
            return true;
        }

        foreach ($rules as $rule) {
            if (ip_in_cidr($ip, $rule)) {
                return true;
            }
        }

        return false;
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$router = new Router();

// Product Routes
$router->add('GET', '/api/products', 'ProductController@index');
$router->add('GET', '/api/products/{id}', 'ProductController@show');
$router->add('POST', '/api/products', 'ProductController@store');
$router->add('PUT', '/api/products/{id}', 'ProductController@update');
$router->add('DELETE', '/api/products/{id}', 'ProductController@destroy');

// User Routes
$router->add('GET', '/api/users', 'UserController@index');
$router->add('POST', '/api/users', 'UserController@store');
$router->add('PUT', '/api/users/{id}', 'UserController@update');
$router->add('POST', '/api/users/{id}/unlock', 'UserController@unlock');
$router->add('GET', '/api/user/addresses', 'UserController@getAddresses');
$router->add('PUT', '/api/user/addresses', 'UserController@updateAddresses');
$router->add('GET', '/api/user/profile', 'UserController@getProfile');
$router->add('PUT', '/api/user/profile', 'UserController@updateProfile');
$router->add('PUT', '/api/user/password', 'UserController@updatePassword');
$router->add('POST', '/api/auth/login', 'AuthController@login');
$router->add('POST', '/api/auth/logout', 'AuthController@logout');
$router->add('POST', '/api/auth/register', 'AuthController@register');
$router->add('POST', '/api/auth/request-otp', 'AuthController@requestOtp');
$router->add('POST', '/api/auth/verify-otp', 'AuthController@verifyOtp');
$router->add('POST', '/api/contact', 'ContactController@store');
$router->add('GET', '/api/auth/verify', 'AuthController@verify');
$router->add('GET', '/api/auth/session', 'AuthController@session');

// Order Routes
$router->add('GET', '/api/orders', 'OrderController@index');
$router->add('GET', '/api/orders/my-orders', 'OrderController@myOrders'); // Specific route for user's orders
$router->add('GET', '/api/orders/{id}', 'OrderController@show');
$router->add('PATCH', '/api/orders/{id}/status', 'OrderController@updateStatus');
$router->add('GET', '/api/orders/{id}/invoice', 'OrderController@invoice');
$router->add('POST', '/api/orders', 'OrderController@store');
$router->add('POST', '/api/orders/quote', 'OrderController@quote');

// Admin Dashboard Routes
$router->add('GET', '/api/admin/dashboard/stats', 'DashboardController@stats');
$router->add('GET', '/api/admin/settings/tax', 'SettingsController@getVat');
$router->add('PUT', '/api/admin/settings/tax', 'SettingsController@updateVat');
$router->add('GET', '/api/settings/shipping', 'SettingsController@getShipping');
$router->add('GET', '/api/settings/store-status', 'SettingsController@getStoreStatus');
$router->add('GET', '/api/settings/brand-logos', 'SettingsController@getPublicBrandLogos');
$router->add('GET', '/api/admin/settings/shipping', 'SettingsController@getShipping');
$router->add('PUT', '/api/admin/settings/shipping', 'SettingsController@updateShipping');
$router->add('GET', '/api/admin/settings/store-status', 'SettingsController@getStoreStatus');
$router->add('PUT', '/api/admin/settings/store-status', 'SettingsController@updateStoreStatus');
$router->add('GET', '/api/admin/settings/product-page', 'SettingsController@getProductPage');
$router->add('PUT', '/api/admin/settings/product-page', 'SettingsController@updateProductPage');
$router->add('GET', '/api/admin/settings/pricing-margins', 'SettingsController@getPricingMargins');
$router->add('PUT', '/api/admin/settings/pricing-margins', 'SettingsController@updatePricingMargins');
$router->add('GET', '/api/admin/settings/pricing-calc', 'SettingsController@getPricingCalc');
$router->add('PUT', '/api/admin/settings/pricing-calc', 'SettingsController@updatePricingCalc');
$router->add('GET', '/api/admin/settings/pricing-rules', 'SettingsController@getPricingRules');
$router->add('PUT', '/api/admin/settings/pricing-rules', 'SettingsController@updatePricingRules');
$router->add('GET', '/api/admin/settings/product-reference-data', 'SettingsController@getProductReferenceData');
$router->add('PUT', '/api/admin/settings/product-reference-data', 'SettingsController@updateProductReferenceData');
$router->add('GET', '/api/admin/discounts', 'DiscountController@index');
$router->add('POST', '/api/admin/discounts', 'DiscountController@store');
$router->add('GET', '/api/admin/discounts/audit', 'DiscountController@audit');
$router->add('GET', '/api/admin/discounts/{id}', 'DiscountController@show');
$router->add('PUT', '/api/admin/discounts/{id}', 'DiscountController@update');
$router->add('PATCH', '/api/admin/discounts/{id}/status', 'DiscountController@updateStatus');
$router->add('GET', '/api/admin/purchase-invoices', 'PurchaseInvoiceController@index');
$router->add('GET', '/api/admin/purchase-invoices/{id}', 'PurchaseInvoiceController@show');
$router->add('GET', '/api/admin/billing/rides', 'BillingDocumentController@rides');
$router->add('GET', '/api/admin/billing/rides/{accessKey}/pdf', 'BillingDocumentController@ridePdf');
$router->add('GET', '/api/admin/pos/shift/active', 'PosController@activeShift');
$router->add('GET', '/api/admin/pos/shifts', 'PosController@shifts');
$router->add('GET', '/api/admin/pos/movements', 'PosController@movements');
$router->add('GET', '/api/admin/pos/customer-by-document', 'PosController@customerByDocument');
$router->add('GET', '/api/admin/quotes', 'QuotationController@index');
$router->add('POST', '/api/admin/quotes', 'QuotationController@store');
$router->add('POST', '/api/admin/quotes/{id}/convert', 'QuotationController@convert');
$router->add('POST', '/api/admin/pos/shift/open', 'PosController@openShift');
$router->add('POST', '/api/admin/pos/shift/close', 'PosController@closeShift');
$router->add('POST', '/api/admin/pos/movements', 'PosController@addMovement');
$router->add('GET', '/api/shipments', 'ShippingController@index');

// Reports (guide/example)
$router->add('GET', '/api/reports/recent-orders', 'ReportController@recentOrders');

// Health Route
$router->add('GET', '/api/health', 'HealthController@status');
$router->add('POST', '/api/security/csp-report', 'SecurityController@cspReport');

function is_public_api_request(string $uri, string $method): bool {
    $normalizedMethod = strtoupper($method);

    if ($normalizedMethod === 'GET' || $normalizedMethod === 'HEAD') {
        if (in_array($uri, [
            '/api/auth/verify',
            '/api/auth/session',
            '/api/settings/shipping',
            '/api/settings/store-status',
            '/api/settings/brand-logos',
            '/api/health',
        ], true)) {
            return true;
        }

        if ($uri === '/api/products' || str_starts_with($uri, '/api/products/')) {
            return true;
        }
    }

    if ($normalizedMethod === 'POST') {
        if (in_array($uri, [
            '/api/auth/login',
            '/api/auth/register',
            '/api/auth/request-otp',
            '/api/auth/verify-otp',
            '/api/orders/quote',
            '/api/contact',
            '/api/security/csp-report',
        ], true)) {
            return true;
        }
    }

    return false;
}

// Global auth: all API requests require a valid token (except auth endpoints).
$isPublic = is_public_api_request($uri, $method);
$authCookieName = trim((string)($_ENV['AUTH_COOKIE_NAME'] ?? 'pm_auth')) ?: 'pm_auth';
$csrfCookieName = trim((string)($_ENV['AUTH_CSRF_COOKIE_NAME'] ?? 'pm_csrf')) ?: 'pm_csrf';
$csrfExemptPaths = [
    '/api/auth/login',
    '/api/auth/register',
    '/api/auth/request-otp',
    '/api/auth/verify-otp',
    '/api/auth/verify',
    '/api/orders/quote',
    '/api/contact',
    '/api/security/csp-report',
    '/api/health',
];
$isMutatingApiRequest = str_starts_with($uri, '/api') && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
$hasAuthCookie = trim((string)($_COOKIE[$authCookieName] ?? '')) !== '';
$hasBearerAuth = preg_match('/Bearer\s+\S+/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '')) === 1;
$shouldEnforceCsrf = $isMutatingApiRequest
    && !in_array($uri, $csrfExemptPaths, true)
    && ($hasAuthCookie || $uri === '/api/auth/logout');

if ($shouldEnforceCsrf) {
    $secFetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($secFetchSite !== '' && !in_array($secFetchSite, ['same-origin', 'same-site', 'none'], true)) {
        Response::error('Solicitud bloqueada por política CSRF', 403, 'CSRF_FETCH_SITE_FORBIDDEN');
        exit;
    }

    $originToCheck = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($originToCheck === '') {
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $refererParts = parse_url($referer);
            if (($refererParts['scheme'] ?? null) && ($refererParts['host'] ?? null)) {
                $originToCheck = $refererParts['scheme'] . '://' . $refererParts['host'] . (isset($refererParts['port']) ? ':' . $refererParts['port'] : '');
            }
        }
    }

    if ($originToCheck !== '') {
        $originHost = parse_url($originToCheck, PHP_URL_HOST);
        $normalizedOriginHost = is_string($originHost) ? strtolower($originHost) : null;
        $originAllowed = TenantContext::isOriginAllowed($originToCheck)
            || ($normalizedOriginHost && (in_array($normalizedOriginHost, ['localhost', '127.0.0.1'], true) || (bool)filter_var($normalizedOriginHost, FILTER_VALIDATE_IP)) && ($isDev || $isLocalHostRequest));
        if (!$originAllowed) {
            Response::error('Origen no permitido para esta operación', 403, 'CSRF_ORIGIN_FORBIDDEN');
            exit;
        }
    }

    $csrfCookie = trim((string)($_COOKIE[$csrfCookieName] ?? ''));
    $csrfHeader = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($csrfCookie === '' || $csrfHeader === '' || !hash_equals($csrfCookie, $csrfHeader)) {
        Response::error('Token CSRF inválido o ausente', 403, 'CSRF_TOKEN_INVALID');
        exit;
    }
}

$requiresAuth = str_starts_with($uri, '/api') && !$isPublic;
if ($requiresAuth) {
    Auth::validateRequestOrFail();
    $adminOnly = str_starts_with($uri, '/api/admin/')
        || str_starts_with($uri, '/api/reports/')
        || $uri === '/api/users'
        || str_starts_with($uri, '/api/users/')
        || $uri === '/api/shipments';
    if ($adminOnly) {
        $adminIpMode = trim((string)($_ENV['ADMIN_IP_MODE'] ?? 'off'));
        $adminIpAllowlist = trim((string)($_ENV['ADMIN_IP_ALLOWLIST'] ?? ''));
        if (normalize_ip_access_mode($adminIpMode) !== 'off' || $adminIpAllowlist !== '') {
            $clientIp = get_client_ip();
            if (!client_ip_matches_allowlist($clientIp, $adminIpAllowlist, $adminIpMode)) {
                Response::error('Acceso administrativo restringido desde esta IP', 403, 'ADMIN_IP_FORBIDDEN');
                exit;
            }
        }
        Auth::requireAdmin();
    }
}

$router->dispatch($method, $uri);
