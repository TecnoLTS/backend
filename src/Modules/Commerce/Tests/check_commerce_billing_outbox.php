<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\Commerce\Application\CommerceBillingOutboxProcessor;
use App\Modules\Commerce\Application\CommerceBillingOutboxRetryPolicy;
use App\Modules\Commerce\Application\CommerceBillingPayloadBuilder;
use App\Modules\Commerce\Application\CommerceBillingTransportException;
use App\Modules\Commerce\Application\Ports\CommerceBillingHttpPort;
use App\Modules\Commerce\Application\Ports\CommerceBillingOutboxStore;

$root = dirname(__DIR__, 4);
$repositorySource = (string)file_get_contents($root . '/src/Modules/Commerce/Infrastructure/CommerceBillingOutboxRepository.php');
$orderSource = (string)file_get_contents($root . '/src/Repositories/OrderRepository.php');
$controllerSource = (string)file_get_contents($root . '/src/Modules/Commerce/Controllers/OrderController.php');
$adapterSource = (string)file_get_contents($root . '/src/Modules/Commerce/Infrastructure/Adapters/AuthenticatedCommerceBillingHttpAdapter.php');
$schemaSource = (string)file_get_contents($root . '/scripts/bootstrap_schema.php');
$composeSource = (string)file_get_contents($root . '/docker-compose.yml');
$iacSource = (string)file_get_contents(dirname($root) . '/infra/production-ha/kubernetes/templates/application-workloads.yaml.tmpl');
$networkSource = (string)file_get_contents(dirname($root) . '/infra/production-ha/kubernetes/templates/network-policies.yaml.tmpl');

$checks = [
    'atomic enqueue requires active Order transaction' => str_contains($repositorySource, 'enqueue requires the active Order transaction'),
    'status transition enqueues before commit' => ($enqueue = strpos($orderSource, 'enqueueBillingCommandIfFinal($tenantId')) !== false
        && ($commit = strpos($orderSource, '$this->db->commit();', $enqueue)) !== false
        && $enqueue < $commit,
    'creation path enqueues before commit' => substr_count($orderSource, 'enqueueBillingCommandIfFinal(') >= 3,
    'tenant/order idempotency is a database unique key' => str_contains($schemaSource, 'CommerceBillingOutbox_tenant_order_uidx')
        && str_contains($repositorySource, 'ON CONFLICT (tenant_id, order_id) DO NOTHING'),
    'claims use row locks and skip locked' => str_contains($repositorySource, 'FOR UPDATE SKIP LOCKED')
        && str_contains($repositorySource, 'lock_token'),
    'claim ordering is tenant fair' => str_contains($repositorySource, 'due_tenants')
        && str_contains($repositorySource, 'CROSS JOIN LATERAL')
        && str_contains($repositorySource, 'LIMIT {$perTenant}'),
    'lease expiry is recoverable' => str_contains($repositorySource, "status = 'processing'")
        && str_contains($repositorySource, 'LEASE_RECOVERED'),
    'success synchronizes Order and outbox atomically' => str_contains($repositorySource, 'jsonb_set(')
        && str_contains($repositorySource, "SET status = 'sent', delivery_state = 'confirmed'"),
    'dead letter requeue is audited' => str_contains($repositorySource, "'manual_requeue'")
        && str_contains($repositorySource, 'requeue_count = requeue_count + 1'),
    'worker calls authenticated HTTP contract' => str_contains($adapterSource, 'X-API-Key: ')
        && str_contains($adapterSource, 'Idempotency-Key: ')
        && str_contains($adapterSource, '/api/')
        && !str_contains($adapterSource, 'App\\Modules\\Billing'),
    'request controller no longer dispatches Billing after response' => !str_contains($controllerSource, 'dispatchBillingInvoiceAfterResponse('),
    'compose worker has only Commerce DB and internal backend networks' => str_contains($composeSource, 'commerce-billing-worker:')
        && str_contains($composeSource, 'DB_WORKER_USERNAME_COMMERCE:')
        && str_contains($composeSource, 'BILLING_OUTBOX_CREDENTIALS_FILE:')
        && !str_contains($composeSource, 'BILLING_OUTBOX_API_KEY:')
        && !preg_match('/commerce-billing-worker:[\s\S]{0,3500}DB_PASSWORD_BILLING:/', $composeSource),
    'production CronJob is explicit and secret-minimal' => str_contains($iacSource, 'name: commerce-billing-outbox')
        && str_contains($iacSource, 'db-commerce-worker-password')
        && str_contains($iacSource, 'commerce-billing-credentials.json')
        && !str_contains($iacSource, 'BILLING_OUTBOX_API_KEY')
        && !preg_match('/name: commerce-billing-outbox[\s\S]{0,3200}db-billing-worker-password/', $iacSource),
    'production NetworkPolicy limits worker to backend and PgBouncer' => str_contains($networkSource, 'metadata: {name: commerce-billing-worker}')
        && str_contains($networkSource, 'app.kubernetes.io/name: commerce-billing-outbox'),
];

final class FakeOutboxStore implements CommerceBillingOutboxStore
{
    public array $claim;
    public array $order;
    public string $state = 'pending';
    public int $attempts = 0;
    public array $phases = [];

    public function __construct()
    {
        $this->claim = [
            'id' => 'cbout-test',
            'tenant_id' => 'tenant-a',
            'order_id' => 'order-a',
            'lock_token' => 'lease-a',
            'attempts' => 1,
            'max_attempts' => 3,
            'command' => [
                'version' => 1,
                'tenant_id' => 'tenant-a',
                'target_host' => 'tenant.example.com',
                'api_mode' => 'test',
            ],
        ];
        $this->order = [
            'id' => 'order-a', 'tenant_id' => 'tenant-a', 'status' => 'completed',
            'total' => '11.50', 'created_at' => '2026-07-15 12:00:00',
            'billing_address' => ['name' => 'CONSUMIDOR FINAL', 'documentNumber' => '9999999999999', 'country' => 'Ecuador'],
            'payment_method' => 'cash', 'shipping' => 0, 'discount_total' => 0, 'vat_rate' => 15,
            'items' => [[
                'product_id' => 'p1', 'product_name' => 'Producto', 'quantity' => 1,
                'price' => '11.50', 'net_total' => '10.00', 'tax_rate' => '15', 'tax_amount' => '1.50',
            ]],
        ];
    }

    public function claimFairBatch(int $limit, int $perTenant, int $leaseSeconds, string $workerId): array
    {
        if (!in_array($this->state, ['pending', 'retry', 'delivery_unknown'], true)) {
            return [];
        }
        $this->state = 'processing';
        $this->attempts++;
        $this->claim['attempts'] = $this->attempts;
        $this->claim['lock_token'] = 'lease-' . $this->attempts;
        return [$this->claim];
    }
    public function loadOrderForClaim(array $outbox): ?array { return $this->order; }
    public function recordPhase(array $outbox, string $phase, string $outcome, ?int $httpStatus = null, ?string $errorCode = null, ?string $errorMessage = null, array $metadata = []): void
    { $this->phases[] = $phase . ':' . $outcome; }
    public function markSucceeded(array $outbox, array $invoice, array $billingMetadata, ?int $httpStatus): void
    { $this->state = 'sent'; }
    public function markFailed(array $outbox, string $errorCode, string $errorMessage, bool $deliveryUnknown, ?int $httpStatus, CommerceBillingOutboxRetryPolicy $retryPolicy): string
    {
        $this->state = $this->attempts >= 3 ? 'dead_letter' : ($deliveryUnknown ? 'delivery_unknown' : 'retry');
        return $this->state;
    }
}

final class LostResponseBillingPort implements CommerceBillingHttpPort
{
    public ?array $invoice = null;
    public int $emitCalls = 0;
    public function findBySourceReference(string $tenantId, string $tenantHost, string $apiMode, string $sourceReference): array
    {
        return $this->invoice === null
            ? ['found' => false, 'http_status' => 404, 'invoice' => null]
            : ['found' => true, 'http_status' => 200, 'invoice' => $this->invoice];
    }
    public function emit(string $tenantId, string $tenantHost, string $apiMode, string $idempotencyKey, array $payload): array
    {
        $this->emitCalls++;
        $this->invoice ??= ['access_key' => '1234567890', 'sri_status' => 'AUTORIZADO', 'total' => '11.50'];
        throw new CommerceBillingTransportException('response lost', true, null, 'TEST_LOST_RESPONSE');
    }
}

$store = new FakeOutboxStore();
$billing = new LostResponseBillingPort();
$processor = new CommerceBillingOutboxProcessor(
    $store,
    $billing,
    new CommerceBillingPayloadBuilder(),
    new CommerceBillingOutboxRetryPolicy(1, 8)
);
$first = $processor->process(1, 1, 30, 'worker-a', 10);
$checks['lost response becomes delivery unknown'] = $store->state === 'delivery_unknown'
    && ($first['delivery_unknown'] ?? 0) === 1;
$second = $processor->process(1, 1, 30, 'worker-b', 10);
$checks['retry recovers post-commit/pre-ack crash without duplicate'] = $store->state === 'sent'
    && ($second['recovered_existing'] ?? 0) === 1
    && $billing->emitCalls === 1;
$checks['recovery always looks up before emitting'] = array_slice($store->phases, -2) === ['lookup:requested', 'lookup:found'];

$retry = new CommerceBillingOutboxRetryPolicy(10, 100);
$checks['retry policy is monotonic and bounded'] = $retry->delaySeconds(1, 'a') >= 10
    && $retry->delaySeconds(2, 'a') >= $retry->delaySeconds(1, 'a')
    && $retry->delaySeconds(50, 'a') <= 100;
$payload = (new CommerceBillingPayloadBuilder())->build($store->order);
$checks['payload preserves source idempotency and tenant attribution'] = ($payload['additional_info']['order_id'] ?? null) === 'order-a'
    && ($payload['additional_info']['tenant_id'] ?? null) === 'tenant-a';
$treatmentOrder = $store->order;
$treatmentOrder['total'] = 20;
$treatmentOrder['vat_rate'] = 0;
$treatmentOrder['items'] = [
    [
        'product_id' => 'zero-rated', 'product_name' => 'IVA 0%', 'quantity' => 1,
        'price' => '10.00', 'net_total' => '10.00', 'tax_rate' => '0', 'tax_amount' => '0',
        'tax_exempt' => false, 'tax_treatment' => 'zero-rated',
    ],
    [
        'product_id' => 'exempt', 'product_name' => 'Exento', 'quantity' => 1,
        'price' => '10.00', 'net_total' => '10.00', 'tax_rate' => '0', 'tax_amount' => '0',
        'tax_exempt' => true, 'tax_treatment' => 'exempt',
    ],
];
$treatmentPayload = (new CommerceBillingPayloadBuilder())->build($treatmentOrder);
$checks['billing contract preserves zero-rated versus exempt SRI treatment'] =
    ($treatmentPayload['items'][0]['tax_percentage_code'] ?? null) === '0'
    && ($treatmentPayload['items'][1]['tax_percentage_code'] ?? null) === '7';

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Commerce Billing durable outbox contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo 'Commerce Billing durable outbox: OK (' . count($checks) . " assertions)\n";
