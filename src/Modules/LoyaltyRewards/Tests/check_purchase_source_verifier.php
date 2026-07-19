<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\LoyaltyRewards\Application\Ports\BillingPurchaseSource;
use App\Modules\LoyaltyRewards\Application\Ports\EcommercePurchaseSource;
use App\Modules\LoyaltyRewards\Application\Ports\PurchaseSourceMatches;
use App\Modules\LoyaltyRewards\Application\PurchaseSourceVerifier;
use App\Modules\LoyaltyRewards\Domain\PurchaseVerificationException;

if (!extension_loaded('bcmath')) {
    fwrite(STDOUT, "[SKIP] ext-bcmath no esta disponible; el runtime QA ejecuta esta prueba.\n");
    exit(0);
}

final class StubEcommercePurchaseSource implements EcommercePurchaseSource
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];
    /** @var list<array{tenantId: string, reference: string}> */
    public array $calls = [];

    public function findMatches(string $tenantId, string $normalizedReference): PurchaseSourceMatches
    {
        $this->calls[] = ['tenantId' => $tenantId, 'reference' => $normalizedReference];

        return new PurchaseSourceMatches($this->rows);
    }
}

final class StubBillingPurchaseSource implements BillingPurchaseSource
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];
    /** @var list<array{tenantId: string, reference: string}> */
    public array $calls = [];

    public function findMatches(string $tenantId, string $normalizedReference): PurchaseSourceMatches
    {
        $this->calls[] = ['tenantId' => $tenantId, 'reference' => $normalizedReference];

        return new PurchaseSourceMatches($this->rows);
    }
}

/** @throws RuntimeException */
function assertPurchase(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return PurchaseVerificationException */
function capturePurchaseFailure(callable $operation): PurchaseVerificationException
{
    try {
        $operation();
    } catch (PurchaseVerificationException $exception) {
        return $exception;
    }

    throw new RuntimeException('Se esperaba PurchaseVerificationException.');
}

$tenantId = 'tenant-a';
$member = [
    'id' => 'member-a',
    'external_customer_id' => 'customer-a',
    'email' => 'member@example.test',
    'metadata' => ['identification' => '1712345678'],
];
$ecommerce = new StubEcommercePurchaseSource();
$billing = new StubBillingPurchaseSource();
$verifier = new PurchaseSourceVerifier($ecommerce, $billing);

$ecommerce->rows = [[
    'id' => 'order-a',
    'source_tenant_id' => $tenantId,
    'customer_id' => 'customer-a',
    'user_id' => null,
    'status' => 'DELIVERED',
    'total' => '12.30',
    'invoice_number' => 'INV 001',
    'billing_address' => [],
]];
$verified = $verifier->verify($tenantId, $member, '12.30', 'usd', " inv\t001 ", ['purchaseSource' => 'ecommerce']);
assertPurchase($verified['type'] === 'ecommerce' && $verified['status'] === 'delivered', 'No se preservo la verificacion ecommerce.');
assertPurchase($verified['amount'] === '12.30' && $verified['currency'] === 'USD', 'No se preservaron monto/moneda ecommerce.');
assertPurchase($ecommerce->calls === [['tenantId' => $tenantId, 'reference' => 'INV 001']], 'El puerto ecommerce no recibio tenant/referencia normalizados.');
assertPurchase($billing->calls === [], 'Una fuente ecommerce explicita no debe consultar Billing.');

$ecommerce->rows = [];
$billing->rows = [[
    'id' => 'invoice-a',
    'source_tenant_id' => $tenantId,
    'billing_customer_tenant_id' => $tenantId,
    'source_reference' => 'FAC-001',
    'access_key' => 'access-a',
    'authorization_number' => 'auth-a',
    'total_with_tax' => '25.00',
    'sri_status' => 'AUTORIZADO',
    'authorized_xml_received' => true,
    'identification' => '1712345678',
    'email' => 'other@example.test',
    'billing_customer_id' => 'billing-customer-a',
]];
$verified = $verifier->verify($tenantId, $member, '25.00', 'USD', 'fac-001', ['purchase_source' => 'auto']);
assertPurchase($verified['type'] === 'billing' && $verified['status'] === 'authorized', 'Auto no hizo fallback seguro hacia Billing.');
assertPurchase(count($ecommerce->calls) === 2 && count($billing->calls) === 1, 'Auto no consulto las fuentes en el orden esperado.');

$callsBeforeBoundFailure = [count($ecommerce->calls), count($billing->calls)];
$failure = capturePurchaseFailure(static fn() => $verifier->verify(
    $tenantId,
    $member,
    '25.00',
    'USD',
    'FAC-001',
    ['purchaseSource' => 'ecommerce'],
    ['clientId' => 'billing-client', 'type' => 'billing']
));
assertPurchase($failure->riskType() === 'purchase_source_client_mismatch' && $failure->httpStatus() === 403, 'La credencial ligada a fuente debe fallar cerrada.');
assertPurchase(
    [count($ecommerce->calls), count($billing->calls)] === $callsBeforeBoundFailure,
    'Una credencial ligada a otra fuente no debe consultar adaptadores.'
);

$billing->rows[0]['source_tenant_id'] = 'tenant-b';
$failure = capturePurchaseFailure(static fn() => $verifier->verify(
    $tenantId,
    $member,
    '25.00',
    'USD',
    'FAC-001',
    ['purchaseSource' => 'billing']
));
assertPurchase($failure->riskType() === 'purchase_billing_tenant_mismatch' && $failure->httpStatus() === 409, 'El tenant mismatch fiscal debe conservarse.');

$adapterCallsBeforeStaff = [count($ecommerce->calls), count($billing->calls)];
$staff = $verifier->verify(
    $tenantId,
    $member,
    '10.00',
    'USD',
    'POS-001',
    [],
    ['verified' => true, 'type' => PurchaseSourceVerifier::STAFF_POS_SOURCE, 'actorId' => 'staff-1']
);
assertPurchase($staff['authorized'] === true && $staff['verified'] === false, 'El contexto staff_pos cambio de semantica.');
assertPurchase(
    [count($ecommerce->calls), count($billing->calls)] === $adapterCallsBeforeStaff,
    'staff_pos autenticado no debe consultar fuentes externas.'
);

$failure = capturePurchaseFailure(static fn() => $verifier->verify(
    $tenantId,
    $member,
    '10.00',
    'USD',
    'POS-002',
    [],
    ['verified' => true, 'type' => PurchaseSourceVerifier::STAFF_POS_SOURCE, 'actorId' => 'api:client']
));
assertPurchase($failure->riskType() === 'purchase_staff_pos_context_invalid' && $failure->httpStatus() === 403, 'staff_pos debe rechazar actores API.');

fwrite(STDOUT, "purchase-source-verifier: ok ports, fallback, binding, tenant y staff-pos\n");
