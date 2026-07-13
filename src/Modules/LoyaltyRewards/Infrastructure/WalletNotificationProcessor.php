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
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Envia un destinatario previamente reclamado como `sending` y persiste un
     * resultado terminal. Una vez iniciada la llamada nunca vuelve a `pending`:
     * un resultado ambiguo queda `delivery_unknown` para respetar at-most-once.
     */
    public function sendRecipient(WalletMessenger $service, array $recipient, string $header, string $body): string {
        $attempts = max(1, (int)$recipient['attempts']);
        $status = 'sent';
        $messageId = null;
        $error = null;
        try {
            $objectId = trim((string)($recipient['object_id'] ?? ''));
            $result = $objectId !== '' && method_exists($service, 'addMessageToObject')
                ? $service->addMessageToObject($objectId, $header, $body)
                : $service->addMessage((string)$recipient['account_id'], $header, $body);
            $messageId = $result['messageId'] ?? null;
        } catch (\RuntimeException $e) {
            $status = $this->isKnownPreSendSkip($e) ? 'skipped' : 'delivery_unknown';
            $error = mb_substr($e->getMessage(), 0, 500);
        } catch (\Throwable $e) {
            $status = 'delivery_unknown';
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        return $this->persistRecipientResult(
            (string)$recipient['id'],
            (string)$recipient['campaign_id'],
            $status,
            $attempts,
            $messageId,
            $error
        );
    }

    /** Drena los destinatarios pendientes de UNA campaña con un servicio ya resuelto. */
    public function drainCampaign(string $campaignId, WalletMessenger $service, int $throttleMs = 0): array {
        $campaign = $this->campaignRow($campaignId);
        if ($campaign === null) {
            return ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'delivery_unknown' => 0];
        }
        $this->markProcessing($campaignId);

        $this->recoverStaleClaims($campaignId);
        $tally = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'delivery_unknown' => 0];
        while (($row = $this->claimRecipient($campaignId)) !== null) {
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
     * @return array{processed:int,sent:int,skipped:int,failed:int,delivery_unknown:int}
     */
    public function drainPending(int $limit, ?string $tenantId, callable $serviceResolver, int $throttleMs = 0): array {
        $sql = "SELECT DISTINCT campaign_id, tenant_id
                FROM loyalty_wallet_campaign_recipients
                WHERE (status = 'pending' OR (status = 'sending' AND updated_at < NOW() - INTERVAL '15 minutes'))" . ($tenantId ? ' AND tenant_id = :tenant_id' : '') .
                ' ORDER BY campaign_id ASC LIMIT ' . max(1, $limit);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($tenantId ? ['tenant_id' => $tenantId] : []);
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tally = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'delivery_unknown' => 0];
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

    /**
     * Persiste el resultado y su contador como una sola transaccion. Si otro
     * worker ya convirtio el claim (por ejemplo, stale -> delivery_unknown),
     * conserva ese estado terminal y no vuelve a incrementar la campana.
     */
    private function persistRecipientResult(
        string $id,
        string $campaignId,
        string $status,
        int $attempts,
        ?string $messageId,
        ?string $error
    ): string {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $updated = $this->updateRecipient($id, $status, $attempts, $messageId, $error);
            if ($updated) {
                $this->bumpCampaign($campaignId, $status);
            } else {
                $status = $this->recipientStatus($id) ?? 'delivery_unknown';
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $status;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function updateRecipient(string $id, string $status, int $attempts, ?string $messageId, ?string $error): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE loyalty_wallet_campaign_recipients
             SET status = :status, attempts = :attempts, message_id = :message_id, last_error = :last_error, updated_at = NOW()
             WHERE id = :id AND status = \'sending\''
        );
        $stmt->execute([
            'status' => $status, 'attempts' => $attempts, 'message_id' => $messageId,
            'last_error' => $error, 'id' => $id,
        ]);

        return $stmt->rowCount() === 1;
    }

    private function recipientStatus(string $id): ?string {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM loyalty_wallet_campaign_recipients WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $status = $stmt->fetchColumn();

        return is_string($status) && $status !== '' ? $status : null;
    }

    private function bumpCampaign(string $campaignId, string $kind): void {
        $column = [
            'sent' => 'sent_count',
            'skipped' => 'skipped_count',
            'failed' => 'failed_count',
            'delivery_unknown' => 'delivery_unknown_count',
        ][$kind] ?? null;
        if ($column === null) {
            return;
        }
        $extra = $kind === 'delivery_unknown' ? ', failed_count = failed_count + 1' : '';
        $this->pdo->prepare(
            "UPDATE loyalty_wallet_campaigns SET {$column} = {$column} + 1{$extra} WHERE id = :id"
        )->execute(['id' => $campaignId]);
    }

    private function markProcessing(string $campaignId): void {
        $this->pdo->prepare(
            "UPDATE loyalty_wallet_campaigns
             SET status = 'processing', started_at = COALESCE(started_at, NOW())
             WHERE id = :id AND status = 'pending'"
        )->execute(['id' => $campaignId]);
    }

    /** Cierra la campaña si no quedan destinatarios pendientes ni en envio. */
    private function closeIfDone(string $campaignId): void {
        $pending = (int)$this->scalar(
            "SELECT COUNT(*) FROM loyalty_wallet_campaign_recipients WHERE campaign_id = :id AND status IN ('pending', 'sending')",
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

    private function claimRecipient(string $campaignId): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.id, r.campaign_id, r.member_id, r.account_id, r.attempts,
                        p.external_object_id AS object_id
                 FROM loyalty_wallet_campaign_recipients r
                 LEFT JOIN loyalty_wallet_passes p
                   ON p.tenant_id = r.tenant_id
                  AND p.member_id = r.member_id
                  AND p.platform = 'google'
                 WHERE r.campaign_id = :cid AND r.status = 'pending'
                 ORDER BY r.id ASC
                 FOR UPDATE OF r SKIP LOCKED
                 LIMIT 1"
            );
            $stmt->execute(['cid' => $campaignId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->pdo->commit();
                return null;
            }
            $attempts = (int)$row['attempts'] + 1;
            $updated = $this->pdo->prepare(
                "UPDATE loyalty_wallet_campaign_recipients
                 SET status = 'sending', attempts = :attempts, updated_at = NOW()
                 WHERE id = :id AND status = 'pending'"
            );
            $updated->execute(['attempts' => $attempts, 'id' => (string)$row['id']]);
            if ($updated->rowCount() !== 1) {
                throw new \RuntimeException('No se pudo reclamar el destinatario Wallet.');
            }
            $this->pdo->commit();
            $row['attempts'] = $attempts;
            $row['status'] = 'sending';

            return $row;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function recoverStaleClaims(string $campaignId): void
    {
        $stmt = $this->pdo->prepare(
            "WITH stale AS (
                UPDATE loyalty_wallet_campaign_recipients
                SET status = 'delivery_unknown',
                    last_error = COALESCE(last_error, 'Envio interrumpido despues de iniciar; no se reintentara automaticamente.'),
                    updated_at = NOW()
                WHERE campaign_id = :campaign_id
                  AND status = 'sending'
                  AND updated_at < NOW() - INTERVAL '15 minutes'
                RETURNING campaign_id
             ), tally AS (
                SELECT campaign_id, COUNT(*)::integer AS total FROM stale GROUP BY campaign_id
             )
             UPDATE loyalty_wallet_campaigns c
             SET delivery_unknown_count = c.delivery_unknown_count + tally.total,
                 failed_count = c.failed_count + tally.total
             FROM tally
             WHERE c.id = tally.campaign_id"
        );
        $stmt->execute(['campaign_id' => $campaignId]);
    }

    private function isKnownPreSendSkip(\RuntimeException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'no existe')
            || str_contains($message, 'ningun telefono')
            || str_contains($message, 'ningún teléfono')
            || str_contains($message, 'debe agregar primero');
    }

    private function scalar(string $sql, array $params): mixed {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
