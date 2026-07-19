<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Application;

final readonly class QueuedMailMessage
{
    /** @param array<string,mixed> $metadata */
    private function __construct(
        public string $id,
        public string $tenantId,
        public string $idempotencyKey,
        public string $payloadFingerprint,
        public string $recipientEmail,
        public string $subject,
        public string $format,
        public string $plainBody,
        public ?string $htmlBody,
        public ?string $replyToEmail,
        public ?string $replyToName,
        public array $metadata,
        public string $auditPreview,
        public int $maxAttempts,
        public int $ttlSeconds
    ) {
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public static function create(
        string $tenantId,
        string $recipientEmail,
        string $subject,
        string $format,
        string $plainBody,
        ?string $htmlBody = null,
        ?string $replyToEmail = null,
        ?string $replyToName = null,
        array $metadata = [],
        ?string $auditPreview = null,
        ?string $idempotencyKey = null,
        int $maxAttempts = 8,
        int $ttlSeconds = 3600,
        int $maxBodyBytes = 262144
    ): self {
        $tenantId = trim($tenantId);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $tenantId)) {
            throw new \InvalidArgumentException('Mailer requires an explicit safe tenant id.');
        }
        $recipientEmail = strtolower(trim($recipientEmail));
        if (strlen($recipientEmail) > 320 || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Mailer recipient is invalid.');
        }
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        if ($subject === '' || strlen($subject) > 255) {
            throw new \InvalidArgumentException('Mailer subject is empty or too long.');
        }
        $format = strtolower(trim($format));
        if (!in_array($format, ['plain', 'html'], true)) {
            throw new \InvalidArgumentException('Mailer queue only accepts plain or html payloads.');
        }
        if ($format === 'html' && ($htmlBody === null || trim($htmlBody) === '')) {
            throw new \InvalidArgumentException('HTML mail requires its complete durable body.');
        }
        if ($format === 'plain' && $plainBody === '') {
            throw new \InvalidArgumentException('Plain mail requires its complete durable body.');
        }
        $payloadBytes = strlen($plainBody) + strlen((string)$htmlBody);
        if ($maxBodyBytes < 1024 || $maxBodyBytes > 1048576 || $payloadBytes > $maxBodyBytes) {
            throw new \InvalidArgumentException('Mailer body exceeds its safe persistence limit.');
        }
        $replyToEmail = $replyToEmail !== null ? strtolower(trim($replyToEmail)) : null;
        if ($replyToEmail === '') {
            $replyToEmail = null;
        }
        if ($replyToEmail !== null && (strlen($replyToEmail) > 320 || !filter_var($replyToEmail, FILTER_VALIDATE_EMAIL))) {
            throw new \InvalidArgumentException('Mailer reply-to is invalid.');
        }
        $replyToName = $replyToName !== null ? trim(str_replace(["\r", "\n"], ' ', $replyToName)) : null;
        $replyToName = $replyToName === '' ? null : mb_substr((string)$replyToName, 0, 255);

        $maxAttempts = max(1, min(25, $maxAttempts));
        $ttlSeconds = max(60, min(604800, $ttlSeconds));
        $metadata = MailPayloadSanitizer::metadata($metadata);
        $auditPreview = mb_substr(trim((string)($auditPreview ?? 'Queued mail payload retained for delivery.')), 0, 2000);

        $fingerprint = hash('sha256', json_encode([
            'recipient' => $recipientEmail,
            'subject' => $subject,
            'format' => $format,
            'plain' => $plainBody,
            'html' => $htmlBody,
            'reply_to' => $replyToEmail,
            'reply_to_name' => $replyToName,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $idempotencyKey = trim((string)$idempotencyKey);
        if ($idempotencyKey === '') {
            // A five-minute bucket makes an HTTP retry converge without
            // suppressing a legitimate identical notification indefinitely.
            $idempotencyKey = 'auto:' . substr(hash(
                'sha256',
                $tenantId . '|' . $fingerprint . '|' . intdiv(time(), 300)
            ), 0, 48);
        }
        if (strlen($idempotencyKey) > 160 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,159}$/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Mailer idempotency key is invalid.');
        }
        $id = 'mail_' . substr(hash('sha256', $tenantId . '|' . $idempotencyKey), 0, 40);

        return new self(
            $id,
            $tenantId,
            $idempotencyKey,
            $fingerprint,
            $recipientEmail,
            $subject,
            $format,
            $plainBody,
            $htmlBody,
            $replyToEmail,
            $replyToName,
            $metadata,
            $auditPreview,
            $maxAttempts,
            $ttlSeconds
        );
    }
}
