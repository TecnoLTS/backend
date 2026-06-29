<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\CatalogInventory\Domain\CatalogInventoryDomain;
use PDO;

class ProductReviewRepository {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getModuleInstance(CatalogInventoryDomain::KEY);
    }

    public function listApprovedForProduct(string $productId): array {
        $stmt = $this->db->prepare('
            SELECT *
            FROM "ProductReview"
            WHERE tenant_id = :tenant_id
              AND product_id = :product_id
              AND status = \'approved\'
            ORDER BY created_at DESC
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'product_id' => $productId,
        ]);

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll() ?: []);
    }

    public function getApprovedSummary(string $productId): array {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)::int AS count, COALESCE(AVG(rating), 0) AS average
            FROM "ProductReview"
            WHERE tenant_id = :tenant_id
              AND product_id = :product_id
              AND status = \'approved\'
        ');
        $stmt->execute([
            'tenant_id' => $this->getTenantId(),
            'product_id' => $productId,
        ]);
        $row = $stmt->fetch() ?: [];

        return [
            'count' => max(0, (int)($row['count'] ?? 0)),
            'average' => round((float)($row['average'] ?? 0), 2),
        ];
    }

    public function listAdmin(array $filters = []): array {
        $status = strtolower(trim((string)($filters['status'] ?? '')));
        $productId = trim((string)($filters['productId'] ?? $filters['product_id'] ?? ''));
        $safeLimit = max(1, min(500, (int)($filters['limit'] ?? 100)));

        $where = ['r.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $this->getTenantId()];

        if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where[] = 'r.status = :status';
            $params['status'] = $status;
        }
        if ($productId !== '') {
            $where[] = 'r.product_id = :product_id';
            $params['product_id'] = $productId;
        }

        $sql = '
            SELECT
                r.*,
                p.name AS product_name,
                p.slug AS product_slug,
                p.legacy_id AS product_legacy_id,
                u.email AS user_email
            FROM "ProductReview" r
            LEFT JOIN "Product" p
              ON p.id = r.product_id
             AND p.tenant_id = r.tenant_id
            LEFT JOIN "Customer" u
              ON u.id = r.user_id
             AND u.tenant_id = r.tenant_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY r.created_at DESC
            LIMIT ' . $safeLimit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'normalizeAdminRow'], $stmt->fetchAll() ?: []);
    }

    public function createVerified(string $productId, array $user, array $payload): array {
        $userId = trim((string)($user['sub'] ?? ''));
        $role = strtolower(trim((string)($user['role'] ?? 'customer')));
        if ($userId === '' || $role === 'guest' || str_starts_with($userId, 'guest-')) {
            throw new \InvalidArgumentException('Debes iniciar sesión con una cuenta registrada para reseñar.');
        }

        $rating = (int)($payload['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('La calificación debe estar entre 1 y 5.');
        }

        $body = $this->cleanText($payload['body'] ?? '', 2000);
        if (mb_strlen($body) < 10) {
            throw new \InvalidArgumentException('La reseña debe tener al menos 10 caracteres.');
        }

        $title = $this->cleanText($payload['title'] ?? '', 120);
        $authorName = $this->cleanText(
            $payload['authorName'] ?? $payload['author_name'] ?? ($user['name'] ?? 'Cliente verificado'),
            80
        );
        if ($authorName === '') {
            $authorName = 'Cliente verificado';
        }

        $eligibleItem = $this->findEligibleOrderItem(
            $productId,
            $userId,
            trim((string)($payload['orderId'] ?? $payload['order_id'] ?? '')),
            trim((string)($payload['orderItemId'] ?? $payload['order_item_id'] ?? ''))
        );
        if (!$eligibleItem) {
            throw new \RuntimeException('Solo puedes reseñar productos comprados en órdenes completadas o entregadas.');
        }

        $id = uniqid('review_');
        $stmt = $this->db->prepare('
            INSERT INTO "ProductReview" (
                id, tenant_id, product_id, order_id, order_item_id, user_id, customer_id,
                rating, title, body, author_name, status, created_at, updated_at
            ) VALUES (
                :id, :tenant_id, :product_id, :order_id, :order_item_id, :user_id, :customer_id,
                :rating, :title, :body, :author_name, \'pending\', NOW(), NOW()
            )
        ');

        try {
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $this->getTenantId(),
                'product_id' => $productId,
                'order_id' => $eligibleItem['order_id'],
                'order_item_id' => $eligibleItem['order_item_id'],
                'user_id' => $userId,
                'customer_id' => $userId,
                'rating' => $rating,
                'title' => $title !== '' ? $title : null,
                'body' => $body,
                'author_name' => $authorName,
            ]);
        } catch (\PDOException $e) {
            if (($e->getCode() ?? '') === '23505') {
                throw new \RuntimeException('Ya registraste una reseña para este producto comprado.');
            }
            throw $e;
        }

        return $this->getById($id) ?? [];
    }

    public function updateStatus(string $id, string $status): ?array {
        $status = strtolower(trim($status));
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('Estado de reseña inválido.');
        }

        $stmt = $this->db->prepare('
            UPDATE "ProductReview"
            SET status = :status, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
            'status' => $status,
        ]);

        return $this->getById($id);
    }

    public function getById(string $id): ?array {
        $stmt = $this->db->prepare('
            SELECT *
            FROM "ProductReview"
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $this->getTenantId(),
        ]);
        $row = $stmt->fetch();

        return $row ? $this->normalizeRow($row) : null;
    }

    private function findEligibleOrderItem(string $productId, string $userId, string $orderId = '', string $orderItemId = ''): ?array {
        $where = [
            'o.tenant_id = :tenant_id',
            'o.user_id = :user_id',
            'oi.product_id = :product_id',
            'LOWER(COALESCE(o.status, \'\')) IN (\'completed\', \'delivered\')',
            'r.id IS NULL',
        ];
        $params = [
            'tenant_id' => $this->getTenantId(),
            'user_id' => $userId,
            'product_id' => $productId,
        ];

        if ($orderId !== '') {
            $where[] = 'o.id = :order_id';
            $params['order_id'] = $orderId;
        }
        if ($orderItemId !== '') {
            $where[] = 'oi.id = :order_item_id';
            $params['order_item_id'] = $orderItemId;
        }

        $stmt = $this->db->prepare('
            SELECT o.id AS order_id, oi.id AS order_item_id
            FROM "OrderItem" oi
            INNER JOIN "Order" o
              ON o.id = oi.order_id
             AND o.tenant_id = :tenant_id
            LEFT JOIN "ProductReview" r
              ON r.tenant_id = o.tenant_id
             AND r.order_item_id = oi.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY o.created_at DESC, oi.id DESC
            LIMIT 1
        ');
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function normalizeAdminRow(array $row): array {
        $normalized = $this->normalizeRow($row);
        $normalized['productName'] = $row['product_name'] ?? null;
        $normalized['productSlug'] = $row['product_slug'] ?? null;
        $normalized['productLegacyId'] = $row['product_legacy_id'] ?? null;
        $normalized['userEmail'] = $row['user_email'] ?? null;
        return $normalized;
    }

    private function normalizeRow(array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'productId' => (string)($row['product_id'] ?? ''),
            'orderId' => (string)($row['order_id'] ?? ''),
            'orderItemId' => (string)($row['order_item_id'] ?? ''),
            'userId' => (string)($row['user_id'] ?? ''),
            'rating' => max(1, min(5, (int)($row['rating'] ?? 0))),
            'title' => $row['title'] ?? null,
            'body' => (string)($row['body'] ?? ''),
            'authorName' => (string)($row['author_name'] ?? 'Cliente verificado'),
            'status' => (string)($row['status'] ?? 'pending'),
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    private function cleanText($value, int $maxLength): string {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$value));
        if ($text === '') {
            return '';
        }
        return mb_substr($text, 0, $maxLength);
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}
