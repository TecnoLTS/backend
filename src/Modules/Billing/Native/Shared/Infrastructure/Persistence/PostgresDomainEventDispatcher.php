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

        $payload = $event->toPrimitives();
        $statement = $this->connection->prepare(
            'INSERT INTO billing_domain_events (
                event_id,
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

        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS billing_domain_events (
                event_id text PRIMARY KEY,
                event_name text NOT NULL,
                access_key text,
                client_id bigint,
                branch_id bigint,
                api_key_id bigint,
                payload jsonb NOT NULL DEFAULT \'{}\'::jsonb,
                context jsonb NOT NULL DEFAULT \'{}\'::jsonb,
                occurred_on timestamp without time zone NOT NULL,
                recorded_at timestamp without time zone DEFAULT NOW() NOT NULL
            )'
        );
        $this->connection->exec(
            'CREATE INDEX IF NOT EXISTS billing_domain_events_access_key_idx
             ON billing_domain_events (access_key, occurred_on DESC)'
        );
        $this->connection->exec(
            'CREATE INDEX IF NOT EXISTS billing_domain_events_client_event_idx
             ON billing_domain_events (client_id, event_name, occurred_on DESC)'
        );

        $this->schemaEnsured = true;
    }

    private function publicContext(array $context): array
    {
        return [
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
