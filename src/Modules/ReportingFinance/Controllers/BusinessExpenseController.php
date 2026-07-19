<?php

namespace App\Modules\ReportingFinance\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Exceptions\FinancialPeriodClosedException;
use App\Modules\ReportingFinance\Application\Ports\BusinessExpensePort;
use App\Modules\ReportingFinance\Infrastructure\ReportingFinancePortsFactory;

class BusinessExpenseController {
    private BusinessExpensePort $expenses;

    private array $categories = [
        'Arriendo',
        'Sueldos',
        'Servicios básicos',
        'Internet / telefonía',
        'Software / suscripciones',
        'Marketing',
        'Transporte / delivery',
        'Mantenimiento',
        'Contabilidad / legal',
        'Otros',
    ];

    public function __construct(?BusinessExpensePort $expenses = null) {
        $this->expenses = $expenses ?? ReportingFinancePortsFactory::businessExpenses();
    }

    private function adminUser(): array {
        return Auth::requireAdmin();
    }

    private function currentUserId(array $user): string {
        return (string)($user['sub'] ?? 'service');
    }

    private function input(): array {
        $decoded = json_decode(file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function categories(): array {
        return array_values(array_unique(array_merge($this->categories, $this->expenses->categories())));
    }

    private function filters(): array {
        return [
            'status' => $_GET['status'] ?? null,
            'category' => $_GET['category'] ?? null,
            'type' => $_GET['type'] ?? null,
            'period' => $_GET['period'] ?? null,
            'from' => $_GET['from'] ?? null,
            'to' => $_GET['to'] ?? null,
        ];
    }

    public function index(): void {
        $this->adminUser();
        try {
            Response::json([
                'expenses' => $this->expenses->list($this->filters()),
                'summary' => $this->expenses->summary($this->filters()),
                'categories' => $this->categories(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'BUSINESS_EXPENSES_LIST_FAILED');
        }
    }

    public function store(): void {
        $user = $this->adminUser();
        try {
            $expense = $this->expenses->create($this->input(), $this->currentUserId($user));
            Response::json([
                'expense' => $expense,
                'summary' => $this->expenses->summary(),
            ], 201);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400, 'BUSINESS_EXPENSE_CREATE_FAILED');
        }
    }

    public function update($id): void {
        $this->adminUser();
        try {
            $expense = $this->expenses->update((string)$id, $this->input());
            Response::json([
                'expense' => $expense,
                'summary' => $this->expenses->summary(),
            ]);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400, 'BUSINESS_EXPENSE_UPDATE_FAILED');
        }
    }

    public function updateStatus($id): void {
        $user = $this->adminUser();
        $input = $this->input();
        try {
            $expense = $this->expenses->updateStatus(
                (string)$id,
                (string)($input['status'] ?? 'pending'),
                $input,
                $this->currentUserId($user)
            );
            Response::json([
                'expense' => $expense,
                'summary' => $this->expenses->summary(),
            ]);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400, 'BUSINESS_EXPENSE_STATUS_FAILED');
        }
    }

    public function recurrences(): void {
        $this->adminUser();
        try {
            Response::json([
                'recurrences' => $this->expenses->listRecurrences(),
                'summary' => $this->expenses->summary(),
                'categories' => $this->categories(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'BUSINESS_EXPENSE_RECURRENCES_LIST_FAILED');
        }
    }

    public function storeRecurrence(): void {
        $user = $this->adminUser();
        try {
            $recurrence = $this->expenses->createRecurrence($this->input(), $this->currentUserId($user));
            Response::json([
                'recurrence' => $recurrence,
                'expenses' => $this->expenses->list($this->filters()),
                'summary' => $this->expenses->summary(),
            ], 201);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400, 'BUSINESS_EXPENSE_RECURRENCE_CREATE_FAILED');
        }
    }

    public function updateRecurrence($id): void {
        $this->adminUser();
        try {
            $recurrence = $this->expenses->updateRecurrence((string)$id, $this->input());
            Response::json([
                'recurrence' => $recurrence,
                'summary' => $this->expenses->summary(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400, 'BUSINESS_EXPENSE_RECURRENCE_UPDATE_FAILED');
        }
    }

    public function deleteRecurrence($id): void {
        $this->adminUser();
        try {
            $result = $this->expenses->deleteRecurrence((string)$id);
            Response::json([
                'deleted' => true,
                'recurrence' => $result['recurrence'] ?? null,
                'generated_expenses_count' => $result['generated_expenses_count'] ?? 0,
                'summary' => $this->expenses->summary(),
            ]);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400, 'BUSINESS_EXPENSE_RECURRENCE_DELETE_FAILED');
        }
    }
}
