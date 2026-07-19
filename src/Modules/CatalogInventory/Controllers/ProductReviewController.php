<?php

namespace App\Modules\CatalogInventory\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Http\Pagination\BoundedPageRequest;
use App\Http\Pagination\CreatedAtIdCursor;
use App\Repositories\ProductRepository;
use App\Repositories\ProductReviewRepository;

class ProductReviewController {
    private ProductRepository $productRepository;
    private ProductReviewRepository $reviewRepository;

    public function __construct() {
        $this->productRepository = new ProductRepository();
        $this->reviewRepository = new ProductReviewRepository();
    }

    public function indexForProduct(string $id): void {
        try {
            $product = $this->productRepository->getById($id, ['includeOutOfStock' => true]);
            if (!$product) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }

            $productId = (string)($product['id'] ?? '');
            $pageSize = BoundedPageRequest::pageSize($_GET, 20, 100);
            $cursor = BoundedPageRequest::cursor($_GET, 'resenas');
            $page = $this->reviewRepository->approvedPageForProduct($productId, [
                'limit' => $pageSize,
                'cursor' => $cursor,
            ]);
            $nextCursor = $page['nextPosition'] !== null
                ? CreatedAtIdCursor::encode($page['nextPosition'])
                : null;
            $summary = $this->reviewRepository->getApprovedSummary($productId);

            Response::json([
                'summary' => $summary,
                'reviews' => $page['items'],
            ], 200, [
                'pagination' => [
                    'pageSize' => $pageSize,
                    'hasMore' => $nextCursor !== null,
                    'nextCursor' => $nextCursor,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_REVIEWS_PAGINATION_INVALID');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_REVIEWS_FETCH_FAILED');
        }
    }

    public function storeForProduct(string $id): void {
        $user = Auth::requireUser();
        try {
            $product = $this->productRepository->getById($id, ['includeOutOfStock' => true]);
            if (!$product) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }

            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $created = $this->reviewRepository->createVerified((string)$product['id'], $user, $data);
            Response::json($created, 201, null, 'Reseña recibida. Será visible cuando sea aprobada.');
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_REVIEW_INVALID');
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 403, 'PRODUCT_REVIEW_NOT_ELIGIBLE');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_REVIEW_CREATE_FAILED');
        }
    }

    public function adminIndex(): void {
        Auth::requireAdmin();
        try {
            Response::json($this->reviewRepository->listAdmin([
                'status' => $_GET['status'] ?? '',
                'productId' => $_GET['productId'] ?? ($_GET['product_id'] ?? ''),
                'limit' => $_GET['limit'] ?? 100,
            ]));
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'ADMIN_REVIEWS_FETCH_FAILED');
        }
    }

    public function adminUpdate(string $id): void {
        Auth::requireAdmin();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $status = (string)($data['status'] ?? '');
            $updated = $this->reviewRepository->updateStatus($id, $status);
            if (!$updated) {
                Response::error('Reseña no encontrada', 404, 'PRODUCT_REVIEW_NOT_FOUND');
                return;
            }
            Response::json($updated);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_REVIEW_STATUS_INVALID');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'ADMIN_REVIEW_UPDATE_FAILED');
        }
    }
}
