<?php

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Application\BillingGateway;

final class BillingGatewayFactory {
    public static function create(): BillingGateway {
        $driver = strtolower(trim((string)($_ENV['BILLING_GATEWAY_DRIVER'] ?? getenv('BILLING_GATEWAY_DRIVER') ?: 'native')));

        if ($driver === '' || $driver === 'native') {
            $native = new NativeBillingGateway();
            $native->assertReady();
            return $native;
        }

        if ($driver === 'native_fallback' || $driver === 'facturador_http') {
            throw new \RuntimeException(sprintf(
                'BILLING_GATEWAY_DRIVER=%s fue retirado del flujo orquestado. Use Billing nativo dentro de platform-core.',
                $driver
            ));
        }

        throw new \RuntimeException(sprintf('BILLING_GATEWAY_DRIVER no soportado: %s', $driver));
    }
}
