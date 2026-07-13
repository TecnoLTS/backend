<?php

namespace App\Modules\LoyaltyRewards\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Modules\LoyaltyRewards\Application\LoyaltyNavigationService;

final class LoyaltyNavigationController {
    public function __construct(private readonly ?LoyaltyNavigationService $service = null) {}

    public function catalog(): void {
        Auth::requireAdmin();

        try {
            $tenantId = TenantContext::id() ?: TenantContext::slug();
            if (!is_string($tenantId) || trim($tenantId) === '') {
                throw new \RuntimeException('Tenant Loyalty no resuelto.');
            }
            $service = $this->service ?? new LoyaltyNavigationService();
            Response::json($service->catalog(trim($tenantId)));
        } catch (\Throwable $exception) {
            error_log(sprintf('[LOYALTY_NAVIGATION_ERROR] catalog failed: %s', $exception->getMessage()));
            Response::error(
                'No se pudo cargar el catalogo de navegacion.',
                500,
                'LOYALTY_NAVIGATION_CATALOG_FAILED'
            );
        }
    }
}
