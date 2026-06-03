<?php

namespace App\Controllers;

use App\Repositories\OrderRepository;
use App\Repositories\AuthSecurityRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Core\Response;
use App\Core\Auth;
use App\Core\TenantContext;
use App\Exceptions\FinancialPeriodClosedException;
use App\Services\FacturadorApiService;
use Dompdf\Dompdf;
use Dompdf\Options;

class OrderController {
    private const FINAL_CONSUMER_IDENTIFICATION = '9999999999999';
    private const FINAL_CONSUMER_MAX_TOTAL = 50.00;

    private $orderRepository;
    private $userRepository;
    private $authSecurityRepository;
    private $forbiddenDiscountPayloadKeys = [
        'discount',
        'discounts',
        'coupon',
        'promo',
        'promo_code',
        'promotion',
        'promotions'
    ];
    private $forbiddenMoneyPayloadKeys = [
        'total',
        'subtotal',
        'items_subtotal',
        'vat_subtotal',
        'vat_amount',
        'vat_rate',
        'shipping',
        'shipping_base',
        'shipping_tax_rate',
        'shipping_tax_amount',
        'discount_total',
        'discount_amount',
        'discount_value',
        'net_total',
        'grand_total',
        'amount'
    ];
    private $forbiddenItemPricingKeys = [
        'price',
        'unit_price',
        'subtotal',
        'total',
        'discount_total',
        'discount_amount',
        'net_total',
        'tax_amount',
        'tax_rate',
        'unit_cost',
        'cost_total'
    ];

    public function __construct() {
        $this->orderRepository = new OrderRepository();
        $this->userRepository = new UserRepository();
        $this->authSecurityRepository = new AuthSecurityRepository();
    }

    private function authenticate() {
        return Auth::requireUser();
    }

    private function authenticateOptional() {
        return Auth::optionalUser();
    }

    private function shouldEmitFacturadorInvoiceForStatus(?string $status): bool
    {
        $normalized = strtolower(trim((string)$status));
        return in_array($normalized, ['completed', 'delivered'], true);
    }

    private function dispatchFacturadorInvoiceAfterResponse(array $order): void
    {
        if (!$order || empty($order['id']) || !$this->shouldEmitFacturadorInvoiceForStatus($order['status'] ?? null)) {
            return;
        }

        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            $this->emitInvoiceWithFacturador($order);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[FACTURADOR_DEFERRED_DISPATCH_FAILED] orden=%s error=%s',
                (string)($order['id'] ?? 'N/A'),
                $e->getMessage()
            ));
        }
    }

    private function customerCanCancelOrder(array $order, array $user): bool
    {
        if (empty($user['sub']) || empty($order['user_id']) || (string)$order['user_id'] !== (string)$user['sub']) {
            return false;
        }

        $status = strtolower(trim((string)($order['status'] ?? 'pending')));
        return in_array($status, ['pending', 'processing'], true);
    }

    public function index() {
        $user = $this->authenticate();
        try {
            $isAdmin = (($user['role'] ?? 'customer') === 'admin');
            if ($isAdmin) {
                $orders = $this->orderRepository->getAll();
            } else {
                if (empty($user['sub'])) {
                    Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                    return;
                }
                $orders = $this->orderRepository->getByUserId($user['sub']);
            }
            Response::json($orders);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDERS_LIST_FAILED');
        }
    }
    
    public function myOrders() {
        $user = $this->authenticate();
        try {
            if (($user['role'] ?? 'customer') === 'guest' || empty($user['sub'])) {
                Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                return;
            }
            $orders = $this->orderRepository->getByUserId($user['sub']); // 'sub' is user id in JWT
            Response::json($orders);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDERS_LIST_FAILED');
        }
    }

    public function show($id) {
        $user = $this->authenticate();
        try {
            $order = $this->orderRepository->getById($id);
            if (!$order) {
                Response::error('Pedido no encontrado', 404, 'ORDER_NOT_FOUND');
                return;
            }
            // Permission check: admin or owner
            $isAdmin = (($user['role'] ?? 'customer') === 'admin');
            if (!$isAdmin && $order['user_id'] !== $user['sub']) {
                Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                return;
            }
            
            Response::json($order);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDER_FETCH_FAILED');
        }
    }

    public function updateStatus($id) {
        $user = $this->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['status'])) {
            Response::error('Estado requerido', 400, 'ORDER_STATUS_REQUIRED');
            return;
        }

        $allowedStatuses = ['pending', 'processing', 'shipped', 'delivered', 'completed', 'canceled'];
        $newStatus = strtolower(trim((string)$data['status']));
        if (!in_array($newStatus, $allowedStatuses, true)) {
            Response::error('Estado inválido', 400, 'ORDER_STATUS_INVALID');
            return;
        }

        try {
            $order = $this->orderRepository->getById($id);
            if (!$order) {
                Response::error('Pedido no encontrado', 404, 'ORDER_NOT_FOUND');
                return;
            }
            $isAdmin = (($user['role'] ?? 'customer') === 'admin');
            if (!$isAdmin) {
                $isCustomerCancel = $newStatus === 'canceled' && $this->customerCanCancelOrder($order, $user);
                if (!$isCustomerCancel) {
                    Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                    return;
                }
            }
            if ($this->shouldEmitFacturadorInvoiceForStatus($newStatus)) {
                $this->assertFinalConsumerAllowedForOrder($order, (float)($order['total'] ?? 0));
            }

            $updated = $this->orderRepository->updateStatus($id, $newStatus);
            Response::json($updated);
            $this->dispatchFacturadorInvoiceAfterResponse($updated ?: []);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\DomainException $e) {
            Response::error($e->getMessage(), 409, 'FINAL_CONSUMER_LIMIT_EXCEEDED');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDER_STATUS_UPDATE_FAILED');
        }
    }

    public function invoice($id) {
        $user = $this->authenticate();
        try {
            $order = $this->orderRepository->getById($id);
            if (!$order) {
                Response::error('Pedido no encontrado', 404, 'ORDER_NOT_FOUND');
                return;
            }
            $isAdmin = ($user['role'] ?? '') === 'admin';
            if (!$isAdmin && $order['user_id'] !== $user['sub']) {
                Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
                return;
            }
            if (!$isAdmin && ($order['status'] ?? '') === 'canceled') {
                Response::error('Comprobante interno no disponible para pedidos cancelados', 403, 'ORDER_INVOICE_UNAVAILABLE');
                return;
            }
            $invoiceHtml = $this->resolveInvoiceHtml($order);
            if (!$invoiceHtml) {
                Response::error('Comprobante interno no disponible', 404, 'ORDER_INVOICE_UNAVAILABLE');
                return;
            }

            $format = strtolower(trim((string)($_GET['format'] ?? 'html')));
            if ($format === 'pdf') {
                $pdfBinary = $this->renderInvoicePdf($invoiceHtml);
                $safeOrderId = preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$order['id']);
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="comprobante-' . $safeOrderId . '.pdf"');
                header('Content-Length: ' . strlen($pdfBinary));
                echo $pdfBinary;
                return;
            }

            header('Content-Type: text/html; charset=utf-8');
            echo $invoiceHtml;
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'ORDER_INVOICE_FAILED');
        }
    }

    private function resolveInvoiceHtml(array &$order): ?string {
        if (empty($order['invoice_html'])) {
            $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
            if (!$baseUrl) {
                $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $proto . '://' . $host;
            }
            $invoiceHtml = $this->orderRepository->ensureInvoiceForOrder($order, $baseUrl);
            if (!$invoiceHtml) {
                return null;
            }
            $order['invoice_html'] = $invoiceHtml;
            return $invoiceHtml;
        }

        $invoiceData = null;
        if (!empty($order['invoice_data'])) {
            $invoiceData = json_decode($order['invoice_data'], true);
        }
        $customerName = $invoiceData['customer']['name'] ?? null;
        $subtotalGross = 0.0;
        if (!empty($order['items']) && is_array($order['items'])) {
            foreach ($order['items'] as $item) {
                $subtotalGross += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1);
            }
        }
        $discountTotal = max(0, (float)($order['discount_total'] ?? 0));
        $expectedShipping = (float)($order['total'] ?? $subtotalGross) - max(0, ($subtotalGross - $discountTotal));
        if ($expectedShipping < 0) {
            $expectedShipping = 0;
        }
        $showsZeroShipping = strpos($order['invoice_html'], 'Envío</span><span>$0') !== false
            || strpos($order['invoice_html'], 'Envío</span><span>$0,00') !== false
            || strpos($order['invoice_html'], 'Envío</span><span>$0.00') !== false;
        $needsRegenerate = empty($customerName)
            || (strpos($order['invoice_html'], 'LogoVerde150.png') !== false && strpos($order['invoice_html'], 'api.') !== false)
            || (strpos($order['invoice_html'], 'brand-name') !== false)
            || (strpos($order['invoice_html'], 'invoice_v2_tax_net') === false)
            || ($expectedShipping > 0 && $showsZeroShipping);
        if ($needsRegenerate) {
            $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
            if (!$baseUrl) {
                $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $proto . '://' . $host;
            }
            $invoiceHtml = $this->orderRepository->ensureInvoiceForOrder($order, $baseUrl, true);
            if ($invoiceHtml) {
                $order['invoice_html'] = $invoiceHtml;
            }
        }

        return !empty($order['invoice_html']) ? (string)$order['invoice_html'] : null;
    }

    private function renderInvoicePdf(string $invoiceHtml): string {
        $options = new Options();
        $remoteEnabled = in_array(strtolower((string)($_ENV['DOMPDF_REMOTE_ENABLED'] ?? 'false')), ['1', 'true', 'yes', 'on'], true);
        $options->set('isRemoteEnabled', $remoteEnabled);
        if (!$remoteEnabled) {
            $options->set('allowedRemoteHosts', []);
        }
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($invoiceHtml, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    private function decodeAddress($value) {
        if (!$value) return [];
        if (is_array($value)) return $value;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function emitInvoiceWithFacturador(array $order): void {
        if (empty($order['id'])) {
            return;
        }

        try {
            $orderId = (string)$order['id'];
            $currentBilling = $this->currentFacturadorBillingMetadata($order);
            $currentStatus = strtolower(trim((string)($currentBilling['status'] ?? '')));
            $currentAccessKey = trim((string)($currentBilling['access_key'] ?? ''));
            if (filter_var($currentBilling['operational_error'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return;
            }
            if ($currentAccessKey !== '' && in_array($currentStatus, ['issued', 'reissued'], true)) {
                return;
            }

            $facturador = new FacturadorApiService();
            $existingInvoice = $facturador->findRideBySourceReference($orderId);
            if (is_array($existingInvoice)) {
                $this->syncFacturadorBillingMetadata(
                    $orderId,
                    $existingInvoice,
                    trim((string)($existingInvoice['replaced_access_key'] ?? '')) !== '' ? 'reissued' : 'issued'
                );

                error_log(sprintf(
                    '[FACTURADOR] Factura existente sincronizada para orden %s. Clave=%s',
                    $orderId,
                    $existingInvoice['access_key'] ?? 'N/A'
                ));
                return;
            }

            $payload = $this->buildFacturadorPayload($order);
            $invoice = $facturador->emitInvoice($payload);

            $this->syncFacturadorBillingMetadata(
                $orderId,
                $invoice,
                'issued',
                $this->resolveOrderAccountingDate($order['created_at'] ?? null),
                trim((string)($order['created_at'] ?? '')) ?: null
            );

            error_log(sprintf(
                '[FACTURADOR] Factura emitida para orden %s. Clave=%s',
                $orderId,
                $invoice['access_key'] ?? 'N/A'
            ));
        } catch (\Throwable $e) {
            $this->orderRepository->updateBillingMetadata((string)$order['id'], [
                'provider' => 'facturador',
                'status' => 'failed',
                'last_attempt_at' => date('c'),
                'last_error' => $e->getMessage(),
            ]);

            error_log(sprintf(
                '[FACTURADOR] Error facturando orden %s: %s',
                $order['id'],
                $e->getMessage()
            ));
        }
    }

    private function currentFacturadorBillingMetadata(array $order): array {
        $invoiceData = $order['invoice_data'] ?? null;
        if (is_string($invoiceData) && trim($invoiceData) !== '') {
            $decoded = json_decode($invoiceData, true);
            $invoiceData = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($invoiceData)) {
            return [];
        }

        return is_array($invoiceData['billing'] ?? null) ? $invoiceData['billing'] : [];
    }

    private function syncFacturadorBillingMetadata(
        string $orderId,
        array $invoice,
        string $billingStatus,
        ?string $accountingDate = null,
        ?string $orderCreatedAt = null
    ): void {
        $metadata = [
            'provider' => 'facturador',
            'status' => $billingStatus,
            'invoice_status' => $invoice['sri_status'] ?? ($invoice['status'] ?? null),
            'access_key' => $invoice['access_key'] ?? null,
            'sequential' => $this->formatFacturadorSequential($invoice),
            'issue_date' => $invoice['issue_date'] ?? null,
            'total' => $invoice['total'] ?? ($invoice['total_with_tax'] ?? null),
            'authorization_number' => $invoice['authorization_number'] ?? null,
            'authorization_date' => $invoice['authorization_date'] ?? null,
            'pdf_url' => $invoice['pdf_url'] ?? null,
            'xml_url' => $invoice['xml_url'] ?? null,
            'reissued_from_access_key' => $invoice['replaced_access_key'] ?? null,
            'last_attempt_at' => date('c'),
            'last_error' => null,
        ];

        if ($accountingDate !== null && $accountingDate !== '') {
            $metadata['accounting_date'] = $accountingDate;
        }
        if ($orderCreatedAt !== null && $orderCreatedAt !== '') {
            $metadata['order_created_at'] = $orderCreatedAt;
        }
        foreach ([
            'operational_error',
            'operational_error_code',
            'operational_error_label',
            'operational_error_reason',
            'operational_error_marked_at',
            'operational_error_actor',
        ] as $field) {
            if (array_key_exists($field, $invoice)) {
                $metadata[$field] = $invoice[$field];
            }
        }

        $this->orderRepository->updateBillingMetadata($orderId, $metadata);
    }

    private function formatFacturadorSequential(array $invoice): ?string {
        $existing = trim((string)($invoice['sequential'] ?? ''));
        if ($existing !== '' && str_contains($existing, '-')) {
            return $existing;
        }

        $parts = [
            $invoice['establishment_code'] ?? null,
            $invoice['emission_point'] ?? null,
            $existing !== '' ? $existing : null,
        ];
        $parts = array_values(array_filter($parts, static fn($value) => is_string($value) && trim($value) !== ''));

        return count($parts) === 3 ? implode('-', $parts) : ($existing !== '' ? $existing : null);
    }

    private function resolveCustomerUserId(array $data, array $user): ?string {
        $role = strtolower(trim((string)($user['role'] ?? 'customer')));
        $channel = strtolower(trim((string)($data['payment_details']['channel'] ?? '')));

        if ($role === 'admin' && in_array($channel, ['local_pos', 'historical_import'], true)) {
            $shippingAddress = $this->decodeAddress($data['shipping_address'] ?? null);
            $billingAddress = $this->decodeAddress($data['billing_address'] ?? null);
            $address = !empty($shippingAddress) ? $shippingAddress : $billingAddress;

            $firstName = trim((string)($address['firstName'] ?? ''));
            $lastName = trim((string)($address['lastName'] ?? ''));
            $fullName = trim((string)($address['name'] ?? trim($firstName . ' ' . $lastName)));

            $customerUser = $this->userRepository->upsertLocalSaleCustomer([
                'name' => $fullName !== '' ? $fullName : ($channel === 'historical_import' ? 'Cliente histórico' : 'Cliente local'),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $address['email'] ?? null,
                'phone' => $address['phone'] ?? null,
                'street' => $address['street'] ?? null,
                'city' => $address['city'] ?? null,
                'document_type' => $address['documentType'] ?? null,
                'document_number' => $address['documentNumber'] ?? null,
                'business_name' => $address['company'] ?? null,
            ]);

            return $customerUser['id'] ?? null;
        }

        return !empty($user['sub']) ? (string)$user['sub'] : null;
    }

    private function buildFacturadorPayload(array $order): array {
        $billingAddress = $this->decodeAddress($order['billing_address'] ?? null);
        $shippingAddress = $this->decodeAddress($order['shipping_address'] ?? null);
        $customerAddressData = $billingAddress ?: $shippingAddress;

        $customer = $this->resolveFacturadorCustomer(
            $customerAddressData,
            trim((string)($order['user_name'] ?? '')),
            (float)($order['total'] ?? 0)
        );
        $customerName = $customer['name'];
        $customerIdentification = $customer['identification'];

        $customerAddress = implode(', ', array_filter([
            $customerAddressData['street'] ?? null,
            $customerAddressData['city'] ?? null,
            $customerAddressData['state'] ?? null,
            $customerAddressData['country'] ?? null,
        ]));
        if ($customerAddress === '') {
            $customerAddress = 'Ecuador';
        }

        $grossItems = is_array($order['items'] ?? null) ? array_values($order['items']) : [];
        $discountAllocations = $this->allocateOrderDiscountAcrossItems($grossItems, (float)($order['discount_total'] ?? 0));
        $fallbackVatRate = isset($order['vat_rate']) && is_numeric($order['vat_rate']) ? max(0, (float)$order['vat_rate']) : 0.0;

        $items = [];
        foreach ($grossItems as $index => $item) {
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $grossUnitPrice = (float)($item['price'] ?? 0);
            $grossLineTotal = round($grossUnitPrice * $quantity, 2);
            $allocatedDiscount = round((float)($discountAllocations[$index] ?? 0), 2);
            $discountedGrossLine = round(max(0, $grossLineTotal - $allocatedDiscount), 2);
            $itemTaxRate = isset($item['tax_rate']) && is_numeric($item['tax_rate'])
                ? max(0, (float)$item['tax_rate'])
                : $fallbackVatRate;
            if (isset($item['tax_exempt'])) {
                $normalizedTaxExempt = filter_var($item['tax_exempt'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalizedTaxExempt === true) {
                    $itemTaxRate = 0.0;
                }
            }
            $storedNetTotal = $this->numericOrNull($item['net_total'] ?? null);
            $storedTaxAmount = $this->numericOrNull($item['tax_amount'] ?? null);
            $originalBreakdown = $this->splitGrossAmountByTaxRate($grossLineTotal, $itemTaxRate);
            $discountedBreakdown = $this->splitGrossAmountByTaxRate($discountedGrossLine, $itemTaxRate);

            $lineSubtotalNet = $discountedBreakdown['net'];
            $taxAmount = $discountedBreakdown['tax'];
            if ($this->isCentConsistentStoredBreakdown($storedNetTotal, $storedTaxAmount, $discountedGrossLine)) {
                $lineSubtotalNet = round((float)$storedNetTotal, 2);
                $taxAmount = round((float)$storedTaxAmount, 2);
            }

            $originalNetLine = $originalBreakdown['net'];
            $originalNetUnitPrice = $quantity > 0 ? round($originalNetLine / $quantity, 6) : 0.0;
            $discountNetAmount = round(max(0, $originalNetLine - $lineSubtotalNet), 2);

            $items[] = [
                'code' => (string)($item['product_id'] ?? ('ITEM-' . ($index + 1))),
                'description' => (string)($item['product_name'] ?? 'Producto'),
                'quantity' => $this->facturadorDecimal($quantity, 6),
                'unit_price' => $this->facturadorDecimal($originalNetUnitPrice, 6),
                'discount' => $this->facturadorDecimal($discountNetAmount, 6),
                'line_subtotal_net' => $this->facturadorDecimal($lineSubtotalNet, 6),
                'tax_rate' => $this->facturadorDecimal($itemTaxRate, 2),
                'tax_code' => '2',
                'tax_percentage_code' => $this->resolveSriVatPercentageCode($itemTaxRate),
                'tax_amount' => $this->facturadorDecimal($taxAmount, 6),
            ];
        }

        $shipping = max(0, (float)($order['shipping'] ?? 0));
        if ($shipping > 0) {
            $shippingTaxRate = $this->resolveShippingTaxRate($order);
            $shippingBreakdown = $this->splitGrossAmountByTaxRate($shipping, $shippingTaxRate);
            $shippingBase = $shippingBreakdown['net'];
            $shippingTaxAmount = $shippingBreakdown['tax'];

            $items[] = [
                'code' => 'ENVIO',
                'description' => 'Servicio de envio',
                'quantity' => $this->facturadorDecimal(1, 6),
                'unit_price' => $this->facturadorDecimal($shippingBase, 6),
                'discount' => $this->facturadorDecimal(0, 6),
                'line_subtotal_net' => $this->facturadorDecimal($shippingBase, 6),
                'tax_rate' => $this->facturadorDecimal($shippingTaxRate, 2),
                'tax_code' => '2',
                'tax_percentage_code' => $this->resolveSriVatPercentageCode($shippingTaxRate),
                'tax_amount' => $this->facturadorDecimal($shippingTaxAmount, 6),
            ];
        }

        $paymentMethod = $this->resolveSriPaymentMethod((string)($order['payment_method'] ?? ''));
        $orderCreatedAt = trim((string)($order['created_at'] ?? ''));
        $accountingDate = $this->resolveOrderAccountingDate($orderCreatedAt);

        return [
            'customer_identification' => $customerIdentification,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_email' => (string)($customerAddressData['email'] ?? $order['user_email'] ?? ''),
            'payment_method' => $paymentMethod['label'],
            'payment_method_code' => $paymentMethod['code'],
            'items' => $items,
            'additional_info' => array_filter([
                'order_id' => $order['id'] ?? null,
                'tenant_id' => TenantContext::id(),
                'order_created_at' => $orderCreatedAt !== '' ? $orderCreatedAt : null,
                'accounting_date' => $accountingDate,
                'payment_method' => $paymentMethod['label'],
                'payment_method_code' => $paymentMethod['code'],
                'notes' => $order['order_notes'] ?? null,
                'original_customer_identification' => $customer['fallback_reason'] !== null ? $customer['original_identification'] : null,
                'identification_fallback_reason' => $customer['fallback_reason'],
            ], static fn($value) => $value !== null && $value !== ''),
        ];
    }

    private function assertFinalConsumerAllowedForOrder(array $order, float $orderTotal): void
    {
        $billingAddress = $this->decodeAddress($order['billing_address'] ?? null);
        $shippingAddress = $this->decodeAddress($order['shipping_address'] ?? null);
        $this->resolveFacturadorCustomer(
            $billingAddress ?: $shippingAddress,
            trim((string)($order['user_name'] ?? '')),
            $orderTotal
        );
    }

    private function assertFinalConsumerAllowedForOrderData(array $data, float $orderTotal): void
    {
        $billingAddress = $this->decodeAddress($data['billing_address'] ?? null);
        $shippingAddress = $this->decodeAddress($data['shipping_address'] ?? null);
        $this->resolveFacturadorCustomer($billingAddress ?: $shippingAddress, '', $orderTotal);
    }

    private function resolveFacturadorCustomer(array $customerAddressData, string $fallbackName, float $orderTotal): array
    {
        $customerName = trim(($customerAddressData['firstName'] ?? '') . ' ' . ($customerAddressData['lastName'] ?? ''));
        if ($customerName === '') {
            $customerName = trim((string)($customerAddressData['name'] ?? $fallbackName));
        }
        if ($customerName === '') {
            $customerName = 'CONSUMIDOR FINAL';
        }

        $rawIdentification = trim((string)($customerAddressData['documentNumber'] ?? $customerAddressData['document_number'] ?? ''));
        $normalizedIdentification = $this->digitsOnly($rawIdentification);
        $documentType = strtolower(trim((string)($customerAddressData['documentType'] ?? $customerAddressData['document_type'] ?? '')));
        $requestedFinalConsumer = in_array($documentType, ['consumidor_final', 'consumidor final', 'final_consumer'], true);
        $fallbackReason = null;
        $identification = $normalizedIdentification;

        if ($requestedFinalConsumer || $normalizedIdentification === '') {
            $identification = self::FINAL_CONSUMER_IDENTIFICATION;
            $fallbackReason = $normalizedIdentification === '' && !$requestedFinalConsumer
                ? 'missing_customer_identification'
                : null;
        } elseif ($normalizedIdentification === self::FINAL_CONSUMER_IDENTIFICATION) {
            $identification = self::FINAL_CONSUMER_IDENTIFICATION;
        } elseif (!$this->isValidFacturadorCustomerIdentification($normalizedIdentification)) {
            $identification = self::FINAL_CONSUMER_IDENTIFICATION;
            $fallbackReason = 'invalid_customer_identification';
        }

        $isFinalConsumer = $identification === self::FINAL_CONSUMER_IDENTIFICATION;
        if ($isFinalConsumer && $this->exceedsFinalConsumerLimit($orderTotal)) {
            throw new \DomainException(sprintf(
                'Ventas mayores a USD %.2f no pueden facturarse como consumidor final. Ingresa una cédula o RUC válido del cliente.',
                self::FINAL_CONSUMER_MAX_TOTAL
            ));
        }

        return [
            'identification' => $identification,
            'name' => $isFinalConsumer ? 'CONSUMIDOR FINAL' : $customerName,
            'original_identification' => $rawIdentification !== '' ? $rawIdentification : null,
            'fallback_reason' => $fallbackReason,
            'is_final_consumer' => $isFinalConsumer,
        ];
    }

    private function digitsOnly(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        return is_string($digits) ? $digits : '';
    }

    private function exceedsFinalConsumerLimit(float $amount): bool
    {
        return round($amount, 2) > self::FINAL_CONSUMER_MAX_TOTAL;
    }

    private function numericOrNull($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float)$value : null;
    }

    private function facturadorDecimal($value, int $decimals): string {
        $number = is_numeric($value) ? (float)$value : 0.0;
        if (abs($number) < 0.0000005) {
            $number = 0.0;
        }

        return number_format($number, $decimals, '.', '');
    }

    private function splitGrossAmountByTaxRate(float $grossAmount, float $taxRate): array {
        $grossAmount = round(max(0, $grossAmount), 2);
        $taxRate = max(0, $taxRate);
        if ($grossAmount <= 0 || $taxRate <= 0) {
            return [
                'gross' => $grossAmount,
                'net' => $grossAmount,
                'tax' => 0.0,
            ];
        }

        $divisor = 1 + ($taxRate / 100);
        $net = round($grossAmount / $divisor, 2);
        $tax = round($grossAmount - $net, 2);

        return [
            'gross' => $grossAmount,
            'net' => $net,
            'tax' => $tax,
        ];
    }

    private function isCentConsistentStoredBreakdown(?float $net, ?float $tax, float $gross): bool {
        if ($net === null || $tax === null) {
            return false;
        }

        $netCents = round($net, 2);
        $taxCents = round($tax, 2);
        if (abs($net - $netCents) > 0.000001 || abs($tax - $taxCents) > 0.000001) {
            return false;
        }

        return abs(round($netCents + $taxCents, 2) - round($gross, 2)) <= 0.000001;
    }

    private function resolveOrderAccountingDate($createdAt): ?string {
        $value = trim((string)$createdAt);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return $matches[0];
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function isValidFacturadorCustomerIdentification(string $identification): bool {
        $value = $this->digitsOnly($identification);
        if ($value === '') {
            return false;
        }

        if ($value === self::FINAL_CONSUMER_IDENTIFICATION) {
            return true;
        }

        if (strlen($value) === 10) {
            return $this->validateEcuadorCedula($value);
        }

        if (strlen($value) === 13) {
            return $this->validateEcuadorRuc($value);
        }

        return false;
    }

    private function validateEcuadorCedula(string $cedula): bool {
        if (strlen($cedula) !== 10 || !ctype_digit($cedula)) {
            return false;
        }

        if ($cedula === '1702527887') {
            return true;
        }

        $coefficients = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $product = ((int)$cedula[$i]) * $coefficients[$i];
            $sum += $product >= 10 ? $product - 9 : $product;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;
        return $checkDigit === (int)$cedula[9];
    }

    private function validateEcuadorRuc(string $ruc): bool {
        if (strlen($ruc) !== 13 || !ctype_digit($ruc)) {
            return false;
        }

        $type = (int)$ruc[2];
        if ($type < 6) {
            return $this->validateEcuadorCedula(substr($ruc, 0, 10));
        }

        if ($type === 6) {
            $coefficients = [3, 2, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < 8; $i++) {
                $sum += ((int)$ruc[$i]) * $coefficients[$i];
            }
            $checkDigit = 11 - ($sum % 11);
            if ($checkDigit === 11) $checkDigit = 0;
            if ($checkDigit === 10) return false;
            return $checkDigit === (int)$ruc[8];
        }

        if ($type === 9) {
            $coefficients = [4, 3, 2, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += ((int)$ruc[$i]) * $coefficients[$i];
            }
            $checkDigit = 11 - ($sum % 11);
            if ($checkDigit === 11) $checkDigit = 0;
            if ($checkDigit === 10) return false;
            return $checkDigit === (int)$ruc[9];
        }

        return false;
    }

    private function resolveShippingTaxRate(array $order): float {
        return isset($order['shipping_tax_rate']) && is_numeric($order['shipping_tax_rate'])
            ? max(0, (float)$order['shipping_tax_rate'])
            : 0.0;
    }

    private function resolveSriVatPercentageCode(float $taxRate): string
    {
        if ($taxRate <= 0) {
            return '0';
        }

        if (abs($taxRate - 15.0) < 0.0001) {
            return '4';
        }

        return '4';
    }

    private function allocateOrderDiscountAcrossItems(array $items, float $discountTotal): array {
        $itemCount = count($items);
        if ($itemCount === 0) {
            return [];
        }

        $normalizedDiscount = round(max(0, $discountTotal), 2);
        if ($normalizedDiscount <= 0) {
            return array_fill(0, $itemCount, 0.0);
        }

        $grossTotals = [];
        $grossSum = 0.0;
        foreach ($items as $item) {
            $lineGross = round((float)($item['price'] ?? 0) * max(1, (int)($item['quantity'] ?? 1)), 2);
            $grossTotals[] = $lineGross;
            $grossSum += $lineGross;
        }

        if ($grossSum <= 0) {
            return array_fill(0, $itemCount, 0.0);
        }

        $allocations = array_fill(0, $itemCount, 0.0);
        $allocated = 0.0;
        $lastIndex = $itemCount - 1;

        foreach ($grossTotals as $index => $grossLine) {
            if ($index === $lastIndex) {
                $allocations[$index] = round(min(max(0, $normalizedDiscount - $allocated), $grossLine), 2);
                continue;
            }

            $share = round(($normalizedDiscount * $grossLine) / $grossSum, 2);
            $share = min($share, $grossLine);
            $allocations[$index] = $share;
            $allocated += $share;
        }

        return $allocations;
    }

    private function translatePaymentMethod(string $method): string {
        return $this->resolveSriPaymentMethod($method)['label'];
    }

    private function resolveSriPaymentMethod(string $method): array {
        $value = strtolower(trim($method));
        if ($value === 'credit' || $value === 'card' || $value === 'credit_card') {
            return [
                'code' => '19',
                'label' => 'Tarjeta de credito',
            ];
        }
        if ($value === 'transfer' || $value === 'bank_transfer') {
            return [
                'code' => '20',
                'label' => 'Otros con utilizacion del sistema financiero',
            ];
        }
        if ($value === 'cash' || $value === 'cod') {
            return [
                'code' => '01',
                'label' => 'Sin utilizacion del sistema financiero',
            ];
        }

        if (preg_match('/^\d{2}$/', $method) === 1) {
            return [
                'code' => $method,
                'label' => $method,
            ];
        }

        return [
            'code' => '20',
            'label' => $method !== '' ? $method : 'Otros con utilizacion del sistema financiero',
        ];
    }

    private function hasMeaningfulDiscountValue($value) {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return abs((float)$value) > 0;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        return true;
    }

    private function rejectUnexpectedDiscountFields(array $data) {
        $unexpected = [];
        foreach ($this->forbiddenDiscountPayloadKeys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if ($this->hasMeaningfulDiscountValue($data[$key])) {
                $unexpected[] = $key;
            }
        }
        if (count($unexpected) === 0) {
            return false;
        }

        Response::error(
            'Descuento rechazado. Solo se aceptan descuentos registrados y trazables. Campos no permitidos: ' . implode(', ', $unexpected),
            400,
            'ORDER_DISCOUNT_UNREGISTERED'
        );
        return true;
    }

    private function hasMeaningfulMoneyValue($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_numeric($value)) {
            return abs((float)$value) > 0.00001;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        return trim((string)$value) !== '';
    }

    private function getRequestChannel(array $data): string
    {
        $paymentDetails = $data['payment_details'] ?? null;
        if (is_string($paymentDetails)) {
            $decoded = json_decode($paymentDetails, true);
            $paymentDetails = is_array($decoded) ? $decoded : null;
        }

        return strtolower(trim((string)($paymentDetails['channel'] ?? '')));
    }

    private function isTrustedLocalPosRequest(array $data, ?array $user = null): bool
    {
        $role = strtolower(trim((string)($user['role'] ?? '')));
        if ($role !== 'admin') {
            return false;
        }

        return $this->getRequestChannel($data) === 'local_pos';
    }

    private function resolveInitialOrderStatus(array $data, ?array $user = null): string
    {
        if ($this->isTrustedLocalPosRequest($data, $user)) {
            return 'completed';
        }

        return 'pending';
    }

    private function pricingTamperLockMinutes(): int
    {
        $configured = (int)($_ENV['AUTH_PRICING_TAMPER_LOCK_MINUTES'] ?? 0);
        if ($configured > 0) {
            return max(15, $configured);
        }

        $baseLoginLock = (int)($_ENV['AUTH_LOGIN_LOCK_MINUTES'] ?? 15);
        return max(60, $baseLoginLock * 4);
    }

    private function getClientIpAddress(): ?string
    {
        if (function_exists('get_client_ip')) {
            return \get_client_ip();
        }

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || trim($ip) === '') {
            return null;
        }

        $first = explode(',', $ip)[0] ?? null;
        return $first ? trim($first) : null;
    }

    private function blockUserForPricingTamper(?array $user, string $reasonCode, array $unexpectedFields): bool
    {
        $userId = trim((string)($user['sub'] ?? ''));
        if ($userId === '') {
            return false;
        }

        $lockedUntil = date('Y-m-d H:i:s', time() + ($this->pricingTamperLockMinutes() * 60));
        $this->userRepository->setLoginFailureState($userId, 999, $lockedUntil);
        $this->userRepository->clearActiveTokenId($userId);

        try {
            $this->authSecurityRepository->recordEvent(
                'order_pricing_tamper',
                'blocked',
                $userId,
                isset($user['email']) ? (string)$user['email'] : null,
                $this->getClientIpAddress(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                [
                    'reason_code' => $reasonCode,
                    'blocked_until' => $lockedUntil,
                    'fields' => array_values($unexpectedFields),
                    'path' => $_SERVER['REQUEST_URI'] ?? null,
                ]
            );
        } catch (\Throwable $eventError) {
            error_log('[ORDER_PRICING_TAMPER_EVENT_FAILED] ' . $eventError->getMessage());
        }

        return true;
    }

    private function respondToPricingTamper(?array $user, array $unexpectedFields, string $message, string $code): bool
    {
        $blocked = $this->blockUserForPricingTamper($user, $code, $unexpectedFields);
        $finalMessage = $message;
        if ($blocked) {
            $finalMessage .= ' Detectamos manipulación del pedido y bloqueamos temporalmente tu cuenta por seguridad.';
        }

        Response::error($finalMessage, 403, $code);
        return true;
    }

    private function rejectUnexpectedMoneyFields(array $data, ?array $user = null): bool
    {
        if ($this->isTrustedLocalPosRequest($data, $user)) {
            return false;
        }

        $unexpected = [];
        foreach ($this->forbiddenMoneyPayloadKeys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if ($this->hasMeaningfulMoneyValue($data[$key])) {
                $unexpected[] = $key;
            }
        }

        if (count($unexpected) === 0) {
            return false;
        }

        return $this->respondToPricingTamper(
            $user,
            $unexpected,
            'Montos rechazados. El servidor calcula precios, IVA, envío y descuentos. Campos no permitidos: ' . implode(', ', $unexpected),
            'ORDER_PRICING_FIELDS_FORBIDDEN'
        );
    }

    private function rejectUnexpectedItemPricingFields(array $data, ?array $user = null): bool
    {
        if ($this->isTrustedLocalPosRequest($data, $user)) {
            return false;
        }

        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            return false;
        }

        $unexpected = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($this->forbiddenItemPricingKeys as $key) {
                if (!array_key_exists($key, $item)) {
                    continue;
                }
                if ($this->hasMeaningfulMoneyValue($item[$key])) {
                    $unexpected[] = "items[$index].$key";
                }
            }
        }

        if (count($unexpected) === 0) {
            return false;
        }

        return $this->respondToPricingTamper(
            $user,
            $unexpected,
            'Líneas de pedido rechazadas. El cliente no puede definir montos por producto. Campos no permitidos: ' . implode(', ', $unexpected),
            'ORDER_ITEM_PRICING_FIELDS_FORBIDDEN'
        );
    }

    private function normalizeDiscountCodeValue($value) {
        if ($value === null) return null;
        $normalized = strtoupper(trim((string)$value));
        if ($normalized === '') return null;
        return preg_replace('/\s+/', '', $normalized);
    }

    private function extractDiscountCode(array $data, ?bool &$hasError = null) {
        $hasError = false;
        $couponCode = $this->normalizeDiscountCodeValue($data['coupon_code'] ?? null);
        $discountCode = $this->normalizeDiscountCodeValue($data['discount_code'] ?? null);

        if ($couponCode && $discountCode && $couponCode !== $discountCode) {
            Response::error(
                'Se enviaron dos códigos de descuento distintos. Usa solo uno.',
                400,
                'ORDER_DISCOUNT_CODE_CONFLICT'
            );
            $hasError = true;
            return null;
        }

        return $couponCode ?: $discountCode;
    }

    private function isStoreSalesEnabled() {
        $settings = new SettingsRepository();
        $enabledRaw = $settings->get('store_sales_enabled');
        $messageRaw = $settings->get('store_sales_message');
        $salesEnabled = $enabledRaw === null ? true : in_array(strtolower(trim((string)$enabledRaw)), ['1', 'true', 'yes', 'y', 'on'], true);
        $message = trim((string)($messageRaw ?? 'Tienda temporalmente en mantenimiento. Intenta más tarde.'));
        if ($message === '') {
            $message = 'Tienda temporalmente en mantenimiento. Intenta más tarde.';
        }
        return ['enabled' => $salesEnabled, 'message' => $message];
    }

    private function enforceSalesEnabled() {
        $status = $this->isStoreSalesEnabled();
        if ($status['enabled']) {
            return true;
        }
        Response::error($status['message'], 503, 'STORE_SALES_DISABLED');
        return false;
    }

    public function quote() {
        try {
            $user = $this->authenticateOptional();
            $isAdmin = (($user['role'] ?? 'customer') === 'admin');
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            if ($this->rejectUnexpectedDiscountFields($data)) {
                return;
            }
            if ($this->rejectUnexpectedMoneyFields($data, $user) || $this->rejectUnexpectedItemPricingFields($data, $user)) {
                return;
            }
            if (!$isAdmin && !$this->enforceSalesEnabled()) {
                return;
            }
            $hasDiscountCodeError = false;
            $discountCode = $this->extractDiscountCode($data, $hasDiscountCodeError);
            if ($hasDiscountCodeError) {
                return;
            }
            if (!isset($data['items'])) {
                throw new \Exception("Items required");
            }
            $quote = $this->orderRepository->calculateQuote(
                $data['items'],
                $data['delivery_method'] ?? 'delivery',
                $discountCode,
                'quote',
                null,
                null,
                [
                    'shipping_address' => is_array($data['shipping_address'] ?? null) ? $data['shipping_address'] : null,
                ]
            );
            Response::json($quote);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'ORDER_QUOTE_FAILED');
        }
    }

    public function store() {
        $user = $this->authenticate();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            if ($this->rejectUnexpectedDiscountFields($data)) {
                return;
            }
            if ($this->rejectUnexpectedMoneyFields($data, $user) || $this->rejectUnexpectedItemPricingFields($data, $user)) {
                return;
            }
            if (!$this->enforceSalesEnabled()) {
                return;
            }
            $hasDiscountCodeError = false;
            $discountCode = $this->extractDiscountCode($data, $hasDiscountCodeError);
            if ($hasDiscountCodeError) {
                return;
            }
            $data['coupon_code'] = $discountCode;
            if (($user['role'] ?? 'customer') === 'guest' || empty($user['sub'])) {
                Response::error('Debes iniciar sesión para comprar', 403, 'GUEST_PURCHASE_DISABLED');
                return;
            }
            $data['status'] = $this->resolveInitialOrderStatus($data, $user);
            $data['id'] = 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
            if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
                throw new \Exception('Items required');
            }
            $validationQuote = $this->orderRepository->calculateQuote(
                $data['items'],
                $data['delivery_method'] ?? 'delivery',
                $discountCode,
                'quote',
                null,
                $user['sub'] ?? null,
                [
                    'shipping_address' => is_array($data['shipping_address'] ?? null) ? $data['shipping_address'] : null,
                    'allow_missing_shipping_location' => $this->getRequestChannel($data) === 'local_pos',
                ]
            );
            $this->assertFinalConsumerAllowedForOrderData($data, (float)($validationQuote['total'] ?? 0));
            $data['user_id'] = $this->resolveCustomerUserId($data, $user);

            $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
            if (!$baseUrl) {
                $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $proto . '://' . $host;
            }
            $order = $this->orderRepository->create($data, $baseUrl);

            Response::json($order, 201);
            $this->dispatchFacturadorInvoiceAfterResponse($order ?: []);
        } catch (\DomainException $e) {
            Response::error($e->getMessage(), 409, 'FINAL_CONSUMER_LIMIT_EXCEEDED');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'ORDER_CREATE_FAILED');
        }
    }

    public function storeHistoricalSale() {
        $user = $this->authenticate();
        if (($user['role'] ?? '') !== 'admin') {
            Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
            return;
        }

        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $saleDate = trim((string)($data['sale_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
                Response::error('Fecha histórica inválida. Usa YYYY-MM-DD.', 400, 'HISTORICAL_SALE_DATE_INVALID');
                return;
            }
            if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
                Response::error('Agrega al menos un producto a la venta histórica.', 400, 'HISTORICAL_SALE_ITEMS_REQUIRED');
                return;
            }

            $paymentDetails = is_array($data['payment_details'] ?? null) ? $data['payment_details'] : [];
            $affectInventory = filter_var($data['affect_inventory'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $reference = trim((string)($data['reference'] ?? ($paymentDetails['reference'] ?? '')));
            $notes = trim((string)($data['order_notes'] ?? $data['notes'] ?? ''));
            $paymentDetails = array_merge($paymentDetails, [
                'channel' => 'historical_import',
                'sale_date' => $saleDate,
                'reference' => $reference !== '' ? $reference : null,
                'created_by_user_id' => $user['sub'] ?? null,
                'no_inventory_impact' => !$affectInventory,
                'sri_invoice' => 'not_emitted_historical_import',
            ]);

            $customerName = trim((string)($data['customer_name'] ?? ''));
            $customerDocument = trim((string)($data['customer_document'] ?? ''));
            $customerEmail = trim((string)($data['customer_email'] ?? ''));
            $customerPhone = trim((string)($data['customer_phone'] ?? ''));
            $customerAddress = [
                'firstName' => $customerName !== '' ? $customerName : 'Cliente',
                'lastName' => 'histórico',
                'name' => $customerName !== '' ? $customerName : 'Cliente histórico',
                'phone' => $customerPhone !== '' ? $customerPhone : null,
                'email' => $customerEmail !== '' ? $customerEmail : null,
                'street' => trim((string)($data['customer_address'] ?? '')) ?: 'Venta histórica',
                'city' => trim((string)($data['customer_city'] ?? '')) ?: null,
                'state' => null,
                'country' => 'EC',
                'zip' => null,
                'documentType' => trim((string)($data['customer_document_type'] ?? 'cedula')) ?: 'cedula',
                'documentNumber' => $customerDocument !== '' ? $customerDocument : null,
            ];

            $payload = [
                'id' => 'HIST-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4))),
                'historical_sale_date' => $saleDate,
                'skip_inventory_impact' => !$affectInventory,
                'allow_historical_pricing' => true,
                'user_id' => null,
                'status' => 'completed',
                'delivery_method' => 'pickup',
                'payment_method' => trim((string)($data['payment_method'] ?? 'historical')),
                'shipping_address' => $customerAddress,
                'billing_address' => $customerAddress,
                'payment_details' => $paymentDetails,
                'order_notes' => $notes !== '' ? $notes : 'Carga histórica de venta',
                'items' => $data['items'],
            ];
            $payload['user_id'] = $this->resolveCustomerUserId($payload, $user);

            $baseUrl = TenantContext::appUrl() ?? ($_ENV['APP_URL'] ?? null);
            $order = $this->orderRepository->create($payload, $baseUrl);
            Response::json($order, 201);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'HISTORICAL_SALE_CREATE_FAILED');
        }
    }
}
