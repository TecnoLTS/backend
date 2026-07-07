<?php

declare(strict_types=1);

namespace App\Modules\LoyaltyRewards\Infrastructure;

use App\Modules\LoyaltyRewards\Infrastructure\Wallet\GoogleWalletService;
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
    public function sendRecipient(GoogleWalletService $service, array $recipient, string $header, string $body): string {
        $attempts = (int)$recipient['attempts'] + 1;
        try {
            $result = $service->addMessage((string)$recipient['account_id'], $header, $body);
            $this->updateRecipient((string)$recipient['id'], 'sent', $attempts, $result['messageId'], null);
            $this->bumpCampaign((string)$recipient['campaign_id'], 'sent');
            return 'sent';
        } catch (\RuntimeException $e) {
            // addMessage lanza RuntimeException cuando el pase no existe en Google (404).
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
    public function drainCampaign(string $campaignId, GoogleWalletService $service, int $throttleMs = 0): array {
        $campaign = $this->campaignRow($campaignId);
        if ($campaign === null) {
            return ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        }
        $this->markProcessing($campaignId);

        $stmt = $this->pdo->prepare(
            "SELECT id, campaign_id, member_id, account_id, attempts
             FROM loyalty_wallet_campaign_recipients
             WHERE campaign_id = :cid AND status = 'pending'
             ORDER BY id ASC"
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
