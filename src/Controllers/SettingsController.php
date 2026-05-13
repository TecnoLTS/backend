<?php

namespace App\Controllers;

use App\Repositories\SettingsRepository;
use App\Repositories\ProductReferenceCatalogRepository;
use App\Repositories\UserRepository;
use App\Support\ProductFieldValueNormalizer;
use App\Core\Response;
use App\Core\Auth;

class SettingsController {
    private function getDefaultProductReferenceData() {
        return [
            'categories' => [],
            'categoryImages' => [],
            'brands' => [],
            'suppliers' => [],
            'sizes' => [],
            'materials' => [],
            'colors' => [],
            'usages' => [],
            'presentations' => [],
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

            if (in_array($catalogKey, ['sizes', 'presentations'], true)) {
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

            $normalized[] = [
    'name' => $name,
    'topImageUrl' => $this->sanitizeAssetUrlValue($item['topImageUrl'] ?? ($item['imageUrl'] ?? ($item['image'] ?? ''))),
    'featuredImages' => [
        'mobilePrimary' => $this->sanitizeAssetUrlValue($featuredImages['mobilePrimary'] ?? ''),
        'mobileSecondary' => $this->sanitizeAssetUrlValue($featuredImages['mobileSecondary'] ?? ''),
        'desktopPrimary' => $this->sanitizeAssetUrlValue($featuredImages['desktopPrimary'] ?? ''),
        'desktopSecondary' => $this->sanitizeAssetUrlValue($featuredImages['desktopSecondary'] ?? ''),
    ],
    'showInImageSection' => array_key_exists('showInImageSection', $item)
        ? $this->parseBool($item['showInImageSection'], true)
        : true,
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

            $defaults[$key] = $this->sanitizeReferenceOptionList($source[$key] ?? [], $key);
        }

        return $defaults;
    }

    private function loadProductReferenceData() {
        $settings = new SettingsRepository();
        $catalogRepository = new ProductReferenceCatalogRepository();

        if (!$catalogRepository->hasAnyEntries()) {
            $stored = $settings->getJson('product_reference_data', []);
            $normalizedLegacy = $this->normalizeProductReferenceDataPayload($stored);
            if ($normalizedLegacy !== $this->getDefaultProductReferenceData()) {
                $catalogRepository->replaceAll($normalizedLegacy);
            }
        }

        $stored = $catalogRepository->getAll();
        $normalized = $this->normalizeProductReferenceDataPayload($stored);

        if ($stored !== $normalized) {
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
        $settings = new SettingsRepository();
        $rate = $settings->get('vat_rate');
        Response::json(['rate' => $rate !== null ? floatval($rate) : 0]);
    }

    public function updateVat() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['rate']) || !is_numeric($data['rate'])) {
            Response::error('IVA inválido', 400, 'SETTINGS_VAT_INVALID');
            return;
        }
        $rate = max(0, floatval($data['rate']));
        $settings = new SettingsRepository();
        $settings->set('vat_rate', (string)$rate);
        Response::json(['rate' => $rate]);
    }

    public function getShipping() {
        $settings = new SettingsRepository();
        $delivery = $settings->get('shipping_delivery');
        $pickup = $settings->get('shipping_pickup');
        $taxRate = $settings->get('shipping_tax_rate');
        $deliveryValue = is_numeric($delivery) ? floatval($delivery) : 5.0;
        $pickupValue = is_numeric($pickup) ? floatval($pickup) : 0.0;
        $taxValue = is_numeric($taxRate) ? floatval($taxRate) : 0.0;
        if ($delivery === null) {
            $settings->set('shipping_delivery', (string)$deliveryValue);
        }
        if ($pickup === null) {
            $settings->set('shipping_pickup', (string)$pickupValue);
        }
        if ($taxRate === null) {
            $settings->set('shipping_tax_rate', (string)$taxValue);
        }
        Response::json([
            'delivery' => $deliveryValue,
            'pickup' => $pickupValue,
            'tax_rate' => $taxValue
        ]);
    }

    public function updateShipping() {
        $user = $this->authenticate();
        $this->requireAdmin($user);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['delivery']) || !is_numeric($data['delivery']) || !isset($data['pickup']) || !is_numeric($data['pickup']) || !isset($data['tax_rate']) || !is_numeric($data['tax_rate'])) {
            Response::error('Costos de envío inválidos', 400, 'SETTINGS_SHIPPING_INVALID');
            return;
        }
        $delivery = max(0, floatval($data['delivery']));
        $pickup = max(0, floatval($data['pickup']));
        $taxRate = max(0, floatval($data['tax_rate']));
        $settings = new SettingsRepository();
        $settings->set('shipping_delivery', (string)$delivery);
        $settings->set('shipping_pickup', (string)$pickup);
        $settings->set('shipping_tax_rate', (string)$taxRate);
        Response::json([
            'delivery' => $delivery,
            'pickup' => $pickup,
            'tax_rate' => $taxRate
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
        $normalized = $this->loadProductReferenceData();
        $brands = array_values(array_filter($normalized['brands'] ?? [], static function ($brand) {
            return is_array($brand) && trim((string)($brand['logoUrl'] ?? '')) !== '';
        }));

        Response::json($brands);
    }

    public function getPublicProductCategories() {
        $normalized = $this->loadProductReferenceData();
        Response::json(array_values($normalized['categories'] ?? []));
    }

    public function getPublicProductCategoryReferences() {
        $normalized = $this->loadProductReferenceData();
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

            $featuredImages = is_array($imageReference['featuredImages'] ?? null) ? $imageReference['featuredImages'] : [];
            $references[] = [
    'name' => $name,
    'topImageUrl' => trim((string)($imageReference['topImageUrl'] ?? '')),
    'featuredImages' => [
        'mobilePrimary' => trim((string)($featuredImages['mobilePrimary'] ?? '')),
        'mobileSecondary' => trim((string)($featuredImages['mobileSecondary'] ?? '')),
        'desktopPrimary' => trim((string)($featuredImages['desktopPrimary'] ?? '')),
        'desktopSecondary' => trim((string)($featuredImages['desktopSecondary'] ?? '')),
    ],
    'showInImageSection' => ($imageReference['showInImageSection'] ?? true) !== false,
];
        }

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
