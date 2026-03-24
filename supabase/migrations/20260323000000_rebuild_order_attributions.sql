-- Drop retail.order_attributions.
--
-- Attribution is implicit: every order is placed through an affiliate's mini site
-- and retail.orders.affiliate_professional_id is set at processing time from the
-- checkout session. A separate attribution table adds no information.

BEGIN;

DROP TABLE IF EXISTS retail.order_attributions;

COMMIT;
