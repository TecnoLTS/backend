<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Services;

use App\Shared\Tax\EcuadorSriVatCatalog;
use BillingService\Billing\Application\Ports\XmlBuilderInterface;
use BillingService\Billing\Domain\Entities\Invoice;
use BillingService\Billing\Domain\Exceptions\SriException;
use DOMDocument;
use DOMElement;

class XmlInvoiceBuilder implements XmlBuilderInterface
{
    private const SRI_VERSION = '1.1.0';

    public function __construct(private readonly array $config) {}

    public function buildInvoiceXml(Invoice $invoice): string
    {
        try {
            $this->assertTaxTotalsMatchDetails($invoice);
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->formatOutput = false;
            $doc->preserveWhiteSpace = false;
            $discountTotal = 0.0;
            foreach ($invoice->items() as $item) {
                $discountTotal += (float) ($item['discount'] ?? 0);
            }

            $factura = $doc->createElement('factura');
            $factura->setAttribute('id', 'comprobante');
            $factura->setAttribute('version', self::SRI_VERSION);
            $doc->appendChild($factura);

            // 1. infoTributaria
            $infoTrib = $doc->createElement('infoTributaria');
            $this->addNodes($doc, $infoTrib, [
                'ambiente' => $invoice->environment()->value(),
                'tipoEmision' => '1',
                'razonSocial' => $this->config['empresa']['razon_social'],
                'nombreComercial' => $this->config['empresa']['nombre_comercial'],
                'ruc' => $invoice->issuerRuc()->value(),
                'claveAcceso' => $invoice->accessKey()->value(),
                'codDoc' => '01',
                'estab' => $invoice->establishment(),
                'ptoEmi' => $invoice->emissionPoint(),
                'secuencial' => str_pad((string)$invoice->sequential(), 9, '0', STR_PAD_LEFT),
                'dirMatriz' => $this->config['empresa']['direccion_matriz'],
            ]);
            $factura->appendChild($infoTrib);

            // 2. infoFactura
            $infoFact = $doc->createElement('infoFactura');
            $this->addNodes($doc, $infoFact, [
                'fechaEmision' => $invoice->issueDate()->format('d/m/Y'),
                'dirEstablecimiento' => $this->config['direccion_establecimiento'] ?? $this->config['empresa']['direccion_matriz'],
                'obligadoContabilidad' => 'NO',
                'tipoIdentificacionComprador' => $invoice->customerIdentification()->getTipoIdentificacion(),
                'razonSocialComprador' => $invoice->customerName(),
                'identificacionComprador' => $invoice->customerIdentification()->value(),
                'direccionComprador' => $invoice->customerAddress() ?: 'N/A',
                'totalSinImpuestos' => $this->formatMoney($invoice->subtotal()->amount()),
                'totalDescuento' => $this->formatMoney($discountTotal),
            ]);

            $totalConImpuestos = $doc->createElement('totalConImpuestos');
            foreach ($invoice->taxes() as $tax) {
                $taxProfile = $this->headerTaxProfile($tax);
                $totalImpuesto = $doc->createElement('totalImpuesto');
                $this->addNodes($doc, $totalImpuesto, [
                    'codigo' => $taxProfile['tax_code'],
                    'codigoPorcentaje' => $taxProfile['percentage_code'],
                    'baseImponible' => $this->formatMoney($tax['baseAmount'] ?? 0),
                    'valor' => $this->formatMoney($tax['amount'] ?? 0),
                ]);
                $totalConImpuestos->appendChild($totalImpuesto);
            }
            $infoFact->appendChild($totalConImpuestos);

            $this->addNodes($doc, $infoFact, [
                'propina' => '0.00',
                'importeTotal' => $this->formatMoney($invoice->total()->amount()),
                'moneda' => 'DOLAR',
            ]);

            $pagos = $doc->createElement('pagos');
            $pago = $doc->createElement('pago');
            $this->addNodes($doc, $pago, [
                'formaPago' => $invoice->paymentMethodCode(),
                'total' => $this->formatMoney($invoice->total()->amount()),
            ]);
            $pagos->appendChild($pago);
            $infoFact->appendChild($pagos);
            $factura->appendChild($infoFact);

            // 3. detalles
            $detalles = $doc->createElement('detalles');
            foreach ($invoice->items() as $item) {
                $lineSubtotal = (float) ($item['lineSubtotal'] ?? (((float) ($item['quantity'] ?? 0) * (float) ($item['unitPrice'] ?? 0)) - (float) ($item['discount'] ?? 0)));
                $taxProfile = $this->lineTaxProfile($item);
                $taxRate = $taxProfile['rate'];
                $taxAmount = $taxProfile['amount'];
                $detalle = $doc->createElement('detalle');
                $this->addNodes($doc, $detalle, [
                    'codigoPrincipal' => $item['code'],
                    'descripcion' => $item['description'],
                    'cantidad' => $this->formatQuantity($item['quantity'] ?? 0),
                    'precioUnitario' => $this->formatUnitPrice($item['unitPrice'] ?? 0),
                    'descuento' => $this->formatMoney($item['discount'] ?? 0),
                    'precioTotalSinImpuesto' => $this->formatMoney($lineSubtotal),
                ]);

                $impuestos = $doc->createElement('impuestos');
                $impuesto = $doc->createElement('impuesto');
                $this->addNodes($doc, $impuesto, [
                    'codigo' => $taxProfile['tax_code'],
                    'codigoPorcentaje' => $taxProfile['percentage_code'],
                    'tarifa' => $this->formatMoney($taxRate),
                    'baseImponible' => $this->formatMoney($lineSubtotal),
                    'valor' => $this->formatMoney($taxAmount),
                ]);
                $impuestos->appendChild($impuesto);
                $detalle->appendChild($impuestos);
                $detalles->appendChild($detalle);
            }
            $factura->appendChild($detalles);

            if ($invoice->customerEmail() !== '') {
                $infoAdicional = $doc->createElement('infoAdicional');
                $campoAdicional = $doc->createElement('campoAdicional', htmlspecialchars($invoice->customerEmail()));
                $campoAdicional->setAttribute('nombre', 'Email');
                $infoAdicional->appendChild($campoAdicional);

                $paymentField = $doc->createElement('campoAdicional', htmlspecialchars($invoice->paymentMethodLabel()));
                $paymentField->setAttribute('nombre', 'FormaPago');
                $infoAdicional->appendChild($paymentField);

                $factura->appendChild($infoAdicional);
            } else {
                $infoAdicional = $doc->createElement('infoAdicional');
                $paymentField = $doc->createElement('campoAdicional', htmlspecialchars($invoice->paymentMethodLabel()));
                $paymentField->setAttribute('nombre', 'FormaPago');
                $infoAdicional->appendChild($paymentField);

                $factura->appendChild($infoAdicional);
            }

            return $doc->saveXML();
        } catch (\Exception $e) {
            throw SriException::invalidXml($e->getMessage());
        }
    }

    private function addNodes(DOMDocument $doc, DOMElement $parent, array $data): void
    {
        foreach ($data as $name => $value) {
            $parent->appendChild($doc->createElement($name, htmlspecialchars((string)$value)));
        }
    }

    /** @return array{tax_code: string, percentage_code: string} */
    private function headerTaxProfile(array $tax): array
    {
        $taxCode = trim((string)($tax['code'] ?? ''));
        $percentageCode = trim((string)($tax['codePercentage'] ?? ''));
        if ($taxCode !== EcuadorSriVatCatalog::TAX_CODE || $percentageCode === '') {
            throw new \InvalidArgumentException('Invoice tax totals require an explicit Ecuador IVA code and percentage code.');
        }
        EcuadorSriVatCatalog::rateForPercentageCode($percentageCode);
        foreach (['baseAmount', 'amount'] as $required) {
            if (!array_key_exists($required, $tax) || !is_numeric($tax[$required]) || (float)$tax[$required] < 0.0) {
                throw new \InvalidArgumentException(sprintf(
                    'Invoice tax total field %s must be explicit and non-negative.',
                    $required
                ));
            }
        }

        return [
            'tax_code' => $taxCode,
            'percentage_code' => $percentageCode,
        ];
    }

    /** @return array{tax_code: string, percentage_code: string, treatment: string, rate: float, amount: float} */
    private function lineTaxProfile(array $item): array
    {
        foreach (['taxRate', 'taxCode', 'taxPercentageCode', 'taxTreatment', 'taxAmount'] as $required) {
            if (!array_key_exists($required, $item)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invoice line is missing required fiscal identity field %s.',
                    $required
                ));
            }
        }

        $taxCode = trim((string)$item['taxCode']);
        if ($taxCode !== EcuadorSriVatCatalog::TAX_CODE) {
            throw new \InvalidArgumentException('Invoice line taxCode must be Ecuador IVA code 2.');
        }
        $rate = EcuadorSriVatCatalog::assertSupportedRate($item['taxRate']);
        $treatment = EcuadorSriVatCatalog::normalizeTreatment($item['taxTreatment']);
        if ($treatment === null) {
            throw new \InvalidArgumentException('Invoice line taxTreatment is required.');
        }
        $percentageCode = EcuadorSriVatCatalog::assertCodeMatches(
            $rate,
            $treatment,
            $item['taxPercentageCode']
        );
        if (!is_numeric($item['taxAmount'])) {
            throw new \InvalidArgumentException('Invoice line taxAmount must be numeric.');
        }
        $quantity = max(0.0, (float)($item['quantity'] ?? 0));
        $unitPrice = max(0.0, (float)($item['unitPrice'] ?? 0));
        $discount = max(0.0, (float)($item['discount'] ?? 0));
        $lineSubtotal = (float)($item['lineSubtotal'] ?? (($quantity * $unitPrice) - $discount));
        $amount = round(max(0.0, (float)$item['taxAmount']), 2);
        $expectedAmount = round(max(0.0, $lineSubtotal) * ($rate / 100), 2);
        if (abs($amount - $expectedAmount) > 0.005) {
            throw new \InvalidArgumentException(sprintf(
                'Invoice line IVA amount %.2f does not match its base/rate; expected %.2f.',
                $amount,
                $expectedAmount
            ));
        }

        return [
            'tax_code' => $taxCode,
            'percentage_code' => $percentageCode,
            'treatment' => $treatment,
            'rate' => $rate,
            'amount' => $amount,
        ];
    }

    private function assertTaxTotalsMatchDetails(Invoice $invoice): void
    {
        $detailGroups = [];
        foreach ($invoice->items() as $item) {
            $profile = $this->lineTaxProfile($item);
            $key = $profile['tax_code'] . ':' . $profile['percentage_code'];
            $lineSubtotal = (float)($item['lineSubtotal'] ?? (
                ((float)($item['quantity'] ?? 0) * (float)($item['unitPrice'] ?? 0))
                - (float)($item['discount'] ?? 0)
            ));
            $detailGroups[$key]['base'] = ($detailGroups[$key]['base'] ?? 0.0) + $lineSubtotal;
            $detailGroups[$key]['amount'] = ($detailGroups[$key]['amount'] ?? 0.0) + $profile['amount'];
        }

        $totalGroups = [];
        foreach ($invoice->taxes() as $tax) {
            $profile = $this->headerTaxProfile($tax);
            $key = $profile['tax_code'] . ':' . $profile['percentage_code'];
            $totalGroups[$key]['base'] = ($totalGroups[$key]['base'] ?? 0.0) + (float)$tax['baseAmount'];
            $totalGroups[$key]['amount'] = ($totalGroups[$key]['amount'] ?? 0.0) + (float)$tax['amount'];
        }

        ksort($detailGroups);
        ksort($totalGroups);
        if (array_keys($detailGroups) !== array_keys($totalGroups)) {
            throw new \InvalidArgumentException('Invoice header and detail SRI tax groups do not match.');
        }
        foreach ($detailGroups as $key => $detailGroup) {
            $totalGroup = $totalGroups[$key];
            if (abs((float)$detailGroup['base'] - (float)$totalGroup['base']) > 0.005
                || abs((float)$detailGroup['amount'] - (float)$totalGroup['amount']) > 0.005) {
                throw new \InvalidArgumentException(sprintf(
                    'Invoice SRI tax totals do not match details for group %s.',
                    $key
                ));
            }
        }
    }

    private function formatQuantity(mixed $value): string
    {
        return $this->formatDecimal($value, 6);
    }

    private function formatUnitPrice(mixed $value): string
    {
        return $this->formatDecimal($value, 6);
    }

    private function formatMoney(mixed $value): string
    {
        return $this->formatDecimal($value, 2);
    }

    private function formatDecimal(mixed $value, int $decimals): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        if (abs($number) < 0.0000005) {
            $number = 0.0;
        }

        return number_format($number, $decimals, '.', '');
    }
}
