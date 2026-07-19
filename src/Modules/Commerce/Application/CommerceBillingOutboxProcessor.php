<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Application\Ports\CommerceBillingHttpPort;
use App\Modules\Commerce\Application\Ports\CommerceBillingOutboxStore;

final class CommerceBillingOutboxProcessor
{
    public function __construct(
        private readonly CommerceBillingOutboxStore $outbox,
        private readonly CommerceBillingHttpPort $billing,
        private readonly CommerceBillingPayloadBuilder $payloadBuilder,
        private readonly CommerceBillingOutboxRetryPolicy $retryPolicy
    ) {
    }

    /** @return array<string,int> */
    public function process(
        int $limit,
        int $perTenant,
        int $leaseSeconds,
        string $workerId,
        int $maxSeconds
    ): array {
        $started = microtime(true);
        $summary = [
            'claimed' => 0,
            'sent' => 0,
            'recovered_existing' => 0,
            'retry' => 0,
            'delivery_unknown' => 0,
            'dead_letter' => 0,
            'claim_lost' => 0,
        ];
        $claims = $this->outbox->claimFairBatch($limit, $perTenant, $leaseSeconds, $workerId);
        $summary['claimed'] = count($claims);
        foreach ($claims as $claim) {
            if ((microtime(true) - $started) >= max(1, $maxSeconds)) {
                // Unprocessed claims are safely reclaimed after the bounded lease.
                break;
            }
            try {
                $outcome = $this->processClaim($claim);
                $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
            } catch (\Throwable $exception) {
                // A lost lease must never let an old worker overwrite a newer
                // result. Other repository/DB failures make this cycle fail so
                // the supervisor retries and health reflects the failure.
                if (str_contains(strtolower($exception->getMessage()), 'lease')
                    || str_contains(strtolower($exception->getMessage()), 'claim was lost')) {
                    $summary['claim_lost']++;
                    continue;
                }
                throw $exception;
            }
        }

        return $summary;
    }

    private function processClaim(array $claim): string
    {
        try {
            $command = $this->decodeCommand($claim['command'] ?? null);
            $tenantId = strtolower(trim((string)($claim['tenant_id'] ?? '')));
            $commandTenantId = strtolower(trim((string)($command['tenant_id'] ?? '')));
            if ($tenantId === '' || $commandTenantId === '' || !hash_equals($tenantId, $commandTenantId)) {
                throw new CommerceBillingTransportException(
                    'Durable Billing command tenant does not match its outbox owner.',
                    false,
                    null,
                    'BILLING_OUTBOX_TENANT_MISMATCH'
                );
            }
            $host = trim((string)($command['target_host'] ?? ''));
            $mode = trim((string)($command['api_mode'] ?? ''));
            $order = $this->outbox->loadOrderForClaim($claim);
            if (!is_array($order)) {
                throw new CommerceBillingTransportException('The source order is unavailable for its Billing command.', false, null, 'BILLING_ORDER_NOT_FOUND');
            }
            $orderTenantId = strtolower(trim((string)($order['tenant_id'] ?? '')));
            if ($orderTenantId === '' || !hash_equals($tenantId, $orderTenantId)) {
                throw new CommerceBillingTransportException(
                    'Source Order tenant does not match its durable Billing command.',
                    false,
                    null,
                    'BILLING_ORDER_TENANT_MISMATCH'
                );
            }
            $payload = $this->payloadBuilder->build($order);

            $this->outbox->recordPhase($claim, 'lookup', 'requested');
            $lookup = $this->billing->findBySourceReference($tenantId, $host, $mode, (string)$claim['order_id']);
            if ($lookup['found'] && is_array($lookup['invoice'])) {
                $this->outbox->recordPhase($claim, 'lookup', 'found', (int)$lookup['http_status']);
                $this->outbox->markSucceeded(
                    $claim,
                    $lookup['invoice'],
                    $this->payloadBuilder->billingMetadata($lookup['invoice'], $order),
                    (int)$lookup['http_status']
                );

                return 'recovered_existing';
            }
            $this->outbox->recordPhase($claim, 'lookup', 'not_found', (int)$lookup['http_status']);

            // Persist intent before the only ambiguous external side effect.
            // If the process dies after Billing commits, the next lease starts
            // with source lookup and converges without a duplicate invoice.
            $this->outbox->recordPhase($claim, 'emit', 'requested');
            $emitted = $this->billing->emit($tenantId, $host, $mode, (string)$claim['id'], $payload);
            $this->outbox->recordPhase($claim, 'emit', 'accepted', (int)$emitted['http_status']);
            $this->outbox->markSucceeded(
                $claim,
                $emitted['invoice'],
                $this->payloadBuilder->billingMetadata($emitted['invoice'], $order),
                (int)$emitted['http_status']
            );

            return 'sent';
        } catch (CommerceBillingTransportException $exception) {
            return $this->outbox->markFailed(
                $claim,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->deliveryUnknown,
                $exception->httpStatus,
                $this->retryPolicy
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return $this->outbox->markFailed(
                $claim,
                'BILLING_COMMAND_INVALID',
                $exception->getMessage(),
                false,
                null,
                $this->retryPolicy
            );
        }
    }

    /** @return array<string,mixed> */
    private function decodeCommand(mixed $command): array
    {
        $decoded = is_array($command)
            ? $command
            : (is_string($command) ? json_decode($command, true) : null);
        if (!is_array($decoded) || (int)($decoded['version'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('Unsupported Commerce Billing durable command.');
        }

        return $decoded;
    }
}
