<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\Commerce\Domain\CommerceDomain;
use PDO;

class DiscountRepository {
    private $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getModuleInstance(CommerceDomain::KEY);
    }

    public function listAll() {
        $stmt = $this->db->prepare('
            SELECT *
            FROM "DiscountCode"
            WHERE tenant_id = :tenant_id
            ORDER BY created_at DESC, id DESC
            LIMIT 100
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'normalizeDiscountRow'], $rows);
    }

    public function getById($id) {
        $stmt = $this->db->prepare('
            SELECT *
            FROM "DiscountCode"
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId()
        ]);
        $row = $stmt->fetch();
        return $row ? $this->normalizeDiscountRow($row) : null;
    }

    public function create(array $data, ?string $actorUserId = null) {
        $id = uniqid('dc_');
        $code = $this->normalizeCode($data['code'] ?? '');
        if ($code === null) {
            throw new \Exception('Código de descuento inválido');
        }

        $stmt = $this->db->prepare('
            INSERT INTO "DiscountCode" (
                id, tenant_id, code, name, description, type, value, min_subtotal, max_discount,
                max_uses, used_count, starts_at, ends_at, is_active, created_by, metadata, created_at, updated_at
            ) VALUES (
                :id, :tenant_id, :code, :name, :description, :type, :value, :min_subtotal, :max_discount,
                :max_uses, 0, :starts_at, :ends_at, :is_active, :created_by, :metadata, NOW(), NOW()
            )
        ');

        try {
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $this->getTenantId(),
                'code' => $code,
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'type' => strtolower(trim((string)($data['type'] ?? 'percent'))),
                'value' => $this->roundMoney($this->toFloat($data['value'] ?? 0)),
                'min_subtotal' => $this->roundMoney(max(0, $this->toFloat($data['min_subtotal'] ?? 0))),
                'max_discount' => isset($data['max_discount']) && $data['max_discount'] !== null
                    ? $this->roundMoney(max(0, $this->toFloat($data['max_discount'])))
                    : null,
                'max_uses' => isset($data['max_uses']) && $data['max_uses'] !== null
                    ? max(1, intval($data['max_uses']))
                    : null,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'is_active' => $this->toBool($data['is_active'] ?? true) ? 1 : 0,
                'created_by' => $actorUserId,
                'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null
            ]);
        } catch (\PDOException $e) {
            if (($e->getCode() ?? '') === '23505') {
                throw new \Exception('Ya existe un código de descuento con ese nombre');
            }
            throw $e;
        }

        $created = $this->getById($id);
        $this->writeAudit('admin_created', [
            'discount_code_id' => $id,
            'code' => $code,
            'payload' => $created,
            'user_id' => $actorUserId
        ]);
        return $created;
    }

    public function update($id, array $data, ?string $actorUserId = null) {
        $current = $this->getById($id);
        if (!$current) {
            return null;
        }

        $next = [
            'code' => array_key_exists('code', $data) ? $this->normalizeCode($data['code']) : $current['code'],
            'name' => array_key_exists('name', $data) ? $this->nullableText($data['name']) : $current['name'],
            'description' => array_key_exists('description', $data) ? $this->nullableText($data['description']) : $current['description'],
            'type' => array_key_exists('type', $data) ? strtolower(trim((string)$data['type'])) : $current['type'],
            'value' => array_key_exists('value', $data) ? $this->roundMoney(max(0, $this->toFloat($data['value']))) : $current['value'],
            'min_subtotal' => array_key_exists('min_subtotal', $data) ? $this->roundMoney(max(0, $this->toFloat($data['min_subtotal']))) : $current['min_subtotal'],
            'max_discount' => array_key_exists('max_discount', $data)
                ? ($data['max_discount'] === null || $data['max_discount'] === '' ? null : $this->roundMoney(max(0, $this->toFloat($data['max_discount']))))
                : $current['max_discount'],
            'max_uses' => array_key_exists('max_uses', $data)
                ? ($data['max_uses'] === null || $data['max_uses'] === '' ? null : max(1, intval($data['max_uses'])))
                : $current['max_uses'],
            'starts_at' => array_key_exists('starts_at', $data) ? ($data['starts_at'] ?: null) : $current['starts_at'],
            'ends_at' => array_key_exists('ends_at', $data) ? ($data['ends_at'] ?: null) : $current['ends_at'],
            'is_active' => array_key_exists('is_active', $data) ? $this->toBool($data['is_active']) : $current['is_active'],
            'metadata' => array_key_exists('metadata', $data) ? $data['metadata'] : $current['metadata']
        ];

        if ($next['code'] === null) {
            throw new \Exception('Código de descuento inválido');
        }

        $stmt = $this->db->prepare('
            UPDATE "DiscountCode"
            SET code = :code,
                name = :name,
                description = :description,
                type = :type,
                value = :value,
                min_subtotal = :min_subtotal,
                max_discount = :max_discount,
                max_uses = :max_uses,
                starts_at = :starts_at,
                ends_at = :ends_at,
                is_active = :is_active,
                metadata = :metadata,
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');

        try {
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $this->getTenantId(),
                'code' => $next['code'],
                'name' => $next['name'],
                'description' => $next['description'],
                'type' => $next['type'],
                'value' => $next['value'],
                'min_subtotal' => $next['min_subtotal'],
                'max_discount' => $next['max_discount'],
                'max_uses' => $next['max_uses'],
                'starts_at' => $next['starts_at'],
                'ends_at' => $next['ends_at'],
                'is_active' => $next['is_active'] ? 1 : 0,
                'metadata' => isset($next['metadata']) ? json_encode($next['metadata']) : null
            ]);
        } catch (\PDOException $e) {
            if (($e->getCode() ?? '') === '23505') {
                throw new \Exception('Ya existe un código de descuento con ese nombre');
            }
            throw $e;
        }

        $updated = $this->getById($id);
        $this->writeAudit('admin_updated', [
            'discount_code_id' => $id,
            'code' => $updated['code'] ?? $current['code'],
            'payload' => [
                'before' => $current,
                'after' => $updated
            ],
            'user_id' => $actorUserId
        ]);
        return $updated;
    }

    public function setActive($id, bool $isActive, ?string $actorUserId = null) {
        $stmt = $this->db->prepare('
            UPDATE "DiscountCode"
            SET is_active = :is_active, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'is_active' => $isActive ? 1 : 0
        ]);
        $updated = $this->getById($id);
        if ($updated) {
            $this->writeAudit($isActive ? 'admin_enabled' : 'admin_disabled', [
                'discount_code_id' => $id,
                'code' => $updated['code'],
                'payload' => ['is_active' => $isActive],
                'user_id' => $actorUserId
            ]);
        }
        return $updated;
    }

    public function getAuditLog(int $limit = 100, ?string $code = null, ?string $orderId = null) {
        $safeLimit = max(1, min(100, $limit));
        $sql = '
            SELECT *
            FROM "DiscountAudit"
            WHERE tenant_id = :tenant_id
        ';
        $params = ['tenant_id' => $this->getTenantId()];
        if ($code !== null && trim($code) !== '') {
            $sql .= ' AND code = :code';
            $params['code'] = $this->normalizeCode($code);
        }
        if ($orderId !== null && trim($orderId) !== '') {
            $sql .= ' AND order_id = :order_id';
            $params['order_id'] = trim($orderId);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . $safeLimit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'normalizeAuditRow'], $rows);
    }

    public function evaluateForQuote(?string $rawCode, float $itemsSubtotal, ?string $userId = null, array $pricingContext = []): array {
        $code = $this->normalizeCode($rawCode);
        if ($code === null) {
            return $this->emptyDiscountResult();
        }

        $row = $this->getCodeByNormalizedCode($code, false);
        if (!$row) {
            $result = $this->rejectedDiscountResult($code, 'invalid_code', 'Código de descuento no registrado.');
            $this->writeAudit('quote_rejected', [
                'code' => $code,
                'reason' => 'invalid_code',
                'payload' => $this->buildAuditPayload($itemsSubtotal, $pricingContext),
                'user_id' => $userId
            ]);
            return $result;
        }

        $evaluation = $this->evaluateRowForSubtotal($row, $itemsSubtotal, $pricingContext);
        if ($evaluation['status'] !== 'applied') {
            $this->writeAudit('quote_rejected', [
                'discount_code_id' => $row['id'],
                'code' => $code,
                'reason' => $evaluation['reason'],
                'payload' => $this->buildAuditPayload($itemsSubtotal, $pricingContext, $evaluation),
                'user_id' => $userId
            ]);
            return $this->rejectedDiscountResult($code, $evaluation['reason'], $evaluation['message']);
        }

        $discountAmount = $evaluation['discount_amount'];
        $normalized = $this->normalizeDiscountRow($row);
        $applied = [
            'id' => $normalized['id'],
            'code' => $normalized['code'],
            'name' => $normalized['name'],
            'type' => $normalized['type'],
            'value' => $normalized['value'],
            'amount' => $discountAmount,
            'requested_amount' => $evaluation['requested_discount_amount'] ?? $discountAmount,
            'limited_by_guardrail' => !empty($evaluation['limited_by_guardrail']),
            'guardrail' => $evaluation['guardrail'] ?? null
        ];

        $this->writeAudit('quote_applied', [
            'discount_code_id' => $row['id'],
            'code' => $code,
            'amount' => $discountAmount,
            'payload' => $this->buildAuditPayload($itemsSubtotal, $pricingContext, $evaluation),
            'user_id' => $userId
        ]);

        return [
            'discount_code' => $code,
            'discount_total' => $discountAmount,
            'discounts_applied' => [$applied],
            'discount_rejections' => []
        ];
    }

    public function reserveForOrder(?string $rawCode, float $itemsSubtotal, string $orderId, ?string $userId = null, array $pricingContext = []): array {
        $code = $this->normalizeCode($rawCode);
        if ($code === null) {
            return $this->emptyDiscountResult();
        }

        $row = $this->getCodeByNormalizedCode($code, true);
        if (!$row) {
            $this->writeAudit('order_rejected', [
                'code' => $code,
                'reason' => 'invalid_code',
                'order_id' => $orderId,
                'payload' => $this->buildAuditPayload($itemsSubtotal, $pricingContext),
                'user_id' => $userId
            ]);
            throw new \Exception('Código de descuento no registrado.');
        }

        $evaluation = $this->evaluateRowForSubtotal($row, $itemsSubtotal, $pricingContext);
        if ($evaluation['status'] !== 'applied') {
            $this->writeAudit('order_rejected', [
                'discount_code_id' => $row['id'],
                'code' => $code,
                'reason' => $evaluation['reason'],
                'order_id' => $orderId,
                'payload' => $this->buildAuditPayload($itemsSubtotal, $pricingContext, $evaluation),
                'user_id' => $userId
            ]);
            throw new \Exception($evaluation['message']);
        }

        $maxUses = isset($row['max_uses']) && $row['max_uses'] !== null ? intval($row['max_uses']) : null;
        $usedCount = intval($row['used_count'] ?? 0);
        if ($maxUses !== null && $maxUses > 0 && $usedCount >= $maxUses) {
            $this->writeAudit('order_rejected', [
                'discount_code_id' => $row['id'],
                'code' => $code,
                'reason' => 'usage_limit_reached',
                'order_id' => $orderId,
                'payload' => $this->buildAuditPayload($itemsSubtotal, $pricingContext),
                'user_id' => $userId
            ]);
            throw new \Exception('Código de descuento agotado.');
        }

        $stmtUpdate = $this->db->prepare('
            UPDATE "DiscountCode"
            SET used_count = used_count + 1,
                updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmtUpdate->execute([
            'id' => $row['id'],
            'tenant_id' => $this->getTenantId()
        ]);

        $normalized = $this->normalizeDiscountRow($row);
        $discountAmount = $evaluation['discount_amount'];
        $snapshot = [
            'id' => $normalized['id'],
            'code' => $normalized['code'],
            'name' => $normalized['name'],
            'description' => $normalized['description'],
            'type' => $normalized['type'],
            'value' => $normalized['value'],
            'min_subtotal' => $normalized['min_subtotal'],
            'max_discount' => $normalized['max_discount'],
            'max_uses' => $normalized['max_uses'],
            'used_count_before' => $normalized['used_count'],
            'starts_at' => $normalized['starts_at'],
            'ends_at' => $normalized['ends_at'],
            'requested_amount' => $evaluation['requested_discount_amount'] ?? $discountAmount,
            'limited_by_guardrail' => !empty($evaluation['limited_by_guardrail']),
            'guardrail' => $evaluation['guardrail'] ?? null
        ];

        $this->writeAudit('order_applied', [
            'discount_code_id' => $row['id'],
            'code' => $code,
            'reason' => 'applied',
            'order_id' => $orderId,
            'amount' => $discountAmount,
            'payload' => array_merge(
                $this->buildAuditPayload($itemsSubtotal, $pricingContext, $evaluation),
                ['snapshot' => $snapshot]
            ),
            'user_id' => $userId
        ]);

        return [
            'discount_code' => $code,
            'discount_total' => $discountAmount,
            'discounts_applied' => [array_merge($snapshot, ['amount' => $discountAmount])],
            'discount_rejections' => []
        ];
    }

    private function getCodeByNormalizedCode(string $code, bool $lockForUpdate) {
        $sql = '
            SELECT *
            FROM "DiscountCode"
            WHERE tenant_id = :tenant_id
              AND code = :code
            LIMIT 1
        ';
        if ($lockForUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'code' => $code
        ]);
        return $stmt->fetch();
    }

    private function evaluateRowForSubtotal(array $row, float $itemsSubtotal, array $pricingContext = []): array {
        $isActive = $this->toBool($row['is_active'] ?? false);
        if (!$isActive) {
            return $this->rejectEvaluation('inactive', 'Código de descuento inactivo.');
        }

        $nowTs = time();
        $startsAt = isset($row['starts_at']) && $row['starts_at'] ? strtotime((string)$row['starts_at']) : null;
        $endsAt = isset($row['ends_at']) && $row['ends_at'] ? strtotime((string)$row['ends_at']) : null;

        if ($startsAt !== null && $startsAt > $nowTs) {
            return $this->rejectEvaluation('not_started', 'El código de descuento aún no está vigente.');
        }
        if ($endsAt !== null && $endsAt < $nowTs) {
            return $this->rejectEvaluation('expired', 'El código de descuento ya expiró.');
        }

        $minSubtotal = max(0, $this->toFloat($row['min_subtotal'] ?? 0));
        if ($itemsSubtotal < $minSubtotal) {
            return $this->rejectEvaluation('min_subtotal_not_met', 'El subtotal no cumple el mínimo requerido para este descuento.');
        }

        $maxUses = isset($row['max_uses']) && $row['max_uses'] !== null ? intval($row['max_uses']) : null;
        $usedCount = intval($row['used_count'] ?? 0);
        if ($maxUses !== null && $maxUses > 0 && $usedCount >= $maxUses) {
            return $this->rejectEvaluation('usage_limit_reached', 'Código de descuento agotado.');
        }

        $type = strtolower(trim((string)($row['type'] ?? '')));
        $value = $this->toFloat($row['value'] ?? 0);
        if ($value <= 0) {
            return $this->rejectEvaluation('invalid_rule', 'El descuento configurado no es válido.');
        }

        $rawDiscount = 0.0;
        if ($type === 'percent') {
            if ($value > 100) {
                return $this->rejectEvaluation('invalid_rule', 'El porcentaje del descuento supera el límite permitido.');
            }
            $rawDiscount = $itemsSubtotal * ($value / 100);
        } elseif ($type === 'fixed') {
            $rawDiscount = $value;
        } else {
            return $this->rejectEvaluation('invalid_rule', 'El tipo de descuento configurado no es válido.');
        }

        $maxDiscount = isset($row['max_discount']) && $row['max_discount'] !== null
            ? max(0, $this->toFloat($row['max_discount']))
            : null;
        if ($maxDiscount !== null && $maxDiscount > 0) {
            $rawDiscount = min($rawDiscount, $maxDiscount);
        }

        $requestedDiscount = $this->roundMoney(min(max(0, $rawDiscount), max(0, $itemsSubtotal)));
        if ($requestedDiscount <= 0) {
            return $this->rejectEvaluation('zero_discount', 'El descuento no aplica sobre el subtotal actual.');
        }

        $guardrail = $this->buildProfitGuardrail($itemsSubtotal, $pricingContext);
        $discountAmount = $requestedDiscount;
        $limitedByGuardrail = false;

        if ($guardrail !== null && $discountAmount > (($guardrail['max_safe_discount'] ?? 0) + 0.00001)) {
            $discountAmount = $guardrail['max_safe_discount'];
            $limitedByGuardrail = true;
        }

        $discountAmount = $this->roundMoney(min(max(0, $discountAmount), max(0, $itemsSubtotal)));
        if ($discountAmount <= 0) {
            if ($guardrail !== null) {
                return $this->rejectEvaluation(
                    'loss_prevention',
                    'El código de descuento dejaría el pedido por debajo del costo y no puede aplicarse.',
                    [
                        'requested_discount_amount' => $requestedDiscount,
                        'guardrail' => $guardrail,
                        'limited_by_guardrail' => true
                    ]
                );
            }
            return $this->rejectEvaluation('zero_discount', 'El descuento no aplica sobre el subtotal actual.');
        }

        return [
            'status' => 'applied',
            'reason' => null,
            'message' => null,
            'discount_amount' => $discountAmount,
            'requested_discount_amount' => $requestedDiscount,
            'limited_by_guardrail' => $limitedByGuardrail,
            'guardrail' => $guardrail
        ];
    }

    private function rejectEvaluation(string $reason, string $message, array $extra = []): array {
        return array_merge([
            'status' => 'rejected',
            'reason' => $reason,
            'message' => $message,
            'discount_amount' => 0.0
        ], $extra);
    }

    private function emptyDiscountResult(): array {
        return [
            'discount_code' => null,
            'discount_total' => 0.0,
            'discounts_applied' => [],
            'discount_rejections' => []
        ];
    }

    private function rejectedDiscountResult(string $code, string $reason, string $message): array {
        return [
            'discount_code' => $code,
            'discount_total' => 0.0,
            'discounts_applied' => [],
            'discount_rejections' => [[
                'code' => $code,
                'reason' => $reason,
                'message' => $message
            ]]
        ];
    }

    private function buildAuditPayload(float $itemsSubtotal, array $pricingContext = [], array $evaluation = []): array {
        $payload = [
            'items_subtotal' => $this->roundMoney($itemsSubtotal),
        ];

        if (array_key_exists('items_cost_total', $pricingContext)) {
            $payload['items_cost_total'] = $this->roundMoney(max(0, $this->toFloat($pricingContext['items_cost_total'])));
        }
        if (array_key_exists('tax_rate', $pricingContext)) {
            $payload['tax_rate'] = $this->roundMoney(max(0, $this->toFloat($pricingContext['tax_rate'])));
        }
        if (isset($evaluation['requested_discount_amount'])) {
            $payload['requested_discount_amount'] = $this->roundMoney(max(0, $this->toFloat($evaluation['requested_discount_amount'])));
        }
        if (isset($evaluation['discount_amount'])) {
            $payload['discount_amount'] = $this->roundMoney(max(0, $this->toFloat($evaluation['discount_amount'])));
        }
        if (!empty($evaluation['limited_by_guardrail'])) {
            $payload['limited_by_guardrail'] = true;
        }
        if (isset($evaluation['guardrail']) && is_array($evaluation['guardrail'])) {
            $payload['guardrail'] = $evaluation['guardrail'];
        }

        return $payload;
    }

    private function buildProfitGuardrail(float $itemsSubtotal, array $pricingContext = []): ?array {
        $itemsCostTotal = max(0, $this->toFloat($pricingContext['items_cost_total'] ?? 0));
        if ($itemsSubtotal <= 0 || $itemsCostTotal <= 0) {
            return null;
        }

        $taxRate = max(0, $this->toFloat($pricingContext['tax_rate'] ?? 0));
        $taxMultiplier = 1 + ($taxRate / 100);
        $costFloorGross = $itemsCostTotal * $taxMultiplier;
        $maxSafeDiscount = $this->floorMoney(max(0, $itemsSubtotal - $costFloorGross));

        return [
            'items_cost_total' => $this->roundMoney($itemsCostTotal),
            'tax_rate' => $this->roundMoney($taxRate),
            'cost_floor_gross' => $this->roundMoney(max(0, $costFloorGross)),
            'max_safe_discount' => $maxSafeDiscount
        ];
    }

    private function writeAudit(string $action, array $data = []): void {
        $stmt = $this->db->prepare('
            INSERT INTO "DiscountAudit" (
                id, tenant_id, discount_code_id, code, action, reason, order_id, amount, payload, user_id, created_at
            ) VALUES (
                :id, :tenant_id, :discount_code_id, :code, :action, :reason, :order_id, :amount, :payload, :user_id, NOW()
            )
        ');
        $stmt->execute([
            'id' => uniqid('da_'),
            'tenant_id' => $this->getTenantId(),
            'discount_code_id' => $data['discount_code_id'] ?? null,
            'code' => isset($data['code']) ? $this->normalizeCode($data['code']) : null,
            'action' => $action,
            'reason' => $data['reason'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'amount' => isset($data['amount']) ? $this->roundMoney($this->toFloat($data['amount'])) : null,
            'payload' => isset($data['payload']) ? json_encode($data['payload']) : null,
            'user_id' => $data['user_id'] ?? null
        ]);
    }

    public function normalizeCode($rawCode): ?string {
        if ($rawCode === null) {
            return null;
        }
        $normalized = strtoupper(trim((string)$rawCode));
        if ($normalized === '') {
            return null;
        }
        $normalized = preg_replace('/\s+/', '', $normalized);
        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeDiscountRow($row) {
        if (!$row || !is_array($row)) {
            return $row;
        }
        $row['value'] = $this->roundMoney($this->toFloat($row['value'] ?? 0));
        $row['min_subtotal'] = $this->roundMoney(max(0, $this->toFloat($row['min_subtotal'] ?? 0)));
        $row['max_discount'] = isset($row['max_discount']) && $row['max_discount'] !== null
            ? $this->roundMoney(max(0, $this->toFloat($row['max_discount'])))
            : null;
        $row['max_uses'] = isset($row['max_uses']) && $row['max_uses'] !== null ? intval($row['max_uses']) : null;
        $row['used_count'] = intval($row['used_count'] ?? 0);
        $row['is_active'] = $this->toBool($row['is_active'] ?? false);
        if (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            $row['metadata'] = is_array($decoded) ? $decoded : null;
        }
        return $row;
    }

    private function normalizeAuditRow($row) {
        if (!$row || !is_array($row)) {
            return $row;
        }
        $row['amount'] = isset($row['amount']) && $row['amount'] !== null ? $this->roundMoney($this->toFloat($row['amount'])) : null;
        if (isset($row['payload']) && is_string($row['payload']) && $row['payload'] !== '') {
            $decoded = json_decode($row['payload'], true);
            $row['payload'] = is_array($decoded) ? $decoded : null;
        }
        return $row;
    }

    private function nullableText($value): ?string {
        if ($value === null) return null;
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function toFloat($value): float {
        return is_numeric($value) ? floatval($value) : 0.0;
    }

    private function roundMoney(float $value): float {
        return round($value, 2);
    }

    private function floorMoney(float $value): float {
        if ($value <= 0) {
            return 0.0;
        }
        return floor(($value + 0.0000001) * 100) / 100;
    }

    private function toBool($value): bool {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return intval($value) !== 0;
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 't'], true);
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}
