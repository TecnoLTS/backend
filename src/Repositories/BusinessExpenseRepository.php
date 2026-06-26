<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Exceptions\FinancialPeriodClosedException;
use App\Modules\ReportingFinance\Domain\ReportingFinanceDomain;
use DateTimeImmutable;
use PDO;

class BusinessExpenseRepository {
    private $db;
    private FinancialPeriodRepository $financialPeriods;
    private static $schemaEnsured = false;
    private array $statuses = ['pending', 'paid', 'overdue', 'cancelled'];
    private array $types = ['one_time', 'recurring_instance'];
    private array $frequencies = ['weekly', 'monthly'];

    public function __construct() {
        $this->db = Database::getModuleInstance(ReportingFinanceDomain::KEY);
        $this->financialPeriods = new FinancialPeriodRepository($this->db);
        $this->ensureSchema();
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    private function newId(string $prefix): string {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    private function ensureSchema(): void {
        if (self::$schemaEnsured) return;

        $this->assertRequiredTables(['BusinessExpenseRecurrence', 'BusinessExpense', 'BusinessExpensePayment']);
        self::$schemaEnsured = true;
    }

    private function assertRequiredTables(array $tables): void {
        $stmt = $this->db->prepare('SELECT to_regclass(:table_name)');
        foreach ($tables as $table) {
            $stmt->execute(['table_name' => 'public."' . $table . '"']);
            if (!$stmt->fetchColumn()) {
                throw new \RuntimeException('Schema de gastos no inicializado. Ejecuta el bootstrap o migraciones de base de datos antes de usar el módulo de gastos.');
            }
        }
    }

    private function money($value): float {
        return round(max(0, (float)$value), 2);
    }

    private function cleanDate($value, ?string $fallback = null): string {
        $raw = trim((string)($value ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
        return $fallback ?: date('Y-m-d');
    }

    private function normalizeFilters(array $filters = []): array {
        $normalized = $filters;
        $period = trim((string)($normalized['period'] ?? ''));
        if ($period !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $normalized['from'] = $period . '-01';
            $normalized['to'] = date('Y-m-t', strtotime($normalized['from']));
        }
        return $normalized;
    }

    private function normalizeStatus(string $status): string {
        $value = strtolower(trim($status));
        return in_array($value, $this->statuses, true) ? $value : 'pending';
    }

    private function asBool($value): bool {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    private function normalizeExpenseRow(array $row): array {
        foreach (['amount', 'tax_amount', 'total'] as $field) {
            $row[$field] = isset($row[$field]) ? (float)$row[$field] : 0.0;
        }
        $row['payment_exists'] = $this->asBool($row['payment_exists'] ?? $row['pdf_exists'] ?? false);
        $period = $this->financialPeriods->periodForDate((string)($row['expense_date'] ?? date('Y-m-d')));
        $row['financial_period_key'] = $row['financial_period_key'] ?? $period['period_key'];
        $row['is_period_closed'] = $this->asBool($row['is_period_closed'] ?? false);
        return $row;
    }

    private function normalizeRecurrenceRow(array $row): array {
        foreach (['amount', 'tax_amount', 'total'] as $field) {
            $row[$field] = isset($row[$field]) ? (float)$row[$field] : 0.0;
        }
        $row['interval_count'] = (int)($row['interval_count'] ?? 1);
        $row['active'] = $this->asBool($row['active'] ?? true);
        return $row;
    }

    private function refreshOverdue(): void {
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

    public function list(array $filters = []): array {
        $filters = $this->normalizeFilters($filters);
        $this->generateDueRecurringInstances();
        $this->refreshOverdue();

        $where = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->getTenantId()];
        foreach (['status', 'category', 'type'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '' && strtolower($value) !== 'all') {
                $where[] = $field . ' = :' . $field;
                $params[$field] = $value;
            }
        }
        if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['from'])) {
            $where[] = 'expense_date >= :from_date';
            $params['from_date'] = $filters['from'];
        }
        if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$filters['to'])) {
            $where[] = 'expense_date <= :to_date';
            $params['to_date'] = $filters['to'];
        }

        $stmt = $this->db->prepare('
            SELECT
                "BusinessExpense".*,
                TO_CHAR("BusinessExpense".expense_date, \'YYYY-MM\') AS financial_period_key,
                EXISTS(
                    SELECT 1
                    FROM "FinancialPeriod" fp
                    WHERE fp.tenant_id = "BusinessExpense".tenant_id
                      AND fp.period_key = TO_CHAR("BusinessExpense".expense_date, \'YYYY-MM\')
                      AND fp.status = \'closed\'
                ) AS is_period_closed,
                EXISTS(SELECT 1 FROM "BusinessExpensePayment" p WHERE p.expense_id = "BusinessExpense".id AND p.tenant_id = "BusinessExpense".tenant_id) AS payment_exists
            FROM "BusinessExpense"
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY COALESCE(due_date, expense_date) DESC, created_at DESC
            LIMIT 300
        ');
        $stmt->execute($params);
        return array_map(fn($row) => $this->normalizeExpenseRow($row), $stmt->fetchAll() ?: []);
    }

    public function categories(): array {
        $stmt = $this->db->prepare('
            SELECT DISTINCT category
            FROM (
                SELECT category FROM "BusinessExpense" WHERE tenant_id = :tenant_id
                UNION ALL
                SELECT category FROM "BusinessExpenseRecurrence" WHERE tenant_id = :tenant_id
            ) source
            WHERE category IS NOT NULL AND TRIM(category) <> \'\'
            ORDER BY category ASC
            LIMIT 100
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return array_values(array_filter(array_map(static fn($row) => (string)($row['category'] ?? ''), $stmt->fetchAll() ?: [])));
    }

    public function create(array $data, string $userId): array {
        $amount = $this->money($data['amount'] ?? 0);
        $tax = $this->money($data['tax_amount'] ?? 0);
        $total = $this->money($data['total'] ?? ($amount + $tax));
        if ($total <= 0) throw new \Exception('El gasto debe ser mayor a cero.');

        $status = $this->normalizeStatus((string)($data['status'] ?? 'pending'));
        $paidAt = $status === 'paid' ? ($data['paid_at'] ?? date(DATE_ATOM)) : null;
        $expenseDate = $this->cleanDate($data['expense_date'] ?? null);
        $dueDate = !empty($data['due_date']) ? $this->cleanDate($data['due_date']) : null;
        $this->financialPeriods->assertDateOpen($expenseDate, 'gasto');

        $stmt = $this->db->prepare('
            INSERT INTO "BusinessExpense" (id, tenant_id, recurrence_id, category, description, amount, tax_amount, total, expense_date, due_date, paid_at, status, type, payment_method, reference, notes, source, source_id, created_by_user_id, created_at, updated_at)
            VALUES (:id, :tenant_id, :recurrence_id, :category, :description, :amount, :tax_amount, :total, :expense_date, :due_date, :paid_at, :status, :type, :payment_method, :reference, :notes, :source, :source_id, :created_by_user_id, NOW(), NOW())
            RETURNING *
        ');
        $stmt->execute([
            'id' => $this->newId('exp'),
            'tenant_id' => $this->getTenantId(),
            'recurrence_id' => $data['recurrence_id'] ?? null,
            'category' => trim((string)($data['category'] ?? 'Otros')) ?: 'Otros',
            'description' => trim((string)($data['description'] ?? 'Gasto operativo')),
            'amount' => $amount,
            'tax_amount' => $tax,
            'total' => $total,
            'expense_date' => $expenseDate,
            'due_date' => $dueDate,
            'paid_at' => $paidAt,
            'status' => $status,
            'type' => in_array(($data['type'] ?? 'one_time'), $this->types, true) ? ($data['type'] ?? 'one_time') : 'one_time',
            'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
            'reference' => trim((string)($data['reference'] ?? '')) ?: null,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,
            'source' => trim((string)($data['source'] ?? '')) ?: null,
            'source_id' => trim((string)($data['source_id'] ?? '')) ?: null,
            'created_by_user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        if ($row && $status === 'paid') {
            $this->recordPayment((string)$row['id'], (float)$row['total'], $row['payment_method'] ?? null, $row['reference'] ?? null, $userId);
        }
        return $row ? $this->normalizeExpenseRow($row) : [];
    }

    public function update(string $id, array $data): array {
        $existing = $this->find($id);
        if (!$existing) throw new \Exception('Gasto no encontrado.');
        $this->financialPeriods->assertDateOpen((string)$existing['expense_date'], 'gasto');

        $newExpenseDate = $this->cleanDate($data['expense_date'] ?? $existing['expense_date']);
        $this->financialPeriods->assertDateOpen($newExpenseDate, 'gasto');

        $amount = $this->money($data['amount'] ?? $existing['amount']);
        $tax = $this->money($data['tax_amount'] ?? $existing['tax_amount']);
        $total = $this->money($data['total'] ?? ($amount + $tax));
        $status = $this->normalizeStatus((string)($data['status'] ?? $existing['status']));

        $stmt = $this->db->prepare('
            UPDATE "BusinessExpense" SET category = :category, description = :description, amount = :amount, tax_amount = :tax_amount, total = :total, expense_date = :expense_date, due_date = :due_date, paid_at = :paid_at, status = :status, payment_method = :payment_method, reference = :reference, notes = :notes, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
            RETURNING *
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'category' => trim((string)($data['category'] ?? $existing['category'])) ?: 'Otros',
            'description' => trim((string)($data['description'] ?? $existing['description'])) ?: 'Gasto operativo',
            'amount' => $amount,
            'tax_amount' => $tax,
            'total' => $total,
            'expense_date' => $newExpenseDate,
            'due_date' => !empty($data['due_date']) ? $this->cleanDate($data['due_date']) : null,
            'paid_at' => $status === 'paid' ? ($data['paid_at'] ?? $existing['paid_at'] ?? date(DATE_ATOM)) : null,
            'status' => $status,
            'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
            'reference' => trim((string)($data['reference'] ?? '')) ?: null,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,
        ]);
        $row = $stmt->fetch() ?: [];
        if ($row && $status === 'paid' && strtolower((string)($existing['status'] ?? '')) !== 'paid') {
            $this->recordPayment((string)$row['id'], (float)$row['total'], $row['payment_method'] ?? null, $row['reference'] ?? null, (string)($row['created_by_user_id'] ?? 'service'));
        }
        return $this->normalizeExpenseRow($row);
    }

    public function updateStatus(string $id, string $status, array $data = [], ?string $userId = null): array {
        $status = $this->normalizeStatus($status);
        $existing = $this->find($id);
        if (!$existing) throw new \Exception('Gasto no encontrado.');
        $this->financialPeriods->assertDateOpen((string)$existing['expense_date'], 'gasto');
        $paidAt = $status === 'paid'
            ? (strtolower((string)($existing['status'] ?? '')) === 'paid' ? ($existing['paid_at'] ?? date(DATE_ATOM)) : ($data['paid_at'] ?? date(DATE_ATOM)))
            : null;
        $stmt = $this->db->prepare('UPDATE "BusinessExpense" SET status = :status, paid_at = :paid_at, payment_method = COALESCE(:payment_method, payment_method), reference = COALESCE(:reference, reference), updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id RETURNING *');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'status' => $status,
            'paid_at' => $paidAt,
            'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
            'reference' => trim((string)($data['reference'] ?? '')) ?: null,
        ]);
        $row = $stmt->fetch();
        if (!$row) throw new \Exception('Gasto no encontrado.');

        if ($status === 'paid' && $userId && strtolower((string)($existing['status'] ?? '')) !== 'paid') {
            $this->recordPayment((string)$row['id'], (float)$row['total'], $row['payment_method'] ?? null, $row['reference'] ?? null, $userId);
        }

        return $this->normalizeExpenseRow($row);
    }

    private function recordPayment(string $expenseId, float $amount, ?string $method, ?string $reference, string $userId): void {
        $existing = $this->db->prepare('SELECT 1 FROM "BusinessExpensePayment" WHERE tenant_id = :tenant_id AND expense_id = :expense_id LIMIT 1');
        $existing->execute([
            'tenant_id' => $this->getTenantId(),
            'expense_id' => $expenseId,
        ]);
        if ($existing->fetch()) {
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO "BusinessExpensePayment" (tenant_id, expense_id, amount, payment_method, reference, created_by_user_id) VALUES (:tenant_id, :expense_id, :amount, :payment_method, :reference, :created_by_user_id)');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'expense_id' => $expenseId,
            'amount' => round($amount, 2),
            'payment_method' => $method,
            'reference' => $reference,
            'created_by_user_id' => $userId,
        ]);
    }

    private function find(string $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM "BusinessExpense" WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute(['id' => $id, 'tenant_id' => $this->getTenantId()]);
        $row = $stmt->fetch();
        return $row ? $this->normalizeExpenseRow($row) : null;
    }

    public function summary(array $options = []): array {
        $options = $this->normalizeFilters($options);
        $this->generateDueRecurringInstances();
        $this->refreshOverdue();
        $where = ['tenant_id = :tenant_id', 'status <> \'cancelled\''];
        $params = ['tenant_id' => $this->getTenantId()];
        foreach (['status', 'category', 'type'] as $field) {
            $value = trim((string)($options[$field] ?? ''));
            if ($value !== '' && strtolower($value) !== 'all') {
                $where[] = $field . ' = :' . $field;
                $params[$field] = $value;
            }
        }
        if (!empty($options['exclude_closed_periods'])) {
            $where[] = 'NOT EXISTS (
                SELECT 1
                FROM "FinancialPeriod" fp
                WHERE fp.tenant_id = "BusinessExpense".tenant_id
                  AND fp.period_key = TO_CHAR("BusinessExpense".expense_date, \'YYYY-MM\')
                  AND fp.status = \'closed\'
            )';
        }
        $fromDate = !empty($options['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['from'])
            ? (string)$options['from']
            : null;
        $toDate = !empty($options['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['to'])
            ? (string)$options['to']
            : null;
        $periodDateFilter = 'TRUE';
        $paidDateFilter = 'TRUE';
        if ($fromDate !== null) {
            $periodDateFilter .= ' AND expense_date >= :from_date';
            $paidDateFilter .= ' AND COALESCE((paid_at AT TIME ZONE \'America/Guayaquil\')::date, expense_date) >= :from_date';
            $params['from_date'] = $fromDate;
        }
        if ($toDate !== null) {
            $periodDateFilter .= ' AND expense_date <= :to_date';
            $paidDateFilter .= ' AND COALESCE((paid_at AT TIME ZONE \'America/Guayaquil\')::date, expense_date) <= :to_date';
            $params['to_date'] = $toDate;
        }
        $stmt = $this->db->prepare('
            SELECT
                SUM(total) FILTER (WHERE status = \'paid\' AND ' . $paidDateFilter . ') AS paid,
                SUM(total) FILTER (WHERE status = \'pending\' AND ' . $periodDateFilter . ') AS pending,
                SUM(total) FILTER (WHERE status = \'overdue\' AND ' . $periodDateFilter . ') AS overdue,
                SUM(total) FILTER (WHERE ' . $periodDateFilter . ') AS period_expenses,
                SUM(total) FILTER (WHERE status IN (\'pending\', \'overdue\') AND ' . $periodDateFilter . ') AS committed,
                COUNT(*) FILTER (WHERE status = \'paid\' AND ' . $paidDateFilter . ') AS paid_count,
                COUNT(*) FILTER (WHERE status = \'pending\' AND ' . $periodDateFilter . ') AS pending_count,
                COUNT(*) FILTER (WHERE status = \'overdue\' AND ' . $periodDateFilter . ') AS overdue_count
            FROM "BusinessExpense"
            WHERE ' . implode(' AND ', $where) . '
        ');
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        $paid = round((float)($row['paid'] ?? 0), 2);
        $pending = round((float)($row['pending'] ?? 0), 2);
        $overdue = round((float)($row['overdue'] ?? 0), 2);
        $committed = round((float)($row['committed'] ?? 0), 2);
        $periodExpenses = round((float)($row['period_expenses'] ?? ($paid + $committed)), 2);
        return [
            'paid' => $paid,
            'pending' => $pending,
            'overdue' => $overdue,
            'committed' => $committed,
            'cash_expenses' => $paid,
            'period_expenses' => $periodExpenses,
            'committed_expenses' => round($paid + $committed, 2),
            'paid_count' => (int)($row['paid_count'] ?? 0),
            'pending_count' => (int)($row['pending_count'] ?? 0),
            'overdue_count' => (int)($row['overdue_count'] ?? 0),
        ];
    }

    public function listRecurrences(): array {
        $this->generateDueRecurringInstances();
        $stmt = $this->db->prepare('SELECT * FROM "BusinessExpenseRecurrence" WHERE tenant_id = :tenant_id ORDER BY active DESC, next_due_date ASC, created_at DESC LIMIT 200');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return array_map(fn($row) => $this->normalizeRecurrenceRow($row), $stmt->fetchAll() ?: []);
    }

    public function createRecurrence(array $data, string $userId): array {
        $amount = $this->money($data['amount'] ?? 0);
        $tax = $this->money($data['tax_amount'] ?? 0);
        $total = $this->money($data['total'] ?? ($amount + $tax));
        if ($total <= 0) throw new \Exception('El gasto recurrente debe ser mayor a cero.');

        $frequency = strtolower(trim((string)($data['frequency'] ?? 'monthly')));
        if (!in_array($frequency, $this->frequencies, true)) $frequency = 'monthly';
        $startDate = $this->cleanDate($data['start_date'] ?? null);
        $nextDue = $this->cleanDate($data['next_due_date'] ?? $startDate, $startDate);
        $this->financialPeriods->assertDateOpen($nextDue, 'gasto recurrente');

        $stmt = $this->db->prepare('
            INSERT INTO "BusinessExpenseRecurrence" (id, tenant_id, category, description, amount, tax_amount, total, frequency, interval_count, start_date, next_due_date, payment_method, reference, notes, active, created_by_user_id, created_at, updated_at)
            VALUES (:id, :tenant_id, :category, :description, :amount, :tax_amount, :total, :frequency, :interval_count, :start_date, :next_due_date, :payment_method, :reference, :notes, TRUE, :created_by_user_id, NOW(), NOW())
            RETURNING *
        ');
        $stmt->execute([
            'id' => $this->newId('expr'),
            'tenant_id' => $this->getTenantId(),
            'category' => trim((string)($data['category'] ?? 'Otros')) ?: 'Otros',
            'description' => trim((string)($data['description'] ?? 'Gasto recurrente')),
            'amount' => $amount,
            'tax_amount' => $tax,
            'total' => $total,
            'frequency' => $frequency,
            'interval_count' => max(1, (int)($data['interval_count'] ?? 1)),
            'start_date' => $startDate,
            'next_due_date' => $nextDue,
            'payment_method' => trim((string)($data['payment_method'] ?? '')) ?: null,
            'reference' => trim((string)($data['reference'] ?? '')) ?: null,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,
            'created_by_user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return [];
        }

        $this->createRecurringInstance($row, $nextDue);
        $nextRecurringDue = $this->nextDueDate($nextDue, $frequency, max(1, (int)($data['interval_count'] ?? 1)));
        $update = $this->db->prepare('UPDATE "BusinessExpenseRecurrence" SET next_due_date = :next_due_date, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id RETURNING *');
        $update->execute([
            'next_due_date' => $nextRecurringDue,
            'id' => $row['id'],
            'tenant_id' => $this->getTenantId(),
        ]);
        $updated = $update->fetch();
        return $this->normalizeRecurrenceRow($updated ?: $row);
    }

    public function updateRecurrence(string $id, array $data): array {
        $stmt = $this->db->prepare('UPDATE "BusinessExpenseRecurrence" SET category = COALESCE(:category, category), description = COALESCE(:description, description), amount = COALESCE(:amount, amount), tax_amount = COALESCE(:tax_amount, tax_amount), total = COALESCE(:total, total), frequency = COALESCE(:frequency, frequency), interval_count = COALESCE(:interval_count, interval_count), next_due_date = COALESCE(:next_due_date, next_due_date), active = COALESCE(:active, active), payment_method = COALESCE(:payment_method, payment_method), reference = COALESCE(:reference, reference), notes = COALESCE(:notes, notes), updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id RETURNING *');
        $frequency = isset($data['frequency']) && in_array($data['frequency'], $this->frequencies, true) ? $data['frequency'] : null;
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'category' => isset($data['category']) ? trim((string)$data['category']) : null,
            'description' => isset($data['description']) ? trim((string)$data['description']) : null,
            'amount' => isset($data['amount']) ? $this->money($data['amount']) : null,
            'tax_amount' => isset($data['tax_amount']) ? $this->money($data['tax_amount']) : null,
            'total' => isset($data['total']) ? $this->money($data['total']) : null,
            'frequency' => $frequency,
            'interval_count' => isset($data['interval_count']) ? max(1, (int)$data['interval_count']) : null,
            'next_due_date' => isset($data['next_due_date']) ? $this->cleanDate($data['next_due_date']) : null,
            'active' => array_key_exists('active', $data) ? (bool)$data['active'] : null,
            'payment_method' => isset($data['payment_method']) ? trim((string)$data['payment_method']) : null,
            'reference' => isset($data['reference']) ? trim((string)$data['reference']) : null,
            'notes' => isset($data['notes']) ? trim((string)$data['notes']) : null,
        ]);
        $row = $stmt->fetch();
        if (!$row) throw new \Exception('Recurrencia no encontrada.');
        return $this->normalizeRecurrenceRow($row);
    }

    public function deleteRecurrence(string $id): array {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->prepare('SELECT * FROM "BusinessExpenseRecurrence" WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        if (!$row) throw new \Exception('Recurrencia no encontrada.');

        $count = $this->db->prepare('SELECT COUNT(*) FROM "BusinessExpense" WHERE tenant_id = :tenant_id AND recurrence_id = :id');
        $count->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $generatedCount = (int)$count->fetchColumn();

        $delete = $this->db->prepare('DELETE FROM "BusinessExpenseRecurrence" WHERE id = :id AND tenant_id = :tenant_id');
        $delete->execute(['id' => $id, 'tenant_id' => $tenantId]);

        return [
            'recurrence' => $this->normalizeRecurrenceRow($row),
            'generated_expenses_count' => $generatedCount,
        ];
    }

    public function generateDueRecurringInstances(): void {
        $stmt = $this->db->prepare('SELECT * FROM "BusinessExpenseRecurrence" WHERE tenant_id = :tenant_id AND active = TRUE AND next_due_date <= CURRENT_DATE ORDER BY next_due_date ASC LIMIT 100');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as $row) {
            $nextDue = (string)$row['next_due_date'];
            $iterations = 0;
            while ($nextDue <= (new DateTimeImmutable('today'))->format('Y-m-d') && $iterations < 24) {
                if (!$this->financialPeriods->isDateClosed($nextDue)) {
                    $this->createRecurringInstance($row, $nextDue);
                }
                $nextDue = $this->nextDueDate($nextDue, (string)$row['frequency'], (int)$row['interval_count']);
                $iterations++;
            }
            $update = $this->db->prepare('UPDATE "BusinessExpenseRecurrence" SET next_due_date = :next_due_date, updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id');
            $update->execute(['next_due_date' => $nextDue, 'id' => $row['id'], 'tenant_id' => $this->getTenantId()]);
        }
    }

    private function createRecurringInstance(array $recurrence, string $dueDate): void {
        $stmt = $this->db->prepare('
            INSERT INTO "BusinessExpense" (id, tenant_id, recurrence_id, category, description, amount, tax_amount, total, expense_date, due_date, status, type, payment_method, reference, notes, created_by_user_id, created_at, updated_at)
            VALUES (:id, :tenant_id, :recurrence_id, :category, :description, :amount, :tax_amount, :total, :expense_date, :due_date, \'pending\', \'recurring_instance\', :payment_method, :reference, :notes, :created_by_user_id, NOW(), NOW())
            ON CONFLICT DO NOTHING
        ');
        $stmt->execute([
            'id' => $this->newId('exp'),
            'tenant_id' => $this->getTenantId(),
            'recurrence_id' => $recurrence['id'],
            'category' => $recurrence['category'],
            'description' => $recurrence['description'],
            'amount' => $recurrence['amount'],
            'tax_amount' => $recurrence['tax_amount'],
            'total' => $recurrence['total'],
            'expense_date' => $dueDate,
            'due_date' => $dueDate,
            'payment_method' => $recurrence['payment_method'] ?? null,
            'reference' => $recurrence['reference'] ?? null,
            'notes' => $recurrence['notes'] ?? null,
            'created_by_user_id' => $recurrence['created_by_user_id'],
        ]);
    }

    private function nextDueDate(string $date, string $frequency, int $interval): string {
        $dt = new DateTimeImmutable($date);
        if ($frequency === 'weekly') {
            return $dt->modify('+' . $interval . ' week')->format('Y-m-d');
        }

        $targetMonth = $dt->modify('first day of this month')->modify('+' . $interval . ' month');
        $day = min((int)$dt->format('d'), (int)$targetMonth->format('t'));
        return $targetMonth->setDate((int)$targetMonth->format('Y'), (int)$targetMonth->format('m'), $day)->format('Y-m-d');
    }
}
