<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Services;

use DOMDocument;
use DOMXPath;

class RidePdfInvoiceDataFactory
{
    public function fromDatabase(array $invoice, array $details, array $resolvedConfig): array
    {
        if ($details === []) {
            $details = $this->detailsFromRawRequest($invoice);
        }

        $rawRequest = $this->decodeJson($invoice['raw_request'] ?? null);
        $additionalInfo = is_array($rawRequest['additional_info'] ?? null) ? $rawRequest['additional_info'] : [];
        $subtotal0 = 0.0;
        $subtotalTaxed = 0.0;
        $items = [];

        foreach ($details as $detail) {
            $lineSubtotal = (float) ($detail['line_subtotal'] ?? 0);
            $taxRate = (float) ($detail['tax_rate'] ?? 0);

            if ($taxRate <= 0.0) {
                $subtotal0 += $lineSubtotal;
            } else {
                $subtotalTaxed += $lineSubtotal;
            }

            $items[] = [
                'code' => (string) ($detail['product_code'] ?? ''),
                'auxiliary_code' => (string) ($detail['auxiliary_code'] ?? ''),
                'quantity' => $this->formatNumber($detail['quantity'] ?? 0, 6),
                'description' => (string) ($detail['description'] ?? ''),
                'additional_detail' => (string) ($detail['additional_detail'] ?? ''),
                'unit_price' => $this->formatNumber($detail['unit_price'] ?? 0, 6),
                'discount' => $this->formatNumber($detail['discount'] ?? 0, 2),
                'total' => $this->formatNumber($lineSubtotal, 2),
            ];
        }

        $calculatedSubtotal = round($subtotal0 + $subtotalTaxed, 2);
        $total = (float) ($invoice['total_with_tax'] ?? 0);
        $subtotal = $calculatedSubtotal > 0
            ? $calculatedSubtotal
            : (float) ($invoice['subtotal_without_tax'] ?? 0);
        $taxTotal = (float) ($invoice['total_tax'] ?? 0);
        if ($taxTotal <= 0.0 && $total > $subtotal) {
            $taxTotal = round($total - $subtotal, 2);
        }
        $paymentMethod = (string) (($invoice['payment_method_label'] ?? '') ?: 'Sin utilizacion del sistema financiero');
        $paymentTotal = $total > 0 ? $total : ($subtotal + $taxTotal);
        $ambiente = strtolower(trim((string) ($invoice['ambiente'] ?? 'pruebas')));

        return [
            'issuer_name' => (string) ($resolvedConfig['empresa']['razon_social'] ?? ''),
            'commercial_name' => (string) ($resolvedConfig['empresa']['nombre_comercial'] ?? ''),
            'issuer_ruc' => (string) ($resolvedConfig['empresa']['ruc'] ?? ''),
            'issuer_address' => (string) (($resolvedConfig['direccion_establecimiento'] ?? '') ?: ($resolvedConfig['empresa']['direccion_matriz'] ?? '')),
            'customer_name' => (string) ($invoice['customer_name'] ?? ''),
            'customer_identification' => (string) ($invoice['customer_identification'] ?? ''),
            'customer_email' => (string) ($invoice['customer_email'] ?? ''),
            'customer_address' => (string) ($invoice['customer_address'] ?? ''),
            'formatted_sequential' => implode('-', array_filter([
                (string) ($invoice['establishment_code'] ?? ''),
                (string) ($invoice['emission_point'] ?? ''),
                (string) ($invoice['sequential'] ?? ''),
            ])),
            'sri_status' => (string) ($invoice['sri_status'] ?? ''),
            'issue_date' => (string) ($invoice['issue_date'] ?? ''),
            'accounting_date' => (string) ($additionalInfo['accounting_date'] ?? ''),
            'order_created_at' => (string) ($additionalInfo['order_created_at'] ?? ''),
            'source_reference' => (string) (($invoice['source_reference'] ?? '') ?: ($additionalInfo['order_id'] ?? '')),
            'environment' => $ambiente === 'produccion' ? 'PRODUCCION' : 'PRUEBAS',
            'access_key' => (string) ($invoice['access_key'] ?? ''),
            'subtotal' => $this->formatNumber($subtotal, 2),
            'subtotal_0' => $this->formatNumber($subtotal0, 2),
            'subtotal_15' => $this->formatNumber($subtotalTaxed, 2),
            'discount_total' => $this->formatNumber($this->sumDetails($details, 'discount'), 2),
            'iva_15' => $this->formatNumber($taxTotal, 2),
            'service_10' => '0.00',
            'tax_total' => $this->formatNumber($taxTotal, 2),
            'total' => $this->formatNumber($paymentTotal, 2),
            'payment_method' => $paymentMethod,
            'payment_total' => $this->formatNumber($paymentTotal, 2),
            'payments' => [
                [
                    'method' => $paymentMethod,
                    'total' => $this->formatNumber($paymentTotal, 2),
                ],
            ],
            'operational_error' => $additionalInfo['operational_error'] ?? false,
            'operational_error_code' => (string) ($additionalInfo['operational_error_code'] ?? ''),
            'operational_error_label' => (string) ($additionalInfo['operational_error_label'] ?? ''),
            'operational_error_reason' => (string) ($additionalInfo['operational_error_reason'] ?? ''),
            'operational_error_marked_at' => (string) ($additionalInfo['operational_error_marked_at'] ?? ''),
            'operational_error_actor' => (string) ($additionalInfo['operational_error_actor'] ?? ''),
            'items' => $items,
        ];
    }

    public function fromXmlFile(string $xmlPath): array
    {
        if (!is_file($xmlPath)) {
            throw new \RuntimeException('XML local no disponible para generar RIDE.');
        }

        $document = new DOMDocument();
        $loaded = @$document->load($xmlPath);
        if (!$loaded) {
            throw new \RuntimeException('XML local inválido para generar RIDE.');
        }

        $xpath = new DOMXPath($document);
        $summary = $this->extractSummaryData($xpath);
        $payments = $this->extractPayments($xpath);
        $items = [];

        foreach ($xpath->query('//detalles/detalle') as $detailNode) {
            $additionalDetails = [];
            foreach ($xpath->query('detallesAdicionales/detAdicional', $detailNode) as $additionalDetailNode) {
                $name = trim((string) ($additionalDetailNode->attributes?->getNamedItem('nombre')?->nodeValue ?? ''));
                $value = trim($additionalDetailNode->textContent);
                if ($name !== '' && $value !== '') {
                    $additionalDetails[] = sprintf('%s: %s', $name, $value);
                } elseif ($value !== '') {
                    $additionalDetails[] = $value;
                }
            }

            $items[] = [
                'code' => $this->queryString($xpath, 'codigoPrincipal', $detailNode),
                'auxiliary_code' => $this->queryString($xpath, 'codigoAuxiliar', $detailNode),
                'description' => $this->queryString($xpath, 'descripcion', $detailNode),
                'additional_detail' => implode(' | ', $additionalDetails),
                'quantity' => $this->queryString($xpath, 'cantidad', $detailNode),
                'unit_price' => $this->queryString($xpath, 'precioUnitario', $detailNode),
                'discount' => $this->queryString($xpath, 'descuento', $detailNode),
                'total' => $this->queryString($xpath, 'precioTotalSinImpuesto', $detailNode),
            ];
        }

        $email = '';
        foreach ($xpath->query('//infoAdicional/campoAdicional') as $extraField) {
            if ($extraField->attributes?->getNamedItem('nombre')?->nodeValue === 'Email') {
                $email = trim($extraField->textContent);
                break;
            }
        }

        $sequential = $this->queryString($xpath, '//infoTributaria/secuencial');
        $total = $this->queryString($xpath, '//infoFactura/importeTotal');

        return [
            'access_key' => $this->queryString($xpath, '//infoTributaria/claveAcceso'),
            'issuer_name' => $this->queryString($xpath, '//infoTributaria/razonSocial'),
            'commercial_name' => $this->queryString($xpath, '//infoTributaria/nombreComercial'),
            'issuer_ruc' => $this->queryString($xpath, '//infoTributaria/ruc'),
            'issuer_address' => $this->queryString($xpath, '//infoFactura/dirEstablecimiento')
                ?: $this->queryString($xpath, '//infoTributaria/dirMatriz'),
            'customer_name' => $this->queryString($xpath, '//infoFactura/razonSocialComprador'),
            'customer_identification' => $this->queryString($xpath, '//infoFactura/identificacionComprador'),
            'customer_address' => $this->queryString($xpath, '//infoFactura/direccionComprador'),
            'customer_email' => $email,
            'issue_date' => $this->queryString($xpath, '//infoFactura/fechaEmision'),
            'subtotal' => $this->queryString($xpath, '//infoFactura/totalSinImpuestos'),
            'subtotal_0' => $summary['subtotal_0'],
            'subtotal_15' => $summary['subtotal_15'],
            'discount_total' => $this->queryString($xpath, '//infoFactura/totalDescuento'),
            'iva_15' => $summary['iva_15'],
            'service_10' => $this->queryString($xpath, '//infoFactura/propina') ?: '0.00',
            'tax_total' => $this->queryString($xpath, 'sum(//infoFactura/totalConImpuestos/totalImpuesto/valor)'),
            'total' => $total,
            'payment_method' => $payments[0]['method'] ?? '',
            'payment_total' => $payments[0]['total'] ?? $total,
            'payments' => $payments,
            'formatted_sequential' => sprintf(
                '%s-%s-%s',
                $this->queryString($xpath, '//infoTributaria/estab'),
                $this->queryString($xpath, '//infoTributaria/ptoEmi'),
                $sequential
            ),
            'environment' => $this->queryString($xpath, '//infoTributaria/ambiente') === '2' ? 'PRODUCCION' : 'PRUEBAS',
            'items' => $items,
        ];
    }

    private function detailsFromRawRequest(array $invoice): array
    {
        $rawRequest = $this->decodeJson($invoice['raw_request'] ?? null);
        $rawItems = is_array($rawRequest['items'] ?? null) ? $rawRequest['items'] : [];
        $details = [];

        foreach (array_values($rawItems) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? $item['unitPrice'] ?? $item['price_net'] ?? 0);
            $discount = (float) ($item['discount'] ?? $item['discount_total'] ?? 0);
            $lineSubtotal = (float) ($item['line_subtotal_net'] ?? $item['lineSubtotal'] ?? $item['net_total'] ?? 0);
            if ($lineSubtotal <= 0.0) {
                $lineSubtotal = max(0.0, ($quantity * $unitPrice) - $discount);
            }

            $details[] = [
                'line_number' => $index + 1,
                'product_code' => $item['code'] ?? $item['product_id'] ?? null,
                'auxiliary_code' => $item['auxiliary_code'] ?? null,
                'description' => (string) ($item['description'] ?? $item['product_name'] ?? 'Producto'),
                'additional_detail' => $item['additional_detail'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'line_subtotal' => round($lineSubtotal, 6),
                'tax_amount' => round((float) ($item['tax_amount'] ?? $item['taxAmount'] ?? 0), 6),
                'tax_rate' => (float) ($item['tax_rate'] ?? $item['taxRate'] ?? 0),
            ];
        }

        return $details;
    }

    private function extractSummaryData(DOMXPath $xpath): array
    {
        $subtotal0 = 0.0;
        $subtotal15 = 0.0;
        $iva15 = 0.0;

        foreach ($xpath->query('//infoFactura/totalConImpuestos/totalImpuesto') as $taxNode) {
            $taxCode = $this->queryString($xpath, 'codigo', $taxNode);
            $percentageCode = $this->queryString($xpath, 'codigoPorcentaje', $taxNode);

            if ($taxCode === '2' && $percentageCode === '0') {
                $subtotal0 += (float) $this->queryString($xpath, 'baseImponible', $taxNode);
            }

            if ($taxCode === '2' && $percentageCode === '4') {
                $subtotal15 += (float) $this->queryString($xpath, 'baseImponible', $taxNode);
                $iva15 += (float) $this->queryString($xpath, 'valor', $taxNode);
            }
        }

        return [
            'subtotal_0' => $this->formatNumber($subtotal0, 2),
            'subtotal_15' => $this->formatNumber($subtotal15, 2),
            'iva_15' => $this->formatNumber($iva15, 2),
        ];
    }

    private function extractPayments(DOMXPath $xpath): array
    {
        $payments = [];

        foreach ($xpath->query('//infoFactura/pagos/pago') as $paymentNode) {
            $code = $this->queryString($xpath, 'formaPago', $paymentNode);
            $payments[] = [
                'code' => $code,
                'method' => $this->paymentMethodLabelFromCode($code),
                'total' => $this->queryString($xpath, 'total', $paymentNode),
            ];
        }

        return $payments;
    }

    private function paymentMethodLabelFromCode(string $code): string
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
            default => $code !== '' ? 'Forma de pago ' . $code : 'No especificado',
        };
    }

    private function queryString(DOMXPath $xpath, string $expression, ?\DOMNode $contextNode = null): string
    {
        $result = $xpath->evaluate($expression, $contextNode);

        if (is_numeric($result)) {
            return $this->formatNumber((float) $result, 2);
        }

        if ($result instanceof \DOMNodeList) {
            if ($result->length === 0) {
                return '';
            }

            return trim($result->item(0)?->textContent ?? '');
        }

        return trim((string) $result);
    }

    private function sumDetails(array $details, string $field): float
    {
        $total = 0.0;
        foreach ($details as $detail) {
            $total += (float) ($detail[$field] ?? 0);
        }

        return $total;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function formatNumber(mixed $value, int $decimals): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }
}
