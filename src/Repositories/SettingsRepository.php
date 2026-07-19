<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\IdentityPlatform\Domain\IdentityPlatformDomain;

class SettingsRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getModuleInstance(IdentityPlatformDomain::KEY);
    }

    public function get($key) {
        $stmt = $this->db->prepare('SELECT value FROM "Setting" WHERE tenant_id = :tenant_id AND key = :key');
        $stmt->execute(['tenant_id' => $this->getTenantId(), 'key' => $this->scopedKey($key)]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : null;
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys): array {
        $logicalKeys = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $logicalKeys[$key] = true;
        }

        if ($logicalKeys === []) {
            return [];
        }

        $tenantId = $this->getTenantId();
        $values = array_fill_keys(array_keys($logicalKeys), null);
        $params = ['tenant_id' => $tenantId];
        $placeholders = [];
        $scopedToLogical = [];

        foreach (array_keys($logicalKeys) as $index => $logicalKey) {
            $parameter = 'key_' . $index;
            $scopedKey = $tenantId ? ($tenantId . ':' . $logicalKey) : $logicalKey;
            $placeholders[] = ':' . $parameter;
            $params[$parameter] = $scopedKey;
            $scopedToLogical[$scopedKey] = $logicalKey;
        }

        $stmt = $this->db->prepare(
            'SELECT "key", value FROM "Setting" WHERE tenant_id = :tenant_id AND "key" IN ('
            . implode(', ', $placeholders)
            . ')'
        );
        $stmt->execute($params);

        while ($row = $stmt->fetch()) {
            $scopedKey = (string)($row['key'] ?? '');
            $logicalKey = $scopedToLogical[$scopedKey] ?? null;
            if ($logicalKey !== null) {
                $values[$logicalKey] = $row['value'] ?? null;
            }
        }

        return $values;
    }

    public function set($key, $value) {
        $scopedKey = $this->scopedKey($key);
        $stmt = $this->db->prepare('INSERT INTO "Setting" (key, value, tenant_id) VALUES (:key, :value, :tenant_id) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value WHERE "Setting".tenant_id = EXCLUDED.tenant_id');
        $stmt->execute([
            'key' => $scopedKey,
            'value' => $value,
            'tenant_id' => $this->getTenantId()
        ]);
        return $this->get($key);
    }

    public function getJson($key, $default = null) {
        $value = $this->get($key);
        if ($value === null || trim((string)$value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }

        return $decoded;
    }

    public function setJson($key, $value) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return null;
        }

        return $this->set($key, $encoded);
    }

    private function scopedKey($key) {
        $tenantId = $this->getTenantId();
        return $tenantId ? ($tenantId . ':' . $key) : $key;
    }

    private function getTenantId() {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }
}
