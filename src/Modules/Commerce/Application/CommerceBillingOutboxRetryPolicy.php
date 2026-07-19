<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application;

final class CommerceBillingOutboxRetryPolicy
{
    public function __construct(
        private readonly int $baseDelaySeconds = 15,
        private readonly int $maxDelaySeconds = 3600
    ) {
        if ($this->baseDelaySeconds < 1 || $this->maxDelaySeconds < $this->baseDelaySeconds) {
            throw new \InvalidArgumentException('Invalid Commerce billing outbox retry bounds.');
        }
    }

    public function delaySeconds(int $attemptNumber, string $stableKey = ''): int
    {
        $attempt = max(1, min(30, $attemptNumber));
        $exponential = min(
            $this->maxDelaySeconds,
            $this->baseDelaySeconds * (2 ** min(20, $attempt - 1))
        );
        // Deterministic jitter prevents synchronized retries without making
        // tests or incident reconstruction nondeterministic.
        $jitterWindow = max(1, (int) floor($exponential * 0.2));
        $jitter = $stableKey === ''
            ? 0
            : (int) (hexdec(substr(hash('sha256', $stableKey . '|' . $attempt), 0, 8)) % ($jitterWindow + 1));

        return min($this->maxDelaySeconds, $exponential + $jitter);
    }
}
