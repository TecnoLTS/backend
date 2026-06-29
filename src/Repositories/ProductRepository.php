<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\CatalogInventory\Domain\CatalogInventoryDomain;
use App\Support\ProductAudience;
use App\Support\ProductSeoMetadata;

class ProductRepository {
    private $db;
    private $taxRateCache = null;
    private $pricingSettingsCache = null;

    public function __construct() {
        $this->db = Database::getModuleInstance(CatalogInventoryDomain::KEY);
    }

    private function getBaseQuery(bool $includeProcurement = false) {
        $procurementSelect = '';
        $procurementJoin = '';

        if ($includeProcurement) {
            $procurementSelect = '
          , purchase.last_purchase_invoice_id AS "lastPurchaseInvoiceId"
          , purchase.last_purchase_invoice_number AS "lastPurchaseInvoiceNumber"
          , purchase.last_purchase_supplier_name AS "lastPurchaseSupplierName"
          , purchase.last_purchase_supplier_document AS "lastPurchaseSupplierDocument"
          , purchase.last_purchase_issued_at AS "lastPurchaseIssuedAt"
          , purchase.last_purchase_received_at AS "lastPurchaseReceivedAt"
          , purchase.last_purchase_quantity AS "lastPurchaseQuantity"
          , purchase.last_purchase_unit_cost AS "lastPurchaseUnitCost"
          , purchase.last_purchase_line_total AS "lastPurchaseLineTotal"
          , purchase_stats.purchase_entries_count AS "purchaseEntriesCount"
          , purchase_stats.purchased_units_total AS "purchasedUnitsTotal"
          , purchase_stats.remaining_units_from_purchases AS "remainingUnitsFromPurchases"
          , purchase_stats.last_purchase_at AS "lastPurchaseAt"
          , open_stock.open_lots_count AS "openLotsCount"
          , open_stock.remaining_units_total AS "remainingUnitsTotal"
          , open_stock.remaining_cost_total AS "remainingCostTotal"
          , open_stock.weighted_remaining_unit_cost AS "weightedRemainingUnitCost"
          , open_stock.min_remaining_unit_cost AS "minRemainingUnitCost"
          , open_stock.max_remaining_unit_cost AS "maxRemainingUnitCost"
          , sales_stats.sales_orders_count AS "salesOrdersCount"
          , sales_stats.sales_units_total AS "salesUnitsTotal"
          , sales_stats.last_sale_at AS "lastSaleAt"
        ';
            $procurementJoin = '
        LEFT JOIN LATERAL (
          SELECT
            il.purchase_invoice_id AS last_purchase_invoice_id,
            pi.invoice_number AS last_purchase_invoice_number,
            pi.supplier_name AS last_purchase_supplier_name,
            pi.supplier_document AS last_purchase_supplier_document,
            pi.issued_at AS last_purchase_issued_at,
            il.received_at AS last_purchase_received_at,
            COALESCE(pii.quantity, il.initial_quantity) AS last_purchase_quantity,
            COALESCE(pii.unit_cost, il.unit_cost, 0) AS last_purchase_unit_cost,
            COALESCE(
              pii.line_total,
              COALESCE(pii.quantity, il.initial_quantity) * COALESCE(pii.unit_cost, il.unit_cost, 0)
            ) AS last_purchase_line_total
          FROM "InventoryLot" il
          LEFT JOIN "PurchaseInvoice" pi
            ON pi.id = il.purchase_invoice_id
           AND pi.tenant_id = il.tenant_id
          LEFT JOIN "PurchaseInvoiceItem" pii
            ON pii.id = il.purchase_invoice_item_id
           AND pii.tenant_id = il.tenant_id
          WHERE il.product_id = p.id
            AND il.tenant_id = p.tenant_id
            AND il.purchase_invoice_id IS NOT NULL
          ORDER BY COALESCE(pi.issued_at::timestamp, il.received_at, il.created_at) DESC,
                   il.created_at DESC,
                   il.id DESC
          LIMIT 1
        ) purchase ON true
        LEFT JOIN LATERAL (
          SELECT
            COUNT(*)::int AS purchase_entries_count,
            COALESCE(SUM(il.initial_quantity), 0)::int AS purchased_units_total,
            COALESCE(SUM(il.remaining_quantity), 0)::int AS remaining_units_from_purchases,
            MAX(COALESCE(pi.issued_at::timestamp, il.received_at, il.created_at)) AS last_purchase_at
          FROM "InventoryLot" il
          LEFT JOIN "PurchaseInvoice" pi
            ON pi.id = il.purchase_invoice_id
           AND pi.tenant_id = il.tenant_id
          WHERE il.product_id = p.id
            AND il.tenant_id = p.tenant_id
            AND il.purchase_invoice_id IS NOT NULL
        ) purchase_stats ON true
        LEFT JOIN LATERAL (
          SELECT
            COUNT(*)::int AS open_lots_count,
            COALESCE(SUM(il.remaining_quantity), 0)::int AS remaining_units_total,
            COALESCE(SUM(il.remaining_quantity * il.unit_cost), 0) AS remaining_cost_total,
            CASE
              WHEN COALESCE(SUM(il.remaining_quantity), 0) > 0
                THEN COALESCE(SUM(il.remaining_quantity * il.unit_cost), 0) / SUM(il.remaining_quantity)
              ELSE 0
            END AS weighted_remaining_unit_cost,
            COALESCE(MIN(il.unit_cost), 0) AS min_remaining_unit_cost,
            COALESCE(MAX(il.unit_cost), 0) AS max_remaining_unit_cost
          FROM "InventoryLot" il
          WHERE il.product_id = p.id
            AND il.tenant_id = p.tenant_id
            AND il.remaining_quantity > 0
        ) open_stock ON true
        LEFT JOIN LATERAL (
          SELECT
            COUNT(DISTINCT o.id)::int AS sales_orders_count,
            COALESCE(SUM(oi.quantity), 0)::int AS sales_units_total,
            MAX(o.created_at) AS last_sale_at
          FROM "OrderItem" oi
          JOIN "Order" o
            ON o.id = oi.order_id
           AND o.tenant_id = p.tenant_id
          WHERE oi.product_id = p.id
            AND LOWER(COALESCE(o.status, \'pending\')) IN (\'delivered\', \'completed\')
        ) sales_stats ON true
        ';
        }

        return '
        SELECT
          p.id,
          p.legacy_id AS "legacyId",
          p.category AS "category",
          p.product_type AS "productType",
          p.name AS "name",
          p.gender AS "gender",
          p.is_new AS "new",
          p.is_sale AS "sale",
          p.is_published AS "published",
          p.price AS "price",
          p.original_price AS "originPrice",
          p.cost AS "cost", 
          p.brand AS "brand",
          p.sold AS "sold",
          p.quantity AS "quantity",
          p.description AS "description",
          p.action AS "action",
          p.slug AS "slug",
          p.created_at AS "createdAt",
          p.updated_at AS "updatedAt",
          COALESCE(p.attributes, \'{}\') AS attributes,
          COALESCE(img.images, \'[]\') AS images,
          COALESCE(img.thumbs, \'[]\') AS thumbs,
          COALESCE(img.image_meta, \'[]\') AS "imageMeta",
          COALESCE(var.variations, \'[]\') AS variations' . $procurementSelect . '
        FROM "Product" p
        LEFT JOIN LATERAL (
          SELECT
            json_agg(i.url ORDER BY i.display_order, i.id) FILTER (WHERE COALESCE(i.kind, \'gallery\') = \'gallery\') AS images,
            json_agg(i.url ORDER BY i.display_order, i.id) FILTER (WHERE COALESCE(i.kind, \'gallery\') = \'thumb\') AS thumbs,
            json_agg(jsonb_build_object(
              \'url\', i.url,
              \'width\', i.width,
              \'height\', i.height,
              \'kind\', COALESCE(i.kind, \'gallery\'),
              \'altText\', i.alt_text,
              \'displayOrder\', i.display_order
            ) ORDER BY i.display_order, i.id) AS image_meta
          FROM "Image" i
          WHERE i.product_id = p.id
        ) img ON true
        LEFT JOIN LATERAL (
          SELECT json_agg(jsonb_build_object(
            \'color\', v.color,
            \'colorCode\', v.color_code,
            \'colorImage\', v.color_image,
            \'image\', v.image
          ) ORDER BY v.id) AS variations
          FROM "Variation" v
          WHERE v.product_id = p.id
        ) var ON true
        ' . $procurementJoin;
    }

    private function getArchivedFilter(bool $includeArchived = false): string {
        if ($includeArchived) {
            return '';
        }

        return " AND COALESCE(p.attributes->>'archived', 'false') <> 'true'";
    }

    public function getAll(array $options = []) {
        $includeUnpublished = (bool)($options['includeUnpublished'] ?? false);
        $includeProcurement = (bool)($options['includeProcurement'] ?? false);
        $includeArchived = (bool)($options['includeArchived'] ?? false);
        $includeOutOfStock = array_key_exists('includeOutOfStock', $options)
            ? (bool)$options['includeOutOfStock']
            : $includeUnpublished;
        $visibilityFilter = $includeUnpublished ? '' : ' AND p.is_published = true';
        $stockFilter = $includeOutOfStock ? '' : ' AND p.quantity > 0';
        $archivedFilter = $this->getArchivedFilter($includeArchived);
        $sql = $this->getBaseQuery($includeProcurement) . ' WHERE p.tenant_id = :tenant_id' . $visibilityFilter . $stockFilter . $archivedFilter . ' ORDER BY p.created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'formatRow'], $rows);
    }

    public function searchForBilling(string $search = '', int $limit = 12, array $options = []): array {
        $includeUnpublished = (bool)($options['includeUnpublished'] ?? true);
        $includeProcurement = (bool)($options['includeProcurement'] ?? false);
        $includeArchived = (bool)($options['includeArchived'] ?? false);
        $includeOutOfStock = array_key_exists('includeOutOfStock', $options)
            ? (bool)$options['includeOutOfStock']
            : true;
        $limit = max(1, min(101, $limit));
        $search = trim($search);
        $params = ['tenant_id' => $this->getTenantId()];
        $visibilityFilter = $includeUnpublished ? '' : ' AND p.is_published = true';
        $stockFilter = $includeOutOfStock ? '' : ' AND p.quantity > 0';
        $archivedFilter = $this->getArchivedFilter($includeArchived);
        $searchFilter = '';
        $orderBy = ' ORDER BY (p.quantity > 0) DESC, p.updated_at DESC, p.created_at DESC, p.id DESC';

        if ($search !== '') {
            $tokens = array_slice(preg_split('/\s+/', $search) ?: [], 0, 6);
            foreach ($tokens as $index => $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }
                $key = 'q' . $index;
                $params[$key] = '%' . $token . '%';
                $searchFilter .= "
                    AND (
                      p.name ILIKE :{$key}
                      OR COALESCE(p.brand, '') ILIKE :{$key}
                      OR COALESCE(p.category, '') ILIKE :{$key}
                      OR COALESCE(p.product_type, '') ILIKE :{$key}
                      OR COALESCE(p.attributes->>'sku', '') ILIKE :{$key}
                      OR COALESCE(p.attributes->>'presentation', '') ILIKE :{$key}
                      OR COALESCE(p.attributes->>'variantLabel', '') ILIKE :{$key}
                      OR ROUND(p.price::numeric, 2)::text ILIKE :{$key}
                      OR ROUND((
                        p.price * (
                          1 + (
                            CASE
                              WHEN COALESCE(p.attributes->>'taxRate', '') ~ '^[0-9]+(\\.[0-9]+)?$'
                                THEN (p.attributes->>'taxRate')::numeric
                              ELSE 15
                            END
                          ) / 100
                        )
                      )::numeric, 2)::text ILIKE :{$key}
                    )
                ";
            }

            $params['exact_search'] = strtolower($search);
            $params['prefix_search'] = $search . '%';
            $orderBy = "
                ORDER BY
                  (p.quantity > 0) DESC,
                  CASE
                    WHEN LOWER(COALESCE(p.attributes->>'sku', '')) = :exact_search THEN 0
                    WHEN p.name ILIKE :prefix_search THEN 1
                    WHEN COALESCE(p.brand, '') ILIKE :prefix_search THEN 2
                    ELSE 3
                  END,
                  p.name ASC,
                  p.id DESC
            ";
        }

        $sql = $this->getBaseQuery($includeProcurement)
            . ' WHERE p.tenant_id = :tenant_id'
            . $visibilityFilter
            . $stockFilter
            . $archivedFilter
            . $searchFilter
            . $orderBy
            . ' LIMIT ' . $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map([$this, 'formatRow'], $rows);
    }

    public function getById($idOrLegacyOrSlug, array $options = []) {
        $includeUnpublished = (bool)($options['includeUnpublished'] ?? false);
        $includeProcurement = (bool)($options['includeProcurement'] ?? false);
        $includeProcurementDetail = (bool)($options['includeProcurementDetail'] ?? false);
        $includeArchived = (bool)($options['includeArchived'] ?? false);
        $includeOutOfStock = array_key_exists('includeOutOfStock', $options)
            ? (bool)$options['includeOutOfStock']
            : $includeUnpublished;
        $visibilityFilter = $includeUnpublished ? '' : ' AND p.is_published = true';
        $stockFilter = $includeOutOfStock ? '' : ' AND p.quantity > 0';
        $archivedFilter = $this->getArchivedFilter($includeArchived);
        $sql = $this->getBaseQuery($includeProcurement) . ' WHERE p.tenant_id = :tenant_id AND (p.id = :id OR p.legacy_id = :id OR p.slug = :id)' . $visibilityFilter . $stockFilter . $archivedFilter . ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $idOrLegacyOrSlug,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $formatted = $this->formatRow($row);
        if ($includeProcurementDetail) {
            $formatted['inventory']['procurementDetail'] = $this->buildProcurementDetail($formatted);
        }

        return $formatted;
    }

    public function getMovementSummary($idOrLegacyOrSlug, string $periodKey = 'month'): ?array {
        $tenantId = $this->getTenantId();
        $period = $this->resolveMovementPeriod($periodKey);

        $stmt = $this->db->prepare('
            SELECT
                id,
                legacy_id,
                name,
                category,
                product_type,
                quantity,
                sold,
                price,
                cost,
                COALESCE(attributes, \'{}\') AS attributes
            FROM "Product" p
            WHERE p.tenant_id = :tenant_id
              AND (p.id = :id OR p.legacy_id = :id OR p.slug = :id)
              AND COALESCE(p.attributes->>\'archived\', \'false\') <> \'true\'
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $idOrLegacyOrSlug,
            'tenant_id' => $tenantId,
        ]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }

        $attributes = json_decode($product['attributes'] ?? '{}', true) ?: [];
        $productId = (string)$product['id'];
        $dateFilter = '';
        $dateParams = [];
        if ($period['start_date'] !== null && $period['end_exclusive'] !== null) {
            $dateFilter = " AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date >= CAST(:start_date AS date)
                            AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date < CAST(:end_exclusive AS date)";
            $dateParams = [
                'start_date' => $period['start_date'],
                'end_exclusive' => $period['end_exclusive'],
            ];
        }

        $fallbackCost = (float)($product['cost'] ?? 0);
        $salesParams = array_merge([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'fallback_cost' => $fallbackCost,
        ], $dateParams);
        $salesStmt = $this->db->prepare("
            SELECT
                COUNT(DISTINCT o.id)::int AS orders_count,
                COALESCE(SUM(COALESCE(oi.quantity, 0)), 0)::int AS units_sold,
                COALESCE(SUM(COALESCE(oi.quantity, 0) * COALESCE(oi.price, 0)), 0) AS gross_revenue,
                COALESCE(SUM(
                    CASE
                        WHEN COALESCE(oi.net_total, 0) > 0 THEN oi.net_total
                        WHEN COALESCE(oi.price_net, 0) > 0 THEN COALESCE(oi.quantity, 0) * oi.price_net
                        ELSE COALESCE(oi.quantity, 0) * COALESCE(oi.price, 0)
                    END
                ), 0) AS net_revenue,
                COALESCE(SUM(
                    CASE
                        WHEN COALESCE(oi.cost_total, 0) > 0 THEN oi.cost_total
                        WHEN COALESCE(oi.unit_cost, 0) > 0 THEN COALESCE(oi.quantity, 0) * oi.unit_cost
                        ELSE COALESCE(oi.quantity, 0) * CAST(:fallback_cost AS numeric)
                    END
                ), 0) AS cost,
                MAX(o.created_at) AS last_sale_at
            FROM \"OrderItem\" oi
            JOIN \"Order\" o
              ON o.id = oi.order_id
             AND o.tenant_id = :tenant_id
            WHERE oi.product_id = :product_id
              AND LOWER(COALESCE(o.status, 'pending')) IN ('completed', 'delivered')
              {$dateFilter}
        ");
        $salesStmt->execute($salesParams);
        $salesRow = $salesStmt->fetch() ?: [];

        $salesRowsStmt = $this->db->prepare("
            SELECT
                o.id,
                o.created_at,
                o.status,
                COALESCE(oi.quantity, 0)::int AS quantity,
                COALESCE(oi.price, 0) AS price,
                CASE
                    WHEN COALESCE(oi.net_total, 0) > 0 THEN oi.net_total
                    WHEN COALESCE(oi.price_net, 0) > 0 THEN COALESCE(oi.quantity, 0) * oi.price_net
                    ELSE COALESCE(oi.quantity, 0) * COALESCE(oi.price, 0)
                END AS net_total,
                CASE
                    WHEN COALESCE(oi.cost_total, 0) > 0 THEN oi.cost_total
                    WHEN COALESCE(oi.unit_cost, 0) > 0 THEN COALESCE(oi.quantity, 0) * oi.unit_cost
                    ELSE COALESCE(oi.quantity, 0) * CAST(:fallback_cost AS numeric)
                END AS cost_total,
                u.name AS customer_name,
                u.email AS customer_email
            FROM \"OrderItem\" oi
            JOIN \"Order\" o
              ON o.id = oi.order_id
             AND o.tenant_id = :tenant_id
            LEFT JOIN \"Customer\" u
              ON u.id = o.user_id
             AND u.tenant_id = o.tenant_id
            WHERE oi.product_id = :product_id
              AND LOWER(COALESCE(o.status, 'pending')) IN ('completed', 'delivered')
              {$dateFilter}
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT 20
        ");
        $salesRowsStmt->execute($salesParams);
        $salesRows = array_map(static function ($row) {
            $net = round((float)($row['net_total'] ?? 0), 2);
            $cost = round((float)($row['cost_total'] ?? 0), 2);
            return [
                'id' => (string)($row['id'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
                'status' => (string)($row['status'] ?? ''),
                'quantity' => (int)($row['quantity'] ?? 0),
                'price' => round((float)($row['price'] ?? 0), 2),
                'net_total' => $net,
                'cost_total' => $cost,
                'profit' => round($net - $cost, 2),
                'customer_name' => $row['customer_name'] ? (string)$row['customer_name'] : null,
                'customer_email' => $row['customer_email'] ? (string)$row['customer_email'] : null,
            ];
        }, $salesRowsStmt->fetchAll() ?: []);

        $purchaseDateExpr = "COALESCE(pi.issued_at::date, il.received_at::date, il.created_at::date)";
        $purchaseDateFilter = '';
        if ($period['start_date'] !== null && $period['end_exclusive'] !== null) {
            $purchaseDateFilter = " AND {$purchaseDateExpr} >= CAST(:start_date AS date)
                                    AND {$purchaseDateExpr} < CAST(:end_exclusive AS date)";
        }
        $purchaseParams = array_merge([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
        ], $dateParams);
        $purchaseStmt = $this->db->prepare("
            SELECT
                COUNT(*)::int AS entries_count,
                COUNT(DISTINCT il.purchase_invoice_id)::int AS invoices_count,
                COALESCE(SUM(il.initial_quantity), 0)::int AS purchased_units,
                COALESCE(SUM(il.remaining_quantity), 0)::int AS remaining_units,
                COALESCE(SUM(il.initial_quantity * il.unit_cost), 0) AS purchase_cost,
                MAX({$purchaseDateExpr}) AS last_purchase_at
            FROM \"InventoryLot\" il
            LEFT JOIN \"PurchaseInvoice\" pi
              ON pi.id = il.purchase_invoice_id
             AND pi.tenant_id = il.tenant_id
            WHERE il.product_id = :product_id
              AND il.tenant_id = :tenant_id
              AND il.purchase_invoice_id IS NOT NULL
              {$purchaseDateFilter}
        ");
        $purchaseStmt->execute($purchaseParams);
        $purchaseRow = $purchaseStmt->fetch() ?: [];

        $purchaseRowsStmt = $this->db->prepare("
            SELECT
                il.id,
                il.purchase_invoice_id,
                pi.invoice_number,
                pi.supplier_name,
                pi.supplier_document,
                {$purchaseDateExpr} AS purchase_date,
                COALESCE(il.initial_quantity, 0)::int AS purchased_quantity,
                COALESCE(il.remaining_quantity, 0)::int AS remaining_quantity,
                COALESCE(il.unit_cost, 0) AS unit_cost,
                COALESCE(il.initial_quantity * il.unit_cost, 0) AS purchase_total
            FROM \"InventoryLot\" il
            LEFT JOIN \"PurchaseInvoice\" pi
              ON pi.id = il.purchase_invoice_id
             AND pi.tenant_id = il.tenant_id
            WHERE il.product_id = :product_id
              AND il.tenant_id = :tenant_id
              AND il.purchase_invoice_id IS NOT NULL
              {$purchaseDateFilter}
            ORDER BY {$purchaseDateExpr} DESC, il.created_at DESC, il.id DESC
            LIMIT 20
        ");
        $purchaseRowsStmt->execute($purchaseParams);
        $purchaseRows = array_map(static function ($row) {
            return [
                'id' => (string)($row['id'] ?? ''),
                'purchase_invoice_id' => $row['purchase_invoice_id'] ? (string)$row['purchase_invoice_id'] : null,
                'invoice_number' => $row['invoice_number'] ? (string)$row['invoice_number'] : null,
                'supplier_name' => $row['supplier_name'] ? (string)$row['supplier_name'] : null,
                'supplier_document' => $row['supplier_document'] ? (string)$row['supplier_document'] : null,
                'purchase_date' => $row['purchase_date'] ?? null,
                'purchased_quantity' => (int)($row['purchased_quantity'] ?? 0),
                'remaining_quantity' => (int)($row['remaining_quantity'] ?? 0),
                'unit_cost' => round((float)($row['unit_cost'] ?? 0), 4),
                'purchase_total' => round((float)($row['purchase_total'] ?? 0), 2),
            ];
        }, $purchaseRowsStmt->fetchAll() ?: []);

        $inventoryStmt = $this->db->prepare('
            SELECT
                COUNT(*) FILTER (WHERE il.purchase_invoice_id IS NOT NULL)::int AS purchase_entries_total,
                COALESCE(SUM(il.initial_quantity) FILTER (WHERE il.purchase_invoice_id IS NOT NULL), 0)::int AS purchased_units_total,
                COALESCE(SUM(il.remaining_quantity) FILTER (WHERE il.purchase_invoice_id IS NOT NULL), 0)::int AS remaining_purchase_units_total,
                COALESCE(SUM(il.remaining_quantity), 0)::int AS remaining_lot_units_total,
                COUNT(*) FILTER (WHERE il.remaining_quantity > 0)::int AS open_lots_count
            FROM "InventoryLot" il
            WHERE il.product_id = :product_id
              AND il.tenant_id = :tenant_id
        ');
        $inventoryStmt->execute([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
        ]);
        $inventoryRow = $inventoryStmt->fetch() ?: [];

        $salesNet = round((float)($salesRow['net_revenue'] ?? 0), 2);
        $salesCost = round((float)($salesRow['cost'] ?? 0), 2);

        return [
            'product' => [
                'id' => $productId,
                'legacy_id' => $product['legacy_id'] ? (string)$product['legacy_id'] : null,
                'name' => (string)($product['name'] ?? ''),
                'category' => (string)($product['category'] ?? ''),
                'product_type' => $product['product_type'] ? (string)$product['product_type'] : null,
                'sku' => isset($attributes['sku']) ? trim((string)$attributes['sku']) : '',
                'quantity' => max(0, (int)($product['quantity'] ?? 0)),
                'sold_field' => max(0, (int)($product['sold'] ?? 0)),
            ],
            'period' => $period,
            'sales' => [
                'orders_count' => (int)($salesRow['orders_count'] ?? 0),
                'units_sold' => (int)($salesRow['units_sold'] ?? 0),
                'gross_revenue' => round((float)($salesRow['gross_revenue'] ?? 0), 2),
                'net_revenue' => $salesNet,
                'cost' => $salesCost,
                'profit' => round($salesNet - $salesCost, 2),
                'last_sale_at' => $salesRow['last_sale_at'] ?? null,
                'orders' => $salesRows,
            ],
            'purchases' => [
                'entries_count' => (int)($purchaseRow['entries_count'] ?? 0),
                'invoices_count' => (int)($purchaseRow['invoices_count'] ?? 0),
                'purchased_units' => (int)($purchaseRow['purchased_units'] ?? 0),
                'remaining_units' => (int)($purchaseRow['remaining_units'] ?? 0),
                'purchase_cost' => round((float)($purchaseRow['purchase_cost'] ?? 0), 2),
                'last_purchase_at' => $purchaseRow['last_purchase_at'] ?? null,
                'lots' => $purchaseRows,
            ],
            'inventory' => [
                'current_stock' => max(0, (int)($product['quantity'] ?? 0)),
                'purchase_entries_total' => (int)($inventoryRow['purchase_entries_total'] ?? 0),
                'purchased_units_total' => (int)($inventoryRow['purchased_units_total'] ?? 0),
                'remaining_purchase_units_total' => (int)($inventoryRow['remaining_purchase_units_total'] ?? 0),
                'remaining_lot_units_total' => (int)($inventoryRow['remaining_lot_units_total'] ?? 0),
                'open_lots_count' => (int)($inventoryRow['open_lots_count'] ?? 0),
            ],
        ];
    }

    public function skuExists(string $sku, ?string $excludeProductId = null): bool {
        $normalizedSku = strtoupper(trim($sku));
        if ($normalizedSku === '') {
            return false;
        }

        $sql = '
            SELECT 1
            FROM "Product"
            WHERE tenant_id = :tenant_id
              AND UPPER(COALESCE(attributes->>\'sku\', \'\')) = :sku
              AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'
        ';

        $params = [
            'tenant_id' => $this->getTenantId(),
            'sku' => $normalizedSku,
        ];

        if ($excludeProductId !== null && trim($excludeProductId) !== '') {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeProductId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (bool)$stmt->fetchColumn();
    }

    private function formatRow($row) {
        $row['images'] = json_decode($row['images'] ?? '[]', true);
        $row['thumbImage'] = json_decode($row['thumbs'] ?? '[]', true);
        $row['imageMeta'] = json_decode($row['imageMeta'] ?? '[]', true);
        $row['variations'] = json_decode($row['variations'] ?? '[]', true);
        $row['attributes'] = $this->normalizeProductAttributes(json_decode($row['attributes'] ?? '{}', true) ?: []);
        $publishedRaw = $row['published'] ?? true;
        if (is_bool($publishedRaw)) {
            $row['published'] = $publishedRaw;
        } else {
            $row['published'] = in_array(strtolower(trim((string)$publishedRaw)), ['1', 'true', 't', 'yes', 'on'], true);
        }
        $expirationDate = $this->normalizeExpirationDate(
            $row['attributes']['expirationDate']
                ?? $row['attributes']['expiryDate']
                ?? null
        );
        $expirationAlertDays = $this->normalizeExpirationAlertDays(
            $row['attributes']['expirationAlertDays']
                ?? $row['attributes']['expiryAlertDays']
                ?? null
        );

        $row['images'] = array_map([$this, 'normalizeImageUrl'], $row['images']);
        $row['thumbImage'] = array_map([$this, 'normalizeImageUrl'], $row['thumbImage']);
        $row['imageMeta'] = array_map(function ($item) {
            if (is_array($item) && isset($item['url'])) {
                $item['url'] = $this->normalizeImageUrl($item['url']);
            }
            return $item;
        }, $row['imageMeta']);
        $row['variations'] = array_map(function ($variation) {
            if (isset($variation['image'])) {
                $variation['image'] = $this->normalizeImageUrl($variation['image']);
            }
            if (isset($variation['colorImage'])) {
                $variation['colorImage'] = $this->normalizeImageUrl($variation['colorImage']);
            }
            return $variation;
        }, $row['variations']);

        ProductSeoMetadata::applyDefaults($row);

        $taxRate = $this->getProductTaxRateForAttributes($row['attributes']);
        $taxMultiplier = $this->getProductTaxMultiplierForAttributes($row['attributes']);
        $row['price'] = round(floatval($row['price'] ?? 0) * $taxMultiplier, 2);
        $row['originPrice'] = round(floatval($row['originPrice'] ?? 0) * $taxMultiplier, 2);
        $row['tax'] = [
            'rate' => round($taxRate, 2),
            'multiplier' => round($taxMultiplier, 4),
            'exempt' => $taxRate <= 0,
        ];
        
        // Smart Business Logic
        $cost = floatval($row['cost'] ?? 0);
        $price = floatval($row['price'] ?? 0);
        $priceNet = $taxMultiplier > 0 ? ($price / $taxMultiplier) : $price;
        $pricing = $this->getPricingSettings();

        if ($cost > 0) {
            $minMargin = $pricing['minMargin'];
            $baseMargin = $pricing['baseMargin'];
            $targetMargin = $pricing['targetMargin'];
            $promoBuffer = $pricing['promoBuffer'];
            $strategy = $pricing['strategy'];
            $rounding = $pricing['rounding'];
            $includeVatInPvp = $pricing['includeVatInPvp'];
            $shippingBuffer = $pricing['shippingBuffer'];

            $minPriceNet = $this->priceFromMargin($cost, $minMargin);
            $targetPriceNet = $this->priceFromMargin($cost, $targetMargin + $promoBuffer);
            $recommendedNet = $minPriceNet;

            if ($strategy === 'target_margin') {
                $recommendedNet = $this->priceFromMargin($cost, $targetMargin + $promoBuffer);
            } elseif ($strategy === 'competitive') {
                $recommendedNet = $this->priceFromMargin($cost, $minMargin);
            } else {
                $recommendedNet = $cost * (1 + (($baseMargin + $promoBuffer) / 100));
            }

            if ($recommendedNet < $minPriceNet) {
                $recommendedNet = $minPriceNet;
            }

            $minPriceNet = $this->applyPricingAdjustments($minPriceNet, $taxMultiplier, $rounding, $includeVatInPvp, $shippingBuffer);
            $recommendedNet = $this->applyPricingAdjustments($recommendedNet, $taxMultiplier, $rounding, $includeVatInPvp, $shippingBuffer);
            $maxPriceNet = $this->applyPricingAdjustments($targetPriceNet, $taxMultiplier, $rounding, $includeVatInPvp, $shippingBuffer);

            if ($recommendedNet < $minPriceNet) {
                $recommendedNet = $minPriceNet;
            }
            if ($maxPriceNet < $recommendedNet) {
                $maxPriceNet = $recommendedNet;
            }

            $row['business'] = [
                'cost' => $cost,
                'margin' => $priceNet > 0 ? round((($priceNet - $cost) / $priceNet) * 100, 1) : 0,
                'profit' => round($priceNet - $cost, 2),
                'suggestions' => [
                    'min_price' => round($minPriceNet, 2),
                    'recommended_price' => round($recommendedNet, 2),
                    'max_price' => round($maxPriceNet, 2),
                    'min_price_pvp' => round($minPriceNet * $taxMultiplier, 2),
                    'recommended_price_pvp' => round($recommendedNet * $taxMultiplier, 2),
                    'max_price_pvp' => round($maxPriceNet * $taxMultiplier, 2)
                ]
            ];
        } else {
            // Default if no cost set yet
            $roundedNet = $this->applyPricingAdjustments($priceNet, $taxMultiplier, $pricing['rounding'], $pricing['includeVatInPvp'], $pricing['shippingBuffer']);
            $row['business'] = [
                'cost' => 0,
                'margin' => 100,
                'profit' => $priceNet,
                'suggestions' => [
                    'min_price' => round($roundedNet * 0.8, 2),
                    'recommended_price' => round($roundedNet, 2),
                    'max_price' => round($roundedNet * 1.2, 2),
                    'min_price_pvp' => round(($roundedNet * 0.8) * $taxMultiplier, 2),
                    'recommended_price_pvp' => round($roundedNet * $taxMultiplier, 2),
                    'max_price_pvp' => round(($roundedNet * 1.2) * $taxMultiplier, 2)
                ]
            ];
        }

        $row['expirationDate'] = $expirationDate;
        $row['expirationAlertDays'] = $expirationAlertDays;
        $row['daysToExpire'] = null;
        $row['expirationStatus'] = 'none';
        if ($expirationDate !== null) {
            $today = new \DateTimeImmutable('today');
            $expiry = \DateTimeImmutable::createFromFormat('Y-m-d', $expirationDate);
            if ($expiry instanceof \DateTimeImmutable) {
                $daysToExpire = (int)$today->diff($expiry)->format('%r%a');
                $row['daysToExpire'] = $daysToExpire;
                if ($daysToExpire < 0) {
                    $row['expirationStatus'] = 'expired';
                } elseif ($daysToExpire <= $expirationAlertDays) {
                    $row['expirationStatus'] = 'expiring';
                } else {
                    $row['expirationStatus'] = 'ok';
                }
            }
        }

        $stockQty = max(0, (int)($row['quantity'] ?? 0));
        $soldHistorical = max(0, (int)($row['sold'] ?? 0));
        $reorderPoint = $this->normalizeIntAttribute(
            $row['attributes']['reorderPoint'] ?? $row['attributes']['stockMin'] ?? null,
            5,
            1,
            20000
        );
        $overstockThreshold = $this->normalizeIntAttribute(
            $row['attributes']['overstockThreshold'] ?? $row['attributes']['stockMax'] ?? null,
            max(20, $reorderPoint * 3),
            $reorderPoint + 1,
            50000
        );
        $stockMax = $this->normalizeIntAttribute(
            $row['attributes']['stockMax'] ?? $row['attributes']['idealStock'] ?? null,
            $overstockThreshold,
            $overstockThreshold,
            50000
        );
        $criticalPoint = max(1, (int)floor($reorderPoint / 2));
        $stockStatus = 'healthy';
        if ($stockQty <= 0) {
            $stockStatus = 'out_of_stock';
        } elseif ($stockQty <= $criticalPoint) {
            $stockStatus = 'critical';
        } elseif ($stockQty <= $reorderPoint) {
            $stockStatus = 'low';
        } elseif ($stockQty >= $overstockThreshold) {
            $stockStatus = 'overstock';
        }
        $velocityWindowMonths = $this->normalizeIntAttribute(
            $row['attributes']['velocityWindowMonths'] ?? null,
            6,
            1,
            24
        );
        $avgMonthlySales = $velocityWindowMonths > 0 ? ($soldHistorical / $velocityWindowMonths) : 0;
        $coverageDays = $avgMonthlySales >= 1
            ? min(720, round(($stockQty / $avgMonthlySales) * 30, 1))
            : null;
        $costTotal = round($cost * $stockQty, 2);
        $saleTotalNet = round($priceNet * $stockQty, 2);
        $saleTotalGross = round($price * $stockQty, 2);

        $row['inventoryStatus'] = $stockStatus;
        $row['inventory'] = [
            'onHand' => $stockQty,
            'reserved' => 0,
            'available' => $stockQty,
            'soldHistorical' => $soldHistorical,
            'reorderPoint' => $reorderPoint,
            'criticalPoint' => $criticalPoint,
            'overstockThreshold' => $overstockThreshold,
            'stockMax' => $stockMax,
            'status' => $stockStatus,
            'coverage' => [
                'days' => $coverageDays,
                'avgMonthlySales' => round($avgMonthlySales, 2),
                'windowMonths' => $velocityWindowMonths,
                'confidence' => $avgMonthlySales >= 1 ? 'medium' : 'low'
            ],
            'valuation' => [
                'costTotal' => $costTotal,
                'saleTotalNet' => $saleTotalNet,
                'saleTotalGross' => $saleTotalGross
            ],
            'lot' => [
                'code' => $row['attributes']['lotCode'] ?? null,
                'location' => $row['attributes']['storageLocation'] ?? ($row['attributes']['warehouseLocation'] ?? null),
                'supplier' => $row['attributes']['supplier'] ?? null
            ],
            'expiration' => [
                'date' => $expirationDate,
                'alertDays' => $expirationAlertDays,
                'daysToExpire' => $row['daysToExpire'],
                'status' => $row['expirationStatus']
            ]
        ];

        if (array_key_exists('lastPurchaseInvoiceId', $row) || array_key_exists('purchaseEntriesCount', $row)) {
            $lastPurchaseInvoiceId = trim((string)($row['lastPurchaseInvoiceId'] ?? ''));
            $lastPurchaseInvoiceNumber = trim((string)($row['lastPurchaseInvoiceNumber'] ?? ''));
            $lastPurchaseSupplierName = trim((string)($row['lastPurchaseSupplierName'] ?? ''));
            $lastPurchaseSupplierDocument = trim((string)($row['lastPurchaseSupplierDocument'] ?? ''));
            $lastPurchaseIssuedAt = $row['lastPurchaseIssuedAt'] ?? null;
            $lastPurchaseReceivedAt = $row['lastPurchaseReceivedAt'] ?? null;
            $lastPurchaseQuantity = max(0, (int)($row['lastPurchaseQuantity'] ?? 0));
            $lastPurchaseUnitCost = round((float)($row['lastPurchaseUnitCost'] ?? 0), 4);
            $lastPurchaseLineTotal = round((float)($row['lastPurchaseLineTotal'] ?? 0), 4);

            $lastPurchaseInvoice = null;
            if ($lastPurchaseInvoiceId !== '' || $lastPurchaseInvoiceNumber !== '') {
                $lastPurchaseInvoice = [
                    'id' => $lastPurchaseInvoiceId !== '' ? $lastPurchaseInvoiceId : null,
                    'invoiceNumber' => $lastPurchaseInvoiceNumber !== '' ? $lastPurchaseInvoiceNumber : null,
                    'supplierName' => $lastPurchaseSupplierName !== '' ? $lastPurchaseSupplierName : null,
                    'supplierDocument' => $lastPurchaseSupplierDocument !== '' ? $lastPurchaseSupplierDocument : null,
                    'issuedAt' => $lastPurchaseIssuedAt ?: null,
                    'receivedAt' => $lastPurchaseReceivedAt ?: null,
                    'quantity' => $lastPurchaseQuantity,
                    'unitCost' => $lastPurchaseUnitCost,
                    'lineTotal' => $lastPurchaseLineTotal
                ];
            }

            $purchaseEntriesCount = max(0, (int)($row['purchaseEntriesCount'] ?? 0));
            $purchasedUnitsTotal = max(0, (int)($row['purchasedUnitsTotal'] ?? 0));
            $remainingUnitsFromPurchases = max(0, (int)($row['remainingUnitsFromPurchases'] ?? 0));
            $lastPurchaseAt = $row['lastPurchaseAt'] ?? null;
            $openLotsCount = max(0, (int)($row['openLotsCount'] ?? 0));
            $remainingUnitsTotal = max(0, (int)($row['remainingUnitsTotal'] ?? 0));
            $remainingCostTotal = round((float)($row['remainingCostTotal'] ?? 0), 4);
            $weightedRemainingUnitCost = round((float)($row['weightedRemainingUnitCost'] ?? 0), 4);
            $minRemainingUnitCost = round((float)($row['minRemainingUnitCost'] ?? 0), 4);
            $maxRemainingUnitCost = round((float)($row['maxRemainingUnitCost'] ?? 0), 4);
            $weightedProfit = $priceNet > 0 ? round($priceNet - $weightedRemainingUnitCost, 2) : 0.0;
            $weightedMargin = $priceNet > 0 ? round((($priceNet - $weightedRemainingUnitCost) / $priceNet) * 100, 1) : 0.0;
            $lastPurchaseProfit = $priceNet > 0 ? round($priceNet - $lastPurchaseUnitCost, 2) : 0.0;
            $lastPurchaseMargin = $priceNet > 0 ? round((($priceNet - $lastPurchaseUnitCost) / $priceNet) * 100, 1) : 0.0;
            $salesOrdersCount = max(0, (int)($row['salesOrdersCount'] ?? 0));
            $salesUnitsTotal = max(0, (int)($row['salesUnitsTotal'] ?? $soldHistorical));
            $lastSaleAt = $row['lastSaleAt'] ?? null;

            $row['lastPurchaseInvoice'] = $lastPurchaseInvoice;
            $row['inventory']['purchaseHistory'] = [
                'entriesCount' => $purchaseEntriesCount,
                'purchasedUnits' => $purchasedUnitsTotal,
                'remainingUnits' => $remainingUnitsFromPurchases,
                'lastPurchaseAt' => $lastPurchaseAt ?: ($lastPurchaseInvoice['receivedAt'] ?? $lastPurchaseInvoice['issuedAt'] ?? null),
            ];
            $row['inventory']['lastPurchaseInvoice'] = $lastPurchaseInvoice;
            $row['inventory']['procurement'] = [
                'openLotsCount' => $openLotsCount,
                'remainingUnitsTotal' => $remainingUnitsTotal,
                'remainingCostTotal' => $remainingCostTotal,
                'weightedUnitCost' => $weightedRemainingUnitCost,
                'minUnitCost' => $minRemainingUnitCost,
                'maxUnitCost' => $maxRemainingUnitCost,
                'weightedProfit' => $weightedProfit,
                'weightedMargin' => $weightedMargin,
                'lastPurchaseProfit' => $lastPurchaseProfit,
                'lastPurchaseMargin' => $lastPurchaseMargin,
            ];
            $row['inventory']['salesHistory'] = [
                'ordersCount' => $salesOrdersCount,
                'soldUnits' => $salesUnitsTotal,
                'lastSaleAt' => $lastSaleAt ?: null,
            ];
        }

        return $row;
    }

    private function buildProcurementDetail(array $product): array {
        $productId = trim((string)($product['id'] ?? ''));
        $priceGross = round((float)($product['price'] ?? 0), 2);
        $productAttributes = $this->normalizeProductAttributes($product['attributes'] ?? []);
        $taxRate = $this->getProductTaxRateForAttributes($productAttributes);
        $taxMultiplier = $this->getProductTaxMultiplierForAttributes($productAttributes);
        $priceNet = $taxMultiplier > 0 ? round($priceGross / $taxMultiplier, 4) : round($priceGross, 4);

        if ($productId === '') {
            return [
                'product_id' => '',
                'legacy_id' => null,
                'product_name' => trim((string)($product['name'] ?? '')),
                'category' => trim((string)($product['category'] ?? '')),
                'price_gross' => $priceGross,
                'price_net' => $priceNet,
                'tax_rate' => round($taxRate, 2),
                'tax_exempt' => $taxRate <= 0,
                'entries_count' => 0,
                'open_lots_count' => 0,
                'purchased_units_total' => 0,
                'consumed_units_total' => 0,
                'remaining_units_total' => 0,
                'remaining_cost_total' => 0.0,
                'weighted_unit_cost' => 0.0,
                'weighted_margin' => 0.0,
                'weighted_profit' => 0.0,
                'min_unit_cost' => 0.0,
                'max_unit_cost' => 0.0,
                'has_unlinked_stock' => false,
                'lots' => [],
            ];
        }

        $stmt = $this->db->prepare('
            SELECT
                il.id,
                il.source_type,
                il.source_ref,
                il.purchase_invoice_id,
                il.purchase_invoice_item_id,
                il.unit_cost,
                il.initial_quantity,
                il.remaining_quantity,
                il.received_at,
                il.created_at,
                pi.invoice_number,
                pi.supplier_name,
                pi.supplier_document,
                pi.issued_at
            FROM "InventoryLot" il
            LEFT JOIN "PurchaseInvoice" pi
              ON pi.id = il.purchase_invoice_id
             AND pi.tenant_id = il.tenant_id
            WHERE il.tenant_id = :tenant_id
              AND il.product_id = :product_id
            ORDER BY COALESCE(pi.issued_at::timestamp, il.received_at, il.created_at) DESC,
                     il.created_at DESC,
                     il.id DESC
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'product_id' => $productId,
        ]);

        $rows = $stmt->fetchAll() ?: [];
        $lots = [];
        $purchasedUnitsTotal = 0;
        $consumedUnitsTotal = 0;
        $remainingUnitsTotal = 0;
        $remainingCostTotal = 0.0;
        $openLotsCount = 0;
        $openUnitCosts = [];
        $hasUnlinkedStock = false;

        foreach ($rows as $row) {
            $purchasedQuantity = max(0, (int)($row['initial_quantity'] ?? 0));
            $remainingQuantity = max(0, (int)($row['remaining_quantity'] ?? 0));
            $consumedQuantity = max(0, $purchasedQuantity - $remainingQuantity);
            $unitCost = round((float)($row['unit_cost'] ?? 0), 4);
            $purchaseTotal = round($purchasedQuantity * $unitCost, 4);
            $remainingCost = round($remainingQuantity * $unitCost, 4);
            $estimatedRemainingNetRevenue = round($remainingQuantity * $priceNet, 4);
            $estimatedRemainingGrossRevenue = round($remainingQuantity * $priceGross, 4);
            $estimatedRemainingProfit = round($estimatedRemainingNetRevenue - $remainingCost, 4);
            $estimatedRemainingMargin = $priceNet > 0
                ? round((($priceNet - $unitCost) / $priceNet) * 100, 1)
                : 0.0;
            $purchaseInvoiceId = trim((string)($row['purchase_invoice_id'] ?? ''));
            $sourceType = trim((string)($row['source_type'] ?? ''));
            $isUnlinked = $purchaseInvoiceId === '' || $sourceType !== 'purchase_invoice';

            $purchasedUnitsTotal += $purchasedQuantity;
            $consumedUnitsTotal += $consumedQuantity;
            $remainingUnitsTotal += $remainingQuantity;
            $remainingCostTotal += $remainingCost;

            if ($remainingQuantity > 0) {
                $openLotsCount += 1;
                $openUnitCosts[] = $unitCost;
            }
            if ($isUnlinked && $remainingQuantity > 0) {
                $hasUnlinkedStock = true;
            }

            $lots[] = [
                'id' => trim((string)($row['id'] ?? '')),
                'source_type' => $sourceType,
                'source_ref' => trim((string)($row['source_ref'] ?? '')) ?: null,
                'purchase_invoice_id' => $purchaseInvoiceId !== '' ? $purchaseInvoiceId : null,
                'purchase_invoice_item_id' => trim((string)($row['purchase_invoice_item_id'] ?? '')) ?: null,
                'invoice_number' => trim((string)($row['invoice_number'] ?? '')) ?: null,
                'supplier_name' => trim((string)($row['supplier_name'] ?? '')) ?: null,
                'supplier_document' => trim((string)($row['supplier_document'] ?? '')) ?: null,
                'issued_at' => $row['issued_at'] ?: null,
                'received_at' => $row['received_at'] ?: null,
                'created_at' => $row['created_at'] ?: null,
                'purchased_quantity' => $purchasedQuantity,
                'consumed_quantity' => $consumedQuantity,
                'remaining_quantity' => $remainingQuantity,
                'unit_cost' => $unitCost,
                'purchase_total' => $purchaseTotal,
                'remaining_cost_total' => $remainingCost,
                'estimated_remaining_net_revenue' => $estimatedRemainingNetRevenue,
                'estimated_remaining_gross_revenue' => $estimatedRemainingGrossRevenue,
                'estimated_remaining_profit' => $estimatedRemainingProfit,
                'estimated_remaining_margin' => $estimatedRemainingMargin,
                'status' => $remainingQuantity > 0 ? 'open' : 'consumed',
            ];
        }

        $weightedUnitCost = $remainingUnitsTotal > 0
            ? round($remainingCostTotal / $remainingUnitsTotal, 4)
            : 0.0;
        $weightedProfit = $priceNet > 0 ? round($priceNet - $weightedUnitCost, 4) : 0.0;
        $weightedMargin = $priceNet > 0
            ? round((($priceNet - $weightedUnitCost) / $priceNet) * 100, 1)
            : 0.0;

        return [
            'product_id' => $productId,
            'legacy_id' => trim((string)($product['legacyId'] ?? '')) ?: null,
            'product_name' => trim((string)($product['name'] ?? '')),
            'category' => trim((string)($product['category'] ?? '')),
            'price_gross' => $priceGross,
            'price_net' => $priceNet,
            'tax_rate' => round($taxRate, 2),
            'tax_exempt' => $taxRate <= 0,
            'entries_count' => count($lots),
            'open_lots_count' => $openLotsCount,
            'purchased_units_total' => $purchasedUnitsTotal,
            'consumed_units_total' => $consumedUnitsTotal,
            'remaining_units_total' => $remainingUnitsTotal,
            'remaining_cost_total' => round($remainingCostTotal, 4),
            'weighted_unit_cost' => $weightedUnitCost,
            'weighted_margin' => $weightedMargin,
            'weighted_profit' => $weightedProfit,
            'min_unit_cost' => count($openUnitCosts) > 0 ? min($openUnitCosts) : 0.0,
            'max_unit_cost' => count($openUnitCosts) > 0 ? max($openUnitCosts) : 0.0,
            'has_unlinked_stock' => $hasUnlinkedStock,
            'lots' => $lots,
        ];
    }

    private function resolveMovementPeriod(string $periodKey): array {
        $key = strtolower(trim($periodKey));
        if ($key === '') {
            $key = 'month';
        }

        $timezone = new \DateTimeZone('America/Guayaquil');
        $today = new \DateTimeImmutable('today', $timezone);

        switch ($key) {
            case 'day':
                $start = $today;
                $endExclusive = $today->modify('+1 day');
                $label = 'Hoy';
                break;
            case 'week':
                $start = $today->modify('-6 days');
                $endExclusive = $today->modify('+1 day');
                $label = 'Últimos 7 días';
                break;
            case 'all':
            case 'historical':
                return [
                    'key' => 'all',
                    'label' => 'Histórico completo',
                    'start_date' => null,
                    'end_date' => null,
                    'end_exclusive' => null,
                    'timezone' => 'America/Guayaquil',
                ];
            case 'month':
                $start = $today->modify('first day of this month');
                $endExclusive = $start->modify('+1 month');
                $label = 'Mes actual';
                break;
            default:
                throw new \InvalidArgumentException('Período inválido. Usa day, week, month o all.');
        }

        return [
            'key' => $key,
            'label' => $label,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $endExclusive->modify('-1 day')->format('Y-m-d'),
            'end_exclusive' => $endExclusive->format('Y-m-d'),
            'timezone' => 'America/Guayaquil',
        ];
    }

    private function getPublicBaseUrl() {
        $base = TenantContext::publicBaseUrl()
            ?? ($_ENV['APP_URL'] ?? ($_ENV['BACKEND_PUBLIC_URL'] ?? 'http://localhost:8080'));
        return rtrim($base, '/');
    }

    private function normalizeImageUrl($url) {
        if (!$url || !is_string($url)) {
            return $url;
        }
        if (strpos($url, '/uploads/') === 0) {
            return $url;
        }
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return $url;
        }
        return $this->getPublicBaseUrl() . '/' . ltrim($url, '/');
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function normalizeImageEntries($items, $defaultKind) {
        $entries = [];
        if (!is_array($items)) {
            return $entries;
        }
        foreach ($items as $item) {
            if (is_string($item)) {
                $url = trim($item);
                if ($url === '') {
                    continue;
                }
                $entries[] = [
                    'url' => $url,
                    'width' => null,
                    'height' => null,
                    'kind' => $defaultKind,
                    'altText' => null
                ];
                continue;
            }
            if (is_array($item)) {
                $url = trim($item['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $kind = $item['kind'] ?? $defaultKind;
                $width = isset($item['width']) && is_numeric($item['width']) ? intval($item['width']) : null;
                $height = isset($item['height']) && is_numeric($item['height']) ? intval($item['height']) : null;
                $altText = trim((string)($item['altText'] ?? $item['alt_text'] ?? ''));
                $entries[] = [
                    'url' => $url,
                    'width' => $width,
                    'height' => $height,
                    'kind' => $kind,
                    'altText' => $altText !== '' ? $altText : null
                ];
            }
        }
        return $entries;
    }

    private function syncImages($productId, $entries, $kind) {
        if (!is_array($entries)) {
            return;
        }
        if ($kind === 'gallery') {
            $stmt = $this->db->prepare('DELETE FROM "Image" WHERE product_id = :id AND (kind = :kind OR kind IS NULL)');
            $stmt->execute(['id' => $productId, 'kind' => $kind]);
        } else {
            $stmt = $this->db->prepare('DELETE FROM "Image" WHERE product_id = :id AND kind = :kind');
            $stmt->execute(['id' => $productId, 'kind' => $kind]);
        }
        if (count($entries) === 0) {
            return;
        }
        $stmt = $this->db->prepare('
            INSERT INTO "Image" (id, url, product_id, kind, width, height, alt_text, display_order)
            VALUES (:id, :url, :product_id, :kind, :width, :height, :alt_text, :display_order)
        ');
        foreach ($entries as $index => $entry) {
            $stmt->execute([
                'id' => uniqid('img_'),
                'url' => $entry['url'],
                'product_id' => $productId,
                'kind' => $entry['kind'] ?? $kind,
                'width' => $entry['width'],
                'height' => $entry['height'],
                'alt_text' => $entry['altText'] ?? null,
                'display_order' => $index
            ]);
        }
    }

    private function getTaxRate() {
        if ($this->taxRateCache !== null) {
            return $this->taxRateCache;
        }
        $settings = new \App\Repositories\SettingsRepository();
        $value = $settings->get('vat_rate');
        $rate = is_numeric($value) ? floatval($value) : 0;
        $this->taxRateCache = $rate;
        return $rate;
    }

    private function normalizeBooleanAttribute($value, bool $default = false): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float)$value !== 0.0;
        }
        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        return $default;
    }

    private function normalizeProductAttributes($attributes): array {
        if (!is_array($attributes)) {
            return [];
        }
        $normalized = $attributes;
        $taxExempt = $this->normalizeBooleanAttribute(
            $normalized['taxExempt'] ?? ($normalized['tax_exempt'] ?? false),
            false
        );
        $normalized['taxExempt'] = $taxExempt ? 'true' : 'false';
        unset($normalized['tax_exempt']);
        return $normalized;
    }

    private function isProductTaxExempt($attributes): bool {
        if (!is_array($attributes)) {
            return false;
        }
        return $this->normalizeBooleanAttribute(
            $attributes['taxExempt'] ?? ($attributes['tax_exempt'] ?? false),
            false
        );
    }

    private function getProductTaxRateForAttributes($attributes): float {
        if ($this->isProductTaxExempt($attributes)) {
            return 0.0;
        }

        $rawRate = is_array($attributes) ? ($attributes['taxRate'] ?? null) : null;
        if ($rawRate !== null && $rawRate !== '' && is_numeric($rawRate)) {
            return max(0, round((float)$rawRate, 2));
        }

        return $this->getTaxRate();
    }

    private function parseTaxRateValue($value): ?float {
        if ($value === null) {
            return null;
        }

        $text = is_string($value) ? trim(str_replace(',', '.', $value)) : $value;
        if ($text === '') {
            return null;
        }
        if (!is_numeric($text)) {
            return null;
        }

        return max(0.0, min(100.0, round((float)$text, 2)));
    }

    private function resolveOptionalBooleanValue(array $values): ?bool {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            return $this->normalizeBooleanAttribute($value, false);
        }

        return null;
    }

    private function getPurchaseTaxRateForPayload(array $purchaseInvoice, array $attributes): float {
        $metadata = is_array($purchaseInvoice['metadata'] ?? null) ? $purchaseInvoice['metadata'] : [];
        $taxExempt = $this->resolveOptionalBooleanValue([
            $purchaseInvoice['purchaseTaxExempt'] ?? null,
            $metadata['purchase_tax_exempt'] ?? null,
            $metadata['purchaseTaxExempt'] ?? null,
            $metadata['tax_exempt'] ?? null,
        ]);

        if ($taxExempt === true) {
            return 0.0;
        }

        $candidates = [
            $purchaseInvoice['purchaseTaxRate'] ?? null,
            $metadata['purchase_tax_rate'] ?? null,
            $metadata['purchaseTaxRate'] ?? null,
            $metadata['tax_rate'] ?? null,
            is_array($attributes) ? ($attributes['purchaseTaxRate'] ?? ($attributes['purchase_tax_rate'] ?? null)) : null,
        ];

        foreach ($candidates as $candidate) {
            $taxRate = $this->parseTaxRateValue($candidate);
            if ($taxRate !== null) {
                return $taxRate;
            }
        }

        return $this->getProductTaxRateForAttributes($attributes);
    }

    private function getProductTaxMultiplierForAttributes($attributes): float {
        return 1 + ($this->getProductTaxRateForAttributes($attributes) / 100);
    }

    private function getPricingSettings() {
        if ($this->pricingSettingsCache !== null) {
            return $this->pricingSettingsCache;
        }
        $settings = new \App\Repositories\SettingsRepository();
        $baseMargin = $this->parseNumericSetting($settings->get('pricing_margin_base'), 30);
        $minMargin = $this->parseNumericSetting($settings->get('pricing_margin_min'), 15);
        $targetMargin = $this->parseNumericSetting($settings->get('pricing_margin_target'), 35);
        $promoBuffer = $this->parseNumericSetting($settings->get('pricing_margin_promo_buffer'), 5);
        $rounding = $this->parseNumericSetting($settings->get('pricing_calc_rounding'), 0.05);
        $shippingBuffer = $this->parseNumericSetting($settings->get('pricing_calc_shipping_buffer'), 0);
        $strategy = $settings->get('pricing_calc_strategy') ?? 'cost_plus';
        $includeVatInPvp = $this->parseBoolSetting($settings->get('pricing_calc_include_vat'), true);

        $minMargin = max(0, $minMargin);
        $baseMargin = max($minMargin, $baseMargin);
        $targetMargin = max($baseMargin, $targetMargin);
        $promoBuffer = max(0, $promoBuffer);

        $allowed = ['cost_plus', 'target_margin', 'competitive'];
        if (!in_array($strategy, $allowed, true)) {
            $strategy = 'cost_plus';
        }

        $this->pricingSettingsCache = [
            'baseMargin' => $baseMargin,
            'minMargin' => $minMargin,
            'targetMargin' => $targetMargin,
            'promoBuffer' => $promoBuffer,
            'rounding' => max(0, $rounding),
            'strategy' => $strategy,
            'includeVatInPvp' => $includeVatInPvp,
            'shippingBuffer' => max(0, $shippingBuffer)
        ];

        return $this->pricingSettingsCache;
    }

    private function parseNumericSetting($value, $default) {
        return is_numeric($value) ? floatval($value) : $default;
    }

    private function parseBoolSetting($value, $default) {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) return true;
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) return false;
        return $default;
    }

    private function normalizeExpirationDate($value): ?string {
        if ($value === null) return null;
        $raw = trim((string)$value);
        if ($raw === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if (!($dt instanceof \DateTimeImmutable)) {
            return null;
        }
        return $dt->format('Y-m-d');
    }

    private function normalizeExpirationAlertDays($value): int {
        if ($value === null || $value === '') {
            return 30;
        }
        if (!is_numeric($value)) {
            return 30;
        }
        return max(0, (int)$value);
    }

    private function normalizeIntAttribute($value, int $default, int $min, int $max): int {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int)$value));
    }

    private function priceFromMargin($cost, $marginPct) {
        $margin = max(0, min(95, $marginPct));
        $denom = 1 - ($margin / 100);
        if ($denom <= 0) return $cost;
        return $cost / $denom;
    }

    private function roundToIncrement($value, $increment) {
        if ($increment <= 0) return $value;
        return round($value / $increment) * $increment;
    }

    private function applyPricingAdjustments($priceNet, $taxMultiplier, $rounding, $includeVatInPvp, $shippingBuffer) {
        $adjusted = $priceNet * (1 + (max(0, $shippingBuffer) / 100));
        if ($rounding > 0) {
            if ($includeVatInPvp && $taxMultiplier > 0) {
                $pvp = $this->roundToIncrement($adjusted * $taxMultiplier, $rounding);
                $adjusted = $taxMultiplier > 0 ? ($pvp / $taxMultiplier) : $pvp;
            } else {
                $adjusted = $this->roundToIncrement($adjusted, $rounding);
            }
        }
        return max(0, $adjusted);
    }

    private function getSuggestedNetPriceForCost($cost, $taxMultiplier = null) {
        $cost = max(0, round((float)$cost, 2));
        if ($cost <= 0) {
            return 0.0;
        }

        $pricing = $this->getPricingSettings();
        $taxMultiplier = $taxMultiplier !== null ? max(1, (float)$taxMultiplier) : $this->getProductTaxMultiplierForAttributes([]);

        $minMargin = $pricing['minMargin'];
        $baseMargin = $pricing['baseMargin'];
        $targetMargin = $pricing['targetMargin'];
        $promoBuffer = $pricing['promoBuffer'];
        $strategy = $pricing['strategy'];
        $rounding = $pricing['rounding'];
        $includeVatInPvp = $pricing['includeVatInPvp'];
        $shippingBuffer = $pricing['shippingBuffer'];

        $minPriceNet = $this->priceFromMargin($cost, $minMargin);
        if ($strategy === 'target_margin') {
            $recommendedNet = $this->priceFromMargin($cost, $targetMargin + $promoBuffer);
        } elseif ($strategy === 'competitive') {
            $recommendedNet = $this->priceFromMargin($cost, $minMargin);
        } else {
            $recommendedNet = $cost * (1 + (($baseMargin + $promoBuffer) / 100));
        }

        if ($recommendedNet < $minPriceNet) {
            $recommendedNet = $minPriceNet;
        }

        $recommendedNet = $this->applyPricingAdjustments(
            $recommendedNet,
            $taxMultiplier,
            $rounding,
            $includeVatInPvp,
            $shippingBuffer
        );
        $minPriceNet = $this->applyPricingAdjustments(
            $minPriceNet,
            $taxMultiplier,
            $rounding,
            $includeVatInPvp,
            $shippingBuffer
        );

        return round(max($recommendedNet, $minPriceNet), 2);
    }

    private function requirePurchaseInvoicePayload(array $data): array {
        $purchaseInvoice = $data['purchaseInvoice'] ?? null;
        if (!is_array($purchaseInvoice) || !$this->payloadHasMeaningfulValue($purchaseInvoice)) {
            throw new \InvalidArgumentException('Debes registrar la factura de compra para ingresar stock.');
        }
        return $purchaseInvoice;
    }

    private function payloadHasMeaningfulValue(mixed $value): bool {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->payloadHasMeaningfulValue($item)) {
                    return true;
                }
            }
            return false;
        }

        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return trim((string)$value) !== '';
        }

        return false;
    }

    private function recordPurchaseInvoiceStockEntry(string $productId, string $productName, int $quantity, float $unitCost, array $data, string $reason, ?array $fallbackAttributes = null): array {
        $purchaseInvoices = new PurchaseInvoiceRepository($this->db);
        $purchaseInvoicePayload = $this->requirePurchaseInvoicePayload($data);
        $rawAttributes = is_array($data['attributes'] ?? null)
            ? $data['attributes']
            : (is_array($fallbackAttributes) ? $fallbackAttributes : []);
        $normalizedAttributes = $this->normalizeProductAttributes($rawAttributes);
        if (array_key_exists('taxExempt', $data) && !array_key_exists('taxExempt', $normalizedAttributes)) {
            $normalizedAttributes['taxExempt'] = $data['taxExempt'] ? 'true' : 'false';
        }
        $purchaseTaxRate = $this->getPurchaseTaxRateForPayload($purchaseInvoicePayload, $normalizedAttributes);
        return $purchaseInvoices->recordStockEntry(
            $purchaseInvoicePayload,
            $productId,
            $productName,
            $quantity,
            $unitCost,
            [
                'reason' => $reason,
                'tax_rate' => round($purchaseTaxRate, 2),
                'tax_exempt' => $purchaseTaxRate <= 0,
                'purchase_tax_rate' => round($purchaseTaxRate, 2),
                'purchase_tax_exempt' => $purchaseTaxRate <= 0,
            ]
        );
    }

    public function create($data) {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $price = isset($data['price']) && is_numeric($data['price']) ? round((float)$data['price'], 4) : 0.0;
            $cost = isset($data['cost']) && is_numeric($data['cost']) ? round((float)$data['cost'], 2) : 0.0;
            if ($cost > 0 && $price < $cost) {
                throw new \Exception('El precio base no puede ser menor al costo del producto.');
            }
            $originalPrice = (isset($data['originPrice']) && is_numeric($data['originPrice']))
                ? max(round((float)$data['originPrice'], 4), $price)
                : $price;
            $isSale = (isset($data['sale']) ? (bool)$data['sale'] : ($originalPrice > $price))
                && $originalPrice > $price;

            $sql = '
                INSERT INTO "Product" (
                    id, legacy_id, tenant_id, category, product_type, name, gender, is_new, is_sale, is_published, price, original_price, cost, brand, sold, quantity, description, action, slug, attributes, created_at, updated_at
                ) VALUES (
                    :id, :legacy_id, :tenant_id, :category, :product_type, :name, :gender, :is_new, :is_sale, :is_published, :price, :original_price, :cost, :brand, :sold, :quantity, :description, :action, :slug, :attributes, NOW(), NOW()
                ) RETURNING id
            ';

            $attributes = isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [];
            $normalizedSpecies = ProductAudience::normalizeSpeciesLabel(
                (string)($attributes['species'] ?? ''),
                (string)($data['gender'] ?? '')
            );
            if ($normalizedSpecies !== '') {
                $attributes['species'] = $normalizedSpecies;
            }

            $params = [
                'id' => !empty($data['id']) ? (string)$data['id'] : uniqid('prod_'),
                'legacy_id' => $data['legacyId'] ?? uniqid(),
                'tenant_id' => $this->getTenantId(),
                'category' => ProductAudience::normalizeCategory(
                    (string)($data['category'] ?? ''),
                    (string)($data['productType'] ?? $data['product_type'] ?? '')
                ) ?: null,
                'product_type' => ProductAudience::normalizeProductType(
                    (string)($data['productType'] ?? $data['product_type'] ?? ''),
                    (string)($data['category'] ?? '')
                ) ?: null,
                'name' => $data['name'],
                'gender' => ProductAudience::resolveGender($attributes['species'] ?? null, (string)($data['gender'] ?? '')),
                'is_new' => isset($data['new']) ? ($data['new'] ? 'true' : 'false') : 'true',
                'is_sale' => $isSale ? 'true' : 'false',
                'is_published' => array_key_exists('published', $data)
                    ? ($data['published'] ? 'true' : 'false')
                    : (array_key_exists('isPublished', $data) ? ($data['isPublished'] ? 'true' : 'false') : 'true'),
                'price' => $price,
                'original_price' => $originalPrice,
                'cost' => $cost,
                'brand' => $data['brand'] ?? 'Generico',
                'sold' => $data['sold'] ?? 0,
                'quantity' => $data['quantity'] ?? 0,
                'description' => $data['description'] ?? '',
                'action' => $data['action'] ?? 'view',
                'slug' => $data['slug'] ?? strtolower(str_replace(' ', '-', $data['name'])) . '-' . uniqid(),
                'attributes' => !empty($attributes) ? json_encode($attributes) : null
            ];

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            $productId = $result['id'];

            $galleryInput = $data['images'] ?? $data['galleryImages'] ?? ($data['image'] ?? []);
            if (is_string($galleryInput)) {
                $galleryInput = [$galleryInput];
            }
            $thumbInput = $data['thumbImages'] ?? $data['thumbImage'] ?? [];
            $galleryEntries = $this->normalizeImageEntries($galleryInput, 'gallery');
            $thumbEntries = $this->normalizeImageEntries($thumbInput, 'thumb');
            if (count($galleryEntries) > 0) {
                $this->syncImages($productId, $galleryEntries, 'gallery');
            }
            if (count($thumbEntries) > 0) {
                $this->syncImages($productId, $thumbEntries, 'thumb');
            }

            $initialQuantity = max(0, (int)($params['quantity'] ?? 0));
            if ($initialQuantity > 0) {
                $purchaseEntry = $this->recordPurchaseInvoiceStockEntry(
                    $productId,
                    (string)$params['name'],
                    $initialQuantity,
                    round((float)($params['cost'] ?? 0), 4),
                    $data,
                    'initial_stock',
                    $attributes
                );
                $inventoryLots = new InventoryLotRepository($this->db);
                $inventoryLots->recordStockIncrease(
                    $productId,
                    $initialQuantity,
                    round((float)($params['cost'] ?? 0), 4),
                    'purchase_invoice',
                    (string)($purchaseEntry['item']['id'] ?? $productId),
                    [
                        'reason' => 'initial_stock',
                        'purchase_invoice_number' => (string)($purchaseEntry['invoice']['invoice_number'] ?? '')
                    ],
                    (string)($purchaseEntry['invoice']['id'] ?? ''),
                    (string)($purchaseEntry['item']['id'] ?? '')
                );
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            return $this->getById($productId, ['includeUnpublished' => true, 'includeProcurement' => true]);
        } catch (\Exception $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function update($id, $data) {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmtCurrent = $this->db->prepare('
                SELECT cost, price, original_price, is_sale, quantity, name, gender, category, product_type, attributes
                FROM "Product"
                WHERE (id = :id OR legacy_id = :id) AND tenant_id = :tenant_id
                LIMIT 1
                FOR UPDATE
            ');
            $stmtCurrent->execute([
                'id' => $id,
                'tenant_id' => $this->getTenantId()
            ]);
            $current = $stmtCurrent->fetch();
            if (!$current) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return null;
            }

            if (array_key_exists('cost', $data) && is_numeric($data['cost'])) {
                $incomingCost = round((float)$data['cost'], 2);
                $currentCost = round((float)($current['cost'] ?? 0), 2);
                $incomingAttributes = is_array($data['attributes'] ?? null)
                    ? $this->normalizeProductAttributes($data['attributes'])
                    : $this->normalizeProductAttributes($current['attributes'] ?? []);
                $taxMultiplier = $this->getProductTaxMultiplierForAttributes($incomingAttributes);

                if (abs($incomingCost - $currentCost) > 0.00001) {
                    $suggestedPrice = $this->getSuggestedNetPriceForCost($incomingCost, $taxMultiplier);
                    $currentPrice = round((float)($current['price'] ?? 0), 4);
                    $requestedPrice = (array_key_exists('price', $data) && is_numeric($data['price']))
                        ? round((float)$data['price'], 4)
                        : null;
                    $nextPrice = max(
                        $suggestedPrice,
                        $currentPrice,
                        $requestedPrice ?? 0
                    );
                    $data['price'] = $nextPrice;

                    if (array_key_exists('originPrice', $data) && is_numeric($data['originPrice'])) {
                        $data['originPrice'] = max((float)$data['originPrice'], $nextPrice);
                    }
                }
            }

            if (array_key_exists('price', $data) || array_key_exists('originPrice', $data) || array_key_exists('sale', $data)) {
                $effectivePrice = (array_key_exists('price', $data) && is_numeric($data['price']))
                    ? round((float)$data['price'], 4)
                    : round((float)($current['price'] ?? 0), 4);
                $normalizedOriginPrice = (array_key_exists('originPrice', $data) && is_numeric($data['originPrice']))
                    ? max(round((float)$data['originPrice'], 4), $effectivePrice)
                    : $effectivePrice;
                $requestedSale = array_key_exists('sale', $data)
                    ? (bool)$data['sale']
                    : ($normalizedOriginPrice > $effectivePrice);

                $data['originPrice'] = $normalizedOriginPrice;
                $data['sale'] = $requestedSale && $normalizedOriginPrice > $effectivePrice;
            }

            $effectiveNextCost = (array_key_exists('cost', $data) && is_numeric($data['cost']))
                ? round((float)$data['cost'], 2)
                : round((float)($current['cost'] ?? 0), 2);
            $effectiveNextPrice = (array_key_exists('price', $data) && is_numeric($data['price']))
                ? round((float)$data['price'], 4)
                : round((float)($current['price'] ?? 0), 4);

            if ($effectiveNextCost > 0 && $effectiveNextPrice < $effectiveNextCost) {
                throw new \Exception('El precio base no puede ser menor al costo del producto.');
            }

            $fields = [];
            $params = ['id' => $id];

            $mapping = [
                'category' => 'category',
                'productType' => 'product_type',
                'name' => 'name',
                'gender' => 'gender',
                'new' => 'is_new',
                'sale' => 'is_sale',
                'published' => 'is_published',
                'isPublished' => 'is_published',
                'price' => 'price',
                'originPrice' => 'original_price',
                'cost' => 'cost',
                'brand' => 'brand',
                'sold' => 'sold',
                'quantity' => 'quantity',
                'description' => 'description',
                'action' => 'action',
                'slug' => 'slug',
                'attributes' => 'attributes'
            ];

            $normalizedAttributesForUpdate = null;

            foreach ($data as $key => $value) {
                if (isset($mapping[$key])) {
                    $dbField = $mapping[$key];
                    $fields[] = "\"$dbField\" = :$key";
                    if ($key === 'attributes') {
                        if (is_array($value)) {
                            $normalizedSpecies = ProductAudience::normalizeSpeciesLabel(
                                (string)($value['species'] ?? ''),
                                (string)($data['gender'] ?? '')
                            );
                            if ($normalizedSpecies !== '') {
                                $value['species'] = $normalizedSpecies;
                            }
                            $normalizedAttributesForUpdate = $value;
                        }
                        $params[$key] = is_string($value) ? $value : json_encode($value);
                    } elseif (in_array($key, ['new', 'sale', 'published', 'isPublished'], true)) {
                        $params[$key] = $value ? 'true' : 'false';
                    } elseif ($key === 'category') {
                        $params[$key] = ProductAudience::normalizeCategory(
                            (string)$value,
                            (string)($data['productType'] ?? $data['product_type'] ?? ($current['product_type'] ?? ''))
                        );
                    } elseif ($key === 'gender') {
                        $params[$key] = ProductAudience::resolveGender(
                            is_array($data['attributes'] ?? null) ? ($data['attributes']['species'] ?? null) : null,
                            (string)$value
                        );
                    } elseif ($key === 'productType') {
                        $params[$key] = ProductAudience::normalizeProductType(
                            (string)$value,
                            (string)($data['category'] ?? ($current['category'] ?? ''))
                        );
                    } else {
                        $params[$key] = $value;
                    }
                }
            }

            if (!array_key_exists('gender', $params) && is_array($normalizedAttributesForUpdate)) {
                $fields[] = '"gender" = :gender';
                $params['gender'] = ProductAudience::resolveGender(
                    $normalizedAttributesForUpdate['species'] ?? null,
                    (string)($current['gender'] ?? '')
                );
            }

            if (empty($fields)) {
                if ($startedTransaction) {
                    $this->db->commit();
                }
                return $this->getById($id, ['includeUnpublished' => true, 'includeProcurement' => true]);
            }

            $fields[] = '"updated_at" = NOW()';
            $sql = 'UPDATE "Product" SET ' . implode(', ', $fields) . ' WHERE (id = :id OR legacy_id = :id) AND tenant_id = :tenant_id';
            $stmt = $this->db->prepare($sql);
            $params['tenant_id'] = $this->getTenantId();
            $stmt->execute($params);

            if (array_key_exists('images', $data) || array_key_exists('galleryImages', $data) || array_key_exists('image', $data)) {
                $galleryInput = $data['images'] ?? $data['galleryImages'] ?? ($data['image'] ?? []);
                if (is_string($galleryInput)) {
                    $galleryInput = [$galleryInput];
                }
                $galleryEntries = $this->normalizeImageEntries($galleryInput, 'gallery');
                $this->syncImages($id, $galleryEntries, 'gallery');
            }
            if (array_key_exists('thumbImages', $data) || array_key_exists('thumbImage', $data)) {
                $thumbInput = $data['thumbImages'] ?? $data['thumbImage'] ?? [];
                $thumbEntries = $this->normalizeImageEntries($thumbInput, 'thumb');
                $this->syncImages($id, $thumbEntries, 'thumb');
            }

            $inventoryLots = new InventoryLotRepository($this->db);
            $currentQuantity = max(0, (int)($current['quantity'] ?? 0));
            $nextQuantity = array_key_exists('quantity', $data) && is_numeric($data['quantity'])
                ? max(0, (int)$data['quantity'])
                : $currentQuantity;

            if ($nextQuantity > $currentQuantity) {
                $increaseQty = $nextQuantity - $currentQuantity;
                $nextCost = round((float)($data['cost'] ?? $current['cost'] ?? 0), 4);
                $nextName = (string)($data['name'] ?? $current['name'] ?? '');
                $inventoryAction = strtolower(trim((string)($data['inventoryAction'] ?? '')));
                if ($inventoryAction === 'adjustment') {
                    $adjustmentReason = trim((string)($data['inventoryAdjustmentReason'] ?? 'Ajuste de inventario'));
                    $inventoryLots->recordStockIncrease(
                        $id,
                        $increaseQty,
                        $nextCost,
                        'inventory_adjustment',
                        uniqid('adjustment_'),
                        [
                            'reason' => 'manual_stock_adjustment',
                            'adjustment_reason' => $adjustmentReason,
                            'direction' => 'increase',
                        ]
                    );
                } else {
                    $purchaseEntry = $this->recordPurchaseInvoiceStockEntry(
                        $id,
                        $nextName !== '' ? $nextName : ($data['name'] ?? 'Producto'),
                        $increaseQty,
                        $nextCost,
                        $data,
                        'manual_stock_increase',
                        is_array($current['attributes'] ?? null)
                            ? $current['attributes']
                            : (json_decode((string)($current['attributes'] ?? '{}'), true) ?: [])
                    );
                    $inventoryLots->recordStockIncrease(
                        $id,
                        $increaseQty,
                        $nextCost,
                        'purchase_invoice',
                        (string)($purchaseEntry['item']['id'] ?? $id),
                        [
                            'reason' => 'manual_stock_increase',
                            'purchase_invoice_number' => (string)($purchaseEntry['invoice']['invoice_number'] ?? '')
                        ],
                        (string)($purchaseEntry['invoice']['id'] ?? ''),
                        (string)($purchaseEntry['item']['id'] ?? '')
                    );
                }
            } elseif ($nextQuantity < $currentQuantity) {
                $inventoryLots->consumeAdjustment(
                    $id,
                    $currentQuantity - $nextQuantity,
                    $currentQuantity,
                    round((float)($current['cost'] ?? 0), 4),
                    [
                        'reason' => 'manual_stock_adjustment',
                        'adjustment_reason' => trim((string)($data['inventoryAdjustmentReason'] ?? 'Ajuste de inventario')),
                        'direction' => 'decrease',
                    ]
                );
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            return $this->getById($id, ['includeUnpublished' => true, 'includeProcurement' => true]);
        } catch (\Exception $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function delete($id) {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        $tenantId = $this->getTenantId();
        try {
            $selectStmt = $this->db->prepare('
                SELECT id, legacy_id, name, quantity, attributes
                FROM "Product"
                WHERE (id = CAST(:id AS text) OR legacy_id = CAST(:legacy_id AS text))
                  AND tenant_id = CAST(:tenant_id AS text)
                LIMIT 1
                FOR UPDATE
            ');
            $selectStmt->execute([
                'id' => $id,
                'legacy_id' => $id,
                'tenant_id' => $tenantId,
            ]);
            $current = $selectStmt->fetch();

            if (!$current) {
                if ($startedTransaction) {
                    $this->db->rollBack();
                }
                return null;
            }

            $attributes = json_decode($current['attributes'] ?? '{}', true);
            if (!is_array($attributes)) {
                $attributes = [];
            }

            $stockStmt = $this->db->prepare('
                SELECT
                    COALESCE(SUM(remaining_quantity), 0)::int AS remaining_units,
                    COUNT(*)::int AS open_lots_count
                FROM "InventoryLot"
                WHERE tenant_id = CAST(:tenant_id AS text)
                  AND product_id = CAST(:product_id AS text)
                  AND remaining_quantity > 0
            ');
            $stockStmt->execute([
                'tenant_id' => $tenantId,
                'product_id' => $current['id'],
            ]);
            $stockState = $stockStmt->fetch() ?: [];
            $currentQuantity = max(0, (int)($current['quantity'] ?? 0));
            $remainingUnits = max(0, (int)($stockState['remaining_units'] ?? 0));
            $openLotsCount = max(0, (int)($stockState['open_lots_count'] ?? 0));

            if ($currentQuantity > 0 || $remainingUnits > 0 || $openLotsCount > 0) {
                throw new \DomainException(
                    'No se puede archivar un producto con stock activo. Primero registra un ajuste de inventario hasta dejarlo en 0; las compras, facturas y ventas historicas se conservan.'
                );
            }

            $attributes['archived'] = 'true';
            $attributes['archivedAt'] = gmdate('c');
            $attributes['archivedProductId'] = (string)($current['id'] ?? '');
            $attributes['archivedLegacyId'] = (string)($current['legacy_id'] ?? '');
            if (!isset($attributes['archivedName']) || trim((string)$attributes['archivedName']) === '') {
                $attributes['archivedName'] = trim((string)($current['name'] ?? ''));
            }

            $updateStmt = $this->db->prepare('
                UPDATE "Product"
                SET is_published = false,
                    quantity = 0,
                    updated_at = NOW(),
                    attributes = CAST(:attributes AS jsonb)
                WHERE id = CAST(:product_id AS text)
                  AND tenant_id = CAST(:tenant_id AS text)
            ');

            $updateStmt->execute([
                'attributes' => json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'product_id' => $current['id'],
                'tenant_id' => $tenantId,
            ]);

            $inventoryLots = new InventoryLotRepository($this->db);
            $lotClosure = $inventoryLots->closeOpenLotsForProduct(
                (string)$current['id'],
                'product_archive',
                [
                    'archived_product_id' => (string)$current['id'],
                    'archived_legacy_id' => (string)($current['legacy_id'] ?? ''),
                    'archived_name' => trim((string)($current['name'] ?? '')),
                ]
            );

            if ($startedTransaction) {
                $this->db->commit();
            }

            return [
                'id' => $current['id'],
                'archived' => true,
                'deleted' => true,
                'closed_lots_count' => (int)($lotClosure['closed_lots_count'] ?? 0),
            ];
        } catch (\Exception $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }}
