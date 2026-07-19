<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$controller = file_get_contents($root . '/src/Modules/Billing/Controllers/BillingDocumentController.php');
$publicController = file_get_contents($root . '/src/Modules/Billing/Controllers/PublicBillingController.php');
$productPort = file_get_contents($root . '/src/Modules/Billing/Application/Ports/BillingProductCatalogPort.php');
$orderPort = file_get_contents($root . '/src/Modules/Billing/Application/Ports/BillingOrderAccountingPort.php');
$reissueUseCase = file_get_contents($root . '/src/Modules/Billing/Native/Billing/Application/UseCases/ReissueStuckInvoice.php');
$invoiceRepository = file_get_contents($root . '/src/Modules/Billing/Native/Billing/Infrastructure/Persistence/InvoiceRepository.php');

$checks = [
    'controller does not import flat repositories' => is_string($controller)
        && !str_contains($controller, 'App\\Repositories\\'),
    'controller consumes product port' => is_string($controller)
        && str_contains($controller, 'BillingProductCatalogPort'),
    'controller consumes order projection port' => is_string($controller)
        && str_contains($controller, 'BillingOrderAccountingPort'),
    'public reissue synchronizes Commerce through order projection port' => is_string($publicController)
        && str_contains($publicController, 'BillingOrderAccountingPort')
        && str_contains($publicController, 'syncOrderBillingMetadata($result)'),
    'product contract does not expose repository type' => is_string($productPort)
        && !str_contains($productPort, 'Repository'),
    'order contract does not expose repository type' => is_string($orderPort)
        && !str_contains($orderPort, 'Repository'),
    'manual reissue bypasses source-reference idempotency' => is_string($reissueUseCase)
        && str_contains(
            $reissueUseCase,
            '$this->emitInvoice->execute(EmitInvoiceRequest::fromArray($rawRequest), false)'
        ),
    'invoice maintenance projection includes reissue payload and links' => is_string($invoiceRepository)
        && str_contains($invoiceRepository, 'sri_status, sri_messages, raw_request, raw_response')
        && str_contains($invoiceRepository, 'replacement_access_key, replaced_access_key'),
    'SRI code 45 advances one value before reissue' => is_string($reissueUseCase)
        && str_contains($reissueUseCase, "containsSriErrorCode(\$oldInvoice['sri_messages'] ?? null, '45')")
        && str_contains($reissueUseCase, 'advanceSequentialAfterSriCollision')
        && is_string($invoiceRepository)
        && str_contains($invoiceRepository, 'let the allocator try the immediately following value'),
];

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    fwrite(STDERR, "Billing/Commerce port boundary failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "Billing/Commerce port boundary: OK\n";
