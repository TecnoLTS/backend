<?php

namespace App\Controllers;

use App\Core\Response;
use App\Core\TenantContext;
use App\Repositories\ContactMessageRepository;
use App\Services\MailService;

class ContactController
{
    private ContactMessageRepository $messages;

    public function __construct()
    {
        $this->messages = new ContactMessageRepository();
    }

    public function store(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            Response::error('Solicitud inválida', 400, 'CONTACT_INVALID_PAYLOAD');
            return;
        }

        if ($this->spamTrapFilled($body)) {
            Response::json([
                'id' => null,
                'delivered' => false,
                'confirmationDelivered' => false,
                'spamFiltered' => true,
            ], 202, null, 'Recibimos tu mensaje. Nuestro equipo te responderá pronto.');
            return;
        }

        $name = $this->sanitizeText($body['name'] ?? '', 140);
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $phone = $this->sanitizeText($body['phone'] ?? '', 40);
        $subject = $this->sanitizeText($body['subject'] ?? '', 160);
        $message = $this->sanitizeMessage($body['message'] ?? '', 5000);

        if ($name === '' || mb_strlen($name) < 3) {
            Response::error('Ingresa tu nombre completo', 422, 'CONTACT_NAME_REQUIRED');
            return;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Ingresa un correo válido', 422, 'CONTACT_EMAIL_INVALID');
            return;
        }
        if ($subject === '' || mb_strlen($subject) < 4) {
            Response::error('Indica el asunto de tu mensaje', 422, 'CONTACT_SUBJECT_REQUIRED');
            return;
        }
        if ($message === '' || mb_strlen($message) < 10) {
            Response::error('Escribe un mensaje más claro para poder ayudarte', 422, 'CONTACT_MESSAGE_TOO_SHORT');
            return;
        }

        $clientIp = $this->getClientIp();
        if ($this->isRateLimited($email, $clientIp)) {
            Response::error('Recibimos varios mensajes en poco tiempo. Espera unos minutos antes de enviar otro.', 429, 'CONTACT_RATE_LIMITED');
            return;
        }

        $record = $this->messages->create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'subject' => $subject,
            'message' => $message,
            'source' => 'contact_page',
            'status' => 'new',
            'ip_address' => $clientIp,
            'user_agent' => trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ?: null,
            'metadata' => [
                'tenant_domain' => TenantContext::get()['domain'] ?? null,
                'referer' => trim((string)($_SERVER['HTTP_REFERER'] ?? '')) ?: null,
            ],
        ]);

        $notificationDelivered = $this->sendNotification($record, $email, $name);
        $confirmationDelivered = $this->sendCustomerConfirmation($record, $email, $name);

        Response::json(
            [
                'id' => $record['id'],
                'delivered' => $notificationDelivered,
                'confirmationDelivered' => $confirmationDelivered,
            ],
            201,
            null,
            $notificationDelivered
                ? 'Recibimos tu mensaje. Nuestro equipo te responderá pronto.'
                : 'Recibimos tu mensaje y quedó registrado correctamente.'
        );
    }

    private function sendNotification(array $record, string $replyToEmail, string $replyToName): bool
    {
        $tenant = TenantContext::get();
        $destination = $this->getContactDestination();

        $subject = sprintf('[Contacto %s] %s', $tenant['name'] ?? 'Sitio', $record['subject'] ?? 'Nuevo mensaje');
        $message = implode("\n", [
            'Nuevo mensaje desde la página de contacto.',
            '',
            'Nombre: ' . ($record['name'] ?? ''),
            'Correo: ' . ($record['email'] ?? ''),
            'Teléfono: ' . (($record['phone'] ?? '') ?: 'No informado'),
            'Asunto: ' . ($record['subject'] ?? ''),
            '',
            'Mensaje:',
            (string)($record['message'] ?? ''),
            '',
            'ID interno: ' . ($record['id'] ?? ''),
            'Tenant: ' . (($tenant['domain'] ?? '') ?: ($tenant['name'] ?? '')),
        ]);

        return MailService::send($destination, $subject, $message, $replyToEmail, $replyToName);
    }

    private function sendCustomerConfirmation(array $record, string $recipientEmail, string $recipientName): bool
    {
        $tenant = TenantContext::get();
        $tenantName = trim((string)($tenant['name'] ?? 'Para Mascotas EC'));
        $contactDestination = $this->getContactDestination();
        $subject = sprintf('[%s] Recibimos tu mensaje', $tenantName);
        $message = implode("\n", [
            'Hola ' . ($recipientName ?: 'cliente') . ',',
            '',
            'Recibimos tu mensaje y ya estamos gestionando tu requerimiento.',
            'Nuestro equipo revisará tu consulta y te responderá lo antes posible.',
            '',
            'Resumen de tu mensaje:',
            'Asunto: ' . ($record['subject'] ?? ''),
            'ID de seguimiento: ' . ($record['id'] ?? ''),
            '',
            'Si necesitas ampliar la información, puedes responder este correo.',
            '',
            $tenantName,
            $contactDestination,
        ]);

        return MailService::send($recipientEmail, $subject, $message, $contactDestination, $tenantName);
    }

    private function getContactDestination(): string
    {
        $destination = trim((string)($_ENV['CONTACT_FORM_TO'] ?? ''));
        if ($destination === '') {
            $destination = trim((string)($_ENV['MAIL_FROM_ADDRESS'] ?? ''));
        }
        if ($destination === '') {
            $destination = 'info@paramascotasec.com';
        }

        return $destination;
    }

    private function spamTrapFilled(array $body): bool
    {
        foreach (['website', 'company', 'url'] as $field) {
            if (trim((string)($body[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function isRateLimited(string $email, ?string $ipAddress): bool
    {
        $emailLimit = max(2, (int)($_ENV['CONTACT_FORM_EMAIL_HOURLY_LIMIT'] ?? 5));
        $ipLimit = max(5, (int)($_ENV['CONTACT_FORM_IP_HOURLY_LIMIT'] ?? 20));

        return $this->messages->countRecentByEmail($email) >= $emailLimit
            || $this->messages->countRecentByIp($ipAddress) >= $ipLimit;
    }

    private function sanitizeText($value, int $maxLength): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return mb_substr($value, 0, $maxLength);
    }

    private function sanitizeMessage($value, int $maxLength): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $value = strip_tags($value);
        $value = preg_replace("/\r\n|\r/u", "\n", $value) ?? $value;
        $value = preg_replace("/\n{3,}/u", "\n\n", $value) ?? $value;
        return mb_substr(trim($value), 0, $maxLength);
    }

    private function getClientIp(): ?string
    {
        $ip = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
        return $ip !== '' ? $ip : null;
    }
}
