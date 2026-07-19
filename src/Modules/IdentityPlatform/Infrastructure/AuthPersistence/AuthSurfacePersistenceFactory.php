<?php

namespace App\Modules\IdentityPlatform\Infrastructure\AuthPersistence;

use App\Core\AuthSurface;
use App\Modules\IdentityPlatform\Application\AuthSurfacePersistence;
use App\Repositories\AuthSecurityRepository;
use App\Repositories\ContactMessageRepository;
use App\Repositories\CustomerAuthSecurityRepository;
use App\Repositories\CustomerPasswordResetTokenRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;

final class AuthSurfacePersistenceFactory
{
    /**
     * This is the canonical, executable surface-to-storage mapping. The factory
     * consumes these exact classes, so the boundary test can verify selection
     * without opening database connections.
     *
     * @return array{
     *   principal: class-string<UserRepository>,
     *   security_events: class-string<AuthSecurityRepository>,
     *   password_resets: class-string<PasswordResetTokenRepository>,
     *   contact_requests: class-string<ContactMessageRepository>,
     *   settings: class-string<SettingsRepository>
     * }
     */
    public static function bindingsForSurface(string $surface): array
    {
        return match ($surface) {
            AuthSurface::DASHBOARD => [
                'principal' => UserRepository::class,
                'security_events' => AuthSecurityRepository::class,
                'password_resets' => PasswordResetTokenRepository::class,
                'contact_requests' => ContactMessageRepository::class,
                'settings' => SettingsRepository::class,
            ],
            AuthSurface::ECOMMERCE => [
                'principal' => CustomerRepository::class,
                'security_events' => CustomerAuthSecurityRepository::class,
                'password_resets' => CustomerPasswordResetTokenRepository::class,
                'contact_requests' => ContactMessageRepository::class,
                'settings' => SettingsRepository::class,
            ],
            default => throw new \InvalidArgumentException('AUTH_SURFACE_PERSISTENCE_UNSUPPORTED'),
        };
    }

    public static function create(string $surface): AuthSurfacePersistence
    {
        $bindings = self::bindingsForSurface($surface);
        $principalClass = $bindings['principal'];
        $securityEventsClass = $bindings['security_events'];
        $passwordResetsClass = $bindings['password_resets'];
        $contactRequestsClass = $bindings['contact_requests'];
        $settingsClass = $bindings['settings'];

        return new AuthSurfacePersistence(
            $surface,
            new LegacyAuthPrincipalAdapter(new $principalClass()),
            new LegacyAuthSecurityEventAdapter(new $securityEventsClass()),
            new LegacyAuthPasswordResetAdapter(new $passwordResetsClass()),
            new LegacyAuthContactRequestAdapter(new $contactRequestsClass()),
            new LegacyAuthSettingsAdapter(new $settingsClass())
        );
    }
}
