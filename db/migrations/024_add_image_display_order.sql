ALTER TABLE "Image" ADD COLUMN IF NOT EXISTS display_order integer;

WITH ordered_images AS (
    SELECT
        id,
        ROW_NUMBER() OVER (
            PARTITION BY product_id, COALESCE(kind, 'gallery')
            ORDER BY id
        ) - 1 AS next_display_order
    FROM "Image"
)
UPDATE "Image" image
SET display_order = ordered_images.next_display_order
FROM ordered_images
WHERE image.id = ordered_images.id
  AND image.display_order IS NULL;

ALTER TABLE "Image" ALTER COLUMN display_order SET DEFAULT 0;
ALTER TABLE "Image" ALTER COLUMN display_order SET NOT NULL;

CREATE INDEX IF NOT EXISTS "Image_product_kind_order_idx"
ON "Image" (product_id, kind, display_order, id);
