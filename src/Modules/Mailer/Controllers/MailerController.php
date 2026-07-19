<?php

namespace App\Modules\Mailer\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\TenantContext;
use App\Modules\Mailer\Infrastructure\Persistence\EmailOutboxRepository;

final class MailerController
{
    public function health(): void
    {
        Auth::requireAdmin();

        try {
            $repository = new EmailOutboxRepository();
            $repository->assertReady();
            $stats = $repository->stats($this->tenantId());
            Response::json([
                'status' => 'healthy',
                'operational_status' => (int)($stats['dead_letter'] ?? 0) > $this->deadLetterDegradationThreshold()
                    ? 'degraded'
                    : 'normal',
                'module' => 'mailer-service',
                'database' => 'dashboard',
                'delivery' => 'durable-outbox',
                'stats' => $stats,
            ]);
        } catch (\Throwable) {
            Response::error(
                'El módulo de correo no puede acceder a dashboard.',
                503,
                'MAILER_SERVICE_UNAVAILABLE'
            );
        }
    }

    public function outbox(): void
    {
        Auth::requireAdmin();

        try {
            $status = strtolower(trim((string)($_GET['status'] ?? '')));
            if (!in_array($status, ['', 'pending', 'retry', 'processing', 'sent', 'dead_letter'], true)) {
                Response::error('Estado de correo inválido.', 422, 'MAILER_STATUS_INVALID');
                return;
            }

            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $repository = new EmailOutboxRepository();
            Response::json([
                'items' => $repository->listOutbox($limit, $status !== '' ? $status : null),
                'stats' => $repository->stats($this->tenantId()),
            ]);
        } catch (\Throwable) {
            Response::error('No se pudo consultar la bandeja de correo.', 500, 'MAILER_OUTBOX_FAILED');
        }
    }

    public function deliveryLog(): void
    {
        Auth::requireAdmin();

        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $repository = new EmailOutboxRepository();
            Response::json([
                'items' => $repository->listDeliveryLog($limit),
            ]);
        } catch (\Throwable) {
            Response::error('No se pudo consultar el historial de entregas.', 500, 'MAILER_DELIVERY_LOG_FAILED');
        }
    }

    private function tenantId(): string
    {
        $tenantId = trim((string)(TenantContext::id() ?? ''));
        if ($tenantId === '') {
            throw new \LogicException('Mailer admin surface requires a tenant.');
        }

        return $tenantId;
    }

    private function deadLetterDegradationThreshold(): int
    {
        $raw = $_ENV['MAILER_OUTBOX_HEALTH_MAX_DEAD_LETTER']
            ?? getenv('MAILER_OUTBOX_HEALTH_MAX_DEAD_LETTER');
        $value = filter_var(
            $raw === false || $raw === null || trim((string)$raw) === '' ? 0 : $raw,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 1000000]]
        );

        return $value === false ? 0 : (int)$value;
    }
}
