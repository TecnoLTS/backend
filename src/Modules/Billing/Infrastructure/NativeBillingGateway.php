<?php

namespace App\Modules\Billing\Infrastructure;

use App\Core\Database;
use App\Modules\Billing\Application\BillingGateway;
use App\Modules\Billing\Domain\BillingDomain;
use App\Services\BillingApiException;
use BillingService\Billing\Application\Dto\Request\EmitInvoiceRequest;
use BillingService\Billing\Application\UseCases\CheckInvoiceStatus;
use BillingService\Billing\Application\UseCases\EmitInvoice;
use BillingService\Billing\Application\UseCases\ReissueStuckInvoice;
use BillingService\Billing\Domain\ValueObjects\AccessKey;
use BillingService\Billing\Infrastructure\Persistence\ApiKeyRepository;
use BillingService\Billing\Infrastructure\Persistence\BillingConfigurationRepository;
use BillingService\Billing\Infrastructure\Persistence\InvoiceRepository;
use BillingService\Billing\Infrastructure\Services\AuthorizedInvoiceMailer;
use BillingService\Billing\Infrastructure\Services\RidePdfGenerator;
use BillingService\Billing\Infrastructure\Services\RidePdfInvoiceDataFactory;
use BillingService\Billing\Infrastructure\Services\SoapSriConnector;
use BillingService\Billing\Infrastructure\Services\XadesBesSigner;
use BillingService\Billing\Infrastructure\Services\XmlInvoiceBuilder;
use BillingService\Billing\Infrastructure\Support\ClientConfigurationResolver;
use BillingService\Shared\Infrastructure\Persistence\PostgresDomainEventDispatcher;
use PDO;
use Psr\Log\NullLogger;

final class NativeBillingGateway implements BillingGateway {
    private PDO $connection;
    private ApiKeyRepository $apiKeys;
    private InvoiceRepository $invoices;
    private BillingConfigurationRepository $configuration;
    private NullLogger $logger;
    private PostgresDomainEventDispatcher $eventDispatcher;
    private array $baseConfig;
    private ?string $rawApiKey;
    private ?string $environmentOverride;

    public function __construct(?PDO $connection = null, ?string $rawApiKey = null, ?string $environmentOverride = null) {
        $this->connection = $connection ?? Database::getModuleInstance(BillingDomain::KEY);
        $this->apiKeys = new ApiKeyRepository($this->connection);
        $this->invoices = new InvoiceRepository($this->connection);
        $this->configuration = new BillingConfigurationRepository($this->connection);
        $this->logger = new NullLogger();
        $this->eventDispatcher = new PostgresDomainEventDispatcher($this->connection, $this->logger);
        $this->baseConfig = self::defaultSriConfig();
        $this->rawApiKey = is_string($rawApiKey) && trim($rawApiKey) !== '' ? trim($rawApiKey) : null;
        $this->environmentOverride = is_string($environmentOverride) && trim($environmentOverride) !== ''
            ? self::normalizeEnvironment($environmentOverride)
            : null;
    }

    public static function dependenciesAvailable(): bool {
        return class_exists(EmitInvoice::class)
            && class_exists(SoapSriConnector::class)
            && extension_loaded('soap')
            && extension_loaded('dom')
            && extension_loaded('openssl');
    }

    public function assertReady(): void {
        if (!self::dependenciesAvailable()) {
            throw new \RuntimeException('Dependencias nativas de Billing no disponibles.');
        }

        $statement = $this->connection->query(
            "SELECT to_regclass('public.invoice_headers') AS invoice_headers, to_regclass('public.api_keys') AS api_keys"
        );
        $row = $statement ? $statement->fetch() : null;
        if (!is_array($row) || empty($row['invoice_headers']) || empty($row['api_keys'])) {
            throw new \RuntimeException('DB fiscal facturacion no inicializada para driver nativo.');
        }
    }

    public function emitInvoice(array $payload): array {
        $environment = $this->environmentForPayload($payload);
        $clientContext = $this->clientContextForPayload($this->apiModeForEnvironment($environment), $payload);
        $resolvedConfig = $this->resolvedConfig($clientContext, $environment);
        $emit = $this->buildEmitInvoice($resolvedConfig, $clientContext);

        return $emit->execute(EmitInvoiceRequest::fromArray($payload))->toArray();
    }

    public function health(): array {
        return [
            'ok' => true,
            'service' => 'Billing',
            'status' => 'healthy',
            'driver' => 'native',
            'database' => 'facturacion',
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    public function listRidePdfs(int $limit = 100, bool $includeCancelled = false): array {
        $clientContext = $this->clientContext($this->defaultApiMode());
        $rows = $this->invoices->listRideInvoicesForClient($clientContext, max(1, min(300, $limit)), $includeCancelled);
        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $additionalInfo = $this->rawRequestAdditionalInfo($row);
            $accessKey = (string)($row['access_key'] ?? '');
            $pdfPath = $this->ridePdfPathForAccessKey($accessKey);
            $exists = is_file($pdfPath);
            $authorizedXmlPath = $this->localAuthorizedXmlPathForInvoice($row);
            $xmlExists = $authorizedXmlPath !== null;
            $isCancelled = $this->isCancelledRideInvoice($row);
            $canGenerate = $this->canGenerateRidePdf($row);
            $needsRefresh = $exists ? $this->ridePdfNeedsRefresh($pdfPath, $row) : false;

            $items[] = [
                'access_key' => $accessKey,
                'source_reference' => $row['source_reference'] ?? null,
                'authorization_number' => $row['authorization_number'] ?? null,
                'authorization_date' => $row['authorization_date'] ?? null,
                'issue_date' => $row['issue_date'] ?? null,
                'accounting_date' => $additionalInfo['accounting_date'] ?? null,
                'order_created_at' => $additionalInfo['order_created_at'] ?? null,
                'financial_period_key' => $additionalInfo['financial_period_key'] ?? null,
                'operational_error' => filter_var($additionalInfo['operational_error'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'operational_error_code' => $additionalInfo['operational_error_code'] ?? null,
                'operational_error_label' => $additionalInfo['operational_error_label'] ?? null,
                'operational_error_reason' => $additionalInfo['operational_error_reason'] ?? null,
                'operational_error_marked_at' => $additionalInfo['operational_error_marked_at'] ?? null,
                'operational_error_actor' => $additionalInfo['operational_error_actor'] ?? null,
                'customer_name' => $row['customer_name'] ?? null,
                'customer_identification' => $row['customer_identification'] ?? null,
                'customer_email' => $row['customer_email'] ?? null,
                'total' => isset($row['total_with_tax']) ? (float)$row['total_with_tax'] : 0.0,
                'total_tax' => isset($row['total_tax']) ? (float)$row['total_tax'] : 0.0,
                'establishment_code' => $row['establishment_code'] ?? null,
                'emission_point' => $row['emission_point'] ?? null,
                'sequential' => $row['sequential'] ?? null,
                'ambiente' => $row['ambiente'] ?? null,
                'sri_status' => $row['sri_status'] ?? null,
                'cancelled_at' => $row['cancelled_at'] ?? null,
                'cancellation_reason' => $row['cancellation_reason'] ?? null,
                'replacement_access_key' => $row['replacement_access_key'] ?? null,
                'replaced_access_key' => $row['replaced_access_key'] ?? null,
                'mail_sent_at' => $row['mail_sent_at'] ?? null,
                'is_cancelled' => $isCancelled,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'pdf_exists' => $exists,
                'pdf_can_generate' => (!$exists || $needsRefresh) && $canGenerate && !$isCancelled,
                'pdf_needs_refresh' => $needsRefresh,
                'pdf_size' => $exists ? filesize($pdfPath) : null,
                'pdf_modified_at' => $exists ? date(DATE_ATOM, (int)filemtime($pdfPath)) : null,
                'authorized_xml_received' => $xmlExists,
                'xml_exists' => $xmlExists,
                'xml_url' => $xmlExists ? sprintf('/api/v1/invoices/%s/xml', $accessKey) : null,
                'xml_size' => $xmlExists ? filesize((string)$authorizedXmlPath) : null,
                'xml_modified_at' => $xmlExists ? date(DATE_ATOM, (int)filemtime((string)$authorizedXmlPath)) : null,
            ];
        }

        return $items;
    }

    public function findRideBySourceReference(string $sourceReference): ?array {
        $sourceReference = trim($sourceReference);
        if ($sourceReference === '') {
            return null;
        }

        $clientContext = $this->clientContext($this->defaultApiMode());
        $invoice = $this->invoices->findActiveInvoiceBySourceReference($clientContext, $sourceReference);
        if (!is_array($invoice)) {
            return null;
        }

        $additionalInfo = $this->rawRequestAdditionalInfo($invoice);

        return [
            'access_key' => $invoice['access_key'] ?? null,
            'source_reference' => $invoice['source_reference'] ?? null,
            'authorization_number' => $invoice['authorization_number'] ?? null,
            'authorization_date' => $invoice['authorization_date'] ?? null,
            'issue_date' => $invoice['issue_date'] ?? null,
            'accounting_date' => $additionalInfo['accounting_date'] ?? null,
            'order_created_at' => $additionalInfo['order_created_at'] ?? null,
            'financial_period_key' => $additionalInfo['financial_period_key'] ?? null,
            'operational_error' => filter_var($additionalInfo['operational_error'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'operational_error_code' => $additionalInfo['operational_error_code'] ?? null,
            'operational_error_label' => $additionalInfo['operational_error_label'] ?? null,
            'operational_error_reason' => $additionalInfo['operational_error_reason'] ?? null,
            'operational_error_marked_at' => $additionalInfo['operational_error_marked_at'] ?? null,
            'operational_error_actor' => $additionalInfo['operational_error_actor'] ?? null,
            'customer_name' => $invoice['customer_name'] ?? null,
            'customer_identification' => $invoice['customer_identification'] ?? null,
            'customer_email' => $invoice['customer_email'] ?? null,
            'total' => isset($invoice['total_with_tax']) ? (float)$invoice['total_with_tax'] : 0.0,
            'total_tax' => isset($invoice['total_tax']) ? (float)$invoice['total_tax'] : 0.0,
            'establishment_code' => $invoice['establishment_code'] ?? null,
            'emission_point' => $invoice['emission_point'] ?? null,
            'sequential' => $invoice['sequential'] ?? null,
            'ambiente' => $invoice['ambiente'] ?? null,
            'sri_status' => $invoice['sri_status'] ?? null,
            'cancelled_at' => $invoice['cancelled_at'] ?? null,
            'cancellation_reason' => $invoice['cancellation_reason'] ?? null,
            'replacement_access_key' => $invoice['replacement_access_key'] ?? null,
            'replaced_access_key' => $invoice['replaced_access_key'] ?? null,
            'mail_sent_at' => $invoice['mail_sent_at'] ?? null,
            'created_at' => $invoice['created_at'] ?? null,
            'updated_at' => $invoice['updated_at'] ?? null,
        ];
    }

    public function getInvoiceStatus(string $accessKey, ?string $ambiente = null): array {
        $environment = $this->environmentForAmbiente($ambiente);
        $clientContext = $this->clientContext($this->apiModeForEnvironment($environment));
        $checkStatus = $this->buildCheckStatus($this->resolvedConfig($clientContext, $environment), $clientContext);

        return $checkStatus->execute($this->normalizeAccessKey($accessKey));
    }

    public function getInvoiceXml(string $accessKey, ?string $ambiente = null): array {
        $accessKey = $this->normalizeAccessKey($accessKey);
        $environment = $this->environmentForAmbiente($ambiente);
        $clientContext = $this->clientContext($this->apiModeForEnvironment($environment));
        $invoice = $this->ensureInvoiceAccess($accessKey, $clientContext);

        $xmlPath = $this->localAuthorizedXmlPathForInvoice($invoice);
        if ($xmlPath === null) {
            $this->getInvoiceStatus($accessKey, $ambiente);
            $invoice = $this->ensureInvoiceAccess($accessKey, $clientContext);
            $xmlPath = $this->localAuthorizedXmlPathForInvoice($invoice);
        }

        if ($xmlPath === null) {
            throw new BillingApiException('XML autorizado no disponible todavía para esta clave de acceso', 404, 'native://billing/xml');
        }

        $content = file_get_contents($xmlPath);
        if (!is_string($content) || trim($content) === '') {
            throw new BillingApiException('XML autorizado vacío o no disponible', 404, 'native://billing/xml');
        }

        return [
            'filename' => $accessKey . '.xml',
            'content' => $content,
        ];
    }

    public function getRidePdf(string $accessKey): array {
        $accessKey = $this->normalizeAccessKey($accessKey);
        $clientContext = $this->clientContext($this->defaultApiMode());
        $this->ensureInvoiceAccess($accessKey, $clientContext);
        $pdfPath = $this->generateRidePdfForAccessKey($accessKey, $clientContext, null);
        $content = file_get_contents($pdfPath);
        if (!is_string($content) || $content === '') {
            throw new BillingApiException('RIDE PDF vacío o no disponible', 404, 'native://billing/ride.pdf');
        }

        return [
            'filename' => 'RIDE-' . $accessKey . '.pdf',
            'content' => $content,
        ];
    }

    public function sendMailTest(string $accessKey, ?string $ambiente = null): array {
        $accessKey = $this->normalizeAccessKey($accessKey);
        $environment = $this->environmentForAmbiente($ambiente);
        $clientContext = $this->clientContext($this->apiModeForEnvironment($environment));
        $this->ensureInvoiceAccess($accessKey, $clientContext);
        $mailer = $this->buildAuthorizedMailer($this->resolvedConfig($clientContext, $environment), $clientContext);

        return $mailer->sendTestDocuments($accessKey);
    }

    public function cancelAndReissueInvoice(string $accessKey, string $reason = '', ?string $ambiente = null): array {
        $accessKey = $this->normalizeAccessKey($accessKey);
        $environment = $this->environmentForAmbiente($ambiente);
        $clientContext = $this->clientContext($this->apiModeForEnvironment($environment));
        $this->ensureInvoiceAccess($accessKey, $clientContext);
        $resolvedConfig = $this->resolvedConfig($clientContext, $environment);
        $emit = $this->buildEmitInvoice($resolvedConfig, $clientContext);
        $reissue = new ReissueStuckInvoice($emit, $this->invoices, $this->logger, $clientContext);

        return $reissue->execute($accessKey, trim($reason) !== '' ? trim($reason) : 'Reemision manual solicitada desde panel administrativo.');
    }

    public function configuration(?string $ambiente = null): array {
        $clientContext = $this->clientContext($this->apiModeForEnvironment($this->environmentForAmbiente($ambiente)));

        return $this->configuration->getConfiguration($clientContext, $this->baseConfig);
    }

    public function updateConfiguration(array $payload, ?string $ambiente = null): array {
        $clientContext = $this->clientContext($this->apiModeForEnvironment($this->environmentForAmbiente($ambiente)));

        return $this->configuration->updateConfiguration($clientContext, $payload, $this->baseConfig);
    }

    public function createBranch(array $payload, ?string $ambiente = null): array {
        $clientContext = $this->clientContext($this->apiModeForEnvironment($this->environmentForAmbiente($ambiente)));

        return $this->configuration->createBranch($clientContext, $payload, $this->baseConfig);
    }

    public function updateBranch(int $branchId, array $payload, ?string $ambiente = null): array {
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('Sucursal fiscal invalida');
        }
        $clientContext = $this->clientContext($this->apiModeForEnvironment($this->environmentForAmbiente($ambiente)));

        return $this->configuration->updateFiscalBranch($clientContext, $branchId, $payload, $this->baseConfig);
    }

    public function uploadCertificate(string $filePath, string $fileName, string $certificatePassword, ?string $ambiente = null): array {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException('Certificado no disponible para Billing');
        }

        $clientContext = $this->clientContext($this->apiModeForEnvironment($this->environmentForAmbiente($ambiente)));
        $upload = [
            'name' => $fileName,
            'tmp_name' => $filePath,
            'size' => filesize($filePath) ?: 0,
            'error' => UPLOAD_ERR_OK,
            'type' => 'application/x-pkcs12',
        ];

        return $this->configuration->uploadCertificate($clientContext, $upload, $certificatePassword, $this->baseConfig);
    }

    public static function defaultSriConfig(?string $environment = null): array {
        $storagePath = self::env('BILLING_STORAGE_PATH', '/var/www/html/storage/billing');
        $resolvedEnvironment = self::normalizeEnvironment($environment ?? self::env('SRI_ENVIRONMENT', self::defaultEnvironmentFromAppEnv()));

        return [
            'environment' => $resolvedEnvironment,
            'timezone' => self::env('APP_TIMEZONE', 'America/Guayaquil'),
            'empresa' => [
                'ruc' => self::env('SRI_RUC', self::env('EMPRESA_RUC', '')),
                'razon_social' => self::env('EMPRESA_RAZON_SOCIAL', ''),
                'nombre_comercial' => self::env('EMPRESA_NOMBRE_COMERCIAL', ''),
                'direccion_matriz' => self::env('EMPRESA_DIRECCION_MATRIZ', ''),
                'obligado_contabilidad' => self::env('EMPRESA_OBLIGADO_CONTABILIDAD', 'SI'),
            ],
            'establecimiento' => self::env('ESTABLECIMIENTO', '001'),
            'punto_emision' => self::env('PUNTO_EMISION', '001'),
            'logo_path' => self::env('BILLING_LOGO_PATH', self::env('LOGO_PATH', '/var/www/html/public/LogoVerde150.png')),
            'certificate' => [
                'path' => self::env('SRI_CERT_PATH', self::env('CERT_PATH', $storagePath . '/certs/firma.p12')),
                'password' => self::env('SRI_CERT_PASSWORD', self::env('CERT_PASSWORD', '')),
            ],
            'mail' => [
                'enabled' => filter_var(self::env('MAIL_ENABLED', self::env('SMTP_HOST', '') !== '' ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN),
                'host' => self::env('MAIL_HOST', self::env('SMTP_HOST', '')),
                'port' => (int)self::env('MAIL_PORT', self::env('SMTP_PORT', '465')),
                'encryption' => self::env('MAIL_ENCRYPTION', self::env('SMTP_SECURE', 'ssl')),
                'username' => self::env('MAIL_USERNAME', self::env('SMTP_USER', '')),
                'password' => self::env('MAIL_PASSWORD', self::env('SMTP_PASS', '')),
                'from_address' => self::env('MAIL_FROM_ADDRESS', ''),
                'from_name' => self::env('MAIL_FROM_NAME', 'Facturacion Electronica'),
                'reply_to_address' => self::env('MAIL_REPLY_TO_ADDRESS', ''),
                'reply_to_name' => self::env('MAIL_REPLY_TO_NAME', ''),
            ],
            'web_services' => [
                'pruebas' => [
                    'recepcion' => self::env('SRI_WS_RECEPCION_PRUEBAS', 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl'),
                    'autorizacion' => self::env('SRI_WS_AUTORIZACION_PRUEBAS', 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl'),
                ],
                'produccion' => [
                    'recepcion' => self::env('SRI_WS_RECEPCION_PRODUCCION', 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl'),
                    'autorizacion' => self::env('SRI_WS_AUTORIZACION_PRODUCCION', 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl'),
                ],
            ],
            'retry' => [
                'max_attempts' => max(1, (int)self::env('MAX_RETRY_ATTEMPTS', '3')),
                'delay_seconds' => max(3600, (int)self::env('RETRY_DELAY_SECONDS', '3600')),
                'sri_connection_delay_seconds' => max(5, (int)self::env('SRI_CONNECTION_RETRY_DELAY_SECONDS', '5')),
            ],
            'cache' => [
                'ttl' => max(60, (int)self::env('CACHE_TTL_SECONDS', '3600')),
            ],
        ];
    }

    private function buildEmitInvoice(array $resolvedConfig, array $clientContext): EmitInvoice {
        $mailer = $this->buildAuthorizedMailer($resolvedConfig, $clientContext);

        return new EmitInvoice(
            new XmlInvoiceBuilder($resolvedConfig),
            new XadesBesSigner($resolvedConfig),
            new SoapSriConnector($resolvedConfig, $this->logger),
            $this->logger,
            $resolvedConfig,
            $mailer,
            $this->invoices,
            $clientContext,
            $this->eventDispatcher
        );
    }

    private function buildCheckStatus(array $resolvedConfig, array $clientContext): CheckInvoiceStatus {
        return new CheckInvoiceStatus(
            new SoapSriConnector($resolvedConfig, $this->logger),
            $this->logger,
            $this->buildAuthorizedMailer($resolvedConfig, $clientContext),
            $this->invoices,
            $clientContext,
            $resolvedConfig,
            $this->eventDispatcher
        );
    }

    private function buildAuthorizedMailer(array $resolvedConfig, array $clientContext): AuthorizedInvoiceMailer {
        return new AuthorizedInvoiceMailer(
            new RidePdfGenerator($resolvedConfig['logo_path'] ?? '/var/www/html/public/LogoVerde150.png'),
            $this->logger,
            $resolvedConfig['mail'] ?? [],
            $this->invoices,
            $clientContext
        );
    }

    private function resolvedConfig(array $clientContext, ?string $environment): array {
        $environment = $this->environmentForAmbiente($environment);
        $this->assertProductionAllowed($environment);
        $baseConfig = self::defaultSriConfig($environment);
        $resolver = new ClientConfigurationResolver($baseConfig);

        return $resolver->resolve($clientContext, $environment);
    }

    private function clientContext(?string $requiredApiMode): array {
        $context = $this->apiKeys->findClientContextByRawKey($this->apiKey(), $requiredApiMode);
        if (!is_array($context)) {
            $message = match ($requiredApiMode) {
                'test' => 'API key fiscal inválida, revocada o sin acceso habilitado para API test',
                'production' => 'API key fiscal inválida, revocada o sin acceso habilitado para API producción',
                default => 'API key fiscal inválida o revocada',
            };
            throw new \InvalidArgumentException($message);
        }

        $this->apiKeys->touchUsage((int)$context['api_key_id']);
        return $context;
    }

    private function clientContextForPayload(?string $requiredApiMode, array $payload): array {
        $clientContext = $this->clientContext(null);
        $branch = is_array($payload['branch'] ?? null) ? $payload['branch'] : [];
        $branchId = null;
        foreach ([$payload['branch_id'] ?? null, $payload['branchId'] ?? null, $branch['id'] ?? null] as $candidate) {
            if (is_numeric($candidate) && (int)$candidate > 0) {
                $branchId = (int)$candidate;
                break;
            }
        }

        $branchCode = $payload['branch_code']
            ?? $payload['establishment_code']
            ?? $payload['establishment']
            ?? $branch['code']
            ?? null;
        $emissionPoint = $payload['emission_point']
            ?? $payload['emissionPoint']
            ?? $branch['emission_point']
            ?? null;

        return $this->apiKeys->withResolvedBranch(
            $clientContext,
            $branchId,
            is_string($branchCode) || is_numeric($branchCode) ? (string)$branchCode : null,
            is_string($emissionPoint) || is_numeric($emissionPoint) ? (string)$emissionPoint : null,
            $requiredApiMode
        );
    }

    private function ensureInvoiceAccess(string $accessKey, array $clientContext): array {
        $invoice = $this->invoices->findInvoiceForClient($accessKey, $clientContext);
        if (!is_array($invoice)) {
            throw new \InvalidArgumentException('Factura no encontrada para el cliente autenticado');
        }

        return $invoice;
    }

    private function generateRidePdfForAccessKey(string $accessKey, array $clientContext, ?string $environment): string {
        $invoice = $this->ensureInvoiceAccess($accessKey, $clientContext);
        if ($this->isCancelledRideInvoice($invoice)) {
            throw new \RuntimeException('La factura fue anulada o reemplazada y no se sirve como RIDE vigente.');
        }

        $pdfPath = $this->ridePdfPathForAccessKey((string)($invoice['access_key'] ?? $accessKey));
        $details = $this->invoices->findInvoiceDetailsForHeader((int)($invoice['id'] ?? 0));
        $localXmlPath = $this->localXmlPathForInvoice($invoice);
        $hasLocalDetailSource = $details !== [] || $this->rawRequestHasItems($invoice) || $localXmlPath !== null;

        if (!$this->ridePdfNeedsRefresh($pdfPath, $invoice) && $hasLocalDetailSource) {
            return $pdfPath;
        }

        if (!$this->canGenerateRidePdf($invoice)) {
            throw new \RuntimeException('El RIDE solo se puede generar para facturas autorizadas.');
        }

        $invoiceEnvironment = strtolower(trim((string)($invoice['ambiente'] ?? '')));
        $resolvedEnvironment = $invoiceEnvironment !== ''
            ? ($invoiceEnvironment === 'produccion' ? 'produccion' : 'pruebas')
            : $environment;
        $resolvedConfig = $this->resolvedConfig(
            $clientContext,
            $this->localDocumentConfigEnvironment($resolvedEnvironment)
        );
        $dataFactory = new RidePdfInvoiceDataFactory();
        $invoiceData = $details === [] && !$this->rawRequestHasItems($invoice) && $localXmlPath !== null
            ? $dataFactory->fromXmlFile($localXmlPath)
            : $dataFactory->fromDatabase($invoice, $details, $resolvedConfig);

        if (empty($invoiceData['items'])) {
            throw new \RuntimeException('No hay detalles locales suficientes para generar el RIDE sin consultar al SRI.');
        }

        $generator = new RidePdfGenerator($resolvedConfig['logo_path'] ?? '/var/www/html/public/LogoVerde150.png');
        return $generator->generate(
            (string)($invoice['access_key'] ?? $accessKey),
            $invoiceData,
            (string)(($invoice['authorization_number'] ?? '') ?: ($invoice['access_key'] ?? $accessKey)),
            isset($invoice['authorization_date']) ? (string)$invoice['authorization_date'] : null
        );
    }

    private function localAuthorizedXmlPathForInvoice(array $invoice): ?string {
        $paths = [
            $this->normalizeLegacyStoragePath($invoice['authorized_xml_path'] ?? null),
        ];

        $accessKey = preg_replace('/[^0-9]/', '', (string)($invoice['access_key'] ?? ''));
        if (is_string($accessKey) && $accessKey !== '') {
            $paths[] = '/var/www/html/storage/billing/xml/autorizados/' . $accessKey . '.xml';
        }

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }

        $authorizedXml = $this->authorizedXmlFromRawResponse($invoice);
        if ($authorizedXml !== null && is_string($accessKey) && $accessKey !== '') {
            $restoredPath = '/var/www/html/storage/billing/xml/autorizados/' . $accessKey . '.xml';
            @mkdir(dirname($restoredPath), 0777, true);
            file_put_contents($restoredPath, $authorizedXml);

            return $restoredPath;
        }

        return null;
    }

    private function localXmlPathForInvoice(array $invoice): ?string {
        $authorizedPath = $this->localAuthorizedXmlPathForInvoice($invoice);
        if ($authorizedPath !== null) {
            return $authorizedPath;
        }

        $paths = [
            $this->normalizeLegacyStoragePath($invoice['signed_xml_path'] ?? null),
        ];

        $accessKey = preg_replace('/[^0-9]/', '', (string)($invoice['access_key'] ?? ''));
        if (is_string($accessKey) && $accessKey !== '') {
            $paths[] = '/var/www/html/storage/billing/xml/firmados/' . $accessKey . '.xml';
            $paths[] = '/var/www/html/storage/billing/xml/generados/' . $accessKey . '.xml';
        }

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function ridePdfPathForAccessKey(string $accessKey): string {
        $normalized = $this->normalizeAccessKey($accessKey);
        return '/var/www/html/storage/billing/pdf/rides/' . $normalized . '.pdf';
    }

    private function canGenerateRidePdf(array $invoice): bool {
        return strtoupper(trim((string)($invoice['sri_status'] ?? ''))) === 'AUTORIZADO';
    }

    private function localDocumentConfigEnvironment(?string $environment): ?string {
        $normalized = self::normalizeEnvironment($environment ?? '');
        if ($normalized !== 'produccion') {
            return $normalized;
        }

        $appEnv = strtolower(trim(self::env('APP_ENV', 'production')));
        if ($appEnv !== 'qa') {
            return 'produccion';
        }

        // In QA we must not talk to SRI production, but local RIDE generation only needs
        // tenant branding/mail metadata. Reuse the safe config profile while the invoice payload
        // itself preserves its original production environment markers.
        return 'pruebas';
    }

    private function isCancelledRideInvoice(array $invoice): bool {
        $status = strtoupper(trim((string)($invoice['sri_status'] ?? '')));
        return trim((string)($invoice['cancelled_at'] ?? '')) !== ''
            || trim((string)($invoice['replacement_access_key'] ?? '')) !== ''
            || in_array($status, ['ANULADA_LOCAL', 'CANCELADA_LOCAL', 'CANCELLED', 'CANCELED'], true);
    }

    private function ridePdfNeedsRefresh(string $pdfPath, array $invoice): bool {
        if (!is_file($pdfPath)) {
            return true;
        }

        $updatedAt = trim((string)($invoice['updated_at'] ?? ''));
        if ($updatedAt === '') {
            return false;
        }

        $updatedTimestamp = strtotime($updatedAt);
        if ($updatedTimestamp === false) {
            return false;
        }

        return (int)filemtime($pdfPath) < $updatedTimestamp;
    }

    private function rawRequestHasItems(array $invoice): bool {
        $rawRequest = $invoice['raw_request'] ?? null;
        if (is_string($rawRequest) && trim($rawRequest) !== '') {
            $rawRequest = json_decode($rawRequest, true);
        }

        return is_array($rawRequest)
            && is_array($rawRequest['items'] ?? null)
            && count($rawRequest['items']) > 0;
    }

    private function rawRequestAdditionalInfo(array $invoice): array {
        $rawRequest = $invoice['raw_request'] ?? null;
        if (is_string($rawRequest) && trim($rawRequest) !== '') {
            $rawRequest = json_decode($rawRequest, true);
        }

        return is_array($rawRequest) && is_array($rawRequest['additional_info'] ?? null)
            ? $rawRequest['additional_info']
            : [];
    }

    private function authorizedXmlFromRawResponse(array $invoice): ?string {
        $rawResponse = $invoice['raw_response'] ?? null;
        if (is_string($rawResponse) && trim($rawResponse) !== '') {
            $rawResponse = json_decode($rawResponse, true);
        }
        if (!is_array($rawResponse)) {
            return null;
        }

        $status = strtoupper(trim((string)($invoice['sri_status'] ?? '')));
        $rawStatus = strtoupper(trim((string)($rawResponse['estado'] ?? $rawResponse['status'] ?? '')));
        if ($status !== 'AUTORIZADO' && $rawStatus !== 'AUTORIZADO') {
            return null;
        }

        foreach ([
            $rawResponse['comprobante'] ?? null,
            $rawResponse['authorization']['comprobante'] ?? null,
            $rawResponse['data']['comprobante'] ?? null,
        ] as $candidate) {
            $xml = is_string($candidate) ? trim($candidate) : '';
            if ($xml !== '' && preg_match('/^<\?xml\s|^<(factura|autorizacion)\b/i', $xml) === 1) {
                return $xml;
            }
        }

        return null;
    }

    private function normalizeLegacyStoragePath(mixed $path): ?string {
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        return str_replace('/app/storage', '/var/www/html/storage/billing', trim($path));
    }

    private function normalizeAccessKey(string $accessKey): string {
        $normalized = preg_replace('/[^0-9]/', '', $accessKey);
        if (!is_string($normalized) || $normalized === '') {
            throw new \InvalidArgumentException('Clave de acceso inválida');
        }

        AccessKey::fromValue($normalized);
        return $normalized;
    }

    private function environmentForPayload(array $payload): string {
        foreach ([$payload['ambiente'] ?? null, $payload['environment'] ?? null, $payload['sri_environment'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->environmentForAmbiente($candidate);
            }
        }

        return $this->environmentForAmbiente(null);
    }

    private function environmentForAmbiente(?string $ambiente): string {
        $normalized = self::normalizeEnvironment($ambiente ?? '');
        if ($normalized === 'produccion') {
            $this->assertProductionAllowed($normalized);
            return 'produccion';
        }
        if ($normalized === 'pruebas') {
            return 'pruebas';
        }
        if ($this->environmentOverride !== null && $this->environmentOverride !== '') {
            if ($this->environmentOverride === 'produccion') {
                $this->assertProductionAllowed($this->environmentOverride);
            }

            return $this->environmentOverride;
        }

        return self::normalizeEnvironment(self::env('SRI_ENVIRONMENT', self::defaultEnvironmentFromAppEnv()));
    }

    private function defaultApiMode(): string {
        return $this->apiModeForEnvironment($this->environmentForAmbiente(null));
    }

    private function apiModeForEnvironment(?string $environment): string {
        return self::normalizeEnvironment($environment ?? '') === 'produccion' ? 'production' : 'test';
    }

    private function assertProductionAllowed(string $environment): void {
        if ($environment !== 'produccion') {
            return;
        }

        $appEnv = strtolower(trim(self::env('APP_ENV', 'production')));
        if (in_array($appEnv, ['production', 'prod'], true)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Uso de Billing SRI producción bloqueado en APP_ENV=%s. En QA use ambiente pruebas.',
            $appEnv !== '' ? $appEnv : 'qa'
        ));
    }

    private function apiKey(): string {
        $apiKey = $this->rawApiKey ?? trim(self::env('BILLING_API_KEY', ''));
        if ($apiKey === '') {
            $apiKey = trim(self::env('FACTURADOR_API_KEY', ''));
        }
        if ($apiKey === '') {
            throw new \RuntimeException('BILLING_API_KEY no configurado para Billing nativo');
        }

        return $apiKey;
    }

    private static function normalizeEnvironment(string $value): string {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['produccion', 'production', 'prod'], true)) {
            return 'produccion';
        }
        if (in_array($normalized, ['pruebas', 'test', 'testing', 'qa'], true)) {
            return 'pruebas';
        }

        return '';
    }

    private static function defaultEnvironmentFromAppEnv(): string {
        $appEnv = strtolower(trim(self::env('APP_ENV', 'production')));
        return $appEnv === 'qa' ? 'pruebas' : 'produccion';
    }

    private static function env(string $key, string $default = ''): string {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || trim((string)$value) === '') {
            return $default;
        }

        return trim((string)$value);
    }
}
