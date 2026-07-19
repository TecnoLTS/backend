<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Semantic DTO catalog for the API surfaces that are integration contracts.
 *
 * The route registry remains the source of truth for operations. This catalog
 * only describes payloads that can be traced to controllers, repositories and
 * the TypeScript consumers shipped in this workspace. Unknown administrative
 * projections keep the conservative inventory contract; public/external APIs
 * and critical mutations fail closed when a semantic mapping is missing.
 */
final class ModuleOpenApiSchemaCatalog
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @return array<string, array<string, mixed>> */
    public static function schemas(): array
    {
        $string = self::string();
        $nullableString = self::nullable($string);
        $integer = self::integer();
        $number = self::number();
        $boolean = self::boolean();
        $timestamp = self::string('date-time');
        $nullableTimestamp = self::nullable($timestamp);
        $identifier = self::string(null, 1, 190);
        $email = self::string('email', 3, 254);
        $url = self::string('uri', 1, 2048);
        $jsonObject = self::object([], [], true);

        return [
            'CoreHealthData' => self::object([
                'estado' => self::string(null, 1, 20),
                'fecha' => self::string(null, 1, 40),
                'servicio' => self::string(null, 1, 80),
                'base_de_datos' => self::string(null, 1, 40),
            ], ['estado', 'fecha'], false),
            'BillingHealthData' => self::object([
                'status' => self::string(null, 1, 40),
                'service' => self::string(null, 1, 80),
                'driver' => self::string(null, 1, 80),
                'version' => self::string(null, 1, 40),
                'timestamp' => self::string(null, 1, 40),
            ], ['status', 'service', 'driver', 'version', 'timestamp'], false),
            'BillingAdminHealthData' => self::object([
                'ok' => self::constBoolean(true),
                'service' => self::enum(['Billing']),
                'status' => self::enum(['healthy']),
                'driver' => self::enum(['native']),
                'database' => self::enum(['facturacion']),
                'storage_driver' => self::string(null, 1, 80),
                'timestamp' => self::string(null, 1, 40),
            ], ['ok', 'service', 'status', 'driver', 'database', 'storage_driver', 'timestamp'], false),
            'LoyaltyHealthData' => self::object([
                'status' => self::string(null, 1, 20),
                'module' => self::string(null, 1, 80),
            ], ['status', 'module'], false),

            'AuthUser' => self::object([
                'id' => $identifier,
                'email' => $email,
                'name' => self::string(null, 1, 160),
                'role' => self::string(null, 1, 80),
                'authSurface' => self::enum(['ecommerce', 'dashboard']),
            ], ['id', 'email', 'name', 'role'], false),
            'AuthSessionData' => self::object([
                'user' => self::ref('AuthUser'),
            ], ['user'], false),
            'AuthMfaChallengeData' => self::object([
                'mfaRequired' => self::constBoolean(true),
                'mfaMethod' => self::enum(['email_otp', 'recovery_code']),
            ], ['mfaRequired', 'mfaMethod'], false),
            'AuthLoginData' => [
                'oneOf' => [self::ref('AuthSessionData'), self::ref('AuthMfaChallengeData')],
            ],
            'AuthRegistrationData' => self::object([
                'id' => $identifier,
                'otpSent' => $boolean,
                'email_verified' => $boolean,
                'debug_token' => self::string(null, 1, 256),
            ], ['id'], false),
            'AuthAccessRequestData' => self::object([
                'id' => $identifier,
                'status' => self::enum(['received']),
            ], ['id', 'status'], false),
            'AuthSentData' => self::object([
                'sent' => $boolean,
                'delivery' => self::enum(['email']),
            ], ['sent'], false),
            'AuthVerifiedData' => self::object(['verified' => $boolean], ['verified'], false),
            'AuthPasswordResetData' => self::object(['passwordReset' => $boolean], ['passwordReset'], false),
            'AuthLogoutData' => self::object(['loggedOut' => $boolean], ['loggedOut'], false),
            'AuthLoginRequest' => self::object([
                'email' => $email,
                'password' => self::string(null, 1, 1024),
                'mfaCode' => self::string(null, 1, 128),
                'mfa_code' => self::string(null, 1, 128),
            ], ['email', 'password'], false),
            'AuthRegisterRequest' => self::object([
                'name' => self::string(null, 1, 160),
                'email' => $email,
                'password' => self::string(null, 12, 1024),
                'documentType' => self::string(null, 1, 40),
                'document_type' => self::string(null, 1, 40),
                'documentNumber' => self::string(null, 1, 80),
                'document_number' => self::string(null, 1, 80),
                'businessName' => self::string(null, 1, 180),
                'business_name' => self::string(null, 1, 180),
                'phone' => self::string(null, 1, 60),
                'skipVerificationEmail' => $boolean,
                'skip_verification_email' => $boolean,
                'sendOtpOnCreate' => $boolean,
                'send_otp_on_create' => $boolean,
            ], ['name', 'email', 'password'], false, [
                ['required' => ['documentType', 'documentNumber']],
                ['required' => ['documentType', 'document_number']],
                ['required' => ['document_type', 'documentNumber']],
                ['required' => ['document_type', 'document_number']],
            ]),
            'AuthAccessRequest' => self::object([
                'name' => self::string(null, 3, 140),
                'email' => $email,
                'company' => self::string(null, 2, 160),
                'message' => self::string(null, 10, 5000),
            ], ['name', 'email', 'company', 'message'], false),
            'AuthEmailRequest' => self::object(['email' => $email], ['email'], false),
            'AuthOtpVerifyRequest' => self::object([
                'email' => $email,
                'code' => self::string(null, 6, 6, '^[0-9]{6}$'),
            ], ['email', 'code'], false),
            'AuthPasswordResetRequest' => self::object([
                'email' => $email,
                'resetPath' => self::enum(['/reset-password', '/dashboard/reset-password']),
                'reset_path' => self::enum(['/reset-password', '/dashboard/reset-password']),
            ], ['email'], false),
            'AuthPasswordResetConfirmRequest' => self::object([
                'token' => self::string(null, 64, 64, '^[A-Fa-f0-9]{64}$'),
                'password' => self::string(null, 12, 1024),
                'newPassword' => self::string(null, 12, 1024),
            ], ['token'], false, [
                ['required' => ['password']],
                ['required' => ['newPassword']],
            ]),

            'ContactRequest' => self::object([
                'name' => self::string(null, 3, 140),
                'email' => $email,
                'phone' => self::string(null, 0, 40),
                'subject' => self::string(null, 4, 160),
                'message' => self::string(null, 10, 5000),
                'website' => self::string(null, 0, 2048),
                'company' => self::string(null, 0, 2048),
                'url' => self::string(null, 0, 2048),
            ], ['name', 'email', 'subject', 'message'], false),
            'ContactResult' => self::object([
                'id' => self::nullable($identifier),
                'delivered' => $boolean,
                'confirmationDelivered' => $boolean,
                'spamFiltered' => $boolean,
            ], ['id', 'delivered', 'confirmationDelivered'], false),

            'CatalogPaginationMeta' => self::object([
                'pageSize' => self::integer(1, 100),
                'hasMore' => $boolean,
                'nextCursor' => $nullableString,
            ], ['pageSize', 'hasMore', 'nextCursor'], false),
            'PublicProductInventory' => self::object([
                'onHand' => self::integer(0),
                'available' => self::integer(0),
                'status' => self::string(null, 1, 80),
            ], ['onHand', 'available', 'status'], false),
            'ProductImageMeta' => self::object([
                'url' => self::string(null, 1, 2048),
                'width' => self::integer(1),
                'height' => self::integer(1),
                'kind' => self::string(null, 1, 40),
                'altText' => $nullableString,
                'displayOrder' => self::integer(0),
            ], ['url'], false),
            'PublicProduct' => self::object([
                'id' => $identifier,
                'internalId' => $identifier,
                'category' => $string,
                'productType' => $string,
                'type' => $string,
                'name' => self::string(null, 1, 240),
                'gender' => $string,
                'new' => $boolean,
                'sale' => $boolean,
                'published' => $boolean,
                'rate' => $number,
                'price' => self::number(0),
                'originPrice' => self::number(0),
                'brand' => $string,
                'sold' => self::integer(0),
                'quantity' => self::integer(0),
                'quantityPurchase' => self::integer(0),
                'sizes' => self::listOf($string),
                'variation' => self::listOf($jsonObject),
                'thumbImage' => self::listOf($string),
                'images' => self::listOf($string),
                'imageMeta' => self::listOf(self::ref('ProductImageMeta')),
                'description' => $string,
                'action' => $string,
                'slug' => $string,
                'attributes' => $jsonObject,
                'inventory' => self::ref('PublicProductInventory'),
                'tax' => self::object([
                    'rate' => $number,
                    'multiplier' => $number,
                    'exempt' => $boolean,
                    'zeroRated' => $boolean,
                    'treatment' => self::enum(['taxed', 'zero-rated', 'exempt']),
                ], ['rate', 'multiplier', 'exempt', 'zeroRated', 'treatment'], false),
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
            ], ['id', 'name', 'price', 'quantity'], true),
            'PublicProductList' => self::listOf(self::ref('PublicProduct')),
            'ProductWriteRequest' => self::object([
                'name' => self::string(null, 1, 240),
                'category' => self::string(null, 1, 160),
                'productType' => self::enum(['Alimento', 'ropa', 'cuidado', 'accesorios']),
                'product_type' => self::enum(['Alimento', 'ropa', 'cuidado', 'accesorios']),
                'price' => self::number(0),
                'originPrice' => self::number(0),
                'cost' => self::number(0),
                'quantity' => self::integer(0),
                'quantityPurchase' => self::integer(0),
                'brand' => self::string(null, 1, 160),
                'description' => self::string(null, 0, 10000),
                'gender' => $string,
                'published' => $boolean,
                'taxExempt' => $boolean,
                'taxRate' => self::enum([0, 5, 12, 13, 14, 15]),
                'attributes' => $jsonObject,
                'images' => self::listOf($string),
                'thumbImage' => self::listOf($string),
                'inventoryAction' => self::enum(['initial_stock', 'restock', 'adjustment']),
                'inventoryAdjustmentReason' => self::string(null, 1, 500),
                'purchaseInvoice' => $jsonObject,
            ], ['name', 'category', 'price', 'quantity', 'brand'], true, [
                ['required' => ['productType']],
                ['required' => ['product_type']],
            ]),
            'ProductArchiveResult' => self::object([
                'archived' => $boolean,
                'id' => $identifier,
            ], [], true),
            'CatalogImageUploadRequest' => self::object([
                'image' => self::string('binary'),
                'variant220' => self::string('binary'),
                'variant360' => self::string('binary'),
                'folder' => self::enum(['products', 'brands', 'categories']),
                'fileName' => self::string(null, 6, 140, '^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\\.webp$'),
            ], ['image', 'folder', 'fileName'], false),
            'CatalogImageUploadResult' => self::object([
                'url' => self::string(null, 1, 2048),
                'fileName' => self::string(null, 1, 140),
                'storageKey' => self::string(null, 1, 512),
                'variants' => self::object([], [], ['type' => 'string']),
                'size' => self::integer(1),
            ], ['url', 'fileName', 'storageKey', 'variants', 'size'], false),
            'ProductReview' => self::object([
                'id' => $identifier,
                'productId' => $identifier,
                'orderId' => $identifier,
                'orderItemId' => $identifier,
                'userId' => $identifier,
                'rating' => self::integer(1, 5),
                'title' => $nullableString,
                'body' => self::string(null, 1, 5000),
                'authorName' => self::string(null, 1, 160),
                'status' => $string,
                'createdAt' => $nullableTimestamp,
                'updatedAt' => $nullableTimestamp,
            ], ['id', 'rating', 'body', 'authorName'], false),
            'ProductReviewSummary' => self::object([
                'count' => self::integer(0),
                'average' => self::number(0, 5),
            ], ['count', 'average'], false),
            'ProductReviewsData' => self::object([
                'summary' => self::ref('ProductReviewSummary'),
                'reviews' => self::listOf(self::ref('ProductReview')),
            ], ['summary', 'reviews'], false),
            'ProductReviewCreateRequest' => self::object([
                'rating' => self::integer(1, 5),
                'title' => self::string(null, 0, 200),
                'body' => self::string(null, 1, 5000),
                'authorName' => self::string(null, 1, 160),
            ], ['rating', 'body'], false),
            'ProductMovementData' => self::object([
                'product' => $jsonObject,
                'period' => $jsonObject,
                'sales' => $jsonObject,
                'purchases' => $jsonObject,
                'inventory' => $jsonObject,
            ], ['product', 'period', 'sales', 'purchases', 'inventory'], false),

            'OrderSelectionItem' => self::object([
                'product_id' => $identifier,
                'quantity' => self::integer(1),
                'product_image' => $string,
            ], ['product_id', 'quantity'], false),
            'PostalAddress' => self::object([
                'name' => $string,
                'address' => $string,
                'address_line_1' => $string,
                'address_line_2' => $string,
                'city' => $string,
                'province' => $string,
                'postal_code' => $string,
                'latitude' => self::number(-90, 90),
                'longitude' => self::number(-180, 180),
                'phone' => $string,
            ], [], true),
            'OrderQuoteRequest' => self::object([
                'items' => self::listOf(self::ref('OrderSelectionItem'), 1),
                'delivery_method' => self::enum(['delivery', 'pickup']),
                'coupon_code' => self::string(null, 1, 80),
                'discount_code' => self::string(null, 1, 80),
                'shipping_address' => self::ref('PostalAddress'),
            ], ['items'], false),
            'OrderCreateRequest' => self::object([
                'items' => self::listOf(self::ref('OrderSelectionItem'), 1),
                'delivery_method' => self::enum(['delivery', 'pickup']),
                'coupon_code' => self::string(null, 1, 80),
                'discount_code' => self::string(null, 1, 80),
                'shipping_address' => self::ref('PostalAddress'),
                'billing_address' => self::ref('PostalAddress'),
                'payment_method' => $string,
                'payment_details' => $jsonObject,
                'order_notes' => self::string(null, 0, 2000),
                'customer_name' => $string,
                'customer_document' => $string,
                'customer_email' => $email,
                'customer_phone' => $string,
                'request_channel' => $string,
            ], ['items'], false),
            'OrderQuoteLine' => self::object([
                'product_id' => $identifier,
                'product_name' => $string,
                'product_image' => $nullableString,
                'quantity' => self::integer(1),
                'price' => self::number(0),
                'price_net' => self::number(0),
                'tax_rate' => self::number(0),
                'tax_exempt' => $boolean,
                'discount_total' => self::number(0),
                'net_total' => self::number(0),
                'tax_amount' => self::number(0),
                'total' => self::number(0),
            ], ['product_id', 'product_name', 'quantity', 'price', 'total'], true),
            'OrderQuoteData' => self::object([
                'subtotal' => self::number(0),
                'items_subtotal_before_discount' => self::number(0),
                'vat_rate' => self::number(0),
                'vat_subtotal' => self::number(0),
                'vat_amount' => self::number(0),
                'mixed_vat_rates' => $boolean,
                'shipping' => self::number(0),
                'shipping_base' => self::number(0),
                'shipping_tax_rate' => self::number(0),
                'shipping_tax_amount' => self::number(0),
                'distance_km' => self::nullable($number),
                'shipping_rule' => $string,
                'is_free_shipping' => $boolean,
                'store_address' => $string,
                'discount_code' => $nullableString,
                'discount_total' => self::number(0),
                'discounts_applied' => self::listOf($jsonObject),
                'discount_rejections' => self::listOf($jsonObject),
                'total' => self::number(0),
                'items' => self::listOf(self::ref('OrderQuoteLine')),
            ], ['subtotal', 'shipping', 'discount_total', 'total', 'items'], false),
            'OrderLine' => self::object([
                'order_id' => $identifier,
                'product_id' => $identifier,
                'product_name' => $string,
                'product_image' => $nullableString,
                'quantity' => self::integer(1),
                'price' => self::number(0),
            ], ['order_id', 'product_id', 'product_name', 'quantity', 'price'], true),
            'OrderSummary' => self::object([
                'id' => self::string(null, 1, 128),
                'user_id' => self::nullable(self::string(null, 1, 128)),
                'total' => self::number(0),
                'status' => self::string(null, 1, 32),
                'created_at' => self::string(null, 1, 40),
                'delivery_method' => self::nullable(self::string(null, 1, 32)),
                'payment_method' => self::nullable(self::string(null, 1, 64)),
                'customer_name' => self::nullable(self::string(null, 1, 80)),
                'customer_email' => self::nullable(self::string('email', 3, 254)),
                'customer_phone' => self::nullable(self::string(null, 1, 40)),
                'customer_document_type' => self::nullable(self::string(null, 1, 32)),
                'customer_document_number' => self::nullable(self::string(null, 1, 64)),
                'customer_company' => self::nullable(self::string(null, 1, 80)),
                'sales_channel' => self::nullable(self::string(null, 1, 32)),
                'items_count' => self::integer(0),
                'units_count' => self::integer(0),
                'mixed_vat_rates' => $boolean,
                'items_subtotal' => self::number(0),
                'vat_subtotal' => self::number(0),
                'vat_rate' => self::number(0),
                'vat_amount' => self::number(0),
                'shipping' => self::number(0),
                'shipping_base' => self::number(0),
                'shipping_tax_rate' => self::number(0),
                'shipping_tax_amount' => self::number(0),
                'discount_code' => self::nullable(self::string(null, 1, 80)),
                'discount_total' => self::number(0),
                'invoice_number' => self::nullable(self::string(null, 1, 80)),
                'invoice_created_at' => self::nullable(self::string(null, 1, 40)),
            ], [
                'id',
                'user_id',
                'total',
                'status',
                'created_at',
                'delivery_method',
                'payment_method',
                'customer_name',
                'customer_email',
                'customer_phone',
                'customer_document_type',
                'customer_document_number',
                'customer_company',
                'sales_channel',
                'items_count',
                'units_count',
                'mixed_vat_rates',
                'items_subtotal',
                'vat_subtotal',
                'vat_rate',
                'vat_amount',
                'shipping',
                'shipping_base',
                'shipping_tax_rate',
                'shipping_tax_amount',
                'discount_code',
                'discount_total',
                'invoice_number',
                'invoice_created_at',
            ], false),
            'Order' => self::object([
                'id' => $identifier,
                'order_number' => $string,
                'user_name' => $string,
                'user_email' => $email,
                'user_id' => $identifier,
                'items_count' => self::integer(0),
                'total' => self::number(0),
                'status' => self::enum(['pending', 'processing', 'shipped', 'delivered', 'completed', 'canceled']),
                'created_at' => $timestamp,
                'order_notes' => $nullableString,
                'shipping_address' => self::nullable(self::ref('PostalAddress')),
                'billing_address' => self::nullable(self::ref('PostalAddress')),
                'delivery_method' => $nullableString,
                'payment_method' => $nullableString,
                'items' => self::listOf(self::ref('OrderLine')),
            ], ['id', 'total', 'status', 'created_at'], true),
            'OrderList' => self::listOf(self::ref('OrderSummary'), null, 100),
            'OrderStatusRequest' => self::object([
                'status' => self::enum(['pending', 'processing', 'shipped', 'delivered', 'completed', 'canceled']),
            ], ['status'], false),

            'ShippingSettings' => self::object([
                'delivery' => self::number(0),
                'pickup' => self::number(0),
                'tax_rate' => self::number(0),
                'store_address' => $string,
                'store_latitude' => self::number(-90, 90),
                'store_longitude' => self::number(-180, 180),
                'free_shipping_radius_km' => self::number(0),
                'shipping_km_flat_rate_limit' => self::number(0),
                'shipping_per_km_rate' => self::number(0),
                'map_min_search_chars' => self::integer(3),
                'map_lookup_cooldown_seconds' => self::integer(0),
                'map_session_lookup_limit' => self::integer(1),
            ], ['delivery', 'pickup', 'tax_rate', 'store_address', 'store_latitude', 'store_longitude'], false),
            'TaxSettingsData' => self::object([
                'rate' => self::enum([0, 5, 12, 13, 14, 15]),
                'credit_current_rate' => self::number(0, 100),
                'credit_carryforward_rate' => self::number(0, 100),
                'tenant_registry_revision' => self::integer(1),
            ], ['rate', 'credit_current_rate', 'credit_carryforward_rate', 'tenant_registry_revision'], false),
            // Credits are optional for backwards-compatible rate-only clients;
            // omission has PATCH-like merge semantics and preserves canonical
            // values. If-Match remains mandatory for every PUT.
            'TaxSettingsUpdateRequest' => self::object([
                'rate' => self::enum([0, 5, 12, 13, 14, 15]),
                'credit_current_rate' => self::number(0, 100),
                'credit_carryforward_rate' => self::number(0, 100),
            ], ['rate'], false),
            'StoreStatus' => self::object([
                'salesEnabled' => $boolean,
                'message' => $string,
                'updatedAt' => $nullableString,
                'updatedBy' => $nullableString,
            ], ['salesEnabled', 'message', 'updatedAt', 'updatedBy'], false),
            'BrandLogo' => self::object([
                'id' => $string,
                'name' => $string,
                'slug' => $string,
                'logoUrl' => $url,
            ], ['name', 'logoUrl'], true),
            'BrandLogoList' => self::listOf(self::ref('BrandLogo')),
            'CategoryReference' => self::object([
                'name' => $string,
                'topImageUrl' => $string,
                'featuredImages' => self::object([
                    'mobilePrimary' => $string,
                    'mobileSecondary' => $string,
                    'desktopPrimary' => $string,
                    'desktopSecondary' => $string,
                ], ['mobilePrimary', 'mobileSecondary', 'desktopPrimary', 'desktopSecondary'], false),
                'showInTopSection' => $boolean,
                'showInFeaturedSection' => $boolean,
                'showInImageSection' => $boolean,
            ], ['name', 'topImageUrl', 'featuredImages', 'showInTopSection', 'showInFeaturedSection', 'showInImageSection'], false),
            'CategoryReferenceList' => self::listOf(self::ref('CategoryReference')),
            'CspReportRequest' => self::object([
                'csp-report' => self::object([
                    'document-uri' => $string,
                    'referrer' => $string,
                    'violated-directive' => $string,
                    'effective-directive' => $string,
                    'original-policy' => $string,
                    'blocked-uri' => $string,
                    'status-code' => self::integer(0, 599),
                    'source-file' => $string,
                    'line-number' => self::integer(0),
                    'column-number' => self::integer(0),
                ], [], true),
            ], [], true),
            'CspReportResult' => self::object(['received' => self::constBoolean(true)], ['received'], false),

            'TenantBranding' => self::object([
                'logoUrl' => $string,
                'logoLightUrl' => $string,
                'logoIconUrl' => $string,
                'primaryColor' => self::string(null, 4, 32),
            ], [], false),
            'TenantEcommerceConfiguration' => self::object([
                'vertical' => self::enum(['petshop', 'technology', 'fashion', 'hardware', 'supermarket', 'pharmacy', 'other']),
                'businessLabel' => $nullableString,
                'enabledCapabilities' => self::listOf($string),
                'defaultTaxRate' => self::enum([0, 5, 12, 13, 14, 15]),
                'purchaseVatCreditCurrentRate' => self::number(0, 100),
                'purchaseVatCreditCarryforwardRate' => self::number(0, 100),
                'notes' => $nullableString,
                // Legacy integration descriptors remain accepted during the
                // additive contract rollout; sanitization emits the canonical
                // fields above.
                'capabilities' => self::listOf($string),
                'businessType' => $string,
                'internalModules' => self::listOf($string),
            ], [], true),
            'TenantLifecycleMetadata' => self::object([
                'lastAction' => self::enum(['suspend', 'resume', 'offboard']),
                'reason' => self::string(null, 8, 500),
                'actorUserId' => $identifier,
                'occurredAt' => $timestamp,
            ], ['lastAction', 'reason', 'actorUserId', 'occurredAt'], false),
            'TenantDomainChangeMetadata' => self::object([
                'previousDomains' => self::listOf(self::string('hostname')),
                'desiredDomains' => self::listOf(self::string('hostname')),
                'reason' => self::string(null, 8, 500),
                'actorUserId' => $identifier,
                'occurredAt' => $timestamp,
            ], ['previousDomains', 'desiredDomains', 'reason', 'actorUserId', 'occurredAt'], false),
            'TenantRollbackMetadata' => self::object([
                'targetRevision' => self::integer(1),
                'reason' => self::string(null, 8, 500),
                'actorUserId' => $identifier,
                'occurredAt' => $timestamp,
            ], ['targetRevision', 'reason', 'actorUserId', 'occurredAt'], false),
            'TenantSummary' => self::object([
                'id' => $identifier,
                'slug' => self::string(null, 1, 80, '^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$'),
                'name' => self::string(null, 1, 160),
                'status' => self::enum(['active', 'inactive', 'suspended']),
                'enabledModules' => self::listOf($string),
                'ecommerceConfiguration' => self::nullable(self::ref('TenantEcommerceConfiguration')),
                'branding' => self::ref('TenantBranding'),
                'domains' => self::listOf(self::string('hostname')),
                'provisioningStatus' => self::enum(['ready', 'pending_gateway', 'pending_dns', 'error']),
                'lifecycle' => self::nullable(self::ref('TenantLifecycleMetadata')),
                'domainChange' => self::nullable(self::ref('TenantDomainChangeMetadata')),
                'rollback' => self::nullable(self::ref('TenantRollbackMetadata')),
                'createdAt' => $nullableTimestamp,
                'updatedAt' => $nullableTimestamp,
            ], ['id', 'slug', 'name', 'status', 'enabledModules', 'branding'], false),
            'TenantCreateRequest' => self::object([
                'name' => self::string(null, 1, 160),
                'slug' => self::string(null, 1, 80, '^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$'),
                'primaryDomain' => self::string('hostname'),
                'primary_domain' => self::string('hostname'),
                'enabledModules' => self::listOf($string),
                'ecommerceConfiguration' => self::nullable(self::ref('TenantEcommerceConfiguration')),
            ], ['name', 'slug'], false, [
                ['required' => ['primaryDomain']],
                ['required' => ['primary_domain']],
            ]),
            'TenantModulesRequest' => self::object([
                'enabledModules' => self::listOf($string),
            ], ['enabledModules'], false),
            'TenantConfigurationRequest' => self::object([
                'ecommerceConfiguration' => self::nullable(self::ref('TenantEcommerceConfiguration')),
            ], ['ecommerceConfiguration'], false),
            'TenantLifecycleRequest' => self::object([
                'action' => self::enum(['suspend', 'resume', 'offboard']),
                'reason' => self::string(null, 8, 500),
            ], ['action', 'reason'], false),
            'TenantDomainsRequest' => self::object([
                'domains' => self::listOf(self::string('hostname'), 1, 32),
                'reason' => self::string(null, 8, 500),
            ], ['domains', 'reason'], false),
            'TenantReconcileRequest' => self::object([
                'reason' => self::string(null, 8, 500),
            ], ['reason'], false),
            'TenantRollbackRequest' => self::object([
                'targetRevision' => self::integer(1),
                'reason' => self::string(null, 8, 500),
            ], ['targetRevision', 'reason'], false),
            'TenantRegistryEvent' => self::object([
                'requestId' => self::string(null, 8, 128),
                'operation' => self::string(null, 3, 80),
                'targetTenantId' => $identifier,
                'actorTenantId' => $identifier,
                'actorUserId' => $identifier,
                'expectedRevision' => self::integer(1),
                'appliedRevision' => self::integer(1),
                'previousTenant' => self::nullable(self::object([], [], true)),
                'desiredTenant' => self::nullable(self::object([], [], true)),
                'createdAt' => $timestamp,
            ], ['requestId', 'operation', 'targetTenantId', 'actorTenantId', 'actorUserId', 'expectedRevision', 'appliedRevision', 'createdAt'], false),
            'TenantRegistryEventList' => self::listOf(self::ref('TenantRegistryEvent')),

            'ManagedUserSecurityLock' => self::object([
                'locked' => $boolean,
                'isLocked' => $boolean,
                'failedAttempts' => self::integer(0),
                'lockedUntil' => $nullableTimestamp,
            ], ['isLocked', 'failedAttempts', 'lockedUntil'], false),
            'ManagedUser' => self::object([
                'id' => $identifier,
                'name' => self::string(null, 1, 160),
                'email' => $email,
                'emailVerified' => $boolean,
                'phone' => $nullableString,
                'department' => $string,
                'position' => $string,
                'status' => self::enum(['invited', 'active', 'inactive', 'blocked']),
                'accountStatus' => self::enum(['invited', 'active', 'inactive', 'blocked']),
                'securityLock' => self::ref('ManagedUserSecurityLock'),
                'roles' => self::listOf($string),
                'roleAssignments' => self::listOf(self::ref('ManagedRoleAssignment')),
                'avatarUrl' => $string,
                'coverUrl' => $string,
                'description' => $nullableString,
                'createdAt' => $timestamp,
                'updatedAt' => $timestamp,
                'lastLoginAt' => $nullableTimestamp,
                'invitationSentAt' => $nullableTimestamp,
                'invitationExpiresAt' => $nullableTimestamp,
                'invitationStatus' => self::nullable(self::enum(['pending'])),
            ], ['id', 'name', 'email', 'status', 'accountStatus', 'securityLock', 'roles'], false),
            'ManagedRoleAssignment' => self::object([
                'id' => $identifier,
                'name' => $string,
                'system' => $boolean,
            ], ['id', 'name', 'system'], false),
            'ManagedUserCreateRequest' => self::object([
                'name' => self::string(null, 3, 160),
                'email' => $email,
                'phone' => self::string(null, 0, 60),
                'department' => self::string(null, 1, 120),
                'position' => self::string(null, 1, 120),
                'roles' => self::listOf($identifier, 1),
                'roleIds' => self::listOf($identifier, 1),
                'description' => self::string(null, 0, 500),
            ], ['name', 'email'], false, [
                ['required' => ['roles']],
                ['required' => ['roleIds']],
            ]),
            'ManagedUserUpdateRequest' => self::object([
                'name' => self::string(null, 3, 160),
                'email' => $email,
                'phone' => self::string(null, 0, 60),
                'department' => self::string(null, 0, 120),
                'position' => self::string(null, 0, 120),
                'description' => self::string(null, 0, 500),
                'roles' => self::listOf($identifier),
                'roleIds' => self::listOf($identifier),
                'status' => self::enum(['invited', 'active', 'inactive', 'blocked']),
            ], [], false),
            'ManagedUserReplaceRequest' => [
                'allOf' => [
                    self::ref('ManagedUserUpdateRequest'),
                    ['type' => 'object', 'required' => ['name', 'email']],
                ],
            ],
            'ManagedUserRolesRequest' => self::object([
                'roleIds' => self::listOf($identifier),
                'roles' => self::listOf($identifier),
            ], [], false, [
                ['required' => ['roleIds']],
                ['required' => ['roles']],
            ]),
            'ManagedUserStatusRequest' => self::object([
                'accountStatus' => self::enum(['invited', 'active', 'inactive', 'blocked']),
                'status' => self::enum(['invited', 'active', 'inactive', 'blocked']),
            ], [], false, [
                ['required' => ['accountStatus']],
                ['required' => ['status']],
            ]),
            'ManagedUserRolesResult' => self::object([
                'userId' => $identifier,
                'roleIds' => self::listOf($identifier),
                'roles' => self::listOf(self::ref('ManagedRoleAssignment')),
                'sessionsRevoked' => $boolean,
            ], ['userId', 'roleIds', 'sessionsRevoked'], false),
            'ManagedUserAccountLinkResult' => self::object([
                'sent' => $boolean,
                'purpose' => self::enum(['invitation', 'password_reset']),
                'expiresAt' => $timestamp,
            ], ['sent', 'purpose', 'expiresAt'], false),
            'ManagedUserSessionsRevokeResult' => self::object([
                'sessionsRevoked' => $boolean,
                'requiresLogin' => $boolean,
                'revokedCount' => self::integer(0),
                'scope' => self::enum(['other-sessions', 'all']),
            ], ['sessionsRevoked', 'requiresLogin'], false),

            'BillingRetryConfiguration' => self::object([
                'enabled' => $boolean,
                'is_active' => $boolean,
                'max_attempts' => self::integer(1),
                'delay_seconds' => self::integer(0),
                'max_retry_days' => self::integer(0),
            ], ['enabled', 'is_active', 'max_attempts', 'delay_seconds', 'max_retry_days'], false),
            'BillingRetryConfigurationRequest' => self::object([
                'enabled' => $boolean,
                'is_active' => $boolean,
                'max_attempts' => self::integer(1),
                'delay_seconds' => self::integer(0),
                'max_retry_days' => self::integer(0),
            ], [], false),
            'BillingBranch' => self::object([
                'id' => self::integer(1),
                'code' => self::string(null, 3, 3, '^[0-9]{3}$'),
                'emission_point' => self::string(null, 3, 3, '^[0-9]{3}$'),
                'name' => $nullableString,
                'address' => $nullableString,
                'api_test' => $boolean,
                'api_production' => $boolean,
                'retries_test' => $boolean,
                'retries_production' => $boolean,
                'is_default' => $boolean,
                'is_active' => $boolean,
            ], ['code', 'emission_point'], false),
            'BillingBranchRequest' => self::object([
                'code' => self::string(null, 3, 3, '^[0-9]{3}$'),
                'emission_point' => self::string(null, 3, 3, '^[0-9]{3}$'),
                'name' => self::string(null, 0, 160),
                'address' => self::string(null, 0, 500),
                'api_test' => $boolean,
                'api_production' => $boolean,
                'retries_test' => $boolean,
                'retries_production' => $boolean,
                'is_default' => $boolean,
                'is_active' => $boolean,
            ], [], false),
            'BillingBranchCreateRequest' => [
                'allOf' => [
                    self::ref('BillingBranchRequest'),
                    ['type' => 'object', 'required' => ['code', 'emission_point', 'address']],
                ],
            ],
            'BillingConfiguration' => self::object([
                'client' => self::object([
                    'id' => self::integer(1),
                    'ruc' => self::string(null, 13, 13, '^[0-9]{13}$'),
                    'business_name' => $string,
                    'trade_name' => $nullableString,
                    'email' => self::nullable($email),
                    'address' => $string,
                ], ['ruc', 'business_name', 'address'], false),
                'branch' => self::ref('BillingBranch'),
                'branches' => self::listOf(self::ref('BillingBranch')),
                'environments' => self::object([
                    'test' => self::ref('BillingEnvironmentConfiguration'),
                    'production' => self::ref('BillingEnvironmentConfiguration'),
                ], ['test', 'production'], false),
                'retries' => self::object([
                    'test' => self::ref('BillingRetryConfigurationRequest'),
                    'production' => self::ref('BillingRetryConfigurationRequest'),
                ], ['test', 'production'], false),
                'certificate' => self::ref('BillingCertificateInfo'),
                'updated_at' => $nullableTimestamp,
            ], ['client', 'branch', 'environments', 'retries', 'certificate'], true),
            'BillingEnvironmentConfiguration' => self::object([
                'label' => $string,
                'sri_environment' => $string,
                'enabled' => $boolean,
                'recepcion_wsdl' => $nullableString,
                'autorizacion_wsdl' => $nullableString,
            ], ['enabled'], false),
            'BillingCertificateInfo' => self::object([
                'file_name' => $nullableString,
                'subject' => $nullableString,
                'valid_from' => $nullableString,
                'expires_at' => $nullableString,
                'days_remaining' => self::nullable($integer),
                'status' => $string,
                'label' => $string,
                'message' => $string,
                'password_configured' => $boolean,
            ], [], false),
            'BillingConfigurationRequest' => self::object([
                'client' => self::ref('BillingConfigurationClientRequest'),
                'branch' => self::ref('BillingBranchRequest'),
                'environments' => self::object([
                    'test' => self::ref('BillingEnvironmentConfiguration'),
                    'production' => self::ref('BillingEnvironmentConfiguration'),
                ], ['test', 'production'], false),
                'retries' => self::object([
                    'test' => self::ref('BillingRetryConfiguration'),
                    'production' => self::ref('BillingRetryConfiguration'),
                ], ['test', 'production'], false),
                'sequences' => $jsonObject,
                'credentials' => self::object([
                    'certificate_password' => self::string(null, 1, 1024),
                ], [], false),
            ], [], false),
            'BillingConfigurationClientRequest' => self::object([
                'ruc' => self::string(null, 13, 13, '^[0-9]{13}$'),
                'business_name' => self::string(null, 1, 240),
                'trade_name' => self::string(null, 0, 240),
                'email' => $email,
                'address' => self::string(null, 1, 500),
            ], [], false),
            'BillingCertificateUploadRequest' => self::object([
                'certificate' => self::string('binary'),
                'certificate_password' => self::string(null, 1, 1024),
            ], ['certificate', 'certificate_password'], false),
            'BillingCustomer' => self::object([
                'identification' => self::string(null, 10, 13, '^[0-9]{10,13}$'),
                'name' => self::string(null, 1, 240),
                'address' => self::string(null, 0, 500),
                'email' => $email,
            ], ['identification', 'name'], false),
            'BillingInvoiceLineRequest' => self::object([
                'code' => $string,
                'auxiliary_code' => $string,
                'description' => self::string(null, 1, 500),
                'quantity' => self::number(0.000001),
                'unit_price' => self::number(0),
                'discount' => self::number(0),
                'tax_rate' => self::number(0, 100),
                'tax_code' => $string,
                'tax_percentage_code' => $string,
                'tax_amount' => self::number(0),
                'line_subtotal_net' => self::number(0),
                'additional_detail' => $string,
            ], ['description', 'quantity', 'unit_price'], false),
            'BillingInvoiceRequest' => self::object([
                'customer' => self::ref('BillingCustomer'),
                'customer_identification' => self::string(null, 10, 13, '^[0-9]{10,13}$'),
                'customer_name' => self::string(null, 1, 240),
                'customer_address' => self::string(null, 0, 500),
                'customer_email' => $email,
                'items' => self::listOf(self::ref('BillingInvoiceLineRequest'), 1),
                'payment_method' => $string,
                'payment_method_code' => $string,
                'source_reference' => self::string(null, 1, 190),
                'additional_info' => $jsonObject,
                'branch_id' => self::integer(1),
                'branch_code' => self::string(null, 3, 3),
                'emission_point' => self::string(null, 3, 3),
            ], ['items'], false, [
                ['required' => ['customer']],
                ['required' => ['customer_identification', 'customer_name']],
            ]),
            'BillingInvoiceData' => self::object([
                'access_key' => self::string(null, 49, 49, '^[0-9]{49}$'),
                'sequential' => self::string(null, 1, 32),
                'issue_date' => self::string('date'),
                'total' => self::number(0),
                'status' => $string,
                'authorization_number' => $nullableString,
                'authorization_date' => $nullableString,
                'pdf_url' => $nullableString,
                'xml_url' => $nullableString,
                'source_reference' => $string,
                'stock_movements' => self::listOf($jsonObject),
            ], ['access_key', 'sequential', 'issue_date', 'total', 'status'], true),
            'BillingRideDocument' => self::object([
                'access_key' => self::string(null, 49, 49, '^[0-9]{49}$'),
                'source_reference' => $nullableString,
                'customer_name' => $nullableString,
                'customer_identification' => $nullableString,
                'customer_email' => self::nullable($email),
                'total' => self::nullable($number),
                'total_tax' => self::nullable($number),
                'sequential' => $nullableString,
                'establishment_code' => $nullableString,
                'emission_point' => $nullableString,
                'ambiente' => $nullableString,
                'sri_status' => $nullableString,
                'issue_date' => $nullableString,
                'authorization_date' => $nullableString,
                'mail_sent_at' => $nullableString,
                'pdf_exists' => self::nullable($boolean),
                'pdf_can_generate' => self::nullable($boolean),
                'authorized_xml_received' => self::nullable($boolean),
                'xml_exists' => self::nullable($boolean),
                'xml_url' => $nullableString,
                'is_cancelled' => self::nullable($boolean),
            ], ['access_key'], true),
            'BillingRideDocumentList' => self::listOf(self::ref('BillingRideDocument')),
            'BillingStatusData' => self::object([
                'status' => $string,
                'access_key' => self::string(null, 49, 49, '^[0-9]{49}$'),
                'authorization_number' => $nullableString,
                'authorization_date' => $nullableString,
                'pdf_url' => $nullableString,
                'xml_url' => $nullableString,
            ], ['status', 'access_key'], true),
            'BillingMailTestResult' => self::object([
                'access_key' => self::string(null, 49, 49, '^[0-9]{49}$'),
                'recipient' => $email,
                'customer_name' => $string,
                'pdf_path' => $string,
                'signed_xml_path' => $string,
                'authorized_xml_exists' => $boolean,
                'test_mode' => self::constBoolean(true),
            ], ['access_key', 'recipient', 'customer_name', 'pdf_path', 'signed_xml_path', 'authorized_xml_exists', 'test_mode'], false),
            'BillingReissueInvoice' => self::object([
                'source_reference' => $nullableString,
                'access_key' => self::nullable(self::string(null, 49, 49, '^[0-9]{49}$')),
                'authorization_number' => $nullableString,
                'authorization_date' => $nullableString,
                'issue_date' => $nullableString,
                'customer_name' => $nullableString,
                'customer_identification' => $nullableString,
                'customer_email' => self::nullable($email),
                'total' => self::nullable($number),
                'establishment_code' => $nullableString,
                'emission_point' => $nullableString,
                'sequential' => $nullableString,
                'ambiente' => $nullableString,
                'sri_status' => $nullableString,
                'cancelled_at' => $nullableString,
                'cancellation_reason' => $nullableString,
                'replacement_access_key' => $nullableString,
                'replaced_access_key' => $nullableString,
                'created_at' => $nullableString,
                'updated_at' => $nullableString,
            ], ['access_key'], false),
            'BillingReissueResult' => self::object([
                'reused_existing_replacement' => $boolean,
                'old_invoice' => self::ref('BillingReissueInvoice'),
                'new_invoice' => self::ref('BillingReissueInvoice'),
            ], ['reused_existing_replacement', 'old_invoice', 'new_invoice'], false),
            'BillingReissueRequest' => self::object([
                'reason' => self::string(null, 0, 500),
                'confirm_reissue' => self::enum(['REEMITIR']),
                'ambiente' => self::enum(['pruebas', 'produccion', 'test', 'production']),
            ], [], false),
            'BillingMailTestRequest' => self::object([
                'ambiente' => self::enum(['pruebas', 'produccion', 'test', 'production']),
            ], [], false),
            'BillingProductSelectionItem' => self::object([
                'product_id' => $identifier,
                'id' => $identifier,
                'quantity' => self::integer(1),
            ], ['quantity'], false, [
                ['required' => ['product_id']],
                ['required' => ['id']],
            ]),
            'BillingInvoiceFromProductsRequest' => self::object([
                'customer' => self::ref('BillingCustomer'),
                'payment_method' => $string,
                'source_reference' => $string,
                'branch_id' => self::integer(1),
                'items' => self::listOf(self::ref('BillingProductSelectionItem'), 1),
            ], ['customer', 'payment_method', 'items'], false),
            'BillingProductRequest' => self::object([
                'sku' => $string,
                'code_aux' => $string,
                'aux_code' => $string,
                'name' => self::string(null, 1, 240),
                'category' => $string,
                'brand' => $string,
                'product_type' => $string,
                'productType' => $string,
                'gender' => $string,
                'unit_measure' => $string,
                'unitMeasure' => $string,
                'image' => self::oneOf([$string, self::listOf(self::oneOf([$string, $jsonObject]))]),
                'image_url' => $string,
                'images' => self::listOf(self::oneOf([$string, $jsonObject])),
                'price_gross' => self::number(0),
                'price' => self::number(0),
                'cost' => self::number(0),
                'quantity' => self::integer(0),
                'stock' => self::integer(0),
                'tax_rate' => self::number(0, 100),
                'tax_exempt' => $boolean,
                'active' => $boolean,
                'description' => $string,
            ], ['name'], false),
            'BillingProductData' => self::object([
                'id' => $identifier,
                'sku' => $string,
                'code_aux' => $string,
                'name' => $string,
                'description' => $string,
                'category' => $string,
                'product_type' => $string,
                'brand' => $string,
                'unit_measure' => $string,
                'image_url' => $string,
                'images' => self::listOf($string),
                'price_gross' => self::number(0),
                'price_net' => self::number(0),
                'tax_rate' => self::number(0, 100),
                'tax_exempt' => $boolean,
                'quantity' => self::integer(0),
                'inventory_status' => $string,
                'active' => $boolean,
                'source' => $string,
            ], ['id', 'sku', 'name', 'price_gross', 'price_net', 'tax_rate', 'tax_exempt', 'quantity', 'active', 'source'], false),

            'LoyaltyProgram' => self::object([
                'id' => $identifier,
                'tenant_id' => $identifier,
                'name' => $string,
                'status' => $string,
                'points_per_currency' => $number,
                'currency_code' => self::string(null, 3, 3),
                'wallet_issuer_name' => $nullableString,
                'wallet_program_name' => $nullableString,
                'brand_color' => $nullableString,
                'logo_url' => $nullableString,
                'metadata' => $jsonObject,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], ['id', 'tenant_id', 'name', 'status', 'points_per_currency', 'currency_code'], false),
            'LoyaltyMember' => self::object([
                'id' => $identifier,
                'name' => $string,
                'account_name' => $string,
                'account_id' => $identifier,
                'email' => self::nullable($email),
                'phone' => $nullableString,
                'tier' => $string,
                'status' => $string,
                'wallet_platform' => $string,
                'points' => $integer,
                'last_activity_at' => $nullableTimestamp,
                'blocked_reason' => $nullableString,
            ], ['id', 'account_id', 'tier', 'status', 'wallet_platform', 'points'], true),
            'LoyaltyMemberCreateRequest' => self::object([
                'name' => self::string(null, 1, 160),
                'accountName' => self::string(null, 1, 160),
                'account_name' => self::string(null, 1, 160),
                'email' => $email,
                'phone' => self::string(null, 1, 60),
                'accountId' => $identifier,
                'account_id' => $identifier,
                'externalCustomerId' => $identifier,
                'external_customer_id' => $identifier,
                'walletPlatform' => self::enum(['google', 'apple', 'none']),
                'wallet_platform' => self::enum(['google', 'apple', 'none']),
                'metadata' => $jsonObject,
            ], ['email', 'phone'], false, [
                ['required' => ['name', 'accountId']],
                ['required' => ['name', 'account_id']],
                ['required' => ['accountName', 'accountId']],
                ['required' => ['account_name', 'account_id']],
            ]),
            'LoyaltyMemberUpdateRequest' => self::object([
                'name' => self::string(null, 1, 160),
                'accountName' => self::string(null, 1, 160),
                'account_name' => self::string(null, 1, 160),
                'email' => $email,
                'phone' => self::string(null, 0, 60),
                'status' => self::enum(['active', 'inactive', 'blocked']),
                'reason' => self::string(null, 0, 500),
                'blockedReason' => self::string(null, 0, 500),
            ], [], false),
            'LoyaltyExternalMemberUpsertRequest' => self::object([
                'memberId' => $identifier,
                'member_id' => $identifier,
                'name' => self::string(null, 1, 160),
                'accountName' => self::string(null, 1, 160),
                'account_name' => self::string(null, 1, 160),
                'email' => $email,
                'customerEmail' => $email,
                'phone' => self::string(null, 0, 60),
                'accountId' => $identifier,
                'account_id' => $identifier,
                'externalCustomerId' => $identifier,
                'external_customer_id' => $identifier,
                'metadata' => $jsonObject,
                'commandId' => $identifier,
                'command_id' => $identifier,
            ], [], false, [
                ['required' => ['memberId']],
                ['required' => ['member_id']],
                ['required' => ['accountId']],
                ['required' => ['account_id']],
                ['required' => ['externalCustomerId']],
                ['required' => ['external_customer_id']],
                ['required' => ['email']],
                ['required' => ['customerEmail']],
            ]),
            'LoyaltyReward' => self::object([
                'id' => $identifier,
                'name' => $string,
                'description' => $nullableString,
                'points_cost' => self::integer(1),
                'stock' => self::integer(0),
                'status' => $string,
                'claim_mode' => self::enum(['staff_only', 'in_store', 'managed']),
                'claim_instructions' => $nullableString,
                'claim_delivery_options' => self::listOf(self::enum(['pickup', 'delivery'])),
                'image_url' => $nullableString,
                'metadata' => $jsonObject,
                'redemption_count' => self::integer(0),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], ['id', 'name', 'points_cost', 'stock', 'status', 'claim_mode'], false),
            'LoyaltyRewardList' => self::listOf(self::ref('LoyaltyReward')),
            'LoyaltyMemberPage' => self::object([
                'items' => self::listOf(self::ref('LoyaltyMember')),
                'total' => self::integer(0),
                'limit' => self::integer(1, 100),
                'offset' => self::integer(0),
                'hasMore' => $boolean,
            ], ['items', 'total', 'limit', 'offset', 'hasMore'], false),
            'LoyaltyRewardRequest' => self::object([
                'name' => self::string(null, 1, 240),
                'description' => self::string(null, 0, 5000),
                'pointsCost' => self::integer(1),
                'stock' => self::integer(0),
                'status' => self::enum(['active', 'inactive', 'deleted']),
                'claimMode' => self::enum(['staff_only', 'in_store', 'managed']),
                'claimInstructions' => self::string(null, 0, 2000),
                'claimDeliveryOptions' => self::listOf(self::enum(['pickup', 'delivery'])),
                'imageUrl' => $nullableString,
                'reason' => self::string(null, 0, 500),
                'metadata' => $jsonObject,
            ], [], false),
            'LoyaltyRewardCreateRequest' => [
                'allOf' => [
                    self::ref('LoyaltyRewardRequest'),
                    ['type' => 'object', 'required' => ['name', 'pointsCost', 'stock']],
                ],
            ],
            'LoyaltyRewardImageRequest' => self::object([
                'image' => self::string('binary'),
            ], ['image'], false),
            'LoyaltyRewardImageResult' => self::object([
                'imageUrl' => $string,
                'fileName' => $string,
                'mimeType' => $string,
                'size' => self::integer(1),
                'width' => self::integer(1),
                'height' => self::integer(1),
            ], ['imageUrl', 'fileName', 'mimeType', 'size', 'width', 'height'], false),
            'LoyaltyRewardDeleteResult' => self::object([
                'deleted' => $boolean,
                'archived' => $boolean,
                'reward' => self::ref('LoyaltyReward'),
            ], ['deleted', 'archived', 'reward'], false),
            'LoyaltyPurchaseRequest' => self::object([
                'memberId' => $identifier,
                'member_id' => $identifier,
                'accountId' => $identifier,
                'account_id' => $identifier,
                'customerName' => $string,
                'customerEmail' => $email,
                'email' => $email,
                'invoiceNumber' => self::string(null, 1, 190),
                'invoice_number' => self::string(null, 1, 190),
                'invoiceAmount' => self::number(0.01),
                'amount' => self::number(0.01),
                'currency' => self::string(null, 3, 3),
                'currencyCode' => self::string(null, 3, 3),
                'walletPlatform' => $string,
                'commandId' => $identifier,
                'command_id' => $identifier,
                'purchaseSource' => self::enum(['auto', 'ecommerce', 'billing']),
                'purchase_source' => self::enum(['auto', 'ecommerce', 'billing']),
                'source' => self::enum(['auto', 'ecommerce', 'billing']),
                'sourceProof' => $jsonObject,
            ], [], false, [
                ['required' => ['memberId', 'invoiceNumber', 'invoiceAmount']],
                ['required' => ['member_id', 'invoice_number', 'amount']],
                ['required' => ['accountId', 'invoiceNumber', 'invoiceAmount']],
                ['required' => ['account_id', 'invoice_number', 'amount']],
                ['required' => ['customerEmail', 'invoiceNumber', 'invoiceAmount']],
                ['required' => ['email', 'invoice_number', 'amount']],
            ]),
            'LoyaltyPurchaseResult' => self::object([
                'member' => self::ref('LoyaltyMember'),
                'pointsEarned' => $integer,
                'balanceAfter' => $integer,
                'invoiceNumber' => $string,
                'invoiceAmount' => $number,
            ], ['member', 'pointsEarned', 'balanceAfter', 'invoiceNumber', 'invoiceAmount'], true),
            'LoyaltyPurchaseReverseRequest' => self::object([
                'reason' => self::string(null, 1, 500),
                'commandId' => $identifier,
            ], ['reason'], false),
            'LoyaltyPurchaseReverseResult' => self::object([
                'member' => self::ref('LoyaltyMember'),
                'pointsReversed' => $integer,
                'balanceAfter' => $integer,
                'reference' => $string,
            ], ['member', 'pointsReversed', 'balanceAfter'], true),
            'LoyaltyRedemption' => self::object([
                'id' => $identifier,
                'member_id' => $identifier,
                'customer' => $string,
                'reward_id' => $identifier,
                'reward' => $string,
                'points_cost' => self::integer(0),
                'status' => $string,
                'source' => $string,
                'fulfillment_type' => $nullableString,
                'code_expires_at' => $nullableTimestamp,
                'expires_at' => $nullableTimestamp,
                'resolved_at' => $nullableTimestamp,
                'resolved_by_user_id' => $nullableString,
                'resolution_note' => $nullableString,
                'metadata' => $jsonObject,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], ['id', 'member_id', 'reward_id', 'points_cost', 'status', 'created_at'], true),
            'LoyaltyRedemptionRequest' => self::object([
                'memberId' => $identifier,
                'member_id' => $identifier,
                'rewardId' => $identifier,
                'reward_id' => $identifier,
                'commandId' => $identifier,
                'command_id' => $identifier,
            ], [], false, [
                ['required' => ['memberId', 'rewardId', 'commandId']],
                ['required' => ['member_id', 'reward_id', 'command_id']],
            ]),
            'LoyaltyRedemptionResult' => self::object([
                'redemption' => self::ref('LoyaltyRedemption'),
                'member' => self::ref('LoyaltyMember'),
                'reward' => self::ref('LoyaltyReward'),
                'pointsRedeemed' => self::integer(0),
                'balanceAfter' => $integer,
                'commandId' => $identifier,
                'claimCode' => $nullableString,
            ], ['redemption', 'member', 'reward'], true),
            'LoyaltyClaimCodeRequest' => self::object([
                'code' => self::string(null, 6, 6, '^[0-9]{6}$'),
            ], ['code'], false),
            'LoyaltyClaimActionRequest' => self::object([
                'note' => self::string(null, 0, 500),
                'reason' => self::string(null, 0, 500),
            ], [], false),
            'LoyaltyAdjustmentRequest' => self::object([
                'memberId' => $identifier,
                'points' => $integer,
                'adjustmentType' => self::enum(['correction', 'service_recovery', 'fraud_correction', 'migration']),
                'reason' => self::string(null, 1, 500),
                'evidence' => self::string(null, 1, 2000),
                'commandId' => $identifier,
            ], ['memberId', 'points', 'adjustmentType', 'reason', 'evidence', 'commandId'], false),
            'LoyaltyAdjustmentResult' => self::object([
                'member' => self::ref('LoyaltyMember'),
                'pointsAdjusted' => $integer,
                'balanceAfter' => $integer,
                'commandId' => $identifier,
                'pointsAvailable' => $integer,
                'debtPaid' => $integer,
                'debtAfter' => $integer,
            ], ['member', 'pointsAdjusted', 'balanceAfter'], true),
            'LoyaltyWalletUpdateRequest' => self::object([
                'platform' => self::enum(['google', 'apple', 'none']),
            ], ['platform'], false),
            'LoyaltyWalletLinkRequest' => self::object([
                'memberId' => $identifier,
                'accountId' => $identifier,
                'sendEmail' => $boolean,
            ], [], false, [
                ['required' => ['memberId']],
                ['required' => ['accountId']],
            ]),
            'LoyaltyWalletLinkData' => self::object([
                'configured' => $boolean,
                'saveUrl' => $url,
                'portalUrl' => $url,
                'qrPath' => $string,
                'objectId' => $string,
                'classId' => $string,
                'member' => self::object([
                    'id' => $identifier,
                    'accountId' => $identifier,
                    'name' => $string,
                ], ['id', 'accountId', 'name'], false),
                'points' => $integer,
                'email' => self::object([
                    'sent' => $boolean,
                    'recipient' => self::nullable($email),
                    'reason' => $string,
                ], ['sent', 'recipient'], false),
            ], ['configured', 'saveUrl', 'objectId', 'classId', 'member', 'points'], false),
            'LoyaltyWalletNotifyRequest' => [
                'allOf' => [
                    self::object([
                        'memberId' => $identifier,
                        'accountId' => $identifier,
                        'header' => self::string(null, 0, 100),
                        'title' => self::string(null, 0, 100),
                        'body' => self::string(null, 1, 300),
                        'message' => self::string(null, 1, 300),
                    ], [], false),
                    ['anyOf' => [
                        ['required' => ['memberId']],
                        ['required' => ['accountId']],
                    ]],
                    ['anyOf' => [
                        ['required' => ['body']],
                        ['required' => ['message']],
                    ]],
                ],
            ],
            'LoyaltyWalletNotifyResult' => self::object([
                'sent' => $boolean,
                'objectId' => $string,
                'messageId' => $string,
                'messageType' => $string,
            ], ['sent', 'objectId', 'messageId'], false),
            'LoyaltyApiClient' => self::object([
                'id' => $identifier,
                'name' => $string,
                'source' => $string,
                'scopes' => self::listOf($string),
                'status' => self::enum(['active', 'suspended', 'revoked']),
                'rate_limit_per_minute' => self::integer(1),
                'last_used_at' => $nullableTimestamp,
                'created_at' => $timestamp,
                'updated_at' => $nullableTimestamp,
                'revoked_at' => $nullableTimestamp,
                'apiKey' => $string,
                'apiKeyNotice' => $string,
            ], ['id', 'name', 'source', 'scopes', 'status', 'rate_limit_per_minute', 'created_at'], false),
            'LoyaltyApiClientRequest' => self::object([
                'name' => self::string(null, 1, 160),
                'source' => self::string(null, 1, 80),
                'scopes' => self::listOf($string),
                'status' => self::enum(['active', 'suspended', 'revoked']),
                'rateLimitPerMinute' => self::integer(1, 10000),
                'reason' => self::string(null, 0, 500),
            ], [], false),
            'LoyaltyApiClientCreateRequest' => [
                'allOf' => [self::ref('LoyaltyApiClientRequest'), ['type' => 'object', 'required' => ['name', 'source', 'scopes']]],
            ],
            'LoyaltyApiClientRotation' => self::object([
                'revokedClientId' => $identifier,
                'replacement' => self::ref('LoyaltyApiClient'),
            ], ['revokedClientId', 'replacement'], false),
            'LoyaltyReasonRequest' => self::object([
                'reason' => self::string(null, 0, 500),
            ], [], false),
            'LoyaltyApiClientRotateRequest' => self::object([
                'reason' => self::string(null, 1, 500),
                'name' => self::string(null, 1, 160),
                'scopes' => self::listOf($string, 1),
                'rateLimitPerMinute' => self::integer(1, 10000),
            ], ['reason'], false),
            'LoyaltyRiskResolveRequest' => self::object([
                'note' => self::string(null, 0, 1000),
                'resolutionNote' => self::string(null, 0, 1000),
            ], [], false),
            'LoyaltyNotificationSegment' => self::object([
                'tier' => $string,
                'status' => $string,
                'wallet' => self::enum(['all', 'google']),
                'query' => $string,
                'purchasedWithinDays' => self::integer(0),
                'inactiveForDays' => self::integer(0),
                'minBalance' => $integer,
                'maxBalance' => $integer,
            ], [], false),
            'LoyaltyNotificationPreviewRequest' => self::object([
                'audience_type' => self::enum(['individual', 'all', 'segment']),
                'memberId' => $identifier,
                'tier' => $string,
                'status' => $string,
                'wallet' => self::enum(['all', 'google']),
                'query' => $string,
                'purchasedWithinDays' => self::integer(0),
                'inactiveForDays' => self::integer(0),
                'minBalance' => $integer,
                'maxBalance' => $integer,
            ], ['audience_type'], false),
            'LoyaltyNotificationCreateRequest' => self::object([
                'audience_type' => self::enum(['individual', 'all', 'segment']),
                'memberId' => $identifier,
                'title' => self::string(null, 0, 100),
                'body' => self::string(null, 1, 300),
                'tier' => $string,
                'status' => $string,
                'wallet' => self::enum(['all', 'google']),
                'query' => $string,
                'purchasedWithinDays' => self::integer(0),
                'inactiveForDays' => self::integer(0),
                'minBalance' => $integer,
                'maxBalance' => $integer,
            ], ['audience_type', 'body'], false),
            'LoyaltyNotificationPreview' => self::object(['recipients' => self::integer(0)], ['recipients'], false),
            'LoyaltyNotificationCampaign' => self::object([
                'id' => $identifier,
                'title' => $string,
                'body' => $string,
                'audience_type' => self::enum(['individual', 'all', 'segment']),
                'audience_filter' => $jsonObject,
                'status' => self::enum(['pending', 'processing', 'completed', 'completed_with_errors', 'canceled']),
                'total_recipients' => self::integer(0),
                'sent_count' => self::integer(0),
                'failed_count' => self::integer(0),
                'skipped_count' => self::integer(0),
                'created_at' => $nullableTimestamp,
                'started_at' => $nullableTimestamp,
                'finished_at' => $nullableTimestamp,
            ], ['id', 'title', 'body', 'audience_type', 'status'], false),
            'LoyaltySettingsPayload' => self::object([
                'program' => $jsonObject,
                'earning' => $jsonObject,
                'redemption' => $jsonObject,
                'expiration' => $jsonObject,
                'security' => $jsonObject,
                'communication' => $jsonObject,
                'googleWallet' => $jsonObject,
            ], [], false),
            'LoyaltySettingsData' => self::object([
                'program' => self::ref('LoyaltyProgram'),
                'settings' => self::ref('LoyaltySettingsPayload'),
                'updatedAt' => $nullableTimestamp,
                'updatedByUserId' => $nullableString,
            ], ['program', 'settings', 'updatedAt', 'updatedByUserId'], false),
            'LoyaltyTierRule' => self::object([
                'id' => $identifier,
                'name' => $string,
                'minLifetimePoints' => self::integer(0),
                'maxLifetimePoints' => self::nullable($integer),
                'multiplier' => self::number(0),
                'benefits' => self::listOf($string),
                'status' => $string,
                'sortOrder' => self::integer(0),
            ], ['name', 'minLifetimePoints', 'multiplier', 'benefits', 'status', 'sortOrder'], false),
            'LoyaltyRulesRequest' => self::object([
                'settings' => self::ref('LoyaltySettingsPayload'),
                'tiers' => self::listOf(self::ref('LoyaltyTierRule')),
                'earning' => $jsonObject,
                'redemption' => $jsonObject,
                'expiration' => $jsonObject,
            ], [], false),
            'LoyaltyRulesData' => self::object([
                'settings' => self::ref('LoyaltySettingsPayload'),
                'tiers' => self::listOf(self::ref('LoyaltyTierRule')),
            ], ['settings', 'tiers'], false),
            'LoyaltyExternalProgramData' => self::object([
                'program' => self::ref('LoyaltyProgram'),
                'settings' => self::ref('LoyaltySettingsPayload'),
                'tiers' => self::listOf(self::ref('LoyaltyTierRule')),
                'rewards' => self::ref('LoyaltyRewardList'),
            ], ['program', 'settings', 'tiers', 'rewards'], false),
            'LoyaltyReportData' => self::object([
                'key' => $string,
                'title' => $string,
                'schemaVersion' => $integer,
                'purpose' => $string,
                'scope' => $string,
                'appliedFilters' => $jsonObject,
                'period' => self::object([
                    'from' => self::string('date'),
                    'to' => self::string('date'),
                    'timezone' => $string,
                    'inclusive' => $boolean,
                    'generatedAt' => $timestamp,
                    'snapshotAt' => $nullableTimestamp,
                ], ['from', 'to'], false),
                'metrics' => self::oneOf([$jsonObject, self::listOf($jsonObject)]),
                'charts' => self::listOf($jsonObject),
                'table' => $jsonObject,
                'export' => $jsonObject,
                'rows' => self::listOf($jsonObject),
            ], ['key', 'title', 'period'], false),
            'LoyaltyPortalAccessRequest' => self::object([
                'identifier' => self::string(null, 1, 254),
                'accountId' => $identifier,
                'email' => $email,
                'phone' => $string,
            ], [], false, [
                ['required' => ['identifier']],
                ['required' => ['accountId']],
                ['required' => ['email']],
                ['required' => ['phone']],
            ]),
            'LoyaltyPortalVerifyRequest' => self::object([
                'challengeId' => $identifier,
                'code' => self::string(null, 6, 6, '^[0-9]{6}$'),
            ], ['challengeId', 'code'], false),
            'LoyaltyPortalClaimRequest' => self::object([
                'rewardId' => $identifier,
                'formNonce' => $identifier,
                'fulfillmentType' => self::enum(['in_store', 'pickup', 'delivery']),
                'contactName' => $string,
                'contactPhone' => $string,
                'contactEmail' => $email,
                'deliveryAddress' => $string,
                'notes' => self::string(null, 0, 500),
            ], ['rewardId', 'formNonce'], false),
            'LoyaltyPortalCancelRequest' => self::object([
                'formNonce' => $identifier,
                'reason' => self::string(null, 0, 500),
            ], ['formNonce'], false),
        ];
    }

    /**
     * @return array{
     *   required: bool,
     *   responseMode: string,
     *   responseSchema: ?string,
     *   requestSchema: ?string,
     *   requestMediaTypes: list<string>,
     *   requestRequired: bool,
     *   source: string
     * }
     */
    public static function contractFor(array $route, string $exposure): array
    {
        $required = self::requiresSemanticContract($route, $exposure);
        $response = self::responseContract($route);
        $request = self::requestContract($route);

        if ($required && $response === null) {
            throw new \RuntimeException(sprintf(
                'Falta response DTO semantico para %s %s (%s).',
                $route['method'],
                $route['path'],
                $exposure
            ));
        }
        if ($required && self::handlerConsumesBody($route) && $request === null) {
            throw new \RuntimeException(sprintf(
                'Falta request DTO semantico para %s %s (%s).',
                $route['method'],
                $route['path'],
                $exposure
            ));
        }

        return [
            'required' => $required,
            'responseMode' => (string)($response['mode'] ?? 'generic'),
            'responseSchema' => isset($response['schema']) ? (string)$response['schema'] : null,
            'requestSchema' => isset($request['schema']) ? (string)$request['schema'] : null,
            'requestMediaTypes' => array_values($request['mediaTypes'] ?? []),
            'requestRequired' => (bool)($request['required'] ?? false),
            'source' => $required ? 'runtime-derived-semantic-catalog' : 'conservative-operation-inventory',
        ];
    }

    public static function requiresSemanticContract(array $route, string $exposure): bool
    {
        if (in_array($exposure, ['public', 'external'], true)) {
            return true;
        }
        $path = (string)$route['path'];
        if (str_starts_with($path, '/api/auth/') || $path === '/api/products/{id}/movement') {
            return true;
        }
        if (!in_array((string)$route['method'], self::MUTATING_METHODS, true)) {
            return false;
        }

        return str_starts_with($path, '/api/auth/')
            || str_starts_with($path, '/api/admin/tenants')
            || str_starts_with($path, '/api/users')
            || str_starts_with($path, '/api/admin/products')
            || $path === '/api/admin/settings/tax'
            || $path === '/api/admin/catalog/images'
            || str_starts_with($path, '/api/products')
            || str_starts_with($path, '/api/orders')
            || str_starts_with($path, '/api/admin/billing')
            || str_starts_with($path, '/api/{apiMode}/v1/')
            || str_starts_with($path, '/api/admin/loyalty')
            || str_starts_with($path, '/api/loyalty/v1/');
    }

    /** @return array<string, int|string|bool> */
    public static function coverage(array $document, array $inventory): array
    {
        $operationByInternalKey = [];
        foreach ($document['paths'] ?? [] as $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }
            foreach ($pathItem as $method => $operation) {
                if (!is_array($operation) || !is_string($operation['x-internal-path'] ?? null)) {
                    continue;
                }
                $operationByInternalKey[strtoupper((string)$method) . ' ' . $operation['x-internal-path']] = $operation;
            }
        }

        $public = 0;
        $external = 0;
        $criticalMutations = 0;
        $required = 0;
        $typed = 0;
        $typedRequests = 0;
        $requiredRequests = 0;
        foreach ($inventory as $route) {
            $key = (string)$route['method'] . ' ' . (string)$route['path'];
            $operation = $operationByInternalKey[$key] ?? [];
            $exposure = (string)($operation['x-exposure'] ?? '');
            if ($exposure === 'public') {
                $public++;
            } elseif ($exposure === 'external') {
                $external++;
            }
            $isCriticalMutation = in_array((string)$route['method'], self::MUTATING_METHODS, true)
                && self::isCriticalDomainPath((string)$route['path']);
            if ($isCriticalMutation) {
                $criticalMutations++;
            }
            if (!self::requiresSemanticContract($route, $exposure)) {
                continue;
            }
            $required++;
            if (($operation['x-contract-coverage']['businessDto'] ?? null) === 'typed') {
                $typed++;
            }
            $contract = self::contractFor($route, $exposure);
            if ($contract['requestSchema'] !== null) {
                $requiredRequests++;
                if (($operation['requestBody']['x-dto-coverage'] ?? null) === 'typed') {
                    $typedRequests++;
                }
            }
        }

        return [
            'operationInventory' => count($inventory),
            'publicOperations' => $public,
            'externalOperations' => $external,
            'criticalDomainMutations' => $criticalMutations,
            'requiredSemanticOperations' => $required,
            'typedSemanticOperations' => $typed,
            'requiredSemanticRequests' => $requiredRequests,
            'typedSemanticRequests' => $typedRequests,
            'requiredSurfacesComplete' => $required > 0 && $required === $typed && $requiredRequests === $typedRequests,
        ];
    }

    /** @return list<string> */
    public static function validationErrors(array $document, array $inventory): array
    {
        $errors = [];
        $operations = [];
        foreach ($document['paths'] ?? [] as $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }
            foreach ($pathItem as $method => $operation) {
                if (is_array($operation) && is_string($operation['x-internal-path'] ?? null)) {
                    $operations[strtoupper((string)$method) . ' ' . $operation['x-internal-path']] = $operation;
                }
            }
        }

        $genericRefs = [
            '#/components/schemas/GenericRequestObject',
            '#/components/schemas/StandardSuccessEnvelope',
            '#/components/schemas/CoreSuccessEnvelope',
            '#/components/schemas/BillingSuccessEnvelope',
            '#/components/schemas/UntypedBusinessData',
        ];
        foreach ($inventory as $route) {
            $key = (string)$route['method'] . ' ' . (string)$route['path'];
            $operation = $operations[$key] ?? null;
            if (!is_array($operation)) {
                continue;
            }
            $exposure = (string)($operation['x-exposure'] ?? '');
            if (!self::requiresSemanticContract($route, $exposure)) {
                continue;
            }
            if (($operation['x-contract-coverage']['businessDto'] ?? null) !== 'typed') {
                $errors[] = sprintf('%s debe declarar businessDto=typed.', $key);
            }
            if (($operation['x-contract-coverage']['schemaSource'] ?? null) !== 'runtime-derived-semantic-catalog') {
                $errors[] = sprintf('%s no conserva schemaSource semantico.', $key);
            }
            $serializedResponses = json_encode($operation['responses'] ?? [], JSON_UNESCAPED_SLASHES) ?: '';
            foreach ($genericRefs as $genericRef) {
                if (str_contains($serializedResponses, $genericRef)) {
                    $errors[] = sprintf('%s usa response generico prohibido %s.', $key, $genericRef);
                }
            }

            $contract = self::contractFor($route, $exposure);
            if ($contract['requestSchema'] !== null) {
                $requestBody = $operation['requestBody'] ?? null;
                if (!is_array($requestBody) || ($requestBody['x-dto-coverage'] ?? null) !== 'typed') {
                    $errors[] = sprintf('%s debe declarar requestBody semantico tipado.', $key);
                    continue;
                }
                $serializedRequest = json_encode($requestBody, JSON_UNESCAPED_SLASHES) ?: '';
                foreach ($genericRefs as $genericRef) {
                    if (str_contains($serializedRequest, $genericRef)) {
                        $errors[] = sprintf('%s usa request generico prohibido %s.', $key, $genericRef);
                    }
                }
                $expectedRef = '#/components/schemas/' . $contract['requestSchema'];
                if (!str_contains($serializedRequest, $expectedRef)) {
                    $errors[] = sprintf('%s no referencia su request DTO %s.', $key, $contract['requestSchema']);
                }
            }
        }

        $coverage = self::coverage($document, $inventory);
        $declared = $document['x-schema-coverage']['semantic'] ?? null;
        if (!is_array($declared) || $declared !== $coverage) {
            $errors[] = 'x-schema-coverage.semantic no coincide con la cobertura calculada.';
        }
        if (($coverage['requiredSurfacesComplete'] ?? false) !== true) {
            $errors[] = 'La cobertura semantica exigible no esta completa.';
        }

        return array_values(array_unique($errors));
    }

    /** @return array{mode: string, schema?: string}|null */
    private static function responseContract(array $route): ?array
    {
        $path = (string)$route['path'];
        $method = (string)$route['method'];
        $handlerMethod = self::handlerMethod($route);

        if ($method === 'HEAD') {
            return ['mode' => 'head'];
        }
        if ($handlerMethod === 'xml' || str_ends_with($path, '/xml')) {
            return ['mode' => 'xml'];
        }
        if (in_array($handlerMethod, ['ridepdf', 'pdf'], true) || str_ends_with($path, '.pdf') || str_ends_with($path, '/pdf')) {
            return ['mode' => 'pdf'];
        }
        if ($handlerMethod === 'invoice') {
            return ['mode' => 'invoice-document'];
        }
        if ($handlerMethod === 'rewardimage') {
            return ['mode' => 'binary'];
        }
        if (in_array($handlerMethod, [
            'publicgooglewalletlanding',
            'publiccatalog',
            'publicrewardsaccess',
            'publicrewardsaccessrequest',
            'publicrewardsaccessverify',
            'publicrewardsportal',
            'publicrewardsportalsession',
            'publicrewardsclaimsession',
            'publicrewardscancelsession',
        ], true)) {
            return ['mode' => 'html'];
        }

        $schema = match ($handlerMethod) {
            'live', 'ready' => 'CoreHealthData',
            'status' => str_contains($path, '/invoices/') ? 'BillingStatusData' : 'CoreHealthData',
            'health' => match (true) {
                $path === '/health' => 'BillingHealthData',
                $path === '/api/admin/billing/health' => 'BillingAdminHealthData',
                $path === '/api/loyalty/v1/health' => 'LoyaltyHealthData',
                default => null,
            },
            'login' => 'AuthLoginData',
            'session' => 'AuthSessionData',
            'logout' => 'AuthLogoutData',
            'register' => 'AuthRegistrationData',
            'requestaccess' => 'AuthAccessRequestData',
            'verify' => 'AuthVerifiedData',
            'requestotp', 'requestpasswordreset' => 'AuthSentData',
            'verifyotp' => 'AuthVerifiedData',
            'confirmpasswordreset' => 'AuthPasswordResetData',
            'cspreport' => 'CspReportResult',
            'index' => str_starts_with($path, '/api/products') ? 'PublicProductList' : (str_starts_with($path, '/api/orders') ? 'OrderList' : null),
            'adminindex' => str_starts_with($path, '/api/admin/products') ? 'PublicProductList' : null,
            'show', 'adminshow', 'store', 'update' => self::catalogCommerceResponseSchema($path, $handlerMethod),
            'patch' => str_starts_with($path, '/api/users/') ? 'ManagedUser' : null,
            'destroy' => str_contains($path, '/products/') ? 'ProductArchiveResult' : null,
            'movement' => 'ProductMovementData',
            'indexforproduct' => 'ProductReviewsData',
            'storeforproduct' => 'ProductReview',
            'quote' => 'OrderQuoteData',
            'updatestatus' => str_starts_with($path, '/api/orders/') ? 'Order' : 'ManagedUser',
            'getshipping' => 'ShippingSettings',
            'getvat', 'updatevat' => 'TaxSettingsData',
            'getstorestatus' => 'StoreStatus',
            'getpublicbrandlogos' => 'BrandLogoList',
            'getpublicproductcategories' => 'StringList',
            'getpublicproductcategoryreferences' => 'CategoryReferenceList',
            'admincreate', 'adminupdatemodules', 'adminupdateconfiguration',
            'adminlifecycle', 'adminupdatedomains', 'adminreconcile', 'adminrollback' => 'TenantSummary',
            'adminevents' => 'TenantRegistryEventList',
            'updateroles' => 'ManagedUserRolesResult',
            'unlock' => 'ManagedUser',
            'invitation', 'passwordreset' => 'ManagedUserAccountLinkResult',
            'revokesessions' => 'ManagedUserSessionsRevokeResult',
            'configuration', 'updateconfiguration', 'createbranch', 'updatebranch', 'uploadcertificate' => self::billingResponseSchema($path, $handlerMethod),
            'emit', 'emitfromproducts' => 'BillingInvoiceData',
            'rides' => 'BillingRideDocumentList',
            'source' => 'BillingRideDocument',
            'cancelandreissue' => 'BillingReissueResult',
            'mailtest' => 'BillingMailTestResult',
            'storeproduct' => 'BillingProductData',
            'externalprogram' => 'LoyaltyExternalProgramData',
            'externalrewards', 'rewards' => 'LoyaltyRewardList',
            'externalmember' => 'LoyaltyMemberPage',
            'externalmemberupsert', 'createmember', 'updatemember', 'updatewallet' => 'LoyaltyMember',
            'externalgooglewalletlink', 'googlewalletlink' => 'LoyaltyWalletLinkData',
            'externalpurchase', 'registerpurchase' => 'LoyaltyPurchaseResult',
            'externalpurchasereverse', 'reversepurchase' => 'LoyaltyPurchaseReverseResult',
            'externalredemption', 'redeemreward' => 'LoyaltyRedemptionResult',
            'externalreport' => 'LoyaltyReportData',
            'createreward', 'updatereward' => 'LoyaltyReward',
            'uploadrewardimage' => 'LoyaltyRewardImageResult',
            'deletereward' => 'LoyaltyRewardDeleteResult',
            'validateredemptionclaimcode', 'approveredemptionclaim', 'deliverredemptionclaim', 'cancelredemptionclaim' => 'LoyaltyRedemption',
            'adjustpoints' => 'LoyaltyAdjustmentResult',
            'updatesettings' => 'LoyaltySettingsData',
            'updaterules' => 'LoyaltyRulesData',
            'resolveriskevent' => 'LoyaltyRiskEvent',
            'createapiclient', 'updateapiclient', 'revokeapiclient' => 'LoyaltyApiClient',
            'rotateapiclient' => 'LoyaltyApiClientRotation',
            'notificationspreview' => 'LoyaltyNotificationPreview',
            'createnotificationcampaign' => 'LoyaltyNotificationCampaign',
            'googlewalletnotify' => 'LoyaltyWalletNotifyResult',
            default => null,
        };

        return is_string($schema) ? ['mode' => self::isBillingEnvelopePath($path) ? 'billing-json' : 'core-json', 'schema' => $schema] : null;
    }

    /** @return array{schema: string, mediaTypes: list<string>, required: bool}|null */
    private static function requestContract(array $route): ?array
    {
        if (!in_array((string)$route['method'], ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }
        $path = (string)$route['path'];
        $handler = self::handlerMethod($route);
        $schema = match ($handler) {
            'login' => 'AuthLoginRequest',
            'register' => 'AuthRegisterRequest',
            'requestaccess' => 'AuthAccessRequest',
            'requestotp' => 'AuthEmailRequest',
            'verifyotp' => 'AuthOtpVerifyRequest',
            'requestpasswordreset' => 'AuthPasswordResetRequest',
            'confirmpasswordreset' => 'AuthPasswordResetConfirmRequest',
            'store' => self::storeRequestSchema($path),
            'update' => str_contains($path, '/products/') ? 'ProductWriteRequest' : (str_starts_with($path, '/api/users/') ? 'ManagedUserReplaceRequest' : null),
            'storeforproduct' => 'ProductReviewCreateRequest',
            'quote' => 'OrderQuoteRequest',
            'updatestatus' => str_starts_with($path, '/api/orders/') ? 'OrderStatusRequest' : 'ManagedUserStatusRequest',
            'updatevat' => 'TaxSettingsUpdateRequest',
            'admincreate' => 'TenantCreateRequest',
            'adminupdatemodules' => 'TenantModulesRequest',
            'adminupdateconfiguration' => 'TenantConfigurationRequest',
            'adminlifecycle' => 'TenantLifecycleRequest',
            'adminupdatedomains' => 'TenantDomainsRequest',
            'adminreconcile' => 'TenantReconcileRequest',
            'adminrollback' => 'TenantRollbackRequest',
            'patch' => 'ManagedUserUpdateRequest',
            'updateroles' => 'ManagedUserRolesRequest',
            'updateconfiguration' => 'BillingConfigurationRequest',
            'createbranch' => 'BillingBranchCreateRequest',
            'updatebranch' => 'BillingBranchRequest',
            'uploadcertificate' => 'BillingCertificateUploadRequest',
            'emit' => 'BillingInvoiceRequest',
            'emitfromproducts' => 'BillingInvoiceFromProductsRequest',
            'storeproduct' => 'BillingProductRequest',
            'cancelandreissue' => 'BillingReissueRequest',
            'mailtest' => 'BillingMailTestRequest',
            'createmember' => 'LoyaltyMemberCreateRequest',
            'updatemember' => 'LoyaltyMemberUpdateRequest',
            'createreward' => 'LoyaltyRewardCreateRequest',
            'updatereward' => 'LoyaltyRewardRequest',
            'uploadrewardimage' => 'LoyaltyRewardImageRequest',
            'registerpurchase', 'externalpurchase' => 'LoyaltyPurchaseRequest',
            'reversepurchase', 'externalpurchasereverse' => 'LoyaltyPurchaseReverseRequest',
            'redeemreward', 'externalredemption' => 'LoyaltyRedemptionRequest',
            'validateredemptionclaimcode' => 'LoyaltyClaimCodeRequest',
            'approveredemptionclaim', 'deliverredemptionclaim', 'cancelredemptionclaim' => 'LoyaltyClaimActionRequest',
            'adjustpoints' => 'LoyaltyAdjustmentRequest',
            'updatesettings' => 'LoyaltySettingsPayload',
            'updaterules' => 'LoyaltyRulesRequest',
            'updatewallet' => 'LoyaltyWalletUpdateRequest',
            'googlewalletlink' => 'LoyaltyWalletLinkRequest',
            'googlewalletnotify' => 'LoyaltyWalletNotifyRequest',
            'externalmemberupsert' => 'LoyaltyExternalMemberUpsertRequest',
            'createapiclient' => 'LoyaltyApiClientCreateRequest',
            'updateapiclient' => 'LoyaltyApiClientRequest',
            'revokeapiclient' => 'LoyaltyReasonRequest',
            'rotateapiclient' => 'LoyaltyApiClientRotateRequest',
            'resolveriskevent' => 'LoyaltyRiskResolveRequest',
            'notificationspreview' => 'LoyaltyNotificationPreviewRequest',
            'createnotificationcampaign' => 'LoyaltyNotificationCreateRequest',
            'publicrewardsaccessrequest' => 'LoyaltyPortalAccessRequest',
            'publicrewardsaccessverify' => 'LoyaltyPortalVerifyRequest',
            'publicrewardsclaimsession' => 'LoyaltyPortalClaimRequest',
            'publicrewardscancelsession' => 'LoyaltyPortalCancelRequest',
            'cspreport' => 'CspReportRequest',
            default => null,
        };
        if (!is_string($schema)) {
            return null;
        }

        $mediaTypes = ['application/json'];
        if (in_array($schema, ['BillingCertificateUploadRequest', 'LoyaltyRewardImageRequest', 'CatalogImageUploadRequest'], true)) {
            $mediaTypes = ['multipart/form-data'];
        } elseif (str_starts_with($schema, 'LoyaltyPortal')) {
            $mediaTypes = ['application/json', 'application/x-www-form-urlencoded'];
        } elseif ($schema === 'CspReportRequest') {
            $mediaTypes = ['application/csp-report', 'application/reports+json', 'application/json'];
        }

        return ['schema' => $schema, 'mediaTypes' => $mediaTypes, 'required' => self::requestIsRequired($handler)];
    }

    private static function storeRequestSchema(string $path): ?string
    {
        return match (true) {
            $path === '/api/contact' => 'ContactRequest',
            $path === '/api/admin/catalog/images' => 'CatalogImageUploadRequest',
            $path === '/api/orders' => 'OrderCreateRequest',
            $path === '/api/products', $path === '/api/admin/products' => 'ProductWriteRequest',
            $path === '/api/users' => 'ManagedUserCreateRequest',
            default => null,
        };
    }

    private static function catalogCommerceResponseSchema(string $path, string $handler): ?string
    {
        if ($path === '/api/contact') {
            return 'ContactResult';
        }
        if ($path === '/api/admin/catalog/images') {
            return 'CatalogImageUploadResult';
        }
        if (str_contains($path, '/products')) {
            return $handler === 'destroy' ? 'ProductArchiveResult' : 'PublicProduct';
        }
        if (str_contains($path, '/orders')) {
            return 'Order';
        }
        if (str_starts_with($path, '/api/users')) {
            return 'ManagedUser';
        }

        return null;
    }

    private static function billingResponseSchema(string $path, string $handler): string
    {
        return 'BillingConfiguration';
    }

    private static function handlerConsumesBody(array $route): bool
    {
        if (!in_array((string)$route['method'], ['POST', 'PUT', 'PATCH'], true)) {
            return false;
        }
        $source = self::handlerSource((string)$route['handler']);
        return str_contains($source, 'php://input')
            || str_contains($source, 'jsonPayload(')
            || str_contains($source, 'jsonBody(')
            || str_contains($source, 'requestPayload(')
            || str_contains($source, '$_POST')
            || str_contains($source, '$_FILES');
    }

    private static function isCriticalDomainPath(string $path): bool
    {
        return str_starts_with($path, '/api/auth/')
            || str_starts_with($path, '/api/admin/tenants')
            || str_starts_with($path, '/api/users')
            || str_starts_with($path, '/api/admin/products')
            || $path === '/api/admin/catalog/images'
            || str_starts_with($path, '/api/products')
            || str_starts_with($path, '/api/orders')
            || str_starts_with($path, '/api/admin/billing')
            || str_starts_with($path, '/api/{apiMode}/v1/')
            || str_starts_with($path, '/api/admin/loyalty')
            || str_starts_with($path, '/api/loyalty/v1/');
    }

    private static function isBillingEnvelopePath(string $path): bool
    {
        return $path === '/health' || str_starts_with($path, '/api/{apiMode}/v1/');
    }

    private static function requestIsRequired(string $handler): bool
    {
        return !in_array($handler, [
            'mailtest',
            'cancelandreissue',
            'approveredemptionclaim',
            'deliverredemptionclaim',
            'cancelredemptionclaim',
            'revokeapiclient',
            'rotateapiclient',
            'resolveriskevent',
            'updatemember',
            'updatereward',
            'updateapiclient',
        ], true);
    }

    private static function handlerMethod(array $route): string
    {
        $handler = (string)$route['handler'];
        return strtolower(substr($handler, (int)strrpos($handler, '@') + 1));
    }

    private static function handlerSource(string $handler): string
    {
        if (substr_count($handler, '@') !== 1) {
            return '';
        }
        [$class, $method] = explode('@', $handler, 2);
        if (!class_exists($class) || !method_exists($class, $method)) {
            return '';
        }
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        if (!is_string($file) || !is_readable($file)) {
            return '';
        }
        $lines = file($file);
        if (!is_array($lines)) {
            return '';
        }
        $source = implode('', array_slice(
            $lines,
            (int)$reflection->getStartLine() - 1,
            (int)$reflection->getEndLine() - (int)$reflection->getStartLine() + 1
        ));
        preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $calls);
        $declaringClass = $reflection->getDeclaringClass();
        foreach (array_unique($calls[1] ?? []) as $calledMethod) {
            if (!$declaringClass->hasMethod($calledMethod)) {
                continue;
            }
            $called = $declaringClass->getMethod($calledMethod);
            $calledFile = $called->getFileName();
            $calledLines = is_string($calledFile) && is_readable($calledFile) ? file($calledFile) : false;
            if (!is_array($calledLines)) {
                continue;
            }
            $source .= implode('', array_slice(
                $calledLines,
                (int)$called->getStartLine() - 1,
                (int)$called->getEndLine() - (int)$called->getStartLine() + 1
            ));
        }

        return $source;
    }

    /** @return array<string, mixed> */
    private static function object(array $properties, array $required = [], bool|array $additionalProperties = false, array $anyOf = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => $additionalProperties,
        ];
        if ($required !== []) {
            $schema['required'] = array_values($required);
        }
        if ($anyOf !== []) {
            $schema['anyOf'] = $anyOf;
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    private static function string(?string $format = null, ?int $minLength = null, ?int $maxLength = null, ?string $pattern = null): array
    {
        $schema = ['type' => 'string'];
        if ($format !== null) {
            $schema['format'] = $format;
        }
        if ($minLength !== null) {
            $schema['minLength'] = $minLength;
        }
        if ($maxLength !== null) {
            $schema['maxLength'] = $maxLength;
        }
        if ($pattern !== null) {
            $schema['pattern'] = $pattern;
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    private static function integer(?int $minimum = null, ?int $maximum = null): array
    {
        $schema = ['type' => 'integer'];
        if ($minimum !== null) {
            $schema['minimum'] = $minimum;
        }
        if ($maximum !== null) {
            $schema['maximum'] = $maximum;
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    private static function number(float|int|null $minimum = null, float|int|null $maximum = null): array
    {
        $schema = ['type' => 'number'];
        if ($minimum !== null) {
            $schema['minimum'] = $minimum;
        }
        if ($maximum !== null) {
            $schema['maximum'] = $maximum;
        }
        return $schema;
    }

    /** @return array{type: string} */
    private static function boolean(): array
    {
        return ['type' => 'boolean'];
    }

    /** @return array{type: string, const: bool} */
    private static function constBoolean(bool $value): array
    {
        return ['type' => 'boolean', 'const' => $value];
    }

    /** @return array<string, mixed> */
    private static function enum(array $values): array
    {
        return ['type' => 'string', 'enum' => array_values($values)];
    }

    /** @return array{\$ref: string} */
    private static function ref(string $schema): array
    {
        return ['$ref' => '#/components/schemas/' . $schema];
    }

    /** @return array<string, mixed> */
    private static function listOf(array $items, ?int $minItems = null, ?int $maxItems = null): array
    {
        $schema = ['type' => 'array', 'items' => $items];
        if ($minItems !== null) {
            $schema['minItems'] = $minItems;
        }
        if ($maxItems !== null) {
            $schema['maxItems'] = $maxItems;
        }
        return $schema;
    }

    /** @return array{anyOf: array<int, array<string, mixed>>} */
    private static function nullable(array $schema): array
    {
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    /** @return array{oneOf: list<array<string, mixed>>} */
    private static function oneOf(array $schemas): array
    {
        return ['oneOf' => array_values($schemas)];
    }
}
