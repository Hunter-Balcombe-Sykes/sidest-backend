BEGIN;

-- Remove `timezone` from all composite primary keys on analytics aggregate tables.
-- Having timezone in the PK caused orphan rows and double-counting when a
-- professional changes their timezone. Timezone is retained as a plain data column.
--
-- Each table first de-duplicates any rows that share the new (smaller) PK tuple
-- (keeping the most recently updated row), then drops the old PK and adds the new one.

-- ============================================================
-- analytics.brand_metrics_hourly
-- Old PK: (hour_start, brand_professional_id, currency_code, timezone)
-- New PK: (hour_start, brand_professional_id, currency_code)
-- ============================================================
DELETE FROM analytics.brand_metrics_hourly
WHERE ctid NOT IN (
    SELECT DISTINCT ON (hour_start, brand_professional_id, currency_code)
        ctid
    FROM analytics.brand_metrics_hourly
    ORDER BY hour_start, brand_professional_id, currency_code, updated_at DESC
);

ALTER TABLE analytics.brand_metrics_hourly
    DROP CONSTRAINT brand_metrics_hourly_pkey;

ALTER TABLE analytics.brand_metrics_hourly
    ADD PRIMARY KEY (hour_start, brand_professional_id, currency_code);

-- ============================================================
-- analytics.professional_metrics_hourly
-- Old PK: (hour_start, affiliate_professional_id, currency_code, timezone)
-- New PK: (hour_start, affiliate_professional_id, currency_code)
-- ============================================================
DELETE FROM analytics.professional_metrics_hourly
WHERE ctid NOT IN (
    SELECT DISTINCT ON (hour_start, affiliate_professional_id, currency_code)
        ctid
    FROM analytics.professional_metrics_hourly
    ORDER BY hour_start, affiliate_professional_id, currency_code, updated_at DESC
);

ALTER TABLE analytics.professional_metrics_hourly
    DROP CONSTRAINT professional_metrics_hourly_pkey;

ALTER TABLE analytics.professional_metrics_hourly
    ADD PRIMARY KEY (hour_start, affiliate_professional_id, currency_code);

-- ============================================================
-- analytics.site_metrics_hourly
-- Old PK: (hour_start, professional_id, site_id, timezone)
-- New PK: (hour_start, professional_id, site_id)
-- ============================================================
DELETE FROM analytics.site_metrics_hourly
WHERE ctid NOT IN (
    SELECT DISTINCT ON (hour_start, professional_id, site_id)
        ctid
    FROM analytics.site_metrics_hourly
    ORDER BY hour_start, professional_id, site_id, updated_at DESC
);

ALTER TABLE analytics.site_metrics_hourly
    DROP CONSTRAINT site_metrics_hourly_pkey;

ALTER TABLE analytics.site_metrics_hourly
    ADD PRIMARY KEY (hour_start, professional_id, site_id);

-- ============================================================
-- analytics.site_metrics_daily
-- Old PK: (day, professional_id, site_id, timezone)
-- New PK: (day, professional_id, site_id)
-- ============================================================
DELETE FROM analytics.site_metrics_daily
WHERE ctid NOT IN (
    SELECT DISTINCT ON (day, professional_id, site_id)
        ctid
    FROM analytics.site_metrics_daily
    ORDER BY day, professional_id, site_id, updated_at DESC
);

ALTER TABLE analytics.site_metrics_daily
    DROP CONSTRAINT site_metrics_daily_pkey;

ALTER TABLE analytics.site_metrics_daily
    ADD PRIMARY KEY (day, professional_id, site_id);

-- ============================================================
-- analytics.booking_metrics_hourly
-- Old PK: (hour_start, professional_id, currency_code, timezone)
-- New PK: (hour_start, professional_id, currency_code)
-- ============================================================
DELETE FROM analytics.booking_metrics_hourly
WHERE ctid NOT IN (
    SELECT DISTINCT ON (hour_start, professional_id, currency_code)
        ctid
    FROM analytics.booking_metrics_hourly
    ORDER BY hour_start, professional_id, currency_code, updated_at DESC
);

ALTER TABLE analytics.booking_metrics_hourly
    DROP CONSTRAINT booking_metrics_hourly_pkey;

ALTER TABLE analytics.booking_metrics_hourly
    ADD PRIMARY KEY (hour_start, professional_id, currency_code);

-- ============================================================
-- analytics.booking_metrics_daily
-- Old PK: (day, professional_id, currency_code, timezone)
-- New PK: (day, professional_id, currency_code)
-- ============================================================
DELETE FROM analytics.booking_metrics_daily
WHERE ctid NOT IN (
    SELECT DISTINCT ON (day, professional_id, currency_code)
        ctid
    FROM analytics.booking_metrics_daily
    ORDER BY day, professional_id, currency_code, updated_at DESC
);

ALTER TABLE analytics.booking_metrics_daily
    DROP CONSTRAINT booking_metrics_daily_pkey;

ALTER TABLE analytics.booking_metrics_daily
    ADD PRIMARY KEY (day, professional_id, currency_code);

COMMIT;
