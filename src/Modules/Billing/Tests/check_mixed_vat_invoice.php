<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use App\Shared\Tax\EcuadorSriVatCatalog;
use BillingService\Billing\Application\UseCases\EmitInvoice;
use BillingService\Billing\Domain\Entities\Invoice;
use BillingService\Billing\Domain\ValueObjects\AccessKey;
use BillingService\Billing\Domain\ValueObjects\Environment;
use BillingService\Billing\Infrastructure\Services\AuthorizedInvoiceMailer;
use BillingService\Billing\Infrastructure\Services\RidePdfInvoiceDataFactory;
use BillingService\Billing\Infrastructure\Services\XmlInvoiceBuilder;
use BillingService\Shared\Domain\ValueObjects\Identification;
use BillingService\Shared\Domain\ValueObjects\Ruc;

$checks = [];
$failures = [];
$check = static function (string $name, bool $passed) use (&$checks, &$failures): void {
    $checks[$name] = $passed;
    if (!$passed) {
        $failures[] = $name;
    }
};

$expectedCodes = [0 => '0', 5 => '5', 12 => '2', 13 => '10', 14 => '3', 15 => '4'];
foreach ($expectedCodes as $rate => $code) {
    $treatment = $rate === 0
        ? EcuadorSriVatCatalog::TREATMENT_ZERO_RATED
        : EcuadorSriVatCatalog::TREATMENT_TAXED;
    $check(
        "catalog maps IVA {$rate}% to SRI {$code}",
        EcuadorSriVatCatalog::percentageCode($rate, $treatment) === $code
    );
}
$check(
    'catalog preserves exempt IVA as SRI code 7',
    EcuadorSriVatCatalog::percentageCode(0, EcuadorSriVatCatalog::TREATMENT_EXEMPT) === '7'
);

$emitReflection = new ReflectionClass(EmitInvoice::class);
$emit = $emitReflection->newInstanceWithoutConstructor();
$mapItems = $emitReflection->getMethod('mapItems');
$calculateTaxes = $emitReflection->getMethod('calculateTaxes');

$requestItems = [
    [
        'code' => 'IVA15',
        'description' => 'Producto gravado IVA 15%',
        'quantity' => 1,
        'unit_price' => 100,
        'discount' => 0,
        'line_subtotal_net' => 100,
        'tax_rate' => 15,
        'tax_code' => '2',
        'tax_percentage_code' => '4',
        'tax_treatment' => 'taxed',
        'tax_amount' => 15,
    ],
    [
        'code' => 'IVA0',
        'description' => 'Producto tarifa IVA 0%',
        'quantity' => 1,
        'unit_price' => 50,
        'discount' => 0,
        'line_subtotal_net' => 50,
        'tax_rate' => 0,
        'tax_code' => '2',
        'tax_percentage_code' => '0',
        'tax_treatment' => 'zero-rated',
        'tax_amount' => 0,
    ],
    [
        'code' => 'EXENTO',
        'description' => 'Producto exento de IVA',
        'quantity' => 1,
        'unit_price' => 25,
        'discount' => 0,
        'line_subtotal_net' => 25,
        'tax_rate' => 0,
        'tax_code' => '2',
        'tax_percentage_code' => '7',
        'tax_treatment' => 'exempt',
        'tax_amount' => 0,
    ],
];

/** @var list<array<string, mixed>> $mappedItems */
$mappedItems = $mapItems->invoke($emit, $requestItems);
/** @var list<array<string, mixed>> $taxes */
$taxes = $calculateTaxes->invoke($emit, $mappedItems);
$check(
    'application mapping preserves all three tax treatments',
    array_column($mappedItems, 'taxTreatment') === ['taxed', 'zero-rated', 'exempt']
);
$check(
    'application totals keep SRI groups 4, 0 and 7 separate',
    array_column($taxes, 'codePercentage') === ['4', '0', '7']
);

$invoice = new Invoice(
    accessKey: AccessKey::create(
        new DateTimeImmutable('2026-07-16'),
        '01',
        '1759687682001',
        '1',
        '001001',
        '000000001',
        '12345678',
        '1'
    ),
    issuerRuc: new Ruc('1759687682001'),
    issuerBusinessName: 'Paramascotas QA',
    customerIdentification: new Identification('1702527887'),
    customerName: 'Cliente Fiscal Mixto',
    customerAddress: 'Quito',
    customerEmail: 'fiscal-mixto@example.test',
    issueDate: new DateTimeImmutable('2026-07-16'),
    environment: new Environment(Environment::PRUEBAS),
    establishment: '001',
    emissionPoint: '001',
    sequential: '000000001',
    items: $mappedItems,
    taxes: $taxes
);

$xmlBuilder = new XmlInvoiceBuilder([
    'empresa' => [
        'razon_social' => 'Paramascotas QA',
        'nombre_comercial' => 'Paramascotas',
        'direccion_matriz' => 'Quito',
    ],
    'direccion_establecimiento' => 'Quito',
]);
$xml = $xmlBuilder->buildInvoiceXml($invoice);
$document = new DOMDocument();
$check('mixed invoice XML is well formed', @$document->loadXML($xml));
$xpath = new DOMXPath($document);
$detailCodes = [];
foreach ($xpath->query('//detalles/detalle/impuestos/impuesto/codigoPorcentaje') ?: [] as $node) {
    $detailCodes[] = trim($node->textContent);
}
$summaryCodes = [];
foreach ($xpath->query('//infoFactura/totalConImpuestos/totalImpuesto/codigoPorcentaje') ?: [] as $node) {
    $summaryCodes[] = trim($node->textContent);
}
$check('XML detail lines preserve SRI codes 4, 0 and 7', $detailCodes === ['4', '0', '7']);
$check('XML totals preserve SRI codes 4, 0 and 7', $summaryCodes === ['4', '0', '7']);
$check('mixed invoice total is 190.00', abs($invoice->total()->amount() - 190.0) < 0.001);

$xmlPath = tempnam(sys_get_temp_dir(), 'billing-mixed-vat-');
if (!is_string($xmlPath)) {
    throw new RuntimeException('Could not allocate mixed VAT XML fixture.');
}
file_put_contents($xmlPath, $xml);

try {
    $rideData = (new RidePdfInvoiceDataFactory())->fromXmlFile($xmlPath);
    $rideTreatments = array_column($rideData['tax_summary'] ?? [], 'treatment');
    $check('RIDE projection preserves taxed, zero-rated and exempt groups', $rideTreatments === ['zero-rated', 'exempt', 'taxed']);
    $check('RIDE projects zero-rated base independently', ($rideData['subtotal_0'] ?? null) === '50.00');
    $check('RIDE projects exempt base independently', ($rideData['subtotal_exempt'] ?? null) === '25.00');
    $check('RIDE projects mixed IVA total', ($rideData['tax_total'] ?? null) === '15.00');

    $mailerReflection = new ReflectionClass(AuthorizedInvoiceMailer::class);
    $mailer = $mailerReflection->newInstanceWithoutConstructor();
    $extractInvoiceData = $mailerReflection->getMethod('extractInvoiceData');
    /** @var array<string, mixed> $mailData */
    $mailData = $extractInvoiceData->invoke($mailer, $xmlPath);
    $mailTreatments = array_column($mailData['tax_summary'] ?? [], 'treatment');
    $check('mail projection preserves the same three tax groups', $mailTreatments === $rideTreatments);
    $mailSummary = $mailerReflection->getMethod('buildTaxSummaryMailHtml')->invoke($mailer, $mailData);
    $check(
        'mail body exposes zero-rated and exempt bases without collapsing them',
        is_string($mailSummary)
            && str_contains($mailSummary, 'IVA 0%')
            && str_contains($mailSummary, 'Exento de IVA')
            && str_contains($mailSummary, 'IVA 15%')
    );
} finally {
    @unlink($xmlPath);
}

foreach ([
    'mismatched SRI percentage code is rejected' => [
        ...$requestItems[0],
        'tax_percentage_code' => '2',
    ],
    'unsupported IVA rate is rejected' => [
        ...$requestItems[0],
        'tax_rate' => 10,
        'tax_percentage_code' => '',
        'tax_amount' => 10,
    ],
    'incoherent IVA amount is rejected' => [
        ...$requestItems[0],
        'tax_amount' => 14,
    ],
] as $name => $invalidItem) {
    $rejected = false;
    try {
        $mapItems->invoke($emit, [$invalidItem]);
    } catch (Throwable) {
        $rejected = true;
    }
    $check($name, $rejected);
}

$repositorySource = file_get_contents(
    dirname(__DIR__) . '/Native/Billing/Infrastructure/Persistence/InvoiceRepository.php'
);
$migrationSource = file_get_contents(
    dirname(__DIR__) . '/Native/Billing/Infrastructure/Persistence/Migrations/003_add_invoice_detail_tax_identity.sql'
);
$check(
    'repository persists exact line tax identity',
    is_string($repositorySource)
        && str_contains($repositorySource, 'tax_percentage_code')
        && str_contains($repositorySource, 'tax_treatment')
);
$check(
    'database migration enforces exact SRI VAT identity',
    is_string($migrationSource)
        && str_contains($migrationSource, 'invoice_details_sri_vat_identity_check')
        && str_contains($migrationSource, "tax_percentage_code = '7'")
);

if ($failures !== []) {
    fwrite(STDERR, "Mixed Billing VAT invoice contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo 'Mixed Billing VAT invoice: OK (' . count($checks) . " assertions)\n";
