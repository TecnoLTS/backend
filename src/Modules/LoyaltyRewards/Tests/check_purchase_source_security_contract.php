<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$verifier = file_get_contents($base . '/Application/PurchaseSourceVerifier.php');
$ecommercePort = file_get_contents($base . '/Application/Ports/EcommercePurchaseSource.php');
$billingPort = file_get_contents($base . '/Application/Ports/BillingPurchaseSource.php');
$ecommerceAdapter = file_get_contents($base . '/Infrastructure/PurchaseSources/PostgresEcommercePurchaseSource.php');
$billingAdapter = file_get_contents($base . '/Infrastructure/PurchaseSources/PostgresBillingPurchaseSource.php');
$verifierFactory = file_get_contents($base . '/Infrastructure/PurchaseSourceVerifierFactory.php');
$repository = file_get_contents($base . '/Infrastructure/LoyaltyRepository.php');
$controller = file_get_contents($base . '/Controllers/LoyaltyController.php');
$schema = file_get_contents($base . '/Infrastructure/LoyaltySchema.php');
$billingRepository = file_get_contents(dirname($base) . '/Billing/Native/Billing/Infrastructure/Persistence/InvoiceRepository.php');
$billingGateway = file_get_contents(dirname($base) . '/Billing/Infrastructure/NativeBillingGateway.php');
$bootstrap = file_get_contents(dirname(__DIR__, 4) . '/scripts/bootstrap_module_databases.php');

if (
    !is_string($verifier)
    || !is_string($ecommercePort)
    || !is_string($billingPort)
    || !is_string($ecommerceAdapter)
    || !is_string($billingAdapter)
    || !is_string($verifierFactory)
    || !is_string($repository)
    || !is_string($controller)
    || !is_string($schema)
    || !is_string($billingRepository)
    || !is_string($billingGateway)
    || !is_string($bootstrap)
) {
    fwrite(STDERR, "No se pudo leer el contrato de seguridad de compras Loyalty.\n");
    exit(1);
}

$checks = [
    'application_has_no_database_dependency' => !str_contains($verifier, 'App\\Core\\Database')
        && !str_contains($verifier, 'PDO')
        && !str_contains($verifier, 'SELECT '),
    'application_has_no_foreign_domain_dependency' => !str_contains($verifier, 'App\\Modules\\Commerce\\')
        && !str_contains($verifier, 'App\\Modules\\Billing\\'),
    'typed_source_ports' => str_contains($ecommercePort, 'interface EcommercePurchaseSource')
        && str_contains($billingPort, 'interface BillingPurchaseSource')
        && str_contains($ecommercePort, '): PurchaseSourceMatches')
        && str_contains($billingPort, '): PurchaseSourceMatches')
        && str_contains($verifier, 'EcommercePurchaseSource $ecommercePurchases')
        && str_contains($verifier, 'BillingPurchaseSource $billingPurchases'),
    'infrastructure_factory_composes_adapters' => str_contains($verifierFactory, 'new PostgresEcommercePurchaseSource()')
        && str_contains($verifierFactory, 'new PostgresBillingPurchaseSource()')
        && str_contains($repository, 'PurchaseSourceVerifierFactory::create()')
        && str_contains($repository, '$this->purchaseSourceVerifier->verify('),
    'billing_header_tenant_filter' => str_contains($billingAdapter, 'WHERE ih.tenant_id = :invoice_tenant_id'),
    'billing_customer_tenant_filter' => str_contains($billingAdapter, 'bc.tenant_id = :billing_customer_tenant_id'),
    'billing_tenants_selected' => str_contains($billingAdapter, 'ih.tenant_id AS source_tenant_id')
        && str_contains($billingAdapter, 'bc.tenant_id AS billing_customer_tenant_id'),
    'billing_tenants_verified' => str_contains($verifier, "purchase_billing_tenant_mismatch"),
    'billing_header_nullable_migration' => str_contains($bootstrap, 'ALTER TABLE invoice_headers ADD COLUMN IF NOT EXISTS tenant_id text')
        && str_contains($bootstrap, 'SET tenant_id = bc.tenant_id'),
    'billing_new_header_tenant_attributed' => str_contains($billingGateway, "\$context['tenant_id']")
        && str_contains($billingRepository, ':billing_customer_id')
        && str_contains($billingRepository, "'tenant_id' => \$tenantId")
        && str_contains($billingRepository, 'requiredTenantId($clientContext)'),
    'ecommerce_tenant_filter' => str_contains($ecommerceAdapter, 'WHERE tenant_id = :tenant_id')
        && str_contains($ecommerceAdapter, "'tenant_id' => \$tenantId"),
    'ecommerce_tenant_verified' => str_contains($verifier, 'purchase_ecommerce_tenant_mismatch'),
    'external_purchase_source_bound' => str_contains($verifier, 'purchase_source_client_mismatch')
        && str_contains($verifier, '$requestedSource = $boundClientSource'),
    'staff_pos_server_context_only' => str_contains($controller, 'PurchaseSourceVerifier::STAFF_POS_SOURCE')
        && str_contains($controller, "'actorId' => \$actorId")
        && str_contains($verifier, "public const STAFF_POS_SOURCE = 'staff_pos'")
        && str_contains($verifier, 'purchase_staff_pos_context_invalid'),
    'staff_pos_receipt_is_source_of_truth' => str_contains($schema, 'CREATE TABLE IF NOT EXISTS loyalty_cash_receipts')
        && str_contains($repository, 'createStaffCashReceipt')
        && str_contains($repository, "'verificationMethod' => 'authenticated_staff_rbac'")
        && str_contains($repository, "'verified' => true"),
    'staff_pos_reversal_is_atomic' => str_contains($repository, 'reverseStaffCashReceiptIfNeeded')
        && str_contains($repository, "SET status = :status,")
        && str_contains($repository, "'status' => 'reversed'"),
    'source_not_found_does_not_autoblock' => str_contains($repository, 'purchaseVerificationRiskSeverity')
        && str_contains($repository, "'purchase_source_not_found'")
        && str_contains($repository, "return 'medium';"),
    'external_reversal_context_forwarded' => str_contains($controller, '$sourceContext = [')
        && preg_match('/reversePurchase\s*\([^;]+\$sourceContext\s*\)/s', $controller) === 1,
    'external_reversal_source_bound' => str_contains($repository, 'assertExternalPurchaseReversalAllowed')
        && str_contains($repository, 'purchase_reversal_source_mismatch'),
    'rotation_lineage_scoped' => str_contains($repository, 'apiClientActorBelongsToRotationLineage')
        && str_contains($repository, "WHERE tenant_id = :tenant_id AND id = :id"),
    'pos_public_path_exact' => str_contains($repository, "PUBLIC_LOYALTY_SERVICE_SEGMENT")
        && str_contains($repository, "'/v1/'"),
    'pos_internal_path_not_trusted' => !str_contains($repository, "str_starts_with(\$path, '/api/loyalty/v1/')"),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, 'Contrato Loyalty inseguro: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("purchase-source-security-contract: ok checks=%d\n", count($checks)));
