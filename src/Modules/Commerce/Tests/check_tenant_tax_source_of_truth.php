<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Core\TenantContext;
use App\Modules\Commerce\Application\TenantDefaultTaxRate;
use App\Modules\Commerce\Infrastructure\Adapters\TenantRegistryCommerceTaxConfigurationAdapter;
use App\Modules\IdentityPlatform\Application\Ports\TenantTaxRegistryControlPlanePort;
use App\Modules\IdentityPlatform\Controllers\TenantController;
use App\Modules\IdentityPlatform\Infrastructure\TenantRuntimeRegistry;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\InventoryIntelligenceService;
use App\Support\ModuleOpenApiSchemaCatalog;

$root = dirname(__DIR__, 4);
$failures = [];

TenantContext::set([
    'id' => 'tax-policy-test',
    'ecommerce_configuration' => ['defaultTaxRate' => 13],
]);
if (TenantDefaultTaxRate::current() !== 13.0) {
    $failures[] = 'canonical camelCase tenant tax rate was not resolved';
}

if (TenantDefaultTaxRate::normalize(101) !== null
    || TenantDefaultTaxRate::normalize(-1) !== null
    || TenantDefaultTaxRate::normalize('invalid') !== null) {
    $failures[] = 'tax rate bounds are not deterministic';
}
TenantContext::clear();

// Deterministic A/B interleaving exercise: mutation A commits revision 101,
// then an unrelated mutation B advances canonical state to 102 before the
// adapter returns. A must still return its own receipt/ETag, never B's.
$interleavingControlPlane = new class implements TenantTaxRegistryControlPlanePort {
    /** @var array{revision:int,registry:array} */
    public array $currentState = [
        'revision' => 100,
        'registry' => [
            'version' => 1,
            'tenants' => [
                'cas-tax-test' => [
                    'id' => 'cas-tax-test',
                    'ecommerce_configuration' => [
                        'defaultTaxRate' => 15.0,
                        'purchaseVatCreditCurrentRate' => 60.0,
                        'purchaseVatCreditCarryforwardRate' => 40.0,
                    ],
                ],
            ],
        ],
    ];

    public function getState(): array
    {
        return $this->currentState;
    }

    public function compareAndSet(
        array $registry,
        int $expectedRevision,
        string $requestId,
        string $requestHash,
        string $operation,
        string $targetTenantId,
        string $actorTenantId,
        string $actorUserId
    ): array {
        if ($expectedRevision !== 100) {
            throw new RuntimeException('CAS_TEST_EXPECTED_REVISION_MISMATCH');
        }
        $receipt = [
            'revision' => 101,
            'applied' => true,
            'idempotent' => false,
        ];
        $concurrentRegistry = $registry;
        $concurrentRegistry['tenants']['cas-tax-test']['ecommerce_configuration']['defaultTaxRate'] = 99.0;
        $this->currentState = ['revision' => 102, 'registry' => $concurrentRegistry];
        return $receipt;
    }

    public function refreshRuntimeProjection(): void
    {
    }
};
TenantContext::set(['id' => 'cas-tax-test']);
$casResult = (new TenantRegistryCommerceTaxConfigurationAdapter($interleavingControlPlane))
    ->updateTaxConfiguration(12, 37.5, 62.5, 'cas-test', 100);
TenantContext::clear();
if (($casResult['revision'] ?? null) !== 101
    || ($casResult['rate'] ?? null) !== 12.0
    || ($interleavingControlPlane->currentState['revision'] ?? null) !== 102) {
    $failures[] = 'CAS writer mixed mutation A representation with concurrent mutation B revision';
}

try {
    TenantDefaultTaxRate::fromTenant([
        'id' => 'unmigrated-tax-policy-test',
        'ecommerce_configuration' => [],
    ]);
    $failures[] = 'missing canonical tenant tax configuration did not fail closed';
} catch (DomainException $exception) {
    if ($exception->getMessage() !== 'TENANT_TAX_CONFIGURATION_REQUIRED') {
        $failures[] = 'missing canonical tenant tax configuration failed with the wrong contract';
    }
}

$tenantController = (new ReflectionClass(TenantController::class))->newInstanceWithoutConstructor();
$sanitizeConfiguration = new ReflectionMethod(TenantController::class, 'sanitizeEcommerceConfiguration');
$sanitized = $sanitizeConfiguration->invoke($tenantController, [
    'vertical' => 'petshop',
    'enabledCapabilities' => ['products', 'pricing'],
    'defaultTaxRate' => 13,
    'purchaseVatCreditCurrentRate' => 60,
    'purchaseVatCreditCarryforwardRate' => 40,
], true);
if (($sanitized['defaultTaxRate'] ?? null) !== 13.0) {
    $failures[] = 'tenant lifecycle serialization drops defaultTaxRate';
}
try {
    $sanitizeConfiguration->invoke($tenantController, [
        'vertical' => 'petshop',
        'enabledCapabilities' => ['products', 'pricing'],
    ], true);
    $failures[] = 'existing tenant sanitization silently recreated a missing tax policy';
} catch (ReflectionException $exception) {
    throw $exception;
} catch (Throwable $exception) {
    $cause = $exception instanceof ReflectionException ? $exception->getPrevious() : $exception;
    if (!$cause instanceof DomainException || $cause->getMessage() !== 'TENANT_TAX_CONFIGURATION_REQUIRED') {
        $failures[] = 'existing tenant sanitization failed with the wrong missing-tax contract';
    }
}
$preserveTaxConfiguration = new ReflectionMethod(TenantController::class, 'preserveStoredTaxConfiguration');
$roundTripInput = $preserveTaxConfiguration->invoke($tenantController, [
    'vertical' => 'technology',
    'enabledCapabilities' => ['products'],
], [
    'defaultTaxRate' => 12,
    'purchaseVatCreditCurrentRate' => 70,
    'purchaseVatCreditCarryforwardRate' => 30,
]);
$roundTripConfiguration = $sanitizeConfiguration->invoke($tenantController, $roundTripInput, true);
if (($roundTripConfiguration['defaultTaxRate'] ?? null) !== 12.0
    || ($roundTripConfiguration['purchaseVatCreditCurrentRate'] ?? null) !== 70.0
    || ($roundTripConfiguration['purchaseVatCreditCarryforwardRate'] ?? null) !== 30.0) {
    $failures[] = 'Tenant Admin PATCH does not preserve the complete canonical tax policy';
}
$preserveModuleConfiguration = new ReflectionMethod(
    TenantController::class,
    'preserveEcommerceConfigurationForModuleChange'
);
$dormantTaxConfiguration = [
    'defaultTaxRate' => 0.0,
    'purchaseVatCreditCurrentRate' => 37.5,
    'purchaseVatCreditCarryforwardRate' => 62.5,
];
$disabledConfiguration = $preserveModuleConfiguration->invoke(
    $tenantController,
    $dormantTaxConfiguration,
    ['dashboard', 'users']
);
$reactivatedConfiguration = $preserveModuleConfiguration->invoke(
    $tenantController,
    $disabledConfiguration,
    ['dashboard', 'users', 'ecommerce']
);
if ($disabledConfiguration !== $dormantTaxConfiguration
    || $reactivatedConfiguration !== $dormantTaxConfiguration) {
    $failures[] = 'ecommerce disable/enable destroys or resets dormant canonical tax configuration';
}

$mergedTenants = TenantRuntimeRegistry::mergeConfiguredWithOverrides([
    'alias-test' => [
        'id' => 'alias-test',
        'slug' => 'alias-test',
        'domains' => ['alias-test.example.com'],
        'ecommerce_configuration' => ['defaultTaxRate' => 15],
    ],
], [
    'version' => 1,
    'tenants' => [
        'alias-test' => [
            'id' => 'alias-test',
            'slug' => 'alias-test',
            'domains' => ['alias-test.example.com'],
            'ecommerce_configuration' => ['default_tax_rate' => 12],
        ],
    ],
]);
$mergedConfiguration = $mergedTenants['alias-test']['ecommerce_configuration'] ?? [];
if (($mergedConfiguration['defaultTaxRate'] ?? null) !== 12
    || array_key_exists('default_tax_rate', $mergedConfiguration)) {
    $failures[] = 'legacy default_tax_rate does not override the configured camelCase default during cutover';
}
$unmigratedTenants = TenantRuntimeRegistry::mergeConfiguredWithOverrides([
    'unmigrated-test' => [
        'id' => 'unmigrated-test',
        'slug' => 'unmigrated-test',
        'domains' => ['unmigrated-test.example.com'],
        'ecommerce_configuration' => [
            'defaultTaxRate' => 15,
            'purchaseVatCreditCurrentRate' => 60,
            'purchaseVatCreditCarryforwardRate' => 40,
        ],
    ],
], [
    'version' => 1,
    'tenants' => [
        'unmigrated-test' => [
            'id' => 'unmigrated-test',
            'slug' => 'unmigrated-test',
            'domains' => ['unmigrated-test.example.com'],
            'ecommerce_configuration' => [],
        ],
    ],
]);
if (array_intersect_key(
    $unmigratedTenants['unmigrated-test']['ecommerce_configuration'] ?? [],
    array_flip(['defaultTaxRate', 'purchaseVatCreditCurrentRate', 'purchaseVatCreditCarryforwardRate'])
) !== []) {
    $failures[] = 'static config/env silently repopulates missing canonical tenant tax fields';
}

TenantContext::set([
    'id' => 'order-tax-policy-test',
    'ecommerce_configuration' => ['defaultTaxRate' => 13],
]);
$orderRepositoryReflection = new ReflectionClass(OrderRepository::class);
$orderRepository = $orderRepositoryReflection->newInstanceWithoutConstructor();
$orderTaxMethod = $orderRepositoryReflection->getMethod('getProductTaxRateForAttributes');
$productRepositoryReflection = new ReflectionClass(ProductRepository::class);
$productRepository = $productRepositoryReflection->newInstanceWithoutConstructor();
$productTaxMethod = $productRepositoryReflection->getMethod('getProductTaxRateForAttributes');
$inventoryReflection = new ReflectionClass(InventoryIntelligenceService::class);
$inventory = $inventoryReflection->newInstanceWithoutConstructor();
$inventoryReflection->getProperty('defaultVatRate')->setValue($inventory, 13.0);
$inventoryTaxMethod = $inventoryReflection->getMethod('taxRateForAttributes');
$taxPolicyCases = [
    [['taxRate' => 12], 12.0],
    [['tax_rate' => '5,0'], 5.0],
    [['taxRate' => 13], 13.0],
    [['taxRate' => 0], 0.0],
    [['taxExempt' => true, 'taxRate' => 12], 0.0],
    [[], 13.0],
];
foreach ($taxPolicyCases as [$attributes, $expected]) {
    $consumerRates = [
        'checkout' => $orderTaxMethod->invoke($orderRepository, $attributes),
        'catalog' => $productTaxMethod->invoke($productRepository, $attributes, true),
        'inventory' => $inventoryTaxMethod->invoke($inventory, $attributes),
    ];
    foreach ($consumerRates as $consumer => $actual) {
        if ($actual !== $expected) {
            $failures[] = "{$consumer} tax precedence mismatch: expected {$expected}, got {$actual}";
        }
    }
}
foreach ([8.5, 12.34, 120, -1, 'invalid'] as $unsupportedRate) {
    try {
        $orderTaxMethod->invoke($orderRepository, ['taxRate' => $unsupportedRate]);
        $failures[] = 'unsupported product tax rate did not fail closed: ' . (string)$unsupportedRate;
    } catch (Throwable) {
    }
}

$historicalTaxBreakdown = $orderRepositoryReflection->getMethod('addTaxBreakdown')->invoke(
    $orderRepository,
    [
        'id' => 'historical-zero-rate-order',
        'vat_rate' => 0,
        'vat_subtotal' => 10,
        'vat_amount' => 0,
        'items_subtotal' => 10,
        'total' => 10,
        'shipping' => 0,
        'shipping_base' => 0,
        'shipping_tax_rate' => 0,
        'shipping_tax_amount' => 0,
        'discount_total' => 0,
        'discount_code' => null,
        'items' => [[
            'quantity' => 1,
            'price' => 10,
        ]],
    ]
);
if (($historicalTaxBreakdown['vat_rate'] ?? null) !== 0.0
    || ($historicalTaxBreakdown['vat_amount'] ?? null) !== 0.0
    || ($historicalTaxBreakdown['vat_subtotal'] ?? null) !== 10.0) {
    $failures[] = 'historical 0% order was reinterpreted with the tenant current default rate';
}

$formatProductRow = $productRepositoryReflection->getMethod('formatRow');
$projectionBase = [
    'id' => 'tax-projection-test',
    'name' => 'Tax projection test',
    'price' => 10,
    'originPrice' => 10,
    'quantity' => 1,
    'images' => '[]',
    'thumbs' => '[]',
    'imageMeta' => '[]',
    'variations' => '[]',
];
$zeroRatedProjection = $formatProductRow->invoke($productRepository, $projectionBase + [
    'attributes' => json_encode(['taxRate' => 0, 'taxExempt' => false], JSON_THROW_ON_ERROR),
], false);
$exemptProjection = $formatProductRow->invoke($productRepository, $projectionBase + [
    'id' => 'tax-projection-exempt-test',
    'attributes' => json_encode(['taxRate' => 0, 'taxExempt' => true], JSON_THROW_ON_ERROR),
], false);
if (($zeroRatedProjection['tax']['rate'] ?? null) !== 0.0
    || ($zeroRatedProjection['tax']['exempt'] ?? null) !== false
    || ($zeroRatedProjection['tax']['zeroRated'] ?? null) !== true
    || ($zeroRatedProjection['tax']['treatment'] ?? null) !== 'zero-rated'
    || ($exemptProjection['tax']['exempt'] ?? null) !== true
    || ($exemptProjection['tax']['treatment'] ?? null) !== 'exempt') {
    $failures[] = 'catalog projection collapses zero-rated products into tax-exempt products';
}
TenantContext::clear();

$schemas = ModuleOpenApiSchemaCatalog::schemas();
$taxSchema = $schemas['TenantEcommerceConfiguration']['properties']['defaultTaxRate'] ?? null;
if (!is_array($taxSchema)
    || ($taxSchema['enum'] ?? null) !== [0, 5, 12, 13, 14, 15]) {
    $failures[] = 'OpenAPI tenant ecommerce contract does not publish the exact SRI IVA catalogue';
}
$productTaxSchema = $schemas['PublicProduct']['properties']['tax'] ?? null;
if (($productTaxSchema['properties']['zeroRated']['type'] ?? null) !== 'boolean'
    || ($productTaxSchema['properties']['treatment']['enum'] ?? null) !== ['taxed', 'zero-rated', 'exempt']
    || !in_array('zeroRated', $productTaxSchema['required'] ?? [], true)
    || !in_array('treatment', $productTaxSchema['required'] ?? [], true)) {
    $failures[] = 'OpenAPI public product tax projection omits zero-rated/exempt treatment fields';
}

$consumerFiles = [
    $root . '/src/Repositories/ProductRepository.php',
    $root . '/src/Repositories/OrderRepository.php',
    $root . '/src/Services/InventoryIntelligenceService.php',
    $root . '/src/Services/BusinessIntelligenceService.php',
    $root . '/src/Http/Shared/SettingsControllerBase.php',
];
foreach ($consumerFiles as $consumerFile) {
    $source = (string)file_get_contents($consumerFile);
    if (!str_contains($source, 'TenantDefaultTaxRate')) {
        $failures[] = basename($consumerFile) . ' does not consume TenantDefaultTaxRate';
    }
    if (str_contains($source, "get('vat_rate')") || str_contains($source, "set('vat_rate'")) {
        $failures[] = basename($consumerFile) . ' still consumes the legacy vat_rate Setting';
    }
}
if (str_contains((string)file_get_contents($root . '/src/Repositories/ProductRepository.php'), 'ELSE 15')) {
    $failures[] = 'billing product search still hardcodes a divergent 15% sale-tax projection';
}

$settingsController = (string)file_get_contents($root . '/src/Http/Shared/SettingsControllerBase.php');
$writer = (string)file_get_contents(
    $root . '/src/Modules/Commerce/Infrastructure/Adapters/TenantRegistryCommerceTaxConfigurationAdapter.php'
);
$registryStore = (string)file_get_contents(
    $root . '/src/Modules/IdentityPlatform/Infrastructure/TenantRuntimeRegistryStore.php'
);
if (!str_contains($settingsController, 'CommerceCollaborationPortsFactory::taxConfiguration()')
    || !str_contains($writer, 'TenantTaxRegistryControlPlanePort')
    || !str_contains($writer, 'TENANT_REGISTRY_REVISION_CONFLICT')
    || !str_contains($writer, 'refreshRuntimeProjection')
    || !str_contains($writer, "'projection_synchronized'")
    || !str_contains($settingsController, 'projectionReconciliationRequired')
    || !str_contains($writer, "throw new \\DomainException('TENANT_NOT_FOUND')")
    || str_contains($writer, 'runtimeTenantSeed')) {
    $failures[] = 'tax updates are not routed through the CAS registry writer and signed snapshot';
}
if (str_contains($writer, 'IdentityPlatform\\Infrastructure')) {
    $failures[] = 'Commerce tax adapter reaches into IdentityPlatform Infrastructure';
}
if (!str_contains($registryStore, "'revision' => \$result['revision']")
    || preg_match('/\$rawResult\s*=\s*\$statement->fetchColumn\(\);(?:(?!public function).)*\$this->getState\(\)/s', $registryStore) === 1) {
    $failures[] = 'tenant registry store still exposes a racy read-after-write receipt';
}
if (str_contains($settingsController, 'sri_purchase_vat_credit_current_rate')
    || str_contains($settingsController, 'sri_purchase_vat_credit_carryforward_rate')
    || !str_contains($writer, 'purchaseVatCreditCurrentRate')
    || !str_contains($writer, 'purchaseVatCreditCarryforwardRate')) {
    $failures[] = 'tax rate and purchase credit policy are not one atomic registry mutation';
}

$productController = (string)file_get_contents(
    $root . '/src/Modules/CatalogInventory/Controllers/ProductController.php'
);
$nginx = (string)file_get_contents($root . '/docker/nginx.conf');
$shippingCacheBlock = '';
$catalogReferenceCacheBlock = '';
preg_match(
    '/location = \/api\/settings\/shipping\s*\{(?<block>.*?)\n\s*\}/s',
    $nginx,
    $shippingCacheMatch
);
preg_match(
    '/location ~ \^\/api\/settings\/\(\?:brand-logos\|product-categories\|product-category-references\)\$\s*\{(?<block>.*?)\n\s*\}/s',
    $nginx,
    $catalogReferenceCacheMatch
);
$shippingCacheBlock = (string)($shippingCacheMatch['block'] ?? '');
$catalogReferenceCacheBlock = (string)($catalogReferenceCacheMatch['block'] ?? '');
if (!str_contains($productController, 'public, max-age=15, s-maxage=15, must-revalidate')
    || str_contains($productController, 'stale-while-revalidate')
    || !str_contains($settingsController, "enableAnonymousPublicReadCache('/api/settings/shipping');")
    || !str_contains($settingsController, 'public, max-age=15, s-maxage=15, must-revalidate')
    || !str_contains($shippingCacheBlock, 'fastcgi_cache_valid 200 15s')
    || str_contains($shippingCacheBlock, 'fastcgi_cache_use_stale')
    || str_contains($shippingCacheBlock, 'fastcgi_cache_background_update')
    || !str_contains($catalogReferenceCacheBlock, 'fastcgi_cache_valid 200 60s')
    || !str_contains(
        $catalogReferenceCacheBlock,
        'fastcgi_cache_use_stale error timeout invalid_header updating http_500 http_503'
    )
    || !str_contains($catalogReferenceCacheBlock, 'fastcgi_cache_background_update on')) {
    $failures[] = 'public tax projection cache can serve fiscal data beyond the declared 15-second bound';
}

if ($failures !== []) {
    fwrite(STDERR, "Tenant tax source-of-truth failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Tenant tax source-of-truth: OK\n";
