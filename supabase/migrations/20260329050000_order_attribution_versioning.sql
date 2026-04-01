-- No-op: retail.order_attributions was permanently dropped in
-- 20260323000000_rebuild_order_attributions.sql. Attribution is implicit —
-- every order is placed through an affiliate's mini site and
-- retail.orders.affiliate_professional_id is set at processing time from
-- the checkout session. The versioning changes planned here are not needed.
SELECT 1;
