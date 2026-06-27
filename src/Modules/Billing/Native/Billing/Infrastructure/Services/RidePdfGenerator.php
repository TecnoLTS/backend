<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class RidePdfGenerator
{
    private const OUTPUT_DIRECTORY = '/var/www/html/storage/billing/pdf/rides';

    public function __construct(private readonly string $logoPath = '/var/www/html/public/LogoVerde150.png') {}

    public function generate(string $accessKey, array $invoiceData, ?string $authorizationNumber, ?string $authorizationDate): string
    {
        @mkdir(self::OUTPUT_DIRECTORY, 0777, true);

        $pdfPath = sprintf('%s/%s.pdf', self::OUTPUT_DIRECTORY, $accessKey);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildHtml($invoiceData, $authorizationNumber, $authorizationDate));
        $dompdf->setPaper('A4');
        $dompdf->render();

        $written = file_put_contents($pdfPath, $dompdf->output());
        if ($written === false) {
            throw new \RuntimeException(sprintf('No se pudo escribir el RIDE PDF en %s', $pdfPath));
        }

        return $pdfPath;
    }

    private function buildHtml(array $invoiceData, ?string $authorizationNumber, ?string $authorizationDate): string
    {
        $issuerName = $this->escape($invoiceData['issuer_name'] ?? '');
        $commercialName = $this->escape($invoiceData['commercial_name'] ?? '');
        $issuerRuc = $this->escape($invoiceData['issuer_ruc'] ?? '');
        $issuerAddress = $this->escape($invoiceData['issuer_address'] ?? '');
        $customerName = $this->escape($invoiceData['customer_name'] ?? '');
        $customerIdentification = $this->escape($invoiceData['customer_identification'] ?? '');
        $customerEmail = $this->escape($invoiceData['customer_email'] ?? 'No registrado');
        $customerAddress = $this->escape($invoiceData['customer_address'] ?? 'No registrada');
        $formattedSequential = $this->escape($invoiceData['formatted_sequential'] ?? '');
        $issueDate = $this->escape($invoiceData['issue_date'] ?? '');
        $sriStatus = $this->escape((string) (($invoiceData['sri_status'] ?? '') ?: 'AUTORIZADO'));
        $accountingDate = $this->escape((string) ($invoiceData['accounting_date'] ?? ''));
        $sourceReference = $this->escape((string) ($invoiceData['source_reference'] ?? ''));
        $operationalError = filter_var($invoiceData['operational_error'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $operationalLabel = $this->escape((string) (($invoiceData['operational_error_label'] ?? '') ?: 'Emitida por error operativo'));
        $operationalReason = $this->escape((string) ($invoiceData['operational_error_reason'] ?? ''));
        $operationalMarkedAt = $this->escape((string) ($invoiceData['operational_error_marked_at'] ?? ''));
        $operationalActor = $this->escape((string) ($invoiceData['operational_error_actor'] ?? ''));
        $environment = $this->escape($invoiceData['environment'] ?? 'PRUEBAS');
        $rawAccessKey = (string) ($invoiceData['access_key'] ?? '');
        $accessKeyValue = $this->escape($rawAccessKey);
        $barcodeSvg = $this->buildBarcodeSvgDataUri($rawAccessKey);
        $subtotal = $this->escape($invoiceData['subtotal'] ?? '0.00');
        $subtotal0 = $this->escape($invoiceData['subtotal_0'] ?? '0.00');
        $subtotal15 = $this->escape($invoiceData['subtotal_15'] ?? '0.00');
        $discountTotal = $this->escape($invoiceData['discount_total'] ?? '0.00');
        $iva15 = $this->escape($invoiceData['iva_15'] ?? '0.00');
        $service10 = $this->escape($invoiceData['service_10'] ?? '0.00');
        $taxTotal = $this->escape($invoiceData['tax_total'] ?? '0.00');
        $total = $this->escape($invoiceData['total'] ?? '0.00');
        $displayCommercialName = $commercialName !== '' ? $commercialName : $issuerName;
        $resolvedAuthorizationNumber = $this->escape($authorizationNumber ?? 'PENDIENTE DE AUTORIZACION');
        $resolvedAuthorizationDate = $this->escape($authorizationDate ?? 'No registrada localmente');
        $logoBlock = $this->buildLogoBlock();
        $operationalAuditBlock = $this->buildOperationalAuditBlock(
            $operationalError,
            $sriStatus,
            $operationalLabel,
            $issueDate,
            $accountingDate,
            $taxTotal,
            $sourceReference,
            $operationalMarkedAt,
            $operationalActor,
            $operationalReason
        );
        $paymentsRows = '';

        foreach ($invoiceData['payments'] ?? [] as $payment) {
            $paymentsRows .= sprintf(
                '<tr>
                    <td>%s</td>
                    <td class="right">%s</td>
                </tr>',
                $this->escape((string) ($payment['method'] ?? 'No especificado')),
                $this->escape((string) ($payment['total'] ?? '0.00'))
            );
        }

        if ($paymentsRows === '') {
            $paymentsRows = sprintf(
                '<tr>
                    <td>%s</td>
                    <td class="right">%s</td>
                </tr>',
                $this->escape((string) ($invoiceData['payment_method'] ?? 'No especificado')),
                $this->escape((string) ($invoiceData['payment_total'] ?? $invoiceData['total'] ?? '0.00'))
            );
        }

        $itemsRows = '';
        foreach ($invoiceData['items'] ?? [] as $item) {
            $itemsRows .= sprintf(
                '<tr>
                    <td class="col-code">%s</td>
                    <td class="col-aux-code">%s</td>
                    <td class="col-qty right">%s</td>
                    <td class="col-desc">%s</td>
                    <td class="col-additional">%s</td>
                    <td class="col-unit right">%s</td>
                    <td class="col-discount right">%s</td>
                    <td class="col-total right">%s</td>
                </tr>',
                $this->escape((string) ($item['code'] ?? '')),
                $this->escape((string) ($item['auxiliary_code'] ?? '')),
                $this->escape((string) ($item['quantity'] ?? '')),
                $this->escape((string) ($item['description'] ?? '')),
                $this->escape((string) ($item['additional_detail'] ?? '')),
                $this->escape((string) ($item['unit_price'] ?? '')),
                $this->escape((string) ($item['discount'] ?? '0.00')),
                $this->escape((string) ($item['total'] ?? ''))
            );
        }

        if ($itemsRows === '') {
            $itemsRows = '<tr><td colspan="8" class="empty-row">Sin detalles</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 14px 16px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #111111;
            line-height: 1.25;
            margin: 0;
        }
        .page,
        .top-layout,
        .client-grid,
        .bottom-grid,
        .summary-table,
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        .top-layout {
            margin-bottom: 8px;
        }
        .top-layout td,
        .client-grid td,
        .bottom-grid td {
            vertical-align: top;
        }
        .left-column,
        .bottom-left {
            padding-right: 8px;
        }
        .box {
            border: 1.1px solid #1b1b1b;
            padding: 8px 10px;
            margin-bottom: 8px;
        }
        .box-cabecera{
            border: 1px solid #1b1b1b;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 8px;
        }

        .box-2 {
            padding: 8px 10px;
            margin-bottom: 7px;
        }
        .table-box,
        .summary-box {
            padding: 0;
        }
        .section-title,
        .table-title {
            font-weight: bold;
            text-transform: uppercase;
        }
        .logo-wrap {
            text-align: left;
            margin-bottom: 10px;
            min-height: 58px;
        }
        .logo {
            max-width: 210px;
            max-height: 72px;
            width: auto;
            height: auto;
        }
        .brand-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 6px;
        }
        .document-title {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 3px;
            margin-bottom: 6px;
        }
        .section-title {
            font-size: 9px;
            margin-bottom: 6px;
        }
        .table-title {
            padding: 7px 10px;
            font-size: 9px;
            background: #f3f3f3;
            border-bottom: 1.3px solid #1b1b1b;
        }
        .row {
            margin-bottom: 4px;
        }
        .label {
            font-weight: bold;
            min-width: 118px;
        }
        .datofactura {
        margin-left:3px
        }
        .compact .label {
            min-width: 144px;
        }
        .authorization-number {
            font-size: 8.4px;
            line-height: 1.2;
            word-break: break-all;
            margin: 1px 0 5px 0;
        }
        .access-key-label {
            font-size: 9px;
            font-weight: bold;
            margin: 6px 0 3px 0;
        }
        .access-key-box {
            border: 1px solid #1b1b1b;
            padding: 4px 6px 6px 6px;
            text-align: center;
        }
        .barcode-image {
            display: block;
            width: 100%;
            height: 52px;
            margin-bottom: 4px;
        }
        .access-key-value {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 7.6px;
            line-height: 1.15;
            word-break: break-all;
        }
        .items-table th,
        .items-table td,
        .summary-table td,
        .payments-table th,
        .payments-table td {
            border: 1px solid #1b1b1b;
            padding: 5px;
            font-size: 9px;
        }
        .items-table th {
            background: #f7f7f7;
            text-align: center;
            font-weight: bold;
        }
        .payments-table {
            width: 100%;
            border-collapse: collapse;
        }
        .payments-table th {
            background: #f7f7f7;
            text-align: center;
            font-weight: bold;
        }
        .summary-label {
            width: 66%;
            background: #f7f7f7;
            font-weight: bold;
        }
        .summary-total td {
            font-weight: bold;
            font-size: 10px;
        }
        .col-code { width: 12%; }
        .col-aux-code { width: 12%; }
        .col-qty { width: 8%; }
        .col-desc { width: 22%; }
        .col-additional { width: 18%; }
        .col-unit { width: 10%; }
        .col-discount { width: 8%; }
        .col-total { width: 10%; }
        .right {
            text-align: right;
        }
        .empty-row {
            text-align: center;
            padding: 10px 0;
        }
        .footer-note {
            border: 1.3px solid #1b1b1b;
            padding: 8px 10px;
            font-size: 9px;
            text-align: center;
        }
        .audit-box {
            border: 1.3px solid #8a5a00;
            background: #fff7e6;
            padding: 6px 8px;
            margin-bottom: 7px;
            font-size: 8.6px;
        }
        .audit-title {
            color: #7a3f00;
            font-weight: bold;
            margin-bottom: 4px;
            text-transform: uppercase;
        }
        .audit-reason {
            margin-top: 4px;
            line-height: 1.25;
        }
    </style>
</head>
<body>
    <div class="page">
        <table class="top-layout">
            <tr>
                <td class="left-column" width="45%">
                    <div class="box-2">
                        {$logoBlock}
                        <div class="brand-name">{$displayCommercialName}</div>
                    </div>
                    <div class="box-cabecera">
                        <div class="section-title">Datos del emisor</div>
                        <div class="row"><span class="label">RUC:</span><span class="datofactura">{$issuerRuc}</span></div>
                        <div class="row"><span class="label">Razon social:</span><span class="datofactura">{$issuerName}</span></div>
                        <div class="row"><span class="label">Direccion matriz:</span><span class="datofactura">{$issuerAddress}</span></div>
                        <div class="row"><span class="label">Obligado contabilidad:</span><span class="datofactura">NO</span></div>
                    </div>
                </td>
                <td width="55%">
                    <div class="box-cabecera">
                        <div class="document-title">FACTURA</div>
                        <div class="row compact"><span class="label">Nro:</span><span class="datofactura">{$formattedSequential}</span></div>
                        <div class="row compact"><span class="label">Estado SRI:</span><span class="datofactura">{$sriStatus}</span></div>
                        <div class="row compact"><span class="label">Numero autorización:</span></div>
                        <div class="authorization-number">{$resolvedAuthorizationNumber}</div>
                        <div class="row compact"><span class="label">Fecha aut:</span><span class="datofactura">{$resolvedAuthorizationDate}</span></div>
                        <div class="row compact"><span class="label">Ambiente:</span><span class="datofactura">{$environment}</span></div>
                        <div class="row compact"><span class="label">Emision:</span><span class="datofactura">Emision normal</span></div>
                        <div class="access-key-label">CLAVE DE ACCESO</div>
                        <div>
                            <img class="barcode-image" src="{$barcodeSvg}" alt="Codigo de barras de clave de acceso">
                            <div class="access-key-value">{$accessKeyValue}</div>
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="box">
            <div class="section-title">Datos del cliente</div>
            <table class="client-grid">
                <tr>
                    <td class="left-column" width="58%">
                        <div class="row"><span class="label">Razon social / nombres:</span><span class="datofactura">{$customerName}</span></div>
                        <div class="row"><span class="label">RUC/CI:</span><span class="datofactura">{$customerIdentification}</span></div>
                        <div class="row"><span class="label">Direccion:</span><span class="datofactura">{$customerAddress}</span></div>
                    </td>
                    <td width="42%">
                        <div class="row"><span class="label">Fecha de emision:</span><span class="datofactura">{$issueDate}</span></div>
                        <div class="row"><span class="label">Correo:</span><span class="datofactura">{$customerEmail}</span></div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="box table-box">
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Cod. principal</th>
                        <th>Cod. Auxiliar</th>
                        <th>Cantidad</th>
                        <th>Descripcion</th>
                        <th>Detalle adicional</th>
                        <th>P. unitario</th>
                        <th>Descuento</th>
                        <th>Precio total</th>
                    </tr>
                </thead>
                <tbody>{$itemsRows}</tbody>
            </table>
        </div>

        <table class="bottom-grid">
            <tr>
                <td class="bottom-left" width="58%">
                    <div class="box">
                        <div class="section-title">Informacion adicional</div>
                        <div class="row"><span class="label">Correo cliente:</span><span class="datofactura">{$customerEmail}</span></div>
                        <div class="row"><span class="label">Direccion cliente:</span><span class="datofactura">{$customerAddress}</span></div>
                    </div>
                    {$operationalAuditBlock}
                    <div class="box">
                        <table class="payments-table">
                            <tr>
                                <th>Forma de pago</th>
                                <th>Valor</th>
                            </tr>
                            {$paymentsRows}
                        </table>
                    </div>
                </td>
                <td width="42%">
                    <div class="box summary-box">
                        <table class="summary-table">
                            <tr>
                                <td class="summary-label">Subtotal 0%</td>
                                <td class="right">{$subtotal0}</td>
                            </tr>
                            <tr>
                                <td class="summary-label">Subtotal 15%</td>
                                <td class="right">{$subtotal15}</td>
                            </tr>
                            <tr>
                                <td class="summary-label">Subtotal sin impuestos</td>
                                <td class="right">{$subtotal}</td>
                            </tr>
                            <tr>
                                <td class="summary-label">Descuento</td>
                                <td class="right">{$discountTotal}</td>
                            </tr>
                            <tr>
                                <td class="summary-label">IVA 15%</td>
                                <td class="right">{$iva15}</td>
                            </tr>
                            <tr>
                                <td class="summary-label">Servicio 10%</td>
                                <td class="right">{$service10}</td>
                            </tr>
                            <tr class="summary-total">
                                <td class="summary-label">Valor total</td>
                                <td class="right">{$total}</td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <div class="footer-note">
            Este documento es una representacion impresa de la factura electronica autorizada por el Servicio de Rentas Internas (SRI). Para verificar su autenticidad, utilice la clave de acceso en el portal del SRI.
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function buildOperationalAuditBlock(
        bool $enabled,
        string $sriStatus,
        string $label,
        string $issueDate,
        string $accountingDate,
        string $taxTotal,
        string $sourceReference,
        string $markedAt,
        string $actor,
        string $reason
    ): string {
        if (!$enabled) {
            return '';
        }

        $rows = [
            ['Estado SRI', $sriStatus],
            ['Etiqueta local', $label],
            ['Fecha SRI', $issueDate],
            ['Fecha de venta', $accountingDate],
            ['IVA local registrado', $taxTotal],
            ['Orden origen', $sourceReference],
            ['Marcada en auditoria', $markedAt],
            ['Responsable', $actor],
        ];

        $htmlRows = '';
        foreach ($rows as [$rowLabel, $value]) {
            if (trim($value) === '') {
                continue;
            }

            $htmlRows .= sprintf(
                '<div class="row"><span class="label">%s:</span><span class="datofactura">%s</span></div>',
                $rowLabel,
                $value
            );
        }

        $reasonBlock = trim($reason) !== ''
            ? sprintf('<div class="audit-reason"><span class="label">Motivo:</span><span class="datofactura">%s</span></div>', $reason)
            : '';

        return <<<HTML
                    <div class="audit-box">
                        <div class="audit-title">Emitida por error operativo</div>
                        {$htmlRows}
                        {$reasonBlock}
                    </div>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function buildLogoBlock(): string
    {
        $logoDataUri = $this->getLogoDataUri();

        if ($logoDataUri === null) {
            return '';
        }

        return sprintf('<div class="logo-wrap"><img class="logo" src="%s" alt="Logo empresa"></div>', $logoDataUri);
    }

    private function getLogoDataUri(): ?string
    {
        $logoPath = $this->resolveLogoPath();
        if ($logoPath === null) {
            return null;
        }

        $contents = file_get_contents($logoPath);
        if ($contents === false) {
            return null;
        }

        return sprintf('data:%s;base64,%s', $this->logoMimeType($logoPath), base64_encode($contents));
    }

    private function resolveLogoPath(): ?string
    {
        $configuredPath = trim($this->logoPath);
        $candidates = [];

        if ($configuredPath !== '') {
            $candidates[] = $configuredPath;

            if (str_starts_with($configuredPath, '/app/public/')) {
                $relativePath = ltrim(substr($configuredPath, strlen('/app/public/')), '/');
                if ($relativePath !== '') {
                    $candidates[] = '/var/www/html/public/' . $relativePath;
                    $candidates[] = '/var/www/html/public/images/brand/' . basename($relativePath);
                }
            }
        }

        $candidates[] = '/var/www/html/public/LogoVerde150.png';
        $candidates[] = '/var/www/html/public/images/brand/LogoVerde150.png';

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function logoMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function buildBarcodeSvgDataUri(string $accessKey): string
    {
        if ($accessKey === '') {
            return '';
        }

        $patterns = [
            '0' => 'nnnwwnwnn',
            '1' => 'wnnwnnnnw',
            '2' => 'nnwwnnnnw',
            '3' => 'wnwwnnnnn',
            '4' => 'nnnwwnnnw',
            '5' => 'wnnwwnnnn',
            '6' => 'nnwwwnnnn',
            '7' => 'nnnwnnwnw',
            '8' => 'wnnwnnwnn',
            '9' => 'nnwwnnwnn',
            '*' => 'nwnnwnwnn',
        ];

        $sequence = '*' . preg_replace('/[^0-9]/', '', $accessKey) . '*';
        $narrow = 1.3;
        $wide = 3.1;
        $gap = 1.3;
        $barHeight = 42;
        $x = 0.0;
        $rects = [];

        foreach (str_split($sequence) as $character) {
            $pattern = $patterns[$character] ?? null;
            if ($pattern === null) {
                continue;
            }

            foreach (str_split($pattern) as $index => $widthType) {
                $elementWidth = $widthType === 'w' ? $wide : $narrow;
                if ($index % 2 === 0) {
                    $rects[] = sprintf(
                        '<rect x="%.2F" y="0" width="%.2F" height="%d" fill="#111111" />',
                        $x,
                        $elementWidth,
                        $barHeight
                    );
                }

                $x += $elementWidth;
            }

            $x += $gap;
        }

        $svgWidth = max(220, (int) ceil($x));
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" preserveAspectRatio="none">%s</svg>',
            $svgWidth,
            $barHeight,
            $svgWidth,
            $barHeight,
            implode('', $rects)
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
