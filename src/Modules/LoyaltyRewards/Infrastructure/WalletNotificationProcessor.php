<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Infrastructure;

use App\Modules\LoyaltyRewards\Infrastructure\Wallet\WalletMessenger;
use PDO;

/**
 * Procesa el envio de notificaciones a los destinatarios de una campaña.
 * Reusado por LoyaltyRepository (envio inline individual) y por el worker CLI
 * (drenado masivo). Agnostico de TenantContext: recibe el PDO y el servicio.
 */
final class WalletNotificationProcessor {
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Envia a un destinatario y persiste el resultado. Devuelve el status final.
     * 404 de Google (pase no guardado) -> 'skipped'. Error recuperable -> 'failed'
     * al agotar intentos, si no se deja 'pending' para reintento.
     */
    public function sendRecipient(WalletMessenger $service, array $recipient, string $header, string $body): string {
        $attempts = (int)$recipient['attempts'] + 1;
        try {
            $objectId = trim((string)($recipient['object_id'] ?? ''));
            $result = $objectId !== '' && method_exists($service, 'addMessageToObject')
                ? $service->addMessageToObject($objectId, $header, $body)
                : $service->addMessage((string)$recipient['account_id'], $header, $body);
            $this->updateRecipient((string)$recipient['id'], 'sent', $attempts, $result['messageId'], null);
            $this->bumpCampaign((string)$recipient['campaign_id'], 'sent');
            return 'sent';
        } catch (\RuntimeException $e) {
            // addMessage lanza RuntimeException cuando no hay pase, usuarios guardados o push real.
            $this->updateRecipient((string)$recipient['id'], 'skipped', $attempts, null, mb_substr($e->getMessage(), 0, 500));
            $this->bumpCampaign((string)$recipient['campaign_id'], 'skipped');
            return 'skipped';
        } catch (\Throwable $e) {
            $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $this->updateRecipient((string)$recipient['id'], $status, $attempts, null, mb_substr($e->getMessage(), 0, 500));
            if ($status === 'failed') {
                $this->bumpCampaign((string)$recipient['campaign_id'], 'failed');
            }
            return $status;
        }
    }

    /** Drena los destinatarios pendientes de UNA campaña con un servicio ya resuelto. */
    public function drainCampaign(string $campaignId, WalletMessenger $service, int $throttleMs = 0): array {
        $campaign = $this->campaignRow($campaignId);
        if ($campaign === null) {
            return ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        }
        $this->markProcessing($campaignId);

        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.campaign_id, r.member_id, r.account_id, r.attempts,
                    p.external_object_id AS object_id
             FROM loyalty_wallet_campaign_recipients r
             LEFT JOIN loyalty_wallet_passes p
               ON p.tenant_id = r.tenant_id
              AND p.member_id = r.member_id
              AND p.platform = 'google'
             WHERE r.campaign_id = :cid AND r.status = 'pending'
             ORDER BY r.id ASC"
        );
        $stmt->execute(['cid' => $campaignId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tally = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $status = $this->sendRecipient($service, $row, (string)$campaign['title'], (string)$campaign['body']);
            $tally['processed']++;
            if (isset($tally[$status])) {
                $tally[$status]++;
            }
            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
        }

        $this->closeIfDone($campaignId);
        return $tally;
    }

    /**
     * Drena destinatarios pendientes de todos los tenants (o uno) resolviendo el
     * servicio por tenant con el resolver dado. Usado por el worker CLI.
     *
     * @param callable(string):(?WalletMessenger) $serviceResolver
     * @return array{processed:int,sent:int,skipped:int,failed:int}
     */
    public function drainPending(int $limit, ?string $tenantId, callable $serviceResolver, int $throttleMs = 0): array {
        $sql = "SELECT DISTINCT campaign_id, tenant_id
                FROM loyalty_wallet_campaign_recipients
                WHERE status = 'pending'" . ($tenantId ? ' AND tenant_id = :tenant_id' : '') .
                ' ORDER BY campaign_id ASC LIMIT ' . max(1, $limit);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($tenantId ? ['tenant_id' => $tenantId] : []);
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tally = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        $servicesByTenant = [];
        foreach ($campaigns as $c) {
            $t = (string)$c['tenant_id'];
            if (!array_key_exists($t, $servicesByTenant)) {
                $servicesByTenant[$t] = $serviceResolver($t);
            }
            $service = $servicesByTenant[$t];
            if ($service === null) {
                continue;
            }
            $part = $this->drainCampaign((string)$c['campaign_id'], $service, $throttleMs);
            foreach ($tally as $k => $_) {
                $tally[$k] += $part[$k];
            }
        }
        return $tally;
    }

    private function updateRecipient(string $id, string $status, int $attempts, ?string $messageId, ?string $error): void {
        $stmt = $this->pdo->prepare(
            'UPDATE loyalty_wallet_campaign_recipients
             SET status = :status, attempts = :attempts, message_id = :message_id, last_error = :last_error, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status, 'attempts' => $attempts, 'message_id' => $messageId,
            'last_error' => $error, 'id' => $id,
        ]);
    }

    private function bumpCampaign(string $campaignId, string $kind): void {
        $column = ['sent' => 'sent_count', 'skipped' => 'skipped_count', 'failed' => 'failed_count'][$kind] ?? null;
        if ($column === null) {
            return;
        }
        $this->pdo->prepare(
            "UPDATE loyalty_wallet_campaigns SET {$column} = {$column} + 1 WHERE id = :id"
        )->execute(['id' => $campaignId]);
    }

    private function markProcessing(string $campaignId): void {
        $this->pdo->prepare(
            "UPDATE loyalty_wallet_campaigns
             SET status = 'processing', started_at = COALESCE(started_at, NOW())
             WHERE id = :id AND status = 'pending'"
        )->execute(['id' => $campaignId]);
    }

    /** Cierra la campaña si no quedan destinatarios pendientes. */
    private function closeIfDone(string $campaignId): void {
        $pending = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_wallet_campaign_recipients WHERE campaign_id = :id AND status = 'pending'",
            ['id' => $campaignId]
        );
        if ($pending > 0) {
            return;
        }
        $failed = (int)$this->scalar('SELECT failed_count FROM loyalty_wallet_campaigns WHERE id = :id', ['id' => $campaignId]);
        $status = $failed > 0 ? 'completed_with_errors' : 'completed';
        $this->pdo->prepare(
            'UPDATE loyalty_wallet_campaigns SET status = :status, finished_at = NOW() WHERE id = :id'
        )->execute(['status' => $status, 'id' => $campaignId]);
    }

    private function campaignRow(string $campaignId): ?array {
        $stmt = $this->pdo->prepare('SELECT id, title, body FROM loyalty_wallet_campaigns WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function scalar(string $sql, array $params): mixed {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
