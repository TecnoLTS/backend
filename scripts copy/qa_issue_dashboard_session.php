<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\AuthSurface;
use App\Core\TenantContext;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\SessionSettingsService;
use Dotenv\Dotenv;
use Firebase\JWT\JWT;

function qaSessionFail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function qaSessionHydrateEnv(): void
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

function qaSessionArg(string $name): ?string
{
    global $argv;

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return trim(substr($argument, strlen($name) + 3));
        }
    }

    return null;
}

function qaSessionAllowedEmail(string $email): bool
{
    return (bool) preg_match('/^qa\.[a-z0-9._-]+@(paramascotasec\.com|tecnolts\.com)$/i', $email);
}

function qaSessionTruthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on'], true);
}

qaSessionHydrateEnv();

$envDir = dirname(__DIR__) . '/entorno';
$envPath = $envDir . '/.env';
if (is_readable($envPath)) {
    Dotenv::createImmutable($envDir)->safeLoad();
    qaSessionHydrateEnv();
}

$appEnv = strtolower(trim((string)($_ENV['APP_ENV'] ?? 'production')));
if ($appEnv !== 'qa') {
    qaSessionFail('qa_issue_dashboard_session is available only in QA environments.');
}

$issuerEnabled = strtolower(trim((string)($_ENV['PLAYWRIGHT_QA_SESSION_ISSUER'] ?? '')));
if (!in_array($issuerEnabled, ['1', 'true', 'yes', 'on'], true)) {
    qaSessionFail('qa_issue_dashboard_session requires PLAYWRIGHT_QA_SESSION_ISSUER=1.');
}

$email = strtolower(trim((string)qaSessionArg('email')));
$requestedTenantId = trim((string)(qaSessionArg('tenant-id') ?? ($_ENV['DEFAULT_TENANT'] ?? $_ENV['PUBLIC_TENANT_SLUG'] ?? 'paramascotasec')));
$surface = strtolower(trim((string)(qaSessionArg('surface') ?? AuthSurface::DASHBOARD)));

if ($email === '') {
    qaSessionFail('Missing --email=<value>');
}

if ($requestedTenantId === '') {
    qaSessionFail('Missing tenant id');
}

if (!qaSessionAllowedEmail($email)) {
    qaSessionFail('qa_issue_dashboard_session only allows ephemeral qa.* test accounts.');
}

if (!in_array($surface, [AuthSurface::DASHBOARD, AuthSurface::ECOMMERCE], true)) {
    qaSessionFail('Unsupported auth surface');
}

$tenants = require dirname(__DIR__) . '/config/tenants.php';
$tenant = $tenants[$requestedTenantId] ?? null;
if (!is_array($tenant)) {
    qaSessionFail("Unknown tenant {$requestedTenantId}");
}

TenantContext::set($tenant);

$userRepository = new UserRepository();
$user = $userRepository->getByEmail($email);
if (!$user) {
    qaSessionFail("User not found for {$email}");
}

if (!qaSessionTruthy($user['email_verified'] ?? false)) {
    qaSessionFail("User {$email} must be email verified.");
}

$role = strtolower(trim((string)($user['role'] ?? '')));
if ($role !== 'admin') {
    qaSessionFail("User {$email} must be an admin QA account.");
}

$jwtSecret = trim((string)($_ENV['JWT_SECRET'] ?? ''));
if ($jwtSecret === '' || strlen($jwtSecret) < 32) {
    qaSessionFail('JWT secret unavailable');
}

$baseCookieName = trim((string)($_ENV['AUTH_COOKIE_NAME'] ?? 'pm_auth')) ?: 'pm_auth';
$csrfCookieBaseName = trim((string)($_ENV['AUTH_CSRF_COOKIE_NAME'] ?? 'pm_csrf')) ?: 'pm_csrf';
$authCookieName = AuthSurface::authCookieName($baseCookieName, $surface);
$csrfCookieName = AuthSurface::csrfCookieName($csrfCookieBaseName, $surface);
$ttlSeconds = (new SessionSettingsService(new SettingsRepository()))
    ->ttlSecondsForRole((string)($user['role'] ?? 'customer'));
$expiresAt = time() + $ttlSeconds;
$tokenId = bin2hex(random_bytes(16));
$csrfToken = bin2hex(random_bytes(32));

$payload = [
    'iat' => time(),
    'exp' => $expiresAt,
    'sub' => (string)$user['id'],
    'email' => (string)$user['email'],
    'name' => (string)($user['name'] ?? 'QA User'),
    'role' => (string)($user['role'] ?? 'customer'),
    'tenant_id' => (string)($tenant['id'] ?? $requestedTenantId),
    'aud' => $surface,
    'auth_surface' => $surface,
    'jti' => $tokenId,
];

$userRepository->markSuccessfulLogin((string)$user['id']);
$userRepository->setActiveTokenId((string)$user['id'], $tokenId);

echo json_encode([
    'authCookieName' => $authCookieName,
    'authCookieValue' => JWT::encode($payload, $jwtSecret, 'HS256'),
    'csrfCookieName' => $csrfCookieName,
    'csrfCookieValue' => $csrfToken,
    'expiresAt' => $expiresAt,
    'tenantId' => (string)($tenant['id'] ?? $requestedTenantId),
    'surface' => $surface,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
