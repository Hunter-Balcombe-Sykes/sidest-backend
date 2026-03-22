-- Shopify-canonical brand + professional analytics foundation.
-- Introduces deterministic checkout attribution, webhook inbox processing,
-- canonical order/ledger storage, payout audit runs, report persistence,
-- and physical daily aggregate tables.

BEGIN;

CREATE SCHEMA IF NOT EXISTS retail;
CREATE SCHEMA IF NOT EXISTS analytics;

-- ============================================================
-- 1) retail.checkout_sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.checkout_sessions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    token text NOT NULL,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES core.sites(id) ON DELETE CASCADE,
    status text NOT NULL DEFAULT 'active',
    expires_at timestamptz NOT NULL,
    converted_at timestamptz NULL,
    last_seen_at timestamptz NULL,
    context_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT checkout_sessions_status_check CHECK (status IN ('active', 'expired', 'converted', 'cancelled')),
    CONSTRAINT checkout_sessions_token_not_blank CHECK (btrim(token) <> ''),
    CONSTRAINT checkout_sessions_not_self_brand_check CHECK (affiliate_professional_id <> brand_professional_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS checkout_sessions_token_uq
    ON retail.checkout_sessions (token);

CREATE INDEX IF NOT EXISTS checkout_sessions_affiliate_status_idx
    ON retail.checkout_sessions (affiliate_professional_id, status);

CREATE INDEX IF NOT EXISTS checkout_sessions_brand_status_idx
    ON retail.checkout_sessions (brand_professional_id, status);

DROP TRIGGER IF EXISTS trg_checkout_sessions_set_updated_at ON retail.checkout_sessions;
CREATE TRIGGER trg_checkout_sessions_set_updated_at
BEFORE UPDATE ON retail.checkout_sessions
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 2) retail.order_event_inbox
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.order_event_inbox (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    source text NOT NULL,
    external_event_id text NOT NULL,
    event_type text NULL,
    shop_domain text NULL,
    integration_id uuid NULL REFERENCES core.professional_integrations(id) ON DELETE SET NULL,
    brand_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    headers jsonb NOT NULL DEFAULT '{}'::jsonb,
    status text NOT NULL DEFAULT 'pending',
    attempts integer NOT NULL DEFAULT 0,
    received_at timestamptz NOT NULL DEFAULT now(),
    processed_at timestamptz NULL,
    rejection_reason text NULL,
    last_error text NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT order_event_inbox_status_check CHECK (status IN ('pending', 'processing', 'processed', 'rejected', 'failed')),
    CONSTRAINT order_event_inbox_source_not_blank CHECK (btrim(source) <> ''),
    CONSTRAINT order_event_inbox_external_event_not_blank CHECK (btrim(external_event_id) <> ''),
    CONSTRAINT order_event_inbox_attempts_nonnegative CHECK (attempts >= 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS order_event_inbox_source_external_uq
    ON retail.order_event_inbox (source, external_event_id);

CREATE INDEX IF NOT EXISTS order_event_inbox_status_received_idx
    ON retail.order_event_inbox (status, received_at DESC);

CREATE INDEX IF NOT EXISTS order_event_inbox_shop_domain_status_idx
    ON retail.order_event_inbox (shop_domain, status);

CREATE INDEX IF NOT EXISTS order_event_inbox_brand_status_idx
    ON retail.order_event_inbox (brand_professional_id, status, received_at DESC);

DROP TRIGGER IF EXISTS trg_order_event_inbox_set_updated_at ON retail.order_event_inbox;
CREATE TRIGGER trg_order_event_inbox_set_updated_at
BEFORE UPDATE ON retail.order_event_inbox
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 3) retail.orders
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.orders (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    shopify_order_id text NOT NULL,
    order_name text NULL,
    source text NOT NULL DEFAULT 'shopify',
    shop_domain text NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    checkout_session_token text NOT NULL,
    lifecycle_status text NOT NULL,
    financial_status text NOT NULL,
    fulfillment_status text NOT NULL,
    currency_code char(3) NOT NULL DEFAULT 'AUD',
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    ordered_at timestamptz NOT NULL,
    paid_at timestamptz NULL,
    cancelled_at timestamptz NULL,
    closed_at timestamptz NULL,
    customer_email_hash text NULL,
    customer_region text NULL,
    shipping_country_code char(2) NULL,
    financials_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    raw_payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT orders_shopify_order_not_blank CHECK (btrim(shopify_order_id) <> ''),
    CONSTRAINT orders_checkout_session_token_not_blank CHECK (btrim(checkout_session_token) <> ''),
    CONSTRAINT orders_shop_domain_not_blank CHECK (btrim(shop_domain) <> ''),
    CONSTRAINT orders_lifecycle_status_check CHECK (lifecycle_status IN ('open', 'closed', 'cancelled')),
    CONSTRAINT orders_financial_status_check CHECK (financial_status IN ('pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'voided')),
    CONSTRAINT orders_fulfillment_status_check CHECK (fulfillment_status IN ('unfulfilled', 'partial', 'fulfilled', 'restocked')),
    CONSTRAINT orders_amounts_nonnegative CHECK (
        gross_cents >= 0
        AND refunded_cents >= 0
        AND returned_cents >= 0
        AND net_cents >= 0
    ),
    CONSTRAINT orders_not_self_brand_check CHECK (affiliate_professional_id <> brand_professional_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS orders_shopify_order_id_uq
    ON retail.orders (shopify_order_id);

CREATE INDEX IF NOT EXISTS orders_brand_ordered_idx
    ON retail.orders (brand_professional_id, ordered_at DESC);

CREATE INDEX IF NOT EXISTS orders_affiliate_ordered_idx
    ON retail.orders (affiliate_professional_id, ordered_at DESC);

CREATE INDEX IF NOT EXISTS orders_checkout_session_idx
    ON retail.orders (checkout_session_token);

CREATE INDEX IF NOT EXISTS orders_financial_status_idx
    ON retail.orders (financial_status, ordered_at DESC);

DROP TRIGGER IF EXISTS trg_orders_set_updated_at ON retail.orders;
CREATE TRIGGER trg_orders_set_updated_at
BEFORE UPDATE ON retail.orders
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 4) retail.order_items
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.order_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id uuid NOT NULL REFERENCES retail.orders(id) ON DELETE CASCADE,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_product_id uuid NULL REFERENCES retail.brand_products(id) ON DELETE SET NULL,
    shopify_line_item_id text NOT NULL,
    shopify_product_id text NULL,
    shopify_variant_id text NULL,
    title text NULL,
    variant_title text NULL,
    sku text NULL,
    quantity integer NOT NULL DEFAULT 1,
    gross_line_cents integer NOT NULL DEFAULT 0,
    discount_line_cents integer NOT NULL DEFAULT 0,
    refunded_line_cents integer NOT NULL DEFAULT 0,
    returned_line_cents integer NOT NULL DEFAULT 0,
    net_line_cents integer NOT NULL DEFAULT 0,
    currency_code char(3) NOT NULL DEFAULT 'AUD',
    product_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT order_items_shopify_line_item_not_blank CHECK (btrim(shopify_line_item_id) <> ''),
    CONSTRAINT order_items_quantity_positive CHECK (quantity > 0),
    CONSTRAINT order_items_amounts_nonnegative CHECK (
        gross_line_cents >= 0
        AND discount_line_cents >= 0
        AND refunded_line_cents >= 0
        AND returned_line_cents >= 0
        AND net_line_cents >= 0
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS order_items_order_line_item_uq
    ON retail.order_items (order_id, shopify_line_item_id);

CREATE INDEX IF NOT EXISTS order_items_brand_created_idx
    ON retail.order_items (brand_professional_id, created_at DESC);

CREATE INDEX IF NOT EXISTS order_items_brand_product_created_idx
    ON retail.order_items (brand_product_id, created_at DESC);

DROP TRIGGER IF EXISTS trg_order_items_set_updated_at ON retail.order_items;
CREATE TRIGGER trg_order_items_set_updated_at
BEFORE UPDATE ON retail.order_items
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE OR REPLACE FUNCTION retail.validate_order_item_brand()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    parent_brand_id uuid;
BEGIN
    SELECT o.brand_professional_id
      INTO parent_brand_id
      FROM retail.orders o
     WHERE o.id = NEW.order_id;

    IF parent_brand_id IS NULL THEN
        RAISE EXCEPTION 'Parent order does not exist for item.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF parent_brand_id <> NEW.brand_professional_id THEN
        RAISE EXCEPTION 'Order item brand_professional_id must equal parent order brand_professional_id.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_validate_order_item_brand ON retail.order_items;
CREATE TRIGGER trg_validate_order_item_brand
BEFORE INSERT OR UPDATE OF order_id, brand_professional_id
ON retail.order_items
FOR EACH ROW
EXECUTE FUNCTION retail.validate_order_item_brand();

-- ============================================================
-- 5) retail.order_attributions
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.order_attributions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id uuid NOT NULL REFERENCES retail.orders(id) ON DELETE CASCADE,
    model text NOT NULL,
    model_version text NOT NULL,
    reason text NOT NULL,
    lineage jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT order_attributions_order_unique UNIQUE (order_id),
    CONSTRAINT order_attributions_model_not_blank CHECK (btrim(model) <> ''),
    CONSTRAINT order_attributions_model_version_not_blank CHECK (btrim(model_version) <> ''),
    CONSTRAINT order_attributions_reason_not_blank CHECK (btrim(reason) <> '')
);

CREATE INDEX IF NOT EXISTS order_attributions_model_idx
    ON retail.order_attributions (model, model_version);

-- ============================================================
-- 6) retail.payout_runs
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.payout_runs (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    period_start date NOT NULL,
    period_end date NOT NULL,
    scheduled_for timestamptz NULL,
    executed_at timestamptz NULL,
    status text NOT NULL DEFAULT 'scheduled',
    total_cents integer NOT NULL DEFAULT 0,
    currency_code char(3) NOT NULL DEFAULT 'AUD',
    external_reference text NULL,
    notes text NULL,
    created_by_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT payout_runs_status_check CHECK (status IN ('scheduled', 'processing', 'executed', 'cancelled', 'failed')),
    CONSTRAINT payout_runs_period_check CHECK (period_end >= period_start),
    CONSTRAINT payout_runs_total_nonnegative CHECK (total_cents >= 0)
);

CREATE INDEX IF NOT EXISTS payout_runs_brand_period_idx
    ON retail.payout_runs (brand_professional_id, period_start, period_end);

CREATE INDEX IF NOT EXISTS payout_runs_status_idx
    ON retail.payout_runs (status, scheduled_for);

DROP TRIGGER IF EXISTS trg_payout_runs_set_updated_at ON retail.payout_runs;
CREATE TRIGGER trg_payout_runs_set_updated_at
BEFORE UPDATE ON retail.payout_runs
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 7) retail.commission_ledger_entries
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.commission_ledger_entries (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id uuid NULL REFERENCES retail.orders(id) ON DELETE SET NULL,
    order_item_id uuid NULL REFERENCES retail.order_items(id) ON DELETE SET NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    payout_run_id uuid NULL REFERENCES retail.payout_runs(id) ON DELETE SET NULL,
    entry_type text NOT NULL,
    status text NOT NULL DEFAULT 'pending',
    amount_cents integer NOT NULL,
    currency_code char(3) NOT NULL DEFAULT 'AUD',
    commission_rate numeric(7,4) NOT NULL,
    rate_source text NOT NULL,
    idempotency_key text NOT NULL,
    calculation_metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT commission_ledger_entry_type_check CHECK (entry_type IN ('accrual', 'reversal', 'payout')),
    CONSTRAINT commission_ledger_status_check CHECK (status IN ('pending', 'approved', 'paid', 'reversed', 'disputed')),
    CONSTRAINT commission_ledger_rate_range_check CHECK (commission_rate >= 0 AND commission_rate <= 100),
    CONSTRAINT commission_ledger_rate_source_not_blank CHECK (btrim(rate_source) <> ''),
    CONSTRAINT commission_ledger_idempotency_not_blank CHECK (btrim(idempotency_key) <> ''),
    CONSTRAINT commission_ledger_not_self_brand_check CHECK (affiliate_professional_id <> brand_professional_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS commission_ledger_entries_idempotency_uq
    ON retail.commission_ledger_entries (idempotency_key);

CREATE INDEX IF NOT EXISTS commission_ledger_entries_brand_status_idx
    ON retail.commission_ledger_entries (brand_professional_id, status, occurred_at DESC);

CREATE INDEX IF NOT EXISTS commission_ledger_entries_affiliate_status_idx
    ON retail.commission_ledger_entries (affiliate_professional_id, status, occurred_at DESC);

CREATE INDEX IF NOT EXISTS commission_ledger_entries_order_idx
    ON retail.commission_ledger_entries (order_id, order_item_id);

CREATE INDEX IF NOT EXISTS commission_ledger_entries_payout_run_idx
    ON retail.commission_ledger_entries (payout_run_id);

DROP TRIGGER IF EXISTS trg_commission_ledger_entries_set_updated_at ON retail.commission_ledger_entries;
CREATE TRIGGER trg_commission_ledger_entries_set_updated_at
BEFORE UPDATE ON retail.commission_ledger_entries
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 8) retail.report_exports and retail.report_schedules
-- ============================================================
CREATE TABLE IF NOT EXISTS retail.report_exports (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    scope text NOT NULL,
    brand_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    affiliate_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    requested_by_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    report_type text NOT NULL,
    format text NOT NULL DEFAULT 'csv',
    filters jsonb NOT NULL DEFAULT '{}'::jsonb,
    status text NOT NULL DEFAULT 'queued',
    file_path text NULL,
    file_size_bytes bigint NULL,
    error_message text NULL,
    expires_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz NULL,
    CONSTRAINT report_exports_scope_check CHECK (scope IN ('brand', 'professional')),
    CONSTRAINT report_exports_format_check CHECK (format IN ('csv', 'xlsx', 'json')),
    CONSTRAINT report_exports_status_check CHECK (status IN ('queued', 'processing', 'completed', 'failed', 'expired')),
    CONSTRAINT report_exports_report_type_not_blank CHECK (btrim(report_type) <> '')
);

CREATE INDEX IF NOT EXISTS report_exports_requested_by_created_idx
    ON retail.report_exports (requested_by_professional_id, created_at DESC);

CREATE INDEX IF NOT EXISTS report_exports_status_created_idx
    ON retail.report_exports (status, created_at DESC);

DROP TRIGGER IF EXISTS trg_report_exports_set_updated_at ON retail.report_exports;
CREATE TRIGGER trg_report_exports_set_updated_at
BEFORE UPDATE ON retail.report_exports
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

CREATE TABLE IF NOT EXISTS retail.report_schedules (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    scope text NOT NULL,
    brand_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    affiliate_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    created_by_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    report_type text NOT NULL,
    format text NOT NULL DEFAULT 'csv',
    cadence text NOT NULL,
    timezone text NOT NULL DEFAULT 'UTC',
    run_at_local_time time NOT NULL DEFAULT '06:00:00'::time,
    day_of_week smallint NULL,
    day_of_month smallint NULL,
    filters jsonb NOT NULL DEFAULT '{}'::jsonb,
    recipients jsonb NOT NULL DEFAULT '[]'::jsonb,
    status text NOT NULL DEFAULT 'active',
    last_run_at timestamptz NULL,
    next_run_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT report_schedules_scope_check CHECK (scope IN ('brand', 'professional')),
    CONSTRAINT report_schedules_format_check CHECK (format IN ('csv', 'xlsx', 'json')),
    CONSTRAINT report_schedules_cadence_check CHECK (cadence IN ('daily', 'weekly', 'monthly')),
    CONSTRAINT report_schedules_status_check CHECK (status IN ('active', 'paused', 'cancelled')),
    CONSTRAINT report_schedules_report_type_not_blank CHECK (btrim(report_type) <> ''),
    CONSTRAINT report_schedules_day_of_week_range CHECK (day_of_week IS NULL OR (day_of_week BETWEEN 1 AND 7)),
    CONSTRAINT report_schedules_day_of_month_range CHECK (day_of_month IS NULL OR (day_of_month BETWEEN 1 AND 31))
);

CREATE INDEX IF NOT EXISTS report_schedules_brand_status_idx
    ON retail.report_schedules (brand_professional_id, status);

CREATE INDEX IF NOT EXISTS report_schedules_affiliate_status_idx
    ON retail.report_schedules (affiliate_professional_id, status);

CREATE INDEX IF NOT EXISTS report_schedules_next_run_idx
    ON retail.report_schedules (status, next_run_at);

DROP TRIGGER IF EXISTS trg_report_schedules_set_updated_at ON retail.report_schedules;
CREATE TRIGGER trg_report_schedules_set_updated_at
BEFORE UPDATE ON retail.report_schedules
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ============================================================
-- 9) Analytics daily physical aggregate tables
-- ============================================================
CREATE TABLE IF NOT EXISTS analytics.brand_metrics_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS brand_metrics_daily_brand_day_idx
    ON analytics.brand_metrics_daily (brand_professional_id, day DESC);

CREATE TABLE IF NOT EXISTS analytics.brand_influencer_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    commission_accrued_cents integer NOT NULL DEFAULT 0,
    commission_reversed_cents integer NOT NULL DEFAULT 0,
    commission_net_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, affiliate_professional_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS brand_influencer_daily_brand_affiliate_day_idx
    ON analytics.brand_influencer_daily (brand_professional_id, affiliate_professional_id, day DESC);

CREATE TABLE IF NOT EXISTS analytics.brand_product_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_product_id uuid NOT NULL REFERENCES retail.brand_products(id) ON DELETE CASCADE,
    category text NULL,
    collection text NULL,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    units_sold integer NOT NULL DEFAULT 0,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    commission_net_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, brand_product_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS brand_product_daily_brand_day_idx
    ON analytics.brand_product_daily (brand_professional_id, day DESC);

CREATE INDEX IF NOT EXISTS brand_product_daily_product_day_idx
    ON analytics.brand_product_daily (brand_product_id, day DESC);

CREATE TABLE IF NOT EXISTS analytics.brand_influencer_product_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_product_id uuid NOT NULL REFERENCES retail.brand_products(id) ON DELETE CASCADE,
    category text NULL,
    collection text NULL,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    units_sold integer NOT NULL DEFAULT 0,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    commission_net_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, affiliate_professional_id, brand_product_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS brand_influencer_product_daily_brand_day_idx
    ON analytics.brand_influencer_product_daily (brand_professional_id, day DESC);

CREATE INDEX IF NOT EXISTS brand_influencer_product_daily_affiliate_day_idx
    ON analytics.brand_influencer_product_daily (affiliate_professional_id, day DESC);

CREATE TABLE IF NOT EXISTS analytics.brand_commission_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    payout_status text NOT NULL,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    accrual_cents integer NOT NULL DEFAULT 0,
    reversal_cents integer NOT NULL DEFAULT 0,
    payout_cents integer NOT NULL DEFAULT 0,
    net_outstanding_cents integer NOT NULL DEFAULT 0,
    entries_count integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT brand_commission_daily_payout_status_check CHECK (payout_status IN ('pending', 'approved', 'paid', 'reversed', 'disputed')),
    PRIMARY KEY (day, brand_professional_id, affiliate_professional_id, payout_status, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS brand_commission_daily_brand_day_idx
    ON analytics.brand_commission_daily (brand_professional_id, day DESC);

CREATE INDEX IF NOT EXISTS brand_commission_daily_affiliate_day_idx
    ON analytics.brand_commission_daily (affiliate_professional_id, day DESC);

CREATE TABLE IF NOT EXISTS analytics.brand_payout_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    payout_status text NOT NULL,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    payout_runs_count integer NOT NULL DEFAULT 0,
    total_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT brand_payout_daily_status_check CHECK (payout_status IN ('scheduled', 'processing', 'executed', 'cancelled', 'failed')),
    PRIMARY KEY (day, brand_professional_id, payout_status, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS brand_payout_daily_brand_day_idx
    ON analytics.brand_payout_daily (brand_professional_id, day DESC);

CREATE TABLE IF NOT EXISTS analytics.brand_region_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    region text NOT NULL,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, region, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS brand_region_daily_brand_day_idx
    ON analytics.brand_region_daily (brand_professional_id, day DESC);

CREATE TABLE IF NOT EXISTS analytics.brand_customer_daily (
    day date NOT NULL,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    customers_count integer NOT NULL DEFAULT 0,
    new_customers_count integer NOT NULL DEFAULT 0,
    returning_customers_count integer NOT NULL DEFAULT 0,
    orders_count integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS brand_customer_daily_brand_day_idx
    ON analytics.brand_customer_daily (brand_professional_id, day DESC);

CREATE TABLE IF NOT EXISTS analytics.professional_metrics_daily (
    day date NOT NULL,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    orders_count integer NOT NULL DEFAULT 0,
    gross_cents integer NOT NULL DEFAULT 0,
    refunded_cents integer NOT NULL DEFAULT 0,
    returned_cents integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    commission_accrued_cents integer NOT NULL DEFAULT 0,
    commission_reversed_cents integer NOT NULL DEFAULT 0,
    commission_paid_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, affiliate_professional_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS professional_metrics_daily_affiliate_day_idx
    ON analytics.professional_metrics_daily (affiliate_professional_id, day DESC);

CREATE TABLE IF NOT EXISTS analytics.professional_product_daily (
    day date NOT NULL,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    brand_product_id uuid NOT NULL REFERENCES retail.brand_products(id) ON DELETE CASCADE,
    category text NULL,
    collection text NULL,
    currency_code char(3) NOT NULL,
    timezone text NOT NULL,
    units_sold integer NOT NULL DEFAULT 0,
    orders_count integer NOT NULL DEFAULT 0,
    net_cents integer NOT NULL DEFAULT 0,
    commission_net_cents integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, affiliate_professional_id, brand_professional_id, brand_product_id, currency_code, timezone)
);

CREATE INDEX IF NOT EXISTS professional_product_daily_affiliate_day_idx
    ON analytics.professional_product_daily (affiliate_professional_id, day DESC);

CREATE INDEX IF NOT EXISTS professional_product_daily_brand_day_idx
    ON analytics.professional_product_daily (brand_professional_id, day DESC);

-- ============================================================
-- 10) Shopify provider lookups in core.professional_integrations
-- ============================================================
CREATE UNIQUE INDEX IF NOT EXISTS professional_integrations_shopify_shop_domain_uq
    ON core.professional_integrations (provider, lower((provider_metadata->>'shop_domain')))
    WHERE
        provider = 'shopify'
        AND NULLIF(btrim(provider_metadata->>'shop_domain'), '') IS NOT NULL;

-- ============================================================
-- 11) Grants for runtime role
-- ============================================================
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA retail TO app_backend';
        EXECUTE 'GRANT USAGE ON SCHEMA analytics TO app_backend';

        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.checkout_sessions TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.order_event_inbox TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.orders TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.order_items TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.order_attributions TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.commission_ledger_entries TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.payout_runs TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.report_exports TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE retail.report_schedules TO app_backend';

        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.brand_metrics_daily TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.brand_influencer_daily TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.brand_product_daily TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.brand_influencer_product_daily TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.brand_commission_daily TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.brand_payout_daily TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.brand_region_daily TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.brand_customer_daily TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.professional_metrics_daily TO app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE analytics.professional_product_daily TO app_backend';
    END IF;
END $$;

COMMIT;
