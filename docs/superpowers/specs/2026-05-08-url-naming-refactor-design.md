# URL & Naming Refactor — Design Spec

**Date:** 2026-05-08
**Status:** Draft (awaiting review)
**Owner:** Josh

## Problem

The frontend currently composes user-facing URLs from multiple name-shaped fields (`display_name`, `handle`, `handle_lc`, and `site.sites.subdomain`). This produces three problems:

1. **Confusion at the API boundary.** Frontend code conflates `display_name`, `handle`, and `subdomain` and isn't sure which to render where.
2. **URL composition logic in the wrong place.** The frontend stitches `subdomain + ".partna.au"` and per-brand URLs `brand.subdomain + "/" + affiliate.handle` itself. Any base-domain change or routing rule update means a frontend change.
3. **Persona ambiguity.** Brands and affiliates share the same `core.professionals` schema with the same field names, so the frontend has to switch on `professional_type` to decide whether `display_name` means "brand name" or "username".

Pre-beta scale (no live customers) is the right window to lock in a cleaner contract before integrations multiply.

## Goal

The backend stores ready-to-render URLs and exposes persona-correct naming. The frontend renders strings; it never composes them. This includes QR code URLs — the existing `/p/{qr_slug}` redirect indirection is removed because the new alias machinery makes it redundant for partna.au URLs.

## Non-goals

- Changing the brand-affiliate connection model (slot 0–3 stays).
- Replacing the existing `site.site_subdomain_aliases` redirect mechanism (extend, don't replace).
- TTL/cleanup on aliases beyond the existing 30-day soft-delete cascade.
- Frontend code changes (separate PR after backend ships).
- A handle-rename endpoint for affiliates (`UpdateProfessionalRequest.php:19` keeps its `// keep handle out of this endpoint` comment as-is — follow-up work).
- Custom domain rename protection. If a brand changes their `custom_domain`, printed materials encoding the old custom domain become stale. Acceptable for pre-beta — most brands will not have custom domains, and those that do can re-print on rename. A future `brand.custom_domain_aliases` table can be added if/when this becomes a real problem.

## Decisions

| Decision | Choice |
|---|---|
| URL slug strategy | Auto-derive from `display_name`, never expose to frontend |
| Rename behavior | Renaming the user-facing name regenerates URLs; old URLs return 301 redirects via alias tables |
| API field projection | DB column stays `display_name`; API resources project to `brand_name` (brands) or `username` (affiliates) |
| Per-brand URL location | New column `brand.brand_partner_links.brand_affiliate_url` |
| Own URL location | New column `core.professionals.user_url` (serves both brand and affiliate "own URL") |
| Sync strategy | Postgres triggers (matches existing `commerce.order_items` / `commerce.brand_affiliate_rollup` mirror pattern) |
| Custom domain | When `brand_store_settings.custom_domain_verified_at IS NOT NULL`, the custom domain is used in `user_url` and cascades to `brand_affiliate_url` |
| Alias cleanup | Existing `ON DELETE CASCADE` handles cleanup when a soft-deleted professional is hard-deleted after 30-day retention |
| `name` unified key | NOT added — only persona-specific `brand_name` / `username` |
| `storefront_base_url` | Removed from `BrandStoreSettingsResource` (clean cut, pre-beta) |
| Aliases schema location | `site` schema (alongside `site.site_subdomain_aliases`) |
| QR code URLs | Drop the `/p/{qr_slug}` indirection. QR images encode `user_url` directly. The new subdomain alias machinery handles printed-QR rename survival on partna.au URLs. |

## Data model

### New columns

```sql
ALTER TABLE core.professionals
    ADD COLUMN user_url text NULL;

ALTER TABLE brand.brand_partner_links
    ADD COLUMN brand_affiliate_url text NULL;
```

`user_url` lives on `professionals` (not `brand_profiles`) because affiliates also have an own URL (`josh.partna.au`) — same shape, same column. Naming caveat: for affiliates this is "the user's URL" generally; the column name is shared on purpose to keep one source of truth.

### New table — `site.professional_handle_aliases`

Mirrors `site.site_subdomain_aliases` exactly. Required because the per-brand affiliate URL `evo.partna.au/josh` uses the affiliate's handle as a path segment — without a handle alias table, affiliate renames would 404 on bookmarked per-brand links.

```sql
CREATE TABLE site.professional_handle_aliases (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    handle varchar(63) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX professional_handle_aliases_handle_lc_uq
    ON site.professional_handle_aliases (LOWER(handle));
CREATE INDEX professional_handle_aliases_professional_idx
    ON site.professional_handle_aliases (professional_id);
```

The `LOWER(handle)` unique index coordinates with `core.professionals.handle_lc` — a handle is in exactly one of the two states: live or aliased.

### Lookup indexes

```sql
CREATE INDEX professionals_user_url_idx
    ON core.professionals (user_url) WHERE user_url IS NOT NULL;

CREATE INDEX brand_partner_links_brand_aff_url_idx
    ON brand.brand_partner_links (brand_affiliate_url);
```

### Removed columns

```sql
-- core.professionals.qr_slug — no longer needed; QR URLs come from user_url
ALTER TABLE core.professionals DROP COLUMN qr_slug;
```

Note: `notifications.email_subscriptions.qr_slug` is a separately-purposed unique tracking token per subscription row, NOT the same concept as `professionals.qr_slug` despite the column name. It stays untouched. Implementation should verify this distinction by inspecting `EmailSubscription.php` and call sites before running the drop.

### Unchanged

- `core.professionals.handle` / `handle_lc` — still required, still unique, still authoritative slug source
- `core.professionals.display_name` — column unchanged; only its API projection changes
- `site.sites.subdomain` and `site.site_subdomain_aliases` — untouched
- `brand.brand_store_settings.custom_domain*` — untouched
- `brand.brand_partner_links.slot` and existing constraints — untouched
- `notifications.email_subscriptions.qr_slug` — untouched (different purpose)

### Write authority

`core.professionals.user_url` and `brand.brand_partner_links.brand_affiliate_url` are **trigger-managed**. App code MUST NOT write to them directly. Eloquent models should:
- Mark these columns as guarded (or omit from `$fillable`).
- Treat them as read-only from PHP. If a developer needs to "force a recompute" (e.g., after manual SQL), update the source field (`handle` or `subdomain`) instead — the trigger will pick it up.

A future safety improvement (out of scope here): add a Postgres BEFORE INSERT/UPDATE trigger that raises if a non-trigger context tries to write these columns. Skipped for now to avoid trigger recursion complexity.

## Trigger logic

### Helper function

Single source of URL composition. Every trigger calls it.

```sql
CREATE OR REPLACE FUNCTION site.compute_professional_url(p_professional_id uuid)
RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_subdomain text;
    v_custom_domain text;
    v_custom_verified timestamptz;
BEGIN
    SELECT bss.custom_domain, bss.custom_domain_verified_at
      INTO v_custom_domain, v_custom_verified
      FROM brand.brand_store_settings bss
     WHERE bss.professional_id = p_professional_id;

    IF v_custom_domain IS NOT NULL AND v_custom_verified IS NOT NULL THEN
        RETURN 'https://' || v_custom_domain;
    END IF;

    SELECT s.subdomain INTO v_subdomain
      FROM site.sites s
     WHERE s.professional_id = p_professional_id;

    IF v_subdomain IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN 'https://' || v_subdomain || '.partna.au';
END;
$$;
```

`'partna.au'` is hardcoded. A future base-domain change requires one DDL edit + one backfill migration. Acceptable trade-off.

### Triggers

| # | Table | Event | Action |
|---|---|---|---|
| 1 | `site.sites` | AFTER INSERT OR UPDATE OF `subdomain` | Recompute `professionals.user_url` for the linked professional, AND recompute `brand_partner_links.brand_affiliate_url` for every row where this professional is the brand |
| 2 | `brand.brand_store_settings` | AFTER INSERT OR UPDATE OF `custom_domain`, `custom_domain_verified_at` | Recompute `professionals.user_url` + cascade to `brand_partner_links` rows where this professional is the brand |
| 3 | `core.professionals` | AFTER UPDATE OF `handle` | Insert OLD handle into `site.professional_handle_aliases`. Recompute `brand_partner_links.brand_affiliate_url` for every row where this professional is the affiliate (path segment changed) |
| 4 | `brand.brand_partner_links` | BEFORE INSERT | Compute initial `brand_affiliate_url` from brand's current `user_url` + affiliate's current `handle` |
| 5 | `core.professionals` | BEFORE UPDATE OF `handle` | Constraint check: raise if new handle exists in `professional_handle_aliases` for a different professional. Prevents renaming into someone else's redirected handle |

### Per-brand URL formula

```
brand_partner_links.brand_affiliate_url
  = professionals[brand_id].user_url || '/' || professionals[affiliate_id].handle
```

### Cascade traces

**Brand renames "Evo" → "Evolution":**
1. App-layer code updates `professionals.display_name`, `professionals.handle`, and (via `UpdateSiteAction.php`) `site.sites.subdomain`.
2. Trigger 5 (BEFORE UPDATE) verifies the new handle isn't aliased to another professional.
3. Trigger 3 fires: old handle inserted into `professional_handle_aliases`.
4. `UpdateSiteAction.php` (existing) inserts old subdomain into `site_subdomain_aliases`.
5. Trigger 1 fires on the subdomain UPDATE: `user_url` recomputed, all `brand_partner_links` rows for this brand cascade-updated.
6. Old URL `https://evo.partna.au/josh` resolves: `evo` looked up in `site_subdomain_aliases` → 301 to `https://evolution.partna.au/josh` (path preserved).

**Affiliate renames "Josh" → "Barber Josh":**
1. App updates `display_name`, `handle`, `site.sites.subdomain`.
2. Trigger 3 fires: old handle inserted into `professional_handle_aliases`.
3. Trigger 1 fires: own `user_url` recomputed.
4. Trigger 3's cascade fires: every `brand_partner_links` row where this affiliate is the affiliate — `brand_affiliate_url` recomputed (path segment changes from `/josh` to `/barber-josh`).
5. Old URL `https://josh.partna.au` redirects via `site_subdomain_aliases` → `https://barber-josh.partna.au`.
6. Old URL `https://evo.partna.au/josh` is handled by frontend looking up the path segment in `professional_handle_aliases` → 301 to `https://evo.partna.au/barber-josh`.

**Brand verifies custom domain:**
1. App writes `brand_store_settings.custom_domain` + `custom_domain_verified_at`.
2. Trigger 2 fires: brand's `user_url` switches to `https://evo.com`. All `brand_partner_links` rows for this brand recompute — affiliate URLs become `https://evo.com/josh`.

## API surface

### `ProfessionalResource` (auth)

```php
[
    'id'                => $this->id,
    'professional_type' => $this->professional_type,
    'brand_name'        => $this->when($this->isBrand(), $this->display_name),
    'username'          => $this->when($this->isAffiliate(), $this->display_name),
    'user_url'          => $this->user_url,
    'first_name'        => $this->first_name,
    'last_name'         => $this->last_name,
    'bio'               => $this->bio,
    // handle / handle_lc REMOVED
]
```

### `ProfessionalPublicResource` (unauthenticated)

Same as above, minus PII (`first_name`, `last_name` excluded).

### `BrandStoreSettingsResource`

Remove `storefront_base_url`. Frontend reads `user_url` from the linked professional resource instead.

### QR code endpoints — removed

The following code is deleted, not deprecated:

- `app/Http/Controllers/Api/PublicSite/QrCodeController.php` — the `redirect()` method and `/p/{qr_slug}` route are removed entirely. The `svg()` method is retained but rewritten to read `user_url` from the professional directly (no slug lookup, no URL composition).
- `app/Http/Controllers/Concerns/BuildsQrCodeUrls.php` — the trait is deleted. No replacement; SVG generator uses `$professional->user_url` directly.
- `qr_slug` is removed from `ProfessionalResource.php`, `ProfessionalDashboardResource.php`, `ProfessionalStaffResource.php`, and `Professional.php` `$fillable`.

The QR SVG endpoint signature changes from `GET /qr/{qr_slug}.svg` (or similar — verify exact route during implementation) to a professional-id-based route, since slug lookup is no longer the primary key path.

Routes referencing `/p/{qr_slug}` in `routes/api.php` and `routes/api/*.php` must be removed.

### Per-brand affiliate listing

Wherever `BrandPartnerLink` is serialized (likely in a `BrandAffiliateController` resource — to be confirmed during implementation planning), include:

```php
[
    'affiliate'           => new ProfessionalPublicResource($this->affiliate),
    'slot'                => $this->slot,
    'brand_affiliate_url' => $this->brand_affiliate_url,
    'created_at'          => $this->created_at,
]
```

### Form Request changes

- **`BootstrapRequest.php`** (signup): handle uniqueness rule extended to also check `site.professional_handle_aliases` (one additional `unique` rule).
- **`UpdateSiteRequest.php`**: subdomain availability check extended to also check `site.professional_handle_aliases` — keeps the namespace unified (you can't claim `evo` as your subdomain if `evo` is anyone's old handle).

## Migration plan

Three migration files, in order. All raw SQL under `supabase/migrations/`.

### Migration 1 — schema + triggers (no data writes)

`supabase/migrations/20260508000000_url_columns_and_triggers.sql`
- Add `user_url` column (nullable)
- Add `brand_affiliate_url` column (nullable)
- Create `site.professional_handle_aliases` table
- Create `site.compute_professional_url(uuid)` function
- Create five triggers
- Add lookup indexes

### Migration 2 — backfill existing rows

`supabase/migrations/20260508000001_backfill_user_urls.sql`
- `UPDATE core.professionals SET user_url = site.compute_professional_url(id)` where a linked `site.sites` row exists
- `UPDATE brand.brand_partner_links SET brand_affiliate_url = ...` joined to brand's `user_url` and affiliate's `handle`
- Pre-beta row counts; single-statement transactions are fine

### Migration 3 — tighten constraints

`supabase/migrations/20260508000002_url_columns_not_null.sql`
- `ALTER TABLE core.professionals ALTER COLUMN user_url SET NOT NULL`
- `ALTER TABLE brand.brand_partner_links ALTER COLUMN brand_affiliate_url SET NOT NULL`

This pattern matches the existing `20260506400000_backfill_orders_payout_id.sql` precedent (backfill, then constrain).

### Migration 4 — drop qr_slug

`supabase/migrations/20260508000003_drop_professionals_qr_slug.sql`
- `ALTER TABLE core.professionals DROP COLUMN qr_slug;`
- Drops the partial unique index `professionals_qr_slug_unique` (cascades automatically with the column drop).

Runs LAST in the migration sequence so that any code path still referencing `qr_slug` during the transitional rollout (between migrations and code deploy) doesn't break. The PHP code changes that remove `qr_slug` from models/resources should land in the same release as this migration, but the order within the release is: code-deploy → migrate. Inverted from the column-add migrations.

### Code-side rollout

1. Apply migrations 1–3.
2. Update `app/Models/Core/Professional/Professional.php` — add `user_url` (read-only — guarded), remove `qr_slug` from `$fillable`.
3. Update `app/Models/Core/Professional/BrandPartnerLink.php` — expose `brand_affiliate_url` (read-only — guarded).
4. Update `ProfessionalResource.php`, `ProfessionalPublicResource.php`, `ProfessionalDashboardResource.php`, `ProfessionalStaffResource.php` per Section 4 above. Remove `qr_slug` and `handle`/`handle_lc`.
5. Drop `storefront_base_url` from `BrandStoreSettingsResource.php`.
6. Update per-brand listing endpoint(s) to include `brand_affiliate_url`.
7. Update `BootstrapRequest.php` and `UpdateSiteRequest.php` to validate against the alias table.
8. Rewrite `QrCodeController::svg` to read `user_url` directly. Delete `QrCodeController::redirect`. Delete `BuildsQrCodeUrls` trait. Remove `/p/{qr_slug}` route from `routes/api.php`.
9. Audit other controllers/services for `qr_slug` references — `app/Services/Professional/SiteProvisioningService.php`, `app/Services/Cache/ProfessionalCacheService.php`, `app/Services/Analytics/AffiliateProjectionsService.php`, `app/Http/Controllers/Api/PublicSite/BootstrapController.php` are known references that need updating.
10. Pest feature tests (see Testing Strategy below).
11. Run `composer test` + Pint before commit.
12. Apply migration 4 (drops `qr_slug` column) AFTER code deploy is verified.

## Testing strategy

The existing test suite uses SQLite in-memory. Triggers and `LOWER()` unique indexes are Postgres-only.

**Approach:**
- Write Pest feature tests for the API and Resource layer changes — these run fine on SQLite (the DB-side trigger is mocked at the model level by setting `user_url` explicitly in factories).
- Write Pest tests for trigger correctness as a separate group, marked with a `pgsql` group annotation. These tests run only when `DB_CONNECTION=pgsql` is set against a real Supabase test instance.
- CI can either run the pgsql group against a live test DB or skip it; document this choice when implementing.
- Manual smoke verification for the cascade flows (brand rename, affiliate rename, custom domain verify) on dev Supabase before merge.

## Risks & open considerations

1. **Trigger ordering on combined writes.** A future "rename brand" action might update `professionals.handle` AND `site.sites.subdomain` in the same transaction. Both triggers fire, `user_url` recomputed twice. End state correct; extra writes ignorable. Don't pre-optimize.

2. **Custom domain unverification.** Setting `custom_domain_verified_at` back to NULL must restore the `partna.au` form. The function handles this via the NULL check; needs a regression test.

3. **Cascade fan-out at scale.** A brand with thousands of affiliates verifying a custom domain triggers thousands of `brand_partner_links` updates. Pre-beta and near-term scale this is sub-100ms. Revisit if a single brand exceeds ~10k affiliates.

4. **No follow-up needed for soft-delete cleanup.** `ON DELETE CASCADE` on `professional_handle_aliases.professional_id` handles cleanup when the existing soft-delete retention job hard-deletes a professional after 30 days. No new scheduled job required.

5. **Frontend coordination.** Frontend repo (per memory: never pull/push from backend session) needs a synchronized PR after backend ships. Removed fields (`handle`, `handle_lc`, `storefront_base_url`) and renamed projections (`brand_name` / `username` / `user_url`) are breaking — this is OK because pre-beta means no live consumers, but the frontend team must be informed before backend ships.

## Out of scope (future work)

- Dedicated handle-rename endpoint (the comment in `UpdateProfessionalRequest.php:19` becomes scoped follow-up work).
- Custom path schemes beyond `evo.partna.au/{handle}` (e.g., `evo.partna.au/team/{handle}`).
- Vanity URLs that don't follow the brand-or-handle pattern.
- Removing `core.professionals.handle_lc` entirely (it remains the case-insensitive uniqueness key; the URL refactor doesn't touch it).
- `brand.custom_domain_aliases` table to protect against custom-domain renames breaking printed QR codes. Defer until a brand actually swaps custom domains in production.
- QR scan attribution. Removing the `/p/{qr_slug}` indirection means we no longer have a natural backend hook to log "this view came from a QR scan". If we need scan attribution later, encode the QR target with a `?utm_source=qr` query string — purely a frontend/SVG-generation change.
