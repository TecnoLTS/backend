<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use App\Core\AuthSurface;
use App\Modules\IdentityPlatform\Application\AuthSurfacePersistence;
use App\Modules\IdentityPlatform\Application\Ports\AuthContactRequestPort;
use App\Modules\IdentityPlatform\Application\Ports\AuthPasswordResetPort;
use App\Modules\IdentityPlatform\Application\Ports\AuthPrincipalPort;
use App\Modules\IdentityPlatform\Application\Ports\AuthSecurityEventPort;
use App\Modules\IdentityPlatform\Application\Ports\AuthSettingsPort;
use App\Modules\IdentityPlatform\Infrastructure\AuthPersistence\AuthSurfacePersistenceFactory;
use App\Modules\IdentityPlatform\Infrastructure\AuthPersistence\LegacyAuthContactRequestAdapter;
use App\Modules\IdentityPlatform\Infrastructure\AuthPersistence\LegacyAuthPasswordResetAdapter;
use App\Modules\IdentityPlatform\Infrastructure\AuthPersistence\LegacyAuthPrincipalAdapter;
use App\Modules\IdentityPlatform\Infrastructure\AuthPersistence\LegacyAuthSecurityEventAdapter;
use App\Modules\IdentityPlatform\Infrastructure\AuthPersistence\LegacyAuthSettingsAdapter;
use App\Repositories\AuthSecurityRepository;
use App\Repositories\ContactMessageRepository;
use App\Repositories\CustomerAuthSecurityRepository;
use App\Repositories\CustomerPasswordResetTokenRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;

/** @return never */
function failAuthSurfacePersistenceBoundary(string $message): void
{
    fwrite(STDERR, "Auth surface persistence boundary check failed: {$message}\n");
    exit(1);
}

/** @param array<string, class-string> $expected */
function expectSurfaceBindings(string $surface, array $expected): void
{
    $actual = AuthSurfacePersistenceFactory::bindingsForSurface($surface);
    if ($actual !== $expected) {
        failAuthSurfacePersistenceBoundary(
            "{$surface} bindings differ: expected=" . json_encode($expected) . ' actual=' . json_encode($actual)
        );
    }
}

expectSurfaceBindings(AuthSurface::DASHBOARD, [
    'principal' => UserRepository::class,
    'security_events' => AuthSecurityRepository::class,
    'password_resets' => PasswordResetTokenRepository::class,
    'contact_requests' => ContactMessageRepository::class,
    'settings' => SettingsRepository::class,
]);
expectSurfaceBindings(AuthSurface::ECOMMERCE, [
    'principal' => CustomerRepository::class,
    'security_events' => CustomerAuthSecurityRepository::class,
    'password_resets' => CustomerPasswordResetTokenRepository::class,
    'contact_requests' => ContactMessageRepository::class,
    'settings' => SettingsRepository::class,
]);

try {
    AuthSurfacePersistenceFactory::bindingsForSurface('unknown-surface');
    failAuthSurfacePersistenceBoundary('unknown surfaces must fail closed');
} catch (InvalidArgumentException $exception) {
    if ($exception->getMessage() !== 'AUTH_SURFACE_PERSISTENCE_UNSUPPORTED') {
        failAuthSurfacePersistenceBoundary('unknown surface failed with a non-canonical error');
    }
}

$adapterPorts = [
    LegacyAuthPrincipalAdapter::class => AuthPrincipalPort::class,
    LegacyAuthSecurityEventAdapter::class => AuthSecurityEventPort::class,
    LegacyAuthPasswordResetAdapter::class => AuthPasswordResetPort::class,
    LegacyAuthContactRequestAdapter::class => AuthContactRequestPort::class,
    LegacyAuthSettingsAdapter::class => AuthSettingsPort::class,
];
foreach ($adapterPorts as $adapter => $port) {
    if (!is_subclass_of($adapter, $port)) {
        failAuthSurfacePersistenceBoundary("{$adapter} does not implement {$port}");
    }
}

$bundle = new ReflectionClass(AuthSurfacePersistence::class);
if (!$bundle->isFinal()) {
    failAuthSurfacePersistenceBoundary('the per-surface persistence bundle must be final');
}
foreach (['surface', 'principal', 'securityEvents', 'passwordResets', 'contactRequests', 'settings'] as $propertyName) {
    if (!$bundle->hasProperty($propertyName) || !$bundle->getProperty($propertyName)->isReadOnly()) {
        failAuthSurfacePersistenceBoundary("bundle property {$propertyName} must be readonly");
    }
}

$userRepositorySource = file_get_contents(__DIR__ . '/../../../Repositories/UserRepository.php');
if (!is_string($userRepositorySource)
    || !str_contains($userRepositorySource, 'if (!$this->syncMemberships)')
    || !str_contains($userRepositorySource, "'active' AS account_status")
) {
    fwrite(STDERR, "Customer auth must not depend on IdentityPlatform tenant_memberships.\n");
    exit(1);
}

$controllerPath = __DIR__ . '/../Controllers/AuthController.php';
$controllerSource = file_get_contents($controllerPath);
if (!is_string($controllerSource) || $controllerSource === '') {
    failAuthSurfacePersistenceBoundary('AuthController source is unreadable');
}
foreach ([
    'App\\Repositories\\',
    'App\\Modules\\Commerce\\',
    'App\\Modules\\Mailer\\',
] as $forbiddenDependency) {
    if (str_contains($controllerSource, $forbiddenDependency)) {
        failAuthSurfacePersistenceBoundary("AuthController imports cross-domain dependency {$forbiddenDependency}");
    }
}
foreach ([
    'AuthSurfacePersistenceFactory::create($this->authSurface)',
    'private AuthPrincipalPort $userRepository',
    'private AuthSecurityEventPort $authSecurityRepository',
    'private AuthPasswordResetPort $passwordResetTokenRepository',
    'private AuthContactRequestPort $contactMessageRepository',
    'private AuthSettingsPort $settingsRepository',
] as $requiredBoundary) {
    if (!str_contains($controllerSource, $requiredBoundary)) {
        failAuthSurfacePersistenceBoundary("AuthController is missing boundary {$requiredBoundary}");
    }
}
if (preg_match('/new\s+(?:User|Customer|AuthSecurity|CustomerAuthSecurity|PasswordResetToken|CustomerPasswordResetToken|ContactMessage|Settings)Repository\s*\(/', $controllerSource)) {
    failAuthSurfacePersistenceBoundary('AuthController constructs a persistence repository directly');
}

echo "Auth surface persistence boundary check passed.\n";
