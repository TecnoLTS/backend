<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\AuthSurface;

/** @return never */
function failIsolationCheck(string $message): void
{
    fwrite(STDERR, "Auth surface isolation check failed: {$message}\n");
    exit(1);
}

function expectSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        failIsolationCheck($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}

$_ENV['AUTH_LEGACY_COOKIE_FALLBACK_ENABLED'] = 'false';
$_ENV['AUTH_LEGACY_SURFACE_QUERY_ENABLED'] = 'false';
$GLOBALS['trusted_internal_proxy_token'] = true;
$_SERVER['HTTP_X_AUTH_SURFACE'] = AuthSurface::DASHBOARD;
$_SERVER['REQUEST_URI'] = '/api/auth/session';
$_GET = [];

expectSameValue(AuthSurface::DASHBOARD, AuthSurface::current(), 'trusted proxy header must select dashboard');
expectSameValue(
    ['pm_auth_dashboard'],
    AuthSurface::authCookieCandidates('pm_auth'),
    'dashboard must not read ecommerce or legacy auth cookies by default'
);
expectSameValue(
    ['pm_csrf_dashboard'],
    AuthSurface::csrfCookieCandidates('pm_csrf'),
    'dashboard must use its own CSRF cookie by default'
);

$GLOBALS['trusted_internal_proxy_token'] = false;
$_SERVER['HTTP_X_AUTH_SURFACE'] = AuthSurface::ECOMMERCE;
$_SERVER['REQUEST_URI'] = '/api/admin/products';
expectSameValue(
    AuthSurface::DASHBOARD,
    AuthSurface::current(),
    'an untrusted client header must not override the route-derived dashboard surface'
);

unset($_SERVER['HTTP_X_AUTH_SURFACE']);
$_GET['auth_surface'] = AuthSurface::ECOMMERCE;
expectSameValue(
    AuthSurface::DASHBOARD,
    AuthSurface::current(),
    'legacy auth_surface query must be ignored by default'
);

$_ENV['AUTH_LEGACY_COOKIE_FALLBACK_ENABLED'] = 'true';
expectSameValue(
    ['pm_auth_dashboard', 'pm_auth'],
    AuthSurface::authCookieCandidates('pm_auth', AuthSurface::DASHBOARD),
    'legacy opt-in may add only the unsuffixed cookie, never the opposite surface'
);
expectSameValue(
    ['pm_csrf_ecommerce', 'pm_csrf'],
    AuthSurface::csrfCookieCandidates('pm_csrf', AuthSurface::ECOMMERCE),
    'legacy CSRF opt-in may add only the unsuffixed cookie'
);

$_ENV['TENANT_RLS_MODE'] = 'enforce';
foreach (['direct', 'session'] as $safePoolMode) {
    $_ENV['DB_POOL_MODE'] = $safePoolMode;
    Database::assertRlsPoolSafetyConfig();
}
foreach (['transaction', 'statement'] as $unsafePoolMode) {
    $_ENV['DB_POOL_MODE'] = $unsafePoolMode;
    try {
        Database::assertRlsPoolSafetyConfig();
        failIsolationCheck("{$unsafePoolMode} pooling must fail closed under RLS");
    } catch (RuntimeException) {
        // Expected: a session GUC cannot be safe behind transaction pooling.
    }
}

$authSource = file_get_contents(__DIR__ . '/../src/Core/Auth.php');
if (!is_string($authSource) || !str_contains($authSource, "throw new \\RuntimeException('AUTH_SURFACE_REQUIRED')")) {
    failIsolationCheck('JWTs without aud/auth_surface must fail closed');
}
$entrypointSource = file_get_contents(__DIR__ . '/../public/index.php');
$authControllerSource = file_get_contents(__DIR__ . '/../src/Modules/IdentityPlatform/Controllers/AuthController.php');
$tenantControllerSource = file_get_contents(__DIR__ . '/../src/Modules/IdentityPlatform/Controllers/TenantController.php');
foreach ([$authSource, $entrypointSource, $authControllerSource, $tenantControllerSource] as $source) {
    if (!is_string($source) || str_contains($source, 'service_auth') || str_contains($source, 'trusted_internal_proxy_service_auth')) {
        failIsolationCheck('proxy header trust must never synthesize an authenticated service/admin identity');
    }
}
if (!is_string($entrypointSource)
    || !str_contains($entrypointSource, 'AdminIpAccessPolicy::productionConfigurationError')
    || !str_contains($entrypointSource, "'ADMIN_IP_POLICY_INVALID'")) {
    failIsolationCheck('production admin routes must fail closed when their custom CIDR policy is absent');
}
$databaseSource = file_get_contents(__DIR__ . '/../src/Core/Database.php');
if (!is_string($databaseSource)
    || !str_contains($databaseSource, 'PDO::ATTR_PERSISTENT         => false')
    || str_contains($databaseSource, 'DB_PERSISTENT_CONNECTIONS')
    || !str_contains($databaseSource, "SELECT set_config('app.tenant_id', :tenant_id, false)")
    || !str_contains($databaseSource, "\$statement->execute(['tenant_id' => \$tenantId])")
    || !str_contains($databaseSource, "\$connectionRole === 'app' ?")
    || !str_contains($databaseSource, ": '';")) {
    failIsolationCheck('PDO must disable persistence and replace stale tenant context, including an empty value for non-app roles, before every use');
}

echo "Auth surface isolation check passed.\n";
