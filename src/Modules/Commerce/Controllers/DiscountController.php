<?php

namespace App\Modules\Commerce\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\DiscountRepository;

class DiscountController {
    private $discountRepository;

    public function __construct() {
        $this->discountRepository = new DiscountRepository();
    }

    private function authenticate() {
        return Auth::requireUser();
    }

    private function requireAdmin($user) {
        if (($user['role'] ?? 'customer') !== 'admin') {
            Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
            exit;
        }
    }

    public function index() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        try {
            Response::json($this->discountRepository->listAll());
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'DISCOUNTS_LIST_FAILED');
        }
    }

    public function show($id) {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        try {
            $row = $this->discountRepository->getById($id);
            if (!$row) {
                Response::error('Código no encontrado', 404, 'DISCOUNT_NOT_FOUND');
                return;
            }
            Response::json($row);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'DISCOUNT_FETCH_FAILED');
        }
    }

    public function store() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $payload = $this->validatePayload($data, false);
            $created = $this->discountRepository->create($payload, $user['sub'] ?? null);
            Response::json($created, 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'DISCOUNT_CREATE_FAILED');
        }
    }

    public function update($id) {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $payload = $this->validatePayload($data, true);
            $updated = $this->discountRepository->update($id, $payload, $user['sub'] ?? null);
            if (!$updated) {
                Response::error('Código no encontrado', 404, 'DISCOUNT_NOT_FOUND');
                return;
            }
            Response::json($updated);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'DISCOUNT_UPDATE_FAILED');
        }
    }

    public function updateStatus($id) {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            if (!array_key_exists('is_active', $data)) {
                Response::error('Campo is_active requerido', 400, 'DISCOUNT_STATUS_REQUIRED');
                return;
            }
            $updated = $this->discountRepository->setActive($id, $this->toBool($data['is_active']), $user['sub'] ?? null);
            if (!$updated) {
                Response::error('Código no encontrado', 404, 'DISCOUNT_NOT_FOUND');
                return;
            }
            Response::json($updated);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'DISCOUNT_STATUS_UPDATE_FAILED');
        }
    }

    public function audit() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        try {
            $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? intval($_GET['limit']) : 100;
            $code = isset($_GET['code']) ? trim((string)$_GET['code']) : null;
            $orderId = isset($_GET['order_id']) ? trim((string)$_GET['order_id']) : null;
            $rows = $this->discountRepository->getAuditLog($limit, $code, $orderId);
            Response::json($rows);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'DISCOUNT_AUDIT_FAILED');
        }
    }

    private function validatePayload(array $data, bool $isUpdate): array {
        $payload = [];

        if (!$isUpdate || array_key_exists('code', $data)) {
            $code = trim((string)($data['code'] ?? ''));
            if ($code === '') {
                throw new \Exception('Código requerido');
            }
            $payload['code'] = $code;
        }

        if (!$isUpdate || array_key_exists('type', $data)) {
            $type = strtolower(trim((string)($data['type'] ?? '')));
            if (!in_array($type, ['percent', 'fixed'], true)) {
                throw new \Exception('Tipo de descuento inválido. Usa percent o fixed.');
            }
            $payload['type'] = $type;
        }

        if (!$isUpdate || array_key_exists('value', $data)) {
            if (!is_numeric($data['value'] ?? null)) {
                throw new \Exception('Valor de descuento inválido');
            }
            $value = floatval($data['value']);
            if ($value <= 0) {
                throw new \Exception('El valor del descuento debe ser mayor a cero');
            }
            if (($payload['type'] ?? strtolower(trim((string)($data['type'] ?? '')))) === 'percent' && $value > 100) {
                throw new \Exception('El porcentaje de descuento no puede exceder 100');
            }
            $payload['value'] = $value;
        }

        if (array_key_exists('name', $data)) {
            $payload['name'] = $this->nullableText($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $payload['description'] = $this->nullableText($data['description']);
        }

        if (array_key_exists('min_subtotal', $data)) {
            if (!is_numeric($data['min_subtotal'])) {
                throw new \Exception('min_subtotal inválido');
            }
            $payload['min_subtotal'] = max(0, floatval($data['min_subtotal']));
        } elseif (!$isUpdate) {
            $payload['min_subtotal'] = 0;
        }

        if (array_key_exists('max_discount', $data)) {
            if ($data['max_discount'] === null || $data['max_discount'] === '') {
                $payload['max_discount'] = null;
            } else {
                if (!is_numeric($data['max_discount'])) {
                    throw new \Exception('max_discount inválido');
                }
                $payload['max_discount'] = max(0, floatval($data['max_discount']));
            }
        }

        if (array_key_exists('max_uses', $data)) {
            if ($data['max_uses'] === null || $data['max_uses'] === '') {
                $payload['max_uses'] = null;
            } else {
                if (!is_numeric($data['max_uses'])) {
                    throw new \Exception('max_uses inválido');
                }
                $uses = intval($data['max_uses']);
                if ($uses < 1) {
                    throw new \Exception('max_uses debe ser mayor a cero');
                }
                $payload['max_uses'] = $uses;
            }
        }

        if (array_key_exists('starts_at', $data)) {
            $payload['starts_at'] = $this->normalizeDateTime($data['starts_at']);
        }
        if (array_key_exists('ends_at', $data)) {
            $payload['ends_at'] = $this->normalizeDateTime($data['ends_at']);
        }

        $startsAt = $payload['starts_at'] ?? ($data['starts_at'] ?? null);
        $endsAt = $payload['ends_at'] ?? ($data['ends_at'] ?? null);
        if ($startsAt && $endsAt && strtotime((string)$endsAt) < strtotime((string)$startsAt)) {
            throw new \Exception('ends_at no puede ser menor que starts_at');
        }

        if (array_key_exists('is_active', $data)) {
            $payload['is_active'] = $this->toBool($data['is_active']);
        } elseif (!$isUpdate) {
            $payload['is_active'] = true;
        }

        if (array_key_exists('metadata', $data)) {
            if ($data['metadata'] !== null && !is_array($data['metadata'])) {
                throw new \Exception('metadata debe ser un objeto JSON');
            }
            $payload['metadata'] = $data['metadata'];
        }

        return $payload;
    }

    private function normalizeDateTime($value): ?string {
        if ($value === null) return null;
        $text = trim((string)$value);
        if ($text === '') return null;
        $timestamp = strtotime($text);
        if ($timestamp === false) {
            throw new \Exception('Fecha/hora inválida: ' . $text);
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function nullableText($value): ?string {
        if ($value === null) return null;
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function toBool($value): bool {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return intval($value) !== 0;
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 't'], true);
    }
}
