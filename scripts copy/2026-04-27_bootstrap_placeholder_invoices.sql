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
    SELECT COALESCE(NULLIF(trim(both '-' from regexp_replace(lower(COALESCE(value, '')), '[^a-z0-9]+', '-', 'g')), ''), 'na')
$$;

CREATE TEMP TABLE tmp_uninvoiced_bootstrap_products AS
SELECT
    p.id AS product_id,
    p.tenant_id,
    p.name AS product_name,
    COALESCE(NULLIF(trim(p.attributes->>'supplier'), ''), 'Proveedor legacy') AS supplier_name,
    COALESCE(NULLIF(trim(src.payload->>'document'), ''), 'LEGACY-' || pg_temp.pm_normalize_key_part(COALESCE(NULLIF(trim(p.attributes->>'supplier'), ''), 'PROVEEDOR'))) AS supplier_document,
    'SIN-FACTURA-' || upper(pg_temp.pm_slug(COALESCE(NULLIF(trim(p.attributes->>'supplier'), ''), 'PROVEEDOR'))) || '-BOOTSTRAP' AS invoice_number,
    MIN(il.created_at)::date AS issued_at,
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
  AND p.quantity > 0
  AND il.source_type = 'bootstrap_opening'
  AND il.purchase_invoice_id IS NULL
GROUP BY p.id, p.tenant_id, p.name, p.attributes, src.payload;

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
    'pinv_bootstrap_' || substr(md5(tenant_id || '|' || external_key), 1, 16),
    tenant_id,
    supplier_name,
    supplier_document,
    invoice_number,
    external_key,
    issued_at,
    0,
    0,
    0,
    'Factura técnica generada para normalizar stock inicial sin número de factura registrado.',
    jsonb_build_object(
        'source', 'bootstrap_opening_without_invoice',
        'migration', '2026-04-27_bootstrap_placeholder_invoices',
        'placeholder_invoice_number', true
    ),
    NOW(),
    NOW()
FROM (
    SELECT
        tenant_id,
        supplier_name,
        supplier_document,
        invoice_number,
        MIN(issued_at) AS issued_at,
        pg_temp.pm_normalize_key_part(invoice_number) || '|' || pg_temp.pm_normalize_key_part(supplier_document) AS external_key
    FROM tmp_uninvoiced_bootstrap_products
    GROUP BY tenant_id, supplier_name, supplier_document, invoice_number
) inv
WHERE NOT EXISTS (
    SELECT 1
    FROM "PurchaseInvoice" pi
    WHERE pi.tenant_id = inv.tenant_id
      AND pi.external_key = inv.external_key
);

CREATE TEMP TABLE tmp_uninvoiced_bootstrap_items AS
SELECT
    ubp.*,
    pi.id AS purchase_invoice_id,
    'pitem_bootstrap_' || substr(md5(ubp.tenant_id || '|' || pi.id || '|' || ubp.product_id), 1, 16) AS item_id
FROM tmp_uninvoiced_bootstrap_products ubp
JOIN "PurchaseInvoice" pi
  ON pi.tenant_id = ubp.tenant_id
 AND pi.external_key = pg_temp.pm_normalize_key_part(ubp.invoice_number) || '|' || pg_temp.pm_normalize_key_part(ubp.supplier_document);

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
        'source', 'bootstrap_opening_without_invoice',
        'migration', '2026-04-27_bootstrap_placeholder_invoices',
        'placeholder_invoice_number', true,
        'tax_rate', ROUND(tax_rate, 2),
        'tax_exempt', tax_rate <= 0,
        'purchase_tax_rate', ROUND(tax_rate, 2),
        'purchase_tax_exempt', tax_rate <= 0
    ),
    NOW(),
    NOW()
FROM tmp_uninvoiced_bootstrap_items item
WHERE quantity > 0
  AND NOT EXISTS (
    SELECT 1
    FROM "PurchaseInvoiceItem" pii
    WHERE pii.tenant_id = item.tenant_id
      AND pii.purchase_invoice_id = item.purchase_invoice_id
      AND pii.product_id = item.product_id
);

UPDATE "InventoryLot" il
SET source_type = 'purchase_invoice',
    source_ref = item.invoice_number,
    purchase_invoice_id = item.purchase_invoice_id,
    purchase_invoice_item_id = item.item_id,
    metadata = COALESCE(il.metadata, '{}'::jsonb)
        || jsonb_build_object(
            'previous_source_type', il.source_type,
            'previous_source_ref', il.source_ref,
            'migration', '2026-04-27_bootstrap_placeholder_invoices',
            'placeholder_invoice_number', item.invoice_number
        ),
    updated_at = NOW()
FROM tmp_uninvoiced_bootstrap_items item
WHERE il.tenant_id = item.tenant_id
  AND il.product_id = item.product_id
  AND il.source_type = 'bootstrap_opening'
  AND il.purchase_invoice_id IS NULL;

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

COMMIT;
