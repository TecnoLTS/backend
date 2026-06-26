<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Support;

class ClientConfigurationResolver
{
    public function __construct(private readonly array $baseConfig) {}

    public function resolve(array $clientContext, ?string $environment = null): array
    {
        $config = $this->baseConfig;

        if ($environment !== null) {
            $config['environment'] = $environment;
        }
        $resolvedEnvironment = (string) ($config['environment'] ?? 'pruebas');

        $config['empresa']['ruc'] = (string) ($clientContext['client_ruc'] ?? $config['empresa']['ruc']);
        $config['empresa']['razon_social'] = (string) ($clientContext['client_business_name'] ?? $config['empresa']['razon_social']);
        $config['empresa']['nombre_comercial'] = (string) (($clientContext['client_trade_name'] ?? '') ?: $config['empresa']['nombre_comercial']);
        $config['empresa']['direccion_matriz'] = (string) (($clientContext['client_address'] ?? '') ?: $config['empresa']['direccion_matriz']);
        $config['direccion_establecimiento'] = (string) (($clientContext['branch_address'] ?? '') ?: $config['empresa']['direccion_matriz']);
        $config['establecimiento'] = (string) ($clientContext['branch_code'] ?? $config['establecimiento']);
        $config['punto_emision'] = (string) ($clientContext['emission_point'] ?? $config['punto_emision']);
        $config['certificate']['path'] = (string) (($clientContext['certificate_path'] ?? '') ?: $config['certificate']['path']);
        $config['certificate']['password'] = (string) (($clientContext['certificate_password'] ?? '') ?: $config['certificate']['password']);
        $config['logo_path'] = (string) (($clientContext['logo_path'] ?? '') ?: ($config['logo_path'] ?? ''));

        foreach (['mail_enabled', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name', 'reply_to_address', 'reply_to_name'] as $field) {
            if (isset($clientContext[$field]) && $clientContext[$field] !== null && $clientContext[$field] !== '') {
                $config['mail'][$this->mapMailKey($field)] = $field === 'mail_enabled'
                    ? filter_var($clientContext[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $config['mail']['enabled']
                    : $clientContext[$field];
            }
        }
        $this->resolveRetrySettings($config, $clientContext, $resolvedEnvironment);

        return $config;
    }

    private function resolveRetrySettings(array &$config, array $clientContext, string $environment): void
    {
        $prefix = $environment === 'produccion' ? 'retry_production' : 'retry_test';
        $isActive = filter_var($clientContext[$prefix . '_is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $maxAttempts = $clientContext[$prefix . '_max_attempts'] ?? null;
        $delaySeconds = $clientContext[$prefix . '_delay_seconds'] ?? null;

        if ($isActive === false) {
            $config['retry']['max_attempts'] = 1;
            return;
        }
        if (is_numeric($maxAttempts)) {
            $config['retry']['max_attempts'] = max(1, (int) $maxAttempts);
        }
        if (is_numeric($delaySeconds)) {
            $config['retry']['delay_seconds'] = max(3600, (int) $delaySeconds);
        }
    }

    private function mapMailKey(string $field): string
    {
        return match ($field) {
            'mail_enabled' => 'enabled',
            'mail_host' => 'host',
            'mail_port' => 'port',
            'mail_encryption' => 'encryption',
            'mail_username' => 'username',
            'mail_password' => 'password',
            'mail_from_address' => 'from_address',
            'mail_from_name' => 'from_name',
            'reply_to_address' => 'reply_to_address',
            'reply_to_name' => 'reply_to_name',
            default => $field,
        };
    }
}
