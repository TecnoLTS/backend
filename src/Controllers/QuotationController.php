<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Repositories\OrderRepository;
use App\Repositories\QuotationRepository;
use App\Services\MailService;
use Dompdf\Dompdf;
use Dompdf\Options;

class QuotationController {
    private $quotationRepository;
    private $orderRepository;
    private $quotationPdfLogoPath;

    public function __construct() {
        $this->quotationRepository = null;
        $this->orderRepository = null;
        $this->quotationPdfLogoPath = null;
    }

    private function quotationRepository(): QuotationRepository {
        if (!$this->quotationRepository instanceof QuotationRepository) {
            $this->quotationRepository = new QuotationRepository();
        }

        return $this->quotationRepository;
    }

    private function orderRepository(): OrderRepository {
        if (!$this->orderRepository instanceof OrderRepository) {
            $this->orderRepository = new OrderRepository();
        }

        return $this->orderRepository;
    }

    private function getAdminUser(): array {
        return Auth::requireUser();
    }

    private function normalizeDiscountCodeValue($value): ?string {
        if ($value === null) {
            return null;
        }
        $normalized = strtoupper(trim((string)$value));
        if ($normalized === '') {
            return null;
        }
        return preg_replace('/\s+/', '', $normalized);
    }

    private function resolveBaseUrl(): string {
        $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
        if ($baseUrl) {
            return $baseUrl;
        }

        $trustProxyHeaders = (bool)($GLOBALS['trust_proxy_headers'] ?? false);
        $proto = ($trustProxyHeaders && !empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
            ? $_SERVER['HTTP_X_FORWARDED_PROTO']
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $proto . '://' . $host;
    }

    private function splitName(string $name): array {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = trim((string)($parts[0] ?? 'Cliente'));
        $last = trim(implode(' ', array_slice($parts, 1)));
        return [
            'first' => $first !== '' ? $first : 'Cliente',
            'last' => $last !== '' ? $last : 'Local',
        ];
    }

    private function buildQuotationEmailBody(array $quotation): string {
        $customerName = trim((string)($quotation['customer_name'] ?? 'cliente'));
        $quoteId = trim((string)($quotation['id'] ?? ''));

        return implode("\n", [
            "Hola {$customerName},",
            '',
            "Te enviamos adjunta tu cotización {$quoteId} en PDF.",
            'Si necesitas ajustes o deseas convertirla en pedido, podemos ayudarte.',
            '',
            'Saludos,',
            'ParaMascotas',
        ]);
    }

    private function formatQuotationDate(?string $value, bool $includeTime = true): string {
        $raw = trim((string)$value);
        if ($raw === '') {
            return 'No definida';
        }

        try {
            $date = new \DateTimeImmutable($raw);
            return $date->format($includeTime ? 'd/m/Y, h:i a' : 'd/m/Y');
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    private function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function money($value): string {
        return '$' . number_format((float)($value ?? 0), 2, ',', '.');
    }

    private function quotationPdfStatusLabel(array $quotation): string {
        $status = strtolower(trim((string)($quotation['status'] ?? 'quoted')));
        if ($status === 'converted' || !empty($quotation['converted_order_id'])) {
            return 'Convertida';
        }
        if (in_array($status, ['closed', 'archived'], true)) {
            return 'Cerrada';
        }
        if (in_array($status, ['cancelled', 'canceled', 'void', 'rejected'], true)) {
            return 'Cancelada';
        }
        $validUntil = trim((string)($quotation['valid_until'] ?? ''));
        if ($validUntil !== '') {
            try {
                if (new \DateTimeImmutable($validUntil) < new \DateTimeImmutable('now')) {
                    return 'Vencida';
                }
            } catch (\Throwable $e) {
                // Keep the commercial status when the stored date cannot be parsed.
            }
        }
        return 'Abierta';
    }

    private function quotationIsExpired(array $quotation): bool {
        $status = strtolower(trim((string)($quotation['status'] ?? 'quoted')));
        if ($status !== 'quoted') {
            return false;
        }
        $validUntil = trim((string)($quotation['valid_until'] ?? ''));
        if ($validUntil === '') {
            return false;
        }
        try {
            return new \DateTimeImmutable($validUntil) < new \DateTimeImmutable('now');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function buildQuotationPdfHtml(array $quotation): string {
        $snapshot = is_array($quotation['quote_snapshot'] ?? null) ? $quotation['quote_snapshot'] : [];
        $items = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
        $address = is_array($quotation['customer_address'] ?? null) ? $quotation['customer_address'] : [];
        $frontendBase = TenantContext::appUrl() ?? ($_ENV['FRONTEND_URL'] ?? ($_ENV['APP_URL'] ?? 'https://paramascotasec.com'));
        if (strpos($frontendBase, 'api.') !== false) {
            $frontendBase = str_replace('://api.', '://', $frontendBase);
        }
        $logoUrl = rtrim($frontendBase, '/') . '/images/brand/LogoVerde150.png';
        $logoCandidates = [
            __DIR__ . '/../../public/images/brand/LogoVerde150.png',
            dirname(__DIR__, 4) . '/paramascotasec/app/public/images/brand/LogoVerde150.png',
        ];
        foreach ($logoCandidates as $logoPath) {
            if (is_string($logoPath) && is_readable($logoPath)) {
                $resolved = realpath($logoPath) ?: $logoPath;
                $mime = strtolower((string)pathinfo($resolved, PATHINFO_EXTENSION)) === 'jpg' ? 'image/jpeg' : 'image/png';
                $logoUrl = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($resolved));
                $this->quotationPdfLogoPath = null;
                break;
            }
        }

        $rows = '';
        foreach ($items as $index => $item) {
            $quantity = max(0, (int)($item['quantity'] ?? 0));
            $price = (float)($item['price'] ?? 0);
            $lineTotal = array_key_exists('total', $item) ? (float)$item['total'] : ($price * $quantity);
            $rows .= '<tr>'
                . '<td class="line-index">' . ($index + 1) . '</td>'
                . '<td><div class="product-name">' . $this->e((string)($item['product_name'] ?? $item['product_id'] ?? 'Producto')) . '</div>'
                . '<div class="muted">Código: ' . $this->e((string)($item['product_id'] ?? 'No indicado')) . '</div></td>'
                . '<td class="center">' . $quantity . '</td>'
                . '<td class="number">' . $this->money($price) . '</td>'
                . '<td class="number strong">' . $this->money($lineTotal) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="empty-row">Sin artículos en esta cotización.</td></tr>';
        }

        $notesHtml = trim((string)($quotation['notes'] ?? '')) !== ''
            ? '<section class="notes"><h3>Observaciones</h3><p>' . nl2br($this->e(trim((string)$quotation['notes']))) . '</p></section>'
            : '';
        $customerDocument = trim((string)($quotation['customer_document_number'] ?? '')) ?: 'No indicado';
        $customerEmail = trim((string)($quotation['customer_email'] ?? '')) ?: 'No indicado';
        $customerPhone = trim((string)($quotation['customer_phone'] ?? '')) ?: 'No indicado';
        $customerAddress = [
            $address['street'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['country'] ?? null,
        ];
        $customerAddressText = implode(', ', array_filter(array_map(static fn($value) => trim((string)$value), $customerAddress))) ?: 'No indicada';
        $subtotal = (float)($snapshot['vat_subtotal'] ?? $snapshot['subtotal'] ?? 0);
        $discount = (float)($snapshot['discount_total'] ?? 0);
        $shipping = (float)($snapshot['shipping'] ?? 0);
        $tax = (float)($snapshot['vat_amount'] ?? 0);
        $total = (float)($snapshot['total'] ?? 0);
        $statusLabel = $this->quotationPdfStatusLabel($quotation);
        $itemTypes = count($items);
        $itemUnits = array_reduce($items, static fn(int $sum, $item): int => $sum + (is_array($item) ? max(0, (int)($item['quantity'] ?? 0)) : 0), 0);
        $quoteId = (string)($quotation['id'] ?? 'Cotización');
        $createdAt = $this->formatQuotationDate((string)($quotation['created_at'] ?? ''), true);
        $validUntil = $this->formatQuotationDate((string)($quotation['valid_until'] ?? ''), false);
        $documentType = trim((string)($quotation['customer_document_type'] ?? 'Documento')) ?: 'Documento';
        $discountCode = trim((string)($quotation['discount_code'] ?? ''));
        $paymentMethod = trim((string)($quotation['payment_method'] ?? '')) ?: 'Por confirmar';

        return '<!doctype html>
        <html lang="es">
        <head>
            <meta charset="utf-8" />
            <title>Cotización ' . $this->e($quoteId) . '</title>
            <style>
                @page { margin: 18px 20px; }
                body { color: #111111; font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 9.5px; line-height: 1.25; margin: 0; }
                .sheet, .top-layout, .client-grid, .bottom-grid, .items, .totals, .summary-strip { border-collapse: collapse; width: 100%; }
                .top-layout { margin-bottom: 8px; }
                .top-layout td, .client-grid td, .bottom-grid td { vertical-align: top; }
                .left-column { padding-right: 8px; }
                .logo-wrap { min-height: 58px; margin-bottom: 8px; }
                .brand-logo { max-height: 66px; max-width: 210px; width: auto; }
                .brand-name { font-size: 16px; font-weight: 800; margin-bottom: 5px; }
                .box, .document-box { border: 1.15px solid #1b1b1b; padding: 8px 10px; margin-bottom: 8px; }
                .document-box { border-radius: 8px; }
                .section-title, .table-title { font-size: 8.8px; font-weight: 800; margin-bottom: 5px; text-transform: uppercase; }
                .document-title { font-size: 18px; font-weight: 800; letter-spacing: 2.4px; margin-bottom: 7px; text-transform: uppercase; }
                .document-number { border: 1px solid #1b1b1b; font-size: 12px; font-weight: 800; margin: 6px 0 8px; padding: 6px 8px; text-align: center; }
                .row { margin-bottom: 4px; }
                .label { display: inline-block; font-weight: 800; min-width: 124px; }
                .value { font-weight: 500; }
                .status { border: 1px solid #1b1b1b; display: inline-block; font-size: 8.5px; font-weight: 800; margin-top: 4px; padding: 3px 7px; text-transform: uppercase; }
                .notice { background: #f8fafc; border: 1px solid #cbd5e1; color: #334155; font-size: 8.7px; padding: 6px 8px; }
                .summary-strip { margin: 4px 0 8px; }
                .summary-strip td { border: 1px solid #1b1b1b; padding: 7px 8px; width: 25%; }
                .summary-strip span { color: #475569; display: block; font-size: 7.8px; font-weight: 800; text-transform: uppercase; }
                .summary-strip strong { display: block; font-size: 12px; margin-top: 3px; }
                .items th, .items td, .totals td { border: 1px solid #1b1b1b; padding: 5px; }
                .items th { background: #f3f4f6; font-size: 8.4px; font-weight: 800; text-align: center; text-transform: uppercase; }
                .items td { border-color: #334155; vertical-align: top; }
                .line-index { text-align: center; width: 34px; }
                .product-name { font-size: 10px; font-weight: 800; line-height: 1.25; }
                .muted { color: #64748b; font-size: 8px; margin-top: 2px; }
                .center { text-align: center; }
                .number { text-align: right; white-space: nowrap; }
                .strong { font-weight: 800; }
                .empty-row { color: #64748b; padding: 12px; text-align: center; }
                .totals .summary-label { background: #f7f7f7; font-weight: 800; width: 66%; }
                .totals .grand td { font-size: 11px; font-weight: 800; }
                .notes { border: 1px solid #1b1b1b; margin-top: 8px; padding: 8px 10px; }
                .notes h3 { font-size: 8.8px; font-weight: 800; margin: 0 0 5px; text-transform: uppercase; }
                .notes p { color: #334155; margin: 0; white-space: pre-wrap; }
                .footer { border: 1.15px solid #1b1b1b; font-size: 8.8px; margin-top: 8px; padding: 8px 10px; text-align: center; }
            </style>
        </head>
        <body>
            <div class="sheet">
                <table class="top-layout">
                    <tr>
                        <td class="left-column" width="45%">
                            <div class="logo-wrap">
                                <img class="brand-logo" src="' . $this->e($logoUrl) . '" alt="ParaMascotas" />
                            </div>
                            <div class="document-box">
                                <div class="brand-name">Para Mascotas EC</div>
                                <div class="section-title">Datos del emisor</div>
                                <div class="row"><span class="label">Razon social:</span><span class="value">Para Mascotas EC</span></div>
                                <div class="row"><span class="label">Actividad:</span><span class="value">Comercializacion de productos para mascotas</span></div>
                                <div class="row"><span class="label">Direccion matriz:</span><span class="value">Quito, Ecuador</span></div>
                                <div class="row"><span class="label">Documento:</span><span class="value">Cotizacion comercial</span></div>
                            </div>
                        </td>
                        <td width="55%">
                            <div class="document-box">
                            <div class="document-title">Cotizacion</div>
                            <div class="document-number">' . $this->e($quoteId) . '</div>
                            <div class="row"><span class="label">Estado comercial:</span><span class="value">' . $this->e($statusLabel) . '</span></div>
                            <div class="row"><span class="label">Fecha de emision:</span><span class="value">' . $this->e($createdAt) . '</span></div>
                            <div class="row"><span class="label">Valida hasta:</span><span class="value">' . $this->e($validUntil) . '</span></div>
                            <div class="row"><span class="label">Ambiente:</span><span class="value">Dashboard comercial</span></div>
                            <span class="status">' . $this->e($statusLabel) . '</span>
                            <div class="notice" style="margin-top:8px;">Documento comercial sin validez tributaria SRI. No reemplaza factura electronica ni RIDE.</div>
                            </div>
                        </td>
                    </tr>
                </table>

                <table class="summary-strip">
                    <tr>
                        <td><span>Total cotizado</span><strong>' . $this->money($total) . '</strong></td>
                        <td><span>Items</span><strong>' . $itemTypes . ' tipo' . ($itemTypes === 1 ? '' : 's') . '</strong></td>
                        <td><span>Unidades</span><strong>' . $itemUnits . ' ud' . ($itemUnits === 1 ? '' : 's') . '</strong></td>
                        <td><span>Validez</span><strong>' . $this->e($validUntil) . '</strong></td>
                    </tr>
                </table>

                <div class="box">
                    <div class="section-title">Datos del cliente</div>
                    <table class="client-grid">
                    <tr>
                        <td class="left-column" width="58%">
                            <div class="row"><span class="label">Razon social / nombres:</span><span class="value">' . $this->e((string)($quotation['customer_name'] ?? 'Cliente')) . '</span></div>
                            <div class="row"><span class="label">' . $this->e($documentType) . ':</span><span class="value">' . $this->e($customerDocument) . '</span></div>
                            <div class="row"><span class="label">Direccion:</span><span class="value">' . $this->e($customerAddressText) . '</span></div>
                        </td>
                        <td width="42%">
                            <div class="row"><span class="label">Correo:</span><span class="value">' . $this->e($customerEmail) . '</span></div>
                            <div class="row"><span class="label">Telefono:</span><span class="value">' . $this->e($customerPhone) . '</span></div>
                            <div class="row"><span class="label">Forma de pago:</span><span class="value">' . $this->e($paymentMethod) . '</span></div>
                        </td>
                    </tr>
                </table>
                </div>

                <table class="items">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th style="width:48%;">Descripción</th>
                            <th class="center">Cantidad</th>
                            <th class="number">Precio unitario</th>
                            <th class="number">Total</th>
                        </tr>
                    </thead>
                    <tbody>' . $rows . '</tbody>
                </table>

                <table class="bottom-grid" style="margin-top:8px;">
                    <tr>
                        <td class="left-column" width="58%">
                            <div class="box">
                                <div class="section-title">Informacion adicional</div>
                                <div class="row"><span class="label">Entrega:</span><span class="value">Retiro en tienda</span></div>
                                <div class="row"><span class="label">Descuento:</span><span class="value">' . $this->e($discountCode !== '' ? $discountCode : 'Sin codigo') . '</span></div>
                                <div class="row"><span class="label">Stock:</span><span class="value">Sujeto a disponibilidad al confirmar la venta</span></div>
                            </div>
                            ' . $notesHtml . '
                        </td>
                        <td width="42%">
                            <div class="box" style="padding:0;">
                                <table class="totals">
                                    <tr><td class="summary-label">Subtotal sin impuestos</td><td class="number">' . $this->money($subtotal) . '</td></tr>
                                    ' . ($discount > 0 ? '<tr><td class="summary-label">Descuento</td><td class="number">-' . $this->money($discount) . '</td></tr>' : '') . '
                                    ' . ($shipping > 0 ? '<tr><td class="summary-label">Envio</td><td class="number">' . $this->money($shipping) . '</td></tr>' : '') . '
                                    <tr><td class="summary-label">IVA</td><td class="number">' . $this->money($tax) . '</td></tr>
                                    <tr class="grand"><td class="summary-label">Valor total</td><td class="number">' . $this->money($total) . '</td></tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                </table>


                <div class="footer">
                    Esta cotizacion es una propuesta comercial. Los precios, impuestos, descuentos y disponibilidad se validan nuevamente al convertir en venta; la factura electronica se emite solo al confirmar el pedido.
                </div>
            </div>
        </body>
        </html>';
    }

    private function generateQuotationPdf(array $quotation): string {
        $options = new Options();
        $remoteEnabled = in_array(strtolower((string)($_ENV['DOMPDF_REMOTE_ENABLED'] ?? 'false')), ['1', 'true', 'yes', 'on'], true);
        $options->set('isRemoteEnabled', $remoteEnabled);
        if (!$remoteEnabled) {
            $options->set('allowedRemoteHosts', []);
        }
        $options->set('isHtml5ParserEnabled', true);
        if ($this->quotationPdfLogoPath) {
            $options->setChroot(dirname($this->quotationPdfLogoPath));
        }
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildQuotationPdfHtml($quotation), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        return $dompdf->output();
    }

    private function sendQuotationEmail(array $quotation): array {
        $recipient = trim((string)($quotation['customer_email'] ?? ''));
        if ($recipient === '') {
            return ['requested' => true, 'sent' => false, 'recipient' => null, 'message' => 'No se indicó correo para enviar la cotización.'];
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['requested' => true, 'sent' => false, 'recipient' => $recipient, 'message' => 'El correo indicado no es válido.'];
        }

        $subject = 'Cotización ' . trim((string)($quotation['id'] ?? '')) . ' - ParaMascotas';
        $message = $this->buildQuotationEmailBody($quotation);
        $attachmentName = trim((string)($quotation['id'] ?? 'cotizacion')) . '.pdf';
        $pdfBinary = $this->generateQuotationPdf($quotation);
        $sent = MailService::sendWithAttachment(
            $recipient,
            $subject,
            $message,
            $attachmentName,
            $pdfBinary,
            'application/pdf',
            null,
            null,
            [
                'source' => 'quotation',
                'quotation_id' => trim((string)($quotation['id'] ?? '')),
            ]
        );

        return [
            'requested' => true,
            'sent' => $sent,
            'recipient' => $recipient,
            'message' => $sent
                ? 'Cotización enviada correctamente por correo con PDF adjunto.'
                : 'No se pudo enviar la cotización por correo.',
        ];
    }

    public function index() {
        $this->getAdminUser();
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            Response::json($this->quotationRepository()->listRecent($limit));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'QUOTATION_LIST_FAILED');
        }
    }

    public function pdf($id) {
        $this->getAdminUser();
        try {
            $quotation = $this->quotationRepository()->getById((string)$id);
            if (!$quotation) {
                Response::error('Cotización no encontrada.', 404, 'QUOTATION_NOT_FOUND');
                return;
            }

            $content = $this->generateQuotationPdf($quotation);
            $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string)$quotation['id']) ?: 'cotizacion';

            Response::noStore();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . addslashes($filename) . '.pdf"');
            header('Content-Length: ' . strlen($content));
            echo $content;
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'QUOTATION_PDF_FAILED');
        }
    }

    public function store() {
        $user = $this->getAdminUser();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            if (count($items) === 0) {
                Response::error('Agrega al menos un producto para cotizar.', 400, 'QUOTATION_ITEMS_REQUIRED');
                return;
            }

            $customerName = trim((string)($data['customer_name'] ?? ''));
            if (mb_strlen($customerName) < 3) {
                Response::error('Ingresa el nombre del cliente para generar la cotización.', 400, 'QUOTATION_CUSTOMER_REQUIRED');
                return;
            }

            $discountCode = $this->normalizeDiscountCodeValue($data['discount_code'] ?? null);
            $quote = $this->orderRepository()->calculateQuote(
                $items,
                $data['delivery_method'] ?? 'pickup',
                $discountCode,
                'quote',
                null,
                null,
                [
                    'shipping_address' => is_array($data['shipping_address'] ?? null) ? $data['shipping_address'] : null,
                    'allow_missing_shipping_location' => true,
                ]
            );

            $createdAt = new \DateTimeImmutable('now');
            $validUntil = $createdAt->modify('+7 days');
            $quotationId = 'COT-' . $createdAt->format('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));

            $quotation = $this->quotationRepository()->create([
                'id' => $quotationId,
                'status' => 'quoted',
                'customer_name' => $customerName,
                'customer_document_type' => trim((string)($data['customer_document_type'] ?? '')) ?: null,
                'customer_document_number' => trim((string)($data['customer_document_number'] ?? '')) ?: null,
                'customer_email' => trim((string)($data['customer_email'] ?? '')) ?: null,
                'customer_phone' => trim((string)($data['customer_phone'] ?? '')) ?: null,
                'customer_address' => is_array($data['customer_address'] ?? null) ? $data['customer_address'] : [],
                'delivery_method' => 'pickup',
                'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
                'discount_code' => $discountCode,
                'notes' => trim((string)($data['notes'] ?? '')) ?: null,
                'items' => $items,
                'quote_snapshot' => $quote,
                'created_by_user_id' => (string)($user['sub'] ?? 'service'),
                'valid_until' => $validUntil->format(DATE_ATOM),
            ]);

            $emailDelivery = [
                'requested' => false,
                'sent' => false,
                'recipient' => null,
                'message' => null,
            ];
            if (!empty($data['send_email'])) {
                $emailDelivery = $this->sendQuotationEmail($quotation);
            }

            Response::json([
                ...$quotation,
                'email_delivery' => $emailDelivery,
            ], 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'QUOTATION_CREATE_FAILED');
        }
    }

    public function closeExpired() {
        $this->getAdminUser();
        try {
            Response::json([
                'closed' => $this->quotationRepository()->closeExpired(),
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'QUOTATION_CLOSE_EXPIRED_FAILED');
        }
    }

    public function close($id) {
        $this->getAdminUser();
        try {
            $quotation = $this->quotationRepository()->getById((string)$id);
            if (!$quotation) {
                Response::error('Cotización no encontrada.', 404, 'QUOTATION_NOT_FOUND');
                return;
            }
            $status = strtolower(trim((string)($quotation['status'] ?? 'quoted')));
            if ($status === 'converted' || !empty($quotation['converted_order_id'])) {
                Response::error('Una cotización convertida no se puede cerrar manualmente.', 409, 'QUOTATION_ALREADY_CONVERTED');
                return;
            }
            if ($status === 'closed') {
                Response::json($quotation);
                return;
            }
            if (!$this->quotationIsExpired($quotation)) {
                Response::error('Solo se pueden cerrar cotizaciones vencidas. Si el cliente confirma, conviértela en venta.', 409, 'QUOTATION_NOT_EXPIRED');
                return;
            }

            Response::json($this->quotationRepository()->markClosed((string)$quotation['id']));
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'QUOTATION_CLOSE_FAILED');
        }
    }

    public function convert($id) {
        $user = $this->getAdminUser();
        try {
            $quotation = $this->quotationRepository()->getById((string)$id);
            if (!$quotation) {
                Response::error('Cotización no encontrada.', 404, 'QUOTATION_NOT_FOUND');
                return;
            }

            if (($quotation['status'] ?? 'quoted') === 'converted' && !empty($quotation['converted_order_id'])) {
                Response::error('Esta cotización ya fue convertida a venta.', 409, 'QUOTATION_ALREADY_CONVERTED');
                return;
            }

            $status = strtolower(trim((string)($quotation['status'] ?? 'quoted')));
            if (in_array($status, ['closed', 'cancelled', 'canceled', 'void', 'rejected'], true)) {
                Response::error('Esta cotización está cerrada o fuera de flujo; crea una nueva cotización antes de vender.', 409, 'QUOTATION_NOT_CONVERTIBLE');
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $paymentMethod = trim((string)($data['payment_method'] ?? ''));
            if ($paymentMethod === '') {
                Response::error('Método de pago requerido para convertir la cotización.', 400, 'QUOTATION_PAYMENT_METHOD_REQUIRED');
                return;
            }

            $paymentDetails = is_array($data['payment_details'] ?? null) ? $data['payment_details'] : [];
            $customerAddress = is_array($quotation['customer_address'] ?? null) ? $quotation['customer_address'] : [];
            $customerName = trim((string)($quotation['customer_name'] ?? 'Cliente local'));
            $nameParts = $this->splitName($customerName);

            $orderAddress = [
                'firstName' => $nameParts['first'],
                'lastName' => $nameParts['last'],
                'phone' => trim((string)($quotation['customer_phone'] ?? '')) ?: null,
                'email' => trim((string)($quotation['customer_email'] ?? '')) ?: null,
                'street' => trim((string)($customerAddress['street'] ?? '')) ?: null,
                'city' => trim((string)($customerAddress['city'] ?? '')) ?: null,
                'state' => trim((string)($customerAddress['state'] ?? '')) ?: null,
                'country' => trim((string)($customerAddress['country'] ?? 'EC')) ?: 'EC',
                'zip' => trim((string)($customerAddress['zip'] ?? '')) ?: null,
                'documentType' => trim((string)($quotation['customer_document_type'] ?? '')) ?: null,
                'documentNumber' => trim((string)($quotation['customer_document_number'] ?? '')) ?: null,
            ];

            $orderPayload = [
                'id' => 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4))),
                'user_id' => (string)($user['sub'] ?? 'service'),
                'status' => 'completed',
                'delivery_method' => 'pickup',
                'payment_method' => $paymentMethod,
                'shipping_address' => $orderAddress,
                'billing_address' => $orderAddress,
                'order_notes' => trim((string)($quotation['notes'] ?? 'Cotización convertida a venta')) ?: 'Cotización convertida a venta',
                'coupon_code' => $this->normalizeDiscountCodeValue($quotation['discount_code'] ?? null),
                'payment_details' => array_merge($paymentDetails, [
                    'channel' => 'local_pos',
                    'quotation_id' => (string)$quotation['id'],
                    'converted_from_quote' => true,
                ]),
                'items' => array_map(static function ($item): array {
                    return [
                        'product_id' => (string)($item['product_id'] ?? ''),
                        'quantity' => max(0, (int)($item['quantity'] ?? 0)),
                    ];
                }, is_array($quotation['items'] ?? null) ? $quotation['items'] : []),
            ];

            $order = $this->orderRepository()->create($orderPayload, $this->resolveBaseUrl());
            $updatedQuotation = $this->quotationRepository()->markConverted((string)$quotation['id'], (string)($order['id'] ?? ''));

            Response::json([
                'quotation' => $updatedQuotation,
                'order' => $order,
            ], 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'QUOTATION_CONVERT_FAILED');
        }
    }
}
