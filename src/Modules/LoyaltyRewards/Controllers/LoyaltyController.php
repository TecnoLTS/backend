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
        $month = isset($_GET['month']) ? trim((string)$_GET['month']) : null;
        $this->respond(fn() => $this->repository->dashboard($month), 'LOYALTY_DASHBOARD_FAILED');
    }

    public function customers(): void {
        Auth::requireAdmin();
        $filters = [
            'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : null,
            'wallet' => isset($_GET['wallet']) ? trim((string)$_GET['wallet']) : 'all',
            'tier' => isset($_GET['tier']) ? trim((string)$_GET['tier']) : 'all',
            'status' => isset($_GET['status']) ? trim((string)$_GET['status']) : 'all',
            'sort' => isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'recent',
            'count' => isset($_GET['count']) ? trim((string)$_GET['count']) : '1',
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

    public function publicGoogleWalletLanding(string $token): void {
        try {
            $landing = $this->repository->googleWalletQrLanding($token);
            Response::noStore();
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderWalletLandingPage($landing);
        } catch (\InvalidArgumentException $e) {
            $this->respondWalletLandingError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->respondWalletLandingError('No se pudo abrir la tarjeta digital.', 500);
        }
    }

    public function publicRewardsPortal(string $token): void {
        try {
            $portal = $this->repository->publicRewardsPortal($token);
            Response::noStore();
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderRewardsPortalPage($portal);
        } catch (\InvalidArgumentException $e) {
            $this->respondWalletLandingError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->respondWalletLandingError('No se pudo abrir el portal de premios.', 500);
        }
    }

    public function publicRewardsClaim(string $token): void {
        try {
            $payload = $this->requestPayload();
            $result = $this->repository->createPortalClaim($token, $payload);
            $portal = $this->repository->publicRewardsPortal($token);
            $portal['claimResult'] = $result;
            Response::noStore();
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderRewardsPortalPage($portal);
        } catch (\InvalidArgumentException $e) {
            $this->respondWalletLandingError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->respondWalletLandingError('No se pudo registrar la solicitud.', 500);
        }
    }

    public function publicRewardsCancel(string $token, string $redemptionId): void {
        try {
            $payload = $this->requestPayload();
            $result = $this->repository->cancelPortalClaim($token, $redemptionId, $payload);
            $portal = $this->repository->publicRewardsPortal($token);
            $portal['claimCancelled'] = $result;
            Response::noStore();
            header('Content-Type: text/html; charset=UTF-8');
            echo $this->renderRewardsPortalPage($portal);
        } catch (\InvalidArgumentException $e) {
            $this->respondWalletLandingError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->respondWalletLandingError('No se pudo cancelar la solicitud.', 500);
        }
    }

    public function redemptionClaims(): void {
        Auth::requireAdmin();
        $filters = [
            'status' => isset($_GET['status']) ? trim((string)$_GET['status']) : 'open',
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 50,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0,
        ];
        $this->respond(fn() => $this->repository->redemptionClaims($filters), 'LOYALTY_REDEMPTION_CLAIMS_FAILED');
    }

    public function approveRedemptionClaim(string $redemptionId): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->approveRedemptionClaim($redemptionId, $payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_REDEMPTION_CLAIM_APPROVE_FAILED'
        );
    }

    public function validateRedemptionClaimCode(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->validateInStoreClaimCode($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_REDEMPTION_CLAIM_CODE_FAILED'
        );
    }

    public function deliverRedemptionClaim(string $redemptionId): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->deliverRedemptionClaim($redemptionId, $payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_REDEMPTION_CLAIM_DELIVER_FAILED'
        );
    }

    public function cancelRedemptionClaim(string $redemptionId): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->cancelRedemptionClaim($redemptionId, $payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_REDEMPTION_CLAIM_CANCEL_FAILED'
        );
    }

    private function renderRewardsPortalPage(array $data): string {
        $token = rawurlencode((string)($data['token'] ?? ''));
        $portalPath = '/' . ltrim((string)($data['publicPath'] ?? "/api/l/r/{$token}"), '/');
        $program = $this->e((string)($data['program']['wallet_program_name'] ?? $data['program']['name'] ?? 'Fidepuntos'));
        $member = $data['member'] ?? [];
        $memberName = $this->e((string)($member['account_name'] ?? $member['name'] ?? 'Socio'));
        $accountId = $this->e((string)($member['account_id'] ?? ''));
        $points = number_format((int)($member['points'] ?? 0), 0, ',', '.');
        $result = $this->renderRewardsPortalResult($data);
        $rewards = array_map(fn(array $reward): string => $this->renderRewardsPortalReward($portalPath, $reward), $data['rewards'] ?? []);
        $claims = array_map(fn(array $claim): string => $this->renderRewardsPortalClaim($portalPath, $claim), $data['claims'] ?? []);
        $rewardList = implode('', $rewards) ?: '<div class="empty">No hay premios publicados para reclamar desde Wallet.</div>';
        $claimList = implode('', $claims) ?: '<div class="empty">Aun no tienes solicitudes recientes.</div>';
        $support = $data['support'] ?? [];
        $supportText = trim((string)($support['phone'] ?? '')) !== ''
            ? 'Soporte: ' . $this->e((string)$support['phone'])
            : (trim((string)($support['email'] ?? '')) !== '' ? 'Soporte: ' . $this->e((string)$support['email']) : 'Consulta al equipo del local para ayuda.');

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Premios {$program}</title>
<style>
:root{color-scheme:light;--bg:#f5f7fb;--surface:#fff;--ink:#10231f;--muted:#64746f;--line:#d9e5e1;--brand:#0f766e;--brand2:#115e59;--ok:#047857;--warn:#b45309;--danger:#b42318}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;line-height:1.5}
main{width:min(720px,100%);margin:0 auto;padding:18px 14px 32px}.hero{background:linear-gradient(135deg,#123d38,#0f766e);color:#fff;border-radius:18px;padding:22px;box-shadow:0 20px 40px rgba(15,118,110,.18)}
.eyebrow{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.78}.hero h1{margin:8px 0 10px;font-size:28px;line-height:1.08}.hero p{margin:0;color:rgba(255,255,255,.86)}
.balance{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}.balance div{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:12px;padding:12px}.balance span,.meta{display:block;font-size:12px;color:var(--muted);font-weight:700}.balance span{color:rgba(255,255,255,.72)}.balance strong{display:block;font-size:20px}
.notice{margin:14px 0 0;border:1px solid #b7dfd8;background:#ecfdf8;color:#134e4a;border-radius:14px;padding:14px}.notice strong{display:block}.notice--cancel{border-color:#fed7aa;background:#fff7ed;color:#7c2d12}
.section{margin-top:18px}.section h2{font-size:19px;margin:0 0 4px}.section>p{margin:0 0 12px;color:var(--muted)}.grid{display:grid;gap:12px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.06)}.card h3{margin:0;font-size:17px}.card p{margin:8px 0;color:var(--muted)}.row{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.price{font-size:18px;font-weight:850;color:var(--brand2);font-variant-numeric:tabular-nums;white-space:nowrap}
.badges{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0}.badge{border-radius:999px;border:1px solid var(--line);padding:5px 9px;font-size:12px;font-weight:800;color:#29443f;background:#f7fbf9}.badge.ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.badge.warn{border-color:#fed7aa;background:#fff7ed;color:#92400e}
form{display:grid;gap:10px;margin-top:12px}label{display:grid;gap:6px;font-weight:750;font-size:13px}textarea,input,select{width:100%;min-height:44px;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font:inherit;background:#fff;color:var(--ink)}textarea{min-height:84px;resize:vertical}
.choice{display:flex;gap:8px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:9px 10px}.choice input{width:auto;min-height:auto}
.btn{appearance:none;border:0;border-radius:11px;min-height:46px;padding:12px 14px;font-weight:850;font-size:15px;cursor:pointer;text-align:center}.btn-primary{background:var(--brand);color:#fff}.btn-secondary{background:#e7f4f1;color:#0f4f49}.btn-danger{background:#fff1f0;color:var(--danger);border:1px solid #fecdca}.btn[disabled]{opacity:.52;cursor:not-allowed}.block{font-size:13px;color:var(--danger);font-weight:750}.empty{border:1px dashed var(--line);border-radius:14px;padding:18px;color:var(--muted);background:#fff}
.claims .card{padding:14px}.status{font-weight:850}.status.pending_review{color:var(--warn)}.status.ready_for_pickup,.status.approved{color:var(--brand2)}.status.delivered{color:var(--ok)}.status.cancelled,.status.expired{color:var(--danger)}
footer{margin-top:22px;color:var(--muted);font-size:13px;text-align:center}@media(max-width:520px){main{padding-inline:10px}.hero{border-radius:0;margin:-18px -10px 0}.balance{grid-template-columns:1fr}.row{display:grid}.price{white-space:normal}.card{border-radius:12px}}
</style>
</head>
<body>
<main>
  <section class="hero">
    <span class="eyebrow">{$program}</span>
    <h1>Premios disponibles</h1>
    <p>Hola {$memberName}, reclama premios desde tu tarjeta Wallet y sigue tus solicitudes.</p>
    <div class="balance" aria-label="Resumen de cuenta">
      <div><span>Cuenta</span><strong>{$accountId}</strong></div>
      <div><span>Saldo</span><strong>{$points} pts</strong></div>
    </div>
  </section>
  {$result}
  <section class="section">
    <h2>Elegir premio</h2>
    <p>Los premios de local generan un codigo temporal; los premios gestionados quedan en revision.</p>
    <div class="grid">{$rewardList}</div>
  </section>
  <section class="section claims">
    <h2>Mis solicitudes</h2>
    <p>Consulta estados recientes o cancela reservas que aun no han sido atendidas.</p>
    <div class="grid">{$claimList}</div>
  </section>
  <footer>{$supportText}</footer>
</main>
</body>
</html>
HTML;
    }

    private function renderRewardsPortalResult(array $data): string {
        if (isset($data['claimResult']) && is_array($data['claimResult'])) {
            $redemption = $data['claimResult']['redemption'] ?? [];
            $reward = $this->e((string)($redemption['reward'] ?? 'Premio'));
            $code = trim((string)($data['claimResult']['claimCode'] ?? ''));
            if ($code !== '') {
                $safeCode = $this->e($code);
                return "<div class=\"notice\"><strong>Codigo para el local: {$safeCode}</strong><span>Muestralo al equipo antes de que expire. Premio: {$reward}.</span></div>";
            }

            return "<div class=\"notice\"><strong>Solicitud recibida</strong><span>{$reward} quedo reservado mientras el gestor revisa retiro o entrega.</span></div>";
        }
        if (isset($data['claimCancelled'])) {
            return '<div class="notice notice--cancel"><strong>Solicitud cancelada</strong><span>Los puntos y el stock reservado fueron devueltos.</span></div>';
        }

        return '';
    }

    private function renderRewardsPortalReward(string $portalPath, array $reward): string {
        $id = $this->e((string)$reward['id']);
        $name = $this->e((string)$reward['name']);
        $description = $this->e((string)($reward['description'] ?? 'Beneficio disponible.'));
        $points = number_format((int)($reward['points_cost'] ?? 0), 0, ',', '.');
        $stock = number_format((int)($reward['stock'] ?? 0), 0, ',', '.');
        $mode = (string)($reward['claim_mode'] ?? 'staff_only');
        $modeLabel = $this->claimModeLabel($mode);
        $instructions = trim((string)($reward['claim_instructions'] ?? ''));
        $instructionsHtml = $instructions !== '' ? '<p>' . $this->e($instructions) . '</p>' : '';
        $canClaim = (bool)($reward['canClaim'] ?? false);
        $blockReason = trim((string)($reward['blockReason'] ?? ''));
        $action = $this->e(rtrim($portalPath, '/') . '/claims');
        $form = '';
        if ($canClaim && $mode === 'in_store') {
            $form = <<<HTML
<form method="post" action="{$action}">
  <input type="hidden" name="rewardId" value="{$id}">
  <input type="hidden" name="fulfillmentType" value="in_store">
  <button class="btn btn-primary" type="submit">Estoy en el local</button>
</form>
HTML;
        } elseif ($canClaim && $mode === 'managed') {
            $options = $this->renderDeliveryOptions($reward['claim_delivery_options'] ?? []);
            $form = <<<HTML
<form method="post" action="{$action}">
  <input type="hidden" name="rewardId" value="{$id}">
  {$options}
  <label>Telefono de contacto<input name="contactPhone" inputmode="tel" autocomplete="tel"></label>
  <label>Notas para el gestor<textarea name="notes" maxlength="500" placeholder="Horario, referencia de retiro o direccion si aplica"></textarea></label>
  <button class="btn btn-primary" type="submit">Solicitar revision</button>
</form>
HTML;
        } else {
            $form = '<div class="block">' . $this->e($blockReason !== '' ? $blockReason : 'No disponible para reclamar ahora.') . '</div>';
        }

        return <<<HTML
<article class="card">
  <div class="row">
    <div>
      <h3>{$name}</h3>
      <p>{$description}</p>
    </div>
    <div class="price">{$points} pts</div>
  </div>
  {$instructionsHtml}
  <div class="badges">
    <span class="badge {$this->claimModeTone($mode)}">{$modeLabel}</span>
    <span class="badge">Stock {$stock}</span>
  </div>
  {$form}
</article>
HTML;
    }

    private function renderRewardsPortalClaim(string $portalPath, array $claim): string {
        $id = $this->e((string)$claim['id']);
        $reward = $this->e((string)($claim['reward'] ?? 'Premio'));
        $points = number_format((int)($claim['points_cost'] ?? 0), 0, ',', '.');
        $status = (string)($claim['status'] ?? '');
        $statusLabel = $this->claimStatusLabel($status);
        $fulfillment = $this->deliveryOptionLabel((string)($claim['fulfillment_type'] ?? ''));
        $created = $this->e((string)($claim['created_at'] ?? ''));
        $cancel = '';
        if (in_array($status, ['pending_review', 'ready_for_pickup'], true)) {
            $action = $this->e(rtrim($portalPath, '/') . '/claims/' . rawurlencode((string)$claim['id']) . '/cancel');
            $cancel = <<<HTML
<form method="post" action="{$action}">
  <input type="hidden" name="reason" value="Cancelado desde Wallet">
  <button class="btn btn-danger" type="submit">Cancelar solicitud</button>
</form>
HTML;
        }

        return <<<HTML
<article class="card">
  <div class="row">
    <div>
      <h3>{$reward}</h3>
      <span class="meta">{$created} · {$fulfillment}</span>
    </div>
    <div class="price">{$points} pts</div>
  </div>
  <div class="badges"><span class="badge"><span class="status {$this->e($status)}">{$statusLabel}</span></span></div>
  {$cancel}
</article>
HTML;
    }

    private function renderDeliveryOptions($options): string {
        $options = is_array($options) ? $options : [];
        if ($options === []) {
            $options = ['pickup'];
        }
        $html = '';
        foreach ($options as $index => $option) {
            $value = $this->e((string)$option);
            $label = $this->deliveryOptionLabel((string)$option);
            $checked = $index === 0 ? ' checked' : '';
            $html .= "<label class=\"choice\"><input type=\"radio\" name=\"fulfillmentType\" value=\"{$value}\"{$checked}> {$label}</label>";
        }

        return $html;
    }

    private function claimModeLabel(string $mode): string {
        return match ($mode) {
            'in_store' => 'Entrega en local',
            'managed' => 'Gestionado por el negocio',
            default => 'Solo gestor',
        };
    }

    private function claimModeTone(string $mode): string {
        return $mode === 'in_store' ? 'ok' : ($mode === 'managed' ? 'warn' : '');
    }

    private function claimStatusLabel(string $status): string {
        return match ($status) {
            'pending_review' => 'Pendiente de revision',
            'ready_for_pickup' => 'Codigo listo',
            'approved' => 'Aprobado',
            'delivered' => 'Entregado',
            'cancelled' => 'Cancelado',
            'expired' => 'Expirado',
            default => 'En proceso',
        };
    }

    private function deliveryOptionLabel(string $option): string {
        return match ($option) {
            'in_store' => 'En local',
            'delivery' => 'Entrega coordinada',
            'pickup' => 'Retiro coordinado',
            default => 'Por coordinar',
        };
    }

    /** @param array{saveUrl: string, portalUrl?: string, memberName: string, accountId: string, points: int, programName: string} $data */
    private function renderWalletLandingPage(array $data): string {
        $program = htmlspecialchars((string)$data['programName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $member = htmlspecialchars((string)$data['memberName'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $account = htmlspecialchars((string)$data['accountId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $points = number_format((int)$data['points'], 0, ',', '.');
        $url = htmlspecialchars((string)$data['saveUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $portalUrl = htmlspecialchars((string)($data['portalUrl'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $portalButton = $portalUrl !== ''
            ? '<a href="' . $portalUrl . '" style="display:block;text-align:center;background:#e7f4f1;color:#0f4f49;text-decoration:none;font-weight:800;font-size:16px;padding:14px 22px;border-radius:12px;margin-top:12px;">Ver premios disponibles</a>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Agregar tarjeta a Google Wallet</title>
</head>
<body style="margin:0;padding:0;background:#f4f7f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#142724;">
  <div style="max-width:460px;margin:0 auto;padding:28px 16px;">
    <div style="background:#ffffff;border:1px solid #dce9e4;border-radius:16px;overflow:hidden;">
      <div style="background:#173d39;color:#ffffff;padding:24px;">
        <div style="font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.78;">{$program}</div>
        <h1 style="margin:8px 0 0;font-size:22px;line-height:1.25;">Tu tarjeta de recompensas esta lista</h1>
      </div>
      <div style="padding:24px;">
        <p style="margin:0 0 18px;font-size:16px;line-height:1.5;">Hola {$member}, agrega tu tarjeta a Google Wallet para ver y usar tus puntos desde el telefono.</p>
        <div style="display:flex;gap:12px;margin:0 0 22px;">
          <div style="flex:1;background:#f7fbf9;border:1px solid #dce9e4;border-radius:10px;padding:12px;">
            <div style="font-size:12px;color:#506a65;font-weight:700;">Cuenta</div>
            <div style="font-size:17px;font-weight:800;">{$account}</div>
          </div>
          <div style="flex:1;background:#f7fbf9;border:1px solid #dce9e4;border-radius:10px;padding:12px;">
            <div style="font-size:12px;color:#506a65;font-weight:700;">Saldo actual</div>
            <div style="font-size:17px;font-weight:800;">{$points} pts</div>
          </div>
        </div>
        <a href="{$url}" style="display:block;text-align:center;background:#0f766e;color:#ffffff;text-decoration:none;font-weight:800;font-size:16px;padding:16px 22px;border-radius:12px;">Agregar a Google Wallet</a>
        {$portalButton}
        <p style="margin:16px 0 0;text-align:center;color:#506a65;font-size:13px;line-height:1.5;">Si no abre, toca el boton desde Chrome en tu telefono Android.</p>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
    }

    private function respondWalletLandingError(string $message, int $status): void {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        Response::noStore();
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tarjeta no disponible</title></head>
<body style="margin:0;padding:0;background:#f4f7f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#142724;">
  <div style="max-width:460px;margin:0 auto;padding:40px 16px;text-align:center;">
    <div style="background:#ffffff;border:1px solid #dce9e4;border-radius:16px;padding:32px 24px;">
      <h1 style="margin:0 0 10px;font-size:20px;">No pudimos abrir la tarjeta</h1>
      <p style="margin:0;color:#506a65;font-size:15px;line-height:1.5;">{$safe}</p>
    </div>
  </div>
</body>
</html>
HTML;
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

    public function notificationsPreview(): void {
        Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(fn() => $this->repository->previewNotificationAudience($payload), 'LOYALTY_NOTIFICATION_PREVIEW_FAILED');
    }

    public function createNotificationCampaign(): void {
        $user = Auth::requireAdmin();
        $payload = $this->jsonPayload();
        $this->respond(
            fn() => $this->repository->createNotificationCampaign($payload, is_string($user['sub'] ?? null) ? $user['sub'] : null),
            'LOYALTY_NOTIFICATION_CREATE_FAILED',
            201
        );
    }

    public function notifications(): void {
        Auth::requireAdmin();
        $filters = [
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 25,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0,
        ];
        $this->respond(fn() => $this->repository->listNotificationCampaigns($filters), 'LOYALTY_NOTIFICATIONS_FAILED');
    }

    public function notificationDetail(string $campaignId): void {
        Auth::requireAdmin();
        $this->respond(fn() => $this->repository->getNotificationCampaign($campaignId), 'LOYALTY_NOTIFICATION_DETAIL_FAILED');
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

    private function requestPayload(): array {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            return $this->jsonPayload();
        }
        if ($_POST !== []) {
            return array_map(static fn($value) => is_string($value) ? trim($value) : $value, $_POST);
        }

        return $this->jsonPayload();
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

    private function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
