<?php

namespace App\Modules\IdentityPlatform\Infrastructure;

final class TenantRuntimeRegistry
{
    private static string $source = 'uninitialized';
    private static ?string $lastError = null;

    public static function mergeConfigured(array $configuredTenants): array
    {
        $defaultTenant = self::defaultTenant($configuredTenants);
        $overrides = self::loadOverrides($defaultTenant);

        if (self::$source === 'fail_closed') {
            $merged = $configuredTenants;
            foreach ($merged as &$tenant) {
                if (is_array($tenant)) {
                    $tenant['status'] = 'inactive';
                    $tenant['provisioning_status'] = 'registry_unavailable';
                }
            }
            unset($tenant);
            return self::removeDomainCollisions($merged);
        }

        return self::mergeConfiguredWithOverrides($configuredTenants, $overrides);
    }

    /**
     * Pure merge used by the reconciliation worker after it has locked and
     * loaded a specific registry revision. It intentionally performs no DB or
     * signed-snapshot I/O, so the snapshot HMAC remains isolated to the API.
     */
    public static function mergeConfiguredWithOverrides(array $configuredTenants, array $overrides): array
    {
        $merged = $configuredTenants;
        $defaultTenant = self::defaultTenant($configuredTenants);
        foreach (($overrides['tenants'] ?? []) as $tenantId => $override) {
            if (!is_array($override)) {
                continue;
            }

            // Persisted overrides are intentionally sparse: lifecycle and
            // reconciliation writes should not duplicate static domains,
            // modules or branding. Hydrate an existing configured tenant
            // before validation, otherwise a perfectly valid sparse readiness
            // receipt is discarded for lacking domains and silently falls
            // back to pending_gateway. A tenant absent from configuration must
            // still provide its own complete identity/domains in the override.
            $existing = is_array($merged[$tenantId] ?? null) ? $merged[$tenantId] : [];
            $normalizationInput = $existing !== []
                ? array_replace($existing, $override)
                : $override;
            $normalized = self::normalizeTenant((string)$tenantId, $normalizationInput, $defaultTenant);
            if ($normalized === null) {
                continue;
            }

            $existing = is_array($merged[$normalized['id']] ?? null) ? $merged[$normalized['id']] : [];
            $merged[$normalized['id']] = array_replace_recursive($existing, $normalized);
        }

        return self::removeDomainCollisions($merged);
    }

    public static function export(array $configuredTenants): array
    {
        return self::exportMerged(self::mergeConfigured($configuredTenants));
    }

    /** @return array{version:int,generatedAt:string,tenants:list<array<string,mixed>>} */
    public static function exportWithOverrides(array $configuredTenants, array $overrides): array
    {
        return self::exportMerged(self::mergeConfiguredWithOverrides($configuredTenants, $overrides));
    }

    /** @return array{version:int,generatedAt:string,tenants:list<array<string,mixed>>} */
    private static function exportMerged(array $mergedTenants): array
    {
        $tenants = [];
        foreach ($mergedTenants as $tenant) {
            $status = strtolower(trim((string)($tenant['status'] ?? 'active')));
            $domains = self::normalizeDomains($tenant['domains'] ?? []);
            if ($status !== 'active' || $domains === []) {
                continue;
            }

            $record = [
                'id' => (string)($tenant['id'] ?? $tenant['slug'] ?? ''),
                'slug' => (string)($tenant['slug'] ?? $tenant['id'] ?? ''),
                'status' => $status,
                'domains' => $domains,
                'primaryDomain' => $domains[0],
                'enabledModules' => array_values(array_unique(array_map(
                    static fn ($module): string => strtolower(trim((string)$module)),
                    is_array($tenant['enabled_modules'] ?? null) ? $tenant['enabled_modules'] : []
                ))),
                'provisioningStatus' => strtolower(trim((string)($tenant['provisioning_status'] ?? 'pending_gateway'))),
                'provisioningDesiredStateHash' => strtolower(trim((string)($tenant['provisioning_desired_hash'] ?? ''))),
            ];
            sort($record['enabledModules'], SORT_STRING);
            $record['desiredStateHash'] = self::desiredStateHash($record);
            $record['businessReady'] = $record['provisioningStatus'] === 'ready'
                && preg_match('/^[a-f0-9]{64}$/', $record['provisioningDesiredStateHash']) === 1
                && hash_equals($record['desiredStateHash'], $record['provisioningDesiredStateHash']);
            $tenants[] = $record;
        }

        usort($tenants, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        return [
            'version' => 1,
            'generatedAt' => gmdate('c'),
            'tenants' => $tenants,
        ];
    }

    public static function desiredStateHash(array $tenant): string
    {
        $modules = array_values(array_unique(array_filter(array_map(
            static fn ($module): string => strtolower(trim((string)$module)),
            is_array($tenant['enabledModules'] ?? null)
                ? $tenant['enabledModules']
                : (is_array($tenant['enabled_modules'] ?? null) ? $tenant['enabled_modules'] : [])
        ))));
        sort($modules, SORT_STRING);
        $canonical = [
            'id' => self::normalizeSlug((string)($tenant['id'] ?? '')),
            'slug' => self::normalizeSlug((string)($tenant['slug'] ?? $tenant['id'] ?? '')),
            'status' => strtolower(trim((string)($tenant['status'] ?? 'active'))),
            'domains' => self::normalizeDomains($tenant['domains'] ?? []),
            'enabledModules' => $modules,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function healthStatus(): array
    {
        return [
            'ready' => in_array(self::$source, ['database', 'signed_snapshot_cache', 'signed_snapshot', 'database_snapshot_degraded'], true),
            'degraded' => in_array(self::$source, ['signed_snapshot', 'database_snapshot_degraded'], true),
            'source' => self::$source,
            'error' => self::$lastError,
        ];
    }

    public static function refreshSnapshot(): void
    {
        try {
            $state = (new TenantRuntimeRegistryStore())->getState();
            TenantRuntimeRegistrySnapshot::save($state['registry'], $state['revision']);
            self::$source = 'database';
            self::$lastError = null;
        } catch (\Throwable $exception) {
            self::$source = 'database_snapshot_degraded';
            self::$lastError = $exception->getMessage();
            throw $exception;
        }
    }

    private static function loadOverrides(array $baseTenant): array
    {
        // Tenant discovery is on the hot path of every non-liveness request.
        // The signed snapshot is refreshed transactionally after registry
        // mutations and by the reconciler, so it is the bounded local cache
        // for reads. Querying the control-plane database here would add a
        // connection and a filesystem verification to every API request.
        try {
            $state = TenantRuntimeRegistrySnapshot::loadState();
            self::$source = 'signed_snapshot_cache';
            self::$lastError = null;
            return $state['registry'];
        } catch (\Throwable $snapshotException) {
            try {
                $state = (new TenantRuntimeRegistryStore())->getState();
                $registry = $state['registry'];
                TenantRuntimeRegistrySnapshot::save($registry, $state['revision']);
                self::$source = 'database';
                self::$lastError = null;
                return $registry;
            } catch (\Throwable $databaseException) {
                // A valid snapshot was not available and the canonical store
                // cannot be reached. Static tenant defaults must never revive
                // a suspended or deprovisioned tenant, so fail closed.
                self::$source = 'fail_closed';
                self::$lastError = $snapshotException->getMessage() . '; ' . $databaseException->getMessage();
                error_log('[TENANT_RUNTIME_REGISTRY_UNAVAILABLE] ' . $databaseException->getMessage());
                error_log('[TENANT_RUNTIME_REGISTRY_FAIL_CLOSED] ' . $snapshotException->getMessage());
                return ['tenants' => []];
            }
        }
    }

    /**
     * Explicit control-plane read used by readiness/failure exercises when a
     * caller needs to prove that PostgreSQL is reachable while preserving the
     * snapshot-first request path.
     */
    public static function verifyCanonicalStore(): int
    {
        try {
            $state = (new TenantRuntimeRegistryStore())->getState();
            try {
                TenantRuntimeRegistrySnapshot::save($state['registry'], $state['revision']);
                self::$source = 'database';
                self::$lastError = null;
            } catch (\Throwable $snapshotException) {
                self::$source = 'database_snapshot_degraded';
                self::$lastError = $snapshotException->getMessage();
                error_log('[TENANT_RUNTIME_REGISTRY_SNAPSHOT_WRITE_FAILED] ' . $snapshotException->getMessage());
            }
            return $state['revision'];
        } catch (\Throwable $databaseException) {
            try {
                TenantRuntimeRegistrySnapshot::load();
                self::$source = 'signed_snapshot';
                self::$lastError = $databaseException->getMessage();
            } catch (\Throwable $snapshotException) {
                self::$source = 'fail_closed';
                self::$lastError = $databaseException->getMessage() . '; ' . $snapshotException->getMessage();
                error_log('[TENANT_RUNTIME_REGISTRY_FAIL_CLOSED] ' . $snapshotException->getMessage());
            }

            throw $databaseException;
        }
    }

    private static function defaultTenant(array $configuredTenants): array
    {
        $defaultId = trim((string)($_ENV['DEFAULT_TENANT'] ?? getenv('DEFAULT_TENANT') ?: 'paramascotasec'));
        if (is_array($configuredTenants[$defaultId] ?? null)) {
            return $configuredTenants[$defaultId];
        }

        $first = reset($configuredTenants);
        return is_array($first) ? $first : [];
    }

    private static function normalizeTenant(string $mapId, array $tenant, array $defaults): ?array
    {
        $id = self::normalizeSlug((string)($tenant['id'] ?? $mapId));
        $slug = self::normalizeSlug((string)($tenant['slug'] ?? $id));
        $domains = self::normalizeDomains($tenant['domains'] ?? []);
        if ($id === '' || $slug === '' || $domains === []) {
            return null;
        }

        $scheme = strtolower(trim((string)($tenant['scheme'] ?? 'https')));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }
        $status = strtolower(trim((string)($tenant['status'] ?? 'active')));
        if (!in_array($status, ['active', 'suspended', 'inactive'], true)) {
            $status = 'inactive';
        }

        $defaultEcommerceConfiguration = self::normalizeEcommerceConfigurationAliases(
            is_array($defaults['ecommerce_configuration'] ?? null)
                ? $defaults['ecommerce_configuration']
                : []
        );
        $tenantEcommerceConfiguration = self::normalizeEcommerceConfigurationAliases(
            is_array($tenant['ecommerce_configuration'] ?? null)
                ? $tenant['ecommerce_configuration']
                : []
        );

        $mergedEcommerceConfiguration = array_replace_recursive(
            $defaultEcommerceConfiguration,
            $tenantEcommerceConfiguration
        );
        // These fields belong exclusively to the canonical registry entry.
        // Static config/env defaults must not make an unmigrated tenant appear
        // fiscally configured after cutover.
        foreach ([
            'defaultTaxRate',
            'purchaseVatCreditCurrentRate',
            'purchaseVatCreditCarryforwardRate',
        ] as $canonicalTaxField) {
            if (!array_key_exists($canonicalTaxField, $tenantEcommerceConfiguration)) {
                unset($mergedEcommerceConfiguration[$canonicalTaxField]);
            }
        }

        return [
            'id' => $id,
            'slug' => $slug,
            'name' => trim((string)($tenant['name'] ?? $id)) ?: $id,
            'status' => $status,
            'domains' => $domains,
            'allowed_origins' => array_map(static fn (string $domain): string => "{$scheme}://{$domain}", $domains),
            'app_url' => "{$scheme}://{$domains[0]}",
            'public_base_url' => "{$scheme}://{$domains[0]}",
            'db' => is_array($tenant['db'] ?? null) ? $tenant['db'] : ($defaults['db'] ?? []),
            'enabled_modules' => is_array($tenant['enabled_modules'] ?? null) ? $tenant['enabled_modules'] : [],
            'platform_admin_emails' => $defaults['platform_admin_emails'] ?? [],
            'platform_admin_domains' => $defaults['platform_admin_domains'] ?? [],
            'branding' => is_array($tenant['branding'] ?? null) ? $tenant['branding'] : [],
            'ecommerce_configuration' => $mergedEcommerceConfiguration ?: null,
            'provisioning_status' => (string)($tenant['provisioning_status'] ?? 'pending_gateway'),
            'provisioning_desired_hash' => strtolower(trim((string)($tenant['provisioning_desired_hash'] ?? ''))),
        ];
    }

    /** @return array<string,mixed> */
    private static function normalizeEcommerceConfigurationAliases(array $configuration): array
    {
        if (!array_key_exists('defaultTaxRate', $configuration)
            && array_key_exists('default_tax_rate', $configuration)) {
            $configuration['defaultTaxRate'] = $configuration['default_tax_rate'];
        }
        unset($configuration['default_tax_rate']);
        return $configuration;
    }

    private static function removeDomainCollisions(array $tenants): array
    {
        $owners = [];
        foreach ($tenants as $tenantId => &$tenant) {
            if (!is_array($tenant)) {
                unset($tenants[$tenantId]);
                continue;
            }

            $uniqueDomains = [];
            foreach (self::normalizeDomains($tenant['domains'] ?? []) as $domain) {
                if (isset($owners[$domain]) && $owners[$domain] !== (string)$tenantId) {
                    error_log(sprintf(
                        '[TENANT_DOMAIN_COLLISION] domain=%s owners=%s,%s',
                        $domain,
                        $owners[$domain],
                        (string)$tenantId
                    ));
                    continue;
                }
                $owners[$domain] = (string)$tenantId;
                $uniqueDomains[] = $domain;
            }
            $tenant['domains'] = $uniqueDomains;
        }
        unset($tenant);

        return $tenants;
    }

    private static function normalizeDomains($domains): array
    {
        if (!is_array($domains)) {
            $domains = [$domains];
        }

        $normalized = [];
        foreach ($domains as $domain) {
            $host = strtolower(rtrim(trim((string)$domain), '.'));
            if (
                $host === ''
                || strlen($host) > 253
                || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)
            ) {
                continue;
            }
            $normalized[] = $host;
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }
}
