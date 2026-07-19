<?php

declare(strict_types=1);

namespace App\Modules\Mailer\Application;

use App\Modules\Mailer\Application\Ports\MailerOutboxStore;
use App\Modules\Mailer\Application\Ports\MailTransport;

final readonly class MailerOutboxProcessor
{
    public function __construct(
        private MailerOutboxStore $outbox,
        private MailTransport $transport,
        private MailerRetryPolicy $retryPolicy
    ) {
    }

    /** @return array<string,int> */
    public function process(int $limit, int $perTenant, int $leaseSeconds, string $workerId, int $maxSeconds): array
    {
        $started = microtime(true);
        $summary = [
            'claimed' => 0,
            'sent' => 0,
            'retry' => 0,
            'dead_letter' => 0,
            'claim_lost' => 0,
        ];
        $claims = $this->outbox->claimFairBatch($limit, $perTenant, $leaseSeconds, $workerId);
        $summary['claimed'] = count($claims);

        foreach ($claims as $claim) {
            if ((microtime(true) - $started) >= max(1, $maxSeconds)) {
                // The bounded lease safely makes an untouched claim available
                // to another cycle without concurrent delivery.
                break;
            }
            try {
                $result = $this->transport->deliver($claim);
                $this->outbox->markDelivered($claim, $result->providerMessageId, $result->transport);
                $summary['sent']++;
            } catch (MailTransportException $exception) {
                $state = $this->outbox->markFailed(
                    $claim,
                    $exception->errorCode,
                    MailPayloadSanitizer::error($exception->getMessage()),
                    $this->retryPolicy
                );
                $summary[$state] = ($summary[$state] ?? 0) + 1;
            } catch (\Throwable $exception) {
                $message = strtolower($exception->getMessage());
                if (str_contains($message, 'lease') || str_contains($message, 'claim')) {
                    $summary['claim_lost']++;
                    continue;
                }
                throw $exception;
            }
        }

        return $summary;
    }
}
