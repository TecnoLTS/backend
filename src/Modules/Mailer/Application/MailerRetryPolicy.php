<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Application;

final readonly class MailerRetryPolicy
{
    public function __construct(
        private int $baseSeconds = 15,
        private int $maxSeconds = 3600,
        private int $jitterPercent = 20
    ) {
        if ($baseSeconds < 1 || $baseSeconds > 3600 || $maxSeconds < $baseSeconds || $maxSeconds > 86400) {
            throw new \InvalidArgumentException('Mailer retry bounds are invalid.');
        }
        if ($jitterPercent < 0 || $jitterPercent > 50) {
            throw new \InvalidArgumentException('Mailer retry jitter must be between 0 and 50 percent.');
        }
    }

    public function delaySeconds(int $attempt, string $messageId): int
    {
        $attempt = max(1, min(30, $attempt));
        $exponent = min(20, $attempt - 1);
        $base = min($this->maxSeconds, $this->baseSeconds * (2 ** $exponent));
        if ($this->jitterPercent === 0 || $base >= $this->maxSeconds) {
            return $base;
        }

        // Deterministic jitter makes retries reproducible while preventing a
        // fleet-wide retry stampede after a shared SMTP outage.
        $bucket = (int)(hexdec(substr(hash('sha256', $messageId . '|' . $attempt), 0, 8)) % 10001);
        $signedRatio = ($bucket / 10000.0) * 2.0 - 1.0;
        $jitter = (int)round($base * ($this->jitterPercent / 100.0) * $signedRatio);

        return max(1, min($this->maxSeconds, $base + $jitter));
    }
}
