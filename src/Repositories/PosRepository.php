<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\Commerce\Domain\CommerceDomain;

class PosRepository {
    private $db;
    private static $schemaEnsured = false;
    private $movementTypes = ['income', 'expense', 'withdrawal', 'deposit', 'adjustment'];

    public function __construct() {
        $this->db = Database::getModuleInstance(CommerceDomain::KEY);
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        $this->assertRequiredTables(['PosShift', 'PosMovement']);
        self::$schemaEnsured = true;
    }

    private function assertRequiredTables(array $tables): void {
        $stmt = $this->db->prepare('SELECT to_regclass(:table_name)');
        foreach ($tables as $table) {
            $stmt->execute(['table_name' => 'public."' . $table . '"']);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('Schema POS no inicializado. Ejecuta el bootstrap o migraciones de base de datos antes de usar el módulo POS.');
            }
        }
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function decodeJsonField($value): array {
        if (!$value) return [];
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeDocumentKey(string $value): string {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value)));
    }

    private function splitName(string $name): array {
        $clean = trim($name);
        if ($clean === '') {
            return ['first' => '', 'last' => ''];
        }
        $parts = preg_split('/\s+/', $clean) ?: [];
        $first = trim((string)($parts[0] ?? ''));
        $last = trim(implode(' ', array_slice($parts, 1)));
        return ['first' => $first, 'last' => $last];
    }

    private function normalizeAddressField($value): ?string {
        $normalized = trim((string)($value ?? ''));
        return $normalized !== '' ? $normalized : null;
    }

    private function sanitizeAddressArray(array $source): array {
        return [
            'street' => $this->normalizeAddressField($source['street'] ?? null),
            'city' => $this->normalizeAddressField($source['city'] ?? null),
            'state' => $this->normalizeAddressField($source['state'] ?? null),
            'country' => $this->normalizeAddressField($source['country'] ?? null),
            'zip' => $this->normalizeAddressField($source['zip'] ?? null),
        ];
    }

    private function sanitizeCustomerEmail(?string $email): ?string {
        $normalized = trim((string)$email);
        if ($normalized === '' || str_ends_with(strtolower($normalized), '@local-pos.invalid')) {
            return null;
        }
        return $normalized;
    }

    private function getAddressFromSavedUserAddresses($rawAddresses): array {
        $addresses = $this->decodeJsonField($rawAddresses);
        if (!is_array($addresses) || count($addresses) === 0) {
            return [];
        }

        foreach ($addresses as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $billing = isset($entry['billing']) && is_array($entry['billing'])
                ? $this->sanitizeAddressArray($entry['billing'])
                : [];
            if (!empty($billing['street'])) {
                return $billing;
            }

            $shipping = isset($entry['shipping']) && is_array($entry['shipping'])
                ? $this->sanitizeAddressArray($entry['shipping'])
                : [];
            if (!empty($shipping['street'])) {
                return $shipping;
            }
        }

        return [];
    }

    private function resolveCustomerAddress(array $billingAddress, array $shippingAddress, array $profile, $rawUserAddresses): ?array {
        $billing = $this->sanitizeAddressArray($billingAddress);
        $shipping = $this->sanitizeAddressArray($shippingAddress);
        $saved = $this->getAddressFromSavedUserAddresses($rawUserAddresses);

        $profileAddressSource = [];
        if (isset($profile['address']) && is_array($profile['address'])) {
            $profileAddressSource = $profile['address'];
        }
        $profileAddressSource = array_merge($profileAddressSource, [
            'street' => $profile['street'] ?? ($profileAddressSource['street'] ?? null),
            'city' => $profile['city'] ?? ($profileAddressSource['city'] ?? null),
            'state' => $profile['state'] ?? ($profileAddressSource['state'] ?? null),
            'country' => $profile['country'] ?? ($profileAddressSource['country'] ?? null),
            'zip' => $profile['zip'] ?? ($profileAddressSource['zip'] ?? null),
        ]);
        $profileAddress = $this->sanitizeAddressArray($profileAddressSource);

        $keys = ['street', 'city', 'state', 'country', 'zip'];
        $resolved = [];
        foreach ($keys as $key) {
            $resolved[$key] =
                $billing[$key] ??
                $shipping[$key] ??
                $profileAddress[$key] ??
                ($saved[$key] ?? null);
        }

        return !empty($resolved['street']) ? $resolved : null;
    }

    private function normalizeShiftRow(array $row): array {
        return [
            'id' => (string)$row['id'],
            'tenant_id' => (string)$row['tenant_id'],
            'opened_by_user_id' => (string)$row['opened_by_user_id'],
            'opened_at' => $row['opened_at'] ?? null,
            'opening_cash' => round((float)($row['opening_cash'] ?? 0), 2),
            'status' => strtolower((string)($row['status'] ?? 'open')),
            'open_notes' => $row['open_notes'] ?? null,
            'closed_by_user_id' => $row['closed_by_user_id'] ?? null,
            'closed_at' => $row['closed_at'] ?? null,
            'closing_cash' => isset($row['closing_cash']) ? round((float)$row['closing_cash'], 2) : null,
            'close_notes' => $row['close_notes'] ?? null,
            'expected_cash' => isset($row['expected_cash']) ? round((float)$row['expected_cash'], 2) : null,
            'difference_cash' => isset($row['difference_cash']) ? round((float)$row['difference_cash'], 2) : null,
            'summary' => $this->decodeJsonField($row['summary_json'] ?? null)
        ];
    }

    private function normalizeMovementRow(array $row): array {
        return [
            'id' => (int)($row['id'] ?? 0),
            'shift_id' => (string)($row['shift_id'] ?? ''),
            'type' => strtolower((string)($row['type'] ?? 'income')),
            'amount' => round((float)($row['amount'] ?? 0), 2),
            'description' => $row['description'] ?? null,
            'business_expense_id' => $row['business_expense_id'] ?? null,
            'created_by_user_id' => (string)($row['created_by_user_id'] ?? ''),
            'created_at' => $row['created_at'] ?? null
        ];
    }

    private function getLocalPosOrders(string $openedAt, ?string $closedAt = null, ?string $shiftId = null): array {
        $sql = '
            SELECT id, total, payment_method, payment_details, order_notes, created_at, status
            FROM "Order"
            WHERE tenant_id = :tenant_id
              AND created_at >= :opened_at
              AND LOWER(COALESCE(status, \'pending\')) NOT IN (\'canceled\', \'cancelled\')
        ';
        $params = [
            'tenant_id' => $this->getTenantId(),
            'opened_at' => $openedAt
        ];
        if ($closedAt) {
            $sql .= ' AND created_at <= :closed_at ';
            $params['closed_at'] = $closedAt;
        }
        $sql .= ' ORDER BY created_at ASC ';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $orders = [];
        foreach ($rows as $row) {
            $details = $this->decodeJsonField($row['payment_details'] ?? null);
            $orderShiftId = trim((string)($details['shift_id'] ?? ''));
            if ($shiftId !== null && trim($shiftId) !== '' && $orderShiftId !== '' && $orderShiftId !== trim($shiftId)) {
                continue;
            }
            $channel = strtolower(trim((string)($details['channel'] ?? '')));
            $notes = strtolower((string)($row['order_notes'] ?? ''));
            $isLocalPos = $channel === 'local_pos' || strpos($notes, 'venta en local') !== false;
            if (!$isLocalPos) {
                continue;
            }
            $orders[] = [
                'id' => (string)$row['id'],
                'total' => round((float)($row['total'] ?? 0), 2),
                'payment_method' => strtolower(trim((string)($row['payment_method'] ?? ''))),
                'payment_details' => $details,
                'created_at' => $row['created_at'] ?? null
            ];
        }
        return $orders;
    }

    private function getCashAndElectronicFromOrder(array $order): array {
        $total = round((float)($order['total'] ?? 0), 2);
        $method = strtolower((string)($order['payment_method'] ?? ''));
        $details = $order['payment_details'] ?? [];

        if ($method === 'cash') {
            $cashReceived = round((float)($details['cash_received'] ?? 0), 2);
            $changeDue = round((float)($details['change_due'] ?? 0), 2);
            $paidAmount = round((float)($details['paid_amount'] ?? 0), 2);
            $cash = $cashReceived > 0 ? max(0, $cashReceived - $changeDue) : 0;
            if ($cash <= 0 && $paidAmount > 0) {
                $cash = min($total, $paidAmount);
            }
            if ($cash <= 0) {
                $cash = $total;
            }
            return ['cash' => round($cash, 2), 'electronic' => 0];
        }

        if ($method === 'mixed') {
            $cashReceived = round((float)($details['cash_received'] ?? 0), 2);
            $changeDue = round((float)($details['change_due'] ?? 0), 2);
            $cash = max(0, $cashReceived - $changeDue);
            $electronic = round((float)($details['electronic_amount'] ?? 0), 2);
            if (($cash + $electronic) <= 0) {
                $paidAmount = round((float)($details['paid_amount'] ?? 0), 2);
                if ($paidAmount > 0) {
                    $cash = min($paidAmount, $total);
                    $electronic = max(0, $paidAmount - $cash);
                }
            }
            $sum = $cash + $electronic;
            if ($sum <= 0) {
                $electronic = $total;
                $cash = 0;
            } elseif ($sum > $total) {
                $factor = $total / $sum;
                $cash = round($cash * $factor, 2);
                $electronic = round(max(0, $total - $cash), 2);
            } elseif ($sum < $total) {
                $electronic = round($electronic + ($total - $sum), 2);
            }
            return ['cash' => round($cash, 2), 'electronic' => round($electronic, 2)];
        }

        // Tarjeta, transferencia y otros no ingresan efectivo físico a caja.
        return ['cash' => 0, 'electronic' => $total];
    }

    public function listMovements(string $shiftId, int $limit = 100): array {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare('
            SELECT id, shift_id, type, amount, description, business_expense_id, created_by_user_id, created_at
            FROM "PosMovement"
            WHERE tenant_id = :tenant_id
              AND shift_id = :shift_id
            ORDER BY created_at DESC, id DESC
            LIMIT ' . $limit . '
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'shift_id' => $shiftId
        ]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'normalizeMovementRow'], $rows);
    }

    /** @return array{income:float,expense:float,adjustments:float} */
    private function movementTotals(string $shiftId): array {
        $stmt = $this->db->prepare('
            SELECT
                COALESCE(SUM(amount) FILTER (WHERE LOWER(type) IN (\'income\', \'deposit\')), 0) AS income,
                COALESCE(SUM(amount) FILTER (WHERE LOWER(type) IN (\'expense\', \'withdrawal\')), 0) AS expense,
                COALESCE(SUM(amount) FILTER (WHERE LOWER(type) = \'adjustment\'), 0) AS adjustments
            FROM "PosMovement"
            WHERE tenant_id = :tenant_id
              AND shift_id = :shift_id
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'shift_id' => $shiftId,
        ]);
        $row = $stmt->fetch() ?: [];
        return [
            'income' => round((float)($row['income'] ?? 0), 2),
            'expense' => round((float)($row['expense'] ?? 0), 2),
            'adjustments' => round((float)($row['adjustments'] ?? 0), 2),
        ];
    }

    public function buildShiftSummary(array $shift): array {
        $openedAt = (string)($shift['opened_at'] ?? '');
        $closedAt = $shift['closed_at'] ?? null;
        $openingCash = round((float)($shift['opening_cash'] ?? 0), 2);
        $orders = $this->getLocalPosOrders($openedAt, $closedAt, (string)($shift['id'] ?? ''));
        $movementTotals = $this->movementTotals((string)$shift['id']);

        $ordersCount = 0;
        $salesTotal = 0;
        $cashSales = 0;
        $electronicSales = 0;
        $paymentTotals = [
            'cash' => 0,
            'card' => 0,
            'transfer' => 0,
            'mixed' => 0,
            'other' => 0
        ];

        foreach ($orders as $order) {
            $ordersCount++;
            $total = round((float)($order['total'] ?? 0), 2);
            $salesTotal += $total;

            $method = strtolower((string)($order['payment_method'] ?? ''));
            if (!array_key_exists($method, $paymentTotals)) {
                $method = 'other';
            }
            $paymentTotals[$method] += $total;

            $split = $this->getCashAndElectronicFromOrder($order);
            $cashSales += round((float)($split['cash'] ?? 0), 2);
            $electronicSales += round((float)($split['electronic'] ?? 0), 2);
        }

        $movementIncome = $movementTotals['income'];
        $movementExpense = $movementTotals['expense'];
        $movementAdjustments = $movementTotals['adjustments'];

        $expectedCash = $openingCash + $cashSales + $movementIncome + $movementAdjustments - $movementExpense;
        $closingCash = isset($shift['closing_cash']) ? round((float)$shift['closing_cash'], 2) : null;
        $differenceCash = $closingCash === null ? null : round($closingCash - $expectedCash, 2);

        return [
            'orders_count' => (int)$ordersCount,
            'sales_total' => round($salesTotal, 2),
            'cash_sales' => round($cashSales, 2),
            'electronic_sales' => round($electronicSales, 2),
            'sales_by_payment' => [
                'cash' => round($paymentTotals['cash'], 2),
                'card' => round($paymentTotals['card'], 2),
                'transfer' => round($paymentTotals['transfer'], 2),
                'mixed' => round($paymentTotals['mixed'], 2),
                'other' => round($paymentTotals['other'], 2)
            ],
            'movement_income' => round($movementIncome, 2),
            'movement_expense' => round($movementExpense, 2),
            'movement_adjustments' => round($movementAdjustments, 2),
            'expected_cash' => round($expectedCash, 2),
            'closing_cash' => $closingCash,
            'difference_cash' => $differenceCash,
            'period' => [
                'start' => $openedAt,
                'end' => $closedAt
            ]
        ];
    }

    public function getActiveShift(): ?array {
        $stmt = $this->db->prepare('
            SELECT *
            FROM "PosShift"
            WHERE tenant_id = :tenant_id
              AND status = \'open\'
            ORDER BY opened_at DESC
            LIMIT 1
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $shift = $this->normalizeShiftRow($row);
        $shift['summary'] = $this->buildShiftSummary($shift);
        return $shift;
    }

    public function getById(string $id): ?array {
        $stmt = $this->db->prepare('
            SELECT *
            FROM "PosShift"
            WHERE tenant_id = :tenant_id
              AND id = :id
            LIMIT 1
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'id' => $id
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $shift = $this->normalizeShiftRow($row);
        if (($shift['status'] ?? 'open') === 'open') {
            $shift['summary'] = $this->buildShiftSummary($shift);
        } elseif (empty($shift['summary'])) {
            $shift['summary'] = $this->buildShiftSummary($shift);
        }
        return $shift;
    }

    public function listShifts(int $limit = 20): array {
        $limit = max(1, min($limit, 100));
        $stmt = $this->db->prepare('
            SELECT *
            FROM "PosShift"
            WHERE tenant_id = :tenant_id
            ORDER BY opened_at DESC
            LIMIT ' . $limit
        );
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $rows = $stmt->fetchAll();
        $list = [];
        foreach ($rows as $row) {
            $shift = $this->normalizeShiftRow($row);
            if (($shift['status'] ?? '') === 'open') {
                $shift['summary'] = $this->buildShiftSummary($shift);
            }
            $list[] = $shift;
        }
        return $list;
    }

    public function openShift(float $openingCash, string $notes, string $userId): array {
        (new FinancialPeriodRepository($this->db))->assertDateOpen(date('Y-m-d'), 'caja POS');
        $active = $this->getActiveShift();
        if ($active) {
            throw new \Exception('Ya existe un turno de caja abierto.');
        }

        $id = 'SHIFT-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $this->db->prepare('
            INSERT INTO "PosShift" (
                id, tenant_id, opened_by_user_id, opened_at, opening_cash, status, open_notes
            ) VALUES (
                :id, :tenant_id, :opened_by_user_id, NOW(), :opening_cash, \'open\', :open_notes
            )
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'opened_by_user_id' => $userId,
            'opening_cash' => round($openingCash, 2),
            'open_notes' => $notes !== '' ? $notes : null
        ]);

        return $this->getById($id) ?? [];
    }

    public function closeActiveShift(float $closingCash, string $notes, string $userId): array {
        (new FinancialPeriodRepository($this->db))->assertDateOpen(date('Y-m-d'), 'caja POS');
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('
                SELECT *
                FROM "PosShift"
                WHERE tenant_id = :tenant_id
                  AND status = \'open\'
                ORDER BY opened_at DESC
                LIMIT 1
                FOR UPDATE
            ');
            $stmt->execute(['tenant_id' => $this->getTenantId()]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new \Exception('No hay un turno de caja abierto para cerrar.');
            }

            $shift = $this->normalizeShiftRow($row);
            $shift['closing_cash'] = round($closingCash, 2);
            $summary = $this->buildShiftSummary($shift);
            $expectedCash = round((float)($summary['expected_cash'] ?? 0), 2);
            $differenceCash = round($closingCash - $expectedCash, 2);

            $update = $this->db->prepare('
                UPDATE "PosShift"
                SET status = \'closed\',
                    closed_by_user_id = :closed_by_user_id,
                    closed_at = NOW(),
                    closing_cash = :closing_cash,
                    close_notes = :close_notes,
                    expected_cash = :expected_cash,
                    difference_cash = :difference_cash,
                    summary_json = :summary_json
                WHERE tenant_id = :tenant_id
                  AND id = :id
            ');
            $update->execute([
                'closed_by_user_id' => $userId,
                'closing_cash' => round($closingCash, 2),
                'close_notes' => $notes !== '' ? $notes : null,
                'expected_cash' => $expectedCash,
                'difference_cash' => $differenceCash,
                'summary_json' => json_encode($summary),
                'tenant_id' => $this->getTenantId(),
                'id' => $shift['id']
            ]);

            $this->db->commit();
            return $this->getById((string)$shift['id']) ?? [];
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function addMovement(string $type, float $amount, string $description, string $userId, ?string $businessExpenseId = null): array {
        (new FinancialPeriodRepository($this->db))->assertDateOpen(date('Y-m-d'), 'movimiento de caja');
        $type = strtolower(trim($type));
        if (!in_array($type, $this->movementTypes, true)) {
            throw new \Exception('Tipo de movimiento inválido.');
        }
        if ($type !== 'adjustment' && $amount <= 0) {
            throw new \Exception('El monto debe ser mayor a cero.');
        }
        if ($type === 'adjustment' && abs($amount) < 0.01) {
            throw new \Exception('El ajuste no puede ser cero.');
        }

        $active = $this->getActiveShift();
        if (!$active) {
            throw new \Exception('Debes abrir la caja antes de registrar movimientos.');
        }

        $stmt = $this->db->prepare('
            INSERT INTO "PosMovement" (
                tenant_id, shift_id, type, amount, description, business_expense_id, created_by_user_id, created_at
            ) VALUES (
                :tenant_id, :shift_id, :type, :amount, :description, :business_expense_id, :created_by_user_id, NOW()
            )
            RETURNING id, shift_id, type, amount, description, business_expense_id, created_by_user_id, created_at
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'shift_id' => $active['id'],
            'type' => $type,
            'amount' => round($amount, 2),
            'description' => $description !== '' ? $description : null,
            'business_expense_id' => $businessExpenseId,
            'created_by_user_id' => $userId
        ]);
        $row = $stmt->fetch();
        return $row ? $this->normalizeMovementRow($row) : [];
    }

    public function findCustomerByDocument(string $document): ?array {
        $documentKey = $this->normalizeDocumentKey($document);
        if ($documentKey === '' || strlen($documentKey) < 6) {
            return null;
        }

        $params = [
            'tenant_id' => $this->getTenantId(),
            'document_key' => $documentKey
        ];

        $stmtOrder = $this->db->prepare('
            SELECT
                o.id AS order_id,
                o.created_at AS order_created_at,
                o.user_id,
                u.name AS user_name,
                u.email AS user_email,
                u.document_type AS user_document_type,
                u.document_number AS user_document_number,
                u.profile AS user_profile,
                u.addresses AS user_addresses,
                o.billing_address,
                o.shipping_address,
                COALESCE(NULLIF(o.billing_address->>\'firstName\', \'\'), o.shipping_address->>\'firstName\', \'\') AS first_name,
                COALESCE(NULLIF(o.billing_address->>\'lastName\', \'\'), o.shipping_address->>\'lastName\', \'\') AS last_name,
                COALESCE(NULLIF(o.billing_address->>\'phone\', \'\'), o.shipping_address->>\'phone\', \'\') AS phone,
                COALESCE(NULLIF(o.billing_address->>\'email\', \'\'), o.shipping_address->>\'email\', \'\') AS email,
                COALESCE(NULLIF(o.billing_address->>\'documentType\', \'\'), o.shipping_address->>\'documentType\', \'\') AS document_type,
                COALESCE(NULLIF(o.billing_address->>\'documentNumber\', \'\'), o.shipping_address->>\'documentNumber\', \'\') AS document_number
            FROM "Order" o
            LEFT JOIN "Customer" u ON u.id = o.user_id AND u.tenant_id = o.tenant_id
            WHERE o.tenant_id = :tenant_id
              AND (
                regexp_replace(upper(COALESCE(o.billing_address->>\'documentNumber\', \'\')), \'[^A-Z0-9]\', \'\', \'g\') = :document_key
                OR regexp_replace(upper(COALESCE(o.shipping_address->>\'documentNumber\', \'\')), \'[^A-Z0-9]\', \'\', \'g\') = :document_key
              )
            ORDER BY o.created_at DESC
            LIMIT 1
        ');
        $stmtOrder->execute($params);
        $row = $stmtOrder->fetch();

        if ($row) {
            $profile = $this->decodeJsonField($row['user_profile'] ?? null);
            $nameCandidate = trim((string)($row['user_name'] ?? ''));
            if ($nameCandidate === '') {
                $nameCandidate = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            }
            $nameParts = $this->splitName($nameCandidate);
            $firstName = trim((string)($row['first_name'] ?? '')) ?: $nameParts['first'];
            $lastName = trim((string)($row['last_name'] ?? '')) ?: $nameParts['last'];

            $documentType = trim((string)($row['document_type'] ?? ''));
            $documentNumber = trim((string)($row['document_number'] ?? ''));
            if ($documentType === '' && !empty($row['user_document_type'])) {
                $documentType = trim((string)$row['user_document_type']);
            }
            if ($documentNumber === '' && !empty($row['user_document_number'])) {
                $documentNumber = trim((string)$row['user_document_number']);
            }

            $phone = trim((string)($row['phone'] ?? ''));
            if ($phone === '' && !empty($profile['phone'])) {
                $phone = trim((string)$profile['phone']);
            }
            $email = trim((string)($row['email'] ?? ''));
            if ($email === '' && !empty($row['user_email'])) {
                $email = trim((string)$row['user_email']);
            }
            $billingAddress = $this->decodeJsonField($row['billing_address'] ?? null);
            $shippingAddress = $this->decodeJsonField($row['shipping_address'] ?? null);
            $customerAddress = $this->resolveCustomerAddress($billingAddress, $shippingAddress, $profile, $row['user_addresses'] ?? null);

            $stmtCount = $this->db->prepare('
                SELECT COUNT(*) AS count
                FROM "Order" o
                WHERE o.tenant_id = :tenant_id
                  AND (
                    regexp_replace(upper(COALESCE(o.billing_address->>\'documentNumber\', \'\')), \'[^A-Z0-9]\', \'\', \'g\') = :document_key
                    OR regexp_replace(upper(COALESCE(o.shipping_address->>\'documentNumber\', \'\')), \'[^A-Z0-9]\', \'\', \'g\') = :document_key
                  )
            ');
            $stmtCount->execute($params);
            $countRow = $stmtCount->fetch();
            $ordersCount = (int)($countRow['count'] ?? 1);

            return [
                'found' => true,
                'customer' => [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'name' => trim($firstName . ' ' . $lastName),
                    'phone' => $phone !== '' ? $phone : null,
                    'email' => $this->sanitizeCustomerEmail($email),
                    'address' => $customerAddress,
                    'documentType' => $documentType !== '' ? strtolower($documentType) : null,
                    'documentNumber' => $documentNumber !== '' ? $documentNumber : $document,
                    'source' => [
                        'type' => 'order',
                        'order_id' => (string)($row['order_id'] ?? ''),
                        'last_order_at' => $row['order_created_at'] ?? null,
                        'orders_count' => $ordersCount
                    ]
                ]
            ];
        }

        $stmtUser = $this->db->prepare('
            SELECT id, name, email, profile, addresses, document_type, document_number
            FROM "Customer"
            WHERE tenant_id = :tenant_id
              AND regexp_replace(upper(COALESCE(document_number, \'\')), \'[^A-Z0-9]\', \'\', \'g\') = :document_key
            ORDER BY created_at DESC
            LIMIT 1
        ');
        $stmtUser->execute($params);
        $userRow = $stmtUser->fetch();
        if (!$userRow) {
            return null;
        }

        $profile = $this->decodeJsonField($userRow['profile'] ?? null);
        $nameParts = $this->splitName((string)($userRow['name'] ?? ''));
        $firstName = trim((string)($profile['firstName'] ?? '')) ?: $nameParts['first'];
        $lastName = trim((string)($profile['lastName'] ?? '')) ?: $nameParts['last'];
        $customerAddress = $this->resolveCustomerAddress([], [], $profile, $userRow['addresses'] ?? null);

        return [
            'found' => true,
            'customer' => [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'name' => trim($firstName . ' ' . $lastName),
                'phone' => !empty($profile['phone']) ? trim((string)$profile['phone']) : null,
                'email' => $this->sanitizeCustomerEmail(!empty($userRow['email']) ? trim((string)$userRow['email']) : null),
                'address' => $customerAddress,
                'documentType' => !empty($userRow['document_type']) ? strtolower(trim((string)$userRow['document_type'])) : null,
                'documentNumber' => !empty($userRow['document_number']) ? trim((string)$userRow['document_number']) : $document,
                'source' => [
                    'type' => 'user_profile',
                    'user_id' => (string)($userRow['id'] ?? '')
                ]
            ]
        ];
    }
}
