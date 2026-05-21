<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

function envValue(string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    $value = trim((string)$value);
    return $value === '' ? $default : $value;
}

function normalizeConfig(array $base, array $override = []): array {
    return [
        'host' => (string)($override['host'] ?? $base['host']),
        'port' => (string)($override['port'] ?? $base['port']),
        'database' => (string)($override['database'] ?? $base['database']),
        'username' => (string)($override['username'] ?? $base['username']),
        'password' => (string)($override['password'] ?? $base['password']),
    ];
}

function connect(array $config): PDO {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $config['host'],
        $config['port'],
        $config['database']
    );
    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function executeSchemaBootstrap(PDO $pdo, string $defaultTenant): void {
    $statements = [
        'CREATE TABLE IF NOT EXISTS "Tenant" (id text PRIMARY KEY, name text, created_at timestamp without time zone DEFAULT NOW())',
        'CREATE TABLE IF NOT EXISTS "User" (
            id text PRIMARY KEY,
            tenant_id text,
            email text NOT NULL,
            name text,
            password text NOT NULL,
            created_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            email_verified boolean DEFAULT false NOT NULL,
            verification_token text,
            role text DEFAULT \'customer\' NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "Product" (
            id text PRIMARY KEY,
            tenant_id text,
            legacy_id text,
            category text NOT NULL,
            product_type text,
            name text NOT NULL,
            gender text,
            is_new boolean DEFAULT false NOT NULL,
            is_sale boolean DEFAULT false NOT NULL,
            is_published boolean DEFAULT true NOT NULL,
            price numeric(12,4) NOT NULL,
            original_price numeric(12,4) NOT NULL,
            cost numeric(10,2) DEFAULT 0 NOT NULL,
            brand text,
            sold integer DEFAULT 0 NOT NULL,
            quantity integer NOT NULL,
            description text NOT NULL,
            action text,
            slug text NOT NULL,
            attributes jsonb,
            created_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "Image" (
            id text PRIMARY KEY,
            url text NOT NULL,
            product_id text,
            kind text,
            width integer,
            height integer
        )',
        'CREATE TABLE IF NOT EXISTS "Variation" (
            id text PRIMARY KEY,
            color text NOT NULL,
            color_code text,
            color_image text,
            image text,
            product_id text NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "Order" (
            id text PRIMARY KEY,
            tenant_id text,
            user_id text,
            status text DEFAULT \'pending\' NOT NULL,
            total numeric(10,2) NOT NULL,
            created_at timestamp(3) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
            shipping_address jsonb,
            billing_address jsonb,
            payment_method text
        )',
        'CREATE TABLE IF NOT EXISTS "OrderItem" (
            id text PRIMARY KEY,
            order_id text NOT NULL,
            product_id text NOT NULL,
            quantity integer NOT NULL,
            price numeric(10,2) NOT NULL,
            unit_cost numeric(12,4) DEFAULT 0 NOT NULL,
            cost_total numeric(12,4) DEFAULT 0 NOT NULL,
            product_name text,
            product_image text
        )',
        'CREATE TABLE IF NOT EXISTS "Quotation" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            status text DEFAULT \'quoted\' NOT NULL,
            customer_name text NOT NULL,
            customer_document_type text,
            customer_document_number text,
            customer_email text,
            customer_phone text,
            customer_address jsonb,
            delivery_method text DEFAULT \'pickup\' NOT NULL,
            payment_method text,
            discount_code text,
            notes text,
            items jsonb DEFAULT \'[]\'::jsonb NOT NULL,
            quote_snapshot jsonb DEFAULT \'{}\'::jsonb NOT NULL,
            created_by_user_id text,
            converted_order_id text,
            valid_until timestamp without time zone,
            converted_at timestamp without time zone,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "InventoryLot" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            product_id text NOT NULL,
            source_type text NOT NULL,
            source_ref text,
            purchase_invoice_id text,
            purchase_invoice_item_id text,
            unit_cost numeric(12,4) DEFAULT 0 NOT NULL,
            initial_quantity integer NOT NULL,
            remaining_quantity integer NOT NULL,
            metadata jsonb,
            received_at timestamp without time zone DEFAULT NOW() NOT NULL,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "InventoryLotAllocation" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            lot_id text NOT NULL,
            order_item_id text NOT NULL,
            product_id text NOT NULL,
            quantity integer NOT NULL,
            unit_cost numeric(12,4) DEFAULT 0 NOT NULL,
            metadata jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "PurchaseInvoice" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            supplier_name text NOT NULL,
            supplier_document text,
            invoice_number text NOT NULL,
            external_key text NOT NULL,
            issued_at date NOT NULL,
            subtotal numeric(12,4) DEFAULT 0 NOT NULL,
            tax_total numeric(12,4) DEFAULT 0 NOT NULL,
            total numeric(12,4) DEFAULT 0 NOT NULL,
            notes text,
            metadata jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "PurchaseInvoiceItem" (
            id text PRIMARY KEY,
            purchase_invoice_id text NOT NULL,
            tenant_id text NOT NULL,
            product_id text NOT NULL,
            product_name_snapshot text,
            quantity integer NOT NULL,
            unit_cost numeric(12,4) DEFAULT 0 NOT NULL,
            line_total numeric(12,4) DEFAULT 0 NOT NULL,
            metadata jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "Setting" (
            key text PRIMARY KEY,
            value text NOT NULL,
            tenant_id text
        )',
        'CREATE TABLE IF NOT EXISTS "ProductReferenceCatalog" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            catalog_key text NOT NULL,
            label text NOT NULL,
            payload jsonb DEFAULT \'{}\'::jsonb,
            sort_order integer DEFAULT 0 NOT NULL,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "DiscountCode" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            code text NOT NULL,
            name text,
            description text,
            type text NOT NULL,
            value numeric(12,2) NOT NULL,
            min_subtotal numeric(12,2) DEFAULT 0 NOT NULL,
            max_discount numeric(12,2),
            max_uses integer,
            used_count integer DEFAULT 0 NOT NULL,
            starts_at timestamp without time zone,
            ends_at timestamp without time zone,
            is_active boolean DEFAULT true NOT NULL,
            created_by text,
            metadata jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "DiscountAudit" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            discount_code_id text,
            code text,
            action text NOT NULL,
            reason text,
            order_id text,
            amount numeric(12,2),
            payload jsonb,
            user_id text,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "AuthSecurityEvent" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            user_id text,
            email text,
            event_type text NOT NULL,
            status text DEFAULT \'info\' NOT NULL,
            ip_address text,
            user_agent text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "ContactMessage" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            name text NOT NULL,
            email text NOT NULL,
            phone text,
            subject text NOT NULL,
            message text NOT NULL,
            source text DEFAULT \'web\' NOT NULL,
            status text DEFAULT \'new\' NOT NULL,
            ip_address text,
            user_agent text,
            metadata jsonb DEFAULT \'{}\'::jsonb,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS "PasswordResetToken" (
            id text PRIMARY KEY,
            tenant_id text NOT NULL,
            user_id text NOT NULL,
            token_hash text NOT NULL,
            expires_at timestamp without time zone NOT NULL,
            used_at timestamp without time zone,
            request_ip text,
            request_user_agent text,
            used_ip text,
            used_user_agent text,
            created_at timestamp without time zone DEFAULT NOW() NOT NULL,
            updated_at timestamp without time zone DEFAULT NOW() NOT NULL
        )',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS addresses jsonb',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS profile jsonb',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS document_type text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS document_number text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS business_name text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS delivery_method text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS otp_code text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS otp_expires_at timestamp',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS otp_attempts integer',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS failed_login_attempts integer',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS login_locked_until timestamp',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS last_login_at timestamp',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS active_token_id text',
        'ALTER TABLE "User" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS product_type text',
        'ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS attributes jsonb',
        'ALTER TABLE "Product" ADD COLUMN IF NOT EXISTS is_published boolean',
        'ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS kind text',
        'ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS width integer',
        'ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS height integer',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_number text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_html text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_created_at timestamp(3) without time zone',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS invoice_data jsonb',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS payment_details jsonb',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS items_subtotal numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS vat_subtotal numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS vat_rate numeric(6,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS vat_amount numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping_base numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping_tax_rate numeric(6,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS shipping_tax_amount numeric(12,2)',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS order_notes text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS discount_code text',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS discount_total numeric(12,2) DEFAULT 0',
        'ALTER TABLE "Order" ADD COLUMN IF NOT EXISTS discount_snapshot jsonb',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS tenant_id text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS status text DEFAULT \'quoted\'',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_name text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_document_type text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_document_number text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_email text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_phone text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS customer_address jsonb',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS delivery_method text DEFAULT \'pickup\'',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS payment_method text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS discount_code text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS notes text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS items jsonb DEFAULT \'[]\'::jsonb',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS quote_snapshot jsonb DEFAULT \'{}\'::jsonb',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS created_by_user_id text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS converted_order_id text',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS valid_until timestamp without time zone',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS converted_at timestamp without time zone',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS created_at timestamp without time zone DEFAULT NOW()',
        'ALTER TABLE "Quotation" ADD COLUMN IF NOT EXISTS updated_at timestamp without time zone DEFAULT NOW()',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS unit_cost numeric(12,4)',
        'ALTER TABLE "OrderItem" ALTER COLUMN unit_cost TYPE numeric(12,4) USING COALESCE(unit_cost, 0)::numeric(12,4)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS cost_total numeric(12,4)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS price_net numeric(12,4)',
        'ALTER TABLE "OrderItem" ALTER COLUMN price_net TYPE numeric(12,4) USING COALESCE(price_net, 0)::numeric(12,4)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS net_total numeric(12,4)',
        'ALTER TABLE "OrderItem" ALTER COLUMN net_total TYPE numeric(12,4) USING COALESCE(net_total, 0)::numeric(12,4)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS tax_rate numeric(6,2)',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_rate TYPE numeric(6,2) USING COALESCE(tax_rate, 0)::numeric(6,2)',
        'ALTER TABLE "OrderItem" ADD COLUMN IF NOT EXISTS tax_amount numeric(12,4)',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_amount TYPE numeric(12,4) USING COALESCE(tax_amount, 0)::numeric(12,4)',
        'ALTER TABLE "InventoryLot" ADD COLUMN IF NOT EXISTS purchase_invoice_id text',
        'ALTER TABLE "InventoryLot" ADD COLUMN IF NOT EXISTS purchase_invoice_item_id text',
        'ALTER TABLE "Setting" ADD COLUMN IF NOT EXISTS tenant_id text',
        'UPDATE "OrderItem" oi SET unit_cost = COALESCE((
            SELECT p.cost
            FROM "Order" o
            LEFT JOIN "Product" p ON p.id = oi.product_id AND p.tenant_id = o.tenant_id
            WHERE o.id = oi.order_id
            LIMIT 1
        ), 0) WHERE oi.unit_cost IS NULL',
        'UPDATE "OrderItem" SET cost_total = ROUND((COALESCE(quantity, 0) * COALESCE(unit_cost, 0))::numeric, 4) WHERE cost_total IS NULL',
        'UPDATE "OrderItem" oi
            SET price_net = ROUND((
                COALESCE(oi.price, 0) / NULLIF((1 + (COALESCE(o.vat_rate, 0) / 100.0)), 0)
            )::numeric, 4)
          FROM "Order" o
          WHERE oi.order_id = o.id
            AND oi.price_net IS NULL',
        'UPDATE "OrderItem" oi
            SET net_total = ROUND((
                COALESCE(oi.quantity, 0) * COALESCE(oi.price_net, 0)
            )::numeric, 4)
          WHERE oi.net_total IS NULL',
        'UPDATE "OrderItem" oi
            SET tax_rate = COALESCE(o.vat_rate, 0)
          FROM "Order" o
          WHERE oi.order_id = o.id
            AND oi.tax_rate IS NULL',
        'UPDATE "OrderItem" oi
            SET tax_amount = ROUND((
                (COALESCE(oi.quantity, 0) * COALESCE(oi.price, 0)) - COALESCE(oi.net_total, 0)
            )::numeric, 4)
          WHERE oi.tax_amount IS NULL',
        'UPDATE "Product" SET is_published = true WHERE is_published IS NULL',
        'ALTER TABLE "Product" ALTER COLUMN is_published SET DEFAULT true',
        'ALTER TABLE "Product" ALTER COLUMN is_published SET NOT NULL',
        'UPDATE "OrderItem" SET unit_cost = 0 WHERE unit_cost IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN unit_cost SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN unit_cost SET NOT NULL',
        'UPDATE "OrderItem" SET cost_total = 0 WHERE cost_total IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN cost_total SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN cost_total SET NOT NULL',
        'UPDATE "OrderItem" SET price_net = 0 WHERE price_net IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN price_net SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN price_net SET NOT NULL',
        'UPDATE "OrderItem" SET net_total = 0 WHERE net_total IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN net_total SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN net_total SET NOT NULL',
        'UPDATE "OrderItem" SET tax_rate = 0 WHERE tax_rate IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_rate SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_rate SET NOT NULL',
        'UPDATE "OrderItem" SET tax_amount = 0 WHERE tax_amount IS NULL',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_amount SET DEFAULT 0',
        'ALTER TABLE "OrderItem" ALTER COLUMN tax_amount SET NOT NULL',
        'ALTER TABLE "User" DROP CONSTRAINT IF EXISTS "User_email_key"',
        'ALTER TABLE "Product" DROP CONSTRAINT IF EXISTS "Product_slug_key"',
        'DROP INDEX IF EXISTS "Product_legacy_id_key"',
        'CREATE INDEX IF NOT EXISTS "User_tenant_id_idx" ON "User" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "User_tenant_email_idx" ON "User" (tenant_id, email)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "User_tenant_email_uidx" ON "User" (tenant_id, email)',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_id_idx" ON "Product" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_published_idx" ON "Product" (tenant_id, is_published)',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_slug_idx" ON "Product" (tenant_id, slug)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "Product_tenant_slug_uidx" ON "Product" (tenant_id, slug)',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_legacy_id_idx" ON "Product" (tenant_id, legacy_id)',
        'CREATE INDEX IF NOT EXISTS "Product_tenant_created_idx" ON "Product" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "Product_catalog_listing_idx" ON "Product" (tenant_id, created_at DESC) WHERE COALESCE(is_published, true) = true AND COALESCE(quantity, 0) > 0',
        'CREATE UNIQUE INDEX IF NOT EXISTS "Product_tenant_sku_active_uidx" ON "Product" (tenant_id, upper(trim(attributes->>\'sku\'))) WHERE COALESCE(NULLIF(trim(attributes->>\'sku\'), \'\'), \'\') <> \'\' AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE UNIQUE INDEX IF NOT EXISTS "Product_tenant_variant_label_active_uidx" ON "Product" (tenant_id, (attributes->>\'variantGroupKey\'), (attributes->>\'variantLabel\')) WHERE COALESCE(NULLIF(attributes->>\'variantGroupKey\', \'\'), \'\') <> \'\' AND COALESCE(NULLIF(attributes->>\'variantLabel\', \'\'), \'\') <> \'\' AND COALESCE(attributes->>\'archived\', \'false\') <> \'true\'',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_id_idx" ON "Order" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_created_idx" ON "Order" (tenant_id, created_at)',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_status_created_idx" ON "Order" (tenant_id, lower(COALESCE(status, \'pending\')), created_at)',
        'CREATE INDEX IF NOT EXISTS "Order_tenant_user_idx" ON "Order" (tenant_id, user_id)',
        'CREATE INDEX IF NOT EXISTS "Quotation_tenant_created_idx" ON "Quotation" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "Quotation_tenant_status_idx" ON "Quotation" (tenant_id, status, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "Quotation_tenant_converted_order_idx" ON "Quotation" (tenant_id, converted_order_id)',
        'CREATE INDEX IF NOT EXISTS "OrderItem_order_id_idx" ON "OrderItem" (order_id)',
        'CREATE INDEX IF NOT EXISTS "OrderItem_product_id_idx" ON "OrderItem" (product_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_product_received_idx" ON "InventoryLot" (tenant_id, product_id, received_at, created_at)',
        'CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_product_remaining_idx" ON "InventoryLot" (tenant_id, product_id, remaining_quantity)',
        'CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_purchase_invoice_idx" ON "InventoryLot" (tenant_id, purchase_invoice_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLot_tenant_purchase_invoice_item_idx" ON "InventoryLot" (tenant_id, purchase_invoice_item_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLotAllocation_tenant_order_item_idx" ON "InventoryLotAllocation" (tenant_id, order_item_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLotAllocation_tenant_product_idx" ON "InventoryLotAllocation" (tenant_id, product_id)',
        'CREATE INDEX IF NOT EXISTS "InventoryLotAllocation_tenant_lot_idx" ON "InventoryLotAllocation" (tenant_id, lot_id)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "PurchaseInvoice_tenant_external_key_uidx" ON "PurchaseInvoice" (tenant_id, external_key)',
        'CREATE INDEX IF NOT EXISTS "PurchaseInvoice_tenant_issued_idx" ON "PurchaseInvoice" (tenant_id, issued_at DESC, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "PurchaseInvoiceItem_tenant_invoice_idx" ON "PurchaseInvoiceItem" (tenant_id, purchase_invoice_id, created_at ASC)',
        'CREATE INDEX IF NOT EXISTS "PurchaseInvoiceItem_tenant_product_idx" ON "PurchaseInvoiceItem" (tenant_id, product_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "Image_product_id_idx" ON "Image" (product_id)',
        'CREATE INDEX IF NOT EXISTS "Variation_product_id_idx" ON "Variation" (product_id)',
        'CREATE INDEX IF NOT EXISTS "Setting_tenant_id_idx" ON "Setting" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "ProductReferenceCatalog_tenant_id_idx" ON "ProductReferenceCatalog" (tenant_id)',
        'CREATE INDEX IF NOT EXISTS "ProductReferenceCatalog_tenant_catalog_idx" ON "ProductReferenceCatalog" (tenant_id, catalog_key, sort_order)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "DiscountCode_tenant_code_uidx" ON "DiscountCode" (tenant_id, code)',
        'CREATE INDEX IF NOT EXISTS "DiscountCode_tenant_active_idx" ON "DiscountCode" (tenant_id, is_active)',
        'CREATE INDEX IF NOT EXISTS "DiscountCode_tenant_window_idx" ON "DiscountCode" (tenant_id, starts_at, ends_at)',
        'CREATE INDEX IF NOT EXISTS "DiscountAudit_tenant_created_idx" ON "DiscountAudit" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "DiscountAudit_tenant_code_idx" ON "DiscountAudit" (tenant_id, code)',
        'CREATE INDEX IF NOT EXISTS "DiscountAudit_tenant_order_idx" ON "DiscountAudit" (tenant_id, order_id)',
        'CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_created_idx" ON "AuthSecurityEvent" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_event_idx" ON "AuthSecurityEvent" (tenant_id, event_type, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_user_idx" ON "AuthSecurityEvent" (tenant_id, user_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "AuthSecurityEvent_tenant_email_idx" ON "AuthSecurityEvent" (tenant_id, email, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "ContactMessage_tenant_created_idx" ON "ContactMessage" (tenant_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "ContactMessage_tenant_status_idx" ON "ContactMessage" (tenant_id, status)',
        'CREATE UNIQUE INDEX IF NOT EXISTS "PasswordResetToken_tenant_hash_uidx" ON "PasswordResetToken" (tenant_id, token_hash)',
        'CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_user_idx" ON "PasswordResetToken" (tenant_id, user_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_expires_idx" ON "PasswordResetToken" (tenant_id, expires_at)',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLot_product_id_fkey\') THEN ALTER TABLE "InventoryLot" ADD CONSTRAINT "InventoryLot_product_id_fkey" FOREIGN KEY (product_id) REFERENCES "Product"(id) ON UPDATE CASCADE ON DELETE RESTRICT; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLot_purchase_invoice_id_fkey\') THEN ALTER TABLE "InventoryLot" ADD CONSTRAINT "InventoryLot_purchase_invoice_id_fkey" FOREIGN KEY (purchase_invoice_id) REFERENCES "PurchaseInvoice"(id) ON UPDATE CASCADE ON DELETE SET NULL; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLot_purchase_invoice_item_id_fkey\') THEN ALTER TABLE "InventoryLot" ADD CONSTRAINT "InventoryLot_purchase_invoice_item_id_fkey" FOREIGN KEY (purchase_invoice_item_id) REFERENCES "PurchaseInvoiceItem"(id) ON UPDATE CASCADE ON DELETE SET NULL; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'PurchaseInvoiceItem_purchase_invoice_id_fkey\') THEN ALTER TABLE "PurchaseInvoiceItem" ADD CONSTRAINT "PurchaseInvoiceItem_purchase_invoice_id_fkey" FOREIGN KEY (purchase_invoice_id) REFERENCES "PurchaseInvoice"(id) ON UPDATE CASCADE ON DELETE CASCADE; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'PurchaseInvoiceItem_product_id_fkey\') THEN ALTER TABLE "PurchaseInvoiceItem" ADD CONSTRAINT "PurchaseInvoiceItem_product_id_fkey" FOREIGN KEY (product_id) REFERENCES "Product"(id) ON UPDATE CASCADE ON DELETE RESTRICT; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLotAllocation_lot_id_fkey\') THEN ALTER TABLE "InventoryLotAllocation" ADD CONSTRAINT "InventoryLotAllocation_lot_id_fkey" FOREIGN KEY (lot_id) REFERENCES "InventoryLot"(id) ON UPDATE CASCADE ON DELETE RESTRICT; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLotAllocation_product_id_fkey\') THEN ALTER TABLE "InventoryLotAllocation" ADD CONSTRAINT "InventoryLotAllocation_product_id_fkey" FOREIGN KEY (product_id) REFERENCES "Product"(id) ON UPDATE CASCADE ON DELETE RESTRICT; END IF; END $$',
        'DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = \'InventoryLotAllocation_order_item_id_fkey\') THEN ALTER TABLE "InventoryLotAllocation" ADD CONSTRAINT "InventoryLotAllocation_order_item_id_fkey" FOREIGN KEY (order_item_id) REFERENCES "OrderItem"(id) ON UPDATE CASCADE ON DELETE SET NULL; END IF; END $$',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }

    $stmtUser = $pdo->prepare('UPDATE "User" SET tenant_id = COALESCE(tenant_id, :tenant)');
    $stmtUser->execute(['tenant' => $defaultTenant]);

    $stmtProduct = $pdo->prepare('UPDATE "Product" SET tenant_id = COALESCE(tenant_id, :tenant)');
    $stmtProduct->execute(['tenant' => $defaultTenant]);

    $pdo->exec('
        INSERT INTO "InventoryLot" (
            id,
            tenant_id,
            product_id,
            source_type,
            source_ref,
            unit_cost,
            initial_quantity,
            remaining_quantity,
            metadata,
            received_at,
            created_at,
            updated_at
        )
        SELECT
            \'lot_seed_\' || md5(COALESCE(p.tenant_id, \'\') || \':\' || COALESCE(p.id, \'\') || \':opening\'),
            p.tenant_id,
            p.id,
            \'bootstrap_opening\',
            p.id,
            COALESCE(p.cost, 0)::numeric(12,4),
            COALESCE(p.quantity, 0),
            COALESCE(p.quantity, 0),
            jsonb_build_object(\'seed\', \'bootstrap_schema\'),
            COALESCE(p.created_at, NOW()),
            NOW(),
            NOW()
        FROM "Product" p
        WHERE COALESCE(p.quantity, 0) > 0
          AND COALESCE(p.tenant_id, \'\') <> \'\'
          AND NOT EXISTS (
              SELECT 1
              FROM "InventoryLot" l
              WHERE l.tenant_id = p.tenant_id
                AND l.product_id = p.id
          )
    ');

    $stmtOrder = $pdo->prepare('UPDATE "Order" SET tenant_id = COALESCE(tenant_id, :tenant)');
    $stmtOrder->execute(['tenant' => $defaultTenant]);

    $stmtQuotation = $pdo->prepare('UPDATE "Quotation" SET tenant_id = COALESCE(tenant_id, :tenant)');
    $stmtQuotation->execute(['tenant' => $defaultTenant]);

    $stmtSettingTenant = $pdo->prepare('UPDATE "Setting" SET tenant_id = COALESCE(tenant_id, :tenant) WHERE tenant_id IS NULL');
    $stmtSettingTenant->execute(['tenant' => $defaultTenant]);

    $stmtSettingKeys = $pdo->prepare('
        UPDATE "Setting" s
        SET key = :prefix || s.key
        WHERE s.key NOT LIKE :pattern
          AND NOT EXISTS (
            SELECT 1 FROM "Setting" t WHERE t.key = :prefix || s.key
          )
    ');
    $stmtSettingKeys->execute([
        'prefix' => $defaultTenant . ':',
        'pattern' => '%:%',
    ]);

    $catalogCountStmt = $pdo->prepare('SELECT COUNT(*) FROM "ProductReferenceCatalog" WHERE tenant_id = :tenant_id');
    $catalogCountStmt->execute(['tenant_id' => $defaultTenant]);
    $catalogCount = (int)$catalogCountStmt->fetchColumn();

    if ($catalogCount === 0) {
        $legacySettingStmt = $pdo->prepare('SELECT value FROM "Setting" WHERE key = :key LIMIT 1');
        $legacySettingStmt->execute(['key' => $defaultTenant . ':product_reference_data']);
        $legacyRaw = $legacySettingStmt->fetchColumn();

        if (is_string($legacyRaw) && trim($legacyRaw) !== '') {
            $decoded = json_decode($legacyRaw, true);
            if (is_array($decoded)) {
                $insertCatalogStmt = $pdo->prepare('
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

                foreach ($decoded as $catalogKey => $values) {
                    if (!is_array($values)) {
                        continue;
                    }

                    foreach (array_values($values) as $index => $value) {
                        if ($catalogKey === 'suppliers' && is_array($value)) {
                            $name = trim((string)($value['name'] ?? ''));
                            if ($name === '') {
                                continue;
                            }

                            $supplierId = trim((string)($value['id'] ?? ''));
                            if ($supplierId === '') {
                                $supplierId = 'prc_' . substr(hash('sha256', $defaultTenant . '|suppliers|' . $name . '|' . ($index + 1)), 0, 28);
                            }

                            $payload = [
                                'id' => $supplierId,
                                'name' => $name,
                                'document' => trim((string)($value['document'] ?? '')),
                                'email' => trim((string)($value['email'] ?? '')),
                                'phone' => trim((string)($value['phone'] ?? '')),
                                'contactName' => trim((string)($value['contactName'] ?? '')),
                                'address' => trim((string)($value['address'] ?? '')),
                                'notes' => trim((string)($value['notes'] ?? '')),
                            ];

                            $insertCatalogStmt->execute([
                                'id' => $supplierId,
                                'tenant_id' => $defaultTenant,
                                'catalog_key' => 'suppliers',
                                'label' => $name,
                                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'sort_order' => $index,
                            ]);
                            continue;
                        }

                        if ($catalogKey === 'brands' && is_array($value)) {
                            $label = trim((string)($value['name'] ?? ($value['label'] ?? '')));
                            $payload = [
                                'id' => trim((string)($value['id'] ?? '')),
                                'name' => $label,
                                'logoUrl' => trim((string)($value['logoUrl'] ?? ($value['logo_url'] ?? ''))),
                            ];
                            if ($payload['id'] === '') {
                                $payload['id'] = 'prc_' . substr(hash('sha256', $defaultTenant . '|' . $catalogKey . '|' . $label . '|' . ($index + 1)), 0, 28);
                            }
                        } else {
                            $label = trim((string)$value);
                            $payload = ['label' => $label];
                        }
                        if ($label === '') {
                            continue;
                        }

                        $rowId = $catalogKey === 'brands' && is_array($payload) && trim((string)($payload['id'] ?? '')) !== ''
                            ? (string)$payload['id']
                            : 'prc_' . substr(hash('sha256', $defaultTenant . '|' . $catalogKey . '|' . $label . '|' . ($index + 1)), 0, 28);

                        $insertCatalogStmt->execute([
                            'id' => $rowId,
                            'tenant_id' => $defaultTenant,
                            'catalog_key' => (string)$catalogKey,
                            'label' => $label,
                            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'sort_order' => $index,
                        ]);
                    }
                }
            }
        }
    }
}

$defaultConfig = [
    'host' => envValue('DB_HOST', 'db'),
    'port' => envValue('DB_PORT', '5432'),
    'database' => envValue('DB_DATABASE', 'paramascotasec'),
    'username' => envValue('DB_USERNAME', 'postgres'),
    'password' => envValue('DB_PASSWORD', 'postgres'),
];

$defaultTenant = envValue('DEFAULT_TENANT', 'paramascotasec');
$tenants = [];
$tenantsFile = __DIR__ . '/../config/tenants.php';
if (file_exists($tenantsFile)) {
    $loaded = require $tenantsFile;
    if (is_array($loaded)) {
        $tenants = $loaded;
    }
}

$targets = [];
$addTarget = static function (array $config) use (&$targets): void {
    $key = implode('|', [$config['host'], $config['port'], $config['database'], $config['username']]);
    $targets[$key] = $config;
};

$addTarget(normalizeConfig($defaultConfig));

foreach ($tenants as $tenant) {
    if (!is_array($tenant)) {
        continue;
    }
    $tenantDb = is_array($tenant['db'] ?? null) ? $tenant['db'] : [];
    $addTarget(normalizeConfig($defaultConfig, $tenantDb));
}

$tenantInsertRows = [];
foreach ($tenants as $slug => $tenant) {
    if (!is_string($slug) || $slug === '') {
        continue;
    }
    $tenantInsertRows[] = [
        'id' => (string)($tenant['id'] ?? $slug),
        'name' => (string)($tenant['name'] ?? $slug),
    ];
}

if (count($tenantInsertRows) === 0) {
    $tenantInsertRows[] = ['id' => $defaultTenant, 'name' => $defaultTenant];
}

try {
    foreach ($targets as $target) {
        $pdo = connect($target);
        executeSchemaBootstrap($pdo, $defaultTenant);
        $insertTenant = $pdo->prepare('INSERT INTO "Tenant" (id, name) VALUES (:id, :name) ON CONFLICT (id) DO NOTHING');
        foreach ($tenantInsertRows as $row) {
            $insertTenant->execute($row);
        }
        fwrite(STDOUT, sprintf(
            "[schema] ok host=%s db=%s user=%s\n",
            $target['host'],
            $target['database'],
            $target['username']
        ));
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[schema] error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);
