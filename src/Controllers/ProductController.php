<?php

namespace App\Controllers;

use App\Repositories\ProductRepository;
use App\Repositories\ProductReferenceCatalogRepository;
use App\Core\Auth;
use App\Core\Response;
use App\Support\ProductAudience;
use App\Support\ProductFieldValueNormalizer;
use App\Support\ProductSeoMetadata;
use App\Support\ProductVariantMetadata;

class ProductController {
    private $productRepository;

    public function __construct() {
        $this->productRepository = new ProductRepository();
    }

    private function normalizePublishedField(array &$data): void {
        $hasPublished = array_key_exists('published', $data);
        $hasIsPublished = array_key_exists('isPublished', $data);
        if (!$hasPublished && !$hasIsPublished) {
            return;
        }

        $rawValue = $hasPublished ? $data['published'] : $data['isPublished'];
        if (is_bool($rawValue)) {
            $data['published'] = $rawValue;
            unset($data['isPublished']);
            return;
        }

        if (is_string($rawValue) || is_numeric($rawValue)) {
            $normalized = filter_var($rawValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                $data['published'] = $normalized;
                unset($data['isPublished']);
                return;
            }
        }

        Response::error('Estado de publicación inválido', 400, 'PRODUCT_PUBLISHED_INVALID');
        exit;
    }

    private function includeUnpublishedFromRequest(): bool {
        $scope = strtolower(trim((string)($_GET['scope'] ?? '')));
        if ($scope !== 'admin') {
            return false;
        }

        Auth::requireAdmin();
        return true;
    }

    private function includeProcurementDetailFromRequest(): bool {
        $rawValue = $_GET['procurement_detail'] ?? ($_GET['procurementDetail'] ?? null);
        if ($rawValue === null) {
            return false;
        }

        $normalized = filter_var($rawValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $normalized === true;
    }

    private function normalizePurchaseInvoiceField(array &$data): void {
        if (!array_key_exists('purchaseInvoice', $data)) {
            return;
        }

        if (!is_array($data['purchaseInvoice'])) {
            Response::error('La factura de compra debe enviarse como objeto.', 400, 'PURCHASE_INVOICE_INVALID');
            exit;
        }

        $invoice = $data['purchaseInvoice'];
        $metadata = $invoice['metadata'] ?? null;
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $normalized = [
            'invoiceNumber' => trim((string)($invoice['invoiceNumber'] ?? $invoice['invoice_number'] ?? '')),
            'supplierName' => trim((string)($invoice['supplierName'] ?? $invoice['supplier_name'] ?? '')),
            'supplierDocument' => trim((string)($invoice['supplierDocument'] ?? $invoice['supplier_document'] ?? '')),
            'purchaseTaxRate' => trim((string)($invoice['purchaseTaxRate'] ?? $invoice['purchase_tax_rate'] ?? ($metadata['purchase_tax_rate'] ?? ($metadata['purchaseTaxRate'] ?? '')))),
            'issuedAt' => trim((string)($invoice['issuedAt'] ?? $invoice['issued_at'] ?? '')),
            'notes' => trim((string)($invoice['notes'] ?? '')),
            'metadata' => $metadata,
        ];
        if ($normalized['purchaseTaxRate'] !== '' && !isset($normalized['metadata']['purchase_tax_rate'])) {
            $normalized['metadata']['purchase_tax_rate'] = $normalized['purchaseTaxRate'];
        }

        $hasValue = false;
        foreach ($normalized as $key => $value) {
            if ($key === 'metadata') {
                continue;
            }
            if ($value !== '') {
                $hasValue = true;
                break;
            }
        }

        $data['purchaseInvoice'] = $hasValue ? $normalized : null;
    }

    private function normalizeInventoryActionField(array &$data): void {
        $rawAction = $data['inventoryAction'] ?? ($data['inventory_action'] ?? null);
        unset($data['inventory_action']);

        if ($rawAction === null || trim((string)$rawAction) === '') {
            unset($data['inventoryAction']);
            return;
        }

        $action = strtolower(trim((string)$rawAction));
        $allowed = ['initial_stock', 'adjustment', 'restock'];
        if (!in_array($action, $allowed, true)) {
            Response::error('Acción de inventario inválida.', 400, 'PRODUCT_INVENTORY_ACTION_INVALID');
            exit;
        }

        $data['inventoryAction'] = $action;
    }

    private function validateInventoryIntent(array &$data, int $stockDelta): void {
        if ($stockDelta === 0) {
            return;
        }

        $action = strtolower(trim((string)($data['inventoryAction'] ?? '')));
        if ($action === '') {
            Response::error('Indica si el cambio de stock es una compra o un ajuste de inventario.', 400, 'PRODUCT_INVENTORY_ACTION_REQUIRED');
            exit;
        }

        if ($action === 'restock') {
            if ($stockDelta <= 0) {
                Response::error('Registrar compra solo puede aumentar el stock.', 400, 'PRODUCT_RESTOCK_QUANTITY_INVALID');
                exit;
            }
            return;
        }

        if ($action === 'adjustment') {
            $reason = trim((string)($data['inventoryAdjustmentReason'] ?? $data['inventory_adjustment_reason'] ?? ''));
            unset($data['inventory_adjustment_reason']);
            if ($reason === '') {
                Response::error('Indica el motivo del ajuste de inventario.', 400, 'PRODUCT_INVENTORY_ADJUSTMENT_REASON_REQUIRED');
                exit;
            }
            $data['inventoryAdjustmentReason'] = $reason;
            return;
        }

        Response::error('Acción de inventario no válida para actualizar stock.', 400, 'PRODUCT_INVENTORY_ACTION_INVALID');
        exit;
    }

    private function normalizeBooleanFlag(mixed $value, bool $default = false): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float)$value !== 0.0;
        }
        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        return $default;
    }

    private function normalizeTaxSettings(array &$data, ?array $currentProduct = null): void {
        $currentAttributes = $currentProduct['attributes'] ?? [];
        if (!is_array($currentAttributes)) {
            $currentAttributes = [];
        }

        $attributes = $data['attributes'] ?? $currentAttributes;
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $rawTaxExempt = $data['taxExempt']
            ?? $attributes['taxExempt']
            ?? $attributes['tax_exempt']
            ?? $currentAttributes['taxExempt']
            ?? $currentAttributes['tax_exempt']
            ?? false;

        $attributes['taxExempt'] = $this->normalizeBooleanFlag($rawTaxExempt, false) ? 'true' : 'false';
        unset($attributes['tax_exempt'], $data['taxExempt']);

        $data['attributes'] = $attributes;
    }

    private function validatePurchaseInvoice(?array $purchaseInvoice, bool $required): void {
        if (!$required) {
            return;
        }
        if (!$purchaseInvoice) {
            Response::error('Debes registrar la factura de compra para ingresar stock.', 400, 'PURCHASE_INVOICE_REQUIRED');
            exit;
        }
        if (($purchaseInvoice['invoiceNumber'] ?? '') === '') {
            Response::error('El número de factura de compra es obligatorio.', 400, 'PURCHASE_INVOICE_NUMBER_REQUIRED');
            exit;
        }
        if (($purchaseInvoice['supplierName'] ?? '') === '') {
            Response::error('El proveedor de la factura de compra es obligatorio.', 400, 'PURCHASE_INVOICE_SUPPLIER_REQUIRED');
            exit;
        }
        if (($purchaseInvoice['supplierDocument'] ?? '') === '') {
            Response::error('El proveedor seleccionado debe tener RUC o documento registrado.', 400, 'PURCHASE_INVOICE_SUPPLIER_DOCUMENT_REQUIRED');
            exit;
        }
        $issuedAt = (string)($purchaseInvoice['issuedAt'] ?? '');
        if ($issuedAt === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $issuedAt) !== 1) {
            Response::error('La fecha de la factura de compra es obligatoria y debe usar formato YYYY-MM-DD.', 400, 'PURCHASE_INVOICE_DATE_INVALID');
            exit;
        }
        $metadata = is_array($purchaseInvoice['metadata'] ?? null) ? $purchaseInvoice['metadata'] : [];
        $taxRateRaw = $purchaseInvoice['purchaseTaxRate'] ?? ($purchaseInvoice['purchase_tax_rate'] ?? ($metadata['purchase_tax_rate'] ?? ($metadata['purchaseTaxRate'] ?? null)));
        $taxRateText = trim(str_replace(',', '.', (string)$taxRateRaw));
        if ($taxRateText === '') {
            Response::error('El IVA de compra es obligatorio.', 400, 'PURCHASE_INVOICE_TAX_RATE_REQUIRED');
            exit;
        }
        if (!is_numeric($taxRateText) || floatval($taxRateText) < 0 || floatval($taxRateText) > 100) {
            Response::error('El IVA de compra debe estar entre 0% y 100%.', 400, 'PURCHASE_INVOICE_TAX_RATE_INVALID');
            exit;
        }
    }

    private function validatePurchaseUnitCost(array $data, ?array $currentProduct = null): void {
        $rawCost = array_key_exists('cost', $data) ? $data['cost'] : ($currentProduct['cost'] ?? null);
        if (!is_numeric($rawCost) || floatval($rawCost) <= 0) {
            Response::error('El costo de compra es obligatorio para ingresar stock.', 400, 'PRODUCT_PURCHASE_COST_REQUIRED');
            exit;
        }
    }

    private function referenceCatalogIdentity(string $value): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $value));
        if (class_exists('\Normalizer')) {
            $normalized = \Normalizer::normalize($normalized, \Normalizer::FORM_D) ?: $normalized;
            $normalized = preg_replace('/\p{Mn}+/u', '', $normalized) ?: $normalized;
        }
        return function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
    }

    private function normalizeReferenceCatalogValue(string $catalogKey, string $value): string {
        $text = trim(preg_replace('/\s+/', ' ', $value));
        if ($text === '') {
            return '';
        }
        if (in_array($catalogKey, ['sizes', 'weights', 'presentations', 'dosages'], true)) {
            return ProductFieldValueNormalizer::normalizeDisplayValue($text);
        }
        return $text;
    }

    private function parseProductCatalogCategories(mixed $value): array {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return preg_split('/\s*,\s*/', $value) ?: [];
    }

    private function isContentMeasurementValue(string $value): bool {
        return preg_match('/\d+(?:[.,]\d+)?\s*(?:KGS?|KG|K|GR|G|ML|L|MG|LB|OZ)\b/iu', trim($value)) === 1;
    }

    private function syncProductReferenceCatalog(array $data): void {
        $repository = new ProductReferenceCatalogRepository();
        $catalog = $repository->getAll();
        $changed = false;
        $addValue = function (string $catalogKey, mixed $rawValue) use (&$catalog, &$changed): string {
            if (!isset($catalog[$catalogKey]) || !is_array($catalog[$catalogKey])) {
                return '';
            }

            $value = $this->normalizeReferenceCatalogValue($catalogKey, (string)$rawValue);
            if ($value === '') {
                return '';
            }

            $valueIdentity = $this->referenceCatalogIdentity($value);
            foreach ($catalog[$catalogKey] as $existingValue) {
                if ($this->referenceCatalogIdentity((string)$existingValue) === $valueIdentity) {
                    return (string)$existingValue;
                }
            }

            $catalog[$catalogKey][] = $value;
            usort($catalog[$catalogKey], fn($left, $right) => strcasecmp((string)$left, (string)$right));
            $changed = true;
            return $value;
        };

        $addValue('categories', $data['category'] ?? '');

        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        foreach ($this->parseProductCatalogCategories($attributes['catalogCategories'] ?? []) as $category) {
            $addValue('categories', $category);
        }

        $attributeCatalogMap = [
            'size' => 'sizes',
            'weight' => 'weights',
            'volume' => 'weights',
            'presentation' => 'presentations',
            'packaging' => 'presentations',
            'dosage' => 'dosages',
            'material' => 'materials',
            'color' => 'colors',
            'usage' => 'usages',
            'activeIngredient' => 'activeIngredients',
            'storageLocation' => 'storageLocations',
            'tag' => 'tags',
            'flavor' => 'flavors',
            'age' => 'ageRanges',
        ];

        foreach ($attributeCatalogMap as $attributeKey => $catalogKey) {
            if (isset($attributes[$attributeKey])) {
                if ($attributeKey === 'size' && $this->isContentMeasurementValue((string)$attributes[$attributeKey])) {
                    $catalogKey = 'weights';
                }
                $addValue($catalogKey, $attributes[$attributeKey]);
            }
        }

        if ($changed) {
            $repository->replaceAll($catalog);
        }
    }

    private function applyProductSeoDefaults(array &$data, ?array $currentProduct = null): void {
        ProductSeoMetadata::applyDefaults($data, $currentProduct);
    }

    private function enforcePublicationEligibility(array &$data, ?array $currentProduct = null): void {
        $requestedPublish = array_key_exists('published', $data) && $data['published'] === true;
        $effectivePrice = array_key_exists('price', $data)
            ? (float)($data['price'] ?? 0)
            : (float)($currentProduct['price'] ?? 0);
        $effectiveQuantity = array_key_exists('quantity', $data)
            ? (int)($data['quantity'] ?? 0)
            : (int)($currentProduct['quantity'] ?? 0);

        $canPublish = $effectivePrice > 0 && $effectiveQuantity > 0;
        if (!$canPublish) {
            $data['published'] = false;
        } elseif (!array_key_exists('published', $data) && $currentProduct === null) {
            $data['published'] = false;
        }

        if ($requestedPublish) {
            $missing = $this->publicationSeoGaps($data, $currentProduct, $effectivePrice);
            if ($missing !== []) {
                Response::error('No se puede publicar: faltan mínimos SEO del producto.', 400, 'PRODUCT_SEO_PUBLICATION_REQUIRED', ['fields' => $missing]);
                exit;
            }
        }
    }

    private function publicationSeoGaps(array $data, ?array $currentProduct, float $effectivePrice): array {
        $attributes = $data['attributes'] ?? ($currentProduct['attributes'] ?? []);
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $description = trim((string)($data['description'] ?? ($currentProduct['description'] ?? '')));
        $fields = [
            'brand' => trim((string)($data['brand'] ?? ($currentProduct['brand'] ?? ''))),
            'sku' => trim((string)($attributes['sku'] ?? '')),
            'species' => trim((string)($attributes['species'] ?? '')),
            'category' => trim((string)($data['category'] ?? ($currentProduct['category'] ?? ''))),
        ];

        $missing = [];
        foreach ($fields as $field => $value) {
            if ($value === '') {
                $missing[] = $field;
            }
        }
        if ($description === '' || mb_strlen($description) < 50) {
            $missing[] = 'description';
        }
        if ($effectivePrice <= 0) {
            $missing[] = 'price';
        }
        if (!$this->hasEffectiveImageSet($data, $currentProduct, 'thumb')) {
            $missing[] = 'thumbnail';
        }
        if (!$this->hasEffectiveImageSet($data, $currentProduct, 'gallery')) {
            $missing[] = 'product_image';
        }
        $missing = array_merge($missing, ProductSeoMetadata::seoFieldGaps($data, $currentProduct));

        return array_values(array_unique($missing));
    }

    private function hasEffectiveImageSet(array $data, ?array $currentProduct, string $kind): bool {
        if ($kind === 'thumb') {
            if (array_key_exists('thumbImages', $data) || array_key_exists('thumbImage', $data)) {
                return $this->hasAnyImage($data['thumbImages'] ?? $data['thumbImage'] ?? []);
            }
            return $this->hasAnyImage($currentProduct['thumbImage'] ?? []);
        }

        if (array_key_exists('images', $data) || array_key_exists('galleryImages', $data) || array_key_exists('image', $data)) {
            return $this->hasAnyImage($data['images'] ?? $data['galleryImages'] ?? $data['image'] ?? []);
        }
        return $this->hasAnyImage($currentProduct['images'] ?? []);
    }

    private function hasAnyImage($value): bool {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                return true;
            }
            if (is_array($item) && trim((string)($item['url'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function normalizeAudienceFields(array &$data, ?array $currentProduct = null): void {
        $currentAttributes = $currentProduct['attributes'] ?? [];
        if (!is_array($currentAttributes)) {
            $currentAttributes = [];
        }

        $attributes = $data['attributes'] ?? $currentAttributes;
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $normalizedType = ProductAudience::normalizeProductType(
            (string)($data['productType'] ?? $data['product_type'] ?? ($currentProduct['productType'] ?? $currentProduct['product_type'] ?? '')),
            (string)($data['category'] ?? ($currentProduct['category'] ?? ''))
        );
        if ($normalizedType !== '') {
            $data['productType'] = $normalizedType;
            unset($data['product_type']);
        }

        $category = ProductAudience::normalizeCategory(
            (string)($data['category'] ?? ($currentProduct['category'] ?? '')),
            $normalizedType
        );
        if ($category !== '' || array_key_exists('category', $data)) {
            $data['category'] = $category;
        }

        $normalizedSpecies = ProductAudience::normalizeSpeciesLabel(
            (string)($attributes['species'] ?? ''),
            (string)($data['gender'] ?? ($currentProduct['gender'] ?? ''))
        );
        if ($normalizedSpecies !== '') {
            $attributes['species'] = $normalizedSpecies;
        }

        $data['attributes'] = $attributes;
        $data['gender'] = ProductAudience::resolveGender(
            $attributes['species'] ?? null,
            (string)($data['gender'] ?? ($currentProduct['gender'] ?? ''))
        );
    }

    private function applyVariantMetadata(array &$data, ?array $currentProduct = null): void {
        $effectiveAttributes = $data['attributes'] ?? ($currentProduct['attributes'] ?? []);
        if (!is_array($effectiveAttributes)) {
            $effectiveAttributes = [];
        }

        $effectiveProduct = [
            'id' => $data['id'] ?? ($currentProduct['id'] ?? ''),
            'internalId' => $data['internalId'] ?? ($currentProduct['internalId'] ?? ''),
            'legacyId' => $data['legacyId'] ?? ($currentProduct['legacyId'] ?? ''),
            'name' => $data['name'] ?? ($currentProduct['name'] ?? ''),
            'description' => $data['description'] ?? ($currentProduct['description'] ?? ''),
            'brand' => $data['brand'] ?? ($currentProduct['brand'] ?? ''),
            'category' => $data['category'] ?? ($currentProduct['category'] ?? ''),
            'productType' => $data['productType'] ?? ($data['product_type'] ?? ($currentProduct['productType'] ?? ($currentProduct['product_type'] ?? ''))),
            'product_type' => $data['product_type'] ?? ($data['productType'] ?? ($currentProduct['product_type'] ?? ($currentProduct['productType'] ?? ''))),
            'gender' => $data['gender'] ?? ($currentProduct['gender'] ?? ''),
            'variantLabel' => $data['variantLabel'] ?? ($currentProduct['variantLabel'] ?? ''),
            'variantBaseName' => $data['variantBaseName'] ?? '',
            'variantGroupKey' => $data['variantGroupKey'] ?? '',
        ];

        $data['attributes'] = ProductVariantMetadata::apply($effectiveProduct, $effectiveAttributes);
    }

    private function validateVariantMetadata(array $data, ?array $currentProduct = null): void {
        $effectiveAttributes = $data['attributes'] ?? ($currentProduct['attributes'] ?? []);
        if (!is_array($effectiveAttributes)) {
            $effectiveAttributes = [];
        }

        $effectiveProduct = [
            'id' => $data['id'] ?? ($currentProduct['id'] ?? ''),
            'internalId' => $data['internalId'] ?? ($currentProduct['internalId'] ?? ''),
            'legacyId' => $data['legacyId'] ?? ($currentProduct['legacyId'] ?? ''),
            'name' => $data['name'] ?? ($currentProduct['name'] ?? ''),
            'description' => $data['description'] ?? ($currentProduct['description'] ?? ''),
            'brand' => $data['brand'] ?? ($currentProduct['brand'] ?? ''),
            'category' => $data['category'] ?? ($currentProduct['category'] ?? ''),
            'productType' => $data['productType'] ?? ($data['product_type'] ?? ($currentProduct['productType'] ?? ($currentProduct['product_type'] ?? ''))),
            'product_type' => $data['product_type'] ?? ($data['productType'] ?? ($currentProduct['product_type'] ?? ($currentProduct['productType'] ?? ''))),
            'gender' => $data['gender'] ?? ($currentProduct['gender'] ?? ''),
            'variantLabel' => $data['variantLabel'] ?? ($currentProduct['variantLabel'] ?? ''),
            'variantBaseName' => $data['variantBaseName'] ?? '',
            'variantGroupKey' => $data['variantGroupKey'] ?? '',
        ];

        $hasVariantContext =
            trim((string)($effectiveProduct['variantLabel'] ?? '')) !== '' ||
            trim((string)($effectiveProduct['variantBaseName'] ?? '')) !== '' ||
            trim((string)($effectiveProduct['variantGroupKey'] ?? '')) !== '' ||
            trim((string)($effectiveAttributes['variantLabel'] ?? '')) !== '' ||
            trim((string)($effectiveAttributes['variantBaseName'] ?? '')) !== '' ||
            trim((string)($effectiveAttributes['variantGroupKey'] ?? '')) !== '' ||
            trim((string)($effectiveAttributes['catalogDisplayMode'] ?? '')) === 'grouped' ||
            trim((string)($effectiveAttributes['variantDefinitionField'] ?? '')) !== '' ||
            trim((string)($effectiveAttributes['variantAxis'] ?? '')) !== '';

        if (!$hasVariantContext) {
            return;
        }

        $variantLabel = ProductVariantMetadata::resolveVariantLabel($effectiveProduct, $effectiveAttributes);
        if ($variantLabel === '') {
            $normalizedType = ProductAudience::normalizeProductType(
                (string)($effectiveProduct['productType'] ?? $effectiveProduct['product_type'] ?? ''),
                (string)($effectiveProduct['category'] ?? '')
            );
            $message = match ($normalizedType) {
                'cuidado' => 'Selecciona o crea el peso/contenido, la presentación, la dosis o el rango recomendado del producto.',
                'Alimento' => 'Selecciona o crea el campo que define la variante del alimento: peso, contenido, empaque, sabor o etapa.',
                default => 'Selecciona o crea la talla, color, presentación o medida que diferencia la variante.',
            };
            Response::error($message, 400, 'PRODUCT_VARIANT_LABEL_REQUIRED');
            exit;
        }
    }

    public function index() {
        try {
            $includeUnpublished = $this->includeUnpublishedFromRequest();
            $products = $this->productRepository->getAll([
                'includeUnpublished' => $includeUnpublished,
                'includeProcurement' => $includeUnpublished,
                'includeOutOfStock' => $includeUnpublished,
            ]);
            Response::json($products);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCTS_LIST_FAILED');
        }
    }

    public function show($id) {
        try {
            $includeUnpublished = $this->includeUnpublishedFromRequest();
            $includeProcurementDetail = $includeUnpublished && $this->includeProcurementDetailFromRequest();
            $product = $this->productRepository->getById($id, [
                'includeUnpublished' => $includeUnpublished,
                'includeProcurement' => $includeUnpublished,
                'includeProcurementDetail' => $includeProcurementDetail,
                'includeOutOfStock' => $includeUnpublished,
            ]);
            if (!$product) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }
            Response::json($product);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_FETCH_FAILED');
        }
    }

    public function movement($id) {
        Auth::requireAdmin();
        $period = strtolower(trim((string)($_GET['period'] ?? 'month')));

        try {
            $movement = $this->productRepository->getMovementSummary($id, $period);
            if (!$movement) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }
            Response::json($movement);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_MOVEMENT_PERIOD_INVALID');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_MOVEMENT_FAILED');
        }
    }

    public function store() {
        Auth::requireAdmin();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $data['id'] = uniqid('prod_');
            $this->normalizePublishedField($data);
            $this->normalizePurchaseInvoiceField($data);
            $this->normalizeInventoryActionField($data);
            $this->normalizeAudienceFields($data, null);
            $this->normalizeTaxSettings($data, null);
            $productType = ProductAudience::normalizeProductType((string)($data['productType'] ?? $data['product_type'] ?? ''), (string)($data['category'] ?? ''));
            $attributes = $data['attributes'] ?? [];
            $allowedTypes = ['Alimento', 'ropa', 'cuidado', 'accesorios'];
            if (!$productType) {
                Response::error('Tipo de producto requerido', 400, 'PRODUCT_TYPE_REQUIRED');
                return;
            }
            if (!in_array($productType, $allowedTypes, true)) {
                Response::error('Tipo de producto inválido', 400, 'PRODUCT_TYPE_INVALID');
                return;
            }
            $requiredFields = ['name', 'category', 'price', 'quantity', 'brand'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || (is_string($data[$field]) && trim((string)$data[$field]) === '')) {
                    Response::error('Campos obligatorios incompletos', 400, 'PRODUCT_FIELDS_REQUIRED', ['field' => $field]);
                    return;
                }
            }
            if (!is_numeric($data['price']) || floatval($data['price']) < 0) {
                Response::error('Precio inválido', 400, 'PRODUCT_PRICE_INVALID');
                return;
            }
            if (!is_numeric($data['quantity']) || intval($data['quantity']) < 0) {
                Response::error('Cantidad inválida', 400, 'PRODUCT_QUANTITY_INVALID');
                return;
            }
            if (isset($data['cost']) && (!is_numeric($data['cost']) || floatval($data['cost']) < 0)) {
                Response::error('Costo inválido', 400, 'PRODUCT_COST_INVALID');
                return;
            }
            if (isset($data['cost']) && is_numeric($data['cost']) && floatval($data['cost']) > 0 && floatval($data['price']) < floatval($data['cost'])) {
                Response::error('El precio base no puede ser menor al costo del producto.', 400, 'PRODUCT_PRICE_BELOW_COST');
                return;
            }
            $quantity = isset($data['quantity']) && is_numeric($data['quantity']) ? intval($data['quantity']) : 0;
            if ($quantity > 0 && !isset($data['inventoryAction'])) {
                $data['inventoryAction'] = 'initial_stock';
            }
            if ($quantity > 0 && (($data['inventoryAction'] ?? 'initial_stock') !== 'initial_stock')) {
                Response::error('El stock inicial de un producto nuevo debe registrarse como stock inicial.', 400, 'PRODUCT_INITIAL_STOCK_ACTION_INVALID');
                return;
            }
            if ($quantity > 0) {
                $this->validatePurchaseUnitCost($data);
            }
            $this->validatePurchaseInvoice($data['purchaseInvoice'] ?? null, $quantity > 0);
            if (!isset($data['description']) || trim((string)$data['description']) === '') {
                Response::error('Descripción requerida', 400, 'PRODUCT_DESCRIPTION_REQUIRED');
                return;
            }
            $required = ['sku', 'species'];
            foreach ($required as $key) {
                if (!isset($attributes[$key]) || trim((string)$attributes[$key]) === '') {
                    Response::error('Atributos obligatorios incompletos', 400, 'PRODUCT_ATTRIBUTES_REQUIRED', ['field' => $key]);
                    return;
                }
            }
            $normalizedSku = strtoupper(trim((string)($attributes['sku'] ?? '')));
            if ($normalizedSku !== '' && $this->productRepository->skuExists($normalizedSku)) {
                Response::error('Ya existe un producto con ese SKU', 400, 'PRODUCT_SKU_DUPLICATE');
                return;
            }
            $expirationDateRaw = trim((string)($attributes['expirationDate'] ?? $attributes['expiryDate'] ?? ''));
            if ($productType === 'Alimento' && $quantity > 0 && $expirationDateRaw === '') {
                Response::error('La fecha de vencimiento es obligatoria para productos de Alimento.', 400, 'PRODUCT_EXPIRY_DATE_REQUIRED');
                return;
            }
            if ($expirationDateRaw !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDateRaw) !== 1) {
                    Response::error('Fecha de vencimiento inválida. Usa formato YYYY-MM-DD.', 400, 'PRODUCT_EXPIRY_DATE_INVALID');
                    return;
                }
                $expirationDate = \DateTimeImmutable::createFromFormat('Y-m-d', $expirationDateRaw);
                if (!($expirationDate instanceof \DateTimeImmutable)) {
                    Response::error('Fecha de vencimiento inválida.', 400, 'PRODUCT_EXPIRY_DATE_INVALID');
                    return;
                }
                $attributes['expirationDate'] = $expirationDate->format('Y-m-d');

                $alertRaw = $attributes['expirationAlertDays'] ?? $attributes['expiryAlertDays'] ?? null;
                if ($alertRaw === null || $alertRaw === '') {
                    $attributes['expirationAlertDays'] = '30';
                } elseif (!is_numeric($alertRaw) || intval($alertRaw) < 0) {
                    Response::error('Días de alerta de vencimiento inválidos.', 400, 'PRODUCT_EXPIRY_ALERT_DAYS_INVALID');
                    return;
                } else {
                    $attributes['expirationAlertDays'] = (string)min(3650, max(0, intval($alertRaw)));
                }
            } else {
                unset($attributes['expirationDate'], $attributes['expiryDate'], $attributes['expirationAlertDays'], $attributes['expiryAlertDays']);
            }
            if (empty($attributes['supplier']) && !empty($data['purchaseInvoice']['supplierName'])) {
                $attributes['supplier'] = trim((string)$data['purchaseInvoice']['supplierName']);
            }
            $data['attributes'] = $attributes;
            $this->normalizeAudienceFields($data, null);
            $this->validateVariantMetadata($data, null);
            $this->applyVariantMetadata($data, null);
            $this->applyProductSeoDefaults($data, null);
            $this->syncProductReferenceCatalog($data);
            $this->enforcePublicationEligibility($data, null);
            $product = $this->productRepository->create($data);
            Response::json($product, 201);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_CREATE_FAILED');
        }
    }

    public function update($id) {
        Auth::requireAdmin();
        try {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $this->normalizePublishedField($data);
            $this->normalizePurchaseInvoiceField($data);
            $this->normalizeInventoryActionField($data);
            $productType = isset($data['productType']) || isset($data['product_type'])
                ? ProductAudience::normalizeProductType(
                    (string)($data['productType'] ?? $data['product_type'] ?? ''),
                    (string)($data['category'] ?? '')
                )
                : null;
            if ($productType !== null) {
                $allowedTypes = ['Alimento', 'ropa', 'cuidado', 'accesorios'];
                if ($productType === '') {
                    Response::error('Tipo de producto requerido', 400, 'PRODUCT_TYPE_REQUIRED');
                    return;
                }
                if (!in_array($productType, $allowedTypes, true)) {
                    Response::error('Tipo de producto inválido', 400, 'PRODUCT_TYPE_INVALID');
                    return;
                }
            }
            $numericRules = [
                'price' => 'PRODUCT_PRICE_INVALID',
                'quantity' => 'PRODUCT_QUANTITY_INVALID',
                'cost' => 'PRODUCT_COST_INVALID'
            ];
            foreach ($numericRules as $field => $errorCode) {
                if (isset($data[$field])) {
                    if (!is_numeric($data[$field]) || floatval($data[$field]) < 0) {
                        Response::error('Valor inválido', 400, $errorCode, ['field' => $field]);
                        return;
                    }
                }
            }
            if (array_key_exists('price', $data) || array_key_exists('cost', $data)) {
                $currentProduct = $this->productRepository->getById($id, ['includeUnpublished' => true]);
                if (!$currentProduct) {
                    Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                    return;
                }
                $effectivePrice = array_key_exists('price', $data) && is_numeric($data['price'])
                    ? floatval($data['price'])
                    : floatval($currentProduct['price'] ?? 0);
                $effectiveCost = array_key_exists('cost', $data) && is_numeric($data['cost'])
                    ? floatval($data['cost'])
                    : floatval($currentProduct['cost'] ?? 0);
                if ($effectiveCost > 0 && $effectivePrice < $effectiveCost && array_key_exists('price', $data)) {
                    Response::error('El precio base no puede ser menor al costo del producto.', 400, 'PRODUCT_PRICE_BELOW_COST');
                    return;
                }
            }
            $requiredFields = ['name', 'category', 'brand', 'description'];
            foreach ($requiredFields as $field) {
                if (array_key_exists($field, $data) && is_string($data[$field]) && trim((string)$data[$field]) === '') {
                    Response::error('Campos obligatorios incompletos', 400, 'PRODUCT_FIELDS_REQUIRED', ['field' => $field]);
                    return;
                }
            }
            if (isset($data['productType']) || isset($data['product_type']) || isset($data['attributes'])) {
                $currentProduct = $this->productRepository->getById($id, ['includeUnpublished' => true]);
                if (!$currentProduct) {
                    Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                    return;
                }

                $attributes = $data['attributes'] ?? ($currentProduct['attributes'] ?? []);
                if (!is_array($attributes)) {
                    $attributes = [];
                }
                $required = ['sku', 'species'];
                foreach ($required as $key) {
                    if (!isset($attributes[$key]) || trim((string)$attributes[$key]) === '') {
                        Response::error('Atributos obligatorios incompletos', 400, 'PRODUCT_ATTRIBUTES_REQUIRED', ['field' => $key]);
                        return;
                    }
                }
                $currentProductId = (string)($currentProduct['id'] ?? '');
                $normalizedSku = strtoupper(trim((string)($attributes['sku'] ?? '')));
                if ($normalizedSku !== '' && $this->productRepository->skuExists($normalizedSku, $currentProductId)) {
                    Response::error('Ya existe un producto con ese SKU', 400, 'PRODUCT_SKU_DUPLICATE');
                    return;
                }
                $effectiveType = $productType ?? ProductAudience::normalizeProductType(
                    (string)($currentProduct['productType'] ?? ''),
                    (string)($currentProduct['category'] ?? '')
                );
                $effectiveQuantity = isset($data['quantity']) && is_numeric($data['quantity'])
                    ? intval($data['quantity'])
                    : intval($currentProduct['quantity'] ?? 0);
                $expirationDateRaw = trim((string)($attributes['expirationDate'] ?? $attributes['expiryDate'] ?? ''));
                if ($effectiveType === 'Alimento' && $effectiveQuantity > 0 && $expirationDateRaw === '') {
                    Response::error('La fecha de vencimiento es obligatoria para productos de Alimento.', 400, 'PRODUCT_EXPIRY_DATE_REQUIRED');
                    return;
                }
                if ($expirationDateRaw !== '') {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDateRaw) !== 1) {
                        Response::error('Fecha de vencimiento inválida. Usa formato YYYY-MM-DD.', 400, 'PRODUCT_EXPIRY_DATE_INVALID');
                        return;
                    }
                    $expirationDate = \DateTimeImmutable::createFromFormat('Y-m-d', $expirationDateRaw);
                    if (!($expirationDate instanceof \DateTimeImmutable)) {
                        Response::error('Fecha de vencimiento inválida.', 400, 'PRODUCT_EXPIRY_DATE_INVALID');
                        return;
                    }
                    $attributes['expirationDate'] = $expirationDate->format('Y-m-d');

                    $alertRaw = $attributes['expirationAlertDays'] ?? $attributes['expiryAlertDays'] ?? null;
                    if ($alertRaw === null || $alertRaw === '') {
                        $attributes['expirationAlertDays'] = '30';
                    } elseif (!is_numeric($alertRaw) || intval($alertRaw) < 0) {
                        Response::error('Días de alerta de vencimiento inválidos.', 400, 'PRODUCT_EXPIRY_ALERT_DAYS_INVALID');
                        return;
                    } else {
                        $attributes['expirationAlertDays'] = (string)min(3650, max(0, intval($alertRaw)));
                    }
                } else {
                    unset($attributes['expirationDate'], $attributes['expiryDate'], $attributes['expirationAlertDays'], $attributes['expiryAlertDays']);
                }
                $currentQuantity = intval($currentProduct['quantity'] ?? 0);
                $stockDelta = $effectiveQuantity - $currentQuantity;
                $this->validateInventoryIntent($data, $stockDelta);
                if ($stockDelta > 0 && (($data['inventoryAction'] ?? '') === 'restock')) {
                    $this->validatePurchaseUnitCost($data, $currentProduct);
                }
                $this->validatePurchaseInvoice($data['purchaseInvoice'] ?? null, $stockDelta > 0 && (($data['inventoryAction'] ?? '') === 'restock'));
                if (empty($attributes['supplier']) && !empty($data['purchaseInvoice']['supplierName'])) {
                    $attributes['supplier'] = trim((string)$data['purchaseInvoice']['supplierName']);
                }
                $data['attributes'] = $attributes;
                $this->normalizeAudienceFields($data, $currentProduct);
                $this->normalizeTaxSettings($data, $currentProduct);
                $this->validateVariantMetadata($data, $currentProduct);
                $this->applyVariantMetadata($data, $currentProduct);
                $this->applyProductSeoDefaults($data, $currentProduct);
                $this->syncProductReferenceCatalog($data);
                $this->enforcePublicationEligibility($data, $currentProduct);
            } else {
                $currentProduct = $this->productRepository->getById($id, ['includeUnpublished' => true]);
                if (!$currentProduct) {
                    Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                    return;
                }
                $currentQuantity = intval($currentProduct['quantity'] ?? 0);
                $effectiveQuantity = isset($data['quantity']) && is_numeric($data['quantity'])
                    ? intval($data['quantity'])
                    : $currentQuantity;
                $stockDelta = $effectiveQuantity - $currentQuantity;
                $this->validateInventoryIntent($data, $stockDelta);
                if ($stockDelta > 0 && (($data['inventoryAction'] ?? '') === 'restock')) {
                    $this->validatePurchaseUnitCost($data, $currentProduct);
                }
                $this->validatePurchaseInvoice($data['purchaseInvoice'] ?? null, $stockDelta > 0 && (($data['inventoryAction'] ?? '') === 'restock'));
                $this->normalizeAudienceFields($data, $currentProduct);
                $this->normalizeTaxSettings($data, $currentProduct);
                $this->validateVariantMetadata($data, $currentProduct);
                $this->applyVariantMetadata($data, $currentProduct);
                $this->applyProductSeoDefaults($data, $currentProduct);
                $this->syncProductReferenceCatalog($data);
                $this->enforcePublicationEligibility($data, $currentProduct);
            }
            $product = $this->productRepository->update($id, $data);
            if (!$product) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }
            Response::json($product);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400, 'PRODUCT_UPDATE_FAILED');
        }
    }

    public function destroy($id) {
        Auth::requireAdmin();
        try {
            $result = $this->productRepository->delete($id);
            if (!$result) {
                Response::error('Producto no encontrado', 404, 'PRODUCT_NOT_FOUND');
                return;
            }

            Response::json($result, 200, null, 'Producto retirado correctamente');
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500, 'PRODUCT_DELETE_FAILED');
        }
    }
}
