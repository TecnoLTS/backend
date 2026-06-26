<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use App\Modules\CatalogInventory\Domain\CatalogInventoryDomain;

class ProductReferenceCatalogRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getModuleInstance(CatalogInventoryDomain::KEY);
    }

    private function getTenantId(): string {
        return TenantContext::id() ?? ($_ENV['DEFAULT_TENANT'] ?? 'paramascotasec');
    }

    public function hasAnyEntries(): bool {
        $stmt = $this->db->prepare('
            SELECT 1
            FROM "ProductReferenceCatalog"
            WHERE tenant_id = :tenant_id
            LIMIT 1
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        return (bool)$stmt->fetchColumn();
    }

    public function getAll(): array {
        $result = [
            'categories' => [],
            'categoryImages' => [],
            'brands' => [],
            'suppliers' => [],
            'sizes' => [],
            'weights' => [],
            'materials' => [],
            'colors' => [],
            'usages' => [],
            'presentations' => [],
            'dosages' => [],
            'activeIngredients' => [],
            'storageLocations' => [],
            'tags' => [],
            'flavors' => [],
            'ageRanges' => [],
        ];

        $stmt = $this->db->prepare('
            SELECT id, catalog_key, label, payload, sort_order
            FROM "ProductReferenceCatalog"
            WHERE tenant_id = :tenant_id
            ORDER BY catalog_key ASC, sort_order ASC, created_at ASC, id ASC
        ');
        $stmt->execute(['tenant_id' => $this->getTenantId()]);
        $rows = $stmt->fetchAll() ?: [];

        foreach ($rows as $row) {
            $catalogKey = trim((string)($row['catalog_key'] ?? ''));
            if (!array_key_exists($catalogKey, $result)) {
                continue;
            }

            $payload = json_decode((string)($row['payload'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            if ($catalogKey === 'brands') {
                $name = trim((string)($payload['name'] ?? ($payload['label'] ?? ($row['label'] ?? ''))));
                if ($name !== '') {
                    $result['brands'][] = [
                        'id' => trim((string)($payload['id'] ?? $row['id'] ?? '')),
                        'name' => $name,
                        'logoUrl' => trim((string)($payload['logoUrl'] ?? ($payload['logo_url'] ?? ''))),
                    ];
                }
                continue;
            }

            if ($catalogKey === 'suppliers') {
                $result['suppliers'][] = [
                    'id' => trim((string)($payload['id'] ?? $row['id'] ?? '')),
                    'name' => trim((string)($payload['name'] ?? $row['label'] ?? '')),
                    'document' => trim((string)($payload['document'] ?? '')),
                    'purchaseTaxRate' => trim((string)($payload['purchaseTaxRate'] ?? ($payload['purchase_tax_rate'] ?? ''))),
                    'email' => trim((string)($payload['email'] ?? '')),
                    'phone' => trim((string)($payload['phone'] ?? '')),
                    'contactName' => trim((string)($payload['contactName'] ?? '')),
                    'address' => trim((string)($payload['address'] ?? '')),
                    'notes' => trim((string)($payload['notes'] ?? '')),
                ];
                continue;
            }

            if ($catalogKey === 'categoryImages') {
                $name = trim((string)($payload['name'] ?? ($payload['label'] ?? ($row['label'] ?? ''))));
                if ($name !== '') {
                    $featuredImages = $payload['featuredImages'] ?? [];
                    if (!is_array($featuredImages)) {
                        $featuredImages = [];
                    }
                    $legacyVisible = array_key_exists('showInImageSection', $payload)
                        ? filter_var($payload['showInImageSection'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false
                        : true;
                    $showInTopSection = array_key_exists('showInTopSection', $payload)
                        ? filter_var($payload['showInTopSection'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false
                        : $legacyVisible;
                    $showInFeaturedSection = array_key_exists('showInFeaturedSection', $payload)
                        ? filter_var($payload['showInFeaturedSection'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false
                        : $legacyVisible;

                    $result['categoryImages'][] = [
                        'name' => $name,
                        'topImageUrl' => trim((string)($payload['topImageUrl'] ?? ($payload['imageUrl'] ?? ''))),
                        'featuredImages' => [
                            'mobilePrimary' => trim((string)($featuredImages['mobilePrimary'] ?? '')),
                            'mobileSecondary' => trim((string)($featuredImages['mobileSecondary'] ?? '')),
                            'desktopPrimary' => trim((string)($featuredImages['desktopPrimary'] ?? '')),
                            'desktopSecondary' => trim((string)($featuredImages['desktopSecondary'] ?? '')),
                        ],
                        'showInTopSection' => $showInTopSection,
                        'showInFeaturedSection' => $showInFeaturedSection,
                        'showInImageSection' => $showInTopSection || $showInFeaturedSection,
                    ];
                }
                continue;
            }

            $label = trim((string)($row['label'] ?? ($payload['label'] ?? '')));
            if ($label !== '') {
                $result[$catalogKey][] = $label;
            }
        }

        return $result;
    }

    public function replaceAll(array $data): array {
        $tenantId = $this->getTenantId();
        $startedTransaction = false;

        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            $deleteStmt = $this->db->prepare('DELETE FROM "ProductReferenceCatalog" WHERE tenant_id = :tenant_id');
            $deleteStmt->execute(['tenant_id' => $tenantId]);

            $insertStmt = $this->db->prepare('
                INSERT INTO "ProductReferenceCatalog" (
                    id,
                    tenant_id,
                    catalog_key,
                    label,
                    payload,
                    sort_order,
                    created_at,
                    updated_at
                ) VALUES (
                    :id,
                    :tenant_id,
                    :catalog_key,
                    :label,
                    :payload::jsonb,
                    :sort_order,
                    NOW(),
                    NOW()
                )
            ');

            $usedBrandIds = [];

            foreach ($data as $catalogKey => $values) {
                if (!is_array($values)) {
                    continue;
                }

                foreach (array_values($values) as $index => $value) {
                    if ($catalogKey === 'brands') {
                        if (is_string($value) || is_numeric($value)) {
                            $value = ['name' => (string)$value];
                        }
                        if (!is_array($value)) {
                            continue;
                        }

                        $brandName = trim((string)($value['name'] ?? ($value['label'] ?? '')));
                        if ($brandName === '') {
                            continue;
                        }

                        $payload = [
                            'id' => trim((string)($value['id'] ?? '')),
                            'name' => $brandName,
                            'logoUrl' => trim((string)($value['logoUrl'] ?? ($value['logo_url'] ?? ''))),
                        ];
                        if ($payload['id'] === '') {
                            $payload['id'] = $this->buildRowId($catalogKey, $brandName, $index + 1);
                        }
                        if (isset($usedBrandIds[$payload['id']])) {
                            $payload['id'] = $this->buildRowId($catalogKey, $brandName, $index + 1);
                        }
                        $usedBrandIds[$payload['id']] = true;

                        $insertStmt->execute([
                            'id' => $payload['id'],
                            'tenant_id' => $tenantId,
                            'catalog_key' => $catalogKey,
                            'label' => $brandName,
                            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'sort_order' => $index,
                        ]);
                        continue;
                    }

                    if ($catalogKey === 'suppliers' && is_array($value)) {
                        $supplierId = trim((string)($value['id'] ?? ''));
                        $supplierName = trim((string)($value['name'] ?? ''));
                        if ($supplierName === '') {
                            continue;
                        }

                        $payload = [
                            'id' => $supplierId !== '' ? $supplierId : $this->buildRowId($catalogKey, $supplierName, $index + 1),
                            'name' => $supplierName,
                            'document' => trim((string)($value['document'] ?? '')),
                            'purchaseTaxRate' => trim((string)($value['purchaseTaxRate'] ?? ($value['purchase_tax_rate'] ?? ''))),
                            'email' => trim((string)($value['email'] ?? '')),
                            'phone' => trim((string)($value['phone'] ?? '')),
                            'contactName' => trim((string)($value['contactName'] ?? '')),
                            'address' => trim((string)($value['address'] ?? '')),
                            'notes' => trim((string)($value['notes'] ?? '')),
                        ];

                        $insertStmt->execute([
                            'id' => $payload['id'],
                            'tenant_id' => $tenantId,
                            'catalog_key' => $catalogKey,
                            'label' => $supplierName,
                            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'sort_order' => $index,
                        ]);
                        continue;
                    }

                    if ($catalogKey === 'categoryImages' && is_array($value)) {
                        $categoryName = trim((string)($value['name'] ?? ($value['label'] ?? ($value['category'] ?? ''))));
                        if ($categoryName === '') {
                            continue;
                        }
                        $featuredImages = $value['featuredImages'] ?? [];
                        if (!is_array($featuredImages)) {
                            $featuredImages = [];
                        }
                        $legacyVisible = array_key_exists('showInImageSection', $value)
                            ? filter_var($value['showInImageSection'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false
                            : true;
                        $showInTopSection = array_key_exists('showInTopSection', $value)
                            ? filter_var($value['showInTopSection'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false
                            : $legacyVisible;
                        $showInFeaturedSection = array_key_exists('showInFeaturedSection', $value)
                            ? filter_var($value['showInFeaturedSection'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false
                            : $legacyVisible;

                        $payload = [
                            'name' => $categoryName,
                            'topImageUrl' => trim((string)($value['topImageUrl'] ?? ($value['imageUrl'] ?? ($value['image'] ?? '')))),
                            'featuredImages' => [
                                'mobilePrimary' => trim((string)($featuredImages['mobilePrimary'] ?? '')),
                                'mobileSecondary' => trim((string)($featuredImages['mobileSecondary'] ?? '')),
                                'desktopPrimary' => trim((string)($featuredImages['desktopPrimary'] ?? '')),
                                'desktopSecondary' => trim((string)($featuredImages['desktopSecondary'] ?? '')),
                            ],
                            'showInTopSection' => $showInTopSection,
                            'showInFeaturedSection' => $showInFeaturedSection,
                            'showInImageSection' => $showInTopSection || $showInFeaturedSection,
                        ];

                        $insertStmt->execute([
                            'id' => $this->buildRowId($catalogKey, $categoryName, $index + 1),
                            'tenant_id' => $tenantId,
                            'catalog_key' => $catalogKey,
                            'label' => $categoryName,
                            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'sort_order' => $index,
                        ]);
                        continue;
                    }

                    $label = trim((string)$value);
                    if ($label === '') {
                        continue;
                    }

                    $insertStmt->execute([
                        'id' => $this->buildRowId($catalogKey, $label, $index + 1),
                        'tenant_id' => $tenantId,
                        'catalog_key' => $catalogKey,
                        'label' => $label,
                        'payload' => json_encode(['label' => $label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'sort_order' => $index,
                    ]);
                }
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->getAll();
    }

    private function buildRowId(string $catalogKey, string $label, int $position): string {
        return 'prc_' . substr(hash('sha256', $this->getTenantId() . '|' . $catalogKey . '|' . $label . '|' . $position), 0, 28);
    }
}
