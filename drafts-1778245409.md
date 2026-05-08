- [ ] **#SCHEMA-1** · P2 — Duplicate indexes on analytics.site_visits waste storage and write bandwidth
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (two identical index definitions)
    - **Affects:** Write throughput on the site_visits table; every INSERT pays double index maintenance.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop one of the two identical `(professional_id, occurred_at)` indexes.
        - Verify that no query plans rely on the dropped name (they are identical, so both are usable; dropping one just removes a redundant copy).
    - **Technical:** Two indexes with the same columns and ordering—`analytics_site_visits_professional_occurred_idx` and `site_visits_professional_time_idx`—were created in the v2 baseline. Postgres maintains both on every write, doubling the B‑tree overhead. The planner can use either; dropping one saves storage (~size of one index) and improves INSERT/UPDATE performance.
    - **Plain English:** Imagine keeping two identical copies of the same paper folder in a filing cabinet. Every time you add a new page, you have to file it in both folders. The information is the same — one folder is just wasted space and effort. Removing the duplicate keeps things cleaner and faster.
    - **Evidence:**
        ```sql
        CREATE INDEX analytics_site_visits_professional_occurred_idx ON analytics.site_visits (professional_id, occurred_at);
        CREATE INDEX site_visits_professional_time_idx ON analytics.site_visits (professional_id, occurred_at);
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#SCHEMA-2** · P2 — Duplicate indexes on analytics.link_clicks waste storage and write bandwidth
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (two identical index definitions)
    - **Affects:** Write throughput on the link_clicks table; every click INSERT pays double index maintenance.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop one of the two identical `(professional_id, occurred_at)` indexes.
        - Verify that no query plans rely on the dropped name.
    - **Technical:** Two indexes—`analytics_link_clicks_professional_occurred_idx` and `link_clicks_professional_time_idx`—have identical columns. Both are maintained on every write with no functional difference. Removing one recovers index storage and reduces write overhead.
    - **Plain English:** Same story as the site_visits copy — a second copy of the same folder that only adds work and takes up room.
    - **Evidence:**
        ```sql
        CREATE INDEX analytics_link_clicks_professional_occurred_idx ON analytics.link_clicks (professional_id, occurred_at);
        CREATE INDEX link_clicks_professional_time_idx ON analytics.link_clicks (professional_id, occurred_at);
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#SCHEMA-3** · P2 — analytics.booking_events table has no Eloquent model and appears unused
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (table definition), app/Models/ (no matching model)
    - **Affects:** Database storage and maintenance; RLS policies exist on this table, adding overhead for every row.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm that no raw SQL or external integration writes to booking_events.
        - If truly unused, drop the table and its RLS policies.
    - **Technical:** The analytics.booking_events table was defined in the baseline but has no corresponding Eloquent model (there’s no `BookingEvent.php` in the `Models` directory). The architecture relies on Eloquent for all logical data access, so a model-less table is effectively dead code in the schema, consuming storage and making maintenance heavier.
    - **Plain English:** There’s a spare room in the office that nobody uses, but it still needs to be cleaned, kept warm, and checked for leaks. If it’s never used, it makes sense to take it off the floor plan.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS analytics.booking_events (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            site_id uuid NOT NULL REFERENCES site.sites(id) ON DELETE CASCADE,
            brand_professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
            -- ...
        );
        ```
        (No file at `app/Models/Analytics/BookingEvent.php`.)
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SCHEMA-4** · P2 — analytics.professional_customer_daily appears unused (no Eloquent model)
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (table definition), app/Models/ (no matching model)
    - **Affects:** Storage and maintenance; the table was not dropped with other legacy aggregates in Phase 4.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm no application code reads from this table.
        - Drop the table if unused.
    - **Technical:** While other daily/hourly aggregate tables were removed in migration 20260506500000, `analytics.professional_customer_daily` was left behind. No Eloquent model exists for it, and the new analytics architecture uses raw event tables with live queries. Without a model, this table is orphaned schema bloat.
    - **Plain English:** After a big cleanup, one-old report board was left hanging on the wall. Nobody uses it to make decisions today, so it’s safe to take it down.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS analytics.professional_customer_daily (
            day date NOT NULL,
            affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            timezone text NOT NULL,
            customers_count integer NOT NULL DEFAULT 0,
            new_customers_count integer NOT NULL DEFAULT 0,
            returning_customers_count integer NOT NULL DEFAULT 0,
            updated_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (day, affiliate_professional_id, timezone)
        );
        ```
        (No corresponding model file.)
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SCHEMA-5** · P2 — Legacy icon/headshot columns on core.professionals likely dead
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (column definitions), app/Models/Core/Professional/Professional.php (missing from fillable/casts)
    - **Affects:** Schema maintenance and storage; these columns are never mass-assigned and have no Eloquent handling.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Search codebase for any raw SQL using `icon_bucket`, `icon_path`, `headshot_bucket`, `headshot_path`.
        - If no references exist, drop the columns (cascading constraints `professionals_headshot_bucket_when_path` and `professionals_icon_bucket_when_path`).
    - **Technical:** The v2 baseline still carries `icon_bucket`, `icon_path`, `headshot_bucket`, `headshot_path` on `core.professionals`, but the current product uses `site.site_media` (with design pool) for all brand/professional imagery. The `Professional` model’s `$fillable` does not include these columns, and no `$casts` or accessors reference them, indicating they are no longer touched by Eloquent. They are dead weight.
    - **Plain English:** Old profile photo slots from an earlier version of the app are still in the database, but the team now puts all photos in the newer “media” system. Those old slots just take up space and create a small risk of confusion.
    - **Evidence:**
        - Schema:
          ```sql
          icon_bucket text DEFAULT 'public-assets',
          icon_path text,
          headshot_bucket text DEFAULT 'public-assets',
          headshot_path text,
          CONSTRAINT professionals_headshot_bucket_when_path CHECK ((headshot_path IS NULL) OR (headshot_bucket IS NOT NULL)),
          CONSTRAINT professionals_icon_bucket_when_path CHECK ((icon_path IS NULL) OR (icon_bucket IS NOT NULL))
          ```
        - Model: `$fillable` contains no `icon_*` or `headshot_*` entries.
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SCHEMA-6** · P2 — Legacy banner_bucket/banner_path on site.sites likely unused
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (column definitions), app/Models/Core/Site/Site.php (missing from fillable)
    - **Affects:** Schema maintenance and storage; design-managed assets now live in site_media.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify no raw queries reference `banner_bucket` or `banner_path`.
        - Drop the columns and the `sites_banner_bucket_when_path` constraint.
    - **Technical:** `site.sites` retains `banner_bucket` and `banner_path` from an earlier design system. Today, brand imagery is stored in `site.site_media` (pool=design) and the site’s `settings` JSON. The `Site` model does not fill or cast these columns, marking them as dead schema artifacts.
    - **Plain English:** A couple of old picture slots on the site were replaced by the new media system, but the database still keeps the empty frames. They can be removed safely.
    - **Evidence:**
        - Schema:
          ```sql
          banner_bucket text DEFAULT 'public-assets',
          banner_path text,
          CONSTRAINT sites_banner_bucket_when_path CHECK ((banner_path IS NULL) OR (banner_bucket IS NOT NULL))
          ```
        - Model: `$fillable` = `[subdomain, theme_id, is_published, settings]` — no banner fields.
    - `[DRAFT, confidence: 0.8]`
