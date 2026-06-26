<?php

namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\CatalogInventory\Domain\CatalogInventoryDomain;
use App\Repositories\SettingsRepository;
use PDO;

class InventoryIntelligenceService {
    private PDO $db;
    private float $defaultVatRate;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getModuleInstance(CatalogInventoryDomain::KEY);
        $settings = new SettingsRepository();
        $vatRate = $settings->get('vat_rate');
        $this->defaultVatRate = is_numeric($vatRate) ? max(0.0, (float)$vatRate) : 0.0;
    }

    public function getIntelligence(?int $windowDays = null, ?int $targetDays = null): array {
        $windowDays = max(7, min(180, (int)($windowDays ?: 30)));
        $targetDays = max(7, min(180, (int)($targetDays ?: 30)));
        $rows = $this->buildRows($windowDays, $targetDays);

        return [
            'summary' => $this->buildSummary($rows),
            'health' => $this->buildHealth($rows),
            'actions' => $this->buildActions($rows),
            'purchasePlan' => $this->buildPurchasePlan($rows),
            'categories' => $this->buildCategorySummary($rows),
            'suppliers' => $this->buildSupplierSummary($rows),
            'rows' => $rows,
            'parameters' => [
                'window_days' => $windowDays,
                'target_days' => $targetDays,
                'realized_statuses' => ['completed', 'delivered'],
                'cost_source' => 'open_inventory_lots_then_product_cost',
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    public function toInventoryValue(array $intelligence): array {
        $summary = is_array($intelligence['summary'] ?? null) ? $intelligence['summary'] : [];
        return [
            'market_value' => $this->roundMoney($summary['market_value'] ?? 0),
            'cost_value' => $this->roundMoney($summary['inventory_cost'] ?? 0),
            'total_items' => (int)($summary['total_units'] ?? 0),
            'products_count' => (int)($summary['total_skus'] ?? 0),
            'skus_with_stock' => (int)($summary['skus_with_stock'] ?? 0),
        ];
    }

    public function toInventoryDeepDive(array $intelligence): array {
        $rows = is_array($intelligence['rows'] ?? null) ? $intelligence['rows'] : [];
        $health = is_array($intelligence['health'] ?? null) ? $intelligence['health'] : [];

        $highValue = $rows;
        usort($highValue, fn($left, $right) => ((float)($right['inventory_cost'] ?? 0)) <=> ((float)($left['inventory_cost'] ?? 0)));

        $riskItems = array_values(array_filter($rows, static function (array $row): bool {
            return in_array((string)($row['status'] ?? ''), ['out', 'critical', 'low'], true)
                || in_array((string)($row['recommended_action'] ?? ''), ['restock_now', 'restock_soon'], true);
        }));
        usort($riskItems, fn($left, $right) => ((float)($right['priority_score'] ?? 0)) <=> ((float)($left['priority_score'] ?? 0)));

        $expiring = array_values(array_filter($rows, static fn(array $row): bool => ($row['status'] ?? '') === 'expiring'));
        usort($expiring, fn($left, $right) => ((int)($left['days_to_expire'] ?? 999999)) <=> ((int)($right['days_to_expire'] ?? 999999)));

        $expired = array_values(array_filter($rows, static fn(array $row): bool => ($row['status'] ?? '') === 'expired'));
        usort($expired, fn($left, $right) => ((int)($right['days_expired'] ?? 0)) <=> ((int)($left['days_expired'] ?? 0)));

        return [
            'highValueItems' => array_map(fn(array $row): array => [
                'id' => $row['product_id'] ?? '',
                'legacy_id' => $row['legacy_id'] ?? null,
                'name' => $row['name'] ?? '',
                'quantity' => (int)($row['quantity'] ?? 0),
                'cost' => $this->roundMoney($row['unit_cost'] ?? 0),
                'total_cost' => $this->roundMoney($row['inventory_cost'] ?? 0),
            ], array_slice($highValue, 0, 8)),
            'riskItems' => array_map(fn(array $row): array => [
                'id' => $row['product_id'] ?? '',
                'legacy_id' => $row['legacy_id'] ?? null,
                'name' => $row['name'] ?? '',
                'quantity' => (int)($row['quantity'] ?? 0),
                'status' => $row['status'] ?? 'available',
                'reorder_point' => (int)($row['reorder_point'] ?? 5),
                'critical_point' => (int)($row['critical_point'] ?? 2),
                'units_sold_30d' => (int)($row['units_sold_window'] ?? 0),
                'avg_daily_units' => $this->roundNumber($row['avg_daily_units'] ?? 0, 2),
                'estimated_days_left' => $row['coverage_days'],
                'recommended_action' => $row['recommended_action'] ?? 'monitor',
                'suggested_purchase_qty' => (int)($row['suggested_purchase_qty'] ?? 0),
                'priority_score' => (int)($row['priority_score'] ?? 0),
            ], array_slice($riskItems, 0, 10)),
            'expiringItems' => array_map(fn(array $row): array => [
                'id' => $row['product_id'] ?? '',
                'legacy_id' => $row['legacy_id'] ?? null,
                'name' => $row['name'] ?? '',
                'quantity' => (int)($row['quantity'] ?? 0),
                'expiration_date' => $row['expiration_date'] ?? null,
                'expiration_alert_days' => (int)($row['expiration_alert_days'] ?? 30),
                'days_to_expire' => (int)($row['days_to_expire'] ?? 0),
            ], array_slice($expiring, 0, 10)),
            'expiredItems' => array_map(fn(array $row): array => [
                'id' => $row['product_id'] ?? '',
                'legacy_id' => $row['legacy_id'] ?? null,
                'name' => $row['name'] ?? '',
                'quantity' => (int)($row['quantity'] ?? 0),
                'expiration_date' => $row['expiration_date'] ?? null,
                'days_expired' => (int)($row['days_expired'] ?? 0),
            ], array_slice($expired, 0, 10)),
            'health' => [
                'out_of_stock' => (int)($health['out_of_stock'] ?? 0),
                'low_stock' => (int)($health['low_stock'] ?? 0),
                'critical_stock' => (int)($health['critical_stock'] ?? 0),
                'overstock' => (int)($health['overstock'] ?? 0),
                'expired_products' => (int)($health['expired_products'] ?? 0),
                'expiring_products' => (int)($health['expiring_products'] ?? 0),
            ],
        ];
    }

    private function buildRows(int $windowDays, int $targetDays): array {
        $stmt = $this->db->prepare("
            WITH sales_window AS (
                SELECT
                    oi.product_id,
                    COALESCE(SUM(COALESCE(oi.quantity, 0)), 0)::int AS units_sold_window,
                    COALESCE(SUM(COALESCE(oi.net_total, 0)), 0) AS net_revenue_window
                FROM \"OrderItem\" oi
                JOIN \"Order\" o ON o.id = oi.order_id
                WHERE o.tenant_id = :tenant_id
                  AND LOWER(COALESCE(o.status, 'pending')) IN ('completed', 'delivered')
                  AND o.created_at >= CURRENT_DATE - (CAST(:window_days AS integer) * INTERVAL '1 day')
                GROUP BY oi.product_id
            )
            SELECT
                p.id,
                p.legacy_id,
                p.name,
                p.category,
                p.product_type,
                p.quantity,
                p.cost,
                p.price,
                p.is_published,
                COALESCE(p.attributes, '{}') AS attributes,
                COALESCE(s.units_sold_window, 0)::int AS units_sold_window,
                COALESCE(s.net_revenue_window, 0) AS net_revenue_window,
                COALESCE(open_stock.open_lots_count, 0)::int AS open_lots_count,
                COALESCE(open_stock.remaining_units_total, 0)::int AS remaining_units_total,
                COALESCE(open_stock.remaining_cost_total, 0) AS remaining_cost_total,
                COALESCE(open_stock.weighted_unit_cost, 0) AS weighted_unit_cost,
                COALESCE(open_stock.unlinked_open_lots_count, 0)::int AS unlinked_open_lots_count,
                last_purchase.purchase_invoice_id AS last_purchase_invoice_id,
                last_purchase.invoice_number AS last_purchase_invoice_number,
                last_purchase.supplier_name AS last_purchase_supplier_name,
                last_purchase.supplier_document AS last_purchase_supplier_document,
                last_purchase.issued_at AS last_purchase_issued_at,
                last_purchase.received_at AS last_purchase_received_at,
                last_purchase.quantity AS last_purchase_quantity,
                last_purchase.unit_cost AS last_purchase_unit_cost
            FROM \"Product\" p
            LEFT JOIN sales_window s ON s.product_id = p.id
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*) FILTER (WHERE il.remaining_quantity > 0)::int AS open_lots_count,
                    COALESCE(SUM(il.remaining_quantity) FILTER (WHERE il.remaining_quantity > 0), 0)::int AS remaining_units_total,
                    COALESCE(SUM(il.remaining_quantity * il.unit_cost) FILTER (WHERE il.remaining_quantity > 0), 0) AS remaining_cost_total,
                    CASE
                        WHEN COALESCE(SUM(il.remaining_quantity) FILTER (WHERE il.remaining_quantity > 0), 0) > 0
                            THEN COALESCE(SUM(il.remaining_quantity * il.unit_cost) FILTER (WHERE il.remaining_quantity > 0), 0)
                                / NULLIF(SUM(il.remaining_quantity) FILTER (WHERE il.remaining_quantity > 0), 0)
                        ELSE 0
                    END AS weighted_unit_cost,
                    COUNT(*) FILTER (
                        WHERE il.remaining_quantity > 0
                          AND (il.purchase_invoice_id IS NULL OR COALESCE(il.source_type, '') <> 'purchase_invoice')
                    )::int AS unlinked_open_lots_count
                FROM \"InventoryLot\" il
                WHERE il.tenant_id = p.tenant_id
                  AND il.product_id = p.id
            ) open_stock ON true
            LEFT JOIN LATERAL (
                SELECT
                    il.purchase_invoice_id,
                    pi.invoice_number,
                    pi.supplier_name,
                    pi.supplier_document,
                    pi.issued_at,
                    il.received_at,
                    COALESCE(pii.quantity, il.initial_quantity, 0)::int AS quantity,
                    COALESCE(pii.unit_cost, il.unit_cost, 0) AS unit_cost
                FROM \"InventoryLot\" il
                LEFT JOIN \"PurchaseInvoice\" pi
                  ON pi.id = il.purchase_invoice_id
                 AND pi.tenant_id = il.tenant_id
                LEFT JOIN \"PurchaseInvoiceItem\" pii
                  ON pii.id = il.purchase_invoice_item_id
                 AND pii.tenant_id = il.tenant_id
                WHERE il.tenant_id = p.tenant_id
                  AND il.product_id = p.id
                  AND il.purchase_invoice_id IS NOT NULL
                ORDER BY COALESCE(pi.issued_at::timestamp, il.received_at, il.created_at) DESC,
                         il.created_at DESC,
                         il.id DESC
                LIMIT 1
            ) last_purchase ON true
            WHERE p.tenant_id = :tenant_id
              AND COALESCE(p.attributes->>'archived', 'false') <> 'true'
            ORDER BY p.name ASC
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'window_days' => $windowDays,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $rows[] = $this->normalizeRow($row, $windowDays, $targetDays);
        }

        usort($rows, function (array $left, array $right): int {
            $score = ((int)($right['priority_score'] ?? 0)) <=> ((int)($left['priority_score'] ?? 0));
            if ($score !== 0) {
                return $score;
            }
            return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        return $rows;
    }

    private function normalizeRow(array $row, int $windowDays, int $targetDays): array {
        $attributes = $this->decodeAttributes($row['attributes'] ?? null);
        $quantity = max(0, (int)($row['quantity'] ?? 0));
        $unitsSold = max(0, (int)($row['units_sold_window'] ?? 0));
        $avgDailyUnits = $windowDays > 0 ? $unitsSold / $windowDays : 0.0;
        $coverageDays = $avgDailyUnits > 0 ? min(9999, (int)ceil($quantity / $avgDailyUnits)) : null;

        $reorderPoint = $this->attributeInt($attributes, ['reorderPoint', 'stockMin'], 5, 1, 20000);
        $stockMax = $this->attributeInt(
            $attributes,
            ['stockMax', 'idealStock', 'overstockThreshold'],
            max(20, $reorderPoint * 3),
            $reorderPoint + 1,
            50000
        );
        $criticalPoint = max(1, (int)floor($reorderPoint / 2));

        $productCost = max(0.0, (float)($row['cost'] ?? 0));
        $openLotUnits = max(0, (int)($row['remaining_units_total'] ?? 0));
        $weightedUnitCost = max(0.0, (float)($row['weighted_unit_cost'] ?? 0));
        $unitCost = $openLotUnits > 0 && $weightedUnitCost > 0 ? $weightedUnitCost : $productCost;
        $inventoryCost = $openLotUnits > 0
            ? max(0.0, (float)($row['remaining_cost_total'] ?? 0))
            : ($quantity * $unitCost);

        $taxRate = $this->taxRateForAttributes($attributes);
        $taxMultiplier = 1 + ($taxRate / 100);
        $priceNet = max(0.0, (float)($row['price'] ?? 0));
        $unitPrice = round($priceNet * $taxMultiplier, 4);
        $marketValue = $quantity * $unitPrice;
        $profitPerUnit = $priceNet - $unitCost;
        $margin = $priceNet > 0 ? (($profitPerUnit / $priceNet) * 100) : 0.0;

        $expiration = $this->expirationMeta($attributes);
        $supplier = trim((string)($row['last_purchase_supplier_name'] ?? ''));
        if ($supplier === '') {
            $supplier = trim((string)($attributes['supplier'] ?? ''));
        }

        $status = $this->resolveStatus($quantity, $criticalPoint, $reorderPoint, $stockMax, $expiration);
        [$recommendedAction, $suggestedQty] = $this->recommendActionAndQuantity(
            $status,
            $quantity,
            $avgDailyUnits,
            $reorderPoint,
            $stockMax,
            $targetDays,
            $expiration,
            $unitCost
        );

        $qualityIssues = [];
        if (trim((string)($attributes['sku'] ?? '')) === '') {
            $qualityIssues[] = 'missing_sku';
        }
        if ($unitCost <= 0) {
            $qualityIssues[] = 'missing_cost';
        }
        if ($unitPrice <= 0) {
            $qualityIssues[] = 'missing_price';
        }
        if ($supplier === '') {
            $qualityIssues[] = 'missing_supplier';
        }
        if ((int)($row['unlinked_open_lots_count'] ?? 0) > 0) {
            $qualityIssues[] = 'unlinked_stock';
        }

        $priorityScore = $this->priorityScore(
            $status,
            $recommendedAction,
            $quantity,
            $avgDailyUnits,
            $coverageDays,
            $margin,
            $inventoryCost,
            $expiration,
            count($qualityIssues)
        );

        return [
            'product_id' => (string)($row['id'] ?? ''),
            'legacy_id' => $row['legacy_id'] ?? null,
            'name' => (string)($row['name'] ?? 'Producto sin nombre'),
            'sku' => trim((string)($attributes['sku'] ?? '')),
            'category' => trim((string)($row['category'] ?? '')) ?: 'Sin categoría',
            'product_type' => trim((string)($row['product_type'] ?? '')),
            'supplier' => $supplier,
            'quantity' => $quantity,
            'status' => $status,
            'avg_daily_units' => $this->roundNumber($avgDailyUnits, 4),
            'units_sold_window' => $unitsSold,
            'coverage_days' => $coverageDays,
            'reorder_point' => $reorderPoint,
            'critical_point' => $criticalPoint,
            'stock_max' => $stockMax,
            'unit_cost' => $this->roundMoney($unitCost),
            'inventory_cost' => $this->roundMoney($inventoryCost),
            'unit_price' => $this->roundMoney($unitPrice),
            'price_net' => $this->roundMoney($priceNet),
            'market_value' => $this->roundMoney($marketValue),
            'potential_profit' => $this->roundMoney($marketValue - $inventoryCost),
            'margin' => $this->roundNumber($margin, 1),
            'expiration_date' => $expiration['date'],
            'expiration_alert_days' => $expiration['alert_days'],
            'days_to_expire' => $expiration['days_to_expire'],
            'days_expired' => $expiration['days_expired'],
            'open_lots_count' => max(0, (int)($row['open_lots_count'] ?? 0)),
            'unlinked_open_lots_count' => max(0, (int)($row['unlinked_open_lots_count'] ?? 0)),
            'last_purchase_invoice_id' => trim((string)($row['last_purchase_invoice_id'] ?? '')),
            'last_purchase_invoice_number' => trim((string)($row['last_purchase_invoice_number'] ?? '')),
            'last_purchase_issued_at' => $row['last_purchase_issued_at'] ?? null,
            'last_purchase_received_at' => $row['last_purchase_received_at'] ?? null,
            'last_purchase_quantity' => max(0, (int)($row['last_purchase_quantity'] ?? 0)),
            'last_purchase_unit_cost' => $this->roundMoney($row['last_purchase_unit_cost'] ?? 0),
            'priority_score' => $priorityScore,
            'recommended_action' => $recommendedAction,
            'suggested_purchase_qty' => $suggestedQty,
            'suggested_purchase_cost' => $this->roundMoney($suggestedQty * $unitCost),
            'quality_issues' => $qualityIssues,
            'published' => $this->boolLike($row['is_published'] ?? true),
        ];
    }

    private function recommendActionAndQuantity(
        string $status,
        int $quantity,
        float $avgDailyUnits,
        int $reorderPoint,
        int $stockMax,
        int $targetDays,
        array $expiration,
        float $unitCost
    ): array {
        if ($status === 'expired') {
            return ['remove_expired', 0];
        }
        if ($status === 'expiring') {
            return ['rotate_or_discount', 0];
        }
        if ($status === 'overstock') {
            return ['reduce_or_promote', 0];
        }
        if ($unitCost <= 0) {
            return ['fix_data', 0];
        }
        if ($avgDailyUnits <= 0) {
            if ($quantity <= 0) {
                return ['review_assortment', 0];
            }
            return ['monitor', 0];
        }

        $targetStock = max($reorderPoint, (int)ceil($avgDailyUnits * $targetDays));
        $targetStock = min(max($targetStock, $reorderPoint), max($stockMax, $reorderPoint));
        $suggestedQty = max(0, $targetStock - $quantity);

        if ($status === 'out' && $suggestedQty > 0) {
            return ['restock_now', $suggestedQty];
        }
        if (in_array($status, ['critical', 'low'], true) && $suggestedQty > 0) {
            return ['restock_soon', $suggestedQty];
        }
        if ($expiration['days_to_expire'] !== null && (int)$expiration['days_to_expire'] <= 30) {
            return ['rotate_or_discount', 0];
        }

        return ['monitor', 0];
    }

    private function priorityScore(
        string $status,
        string $action,
        int $quantity,
        float $avgDailyUnits,
        ?int $coverageDays,
        float $margin,
        float $inventoryCost,
        array $expiration,
        int $qualityIssueCount
    ): int {
        $score = 0;
        $score += match ($status) {
            'expired' => 95,
            'out' => 70,
            'critical' => 58,
            'expiring' => 55,
            'low' => 42,
            'overstock' => 35,
            default => 0,
        };

        if ($avgDailyUnits > 0) {
            $score += 10;
        }
        if ($coverageDays !== null) {
            if ($coverageDays <= 7) {
                $score += 22;
            } elseif ($coverageDays <= 14) {
                $score += 15;
            } elseif ($coverageDays <= 30) {
                $score += 8;
            }
        }
        if ($margin >= 35 && in_array($action, ['restock_now', 'restock_soon'], true)) {
            $score += 8;
        }
        if ($inventoryCost >= 100 && in_array($action, ['reduce_or_promote', 'rotate_or_discount'], true)) {
            $score += 8;
        }
        if ($expiration['days_to_expire'] !== null && (int)$expiration['days_to_expire'] <= 15) {
            $score += 12;
        }
        if ($quantity <= 0 && $avgDailyUnits <= 0 && $action === 'review_assortment') {
            $score = max($score, 35);
        }
        $score += min(15, $qualityIssueCount * 5);

        return max(0, min(100, $score));
    }

    private function resolveStatus(int $quantity, int $criticalPoint, int $reorderPoint, int $stockMax, array $expiration): string {
        if ($quantity > 0 && $expiration['days_expired'] !== null) {
            return 'expired';
        }
        if ($quantity > 0 && $expiration['days_to_expire'] !== null && $expiration['days_to_expire'] <= $expiration['alert_days']) {
            return 'expiring';
        }
        if ($quantity <= 0) {
            return 'out';
        }
        if ($quantity <= $criticalPoint) {
            return 'critical';
        }
        if ($quantity <= $reorderPoint) {
            return 'low';
        }
        if ($quantity >= $stockMax) {
            return 'overstock';
        }
        return 'available';
    }

    private function buildSummary(array $rows): array {
        $summary = [
            'total_skus' => count($rows),
            'total_units' => 0,
            'skus_with_stock' => 0,
            'inventory_cost' => 0.0,
            'market_value' => 0.0,
            'potential_profit' => 0.0,
            'purchase_recommended_skus' => 0,
            'suggested_purchase_units' => 0,
            'suggested_purchase_cost' => 0.0,
            'risk_skus' => 0,
            'expired_units' => 0,
            'expiring_units' => 0,
            'overstock_capital' => 0.0,
            'avg_margin' => 0.0,
        ];
        $marginTotal = 0.0;
        $marginCount = 0;

        foreach ($rows as $row) {
            $qty = (int)($row['quantity'] ?? 0);
            $summary['total_units'] += $qty;
            if ($qty > 0) {
                $summary['skus_with_stock'] += 1;
            }
            $summary['inventory_cost'] += (float)($row['inventory_cost'] ?? 0);
            $summary['market_value'] += (float)($row['market_value'] ?? 0);
            $summary['potential_profit'] += (float)($row['potential_profit'] ?? 0);
            $summary['suggested_purchase_units'] += (int)($row['suggested_purchase_qty'] ?? 0);
            $summary['suggested_purchase_cost'] += (float)($row['suggested_purchase_cost'] ?? 0);
            if ((int)($row['suggested_purchase_qty'] ?? 0) > 0) {
                $summary['purchase_recommended_skus'] += 1;
            }
            if (in_array((string)($row['status'] ?? ''), ['out', 'critical', 'low', 'expired', 'expiring'], true)) {
                $summary['risk_skus'] += 1;
            }
            if (($row['status'] ?? '') === 'expired') {
                $summary['expired_units'] += $qty;
            }
            if (($row['status'] ?? '') === 'expiring') {
                $summary['expiring_units'] += $qty;
            }
            if (($row['status'] ?? '') === 'overstock') {
                $summary['overstock_capital'] += (float)($row['inventory_cost'] ?? 0);
            }
            if ((float)($row['unit_price'] ?? 0) > 0 && (float)($row['unit_cost'] ?? 0) > 0) {
                $marginTotal += (float)($row['margin'] ?? 0);
                $marginCount += 1;
            }
        }

        $summary['avg_margin'] = $marginCount > 0 ? $this->roundNumber($marginTotal / $marginCount, 1) : 0.0;
        foreach (['inventory_cost', 'market_value', 'potential_profit', 'suggested_purchase_cost', 'overstock_capital'] as $key) {
            $summary[$key] = $this->roundMoney($summary[$key]);
        }

        return $summary;
    }

    private function buildHealth(array $rows): array {
        $health = [
            'available' => 0,
            'out_of_stock' => 0,
            'critical_stock' => 0,
            'low_stock' => 0,
            'overstock' => 0,
            'expired_products' => 0,
            'expiring_products' => 0,
            'purchase_recommended' => 0,
            'review_recommended' => 0,
            'data_quality_issues' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if ($status === 'available') $health['available'] += 1;
            if ($status === 'out') $health['out_of_stock'] += 1;
            if ($status === 'critical') $health['critical_stock'] += 1;
            if ($status === 'low') $health['low_stock'] += 1;
            if ($status === 'overstock') $health['overstock'] += 1;
            if ($status === 'expired') $health['expired_products'] += 1;
            if ($status === 'expiring') $health['expiring_products'] += 1;
            if ((int)($row['suggested_purchase_qty'] ?? 0) > 0) $health['purchase_recommended'] += 1;
            if (in_array((string)($row['recommended_action'] ?? ''), ['review_assortment', 'fix_data'], true)) $health['review_recommended'] += 1;
            if (count($row['quality_issues'] ?? []) > 0) $health['data_quality_issues'] += 1;
        }

        return $health;
    }

    private function buildActions(array $rows): array {
        $actionRows = array_values(array_filter($rows, static fn(array $row): bool => ($row['recommended_action'] ?? 'monitor') !== 'monitor'));
        usort($actionRows, fn($left, $right) => ((int)($right['priority_score'] ?? 0)) <=> ((int)($left['priority_score'] ?? 0)));

        return array_map(function (array $row): array {
            return [
                'id' => ($row['product_id'] ?? '') . ':' . ($row['recommended_action'] ?? 'monitor'),
                'product_id' => $row['product_id'] ?? '',
                'name' => $row['name'] ?? '',
                'sku' => $row['sku'] ?? '',
                'supplier' => $row['supplier'] ?? '',
                'severity' => $this->severityForScore((int)($row['priority_score'] ?? 0)),
                'title' => $this->actionTitle((string)($row['recommended_action'] ?? 'monitor')),
                'detail' => $this->actionDetail($row),
                'action' => $row['recommended_action'] ?? 'monitor',
                'priority_score' => (int)($row['priority_score'] ?? 0),
                'suggested_purchase_qty' => (int)($row['suggested_purchase_qty'] ?? 0),
                'suggested_purchase_cost' => $this->roundMoney($row['suggested_purchase_cost'] ?? 0),
            ];
        }, array_slice($actionRows, 0, 20));
    }

    private function buildPurchasePlan(array $rows): array {
        $groups = [];
        foreach ($rows as $row) {
            $qty = (int)($row['suggested_purchase_qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $supplier = trim((string)($row['supplier'] ?? ''));
            if ($supplier === '') {
                $supplier = 'Proveedor por definir';
            }
            if (!isset($groups[$supplier])) {
                $groups[$supplier] = [
                    'supplier' => $supplier,
                    'items_count' => 0,
                    'units' => 0,
                    'estimated_cost' => 0.0,
                    'max_priority_score' => 0,
                    'items' => [],
                ];
            }
            $groups[$supplier]['items_count'] += 1;
            $groups[$supplier]['units'] += $qty;
            $groups[$supplier]['estimated_cost'] += (float)($row['suggested_purchase_cost'] ?? 0);
            $groups[$supplier]['max_priority_score'] = max($groups[$supplier]['max_priority_score'], (int)($row['priority_score'] ?? 0));
            $groups[$supplier]['items'][] = [
                'product_id' => $row['product_id'] ?? '',
                'name' => $row['name'] ?? '',
                'sku' => $row['sku'] ?? '',
                'quantity' => $qty,
                'unit_cost' => $this->roundMoney($row['unit_cost'] ?? 0),
                'estimated_cost' => $this->roundMoney($row['suggested_purchase_cost'] ?? 0),
                'priority_score' => (int)($row['priority_score'] ?? 0),
            ];
        }

        $plan = array_values($groups);
        foreach ($plan as &$group) {
            $group['estimated_cost'] = $this->roundMoney($group['estimated_cost']);
            usort($group['items'], fn($left, $right) => ((int)$right['priority_score']) <=> ((int)$left['priority_score']));
        }
        unset($group);

        usort($plan, fn($left, $right) => ((int)$right['max_priority_score']) <=> ((int)$left['max_priority_score']));
        return $plan;
    }

    private function buildCategorySummary(array $rows): array {
        return $this->buildGroupedSummary($rows, 'category');
    }

    private function buildSupplierSummary(array $rows): array {
        return $this->buildGroupedSummary($rows, 'supplier', 'Proveedor por definir');
    }

    private function buildGroupedSummary(array $rows, string $key, string $fallback = 'Sin categoría'): array {
        $groups = [];
        foreach ($rows as $row) {
            $name = trim((string)($row[$key] ?? '')) ?: $fallback;
            if (!isset($groups[$name])) {
                $groups[$name] = [
                    $key => $name,
                    'skus' => 0,
                    'units' => 0,
                    'inventory_cost' => 0.0,
                    'market_value' => 0.0,
                    'suggested_purchase_units' => 0,
                    'suggested_purchase_cost' => 0.0,
                    'risk_skus' => 0,
                ];
            }
            $groups[$name]['skus'] += 1;
            $groups[$name]['units'] += (int)($row['quantity'] ?? 0);
            $groups[$name]['inventory_cost'] += (float)($row['inventory_cost'] ?? 0);
            $groups[$name]['market_value'] += (float)($row['market_value'] ?? 0);
            $groups[$name]['suggested_purchase_units'] += (int)($row['suggested_purchase_qty'] ?? 0);
            $groups[$name]['suggested_purchase_cost'] += (float)($row['suggested_purchase_cost'] ?? 0);
            if (in_array((string)($row['status'] ?? ''), ['out', 'critical', 'low', 'expired', 'expiring'], true)) {
                $groups[$name]['risk_skus'] += 1;
            }
        }

        $output = array_values($groups);
        foreach ($output as &$group) {
            $group['inventory_cost'] = $this->roundMoney($group['inventory_cost']);
            $group['market_value'] = $this->roundMoney($group['market_value']);
            $group['suggested_purchase_cost'] = $this->roundMoney($group['suggested_purchase_cost']);
        }
        unset($group);

        usort($output, fn($left, $right) => ((float)$right['inventory_cost']) <=> ((float)$left['inventory_cost']));
        return $output;
    }

    private function actionTitle(string $action): string {
        return match ($action) {
            'restock_now' => 'Comprar ahora',
            'restock_soon' => 'Programar reposición',
            'rotate_or_discount' => 'Rotar o liquidar',
            'remove_expired' => 'Retirar vencido',
            'reduce_or_promote' => 'Liberar capital',
            'fix_data' => 'Completar datos',
            'review_assortment' => 'Revisar surtido',
            default => 'Monitorear',
        };
    }

    private function actionDetail(array $row): string {
        $qty = (int)($row['suggested_purchase_qty'] ?? 0);
        if ($qty > 0) {
            return 'Sugerido +' . $qty . ' uds para cubrir demanda y mínimo operativo.';
        }
        return match ((string)($row['recommended_action'] ?? 'monitor')) {
            'rotate_or_discount' => 'Tiene stock con vencimiento cercano; prioriza venta o descuento antes de comprar.',
            'remove_expired' => 'Tiene stock vencido; retíralo de venta y registra ajuste/merma.',
            'reduce_or_promote' => 'Tiene más stock que el máximo operativo; promociona o reduce próxima compra.',
            'fix_data' => 'Faltan datos críticos como costo, precio, SKU, proveedor o enlace de lote.',
            'review_assortment' => 'Sin ventas recientes; valida si debe seguir en catálogo antes de reponer.',
            default => 'Sin acción urgente.',
        };
    }

    private function severityForScore(int $score): string {
        if ($score >= 75) return 'critical';
        if ($score >= 45) return 'warning';
        return 'info';
    }

    private function expirationMeta(array $attributes): array {
        $rawDate = trim((string)($attributes['expirationDate'] ?? $attributes['expiryDate'] ?? ''));
        $alertDays = $this->attributeInt($attributes, ['expirationAlertDays', 'expiryAlertDays'], 30, 0, 3650);
        if ($rawDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) !== 1) {
            return [
                'date' => null,
                'alert_days' => $alertDays,
                'days_to_expire' => null,
                'days_expired' => null,
            ];
        }

        $today = new \DateTimeImmutable('today');
        $expiry = \DateTimeImmutable::createFromFormat('Y-m-d', $rawDate);
        if (!$expiry) {
            return [
                'date' => null,
                'alert_days' => $alertDays,
                'days_to_expire' => null,
                'days_expired' => null,
            ];
        }

        $diff = (int)$today->diff($expiry)->format('%r%a');
        return [
            'date' => $expiry->format('Y-m-d'),
            'alert_days' => $alertDays,
            'days_to_expire' => $diff >= 0 ? $diff : null,
            'days_expired' => $diff < 0 ? abs($diff) : null,
        ];
    }

    private function taxRateForAttributes(array $attributes): float {
        $taxExempt = $this->boolLike($attributes['taxExempt'] ?? ($attributes['tax_exempt'] ?? false));
        if ($taxExempt) {
            return 0.0;
        }
        $rawRate = $attributes['taxRate'] ?? ($attributes['tax_rate'] ?? null);
        if (is_numeric($rawRate)) {
            return max(0.0, min(100.0, (float)$rawRate));
        }
        return $this->defaultVatRate;
    }

    private function attributeInt(array $attributes, array $keys, int $default, int $min, int $max): int {
        foreach ($keys as $key) {
            if (array_key_exists($key, $attributes) && is_numeric($attributes[$key])) {
                return max($min, min($max, (int)$attributes[$key]));
            }
        }
        return max($min, min($max, $default));
    }

    private function decodeAttributes($value): array {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function boolLike($value): bool {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (float)$value !== 0.0;
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 't', 'yes', 'y', 'on', 'si', 'sí'], true);
        }
        return false;
    }

    private function roundMoney($value): float {
        return round((float)($value ?? 0), 2);
    }

    private function roundNumber($value, int $precision): float {
        return round((float)($value ?? 0), $precision);
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}
