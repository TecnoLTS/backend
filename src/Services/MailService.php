<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\TenantContext;
use App\Modules\Mailer\Application\MailPayloadSanitizer;
use App\Modules\Mailer\Application\MailTransportException;
use App\Modules\Mailer\Application\QueuedMailMessage;
use App\Modules\Mailer\Infrastructure\Persistence\EmailOutboxRepository;
use App\Modules\Mailer\Infrastructure\Transport\SmtpMailTransport;

final class MailService
{
    /**
     * Returns true once the complete message is durably accepted. Delivery is
     * performed by the isolated Mailer worker, never on the request path.
     *
     * @param array<string,mixed> $metadata
     */
    public static function send(
        string $to,
        string $subject,
        string $message,
        ?string $replyTo = null,
        ?string $replyToName = null,
        array $metadata = []
    ): bool {
        return self::enqueue(
            $to,
            $subject,
            'plain',
            $message,
            null,
            $replyTo,
            $replyToName,
            $metadata,
            null
        );
    }

    /**
     * Returns true once the complete HTML/plain alternative payload is durable.
     * `$storedBody` is retained only as an audit preview; it is not substituted
     * for the deliverable body because that would make recovery impossible.
     *
     * @param array<string,mixed> $metadata
     */
    public static function sendHtml(
        string $to,
        string $subject,
        string $htmlMessage,
        ?string $plainMessage = null,
        ?string $replyTo = null,
        ?string $replyToName = null,
        array $metadata = [],
        ?string $storedBody = null
    ): bool {
        return self::enqueue(
            $to,
            $subject,
            'html',
            $plainMessage ?? trim(strip_tags($htmlMessage)),
            $htmlMessage,
            $replyTo,
            $replyToName,
            $metadata,
            $storedBody
        );
    }

    /**
     * Attachments remain synchronous by design: persisting arbitrary binary
     * documents in the general outbox would duplicate potentially sensitive
     * fiscal/customer artifacts. The attempt and outcome are still durable,
     * while the binary and body are never written to the audit row.
     *
     * @param array<string,mixed> $metadata
     */
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
        $tenantId = self::tenantId();
        if ($tenantId === null) {
            self::safeLog('mailer_attachment_rejected', 'TENANT_REQUIRED');
            return false;
        }
        $maxAttachmentBytes = self::boundedInteger('MAILER_SYNC_ATTACHMENT_MAX_BYTES', 10485760, 1024, 26214400);
        if (strlen($attachmentContent) > $maxAttachmentBytes || trim($attachmentContent) === '') {
            self::safeLog('mailer_attachment_rejected', 'ATTACHMENT_SIZE_INVALID');
            return false;
        }

        try {
            $repository = new EmailOutboxRepository();
            $audit = $repository->createAttachmentAudit(
                $tenantId,
                $to,
                $subject,
                'Synchronous attachment delivery; message body and binary omitted.',
                [
                    ...self::extractMetadata($metadata),
                    'attachment' => [
                        'name' => mb_substr(basename(str_replace('\\', '/', $attachmentName)), 0, 255),
                        'mime_type' => mb_substr(trim($mimeType), 0, 127),
                        'bytes' => strlen($attachmentContent),
                        'binary_persisted' => false,
                    ],
                ]
            );
        } catch (\Throwable $exception) {
            self::safeLog('mailer_attachment_audit_failed', $exception::class);
            return false;
        }

        try {
            $result = (new SmtpMailTransport())->deliverAttachment(
                $to,
                $subject,
                $message,
                $attachmentName,
                $attachmentContent,
                $mimeType,
                $replyTo,
                $replyToName
            );
            $repository->completeAttachmentAudit($audit, true, $result->providerMessageId);
            return true;
        } catch (MailTransportException $exception) {
            try {
                $repository->completeAttachmentAudit(
                    $audit,
                    false,
                    null,
                    $exception->errorCode,
                    MailPayloadSanitizer::error($exception->getMessage())
                );
            } catch (\Throwable $auditException) {
                self::safeLog('mailer_attachment_outcome_audit_failed', $auditException::class);
            }
            self::safeLog('mailer_attachment_delivery_failed', $exception->errorCode);
            return false;
        } catch (\Throwable $exception) {
            try {
                $repository->completeAttachmentAudit($audit, false, null, 'SMTP_ATTACHMENT_UNEXPECTED');
            } catch (\Throwable) {
                // The stale audit lease is intentionally visible to health.
            }
            self::safeLog('mailer_attachment_delivery_failed', $exception::class);
            return false;
        }
    }

    /** @param array<string,mixed> $metadata */
    private static function enqueue(
        string $to,
        string $subject,
        string $format,
        string $plainBody,
        ?string $htmlBody,
        ?string $replyTo,
        ?string $replyToName,
        array $metadata,
        ?string $auditPreview
    ): bool {
        $tenantId = self::tenantId();
        if ($tenantId === null) {
            self::safeLog('mailer_enqueue_rejected', 'TENANT_REQUIRED');
            return false;
        }

        try {
            $idempotencyKey = self::extractStringOption($metadata, 'idempotency_key');
            $ttlSeconds = self::extractIntegerOption(
                $metadata,
                'ttl_seconds',
                self::boundedInteger('MAILER_OUTBOX_MESSAGE_TTL_SECONDS', 3600, 60, 604800),
                60,
                604800
            );
            $maxAttempts = self::extractIntegerOption(
                $metadata,
                'max_attempts',
                self::boundedInteger('MAILER_OUTBOX_MAX_ATTEMPTS', 8, 1, 25),
                1,
                25
            );
            $message = QueuedMailMessage::create(
                $tenantId,
                $to,
                $subject,
                $format,
                $plainBody,
                $htmlBody,
                $replyTo,
                $replyToName,
                self::extractMetadata($metadata),
                $auditPreview,
                $idempotencyKey,
                $maxAttempts,
                $ttlSeconds,
                self::boundedInteger('MAILER_OUTBOX_MAX_BODY_BYTES', 262144, 1024, 1048576)
            );
            $accepted = (new EmailOutboxRepository())->enqueue($message);

            return ($accepted['accepted'] ?? false) === true;
        } catch (\Throwable $exception) {
            // No address, subject, body, token, SMTP credential or raw database
            // error reaches container logs.
            self::safeLog('mailer_enqueue_failed', $exception::class);
            return false;
        }
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private static function extractMetadata(array $metadata): array
    {
        unset($metadata['idempotency_key'], $metadata['ttl_seconds'], $metadata['max_attempts']);
        return MailPayloadSanitizer::metadata($metadata);
    }

    /** @param array<string,mixed> $metadata */
    private static function extractStringOption(array $metadata, string $key): ?string
    {
        if (!isset($metadata[$key]) || !is_scalar($metadata[$key])) {
            return null;
        }
        $value = trim((string)$metadata[$key]);
        return $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $metadata */
    private static function extractIntegerOption(array $metadata, string $key, int $default, int $min, int $max): int
    {
        if (!array_key_exists($key, $metadata)) {
            return $default;
        }
        $value = filter_var($metadata[$key], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);
        if ($value === false) {
            throw new \InvalidArgumentException("Mailer {$key} is outside its safe range.");
        }
        return (int)$value;
    }

    private static function boundedInteger(string $key, int $default, int $min, int $max): int
    {
        $raw = $_ENV[$key] ?? getenv($key);
        $value = filter_var($raw === false || $raw === null || trim((string)$raw) === '' ? $default : $raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);
        if ($value === false) {
            throw new \RuntimeException("{$key} is outside its safe range.");
        }
        return (int)$value;
    }

    private static function tenantId(): ?string
    {
        $tenantId = trim((string)(TenantContext::id() ?? ''));
        return preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $tenantId) ? $tenantId : null;
    }

    private static function safeLog(string $event, string $code): void
    {
        error_log(json_encode([
            'event' => $event,
            'code' => mb_substr(preg_replace('/[^A-Za-z0-9_.:\\-]/', '_', $code) ?: 'UNKNOWN', 0, 160),
        ], JSON_UNESCAPED_SLASHES));
    }
}
