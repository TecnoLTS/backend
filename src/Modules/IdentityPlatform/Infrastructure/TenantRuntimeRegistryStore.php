<?php

namespace App\Modules\IdentityPlatform\Infrastructure;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;

/**
 * Narrow database capability for the singleton tenant desired-state registry.
 *
 * Runtime roles never receive table privileges or a BYPASSRLS membership. The
 * only boundary exposed to app/worker is the pair of typed functions owned by
 * the dedicated NOLOGIN platform-auth role.
 */
final class TenantRuntimeRegistryStore
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getModuleCapabilityInstance(
            IdentityPlatformDomain::KEY,
            'tenant-runtime-registry'
        );
    }

    public function get(): array
    {
        return $this->getState()['registry'];
    }

    /** @return array{revision:int,registry:array} */
    public function getState(): array
    {
        $statement = $this->db->query('SELECT platform_auth.get_tenant_runtime_registry_state()');
        $raw = $statement->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        $revision = $decoded['revision'] ?? null;
        $registry = $decoded['registry'] ?? null;
        if (!is_int($revision) || $revision < 1 || !is_array($registry)
            || ($registry['version'] ?? null) !== 1
            || !is_array($registry['tenants'] ?? null)) {
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_STATE_INVALID');
        }

        return ['revision' => $revision, 'registry' => $registry];
    }

    /**
     * @return array{revision:int,applied:bool,idempotent:bool}
     */
    public function set(
        array $registry,
        int $expectedRevision,
        string $requestId,
        string $requestHash,
        string $operation,
        string $targetTenantId,
        ?string $actorTenantId = null,
        ?string $actorUserId = null
    ): array
    {
        if (($registry['version'] ?? null) !== 1 || !is_array($registry['tenants'] ?? null)) {
            throw new \InvalidArgumentException('TENANT_RUNTIME_REGISTRY_PAYLOAD_INVALID');
        }
        $encoded = json_encode($registry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $actorTenantId = trim((string)($actorTenantId ?? TenantContext::id() ?? 'worker'));
        $actorUserId = trim((string)($actorUserId ?? 'tenant-runtime-reconciler'));

        $statement = $this->db->prepare(
            'SELECT platform_auth.set_tenant_runtime_registry('
            . 'CAST(:payload AS jsonb), :expected_revision, :request_id, :request_hash, '
            . ':operation, :target_tenant_id, :actor_tenant_id, :actor_user_id)'
        );
        $statement->execute([
            'payload' => $encoded,
            'expected_revision' => $expectedRevision,
            'request_id' => $requestId,
            'request_hash' => $requestHash,
            'operation' => $operation,
            'target_tenant_id' => $targetTenantId,
            'actor_tenant_id' => $actorTenantId,
            'actor_user_id' => $actorUserId,
        ]);
        $rawResult = $statement->fetchColumn();
        $result = is_string($rawResult) ? json_decode($rawResult, true) : (is_array($rawResult) ? $rawResult : null);
        if (!is_array($result)
            || !is_int($result['revision'] ?? null)
            || !is_bool($result['applied'] ?? null)
            || !is_bool($result['idempotent'] ?? null)) {
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_WRITE_FAILED');
        }

        // The SECURITY DEFINER writer returns the exact applied revision in
        // the same SQL statement as its CAS receipt. The caller already owns
        // the submitted representation. Never perform a separate read:
        // another mutation could otherwise replace revision A with B.
        return [
            'revision' => $result['revision'],
            'applied' => $result['applied'],
            'idempotent' => $result['idempotent'],
        ];
    }

    /** @return array<string,mixed>|null */
    public function mutation(string $requestId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT platform_auth.get_tenant_runtime_registry_mutation(:request_id)'
        );
        $statement->execute(['request_id' => $requestId]);
        $raw = $statement->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<array<string,mixed>> */
    public function events(string $tenantId, int $limit = 50): array
    {
        $statement = $this->db->prepare(
            'SELECT platform_auth.get_tenant_runtime_registry_events(:tenant_id, :event_limit)'
        );
        $statement->bindValue('tenant_id', $tenantId);
        $statement->bindValue('event_limit', max(1, min(200, $limit)), \PDO::PARAM_INT);
        $statement->execute();
        $raw = $statement->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($decoded)) {
            throw new \RuntimeException('TENANT_RUNTIME_REGISTRY_EVENTS_INVALID');
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @return array<string,mixed>|null */
    public function tenantAtRevision(string $tenantId, int $revision): ?array
    {
        $statement = $this->db->prepare(
            'SELECT platform_auth.get_tenant_runtime_registry_tenant_at_revision(:tenant_id, :revision)'
        );
        $statement->execute(['tenant_id' => $tenantId, 'revision' => $revision]);
        $raw = $statement->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        return is_array($decoded) ? $decoded : null;
    }
}
