<?php

namespace App\Modules\LoyaltyRewards\Infrastructure\Wallet;

/**
 * Punto de entrada para construir el servicio de Google Wallet de un tenant.
 */
final class GoogleWalletFactory {
    public static function config(string $tenantId, array $settings, array $program): GoogleWalletConfig {
        return GoogleWalletConfig::fromEnvAndSettings($tenantId, $settings, $program);
    }

    /** Devuelve null si la configuracion del tenant esta incompleta o deshabilitada. */
    public static function make(string $tenantId, array $settings, array $program): ?GoogleWalletService {
        $config = self::config($tenantId, $settings, $program);
        if (!$config->isConfigured()) {
            return null;
        }

        return self::fromConfig($config);
    }

    /** @throws GoogleWalletException si el service account no se puede cargar */
    public static function fromConfig(GoogleWalletConfig $config): GoogleWalletService {
        $account = GoogleServiceAccount::load($config->serviceAccountPath());
        $http = new GoogleWalletHttpClient();
        $tokens = new GoogleOAuthTokenProvider($account, $http);

        return new GoogleWalletService($config, $account, $tokens, $http);
    }
}
