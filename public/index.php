<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Router;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\TenantResolver;
use App\Core\Auth;
use App\Core\AuthSurface;
use App\Core\AdminIpAccessPolicy;
use App\Core\InternalProxyTrust;
use App\Modules\IdentityPlatform\Application\TenantAccessService;
use App\Modules\IdentityPlatform\Controllers\HealthController;
use App\Repositories\UserRepository;
use App\Support\ModuleOpenApiDocument;

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

if (!function_exists('hydrate_process_environment')) {
    function hydrate_process_environment(): void
    {
        $values = getenv();
        if (!is_array($values)) {
            return;
        }

        foreach ($values as $key => $value) {
            if (is_string($key) && !array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }
}

hydrate_process_environment();

// Load entorno/.env when it is readable. Docker can also inject the required
// environment variables, so an unreadable local file should not break requests.
$envDir = __DIR__ . '/../entorno';
$envPath = $envDir . '/.env';
if (is_readable($envPath)) {
    $dotenv = Dotenv::createImmutable($envDir);
    $dotenv->safeLoad();
} elseif (file_exists($envPath)) {
    error_log('[ENV_WARNING] entorno/.env exists but is not readable; using process environment only.');
}
hydrate_process_environment();

// Liveness must remain independent from tenant discovery, PostgreSQL and every
// other external dependency. Keep both the explicit probe and the historical
// health alias on this fast path; readiness continues through /api/readyz.
$bootstrapRequestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$bootstrapRequestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$bootstrapLivenessPaths = ['/api/livez', '/api/health'];
if (in_array($bootstrapRequestMethod, ['GET', 'HEAD'], true)
    && in_array($bootstrapRequestPath, $bootstrapLivenessPaths, true)) {
    header_remove('X-Powered-By');
    Response::noStore();
    if ($bootstrapRequestMethod === 'HEAD') {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        exit;
    }

    (new HealthController())->live();
    exit;
}

if ($bootstrapRequestMethod === 'GET') {
    $requestPath = $bootstrapRequestPath;
    if ($requestPath === '/openapi.json') {
        $document = ModuleOpenApiDocument::build(dirname(__DIR__));
        if (!headers_sent()) {
            header('Content-Type: application/vnd.oai.openapi+json;version=3.1; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
    if ($requestPath === '/module.json') {
        $manifestPath = __DIR__ . '/../module.json';
        if (!is_readable($manifestPath)) {
            respond_with_json_error('Module manifest no disponible', 500, 'MODULE_MANIFEST_NOT_FOUND');
            exit;
        }

        $manifestContents = file_get_contents($manifestPath);
        $manifest = is_string($manifestContents) ? json_decode($manifestContents, true) : null;
        if (!is_array($manifest)) {
            respond_with_json_error('Module manifest invalido', 500, 'MODULE_MANIFEST_INVALID');
            exit;
        }

        if (!headers_sent()) {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
        }

        echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

header_remove('X-Powered-By');

$configuredTenants = require __DIR__ . '/../config/tenants.php';
$tenants = \App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry::mergeConfigured(
    is_array($configuredTenants) ? $configuredTenants : []
);
$host = null;
$rawHttpHost = $_SERVER['HTTP_HOST'] ?? null;
$normalizedHttpHost = is_string($rawHttpHost) ? preg_replace('/:\d+$/', '', strtolower(trim($rawHttpHost))) : null;
$providedInternalProxyToken = trim((string)($_SERVER['HTTP_X_INTERNAL_PROXY_TOKEN'] ?? ''));
$internalProxyConfigurationError = InternalProxyTrust::configurationError($_ENV);
if ($internalProxyConfigurationError !== null) {
    error_log('[INTERNAL_PROXY_CONFIGURATION_INVALID] ' . $internalProxyConfigurationError);
    respond_with_json_error('Configuracion interna no disponible', 503, 'INTERNAL_PROXY_CONFIGURATION_INVALID');
    exit;
}
$trustedInternalProxyScope = InternalProxyTrust::resolveScope($_ENV, $providedInternalProxyToken);
if ($providedInternalProxyToken !== '' && $trustedInternalProxyScope === null) {
    respond_with_json_error('Credencial de proxy interno invalida', 403, 'INTERNAL_PROXY_CREDENTIAL_INVALID');
    exit;
}
$providedAuthSurface = strtolower(trim((string)($_SERVER['HTTP_X_AUTH_SURFACE'] ?? '')));
if (
    $providedAuthSurface !== ''
    && !InternalProxyTrust::allowsAuthSurface(
        $trustedInternalProxyScope,
        $providedAuthSurface,
        $bootstrapRequestMethod,
        $bootstrapRequestPath
    )
) {
    respond_with_json_error('La superficie solicitada no pertenece a este proxy', 403, 'INTERNAL_PROXY_SCOPE_VIOLATION');
    exit;
}
$hasTrustedInternalProxyToken = $trustedInternalProxyScope !== null;
$GLOBALS['trusted_internal_proxy_scope'] = $trustedInternalProxyScope;
$GLOBALS['trusted_internal_proxy_token'] = $hasTrustedInternalProxyToken;
if ($trustedInternalProxyScope === InternalProxyTrust::STOREFRONT && $providedAuthSurface === '') {
    // Server-side storefront requests without an explicit surface are always
    // constrained to ecommerce; they cannot fall back to URI inference.
    $_SERVER['HTTP_X_AUTH_SURFACE'] = AuthSurface::ECOMMERCE;
    $providedAuthSurface = AuthSurface::ECOMMERCE;
}
$appEnv = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
$proxyHeaderFlagEnabled = in_array(strtolower((string)($_ENV['TRUST_PROXY_HEADERS'] ?? 'false')), ['1', 'true', 'yes', 'on'], true);
$isNonProduction = $appEnv === 'qa';
$trustProxyHeaders = $hasTrustedInternalProxyToken || ($proxyHeaderFlagEnabled && $isNonProduction);
if ($proxyHeaderFlagEnabled && !$trustProxyHeaders) {
    error_log('[PROXY_HEADER_WARNING] TRUST_PROXY_HEADERS is ignored in production without a valid internal proxy token.');
}
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
$normalizedResolvedHost = $host ? preg_replace('/:\d+$/', '', strtolower($host)) : null;
$tenant = TenantResolver::resolveFromHost($tenants, $host);
if (!$tenant) {
    $localHosts = ['localhost', '127.0.0.1'];
    $normalizedHost = $host ? preg_replace('/:\\d+$/', '', strtolower($host)) : null;
    $fallbackSlug = $_ENV['DEFAULT_TENANT'] ?? 'paramascotasec';
    $isInternalHost = is_string($normalizedHost) && (
        $normalizedHost === 'backend-http'
        || str_ends_with($normalizedHost, '-backend-http')
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

// Tenant business traffic is published only after the edge controller has
// attested this exact desired state. Keep bootstrap health/readiness reachable
// so a pending tenant can complete provisioning; every other API fails closed
// even if a stale APISIX route survived temporarily.
$tenantDesiredStateHash = \App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry::desiredStateHash($tenant);
$tenantProvisioningStatus = strtolower(trim((string)($tenant['provisioning_status'] ?? 'pending_gateway')));
$tenantProvisioningDesiredHash = strtolower(trim((string)($tenant['provisioning_desired_hash'] ?? '')));
$tenantBusinessReady = $tenantProvisioningStatus === 'ready'
    && preg_match('/^[a-f0-9]{64}$/', $tenantProvisioningDesiredHash) === 1
    && hash_equals($tenantDesiredStateHash, $tenantProvisioningDesiredHash);
$tenantBootstrapPaths = ['/api/health', '/api/livez', '/api/readyz'];
$dashboardAdminHost = strtolower(trim((string)($_ENV['DASHBOARD_ADMIN_HOST'] ?? getenv('DASHBOARD_ADMIN_HOST') ?: '')));
$isPlatformRecoveryHost = $dashboardAdminHost !== '' && $normalizedResolvedHost === $dashboardAdminHost;
$isPlatformRecoveryPath = str_starts_with($bootstrapRequestPath, '/api/auth/')
    || $bootstrapRequestPath === '/api/admin/tenants'
    || str_starts_with($bootstrapRequestPath, '/api/admin/tenants/');
if (!$tenantBusinessReady
    && !in_array($bootstrapRequestPath, $tenantBootstrapPaths, true)
    && !($isPlatformRecoveryHost && $isPlatformRecoveryPath)) {
    respond_with_json_error(
        'Tenant pendiente de verificacion del gateway',
        503,
        'TENANT_EDGE_PROVISIONING_INCOMPLETE'
    );
    exit;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$isDev = $isNonProduction;
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
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant, X-CSRF-Token, X-API-Key, Idempotency-Key, If-Match');
header('Access-Control-Expose-Headers: ETag');
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
        return AdminIpAccessPolicy::normalizeMode($mode);
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
        return AdminIpAccessPolicy::ipInCidr($ip, $cidr);
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
        return AdminIpAccessPolicy::rules($mode, $allowlist);
    }

    function client_ip_matches_allowlist(string $ip, string $allowlist, string $mode = 'off'): bool
    {
        return AdminIpAccessPolicy::matches($ip, $allowlist, $mode);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$router = new Router();
$routes = require __DIR__ . '/../config/routes.php';
foreach ($routes as $route) {
    $router->add(
        $route['method'],
        $route['path'],
        $route['handler'],
        $route['capability'] ?? null
    );
}
$matchedRoute = $router->match($method, $uri);
$routeCapability = is_array($matchedRoute)
    ? (string)($matchedRoute['route']['capability'] ?? '')
    : '';

function is_public_billing_api_request(string $uri, string $method): bool {
    $normalizedMethod = strtoupper($method);
    if (!in_array($normalizedMethod, ['GET', 'HEAD', 'POST'], true)) {
        return false;
    }

    return preg_match('#^/api/(test|production)/v1/#', $uri) === 1;
}

function is_public_api_request(string $uri, string $method): bool {
    $normalizedMethod = strtoupper($method);

    if (is_public_billing_api_request($uri, $method)) {
        return true;
    }

    if (in_array($normalizedMethod, ['GET', 'HEAD', 'POST'], true) && str_starts_with($uri, '/api/loyalty/v1/')) {
        return true;
    }

    if (in_array($normalizedMethod, ['GET', 'HEAD'], true) && str_starts_with($uri, '/api/l/w/')) {
        return true;
    }

    if ($normalizedMethod === 'GET' && str_starts_with($uri, '/api/l/r/')) {
        return true;
    }

    if (in_array($normalizedMethod, ['GET', 'HEAD', 'POST'], true) && str_starts_with($uri, '/api/l/portal')) {
        return true;
    }

    if (in_array($normalizedMethod, ['GET', 'HEAD', 'POST'], true) && str_starts_with($uri, '/api/l/access')) {
        return true;
    }

    if (in_array($normalizedMethod, ['GET', 'HEAD'], true) && str_starts_with($uri, '/api/l/c/')) {
        return true;
    }

    if (in_array($normalizedMethod, ['GET', 'HEAD'], true) && str_starts_with($uri, '/api/l/reward-images/')) {
        return true;
    }

    if ($normalizedMethod === 'GET' || $normalizedMethod === 'HEAD') {
        if (in_array($uri, [
            '/api/auth/verify',
            '/api/auth/session',
            '/api/settings/shipping',
            '/api/settings/store-status',
            '/api/settings/brand-logos',
            '/api/settings/product-categories',
            '/api/settings/product-category-references',
            '/api/health',
            '/api/livez',
            '/api/readyz',
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
            '/api/auth/access-requests',
            '/api/auth/password-reset/request',
            '/api/auth/password-reset/confirm',
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
$isPublicBillingApi = is_public_billing_api_request($uri, $method);
$authCookieName = trim((string)($_ENV['AUTH_COOKIE_NAME'] ?? 'pm_auth')) ?: 'pm_auth';
$csrfCookieBaseName = trim((string)($_ENV['AUTH_CSRF_COOKIE_NAME'] ?? 'pm_csrf')) ?: 'pm_csrf';
$csrfCookieNames = AuthSurface::csrfCookieCandidates($csrfCookieBaseName);
$csrfExemptPaths = [
    '/api/auth/login',
    '/api/auth/register',
    '/api/auth/request-otp',
    '/api/auth/verify-otp',
    '/api/auth/access-requests',
    '/api/auth/password-reset/request',
    '/api/auth/password-reset/confirm',
    '/api/auth/verify',
    '/api/orders/quote',
    '/api/contact',
    '/api/security/csp-report',
    '/api/health',
];
$isMutatingApiRequest = str_starts_with($uri, '/api') && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
$hasAuthCookie = false;
foreach (AuthSurface::authCookieCandidates($authCookieName) as $candidateCookieName) {
    if (trim((string)($_COOKIE[$candidateCookieName] ?? '')) !== '') {
        $hasAuthCookie = true;
        break;
    }
}
$hasBearerAuth = preg_match('/Bearer\s+\S+/', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '')) === 1;
$isPortalFormRequest = $method === 'POST' && str_starts_with($uri, '/api/l/portal/');
$shouldEnforceCsrf = $isMutatingApiRequest
    && !in_array($uri, $csrfExemptPaths, true)
    && !$isPublicBillingApi
    && !$isPortalFormRequest
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

    $csrfCookie = '';
    foreach ($csrfCookieNames as $candidateCookieName) {
        $candidateValue = trim((string)($_COOKIE[$candidateCookieName] ?? ''));
        if ($candidateValue !== '') {
            $csrfCookie = $candidateValue;
            break;
        }
    }
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
        || $uri === '/api/roles'
        || str_starts_with($uri, '/api/roles/')
        || $uri === '/api/access/audit'
        || $uri === '/api/shipments';
    if ($adminOnly) {
        $adminIpMode = trim((string)($_ENV['ADMIN_IP_MODE'] ?? 'off'));
        $adminIpAllowlist = trim((string)($_ENV['ADMIN_IP_ALLOWLIST'] ?? ''));
        $normalizedAdminIpMode = normalize_ip_access_mode($adminIpMode);
        $adminPolicyError = AdminIpAccessPolicy::productionConfigurationError(
            $appEnv,
            $adminIpMode,
            $adminIpAllowlist
        );
        if ($adminPolicyError !== null) {
            error_log('[ADMIN_IP_POLICY_INVALID] ' . $adminPolicyError);
            Response::error(
                'La política de acceso administrativo no está disponible.',
                503,
                'ADMIN_IP_POLICY_INVALID'
            );
            exit;
        }
        if ($normalizedAdminIpMode !== 'off' || $adminIpAllowlist !== '') {
            $clientIp = get_client_ip();
            if (!client_ip_matches_allowlist($clientIp, $adminIpAllowlist, $adminIpMode)) {
                Response::error('Acceso administrativo restringido desde esta IP', 403, 'ADMIN_IP_FORBIDDEN');
                exit;
            }
        }
        Auth::requireAdmin();
    }

    $tenantAccess = new TenantAccessService();
    $accessDecision = $tenantAccess->routeAccessDecision($routeCapability, $method, $uri);
    if (!empty($accessDecision['requiresPermission'])) {
        $payload = Auth::requireUser();
        $userRepository = new UserRepository();
        $currentUser = $userRepository->getById((string)($payload['sub'] ?? ''));
        if (!$currentUser) {
            Response::error('Sesión inválida', 401, 'AUTH_TOKEN_REVOKED');
            exit;
        }

        $moduleKey = $accessDecision['module'] ?? null;
        $isPlatformAdmin = $tenantAccess->userHasPermission(
            $currentUser,
            TenantContext::get() ?? [],
            TenantAccessService::PLATFORM_ADMIN_PERMISSION
        );
        if (!$isPlatformAdmin && !$tenantAccess->moduleEnabledForTenant($moduleKey, TenantContext::get() ?? [])) {
            Response::error(
                'El tenant no tiene contratado este módulo.',
                403,
                'TENANT_MODULE_NOT_ENABLED',
                ['module' => $moduleKey]
            );
            exit;
        }

        $requiredPermissions = is_array($accessDecision['permissions'] ?? null)
            ? $accessDecision['permissions']
            : [(string)($accessDecision['permission'] ?? '')];
        $missingPermission = null;
        foreach ($requiredPermissions as $permission) {
            $permission = (string)$permission;
            if (!$tenantAccess->userHasPermission($currentUser, TenantContext::get() ?? [], $permission)) {
                $missingPermission = $permission;
                break;
            }
        }
        if ($missingPermission !== null) {
            Response::error(
                'No tienes permiso para usar esta función del módulo.',
                403,
                'TENANT_MODULE_PERMISSION_DENIED',
                ['permission' => $missingPermission, 'module' => $moduleKey]
            );
            exit;
        }
    }
}

$router->dispatch($method, $uri);
