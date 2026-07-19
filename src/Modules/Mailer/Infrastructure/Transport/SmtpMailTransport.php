<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Infrastructure\Transport;

use App\Modules\Mailer\Application\MailDeliveryResult;
use App\Modules\Mailer\Application\MailPayloadSanitizer;
use App\Modules\Mailer\Application\MailTransportException;
use App\Modules\Mailer\Application\Ports\MailTransport;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailTransport implements MailTransport
{
    /** @param array<string,mixed> $message */
    public function deliver(array $message): MailDeliveryResult
    {
        try {
            $format = strtolower(trim((string)($message['message_format'] ?? '')));
            if (!in_array($format, ['plain', 'html'], true)) {
                throw new \InvalidArgumentException('Unsupported queued mail format.');
            }
            $plainBody = (string)($message['plain_body'] ?? $message['body'] ?? '');
            $htmlBody = isset($message['html_body']) ? (string)$message['html_body'] : null;
            $mail = $this->buildMailer(
                (string)($message['recipient_email'] ?? ''),
                (string)($message['subject'] ?? ''),
                $format === 'html' ? (string)$htmlBody : $plainBody,
                isset($message['reply_to_email']) ? (string)$message['reply_to_email'] : null,
                isset($message['reply_to_name']) ? (string)$message['reply_to_name'] : null,
                $format === 'html'
            );
            if ($format === 'html' && $plainBody !== '') {
                $mail->AltBody = $plainBody;
            }
            $mail->send();

            return new MailDeliveryResult('smtp', $this->boundedMessageId($mail->getLastMessageID()));
        } catch (MailTransportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new MailTransportException(
                MailPayloadSanitizer::error($exception->getMessage()),
                'SMTP_DELIVERY_FAILED',
                $exception
            );
        }
    }

    public function deliverAttachment(
        string $to,
        string $subject,
        string $message,
        string $attachmentName,
        string $attachmentContent,
        string $mimeType,
        ?string $replyTo = null,
        ?string $replyToName = null
    ): MailDeliveryResult {
        try {
            $mail = $this->buildMailer($to, $subject, $message, $replyTo, $replyToName, false);
            $mail->addStringAttachment(
                $attachmentContent,
                mb_substr(str_replace(["\r", "\n"], '', $attachmentName), 0, 255),
                PHPMailer::ENCODING_BASE64,
                mb_substr(trim($mimeType), 0, 127)
            );
            $mail->send();

            return new MailDeliveryResult('smtp_attachment', $this->boundedMessageId($mail->getLastMessageID()));
        } catch (MailTransportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new MailTransportException(
                MailPayloadSanitizer::error($exception->getMessage()),
                'SMTP_ATTACHMENT_DELIVERY_FAILED',
                $exception
            );
        }
    }

    public function assertConfigured(): void
    {
        if (!class_exists(PHPMailer::class)) {
            throw new MailTransportException('PHPMailer runtime is unavailable.', 'SMTP_RUNTIME_UNAVAILABLE');
        }
        $host = $this->env('SMTP_HOST');
        $port = filter_var($this->env('SMTP_PORT', '587'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $from = $this->env('MAIL_FROM_ADDRESS');
        if ($host === '' || strlen($host) > 253 || preg_match('/[\s\r\n]/', $host) || $port === false) {
            throw new MailTransportException('SMTP endpoint configuration is invalid.', 'SMTP_CONFIG_INVALID');
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new MailTransportException('SMTP sender configuration is invalid.', 'SMTP_CONFIG_INVALID');
        }
        if ($this->env('SMTP_USER') !== '' && $this->env('SMTP_PASS') === '') {
            throw new MailTransportException('SMTP authenticated transport lacks its password.', 'SMTP_CONFIG_INVALID');
        }
    }

    private function buildMailer(
        string $to,
        string $subject,
        string $message,
        ?string $replyTo,
        ?string $replyToName,
        bool $isHtml
    ): PHPMailer {
        $this->assertConfigured();
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new MailTransportException('Queued SMTP recipient is invalid.', 'SMTP_RECIPIENT_INVALID');
        }

        $host = $this->env('SMTP_HOST');
        $port = (int)$this->env('SMTP_PORT', '587');
        $username = $this->env('SMTP_USER');
        $fromAddress = $this->env('MAIL_FROM_ADDRESS');
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Hostname = $this->messageIdDomain($fromAddress);
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = $username !== '';
        $mail->Username = $username;
        $mail->Password = $this->normalizedPassword($host, $this->env('SMTP_PASS'));
        $mail->SMTPSecure = $this->resolveEncryption($this->env('SMTP_SECURE', 'tls'), $port);
        $mail->Timeout = max(3, min(60, (int)$this->env('SMTP_TIMEOUT', '10')));
        $mail->setFrom($fromAddress, $this->env('MAIL_FROM_NAME', 'Para Mascotas EC'));
        if ($replyTo !== null && $replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo, $replyToName ?: $replyTo);
        }
        $mail->addAddress($to);
        $mail->Subject = str_replace(["\r", "\n"], ' ', $subject);
        $mail->Body = $message;
        $mail->isHTML($isHtml);
        $mail->CharSet = 'UTF-8';

        return $mail;
    }

    private function messageIdDomain(string $fromAddress): string
    {
        $configured = strtolower(rtrim($this->env('MAIL_MESSAGE_ID_DOMAIN'), '.'));
        $separator = strrpos($fromAddress, '@');
        $domain = $configured !== ''
            ? $configured
            : strtolower($separator === false ? '' : substr($fromAddress, $separator + 1));

        if (
            $domain === ''
            || strlen($domain) > 253
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new MailTransportException('SMTP message ID domain is invalid.', 'SMTP_CONFIG_INVALID');
        }

        return $domain;
    }

    private function resolveEncryption(string $secure, int $port): string
    {
        $secure = strtolower(trim($secure));
        if (in_array($secure, ['ssl', 'smtps'], true)) {
            return PHPMailer::ENCRYPTION_SMTPS;
        }
        if (in_array($secure, ['off', 'none', 'plain'], true)) {
            return '';
        }

        return $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    }

    private function normalizedPassword(string $host, string $password): string
    {
        $password = trim($password);
        $collapsed = preg_replace('/\s+/', '', $password) ?? $password;
        return $collapsed !== $password && str_contains(strtolower($host), 'gmail') ? $collapsed : $password;
    }

    private function boundedMessageId(string $messageId): ?string
    {
        $messageId = trim(str_replace(["\r", "\n"], '', $messageId));
        return $messageId !== '' ? mb_substr($messageId, 0, 512) : null;
    }

    private function env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return $value === false || $value === null || trim((string)$value) === ''
            ? $default
            : trim((string)$value);
    }
}
