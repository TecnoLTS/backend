<?php

namespace App\Http\Shared;

use App\Repositories\SettingsRepository;
use App\Repositories\ProductReferenceCatalogRepository;
use App\Repositories\UserRepository;
use App\Services\SessionSettingsService;
use App\Support\ProductFieldValueNormalizer;
use App\Core\Response;
use App\Core\Auth;
use App\Modules\Commerce\Application\TenantDefaultTaxRate;
use App\Modules\Commerce\Infrastructure\CommerceCollaborationPortsFactory;

abstract class SettingsControllerBase {
    private const DEFAULT_SHIPPING_STORE_ADDRESS = 'Av. de la Prensa y Juan Paz y Miño, 170104 Quito';
    private const DEFAULT_SHIPPING_STORE_LATITUDE = -0.148306;
    private const DEFAULT_SHIPPING_STORE_LONGITUDE = -78.490870;
    private const PREVIOUS_SHIPPING_STORE_LATITUDE = -0.12231;
    private const PREVIOUS_SHIPPING_STORE_LONGITUDE = -78.49375;
    private const LEGACY_SHIPPING_STORE_LATITUDE = -0.117371;
    private const LEGACY_SHIPPING_STORE_LONGITUDE = -78.494256;

    /**
     * Permite cachear exclusivamente lecturas publicas anonimas cuya respuesta
     * no varia por sesion. El filtro se replica en Nginx: si aparece una query,
     * cookie o Authorization se conserva el no-store global y la peticion llega
     * siempre a PHP.
     */
    private function enableAnonymousPublicReadCache(
        string $expectedPath,
        bool $longLivedCatalogReference = false
    ): void {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '';
        $queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
        $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        $cookie = trim((string)($_SERVER['HTTP_COOKIE'] ?? ''));

        if (
            $method !== 'GET'
            || $requestPath !== $expectedPath
            || $queryString !== ''
            || $authorization !== ''
            || $cookie !== ''
        ) {
            return;
        }

        header_remove('Pragma');
        header_remove('Expires');
        if ($longLivedCatalogReference) {
            header('Cache-Control: public, max-age=60, s-maxage=60, stale-while-revalidate=30, stale-if-error=300');
        } else {
            // Shipping incluye precio e impuesto: su proyeccion fiscal nunca se
            // sirve stale y conserva el limite estricto de quince segundos.
            header('Cache-Control: public, max-age=15, s-maxage=15, must-revalidate');
        }
        header('Vary: Origin, Accept-Encoding');
    }

    private function getDefaultProductReferenceData() {
        return [
            'categories' => [],
            'categoryImages' => [],
            'brands' => [],
            'suppliers' => [],
            'commercialAttributes' => [],
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
    }

    private function parseBool($value, $default = false) {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) return true;
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) return false;
        return $default;
    }

    private function getNumericSetting(SettingsRepository $settings, $key, $default) {
        $value = $settings->get($key);
        return is_numeric($value) ? floatval($value) : $default;
    }

    private function normalizeShippingStoreAddress(?string $value): string {
        $address = trim((string)($value ?? ''));
        return $address !== '' ? $address : self::DEFAULT_SHIPPING_STORE_ADDRESS;
    }

    private function normalizeShippingStoreKey(?string $value): string {
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string)($value ?? '')), 'UTF-8')
            : strtolower(trim((string)($value ?? '')));
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized ?? '');
        return trim((string)$normalized);
    }

    private function isLegacyShippingStoreLocation(string $address, float $latitude, float $longitude): bool {
        $matchesLegacyPoint = (
            abs($latitude - self::LEGACY_SHIPPING_STORE_LATITUDE) < 0.00001
            && abs($longitude - self::LEGACY_SHIPPING_STORE_LONGITUDE) < 0.00001
        ) || (
            abs($latitude - self::PREVIOUS_SHIPPING_STORE_LATITUDE) < 0.00001
            && abs($longitude - self::PREVIOUS_SHIPPING_STORE_LONGITUDE) < 0.00001
        );
        if (!$matchesLegacyPoint) {
            return false;
        }

        $normalizedAddress = $this->normalizeShippingStoreKey($address);
        $defaultAddress = $this->normalizeShippingStoreKey(self::DEFAULT_SHIPPING_STORE_ADDRESS);
        return $normalizedAddress === '' || $normalizedAddress === $defaultAddress;
    }

    private function sanitizeReferenceOptionList($value, ?string $catalogKey = null) {
        if (!is_array($value)) {
            return [];
        }

        $seen = [];
        $normalized = [];

        foreach ($value as $item) {
            $text = trim(preg_replace('/\s+/', ' ', (string)$item));
            if ($text === '') {
                continue;
            }

            if (in_array($catalogKey, ['sizes', 'weights', 'presentations', 'dosages'], true)) {
                $text = ProductFieldValueNormalizer::normalizeDisplayValue($text);
                if ($text === '') {
                    continue;
                }
            }

            $dedupeKey = function_exists('mb_strtolower')
                ? mb_strtolower($text, 'UTF-8')
                : strtolower($text);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $normalized[] = $text;
        }

        return array_values($normalized);
    }

    private function isWeightOrContentReference(string $value): bool {
        return preg_match('/\d+(?:[.,]\d+)?\s*(?:kgs?|kg|k|gr|g|lb|lbs|l|lt|lts|ml|oz|un|uni|und|unidad(?:es)?)\b/iu', $value) === 1;
    }

    private function isDosageReference(string $value): bool {
        return preg_match('/\d+(?:[.,]\d+)?\s*(?:mg|mcg|ug|µg|ui|iu)\b|(?:^|\s)\d+\s*(?:dosis|dose|tabletas?|tabs?|comprimidos?|capsulas?|cápsulas?)\b/iu', $value) === 1;
    }

    private function mergeReferenceOptionLists(array ...$lists): array {
        return $this->sanitizeReferenceOptionList(array_merge(...$lists));
    }

    private function normalizeMeasuredReferenceList(array $values): array {
        return $this->sanitizeReferenceOptionList($values, 'weights');
    }

    private function normalizeCommercialAttributeKey(mixed $value, mixed $fallback = ''): string {
        $raw = $this->sanitizeTextValue($value, 80);
        if ($raw === '') {
            $raw = $this->sanitizeTextValue($fallback, 80);
        }
        if ($raw === '') {
            return '';
        }
        if (class_exists('\Normalizer')) {
            $raw = \Normalizer::normalize($raw, \Normalizer::FORM_D) ?: $raw;
            $raw = preg_replace('/\p{Mn}+/u', '', $raw) ?: $raw;
        }
        $raw = strtolower($raw);
        $key = preg_replace('/[^a-z0-9]+/', '_', $raw) ?: '';
        return trim(substr($key, 0, 48), '_');
    }

    private function sanitizeCommercialAttributeReferenceList(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $seen = [];
        $normalized = [];

        foreach (array_values($value) as $index => $item) {
            if (is_string($item) || is_numeric($item)) {
                $item = ['label' => (string)$item];
            }
            if (!is_array($item)) {
                continue;
            }

            $label = $this->sanitizeTextValue($item['label'] ?? ($item['name'] ?? ''), 120);
            $key = $this->normalizeCommercialAttributeKey($item['key'] ?? '', $label);
            if ($label === '' || $key === '' || isset($seen[$key])) {
                continue;
            }

            $values = $this->sanitizeReferenceOptionList(
                $this->parseReferenceOptionInput($item['values'] ?? ($item['options'] ?? []))
            );
            $legacyKeys = [];
            if (is_array($item['legacyKeys'] ?? null)) {
                foreach ($item['legacyKeys'] as $legacyKey) {
                    $normalizedLegacyKey = $this->normalizeCommercialAttributeKey($legacyKey);
                    if ($normalizedLegacyKey !== '' && $normalizedLegacyKey !== $key) {
                        $legacyKeys[] = $normalizedLegacyKey;
                    }
                }
            }

            $seen[$key] = true;
            $normalized[] = [
                'id' => $this->sanitizeTextValue($item['id'] ?? '', 80) ?: 'attribute-' . $key . '-' . ($index + 1),
                'key' => $key,
                'label' => $label,
                'values' => $values,
                'legacyKeys' => array_values(array_unique($legacyKeys)),
            ];
        }

        return $normalized;
    }

    private function parseReferenceOptionInput(mixed $value): array {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) || is_numeric($value)) {
            return preg_split('/[\n,;]+/', (string)$value) ?: [];
        }
        return [];
    }

    private function migrateMeasuredOptions(array &$data): void {
        $sizes = $this->normalizeMeasuredReferenceList($data['sizes'] ?? []);
        $weights = [];
        $remainingSizes = [];

        foreach ($sizes as $size) {
            if ($this->isDosageReference($size)) {
                $weights[] = $size;
            } elseif ($this->isWeightOrContentReference($size)) {
                $weights[] = $size;
            } else {
                $remainingSizes[] = $size;
            }
        }

        $data['sizes'] = $remainingSizes;
        $data['weights'] = $this->normalizeMeasuredReferenceList(array_merge($data['weights'] ?? [], $data['dosages'] ?? [], $weights));
        $data['dosages'] = [];
    }

    private function defaultCommercialAttributesFromLegacyCatalogs(array $data): array {
        $definitions = [
            ['key' => 'weight', 'label' => 'Contenido / dosis', 'values' => $data['weights'] ?? [], 'legacyKeys' => ['volume', 'dosage']],
            ['key' => 'presentation', 'label' => 'Presentacion', 'values' => $data['presentations'] ?? [], 'legacyKeys' => ['packaging']],
            ['key' => 'flavor', 'label' => 'Sabor', 'values' => $data['flavors'] ?? []],
            ['key' => 'target', 'label' => 'Etapa / rango recomendado', 'values' => $data['ageRanges'] ?? [], 'legacyKeys' => ['age', 'range']],
            ['key' => 'size', 'label' => 'Talla / tamano', 'values' => $data['sizes'] ?? []],
            ['key' => 'color', 'label' => 'Color', 'values' => $data['colors'] ?? []],
            ['key' => 'material', 'label' => 'Material', 'values' => $data['materials'] ?? []],
            ['key' => 'usage', 'label' => 'Uso / necesidad', 'values' => $data['usages'] ?? []],
        ];

        return $this->sanitizeCommercialAttributeReferenceList(array_values(array_filter(
            $definitions,
            static fn($definition) => !empty($definition['values'])
        )));
    }

    private function sanitizeTextValue($value, $maxLength = 255) {
        $text = trim(preg_replace('/\s+/', ' ', (string)$value));
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength, 'UTF-8');
        }

        return substr($text, 0, $maxLength);
    }

    private function sanitizeAssetUrlValue($value): string {
        $text = $this->sanitizeTextValue($value, 2048);
        if ($text === '') {
            return '';
        }

        if (str_starts_with($text, '/uploads/') || str_starts_with($text, '/images/')) {
            if (preg_match('/[\x00-\x1F\x7F<>"\']/', $text)) {
                return '';
            }
            return $text;
        }

        if (filter_var($text, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $text)) {
            return $text;
        }

        return '';
    }

    private function sanitizeCategoryImageReferenceList($value) {
        if (!is_array($value)) {
            return [];
        }

        $seenNames = [];
        $normalized = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $this->sanitizeTextValue($item['name'] ?? ($item['label'] ?? ($item['category'] ?? '')), 160);
            if ($name === '') {
                continue;
            }

            $nameKey = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);
            if (isset($seenNames[$nameKey])) {
                continue;
            }
            $seenNames[$nameKey] = true;

            $featuredImages = $item['featuredImages'] ?? [];
            if (!is_array($featuredImages)) {
                $featuredImages = [];
            }

            $legacyVisible = array_key_exists('showInImageSection', $item)
                ? $this->parseBool($item['showInImageSection'], true)
                : true;
            $showInTopSection = array_key_exists('showInTopSection', $item)
                ? $this->parseBool($item['showInTopSection'], $legacyVisible)
                : $legacyVisible;
            $showInFeaturedSection = array_key_exists('showInFeaturedSection', $item)
                ? $this->parseBool($item['showInFeaturedSection'], $legacyVisible)
                : $legacyVisible;

            $normalized[] = [
                'name' => $name,
                'topImageUrl' => $this->sanitizeAssetUrlValue($item['topImageUrl'] ?? ($item['imageUrl'] ?? ($item['image'] ?? ''))),
                'featuredImages' => [
                    'mobilePrimary' => $this->sanitizeAssetUrlValue($featuredImages['mobilePrimary'] ?? ''),
                    'mobileSecondary' => $this->sanitizeAssetUrlValue($featuredImages['mobileSecondary'] ?? ''),
                    'desktopPrimary' => $this->sanitizeAssetUrlValue($featuredImages['desktopPrimary'] ?? ''),
                    'desktopSecondary' => $this->sanitizeAssetUrlValue($featuredImages['desktopSecondary'] ?? ''),
                ],
                'showInTopSection' => $showInTopSection,
                'showInFeaturedSection' => $showInFeaturedSection,
                'showInImageSection' => $showInTopSection || $showInFeaturedSection,
            ];
        }

        return array_values($normalized);
    }

    private function buildBrandReferenceId($name) {
        $base = preg_replace('/[^A-Z0-9]+/', '', strtoupper($this->sanitizeTextValue($name, 160)));
        if ($base === '') {
            return 'brand-' . uniqid();
        }
        return 'brand-' . strtolower($base);
    }

    private function sanitizeBrandReferenceList($value) {
        if (!is_array($value)) {
            return [];
        }

        $seenNames = [];
        $seenIds = [];
        $normalized = [];

        foreach ($value as $index => $item) {
            if (is_string($item) || is_numeric($item)) {
                $item = ['name' => (string)$item];
            }
            if (!is_array($item)) {
                continue;
            }

            $name = $this->sanitizeTextValue($item['name'] ?? ($item['label'] ?? ($item['brand'] ?? '')), 160);
            if ($name === '') {
                continue;
            }

            $nameKey = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);

            if (isset($seenNames[$nameKey])) {
                continue;
            }

            $seenNames[$nameKey] = true;
            $id = $this->sanitizeTextValue($item['id'] ?? '', 160);
            $logoUrl = $this->sanitizeAssetUrlValue($item['logoUrl'] ?? ($item['logo_url'] ?? ($item['imageUrl'] ?? ($item['image'] ?? ($item['logo'] ?? '')))));
            $baseId = $id !== '' ? $id : $this->buildBrandReferenceId($name !== '' ? $name : (string)($index + 1));
            $finalId = $baseId;
            $suffix = 2;
            while (isset($seenIds[$finalId])) {
                $finalId = $baseId . '-' . $suffix;
                $suffix++;
            }
            $seenIds[$finalId] = true;

            $normalized[] = [
                'id' => $finalId,
                'name' => $name,
                'logoUrl' => $logoUrl,
            ];
        }

        usort($normalized, static function ($left, $right) {
            return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        return array_values($normalized);
    }

    private function sanitizePercentageValue($value, $max = 100): string {
        if ($value === null) {
            return '';
        }

        $text = trim(str_replace(',', '.', (string)$value));
        if ($text === '' || !is_numeric($text)) {
            return '';
        }

        $normalized = max(0, min((float)$max, round((float)$text, 2)));
        return rtrim(rtrim(number_format($normalized, 2, '.', ''), '0'), '.');
    }

    private function normalizeSupplierDocumentKey($value) {
        return preg_replace('/[^A-Z0-9]+/', '', strtoupper($this->sanitizeTextValue($value, 64)));
    }

    private function buildSupplierReferenceId($name, $document) {
        $base = $this->normalizeSupplierDocumentKey($document !== '' ? $document : $name);
        if ($base === '') {
            return 'supplier-' . uniqid();
        }
        return 'supplier-' . strtolower($base);
    }

    private function sanitizeSupplierReferenceList($value) {
        if (!is_array($value)) {
            return [];
        }

        $seenNames = [];
        $seenDocuments = [];
        $normalized = [];

        foreach ($value as $index => $item) {
            if (is_string($item) || is_numeric($item)) {
                $item = ['name' => (string)$item];
            }
            if (!is_array($item)) {
                continue;
            }

            $name = $this->sanitizeTextValue($item['name'] ?? ($item['supplierName'] ?? ($item['label'] ?? '')), 160);
            if ($name === '') {
                continue;
            }

            $document = $this->sanitizeTextValue($item['document'] ?? ($item['supplierDocument'] ?? ''), 64);
            $email = strtolower($this->sanitizeTextValue($item['email'] ?? '', 190));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = '';
            }

            $phone = $this->sanitizeTextValue($item['phone'] ?? '', 64);
            $contactName = $this->sanitizeTextValue($item['contactName'] ?? ($item['contact_name'] ?? ''), 160);
            $address = $this->sanitizeTextValue($item['address'] ?? '', 255);
            $notes = $this->sanitizeTextValue($item['notes'] ?? '', 255);
            $purchaseTaxRate = $this->sanitizePercentageValue($item['purchaseTaxRate'] ?? ($item['purchase_tax_rate'] ?? null));
            $id = $this->sanitizeTextValue($item['id'] ?? '', 160);

            $nameKey = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);
            $documentKey = $this->normalizeSupplierDocumentKey($document);

            if (isset($seenNames[$nameKey])) {
                continue;
            }
            if ($documentKey !== '' && isset($seenDocuments[$documentKey])) {
                continue;
            }

            $seenNames[$nameKey] = true;
            if ($documentKey !== '') {
                $seenDocuments[$documentKey] = true;
            }

            $normalized[] = [
                'id' => $id !== '' ? $id : $this->buildSupplierReferenceId($name, $document !== '' ? $document : (string)($index + 1)),
                'name' => $name,
                'document' => $document,
                'purchaseTaxRate' => $purchaseTaxRate,
                'email' => $email,
                'phone' => $phone,
                'contactName' => $contactName,
                'address' => $address,
                'notes' => $notes,
            ];
        }

        usort($normalized, static function ($left, $right) {
            return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        return array_values($normalized);
    }

    private function normalizeProductReferenceDataPayload($data) {
        $defaults = $this->getDefaultProductReferenceData();
        $source = is_array($data) ? $data : [];

        foreach (array_keys($defaults) as $key) {
            if ($key === 'brands') {
                $defaults[$key] = $this->sanitizeBrandReferenceList($source[$key] ?? []);
                continue;
            }

            if ($key === 'suppliers') {
                $defaults[$key] = $this->sanitizeSupplierReferenceList($source[$key] ?? []);
                continue;
            }

            if ($key === 'categoryImages') {
                $defaults[$key] = $this->sanitizeCategoryImageReferenceList($source[$key] ?? []);
                continue;
            }

            if ($key === 'commercialAttributes') {
                $defaults[$key] = $this->sanitizeCommercialAttributeReferenceList($source[$key] ?? []);
                continue;
            }

            $defaults[$key] = $this->sanitizeReferenceOptionList($source[$key] ?? [], $key);
        }

        $this->migrateMeasuredOptions($defaults);
        if (empty($defaults['commercialAttributes'])) {
            $defaults['commercialAttributes'] = $this->defaultCommercialAttributesFromLegacyCatalogs($defaults);
        }

        return $defaults;
    }

    private function loadProductReferenceData(bool $repairStoredCatalog = true) {
        $catalogRepository = new ProductReferenceCatalogRepository();

        if (!$catalogRepository->hasAnyEntries()) {
            // El fallback legacy solo necesita la DB de IdentityPlatform cuando
            // el catalogo owner aun esta vacio. Evita abrir una segunda conexion
            // en cada lectura publica del ecommerce.
            $settings = new SettingsRepository();
            $stored = $settings->getJson('product_reference_data', []);
            $normalizedLegacy = $this->normalizeProductReferenceDataPayload($stored);
            if ($normalizedLegacy !== $this->getDefaultProductReferenceData()) {
                if (!$repairStoredCatalog) {
                    return $normalizedLegacy;
                }
                $catalogRepository->replaceAll($normalizedLegacy);
            }
        }

        $stored = $catalogRepository->getAll();
        $normalized = $this->normalizeProductReferenceDataPayload($stored);

        if ($repairStoredCatalog && $stored !== $normalized) {
            $catalogRepository->replaceAll($normalized);
        }

        return $normalized;
    }

    private function authenticate() {
        return Auth::requireUser();
    }

    private function requireAdmin($user) {
        if (($user['role'] ?? 'customer') === 'admin') {
            return;
        }

        $repo = new UserRepository();
        $dbUser = $repo->getById($user['sub'] ?? '');
        if (($dbUser['role'] ?? 'customer') === 'admin') {
            return;
        }

        Response::error('No autorizado', 403, 'AUTH_FORBIDDEN');
        exit;
    }

    public function getVat() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        try {
            $taxConfiguration = CommerceCollaborationPortsFactory::taxConfiguration()->getTaxConfiguration();
        } catch (\Throwable $exception) {
            error_log('[TENANT_TAX_CONFIGURATION_READ_FAILED] ' . $exception->getMessage());
            Response::error('La configuración tributaria canónica no está disponible.', 503, 'TENANT_TAX_CONFIGURATION_REQUIRED');
            return;
        }
        $this->setTaxConfigurationEtag($taxConfiguration['revision']);
        Response::json([
            'rate' => $taxConfiguration['rate'],
            'credit_current_rate' => $taxConfiguration['credit_current_rate'],
            'credit_carryforward_rate' => $taxConfiguration['credit_carryforward_rate'],
            'tenant_registry_revision' => $taxConfiguration['revision'],
        ], 200, [
            'tenantRegistryRevision' => $taxConfiguration['revision'],
        ]);
    }

    public function updateVat() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || !array_key_exists('rate', $data)) {
            Response::error('IVA inválido', 400, 'SETTINGS_VAT_INVALID');
            return;
        }
        $rate = TenantDefaultTaxRate::normalizeVatRate($data['rate']);
        if ($rate === null) {
            Response::error('Tarifa IVA no soportada por el catálogo SRI.', 400, 'SETTINGS_VAT_UNSUPPORTED');
            return;
        }
        $taxConfigurationPort = CommerceCollaborationPortsFactory::taxConfiguration();
        try {
            $currentConfiguration = $taxConfigurationPort->getTaxConfiguration();
        } catch (\Throwable $exception) {
            error_log('[TENANT_TAX_CONFIGURATION_READ_FAILED] ' . $exception->getMessage());
            Response::error('La configuración tributaria canónica no está disponible.', 503, 'TENANT_TAX_CONFIGURATION_REQUIRED');
            return;
        }
        $expectedRevision = $this->expectedTaxConfigurationRevision();
        if ($expectedRevision === null) {
            $this->setTaxConfigurationEtag($currentConfiguration['revision']);
            Response::error('If-Match es obligatorio para actualizar la configuración tributaria.', 428, 'TENANT_TAX_PRECONDITION_REQUIRED');
            return;
        }
        if ($expectedRevision !== $currentConfiguration['revision']) {
            $this->setTaxConfigurationEtag($currentConfiguration['revision']);
            Response::error('La configuración tributaria cambió; recarga antes de guardar.', 412, 'TENANT_TAX_CONFIGURATION_REVISION_CONFLICT');
            return;
        }
        $creditCurrentRate = array_key_exists('credit_current_rate', $data)
            ? TenantDefaultTaxRate::normalize($data['credit_current_rate'])
            : $currentConfiguration['credit_current_rate'];
        $creditCarryforwardRate = array_key_exists('credit_carryforward_rate', $data)
            ? TenantDefaultTaxRate::normalize($data['credit_carryforward_rate'])
            : $currentConfiguration['credit_carryforward_rate'];
        if ($creditCurrentRate === null || $creditCarryforwardRate === null) {
            Response::error('Créditos tributarios inválidos', 400, 'SETTINGS_VAT_INVALID');
            return;
        }
        try {
            $taxConfiguration = $taxConfigurationPort->updateTaxConfiguration(
                    $rate,
                    $creditCurrentRate,
                    $creditCarryforwardRate,
                    (string)($user['sub'] ?? $user['id'] ?? 'tenant-settings-admin'),
                    $expectedRevision
                );
        } catch (\Throwable $exception) {
            error_log('[TENANT_DEFAULT_TAX_RATE_UPDATE_FAILED] ' . $exception->getMessage());
            if ($exception->getMessage() === 'TENANT_TAX_CONFIGURATION_REVISION_CONFLICT') {
                Response::error('La configuración tributaria cambió; recarga antes de guardar.', 412, 'TENANT_TAX_CONFIGURATION_REVISION_CONFLICT');
                return;
            }
            Response::error('No se pudo guardar la configuración tributaria.', 503, 'SETTINGS_VAT_UPDATE_UNAVAILABLE');
            return;
        }
        $this->setTaxConfigurationEtag($taxConfiguration['revision']);
        Response::json([
            'rate' => $taxConfiguration['rate'],
            'credit_current_rate' => $taxConfiguration['credit_current_rate'],
            'credit_carryforward_rate' => $taxConfiguration['credit_carryforward_rate'],
            'tenant_registry_revision' => $taxConfiguration['revision'],
        ], 200, [
            'tenantRegistryRevision' => $taxConfiguration['revision'],
            'applied' => $taxConfiguration['applied'],
            'catalogTaxProjectionSynchronized' => $taxConfiguration['projection_synchronized'],
            'catalogTaxProjectionMaxStalenessSeconds' => $taxConfiguration['projection_synchronized'] ? 15 : null,
            'projectionReconciliationRequired' => !$taxConfiguration['projection_synchronized'],
        ]);
    }

    private function expectedTaxConfigurationRevision(): ?int {
        $header = trim((string)($_SERVER['HTTP_IF_MATCH'] ?? ''));
        if (preg_match('/^"tenant-tax-([1-9][0-9]*)"$/', $header, $matches) !== 1) {
            return null;
        }
        $revision = filter_var($matches[1], FILTER_VALIDATE_INT);
        return is_int($revision) && $revision > 0 ? $revision : null;
    }

    private function setTaxConfigurationEtag(int $revision): void {
        header('ETag: "tenant-tax-' . $revision . '"');
        header('X-Tenant-Registry-Revision: ' . $revision);
    }

    public function getSessionSettings() {
        $user = $this->authenticate();
        $this->requireAdmin($user);

        $service = new SessionSettingsService();
        Response::json($service->getSettings());
    }

    public function updateSessionSettings() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (
            !isset($data['customerSessionHours']) || !is_numeric($data['customerSessionHours'])
            || !isset($data['adminSessionHours']) || !is_numeric($data['adminSessionHours'])
        ) {
            Response::error('Duración de sesión inválida', 400, 'SETTINGS_SESSION_INVALID');
            return;
        }

        $service = new SessionSettingsService();
        Response::json($service->updateSettings(
            $data['customerSessionHours'],
            $data['adminSessionHours']
        ));
    }

    public function getShipping() {
        $settings = new SettingsRepository();
        $shippingSettings = $settings->getMany([
            'shipping_delivery',
            'shipping_pickup',
            'shipping_tax_rate',
            'shipping_store_address',
            'shipping_store_latitude',
            'shipping_store_longitude',
            'free_shipping_radius_km',
            'shipping_km_flat_rate_limit',
            'shipping_per_km_rate',
            'shipping_map_min_search_chars',
            'shipping_map_lookup_cooldown_seconds',
            'shipping_map_session_lookup_limit',
        ]);
        $delivery = $shippingSettings['shipping_delivery'] ?? null;
        $pickup = $shippingSettings['shipping_pickup'] ?? null;
        $taxRate = $shippingSettings['shipping_tax_rate'] ?? null;
        $storeAddress = $shippingSettings['shipping_store_address'] ?? null;
        $storeLatitude = $shippingSettings['shipping_store_latitude'] ?? null;
        $storeLongitude = $shippingSettings['shipping_store_longitude'] ?? null;
        $freeRadius = $shippingSettings['free_shipping_radius_km'] ?? null;
        $kmFlatRateLimit = $shippingSettings['shipping_km_flat_rate_limit'] ?? null;
        $perKmRate = $shippingSettings['shipping_per_km_rate'] ?? null;
        $mapMinSearchChars = $shippingSettings['shipping_map_min_search_chars'] ?? null;
        $mapLookupCooldownSeconds = $shippingSettings['shipping_map_lookup_cooldown_seconds'] ?? null;
        $mapSessionLookupLimit = $shippingSettings['shipping_map_session_lookup_limit'] ?? null;
        $deliveryValue = is_numeric($delivery) ? floatval($delivery) : 5.0;
        $pickupValue = is_numeric($pickup) ? floatval($pickup) : 0.0;
        $taxValue = is_numeric($taxRate) ? floatval($taxRate) : 0.0;
        $storeAddressValue = $this->normalizeShippingStoreAddress(is_string($storeAddress) ? $storeAddress : null);
        $storeLatitudeValue = is_numeric($storeLatitude) ? floatval($storeLatitude) : self::DEFAULT_SHIPPING_STORE_LATITUDE;
        $storeLongitudeValue = is_numeric($storeLongitude) ? floatval($storeLongitude) : self::DEFAULT_SHIPPING_STORE_LONGITUDE;
        $migratedLegacyStore = $this->isLegacyShippingStoreLocation($storeAddressValue, $storeLatitudeValue, $storeLongitudeValue);
        if ($migratedLegacyStore) {
            $storeLatitudeValue = self::DEFAULT_SHIPPING_STORE_LATITUDE;
            $storeLongitudeValue = self::DEFAULT_SHIPPING_STORE_LONGITUDE;
            $storeAddressValue = self::DEFAULT_SHIPPING_STORE_ADDRESS;
        }
        $freeRadiusValue = is_numeric($freeRadius) ? max(0, floatval($freeRadius)) : 5.0;
        $kmFlatRateLimitValue = is_numeric($kmFlatRateLimit) ? max(0, floatval($kmFlatRateLimit)) : 7.0;
        $perKmRateValue = is_numeric($perKmRate) ? max(0, floatval($perKmRate)) : 1.0;
        $mapMinSearchCharsValue = is_numeric($mapMinSearchChars) ? max(3, (int)$mapMinSearchChars) : 6;
        $mapLookupCooldownSecondsValue = is_numeric($mapLookupCooldownSeconds) ? max(0, (int)$mapLookupCooldownSeconds) : 3;
        $mapSessionLookupLimitValue = is_numeric($mapSessionLookupLimit) ? max(1, (int)$mapSessionLookupLimit) : 12;
        // GET no materializa defaults ni repara datos legacy. La persistencia
        // pertenece al bootstrap/migraciones o al PUT administrativo explicito.
        $this->enableAnonymousPublicReadCache('/api/settings/shipping');
        Response::json([
            'delivery' => $deliveryValue,
            'pickup' => $pickupValue,
            'tax_rate' => $taxValue,
            'store_address' => $storeAddressValue,
            'store_latitude' => $storeLatitudeValue,
            'store_longitude' => $storeLongitudeValue,
            'free_shipping_radius_km' => $freeRadiusValue,
            'shipping_km_flat_rate_limit' => $kmFlatRateLimitValue,
            'shipping_per_km_rate' => $perKmRateValue,
            'map_min_search_chars' => $mapMinSearchCharsValue,
            'map_lookup_cooldown_seconds' => $mapLookupCooldownSecondsValue,
            'map_session_lookup_limit' => $mapSessionLookupLimitValue,
        ]);
    }

    public function updateShipping() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true);
        if (
            !isset($data['delivery']) || !is_numeric($data['delivery'])
            || !isset($data['pickup']) || !is_numeric($data['pickup'])
            || !isset($data['tax_rate']) || !is_numeric($data['tax_rate'])
            || !isset($data['store_address']) || trim((string)$data['store_address']) === ''
            || !isset($data['store_latitude']) || !is_numeric($data['store_latitude'])
            || !isset($data['store_longitude']) || !is_numeric($data['store_longitude'])
            || !isset($data['free_shipping_radius_km']) || !is_numeric($data['free_shipping_radius_km'])
            || !isset($data['map_min_search_chars']) || !is_numeric($data['map_min_search_chars'])
            || !isset($data['map_lookup_cooldown_seconds']) || !is_numeric($data['map_lookup_cooldown_seconds'])
            || !isset($data['map_session_lookup_limit']) || !is_numeric($data['map_session_lookup_limit'])
        ) {
            Response::error('Costos de envío inválidos', 400, 'SETTINGS_SHIPPING_INVALID');
            return;
        }
        $delivery = max(0, floatval($data['delivery']));
        $pickup = max(0, floatval($data['pickup']));
        $taxRate = max(0, floatval($data['tax_rate']));
        $storeAddress = trim((string)$data['store_address']);
        $storeLatitude = floatval($data['store_latitude']);
        $storeLongitude = floatval($data['store_longitude']);
        $freeRadius = max(0, floatval($data['free_shipping_radius_km']));
        $kmFlatRateLimit = isset($data['shipping_km_flat_rate_limit']) && is_numeric($data['shipping_km_flat_rate_limit'])
            ? max(0, floatval($data['shipping_km_flat_rate_limit']))
            : max(0, floatval($freeRadius));
        $perKmRate = isset($data['shipping_per_km_rate']) && is_numeric($data['shipping_per_km_rate'])
            ? max(0, floatval($data['shipping_per_km_rate']))
            : 0.0;
        $mapMinSearchCharsValue = max(3, (int)$data['map_min_search_chars']);
        $mapLookupCooldownSecondsValue = max(0, (int)$data['map_lookup_cooldown_seconds']);
        $mapSessionLookupLimitValue = max(1, (int)$data['map_session_lookup_limit']);
        if ($storeLatitude < -90 || $storeLatitude > 90 || $storeLongitude < -180 || $storeLongitude > 180) {
            Response::error('Coordenadas del local inválidas', 400, 'SETTINGS_SHIPPING_COORDINATES_INVALID');
            return;
        }
        $settings = new SettingsRepository();
        $settings->set('shipping_delivery', (string)$delivery);
        $settings->set('shipping_pickup', (string)$pickup);
        $settings->set('shipping_tax_rate', (string)$taxRate);
        $settings->set('shipping_store_address', $storeAddress);
        $settings->set('shipping_store_latitude', (string)$storeLatitude);
        $settings->set('shipping_store_longitude', (string)$storeLongitude);
        $settings->set('free_shipping_radius_km', (string)$freeRadius);
        $settings->set('shipping_km_flat_rate_limit', (string)$kmFlatRateLimit);
        $settings->set('shipping_per_km_rate', (string)$perKmRate);
        $settings->set('shipping_map_min_search_chars', (string)$mapMinSearchCharsValue);
        $settings->set('shipping_map_lookup_cooldown_seconds', (string)$mapLookupCooldownSecondsValue);
        $settings->set('shipping_map_session_lookup_limit', (string)$mapSessionLookupLimitValue);
        Response::json([
            'delivery' => $delivery,
            'pickup' => $pickup,
            'tax_rate' => $taxRate,
            'store_address' => $storeAddress,
            'store_latitude' => $storeLatitude,
            'store_longitude' => $storeLongitude,
            'free_shipping_radius_km' => $freeRadius,
            'shipping_km_flat_rate_limit' => $kmFlatRateLimit,
            'shipping_per_km_rate' => $perKmRate,
            'map_min_search_chars' => $mapMinSearchCharsValue,
            'map_lookup_cooldown_seconds' => $mapLookupCooldownSecondsValue,
            'map_session_lookup_limit' => $mapSessionLookupLimitValue,
        ]);
    }

    public function getStoreStatus() {
        $settings = new SettingsRepository();

        $enabledRaw = $settings->get('store_sales_enabled');
        $messageRaw = $settings->get('store_sales_message');
        $updatedAt = $settings->get('store_sales_updated_at');
        $updatedBy = $settings->get('store_sales_updated_by');

        $salesEnabled = $enabledRaw === null ? true : $this->parseBool($enabledRaw, true);
        $message = trim((string)($messageRaw ?? 'Tienda temporalmente en mantenimiento. Intenta más tarde.'));
        if ($message === '') {
            $message = 'Tienda temporalmente en mantenimiento. Intenta más tarde.';
        }

        if ($enabledRaw === null) {
            $settings->set('store_sales_enabled', '1');
        }
        if ($messageRaw === null) {
            $settings->set('store_sales_message', $message);
        }

        Response::json([
            'salesEnabled' => $salesEnabled,
            'message' => $message,
            'updatedAt' => $updatedAt ?: null,
            'updatedBy' => $updatedBy ?: null
        ]);
    }

    public function updateStoreStatus() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (!array_key_exists('salesEnabled', $data)) {
            Response::error('Campo salesEnabled requerido', 400, 'SETTINGS_STORE_STATUS_REQUIRED');
            return;
        }

        $salesEnabled = $this->parseBool($data['salesEnabled'], true);
        $message = trim((string)($data['message'] ?? ''));
        if ($message === '') {
            $message = 'Tienda temporalmente en mantenimiento. Intenta más tarde.';
        }

        if (!$salesEnabled && trim($message) === '') {
            Response::error('Debes indicar un mensaje claro de mantenimiento', 400, 'SETTINGS_STORE_MESSAGE_REQUIRED');
            return;
        }

        $settings = new SettingsRepository();
        $settings->set('store_sales_enabled', $salesEnabled ? '1' : '0');
        $settings->set('store_sales_message', $message);
        $settings->set('store_sales_updated_at', date('c'));
        $settings->set('store_sales_updated_by', (string)($user['sub'] ?? 'admin'));

        Response::json([
            'salesEnabled' => $salesEnabled,
            'message' => $message,
            'updatedAt' => $settings->get('store_sales_updated_at'),
            'updatedBy' => $settings->get('store_sales_updated_by')
        ]);
    }

    public function getProductPage() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();
        $deliveryEstimate = $settings->get('product_page_delivery_estimate') ?? '14 de enero - 18 de enero';
        $viewerCount = $settings->get('product_page_viewer_count');
        $freeShipping = $settings->get('product_page_free_shipping');
        $supportHours = $settings->get('product_page_support_hours') ?? '8:30 AM a 10:00 PM';
        $returnDays = $settings->get('product_page_return_days');

        Response::json([
            'deliveryEstimate' => $deliveryEstimate,
            'viewerCount' => is_numeric($viewerCount) ? intval($viewerCount) : 38,
            'freeShippingThreshold' => is_numeric($freeShipping) ? floatval($freeShipping) : 75,
            'supportHours' => $supportHours,
            'returnDays' => is_numeric($returnDays) ? intval($returnDays) : 100
        ]);
    }

    public function updateProductPage() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $settings = new SettingsRepository();

        $deliveryEstimate = trim((string)($data['deliveryEstimate'] ?? ''));
        $supportHours = trim((string)($data['supportHours'] ?? ''));

        $viewerCount = isset($data['viewerCount']) && is_numeric($data['viewerCount'])
            ? max(0, intval($data['viewerCount']))
            : null;
        $freeShipping = isset($data['freeShippingThreshold']) && is_numeric($data['freeShippingThreshold'])
            ? max(0, floatval($data['freeShippingThreshold']))
            : null;
        $returnDays = isset($data['returnDays']) && is_numeric($data['returnDays'])
            ? max(0, intval($data['returnDays']))
            : null;

        if ($deliveryEstimate !== '') {
            $settings->set('product_page_delivery_estimate', $deliveryEstimate);
        }
        if ($supportHours !== '') {
            $settings->set('product_page_support_hours', $supportHours);
        }
        if ($viewerCount !== null) {
            $settings->set('product_page_viewer_count', (string)$viewerCount);
        }
        if ($freeShipping !== null) {
            $settings->set('product_page_free_shipping', (string)$freeShipping);
        }
        if ($returnDays !== null) {
            $settings->set('product_page_return_days', (string)$returnDays);
        }

        Response::json([
            'deliveryEstimate' => $deliveryEstimate ?: ($settings->get('product_page_delivery_estimate') ?? '14 de enero - 18 de enero'),
            'viewerCount' => $viewerCount ?? (is_numeric($settings->get('product_page_viewer_count')) ? intval($settings->get('product_page_viewer_count')) : 38),
            'freeShippingThreshold' => $freeShipping ?? (is_numeric($settings->get('product_page_free_shipping')) ? floatval($settings->get('product_page_free_shipping')) : 75),
            'supportHours' => $supportHours ?: ($settings->get('product_page_support_hours') ?? '8:30 AM a 10:00 PM'),
            'returnDays' => $returnDays ?? (is_numeric($settings->get('product_page_return_days')) ? intval($settings->get('product_page_return_days')) : 100)
        ]);
    }

    public function getPricingMargins() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();

        $base = $this->getNumericSetting($settings, 'pricing_margin_base', 30);
        $min = $this->getNumericSetting($settings, 'pricing_margin_min', 15);
        $target = $this->getNumericSetting($settings, 'pricing_margin_target', 35);
        $promo = $this->getNumericSetting($settings, 'pricing_margin_promo_buffer', 5);

        if ($settings->get('pricing_margin_base') === null) {
            $settings->set('pricing_margin_base', (string)$base);
        }
        if ($settings->get('pricing_margin_min') === null) {
            $settings->set('pricing_margin_min', (string)$min);
        }
        if ($settings->get('pricing_margin_target') === null) {
            $settings->set('pricing_margin_target', (string)$target);
        }
        if ($settings->get('pricing_margin_promo_buffer') === null) {
            $settings->set('pricing_margin_promo_buffer', (string)$promo);
        }

        $min = max(0, $min);
        $base = max($min, $base);
        $target = max($base, $target);
        $promo = max(0, $promo);

        Response::json([
            'baseMargin' => $base,
            'minMargin' => $min,
            'targetMargin' => $target,
            'promoBuffer' => $promo
        ]);
    }

    public function updatePricingMargins() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (!isset($data['baseMargin']) || !is_numeric($data['baseMargin'])) {
            Response::error('Margen base inválido', 400, 'SETTINGS_MARGIN_BASE_INVALID');
            return;
        }
        if (!isset($data['minMargin']) || !is_numeric($data['minMargin'])) {
            Response::error('Margen mínimo inválido', 400, 'SETTINGS_MARGIN_MIN_INVALID');
            return;
        }
        if (!isset($data['targetMargin']) || !is_numeric($data['targetMargin'])) {
            Response::error('Margen objetivo inválido', 400, 'SETTINGS_MARGIN_TARGET_INVALID');
            return;
        }

        $base = max(0, floatval($data['baseMargin']));
        $min = max(0, floatval($data['minMargin']));
        $target = max(0, floatval($data['targetMargin']));
        $promo = isset($data['promoBuffer']) && is_numeric($data['promoBuffer']) ? max(0, floatval($data['promoBuffer'])) : 5;

        if ($base < $min) $base = $min;
        if ($target < $base) $target = $base;

        $settings = new SettingsRepository();
        $settings->set('pricing_margin_base', (string)$base);
        $settings->set('pricing_margin_min', (string)$min);
        $settings->set('pricing_margin_target', (string)$target);
        $settings->set('pricing_margin_promo_buffer', (string)$promo);

        Response::json([
            'baseMargin' => $base,
            'minMargin' => $min,
            'targetMargin' => $target,
            'promoBuffer' => $promo
        ]);
    }

    public function getPricingCalc() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();

        $rounding = $this->getNumericSetting($settings, 'pricing_calc_rounding', 0.05);
        $strategy = $settings->get('pricing_calc_strategy') ?? 'cost_plus';
        $includeVat = $this->parseBool($settings->get('pricing_calc_include_vat'), true);
        $shippingBuffer = $this->getNumericSetting($settings, 'pricing_calc_shipping_buffer', 0);

        if ($settings->get('pricing_calc_rounding') === null) {
            $settings->set('pricing_calc_rounding', (string)$rounding);
        }
        if ($settings->get('pricing_calc_strategy') === null) {
            $settings->set('pricing_calc_strategy', (string)$strategy);
        }
        if ($settings->get('pricing_calc_include_vat') === null) {
            $settings->set('pricing_calc_include_vat', $includeVat ? '1' : '0');
        }
        if ($settings->get('pricing_calc_shipping_buffer') === null) {
            $settings->set('pricing_calc_shipping_buffer', (string)$shippingBuffer);
        }

        $allowed = ['cost_plus', 'target_margin', 'competitive'];
        if (!in_array($strategy, $allowed, true)) {
            $strategy = 'cost_plus';
        }

        Response::json([
            'rounding' => max(0, $rounding),
            'strategy' => $strategy,
            'includeVatInPvp' => $includeVat,
            'shippingBuffer' => max(0, $shippingBuffer)
        ]);
    }

    public function updatePricingCalc() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (!isset($data['rounding']) || !is_numeric($data['rounding'])) {
            Response::error('Redondeo inválido', 400, 'SETTINGS_ROUNDING_INVALID');
            return;
        }
        if (!isset($data['strategy']) || !is_string($data['strategy'])) {
            Response::error('Estrategia inválida', 400, 'SETTINGS_STRATEGY_INVALID');
            return;
        }

        $rounding = max(0, floatval($data['rounding']));
        $strategy = trim((string)$data['strategy']);
        $allowed = ['cost_plus', 'target_margin', 'competitive'];
        if (!in_array($strategy, $allowed, true)) {
            $strategy = 'cost_plus';
        }
        $includeVat = isset($data['includeVatInPvp']) ? $this->parseBool($data['includeVatInPvp'], true) : true;
        $shippingBuffer = isset($data['shippingBuffer']) && is_numeric($data['shippingBuffer']) ? max(0, floatval($data['shippingBuffer'])) : 0;

        $settings = new SettingsRepository();
        $settings->set('pricing_calc_rounding', (string)$rounding);
        $settings->set('pricing_calc_strategy', (string)$strategy);
        $settings->set('pricing_calc_include_vat', $includeVat ? '1' : '0');
        $settings->set('pricing_calc_shipping_buffer', (string)$shippingBuffer);

        Response::json([
            'rounding' => $rounding,
            'strategy' => $strategy,
            'includeVatInPvp' => $includeVat,
            'shippingBuffer' => $shippingBuffer
        ]);
    }

    public function getPricingRules() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $settings = new SettingsRepository();

        $bulkThreshold = $this->getNumericSetting($settings, 'pricing_rule_bulk_threshold', 10);
        $bulkDiscount = $this->getNumericSetting($settings, 'pricing_rule_bulk_discount', 5);
        $clearanceThreshold = $this->getNumericSetting($settings, 'pricing_rule_clearance_threshold', 25);
        $clearanceDiscount = $this->getNumericSetting($settings, 'pricing_rule_clearance_discount', 15);

        if ($settings->get('pricing_rule_bulk_threshold') === null) {
            $settings->set('pricing_rule_bulk_threshold', (string)$bulkThreshold);
        }
        if ($settings->get('pricing_rule_bulk_discount') === null) {
            $settings->set('pricing_rule_bulk_discount', (string)$bulkDiscount);
        }
        if ($settings->get('pricing_rule_clearance_threshold') === null) {
            $settings->set('pricing_rule_clearance_threshold', (string)$clearanceThreshold);
        }
        if ($settings->get('pricing_rule_clearance_discount') === null) {
            $settings->set('pricing_rule_clearance_discount', (string)$clearanceDiscount);
        }

        Response::json([
            'bulkThreshold' => max(1, round($bulkThreshold)),
            'bulkDiscount' => max(0, min(90, $bulkDiscount)),
            'clearanceThreshold' => max(1, round($clearanceThreshold)),
            'clearanceDiscount' => max(0, min(90, $clearanceDiscount))
        ]);
    }

    public function updatePricingRules() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        $bulkThreshold = isset($data['bulkThreshold']) && is_numeric($data['bulkThreshold']) ? max(1, intval($data['bulkThreshold'])) : null;
        $bulkDiscount = isset($data['bulkDiscount']) && is_numeric($data['bulkDiscount']) ? max(0, min(90, floatval($data['bulkDiscount']))) : null;
        $clearanceThreshold = isset($data['clearanceThreshold']) && is_numeric($data['clearanceThreshold']) ? max(1, intval($data['clearanceThreshold'])) : null;
        $clearanceDiscount = isset($data['clearanceDiscount']) && is_numeric($data['clearanceDiscount']) ? max(0, min(90, floatval($data['clearanceDiscount']))) : null;

        if ($bulkThreshold === null || $bulkDiscount === null || $clearanceThreshold === null || $clearanceDiscount === null) {
            Response::error('Reglas de precio inválidas', 400, 'SETTINGS_PRICING_RULES_INVALID');
            return;
        }

        $settings = new SettingsRepository();
        $settings->set('pricing_rule_bulk_threshold', (string)$bulkThreshold);
        $settings->set('pricing_rule_bulk_discount', (string)$bulkDiscount);
        $settings->set('pricing_rule_clearance_threshold', (string)$clearanceThreshold);
        $settings->set('pricing_rule_clearance_discount', (string)$clearanceDiscount);

        Response::json([
            'bulkThreshold' => $bulkThreshold,
            'bulkDiscount' => $bulkDiscount,
            'clearanceThreshold' => $clearanceThreshold,
            'clearanceDiscount' => $clearanceDiscount
        ]);
    }

    public function getProductReferenceData() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        Response::json($this->loadProductReferenceData());
    }

    public function getPublicBrandLogos() {
        $normalized = $this->loadProductReferenceData(false);
        $brands = array_values(array_filter($normalized['brands'] ?? [], static function ($brand) {
            return is_array($brand) && trim((string)($brand['logoUrl'] ?? '')) !== '';
        }));

        $this->enableAnonymousPublicReadCache('/api/settings/brand-logos', true);
        Response::json($brands);
    }

    public function getPublicProductCategories() {
        $normalized = $this->loadProductReferenceData(false);
        $this->enableAnonymousPublicReadCache('/api/settings/product-categories', true);
        Response::json(array_values($normalized['categories'] ?? []));
    }

    public function getPublicProductCategoryReferences() {
        $normalized = $this->loadProductReferenceData(false);
        $categories = array_values($normalized['categories'] ?? []);
        $imagesByName = [];

        foreach (($normalized['categoryImages'] ?? []) as $imageReference) {
            if (!is_array($imageReference)) {
                continue;
            }
            $name = trim((string)($imageReference['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);
            $imagesByName[$key] = $imageReference;
        }

        $references = [];
$allCategories = $categories;
$seenCategories = [];

        foreach ($allCategories as $category) {
            $name = trim((string)$category);
            if ($name === '') {
                continue;
            }
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);
            $lookupKeys = [$key];
            if ($key === 'todas') {
                $lookupKeys[] = 'todos';
            }
            if ($key === 'ofertas') {
                $lookupKeys[] = 'descuentos';
            }
            if (isset($seenCategories[$key])) {
                continue;
            }
            $seenCategories[$key] = true;

            $imageReference = [];
            foreach ($lookupKeys as $lookupKey) {
                if (isset($imagesByName[$lookupKey])) {
                    $imageReference = $imagesByName[$lookupKey];
                    break;
                }
            }
            $featuredImages = is_array($imageReference['featuredImages'] ?? null) ? $imageReference['featuredImages'] : [];
            $legacyVisible = ($imageReference['showInImageSection'] ?? true) !== false;
            $showInTopSection = array_key_exists('showInTopSection', $imageReference)
                ? $this->parseBool($imageReference['showInTopSection'], $legacyVisible)
                : $legacyVisible;
            $showInFeaturedSection = array_key_exists('showInFeaturedSection', $imageReference)
                ? $this->parseBool($imageReference['showInFeaturedSection'], $legacyVisible)
                : $legacyVisible;

            $references[] = [
                'name' => $name,
                'topImageUrl' => trim((string)($imageReference['topImageUrl'] ?? '')),
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

        $this->enableAnonymousPublicReadCache('/api/settings/product-category-references', true);
        Response::json($references);
    }

    public function updateProductReferenceData() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        $normalized = $this->normalizeProductReferenceDataPayload($data);
        $catalogRepository = new ProductReferenceCatalogRepository();
        $catalogRepository->replaceAll($normalized);

        Response::json($normalized);
    }
}
