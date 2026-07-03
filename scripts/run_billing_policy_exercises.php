<?php

declare(strict_types=1);

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    '/var/www/html/vendor/autoload.php',
];

$autoloadPath = null;
foreach ($autoloadCandidates as $candidate) {
    if (is_string($candidate) && is_file($candidate)) {
        $autoloadPath = $candidate;
        break;
    }
}

if (!is_string($autoloadPath)) {
    fwrite(STDERR, "No se encontro vendor/autoload.php para run_billing_policy_exercises.php\n");
    exit(1);
}

require_once $autoloadPath;

use App\Core\Database;
use App\Modules\Billing\Domain\BillingDomain;
use BillingService\Billing\Application\Dto\Request\EmitInvoiceRequest;
use BillingService\Billing\Application\Ports\DocumentSignerInterface;
use BillingService\Billing\Application\Ports\SriGatewayInterface;
use BillingService\Billing\Application\Ports\XmlBuilderInterface;
use BillingService\Billing\Application\UseCases\EmitInvoice;
use BillingService\Billing\Domain\Entities\Invoice;
use BillingService\Billing\Infrastructure\Persistence\InvoiceRepository;
use BillingService\Billing\Infrastructure\Services\AuthorizedInvoiceMailer;
use BillingService\Billing\Infrastructure\Services\RidePdfGenerator;
use Dotenv\Dotenv;
use Psr\Log\NullLogger;

final class ExerciseXmlBuilder implements XmlBuilderInterface
{
    public ?Invoice $lastInvoice = null;

    public function buildInvoiceXml(Invoice $invoice): string
    {
        $this->lastInvoice = $invoice;

        return sprintf(
            '<factura><cliente>%s</cliente><total>%.2f</total></factura>',
            htmlspecialchars($invoice->customerName(), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $invoice->total()->amount()
        );
    }
}

final class ExerciseSigner implements DocumentSignerInterface
{
    public function sign(string $xml): string
    {
        return $xml;
    }

    public function verify(string $signedXml): bool
    {
        return trim($signedXml) !== '';
    }
}

final class ExerciseSriGateway implements SriGatewayInterface
{
    public array $sentDocuments = [];

    public function sendDocument(string $signedXml): array
    {
        $this->sentDocuments[] = $signedXml;

        return [
            'estado' => 'DEVUELTA',
            'comprobantes' => [
                'comprobante' => [
                    'mensajes' => [
                        'mensaje' => [
                            'identificador' => '43',
                            'mensaje' => 'Prueba QA',
                            'informacionAdicional' => 'Validador local',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function checkAuthorization(string $accessKey): array
    {
        return [
            'estado' => 'NO AUTORIZADO',
            'mensajes' => [],
        ];
    }
}

final class ExerciseInvoiceRepository extends InvoiceRepository
{
    public ?Invoice $lastDraftInvoice = null;
    public ?array $lastDraftPayload = null;
    public ?array $lastStatusUpdate = null;
    public ?string $lastSequential = null;
    public int $draftInvoicesCreated = 0;

    public function __construct(PDO $connection)
    {
        parent::__construct($connection);
    }

    public function acquireSourceReferenceLock(array $clientContext, string $sourceReference): void
    {
    }

    public function releaseSourceReferenceLock(array $clientContext, string $sourceReference): void
    {
    }

    public function findActiveInvoiceBySourceReference(array $clientContext, string $sourceReference): ?array
    {
        return null;
    }

    public function acquireSequentialLock(int $branchId, string $environment): void
    {
    }

    public function releaseSequentialLock(int $branchId, string $environment): void
    {
    }

    public function nextSequentialForBranchAndEnvironment(int $branchId, string $environment): string
    {
        return '000000123';
    }

    public function markSequentialConsumed(int $branchId, string $environment, string $sequential): void
    {
        $this->lastSequential = $sequential;
    }

    public function createDraftInvoice(array $clientContext, Invoice $invoice, array $requestPayload, string $signedXmlPath): int
    {
        $this->draftInvoicesCreated++;
        $this->lastDraftInvoice = $invoice;
        $this->lastDraftPayload = $requestPayload;

        return 1;
    }

    public function updateStatus(string $accessKey, array $clientContext, array $data): void
    {
        $this->lastStatusUpdate = [
            'access_key' => $accessKey,
            'data' => $data,
        ];
    }

    public function findInvoiceForClient(string $accessKey, array $clientContext): ?array
    {
        return null;
    }
}

$envDir = __DIR__ . '/../entorno';
if (is_readable($envDir . '/.env')) {
    Dotenv::createImmutable($envDir)->safeLoad();
}

$logger = new NullLogger();
$connection = Database::getModuleInstance(BillingDomain::KEY);
$xmlBuilder = new ExerciseXmlBuilder();
$signer = new ExerciseSigner();
$sriGateway = new ExerciseSriGateway();
$invoiceRepository = new ExerciseInvoiceRepository($connection);
$clientContext = [
    'client_id' => 1,
    'branch_id' => 1,
    'resolved_branch_id' => 1,
    'api_key_id' => 1,
];
$config = [
    'timezone' => 'America/Guayaquil',
    'environment' => 'pruebas',
    'establecimiento' => '001',
    'punto_emision' => '001',
    'empresa' => [
        'ruc' => trim((string) ($_ENV['SRI_RUC'] ?? '1759687682001')),
        'razon_social' => 'Paramascotas QA',
    ],
    'mail' => [
        'enabled' => false,
    ],
];
$mailer = new AuthorizedInvoiceMailer(
    new RidePdfGenerator('/var/www/html/public/LogoVerde150.png'),
    $logger,
    $config['mail'],
    $invoiceRepository,
    $clientContext
);
$useCase = new EmitInvoice(
    $xmlBuilder,
    $signer,
    $sriGateway,
    $logger,
    $config,
    $mailer,
    $invoiceRepository,
    $clientContext,
    null
);

$makeRequest = static function (array $overrides = []): EmitInvoiceRequest {
    $payload = array_replace_recursive([
        'customer_identification' => '1702527887',
        'customer_name' => 'Cliente QA',
        'customer_address' => 'Quito',
        'customer_email' => 'qa.billing-policy@paramascotasec.com',
        'payment_method' => 'Tarjeta de credito',
        'additional_info' => [
            'order_id' => 'QA-BILLING-POLICY-' . gmdate('YmdHis'),
        ],
        'items' => [
            [
                'description' => 'Alimento premium',
                'quantity' => 2,
                'unit_price' => 30.00,
                'discount' => 0.00,
                'tax_rate' => 15.0,
            ],
        ],
    ], $overrides);

    return EmitInvoiceRequest::fromArray($payload);
};

$unlinkGeneratedArtifacts = static function (?Invoice $invoice): void {
    if (!$invoice instanceof Invoice) {
        return;
    }

    $accessKey = $invoice->accessKey()->value();
    foreach ([
        "/var/www/html/storage/billing/xml/generados/{$accessKey}.xml",
        "/var/www/html/storage/billing/xml/firmados/{$accessKey}.xml",
        "/var/www/html/storage/billing/xml/autorizados/{$accessKey}.xml",
    ] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
};

$report = [
    'started_at' => date('c'),
    'checks' => [],
];

try {
    try {
        $useCase->execute($makeRequest([
            'customer_identification' => '9999999999',
            'customer_name' => 'Consumidor Final',
        ]));

        $report['checks']['final_consumer_placeholder_over_limit'] = [
            'blocked' => false,
            'message' => null,
            'draft_invoices_created' => $invoiceRepository->draftInvoicesCreated,
        ];
    } catch (\InvalidArgumentException $exception) {
        $report['checks']['final_consumer_placeholder_over_limit'] = [
            'blocked' => str_contains($exception->getMessage(), 'Ventas mayores a USD 50.00'),
            'message' => $exception->getMessage(),
            'draft_invoices_created' => $invoiceRepository->draftInvoicesCreated,
        ];
    }

    $allowedResponse = $useCase->execute($makeRequest());
    $allowedInvoice = $invoiceRepository->lastDraftInvoice;
    $report['checks']['identified_customer_over_limit'] = [
        'allowed' => $allowedResponse->status === 'REJECTED'
            && $invoiceRepository->draftInvoicesCreated === 1
            && $allowedInvoice instanceof Invoice,
        'response_total' => $allowedResponse->total,
        'response_status' => $allowedResponse->status,
        'customer_identification' => $allowedInvoice?->customerIdentification()->value(),
        'payment_method_code' => $allowedInvoice?->paymentMethodCode(),
        'payment_method_label' => $allowedInvoice?->paymentMethodLabel(),
        'stored_sequential' => $invoiceRepository->lastSequential,
        'sri_status' => $invoiceRepository->lastStatusUpdate['data']['sri_status'] ?? null,
    ];
    $report['checks']['derived_totals_without_client_money_fields'] = [
        'total_matches_expected' => round((float) $allowedResponse->total, 2) === 69.00,
        'draft_subtotal' => $allowedInvoice?->subtotal()->amount(),
        'draft_tax' => $allowedInvoice?->totalTax()->amount(),
        'draft_total' => $allowedInvoice?->total()->amount(),
        'xml_built' => $xmlBuilder->lastInvoice instanceof Invoice,
        'sri_send_count' => count($sriGateway->sentDocuments),
    ];
} finally {
    $unlinkGeneratedArtifacts($invoiceRepository->lastDraftInvoice);
}

$allChecksPassed = true;
foreach ($report['checks'] as $check) {
    foreach ($check as $key => $value) {
        if (in_array($key, ['message', 'response_total', 'response_status', 'customer_identification', 'payment_method_code', 'payment_method_label', 'stored_sequential', 'sri_status', 'draft_subtotal', 'draft_tax', 'draft_total', 'draft_invoices_created', 'xml_built', 'sri_send_count'], true)) {
            continue;
        }

        if ($value !== true) {
            $allChecksPassed = false;
        }
    }
}

$report['ok'] = $allChecksPassed;
$report['finished_at'] = date('c');

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($allChecksPassed ? 0 : 1);
