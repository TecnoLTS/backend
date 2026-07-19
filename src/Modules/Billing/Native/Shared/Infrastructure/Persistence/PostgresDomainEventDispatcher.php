<?php

declare(strict_types=1);

namespace BillingService\Shared\Infrastructure\Persistence;

use BillingService\Shared\Application\Events\DomainEventDispatcher;
use BillingService\Shared\Domain\Events\DomainEvent;
use PDO;
use Psr\Log\LoggerInterface;

final class PostgresDomainEventDispatcher implements DomainEventDispatcher
{
    private bool $schemaEnsured = false;

    public function __construct(
        private readonly PDO $connection,
        private readonly LoggerInterface $logger
    ) {}

    public function dispatch(DomainEvent $event, array $context = []): void
    {
        $this->ensureSchema();
        $tenantId = $this->requiredTenantId($context);

        $payload = $event->toPrimitives();
        $statement = $this->connection->prepare(
            'INSERT INTO billing_domain_events (
                event_id,
                tenant_id,
                event_name,
                access_key,
                client_id,
                branch_id,
                api_key_id,
                payload,
                context,
                occurred_on
            ) VALUES (
                :event_id,
                :tenant_id,
                :event_name,
                :access_key,
                :client_id,
                :branch_id,
                :api_key_id,
                CAST(:payload AS JSONB),
                CAST(:context AS JSONB),
                :occurred_on
            )
            ON CONFLICT (event_id) DO NOTHING'
        );

        $statement->bindValue(':event_id', $event->eventId());
        $statement->bindValue(':tenant_id', $tenantId);
        $statement->bindValue(':event_name', $event->eventName());
        $this->bindNullableString($statement, ':access_key', $this->stringValue($payload['access_key'] ?? null));
        $this->bindNullableInt($statement, ':client_id', $context['client_id'] ?? null);
        $this->bindNullableInt($statement, ':branch_id', $context['resolved_branch_id'] ?? $context['branch_id'] ?? null);
        $this->bindNullableInt($statement, ':api_key_id', $context['api_key_id'] ?? null);
        $statement->bindValue(':payload', $this->jsonEncode($payload));
        $statement->bindValue(':context', $this->jsonEncode($this->publicContext($context)));
        $statement->bindValue(':occurred_on', $event->occurredOn()->format('Y-m-d H:i:s'));
        $statement->execute();

        $this->logger->info('[BillingDomainEvent] Evento registrado', [
            'event_name' => $event->eventName(),
            'event_id' => $event->eventId(),
            'access_key' => $payload['access_key'] ?? null,
        ]);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $ready = $this->connection->query(
            "SELECT to_regclass('public.billing_domain_events') IS NOT NULL
                AND EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_schema = 'public'
                      AND table_name = 'billing_domain_events'
                      AND column_name = 'tenant_id'
                      AND is_nullable = 'NO'
                )"
        )->fetchColumn();
        if (!filter_var($ready, FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('billing_domain_events requiere bootstrap tenant antes de registrar eventos.');
        }

        $this->schemaEnsured = true;
    }

    private function publicContext(array $context): array
    {
        return [
            'tenant_id' => $context['tenant_id'] ?? null,
            'client_id' => $context['client_id'] ?? null,
            'branch_id' => $context['branch_id'] ?? null,
            'resolved_branch_id' => $context['resolved_branch_id'] ?? null,
            'api_key_id' => $context['api_key_id'] ?? null,
            'api_key_name' => $context['api_key_name'] ?? null,
            'api_mode' => $context['api_mode'] ?? null,
            'client_ruc' => $context['client_ruc'] ?? null,
            'branch_code' => $context['branch_code'] ?? null,
            'emission_point' => $context['emission_point'] ?? null,
        ];
    }

    private function requiredTenantId(array $context): string
    {
        $tenantId = trim((string)($context['tenant_id'] ?? ''));
        if ($tenantId === '') {
            throw new \RuntimeException('El evento fiscal no tiene tenant_id resuelto.');
        }

        return $tenantId;
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    private function bindNullableString(\PDOStatement $statement, string $name, ?string $value): void
    {
        if ($value === null) {
            $statement->bindValue($name, null, PDO::PARAM_NULL);
            return;
        }

        $statement->bindValue($name, $value, PDO::PARAM_STR);
    }

    private function bindNullableInt(\PDOStatement $statement, string $name, mixed $value): void
    {
        if (!is_numeric($value)) {
            $statement->bindValue($name, null, PDO::PARAM_NULL);
            return;
        }

        $statement->bindValue($name, (int) $value, PDO::PARAM_INT);
    }

    private function jsonEncode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
