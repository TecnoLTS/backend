<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\TenantContext;
use App\Modules\Commerce\Application\TenantDefaultTaxRate;
use App\Modules\Commerce\Infrastructure\Adapters\TenantRegistryCommerceTaxConfigurationAdapter;
use App\Modules\IdentityPlatform\Infrastructure\DatabaseTenantTaxRegistryControlPlane;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistryStore;
use App\Repositories\SettingsRepository;
use Dotenv\Dotenv;

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

/** @return array<string,string|bool> */
function vatCutoverOptions(array $arguments): array
{
    $options = ['execute' => false, 'maintenance-window-confirmed' => false];
    foreach ($arguments as $argument) {
        if ($argument === '--execute') {
            $options['execute'] = true;
            continue;
        }
        if ($argument === '--maintenance-window-confirmed') {
            $options['maintenance-window-confirmed'] = true;
            continue;
        }
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('VAT_CUTOVER_ARGUMENT_INVALID');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($name, [
            'prefer',
            'missing-default-rate',
            'missing-credit-current',
            'missing-credit-carryforward',
            'expected-revision',
        ], true) || isset($options[$name]) || trim($value) === '') {
            throw new InvalidArgumentException('VAT_CUTOVER_ARGUMENT_INVALID');
        }
        $options[$name] = trim($value);
    }
    if (isset($options['prefer']) && !in_array($options['prefer'], ['legacy', 'canonical'], true)) {
        throw new InvalidArgumentException('VAT_CUTOVER_PREFERENCE_INVALID');
    }
    if ($options['execute'] === true) {
        if ($options['maintenance-window-confirmed'] !== true) {
            throw new InvalidArgumentException('VAT_CUTOVER_MAINTENANCE_WINDOW_REQUIRED');
        }
        if (!isset($options['expected-revision'])
            || preg_match('/^[1-9][0-9]*$/', (string)$options['expected-revision']) !== 1) {
            throw new InvalidArgumentException('VAT_CUTOVER_EXPECTED_REVISION_REQUIRED');
        }
    }
    return $options;
}

/** @return array{value:float,source:string} */
function selectVatCutoverValue(
    mixed $canonical,
    mixed $legacy,
    mixed $missingDefault,
    ?string $preference,
    string $field,
    string $tenantId
): array {
    $canonicalRate = TenantDefaultTaxRate::normalize($canonical);
    $legacyRate = TenantDefaultTaxRate::normalize($legacy);
    if ($canonicalRate !== null && $legacyRate !== null && $canonicalRate !== $legacyRate) {
        if ($preference === null) {
            throw new RuntimeException("VAT_CUTOVER_CONFLICT tenant={$tenantId} field={$field}");
        }
        return $preference === 'legacy'
            ? ['value' => $legacyRate, 'source' => 'legacy_setting_explicit_preference']
            : ['value' => $canonicalRate, 'source' => 'canonical_registry_explicit_preference'];
    }
    if ($canonicalRate !== null) {
        return ['value' => $canonicalRate, 'source' => 'canonical_registry'];
    }
    if ($legacyRate !== null) {
        return ['value' => $legacyRate, 'source' => 'legacy_setting'];
    }
    $defaultRate = TenantDefaultTaxRate::normalize($missingDefault);
    if ($defaultRate === null) {
        throw new RuntimeException("VAT_CUTOVER_DEFAULT_REQUIRED tenant={$tenantId} field={$field}");
    }
    return ['value' => $defaultRate, 'source' => 'operator_explicit_default'];
}

try {
    $options = vatCutoverOptions(array_slice($argv, 1));
    $execute = $options['execute'] === true;
    $preference = isset($options['prefer']) ? (string)$options['prefer'] : null;
    $store = new TenantRuntimeRegistryStore();
    $state = $store->getState();
    $expectedRevision = isset($options['expected-revision'])
        ? (int)$options['expected-revision']
        : $state['revision'];
    if ($execute && $expectedRevision !== $state['revision']) {
        throw new RuntimeException(sprintf(
            'VAT_CUTOVER_REVISION_CONFLICT expected=%d actual=%d',
            $expectedRevision,
            $state['revision']
        ));
    }
    $configured = require __DIR__ . '/../config/tenants.php';
    $effectiveTenants = TenantRuntimeRegistry::mergeConfiguredWithOverrides(
        is_array($configured) ? $configured : [],
        $state['registry']
    );
    $workingRegistry = $state['registry'];
    $controlPlane = new DatabaseTenantTaxRegistryControlPlane($store);
    $plan = [];

    foreach ($effectiveTenants as $tenantId => $effectiveTenant) {
        if (!is_array($effectiveTenant)) {
            continue;
        }
        $enabledModules = is_array($effectiveTenant['enabled_modules'] ?? null)
            ? $effectiveTenant['enabled_modules']
            : [];
        if (!in_array('ecommerce', $enabledModules, true)) {
            continue;
        }
        TenantContext::set($effectiveTenant);
        $settings = new SettingsRepository();
        $storedTenant = is_array($workingRegistry['tenants'][$tenantId] ?? null)
            ? $workingRegistry['tenants'][$tenantId]
            : null;
        $storedConfiguration = is_array($storedTenant['ecommerce_configuration'] ?? null)
            ? $storedTenant['ecommerce_configuration']
            : [];
        $rate = selectVatCutoverValue(
            $storedConfiguration['defaultTaxRate'] ?? $storedConfiguration['default_tax_rate'] ?? null,
            $settings->get('vat_rate'),
            $options['missing-default-rate'] ?? null,
            $preference,
            'defaultTaxRate',
            (string)$tenantId
        );
        if (TenantDefaultTaxRate::normalizeVatRate($rate['value']) === null) {
            throw new RuntimeException("VAT_CUTOVER_SRI_RATE_UNSUPPORTED tenant={$tenantId}");
        }
        $creditCurrent = selectVatCutoverValue(
            $storedConfiguration['purchaseVatCreditCurrentRate'] ?? null,
            $settings->get('sri_purchase_vat_credit_current_rate'),
            $options['missing-credit-current'] ?? null,
            $preference,
            'purchaseVatCreditCurrentRate',
            (string)$tenantId
        );
        $creditCarryforward = selectVatCutoverValue(
            $storedConfiguration['purchaseVatCreditCarryforwardRate'] ?? null,
            $settings->get('sri_purchase_vat_credit_carryforward_rate'),
            $options['missing-credit-carryforward'] ?? null,
            $preference,
            'purchaseVatCreditCarryforwardRate',
            (string)$tenantId
        );
        $entry = [
            'tenantId' => (string)$tenantId,
            'rate' => $rate,
            'creditCurrent' => $creditCurrent,
            'creditCarryforward' => $creditCarryforward,
        ];
        if ($execute) {
            if ($storedTenant !== null) {
                $entry['result'] = (new TenantRegistryCommerceTaxConfigurationAdapter($controlPlane))
                    ->updateTaxConfiguration(
                    $rate['value'],
                    $creditCurrent['value'],
                    $creditCarryforward['value'],
                    'vat-rate-cutover-v1',
                    $expectedRevision
                );
            } else {
                // A configured/effective tenant may predate the canonical DB
                // registry. Seed exactly that effective tenant and its full
                // tax policy in one audited CAS; runtime writers still refuse
                // implicit tenant recreation.
                $storedTenant = $effectiveTenant;
                $storedConfiguration = is_array($storedTenant['ecommerce_configuration'] ?? null)
                    ? $storedTenant['ecommerce_configuration']
                    : [];
                unset($storedConfiguration['default_tax_rate']);
                $storedConfiguration['defaultTaxRate'] = $rate['value'];
                $storedConfiguration['purchaseVatCreditCurrentRate'] = $creditCurrent['value'];
                $storedConfiguration['purchaseVatCreditCarryforwardRate'] = $creditCarryforward['value'];
                $storedTenant['ecommerce_configuration'] = $storedConfiguration;
                $storedTenant['updated_at'] = gmdate('c');
                $workingRegistry['version'] = 1;
                $workingRegistry['tenants'][(string)$tenantId] = $storedTenant;
                $requestId = sprintf('vat-cutover.%s.%s', $tenantId, bin2hex(random_bytes(8)));
                $requestHash = hash('sha256', json_encode([
                    'operation' => 'tenant.ecommerce-tax.cutover',
                    'tenantId' => (string)$tenantId,
                    'expectedRevision' => $expectedRevision,
                    'configuration' => $storedConfiguration,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $receipt = $controlPlane->compareAndSet(
                    $workingRegistry,
                    $expectedRevision,
                    $requestId,
                    $requestHash,
                    'tenant.ecommerce-tax.cutover',
                    (string)$tenantId,
                    'platform',
                    'vat-rate-cutover-v1'
                );
                try {
                    $controlPlane->refreshRuntimeProjection();
                    $projectionSynchronized = true;
                } catch (Throwable $projectionException) {
                    error_log('[VAT_CUTOVER_PROJECTION_REFRESH_FAILED] ' . $projectionException->getMessage());
                    $projectionSynchronized = false;
                }
                $entry['result'] = [
                    'rate' => $rate['value'],
                    'credit_current_rate' => $creditCurrent['value'],
                    'credit_carryforward_rate' => $creditCarryforward['value'],
                    'revision' => $receipt['revision'],
                    'applied' => $receipt['applied'],
                    'projection_synchronized' => $projectionSynchronized,
                ];
            }
            $expectedRevision = (int)$entry['result']['revision'];
            $workingConfiguration = is_array($storedTenant['ecommerce_configuration'] ?? null)
                ? $storedTenant['ecommerce_configuration']
                : [];
            unset($workingConfiguration['default_tax_rate']);
            $workingConfiguration['defaultTaxRate'] = $rate['value'];
            $workingConfiguration['purchaseVatCreditCurrentRate'] = $creditCurrent['value'];
            $workingConfiguration['purchaseVatCreditCarryforwardRate'] = $creditCarryforward['value'];
            $storedTenant['ecommerce_configuration'] = $workingConfiguration;
            $workingRegistry['tenants'][(string)$tenantId] = $storedTenant;
            if (($entry['result']['projection_synchronized'] ?? false) !== true) {
                throw new RuntimeException(
                    "VAT_CUTOVER_PROJECTION_RECONCILIATION_REQUIRED tenant={$tenantId} revision={$expectedRevision}"
                );
            }
        }
        $plan[] = $entry;
    }
    TenantContext::clear();

    fwrite(STDOUT, json_encode([
        'event' => $execute ? 'vat_registry_cutover_complete' : 'vat_registry_cutover_plan',
        'executed' => $execute,
        'startingRevision' => $state['revision'],
        'finalRevision' => $expectedRevision,
        'tenants' => $plan,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    if (!$execute) {
        fwrite(STDOUT, "Plan only. Add --execute after reviewing every source decision.\n");
    }
} catch (Throwable $exception) {
    TenantContext::clear();
    fwrite(STDERR, '[vat-registry-cutover] failed_closed ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
