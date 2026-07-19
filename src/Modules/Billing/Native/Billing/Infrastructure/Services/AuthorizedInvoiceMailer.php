<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Services;

use App\Infrastructure\Storage\Billing\BillingArtifactStorage;
use App\Shared\Tax\EcuadorSriVatSummary;
use BillingService\Billing\Infrastructure\Persistence\InvoiceRepository;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;

class AuthorizedInvoiceMailer
{
    public function __construct(
        private readonly RidePdfGenerator $ridePdfGenerator,
        private readonly LoggerInterface $logger,
        private readonly array $mailConfig,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly array $clientContext
    ) {}

    public function sendAuthorizedDocuments(string $accessKey, ?string $authorizationNumber, DateTimeImmutable|string|null $authorizationDate): void
    {
        if (!($this->mailConfig['enabled'] ?? false)) {
            return;
        }

        if ($this->invoiceRepository->hasMailBeenSent($accessKey, $this->clientContext)) {
            return;
        }

        try {
            $result = $this->deliverDocuments(
                accessKey: $accessKey,
                authorizationNumber: $authorizationNumber,
                authorizationDate: $authorizationDate,
                requireAuthorizedXml: true,
                isTest: false
            );

            $this->invoiceRepository->markMailAsSent($accessKey, $this->clientContext);
            $this->logger->info(sprintf('[Mail] RIDE enviado a %s', $result['recipient']));
        } catch (MailException $exception) {
            $this->logger->error('[Mail] Error enviando correo de factura', [
                'access_key' => $accessKey,
                'error_type' => $exception::class,
                'error_code' => (int)$exception->getCode(),
            ]);
        } catch (\RuntimeException $exception) {
            $this->logger->warning('[Mail] No fue posible enviar correo de factura', [
                'access_key' => $accessKey,
                'error_type' => $exception::class,
                'error_code' => (int)$exception->getCode(),
            ]);
        }
    }

    public function sendTestDocuments(string $accessKey): array
    {
        $result = $this->deliverDocuments(
            accessKey: $accessKey,
            authorizationNumber: 'PRUEBA MANUAL',
            authorizationDate: new DateTimeImmutable(),
            requireAuthorizedXml: false,
            isTest: true
        );

        $this->logger->info('[MailTest] Correo de prueba enviado', [
            'access_key' => $accessKey,
            'recipient' => $result['recipient'],
            'authorized_xml_exists' => $result['authorized_xml_exists'],
        ]);

        return $result;
    }

    private function extractInvoiceData(string $signedXmlPath): array
    {
        $document = new DOMDocument();
        $document->load($signedXmlPath);

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
                    continue;
                }

                if ($value !== '') {
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

        return [
            'access_key' => $this->queryString($xpath, '//infoTributaria/claveAcceso'),
            'issuer_name' => $this->queryString($xpath, '//infoTributaria/razonSocial'),
            'commercial_name' => $this->queryString($xpath, '//infoTributaria/nombreComercial'),
            'issuer_ruc' => $this->queryString($xpath, '//infoTributaria/ruc'),
            'issuer_address' => $this->queryString($xpath, '//infoTributaria/dirMatriz'),
            'customer_name' => $this->queryString($xpath, '//infoFactura/razonSocialComprador'),
            'customer_identification' => $this->queryString($xpath, '//infoFactura/identificacionComprador'),
            'customer_address' => $this->queryString($xpath, '//infoFactura/direccionComprador'),
            'customer_email' => $email,
            'issue_date' => $this->queryString($xpath, '//infoFactura/fechaEmision'),
            'subtotal' => $this->queryString($xpath, '//infoFactura/totalSinImpuestos'),
            'subtotal_0' => $summary['subtotal_0'],
            'subtotal_exempt' => $summary['subtotal_exempt'],
            'subtotal_taxed' => $summary['subtotal_taxed'],
            'subtotal_15' => $summary['subtotal_15'],
            'discount_total' => $this->queryString($xpath, '//infoFactura/totalDescuento'),
            'iva_15' => $summary['iva_15'],
            'tax_summary' => $summary['tax_summary'],
            'service_10' => $this->queryString($xpath, '//infoFactura/propina'),
            'tax_total' => $summary['tax_total'],
            'total' => $this->queryString($xpath, '//infoFactura/importeTotal'),
            'payments' => $payments,
            'payment_method' => $payments[0]['method'] ?? '',
            'payment_total' => $payments[0]['total'] ?? $this->queryString($xpath, '//infoFactura/importeTotal'),
            'establishment' => $this->queryString($xpath, '//infoTributaria/estab'),
            'emission_point' => $this->queryString($xpath, '//infoTributaria/ptoEmi'),
            'sequential' => $sequential,
            'formatted_sequential' => sprintf(
                '%s-%s-%s',
                $this->queryString($xpath, '//infoTributaria/estab'),
                $this->queryString($xpath, '//infoTributaria/ptoEmi'),
                $sequential
            ),
            'environment' => $this->queryString($xpath, '//infoTributaria/ambiente') === '2' ? 'PRODUCCIÓN' : 'PRUEBAS',
            'items' => $items,
        ];
    }

    private function extractSummaryData(DOMXPath $xpath): array
    {
        $entries = [];

        foreach ($xpath->query('//infoFactura/totalConImpuestos/totalImpuesto') as $taxNode) {
            $entries[] = [
                'tax_code' => $this->queryString($xpath, 'codigo', $taxNode),
                'percentage_code' => $this->queryString($xpath, 'codigoPorcentaje', $taxNode),
                'base' => $this->queryString($xpath, 'baseImponible', $taxNode),
                'amount' => $this->queryString($xpath, 'valor', $taxNode),
            ];
        }

        $summary = EcuadorSriVatSummary::summarize($entries);

        return [
            'subtotal_0' => number_format($summary['subtotal_zero_rated'], 2, '.', ''),
            'subtotal_exempt' => number_format($summary['subtotal_exempt'], 2, '.', ''),
            'subtotal_taxed' => number_format($summary['subtotal_taxed'], 2, '.', ''),
            'subtotal_15' => number_format($summary['subtotal_15'], 2, '.', ''),
            'iva_15' => number_format($summary['iva_15'], 2, '.', ''),
            'tax_total' => number_format($summary['tax_total'], 2, '.', ''),
            'tax_summary' => array_map(static fn(array $group): array => [
                ...$group,
                'rate' => $group['rate'] === null ? null : number_format($group['rate'], 2, '.', ''),
                'base' => number_format($group['base'], 2, '.', ''),
                'amount' => number_format($group['amount'], 2, '.', ''),
            ], $summary['groups']),
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
            return number_format((float) $result, 2, '.', '');
        }

        if ($result instanceof \DOMNodeList) {
            if ($result->length === 0) {
                return '';
            }

            return trim($result->item(0)?->textContent ?? '');
        }

        return trim((string) $result);
    }

    private function buildMailBody(array $invoiceData, ?string $authorizationNumber, ?string $authorizationDate): string
    {
        return sprintf(
            '<p>Estimado(a) %s,</p>
            <p>Su factura electrónica <strong>%s</strong> ha sido autorizada por el SRI.</p>
            <p><strong>Número de autorización:</strong> %s<br>
            <strong>Fecha de autorización:</strong> %s<br>
            <strong>Total:</strong> %s USD</p>
            <p><strong>Resumen tributario:</strong><br>%s</p>
            <p>Adjuntamos el RIDE en PDF y el XML firmado.</p>
            <p>Este correo fue generado automáticamente.</p>',
            htmlspecialchars($invoiceData['customer_name'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($invoiceData['formatted_sequential'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($authorizationNumber ?? 'PENDIENTE', ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($authorizationDate ?? 'PENDIENTE', ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($invoiceData['total'], ENT_QUOTES, 'UTF-8'),
            $this->buildTaxSummaryMailHtml($invoiceData)
        );
    }

    private function buildTestMailBody(array $invoiceData, bool $authorizedXmlExists): string
    {
        return sprintf(
            '<p>Estimado(a) %s,</p>
            <p>Este es un <strong>correo de prueba</strong> para validar la generación del RIDE en PDF y el adjunto del XML firmado.</p>
            <p><strong>Factura:</strong> %s<br>
            <strong>Total:</strong> %s USD<br>
            <strong>XML autorizado disponible:</strong> %s</p>
            <p><strong>Resumen tributario:</strong><br>%s</p>
            <p>Se adjunta el RIDE generado con la información actual y el XML firmado disponible en la aplicación.</p>',
            htmlspecialchars($invoiceData['customer_name'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($invoiceData['formatted_sequential'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($invoiceData['total'], ENT_QUOTES, 'UTF-8'),
            $authorizedXmlExists ? 'SI' : 'NO',
            $this->buildTaxSummaryMailHtml($invoiceData)
        );
    }

    private function buildTaxSummaryMailHtml(array $invoiceData): string
    {
        $lines = [];
        foreach ($invoiceData['tax_summary'] ?? [] as $group) {
            if (!is_array($group)) {
                continue;
            }
            $label = htmlspecialchars((string)($group['label'] ?? 'Impuesto'), ENT_QUOTES, 'UTF-8');
            $base = htmlspecialchars((string)($group['base'] ?? '0.00'), ENT_QUOTES, 'UTF-8');
            $amount = (float)($group['amount'] ?? 0);
            $lines[] = sprintf('Base %s: %s USD', $label, $base);
            if ($amount > 0.0) {
                $lines[] = sprintf(
                    '%s: %s USD',
                    $label,
                    htmlspecialchars(number_format($amount, 2, '.', ''), ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return $lines === [] ? 'Sin desglose tributario.' : implode('<br>', $lines);
    }

    private function normalizeAuthorizationDate(DateTimeImmutable|string|null $authorizationDate): ?string
    {
        if ($authorizationDate instanceof DateTimeImmutable) {
            return $authorizationDate->format('Y-m-d H:i:s');
        }

        if (is_string($authorizationDate) && $authorizationDate !== '') {
            try {
                return (new DateTimeImmutable($authorizationDate))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                return $authorizationDate;
            }
        }

        return null;
    }

    private function deliverDocuments(
        string $accessKey,
        ?string $authorizationNumber,
        DateTimeImmutable|string|null $authorizationDate,
        bool $requireAuthorizedXml,
        bool $isTest
    ): array {
        if (!($this->mailConfig['enabled'] ?? false)) {
            throw new \RuntimeException('El envío de correo está deshabilitado.');
        }

        $artifacts = new BillingArtifactStorage();
        $signedXmlReference = $artifacts->xmlReference('firmados', $accessKey);
        $authorizedXmlReference = $artifacts->xmlReference('autorizados', $accessKey);
        $authorizedXmlExists = $artifacts->exists($authorizedXmlReference);

        if (!$artifacts->exists($signedXmlReference)) {
            throw new \RuntimeException('No existe el XML firmado para la clave indicada.');
        }

        if ($requireAuthorizedXml && !$authorizedXmlExists) {
            throw new \RuntimeException('El XML autorizado aún no está disponible para la clave indicada.');
        }

        $signedXmlPath = $artifacts->materialize($signedXmlReference);
        $invoiceData = $this->extractInvoiceData($signedXmlPath);
        if ($invoiceData['customer_email'] === '') {
            throw new \RuntimeException('La factura no contiene correo del cliente en el XML firmado.');
        }

        $authorizationDateText = $this->normalizeAuthorizationDate($authorizationDate);
        $ridePdfReference = $this->ridePdfGenerator->generate(
            $accessKey,
            $invoiceData,
            $authorizationNumber,
            $authorizationDateText
        );
        $ridePdfPath = $artifacts->materialize($ridePdfReference);

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $this->mailConfig['host'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $this->mailConfig['username'];
        $mailer->Password = $this->mailConfig['password'];
        $mailer->Port = $this->mailConfig['port'];
        $mailer->CharSet = 'UTF-8';

        if (($this->mailConfig['encryption'] ?? '') === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (($this->mailConfig['encryption'] ?? '') === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mailer->setFrom($this->mailConfig['from_address'], $this->mailConfig['from_name']);
        $mailer->addAddress($invoiceData['customer_email'], $invoiceData['customer_name']);

        if (!empty($this->mailConfig['reply_to_address'])) {
            $mailer->addReplyTo(
                $this->mailConfig['reply_to_address'],
                $this->mailConfig['reply_to_name'] ?: $this->mailConfig['from_name']
            );
        }

        $mailer->isHTML(true);
        $mailer->Subject = $isTest
            ? sprintf('Prueba RIDE %s', $invoiceData['formatted_sequential'])
            : sprintf('Factura electrónica %s', $invoiceData['formatted_sequential']);
        $mailer->Body = $isTest
            ? $this->buildTestMailBody($invoiceData, $authorizedXmlExists)
            : $this->buildMailBody($invoiceData, $authorizationNumber, $authorizationDateText);
        $mailer->AltBody = $isTest
            ? sprintf('Prueba de RIDE para %s. Se adjunta el PDF y el XML firmado.', $invoiceData['formatted_sequential'])
            : sprintf('Su factura electrónica %s ha sido autorizada. Se adjunta el RIDE en PDF y el XML firmado.', $invoiceData['formatted_sequential']);

        $mailer->addAttachment($ridePdfPath, sprintf('RIDE-%s.pdf', $accessKey));
        $mailer->addAttachment($signedXmlPath, sprintf('%s.xml', $accessKey));
        $mailer->send();

        return [
            'access_key' => $accessKey,
            'recipient' => $invoiceData['customer_email'],
            'customer_name' => $invoiceData['customer_name'],
            'pdf_path' => sprintf('storage/pdf/rides/%s.pdf', $accessKey),
            'signed_xml_path' => sprintf('storage/xml/firmados/%s.xml', $accessKey),
            'authorized_xml_exists' => $authorizedXmlExists,
            'test_mode' => $isTest,
        ];
    }
}
