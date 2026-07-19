<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Modules\Commerce\Application\CommerceBillingTransportException;
use App\Modules\Commerce\Infrastructure\Adapters\AuthenticatedCommerceBillingHttpAdapter;
use App\Modules\Commerce\Infrastructure\Security\CommerceBillingCredentialRegistry;

$root = dirname(__DIR__, 4);
$workspace = dirname($root);
$checks = [];
$temporaryDirectory = sys_get_temp_dir() . '/commerce-billing-registry-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryDirectory, 0700)) {
    throw new RuntimeException('Could not create credential registry test directory.');
}
$registryPath = $temporaryDirectory . '/credentials.json';
$document = [
    'version' => 1,
    'credentials' => [
        [
            'tenant_id' => 'tenant-a',
            'allowed_hosts' => ['billing-a.example.com', 'shop-a.example.com'],
            'api_key' => str_repeat('a', 32),
        ],
        [
            'tenant_id' => 'tenant-b',
            'allowed_hosts' => ['billing-b.example.com'],
            'api_key' => str_repeat('b', 32),
        ],
    ],
];

try {
    file_put_contents($registryPath, json_encode($document, JSON_THROW_ON_ERROR));
    chmod($registryPath, 0444);
    $registry = new CommerceBillingCredentialRegistry($registryPath);
    $checks['exact tenant and host select only their credential'] = hash_equals(
        str_repeat('a', 32),
        $registry->credentialFor('tenant-a', 'shop-a.example.com')
    );

    $mismatchCode = null;
    try {
        $registry->credentialFor('tenant-a', 'billing-b.example.com');
    } catch (CommerceBillingTransportException $exception) {
        $mismatchCode = $exception->errorCode;
    }
    $checks['cross-tenant host is rejected fail-closed'] = $mismatchCode === 'BILLING_TENANT_HOST_MISMATCH';

    $missingCode = null;
    try {
        $registry->credentialFor('tenant-c', 'billing-c.example.com');
    } catch (CommerceBillingTransportException $exception) {
        $missingCode = $exception->errorCode;
    }
    $checks['tenant without credential is rejected fail-closed'] = $missingCode === 'BILLING_TENANT_CREDENTIAL_MISSING';

    $adapterCode = null;
    try {
        (new AuthenticatedCommerceBillingHttpAdapter('http://backend-http:8080', $registry, 1))
            ->findBySourceReference('tenant-a', 'billing-b.example.com', 'test', 'order-a');
    } catch (CommerceBillingTransportException $exception) {
        $adapterCode = $exception->errorCode;
    }
    $checks['adapter authorizes tenant and host before network I/O'] = $adapterCode === 'BILLING_TENANT_HOST_MISMATCH';

    $duplicateHostRejected = false;
    $duplicate = $document;
    $duplicate['credentials'][1]['allowed_hosts'] = ['shop-a.example.com'];
    try {
        CommerceBillingCredentialRegistry::normalizedDocument($duplicate);
    } catch (InvalidArgumentException) {
        $duplicateHostRejected = true;
    }
    $checks['one host cannot belong to multiple tenants'] = $duplicateHostRejected;

    $sharedKeyRejected = false;
    $duplicate = $document;
    $duplicate['credentials'][1]['api_key'] = $duplicate['credentials'][0]['api_key'];
    try {
        CommerceBillingCredentialRegistry::normalizedDocument($duplicate);
    } catch (InvalidArgumentException) {
        $sharedKeyRejected = true;
    }
    $checks['one API key cannot be shared by tenants'] = $sharedKeyRejected;

    chmod($registryPath, 0666);
    $unsafePermissionsRejected = false;
    try {
        new CommerceBillingCredentialRegistry($registryPath);
    } catch (RuntimeException) {
        $unsafePermissionsRejected = true;
    }
    $checks['group or world writable registry is rejected'] = $unsafePermissionsRejected;
    chmod($registryPath, 0444);

    $portSource = (string)file_get_contents($root . '/src/Modules/Commerce/Application/Ports/CommerceBillingHttpPort.php');
    $processorSource = (string)file_get_contents($root . '/src/Modules/Commerce/Application/CommerceBillingOutboxProcessor.php');
    $workerSource = (string)file_get_contents($root . '/scripts/process_commerce_billing_outbox.php');
    $composeSource = (string)file_get_contents($root . '/docker-compose.yml');
    $iacSource = (string)file_get_contents($workspace . '/infra/production-ha/kubernetes/templates/application-workloads.yaml.tmpl');
    $managerSource = (string)file_get_contents($root . '/scripts/manage_commerce_billing_credentials.php');
    $workerBlock = preg_match('/^  commerce-billing-worker:\n([\s\S]*?)(?=^  [a-z][a-z0-9-]+:\n|\z)/m', $composeSource, $matches) === 1
        ? $matches[0]
        : '';

    $checks['HTTP port requires tenant id on every Billing operation'] = substr_count($portSource, 'string $tenantId') >= 2;
    $checks['processor verifies claim command and Order tenant'] = str_contains($processorSource, 'BILLING_OUTBOX_TENANT_MISMATCH')
        && str_contains($processorSource, 'BILLING_ORDER_TENANT_MISMATCH');
    $checks['health loads registry and validates durable backlog bindings'] = ($registryOffset = strpos($workerSource, 'new CommerceBillingCredentialRegistry')) !== false
        && ($healthOffset = strpos($workerSource, "if (\$option('metrics')")) !== false
        && $registryOffset < $healthOffset
        && str_contains($workerSource, 'requiredCredentialBindings()');
    $checks['Compose mounts registry only as a read-only file secret'] = $workerBlock !== ''
        && str_contains($workerBlock, 'BILLING_OUTBOX_CREDENTIALS_FILE: /run/secrets/backend/commerce-billing-credentials.json')
        && str_contains($workerBlock, 'source: commerce-billing-credentials')
        && !str_contains($workerBlock, 'BILLING_OUTBOX_API_KEY:')
        && !str_contains($workerBlock, 'BILLING_API_KEY:');
    $checks['Kubernetes mounts one ExternalSecret-compatible registry item'] = str_contains($iacSource, 'key: commerce-billing-credentials.json')
        && str_contains($iacSource, 'BILLING_OUTBOX_CREDENTIALS_FILE')
        && !preg_match('/name: commerce-billing-outbox[\s\S]{0,3200}BILLING_OUTBOX_API_KEY/', $iacSource);
    $checks['management tool rejects raw keys in argv and writes atomically'] = str_contains($managerSource, 'Raw API keys are intentionally rejected in argv')
        && str_contains($managerSource, 'credential-file')
        && str_contains($managerSource, 'rename($temporary, $path)');
} finally {
    @chmod($registryPath, 0600);
    @unlink($registryPath);
    @rmdir($temporaryDirectory);
}

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Commerce Billing tenant credential contract failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo 'Commerce Billing tenant credential contract: OK (' . count($checks) . " assertions)\n";
