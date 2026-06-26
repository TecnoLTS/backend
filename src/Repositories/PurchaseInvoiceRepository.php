<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\CatalogInventory\Domain\CatalogInventoryDomain;
use PDO;

class PurchaseInvoiceRepository {
    private $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getModuleInstance(CatalogInventoryDomain::KEY);
    }

    public function recordStockEntry(array $purchaseInvoiceData, string $productId, string $productName, int $quantity, float $unitCost, array $metadata = []): array {
        $quantity = max(0, $quantity);
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('La cantidad de compra debe ser mayor a 0.');
        }

        $payload = $this->normalizePayload($purchaseInvoiceData);
        $invoice = $this->findOrCreateInvoice($payload);
        $item = $this->createInvoiceItem($invoice['id'], $productId, $productName, $quantity, $unitCost, $metadata);
        $invoice = $this->recalculateInvoiceTotals($invoice['id']);

        return [
            'invoice' => $invoice,
            'item' => $item,
        ];
    }

    public function listRecent(int $limit = 100): array {
        $safeLimit = max(1, min(200, $limit));
        $stmt = $this->db->prepare("
            SELECT
                pi.id,
                pi.invoice_number,
                pi.supplier_name,
                pi.supplier_document,
                pi.issued_at,
                pi.subtotal,
                pi.tax_total,
                pi.total,
                pi.notes,
                pi.created_at,
                COUNT(pii.id)::int AS items_count,
                COALESCE(SUM(pii.quantity), 0)::int AS units_total,
                COUNT(DISTINCT pii.product_id)::int AS products_count
            FROM \"PurchaseInvoice\" pi
            LEFT JOIN \"PurchaseInvoiceItem\" pii
              ON pii.purchase_invoice_id = pi.id
             AND pii.tenant_id = pi.tenant_id
            WHERE pi.tenant_id = :tenant_id
            GROUP BY pi.id
            ORDER BY pi.issued_at DESC, pi.created_at DESC
            LIMIT $safeLimit
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return $stmt->fetchAll() ?: [];
    }

    public function summarizePeriod(string $startDate, string $endExclusive): array {
        $stmt = $this->db->prepare("
            WITH scoped_invoices AS (
                SELECT
                    id,
                    supplier_name,
                    COALESCE(subtotal, 0) AS subtotal,
                    COALESCE(tax_total, 0) AS tax_total,
                    COALESCE(total, 0) AS total
                FROM \"PurchaseInvoice\"
                WHERE tenant_id = :tenant_id
                  AND issued_at >= CAST(:start_date AS date)
                  AND issued_at < CAST(:end_exclusive AS date)
            ),
            item_totals AS (
                SELECT
                    pii.purchase_invoice_id,
                    COALESCE(SUM(pii.quantity), 0)::int AS units_total,
                    COUNT(DISTINCT pii.product_id)::int AS products_count
                FROM \"PurchaseInvoiceItem\" pii
                JOIN scoped_invoices si ON si.id = pii.purchase_invoice_id
                WHERE pii.tenant_id = :tenant_id
                GROUP BY pii.purchase_invoice_id
            )
            SELECT
                COUNT(si.id)::int AS invoices_count,
                COALESCE(SUM(si.subtotal), 0) AS subtotal,
                COALESCE(SUM(si.tax_total), 0) AS tax_total,
                COALESCE(SUM(si.total), 0) AS total,
                COALESCE(SUM(it.units_total), 0)::int AS units_total,
                COALESCE(SUM(it.products_count), 0)::int AS products_count,
                COUNT(DISTINCT NULLIF(TRIM(UPPER(si.supplier_name)), ''))::int AS suppliers_count
            FROM scoped_invoices si
            LEFT JOIN item_totals it ON it.purchase_invoice_id = si.id
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate,
            'end_exclusive' => $endExclusive,
        ]);
        $row = $stmt->fetch() ?: [];

        return [
            'invoices_count' => (int)($row['invoices_count'] ?? 0),
            'subtotal' => round((float)($row['subtotal'] ?? 0), 2),
            'tax_total' => round((float)($row['tax_total'] ?? 0), 2),
            'total' => round((float)($row['total'] ?? 0), 2),
            'units_total' => (int)($row['units_total'] ?? 0),
            'products_count' => (int)($row['products_count'] ?? 0),
            'suppliers_count' => (int)($row['suppliers_count'] ?? 0),
        ];
    }

    public function getById(string $id): ?array {
        $stmt = $this->db->prepare("
            SELECT
                id,
                invoice_number,
                supplier_name,
                supplier_document,
                issued_at,
                subtotal,
                tax_total,
                total,
                notes,
                metadata,
                created_at,
                updated_at
            FROM \"PurchaseInvoice\"
            WHERE id = :id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
        ]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            return null;
        }

        $stmtItems = $this->db->prepare("
            SELECT
                pii.id,
                pii.product_id,
                pii.product_name_snapshot,
                pii.quantity,
                pii.unit_cost,
                pii.line_total,
                pii.metadata,
                pii.created_at,
                p.category,
                p.brand
            FROM \"PurchaseInvoiceItem\" pii
            LEFT JOIN \"Product\" p
              ON p.id = pii.product_id
             AND p.tenant_id = pii.tenant_id
            WHERE pii.purchase_invoice_id = :invoice_id
              AND pii.tenant_id = :tenant_id
            ORDER BY pii.created_at ASC, pii.id ASC
        ");
        $stmtItems->execute([
            'invoice_id' => $id,
            'tenant_id' => $this->getTenantId(),
        ]);

        $invoice['metadata'] = $this->decodeJsonField($invoice['metadata'] ?? null);
        $invoice['items'] = array_map(function (array $row): array {
            $row['metadata'] = $this->decodeJsonField($row['metadata'] ?? null);
            return $row;
        }, $stmtItems->fetchAll() ?: []);

        return $invoice;
    }

    private function normalizePayload(array $purchaseInvoiceData): array {
        $invoiceNumber = trim((string)($purchaseInvoiceData['invoiceNumber'] ?? $purchaseInvoiceData['invoice_number'] ?? ''));
        $supplierName = trim((string)($purchaseInvoiceData['supplierName'] ?? $purchaseInvoiceData['supplier_name'] ?? ''));
        $supplierDocument = trim((string)($purchaseInvoiceData['supplierDocument'] ?? $purchaseInvoiceData['supplier_document'] ?? ''));
        $issuedAt = trim((string)($purchaseInvoiceData['issuedAt'] ?? $purchaseInvoiceData['issued_at'] ?? ''));
        $notes = trim((string)($purchaseInvoiceData['notes'] ?? ''));

        if ($invoiceNumber === '') {
            throw new \InvalidArgumentException('La factura de compra requiere un número de factura.');
        }
        if ($supplierName === '') {
            throw new \InvalidArgumentException('La factura de compra requiere el nombre del proveedor.');
        }
        if ($supplierDocument === '') {
            throw new \InvalidArgumentException('La factura de compra requiere el RUC o documento del proveedor.');
        }
        if ($issuedAt === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedAt) !== 1) {
            throw new \InvalidArgumentException('La factura de compra requiere una fecha válida en formato YYYY-MM-DD.');
        }

        $metadata = $purchaseInvoiceData['metadata'] ?? null;
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return [
            'invoice_number' => $invoiceNumber,
            'supplier_name' => $supplierName,
            'supplier_document' => $supplierDocument,
            'issued_at' => $issuedAt,
            'notes' => $notes !== '' ? $notes : null,
            'external_key' => $this->buildExternalKey($invoiceNumber, $supplierDocument),
            'metadata' => $metadata,
        ];
    }

    private function findOrCreateInvoice(array $payload): array {
        $stmt = $this->db->prepare("
            SELECT id
            FROM \"PurchaseInvoice\"
            WHERE tenant_id = :tenant_id
              AND external_key = :external_key
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'external_key' => $payload['external_key'],
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            $update = $this->db->prepare("
                UPDATE \"PurchaseInvoice\"
                SET supplier_name = :supplier_name,
                    supplier_document = :supplier_document,
                    invoice_number = :invoice_number,
                    issued_at = :issued_at,
                    notes = :notes,
                    metadata = :metadata,
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");
            $update->execute([
                'id' => $existing['id'],
                'tenant_id' => $this->getTenantId(),
                'supplier_name' => $payload['supplier_name'],
                'supplier_document' => $payload['supplier_document'],
                'invoice_number' => $payload['invoice_number'],
                'issued_at' => $payload['issued_at'],
                'notes' => $payload['notes'],
                'metadata' => $this->encodeJsonField($payload['metadata']),
            ]);

            return $this->getHeaderById((string)$existing['id']);
        }

        $id = uniqid('pinv_');
        $insert = $this->db->prepare("
            INSERT INTO \"PurchaseInvoice\" (
                id,
                tenant_id,
                supplier_name,
                supplier_document,
                invoice_number,
                external_key,
                issued_at,
                subtotal,
                tax_total,
                total,
                notes,
                metadata,
                created_at,
                updated_at
            ) VALUES (
                :id,
                :tenant_id,
                :supplier_name,
                :supplier_document,
                :invoice_number,
                :external_key,
                :issued_at,
                0,
                0,
                0,
                :notes,
                :metadata,
                NOW(),
                NOW()
            )
        ");
        $insert->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'supplier_name' => $payload['supplier_name'],
            'supplier_document' => $payload['supplier_document'],
            'invoice_number' => $payload['invoice_number'],
            'external_key' => $payload['external_key'],
            'issued_at' => $payload['issued_at'],
            'notes' => $payload['notes'],
            'metadata' => $this->encodeJsonField($payload['metadata']),
        ]);

        return $this->getHeaderById($id);
    }

    private function createInvoiceItem(string $invoiceId, string $productId, string $productName, int $quantity, float $unitCost, array $metadata = []): array {
        $unitCost = round($unitCost, 4);
        $lineTotal = round($quantity * $unitCost, 4);
        $id = uniqid('pitem_');
        $normalizedMetadata = $this->normalizeItemMetadata($metadata, $lineTotal);

        $stmt = $this->db->prepare("
            INSERT INTO \"PurchaseInvoiceItem\" (
                id,
                purchase_invoice_id,
                tenant_id,
                product_id,
                product_name_snapshot,
                quantity,
                unit_cost,
                line_total,
                metadata,
                created_at,
                updated_at
            ) VALUES (
                :id,
                :purchase_invoice_id,
                :tenant_id,
                :product_id,
                :product_name_snapshot,
                :quantity,
                :unit_cost,
                :line_total,
                :metadata,
                NOW(),
                NOW()
            )
        ");
        $stmt->execute([
            'id' => $id,
            'purchase_invoice_id' => $invoiceId,
            'tenant_id' => $this->getTenantId(),
            'product_id' => $productId,
            'product_name_snapshot' => $productName,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => $lineTotal,
            'metadata' => $this->encodeJsonField($normalizedMetadata),
        ]);

        return [
            'id' => $id,
            'purchase_invoice_id' => $invoiceId,
            'product_id' => $productId,
            'product_name_snapshot' => $productName,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => $lineTotal,
            'metadata' => $normalizedMetadata,
        ];
    }

    private function recalculateInvoiceTotals(string $invoiceId): array {
        $stmt = $this->db->prepare("
            SELECT
                line_total,
                metadata
            FROM \"PurchaseInvoiceItem\"
            WHERE tenant_id = :tenant_id
              AND purchase_invoice_id = :invoice_id
        ");
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'invoice_id' => $invoiceId,
        ]);
        $rows = $stmt->fetchAll() ?: [];
        $subtotal = 0.0;
        $taxTotal = 0.0;

        foreach ($rows as $row) {
            $lineTotal = round((float)($row['line_total'] ?? 0), 4);
            $subtotal += $lineTotal;
            $taxTotal += $this->resolveItemTaxAmount($row['metadata'] ?? null, $lineTotal);
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $grandTotal = round($subtotal + $taxTotal, 2);

        $update = $this->db->prepare("
            UPDATE \"PurchaseInvoice\"
            SET subtotal = :subtotal,
                tax_total = :tax_total,
                total = :total,
                updated_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
        ");
        $update->execute([
            'id' => $invoiceId,
            'tenant_id' => $this->getTenantId(),
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $grandTotal,
        ]);

        return $this->getHeaderById($invoiceId);
    }

    private function normalizeItemMetadata(array $metadata, float $lineTotal): array {
        $taxRate = isset($metadata['tax_rate']) && is_numeric($metadata['tax_rate'])
            ? max(0, round((float)$metadata['tax_rate'], 2))
            : 0.0;
        $taxExempt = $this->normalizeBooleanValue($metadata['tax_exempt'] ?? false);

        if ($taxExempt || $taxRate <= 0) {
            $taxRate = 0.0;
            $taxExempt = true;
        }

        $metadata['tax_rate'] = $taxRate;
        $metadata['tax_exempt'] = $taxExempt;
        $metadata['tax_amount'] = $taxExempt ? 0.0 : round($lineTotal * ($taxRate / 100), 2);

        return $metadata;
    }

    private function resolveItemTaxAmount($metadata, float $lineTotal): float {
        $decoded = $this->decodeJsonField($metadata);
        $taxRate = isset($decoded['tax_rate']) && is_numeric($decoded['tax_rate'])
            ? max(0, (float)$decoded['tax_rate'])
            : 0.0;
        $taxExempt = $this->normalizeBooleanValue($decoded['tax_exempt'] ?? false);
        if ($taxExempt || $taxRate <= 0) {
            return 0.0;
        }

        if (isset($decoded['tax_amount']) && is_numeric($decoded['tax_amount'])) {
            return round((float)$decoded['tax_amount'], 2);
        }

        return round($lineTotal * ($taxRate / 100), 2);
    }

    private function getHeaderById(string $id): array {
        $stmt = $this->db->prepare("
            SELECT
                id,
                supplier_name,
                supplier_document,
                invoice_number,
                external_key,
                issued_at,
                subtotal,
                tax_total,
                total,
                notes,
                metadata,
                created_at,
                updated_at
            FROM \"PurchaseInvoice\"
            WHERE id = :id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('No se pudo recuperar la factura de compra registrada.');
        }
        $row['metadata'] = $this->decodeJsonField($row['metadata'] ?? null);
        return $row;
    }

    private function buildExternalKey(string $invoiceNumber, string $supplierIdentity): string {
        return $this->normalizeKeyPart($invoiceNumber) . '|' . $this->normalizeKeyPart($supplierIdentity);
    }

    private function normalizeKeyPart(string $value): string {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/', '', $value);
        return $value !== '' ? $value : 'NA';
    }

    private function encodeJsonField(?array $value): ?string {
        return is_array($value) && count($value) > 0 ? json_encode($value) : null;
    }

    private function decodeJsonField($value): array {
        if (!$value) {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeBooleanValue($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'si', 'sí'], true);
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}
