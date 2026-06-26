<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Services;

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
                $totalImpuesto = $doc->createElement('totalImpuesto');
                $this->addNodes($doc, $totalImpuesto, [
                    'codigo' => (string) ($tax['code'] ?? '2'),
                    'codigoPorcentaje' => (string) ($tax['codePercentage'] ?? '4'),
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
                $taxRate = (float) ($item['taxRate'] ?? 15);
                $taxAmount = (float) ($item['taxAmount'] ?? ($lineSubtotal * ($taxRate / 100)));
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
                    'codigo' => (string) ($item['taxCode'] ?? '2'),
                    'codigoPorcentaje' => (string) ($item['taxPercentageCode'] ?? '4'),
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
