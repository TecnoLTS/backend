BEGIN;

CREATE FUNCTION pg_temp.pm_normalize_key_part(value text)
RETURNS text
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT COALESCE(NULLIF(regexp_replace(upper(trim(COALESCE(value, ''))), '[^A-Z0-9]+', '', 'g'), ''), 'NA')
$$;

CREATE FUNCTION pg_temp.pm_slug(value text)
RETURNS text
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT COALESCE(
        NULLIF(
            trim(both '-' from regexp_replace(
                lower(translate(COALESCE(value, ''),
                    'ÁÀÄÂÃÉÈËÊÍÌÏÎÓÒÖÔÕÚÙÜÛÑÇáàäâãéèëêíìïîóòöôõúùüûñç/',
                    'AAAAAEEEEIIIIOOOOOUUUUNCaaaaaeeeeiiiiooooouuuunc-'
                )),
                '[^a-z0-9]+',
                '-',
                'g'
            )),
            ''
        ),
        'na'
    )
$$;

WITH legacy_products AS (
    SELECT
        p.id AS product_id,
        p.tenant_id,
        p.name AS product_name,
        COALESCE(NULLIF(trim(p.attributes->>'supplier'), ''), 'Proveedor legacy') AS supplier_name,
        COALESCE(NULLIF(trim(src.payload->>'document'), ''), 'LEGACY-' || pg_temp.pm_normalize_key_part(COALESCE(NULLIF(trim(p.attributes->>'supplier'), ''), 'PROVEEDOR'))) AS supplier_document,
        trim(p.attributes->>'purchaseInvoiceNumber') AS invoice_number,
        COALESCE(NULLIF(trim(p.attributes->>'purchaseInvoiceDate'), ''), p.created_at::date::text) AS issued_at,
        NULLIF(trim(p.attributes->>'purchaseInvoiceNotes'), '') AS notes,
        COALESCE(NULLIF(trim(p.attributes->>'purchaseTaxRate'), '')::numeric, NULLIF(trim(src.payload->>'purchaseTaxRate'), '')::numeric, 0) AS tax_rate,
        SUM(il.initial_quantity)::int AS quantity,
        CASE
            WHEN SUM(il.initial_quantity) > 0 THEN ROUND(SUM(il.initial_quantity * il.unit_cost) / SUM(il.initial_quantity), 4)
            ELSE ROUND(MAX(COALESCE(il.unit_cost, p.cost, 0)), 4)
        END AS unit_cost,
        ROUND(SUM(il.initial_quantity * il.unit_cost), 4) AS line_total
    FROM "Product" p
    JOIN "InventoryLot" il
      ON il.tenant_id = p.tenant_id
     AND il.product_id = p.id
    LEFT JOIN "ProductReferenceCatalog" src
      ON src.tenant_id = p.tenant_id
     AND src.catalog_key = 'suppliers'
     AND lower(trim(src.label)) = lower(trim(p.attributes->>'supplier'))
    WHERE p.tenant_id = 'paramascotasec'
      AND COALESCE(NULLIF(trim(p.attributes->>'purchaseInvoiceNumber'), ''), '') <> ''
      AND il.source_type = 'bootstrap_opening'
    GROUP BY p.id, p.tenant_id, p.name, p.attributes, p.created_at, src.payload
),
legacy_invoices AS (
    SELECT
        tenant_id,
        supplier_name,
        supplier_document,
        invoice_number,
        issued_at::date AS issued_at,
        string_agg(DISTINCT notes, E'\n') FILTER (WHERE notes IS NOT NULL) AS notes,
        pg_temp.pm_normalize_key_part(invoice_number) || '|' || pg_temp.pm_normalize_key_part(supplier_document) AS external_key
    FROM legacy_products
    GROUP BY tenant_id, supplier_name, supplier_document, invoice_number, issued_at
),
inserted_invoices AS (
    INSERT INTO "PurchaseInvoice" (
        id,
        tenant_id,
        supplier_name,
        supplier_document,
        invoice_number,
        external_key,
        issued_at,
        subtotal,
        tax_total,
        total,
        notes,
        metadata,
        created_at,
        updated_at
    )
    SELECT
        'pinv_legacy_' || substr(md5(tenant_id || '|' || external_key), 1, 18),
        tenant_id,
        supplier_name,
        supplier_document,
        invoice_number,
        external_key,
        issued_at,
        0,
        0,
        0,
        notes,
        jsonb_build_object('source', 'legacy_product_attributes', 'migration', '2026-04-27_product_consistency_repair'),
        NOW(),
        NOW()
    FROM legacy_invoices li
    WHERE NOT EXISTS (
        SELECT 1
        FROM "PurchaseInvoice" pi
        WHERE pi.tenant_id = li.tenant_id
          AND pi.external_key = li.external_key
    )
    RETURNING id, tenant_id, external_key
),
invoice_lookup AS (
    SELECT pi.id, pi.tenant_id, pi.external_key
    FROM "PurchaseInvoice" pi
    JOIN legacy_invoices li
      ON li.tenant_id = pi.tenant_id
     AND li.external_key = pi.external_key
),
legacy_items AS (
    SELECT
        lp.*,
        ilook.id AS purchase_invoice_id,
        'pitem_legacy_' || substr(md5(lp.tenant_id || '|' || ilook.id || '|' || lp.product_id), 1, 18) AS item_id
    FROM legacy_products lp
    JOIN invoice_lookup ilook
      ON ilook.tenant_id = lp.tenant_id
     AND ilook.external_key = pg_temp.pm_normalize_key_part(lp.invoice_number) || '|' || pg_temp.pm_normalize_key_part(lp.supplier_document)
),
inserted_items AS (
    INSERT INTO "PurchaseInvoiceItem" (
        id,
        purchase_invoice_id,
        tenant_id,
        product_id,
        product_name_snapshot,
        quantity,
        unit_cost,
        line_total,
        metadata,
        created_at,
        updated_at
    )
    SELECT
        item_id,
        purchase_invoice_id,
        tenant_id,
        product_id,
        product_name,
        quantity,
        unit_cost,
        line_total,
        jsonb_build_object(
            'source', 'legacy_product_attributes',
            'migration', '2026-04-27_product_consistency_repair',
            'tax_rate', ROUND(tax_rate, 2),
            'tax_exempt', tax_rate <= 0,
            'purchase_tax_rate', ROUND(tax_rate, 2),
            'purchase_tax_exempt', tax_rate <= 0
        ),
        NOW(),
        NOW()
    FROM legacy_items li
    WHERE quantity > 0
      AND NOT EXISTS (
        SELECT 1
        FROM "PurchaseInvoiceItem" pii
        WHERE pii.tenant_id = li.tenant_id
          AND pii.purchase_invoice_id = li.purchase_invoice_id
          AND pii.product_id = li.product_id
      )
    RETURNING id
),
updated_lots AS (
    UPDATE "InventoryLot" il
    SET source_type = 'purchase_invoice',
        source_ref = li.invoice_number,
        purchase_invoice_id = li.purchase_invoice_id,
        purchase_invoice_item_id = li.item_id,
        metadata = COALESCE(il.metadata, '{}'::jsonb)
            || jsonb_build_object(
                'previous_source_type', il.source_type,
                'previous_source_ref', il.source_ref,
                'migration', '2026-04-27_product_consistency_repair',
                'legacy_purchase_invoice_number', li.invoice_number
            ),
        updated_at = NOW()
    FROM legacy_items li
    WHERE il.tenant_id = li.tenant_id
      AND il.product_id = li.product_id
      AND il.source_type = 'bootstrap_opening'
      AND il.purchase_invoice_id IS NULL
    RETURNING il.id
)
UPDATE "PurchaseInvoice" pi
SET subtotal = totals.subtotal,
    tax_total = totals.tax_total,
    total = totals.subtotal + totals.tax_total,
    updated_at = NOW()
FROM (
    SELECT
        pii.tenant_id,
        pii.purchase_invoice_id,
        ROUND(SUM(pii.line_total), 4) AS subtotal,
        ROUND(SUM(pii.line_total * COALESCE(NULLIF(pii.metadata->>'tax_rate', '')::numeric, 0) / 100), 4) AS tax_total
    FROM "PurchaseInvoiceItem" pii
    GROUP BY pii.tenant_id, pii.purchase_invoice_id
) totals
WHERE pi.tenant_id = totals.tenant_id
  AND pi.id = totals.purchase_invoice_id;

-- Repair imported single-product group keys so variants with the same family can group.
UPDATE "Product" p
SET attributes = jsonb_set(
        p.attributes,
        '{variantGroupKey}',
        to_jsonb(
            pg_temp.pm_slug(COALESCE(NULLIF(p.attributes->>'supplier', ''), p.brand, 'paramascotas'))
            || '-' || pg_temp.pm_slug(COALESCE(NULLIF(p.product_type, ''), p.category, 'producto'))
            || '-' || pg_temp.pm_slug(COALESCE(NULLIF(p.attributes->>'species', ''), p.gender, 'mascota'))
            || '-' || pg_temp.pm_slug(COALESCE(NULLIF(p.attributes->>'variantBaseName', ''), p.name))
        ),
        true
    ),
    updated_at = NOW()
WHERE p.tenant_id = 'paramascotasec'
  AND COALESCE(p.attributes->>'archived', 'false') <> 'true'
  AND COALESCE(p.attributes->>'variantGroupKey', '') LIKE 'single:%'
  AND COALESCE(NULLIF(p.attributes->>'variantBaseName', ''), '') <> '';

-- Product with operational stock but no variant metadata.
UPDATE "Product"
SET attributes = jsonb_set(
        jsonb_set(
            COALESCE(attributes, '{}'::jsonb),
            '{variantLabel}',
            to_jsonb('Unidad'::text),
            true
        ),
        '{variantGroupKey}',
        to_jsonb(pg_temp.pm_slug(COALESCE(NULLIF(attributes->>'supplier', ''), brand, 'paramascotas')) || '-' || pg_temp.pm_slug(COALESCE(NULLIF(product_type, ''), category, 'producto')) || '-' || pg_temp.pm_slug(COALESCE(NULLIF(attributes->>'species', ''), gender, 'mascota')) || '-' || pg_temp.pm_slug(COALESCE(NULLIF(attributes->>'variantBaseName', ''), name))),
        true
    ),
    updated_at = NOW()
WHERE tenant_id = 'paramascotasec'
  AND COALESCE(attributes->>'archived', 'false') <> 'true'
  AND COALESCE(NULLIF(attributes->>'variantLabel', ''), '') = ''
  AND COALESCE(attributes->>'sku', '') = 'SOLDIS-EXHIBIDOR-MIMMA';

-- Duplicate labels inside the same family should remain distinct in the storefront selector.
UPDATE "Product" p
SET attributes = jsonb_set(
        p.attributes,
        '{variantLabel}',
        to_jsonb(trim(COALESCE(NULLIF(p.attributes->>'size', ''), p.attributes->>'variantLabel') || ' ' || COALESCE(NULLIF(p.attributes->>'color', ''), ''))),
        true
    ),
    updated_at = NOW()
WHERE p.tenant_id = 'paramascotasec'
  AND COALESCE(p.attributes->>'archived', 'false') <> 'true'
  AND COALESCE(NULLIF(p.attributes->>'color', ''), '') <> ''
  AND EXISTS (
      SELECT 1
      FROM "Product" other
      WHERE other.tenant_id = p.tenant_id
        AND other.id <> p.id
        AND COALESCE(other.attributes->>'archived', 'false') <> 'true'
        AND other.attributes->>'variantGroupKey' = p.attributes->>'variantGroupKey'
        AND other.attributes->>'variantLabel' = p.attributes->>'variantLabel'
  );

CREATE UNIQUE INDEX IF NOT EXISTS "Product_tenant_sku_active_uidx"
ON "Product" (tenant_id, upper(trim(attributes->>'sku')))
WHERE COALESCE(NULLIF(trim(attributes->>'sku'), ''), '') <> ''
  AND COALESCE(attributes->>'archived', 'false') <> 'true';

CREATE UNIQUE INDEX IF NOT EXISTS "Product_tenant_variant_label_active_uidx"
ON "Product" (tenant_id, (attributes->>'variantGroupKey'), (attributes->>'variantLabel'))
WHERE COALESCE(NULLIF(attributes->>'variantGroupKey', ''), '') <> ''
  AND COALESCE(NULLIF(attributes->>'variantLabel', ''), '') <> ''
  AND COALESCE(attributes->>'archived', 'false') <> 'true';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'InventoryLot_product_id_fkey') THEN
        ALTER TABLE "InventoryLot"
            ADD CONSTRAINT "InventoryLot_product_id_fkey"
            FOREIGN KEY (product_id) REFERENCES "Product"(id)
            ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'InventoryLot_purchase_invoice_id_fkey') THEN
        ALTER TABLE "InventoryLot"
            ADD CONSTRAINT "InventoryLot_purchase_invoice_id_fkey"
            FOREIGN KEY (purchase_invoice_id) REFERENCES "PurchaseInvoice"(id)
            ON UPDATE CASCADE ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'InventoryLot_purchase_invoice_item_id_fkey') THEN
        ALTER TABLE "InventoryLot"
            ADD CONSTRAINT "InventoryLot_purchase_invoice_item_id_fkey"
            FOREIGN KEY (purchase_invoice_item_id) REFERENCES "PurchaseInvoiceItem"(id)
            ON UPDATE CASCADE ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'PurchaseInvoiceItem_purchase_invoice_id_fkey') THEN
        ALTER TABLE "PurchaseInvoiceItem"
            ADD CONSTRAINT "PurchaseInvoiceItem_purchase_invoice_id_fkey"
            FOREIGN KEY (purchase_invoice_id) REFERENCES "PurchaseInvoice"(id)
            ON UPDATE CASCADE ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'PurchaseInvoiceItem_product_id_fkey') THEN
        ALTER TABLE "PurchaseInvoiceItem"
            ADD CONSTRAINT "PurchaseInvoiceItem_product_id_fkey"
            FOREIGN KEY (product_id) REFERENCES "Product"(id)
            ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'InventoryLotAllocation_lot_id_fkey') THEN
        ALTER TABLE "InventoryLotAllocation"
            ADD CONSTRAINT "InventoryLotAllocation_lot_id_fkey"
            FOREIGN KEY (lot_id) REFERENCES "InventoryLot"(id)
            ON UPDATE CASCADE ON DELETE CASCADE;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'InventoryLotAllocation_product_id_fkey') THEN
        ALTER TABLE "InventoryLotAllocation"
            ADD CONSTRAINT "InventoryLotAllocation_product_id_fkey"
            FOREIGN KEY (product_id) REFERENCES "Product"(id)
            ON UPDATE CASCADE ON DELETE RESTRICT;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'InventoryLotAllocation_order_item_id_fkey') THEN
        ALTER TABLE "InventoryLotAllocation"
            ADD CONSTRAINT "InventoryLotAllocation_order_item_id_fkey"
            FOREIGN KEY (order_item_id) REFERENCES "OrderItem"(id)
            ON UPDATE CASCADE ON DELETE CASCADE;
    END IF;
END $$;

COMMIT;
