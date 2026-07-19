<?php

declare(strict_types=1);

namespace BillingService\Billing\Application\UseCases;

use App\Infrastructure\Storage\Billing\BillingArtifactStorage;
use App\Shared\Tax\EcuadorSriVatCatalog;
use BillingService\Billing\Application\Dto\Request\EmitInvoiceRequest;
use BillingService\Billing\Application\Dto\Response\InvoiceResponse;
use BillingService\Billing\Application\Services\SriErrorInterpreter;
use BillingService\Billing\Infrastructure\Persistence\InvoiceRepository;
use BillingService\Billing\Application\Ports\DocumentSignerInterface;
use BillingService\Billing\Application\Ports\SriGatewayInterface;
use BillingService\Billing\Application\Ports\XmlBuilderInterface;
use BillingService\Billing\Domain\Entities\Invoice;
use BillingService\Billing\Domain\ValueObjects\AccessKey;
use BillingService\Billing\Domain\ValueObjects\DocumentType;
use BillingService\Billing\Domain\ValueObjects\Environment;
use BillingService\Billing\Domain\Events\InvoiceAuthorized;
use BillingService\Billing\Domain\Events\InvoiceEmitted;
use BillingService\Billing\Domain\Events\InvoiceRejected;
use BillingService\Billing\Infrastructure\Services\AuthorizedInvoiceMailer;
use BillingService\Shared\Application\Events\DomainEventDispatcher;
use BillingService\Shared\Domain\Events\DomainEvent;
use BillingService\Shared\Domain\ValueObjects\Ruc;
use BillingService\Shared\Domain\ValueObjects\Identification;
use BillingService\Shared\Infrastructure\Logging\MonologLogger;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

class EmitInvoice
{
    private const FINAL_CONSUMER_IDENTIFICATION = '9999999999999';
    private const FINAL_CONSUMER_MAX_TOTAL = 50.00;
    private const FINAL_CONSUMER_PLACEHOLDERS = [
        '9999999999' => true,
        '9999999999999' => true,
    ];

    public function __construct(
        private readonly XmlBuilderInterface $xmlBuilder,
        private readonly DocumentSignerInterface $signer,
        private readonly SriGatewayInterface $sriGateway,
        private readonly LoggerInterface $logger,
        private readonly array $config,
        private readonly AuthorizedInvoiceMailer $authorizedInvoiceMailer,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly array $clientContext,
        private readonly ?DomainEventDispatcher $eventDispatcher = null
    ) {}

    public function execute(EmitInvoiceRequest $request, bool $allowExistingActiveInvoice = true): InvoiceResponse
    {
        // Validar request
        $errors = $request->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Datos de factura inválidos: ' . json_encode($errors));
        }
        $customerIdentificationValue = $this->normalizeCustomerIdentification(
            $request->customerIdentification,
            $request->customerName
        );
        $this->assertFinalConsumerAmountAllowed($request, $customerIdentificationValue);
        $customerIdentification = new Identification($customerIdentificationValue);
        $customerName = $customerIdentification->isFinalConsumer() ? 'CONSUMIDOR FINAL' : $request->customerName;
        $requestPayload = $request->toArray();
        $requestPayload['customer_identification'] = $customerIdentificationValue;
        $requestPayload['customer_name'] = $customerName;

        $sourceReference = trim((string) ($request->additionalInfo['order_id'] ?? ''));
        $sourceReferenceLockAcquired = false;
        $sequentialLockAcquired = false;
        $branchId = 0;
        $environmentKey = '';

        try {
        if ($allowExistingActiveInvoice && $sourceReference !== '') {
            $this->invoiceRepository->acquireSourceReferenceLock($this->clientContext, $sourceReference);
            $sourceReferenceLockAcquired = true;
            $existingInvoice = $this->invoiceRepository->findActiveInvoiceBySourceReference($this->clientContext, $sourceReference);
            if (is_array($existingInvoice)) {
                $this->logger->warning('[EmitInvoice] Reutilizando factura activa existente para la orden', [
                    'source_reference' => $sourceReference,
                    'access_key' => $existingInvoice['access_key'] ?? null,
                    'sri_status' => $existingInvoice['sri_status'] ?? null,
                ]);

                return InvoiceResponse::fromStoredInvoice($existingInvoice);
            }
        }

        $issueDate = new DateTimeImmutable('now', new DateTimeZone($this->config['timezone'] ?? 'America/Guayaquil'));
        $environmentKey = $this->config['environment'] === 'pruebas' ? 'pruebas' : 'produccion';
        $branchId = (int) $this->clientContext['resolved_branch_id'];
        $this->invoiceRepository->acquireSequentialLock($branchId, $environmentKey);
        $sequentialLockAcquired = true;

        $sequential = $this->invoiceRepository->nextSequentialForBranchAndEnvironment(
            $this->clientContext,
            $branchId,
            $environmentKey
        );
        $invoiceNumber = $this->formatInvoiceNumber(
            (string) $this->config['establecimiento'],
            (string) $this->config['punto_emision'],
            $sequential
        );

        $accessKey = AccessKey::generate(
            date: $issueDate->format('dmY'),
            documentType: DocumentType::FACTURA,
            ruc: $this->config['empresa']['ruc'],
            environment: $this->config['environment'] === 'pruebas' ? Environment::PRUEBAS : Environment::PRODUCCION,
            series: $this->config['establecimiento'] . $this->config['punto_emision'],
            sequential: $sequential,
            emissionCode: '1' // Normal
        );

        $paymentMethod = $this->resolvePaymentMethod($request);
        $mappedItems = $this->mapItems($request->items);

        // Crear entidad Invoice
        $invoice = new Invoice(
            accessKey: $accessKey,
            issuerRuc: new Ruc($this->config['empresa']['ruc']),
            issuerBusinessName: $this->config['empresa']['razon_social'],
            customerIdentification: $customerIdentification,
            customerName: $customerName,
            customerAddress: $request->customerAddress,
            customerEmail: $request->customerEmail,
            issueDate: $issueDate,
            environment: new Environment(
                $this->config['environment'] === 'pruebas' ? Environment::PRUEBAS : Environment::PRODUCCION
            ),
            establishment: $this->config['establecimiento'],
            emissionPoint: $this->config['punto_emision'],
            sequential: $sequential,
            items: $mappedItems,
            taxes: $this->calculateTaxes($mappedItems),
            paymentMethodCode: $paymentMethod['code'],
            paymentMethodLabel: $paymentMethod['label']
        );

        if ($this->logger instanceof MonologLogger) {
            $this->logger->replaceSharedContext([
                'invoice_number' => $invoiceNumber,
                'invoice_sequential' => str_pad($sequential, 9, '0', STR_PAD_LEFT),
                'access_key' => $accessKey->value(),
                'total' => round($invoice->total()->amount(), 2),
            ]);
        }

        // Generar XML
        $xml = $this->xmlBuilder->buildInvoiceXml($invoice);
        // Guardar XML generado (sin firmar)
        $accessKeyValue = $accessKey->value();
        $artifactStorage = new BillingArtifactStorage();
        $artifactStorage->putXml('generados', $accessKeyValue, $xml);

        // Firmar XML
        $signedXml = $this->signer->sign($xml);

        // Guardar XML firmado
        $signedXmlPath = $artifactStorage->putXml('firmados', $accessKeyValue, $signedXml);
        $this->invoiceRepository->createDraftInvoice($this->clientContext, $invoice, $requestPayload, $signedXmlPath);
        if ($sourceReferenceLockAcquired) {
            $this->invoiceRepository->releaseSourceReferenceLock($this->clientContext, $sourceReference);
            $sourceReferenceLockAcquired = false;
        }

        // Enviar al SRI
        $sriResponse = $this->sriGateway->sendDocument($signedXml);
        $this->logger->info('[EmitInvoice] Factura enviada');

        $this->invoiceRepository->updateStatus($accessKey->value(), $this->clientContext, [
            'sri_status' => $sriResponse['estado'] ?? 'PENDING',
            'sri_messages' => SriErrorInterpreter::enrich($sriResponse['comprobantes'] ?? null),
            'raw_response' => $sriResponse,
            'authorized_xml_received' => false,
            'reintento' => $this->shouldEnableRetry(
                $sriResponse['estado'] ?? 'PENDING',
                false
            ),
        ]);
        $this->invoiceRepository->markSequentialConsumed($this->clientContext, $branchId, $environmentKey, $sequential);

        // Actualizar estado del invoice según respuesta del SRI
        if (isset($sriResponse['estado']) && $sriResponse['estado'] === 'DEVUELTA') {
            // Convertir a array si es necesario
            $comprobantes = json_decode(json_encode($sriResponse['comprobantes'] ?? []), true);
            $mensaje = $comprobantes['comprobante']['mensajes']['mensaje'] ?? null;

            if ($mensaje && is_array($mensaje)) {
                $razon = $mensaje['mensaje'] ?? 'Rechazada por el SRI';
            } else {
                $razon = 'Rechazada por el SRI';
            }

            $invoice->reject($razon);
            $this->logger->warning('[EmitInvoice] Factura rechazada por el SRI', [
                'detalle' => $this->summarizeSriMessage($comprobantes['comprobante']['mensajes']['mensaje'] ?? null),
            ]);

            $this->invoiceRepository->updateStatus($accessKey->value(), $this->clientContext, [
                'sri_status' => 'DEVUELTA',
                'sri_messages' => SriErrorInterpreter::enrich($comprobantes['comprobante']['mensajes'] ?? []),
                'raw_response' => $sriResponse,
                'authorized_xml_received' => false,
                'reintento' => false,
            ]);
            $this->dispatchDomainEvent(new InvoiceRejected(
                accessKey: $accessKey->value(),
                reason: $razon
            ));
        } elseif (isset($sriResponse['estado']) && $sriResponse['estado'] === 'RECIBIDA') {
            sleep(3); // Esperar 3 segundos para que el SRI procese

            try {
                $authResponse = $this->sriGateway->checkAuthorization($accessKey->value());

                if ($authResponse['estado'] === 'AUTORIZADO') {
                    $invoice->authorize(
                        $authResponse['numeroAutorizacion'],
                        new \DateTimeImmutable($authResponse['fechaAutorizacion'])
                    );

                    $authorizedXmlPath = !empty($authResponse['comprobante'])
                        ? $this->saveAuthorizedXml($accessKey->value(), (string) $authResponse['comprobante'])
                        : null;

                    $this->invoiceRepository->updateStatus($accessKey->value(), $this->clientContext, [
                        'sri_status' => 'AUTORIZADO',
                        'authorization_number' => $authResponse['numeroAutorizacion'] ?? null,
                        'authorization_date' => $invoice->authorizationDate(),
                        'sri_messages' => SriErrorInterpreter::enrich($authResponse['mensajes'] ?? []),
                        'raw_response' => $authResponse,
                        'authorized_xml_path' => $authorizedXmlPath,
                        'authorized_xml_received' => !empty($authResponse['comprobante']),
                        'reintento' => $this->shouldEnableRetry(
                            'AUTORIZADO',
                            !empty($authResponse['comprobante'])
                        ),
                    ]);

                    $this->logger->info('[EmitInvoice] Factura recibida y autorizada');
                    $this->dispatchDomainEvent(new InvoiceAuthorized(
                        accessKey: $accessKey->value(),
                        authorizationNumber: (string) ($authResponse['numeroAutorizacion'] ?? $accessKey->value()),
                        authorizationDate: $this->normalizeEventDate($invoice->authorizationDate())
                    ));

                    $this->authorizedInvoiceMailer->sendAuthorizedDocuments(
                        $accessKey->value(),
                        $authResponse['numeroAutorizacion'] ?? null,
                        $invoice->authorizationDate()
                    );
                } elseif ($authResponse['estado'] === 'NO AUTORIZADO') {
                    $mensajes = $authResponse['mensajes'] ?? [];
                    $razon = !empty($mensajes) ? json_encode($mensajes) : 'No autorizada por el SRI';
                    $invoice->reject($razon);
                    $this->logger->warning('[EmitInvoice] Factura no autorizada', [
                        'detalle' => $this->summarizeSriMessage($mensajes[0] ?? null),
                    ]);

                    $this->invoiceRepository->updateStatus($accessKey->value(), $this->clientContext, [
                        'sri_status' => 'NO AUTORIZADO',
                        'sri_messages' => SriErrorInterpreter::enrich($mensajes),
                        'raw_response' => $authResponse,
                        'authorized_xml_received' => false,
                        'reintento' => false,
                    ]);
                    $this->dispatchDomainEvent(new InvoiceRejected(
                        accessKey: $accessKey->value(),
                        reason: $razon
                    ));
                } else {
                    $this->invoiceRepository->updateStatus($accessKey->value(), $this->clientContext, [
                        'sri_status' => $authResponse['estado'] ?? 'EN PROCESAMIENTO',
                        'sri_messages' => SriErrorInterpreter::enrich($authResponse['mensajes'] ?? []),
                        'raw_response' => $authResponse,
                        'authorized_xml_received' => false,
                        'reintento' => $this->shouldEnableRetry(
                            $authResponse['estado'] ?? 'EN PROCESAMIENTO',
                            false
                        ),
                    ]);
                }
                // Si está EN PROCESAMIENTO, se queda en PENDING

            } catch (\Exception $e) {
                $this->logger->error('[EmitInvoice] Error al consultar autorización', [
                    'error_type' => $e::class,
                    'error_code' => (int)$e->getCode(),
                ]);

                $this->invoiceRepository->updateStatus($accessKey->value(), $this->clientContext, [
                    'sri_status' => 'EN PROCESAMIENTO',
                    'sri_messages' => [['mensaje' => 'No se pudo consultar la autorizacion SRI.']],
                    'raw_response' => ['exception_type' => $e::class],
                    'authorized_xml_received' => false,
                    'reintento' => $this->shouldEnableRetry('EN PROCESAMIENTO', false),
                ]);
                // Continuar sin fallar - invoice se queda en PENDING
            }
        }

        $this->dispatchDomainEvent(new InvoiceEmitted(
            accessKey: $accessKey->value(),
            issuerRuc: $this->config['empresa']['ruc'],
            customerIdentification: $customerIdentificationValue,
            total: $invoice->total()->amount()
        ));

        $storedInvoice = $this->invoiceRepository->findInvoiceForClient($accessKey->value(), $this->clientContext);
        if (is_array($storedInvoice)) {
            return InvoiceResponse::fromStoredInvoice($storedInvoice);
        }

        return InvoiceResponse::fromInvoice($invoice);
        } finally {
            if ($sequentialLockAcquired) {
                $this->invoiceRepository->releaseSequentialLock($branchId, $environmentKey);
            }
            if ($sourceReferenceLockAcquired) {
                $this->invoiceRepository->releaseSourceReferenceLock($this->clientContext, $sourceReference);
            }
        }
    }

    private function mapItems(array $items): array
    {
        return array_map(function (array $item): array {
            $quantity = max(0.0, (float) ($item['quantity'] ?? 0));
            $unitPrice = max(0.0, (float) ($item['unit_price'] ?? 0));
            $discount = max(0.0, (float) ($item['discount'] ?? 0));
            $taxRate = $this->normalizeTaxRate($item['tax_rate'] ?? null);
            $lineSubtotal = $this->normalizeLineSubtotal($item['line_subtotal_net'] ?? null, $quantity, $unitPrice, $discount);
            $taxCode = trim((string) ($item['tax_code'] ?? EcuadorSriVatCatalog::TAX_CODE));
            if ($taxCode !== EcuadorSriVatCatalog::TAX_CODE) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported line tax code %s; Billing currently accepts Ecuador IVA code %s.',
                    $taxCode === '' ? '(empty)' : $taxCode,
                    EcuadorSriVatCatalog::TAX_CODE
                ));
            }
            $legacyTaxExempt = filter_var($item['tax_exempt'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $taxTreatment = EcuadorSriVatCatalog::normalizeTreatment(
                $item['tax_treatment'] ?? ($legacyTaxExempt ? EcuadorSriVatCatalog::TREATMENT_EXEMPT : null)
            )
                ?? EcuadorSriVatCatalog::inferTreatment($taxRate, $item['tax_percentage_code'] ?? null);
            $taxPercentageCode = $this->normalizeTaxPercentageCode(
                $item['tax_percentage_code'] ?? null,
                $taxRate,
                $taxTreatment
            );
            $taxAmount = $this->normalizeTaxAmount($item['tax_amount'] ?? null, $lineSubtotal, $taxRate);

            return [
                'code' => $item['code'] ?? 'PROD-001',
                'auxiliary_code' => $item['auxiliary_code'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'discount' => $discount,
                'lineSubtotal' => $lineSubtotal,
                'taxRate' => $taxRate,
                'taxCode' => $taxCode,
                'taxPercentageCode' => $taxPercentageCode,
                'taxTreatment' => $taxTreatment,
                'taxAmount' => $taxAmount,
                'additional_detail' => $item['additional_detail'] ?? null,
            ];
        }, $items);
    }

    private function calculateTaxes(array $items): array
    {
        $taxes = [];

        foreach ($items as $item) {
            if (!array_key_exists('taxCode', $item) || !array_key_exists('taxPercentageCode', $item)) {
                throw new \InvalidArgumentException('Mapped invoice items require explicit tax identity.');
            }
            $taxCode = (string) $item['taxCode'];
            $codePercentage = (string) $item['taxPercentageCode'];
            $groupKey = $taxCode . ':' . $codePercentage;

            if (!isset($taxes[$groupKey])) {
                $taxes[$groupKey] = [
                    'code' => $taxCode,
                    'codePercentage' => $codePercentage,
                    'baseAmount' => 0.0,
                    'amount' => 0.0,
                ];
            }

            $taxes[$groupKey]['baseAmount'] += (float) ($item['lineSubtotal'] ?? 0);
            $taxes[$groupKey]['amount'] += (float) ($item['taxAmount'] ?? 0);
        }

        foreach ($taxes as &$tax) {
            $tax['baseAmount'] = round((float) $tax['baseAmount'], 6);
            $tax['amount'] = round((float) $tax['amount'], 6);
        }
        unset($tax);

        return array_values($taxes);
    }

    private function normalizeCustomerIdentification(string $identification, string $customerName): string
    {
        $digits = preg_replace('/\D+/', '', $identification);
        $digits = is_string($digits) ? $digits : '';
        $normalizedName = strtolower(trim(preg_replace('/\s+/', ' ', $customerName) ?? $customerName));

        if (isset(self::FINAL_CONSUMER_PLACEHOLDERS[$digits])) {
            return self::FINAL_CONSUMER_IDENTIFICATION;
        }

        if (in_array($normalizedName, ['consumidor final', 'consumidorfinal'], true)) {
            return self::FINAL_CONSUMER_IDENTIFICATION;
        }

        return $identification;
    }

    private function assertFinalConsumerAmountAllowed(EmitInvoiceRequest $request, string $customerIdentification): void
    {
        $identification = preg_replace('/\D+/', '', $customerIdentification);
        if (!is_string($identification) || $identification !== self::FINAL_CONSUMER_IDENTIFICATION) {
            return;
        }

        if (round($this->calculateRequestTotal($request->items), 2) <= self::FINAL_CONSUMER_MAX_TOTAL) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Ventas mayores a USD %.2f no pueden facturarse como consumidor final. Ingresa una cédula o RUC válido del cliente.',
            self::FINAL_CONSUMER_MAX_TOTAL
        ));
    }

    private function calculateRequestTotal(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $quantity = max(0.0, (float) ($item['quantity'] ?? 0));
            $unitPrice = max(0.0, (float) ($item['unit_price'] ?? 0));
            $discount = max(0.0, (float) ($item['discount'] ?? 0));
            $taxRate = $this->normalizeTaxRate($item['tax_rate'] ?? null);
            $lineSubtotal = $this->normalizeLineSubtotal($item['line_subtotal_net'] ?? null, $quantity, $unitPrice, $discount);
            $taxAmount = $this->normalizeTaxAmount($item['tax_amount'] ?? null, $lineSubtotal, $taxRate);

            $total += $lineSubtotal + $taxAmount;
        }

        return max(0.0, round($total, 6));
    }

    private function saveAuthorizedXml(string $accessKey, string $authorizedXml): string
    {
        return (new BillingArtifactStorage())->putXml('autorizados', $accessKey, $authorizedXml);
    }

    private function dispatchDomainEvent(DomainEvent $event): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        try {
            $this->eventDispatcher->dispatch($event, $this->clientContext);
        } catch (\Throwable $e) {
            $this->logger->warning('[EmitInvoice] No se pudo registrar evento de dominio', [
                'event_name' => $event->eventName(),
                'event_id' => $event->eventId(),
                'error_type' => $e::class,
                'error_code' => (int)$e->getCode(),
            ]);
        }
    }

    private function normalizeEventDate(DateTimeImmutable|string|null $value): string
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return (new DateTimeImmutable('now', new DateTimeZone($this->config['timezone'] ?? 'America/Guayaquil')))
            ->format('Y-m-d H:i:s');
    }

    private function shouldEnableRetry(?string $sriStatus, bool $authorizedXmlReceived): bool
    {
        if ($authorizedXmlReceived) {
            return false;
        }

        $normalizedStatus = strtoupper(trim((string) ($sriStatus ?? 'UNKNOWN')));

        return in_array($normalizedStatus, ['RECIBIDA', 'EN PROCESAMIENTO', 'AUTORIZADO', 'PENDING', 'UNKNOWN'], true);
    }

    private function formatInvoiceNumber(string $establishment, string $emissionPoint, string $sequential): string
    {
        return sprintf(
            '%s-%s-%s',
            str_pad($establishment, 3, '0', STR_PAD_LEFT),
            str_pad($emissionPoint, 3, '0', STR_PAD_LEFT),
            str_pad($sequential, 9, '0', STR_PAD_LEFT)
        );
    }

    private function resolvePaymentMethod(EmitInvoiceRequest $request): array
    {
        $label = trim((string) ($request->paymentMethod ?? ''));
        $code = trim((string) ($request->paymentMethodCode ?? ''));

        if ($code !== '') {
            return [
                'code' => $code,
                'label' => $label !== '' ? $label : $this->paymentLabelFromCode($code),
            ];
        }

        if ($label === '') {
            return [
                'code' => '01',
                'label' => 'Sin utilizacion del sistema financiero',
            ];
        }

        $normalized = strtolower(trim($label));

        return match ($normalized) {
            'efectivo',
            'cash',
            'contra entrega',
            'pago contra entrega',
            'sin utilizacion del sistema financiero' => [
                'code' => '01',
                'label' => $label,
            ],
            'tarjeta de debito',
            'debito',
            'debit card' => [
                'code' => '16',
                'label' => $label,
            ],
            'tarjeta de credito',
            'credito',
            'credit',
            'credit_card',
            'credit card',
            'tarjeta de credito/debito',
            'tarjeta de credito / debito' => [
                'code' => '19',
                'label' => $label,
            ],
            'transferencia',
            'transferencia bancaria',
            'bank_transfer',
            'bank transfer' => [
                'code' => '20',
                'label' => $label,
            ],
            default => [
                'code' => '20',
                'label' => $label,
            ],
        };
    }

    private function paymentLabelFromCode(string $code): string
    {
        return match ($code) {
            '01' => 'Sin utilizacion del sistema financiero',
            '15' => 'Compensacion de deudas',
            '16' => 'Tarjeta de debito',
            '17' => 'Dinero electronico',
            '18' => 'Tarjeta prepago',
            '19' => 'Tarjeta de credito',
            '20' => 'Otros con utilizacion del sistema financiero',
            '21' => 'Endoso de titulos',
            default => 'Forma de pago ' . $code,
        };
    }

    private function normalizeTaxRate(mixed $value): float
    {
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException('Line tax_rate is required.');
        }

        return EcuadorSriVatCatalog::assertSupportedRate($value);
    }

    private function normalizeTaxPercentageCode(mixed $value, float $taxRate, string $taxTreatment): string
    {
        $normalized = trim((string) $value);
        if ($normalized !== '') {
            return EcuadorSriVatCatalog::assertCodeMatches($taxRate, $taxTreatment, $normalized);
        }

        return EcuadorSriVatCatalog::percentageCode($taxRate, $taxTreatment);
    }

    private function normalizeLineSubtotal(mixed $value, float $quantity, float $unitPrice, float $discount): float
    {
        if ($value !== null && $value !== '') {
            return max(0.0, round((float) $value, 6));
        }

        return max(0.0, round(($quantity * $unitPrice) - $discount, 6));
    }

    private function normalizeTaxAmount(mixed $value, float $lineSubtotal, float $taxRate): float
    {
        $expected = round($lineSubtotal * ($taxRate / 100), 2);
        if ($value !== null && $value !== '') {
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException('Line tax_amount must be numeric.');
            }
            $provided = max(0.0, round((float) $value, 2));
            if (abs($provided - $expected) > 0.005) {
                throw new \InvalidArgumentException(sprintf(
                    'Line tax_amount %.2f does not match taxable base %.2f at IVA %s%%; expected %.2f.',
                    $provided,
                    $lineSubtotal,
                    rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.'),
                    $expected
                ));
            }

            return $provided;
        }

        return max(0.0, $expected);
    }

    private function summarizeSriMessage(mixed $message): string
    {
        if (!is_array($message)) {
            return 'Sin detalle';
        }

        $identifier = trim((string) ($message['identificador'] ?? ''));
        $text = trim((string) ($message['mensaje'] ?? ''));
        $extra = trim((string) ($message['informacionAdicional'] ?? ''));

        $summary = $text !== '' ? $text : 'Sin detalle';
        if ($identifier !== '') {
            $summary = sprintf('%s (%s)', $summary, $identifier);
        }

        if ($extra !== '') {
            $summary .= ': ' . $extra;
        }

        return $summary;
    }
}
