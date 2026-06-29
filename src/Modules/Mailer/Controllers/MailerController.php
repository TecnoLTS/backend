<?php

namespace App\Modules\Mailer\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Modules\Mailer\Infrastructure\Persistence\EmailOutboxRepository;

final class MailerController
{
    public function health(): void
    {
        Auth::requireAdmin();

        try {
            $repository = new EmailOutboxRepository();
            $repository->assertReady();
            Response::json([
                'status' => 'healthy',
                'module' => 'mailer-service',
                'database' => 'dashboard',
                'stats' => $repository->stats(),
            ]);
        } catch (\Throwable $exception) {
            Response::error(
                'El módulo de correo no puede acceder a dashboard.',
                503,
                'MAILER_SERVICE_UNAVAILABLE',
                ['reason' => $exception->getMessage()]
            );
        }
    }

    public function outbox(): void
    {
        Auth::requireAdmin();

        try {
            $status = strtolower(trim((string)($_GET['status'] ?? '')));
            if (!in_array($status, ['', 'pending', 'sent', 'failed'], true)) {
                Response::error('Estado de correo inválido.', 422, 'MAILER_STATUS_INVALID');
                return;
            }

            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $repository = new EmailOutboxRepository();
            Response::json([
                'items' => $repository->listOutbox($limit, $status !== '' ? $status : null),
                'stats' => $repository->stats(),
            ]);
        } catch (\Throwable $exception) {
            Response::error('No se pudo consultar la bandeja de correo.', 500, 'MAILER_OUTBOX_FAILED', [
                'reason' => $exception->getMessage(),
            ]);
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
        } catch (\Throwable $exception) {
            Response::error('No se pudo consultar el historial de entregas.', 500, 'MAILER_DELIVERY_LOG_FAILED', [
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
