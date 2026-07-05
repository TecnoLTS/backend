<?php

namespace App\Modules\LoyaltyRewards\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Modules\LoyaltyRewards\Infrastructure\LoyaltyRepository;

final class LoyaltyController {
    private LoyaltyRepository $repository;

    public function __construct() {
        $this->repository = new LoyaltyRepository();
    }

    public function dashboard(): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->dashboard(), 'LOYALTY_DASHBOARD_FAILED');
    }

    public function customers(): void {
        Auth::requireAdmin();
        $filters = [
            'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : null,
            'wallet' => isset($_GET['wallet']) ? trim((string)$_GET['wallet']) : 'all',
            'tier' => isset($_GET['tier']) ? trim((string)$_GET['tier']) : 'all',
            'status' => isset($_GET['status']) ? trim((string)$_GET['status']) : 'all',
            'sort' => isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'recent',
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 25,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0,
        ];
        $paged = isset($_GET['paged']) && in_array(strtolower((string)$_GET['paged']), ['1', 'true', 'yes'], true);
        $this->respond(fn() => $paged ? $this->repository->customersPage($filters) : $this->repository->customers($filters['q']), 'LOYALTY_CUSTOMERS_FAILED');
    }

    public function customerDetail(string $memberId): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->customerDetail($memberId), 'LOYALTY_CUSTOMER_DETAIL_FAILED');
    }

    public function rewards(): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->rewards(), 'LOYALTY_REWARDS_FAILED');
    }

    public function createReward(): void {
        Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(fn() => $this->repository->createReward($payload), 'LOYALTY_REWARD_CREATE_FAILED', 201);
    }

    public function registerPurchase(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->registerPurchase($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_PURCHASE_REGISTER_FAILED',
            201
        );
    }

    public function redeemReward(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->redeemReward($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_REDEMPTION_FAILED',
            201
        );
    }

    public function updateWallet(string $memberId): void {
        Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(fn() => $this->repository->updateWallet($memberId, $payload), 'LOYALTY_WALLET_UPDATE_FAILED');
    }

    public function passPreview(string $memberId): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->passPreview($memberId), 'LOYALTY_PASS_PREVIEW_FAILED');
    }

    public function googleWalletLink(): void {
        Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(fn() => $this->repository->googleWalletLinkPlan($payload), 'LOYALTY_GOOGLE_WALLET_LINK_FAILED');
    }

    private function respond(callable $callback, string $code, int $status = 200): void {
        try {
            Response::json($callback(), $status);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, $code);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 404, $code);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, $code);
        }
    }

    private function jsonPayload(): array {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('JSON invalido.');
        }

        return $decoded;
    }
}
