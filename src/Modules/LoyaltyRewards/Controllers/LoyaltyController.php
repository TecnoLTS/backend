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

    public function createMember(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->createMember($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_MEMBER_CREATE_FAILED',
            201
        );
    }

    public function updateMember(string $memberId): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->updateMember($memberId, $payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_MEMBER_UPDATE_FAILED'
        );
    }

    public function rewards(): void {
        Auth::requireAdmin();
        $filters = [
            'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : null,
            'status' => isset($_GET['status']) ? trim((string)$_GET['status']) : 'all',
            'stock' => isset($_GET['stock']) ? trim((string)$_GET['stock']) : 'all',
        ];
        $this->respond(fn() => $this->repository->rewards($filters), 'LOYALTY_REWARDS_FAILED');
    }

    public function createReward(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->createReward($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_REWARD_CREATE_FAILED',
            201
        );
    }

    public function rewardDetail(string $rewardId): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->rewardDetail($rewardId), 'LOYALTY_REWARD_DETAIL_FAILED');
    }

    public function updateReward(string $rewardId): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->updateReward($rewardId, $payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_REWARD_UPDATE_FAILED'
        );
    }

    public function deleteReward(string $rewardId): void {
        $user = Auth::requireAdmin();
        $this->respond(
            fn() => $this->repository->deleteReward($rewardId, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_REWARD_DELETE_FAILED'
        );
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

    public function reversePurchase(string $reference): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->reversePurchase($reference, $payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_PURCHASE_REVERSE_FAILED',
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
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->googleWalletLink($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_GOOGLE_WALLET_LINK_FAILED'
        );
    }

    public function googleWalletNotify(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->googleWalletNotify($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_WALLET_NOTIFY_FAILED'
        );
    }

    public function externalGoogleWalletLink(string $accountId): void {
        $client = $this->externalClient('wallet:link');
        $this->respond(
            fn() => $this->repository->googleWalletLinkForAccount($accountId, $client),
            'LOYALTY_EXTERNAL_WALLET_LINK_FAILED'
        );
    }

    public function settings(): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->settings(), 'LOYALTY_SETTINGS_FAILED');
    }

    public function updateSettings(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->updateSettings($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_SETTINGS_UPDATE_FAILED'
        );
    }

    public function rules(): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->rules(), 'LOYALTY_RULES_FAILED');
    }

    public function updateRules(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->updateRules($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_RULES_UPDATE_FAILED'
        );
    }

    public function adjustPoints(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->adjustPoints($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_POINTS_ADJUST_FAILED',
            201
        );
    }

    public function reportsCatalog(): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->reportsCatalog(), 'LOYALTY_REPORTS_CATALOG_FAILED');
    }

    public function report(string $reportKey): void {
        Auth::requireAdmin();
        $filters = $this->queryFilters();
        $this->respond(fn() => $this->repository->report($reportKey, $filters), 'LOYALTY_REPORT_FAILED');
    }

    public function exportReport(string $reportKey): void {
        Auth::requireAdmin();
        try {
            $filters = $this->queryFilters();
            $format = strtolower((string)($filters['format'] ?? 'xlsx'));
            unset($filters['format']);

            if ($format === 'csv') {
                $export = $this->repository->reportCsv($reportKey, $filters);
                $contentType = 'text/csv; charset=utf-8';
            } else {
                $export = $this->repository->reportExcel($reportKey, $filters);
                $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }

            $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string)($export['filename'] ?? 'fidepuntos-reporte.xlsx'));
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo (string)($export['content'] ?? '');
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422, 'LOYALTY_REPORT_EXPORT_FAILED');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500, 'LOYALTY_REPORT_EXPORT_FAILED');
        }
    }

    public function auditEvents(): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->auditEvents($this->queryFilters()), 'LOYALTY_AUDIT_FAILED');
    }

    public function riskEvents(): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->riskEvents($this->queryFilters()), 'LOYALTY_RISK_FAILED');
    }

    public function resolveRiskEvent(string $eventId): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->resolveRiskEvent($eventId, $payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_RISK_RESOLVE_FAILED'
        );
    }

    public function apiClients(): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->apiClients(), 'LOYALTY_API_CLIENTS_FAILED');
    }

    public function createApiClient(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->createApiClient($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_API_CLIENT_CREATE_FAILED',
            201
        );
    }

    public function updateApiClient(string $clientId): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->updateApiClient($clientId, $payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_API_CLIENT_UPDATE_FAILED'
        );
    }

    public function revokeApiClient(string $clientId): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->revokeApiClient($clientId, $payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_API_CLIENT_REVOKE_FAILED'
        );
    }

    public function externalHealth(): void {
        Response::json(['status' => 'ok', 'module' => 'loyalty-rewards']);
    }

    public function externalProgram(): void {
        $this->externalClient('program:read');
        $this->respond(fn() => $this->repository->externalProgram(), 'LOYALTY_EXTERNAL_PROGRAM_FAILED');
    }

    public function externalRewards(): void {
        $this->externalClient('rewards:read');
        $this->respond(fn() => $this->repository->rewards(), 'LOYALTY_EXTERNAL_REWARDS_FAILED');
    }

    public function externalMember(string $accountId): void {
        $this->externalClient('members:read');
        $this->respond(fn() => $this->repository->customersPage(['q' => $accountId, 'limit' => 10, 'offset' => 0]), 'LOYALTY_EXTERNAL_MEMBER_FAILED');
    }

    public function externalMemberUpsert(): void {
        $client = $this->externalClient('members:write');
        $payload = $this->jsonPayload();
        $idempotencyKey = $this->header('Idempotency-Key');
        $result = $this->repository->idempotentExternalMutation(
            'members.upsert',
            $idempotencyKey,
            $payload,
            fn() => $this->repository->upsertExternalMember($payload, $client)
        );
        Response::json($result['payload'], $result['status']);
    }

    public function externalPurchase(): void {
        $client = $this->externalClient('purchases:write');
        $payload = $this->jsonPayload();
        $idempotencyKey = $this->header('Idempotency-Key');
        $result = $this->repository->idempotentExternalMutation(
            'purchases.create',
            $idempotencyKey,
            $payload,
            fn() => $this->repository->registerPurchase($payload, 'api:' . ($client['id'] ?? 'external'))
        );
        Response::json($result['payload'], $result['status']);
    }

    public function externalPurchaseReverse(string $reference): void {
        $client = $this->externalClient('purchases:reverse');
        $payload = $this->jsonPayload();
        $idempotencyKey = $this->header('Idempotency-Key');
        $result = $this->repository->idempotentExternalMutation(
            'purchases.reverse.' . $reference,
            $idempotencyKey,
            $payload,
            fn() => $this->repository->reversePurchase($reference, $payload, 'api:' . ($client['id'] ?? 'external'))
        );
        Response::json($result['payload'], $result['status']);
    }

    public function externalRedemption(): void {
        $client = $this->externalClient('redemptions:write');
        $payload = $this->jsonPayload();
        $idempotencyKey = $this->header('Idempotency-Key');
        $result = $this->repository->idempotentExternalMutation(
            'redemptions.create',
            $idempotencyKey,
            $payload,
            fn() => $this->repository->redeemReward($payload, 'api:' . ($client['id'] ?? 'external'))
        );
        Response::json($result['payload'], $result['status']);
    }

    public function externalReport(string $reportKey): void {
        $this->externalClient('reports:read');
        $this->respond(fn() => $this->repository->report($reportKey, $this->queryFilters()), 'LOYALTY_EXTERNAL_REPORT_FAILED');
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

    private function queryFilters(): array {
        return array_map(static fn($value) => is_string($value) ? trim($value) : $value, $_GET);
    }

    private function externalClient(string $scope): array {
        $rawKey = $this->header('X-API-Key');
        if ($rawKey === '') {
            $authorization = $this->header('Authorization');
            if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
                $rawKey = trim($matches[1]);
            }
        }

        return $this->repository->authenticateExternalClient($rawKey, $scope);
    }

    private function header(string $name): string {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
            return trim($_SERVER[$serverKey]);
        }
        if (strcasecmp($name, 'Authorization') === 0 && isset($_SERVER['Authorization']) && is_string($_SERVER['Authorization'])) {
            return trim($_SERVER['Authorization']);
        }

        return '';
    }
}
