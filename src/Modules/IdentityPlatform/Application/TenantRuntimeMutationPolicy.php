<?php

declare(strict_types=1);

namespace App\Modules\IdentityPlatform\Application;

/**
 * Pure policy for tenant control-plane transitions.
 *
 * Persistence, authorization and optimistic concurrency live at the typed
 * registry boundary; this class makes transition invariants independently
 * executable and keeps HTTP handlers free of lifecycle branching.
 */
final class TenantRuntimeMutationPolicy
{
    private const STATUS_BY_ACTION = [
        'suspend' => 'suspended',
        'resume' => 'active',
        'offboard' => 'inactive',
    ];

    /** @return array<string,mixed> */
    public static function transition(
        array $tenant,
        string $action,
        string $reason,
        string $actorUserId,
        string $occurredAt
    ): array {
        $action = strtolower(trim($action));
        $currentStatus = strtolower(trim((string)($tenant['status'] ?? 'active')));
        $reason = trim($reason);
        if (!isset(self::STATUS_BY_ACTION[$action])) {
            throw new \DomainException('TENANT_LIFECYCLE_ACTION_INVALID');
        }
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 500) {
            throw new \DomainException('TENANT_LIFECYCLE_REASON_INVALID');
        }

        $allowed = match ($action) {
            'suspend' => $currentStatus === 'active',
            'resume' => $currentStatus === 'suspended',
            'offboard' => in_array($currentStatus, ['active', 'suspended'], true),
            default => false,
        };
        if (!$allowed) {
            throw new \DomainException('TENANT_LIFECYCLE_TRANSITION_INVALID');
        }

        $tenant['status'] = self::STATUS_BY_ACTION[$action];
        $tenant['provisioning_status'] = 'pending_gateway';
        $tenant['lifecycle'] = [
            'lastAction' => $action,
            'reason' => $reason,
            'actorUserId' => $actorUserId,
            'occurredAt' => $occurredAt,
        ];
        if ($action === 'suspend') {
            $tenant['suspended_at'] = $occurredAt;
            unset($tenant['resumed_at']);
        } elseif ($action === 'resume') {
            $tenant['resumed_at'] = $occurredAt;
            unset($tenant['suspended_at']);
        } else {
            $tenant['offboarded_at'] = $occurredAt;
            $tenant['retention_until'] = (new \DateTimeImmutable($occurredAt))
                ->modify('+90 days')
                ->format(DATE_ATOM);
        }
        $tenant['updated_at'] = $occurredAt;
        return $tenant;
    }

    /** @return array<string,mixed> */
    public static function updateDomains(
        array $tenant,
        array $rawDomains,
        string $reason,
        string $actorUserId,
        string $occurredAt
    ): array {
        if (strtolower(trim((string)($tenant['status'] ?? 'active'))) === 'inactive') {
            throw new \DomainException('TENANT_OFFBOARDED_IMMUTABLE');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 500) {
            throw new \DomainException('TENANT_DOMAIN_REASON_INVALID');
        }
        $domains = self::normalizeDomains($rawDomains);
        $previous = self::normalizeDomains(is_array($tenant['domains'] ?? null) ? $tenant['domains'] : []);
        if ($domains === $previous) {
            throw new \DomainException('TENANT_DOMAINS_UNCHANGED');
        }

        $tenant['domains'] = $domains;
        $tenant['allowed_origins'] = array_map(static fn (string $domain): string => 'https://' . $domain, $domains);
        $tenant['app_url'] = 'https://' . $domains[0];
        $tenant['public_base_url'] = 'https://' . $domains[0];
        $tenant['provisioning_status'] = 'pending_gateway';
        $tenant['domain_change'] = [
            'previousDomains' => $previous,
            'desiredDomains' => $domains,
            'reason' => $reason,
            'actorUserId' => $actorUserId,
            'occurredAt' => $occurredAt,
        ];
        $tenant['updated_at'] = $occurredAt;
        return $tenant;
    }

    /** @return list<string> */
    public static function normalizeDomains(array $rawDomains): array
    {
        if ($rawDomains === [] || count($rawDomains) > 32) {
            throw new \DomainException('TENANT_DOMAINS_INVALID');
        }
        $domains = [];
        foreach ($rawDomains as $rawDomain) {
            if (!is_string($rawDomain)) {
                throw new \DomainException('TENANT_DOMAINS_INVALID');
            }
            $domain = strtolower(rtrim(trim($rawDomain), '.'));
            if (
                $domain === ''
                || strlen($domain) > 253
                || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1
                || in_array($domain, $domains, true)
            ) {
                throw new \DomainException('TENANT_DOMAINS_INVALID');
            }
            $domains[] = $domain;
        }
        return $domains;
    }
}
