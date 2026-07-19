<?php

namespace App\Repositories;

use App\Modules\Commerce\Application\TenantDefaultTaxRate;
use App\Modules\Commerce\Domain\ProductTaxPolicy;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\Commerce\Application\OrderListSummary;
use App\Modules\Commerce\Domain\CommerceDomain;
use App\Modules\Commerce\Infrastructure\CommerceBillingOutboxRepository;
use App\Repositories\FinancialPeriodRepository;
use PDO;

class OrderRepository {
    private const DEFAULT_SHIPPING_STORE_ADDRESS = 'Av. de la Prensa y Juan Paz y Miño, 170104 Quito';
    private const DEFAULT_SHIPPING_STORE_LATITUDE = -0.148306;
    private const DEFAULT_SHIPPING_STORE_LONGITUDE = -78.490870;
    private const PREVIOUS_SHIPPING_STORE_LATITUDE = -0.12231;
    private const PREVIOUS_SHIPPING_STORE_LONGITUDE = -78.49375;
    private const LEGACY_SHIPPING_STORE_LATITUDE = -0.117371;
    private const LEGACY_SHIPPING_STORE_LONGITUDE = -78.494256;

    private $db;
    private $discountRepository;
    private $taxRateCache = null;
    private $shippingConfigCache = null;
    private $shippingTaxRateCache = null;

    public function __construct() {
        $this->db = Database::getModuleInstance(CommerceDomain::KEY);
        $this->discountRepository = new DiscountRepository($this->db);
    }

    /**
     * @param array{limit?:int,cursor?:array{createdAt:string,id:string}|null} $options
     * @return array{items:array<int,array<string,mixed>>,nextPosition:array{createdAt:string,id:string}|null}
     */
    public function getPageResult(array $options = []): array {
        return $this->getOrderSummaryPage(null, $options);
    }

    /**
     * @deprecated HTTP list handlers must call getPageResult() explicitly.
     */
    public function getPage(array $options = []) {
        return $this->getPageResult($options)['items'];
    }

    /**
     * @deprecated HTTP list handlers must call getPageResult() explicitly.
     */
    public function getAll(array $options = []) {
        return $this->getPage($options);
    }

    /**
     * @param array{limit?:int,cursor?:array{createdAt:string,id:string}|null} $options
     * @return array{items:array<int,array<string,mixed>>,nextPosition:array{createdAt:string,id:string}|null}
     */
    public function getByUserIdPage($userId, array $options = []): array {
        return $this->getOrderSummaryPage((string)$userId, $options);
    }

    /**
     * Consulta unica para ambos canales de listado. Solo emite el DTO
     * order-summary-v1; los campos grandes y las lineas se consultan por id.
     *
     * @param array{limit?:int,cursor?:array{createdAt:string,id:string}|null} $options
     * @return array{items:array<int,array<string,mixed>>,nextPosition:array{createdAt:string,id:string}|null}
     */
    private function getOrderSummaryPage(?string $userId, array $options): array {
        $limit = filter_var($options['limit'] ?? 100, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => OrderListSummary::MAX_PAGE_SIZE],
        ]);
        $safeLimit = $limit === false ? OrderListSummary::MAX_PAGE_SIZE : (int)$limit;
        $cursor = is_array($options['cursor'] ?? null) ? $options['cursor'] : null;
        $cursorFilter = $cursor !== null
            ? ' AND (o.created_at, o.id) < (:cursor_created_at, :cursor_id)'
            : '';
        $userFilter = $userId !== null ? ' AND o.user_id = :user_id' : '';

        $stmt = $this->db->prepare('
            SELECT
                o.id,
                o.user_id,
                o.total,
                LEFT(o.status, 32) AS status,
                o.created_at,
                LEFT(o.delivery_method, 32) AS delivery_method,
                LEFT(o.payment_method, 64) AS payment_method,
                LEFT(COALESCE(NULLIF(BTRIM(o.customer_name), \'\'), NULLIF(BTRIM(u.name), \'\')), 80) AS customer_name,
                LEFT(COALESCE(NULLIF(BTRIM(o.customer_email), \'\'), NULLIF(BTRIM(u.email), \'\')), 254) AS customer_email,
                LEFT(o.customer_phone, 40) AS customer_phone,
                LEFT(o.customer_document_type, 32) AS customer_document_type,
                LEFT(o.customer_document_number, 64) AS customer_document_number,
                LEFT(o.customer_company, 80) AS customer_company,
                LEFT(o.sales_channel, 32) AS sales_channel,
                COALESCE(item_totals.items_count, 0) AS items_count,
                COALESCE(item_totals.units_count, 0) AS units_count,
                COALESCE(item_totals.mixed_vat_rates, false) AS mixed_vat_rates,
                COALESCE(o.items_subtotal, 0) AS items_subtotal,
                COALESCE(o.vat_subtotal, 0) AS vat_subtotal,
                COALESCE(o.vat_rate, 0) AS vat_rate,
                COALESCE(o.vat_amount, 0) AS vat_amount,
                COALESCE(o.shipping, 0) AS shipping,
                COALESCE(o.shipping_base, o.shipping, 0) AS shipping_base,
                COALESCE(o.shipping_tax_rate, 0) AS shipping_tax_rate,
                COALESCE(o.shipping_tax_amount, 0) AS shipping_tax_amount,
                LEFT(o.discount_code, 80) AS discount_code,
                COALESCE(o.discount_total, 0) AS discount_total,
                LEFT(o.invoice_number, 80) AS invoice_number,
                o.invoice_created_at
            FROM "Order" o
            LEFT JOIN "Customer" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*)::bigint AS items_count,
                    COALESCE(SUM(GREATEST(oi.quantity, 0)), 0)::bigint AS units_count,
                    COUNT(DISTINCT COALESCE(oi.tax_rate, o.vat_rate, 0))
                        FILTER (WHERE GREATEST(oi.quantity, 0) > 0) > 1 AS mixed_vat_rates
                FROM "OrderItem" oi
                WHERE oi.order_id = o.id
                  AND oi.tenant_id = o.tenant_id
            ) item_totals ON true
            WHERE o.tenant_id = :tenant_id
            ' . $userFilter . '
            ' . $cursorFilter . '
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':tenant_id', $this->getTenantId(), PDO::PARAM_STR);
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        }
        if ($cursor !== null) {
            $stmt->bindValue(':cursor_created_at', (string)$cursor['createdAt'], PDO::PARAM_STR);
            $stmt->bindValue(':cursor_id', (string)$cursor['id'], PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $safeLimit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll() ?: [];
        $hasMore = count($orders) > $safeLimit;
        if ($hasMore) {
            $orders = array_slice($orders, 0, $safeLimit);
        }

        $orders = OrderListSummary::projectRows($orders);

        $last = $orders !== [] ? $orders[array_key_last($orders)] : null;
        return [
            'items' => $orders,
            'nextPosition' => $hasMore && $last !== null ? [
                'createdAt' => (string)$last['created_at'],
                'id' => (string)$last['id'],
            ] : null,
        ];
    }

    /**
     * @deprecated HTTP list handlers must call getByUserIdPage().
     */
    public function getByUserId($userId) {
        return $this->getByUserIdPage($userId)['items'];
    }

    public function getById($id) {
        $stmt = $this->db->prepare('
            SELECT o.*, u.name as user_name, u.email as user_email
            FROM "Order" o
            LEFT JOIN "Customer" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.id = :id AND o.tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        $order = $stmt->fetch();

        if ($order) {
            $stmtItems = $this->db->prepare('
                SELECT oi.*
                FROM "OrderItem" oi
                JOIN "Order" o ON oi.order_id = o.id AND oi.tenant_id = o.tenant_id
                WHERE oi.order_id = :order_id
                  AND o.tenant_id = :tenant_id
            ');
            $stmtItems->execute([
                'order_id' => $id,
                'tenant_id' => $this->getTenantId()
            ]);
            $order['items'] = $stmtItems->fetchAll();
            $order = $this->addTaxBreakdown($order);
        }

        return $order;
    }

    public function updateStatus($id, $status) {
        $tenantId = $this->getTenantId();
        $this->db->beginTransaction();
        try {
            $inventoryLots = new InventoryLotRepository($this->db);
            $stmtCurrent = $this->db->prepare('SELECT status, payment_details, (created_at AT TIME ZONE \'America/Guayaquil\')::date AS financial_date FROM "Order" WHERE id = :id AND tenant_id = :tenant_id FOR UPDATE');
            $stmtCurrent->execute([
                'id' => $id,
                'tenant_id' => $tenantId
            ]);
            $current = $stmtCurrent->fetch();
            if (!$current) {
                $this->db->rollBack();
                return null;
            }

            $currentStatus = strtolower(trim((string)($current['status'] ?? 'pending')));
            $nextStatus = strtolower(trim((string)$status));
            (new FinancialPeriodRepository($this->db))->assertDateOpen((string)($current['financial_date'] ?? date('Y-m-d')), 'pedido');
            $skipInventoryImpact = $this->orderSkipsInventoryImpact($current['payment_details'] ?? null);
            $currentActive = !$skipInventoryImpact && $this->orderAffectsInventory($currentStatus);
            $nextActive = !$skipInventoryImpact && $this->orderAffectsInventory($nextStatus);

            if ($currentActive !== $nextActive) {
                $stmtItems = $this->db->prepare('SELECT id, product_id, quantity, unit_cost, cost_total FROM "OrderItem" WHERE tenant_id = :tenant_id AND order_id = :order_id');
                $stmtItems->execute(['tenant_id' => $tenantId, 'order_id' => $id]);
                $items = $stmtItems->fetchAll();
                $stmtLockProduct = $this->db->prepare('
                    SELECT quantity, cost
                    FROM "Product"
                    WHERE id = :id AND tenant_id = :tenant_id
                    LIMIT 1
                    FOR UPDATE
                ');
                $stmtUpdateOrderItemCost = $this->db->prepare('
                    UPDATE "OrderItem"
                    SET unit_cost = :unit_cost,
                        cost_total = :cost_total
                    WHERE id = :id
                      AND tenant_id = :tenant_id
                ');

                if ($currentActive && !$nextActive) {
                    $stmtRestore = $this->db->prepare('UPDATE "Product" SET quantity = quantity + :qty, sold = GREATEST(0, sold - :qty) WHERE id = :id AND tenant_id = :tenant_id');
                    foreach ($items as $item) {
                        $qty = max(0, (int)($item['quantity'] ?? 0));
                        if ($qty <= 0) {
                            continue;
                        }
                        $restore = $inventoryLots->restoreForOrderItem((string)$item['id']);
                        $restoredQty = max(0, (int)($restore['restored_quantity'] ?? 0));
                        if ($restoredQty < $qty) {
                            $inventoryLots->recordStockIncrease(
                                (string)$item['product_id'],
                                $qty - $restoredQty,
                                round((float)($item['unit_cost'] ?? 0), 4),
                                'order_cancel_restore',
                                (string)$id,
                                ['order_item_id' => (string)$item['id'], 'fallback' => true]
                            );
                        }
                        $stmtRestore->execute([
                            'qty' => $qty,
                            'id' => $item['product_id'],
                            'tenant_id' => $tenantId
                        ]);
                    }
                } else {
                    $stmtConsume = $this->db->prepare('UPDATE "Product" SET quantity = quantity - :qty, sold = sold + :qty WHERE id = :id AND tenant_id = :tenant_id AND quantity >= :qty');
                    foreach ($items as $item) {
                        $qty = max(0, (int)($item['quantity'] ?? 0));
                        if ($qty <= 0) {
                            continue;
                        }
                        $stmtLockProduct->execute([
                            'id' => $item['product_id'],
                            'tenant_id' => $tenantId
                        ]);
                        $productRow = $stmtLockProduct->fetch();
                        if (!$productRow) {
                            throw new \Exception('Producto no encontrado para reactivar el pedido');
                        }

                        $allocation = $inventoryLots->consumeForOrderItem(
                            (string)$item['product_id'],
                            (string)$item['id'],
                            $qty,
                            max(0, (int)($productRow['quantity'] ?? 0)),
                            round((float)($productRow['cost'] ?? 0), 4),
                            ['order_id' => (string)$id, 'reason' => 'order_status_reactivate']
                        );
                        $stmtUpdateOrderItemCost->execute([
                            'id' => (string)$item['id'],
                            'tenant_id' => $tenantId,
                            'unit_cost' => round((float)($allocation['unit_cost'] ?? 0), 4),
                            'cost_total' => round((float)($allocation['cost_total'] ?? 0), 4)
                        ]);

                        $stmtConsume->execute([
                            'qty' => $qty,
                            'id' => $item['product_id'],
                            'tenant_id' => $tenantId
                        ]);
                        if ($stmtConsume->rowCount() !== 1) {
                            throw new \Exception('Stock insuficiente para reactivar el pedido');
                        }
                    }
                }
            }

            $stmt = $this->db->prepare('UPDATE "Order" SET status = :status WHERE id = :id AND tenant_id = :tenant_id');
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $tenantId,
                'status' => $nextStatus
            ]);
            $this->enqueueBillingCommandIfFinal($tenantId, (string)$id, $nextStatus, 'status_transition');
            $this->db->commit();
            return $this->getById($id);
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function ensureInvoiceForOrder($order, $baseUrl = null, $force = false) {
        if (!$order) return null;
        if (!empty($order['invoice_html']) && !$force) {
            return $order['invoice_html'];
        }

        $orderId = $order['id'];
        $invoiceNumber = ($order['invoice_number'] ?? null) ?: $this->buildInvoiceNumber($orderId);

        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $subtotal = isset($order['items_subtotal']) ? (float)$order['items_subtotal'] : 0;
        if ($subtotal <= 0) {
            foreach ($items as $item) {
                $lineNet = isset($item['net_total']) && is_numeric($item['net_total']) ? (float)$item['net_total'] : null;
                $lineTax = isset($item['tax_amount']) && is_numeric($item['tax_amount']) ? (float)$item['tax_amount'] : null;
                if ($lineNet !== null && $lineTax !== null) {
                    $subtotal += $lineNet + $lineTax;
                    continue;
                }
                $subtotal += (float)$item['price'] * (int)$item['quantity'];
            }
        }
        $orderTotal = (float)($order['total'] ?? $subtotal);
        $shippingFromOrder = isset($order['shipping']) ? (float)$order['shipping'] : ($orderTotal - $subtotal);
        if ($shippingFromOrder < 0) {
            $shippingFromOrder = 0;
        }

        $quote = [
            'items' => array_map(function ($item) {
                return [
                    'product_name' => $item['product_name'] ?? null,
                    'price' => (float)($item['price'] ?? 0),
                    'price_net' => isset($item['price_net']) ? (float)$item['price_net'] : null,
                    'net_total' => isset($item['net_total']) ? (float)$item['net_total'] : null,
                    'tax_rate' => isset($item['tax_rate']) ? (float)$item['tax_rate'] : null,
                    'tax_amount' => isset($item['tax_amount']) ? (float)$item['tax_amount'] : null,
                    'quantity' => (int)($item['quantity'] ?? 1),
                    'product_image' => $item['product_image'] ?? null
                ];
            }, $items),
            'subtotal' => $subtotal,
            'shipping' => $shippingFromOrder,
            'shipping_base' => isset($order['shipping_base']) ? (float)$order['shipping_base'] : null,
            'shipping_tax_rate' => isset($order['shipping_tax_rate']) ? (float)$order['shipping_tax_rate'] : null,
            'shipping_tax_amount' => isset($order['shipping_tax_amount']) ? (float)$order['shipping_tax_amount'] : null,
            'total' => $orderTotal,
            'vat_rate' => isset($order['vat_rate']) ? (float)$order['vat_rate'] : $this->getTaxRate(),
            'vat_subtotal' => isset($order['vat_subtotal']) ? (float)$order['vat_subtotal'] : null,
            'vat_amount' => isset($order['vat_amount']) ? (float)$order['vat_amount'] : null,
            'mixed_vat_rates' => (bool)($order['mixed_vat_rates'] ?? false),
            'discount_code' => $order['discount_code'] ?? null,
            'discount_total' => isset($order['discount_total']) ? (float)$order['discount_total'] : 0
        ];

        $billing = $this->decodeJsonField($order['billing_address'] ?? null);
        if (!$billing) {
            $billing = $this->getUserDefaultBilling($order['user_id'] ?? null);
        }

        $shipping = $this->decodeJsonField($order['shipping_address'] ?? null);
        $customerName = trim(($shipping['firstName'] ?? '') . ' ' . ($shipping['lastName'] ?? ''));
        if (!$customerName) {
            $customerName = trim(($billing['firstName'] ?? '') . ' ' . ($billing['lastName'] ?? ''));
        }
        if (!$customerName) {
            $customerName = $order['user_name'] ?? null;
        }
        if (!$customerName) {
            $stmtUser = $this->db->prepare('SELECT name FROM "Customer" WHERE id = :id AND tenant_id = :tenant_id');
            $stmtUser->execute([
                'id' => $order['user_id'] ?? null,
                'tenant_id' => $this->getTenantId()
            ]);
            $userRow = $stmtUser->fetch();
            if ($userRow && !empty($userRow['name'])) {
                $customerName = $userRow['name'];
            }
        }

        $customerEmail = $shipping['email'] ?? $billing['email'] ?? null;
        $customerPhone = $shipping['phone'] ?? $billing['phone'] ?? null;

        $paymentLabel = $this->translatePaymentMethod($order['payment_method'] ?? null);

        $invoiceData = [
            'customer' => [
                'name' => $customerName ?: null,
                'email' => $customerEmail,
                'phone' => $customerPhone
            ],
            'billing_address' => $billing,
            'shipping_address' => [
                'line1' => 'Local Para Mascotas EC',
                'line2' => 'Retiro en tienda'
            ],
            'company' => $billing['company'] ?? null,
            'payment_method' => $paymentLabel,
            'items' => $quote['items']
        ];

        $invoiceHtml = $this->renderInvoiceHtml($invoiceNumber, [
            'billing_address' => $billing,
            'shipping_address' => $shipping,
            'payment_method' => $order['payment_method'] ?? null
        ], $quote, $invoiceData, $baseUrl);

        $stmt = $this->db->prepare('UPDATE "Order" SET invoice_number = :invoice_number, invoice_html = :invoice_html, invoice_created_at = NOW(), invoice_data = :invoice_data WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $orderId,
            'tenant_id' => $this->getTenantId(),
            'invoice_number' => $invoiceNumber,
            'invoice_html' => $invoiceHtml,
            'invoice_data' => json_encode($invoiceData)
        ]);

        return $invoiceHtml;
    }

    public function calculateQuote($items, $deliveryMethod = 'delivery', $discountCode = null, $context = 'quote', $orderId = null, $userId = null, array $options = []) {
        if (!is_array($items) || count($items) === 0) {
            throw new \Exception('Items required');
        }
        $deliveryMethod = strtolower(trim((string)$deliveryMethod));
        $bypassStockCheck = (bool)($options['bypass_stock_check'] ?? false);
        $bypassLotAllocation = (bool)($options['bypass_lot_allocation'] ?? false);
        $allowPriceOverrides = (bool)($options['allow_price_overrides'] ?? false);
        $itemsGrossSubtotal = 0;
        $itemsCostSubtotal = 0;
        $itemsWithDetails = [];
        $itemGrossLineTotals = [];
        $taxRatesSeen = [];
        $inventoryLots = new InventoryLotRepository($this->db);

        foreach ($items as $item) {
            $productId = trim((string)($item['product_id'] ?? ''));
            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;
            if ($productId === '' || $quantity <= 0) {
                throw new \Exception('Item inválido');
            }

            $stmt = $this->db->prepare('
                SELECT p.id, p.legacy_id, p.price, p.cost, p.name, p.quantity, p.attributes,
                       (SELECT url FROM "Image" WHERE tenant_id = p.tenant_id AND product_id = p.id ORDER BY id LIMIT 1) as image
                FROM "Product" p
                WHERE (p.id = :id OR p.legacy_id = :id) AND p.tenant_id = :tenant_id
            ');
            $stmt->execute([
                'id' => $productId,
                'tenant_id' => $this->getTenantId()
            ]);
            $product = $stmt->fetch();

            if (!$product) {
                throw new \Exception('Producto no encontrado: ' . $productId);
            }

            $availableQty = (int)($product['quantity'] ?? 0);
            if (!$bypassStockCheck && $availableQty < $quantity) {
                throw new \Exception('Stock insuficiente para: ' . $product['name']);
            }

            $attributes = [];
            if (!empty($product['attributes'])) {
                $decoded = json_decode((string)$product['attributes'], true);
                if (is_array($decoded)) {
                    $attributes = $decoded;
                }
            }
            $itemTaxRate = $this->getProductTaxRateForAttributes($attributes);
            $itemTaxExempt = ProductTaxPolicy::isExempt($attributes);
            $itemTaxMultiplier = 1 + ($itemTaxRate / 100);
            $expirationDateRaw = trim((string)($attributes['expirationDate'] ?? $attributes['expiryDate'] ?? ''));
            if ($expirationDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDateRaw) === 1) {
                $expirationDate = \DateTimeImmutable::createFromFormat('Y-m-d', $expirationDateRaw);
                if ($expirationDate instanceof \DateTimeImmutable) {
                    $today = new \DateTimeImmutable('today');
                    if ($expirationDate < $today) {
                        throw new \Exception('Producto vencido: ' . $product['name']);
                    }
                }
            }

            $baseUnitPrice = round((float)($product['price'] ?? 0), 4);
            $priceWithTax = round($baseUnitPrice * $itemTaxMultiplier, 2);
            if ($allowPriceOverrides) {
                $overrideGross = $item['unit_price_gross'] ?? $item['price_gross'] ?? $item['price'] ?? null;
                if ($overrideGross !== null && $overrideGross !== '' && is_numeric($overrideGross)) {
                    $priceWithTax = round(max(0, (float)$overrideGross), 2);
                    $baseUnitPrice = $itemTaxMultiplier > 0 ? round($priceWithTax / $itemTaxMultiplier, 4) : $priceWithTax;
                }
                $overrideTaxRate = $item['tax_rate'] ?? null;
                if (!$itemTaxExempt
                    && $overrideTaxRate !== null
                    && $overrideTaxRate !== ''
                    && is_numeric($overrideTaxRate)) {
                    $itemTaxRate = ProductTaxPolicy::normalizeRate($overrideTaxRate) ?? $itemTaxRate;
                    $itemTaxMultiplier = 1 + ($itemTaxRate / 100);
                    $baseUnitPrice = $itemTaxMultiplier > 0 ? round($priceWithTax / $itemTaxMultiplier, 4) : $priceWithTax;
                }
            }
            $lineTotal = round($priceWithTax * $quantity, 2);
            $fallbackUnitCost = round((float)($product['cost'] ?? 0), 4);
            if ($allowPriceOverrides) {
                $overrideUnitCost = $item['unit_cost'] ?? $item['cost'] ?? null;
                if ($overrideUnitCost !== null && $overrideUnitCost !== '' && is_numeric($overrideUnitCost)) {
                    $fallbackUnitCost = round(max(0, (float)$overrideUnitCost), 4);
                }
            }
            $costAllocation = $bypassLotAllocation
                ? [
                    'unit_cost' => $fallbackUnitCost,
                    'cost_total' => round($fallbackUnitCost * $quantity, 4),
                    'allocations' => [],
                ]
                : $inventoryLots->previewSaleAllocation(
                    (string)$product['id'],
                    $quantity,
                    $availableQty,
                    $fallbackUnitCost
                );
            $itemsGrossSubtotal += $lineTotal;
            $itemsCostSubtotal += round((float)($costAllocation['cost_total'] ?? 0), 4);
            $itemGrossLineTotals[] = $lineTotal;
            $taxRatesSeen[(string)round($itemTaxRate, 2)] = true;

            $itemsWithDetails[] = [
                'product_id' => $product['id'],
                'product_name' => $product['name'],
                'product_image' => $item['product_image'] ?? $product['image'],
                'quantity' => $quantity,
                'unit_cost' => round((float)($costAllocation['unit_cost'] ?? 0), 4),
                'cost_total' => round((float)($costAllocation['cost_total'] ?? 0), 4),
                'price' => $priceWithTax,
                'price_net' => $baseUnitPrice,
                'tax_rate' => round($itemTaxRate, 2),
                'tax_exempt' => $itemTaxExempt,
                'tax_treatment' => $itemTaxExempt
                    ? ProductTaxPolicy::TREATMENT_EXEMPT
                    : ($itemTaxRate === 0.0
                        ? ProductTaxPolicy::TREATMENT_ZERO_RATED
                        : ProductTaxPolicy::TREATMENT_TAXED),
                'total' => $lineTotal
            ];
        }

        $distinctTaxRates = array_map('floatval', array_keys($taxRatesSeen));
        sort($distinctTaxRates);
        $mixedVatRates = count($distinctTaxRates) > 1;
        $displayVatRate = count($distinctTaxRates) === 1
            ? (float)$distinctTaxRates[0]
            : (count($distinctTaxRates) > 0 ? max($distinctTaxRates) : 0.0);

        $discountPricingContext = [
            'items_cost_total' => round($itemsCostSubtotal, 2),
            'tax_rate' => round($displayVatRate, 2)
        ];
        $discountResult = ($context === 'order')
            ? $this->discountRepository->reserveForOrder($discountCode, $itemsGrossSubtotal, (string)$orderId, $userId, $discountPricingContext)
            : $this->discountRepository->evaluateForQuote($discountCode, $itemsGrossSubtotal, $userId, $discountPricingContext);
        $discountTotal = max(0, (float)($discountResult['discount_total'] ?? 0));
        $discountAllocations = $this->allocateDiscountAcrossLineTotals($itemGrossLineTotals, $discountTotal);

        $itemsNetSubtotal = 0.0;
        $vatSubtotalBeforeDiscount = 0.0;
        $vatAmountBeforeDiscount = 0.0;
        $vatSubtotal = 0.0;
        $vatAmount = 0.0;
        foreach ($itemsWithDetails as $index => &$item) {
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            $itemTaxRate = round((float)($item['tax_rate'] ?? 0), 2);
            $grossLineTotal = round((float)($itemGrossLineTotals[$index] ?? 0), 2);
            $allocatedDiscount = round((float)($discountAllocations[$index] ?? 0), 2);
            $discountedGrossLine = round(max(0, $grossLineTotal - $allocatedDiscount), 2);
            $taxBreakdownBeforeDiscount = $this->splitGrossAmountByTaxRate($grossLineTotal, $itemTaxRate);
            $taxBreakdown = $this->splitGrossAmountByTaxRate($discountedGrossLine, $itemTaxRate);
            $discountedNetLine = $taxBreakdown['net'];
            $discountedTaxAmount = $taxBreakdown['tax'];

            $item['discount_total'] = $allocatedDiscount;
            $item['price_net'] = round($quantity > 0 ? ($discountedNetLine / $quantity) : 0, 4);
            $item['net_total'] = $discountedNetLine;
            $item['tax_amount'] = $discountedTaxAmount;
            $item['total'] = $discountedGrossLine;

            $itemsNetSubtotal += $discountedGrossLine;
            $vatSubtotalBeforeDiscount += $taxBreakdownBeforeDiscount['net'];
            $vatAmountBeforeDiscount += $taxBreakdownBeforeDiscount['tax'];
            $vatSubtotal += $discountedNetLine;
            $vatAmount += $discountedTaxAmount;
        }
        unset($item);

        $shippingPricing = $this->resolveShippingPricing(
            $deliveryMethod,
            is_array($options['shipping_address'] ?? null) ? $options['shipping_address'] : null,
            (bool)($options['allow_missing_shipping_location'] ?? false)
        );
        $shippingBase = $shippingPricing['shipping_base'];
        $shippingTaxRate = $this->getShippingTaxRate();
        $shippingTaxAmount = $shippingTaxRate > 0 ? ($shippingBase * ($shippingTaxRate / 100)) : 0;
        $shippingTotal = $shippingBase + $shippingTaxAmount;
        $total = $itemsNetSubtotal + $shippingTotal;

        return [
            'subtotal' => round($itemsNetSubtotal, 2),
            'items_subtotal_before_discount' => round($itemsGrossSubtotal, 2),
            'vat_rate' => round($displayVatRate, 2),
            'vat_subtotal_before_discount' => round($vatSubtotalBeforeDiscount, 2),
            'vat_amount_before_discount' => round($vatAmountBeforeDiscount, 2),
            'vat_subtotal' => round($vatSubtotal, 2),
            'vat_amount' => round($vatAmount, 2),
            'mixed_vat_rates' => $mixedVatRates,
            'shipping' => round($shippingTotal, 2),
            'shipping_base' => round($shippingBase, 2),
            'shipping_tax_rate' => round($shippingTaxRate, 2),
            'shipping_tax_amount' => round($shippingTaxAmount, 2),
            'distance_km' => $shippingPricing['distance_km'],
            'shipping_rule' => $shippingPricing['shipping_rule'],
            'is_free_shipping' => $shippingPricing['is_free_shipping'],
            'store_address' => $shippingPricing['store_address'],
            'discount_code' => $discountResult['discount_code'] ?? null,
            'discount_total' => round($discountTotal, 2),
            'discounts_applied' => $discountResult['discounts_applied'] ?? [],
            'discount_rejections' => $discountResult['discount_rejections'] ?? [],
            'total' => round($total, 2),
            'items' => $itemsWithDetails
        ];
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

    private function getShippingConfig(): array {
        if ($this->shippingConfigCache !== null) {
            return $this->shippingConfigCache;
        }

        $settings = new \App\Repositories\SettingsRepository();
        $delivery = $settings->get('shipping_delivery');
        $pickup = $settings->get('shipping_pickup');
        $taxRate = $settings->get('shipping_tax_rate');
        $storeAddress = $settings->get('shipping_store_address');
        $storeLatitude = $settings->get('shipping_store_latitude');
        $storeLongitude = $settings->get('shipping_store_longitude');
        $freeRadius = $settings->get('free_shipping_radius_km');
        $kmFlatRateLimit = $settings->get('shipping_km_flat_rate_limit');
        $perKmRate = $settings->get('shipping_per_km_rate');

        $config = [
            'delivery' => is_numeric($delivery) ? floatval($delivery) : 5.0,
            'pickup' => is_numeric($pickup) ? floatval($pickup) : 0.0,
            'tax_rate' => is_numeric($taxRate) ? floatval($taxRate) : $this->getTaxRate(),
            'store_address' => $this->normalizeShippingStoreAddress(is_string($storeAddress) ? $storeAddress : null),
            'store_latitude' => is_numeric($storeLatitude) ? floatval($storeLatitude) : self::DEFAULT_SHIPPING_STORE_LATITUDE,
            'store_longitude' => is_numeric($storeLongitude) ? floatval($storeLongitude) : self::DEFAULT_SHIPPING_STORE_LONGITUDE,
            'free_shipping_radius_km' => is_numeric($freeRadius) ? max(0, floatval($freeRadius)) : 5.0,
            'shipping_km_flat_rate_limit' => is_numeric($kmFlatRateLimit) ? max(0, floatval($kmFlatRateLimit)) : 7.0,
            'shipping_per_km_rate' => is_numeric($perKmRate) ? max(0, floatval($perKmRate)) : 1.0,
        ];

        $migratedLegacyStore = $this->isLegacyShippingStoreLocation(
            (string)$config['store_address'],
            (float)$config['store_latitude'],
            (float)$config['store_longitude'],
        );
        if ($migratedLegacyStore) {
            $config['store_address'] = self::DEFAULT_SHIPPING_STORE_ADDRESS;
            $config['store_latitude'] = self::DEFAULT_SHIPPING_STORE_LATITUDE;
            $config['store_longitude'] = self::DEFAULT_SHIPPING_STORE_LONGITUDE;
        }

        if ($delivery === null) {
            $settings->set('shipping_delivery', (string)$config['delivery']);
        }
        if ($pickup === null) {
            $settings->set('shipping_pickup', (string)$config['pickup']);
        }
        if ($taxRate === null) {
            $settings->set('shipping_tax_rate', (string)$config['tax_rate']);
        }
        if ($storeAddress === null || $migratedLegacyStore) {
            $settings->set('shipping_store_address', $config['store_address']);
        }
        if ($storeLatitude === null || $migratedLegacyStore) {
            $settings->set('shipping_store_latitude', (string)$config['store_latitude']);
        }
        if ($storeLongitude === null || $migratedLegacyStore) {
            $settings->set('shipping_store_longitude', (string)$config['store_longitude']);
        }
        if ($freeRadius === null) {
            $settings->set('free_shipping_radius_km', (string)$config['free_shipping_radius_km']);
        }
        if ($kmFlatRateLimit === null) {
            $settings->set('shipping_km_flat_rate_limit', (string)$config['shipping_km_flat_rate_limit']);
        }
        if ($perKmRate === null) {
            $settings->set('shipping_per_km_rate', (string)$config['shipping_per_km_rate']);
        }

        $this->shippingConfigCache = $config;
        return $this->shippingConfigCache;
    }

    private function getShippingRate($deliveryMethod) {
        $config = $this->getShippingConfig();
        $method = $deliveryMethod === 'pickup' ? 'pickup' : 'delivery';
        return (float)($config[$method] ?? 0);
    }

    private function normalizeShippingStoreAddress(?string $value): string {
        $address = trim((string)($value ?? ''));
        return $address !== '' ? $address : self::DEFAULT_SHIPPING_STORE_ADDRESS;
    }

    private function normalizeShippingStoreKey(?string $value): string {
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string)($value ?? '')), 'UTF-8')
            : strtolower(trim((string)($value ?? '')));
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized ?? '');
        return trim((string)$normalized);
    }

    private function isLegacyShippingStoreLocation(string $address, float $latitude, float $longitude): bool {
        $matchesLegacyPoint = (
            abs($latitude - self::LEGACY_SHIPPING_STORE_LATITUDE) < 0.00001
            && abs($longitude - self::LEGACY_SHIPPING_STORE_LONGITUDE) < 0.00001
        ) || (
            abs($latitude - self::PREVIOUS_SHIPPING_STORE_LATITUDE) < 0.00001
            && abs($longitude - self::PREVIOUS_SHIPPING_STORE_LONGITUDE) < 0.00001
        );
        if (!$matchesLegacyPoint) {
            return false;
        }

        $normalizedAddress = $this->normalizeShippingStoreKey($address);
        $defaultAddress = $this->normalizeShippingStoreKey(self::DEFAULT_SHIPPING_STORE_ADDRESS);
        return $normalizedAddress === '' || $normalizedAddress === $defaultAddress;
    }

    private function getShippingTaxRate() {
        if ($this->shippingTaxRateCache !== null) {
            return $this->shippingTaxRateCache;
        }
        $config = $this->getShippingConfig();
        $rate = max(0, (float)($config['tax_rate'] ?? $this->getTaxRate()));
        $this->shippingTaxRateCache = $rate;
        return $rate;
    }

    private function normalizeCoordinate($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private function normalizeCheckoutAddress(array $address): array {
        $normalized = [
            'firstName' => trim((string)($address['firstName'] ?? $address['first_name'] ?? '')),
            'lastName' => trim((string)($address['lastName'] ?? $address['last_name'] ?? '')),
            'name' => trim((string)($address['name'] ?? '')),
            'email' => trim((string)($address['email'] ?? '')),
            'phone' => trim((string)($address['phone'] ?? '')),
            'documentType' => trim((string)($address['documentType'] ?? $address['document_type'] ?? '')),
            'documentNumber' => trim((string)($address['documentNumber'] ?? $address['document_number'] ?? '')),
            'company' => trim((string)($address['company'] ?? $address['businessName'] ?? $address['business_name'] ?? '')),
            'country' => $this->normalizeCountryName($address['country'] ?? null),
            'street' => trim((string)($address['street'] ?? $address['address'] ?? '')),
            'city' => trim((string)($address['city'] ?? '')),
            'state' => trim((string)($address['state'] ?? $address['province'] ?? '')),
            'zip' => trim((string)($address['zip'] ?? $address['postalCode'] ?? $address['postal_code'] ?? '')),
            'formattedAddress' => trim((string)($address['formattedAddress'] ?? $address['formatted_address'] ?? '')),
            'placeId' => trim((string)($address['placeId'] ?? $address['place_id'] ?? '')),
            'latitude' => $this->normalizeCoordinate($address['latitude'] ?? $address['lat'] ?? null),
            'longitude' => $this->normalizeCoordinate($address['longitude'] ?? $address['lng'] ?? null),
        ];

        return $normalized;
    }

    private function calculateDistanceKm(float $startLatitude, float $startLongitude, float $endLatitude, float $endLongitude): float {
        $earthRadiusKm = 6371.0;
        $deltaLatitude = deg2rad($endLatitude - $startLatitude);
        $deltaLongitude = deg2rad($endLongitude - $startLongitude);

        $a = sin($deltaLatitude / 2) ** 2
            + cos(deg2rad($startLatitude)) * cos(deg2rad($endLatitude)) * sin($deltaLongitude / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    private function resolveShippingPricing(string $deliveryMethod, ?array $shippingAddress, bool $allowMissingLocation = false): array {
        $config = $this->getShippingConfig();
        $storeAddress = (string)$config['store_address'];
        $storeLatitude = $this->normalizeCoordinate($config['store_latitude'] ?? null);
        $storeLongitude = $this->normalizeCoordinate($config['store_longitude'] ?? null);
        $freeRadiusKm = max(0, (float)($config['free_shipping_radius_km'] ?? 0));
        $flatRateLimitKm = max(0, (float)($config['shipping_km_flat_rate_limit'] ?? 0));
        $perKmRate = max(0, (float)($config['shipping_per_km_rate'] ?? 0));
        $flatRate = (float)($config['delivery'] ?? 0);

        if ($deliveryMethod === 'pickup') {
            return [
                'shipping_base' => (float)($config['pickup'] ?? 0),
                'distance_km' => null,
                'shipping_rule' => 'pickup',
                'is_free_shipping' => (float)($config['pickup'] ?? 0) <= 0,
                'store_address' => $storeAddress,
            ];
        }

        if (
            $storeLatitude === null || $storeLongitude === null
            || $storeLatitude < -90 || $storeLatitude > 90
            || $storeLongitude < -180 || $storeLongitude > 180
        ) {
            throw new \Exception('Configura la ubicación base del local antes de cotizar envíos a domicilio.');
        }

        $normalizedAddress = $this->normalizeCheckoutAddress($shippingAddress ?? []);
        if (($normalizedAddress['country'] ?? 'Ecuador') !== 'Ecuador') {
            throw new \Exception('Solo realizamos envíos dentro de Ecuador.');
        }

        $latitude = $this->normalizeCoordinate($normalizedAddress['latitude'] ?? null);
        $longitude = $this->normalizeCoordinate($normalizedAddress['longitude'] ?? null);
        if ($latitude === null || $longitude === null) {
            if (!$allowMissingLocation) {
                throw new \Exception('Selecciona tu ubicación exacta en el mapa para calcular el envío.');
            }

            return [
                'shipping_base' => $flatRate,
                'distance_km' => null,
                'shipping_rule' => 'standard_delivery',
                'is_free_shipping' => false,
                'store_address' => $storeAddress,
            ];
        }
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \Exception('La ubicación seleccionada en el mapa es inválida.');
        }

        $distanceKm = round($this->calculateDistanceKm($storeLatitude, $storeLongitude, $latitude, $longitude), 2);

        if ($distanceKm <= $freeRadiusKm) {
            $shippingBase = 0.0;
            $shippingRule = 'free_radius';
            $isFreeShipping = true;
        } elseif ($distanceKm <= $flatRateLimitKm || $perKmRate <= 0) {
            $shippingBase = $flatRate;
            $shippingRule = 'km_flat_rate';
            $isFreeShipping = false;
        } else {
            $extraKm = $distanceKm - $flatRateLimitKm;
            $shippingBase = $flatRate + ($extraKm * $perKmRate);
            $shippingRule = 'km_per_km_rate';
            $isFreeShipping = false;
        }

        $normalizedAddress['distanceKm'] = $distanceKm;
        $normalizedAddress['shippingZone'] = $shippingRule;
        $normalizedAddress['shippingRule'] = $shippingRule;
        $normalizedAddress['isFreeShipping'] = $isFreeShipping;
        $normalizedAddress['storeAddress'] = $storeAddress;
        $normalizedAddress['storeLatitude'] = $storeLatitude;
        $normalizedAddress['storeLongitude'] = $storeLongitude;
        $normalizedAddress['freeShippingRadiusKm'] = $freeRadiusKm;
        $normalizedAddress['flatRateLimitKm'] = $flatRateLimitKm;
        $normalizedAddress['perKmRate'] = $perKmRate;

        return [
            'shipping_base' => round($shippingBase, 2),
            'distance_km' => $distanceKm,
            'shipping_rule' => $shippingRule,
            'is_free_shipping' => $isFreeShipping,
            'store_address' => $storeAddress,
            'shipping_address' => $normalizedAddress,
        ];
    }

    private function getTaxRate() {
        if ($this->taxRateCache !== null) {
            return $this->taxRateCache;
        }
        $this->taxRateCache = TenantDefaultTaxRate::current();
        return $this->taxRateCache;
    }

    private function normalizeProductAttributes($value): array {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function normalizeBooleanAttribute($value, bool $default = false): bool {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int)$value) === 1;
        }
        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') {
            return $default;
        }
        if (in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on', 'si', 'sí'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'f', 'no', 'n', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private function isProductTaxExempt(array $attributes): bool {
        return ProductTaxPolicy::isExempt($attributes);
    }

    private function getProductTaxRateForAttributes(array $attributes): float {
        return ProductTaxPolicy::rate($attributes, $this->getTaxRate());
    }

    private function getProductTaxMultiplierForAttributes(array $attributes): float {
        return 1 + ($this->getProductTaxRateForAttributes($attributes) / 100);
    }

    private function allocateDiscountAcrossLineTotals(array $lineTotals, float $discountTotal): array {
        $lineCount = count($lineTotals);
        if ($lineCount === 0) {
            return [];
        }

        $normalizedDiscount = round(max(0, $discountTotal), 2);
        if ($normalizedDiscount <= 0) {
            return array_fill(0, $lineCount, 0.0);
        }

        $normalizedLineTotals = array_map(
            static fn($total) => round(max(0, (float)$total), 2),
            $lineTotals
        );
        $grossTotal = array_sum($normalizedLineTotals);
        if ($grossTotal <= 0) {
            return array_fill(0, $lineCount, 0.0);
        }

        $allocations = array_fill(0, $lineCount, 0.0);
        $remainingDiscount = $normalizedDiscount;
        $remainingGross = $grossTotal;
        foreach ($normalizedLineTotals as $index => $lineTotal) {
            if ($lineTotal <= 0 || $remainingDiscount <= 0 || $remainingGross <= 0) {
                continue;
            }
            if ($index === $lineCount - 1) {
                $allocation = min($lineTotal, $remainingDiscount);
            } else {
                $share = $lineTotal / $remainingGross;
                $allocation = round($normalizedDiscount * $share, 2);
                $allocation = min($lineTotal, min($remainingDiscount, $allocation));
            }

            $allocations[$index] = $allocation;
            $remainingDiscount = round($remainingDiscount - $allocation, 2);
            $remainingGross = round($remainingGross - $lineTotal, 2);
        }

        if ($remainingDiscount > 0) {
            for ($index = $lineCount - 1; $index >= 0; $index--) {
                $capacity = round($normalizedLineTotals[$index] - $allocations[$index], 2);
                if ($capacity <= 0) {
                    continue;
                }
                $extra = min($capacity, $remainingDiscount);
                $allocations[$index] = round($allocations[$index] + $extra, 2);
                $remainingDiscount = round($remainingDiscount - $extra, 2);
                if ($remainingDiscount <= 0) {
                    break;
                }
            }
        }

        return $allocations;
    }

    private function normalizeCountryName($value) {
        $normalized = mb_strtolower(trim((string)$value), 'UTF-8');
        if ($normalized === '' || $normalized === 'ecuador' || $normalized === 'ec' || $normalized === 'república del ecuador' || $normalized === 'republica del ecuador') {
            return 'Ecuador';
        }
        return trim((string)$value);
    }

    /**
     * Conserva un snapshot escalar de contacto para listados y auditoria.
     * Los documentos JSON completos siguen reservados para el detalle.
     *
     * @return array<string,?string>
     */
    private function resolveCustomerSnapshot(array $data): array {
        $shipping = is_array($data['shipping_address'] ?? null) ? $data['shipping_address'] : [];
        $billing = is_array($data['billing_address'] ?? null) ? $data['billing_address'] : [];
        $customer = [];
        $customerId = trim((string)($data['customer_id'] ?? $data['user_id'] ?? ''));
        if ($customerId !== '') {
            $stmt = $this->db->prepare('
                SELECT name, email, document_type, document_number, business_name, profile
                FROM "Customer"
                WHERE tenant_id = :tenant_id
                  AND id = :customer_id
                LIMIT 1
            ');
            $stmt->execute([
                'tenant_id' => $this->getTenantId(),
                'customer_id' => $customerId,
            ]);
            $customer = $stmt->fetch() ?: [];
        }
        $profile = $customer['profile'] ?? null;
        if (is_string($profile)) {
            $decoded = json_decode($profile, true);
            $profile = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($profile)) {
            $profile = [];
        }

        return [
            'customer_name' => $this->firstSnapshotText([
                $data['customer_name'] ?? null,
                $shipping['name'] ?? null,
                $this->addressSnapshotName($shipping),
                $billing['name'] ?? null,
                $this->addressSnapshotName($billing),
                $customer['name'] ?? null,
            ], 160),
            'customer_email' => $this->firstSnapshotText([
                $data['customer_email'] ?? null,
                $shipping['email'] ?? null,
                $billing['email'] ?? null,
                $customer['email'] ?? null,
            ], 254),
            'customer_phone' => $this->firstSnapshotText([
                $data['customer_phone'] ?? null,
                $shipping['phone'] ?? null,
                $billing['phone'] ?? null,
                $profile['phone'] ?? null,
                $profile['mobile'] ?? null,
            ], 40),
            'customer_document_type' => $this->firstSnapshotText([
                $data['customer_document_type'] ?? null,
                $billing['documentType'] ?? $billing['document_type'] ?? null,
                $shipping['documentType'] ?? $shipping['document_type'] ?? null,
                $customer['document_type'] ?? null,
            ], 32),
            'customer_document_number' => $this->firstSnapshotText([
                $data['customer_document_number'] ?? $data['customer_document'] ?? null,
                $billing['documentNumber'] ?? $billing['document_number'] ?? null,
                $shipping['documentNumber'] ?? $shipping['document_number'] ?? null,
                $customer['document_number'] ?? null,
            ], 64),
            'customer_company' => $this->firstSnapshotText([
                $data['customer_company'] ?? null,
                $billing['company'] ?? $billing['businessName'] ?? $billing['business_name'] ?? null,
                $shipping['company'] ?? $shipping['businessName'] ?? $shipping['business_name'] ?? null,
                $customer['business_name'] ?? null,
            ], 160),
            'sales_channel' => $this->firstSnapshotText([
                is_array($data['payment_details'] ?? null) ? ($data['payment_details']['channel'] ?? null) : null,
                $data['request_channel'] ?? null,
            ], 32),
        ];
    }

    private function addressSnapshotName(array $address): ?string {
        $firstName = trim((string)($address['firstName'] ?? $address['first_name'] ?? ''));
        $lastName = trim((string)($address['lastName'] ?? $address['last_name'] ?? ''));
        $name = trim($firstName . ' ' . $lastName);
        return $name !== '' ? $name : null;
    }

    /** @param array<int,mixed> $values */
    private function firstSnapshotText(array $values, int $maxCharacters): ?string {
        foreach ($values as $value) {
            $normalized = trim((string)($value ?? ''));
            if ($normalized === '') {
                continue;
            }
            return function_exists('mb_substr')
                ? mb_substr($normalized, 0, $maxCharacters, 'UTF-8')
                : substr($normalized, 0, $maxCharacters);
        }
        return null;
    }

    public function create($data, $baseUrl = null) {
        $this->db->beginTransaction();
        try {
            $inventoryLots = new InventoryLotRepository($this->db);
            $paymentDetails = $data['payment_details'] ?? null;
            if (is_string($paymentDetails)) {
                $decoded = json_decode($paymentDetails, true);
                $paymentDetails = is_array($decoded) ? $decoded : null;
            }
            if (is_array($paymentDetails)) {
                $data['payment_details'] = $paymentDetails;
            }
            $channel = strtolower(trim((string)($paymentDetails['channel'] ?? '')));
            $isHistoricalImport = $channel === 'historical_import';
            $skipInventoryImpact = filter_var($data['skip_inventory_impact'] ?? ($paymentDetails['no_inventory_impact'] ?? false), FILTER_VALIDATE_BOOLEAN);
            $createdAtExpression = 'NOW()';
            $createdAtParam = null;
            if ($isHistoricalImport) {
                $saleDate = trim((string)($data['historical_sale_date'] ?? $paymentDetails['sale_date'] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
                    throw new \Exception('Fecha histórica inválida.');
                }
                (new FinancialPeriodRepository($this->db))->assertDateOpen($saleDate, 'venta histórica');
                $createdAtExpression = ':created_at';
                $createdAtParam = $saleDate . ' 12:00:00';
                $paymentDetails['channel'] = 'historical_import';
                $paymentDetails['sale_date'] = $saleDate;
                $paymentDetails['no_inventory_impact'] = $skipInventoryImpact;
                $data['payment_details'] = $paymentDetails;
            }
            if ($channel === 'local_pos') {
                $posRepository = new PosRepository();
                $activeShift = $posRepository->getActiveShift();
                if (!$activeShift) {
                    throw new \Exception('No hay una caja abierta para registrar ventas en local.');
                }
                $requestedShiftId = trim((string)($paymentDetails['shift_id'] ?? ''));
                if ($requestedShiftId !== '' && $requestedShiftId !== (string)$activeShift['id']) {
                    throw new \Exception('La venta local no corresponde al turno de caja activo.');
                }
                $paymentDetails['shift_id'] = (string)$activeShift['id'];
                $data['payment_details'] = $paymentDetails;
            }

            $deliveryMethod = strtolower(trim((string)($data['delivery_method'] ?? 'delivery')));
            if ($deliveryMethod === 'delivery') {
                $shippingAddress = isset($data['shipping_address']) && is_array($data['shipping_address'])
                    ? $data['shipping_address']
                    : [];
                $shippingAddress = $this->normalizeCheckoutAddress($shippingAddress);
                $shippingCountry = $this->normalizeCountryName($shippingAddress['country'] ?? null);
                if ($shippingCountry !== 'Ecuador') {
                    throw new \Exception('Solo realizamos envíos dentro de Ecuador.');
                }
                $shippingAddress['country'] = 'Ecuador';
                $data['shipping_address'] = $shippingAddress;
            }

            if (isset($data['billing_address']) && is_array($data['billing_address'])) {
                $billingAddress = $this->normalizeCheckoutAddress($data['billing_address']);
                $billingCountry = $this->normalizeCountryName($billingAddress['country'] ?? null);
                if ($billingCountry === 'Ecuador' || $billingCountry === '') {
                    $billingAddress['country'] = 'Ecuador';
                }
                $data['billing_address'] = $billingAddress;
            }
            $customerSnapshot = $this->resolveCustomerSnapshot($data);

            // Inteligencia de Negocio: El Backend recalcula y valida TODO.
            $quote = $this->calculateQuote(
                $data['items'],
                $deliveryMethod,
                $data['coupon_code'] ?? ($data['discount_code'] ?? null),
                'order',
                $data['id'],
                $data['user_id'] ?? null,
                [
                    'bypass_stock_check' => $isHistoricalImport && $skipInventoryImpact,
                    'bypass_lot_allocation' => $isHistoricalImport && $skipInventoryImpact,
                    'allow_price_overrides' => $isHistoricalImport && filter_var($data['allow_historical_pricing'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'shipping_address' => is_array($data['shipping_address'] ?? null) ? $data['shipping_address'] : null,
                    'allow_missing_shipping_location' => $channel === 'local_pos' || $isHistoricalImport,
                ]
            );
            if ($deliveryMethod === 'delivery' && isset($data['shipping_address']) && is_array($data['shipping_address'])) {
                $data['shipping_address'] = array_merge($data['shipping_address'], [
                    'distanceKm' => $quote['distance_km'] ?? null,
                    'shippingZone' => $quote['shipping_rule'] ?? 'standard_delivery',
                    'shippingRule' => $quote['shipping_rule'] ?? 'standard_delivery',
                    'isFreeShipping' => (bool)($quote['is_free_shipping'] ?? false),
                    'storeAddress' => $quote['store_address'] ?? null,
                    'storeLatitude' => $this->getShippingConfig()['store_latitude'] ?? null,
                    'storeLongitude' => $this->getShippingConfig()['store_longitude'] ?? null,
                    'freeShippingRadiusKm' => $this->getShippingConfig()['free_shipping_radius_km'] ?? null,
                ]);
            }
            $orderStatus = strtolower(trim((string)($data['status'] ?? 'pending')));

            $stmt = $this->db->prepare('INSERT INTO "Order" ("id", "tenant_id", "user_id", "customer_id", "customer_name", "customer_email", "customer_phone", "customer_document_type", "customer_document_number", "customer_company", "sales_channel", "customer_snapshot_version", "total", "status", "created_at", "shipping_address", "billing_address", "payment_method", "delivery_method", "payment_details", "items_subtotal", "vat_subtotal", "vat_rate", "vat_amount", "shipping", "shipping_base", "shipping_tax_rate", "shipping_tax_amount", "discount_code", "discount_total", "discount_snapshot", "order_notes") VALUES (:id, :tenant_id, :user_id, :customer_id, :customer_name, :customer_email, :customer_phone, :customer_document_type, :customer_document_number, :customer_company, :sales_channel, 1, :total, :status, ' . $createdAtExpression . ', :shipping_address, :billing_address, :payment_method, :delivery_method, :payment_details, :items_subtotal, :vat_subtotal, :vat_rate, :vat_amount, :shipping, :shipping_base, :shipping_tax_rate, :shipping_tax_amount, :discount_code, :discount_total, :discount_snapshot, :order_notes)');

            $orderParams = [
                'id' => $data['id'],
                'tenant_id' => $this->getTenantId(),
                'user_id' => $data['user_id'],
                'customer_id' => $data['customer_id'] ?? $data['user_id'],
                'customer_name' => $customerSnapshot['customer_name'],
                'customer_email' => $customerSnapshot['customer_email'],
                'customer_phone' => $customerSnapshot['customer_phone'],
                'customer_document_type' => $customerSnapshot['customer_document_type'],
                'customer_document_number' => $customerSnapshot['customer_document_number'],
                'customer_company' => $customerSnapshot['customer_company'],
                'sales_channel' => $customerSnapshot['sales_channel'],
                'total' => $quote['total'],
                'status' => $orderStatus,
                'shipping_address' => json_encode($data['shipping_address'] ?? null),
                'billing_address' => json_encode($data['billing_address'] ?? null),
                'payment_method' => $data['payment_method'] ?? null,
                'delivery_method' => $deliveryMethod,
                'payment_details' => isset($data['payment_details']) ? json_encode($data['payment_details']) : null,
                'items_subtotal' => $quote['subtotal'],
                'vat_subtotal' => $quote['vat_subtotal'],
                'vat_rate' => $quote['vat_rate'],
                'vat_amount' => $quote['vat_amount'],
                'shipping' => $quote['shipping'],
                'shipping_base' => $quote['shipping_base'] ?? $quote['shipping'],
                'shipping_tax_rate' => $quote['shipping_tax_rate'] ?? 0,
                'shipping_tax_amount' => $quote['shipping_tax_amount'] ?? 0,
                'discount_code' => $quote['discount_code'] ?? null,
                'discount_total' => $quote['discount_total'] ?? 0,
                'discount_snapshot' => isset($quote['discounts_applied']) ? json_encode($quote['discounts_applied']) : null,
                'order_notes' => $data['order_notes'] ?? null
            ];
            if ($createdAtParam !== null) {
                $orderParams['created_at'] = $createdAtParam;
            }
            $stmt->execute($orderParams);

            $stmtItem = $this->db->prepare('INSERT INTO "OrderItem" ("id", "tenant_id", "order_id", "product_id", "product_name", "product_image", "quantity", "price", "price_net", "net_total", "tax_rate", "tax_amount", "unit_cost", "cost_total") VALUES (:id, :tenant_id, :order_id, :product_id, :product_name, :product_image, :quantity, :price, :price_net, :net_total, :tax_rate, :tax_amount, :unit_cost, :cost_total)');
            $stmtUpdateStock = $this->db->prepare('UPDATE "Product" SET quantity = quantity - :qty, sold = sold + :qty WHERE id = :id AND tenant_id = :tenant_id AND quantity >= :qty');
            $stmtLockProduct = $this->db->prepare('
                SELECT quantity, cost
                FROM "Product"
                WHERE id = :id AND tenant_id = :tenant_id
                LIMIT 1
                FOR UPDATE
            ');
            $stmtUpdateOrderItemCost = $this->db->prepare('
                UPDATE "OrderItem"
                SET unit_cost = :unit_cost,
                    cost_total = :cost_total
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ');

            foreach ($quote['items'] as $item) {
                $orderItemId = uniqid('item_');
                $previewUnitCost = round((float)($item['unit_cost'] ?? 0), 4);
                $previewCostTotal = round((float)($item['cost_total'] ?? ($previewUnitCost * (int)($item['quantity'] ?? 0))), 4);
                $stmtItem->execute([
                    'id' => $orderItemId,
                    'tenant_id' => $this->getTenantId(),
                    'order_id' => $data['id'],
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_image' => $item['product_image'] ?? null,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'price_net' => round((float)($item['price_net'] ?? 0), 4),
                    'net_total' => round((float)($item['net_total'] ?? 0), 4),
                    'tax_rate' => round((float)($item['tax_rate'] ?? 0), 2),
                    'tax_amount' => round((float)($item['tax_amount'] ?? 0), 4),
                    'unit_cost' => $previewUnitCost,
                    'cost_total' => $previewCostTotal
                ]);

                if (!$skipInventoryImpact && $this->orderAffectsInventory($orderStatus)) {
                    $stmtLockProduct->execute([
                        'id' => $item['product_id'],
                        'tenant_id' => $this->getTenantId()
                    ]);
                    $productRow = $stmtLockProduct->fetch();
                    if (!$productRow) {
                        throw new \Exception('Producto no encontrado durante la reserva de inventario.');
                    }

                    $allocation = $inventoryLots->consumeForOrderItem(
                        (string)$item['product_id'],
                        $orderItemId,
                        max(0, (int)($item['quantity'] ?? 0)),
                        max(0, (int)($productRow['quantity'] ?? 0)),
                        round((float)($productRow['cost'] ?? 0), 4),
                        ['order_id' => $data['id'], 'reason' => 'order_create']
                    );
                    $stmtUpdateOrderItemCost->execute([
                        'id' => $orderItemId,
                        'tenant_id' => $this->getTenantId(),
                        'unit_cost' => round((float)($allocation['unit_cost'] ?? 0), 4),
                        'cost_total' => round((float)($allocation['cost_total'] ?? 0), 4)
                    ]);

                    $stmtUpdateStock->execute([
                        'qty' => $item['quantity'],
                        'id' => $item['product_id'],
                        'tenant_id' => $this->getTenantId()
                    ]);
                    if ($stmtUpdateStock->rowCount() !== 1) {
                        throw new \Exception('Stock insuficiente para completar el pedido');
                    }
                }
            }

            if ($this->realizedSalesConditionForStatus($orderStatus)) {
                $invoiceData = $this->buildInvoiceData($data, $quote);
                $invoiceNumber = $this->buildInvoiceNumber($data['id']);
                $invoiceHtml = $this->renderInvoiceHtml($invoiceNumber, $data, $quote, $invoiceData, $baseUrl);

                $stmtInvoice = $this->db->prepare('UPDATE "Order" SET invoice_number = :invoice_number, invoice_html = :invoice_html, invoice_created_at = NOW(), invoice_data = :invoice_data WHERE id = :id AND tenant_id = :tenant_id');
                $stmtInvoice->execute([
                    'id' => $data['id'],
                    'tenant_id' => $this->getTenantId(),
                    'invoice_number' => $invoiceNumber,
                    'invoice_html' => $invoiceHtml,
                    'invoice_data' => json_encode($invoiceData)
                ]);
            }

            $this->enqueueBillingCommandIfFinal(
                $this->getTenantId(),
                (string)$data['id'],
                $orderStatus,
                'order_created'
            );

            $this->db->commit();
            return $this->getById($data['id']);

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function activeOrdersCondition($alias = 'o') {
        return "LOWER(COALESCE({$alias}.status, 'pending')) NOT IN ('canceled', 'cancelled')";
    }

    private function realizedSalesCondition($alias = 'o') {
        return "LOWER(COALESCE({$alias}.status, 'pending')) IN ('delivered', 'completed')";
    }

    private function realizedSalesConditionForStatus($status) {
        $normalized = strtolower(trim((string)$status));
        return in_array($normalized, ['delivered', 'completed'], true);
    }

    private function enqueueBillingCommandIfFinal(
        string $tenantId,
        string $orderId,
        string $status,
        string $trigger
    ): void {
        if (!$this->realizedSalesConditionForStatus($status)) {
            return;
        }

        $tenant = TenantContext::get() ?? [];
        $domains = is_array($tenant['domains'] ?? null) ? $tenant['domains'] : [];
        $targetHost = strtolower(trim((string)($domains[0] ?? '')));
        if ($targetHost === '') {
            $targetHost = strtolower(trim((string)(parse_url((string)($tenant['app_url'] ?? ''), PHP_URL_HOST) ?: '')));
        }
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $targetHost) !== 1) {
            throw new \RuntimeException('Tenant Billing target host is unavailable; final Order transition was not committed.');
        }

        $sriEnvironment = strtolower(trim((string)($_ENV['SRI_ENVIRONMENT'] ?? getenv('SRI_ENVIRONMENT') ?: 'produccion')));
        $apiMode = in_array($sriEnvironment, ['test', 'testing', 'pruebas', 'qa'], true) ? 'test' : 'production';
        $maxAttempts = filter_var(
            $_ENV['BILLING_OUTBOX_MAX_ATTEMPTS'] ?? getenv('BILLING_OUTBOX_MAX_ATTEMPTS') ?: 12,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]]
        );

        (new CommerceBillingOutboxRepository($this->db))->enqueueForOrder(
            $tenantId,
            $orderId,
            $status,
            [
                'target_host' => $targetHost,
                'api_mode' => $apiMode,
                'trigger' => $trigger,
            ],
            $maxAttempts === false ? 12 : (int)$maxAttempts
        );
    }

    private function orderAffectsInventory($status) {
        $normalized = strtolower(trim((string)$status));
        return !in_array($normalized, ['canceled', 'cancelled'], true);
    }

    private function orderSkipsInventoryImpact($paymentDetails): bool {
        if (is_string($paymentDetails) && trim($paymentDetails) !== '') {
            $decoded = json_decode($paymentDetails, true);
            $paymentDetails = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($paymentDetails)) {
            return false;
        }
        $channel = strtolower(trim((string)($paymentDetails['channel'] ?? '')));
        $skip = filter_var($paymentDetails['no_inventory_impact'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $skip || ($channel === 'historical_import' && array_key_exists('no_inventory_impact', $paymentDetails) && $skip);
    }

    private function orderItemCostExpression(string $orderItemAlias = 'oi', string $productAlias = 'p'): string {
        return "CASE
            WHEN COALESCE({$orderItemAlias}.cost_total, 0) > 0 THEN {$orderItemAlias}.cost_total
            WHEN COALESCE({$orderItemAlias}.unit_cost, 0) > 0 THEN COALESCE({$orderItemAlias}.quantity, 0) * {$orderItemAlias}.unit_cost
            ELSE COALESCE({$orderItemAlias}.quantity, 0) * COALESCE({$productAlias}.cost, 0)
        END";
    }

    private function orderItemUnitCostExpression(string $orderItemAlias = 'oi', string $productAlias = 'p'): string {
        return "CASE
            WHEN COALESCE({$orderItemAlias}.unit_cost, 0) > 0 THEN {$orderItemAlias}.unit_cost
            ELSE COALESCE({$productAlias}.cost, 0)
        END";
    }

    private function rawOrderItemGrossTotalExpression(string $orderItemAlias = 'oi'): string {
        return "(COALESCE({$orderItemAlias}.quantity, 0) * COALESCE({$orderItemAlias}.price, 0))";
    }

    private function orderItemTaxRateExpression(string $orderItemAlias = 'oi', string $orderAlias = 'o'): string {
        return "COALESCE({$orderItemAlias}.tax_rate, COALESCE({$orderAlias}.vat_rate, 0))";
    }

    private function orderItemNetTotalExpression(string $orderItemAlias = 'oi', string $orderAlias = 'o'): string {
        $rawGrossExpr = $this->rawOrderItemGrossTotalExpression($orderItemAlias);
        $taxRateExpr = $this->orderItemTaxRateExpression($orderItemAlias, $orderAlias);

        return "COALESCE({$orderItemAlias}.net_total, CASE
            WHEN {$taxRateExpr} > 0 THEN ({$rawGrossExpr} / NULLIF((1 + ({$taxRateExpr} / 100.0)), 0))
            ELSE {$rawGrossExpr}
        END)";
    }

    private function orderItemTaxAmountExpression(string $orderItemAlias = 'oi', string $orderAlias = 'o'): string {
        $rawGrossExpr = $this->rawOrderItemGrossTotalExpression($orderItemAlias);
        $netExpr = $this->orderItemNetTotalExpression($orderItemAlias, $orderAlias);

        return "COALESCE({$orderItemAlias}.tax_amount, ({$rawGrossExpr} - ({$netExpr})))";
    }

    private function orderItemGrossTotalExpression(string $orderItemAlias = 'oi', string $orderAlias = 'o'): string {
        $rawGrossExpr = $this->rawOrderItemGrossTotalExpression($orderItemAlias);
        $netExpr = $this->orderItemNetTotalExpression($orderItemAlias, $orderAlias);
        $taxExpr = $this->orderItemTaxAmountExpression($orderItemAlias, $orderAlias);

        return "COALESCE(({$orderItemAlias}.net_total + {$orderItemAlias}.tax_amount), (($netExpr) + ($taxExpr)), {$rawGrossExpr})";
    }

    private function netSalesSql($alias = 'o') {
        $totalMinusShipping = "(COALESCE({$alias}.total, 0) - COALESCE({$alias}.shipping, 0))";
        $vatRateExpr = "COALESCE({$alias}.vat_rate, 0)";
        $multiplierExpr = "(1 + ({$vatRateExpr} / 100.0))";

        return "COALESCE({$alias}.vat_subtotal, CASE
            WHEN {$vatRateExpr} > 0 THEN ({$totalMinusShipping} / NULLIF({$multiplierExpr}, 0))
            ELSE ({$totalMinusShipping} - COALESCE({$alias}.vat_amount, 0))
        END)";
    }

    private function vatAmountSql($alias = 'o') {
        $totalMinusShipping = "(COALESCE({$alias}.total, 0) - COALESCE({$alias}.shipping, 0))";
        $netExpr = $this->netSalesSql($alias);
        return "COALESCE({$alias}.vat_amount, ({$totalMinusShipping} - ({$netExpr})))";
    }

    // Stats methods for dashboard
    public function getTotalSales() {
        $netExpr = $this->netSalesSql('o');
        $realizedStatus = $this->realizedSalesCondition('o');
        $stmt = $this->db->prepare("
            SELECT SUM($netExpr) as total
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id AND $realizedStatus
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return round((float)($result['total'] ?? 0), 2);
    }

    public function getSalesProgress() {
        $netExpr = $this->netSalesSql('o');
        $realizedStatus = $this->realizedSalesCondition('o');
        $stmtThis = $this->db->prepare("
            SELECT SUM($netExpr)
            FROM \"Order\" o
            CROSS JOIN (
                SELECT
                    (NOW() AT TIME ZONE 'America/Guayaquil')::date AS today,
                    DATE_TRUNC('month', (NOW() AT TIME ZONE 'America/Guayaquil')::date)::date AS month_start
            ) bounds
            WHERE o.tenant_id = :tenant_id
              AND $realizedStatus
              AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date >= bounds.month_start
              AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date <= bounds.today
        ");
        $stmtThis->execute(['tenant_id' => $this->getTenantId()]);
        $thisMonth = (float)($stmtThis->fetchColumn() ?: 0);

        $stmtLast = $this->db->prepare("
            SELECT SUM($netExpr)
            FROM \"Order\" o
            CROSS JOIN (
                SELECT
                    (NOW() AT TIME ZONE 'America/Guayaquil')::date AS today,
                    DATE_TRUNC('month', (NOW() AT TIME ZONE 'America/Guayaquil')::date)::date AS month_start,
                    (DATE_TRUNC('month', (NOW() AT TIME ZONE 'America/Guayaquil')::date) - INTERVAL '1 month')::date AS previous_month_start
            ) bounds
            WHERE o.tenant_id = :tenant_id
              AND $realizedStatus
              AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date >= bounds.previous_month_start
              AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date < bounds.previous_month_start
                + ((bounds.today - bounds.month_start + 1) * INTERVAL '1 day')
        ");
        $stmtLast->execute(['tenant_id' => $this->getTenantId()]);
        $lastMonth = (float)($stmtLast->fetchColumn() ?: 0);

        $percentage = $lastMonth > 0
            ? (($thisMonth - $lastMonth) / $lastMonth) * 100
            : ($thisMonth > 0 ? 100 : 0);

        return [
            'current' => round($thisMonth, 2),
            'previous' => round($lastMonth, 2),
            'percentage' => round($percentage, 1),
            'comparison' => 'month_to_date'
        ];
    }

    public function getNewOrdersCount() {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count
            FROM "Order"
            WHERE tenant_id = :tenant_id
              AND (created_at AT TIME ZONE \'America/Guayaquil\')::date = (NOW() AT TIME ZONE \'America/Guayaquil\')::date
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $result = $stmt->fetch();
        return (int)($result['count'] ?? 0);
    }

    public function getOrdersProgress() {
        $stmtToday = $this->db->prepare('
            SELECT COUNT(*)
            FROM "Order"
            WHERE tenant_id = :tenant_id
              AND (created_at AT TIME ZONE \'America/Guayaquil\')::date = (NOW() AT TIME ZONE \'America/Guayaquil\')::date
        ');
        $stmtToday->execute(['tenant_id' => $this->getTenantId()]);
        $today = (int)($stmtToday->fetchColumn() ?: 0);

        $stmtYesterday = $this->db->prepare('
            SELECT COUNT(*)
            FROM "Order"
            WHERE tenant_id = :tenant_id
              AND (created_at AT TIME ZONE \'America/Guayaquil\')::date = (NOW() AT TIME ZONE \'America/Guayaquil\')::date - 1
        ');
        $stmtYesterday->execute(['tenant_id' => $this->getTenantId()]);
        $yesterday = (int)($stmtYesterday->fetchColumn() ?: 0);

        $percentage = $yesterday > 0
            ? (($today - $yesterday) / $yesterday) * 100
            : ($today > 0 ? 100 : 0);

        return [
            'current' => $today,
            'previous' => $yesterday,
            'percentage' => round($percentage, 1)
        ];
    }

    public function getMonthlyPerformance() {
        $netExpr = $this->netSalesSql('o');
        $lineCostExpr = $this->orderItemCostExpression('oi', 'p');
        $realizedStatus = $this->realizedSalesCondition('o');
        $stmt = $this->db->prepare("
            SELECT TO_CHAR(d::date, 'YYYY-MM-DD') as day,
                   TO_CHAR(d::date, 'YYYY-MM-DD') as date,
                   COALESCE(SUM($netExpr), 0) as total,
                   COALESCE(SUM(o.total), 0) as gross,
                   COALESCE(SUM(item_costs.cost_total), 0) as cost
            FROM generate_series(
                (NOW() AT TIME ZONE 'America/Guayaquil')::date - INTERVAL '6 days',
                (NOW() AT TIME ZONE 'America/Guayaquil')::date,
                '1 day'
            ) d
            LEFT JOIN \"Order\" o ON (o.created_at AT TIME ZONE 'America/Guayaquil')::date = d::date
                AND $realizedStatus
                AND o.tenant_id = :tenant_id
            LEFT JOIN LATERAL (
                SELECT COALESCE(SUM($lineCostExpr), 0) as cost_total
                FROM \"OrderItem\" oi
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                WHERE oi.order_id = o.id
            ) item_costs ON true
            GROUP BY d
            ORDER BY d ASC
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getSalesTrend30Days() {
        $netExpr = $this->netSalesSql('o');
        $lineCostExpr = $this->orderItemCostExpression('oi', 'p');
        $realizedStatus = $this->realizedSalesCondition('o');
        $stmt = $this->db->prepare("
            SELECT TO_CHAR(d::date, 'YYYY-MM-DD') as day,
                   TO_CHAR(d::date, 'YYYY-MM-DD') as date,
                   COALESCE(SUM($netExpr), 0) as total,
                   COALESCE(SUM(o.total), 0) as gross,
                   COALESCE(SUM(item_costs.cost_total), 0) as cost
            FROM generate_series(
                (NOW() AT TIME ZONE 'America/Guayaquil')::date - INTERVAL '29 days',
                (NOW() AT TIME ZONE 'America/Guayaquil')::date,
                '1 day'
            ) d
            LEFT JOIN \"Order\" o ON (o.created_at AT TIME ZONE 'America/Guayaquil')::date = d::date
                AND $realizedStatus
                AND o.tenant_id = :tenant_id
            LEFT JOIN LATERAL (
                SELECT COALESCE(SUM($lineCostExpr), 0) as cost_total
                FROM \"OrderItem\" oi
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                WHERE oi.order_id = o.id
            ) item_costs ON true
            GROUP BY d
            ORDER BY d ASC
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getFinancialTrends(): array {
        try {
            (new BusinessExpenseRepository())->summary();
        } catch (\Throwable $e) {
            // Trend generation should not fail the dashboard if expenses are temporarily unavailable.
        }

        $startDate = $this->resolveFinancialTrendStartDate();
        $daily = $this->getFinancialTrendRows('day', $startDate);
        $monthly = $this->getFinancialTrendRows('month', $startDate);

        return [
            'start_date' => $startDate,
            'daily' => $daily,
            'monthly' => $monthly,
            'totals' => $this->summarizeFinancialTrendRows($monthly),
        ];
    }

    public function getFinancialTrendsForPeriod(array $period, ?string $scope = null): array {
        try {
            (new BusinessExpenseRepository())->summary();
        } catch (\Throwable $e) {
            // Trend generation should not fail the report if expenses are temporarily unavailable.
        }

        $startDate = (string)($period['start_date'] ?? '');
        $endDate = (string)($period['end_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $startDate = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $endDate = (new \DateTimeImmutable('today', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        }

        $grain = in_array($scope, ['year', 'historical'], true) ? 'month' : 'day';
        $rows = $this->getFinancialTrendRows($grain, $startDate, $endDate);

        return [
            'start_date' => $startDate,
            'daily' => $grain === 'day' ? $rows : [],
            'monthly' => $grain === 'month' ? $rows : [],
            'totals' => $this->summarizeFinancialTrendRows($rows),
        ];
    }

    private function resolveFinancialTrendStartDate(): string {
        $stmt = $this->db->prepare("
            SELECT MIN(activity_date) AS first_activity
            FROM (
                SELECT (created_at AT TIME ZONE 'America/Guayaquil')::date AS activity_date
                FROM \"Order\"
                WHERE tenant_id = :tenant_id
                UNION ALL
                SELECT expense_date AS activity_date
                FROM \"BusinessExpense\"
                WHERE tenant_id = :tenant_id
                UNION ALL
                SELECT COALESCE((paid_at AT TIME ZONE 'America/Guayaquil')::date, expense_date) AS activity_date
                FROM \"BusinessExpense\"
                WHERE tenant_id = :tenant_id AND status = 'paid'
                UNION ALL
                SELECT adjustment_date AS activity_date
                FROM \"FinancialAdjustment\"
                WHERE tenant_id = :tenant_id
            ) activity
            WHERE activity_date IS NOT NULL
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $first = $stmt->fetchColumn();
        if (is_string($first) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $first) === 1) {
            return $first;
        }

        return (new \DateTimeImmutable('first day of this month', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
    }

    private function getFinancialTrendRows(string $grain, string $startDate, ?string $endDate = null): array {
        $isMonthly = $grain === 'month';
        if ($endDate !== null) {
            $seriesSql = $isMonthly
                ? "generate_series(DATE_TRUNC('month', CAST(:start_date AS date)), DATE_TRUNC('month', CAST(:end_date AS date)), INTERVAL '1 month')"
                : "generate_series(CAST(:start_date AS date), CAST(:end_date AS date), INTERVAL '1 day')";
        } else {
            $seriesSql = $isMonthly
                ? "generate_series(DATE_TRUNC('month', CAST(:start_date AS date)), DATE_TRUNC('month', (NOW() AT TIME ZONE 'America/Guayaquil')::date), INTERVAL '1 month')"
                : "generate_series(GREATEST(CAST(:start_date AS date), (NOW() AT TIME ZONE 'America/Guayaquil')::date - INTERVAL '89 days'), (NOW() AT TIME ZONE 'America/Guayaquil')::date, INTERVAL '1 day')";
        }
        $periodSelect = $isMonthly ? "TO_CHAR(s.period_start, 'YYYY-MM')" : "TO_CHAR(s.period_start, 'YYYY-MM-DD')";
        $salesGroup = $isMonthly
            ? "DATE_TRUNC('month', (o.created_at AT TIME ZONE 'America/Guayaquil')::date)::date"
            : "(o.created_at AT TIME ZONE 'America/Guayaquil')::date";
        $expenseGroup = $isMonthly ? "DATE_TRUNC('month', be.expense_date)::date" : "be.expense_date";
        $adjustmentGroup = $isMonthly ? "DATE_TRUNC('month', adjustment_date)::date" : "adjustment_date";
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');
        $costExpr = $this->orderItemCostExpression('oi', 'p');
        $realizedStatus = $this->realizedSalesCondition('o');

        $stmt = $this->db->prepare("
            WITH series AS (
                SELECT period_start::date AS period_start
                FROM {$seriesSql} AS period_start
            ),
            sales AS (
                SELECT
                    {$salesGroup} AS period_start,
                    COUNT(*)::int AS orders_count,
                    COALESCE(SUM(o.total), 0) AS gross_sales,
                    COALESCE(SUM({$netExpr}), 0) AS net_sales,
                    COALESCE(SUM({$vatExpr}), 0) AS tax_collected,
                    COALESCE(SUM(o.shipping), 0) AS shipping_collected
                FROM \"Order\" o
                WHERE o.tenant_id = :tenant_id
                  AND {$realizedStatus}
                GROUP BY {$salesGroup}
            ),
            costs AS (
                SELECT
                    {$salesGroup} AS period_start,
                    COALESCE(SUM({$costExpr}), 0) AS product_cost
                FROM \"OrderItem\" oi
                JOIN \"Order\" o ON oi.order_id = o.id
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                WHERE o.tenant_id = :tenant_id
                  AND {$realizedStatus}
                GROUP BY {$salesGroup}
            ),
            paid_expenses AS (
                SELECT
                    {$expenseGroup} AS period_start,
                    COALESCE(SUM(be.total), 0) AS expenses_paid,
                    COUNT(*)::int AS expenses_paid_count
                FROM \"BusinessExpense\" be
                WHERE be.tenant_id = :tenant_id
                  AND be.status = 'paid'
                GROUP BY {$expenseGroup}
            ),
            period_expenses AS (
                SELECT
                    {$expenseGroup} AS period_start,
                    COALESCE(SUM(be.total), 0) AS expenses_incurred,
                    COALESCE(SUM(be.total) FILTER (WHERE be.status = 'paid'), 0) AS expenses_incurred_paid,
                    COUNT(*)::int AS expenses_incurred_count
                FROM \"BusinessExpense\" be
                WHERE be.tenant_id = :tenant_id
                  AND be.status <> 'cancelled'
                GROUP BY {$expenseGroup}
            ),
            unpaid_expenses AS (
                SELECT
                    {$expenseGroup} AS period_start,
                    COALESCE(SUM(be.total) FILTER (WHERE be.status = 'pending'), 0) AS expenses_pending,
                    COALESCE(SUM(be.total) FILTER (WHERE be.status = 'overdue'), 0) AS expenses_overdue,
                    COUNT(*) FILTER (WHERE be.status = 'pending')::int AS expenses_pending_count,
                    COUNT(*) FILTER (WHERE be.status = 'overdue')::int AS expenses_overdue_count
                FROM \"BusinessExpense\" be
                WHERE be.tenant_id = :tenant_id
                  AND be.status IN ('pending', 'overdue')
                GROUP BY {$expenseGroup}
            ),
            adjustments AS (
                SELECT
                    {$adjustmentGroup} AS period_start,
                    COALESCE(SUM(total), 0) AS financial_adjustments
                FROM \"FinancialAdjustment\"
                WHERE tenant_id = :tenant_id
                GROUP BY {$adjustmentGroup}
            )
            SELECT
                {$periodSelect} AS period,
                s.period_start,
                COALESCE(sa.orders_count, 0)::int AS orders_count,
                COALESCE(sa.gross_sales, 0) AS gross_sales,
                COALESCE(sa.net_sales, 0) AS net_sales,
                COALESCE(sa.tax_collected, 0) AS tax_collected,
                COALESCE(sa.shipping_collected, 0) AS shipping_collected,
                COALESCE(c.product_cost, 0) AS product_cost,
                COALESCE(pe.expenses_paid, 0) AS expenses_paid,
                COALESCE(ie.expenses_incurred, 0) AS expenses_incurred,
                COALESCE(ie.expenses_incurred_paid, 0) AS expenses_incurred_paid,
                COALESCE(ue.expenses_pending, 0) AS expenses_pending,
                COALESCE(ue.expenses_overdue, 0) AS expenses_overdue,
                COALESCE(pe.expenses_paid_count, 0)::int AS expenses_paid_count,
                COALESCE(ie.expenses_incurred_count, 0)::int AS expenses_incurred_count,
                COALESCE(ue.expenses_pending_count, 0)::int AS expenses_pending_count,
                COALESCE(ue.expenses_overdue_count, 0)::int AS expenses_overdue_count,
                COALESCE(a.financial_adjustments, 0) AS financial_adjustments
            FROM series s
            LEFT JOIN sales sa ON sa.period_start = s.period_start
            LEFT JOIN costs c ON c.period_start = s.period_start
            LEFT JOIN paid_expenses pe ON pe.period_start = s.period_start
            LEFT JOIN period_expenses ie ON ie.period_start = s.period_start
            LEFT JOIN unpaid_expenses ue ON ue.period_start = s.period_start
            LEFT JOIN adjustments a ON a.period_start = s.period_start
            ORDER BY s.period_start ASC
        ");
        $params = [
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate,
        ];
        if ($endDate !== null) {
            $params['end_date'] = $endDate;
        }
        $stmt->execute($params);

        return array_map(function (array $row): array {
            $net = (float)($row['net_sales'] ?? 0);
            $cost = (float)($row['product_cost'] ?? 0);
            $paid = (float)($row['expenses_paid'] ?? 0);
            $incurred = (float)($row['expenses_incurred'] ?? 0);
            $pending = (float)($row['expenses_pending'] ?? 0);
            $overdue = (float)($row['expenses_overdue'] ?? 0);
            $adjustments = (float)($row['financial_adjustments'] ?? 0);
            $grossProfit = $net - $cost;
            $cashProfit = $grossProfit - $paid - $adjustments;
            $periodExpenses = $incurred;
            $committedExpenses = $periodExpenses;
            $periodProfit = $grossProfit - $incurred - $adjustments;
            $committedProfit = $grossProfit - $committedExpenses - $adjustments;
            $periodMargin = $net > 0 ? round(($periodProfit / $net) * 100, 1) : 0;

            return [
                'period' => (string)($row['period'] ?? ''),
                'date' => (string)($row['period_start'] ?? ''),
                'orders_count' => (int)($row['orders_count'] ?? 0),
                'gross_sales' => round((float)($row['gross_sales'] ?? 0), 2),
                'net_sales' => round($net, 2),
                'tax_collected' => round((float)($row['tax_collected'] ?? 0), 2),
                'shipping_collected' => round((float)($row['shipping_collected'] ?? 0), 2),
                'product_cost' => round($cost, 2),
                'gross_profit' => round($grossProfit, 2),
                'expenses_paid' => round($paid, 2),
                'expenses_cash_paid' => round($paid, 2),
                'expenses_incurred' => round($incurred, 2),
                'period_expenses' => round($periodExpenses, 2),
                'expenses_incurred_paid' => round((float)($row['expenses_incurred_paid'] ?? 0), 2),
                'expenses_pending' => round($pending, 2),
                'expenses_overdue' => round($overdue, 2),
                'committed_expenses' => round($committedExpenses, 2),
                'expenses_paid_count' => (int)($row['expenses_paid_count'] ?? 0),
                'expenses_incurred_count' => (int)($row['expenses_incurred_count'] ?? 0),
                'expenses_pending_count' => (int)($row['expenses_pending_count'] ?? 0),
                'expenses_overdue_count' => (int)($row['expenses_overdue_count'] ?? 0),
                'financial_adjustments' => round($adjustments, 2),
                'net_cash_profit' => round($cashProfit, 2),
                'net_period_profit' => round($periodProfit, 2),
                'net_committed_profit' => round($committedProfit, 2),
                'gross_margin' => $net > 0 ? round(($grossProfit / $net) * 100, 1) : 0,
                'net_cash_margin' => $net > 0 ? round(($cashProfit / $net) * 100, 1) : 0,
                'net_period_margin' => $periodMargin,
                'net_committed_margin' => $net > 0 ? round(($committedProfit / $net) * 100, 1) : 0,
            ];
        }, $stmt->fetchAll() ?: []);
    }

    private function summarizeFinancialTrendRows(array $rows): array {
        $totals = [
            'orders_count' => 0,
            'gross_sales' => 0.0,
            'net_sales' => 0.0,
            'tax_collected' => 0.0,
            'shipping_collected' => 0.0,
            'product_cost' => 0.0,
            'gross_profit' => 0.0,
            'expenses_paid' => 0.0,
            'expenses_cash_paid' => 0.0,
            'expenses_incurred' => 0.0,
            'period_expenses' => 0.0,
            'expenses_incurred_paid' => 0.0,
            'expenses_pending' => 0.0,
            'expenses_overdue' => 0.0,
            'committed_expenses' => 0.0,
            'financial_adjustments' => 0.0,
            'net_cash_profit' => 0.0,
            'net_period_profit' => 0.0,
            'net_committed_profit' => 0.0,
        ];

        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] += $key === 'orders_count'
                    ? (int)($row[$key] ?? 0)
                    : (float)($row[$key] ?? 0);
            }
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = $key === 'orders_count' ? (int)$value : round((float)$value, 2);
        }

        $net = (float)$totals['net_sales'];
        $totals['gross_margin'] = $net > 0 ? round(((float)$totals['gross_profit'] / $net) * 100, 1) : 0;
        $totals['net_cash_margin'] = $net > 0 ? round(((float)$totals['net_cash_profit'] / $net) * 100, 1) : 0;
        $totals['net_period_margin'] = $net > 0 ? round(((float)$totals['net_period_profit'] / $net) * 100, 1) : 0;
        $totals['net_committed_margin'] = $net > 0 ? round(((float)$totals['net_committed_profit'] / $net) * 100, 1) : 0;

        return $totals;
    }

    public function getSalesSummary() {
        $financialPeriods = new FinancialPeriodRepository($this->db);
        $closedTotals = $financialPeriods->closedSnapshotTotals();
        $closedPeriodGuard = "NOT EXISTS (
            SELECT 1
            FROM \"FinancialPeriod\" fp
            WHERE fp.tenant_id = o.tenant_id
              AND fp.period_key = TO_CHAR((o.created_at AT TIME ZONE 'America/Guayaquil')::date, 'YYYY-MM')
              AND fp.status = 'closed'
        )";
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');
        $realizedStatus = $this->realizedSalesCondition('o');
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*)::int as orders_count,
                SUM(COALESCE(o.total, 0)) as gross,
                SUM($netExpr) as net,
                SUM($vatExpr) as vat,
                SUM(COALESCE(o.shipping, 0)) as shipping
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id AND $realizedStatus AND $closedPeriodGuard
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $row = $stmt->fetch();
        $ordersCount = (int)($closedTotals['sales']['orders_count'] ?? 0) + (int)($row['orders_count'] ?? 0);
        $net = (float)($closedTotals['sales']['net'] ?? 0) + (float)($row['net'] ?? 0);
        $gross = (float)($closedTotals['sales']['total'] ?? 0) + (float)($row['gross'] ?? 0);
        $vat = (float)($closedTotals['sales']['tax'] ?? 0) + (float)($row['vat'] ?? 0);
        $shipping = (float)($closedTotals['sales']['shipping'] ?? 0) + (float)($row['shipping'] ?? 0);
        return [
            'orders_count' => $ordersCount,
            'gross' => round($gross, 2),
            'net' => round($net, 2),
            'vat' => round($vat, 2),
            'shipping' => round($shipping, 2),
            'average_order_net' => $ordersCount > 0 ? round($net / $ordersCount, 2) : 0,
            'average_order_gross' => $ordersCount > 0 ? round($gross / $ordersCount, 2) : 0
        ];
    }

    public function getKpiTraceability($orderLimit = 12, $productLimit = 8) {
        $safeOrderLimit = max(1, min(50, (int)$orderLimit));
        $safeProductLimit = max(1, min(30, (int)$productLimit));
        $realizedStatus = $this->realizedSalesCondition('o');
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');
        $itemNetExpr = $this->orderItemNetTotalExpression('oi', 'o');
        $itemGrossExpr = $this->orderItemGrossTotalExpression('oi', 'o');
        $itemCostExpr = $this->orderItemCostExpression('oi', 'p');

        $ordersStmt = $this->db->prepare("
            SELECT
                o.id,
                o.created_at,
                LOWER(COALESCE(o.status, 'pending')) as status,
                u.name as user_name,
                COALESCE(o.total, 0) as gross,
                $netExpr as net,
                $vatExpr as vat,
                COALESCE(o.shipping, 0) as shipping
            FROM \"Order\" o
            LEFT JOIN \"Customer\" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.tenant_id = :tenant_id AND $realizedStatus
            ORDER BY o.created_at DESC
            LIMIT $safeOrderLimit
        ");
        $ordersStmt->execute(['tenant_id' => $this->getTenantId()]);
        $orders = $ordersStmt->fetchAll();

        foreach ($orders as &$order) {
            $order['gross'] = round((float)($order['gross'] ?? 0), 2);
            $order['net'] = round((float)($order['net'] ?? 0), 2);
            $order['vat'] = round((float)($order['vat'] ?? 0), 2);
            $order['shipping'] = round((float)($order['shipping'] ?? 0), 2);
        }
        unset($order);

        $productsStmt = $this->db->prepare("
            WITH active_lines AS (
                SELECT
                    NULLIF(TRIM(oi.product_id), '') AS product_id,
                    COALESCE(
                        NULLIF(TRIM(oi.product_id), ''),
                        'name:' || COALESCE(NULLIF(TRIM(oi.product_name), ''), 'Producto sin nombre')
                    ) AS product_group_key,
                    COALESCE(NULLIF(TRIM(oi.product_name), ''), 'Producto sin nombre') AS product_name,
                    COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') AS category,
                    o.id AS order_id,
                    COALESCE(oi.quantity, 0)::numeric AS quantity,
                    $itemGrossExpr AS line_gross_items,
                    $itemNetExpr AS line_net_estimate,
                    COALESCE(o.shipping, 0) AS order_shipping,
                    COALESCE($netExpr, 0) AS order_net,
                    COALESCE($vatExpr, 0) AS order_vat,
                    $itemCostExpr AS line_cost,
                    SUM($itemGrossExpr) OVER (PARTITION BY o.id) AS order_items_gross
                FROM \"OrderItem\" oi
                JOIN \"Order\" o ON oi.order_id = o.id
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                WHERE o.tenant_id = :tenant_id AND $realizedStatus
            ),
            distributed_lines AS (
                SELECT
                    product_id,
                    product_group_key,
                    product_name,
                    category,
                    order_id,
                    quantity,
                    line_net_estimate,
                    CASE
                        WHEN order_net > 0 THEN order_shipping * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN order_shipping * (line_gross_items / order_items_gross)
                        ELSE 0
                    END AS line_shipping,
                    CASE
                        WHEN order_net > 0 THEN order_vat * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN GREATEST(line_gross_items - line_net_estimate, 0)
                        ELSE 0
                    END AS line_vat,
                    line_cost
                FROM active_lines
            )
            SELECT
                COALESCE(NULLIF(MAX(product_id), ''), '') AS product_id,
                COALESCE(NULLIF(MAX(product_name), ''), 'Producto sin nombre') AS product_name,
                category,
                COALESCE(SUM(quantity), 0)::int AS units_sold,
                COALESCE(SUM(line_net_estimate), 0) AS net_revenue,
                COALESCE(SUM(line_vat), 0) AS vat_amount,
                COALESCE(SUM(line_shipping), 0) AS shipping_amount,
                COALESCE(SUM(line_cost), 0) AS cost,
                COALESCE(SUM(line_net_estimate + line_vat + line_shipping), 0) AS gross_revenue,
                STRING_AGG(DISTINCT order_id, ', ') AS order_refs
            FROM distributed_lines
            GROUP BY product_group_key, category
            ORDER BY net_revenue DESC, units_sold DESC
            LIMIT $safeProductLimit
        ");
        $productsStmt->execute([
            'tenant_id' => $this->getTenantId()
        ]);
        $products = $productsStmt->fetchAll();

        foreach ($products as &$product) {
            $product['units_sold'] = (int)($product['units_sold'] ?? 0);
            $product['net_revenue'] = round((float)($product['net_revenue'] ?? 0), 2);
            $product['vat_amount'] = round((float)($product['vat_amount'] ?? 0), 2);
            $product['shipping_amount'] = round((float)($product['shipping_amount'] ?? 0), 2);
            $product['cost'] = round((float)($product['cost'] ?? 0), 2);
            $product['profit'] = round($product['net_revenue'] - $product['cost'], 2);
            $product['margin'] = $product['net_revenue'] > 0 ? round(($product['profit'] / $product['net_revenue']) * 100, 1) : 0;
            $product['gross_revenue'] = round((float)($product['gross_revenue'] ?? 0), 2);
            $refs = trim((string)($product['order_refs'] ?? ''));
            $product['order_refs'] = $refs === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $refs))));
        }
        unset($product);

        $categoriesStmt = $this->db->prepare("
            WITH active_lines AS (
                SELECT
                    COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') AS category,
                    o.id AS order_id,
                    $itemGrossExpr AS line_gross_items,
                    $itemNetExpr AS line_net_estimate,
                    COALESCE(o.shipping, 0) AS order_shipping,
                    COALESCE($netExpr, 0) AS order_net,
                    COALESCE($vatExpr, 0) AS order_vat,
                    $itemCostExpr AS line_cost,
                    SUM($itemGrossExpr) OVER (PARTITION BY o.id) AS order_items_gross
                FROM \"OrderItem\" oi
                JOIN \"Order\" o ON oi.order_id = o.id
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                WHERE o.tenant_id = :tenant_id AND $realizedStatus
            ),
            distributed_lines AS (
                SELECT
                    category,
                    order_id,
                    line_net_estimate,
                    CASE
                        WHEN order_net > 0 THEN order_shipping * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN order_shipping * (line_gross_items / order_items_gross)
                        ELSE 0
                    END AS line_shipping,
                    CASE
                        WHEN order_net > 0 THEN order_vat * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN GREATEST(line_gross_items - line_net_estimate, 0)
                        ELSE 0
                    END AS line_vat,
                    line_cost
                FROM active_lines
            )
            SELECT
                category,
                COALESCE(SUM(line_net_estimate), 0) AS net_revenue,
                COALESCE(SUM(line_vat), 0) AS vat_amount,
                COALESCE(SUM(line_shipping), 0) AS shipping_amount,
                COALESCE(SUM(line_cost), 0) AS cost,
                COALESCE(SUM(line_net_estimate + line_vat + line_shipping), 0) AS gross_revenue,
                STRING_AGG(DISTINCT order_id, ', ') AS order_refs
            FROM distributed_lines
            GROUP BY category
            ORDER BY net_revenue DESC
            LIMIT 6
        ");
        $categoriesStmt->execute([
            'tenant_id' => $this->getTenantId()
        ]);
        $categories = $categoriesStmt->fetchAll();

        foreach ($categories as &$category) {
            $category['net_revenue'] = round((float)($category['net_revenue'] ?? 0), 2);
            $category['vat_amount'] = round((float)($category['vat_amount'] ?? 0), 2);
            $category['shipping_amount'] = round((float)($category['shipping_amount'] ?? 0), 2);
            $category['cost'] = round((float)($category['cost'] ?? 0), 2);
            $category['profit'] = round($category['net_revenue'] - $category['cost'], 2);
            $category['margin'] = $category['net_revenue'] > 0 ? round(($category['profit'] / $category['net_revenue']) * 100, 1) : 0;
            $category['gross_revenue'] = round((float)($category['gross_revenue'] ?? 0), 2);
            $refs = trim((string)($category['order_refs'] ?? ''));
            $category['order_refs'] = $refs === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $refs))));
        }
        unset($category);

        return [
            'orders' => $orders,
            'products' => $products,
            'categories' => $categories
        ];
    }

    public function getTopProducts() {
        $realizedStatus = $this->realizedSalesCondition('o');
        $itemNetExpr = $this->orderItemNetTotalExpression('oi', 'o');
        $stmt = $this->db->prepare("
            SELECT oi.product_name as name,
                   SUM(oi.quantity) as sold,
                   SUM($itemNetExpr) as revenue
            FROM \"OrderItem\" oi
            JOIN \"Order\" o ON oi.order_id = o.id
            WHERE o.tenant_id = :tenant_id AND $realizedStatus
            GROUP BY oi.product_name
            ORDER BY sold DESC
            LIMIT 5
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    private function resolveReportPeriod(?string $selectedMonth = null): array {
        $monthKey = null;
        if (is_string($selectedMonth) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth) === 1) {
            $monthKey = $selectedMonth;
        }
        if ($monthKey === null) {
            $monthKey = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->format('Y-m');
        }

        $start = new \DateTimeImmutable($monthKey . '-01', new \DateTimeZone('America/Guayaquil'));
        $end = $start->modify('last day of this month');
        $endExclusive = $start->modify('+1 month');
        return [
            'period_key' => $monthKey,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'end_exclusive' => $endExclusive->format('Y-m-d'),
            'timezone' => 'America/Guayaquil',
        ];
    }

    private function resolveWeekPeriod(?string $selectedDate = null): array {
        $timezone = new \DateTimeZone('America/Guayaquil');
        $anchor = is_string($selectedDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) === 1
            ? new \DateTimeImmutable($selectedDate, $timezone)
            : new \DateTimeImmutable('today', $timezone);
        $end = $anchor;
        $start = $end->modify('-6 days');

        return [
            'period_key' => 'week:' . $start->format('Y-m-d') . ':' . $end->format('Y-m-d'),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'end_exclusive' => $end->modify('+1 day')->format('Y-m-d'),
            'timezone' => 'America/Guayaquil',
        ];
    }

    private function resolveYearPeriod(?string $selectedYear = null): array {
        $timezone = new \DateTimeZone('America/Guayaquil');
        $yearKey = is_string($selectedYear) && preg_match('/^\d{4}$/', $selectedYear) === 1
            ? $selectedYear
            : (new \DateTimeImmutable('now', $timezone))->format('Y');
        $start = new \DateTimeImmutable($yearKey . '-01-01', $timezone);
        $end = new \DateTimeImmutable($yearKey . '-12-31', $timezone);

        return [
            'period_key' => $yearKey,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'end_exclusive' => $end->modify('+1 day')->format('Y-m-d'),
            'timezone' => 'America/Guayaquil',
        ];
    }

    public function getReportPeriodSummary(?string $selectedMonth = null, ?string $selectedDate = null, ?string $scope = null, ?string $selectedYear = null, array $options = []): array {
        $tenantId = $this->getTenantId();
        $projection = (string)($options['projection'] ?? 'export');
        $screenProjection = $projection === 'screen';
        $ordersLimitValue = $this->reportLimitValue($options['orders_limit'] ?? 5);
        $productsLimitValue = $this->reportLimitValue($options['products_limit'] ?? 8);
        $categoriesLimitValue = $this->reportLimitValue($options['categories_limit'] ?? 8);
        $ordersLimit = $screenProjection ? 'LIMIT ' . $ordersLimitValue : '';
        $productsLimit = $screenProjection ? 'LIMIT ' . $productsLimitValue : '';
        $categoriesLimit = $screenProjection ? 'LIMIT ' . $categoriesLimitValue : '';
        $includeFinancialTrends = (bool)($options['include_financial_trends'] ?? false);
        $timezone = new \DateTimeZone('America/Guayaquil');
        if ($scope === 'historical') {
            $period = [
                'period_key' => 'historical',
                'start_date' => '2000-01-01',
                'end_date' => '2099-12-31',
                'end_exclusive' => '2100-01-01',
                'timezone' => 'America/Guayaquil',
            ];
        } elseif ($scope === 'year') {
            $period = $this->resolveYearPeriod($selectedYear);
        } elseif ($scope === 'week') {
            $period = $this->resolveWeekPeriod($selectedDate);
        } elseif ($scope === 'day' || $selectedDate) {
            $selectedDay = is_string($selectedDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) === 1
                ? $selectedDate
                : (new \DateTimeImmutable('today', $timezone))->format('Y-m-d');
            $date = new \DateTimeImmutable($selectedDay, $timezone);
            $period = [
                'period_key' => $date->format('Y-m-d'),
                'start_date' => $date->format('Y-m-d'),
                'end_date' => $date->format('Y-m-d'),
                'end_exclusive' => $date->modify('+1 day')->format('Y-m-d'),
                'timezone' => 'America/Guayaquil',
            ];
        } else {
            $period = $this->resolveReportPeriod($selectedMonth);
        }
        $financialPeriods = new FinancialPeriodRepository($this->db);
        $purchaseInvoices = new PurchaseInvoiceRepository($this->db);
        $snapshot = $financialPeriods->buildSnapshot($period['start_date'], $period['end_date'], $period['period_key']);
        $purchaseInvoiceSummary = $purchaseInvoices->summarizePeriod($period['start_date'], $period['end_exclusive']);
        $realizedSales = $this->realizedSalesCondition('o');
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');
        $itemNetExpr = $this->orderItemNetTotalExpression('oi', 'o');
        $itemGrossExpr = $this->orderItemGrossTotalExpression('oi', 'o');
        $unitCostExpr = $this->orderItemUnitCostExpression('oi', 'p');
        $lineCostExpr = $this->orderItemCostExpression('oi', 'p');

        $ordersStmt = $this->db->prepare("
            SELECT
                o.id,
                o.created_at,
                LOWER(COALESCE(o.status, 'pending')) AS status,
                COALESCE(
                    NULLIF(TRIM(u.name), ''),
                    NULLIF(TRIM(CONCAT_WS(' ', o.shipping_address->>'firstName', o.shipping_address->>'lastName')), ''),
                    NULLIF(TRIM(CONCAT_WS(' ', o.billing_address->>'firstName', o.billing_address->>'lastName')), ''),
                    'Consumidor final'
                ) AS user_name,
                COALESCE(NULLIF(TRIM(u.email), ''), NULLIF(TRIM(o.shipping_address->>'email'), ''), NULLIF(TRIM(o.billing_address->>'email'), '')) AS customer_email,
                COALESCE(NULLIF(TRIM(o.shipping_address->>'phone'), ''), NULLIF(TRIM(o.billing_address->>'phone'), '')) AS customer_phone,
                COALESCE(NULLIF(TRIM(u.document_type), ''), NULLIF(TRIM(o.shipping_address->>'documentType'), ''), NULLIF(TRIM(o.billing_address->>'documentType'), '')) AS customer_document_type,
                COALESCE(NULLIF(TRIM(u.document_number), ''), NULLIF(TRIM(o.shipping_address->>'documentNumber'), ''), NULLIF(TRIM(o.billing_address->>'documentNumber'), '')) AS customer_document_number,
                o.payment_method,
                o.delivery_method,
                o.discount_code,
                COALESCE(o.discount_total, 0) AS discount_total,
                COALESCE(o.items_subtotal, 0) AS items_subtotal,
                COALESCE(o.vat_rate, 0) AS vat_rate,
                COALESCE(o.shipping_base, o.shipping, 0) AS shipping_base,
                COALESCE(o.shipping_tax_amount, 0) AS shipping_tax_amount,
                COALESCE(items.item_lines_count, 0)::int AS item_lines_count,
                COALESCE(items.units_count, 0)::int AS units_count,
                COALESCE(items.cost, 0) AS cost,
                COALESCE(items.items_summary, '') AS items_summary,
                COALESCE(o.total, 0) AS gross,
                $netExpr AS net,
                $vatExpr AS vat,
                COALESCE(o.shipping, 0) AS shipping
            FROM \"Order\" o
            LEFT JOIN \"Customer\" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*) AS item_lines_count,
                    COALESCE(SUM(COALESCE(oi.quantity, 0)), 0)::int AS units_count,
                    COALESCE(SUM($lineCostExpr), 0) AS cost,
                    STRING_AGG(
                        COALESCE(NULLIF(TRIM(oi.product_name), ''), NULLIF(TRIM(p.name), ''), 'Producto sin nombre')
                            || ' x' || COALESCE(oi.quantity, 0)::text,
                        '; '
                        ORDER BY COALESCE(NULLIF(TRIM(oi.product_name), ''), NULLIF(TRIM(p.name), ''), 'Producto sin nombre')
                    ) AS items_summary
                FROM \"OrderItem\" oi
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = o.tenant_id
                WHERE oi.order_id = o.id
            ) items ON true
            WHERE o.tenant_id = :tenant_id
              AND $realizedSales
              AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date >= CAST(:start_date AS date)
              AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date < CAST(:end_exclusive AS date)
            ORDER BY o.created_at DESC
            {$ordersLimit}
        ");
        $ordersStmt->execute([
            'tenant_id' => $tenantId,
            'start_date' => $period['start_date'],
            'end_exclusive' => $period['end_exclusive'],
        ]);
        $orders = array_map(static function (array $row): array {
            $net = round((float)($row['net'] ?? 0), 2);
            $cost = round((float)($row['cost'] ?? 0), 2);
            $profit = round($net - $cost, 2);
            $unitsCount = (int)($row['units_count'] ?? 0);
            return [
                'id' => (string)($row['id'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'user_name' => $row['user_name'] ?? null,
                'customer_email' => $row['customer_email'] ?? null,
                'customer_phone' => $row['customer_phone'] ?? null,
                'customer_document_type' => $row['customer_document_type'] ?? null,
                'customer_document_number' => $row['customer_document_number'] ?? null,
                'payment_method' => $row['payment_method'] ?? null,
                'delivery_method' => $row['delivery_method'] ?? null,
                'discount_code' => $row['discount_code'] ?? null,
                'discount_total' => round((float)($row['discount_total'] ?? 0), 2),
                'items_subtotal' => round((float)($row['items_subtotal'] ?? 0), 2),
                'vat_rate' => round((float)($row['vat_rate'] ?? 0), 2),
                'shipping_base' => round((float)($row['shipping_base'] ?? 0), 2),
                'shipping_tax_amount' => round((float)($row['shipping_tax_amount'] ?? 0), 2),
                'item_lines_count' => (int)($row['item_lines_count'] ?? 0),
                'units_count' => $unitsCount,
                'items_summary' => (string)($row['items_summary'] ?? ''),
                'gross' => round((float)($row['gross'] ?? 0), 2),
                'net' => $net,
                'vat' => round((float)($row['vat'] ?? 0), 2),
                'shipping' => round((float)($row['shipping'] ?? 0), 2),
                'cost' => $cost,
                'profit' => $profit,
                'margin' => $net > 0 ? round(($profit / $net) * 100, 1) : 0,
                'average_unit_net' => $unitsCount > 0 ? round($net / $unitsCount, 2) : 0,
            ];
        }, $ordersStmt->fetchAll() ?: []);

        $metricsStmt = $this->db->prepare("
            WITH active_lines AS (
                SELECT
                    NULLIF(TRIM(oi.product_id), '') AS product_id,
                    COALESCE(
                        NULLIF(TRIM(oi.product_id), ''),
                        'name:' || COALESCE(NULLIF(TRIM(oi.product_name), ''), NULLIF(TRIM(p.name), ''), 'Producto sin nombre')
                    ) AS product_group_key,
                    NULLIF(TRIM(p.legacy_id), '') AS legacy_id,
                    COALESCE(NULLIF(TRIM(oi.product_name), ''), NULLIF(TRIM(p.name), ''), 'Producto sin nombre') AS product_name,
                    COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') AS category,
                    NULLIF(TRIM(p.attributes->>'sku'), '') AS sku,
                    o.id AS order_id,
                    COALESCE(oi.quantity, 0)::numeric AS quantity,
                    $itemGrossExpr AS line_gross_items,
                    $itemNetExpr AS line_net_estimate,
                    COALESCE(o.shipping, 0) AS order_shipping,
                    COALESCE($netExpr, 0) AS order_net,
                    COALESCE($vatExpr, 0) AS order_vat,
                    SUM($itemGrossExpr) OVER (PARTITION BY o.id) AS order_items_gross,
                    $unitCostExpr AS unit_cost,
                    $lineCostExpr AS line_cost
                FROM \"OrderItem\" oi
                JOIN \"Order\" o ON oi.order_id = o.id
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                WHERE o.tenant_id = :tenant_id
                  AND $realizedSales
                  AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date >= CAST(:start_date AS date)
                  AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date < CAST(:end_exclusive AS date)
            ),
            distributed_lines AS (
                SELECT
                    product_id,
                    product_group_key,
                    legacy_id,
                    product_name,
                    category,
                    sku,
                    order_id,
                    quantity,
                    line_gross_items,
                    line_net_estimate,
                    CASE
                        WHEN order_net > 0 THEN order_shipping * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN order_shipping * (line_gross_items / order_items_gross)
                        ELSE 0
                    END AS line_shipping,
                    CASE
                        WHEN order_net > 0 THEN order_vat * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN GREATEST(line_gross_items - line_net_estimate, 0)
                        ELSE 0
                    END AS line_vat,
                    line_cost
                FROM active_lines
            ),
            product_rows AS (
                SELECT
                    COALESCE(NULLIF(MAX(product_id), ''), '') AS product_id,
                    COALESCE(NULLIF(MAX(legacy_id), ''), '') AS legacy_id,
                    COALESCE(NULLIF(MAX(product_name), ''), 'Producto sin nombre') AS product_name,
                    category,
                    COALESCE(NULLIF(MAX(sku), ''), '') AS sku,
                    COUNT(DISTINCT order_id)::int AS orders_count,
                    COALESCE(SUM(quantity), 0)::int AS units_sold,
                    COALESCE(SUM(line_gross_items + line_shipping), 0) AS gross_revenue,
                    COALESCE(SUM(line_net_estimate), 0) AS net_revenue,
                    COALESCE(SUM(line_vat), 0) AS vat_amount,
                    COALESCE(SUM(line_shipping), 0) AS shipping_amount,
                    COALESCE(SUM(line_cost), 0) AS cost,
                    STRING_AGG(DISTINCT order_id::text, ', ' ORDER BY order_id::text) AS order_refs
                FROM distributed_lines
                GROUP BY product_group_key, category
                ORDER BY net_revenue DESC, units_sold DESC
                {$productsLimit}
            ),
            category_rows AS (
                SELECT
                    category,
                    COUNT(DISTINCT order_id)::int AS orders_count,
                    COALESCE(SUM(quantity), 0)::int AS units_sold,
                    COALESCE(SUM(line_gross_items + line_shipping), 0) AS gross_revenue,
                    COALESCE(SUM(line_net_estimate), 0) AS net_revenue,
                    COALESCE(SUM(line_vat), 0) AS vat_amount,
                    COALESCE(SUM(line_shipping), 0) AS shipping_amount,
                    COALESCE(SUM(line_cost), 0) AS cost,
                    STRING_AGG(DISTINCT order_id::text, ', ' ORDER BY order_id::text) AS order_refs
                FROM distributed_lines
                GROUP BY category
                ORDER BY net_revenue DESC, units_sold DESC
                {$categoriesLimit}
            )
            SELECT 'product' AS row_type, product_id, legacy_id, product_name, category, sku, orders_count, units_sold, gross_revenue, net_revenue, vat_amount, shipping_amount, cost, order_refs
            FROM product_rows
            UNION ALL
            SELECT 'category' AS row_type, NULL AS product_id, NULL AS legacy_id, NULL AS product_name, category, NULL AS sku, orders_count, units_sold, gross_revenue, net_revenue, vat_amount, shipping_amount, cost, order_refs
            FROM category_rows
            ORDER BY row_type DESC, net_revenue DESC, units_sold DESC
        ");
        $metricsStmt->execute([
            'tenant_id' => $tenantId,
            'start_date' => $period['start_date'],
            'end_exclusive' => $period['end_exclusive'],
        ]);

        $products = [];
        $categories = [];
        foreach ($metricsStmt->fetchAll() ?: [] as $row) {
            $net = (float)($row['net_revenue'] ?? 0);
            $cost = (float)($row['cost'] ?? 0);
            $profit = $net - $cost;
            $refs = trim((string)($row['order_refs'] ?? ''));
            $normalized = [
                'category' => (string)($row['category'] ?? 'Sin categoría'),
                'orders_count' => (int)($row['orders_count'] ?? 0),
                'units_sold' => (int)($row['units_sold'] ?? 0),
                'gross_revenue' => round((float)($row['gross_revenue'] ?? 0), 2),
                'net_revenue' => round($net, 2),
                'vat_amount' => round((float)($row['vat_amount'] ?? 0), 2),
                'shipping_amount' => round((float)($row['shipping_amount'] ?? 0), 2),
                'cost' => round($cost, 2),
                'profit' => round($profit, 2),
                'margin' => $net > 0 ? round(($profit / $net) * 100, 1) : 0,
                'order_refs' => $refs === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $refs)))),
            ];
            if (($row['row_type'] ?? '') === 'product') {
                $products[] = array_merge($normalized, [
                    'product_id' => (string)($row['product_id'] ?? ''),
                    'legacy_id' => trim((string)($row['legacy_id'] ?? '')) ?: null,
                    'product_name' => (string)($row['product_name'] ?? 'Producto sin nombre'),
                    'sku' => trim((string)($row['sku'] ?? '')),
                ]);
            } else {
                $categories[] = $normalized;
            }
        }

        return [
            'period' => $period,
            'timezone' => 'America/Guayaquil',
            'realized_statuses' => ['completed', 'delivered'],
            'sales' => $snapshot['sales'] ?? [],
            'profit' => $snapshot['profit'] ?? [],
            'expenses' => $snapshot['expenses'] ?? [],
            'adjustments' => $snapshot['adjustments'] ?? [],
            'purchase_invoices' => $purchaseInvoiceSummary,
            'meta' => [
                'projection' => $screenProjection ? 'screen' : 'export',
                'limits' => [
                    'orders' => $screenProjection ? $ordersLimitValue : null,
                    'products' => $screenProjection ? $productsLimitValue : null,
                    'categories' => $screenProjection ? $categoriesLimitValue : null,
                ],
            ],
            'financial_trends' => $includeFinancialTrends ? $this->getFinancialTrendsForPeriod($period, $scope) : null,
            'orders' => $orders,
            'products' => $products,
            'categories' => $categories,
        ];
    }

    private function reportLimitValue(mixed $value): int {
        $limit = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 100,
            ],
        ]);

        return $limit === false ? 10 : (int)$limit;
    }

    public function getProductSalesRanking(?string $selectedMonth = null, ?string $selectedDate = null) {
        $tenantId = $this->getTenantId();
        $realizedSales = $this->realizedSalesCondition('o');
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');
        $lineCostExpr = $this->orderItemCostExpression('oi', 'pr');
        $unitCostExpr = $this->orderItemUnitCostExpression('oi', 'pr');
        $itemNetExpr = $this->orderItemNetTotalExpression('oi', 'o');
        $itemGrossExpr = $this->orderItemGrossTotalExpression('oi', 'o');
        $periodStmt = $this->db->prepare("
            SELECT
                MIN((o.created_at AT TIME ZONE 'America/Guayaquil')::date) AS historical_start,
                MAX((o.created_at AT TIME ZONE 'America/Guayaquil')::date) AS historical_end
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id
              AND $realizedSales
        ");
        $periodStmt->execute(['tenant_id' => $tenantId]);
        $periodRow = $periodStmt->fetch() ?: [];
        $monthKey = null;
        if (is_string($selectedMonth) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth) === 1) {
            $monthKey = $selectedMonth;
        }
        if ($monthKey === null) {
            $monthKey = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->format('Y-m');
        }
        $monthStart = $monthKey . '-01';
        $nextMonthStart = date('Y-m-01', strtotime($monthStart . ' +1 month'));
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        if (is_string($selectedDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) === 1) {
            $selDate = new \DateTimeImmutable($selectedDate, new \DateTimeZone('America/Guayaquil'));
            $rangeStart = $selDate->format('Y-m-d');
            $rangeEnd = $selDate->format('Y-m-d');
            $rangeEndExclusive = $selDate->modify('+1 day')->format('Y-m-d');
        } else {
            $todayEcuador = new \DateTimeImmutable('today', new \DateTimeZone('America/Guayaquil'));
            $rangeStart = $todayEcuador->modify('-29 days')->format('Y-m-d');
            $rangeEnd = $todayEcuador->format('Y-m-d');
            $rangeEndExclusive = $todayEcuador->modify('+1 day')->format('Y-m-d');
        }

        $stmt = $this->db->prepare("
            WITH active_lines AS (
                SELECT
                    oi.product_id,
                    o.id AS order_id,
	                    (o.created_at AT TIME ZONE 'America/Guayaquil')::date AS created_local_date,
                    COALESCE(oi.quantity, 0)::numeric AS quantity,
                    $itemGrossExpr AS line_gross_items,
                    $itemNetExpr AS line_net_estimate,
                    COALESCE(o.shipping, 0) AS order_shipping,
                    COALESCE($netExpr, 0) AS order_net,
                    COALESCE($vatExpr, 0) AS order_vat,
                    SUM($itemGrossExpr) OVER (PARTITION BY o.id) AS order_items_gross,
                    $unitCostExpr AS unit_cost,
                    $lineCostExpr AS line_cost
                FROM \"OrderItem\" oi
                JOIN \"Order\" o ON oi.order_id = o.id
                LEFT JOIN \"Product\" pr ON oi.product_id = pr.id AND pr.tenant_id = :tenant_id
                WHERE o.tenant_id = :tenant_id
                  AND $realizedSales
            ),
            distributed_lines AS (
                SELECT
                    product_id,
                    order_id,
	                    created_local_date,
                    quantity,
                    line_gross_items,
                    line_net_estimate,
                    CASE
                        WHEN order_net > 0 THEN order_shipping * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN order_shipping * (line_gross_items / order_items_gross)
                        ELSE 0
                    END AS line_shipping,
                    CASE
                        WHEN order_net > 0 THEN order_vat * (line_net_estimate / order_net)
                        WHEN order_items_gross > 0 THEN GREATEST(line_gross_items - line_net_estimate, 0)
                        ELSE 0
                    END AS line_vat,
                    unit_cost,
                    line_cost
                FROM active_lines
            ),
            product_metrics AS (
                SELECT
                    dl.product_id,
                    COUNT(DISTINCT dl.order_id) FILTER (
	                        WHERE dl.created_local_date >= CAST(:start_date AS date) AND dl.created_local_date < CAST(:end_date AS date)
	                    )::int AS month_orders_count,
	                    COALESCE(SUM(dl.quantity) FILTER (
	                        WHERE dl.created_local_date >= CAST(:start_date AS date) AND dl.created_local_date < CAST(:end_date AS date)
	                    ), 0)::int AS month_units_sold,
	                    COALESCE(SUM(dl.line_gross_items + dl.line_shipping) FILTER (
	                        WHERE dl.created_local_date >= CAST(:start_date AS date) AND dl.created_local_date < CAST(:end_date AS date)
	                    ), 0) AS month_gross_revenue,
	                    COALESCE(SUM(dl.line_net_estimate) FILTER (
	                        WHERE dl.created_local_date >= CAST(:start_date AS date) AND dl.created_local_date < CAST(:end_date AS date)
	                    ), 0) AS month_net_revenue,
	                    COALESCE(SUM(dl.line_vat) FILTER (
	                        WHERE dl.created_local_date >= CAST(:start_date AS date) AND dl.created_local_date < CAST(:end_date AS date)
	                    ), 0) AS month_vat_amount,
	                    COALESCE(SUM(dl.line_shipping) FILTER (
	                        WHERE dl.created_local_date >= CAST(:start_date AS date) AND dl.created_local_date < CAST(:end_date AS date)
	                    ), 0) AS month_shipping_amount,
	                    COALESCE(SUM(dl.line_cost) FILTER (
	                        WHERE dl.created_local_date >= CAST(:start_date AS date) AND dl.created_local_date < CAST(:end_date AS date)
	                    ), 0) AS month_cost,
                    COUNT(DISTINCT dl.order_id) FILTER (
                        WHERE dl.created_local_date >= CAST(:range_start_date AS date)
                          AND dl.created_local_date < CAST(:range_end_exclusive AS date)
                    )::int AS range_orders_count,
                    COALESCE(SUM(dl.quantity) FILTER (
                        WHERE dl.created_local_date >= CAST(:range_start_date AS date)
                          AND dl.created_local_date < CAST(:range_end_exclusive AS date)
                    ), 0)::int AS range_units_sold,
                    COALESCE(SUM(dl.line_gross_items + dl.line_shipping) FILTER (
                        WHERE dl.created_local_date >= CAST(:range_start_date AS date)
                          AND dl.created_local_date < CAST(:range_end_exclusive AS date)
                    ), 0) AS range_gross_revenue,
                    COALESCE(SUM(dl.line_net_estimate) FILTER (
                        WHERE dl.created_local_date >= CAST(:range_start_date AS date)
                          AND dl.created_local_date < CAST(:range_end_exclusive AS date)
                    ), 0) AS range_net_revenue,
                    COALESCE(SUM(dl.line_vat) FILTER (
                        WHERE dl.created_local_date >= CAST(:range_start_date AS date)
                          AND dl.created_local_date < CAST(:range_end_exclusive AS date)
                    ), 0) AS range_vat_amount,
                    COALESCE(SUM(dl.line_shipping) FILTER (
                        WHERE dl.created_local_date >= CAST(:range_start_date AS date)
                          AND dl.created_local_date < CAST(:range_end_exclusive AS date)
                    ), 0) AS range_shipping_amount,
                    COALESCE(SUM(dl.line_cost) FILTER (
                        WHERE dl.created_local_date >= CAST(:range_start_date AS date)
                          AND dl.created_local_date < CAST(:range_end_exclusive AS date)
                    ), 0) AS range_cost,
                    COUNT(DISTINCT dl.order_id)::int AS historical_orders_count,
                    COALESCE(SUM(dl.quantity), 0)::int AS historical_units_sold,
                    COALESCE(SUM(dl.line_gross_items + dl.line_shipping), 0) AS historical_gross_revenue,
                    COALESCE(SUM(dl.line_net_estimate), 0) AS historical_net_revenue,
                    COALESCE(SUM(dl.line_vat), 0) AS historical_vat_amount,
                    COALESCE(SUM(dl.line_shipping), 0) AS historical_shipping_amount,
                    COALESCE(SUM(dl.line_cost), 0) AS historical_cost
                FROM distributed_lines dl
                GROUP BY dl.product_id
            )
            SELECT
                p.id AS product_id,
                p.legacy_id AS legacy_id,
                COALESCE(NULLIF(TRIM(p.name), ''), 'Producto sin nombre') AS product_name,
                COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') AS category,
                NULLIF(TRIM(p.attributes->>'sku'), '') AS sku,
                COALESCE(pm.month_orders_count, 0) AS month_orders_count,
                COALESCE(pm.month_units_sold, 0) AS month_units_sold,
                COALESCE(pm.month_gross_revenue, 0) AS month_gross_revenue,
                COALESCE(pm.month_net_revenue, 0) AS month_net_revenue,
                COALESCE(pm.month_vat_amount, 0) AS month_vat_amount,
                COALESCE(pm.month_shipping_amount, 0) AS month_shipping_amount,
                COALESCE(pm.month_cost, 0) AS month_cost,
                COALESCE(pm.range_orders_count, 0) AS range_orders_count,
                COALESCE(pm.range_units_sold, 0) AS range_units_sold,
                COALESCE(pm.range_gross_revenue, 0) AS range_gross_revenue,
                COALESCE(pm.range_net_revenue, 0) AS range_net_revenue,
                COALESCE(pm.range_vat_amount, 0) AS range_vat_amount,
                COALESCE(pm.range_shipping_amount, 0) AS range_shipping_amount,
                COALESCE(pm.range_cost, 0) AS range_cost,
                COALESCE(pm.historical_orders_count, 0) AS historical_orders_count,
                COALESCE(pm.historical_units_sold, 0) AS historical_units_sold,
                COALESCE(pm.historical_gross_revenue, 0) AS historical_gross_revenue,
                COALESCE(pm.historical_net_revenue, 0) AS historical_net_revenue,
                COALESCE(pm.historical_vat_amount, 0) AS historical_vat_amount,
                COALESCE(pm.historical_shipping_amount, 0) AS historical_shipping_amount,
                COALESCE(pm.historical_cost, 0) AS historical_cost
            FROM \"Product\" p
            LEFT JOIN product_metrics pm ON pm.product_id = p.id
            WHERE p.tenant_id = :tenant_id
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'start_date' => $monthStart,
            'end_date' => $nextMonthStart,
            'range_start_date' => $rangeStart,
            'range_end_exclusive' => $rangeEndExclusive
        ]);
        $rows = $stmt->fetchAll() ?: [];

        $orderCountStmt = $this->db->prepare("
            SELECT
                COUNT(DISTINCT o.id) FILTER (
                    WHERE (o.created_at AT TIME ZONE 'America/Guayaquil')::date >= CAST(:start_date AS date)
                      AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date < CAST(:end_date AS date)
                )::int AS month_orders_count,
                COUNT(DISTINCT o.id) FILTER (
                    WHERE (o.created_at AT TIME ZONE 'America/Guayaquil')::date >= CAST(:range_start_date AS date)
                      AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date < CAST(:range_end_exclusive AS date)
                )::int AS range_orders_count,
                COUNT(DISTINCT o.id)::int AS historical_orders_count
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id
              AND $realizedSales
        ");
        $orderCountStmt->execute([
            'tenant_id' => $tenantId,
            'start_date' => $monthStart,
            'end_date' => $nextMonthStart,
            'range_start_date' => $rangeStart,
            'range_end_exclusive' => $rangeEndExclusive
        ]);
        $orderCounts = $orderCountStmt->fetch() ?: [];

        $normalized = array_map(static function ($row) {
            $monthNet = (float)($row['month_net_revenue'] ?? 0);
            $monthCost = (float)($row['month_cost'] ?? 0);
            $monthProfit = $monthNet - $monthCost;
            $monthMargin = $monthNet > 0 ? ($monthProfit / $monthNet) * 100 : 0;
            $rangeNet = (float)($row['range_net_revenue'] ?? 0);
            $rangeCost = (float)($row['range_cost'] ?? 0);
            $rangeProfit = $rangeNet - $rangeCost;
            $rangeMargin = $rangeNet > 0 ? ($rangeProfit / $rangeNet) * 100 : 0;
            $historicalNet = (float)($row['historical_net_revenue'] ?? 0);
            $historicalCost = (float)($row['historical_cost'] ?? 0);
            $historicalProfit = $historicalNet - $historicalCost;
            $historicalMargin = $historicalNet > 0 ? ($historicalProfit / $historicalNet) * 100 : 0;

            return [
                'product_id' => (string)($row['product_id'] ?? ''),
                'legacy_id' => trim((string)($row['legacy_id'] ?? '')) ?: null,
                'product_name' => (string)($row['product_name'] ?? 'Producto sin nombre'),
                'category' => (string)($row['category'] ?? 'Sin categoría'),
                'sku' => trim((string)($row['sku'] ?? '')),
                'month_orders_count' => (int)($row['month_orders_count'] ?? 0),
                'month_units_sold' => (int)($row['month_units_sold'] ?? 0),
                'month_gross_revenue' => round((float)($row['month_gross_revenue'] ?? 0), 2),
                'month_net_revenue' => round((float)($row['month_net_revenue'] ?? 0), 2),
                'month_vat_amount' => round((float)($row['month_vat_amount'] ?? 0), 2),
                'month_shipping_amount' => round((float)($row['month_shipping_amount'] ?? 0), 2),
                'month_cost' => round($monthCost, 2),
                'month_profit' => round($monthProfit, 2),
                'month_margin' => round($monthMargin, 1),
                'range_orders_count' => (int)($row['range_orders_count'] ?? 0),
                'range_units_sold' => (int)($row['range_units_sold'] ?? 0),
                'range_gross_revenue' => round((float)($row['range_gross_revenue'] ?? 0), 2),
                'range_net_revenue' => round((float)($row['range_net_revenue'] ?? 0), 2),
                'range_vat_amount' => round((float)($row['range_vat_amount'] ?? 0), 2),
                'range_shipping_amount' => round((float)($row['range_shipping_amount'] ?? 0), 2),
                'range_cost' => round($rangeCost, 2),
                'range_profit' => round($rangeProfit, 2),
                'range_margin' => round($rangeMargin, 1),
                'historical_orders_count' => (int)($row['historical_orders_count'] ?? 0),
                'historical_units_sold' => (int)($row['historical_units_sold'] ?? 0),
                'historical_gross_revenue' => round((float)($row['historical_gross_revenue'] ?? 0), 2),
                'historical_net_revenue' => round((float)($row['historical_net_revenue'] ?? 0), 2),
                'historical_vat_amount' => round((float)($row['historical_vat_amount'] ?? 0), 2),
                'historical_shipping_amount' => round((float)($row['historical_shipping_amount'] ?? 0), 2),
                'historical_cost' => round($historicalCost, 2),
                'historical_profit' => round($historicalProfit, 2),
                'historical_margin' => round($historicalMargin, 1),
            ];
        }, $rows);

        $monthlyRanking = $normalized;
        usort($monthlyRanking, static function ($a, $b) {
            if ($a['month_units_sold'] !== $b['month_units_sold']) {
                return $b['month_units_sold'] <=> $a['month_units_sold'];
            }
            if ($a['historical_units_sold'] !== $b['historical_units_sold']) {
                return $b['historical_units_sold'] <=> $a['historical_units_sold'];
            }
            return strcmp((string)$a['product_name'], (string)$b['product_name']);
        });

        $rangeRanking = $normalized;
        usort($rangeRanking, static function ($a, $b) {
            if ($a['range_units_sold'] !== $b['range_units_sold']) {
                return $b['range_units_sold'] <=> $a['range_units_sold'];
            }
            if ($a['range_net_revenue'] !== $b['range_net_revenue']) {
                return $b['range_net_revenue'] <=> $a['range_net_revenue'];
            }
            return strcmp((string)$a['product_name'], (string)$b['product_name']);
        });

        $historicalRanking = $normalized;
        usort($historicalRanking, static function ($a, $b) {
            if ($a['historical_units_sold'] !== $b['historical_units_sold']) {
                return $b['historical_units_sold'] <=> $a['historical_units_sold'];
            }
            if ($a['month_units_sold'] !== $b['month_units_sold']) {
                return $b['month_units_sold'] <=> $a['month_units_sold'];
            }
            return strcmp((string)$a['product_name'], (string)$b['product_name']);
        });

        $monthUnitsTotal = 0;
        $monthNetTotal = 0.0;
        $rangeUnitsTotal = 0;
        $rangeNetTotal = 0.0;
        $historicalUnitsTotal = 0;
        $historicalNetTotal = 0.0;
        $monthOrdersAgg = ['count' => 0, 'gross' => 0.0, 'net' => 0.0, 'vat' => 0.0, 'shipping' => 0.0, 'cost' => 0.0];
        $rangeOrdersAgg = ['count' => 0, 'gross' => 0.0, 'net' => 0.0, 'vat' => 0.0, 'shipping' => 0.0, 'cost' => 0.0];
        $historicalOrdersAgg = ['count' => 0, 'gross' => 0.0, 'net' => 0.0, 'vat' => 0.0, 'shipping' => 0.0, 'cost' => 0.0];
        foreach ($normalized as $item) {
            $monthUnitsTotal += (int)$item['month_units_sold'];
            $monthNetTotal += (float)$item['month_net_revenue'];
            $rangeUnitsTotal += (int)$item['range_units_sold'];
            $rangeNetTotal += (float)$item['range_net_revenue'];
            $historicalUnitsTotal += (int)$item['historical_units_sold'];
            $historicalNetTotal += (float)$item['historical_net_revenue'];
            $historicalOrdersAgg['count'] += (int)$item['historical_orders_count'];
            $historicalOrdersAgg['gross'] += (float)$item['historical_gross_revenue'];
            $historicalOrdersAgg['net'] += (float)$item['historical_net_revenue'];
            $historicalOrdersAgg['vat'] += (float)$item['historical_vat_amount'];
            $historicalOrdersAgg['shipping'] += (float)$item['historical_shipping_amount'];
            $historicalOrdersAgg['cost'] += (float)$item['historical_cost'];
            $monthOrdersAgg['count'] += (int)$item['month_orders_count'];
            $monthOrdersAgg['gross'] += (float)$item['month_gross_revenue'];
            $monthOrdersAgg['net'] += (float)$item['month_net_revenue'];
            $monthOrdersAgg['vat'] += (float)$item['month_vat_amount'];
            $monthOrdersAgg['shipping'] += (float)$item['month_shipping_amount'];
            $monthOrdersAgg['cost'] += (float)$item['month_cost'];
            $rangeOrdersAgg['count'] += (int)$item['range_orders_count'];
            $rangeOrdersAgg['gross'] += (float)$item['range_gross_revenue'];
            $rangeOrdersAgg['net'] += (float)$item['range_net_revenue'];
            $rangeOrdersAgg['vat'] += (float)$item['range_vat_amount'];
            $rangeOrdersAgg['shipping'] += (float)$item['range_shipping_amount'];
            $rangeOrdersAgg['cost'] += (float)$item['range_cost'];
        }
        $monthOrdersAgg['count'] = (int)($orderCounts['month_orders_count'] ?? 0);
        $rangeOrdersAgg['count'] = (int)($orderCounts['range_orders_count'] ?? 0);
        $historicalOrdersAgg['count'] = (int)($orderCounts['historical_orders_count'] ?? 0);

        $buildFinancial = static function (array $agg): array {
            $net = round($agg['net'], 2);
            $cost = round($agg['cost'], 2);
            $profit = round($net - $cost, 2);
            $margin = $net > 0 ? round(($profit / $net) * 100, 1) : 0;
            return [
                'orders_count' => $agg['count'],
                'gross' => round($agg['gross'], 2),
                'net' => $net,
                'vat' => round($agg['vat'], 2),
                'shipping' => round($agg['shipping'], 2),
                'cost' => $cost,
                'profit' => $profit,
                'margin' => $margin,
            ];
        };
        $monthlyFinancial = $buildFinancial($monthOrdersAgg);
        $rangeFinancial = $buildFinancial($rangeOrdersAgg);
        $historicalFinancial = $buildFinancial($historicalOrdersAgg);

        return [
            'period' => [
                'start' => $monthStart,
                'end' => $monthEnd,
            ],
            'selectedMonth' => $monthKey,
            'historicalPeriod' => [
                'start' => isset($periodRow['historical_start']) && $periodRow['historical_start'] !== null ? (string)$periodRow['historical_start'] : null,
                'end' => isset($periodRow['historical_end']) && $periodRow['historical_end'] !== null ? (string)$periodRow['historical_end'] : (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d'),
            ],
            'rangePeriod' => [
                'start' => $rangeStart,
                'end' => $rangeEnd,
            ],
            'monthlyTotals' => [
                'units_sold' => $monthUnitsTotal,
                'net_revenue' => round($monthNetTotal, 2),
            ],
            'monthlyFinancial' => $monthlyFinancial,
            'rangeTotals' => [
                'units_sold' => $rangeUnitsTotal,
                'net_revenue' => round($rangeNetTotal, 2),
            ],
            'rangeFinancial' => $rangeFinancial,
            'historicalTotals' => [
                'units_sold' => $historicalUnitsTotal,
                'net_revenue' => round($historicalNetTotal, 2),
            ],
            'historicalFinancial' => $historicalFinancial,
            'monthlyRanking' => $monthlyRanking,
            'rangeRanking' => $rangeRanking,
            'historicalRanking' => $historicalRanking,
        ];
    }

    public function getSalesByCategory() {
        $realizedStatus = $this->realizedSalesCondition('o');
        $itemNetExpr = $this->orderItemNetTotalExpression('oi', 'o');
        $stmt = $this->db->prepare("
            SELECT COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') as category,
                   SUM($itemNetExpr) as total
            FROM \"OrderItem\" oi
            LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
            JOIN \"Order\" o ON oi.order_id = o.id
            WHERE o.tenant_id = :tenant_id AND $realizedStatus
            GROUP BY COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría')
            ORDER BY total DESC
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getAverageOrderValue() {
        $netExpr = $this->netSalesSql('o');
        $realizedStatus = $this->realizedSalesCondition('o');
        $stmt = $this->db->prepare("
            SELECT AVG($netExpr) as avg
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id AND $realizedStatus
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return round((float)($stmt->fetchColumn() ?: 0), 2);
    }

    public function getSalesDeepDive() {
        $netExpr = $this->netSalesSql('o');
        $realizedStatus = $this->realizedSalesCondition('o');
        $stmtCurrent = $this->db->prepare("
            SELECT EXTRACT(DAY FROM d) as day, COALESCE(SUM($netExpr), 0) as total
            FROM generate_series(
                DATE_TRUNC('month', (NOW() AT TIME ZONE 'America/Guayaquil')::date),
                (NOW() AT TIME ZONE 'America/Guayaquil')::date,
                '1 day'
            ) d
            LEFT JOIN \"Order\" o ON (o.created_at AT TIME ZONE 'America/Guayaquil')::date = d::date
                AND $realizedStatus
                AND o.tenant_id = :tenant_id
            GROUP BY day ORDER BY day ASC
        ");
        $stmtCurrent->execute(['tenant_id' => $this->getTenantId()]);
        $currentDays = $stmtCurrent->fetchAll();

        $stmtPrevious = $this->db->prepare("
            WITH bounds AS (
                SELECT
                    (NOW() AT TIME ZONE 'America/Guayaquil')::date AS today,
                    DATE_TRUNC('month', (NOW() AT TIME ZONE 'America/Guayaquil')::date)::date AS month_start,
                    (DATE_TRUNC('month', (NOW() AT TIME ZONE 'America/Guayaquil')::date) - INTERVAL '1 month')::date AS previous_month_start
            )
            SELECT EXTRACT(DAY FROM d) as day, COALESCE(SUM($netExpr), 0) as total
            FROM bounds,
            generate_series(
                bounds.previous_month_start,
                bounds.previous_month_start + ((bounds.today - bounds.month_start) * INTERVAL '1 day'),
                '1 day'
            ) d
            LEFT JOIN \"Order\" o ON (o.created_at AT TIME ZONE 'America/Guayaquil')::date = d::date
                AND $realizedStatus
                AND o.tenant_id = :tenant_id
            GROUP BY day ORDER BY day ASC
        ");
        $stmtPrevious->execute(['tenant_id' => $this->getTenantId()]);
        $previousDays = $stmtPrevious->fetchAll();

        $itemNetExpr = $this->orderItemNetTotalExpression('oi', 'o');
        $catGrowthStmt = $this->db->prepare("
            WITH bounds AS (
                SELECT
                    (NOW() AT TIME ZONE 'America/Guayaquil')::date AS today,
                    DATE_TRUNC('month', (NOW() AT TIME ZONE 'America/Guayaquil')::date)::date AS month_start,
                    (DATE_TRUNC('month', (NOW() AT TIME ZONE 'America/Guayaquil')::date) - INTERVAL '1 month')::date AS previous_month_start
            ),
            this_month AS (
                SELECT
                    COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') as category,
                    SUM($itemNetExpr) as current_sales
                FROM \"OrderItem\" oi
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                JOIN \"Order\" o ON oi.order_id = o.id
                CROSS JOIN bounds
                WHERE $realizedStatus
                  AND o.tenant_id = :tenant_id
                  AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date >= bounds.month_start
                  AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date <= bounds.today
                GROUP BY COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría')
            ),
            last_month AS (
                SELECT
                    COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría') as category,
                    SUM($itemNetExpr) as previous_sales
                FROM \"OrderItem\" oi
                LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
                JOIN \"Order\" o ON oi.order_id = o.id
                CROSS JOIN bounds
                WHERE $realizedStatus
                  AND o.tenant_id = :tenant_id
                  AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date >= bounds.previous_month_start
                  AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date < bounds.previous_month_start
                    + ((bounds.today - bounds.month_start + 1) * INTERVAL '1 day')
                GROUP BY COALESCE(NULLIF(TRIM(p.category), ''), 'Sin categoría')
            )
            SELECT
                COALESCE(tm.category, lm.category) as category,
                COALESCE(tm.current_sales, 0) as current,
                COALESCE(lm.previous_sales, 0) as previous,
                CASE
                    WHEN COALESCE(lm.previous_sales, 0) > 0
                        THEN ROUND(((COALESCE(tm.current_sales, 0) - lm.previous_sales) / lm.previous_sales) * 100, 1)
                    WHEN COALESCE(tm.current_sales, 0) > 0
                        THEN 100
                    ELSE 0
                END as growth
            FROM this_month tm
            FULL OUTER JOIN last_month lm ON tm.category = lm.category
            ORDER BY growth DESC
        ");
        $catGrowthStmt->execute(['tenant_id' => $this->getTenantId()]);
        $catGrowth = $catGrowthStmt->fetchAll();

        return [
            'daily' => [
                'current' => $currentDays,
                'previous' => $previousDays
            ],
            'categories' => $catGrowth
        ];
    }

    public function getProfitStats() {
        $financialPeriods = new FinancialPeriodRepository($this->db);
        $closedTotals = $financialPeriods->closedSnapshotTotals();
        $closedPeriodGuard = "NOT EXISTS (
            SELECT 1
            FROM \"FinancialPeriod\" fp
            WHERE fp.tenant_id = o.tenant_id
              AND fp.period_key = TO_CHAR((o.created_at AT TIME ZONE 'America/Guayaquil')::date, 'YYYY-MM')
              AND fp.status = 'closed'
        )";
        $netExpr = $this->netSalesSql('o');
        $realizedStatus = $this->realizedSalesCondition('o');
        $salesStmt = $this->db->prepare("
            SELECT
                SUM($netExpr) as revenue,
                SUM(COALESCE(o.shipping_base, o.shipping, 0)) as shipping_collected
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id AND $realizedStatus AND $closedPeriodGuard
        ");
        $salesStmt->execute(['tenant_id' => $this->getTenantId()]);
        $salesRow = $salesStmt->fetch();
        $revenue = (float)($closedTotals['sales']['net'] ?? 0) + (float)($salesRow['revenue'] ?? 0);
        $shippingCollected = (float)($closedTotals['sales']['shipping'] ?? 0) + (float)($salesRow['shipping_collected'] ?? 0);
        $costExpr = $this->orderItemCostExpression('oi', 'p');

        $costStmt = $this->db->prepare("
            SELECT SUM($costExpr) as cost
            FROM \"OrderItem\" oi
            JOIN \"Order\" o ON oi.order_id = o.id
            LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
            WHERE o.tenant_id = :tenant_id AND $realizedStatus AND $closedPeriodGuard
        ");
        $costStmt->execute(['tenant_id' => $this->getTenantId()]);
        $costRow = $costStmt->fetch();
        $cost = (float)($closedTotals['profit']['cost'] ?? 0) + (float)($costRow['cost'] ?? 0);
        $grossProfit = $revenue - $cost;
        $grossMargin = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;
        $expenseSummary = $this->getBusinessExpenseSummary(true);
        $paidExpenses = (float)($closedTotals['profit']['paid_expenses'] ?? 0) + (float)($expenseSummary['paid'] ?? 0);
        $pendingExpenses = (float)($closedTotals['profit']['pending_expenses'] ?? 0) + (float)($expenseSummary['pending'] ?? 0);
        $overdueExpenses = (float)($closedTotals['profit']['overdue_expenses'] ?? 0) + (float)($expenseSummary['overdue'] ?? 0);
        $periodExpenses = (float)($closedTotals['profit']['period_expenses'] ?? 0);
        if ($periodExpenses <= 0) {
            $periodExpenses = (float)($closedTotals['profit']['committed_expenses'] ?? 0);
        }
        $periodExpenses += (float)($expenseSummary['period_expenses'] ?? $expenseSummary['committed_expenses'] ?? ($expenseSummary['paid'] ?? 0) + ($expenseSummary['pending'] ?? 0) + ($expenseSummary['overdue'] ?? 0));
        $committedExpenses = $periodExpenses;
        $financialAdjustments = (float)($closedTotals['profit']['financial_adjustments'] ?? 0) + (float)($financialPeriods->adjustmentSummary(null, true)['total'] ?? 0);
        $netCashProfit = $grossProfit - $paidExpenses - $financialAdjustments;
        $netPeriodProfit = $grossProfit - $periodExpenses - $financialAdjustments;
        $netCommittedProfit = $netPeriodProfit;
        $netCashMargin = $revenue > 0 ? ($netCashProfit / $revenue) * 100 : 0;
        $netPeriodMargin = $revenue > 0 ? ($netPeriodProfit / $revenue) * 100 : 0;
        $netCommittedMargin = $revenue > 0 ? ($netCommittedProfit / $revenue) * 100 : 0;

        return [
            'revenue' => round($revenue, 2),
            'cost' => round($cost, 2),
            'shipping_collected' => round($shippingCollected, 2),
            'operating_expenses' => round($periodExpenses, 2),
            'period_expenses' => round($periodExpenses, 2),
            'paid_expenses' => round($paidExpenses, 2),
            'pending_expenses' => round($pendingExpenses, 2),
            'overdue_expenses' => round($overdueExpenses, 2),
            'committed_expenses' => round($committedExpenses, 2),
            'financial_adjustments' => round($financialAdjustments, 2),
            'gross_profit' => round($grossProfit, 2),
            'gross_margin' => round($grossMargin, 1),
            'net_cash_profit' => round($netCashProfit, 2),
            'net_cash_margin' => round($netCashMargin, 1),
            'net_period_profit' => round($netPeriodProfit, 2),
            'net_period_margin' => round($netPeriodMargin, 1),
            'net_committed_profit' => round($netCommittedProfit, 2),
            'net_committed_margin' => round($netCommittedMargin, 1),
            'net_profit' => round($netPeriodProfit, 2),
            'net_margin' => round($netPeriodMargin, 1),
            'expense_source' => 'business_expenses',
            // Backwards-compatible names used by older panel widgets.
            'shipping_cost' => 0,
            'profit' => round($grossProfit, 2),
            'margin' => round($grossMargin, 1)
        ];
    }

    private function getBusinessExpenseSummary(bool $excludeClosedPeriods = false): array {
        try {
            return (new BusinessExpenseRepository())->summary([
                'exclude_closed_periods' => $excludeClosedPeriods,
            ]);
        } catch (\Throwable $e) {
            return [
                'paid' => 0.0,
                'pending' => 0.0,
                'overdue' => 0.0,
            ];
        }
    }

    public function getInventoryValue() {
        $stmt = $this->db->prepare('
            SELECT
                SUM(quantity * price) as market_value,
                SUM(quantity * cost) as cost_value,
                SUM(quantity) as total_items,
                COUNT(*) as products_count,
                COUNT(*) FILTER (WHERE quantity > 0) as skus_with_stock
            FROM "Product"
            WHERE tenant_id = :tenant_id
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetch();
    }

    public function getOrdersByStatus() {
        $stmt = $this->db->prepare("
            SELECT LOWER(COALESCE(status, 'pending')) as status, COUNT(*) as count
            FROM \"Order\"
            WHERE tenant_id = :tenant_id
            GROUP BY LOWER(COALESCE(status, 'pending'))
            ORDER BY count DESC
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function buildInvoiceNumber($orderId) {
        $date = date('Ymd');
        $suffix = substr(preg_replace('/[^A-Za-z0-9]/', '', $orderId), -6);
        return 'FAC-' . $date . '-' . strtoupper($suffix);
    }

    private function buildInvoiceData($data, $quote) {
        $billing = $data['billing_address'] ?? null;
        if (!$billing) {
            $billing = $this->getUserDefaultBilling($data['user_id']);
        }

        return [
            'customer' => [
                'name' => trim(($data['shipping_address']['firstName'] ?? '') . ' ' . ($data['shipping_address']['lastName'] ?? '')),
                'email' => $data['shipping_address']['email'] ?? null,
                'phone' => $data['shipping_address']['phone'] ?? null
            ],
            'billing_address' => $billing,
            'shipping_address' => [
                'line1' => 'Local Para Mascotas EC',
                'line2' => 'Retiro en tienda'
            ],
            'company' => $billing['company'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'items' => $quote['items'],
            'order_notes' => $data['order_notes'] ?? null
        ];
    }

    public function updateBillingMetadata(string $orderId, array $billingMetadata): void {
        $stmt = $this->db->prepare('SELECT invoice_data FROM "Order" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $orderId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();

        $invoiceData = [];
        if ($row && !empty($row['invoice_data'])) {
            $decoded = is_array($row['invoice_data']) ? $row['invoice_data'] : json_decode((string)$row['invoice_data'], true);
            if (is_array($decoded)) {
                $invoiceData = $decoded;
            }
        }

        $existingBilling = is_array($invoiceData['billing'] ?? null) ? $invoiceData['billing'] : [];
        $invoiceData['billing'] = array_merge($existingBilling, $billingMetadata);

        $stmtUpdate = $this->db->prepare('UPDATE "Order" SET invoice_data = :invoice_data WHERE id = :id AND tenant_id = :tenant_id');
        $stmtUpdate->execute([
            'id' => $orderId,
            'tenant_id' => $this->getTenantId(),
            'invoice_data' => json_encode($invoiceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    }

    public function getAccountingDatesByOrderIds(array $orderIds): array {
        $orderIds = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $orderIds), static fn(string $value): bool => $value !== '')));

        if (count($orderIds) === 0) {
            return [];
        }

        $placeholders = [];
        $params = ['tenant_id' => $this->getTenantId()];
        foreach ($orderIds as $index => $orderId) {
            $name = 'order_id_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $orderId;
        }

        $stmt = $this->db->prepare('
            SELECT
                id,
                created_at::text AS order_created_at,
                created_at::date::text AS accounting_date,
                TO_CHAR(created_at::date, \'YYYY-MM\') AS financial_period_key
            FROM "Order"
            WHERE tenant_id = :tenant_id
              AND id IN (' . implode(', ', $placeholders) . ')
        ');
        $stmt->execute($params);

        $dates = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $dates[$id] = [
                'order_created_at' => $row['order_created_at'] ?? null,
                'accounting_date' => $row['accounting_date'] ?? null,
                'financial_period_key' => $row['financial_period_key'] ?? null,
            ];
        }

        return $dates;
    }

    private function getUserDefaultBilling($userId) {
        if (!$userId) return null;
        $stmt = $this->db->prepare('SELECT addresses FROM "Customer" WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([
            'id' => $userId,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        if (!$row || empty($row['addresses'])) return null;
        $addresses = json_decode($row['addresses'], true);
        if (!is_array($addresses) || count($addresses) === 0) return null;
        $first = $addresses[0];
        return $first['billing'] ?? null;
    }

    private function decodeJsonField($value) {
        if (!$value) return null;
        if (is_array($value)) return $value;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function summarizeOrderItemsTax(array $items, float $fallbackTaxRate): array {
        $grossSubtotal = 0.0;
        $netSubtotal = 0.0;
        $taxAmount = 0.0;
        $ratesSeen = [];

        foreach ($items as $item) {
            $quantity = max(0, (int)($item['quantity'] ?? 0));
            if ($quantity <= 0) {
                continue;
            }

            $lineTaxRate = isset($item['tax_rate']) && is_numeric($item['tax_rate'])
                ? max(0, (float)$item['tax_rate'])
                : max(0, $fallbackTaxRate);
            $lineTaxExempt = isset($item['tax_exempt'])
                ? $this->normalizeBooleanAttribute($item['tax_exempt'], false)
                : (($item['tax_treatment'] ?? null) === ProductTaxPolicy::TREATMENT_EXEMPT);
            if ($lineTaxExempt) {
                $lineTaxRate = 0.0;
            }
            $lineTaxMultiplier = 1 + ($lineTaxRate / 100);

            $rawGrossLine = round((float)($item['price'] ?? 0) * $quantity, 4);
            $storedNetTotal = isset($item['net_total']) && is_numeric($item['net_total'])
                ? round((float)$item['net_total'], 4)
                : null;
            $storedTaxAmount = isset($item['tax_amount']) && is_numeric($item['tax_amount'])
                ? round((float)$item['tax_amount'], 4)
                : null;

            if ($storedNetTotal !== null && $storedTaxAmount !== null) {
                $lineNetTotal = $storedNetTotal;
                $lineTaxAmount = $storedTaxAmount;
                $lineGrossTotal = round($lineNetTotal + $lineTaxAmount, 4);
            } else {
                $lineGrossTotal = $rawGrossLine;
                $lineNetTotal = $lineTaxMultiplier > 0
                    ? round($lineGrossTotal / $lineTaxMultiplier, 4)
                    : round($lineGrossTotal, 4);
                $lineTaxAmount = round($lineGrossTotal - $lineNetTotal, 4);
            }

            $grossSubtotal += $lineGrossTotal;
            $netSubtotal += $lineNetTotal;
            $taxAmount += $lineTaxAmount;
            $ratesSeen[(string)round($lineTaxRate, 2)] = true;
        }

        $distinctRates = array_map('floatval', array_keys($ratesSeen));
        sort($distinctRates);
        $mixedRates = count($distinctRates) > 1;
        $displayRate = count($distinctRates) === 1
            ? (float)$distinctRates[0]
            : (count($distinctRates) > 0 ? max($distinctRates) : max(0, $fallbackTaxRate));

        return [
            'gross_subtotal' => round($grossSubtotal, 2),
            'net_subtotal' => round($netSubtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'vat_rate' => round($displayRate, 2),
            'mixed_vat_rates' => $mixedRates,
        ];
    }

    private function renderInvoiceHtml($invoiceNumber, $data, $quote, $invoiceData, $baseUrl = null) {
        $frontendBase = TenantContext::appUrl() ?? ($_ENV['FRONTEND_URL'] ?? ($_ENV['APP_URL'] ?? ''));
        $baseUrl = $baseUrl ?: $frontendBase;
        if (empty($baseUrl)) {
            $baseUrl = TenantContext::appUrl() ?? 'https://paramascotasec.com';
        }
        if (strpos($baseUrl, 'api.') !== false) {
            $baseUrl = str_replace('://api.', '://', $baseUrl);
        }
        $logoUrl = rtrim($baseUrl, '/') . '/images/brand/LogoVerde150.png';
        $logoPath = __DIR__ . '/../../public/images/brand/LogoVerde150.png';
        if (file_exists($logoPath)) {
            $logoData = base64_encode(file_get_contents($logoPath));
            $logoUrl = 'data:image/png;base64,' . $logoData;
        }
        $customer = $invoiceData['customer'] ?? [];
        $billing = $invoiceData['billing_address'] ?? null;
        $company = $invoiceData['company'] ?? null;
        $payment = $invoiceData['payment_method'] ?? '—';
        $items = $quote['items'] ?? [];
        $subtotal = $quote['subtotal'] ?? 0;
        $shipping = $quote['shipping'] ?? 0;
        $total = $quote['total'] ?? 0;
        $discountTotal = isset($quote['discount_total']) ? (float)$quote['discount_total'] : 0;
        $discountCode = trim((string)($quote['discount_code'] ?? ''));
        $taxRate = isset($quote['vat_rate']) ? (float)$quote['vat_rate'] : $this->getTaxRate();
        $mixedVatRates = !empty($quote['mixed_vat_rates']);
        $taxNetSubtotal = isset($quote['vat_subtotal']) && $quote['vat_subtotal'] !== null
            ? (float)$quote['vat_subtotal']
            : $subtotal;
        $taxAmount = isset($quote['vat_amount']) && $quote['vat_amount'] !== null
            ? (float)$quote['vat_amount']
            : ($subtotal - $taxNetSubtotal);
        $billingLines = $this->formatAddressLines($billing);
        $shippingLines = $invoiceData['shipping_address'] ?? [];

        $rows = '';
        foreach ($items as $item) {
            $itemPrice = (float)($item['price'] ?? 0);
            $itemQty = (int)($item['quantity'] ?? 1);
            $itemTaxRate = isset($item['tax_rate']) && is_numeric($item['tax_rate'])
                ? (float)$item['tax_rate']
                : $taxRate;
            $itemTaxMultiplier = 1 + ($itemTaxRate / 100);
            $priceNet = $itemTaxMultiplier > 0 ? ($itemPrice / $itemTaxMultiplier) : $itemPrice;
            $rowTotal = number_format($priceNet * $itemQty, 2, ',', '.');
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($item['product_name'] ?? '-') . '</td>'
                . '<td>' . $itemQty . '</td>'
                . '<td>$' . number_format($priceNet, 2, ',', '.') . '</td>'
                . '<td>$' . $rowTotal . '</td>'
                . '</tr>';
        }

        $billingHtml = count($billingLines) > 0 ? implode('', array_map(fn($line) => '<div>' . htmlspecialchars($line) . '</div>', $billingLines)) : '<div>-</div>';
        $shippingHtml = '';
        if (!empty($shippingLines['line1'])) $shippingHtml .= '<div>' . htmlspecialchars($shippingLines['line1']) . '</div>';
        if (!empty($shippingLines['line2'])) $shippingHtml .= '<div>' . htmlspecialchars($shippingLines['line2']) . '</div>';
        $discountLabel = $discountCode !== '' ? ('Descuento (' . htmlspecialchars($discountCode) . ')') : 'Descuento';
        $discountRow = $discountTotal > 0
            ? ('<tr><td colspan="3">' . $discountLabel . '</td><td>-$' . number_format($discountTotal, 2, ',', '.') . '</td></tr>')
            : '';
        $taxLabel = $mixedVatRates
            ? 'IVA aplicado'
            : ('IVA (' . number_format($taxRate, 2, ',', '.') . '%)');

        return '<!doctype html>
<html lang="es">
<head>
  <!-- invoice_v2_tax_net -->
  <meta charset="utf-8" />
  <title>Comprobante interno ' . $invoiceNumber . '</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 32px; color: #1f2937; }
    .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
    .brand { display:flex; align-items:center; }
    .brand img { height:36px; }
    .meta { text-align:right; font-size:12px; color:#4b5563; }
    .grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; font-size:12px; }
    .box { border:1px solid #e5e7eb; padding:12px; border-radius:8px; }
    table { width:100%; border-collapse: collapse; margin-top:12px; font-size:12px; }
    th, td { border-bottom:1px solid #e5e7eb; padding:8px; text-align:left; }
    th { background:#f9fafb; text-transform: uppercase; font-size:11px; }
    .items-table { table-layout: fixed; }
    .items-table th:nth-child(2), .items-table td:nth-child(2) { text-align:center; width:80px; }
    .items-table th:nth-child(3), .items-table td:nth-child(3) { text-align:right; width:120px; }
    .items-table th:nth-child(4), .items-table td:nth-child(4) { text-align:right; width:120px; }
    .items-table tfoot td { padding:6px 8px; border-bottom:none; }
    .items-table tfoot td:last-child { text-align:right; }
    .items-table tfoot tr.total td { font-weight:700; font-size:14px; }
    .total { font-weight:700; font-size:14px; }
  </style>
</head>
<body>
  <div class="header">
    <div class="brand">
      <img src="' . htmlspecialchars($logoUrl) . '" alt="Para Mascotas EC" />
    </div>
    <div class="meta">
      <div>Comprobante interno: ' . htmlspecialchars($invoiceNumber) . '</div>
      <div>Fecha: ' . date('d/m/Y') . '</div>
    </div>
  </div>
  <div class="grid">
    <div class="box">
      <strong>Cliente</strong>
      <div>' . htmlspecialchars($customer['name'] ?? '-') . '</div>
      <div>' . htmlspecialchars($customer['email'] ?? '-') . '</div>
      <div>' . htmlspecialchars($customer['phone'] ?? '-') . '</div>
    </div>
    <div class="box">
      <strong>Método de pago</strong>
      <div>' . htmlspecialchars($payment) . '</div>
    </div>
    <div class="box">
      <strong>Dirección de envío</strong>
      ' . $shippingHtml . '
    </div>
    <div class="box">
      <strong>Dirección de facturación</strong>
      ' . $billingHtml . '
    </div>
    <div class="box">
      <strong>Empresa</strong>
      <div>' . htmlspecialchars($company ?: 'No aplica') . '</div>
    </div>
  </div>
  <div class="section">
    <strong>Artículos</strong>
    <table class="items-table">
      <thead>
        <tr>
          <th>Producto</th>
          <th>Cantidad</th>
          <th>Precio</th>
          <th>Sub total</th>
        </tr>
      </thead>
      <tbody>
        ' . $rows . '
      </tbody>
      <tfoot>
        <tr><td colspan="3">Subtotal sin IVA</td><td>$' . number_format($taxNetSubtotal, 2, ',', '.') . '</td></tr>
        <tr><td colspan="3">' . $taxLabel . '</td><td>$' . number_format($taxAmount, 2, ',', '.') . '</td></tr>
        ' . $discountRow . '
        <tr><td colspan="3">Envío</td><td>$' . number_format($shipping, 2, ',', '.') . '</td></tr>
        <tr class="total"><td colspan="3">Total</td><td>$' . number_format($total, 2, ',', '.') . '</td></tr>
      </tfoot>
    </table>
  </div>
</body>
</html>';
    }

    private function addTaxBreakdown($order) {
        if (!$order || !is_array($order)) return $order;
        $taxRate = $this->getTaxRate();
        $items = $order['items'] ?? [];
        $itemsSubtotal = isset($order['items_subtotal']) ? (float)$order['items_subtotal'] : 0;
        $storedVatSubtotal = isset($order['vat_subtotal']) ? (float)$order['vat_subtotal'] : 0;
        $storedVatAmount = isset($order['vat_amount']) ? (float)$order['vat_amount'] : 0;
        $hasStoredVatRate = array_key_exists('vat_rate', $order)
            && $order['vat_rate'] !== null
            && $order['vat_rate'] !== ''
            && is_numeric($order['vat_rate']);
        $storedVatRate = $hasStoredVatRate
            ? (ProductTaxPolicy::normalizeRate($order['vat_rate']) ?? 0.0)
            : null;
        $itemSummary = is_array($items) && count($items) > 0
            ? $this->summarizeOrderItemsTax($items, $hasStoredVatRate ? $storedVatRate : $taxRate)
            : null;

        if ($itemsSubtotal <= 0 && is_array($itemSummary)) {
            $itemsSubtotal = (float)($itemSummary['gross_subtotal'] ?? 0);
        }

        $netSubtotal = $storedVatSubtotal > 0
            ? $storedVatSubtotal
            : (is_array($itemSummary) ? (float)($itemSummary['net_subtotal'] ?? 0) : $itemsSubtotal);
        $taxAmount = $storedVatAmount > 0
            ? $storedVatAmount
            : (is_array($itemSummary) ? (float)($itemSummary['tax_amount'] ?? 0) : 0);
        $order['vat_rate'] = $hasStoredVatRate
            ? $storedVatRate
            : (is_array($itemSummary) ? (float)($itemSummary['vat_rate'] ?? $taxRate) : $taxRate);
        $order['mixed_vat_rates'] = is_array($itemSummary) ? !empty($itemSummary['mixed_vat_rates']) : false;
        $order['vat_subtotal'] = round($netSubtotal, 2);
        $order['vat_amount'] = round($taxAmount, 2);
        $order['items_subtotal'] = round($itemsSubtotal, 2);
        $discountTotal = isset($order['discount_total']) ? (float)$order['discount_total'] : 0;
        if ($discountTotal < 0) $discountTotal = 0;
        $order['discount_total'] = round($discountTotal, 2);
        if (!array_key_exists('discount_code', $order)) {
            $order['discount_code'] = null;
        }
        $shipping = isset($order['shipping']) ? (float)$order['shipping'] : null;
        if ($shipping === null || $shipping < 0) {
            $orderTotal = (float)($order['total'] ?? $itemsSubtotal);
            $shipping = $orderTotal - $itemsSubtotal;
            if ($shipping < 0) $shipping = 0;
        }
        $order['shipping'] = round($shipping, 2);

        $shippingTaxRate = isset($order['shipping_tax_rate']) ? (float)$order['shipping_tax_rate'] : $this->getShippingTaxRate();
        if ($shippingTaxRate < 0) $shippingTaxRate = 0;
        $shippingBase = isset($order['shipping_base']) ? (float)$order['shipping_base'] : null;
        if ($shippingBase === null || $shippingBase < 0) {
            $shippingBase = $shippingTaxRate > 0 ? ($order['shipping'] / (1 + ($shippingTaxRate / 100))) : $order['shipping'];
        }
        $shippingTaxAmount = isset($order['shipping_tax_amount']) ? (float)$order['shipping_tax_amount'] : null;
        if ($shippingTaxAmount === null || $shippingTaxAmount < 0) {
            $shippingTaxAmount = $shippingTaxRate > 0 ? ($shippingBase * ($shippingTaxRate / 100)) : 0;
        }
        $order['shipping_base'] = round($shippingBase, 2);
        $order['shipping_tax_rate'] = round($shippingTaxRate, 2);
        $order['shipping_tax_amount'] = round($shippingTaxAmount, 2);

        $shouldPersist = (!array_key_exists('items_subtotal', $order)
            || !array_key_exists('vat_subtotal', $order)
            || !array_key_exists('vat_amount', $order)
            || !array_key_exists('vat_rate', $order)
            || !isset($order['shipping'])
            || !isset($order['shipping_base'])
            || !isset($order['shipping_tax_amount'])
            || !isset($order['shipping_tax_rate'])
            || !array_key_exists('discount_total', $order)
            || !array_key_exists('discount_code', $order));
        if (!empty($order['id']) && $shouldPersist) {
            try {
                $stmt = $this->db->prepare('UPDATE "Order" SET items_subtotal = :items_subtotal, vat_subtotal = :vat_subtotal, vat_rate = :vat_rate, vat_amount = :vat_amount, shipping = :shipping, shipping_base = :shipping_base, shipping_tax_rate = :shipping_tax_rate, shipping_tax_amount = :shipping_tax_amount, discount_total = :discount_total, discount_code = COALESCE(discount_code, :discount_code) WHERE id = :id AND tenant_id = :tenant_id');
                $stmt->execute([
                    'id' => $order['id'],
                    'tenant_id' => $this->getTenantId(),
                    'items_subtotal' => $order['items_subtotal'],
                    'vat_subtotal' => $order['vat_subtotal'],
                    'vat_rate' => $order['vat_rate'],
                    'vat_amount' => $order['vat_amount'],
                    'shipping' => $order['shipping'],
                    'shipping_base' => $order['shipping_base'],
                    'shipping_tax_rate' => $order['shipping_tax_rate'],
                    'shipping_tax_amount' => $order['shipping_tax_amount'],
                    'discount_total' => $order['discount_total'],
                    'discount_code' => $order['discount_code']
                ]);
            } catch (\Exception $e) {
                // noop: avoid breaking read flow
            }
        }
        return $order;
    }

    private function formatAddressLines($addr) {
        if (!$addr || !is_array($addr)) return [];
        $nameLine = trim(($addr['firstName'] ?? '') . ' ' . ($addr['lastName'] ?? ''));
        $cityLine = trim(implode(', ', array_filter([$addr['city'] ?? null, $addr['state'] ?? null, $addr['zip'] ?? null])));
        $docType = trim((string)($addr['documentType'] ?? ''));
        $docNumber = trim((string)($addr['documentNumber'] ?? ''));
        $docLine = null;
        if ($docType !== '' && $docNumber !== '') {
            $docLine = 'Identificación: ' . $docType . ' ' . $docNumber;
        } elseif ($docNumber !== '') {
            $docLine = 'Identificación: ' . $docNumber;
        }
        $lines = array_filter([
            $nameLine ?: null,
            $addr['company'] ?? null,
            $docLine,
            $addr['street'] ?? null,
            $cityLine ?: null,
            $addr['country'] ?? null,
            $addr['phone'] ?? null,
            $addr['email'] ?? null
        ]);
        return array_values($lines);
    }

    private function translatePaymentMethod($method) {
        $value = strtolower((string)($method ?? ''));
        if ($value === 'credit' || $value === 'card' || $value === 'credit_card') {
            return 'Tarjeta de crédito/débito';
        }
        if ($value === 'transfer' || $value === 'bank_transfer') {
            return 'Transferencia bancaria';
        }
        if ($value === 'cash' || $value === 'cod') {
            return 'Pago contra entrega';
        }
        return $method ?: '—';
    }

    public function getRecentOrders($limit = 5) {
        $netExpr = $this->netSalesSql('o');
        $vatExpr = $this->vatAmountSql('o');
        $realizedStatus = $this->realizedSalesCondition('o');
        $safeLimit = max(1, min(50, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT o.id,
                   u.name as user_name,
                   u.email as user_email,
                   o.total,
                   $netExpr as vat_subtotal,
                   $vatExpr as vat_amount,
                   COALESCE(o.shipping, 0) as shipping,
                   COALESCE(o.vat_rate, 0) as vat_rate,
                   o.status,
                   o.created_at
            FROM \"Order\" o
            LEFT JOIN \"Customer\" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.tenant_id = :tenant_id
              AND $realizedStatus
            ORDER BY o.created_at DESC
            LIMIT $safeLimit
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getPickupQueue($limit = 10) {
        $safeLimit = max(1, min(100, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT o.id,
                   o.status,
                   o.created_at,
                   o.shipping_address,
                   u.name as user_name,
                   u.email as user_email
            FROM \"Order\" o
            LEFT JOIN \"Customer\" u ON o.user_id = u.id AND u.tenant_id = o.tenant_id
            WHERE o.tenant_id = :tenant_id
              AND LOWER(COALESCE(o.status, '')) IN ('pickup', 'ready_for_pickup', 'ready')
            ORDER BY o.created_at DESC
            LIMIT $safeLimit
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll();
    }

    public function getInventoryDeepDive() {
        $realizedStatus = $this->realizedSalesCondition('o');
        $thirtyDaySalesCte = "
            WITH sales_30d AS (
                SELECT
                    oi.product_id,
                    COALESCE(SUM(COALESCE(oi.quantity, 0)), 0) AS units_sold_30d
                FROM \"OrderItem\" oi
                JOIN \"Order\" o ON oi.order_id = o.id
                WHERE o.tenant_id = :tenant_id
                  AND $realizedStatus
                  AND o.created_at >= CURRENT_DATE - INTERVAL '30 days'
                GROUP BY oi.product_id
            )
        ";
        // High Value Stock (Top 5 by cost investment)
        $highValueStmt = $this->db->prepare("
            SELECT name, quantity, cost, (quantity * cost) as total_cost
            FROM \"Product\"
            WHERE tenant_id = :tenant_id AND quantity > 0
            ORDER BY total_cost DESC
            LIMIT 5
        ");
        $highValueStmt->execute(['tenant_id' => $this->getTenantId()]);
        $highValue = $highValueStmt->fetchAll();

        // Stock Risk (Low quantity)
        $stockRiskStmt = $this->db->prepare($thirtyDaySalesCte . "
            SELECT
                p.name,
                p.quantity,
                COALESCE(s.units_sold_30d, 0)::int AS units_sold_30d,
                ROUND((COALESCE(s.units_sold_30d, 0) / 30.0)::numeric, 2) AS avg_daily_units,
                CASE
                    WHEN COALESCE(s.units_sold_30d, 0) > 0
                        THEN CEIL(p.quantity / NULLIF((COALESCE(s.units_sold_30d, 0) / 30.0), 0))
                    WHEN p.quantity <= 0
                        THEN 0
                    ELSE NULL
                END AS estimated_days_left
            FROM \"Product\" p
            LEFT JOIN sales_30d s ON s.product_id = p.id
            WHERE p.tenant_id = :tenant_id
              AND (
                p.quantity <= 5
                OR (
                    COALESCE(s.units_sold_30d, 0) > 0
                    AND p.quantity / NULLIF((COALESCE(s.units_sold_30d, 0) / 30.0), 0) <= 14
                )
              )
            ORDER BY
                CASE
                    WHEN p.quantity <= 0 THEN 0
                    WHEN COALESCE(s.units_sold_30d, 0) > 0
                        THEN p.quantity / NULLIF((COALESCE(s.units_sold_30d, 0) / 30.0), 0)
                    ELSE 999999
                END ASC,
                p.quantity ASC
            LIMIT 5
        ");
        $stockRiskStmt->execute(['tenant_id' => $this->getTenantId()]);
        $stockRisk = $stockRiskStmt->fetchAll();

        // Expiration analysis (only products with stock and valid YYYY-MM-DD expiration date in attributes)
        $expiringSoonStmt = $this->db->prepare("
            SELECT
                t.id,
                t.legacy_id,
                t.name,
                t.quantity,
                t.expiration_date,
                t.expiration_alert_days,
                (t.expiration_date - CURRENT_DATE) AS days_to_expire
            FROM (
                SELECT
                    id,
                    legacy_id,
                    name,
                    quantity,
                    CASE
                        WHEN COALESCE(attributes->>'expirationDate', attributes->>'expiryDate') ~ '^\d{4}-\d{2}-\d{2}$'
                            THEN (COALESCE(attributes->>'expirationDate', attributes->>'expiryDate'))::date
                        ELSE NULL
                    END AS expiration_date,
                    CASE
                        WHEN COALESCE(attributes->>'expirationAlertDays', attributes->>'expiryAlertDays') ~ '^\d+$'
                            THEN GREATEST(0, (COALESCE(attributes->>'expirationAlertDays', attributes->>'expiryAlertDays'))::int)
                        ELSE 30
                    END AS expiration_alert_days
                FROM \"Product\"
                WHERE tenant_id = :tenant_id
                  AND quantity > 0
            ) t
            WHERE t.expiration_date IS NOT NULL
              AND t.expiration_date >= CURRENT_DATE
              AND t.expiration_date <= (CURRENT_DATE + t.expiration_alert_days)
            ORDER BY t.expiration_date ASC, t.quantity DESC
            LIMIT 8
        ");
        $expiringSoonStmt->execute(['tenant_id' => $this->getTenantId()]);
        $expiringSoon = $expiringSoonStmt->fetchAll();

        $expiredStmt = $this->db->prepare("
            SELECT
                t.id,
                t.legacy_id,
                t.name,
                t.quantity,
                t.expiration_date,
                (CURRENT_DATE - t.expiration_date) AS days_expired
            FROM (
                SELECT
                    id,
                    legacy_id,
                    name,
                    quantity,
                    CASE
                        WHEN COALESCE(attributes->>'expirationDate', attributes->>'expiryDate') ~ '^\d{4}-\d{2}-\d{2}$'
                            THEN (COALESCE(attributes->>'expirationDate', attributes->>'expiryDate'))::date
                        ELSE NULL
                    END AS expiration_date
                FROM \"Product\"
                WHERE tenant_id = :tenant_id
                  AND quantity > 0
            ) t
            WHERE t.expiration_date IS NOT NULL
              AND t.expiration_date < CURRENT_DATE
            ORDER BY t.expiration_date ASC, t.quantity DESC
            LIMIT 8
        ");
        $expiredStmt->execute(['tenant_id' => $this->getTenantId()]);
        $expired = $expiredStmt->fetchAll();

        // Stock Health Summary
        $summaryStmt = $this->db->prepare("
            SELECT
                COUNT(*) FILTER (WHERE t.quantity = 0) AS out_of_stock,
                COUNT(*) FILTER (WHERE t.quantity > 0 AND t.quantity <= 5) AS low_stock,
                COUNT(*) FILTER (WHERE t.quantity > 50) AS overstock,
                COUNT(*) FILTER (
                    WHERE t.quantity > 0
                      AND t.expiration_date IS NOT NULL
                      AND t.expiration_date < CURRENT_DATE
                ) AS expired_products,
                COUNT(*) FILTER (
                    WHERE t.quantity > 0
                      AND t.expiration_date IS NOT NULL
                      AND t.expiration_date >= CURRENT_DATE
                      AND t.expiration_date <= (CURRENT_DATE + t.expiration_alert_days)
                ) AS expiring_products
            FROM (
                SELECT
                    quantity,
                    CASE
                        WHEN COALESCE(attributes->>'expirationDate', attributes->>'expiryDate') ~ '^\d{4}-\d{2}-\d{2}$'
                            THEN (COALESCE(attributes->>'expirationDate', attributes->>'expiryDate'))::date
                        ELSE NULL
                    END AS expiration_date,
                    CASE
                        WHEN COALESCE(attributes->>'expirationAlertDays', attributes->>'expiryAlertDays') ~ '^\d+$'
                            THEN GREATEST(0, (COALESCE(attributes->>'expirationAlertDays', attributes->>'expiryAlertDays'))::int)
                        ELSE 30
                    END AS expiration_alert_days
                FROM \"Product\"
                WHERE tenant_id = :tenant_id
            ) t
        ");
        $summaryStmt->execute(['tenant_id' => $this->getTenantId()]);
        $summary = $summaryStmt->fetch();

        return [
            'highValueItems' => $highValue,
            'riskItems' => $stockRisk,
            'expiringItems' => $expiringSoon,
            'expiredItems' => $expired,
            'health' => $summary
        ];
    }

    public function getAOVDeepDive() {
        $netExpr = $this->netSalesSql('o');
        $realizedStatus = $this->realizedSalesCondition('o');
        $distributionStmt = $this->db->prepare("
            WITH orders AS (
                SELECT $netExpr as net_total
                FROM \"Order\" o
                WHERE o.tenant_id = :tenant_id AND $realizedStatus
            ),
            bucketed AS (
                SELECT
                    CASE
                        WHEN net_total < 20 THEN 'Menor a $20'
                        WHEN net_total < 50 THEN '$20 a $49.99'
                        WHEN net_total < 100 THEN '$50 a $99.99'
                        ELSE '$100 o más'
                    END as bucket,
                    CASE
                        WHEN net_total < 20 THEN 1
                        WHEN net_total < 50 THEN 2
                        WHEN net_total < 100 THEN 3
                        ELSE 4
                    END as sort_order,
                    net_total
                FROM orders
            ),
            totals AS (
                SELECT COUNT(*) AS total_orders, COALESCE(SUM(net_total), 0) AS total_revenue
                FROM orders
            )
            SELECT
                b.bucket,
                COUNT(*)::int as count,
                COALESCE(SUM(b.net_total), 0) as revenue,
                COALESCE(AVG(b.net_total), 0) as avg_order_value,
                CASE WHEN MAX(t.total_orders) > 0 THEN ROUND((COUNT(*)::numeric / MAX(t.total_orders)) * 100, 1) ELSE 0 END as order_share,
                CASE WHEN MAX(t.total_revenue) > 0 THEN ROUND((COALESCE(SUM(b.net_total), 0) / MAX(t.total_revenue)) * 100, 1) ELSE 0 END as revenue_share
            FROM bucketed b
            CROSS JOIN totals t
            GROUP BY b.bucket, b.sort_order
            ORDER BY b.sort_order ASC
        ");
        $distributionStmt->execute(['tenant_id' => $this->getTenantId()]);
        $distribution = $distributionStmt->fetchAll();

        return [
            'distribution' => $distribution
        ];
    }
}
