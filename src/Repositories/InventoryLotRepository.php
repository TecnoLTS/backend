<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\CatalogInventory\Domain\CatalogInventoryDomain;
use PDO;

class InventoryLotRepository {
    private $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getModuleInstance(CatalogInventoryDomain::KEY);
    }

    public function previewSaleAllocation(string $productId, int $quantity, int $availableQuantity, float $fallbackUnitCost): array {
        if ($quantity <= 0) {
            return [
                'unit_cost' => 0.0,
                'cost_total' => 0.0,
                'allocations' => []
            ];
        }

        $lots = $this->getAvailableLots($productId, false);
        $lotsCoveredQty = $this->sumRemainingQuantity($lots);

        if ($lotsCoveredQty < $quantity && $availableQuantity > $lotsCoveredQty) {
            $reconciliationQty = min($availableQuantity - $lotsCoveredQty, $quantity - $lotsCoveredQty);
            if ($reconciliationQty > 0) {
                $lots[] = [
                    'id' => '__virtual_reconciliation__',
                    'remaining_quantity' => $reconciliationQty,
                    'unit_cost' => $this->roundMoney($fallbackUnitCost),
                    'received_at' => null,
                    'created_at' => null
                ];
            }
        }

        return $this->allocateAcrossLots($lots, $quantity);
    }

    public function recordStockIncrease(
        string $productId,
        int $quantity,
        float $unitCost,
        string $sourceType,
        ?string $sourceRef = null,
        array $metadata = [],
        ?string $purchaseInvoiceId = null,
        ?string $purchaseInvoiceItemId = null
    ): void {
        $quantity = max(0, $quantity);
        if ($quantity <= 0) {
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO "InventoryLot" (
                id,
                tenant_id,
                product_id,
                source_type,
                source_ref,
                purchase_invoice_id,
                purchase_invoice_item_id,
                unit_cost,
                initial_quantity,
                remaining_quantity,
                metadata,
                received_at,
                created_at,
                updated_at
            ) VALUES (
                :id,
                :tenant_id,
                :product_id,
                :source_type,
                :source_ref,
                :purchase_invoice_id,
                :purchase_invoice_item_id,
                :unit_cost,
                :initial_quantity,
                :remaining_quantity,
                :metadata,
                NOW(),
                NOW(),
                NOW()
            )
        ');
        $stmt->execute([
            'id' => uniqid('lot_'),
            'tenant_id' => $this->getTenantId(),
            'product_id' => $productId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'purchase_invoice_id' => $purchaseInvoiceId,
            'purchase_invoice_item_id' => $purchaseInvoiceItemId,
            'unit_cost' => $this->roundMoney($unitCost),
            'initial_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'metadata' => $this->encodeMetadata($metadata)
        ]);
    }

    public function consumeAdjustment(string $productId, int $quantity, int $availableQuantity, float $fallbackUnitCost, array $metadata = []): array {
        $quantity = max(0, $quantity);
        if ($quantity <= 0) {
            return [
                'unit_cost' => 0.0,
                'cost_total' => 0.0,
                'allocations' => []
            ];
        }

        $this->ensureCoverageForAvailableStock($productId, $availableQuantity, $fallbackUnitCost, $metadata);
        $lots = $this->getAvailableLots($productId, true);
        $allocation = $this->allocateAcrossLots($lots, $quantity);

        foreach ($allocation['allocations'] as $segment) {
            $stmt = $this->db->prepare('
                UPDATE "InventoryLot"
                SET remaining_quantity = remaining_quantity - :quantity,
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ');
            $stmt->execute([
                'quantity' => $segment['quantity'],
                'id' => $segment['lot_id'],
                'tenant_id' => $this->getTenantId()
            ]);
        }

        return $allocation;
    }

    public function consumeForOrderItem(string $productId, string $orderItemId, int $quantity, int $availableQuantity, float $fallbackUnitCost, array $metadata = []): array {
        $quantity = max(0, $quantity);
        if ($quantity <= 0) {
            return [
                'unit_cost' => 0.0,
                'cost_total' => 0.0,
                'allocations' => []
            ];
        }

        $this->ensureCoverageForAvailableStock($productId, $availableQuantity, $fallbackUnitCost, $metadata);
        $lots = $this->getAvailableLots($productId, true);
        $allocation = $this->allocateAcrossLots($lots, $quantity);

        foreach ($allocation['allocations'] as $segment) {
            $stmtLot = $this->db->prepare('
                UPDATE "InventoryLot"
                SET remaining_quantity = remaining_quantity - :quantity,
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ');
            $stmtLot->execute([
                'quantity' => $segment['quantity'],
                'id' => $segment['lot_id'],
                'tenant_id' => $this->getTenantId()
            ]);

            $stmtAllocation = $this->db->prepare('
                INSERT INTO "InventoryLotAllocation" (
                    id,
                    tenant_id,
                    lot_id,
                    order_item_id,
                    product_id,
                    quantity,
                    unit_cost,
                    metadata,
                    created_at
                ) VALUES (
                    :id,
                    :tenant_id,
                    :lot_id,
                    :order_item_id,
                    :product_id,
                    :quantity,
                    :unit_cost,
                    :metadata,
                    NOW()
                )
            ');
            $stmtAllocation->execute([
                'id' => uniqid('lota_'),
                'tenant_id' => $this->getTenantId(),
                'lot_id' => $segment['lot_id'],
                'order_item_id' => $orderItemId,
                'product_id' => $productId,
                'quantity' => $segment['quantity'],
                'unit_cost' => $segment['unit_cost'],
                'metadata' => $this->encodeMetadata($metadata)
            ]);
        }

        return $allocation;
    }

    public function restoreForOrderItem(string $orderItemId): array {
        $stmt = $this->db->prepare('
            SELECT id, lot_id, quantity
            FROM "InventoryLotAllocation"
            WHERE tenant_id = :tenant_id
              AND order_item_id = :order_item_id
            ORDER BY created_at ASC, id ASC
            FOR UPDATE
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'order_item_id' => $orderItemId
        ]);
        $allocations = $stmt->fetchAll() ?: [];

        $restoredQuantity = 0;
        foreach ($allocations as $allocation) {
            $qty = max(0, (int)($allocation['quantity'] ?? 0));
            if ($qty <= 0) {
                continue;
            }

            $stmtRestore = $this->db->prepare('
                UPDATE "InventoryLot"
                SET remaining_quantity = remaining_quantity + :quantity,
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ');
            $stmtRestore->execute([
                'quantity' => $qty,
                'id' => $allocation['lot_id'],
                'tenant_id' => $this->getTenantId()
            ]);
            $restoredQuantity += $qty;
        }

        if (count($allocations) > 0) {
            $stmtDelete = $this->db->prepare('
                DELETE FROM "InventoryLotAllocation"
                WHERE tenant_id = :tenant_id
                  AND order_item_id = :order_item_id
            ');
            $stmtDelete->execute([
                'tenant_id' => $this->getTenantId(),
                'order_item_id' => $orderItemId
            ]);
        }

        return [
            'restored_quantity' => $restoredQuantity,
            'allocations_count' => count($allocations)
        ];
    }

    public function closeOpenLotsForProduct(string $productId, string $reason, array $metadata = []): array {
        $reason = trim($reason) !== '' ? trim($reason) : 'inventory_lot_closure';
        $metadata = array_merge([
            'reason' => $reason,
            'closed_at' => gmdate('c'),
        ], $metadata);

        $stmt = $this->db->prepare('
            UPDATE "InventoryLot"
            SET metadata = COALESCE(metadata, \'{}\'::jsonb)
                    || CAST(:metadata AS jsonb)
                    || jsonb_build_object(
                        \'previous_remaining_quantity\', remaining_quantity,
                        \'closure_reason\', CAST(:reason AS text)
                    ),
                remaining_quantity = 0,
                updated_at = NOW()
            WHERE tenant_id = CAST(:tenant_id AS text)
              AND product_id = CAST(:product_id AS text)
              AND remaining_quantity > 0
            RETURNING id, remaining_quantity AS closed_remaining_quantity
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'product_id' => $productId,
            'reason' => $reason,
            'metadata' => $this->encodeMetadata($metadata),
        ]);
        $rows = $stmt->fetchAll() ?: [];

        return [
            'closed_lots_count' => count($rows),
            'closed_lot_ids' => array_map(static fn(array $row): string => (string)($row['id'] ?? ''), $rows),
        ];
    }

    private function ensureCoverageForAvailableStock(string $productId, int $availableQuantity, float $fallbackUnitCost, array $metadata = []): void {
        $availableQuantity = max(0, $availableQuantity);
        if ($availableQuantity <= 0) {
            return;
        }

        $stmt = $this->db->prepare('
            SELECT COALESCE(SUM(remaining_quantity), 0) AS covered_quantity
            FROM "InventoryLot"
            WHERE tenant_id = :tenant_id
              AND product_id = :product_id
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'product_id' => $productId
        ]);
        $coveredQuantity = (int)($stmt->fetchColumn() ?: 0);
        $gap = $availableQuantity - $coveredQuantity;

        if ($gap > 0) {
            $metadata['reconciled_gap'] = $gap;
            $this->recordStockIncrease(
                $productId,
                $gap,
                $fallbackUnitCost,
                'stock_reconciliation',
                $productId,
                $metadata
            );
        }
    }

    private function getAvailableLots(string $productId, bool $forUpdate): array {
        $sql = '
            SELECT id, remaining_quantity, unit_cost, received_at, created_at
            FROM "InventoryLot"
            WHERE tenant_id = :tenant_id
              AND product_id = :product_id
              AND remaining_quantity > 0
            ORDER BY received_at ASC NULLS LAST, created_at ASC NULLS LAST, id ASC
        ';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'product_id' => $productId
        ]);
        return $stmt->fetchAll() ?: [];
    }

    private function allocateAcrossLots(array $lots, int $quantity): array {
        $remaining = max(0, $quantity);
        $allocations = [];
        $costTotal = 0.0;

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $lotQty = max(0, (int)($lot['remaining_quantity'] ?? 0));
            if ($lotQty <= 0) {
                continue;
            }

            $consumeQty = min($remaining, $lotQty);
            $unitCost = $this->roundMoney((float)($lot['unit_cost'] ?? 0));

            $allocations[] = [
                'lot_id' => (string)$lot['id'],
                'quantity' => $consumeQty,
                'unit_cost' => $unitCost
            ];
            $costTotal += $consumeQty * $unitCost;
            $remaining -= $consumeQty;
        }

        if ($remaining > 0) {
            throw new \Exception('Stock por lote insuficiente para completar la operación.');
        }

        $costTotal = round($costTotal, 4);
        $unitCost = $quantity > 0 ? round($costTotal / $quantity, 4) : 0.0;

        return [
            'unit_cost' => $unitCost,
            'cost_total' => $costTotal,
            'allocations' => $allocations
        ];
    }

    private function sumRemainingQuantity(array $lots): int {
        $total = 0;
        foreach ($lots as $lot) {
            $total += max(0, (int)($lot['remaining_quantity'] ?? 0));
        }
        return $total;
    }

    private function encodeMetadata(array $metadata): ?string {
        return count($metadata) > 0 ? json_encode($metadata) : null;
    }

    private function roundMoney(float $value): float {
        return round($value, 4);
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}
