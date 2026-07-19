<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Application\Ports;

use App\Modules\Mailer\Application\MailerRetryPolicy;
use App\Modules\Mailer\Application\QueuedMailMessage;

interface MailerOutboxStore
{
    /** @return array<string,mixed> */
    public function enqueue(QueuedMailMessage $message): array;

    /** @return array<int,array<string,mixed>> */
    public function claimFairBatch(int $limit, int $perTenant, int $leaseSeconds, string $workerId): array;

    public function markDelivered(array $claim, ?string $providerMessageId, string $transport): void;

    public function markFailed(
        array $claim,
        string $errorCode,
        string $errorMessage,
        MailerRetryPolicy $retryPolicy
    ): string;
}
