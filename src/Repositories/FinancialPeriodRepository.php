<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Exceptions\FinancialPeriodClosedException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

class FinancialPeriodRepository {
    private PDO $db;
    private static bool $schemaEnsured = false;
    private DateTimeZone $timezone;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getInstance();
        $this->timezone = new DateTimeZone('America/Guayaquil');
        $this->ensureSchema();
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function newId(string $prefix): string {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    private function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        $this->assertRequiredTables(['FinancialPeriod', 'FinancialAdjustment']);
        self::$schemaEnsured = true;
    }

    private function assertRequiredTables(array $tables): void {
        $stmt = $this->db->prepare('SELECT to_regclass(:table_name)');
        foreach ($tables as $table) {
            $stmt->execute(['table_name' => 'public."' . $table . '"']);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('Schema financiero no inicializado. Ejecuta el bootstrap o migraciones de base de datos antes de usar el módulo financiero.');
            }
        }
    }

    private function cleanDate(?string $date = null): string {
        $raw = trim((string)$date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return (new DateTimeImmutable('today', $this->timezone))->format('Y-m-d');
    }

    public function periodForDate(?string $date = null): array {
        $dt = new DateTimeImmutable($this->cleanDate($date), $this->timezone);
        $start = $dt->modify('first day of this month');
        $end = $dt->modify('last day of this month');
        return [
            'period_key' => $dt->format('Y-m'),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ];
    }

    public function normalizePeriodKey(string $periodKey): array {
        $value = trim($periodKey);
        if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException('Período financiero inválido.');
        }
        return $this->periodForDate($value . '-01');
    }

    private function normalizePeriodRow(array $row, ?array $fallback = null): array {
        $period = $fallback ?: [
            'period_key' => (string)($row['period_key'] ?? ''),
            'start_date' => (string)($row['start_date'] ?? ''),
            'end_date' => (string)($row['end_date'] ?? ''),
        ];
        $snapshot = $row['snapshot_json'] ?? null;
        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);
            $snapshot = is_array($decoded) ? $decoded : null;
        }
        return [
            'id' => $row['id'] ?? null,
            'tenant_id' => $row['tenant_id'] ?? $this->getTenantId(),
            'period_key' => $period['period_key'],
            'start_date' => $period['start_date'],
            'end_date' => $period['end_date'],
            'status' => strtolower((string)($row['status'] ?? 'open')),
            'snapshot' => $snapshot,
            'closed_by_user_id' => $row['closed_by_user_id'] ?? null,
            'closed_at' => $row['closed_at'] ?? null,
            'notes' => $row['notes'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function normalizeAdjustmentRow(array $row): array {
        foreach (['amount', 'tax_amount', 'total'] as $field) {
            $row[$field] = isset($row[$field]) ? round((float)$row[$field], 2) : 0.0;
        }
        return $row;
    }

    public function getByPeriodKey(string $periodKey): ?array {
        $period = $this->normalizePeriodKey($periodKey);
        $stmt = $this->db->prepare('SELECT * FROM "FinancialPeriod" WHERE tenant_id = :tenant_id AND period_key = :period_key LIMIT 1');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'period_key' => $period['period_key'],
        ]);
        $row = $stmt->fetch();
        return $row ? $this->normalizePeriodRow($row, $period) : $this->normalizePeriodRow(['status' => 'open'], $period);
    }

    public function isDateClosed(?string $date): bool {
        $period = $this->periodForDate($date);
        $stmt = $this->db->prepare('SELECT status FROM "FinancialPeriod" WHERE tenant_id = :tenant_id AND period_key = :period_key LIMIT 1');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'period_key' => $period['period_key'],
        ]);
        $row = $stmt->fetch();
        return strtolower((string)($row['status'] ?? 'open')) === 'closed';
    }

    public function assertDateOpen(?string $date, string $target = 'registro'): void {
        $period = $this->periodForDate($date);
        if ($this->isDateClosed($date)) {
            throw new FinancialPeriodClosedException(
                $period['period_key'],
                'No se puede modificar este ' . $target . ' porque el período financiero ' . $period['period_key'] . ' ya está cerrado. Crea un ajuste en el período actual.'
            );
        }
    }

    public function listRecent(int $months = 14): array {
        $months = max(1, min($months, 36));
        $current = new DateTimeImmutable('first day of this month', $this->timezone);
        $firstActivity = $this->firstActivityPeriodKey();
        $periods = [];
        $keys = [];
        for ($i = 0; $i < $months; $i++) {
            $date = $current->modify('-' . $i . ' month')->format('Y-m-d');
            $period = $this->periodForDate($date);
            if ($firstActivity !== null && $period['period_key'] < $firstActivity) {
                break;
            }
            $periods[$period['period_key']] = $this->normalizePeriodRow(['status' => 'open'], $period);
            $keys[] = $period['period_key'];
        }

        if (empty($keys)) {
            $period = $this->periodForDate($current->format('Y-m-d'));
            $periods[$period['period_key']] = $this->normalizePeriodRow(['status' => 'open'], $period);
            $keys[] = $period['period_key'];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare('SELECT * FROM "FinancialPeriod" WHERE tenant_id = ? AND period_key IN (' . $placeholders . ')');
        $params = array_merge([$this->getTenantId()], $keys);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $periods[(string)$row['period_key']] = $this->normalizePeriodRow($row);
        }
        return array_values($periods);
    }

    private function firstActivityPeriodKey(): ?string {
        $stmt = $this->db->prepare("
            SELECT MIN(activity_date) AS first_date
            FROM (
                SELECT (created_at AT TIME ZONE 'America/Guayaquil')::date AS activity_date FROM \"Order\" WHERE tenant_id = :tenant_id
                UNION ALL
                SELECT expense_date AS activity_date FROM \"BusinessExpense\" WHERE tenant_id = :tenant_id
                UNION ALL
                SELECT adjustment_date AS activity_date FROM \"FinancialAdjustment\" WHERE tenant_id = :tenant_id
                UNION ALL
                SELECT start_date AS activity_date FROM \"FinancialPeriod\" WHERE tenant_id = :tenant_id
            ) activity
        ");
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $date = $stmt->fetch()['first_date'] ?? null;
        if (!$date) {
            return null;
        }
        return $this->periodForDate((string)$date)['period_key'];
    }

    public function listAdjustments(?string $periodKey = null, int $limit = 100): array {
        $limit = max(1, min($limit, 300));
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->getTenantId()];
        if ($periodKey !== null && trim($periodKey) !== '') {
            $period = $this->normalizePeriodKey($periodKey);
            $where[] = 'period_key = :period_key';
            $params['period_key'] = $period['period_key'];
        }
        $stmt = $this->db->prepare('SELECT * FROM "FinancialAdjustment" WHERE ' . implode(' AND ', $where) . ' ORDER BY adjustment_date DESC, created_at DESC LIMIT ' . $limit);
        $stmt->execute($params);
        return array_map(fn($row) => $this->normalizeAdjustmentRow($row), $stmt->fetchAll() ?: []);
    }

    public function adjustmentSummary(?string $periodKey = null, bool $excludeClosedPeriods = false): array {
        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->getTenantId()];
        if ($periodKey !== null && trim($periodKey) !== '') {
            $period = $this->normalizePeriodKey($periodKey);
            $where[] = 'period_key = :period_key';
            $params['period_key'] = $period['period_key'];
        }
        if ($excludeClosedPeriods) {
            $where[] = "NOT EXISTS (
                SELECT 1
                FROM \"FinancialPeriod\" fp
                WHERE fp.tenant_id = \"FinancialAdjustment\".tenant_id
                  AND fp.period_key = \"FinancialAdjustment\".period_key
                  AND fp.status = 'closed'
            )";
        }
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(total), 0) AS total, COUNT(*) AS count FROM "FinancialAdjustment" WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        return [
            'total' => round((float)($row['total'] ?? 0), 2),
            'count' => (int)($row['count'] ?? 0),
        ];
    }

    private function adjustmentSummaryForDateRange(string $startDate, string $endDate): array {
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1
        ) {
            throw new \InvalidArgumentException('Rango financiero inválido.');
        }

        $stmt = $this->db->prepare('
            SELECT COALESCE(SUM(total), 0) AS total, COUNT(*) AS count
            FROM "FinancialAdjustment"
            WHERE tenant_id = :tenant_id
              AND adjustment_date BETWEEN :start_date AND :end_date
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $row = $stmt->fetch() ?: [];
        return [
            'total' => round((float)($row['total'] ?? 0), 2),
            'count' => (int)($row['count'] ?? 0),
        ];
    }

    public function createAdjustment(array $data, string $userId): array {
        $date = $this->cleanDate($data['adjustment_date'] ?? null);
        $this->assertDateOpen($date, 'ajuste financiero');
        $period = $this->periodForDate($date);
        $amount = round((float)($data['amount'] ?? 0), 2);
        $tax = round((float)($data['tax_amount'] ?? 0), 2);
        $total = array_key_exists('total', $data) ? round((float)$data['total'], 2) : round($amount + $tax, 2);
        if (abs($total) < 0.01) {
            throw new \InvalidArgumentException('El ajuste financiero no puede ser cero.');
        }

        $stmt = $this->db->prepare('
            INSERT INTO "FinancialAdjustment" (id, tenant_id, period_key, adjustment_date, type, target_type, target_id, original_period_key, description, amount, tax_amount, total, reason, created_by_user_id, created_at)
            VALUES (:id, :tenant_id, :period_key, :adjustment_date, :type, :target_type, :target_id, :original_period_key, :description, :amount, :tax_amount, :total, :reason, :created_by_user_id, NOW())
            RETURNING *
        ');
        $stmt->execute([
            'id' => $this->newId('fadj'),
            'tenant_id' => $this->getTenantId(),
            'period_key' => $period['period_key'],
            'adjustment_date' => $date,
            'type' => trim((string)($data['type'] ?? 'manual_adjustment')) ?: 'manual_adjustment',
            'target_type' => trim((string)($data['target_type'] ?? '')) ?: null,
            'target_id' => trim((string)($data['target_id'] ?? '')) ?: null,
            'original_period_key' => trim((string)($data['original_period_key'] ?? '')) ?: null,
            'description' => trim((string)($data['description'] ?? 'Ajuste financiero')) ?: 'Ajuste financiero',
            'amount' => $amount,
            'tax_amount' => $tax,
            'total' => $total,
            'reason' => trim((string)($data['reason'] ?? '')) ?: null,
            'created_by_user_id' => $userId,
        ]);
        return $this->normalizeAdjustmentRow($stmt->fetch() ?: []);
    }

    public function closePeriod(string $periodKey, string $notes, string $userId): array {
        $period = $this->normalizePeriodKey($periodKey);
        $today = (new DateTimeImmutable('today', $this->timezone))->format('Y-m-d');
        if ($period['end_date'] >= $today) {
            throw new \InvalidArgumentException('Solo puedes cerrar meses que ya terminaron.');
        }

        $existing = $this->getByPeriodKey($period['period_key']);
        if (($existing['status'] ?? 'open') === 'closed') {
            throw new \InvalidArgumentException('Este período financiero ya está cerrado.');
        }

        $this->refreshOpenOverdueExpenses();
        $snapshot = $this->buildSnapshot($period['start_date'], $period['end_date'], $period['period_key']);
        $stmt = $this->db->prepare('
            INSERT INTO "FinancialPeriod" (id, tenant_id, period_key, start_date, end_date, status, snapshot_json, closed_by_user_id, closed_at, notes, created_at, updated_at)
            VALUES (:id, :tenant_id, :period_key, :start_date, :end_date, \'closed\', :snapshot_json, :closed_by_user_id, NOW(), :notes, NOW(), NOW())
            ON CONFLICT (tenant_id, period_key)
            DO UPDATE SET status = \'closed\', snapshot_json = EXCLUDED.snapshot_json, closed_by_user_id = EXCLUDED.closed_by_user_id, closed_at = NOW(), notes = EXCLUDED.notes, updated_at = NOW()
            RETURNING *
        ');
        $stmt->execute([
            'id' => $this->newId('fper'),
            'tenant_id' => $this->getTenantId(),
            'period_key' => $period['period_key'],
            'start_date' => $period['start_date'],
            'end_date' => $period['end_date'],
            'snapshot_json' => json_encode($snapshot),
            'closed_by_user_id' => $userId,
            'notes' => $notes !== '' ? $notes : null,
        ]);
        return $this->normalizePeriodRow($stmt->fetch() ?: [], $period);
    }

    public function previewPeriod(string $periodKey): array {
        $period = $this->normalizePeriodKey($periodKey);
        $existing = $this->getByPeriodKey($period['period_key']);
        $this->refreshOpenOverdueExpenses();
        $snapshot = ($existing['status'] ?? 'open') === 'closed' && is_array($existing['snapshot'] ?? null)
            ? $existing['snapshot']
            : $this->buildSnapshot($period['start_date'], $period['end_date'], $period['period_key']);

        return [
            'period' => $existing,
            'snapshot' => $snapshot,
            'adjustments' => $this->listAdjustments($period['period_key'], 50),
        ];
    }

    private function refreshOpenOverdueExpenses(): void {
        $stmt = $this->db->prepare('
            UPDATE "BusinessExpense"
            SET status = \'overdue\', updated_at = NOW()
            WHERE tenant_id = :tenant_id
              AND status = \'pending\'
              AND due_date IS NOT NULL
              AND due_date < CURRENT_DATE
              AND NOT EXISTS (
                  SELECT 1
                  FROM "FinancialPeriod" fp
                  WHERE fp.tenant_id = "BusinessExpense".tenant_id
                    AND fp.period_key = TO_CHAR("BusinessExpense".expense_date, \'YYYY-MM\')
                    AND fp.status = \'closed\'
              )
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
    }

    public function closedSnapshotTotals(): array {
        $stmt = $this->db->prepare('SELECT snapshot_json FROM "FinancialPeriod" WHERE tenant_id = :tenant_id AND status = \'closed\'');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $totals = [
            'sales' => ['orders_count' => 0, 'total' => 0.0, 'net' => 0.0, 'tax' => 0.0, 'shipping' => 0.0],
            'profit' => [
                'cost' => 0.0,
                'paid_expenses' => 0.0,
                'period_expenses' => 0.0,
                'pending_expenses' => 0.0,
                'overdue_expenses' => 0.0,
                'committed_expenses' => 0.0,
                'financial_adjustments' => 0.0,
            ],
            'expenses' => ['paid_count' => 0, 'pending_count' => 0, 'overdue_count' => 0],
        ];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $snapshot = $row['snapshot_json'] ?? null;
            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);
            }
            if (!is_array($snapshot)) {
                continue;
            }
            foreach ($totals['sales'] as $field => $value) {
                $totals['sales'][$field] += $field === 'orders_count'
                    ? (int)($snapshot['sales'][$field] ?? 0)
                    : (float)($snapshot['sales'][$field] ?? 0);
            }
            foreach ($totals['profit'] as $field => $value) {
                $totals['profit'][$field] += (float)($snapshot['profit'][$field] ?? 0);
            }
            foreach ($totals['expenses'] as $field => $value) {
                $totals['expenses'][$field] += (int)($snapshot['expenses'][$field] ?? 0);
            }
        }

        return $totals;
    }

    public function buildSnapshot(string $startDate, string $endDate, string $periodKey): array {
        $netExpr = "COALESCE(o.vat_subtotal, CASE WHEN COALESCE(o.vat_rate, 0) > 0 THEN ((COALESCE(o.total, 0) - COALESCE(o.shipping, 0)) / NULLIF((1 + (COALESCE(o.vat_rate, 0) / 100.0)), 0)) ELSE ((COALESCE(o.total, 0) - COALESCE(o.shipping, 0)) - COALESCE(o.vat_amount, 0)) END)";
        $taxExpr = "COALESCE(o.vat_amount, ((COALESCE(o.total, 0) - COALESCE(o.shipping, 0)) - ($netExpr)))";
        $realized = "LOWER(COALESCE(o.status, 'pending')) IN ('delivered', 'completed')";
        $salesStmt = $this->db->prepare("
            SELECT
                COUNT(*) AS orders_count,
                COALESCE(SUM(o.total), 0) AS total_sales,
                COALESCE(SUM($netExpr), 0) AS net_sales,
                COALESCE(SUM($taxExpr), 0) AS tax_collected,
                COALESCE(SUM(COALESCE(o.shipping_base, o.shipping, 0)), 0) AS shipping_collected
            FROM \"Order\" o
            WHERE o.tenant_id = :tenant_id
              AND $realized
              AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date BETWEEN :start_date AND :end_date
        ");
        $salesStmt->execute(['tenant_id' => $this->getTenantId(), 'start_date' => $startDate, 'end_date' => $endDate]);
        $sales = $salesStmt->fetch() ?: [];

        $lineCostExpr = "CASE
            WHEN COALESCE(oi.cost_total, 0) > 0 THEN oi.cost_total
            WHEN COALESCE(oi.unit_cost, 0) > 0 THEN COALESCE(oi.quantity, 0) * oi.unit_cost
            ELSE COALESCE(oi.quantity, 0) * COALESCE(p.cost, 0)
        END";
        $costStmt = $this->db->prepare("
            SELECT COALESCE(SUM($lineCostExpr), 0) AS cost
            FROM \"OrderItem\" oi
            JOIN \"Order\" o ON oi.order_id = o.id
            LEFT JOIN \"Product\" p ON oi.product_id = p.id AND p.tenant_id = :tenant_id
            WHERE o.tenant_id = :tenant_id
              AND $realized
              AND (o.created_at AT TIME ZONE 'America/Guayaquil')::date BETWEEN :start_date AND :end_date
        ");
        $costStmt->execute(['tenant_id' => $this->getTenantId(), 'start_date' => $startDate, 'end_date' => $endDate]);
        $cost = (float)(($costStmt->fetch() ?: [])['cost'] ?? 0);

        $expenseStmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(total) FILTER (
                    WHERE expense_date BETWEEN :start_date AND :end_date
                ), 0) AS period_expenses,
                COALESCE(SUM(total) FILTER (
                    WHERE status = 'paid'
                      AND COALESCE((paid_at AT TIME ZONE 'America/Guayaquil')::date, expense_date) BETWEEN :start_date AND :end_date
                ), 0) AS paid,
                COALESCE(SUM(total) FILTER (
                    WHERE expense_date BETWEEN :start_date AND :end_date
                      AND NOT (status = 'paid' AND COALESCE((paid_at AT TIME ZONE 'America/Guayaquil')::date, expense_date) <= :end_date)
                      AND (due_date IS NULL OR due_date >= :end_date)
                ), 0) AS pending,
                COALESCE(SUM(total) FILTER (
                    WHERE expense_date BETWEEN :start_date AND :end_date
                      AND NOT (status = 'paid' AND COALESCE((paid_at AT TIME ZONE 'America/Guayaquil')::date, expense_date) <= :end_date)
                      AND due_date IS NOT NULL
                      AND due_date < :end_date
                ), 0) AS overdue,
                COUNT(*) FILTER (
                    WHERE status = 'paid'
                      AND COALESCE((paid_at AT TIME ZONE 'America/Guayaquil')::date, expense_date) BETWEEN :start_date AND :end_date
                ) AS paid_count,
                COUNT(*) FILTER (
                    WHERE expense_date BETWEEN :start_date AND :end_date
                      AND NOT (status = 'paid' AND COALESCE((paid_at AT TIME ZONE 'America/Guayaquil')::date, expense_date) <= :end_date)
                      AND (due_date IS NULL OR due_date >= :end_date)
                ) AS pending_count,
                COUNT(*) FILTER (
                    WHERE expense_date BETWEEN :start_date AND :end_date
                      AND NOT (status = 'paid' AND COALESCE((paid_at AT TIME ZONE 'America/Guayaquil')::date, expense_date) <= :end_date)
                      AND due_date IS NOT NULL
                      AND due_date < :end_date
                ) AS overdue_count
            FROM \"BusinessExpense\"
            WHERE tenant_id = :tenant_id
              AND status <> 'cancelled'
              AND (
                  expense_date BETWEEN :start_date AND :end_date
                  OR COALESCE((paid_at AT TIME ZONE 'America/Guayaquil')::date, expense_date) BETWEEN :start_date AND :end_date
              )
        ");
        $expenseStmt->execute(['tenant_id' => $this->getTenantId(), 'start_date' => $startDate, 'end_date' => $endDate]);
        $expenses = $expenseStmt->fetch() ?: [];

        $adjustments = $this->adjustmentSummaryForDateRange($startDate, $endDate);
        $netSales = (float)($sales['net_sales'] ?? 0);
        $paidExpenses = (float)($expenses['paid'] ?? 0);
        $periodExpenses = (float)($expenses['period_expenses'] ?? 0);
        $pendingExpenses = (float)($expenses['pending'] ?? 0);
        $overdueExpenses = (float)($expenses['overdue'] ?? 0);
        $committedExpenses = $periodExpenses;
        $adjustmentTotal = (float)($adjustments['total'] ?? 0);
        $grossProfit = $netSales - $cost;
        $netCashProfit = $grossProfit - $paidExpenses - $adjustmentTotal;
        $netPeriodProfit = $grossProfit - $periodExpenses - $adjustmentTotal;
        $netCommittedProfit = $netPeriodProfit;

        return [
            'period_key' => $periodKey,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'sales' => [
                'orders_count' => (int)($sales['orders_count'] ?? 0),
                'total' => round((float)($sales['total_sales'] ?? 0), 2),
                'net' => round($netSales, 2),
                'tax' => round((float)($sales['tax_collected'] ?? 0), 2),
                'shipping' => round((float)($sales['shipping_collected'] ?? 0), 2),
            ],
            'profit' => [
                'cost' => round($cost, 2),
                'gross_profit' => round($grossProfit, 2),
                'gross_margin' => $netSales > 0 ? round(($grossProfit / $netSales) * 100, 1) : 0,
                'period_expenses' => round($periodExpenses, 2),
                'paid_expenses' => round($paidExpenses, 2),
                'pending_expenses' => round($pendingExpenses, 2),
                'overdue_expenses' => round($overdueExpenses, 2),
                'committed_expenses' => round($committedExpenses, 2),
                'financial_adjustments' => round($adjustmentTotal, 2),
                'net_cash_profit' => round($netCashProfit, 2),
                'net_cash_margin' => $netSales > 0 ? round(($netCashProfit / $netSales) * 100, 1) : 0,
                'net_period_profit' => round($netPeriodProfit, 2),
                'net_period_margin' => $netSales > 0 ? round(($netPeriodProfit / $netSales) * 100, 1) : 0,
                'net_committed_profit' => round($netCommittedProfit, 2),
                'net_committed_margin' => $netSales > 0 ? round(($netCommittedProfit / $netSales) * 100, 1) : 0,
            ],
            'expenses' => [
                'paid_count' => (int)($expenses['paid_count'] ?? 0),
                'pending_count' => (int)($expenses['pending_count'] ?? 0),
                'overdue_count' => (int)($expenses['overdue_count'] ?? 0),
            ],
            'adjustments' => $adjustments,
            'closed_at' => date(DATE_ATOM),
        ];
    }
}
