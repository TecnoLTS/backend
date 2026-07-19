<?php

declare(strict_types=1);

namespace BillingService\Billing\Application\UseCases;

use BillingService\Billing\Application\Dto\Request\EmitInvoiceRequest;
use BillingService\Billing\Domain\ValueObjects\AccessKey;
use BillingService\Billing\Infrastructure\Persistence\InvoiceRepository;
use Psr\Log\LoggerInterface;

class ReissueStuckInvoice
{
    private const REISSUABLE_STATUSES = [
        'RECIBIDA',
        'EN PROCESAMIENTO',
        'PENDING',
        'UNKNOWN',
        'DEVUELTA',
        'NO AUTORIZADO',
    ];

    public function __construct(
        private readonly EmitInvoice $emitInvoice,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly LoggerInterface $logger,
        private readonly array $clientContext
    ) {}

    public function execute(string $accessKey, string $reason = ''): array
    {
        $normalizedAccessKey = AccessKey::fromValue($accessKey)->value();
        $reason = trim($reason) !== ''
            ? trim($reason)
            : 'Reemision manual: comprobante anterior atascado en procesamiento SRI.';

        if (!$this->invoiceRepository->tryAcquireMaintenanceLock($normalizedAccessKey)) {
            throw new \RuntimeException('Ya existe una reemision en curso para esta factura.');
        }

        try {
            $oldInvoice = $this->invoiceRepository->findInvoiceForClient($normalizedAccessKey, $this->clientContext);
            if (!is_array($oldInvoice)) {
                throw new \InvalidArgumentException('Factura no encontrada para el cliente autenticado.');
            }

            $oldStatus = strtoupper(trim((string) ($oldInvoice['sri_status'] ?? '')));
            if ($oldStatus === 'AUTORIZADO') {
                throw new \RuntimeException('No se puede reemitir una factura autorizada por el SRI.');
            }

            $existingReplacementAccessKey = trim((string) ($oldInvoice['replacement_access_key'] ?? ''));
            if ($existingReplacementAccessKey !== '') {
                $replacement = $this->invoiceRepository->findInvoiceForClient($existingReplacementAccessKey, $this->clientContext);
                return [
                    'reused_existing_replacement' => true,
                    'old_invoice' => $this->publicInvoiceData($oldInvoice),
                    'new_invoice' => $this->publicInvoiceData($replacement ?? ['access_key' => $existingReplacementAccessKey]),
                ];
            }

            if ($oldStatus === 'ANULADA_LOCAL' || $oldStatus === 'CANCELADA_LOCAL') {
                throw new \RuntimeException('La factura ya fue anulada localmente y no tiene una factura de reemplazo vinculada.');
            }

            if (!in_array($oldStatus, self::REISSUABLE_STATUSES, true)) {
                throw new \RuntimeException(sprintf(
                    'No se puede reemitir una factura en estado %s.',
                    $oldStatus !== '' ? $oldStatus : 'desconocido'
                ));
            }

            $rawRequest = $this->decodeRawRequest($oldInvoice['raw_request'] ?? null);
            $oldOrderId = trim((string) ($oldInvoice['source_reference'] ?? ''));
            $requestOrderId = trim((string) ($rawRequest['additional_info']['order_id'] ?? ''));
            if ($oldOrderId !== '' && $requestOrderId !== '' && $oldOrderId !== $requestOrderId) {
                throw new \RuntimeException('La orden de la factura original no coincide con el payload guardado.');
            }

            if ($this->containsSriErrorCode($oldInvoice['sri_messages'] ?? null, '45')) {
                $branchId = (int) ($oldInvoice['branch_id'] ?? $this->clientContext['resolved_branch_id'] ?? 0);
                $environment = trim((string) ($oldInvoice['ambiente'] ?? ''));
                $sequential = trim((string) ($oldInvoice['sequential'] ?? ''));
                if ($branchId <= 0 || !in_array($environment, ['pruebas', 'produccion'], true) || $sequential === '') {
                    throw new \RuntimeException('No se pudo determinar el alcance del secuencial rechazado por el SRI.');
                }
                $this->invoiceRepository->advanceSequentialAfterSriCollision(
                    $this->clientContext,
                    $branchId,
                    $environment,
                    $sequential
                );
            }

            // A reissue must bypass the ordinary source-reference idempotency
            // lookup. Otherwise EmitInvoice returns the same rejected invoice
            // and the replacement would be linked to itself.
            $response = $this->emitInvoice->execute(EmitInvoiceRequest::fromArray($rawRequest), false);
            $newInvoiceData = $response->toArray();
            $newAccessKey = AccessKey::fromValue((string) ($newInvoiceData['access_key'] ?? ''))->value();

            $this->invoiceRepository->linkReplacementToOriginal($newAccessKey, $this->clientContext, $normalizedAccessKey);
            $this->invoiceRepository->markInvoiceAsManualReplacement(
                $normalizedAccessKey,
                $this->clientContext,
                $newAccessKey,
                $reason
            );

            $newInvoice = $this->invoiceRepository->findInvoiceForClient($newAccessKey, $this->clientContext);
            $oldInvoice = $this->invoiceRepository->findInvoiceForClient($normalizedAccessKey, $this->clientContext) ?? $oldInvoice;

            $this->logger->warning('[ReissueStuckInvoice] Factura reemitida manualmente', [
                'old_access_key' => $normalizedAccessKey,
                'new_access_key' => $newAccessKey,
                'source_reference' => $oldOrderId !== '' ? $oldOrderId : null,
            ]);

            return [
                'reused_existing_replacement' => false,
                'old_invoice' => $this->publicInvoiceData($oldInvoice),
                'new_invoice' => $this->publicInvoiceData($newInvoice ?? $newInvoiceData),
            ];
        } finally {
            $this->invoiceRepository->releaseMaintenanceLock($normalizedAccessKey);
        }
    }

    private function decodeRawRequest(mixed $rawRequest): array
    {
        if (is_array($rawRequest)) {
            return $rawRequest;
        }

        if (is_string($rawRequest) && trim($rawRequest) !== '') {
            $decoded = json_decode($rawRequest, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException('La factura original no tiene payload raw_request reutilizable.');
    }

    private function containsSriErrorCode(mixed $messages, string $expectedCode): bool
    {
        if (!is_array($messages)) {
            if (is_string($messages) && trim($messages) !== '') {
                $decoded = json_decode($messages, true);
                return is_array($decoded) && $this->containsSriErrorCode($decoded, $expectedCode);
            }
            return false;
        }

        $identifier = trim((string) ($messages['identificador'] ?? $messages['identifier'] ?? ''));
        if ($identifier === $expectedCode) {
            return true;
        }
        foreach ($messages as $value) {
            if ($this->containsSriErrorCode($value, $expectedCode)) {
                return true;
            }
        }

        return false;
    }

    private function publicInvoiceData(array $invoice): array
    {
        return [
            'source_reference' => $invoice['source_reference'] ?? null,
            'access_key' => $invoice['access_key'] ?? null,
            'authorization_number' => $invoice['authorization_number'] ?? null,
            'authorization_date' => $invoice['authorization_date'] ?? null,
            'issue_date' => $invoice['issue_date'] ?? null,
            'customer_name' => $invoice['customer_name'] ?? null,
            'customer_identification' => $invoice['customer_identification'] ?? null,
            'customer_email' => $invoice['customer_email'] ?? null,
            'total' => isset($invoice['total_with_tax']) ? (float) $invoice['total_with_tax'] : ($invoice['total'] ?? null),
            'establishment_code' => $invoice['establishment_code'] ?? null,
            'emission_point' => $invoice['emission_point'] ?? null,
            'sequential' => $invoice['sequential'] ?? null,
            'ambiente' => $invoice['ambiente'] ?? null,
            'sri_status' => $invoice['sri_status'] ?? ($invoice['status'] ?? null),
            'cancelled_at' => $invoice['cancelled_at'] ?? null,
            'cancellation_reason' => $invoice['cancellation_reason'] ?? null,
            'replacement_access_key' => $invoice['replacement_access_key'] ?? null,
            'replaced_access_key' => $invoice['replaced_access_key'] ?? null,
            'created_at' => $invoice['created_at'] ?? null,
            'updated_at' => $invoice['updated_at'] ?? null,
        ];
    }
}
