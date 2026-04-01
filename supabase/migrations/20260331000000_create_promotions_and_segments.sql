BEGIN;

-- =============================================================================
-- Promotions & Affiliate Segments Engine
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. retail.brand_affiliate_segments
--    Dynamic, criteria-based affiliate groupings owned by a brand.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS retail.brand_affiliate_segments (
    id                       UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id    UUID        NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    name                     TEXT        NOT NULL,
    description              TEXT,
    criteria                 TEXT        NOT NULL,
    size                     INTEGER     NOT NULL DEFAULT 10,
    lookback_days            INTEGER,
    professional_type_filter TEXT,
    members_refreshed_at     TIMESTAMPTZ,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (brand_professional_id, name),

    CONSTRAINT brand_affiliate_segments_criteria_check CHECK (
        criteria IN (
            'highest_revenue', 'lowest_revenue',
            'most_orders', 'fewest_orders',
            'highest_commission', 'lowest_commission',
            'newest', 'professional_type'
        )
    ),

    CONSTRAINT brand_affiliate_segments_size_check CHECK (size >= 0),

    CONSTRAINT brand_affiliate_segments_type_filter_check CHECK (
        criteria <> 'professional_type' OR professional_type_filter IS NOT NULL
    )
);

COMMENT ON TABLE retail.brand_affiliate_segments
    IS 'Dynamic affiliate groupings defined by criteria. Membership is auto-computed from analytics data.';

COMMENT ON COLUMN retail.brand_affiliate_segments.criteria
    IS 'Ranking/filter criteria: highest_revenue, lowest_revenue, most_orders, fewest_orders, highest_commission, lowest_commission, newest, professional_type';

COMMENT ON COLUMN retail.brand_affiliate_segments.size
    IS 'Top N affiliates to include. 0 means empty segment.';

COMMENT ON COLUMN retail.brand_affiliate_segments.lookback_days
    IS 'NULL = all-time. Otherwise number of days to look back for analytics criteria.';

COMMENT ON COLUMN retail.brand_affiliate_segments.professional_type_filter
    IS 'Required when criteria = professional_type. E.g. barber, salon, ambassador.';

CREATE INDEX IF NOT EXISTS brand_affiliate_segments_brand_idx
    ON retail.brand_affiliate_segments (brand_professional_id);

CREATE OR REPLACE FUNCTION retail.set_brand_affiliate_segments_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;

CREATE TRIGGER brand_affiliate_segments_updated_at
    BEFORE UPDATE ON retail.brand_affiliate_segments
    FOR EACH ROW EXECUTE FUNCTION retail.set_brand_affiliate_segments_updated_at();

-- ---------------------------------------------------------------------------
-- 2. retail.brand_affiliate_segment_members
--    Cache table — auto-populated by SegmentEvaluationService.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS retail.brand_affiliate_segment_members (
    id                        UUID    PRIMARY KEY DEFAULT gen_random_uuid(),
    segment_id                UUID    NOT NULL REFERENCES retail.brand_affiliate_segments(id) ON DELETE CASCADE,
    affiliate_professional_id UUID    NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    rank                      INTEGER NOT NULL DEFAULT 0,
    metric_value              BIGINT  NOT NULL DEFAULT 0,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (segment_id, affiliate_professional_id)
);

COMMENT ON TABLE retail.brand_affiliate_segment_members
    IS 'Cached segment membership — populated by SegmentEvaluationService, not manually managed.';

COMMENT ON COLUMN retail.brand_affiliate_segment_members.rank
    IS 'Position in the ranked list (1 = top). 0 for unranked criteria like professional_type.';

COMMENT ON COLUMN retail.brand_affiliate_segment_members.metric_value
    IS 'The metric value used for ranking (revenue cents, order count, commission cents, or epoch seconds for newest).';

CREATE INDEX IF NOT EXISTS brand_affiliate_segment_members_affiliate_idx
    ON retail.brand_affiliate_segment_members (affiliate_professional_id);

-- ---------------------------------------------------------------------------
-- 3. retail.brand_promotions
--    Time-bounded promotion with affiliate + product targeting.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS retail.brand_promotions (
    id                       UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    brand_professional_id    UUID         NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    name                     TEXT         NOT NULL,
    description              TEXT,
    starts_at                TIMESTAMPTZ  NOT NULL,
    ends_at                  TIMESTAMPTZ  NOT NULL,
    commission_rate          NUMERIC(5,2),
    discount_rate            NUMERIC(5,2),
    affiliate_scope          TEXT         NOT NULL DEFAULT 'all',
    affiliate_segment_ids    UUID[]       NOT NULL DEFAULT '{}',
    affiliate_ids            UUID[]       NOT NULL DEFAULT '{}',
    product_scope            TEXT         NOT NULL DEFAULT 'all',
    product_ids              UUID[]       NOT NULL DEFAULT '{}',
    priority                 INTEGER      NOT NULL DEFAULT 0,
    is_active                BOOLEAN      NOT NULL DEFAULT TRUE,
    notification_sent_at     TIMESTAMPTZ,
    end_notification_sent_at TIMESTAMPTZ,
    created_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT brand_promotions_ends_after_starts CHECK (ends_at > starts_at),

    CONSTRAINT brand_promotions_has_rate CHECK (
        commission_rate IS NOT NULL OR discount_rate IS NOT NULL
    ),

    CONSTRAINT brand_promotions_priority_range CHECK (
        priority BETWEEN 0 AND 100
    ),

    CONSTRAINT brand_promotions_affiliate_scope_check CHECK (
        affiliate_scope IN ('all', 'segments', 'affiliates')
    ),

    CONSTRAINT brand_promotions_product_scope_check CHECK (
        product_scope IN ('all', 'products')
    ),

    CONSTRAINT brand_promotions_commission_rate_range CHECK (
        commission_rate IS NULL OR (commission_rate >= 0 AND commission_rate <= 100)
    ),

    CONSTRAINT brand_promotions_discount_rate_range CHECK (
        discount_rate IS NULL OR (discount_rate >= 0 AND discount_rate <= 100)
    )
);

COMMENT ON TABLE retail.brand_promotions
    IS 'Time-bounded commission/discount promotions with affiliate and product targeting.';

COMMENT ON COLUMN retail.brand_promotions.affiliate_scope
    IS 'all = all affiliates; segments = specific segment IDs; affiliates = specific affiliate IDs';

COMMENT ON COLUMN retail.brand_promotions.product_scope
    IS 'all = all products; products = specific product IDs';

COMMENT ON COLUMN retail.brand_promotions.priority
    IS 'Higher number wins when multiple promotions overlap. Range 0-100.';

-- Primary lookup index: active promotions for a brand within a time window
CREATE INDEX IF NOT EXISTS brand_promotions_brand_active_dates_idx
    ON retail.brand_promotions (brand_professional_id, is_active, starts_at, ends_at);

-- Admin listing index
CREATE INDEX IF NOT EXISTS brand_promotions_brand_idx
    ON retail.brand_promotions (brand_professional_id);

-- Scheduler scan indexes for due unsent promotion notifications.
CREATE INDEX IF NOT EXISTS brand_promotions_start_notify_due_idx
    ON retail.brand_promotions (starts_at, id)
    WHERE is_active = TRUE AND notification_sent_at IS NULL;

CREATE INDEX IF NOT EXISTS brand_promotions_end_notify_due_idx
    ON retail.brand_promotions (ends_at, id)
    WHERE is_active = TRUE AND end_notification_sent_at IS NULL;

-- GIN indexes for array containment queries
CREATE INDEX IF NOT EXISTS brand_promotions_affiliate_ids_gin
    ON retail.brand_promotions USING GIN (affiliate_ids);

CREATE INDEX IF NOT EXISTS brand_promotions_product_ids_gin
    ON retail.brand_promotions USING GIN (product_ids);

CREATE INDEX IF NOT EXISTS brand_promotions_affiliate_segment_ids_gin
    ON retail.brand_promotions USING GIN (affiliate_segment_ids);

CREATE OR REPLACE FUNCTION retail.set_brand_promotions_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;

CREATE TRIGGER brand_promotions_updated_at
    BEFORE UPDATE ON retail.brand_promotions
    FOR EACH ROW EXECUTE FUNCTION retail.set_brand_promotions_updated_at();

-- ---------------------------------------------------------------------------
-- 4. Additional supporting indexes
-- ---------------------------------------------------------------------------

-- Covering index for "newest" segment queries on brand_partner_links:
-- ORDER BY created_at DESC becomes an index-only scan with this index.
CREATE INDEX IF NOT EXISTS brand_partner_links_brand_created_idx
    ON core.brand_partner_links (brand_professional_id, created_at DESC);

-- Functional partial index for promotion analytics on commission_ledger_entries:
-- Supports WHERE calculation_metadata->>'promotion_id' = :id without full table scan.
CREATE INDEX IF NOT EXISTS idx_cle_promotion_id
    ON retail.commission_ledger_entries ((calculation_metadata->>'promotion_id'))
    WHERE calculation_metadata->>'promotion_id' IS NOT NULL;

COMMIT;
