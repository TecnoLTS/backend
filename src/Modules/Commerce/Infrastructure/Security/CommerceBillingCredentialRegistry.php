<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Security;

use App\Modules\Commerce\Application\CommerceBillingTransportException;

/**
 * Read-only tenant credential registry for the Commerce -> Billing data plane.
 *
 * The registry is deliberately independent from Billing persistence: the
 * worker can read Commerce and call Billing over HTTP, but it can never query
 * facturacion to discover or reuse another tenant's credential.
 */
final class CommerceBillingCredentialRegistry
{
    private const MAX_FILE_BYTES = 1048576;

    /** @var array<string,array{allowed_hosts:list<string>,api_key:string}> */
    private array $credentialsByTenant;

    public function __construct(private readonly string $path)
    {
        $this->credentialsByTenant = self::loadFile($this->path)['credentials_by_tenant'];
    }

    public function credentialFor(string $tenantId, string $tenantHost): string
    {
        $tenantId = strtolower(trim($tenantId));
        $tenantHost = strtolower(rtrim(trim($tenantHost), '.'));
        if (!self::validTenantId($tenantId)) {
            throw new CommerceBillingTransportException(
                'Invalid tenant identity in durable Billing command.',
                false,
                null,
                'BILLING_TENANT_ID_INVALID'
            );
        }
        if (!self::validHost($tenantHost)) {
            throw new CommerceBillingTransportException(
                'Invalid tenant host in durable Billing command.',
                false,
                null,
                'BILLING_TENANT_HOST_INVALID'
            );
        }

        $credential = $this->credentialsByTenant[$tenantId] ?? null;
        if (!is_array($credential)) {
            throw new CommerceBillingTransportException(
                'No dedicated Billing credential is configured for this tenant.',
                false,
                null,
                'BILLING_TENANT_CREDENTIAL_MISSING'
            );
        }
        if (!in_array($tenantHost, $credential['allowed_hosts'], true)) {
            throw new CommerceBillingTransportException(
                'Billing credential and tenant host do not belong to the same tenant.',
                false,
                null,
                'BILLING_TENANT_HOST_MISMATCH'
            );
        }

        return $credential['api_key'];
    }

    /** @return array{version:int,credentials:list<array{tenant_id:string,allowed_hosts:list<string>,api_key:string}>} */
    public static function normalizedDocument(array $document): array
    {
        if (array_keys($document) !== ['version', 'credentials']
            || ($document['version'] ?? null) !== 1
            || !is_array($document['credentials'] ?? null)
            || $document['credentials'] === []) {
            throw new \InvalidArgumentException('Commerce Billing credential registry shape is invalid.');
        }

        $tenants = [];
        $hostOwners = [];
        $keyOwners = [];
        foreach ($document['credentials'] as $entry) {
            if (!is_array($entry)
                || array_keys($entry) !== ['tenant_id', 'allowed_hosts', 'api_key']
                || !is_string($entry['tenant_id'] ?? null)
                || !is_array($entry['allowed_hosts'] ?? null)
                || !is_string($entry['api_key'] ?? null)) {
                throw new \InvalidArgumentException('Commerce Billing credential entry shape is invalid.');
            }

            $tenantId = strtolower(trim($entry['tenant_id']));
            if (!self::validTenantId($tenantId) || isset($tenants[$tenantId])) {
                throw new \InvalidArgumentException('Commerce Billing credential tenant identity is invalid or duplicated.');
            }
            $apiKey = trim($entry['api_key']);
            if (strlen($apiKey) < 24 || strlen($apiKey) > 512 || preg_match('/[\x00-\x20\x7f]/', $apiKey) === 1) {
                throw new \InvalidArgumentException('Commerce Billing credential value does not meet the safety policy.');
            }
            $keyFingerprint = hash('sha256', $apiKey);
            if (isset($keyOwners[$keyFingerprint])) {
                throw new \InvalidArgumentException('A Commerce Billing API credential cannot be shared by tenants.');
            }

            $hosts = [];
            foreach ($entry['allowed_hosts'] as $host) {
                if (!is_string($host)) {
                    throw new \InvalidArgumentException('Commerce Billing tenant host list is invalid.');
                }
                $host = strtolower(rtrim(trim($host), '.'));
                if (!self::validHost($host) || isset($hosts[$host]) || isset($hostOwners[$host])) {
                    throw new \InvalidArgumentException('Commerce Billing tenant host is invalid or assigned more than once.');
                }
                $hosts[$host] = true;
                $hostOwners[$host] = $tenantId;
            }
            if ($hosts === []) {
                throw new \InvalidArgumentException('Each Commerce Billing tenant requires at least one exact host.');
            }

            $tenants[$tenantId] = [
                'tenant_id' => $tenantId,
                'allowed_hosts' => array_keys($hosts),
                'api_key' => $apiKey,
            ];
            $keyOwners[$keyFingerprint] = $tenantId;
        }

        ksort($tenants, SORT_STRING);
        foreach ($tenants as &$entry) {
            sort($entry['allowed_hosts'], SORT_STRING);
        }
        unset($entry);

        return ['version' => 1, 'credentials' => array_values($tenants)];
    }

    /** @return array{document:array,credentials_by_tenant:array<string,array{allowed_hosts:list<string>,api_key:string}>} */
    public static function loadFile(string $path): array
    {
        clearstatcache(true, $path);
        if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('BILLING_OUTBOX_CREDENTIALS_FILE must reference a readable absolute regular file.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Commerce Billing credential registry size is invalid.');
        }
        $permissions = fileperms($path);
        if (is_int($permissions) && (($permissions & 0022) !== 0)) {
            throw new \RuntimeException('Commerce Billing credential registry must not be writable by group or others.');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            throw new \RuntimeException('Commerce Billing credential registry could not be read completely.');
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Commerce Billing credential registry JSON is invalid.');
        } finally {
            // Avoid retaining a second copy of the raw secret document.
            $raw = '';
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Commerce Billing credential registry JSON root is invalid.');
        }
        $normalized = self::normalizedDocument($decoded);
        $byTenant = [];
        foreach ($normalized['credentials'] as $entry) {
            $byTenant[$entry['tenant_id']] = [
                'allowed_hosts' => $entry['allowed_hosts'],
                'api_key' => $entry['api_key'],
            ];
        }

        return ['document' => $normalized, 'credentials_by_tenant' => $byTenant];
    }

    private static function validTenantId(string $tenantId): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $tenantId) === 1;
    }

    private static function validHost(string $host): bool
    {
        return preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    }
}
