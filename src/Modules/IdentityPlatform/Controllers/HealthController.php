<?php

namespace App\Modules\IdentityPlatform\Controllers;

use App\Core\Response;
use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry;
use App\Modules\IdentityPlatform\Infrastructure\Readiness\RuntimeReadinessFactory;

class HealthController {
    /**
     * Liveness intentionally avoids every external dependency. If this method
     * answers, the PHP runtime and router are alive.
     */
    public function live(): void {
        Response::json([
            'estado' => 'ok',
            'fecha' => date('Y-m-d H:i:s'),
            'servicio' => 'platform-core',
        ]);
    }

    /**
     * Readiness verifies that the runtime can serve database-backed requests.
     */
    public function ready(): void {
        try {
            $db = Database::getInstance();
            $db->query('SELECT 1');
            $registryRevision = TenantRuntimeRegistry::verifyCanonicalStore();
            $registry = TenantRuntimeRegistry::healthStatus();
            if (($registry['ready'] ?? false) !== true) {
                Response::error('Registro de tenants no confirmado', 503, 'HEALTH_TENANT_REGISTRY_UNAVAILABLE');
                return;
            }
            $dependencyStatus = [];
            foreach (RuntimeReadinessFactory::dependencies() as $dependency) {
                $dependencyStatus = [...$dependencyStatus, ...$dependency->assertReady()];
            }
            $tenant = TenantContext::get() ?? [];
            Response::json([
                'estado' => 'ok',
                'fecha' => date('Y-m-d H:i:s'),
                'base_de_datos' => 'conectada',
                'registro_tenants' => ($registry['degraded'] ?? false) ? 'snapshot_degradado' : 'confirmado',
                'tenant_id' => TenantContext::id(),
                'tenant_slug' => TenantContext::slug(),
                'tenant_desired_state_hash' => TenantRuntimeRegistry::desiredStateHash($tenant),
                'tenant_registry_revision' => $registryRevision,
                ...$dependencyStatus,
            ]);
        } catch (\Throwable $e) {
            Response::error('Base de datos no disponible', 503, 'HEALTH_DB_UNAVAILABLE');
        }
    }

    /** Backwards-compatible liveness alias. Use /api/readyz for dependencies. */
    public function status(): void {
        $this->live();
    }
}
