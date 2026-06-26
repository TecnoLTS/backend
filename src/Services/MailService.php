<?php

namespace App\Services;

use App\Modules\Mailer\Infrastructure\Persistence\EmailOutboxRepository;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService {
    private static function buildMailer(
        string $to,
        string $subject,
        string $message,
        ?string $replyTo = null,
        ?string $replyToName = null,
        bool $isHtml = false
    ): ?PHPMailer {
        $fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@paramascotasec.com';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Para Mascotas EC';
        $smtpHost = $_ENV['SMTP_HOST'] ?? null;

        if (!$smtpHost || !class_exists(PHPMailer::class)) {
            return null;
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'] ?? '';
        $mail->Password = self::normalizedSmtpPassword($smtpHost, (string)($_ENV['SMTP_PASS'] ?? ''));
        $mail->SMTPSecure = self::resolveSmtpEncryption($_ENV['SMTP_SECURE'] ?? 'tls', $mail->Port);
        $mail->Timeout = max(3, (int)($_ENV['SMTP_TIMEOUT'] ?? 10));
        $mail->setFrom($fromAddress, $fromName);
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo, $replyToName ?: $replyTo);
        }
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->isHTML($isHtml);
        $mail->CharSet = 'UTF-8';

        return $mail;
    }

    private static function resolveSmtpEncryption(?string $secure, int $port): string
    {
        $normalized = strtolower(trim((string)$secure));

        if (in_array($normalized, ['ssl', 'smtps'], true)) {
            return PHPMailer::ENCRYPTION_SMTPS;
        }

        if (in_array($normalized, ['tls', 'starttls'], true)) {
            // Compatibilidad con configuraciones comunes donde 465 se marca como "tls"
            // aunque en realidad requiere SMTP implícito.
            return $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        }

        if (in_array($normalized, ['off', 'none', 'plain'], true)) {
            return '';
        }

        return $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    }

    private static function normalizedSmtpPassword(?string $host, string $password): string
    {
        $password = trim($password);
        if ($password === '' || !$host) {
            return $password;
        }

        $normalizedHost = strtolower(trim($host));
        $collapsed = preg_replace('/\s+/', '', $password) ?? $password;

        if ($collapsed !== $password && str_contains($normalizedHost, 'gmail')) {
            return $collapsed;
        }

        return $password;
    }

    public static function send(
        string $to,
        string $subject,
        string $message,
        ?string $replyTo = null,
        ?string $replyToName = null,
        array $metadata = []
    ): bool {
        $outboxId = self::recordPending($to, $subject, $message, [
            ...$metadata,
            'transport' => 'plain',
            'reply_to' => $replyTo,
            'reply_to_name' => $replyToName,
        ]);
        $smtpHost = $_ENV['SMTP_HOST'] ?? null;

        if ($smtpHost && class_exists(PHPMailer::class)) {
            try {
                $mail = self::buildMailer($to, $subject, $message, $replyTo, $replyToName, false);
                if (!$mail) {
                    self::recordFailed($outboxId, 'No se pudo construir el mensaje SMTP.', ['transport' => 'smtp']);
                    return false;
                }
                $mail->send();
                self::recordDelivered($outboxId, $mail->getLastMessageID(), ['transport' => 'smtp']);
                return true;
            } catch (Exception $e) {
                error_log('SMTP send failed: ' . $e->getMessage());
                self::recordFailed($outboxId, $e->getMessage(), ['transport' => 'smtp']);
                return false;
            }
        }

        $fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@paramascotasec.com';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Para Mascotas EC';

        $replyToHeader = $fromAddress;
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyToHeader = $replyTo;
        }

        $headers = [
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $replyToHeader,
            'Content-Type: text/plain; charset=UTF-8'
        ];

        $result = mail($to, $subject, $message, implode("\r\n", $headers));
        if (!$result) {
            error_log('Mail() failed for: ' . $to);
            self::recordFailed($outboxId, 'mail() returned false.', ['transport' => 'mail']);
        } else {
            self::recordDelivered($outboxId, null, ['transport' => 'mail']);
        }
        return $result;
    }

    public static function sendWithAttachment(
        string $to,
        string $subject,
        string $message,
        string $attachmentName,
        string $attachmentContent,
        string $mimeType = 'application/pdf',
        ?string $replyTo = null,
        ?string $replyToName = null,
        array $metadata = []
    ): bool {
        $outboxId = self::recordPending($to, $subject, $message, [
            ...$metadata,
            'transport' => 'attachment',
            'reply_to' => $replyTo,
            'reply_to_name' => $replyToName,
            'attachment' => [
                'name' => $attachmentName,
                'mime_type' => $mimeType,
                'bytes' => strlen($attachmentContent),
            ],
        ]);
        $smtpHost = $_ENV['SMTP_HOST'] ?? null;
        if (!$smtpHost || !class_exists(PHPMailer::class)) {
            error_log('Attachment email requires configured SMTP/PHPMailer.');
            self::recordFailed($outboxId, 'El envío con adjuntos requiere SMTP/PHPMailer configurado.', ['transport' => 'smtp']);
            return false;
        }

        try {
            $mail = self::buildMailer($to, $subject, $message, $replyTo, $replyToName, false);
            if (!$mail) {
                self::recordFailed($outboxId, 'No se pudo construir el mensaje SMTP.', ['transport' => 'smtp']);
                return false;
            }
            $mail->addStringAttachment($attachmentContent, $attachmentName, PHPMailer::ENCODING_BASE64, $mimeType);
            $mail->send();
            self::recordDelivered($outboxId, $mail->getLastMessageID(), ['transport' => 'smtp']);
            return true;
        } catch (Exception $e) {
            error_log('SMTP attachment send failed: ' . $e->getMessage());
            self::recordFailed($outboxId, $e->getMessage(), ['transport' => 'smtp']);
            return false;
        }
    }

    private static function recordPending(string $to, string $subject, string $message, array $metadata): ?string
    {
        try {
            $repository = new EmailOutboxRepository();
            $record = $repository->createPending([
                'to' => $to,
                'subject' => $subject,
                'body' => $message,
                'metadata' => self::cleanMetadata($metadata),
            ]);

            return is_string($record['id'] ?? null) ? $record['id'] : null;
        } catch (\Throwable $exception) {
            error_log('Mailer outbox record failed: ' . $exception->getMessage());
            return null;
        }
    }

    private static function recordDelivered(?string $outboxId, ?string $providerMessageId = null, array $metadata = []): void
    {
        if ($outboxId === null || $outboxId === '') {
            return;
        }

        try {
            (new EmailOutboxRepository())->markDelivered($outboxId, $providerMessageId, self::cleanMetadata($metadata));
        } catch (\Throwable $exception) {
            error_log('Mailer delivery log failed: ' . $exception->getMessage());
        }
    }

    private static function recordFailed(?string $outboxId, string $errorMessage, array $metadata = []): void
    {
        if ($outboxId === null || $outboxId === '') {
            return;
        }

        try {
            (new EmailOutboxRepository())->markFailed($outboxId, $errorMessage, self::cleanMetadata($metadata));
        } catch (\Throwable $exception) {
            error_log('Mailer failure log failed: ' . $exception->getMessage());
        }
    }

    private static function cleanMetadata(array $metadata): array
    {
        return array_filter($metadata, static fn($value): bool => $value !== null && $value !== '');
    }
}
