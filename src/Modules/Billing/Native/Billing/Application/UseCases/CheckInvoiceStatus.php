<?php

declare(strict_types=1);

namespace BillingService\Billing\Application\UseCases;

use App\Infrastructure\Storage\Billing\BillingArtifactStorage;
use BillingService\Billing\Infrastructure\Persistence\InvoiceRepository;
use BillingService\Billing\Application\Ports\SriGatewayInterface;
use BillingService\Billing\Application\Services\SriErrorInterpreter;
use BillingService\Billing\Domain\Events\InvoiceAuthorized;
use BillingService\Billing\Domain\Events\InvoiceRejected;
use BillingService\Billing\Infrastructure\Services\AuthorizedInvoiceMailer;
use BillingService\Billing\Domain\ValueObjects\AccessKey;
use BillingService\Shared\Application\Events\DomainEventDispatcher;
use BillingService\Shared\Domain\Events\DomainEvent;
use Psr\Log\LoggerInterface;

class CheckInvoiceStatus
{
    public function __construct(
        private readonly SriGatewayInterface $sriGateway,
        private readonly LoggerInterface $logger,
        private readonly AuthorizedInvoiceMailer $authorizedInvoiceMailer,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly array $clientContext,
        private readonly array $config = [],
        private readonly ?DomainEventDispatcher $eventDispatcher = null
    ) {}

    public function execute(string $accessKey): array
    {
        // Validar formato de clave de acceso
        AccessKey::fromValue($accessKey);

        $invoice = $this->invoiceRepository->findInvoiceForClient($accessKey, $this->clientContext);
        if ($invoice === null) {
            throw new \InvalidArgumentException('Factura no encontrada para el cliente autenticado');
        }

        $restoredAuthorizedXmlPath = $this->restoreAuthorizedXmlFromLocalCache($accessKey, $invoice);
        if ($restoredAuthorizedXmlPath !== null) {
            $invoice['authorized_xml_path'] = $restoredAuthorizedXmlPath;
            $invoice['authorized_xml_received'] = true;
        }

        $localStatus = strtoupper(trim((string) ($invoice['sri_status'] ?? '')));
        $isLocallyCancelled = trim((string) ($invoice['cancelled_at'] ?? '')) !== ''
            || trim((string) ($invoice['replacement_access_key'] ?? '')) !== ''
            || in_array($localStatus, ['ANULADA_LOCAL', 'CANCELADA_LOCAL', 'CANCELLED', 'CANCELED'], true);

        if ($isLocallyCancelled) {
            return [
                'access_key' => $accessKey,
                'status' => $localStatus !== '' ? $localStatus : 'ANULADA_LOCAL',
                'authorization_number' => $invoice['authorization_number'] ?? null,
                'authorization_date' => $invoice['authorization_date'] ?? null,
                'replacement_access_key' => $invoice['replacement_access_key'] ?? null,
                'messages' => [[
                    'mensaje' => 'Factura anulada o reemplazada localmente. No se consulta al SRI.',
                ]],
                'xml_url' => null,
            ];
        }

        $configuredEnvironment = $this->configuredEnvironment();
        $invoiceEnvironment = $this->invoiceEnvironment($invoice, $configuredEnvironment);
        $hasLocalAuthorization = $this->hasLocalAuthorization($invoice);
        $hasAuthorizedXml = $this->authorizedXmlExists($accessKey) || $this->localAuthorizedXmlReceived($invoice);
        if ($invoiceEnvironment !== $configuredEnvironment) {
            $this->logger->warning('[CheckInvoiceStatus] Ambiente de comprobante no coincide con endpoint SRI. Se conserva estado local.', [
                'access_key' => $accessKey,
                'invoice_environment' => $invoiceEnvironment,
                'configured_environment' => $configuredEnvironment,
            ]);

            if ($hasLocalAuthorization && $localStatus !== 'AUTORIZADO') {
                $this->invoiceRepository->updateStatus($accessKey, $this->clientContext, [
                    'sri_status' => 'AUTORIZADO',
                    'authorization_number' => $invoice['authorization_number'] ?? null,
                    'authorization_date' => $invoice['authorization_date'] ?? null,
                    'sri_messages' => [[
                        'mensaje' => 'Autorizacion local preservada. No se consulto al SRI para no mezclar ambientes.',
                    ]],
                    'raw_response' => [
                        'source' => 'local_cache',
                        'reason' => 'environment_mismatch_with_local_authorization',
                        'previous_status' => $localStatus,
                        'invoice_environment' => $invoiceEnvironment,
                        'configured_environment' => $configuredEnvironment,
                    ],
                    'authorized_xml_path' => $this->authorizedXmlExists($accessKey)
                        ? $this->authorizedXmlPath($accessKey)
                        : ($invoice['authorized_xml_path'] ?? null),
                    'authorized_xml_received' => $hasAuthorizedXml,
                    'reintento' => false,
                ]);
                $this->markSequentialConsumedFromInvoice($invoice);
            }

            return $this->localStatusResponse(
                accessKey: $accessKey,
                invoice: $invoice,
                status: $hasLocalAuthorization ? 'AUTORIZADO' : ($localStatus !== '' ? $localStatus : 'SIN_ESTADO_LOCAL'),
                message: sprintf(
                    'Comprobante de ambiente %s consultado desde endpoint %s. No se consulta al SRI para evitar mezclar ambientes.',
                    $invoiceEnvironment,
                    $configuredEnvironment
                )
            );
        }

        if (
            $hasLocalAuthorization
            && $localStatus === 'AUTORIZADO'
            && $hasAuthorizedXml
            && !$this->shouldForceSriRefresh($invoice)
        ) {
            return $this->localStatusResponse(
                accessKey: $accessKey,
                invoice: $invoice,
                status: 'AUTORIZADO',
                message: 'Factura autorizada localmente con XML disponible. Estado local completo.'
            );
        }

        $this->logger->info('[CheckInvoiceStatus] Consultando estado en el SRI', [
            'access_key' => $accessKey
        ]);

        // Consultar en el SRI
        $authorization = $this->sriGateway->checkAuthorization($accessKey);

        $this->logger->info('[CheckInvoiceStatus] Estado obtenido', [
            'access_key' => $accessKey,
            'status' => $authorization['estado'] ?? 'UNKNOWN'
        ]);

        $status = strtoupper(trim((string) ($authorization['estado'] ?? 'UNKNOWN')));
        $authorizationXmlReceived = false;
        if ($status === 'AUTORIZADO' && !empty($authorization['comprobante'])) {
            $this->saveAuthorizedXml($accessKey, (string) $authorization['comprobante']);
            $authorizationXmlReceived = true;
        }

        if ($status === 'AUTORIZADO') {
            $authorizedXmlExists = $authorizationXmlReceived || $this->authorizedXmlExists($accessKey);
            $authorizedXmlPath = $authorizedXmlExists ? $this->authorizedXmlPath($accessKey) : null;

            if ($authorizationXmlReceived) {
                $this->authorizedInvoiceMailer->sendAuthorizedDocuments(
                    $accessKey,
                    $authorization['numeroAutorizacion'] ?? null,
                    $authorization['fechaAutorizacion'] ?? null
                );
            }

            $this->invoiceRepository->updateStatus($accessKey, $this->clientContext, [
                'sri_status' => 'AUTORIZADO',
                'authorization_number' => $authorization['numeroAutorizacion'] ?? null,
                'authorization_date' => $authorization['fechaAutorizacion'] ?? null,
                'sri_messages' => SriErrorInterpreter::enrich($authorization['mensajes'] ?? []),
                'raw_response' => $authorization,
                'authorized_xml_path' => $authorizedXmlPath,
                'authorized_xml_received' => $authorizedXmlExists,
                'reintento' => $this->shouldEnableRetry('AUTORIZADO', $authorizedXmlExists),
            ]);
            $this->markSequentialConsumedFromInvoice($invoice);

            if ($localStatus !== 'AUTORIZADO' || !$hasLocalAuthorization) {
                $this->dispatchDomainEvent(new InvoiceAuthorized(
                    accessKey: $accessKey,
                    authorizationNumber: (string) ($authorization['numeroAutorizacion'] ?? $accessKey),
                    authorizationDate: (string) ($authorization['fechaAutorizacion'] ?? date('Y-m-d H:i:s'))
                ));
            }
        } else {
            $authorizedXmlExists = $this->authorizedXmlExists($accessKey);

            $this->invoiceRepository->updateStatus($accessKey, $this->clientContext, [
                'sri_status' => $status,
                'authorization_number' => $authorization['numeroAutorizacion'] ?? null,
                'authorization_date' => $authorization['fechaAutorizacion'] ?? null,
                'sri_messages' => SriErrorInterpreter::enrich($authorization['mensajes'] ?? []),
                'raw_response' => $authorization,
                'authorized_xml_received' => $authorizedXmlExists,
                'reintento' => $this->shouldEnableRetry($status, $authorizedXmlExists),
            ]);
            $this->markSequentialConsumedFromInvoice($invoice);

            if ($status === 'NO AUTORIZADO' && $localStatus !== 'NO AUTORIZADO') {
                $this->dispatchDomainEvent(new InvoiceRejected(
                    accessKey: $accessKey,
                    reason: $this->rejectionReason($authorization['mensajes'] ?? [])
                ));
            }
        }

        return [
            'access_key' => $accessKey,
            'status' => $status,
            'authorization_number' => $authorization['numeroAutorizacion'] ?? null,
            'authorization_date' => $authorization['fechaAutorizacion'] ?? null,
            'messages' => SriErrorInterpreter::enrich($authorization['mensajes'] ?? []),
            'xml_url' => $this->authorizedXmlExists($accessKey)
                ? sprintf('/api/v1/invoices/%s/xml', $accessKey)
                : null,
        ];
    }

    private function markSequentialConsumedFromInvoice(array $invoice): void
    {
        $branchId = (int) ($invoice['branch_id'] ?? $this->clientContext['resolved_branch_id'] ?? 0);
        $environment = trim((string) ($invoice['ambiente'] ?? $this->configuredEnvironment()));
        $sequential = trim((string) ($invoice['sequential'] ?? ''));

        if ($branchId <= 0 || $environment === '' || $sequential === '') {
            return;
        }

        $this->invoiceRepository->markSequentialConsumed($this->clientContext, $branchId, $environment, $sequential);
    }

    private function hasLocalAuthorization(array $invoice): bool
    {
        return trim((string) ($invoice['authorization_number'] ?? '')) !== ''
            || trim((string) ($invoice['authorization_date'] ?? '')) !== ''
            || $this->localAuthorizedXmlReceived($invoice);
    }

    private function localAuthorizedXmlReceived(array $invoice): bool
    {
        $storedPath = trim((string) ($invoice['authorized_xml_path'] ?? ''));

        return filter_var($invoice['authorized_xml_received'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && $storedPath !== ''
            && (new BillingArtifactStorage())->exists($storedPath);
    }

    private function restoreAuthorizedXmlFromLocalCache(string $accessKey, array $invoice): ?string
    {
        if ($this->authorizedXmlExists($accessKey)) {
            return $this->authorizedXmlPath($accessKey);
        }

        $authorizedXml = $this->authorizedXmlFromRawResponse($invoice['raw_response'] ?? null);
        if ($authorizedXml === null) {
            return null;
        }

        $this->saveAuthorizedXml($accessKey, $authorizedXml);

        $localStatus = strtoupper(trim((string) ($invoice['sri_status'] ?? '')));
        $cachedStatus = $this->statusFromRawResponse($invoice['raw_response'] ?? null);
        $this->invoiceRepository->updateStatus($accessKey, $this->clientContext, [
            'sri_status' => $cachedStatus ?? ($localStatus !== '' ? $localStatus : 'AUTORIZADO'),
            'authorization_number' => $invoice['authorization_number'] ?? null,
            'authorization_date' => $invoice['authorization_date'] ?? null,
            'authorized_xml_path' => $this->authorizedXmlPath($accessKey),
            'authorized_xml_received' => true,
            'reintento' => false,
        ]);

        $this->logger->info('[CheckInvoiceStatus] XML autorizado restaurado desde respuesta local', [
            'access_key' => $accessKey,
            'authorized_xml_path' => $this->authorizedXmlPath($accessKey),
        ]);

        return $this->authorizedXmlPath($accessKey);
    }

    private function authorizedXmlFromRawResponse(mixed $rawResponse): ?string
    {
        $decoded = $this->decodeJsonObject($rawResponse);
        if ($decoded === null) {
            return null;
        }

        $candidates = [
            $decoded['comprobante'] ?? null,
            $decoded['authorization']['comprobante'] ?? null,
            $decoded['data']['comprobante'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $xml = is_string($candidate) ? trim($candidate) : '';
            if ($xml !== '' && preg_match('/^<\\?xml\\s|^<(factura|autorizacion)\\b/i', $xml) === 1) {
                return $xml;
            }
        }

        return null;
    }

    private function statusFromRawResponse(mixed $rawResponse): ?string
    {
        $decoded = $this->decodeJsonObject($rawResponse);
        $status = strtoupper(trim((string) ($decoded['estado'] ?? $decoded['status'] ?? '')));

        return $status !== '' ? $status : null;
    }

    private function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function localStatusResponse(string $accessKey, array $invoice, string $status, string $message): array
    {
        return [
            'access_key' => $accessKey,
            'status' => $status,
            'authorization_number' => $invoice['authorization_number'] ?? null,
            'authorization_date' => $invoice['authorization_date'] ?? null,
            'messages' => [[
                'mensaje' => $message,
            ]],
            'xml_url' => $this->authorizedXmlExists($accessKey) || $this->localAuthorizedXmlReceived($invoice)
                ? sprintf('/api/v1/invoices/%s/xml', $accessKey)
                : null,
        ];
    }

    private function configuredEnvironment(): string
    {
        return $this->normalizeEnvironment($this->config['environment'] ?? 'pruebas');
    }

    private function invoiceEnvironment(array $invoice, string $fallback): string
    {
        return $this->normalizeEnvironment($invoice['ambiente'] ?? $fallback);
    }

    private function normalizeEnvironment(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        return $normalized === 'produccion' || $normalized === 'production'
            ? 'produccion'
            : 'pruebas';
    }

    private function saveAuthorizedXml(string $accessKey, string $authorizedXml): void
    {
        (new BillingArtifactStorage())->putXml('autorizados', $accessKey, $authorizedXml);
    }

    private function dispatchDomainEvent(DomainEvent $event): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        try {
            $this->eventDispatcher->dispatch($event, $this->clientContext);
        } catch (\Throwable $e) {
            $this->logger->warning('[CheckInvoiceStatus] No se pudo registrar evento de dominio', [
                'event_name' => $event->eventName(),
                'event_id' => $event->eventId(),
                'error_type' => $e::class,
                'error_code' => (int)$e->getCode(),
            ]);
        }
    }

    private function rejectionReason(mixed $messages): string
    {
        if (is_array($messages)) {
            $first = $messages[0] ?? $messages['mensaje'] ?? null;
            if (is_array($first)) {
                foreach (['mensaje', 'informacionAdicional', 'identificador'] as $key) {
                    $value = trim((string) ($first[$key] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }

            if (is_string($first) && trim($first) !== '') {
                return trim($first);
            }
        }

        return 'No autorizada por el SRI';
    }

    private function authorizedXmlExists(string $accessKey): bool
    {
        return (new BillingArtifactStorage())->exists($this->authorizedXmlPath($accessKey));
    }

    private function authorizedXmlPath(string $accessKey): string
    {
        return (new BillingArtifactStorage())->xmlReference('autorizados', $accessKey);
    }

    private function shouldForceSriRefresh(array $invoice): bool
    {
        return filter_var($invoice['force_sri_refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function shouldEnableRetry(?string $sriStatus, bool $authorizedXmlReceived): bool
    {
        if ($authorizedXmlReceived) {
            return false;
        }

        $normalizedStatus = strtoupper(trim((string) ($sriStatus ?? 'UNKNOWN')));

        return in_array($normalizedStatus, ['RECIBIDA', 'EN PROCESAMIENTO', 'AUTORIZADO', 'PENDING', 'UNKNOWN'], true);
    }
}
