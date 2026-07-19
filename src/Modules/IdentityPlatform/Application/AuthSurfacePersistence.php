<?php

namespace App\Modules\IdentityPlatform\Application;

use App\Modules\IdentityPlatform\Application\Ports\AuthContactRequestPort;
use App\Modules\IdentityPlatform\Application\Ports\AuthPasswordResetPort;
use App\Modules\IdentityPlatform\Application\Ports\AuthPrincipalPort;
use App\Modules\IdentityPlatform\Application\Ports\AuthSecurityEventPort;
use App\Modules\IdentityPlatform\Application\Ports\AuthSettingsPort;

/**
 * Complete persistence boundary for one authentication surface.
 *
 * Keeping the ports in one immutable bundle prevents a controller instance
 * from accidentally mixing dashboard principals with ecommerce security or
 * password-reset records.
 */
final class AuthSurfacePersistence
{
    public function __construct(
        public readonly string $surface,
        public readonly AuthPrincipalPort $principal,
        public readonly AuthSecurityEventPort $securityEvents,
        public readonly AuthPasswordResetPort $passwordResets,
        public readonly AuthContactRequestPort $contactRequests,
        public readonly AuthSettingsPort $settings
    ) {
    }
}
