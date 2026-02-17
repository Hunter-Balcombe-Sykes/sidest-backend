-- Add marketing_opt_in_cached as optional cache field with default to true
-- Source of truth is EmailSubscription.status, this is for UX/performance

ALTER TABLE "core"."customers" 
ADD COLUMN "marketing_opt_in_cached" boolean DEFAULT true;

COMMENT ON COLUMN "core"."customers"."marketing_opt_in_cached" IS 'Cache of EmailSubscription status for this customer (true=subscribed, false=unsubscribed). Defaults to true for new customers. Source of truth is EmailSubscription.status';

CREATE INDEX "customers_marketing_opt_in_cached_idx" ON "core"."customers" 
USING "btree" ("professional_id", "marketing_opt_in_cached") 
WHERE ("marketing_opt_in_cached" IS NOT NULL);
