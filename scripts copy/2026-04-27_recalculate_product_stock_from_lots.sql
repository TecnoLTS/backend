BEGIN;

WITH lot_stock AS (
  SELECT
    product_id,
    COALESCE(SUM(remaining_quantity), 0)::integer AS quantity
  FROM "InventoryLot"
  GROUP BY product_id
)
UPDATE "Product" p
SET quantity = COALESCE(ls.quantity, 0)
FROM lot_stock ls
WHERE p.id = ls.product_id
  AND COALESCE(p.quantity, 0) <> COALESCE(ls.quantity, 0);

UPDATE "Product" p
SET quantity = 0
WHERE NOT EXISTS (
    SELECT 1
    FROM "InventoryLot" l
    WHERE l.product_id = p.id
  )
  AND COALESCE(p.quantity, 0) <> 0;

COMMIT;
