<?php

namespace App\Modules\Commerce\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Exceptions\FinancialPeriodClosedException;
use App\Modules\Commerce\Application\Ports\CommerceExpensePort;
use App\Modules\Commerce\Infrastructure\CommerceCollaborationPortsFactory;
use App\Repositories\PosRepository;

class PosController {
    private $repository;
    private ?CommerceExpensePort $expenses = null;

    public function __construct() {
        $this->repository = new PosRepository();
    }

    private function getAdminUser(): array {
        return Auth::requireUser();
    }

    private function getCurrentUserId(array $user): string {
        return (string)($user['sub'] ?? 'service');
    }

    private function expenses(): CommerceExpensePort {
        return $this->expenses ??= CommerceCollaborationPortsFactory::expenses();
    }

    public function activeShift() {
        $this->getAdminUser();
        try {
            $shift = $this->repository->getActiveShift();
            $movements = $shift ? $this->repository->listMovements((string)$shift['id']) : [];
            $history = $this->repository->listShifts(20);
            Response::json([
                'shift' => $shift,
                'movements' => $movements,
                'history' => $history
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'POS_ACTIVE_SHIFT_FAILED');
        }
    }

    public function shifts() {
        $this->getAdminUser();
        try {
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
            $history = $this->repository->listShifts($limit);
            Response::json($history);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'POS_SHIFT_LIST_FAILED');
        }
    }

    public function movements() {
        $this->getAdminUser();
        try {
            $active = $this->repository->getActiveShift();
            $shiftId = trim((string)($_GET['shift_id'] ?? ''));
            if ($shiftId === '') {
                $shiftId = (string)($active['id'] ?? '');
            }
            if ($shiftId === '') {
                Response::json([]);
                return;
            }
            $movements = $this->repository->listMovements($shiftId);
            Response::json($movements);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'POS_MOVEMENTS_LIST_FAILED');
        }
    }

    public function openShift() {
        $user = $this->getAdminUser();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            if (!isset($data['opening_cash']) || !is_numeric($data['opening_cash'])) {
                Response::error('Monto inicial de caja inválido.', 400, 'POS_OPENING_CASH_INVALID');
                return;
            }
            $openingCash = max(0, round((float)$data['opening_cash'], 2));
            $notes = trim((string)($data['notes'] ?? ''));

            $shift = $this->repository->openShift($openingCash, $notes, $this->getCurrentUserId($user));
            $movements = $this->repository->listMovements((string)$shift['id']);
            $history = $this->repository->listShifts(20);
            Response::json([
                'shift' => $shift,
                'movements' => $movements,
                'history' => $history
            ], 201);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'POS_SHIFT_OPEN_FAILED');
        }
    }

    public function closeShift() {
        $user = $this->getAdminUser();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            if (!isset($data['closing_cash']) || !is_numeric($data['closing_cash'])) {
                Response::error('Monto de cierre inválido.', 400, 'POS_CLOSING_CASH_INVALID');
                return;
            }
            $closingCash = max(0, round((float)$data['closing_cash'], 2));
            $notes = trim((string)($data['notes'] ?? ''));

            $shift = $this->repository->closeActiveShift($closingCash, $notes, $this->getCurrentUserId($user));
            $history = $this->repository->listShifts(20);
            Response::json([
                'shift' => $shift,
                'movements' => [],
                'history' => $history
            ]);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'POS_SHIFT_CLOSE_FAILED');
        }
    }

    public function addMovement() {
        $user = $this->getAdminUser();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $type = strtolower(trim((string)($data['type'] ?? '')));
            if ($type === '') {
                Response::error('Tipo de movimiento requerido.', 400, 'POS_MOVEMENT_TYPE_REQUIRED');
                return;
            }
            if (!isset($data['amount']) || !is_numeric($data['amount'])) {
                Response::error('Monto de movimiento inválido.', 400, 'POS_MOVEMENT_AMOUNT_INVALID');
                return;
            }
            $amount = round((float)$data['amount'], 2);
            $description = trim((string)($data['description'] ?? ''));
            $businessExpenseId = trim((string)($data['business_expense_id'] ?? '')) ?: null;

            if (in_array($type, ['expense', 'withdrawal'], true)) {
                if ($businessExpenseId) {
                    $this->expenses()->markPaid($businessExpenseId, $this->getCurrentUserId($user));
                } elseif (!empty($data['create_business_expense'])) {
                    $expense = $this->expenses()->createPaidCashExpense([
                        'category' => trim((string)($data['business_expense_category'] ?? 'Otros')) ?: 'Otros',
                        'description' => $description !== '' ? $description : 'Egreso POS',
                        'amount' => $amount,
                        'tax_amount' => 0,
                        'total' => $amount,
                        'expense_date' => date('Y-m-d'),
                        'due_date' => date('Y-m-d'),
                    ], $this->getCurrentUserId($user));
                    $businessExpenseId = (string)($expense['id'] ?? '');
                }
            }

            $movement = $this->repository->addMovement($type, $amount, $description, $this->getCurrentUserId($user), $businessExpenseId ?: null);
            $shift = $this->repository->getActiveShift();
            $movements = $shift ? $this->repository->listMovements((string)$shift['id']) : [];
            $history = $this->repository->listShifts(20);

            Response::json([
                'movement' => $movement,
                'shift' => $shift,
                'movements' => $movements,
                'history' => $history
            ], 201);
        } catch (FinancialPeriodClosedException $e) {
            Response::error($e->getMessage(), 409, 'FINANCIAL_PERIOD_CLOSED', [
                'period_key' => $e->getPeriodKey(),
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'POS_MOVEMENT_CREATE_FAILED');
        }
    }

    public function customerByDocument() {
        $this->getAdminUser();
        try {
            $document = trim((string)($_GET['document'] ?? ''));
            if ($document === '') {
                Response::error('Debes enviar la cédula/documento a consultar.', 400, 'POS_CUSTOMER_DOCUMENT_REQUIRED');
                return;
            }

            $result = $this->repository->findCustomerByDocument($document);
            if (!$result) {
                Response::json([
                    'found' => false,
                    'customer' => null
                ]);
                return;
            }
            Response::json($result);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'POS_CUSTOMER_LOOKUP_FAILED');
        }
    }
}
