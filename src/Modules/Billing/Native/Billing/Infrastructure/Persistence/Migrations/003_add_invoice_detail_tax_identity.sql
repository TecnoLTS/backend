-- Preserve the exact fiscal identity used for every invoice line. A numeric
-- rate alone cannot distinguish SRI zero-rated (code 0) from exempt (code 7).
ALTER TABLE public.invoice_details
    ADD COLUMN tax_code varchar(10),
    ADD COLUMN tax_percentage_code varchar(10),
    ADD COLUMN tax_treatment varchar(20);

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.invoice_details
        WHERE tax_rate NOT IN (0, 5, 12, 13, 14, 15)
    ) THEN
        RAISE EXCEPTION
            'invoice_details contains IVA rates outside the supported SRI catalogue; reconcile them before V003';
    END IF;
END
$$;

WITH request_tax AS (
    SELECT
        detail.id,
        NULLIF(BTRIM(item.value ->> 'tax_code'), '') AS requested_tax_code,
        NULLIF(BTRIM(item.value ->> 'tax_percentage_code'), '') AS requested_percentage_code,
        NULLIF(LOWER(BTRIM(REPLACE(item.value ->> 'tax_treatment', '_', '-'))), '') AS requested_treatment,
        LOWER(COALESCE(item.value ->> 'tax_exempt', 'false')) IN ('1', 'true', 't', 'yes', 'on') AS requested_exempt
    FROM public.invoice_details detail
    JOIN public.invoice_headers header
      ON header.id = detail.invoice_header_id
    LEFT JOIN LATERAL jsonb_array_elements(
        CASE
            WHEN jsonb_typeof(header.raw_request -> 'items') = 'array'
                THEN header.raw_request -> 'items'
            ELSE '[]'::jsonb
        END
    ) WITH ORDINALITY AS item(value, line_number)
      ON item.line_number = detail.line_number
)
UPDATE public.invoice_details detail
SET
    tax_code = COALESCE(request_tax.requested_tax_code, '2'),
    tax_percentage_code = COALESCE(
        request_tax.requested_percentage_code,
        CASE detail.tax_rate
            WHEN 0 THEN CASE
                WHEN request_tax.requested_treatment = 'exempt' OR request_tax.requested_exempt THEN '7'
                ELSE '0'
            END
            WHEN 5 THEN '5'
            WHEN 12 THEN '2'
            WHEN 13 THEN '10'
            WHEN 14 THEN '3'
            WHEN 15 THEN '4'
        END
    ),
    tax_treatment = CASE
        WHEN request_tax.requested_treatment IN ('taxed', 'zero-rated', 'exempt')
            THEN request_tax.requested_treatment
        WHEN request_tax.requested_exempt OR request_tax.requested_percentage_code = '7'
            THEN 'exempt'
        WHEN detail.tax_rate = 0
            THEN 'zero-rated'
        ELSE 'taxed'
    END
FROM request_tax
WHERE request_tax.id = detail.id;

ALTER TABLE public.invoice_details
    ALTER COLUMN tax_code SET DEFAULT '2',
    ALTER COLUMN tax_code SET NOT NULL,
    ALTER COLUMN tax_percentage_code SET NOT NULL,
    ALTER COLUMN tax_treatment SET NOT NULL,
    ADD CONSTRAINT invoice_details_tax_code_iva_check
        CHECK (tax_code = '2'),
    ADD CONSTRAINT invoice_details_tax_treatment_check
        CHECK (tax_treatment IN ('taxed', 'zero-rated', 'exempt')),
    ADD CONSTRAINT invoice_details_sri_vat_identity_check
        CHECK (
            (tax_rate = 0 AND tax_treatment = 'zero-rated' AND tax_percentage_code = '0')
            OR (tax_rate = 0 AND tax_treatment = 'exempt' AND tax_percentage_code = '7')
            OR (tax_rate = 5 AND tax_treatment = 'taxed' AND tax_percentage_code = '5')
            OR (tax_rate = 12 AND tax_treatment = 'taxed' AND tax_percentage_code = '2')
            OR (tax_rate = 13 AND tax_treatment = 'taxed' AND tax_percentage_code = '10')
            OR (tax_rate = 14 AND tax_treatment = 'taxed' AND tax_percentage_code = '3')
            OR (tax_rate = 15 AND tax_treatment = 'taxed' AND tax_percentage_code = '4')
        );

COMMENT ON COLUMN public.invoice_details.tax_code IS
    'SRI tax code; Billing currently persists Ecuador IVA code 2.';
COMMENT ON COLUMN public.invoice_details.tax_percentage_code IS
    'Exact SRI codigoPorcentaje used in the emitted invoice XML.';
COMMENT ON COLUMN public.invoice_details.tax_treatment IS
    'Canonical semantic treatment: taxed, zero-rated or exempt.';
