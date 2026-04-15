# V2 Pre-Beta — V1 Code Removal Plan

> **Branch:** `development-v2` (current)
> **V1 Backup:** `development-v1` (already created — full copy of current backend)
> **Scope:** Removals and modifications ONLY. No new features.

---

## Why This Matters

V2 moves product data to Shopify's native data layer (metafields, collections, Storefront API). The entire V1 local product sync layer — tables, models, controllers, services, jobs — is dead code in a V2 world. Leaving it creates confusion, gets accidentally called, and wastes debug time. It must go before any V2 feature work begins.

---

## Execution Order

Migrations must respect foreign key dependencies. Code removal can happen in any order but should follow a logical outside-in approach: routes → controllers → services → models → migrations.

### Phase 1: Code Removal (No Schema Changes Yet)

Remove all V1 application code first. This makes the codebase clean before any database changes, and means `php artisan test` will surface any missed dependencies.

### Phase 2: Database Migrations

Write Laravel migrations (each with `down()`) to drop tables, columns, rename tables, and create the V2 replacement table.

### Phase 3: Verification

Run tests, check for orphaned references, confirm nothing breaks.

---

## Phase 1 — Code Removal

### Step 1.1: Remove V1 Routes

**File:** `routes/api/professional.php`

Delete all route registrations for the following endpoint groups:

**Store product management routes (DELETE ALL):**
- `GET /store/brand-products`
- `PATCH /store/brand-products/bulk`
- `PATCH /store/brand-products/{brandProductId}`
- `GET /store/available-products`
- `GET /store/featured-products`
- `PUT /store/featured-products`

**Store brand settings routes (PARTIAL — see note):**
- `GET /store/brand-settings` — DELETE
- `PATCH /store/brand-settings` — DELETE
> **Note:** The `payout_hold_days` setting will be re-exposed via a new endpoint in V2. For now, remove the V1 route entirely.

**Affiliate product override routes (DELETE ALL):**
- `GET /store/affiliate-overrides`
- `PUT /store/affiliate-overrides/deny`
- `DELETE /store/affiliate-overrides/deny`
- `PUT /store/affiliate-overrides/allow`
- `DELETE /store/affiliate-overrides/allow`

**Affiliate product settings routes (DELETE ALL):**
- `GET /store/affiliate-product-settings`
- `PUT /store/affiliate-product-settings`
- `DELETE /store/affiliate-product-settings`

**Product media routes (DELETE ALL):**
- `GET /store/products/{brandProductId}/media`
- `POST /store/products/{brandProductId}/media`
- `POST /store/products/{brandProductId}/media/reorder`
- `DELETE /store/products/{brandProductId}/media/{mediaId}`

**Affiliate defaults routes (DELETE ALL):**
- `GET /store/affiliate-defaults`
- `PATCH /store/affiliate-defaults`

**Affiliate settings routes (DELETE ALL):**
- `GET /store/affiliate-settings/{affiliateId}`
- `PATCH /store/affiliate-settings/{affiliateId}`

**Affiliate segment routes (DELETE ALL):**
- `GET /store/affiliate-segments`
- `POST /store/affiliate-segments`
- `GET /store/affiliate-segments/{segmentId}`
- `PATCH /store/affiliate-segments/{segmentId}`
- `DELETE /store/affiliate-segments/{segmentId}`
- `POST /store/affiliate-segments/{segmentId}/refresh`

**Promotion routes (DELETE ALL):**
- `GET /store/promotions`
- `POST /store/promotions`
- `POST /store/promotions/preview`
- `GET /store/promotions/{promotionId}`
- `PATCH /store/promotions/{promotionId}`
- `DELETE /store/promotions/{promotionId}`
- `POST /store/promotions/{promotionId}/clone`
- `GET /store/promotions/{promotionId}/analytics`

**Store analytics routes (DELETE ALL):**
- `GET /store/brand-analytics/overview`
- `GET /store/brand-analytics/influencers`
- `GET /store/brand-analytics/influencers/{professionalId}`
- `GET /store/brand-analytics/products`
- `GET /store/brand-analytics/products/{brandProductId}`
- `GET /store/brand-analytics/commissions`
- `GET /store/brand-analytics/timeseries`
- `GET /store/my-analytics/overview`
- `GET /store/my-analytics/products`
- `GET /store/my-analytics/products/{brandProductId}`
- `GET /store/my-analytics/commissions`
- `GET /store/my-analytics/customers`
- `GET /store/my-analytics/timeseries`

**File:** `routes/api.php`

Delete these public store routes:
- `GET /public/store/featured-products-by-slug`
- `POST /public/store/checkout-session-by-slug`
- `POST /public/store/stripe-checkout-by-slug`
- `POST /public/store/payment-intent-by-slug`

**File:** `routes/api/publicSite.php`

Delete these public site store routes:
- `GET /public/store/featured-products`
- `POST /public/store/checkout-session`
- `POST /public/store/stripe-checkout`
- `POST /public/store/payment-intent`

---

### Step 1.2: Delete V1 Controllers

Delete these entire controller files:

| File | Reason |
|------|--------|
| `app/Http/Controllers/Api/Professional/Store/BrandProductsController.php` | Local product catalog — replaced by Shopify Storefront API |
| `app/Http/Controllers/Api/Professional/Store/BrandProductMediaController.php` | Custom product media — brands use Shopify's native media |
| `app/Http/Controllers/Api/Professional/Store/BrandProductAffiliateSettingController.php` | Per-affiliate pricing — replaced by Shopify metafields |
| `app/Http/Controllers/Api/Professional/Store/BrandProductAffiliateOverrideController.php` | Deny/allow overrides — affiliates choose their own products in V2 |
| `app/Http/Controllers/Api/Professional/Store/FeaturedProductsController.php` | Featured product selection — rebuilt for V2 with Shopify GIDs |
| `app/Http/Controllers/Api/Professional/Store/BrandPromotionController.php` | Time-bounded promotions — not in V2 scope |
| `app/Http/Controllers/Api/Professional/Store/BrandAffiliateSegmentController.php` | Dynamic affiliate segments — not in V2 scope |
| `app/Http/Controllers/Api/Professional/Store/BrandAffiliateSettingsController.php` | Per-affiliate media toggles — not in V2 scope |
| `app/Http/Controllers/Api/Professional/Store/BrandAffiliateDefaultsController.php` | Affiliate theme/product defaults — replaced by Shopify metafields |
| `app/Http/Controllers/Api/Professional/Store/BrandStoreController.php` | Brand store settings — commission/checkout/favourites all move to Shopify |
| `app/Http/Controllers/Api/Professional/Store/StoreAnalyticsV2Controller.php` | Store analytics — will be rebuilt for V2 schema |
| `app/Http/Controllers/Api/PublicSite/PublicStoreController.php` | Public store endpoints — V2 uses Shopify native checkout |

**Total: 12 controllers deleted**

---

### Step 1.3: Delete V1 Services

Delete these entire service files:

| File | Reason |
|------|--------|
| `app/Services/Store/BrandProductCatalogService.php` | Local catalog queries — no local catalog in V2 |
| `app/Services/Store/BrandProductSettingsService.php` | Ensures settings rows for synced products — settings now in Shopify metafields |
| `app/Services/Store/ShopifyCatalogSyncService.php` | Shopify → local product sync — no sync needed in V2 |
| `app/Services/Store/PromotionResolutionService.php` | Resolve active promotions — promotions removed |
| `app/Services/Store/SegmentEvaluationService.php` | Evaluate segment membership — segments removed |
| `app/Services/Store/FeaturedProductsPayloadService.php` | Build featured products payload — rebuilt for V2 |
| `app/Services/Store/PublicStripeCheckoutService.php` | Stripe checkout sessions — V2 uses Shopify native checkout |
| `app/Services/Store/OrderAnalyticsAggregateService.php` | Order analytics aggregation — will be rebuilt for V2 schema |
| `app/Services/Store/OrderAnalyticsHourlyAggregateService.php` | Hourly order analytics — will be rebuilt for V2 schema |

**Total: 9 services deleted**

**Services to SIMPLIFY (keep file, gut V1 logic):**

| File | What to remove | What to keep |
|------|---------------|-------------|
| `app/Services/Store/BrandPricingService.php` | Remove price/discount lookups from `brand_product_settings`, `brand_product_affiliate_settings`, and `brand_promotions` tables. Remove promotion stacking logic. | Keep core commission calculation math (percentage of order total). V2 reads rates from Shopify metafields, but the arithmetic is the same. |
| `app/Services/Store/SelectionCleanupService.php` | Remove joins to `brand_products` table. Remove enterprise product lookups. | Simplify to work with `shopify_product_gid TEXT` in the new `affiliate_product_selections` table. |
| `app/Services/Store/BrandAccessService.php` | Remove deny/allow override resolution from `brand_product_affiliate_overrides`. Remove segment-based access checks. | Keep basic brand-affiliate relationship validation. |

---

### Step 1.4: Delete V1 Jobs

Delete these entire job files:

| File | Reason |
|------|--------|
| `app/Jobs/Store/RebuildBrandDailyAggregatesJob.php` | Aggregates from V1 schema — will be rebuilt |
| `app/Jobs/Store/RebuildBrandHourlyAggregatesJob.php` | Aggregates from V1 schema — will be rebuilt |
| `app/Jobs/Store/RebuildProfessionalDailyAggregatesJob.php` | Aggregates from V1 schema — will be rebuilt |
| `app/Jobs/Store/RebuildProfessionalHourlyAggregatesJob.php` | Aggregates from V1 schema — will be rebuilt |
| `app/Jobs/Notifications/SendPromotionStartNotificationsJob.php` | Promotions removed |
| `app/Jobs/Notifications/SendPromotionEndNotificationsJob.php` | Promotions removed |
| `app/Jobs/Notifications/RefreshActiveSegmentMembersJob.php` | Segments removed |

**Total: 7 jobs deleted**

**Also remove their schedule registrations** from `app/Console/Kernel.php` (or `routes/console.php`):
- Remove `SendPromotionStartNotificationsJob` schedule (every 5 minutes)
- Remove `SendPromotionEndNotificationsJob` schedule (every 5 minutes)
- Remove `RefreshActiveSegmentMembersJob` schedule (hourly)
- Remove all 4 `RebuildBrand/ProfessionalDailyAggregatesJob` and `RebuildBrand/ProfessionalHourlyAggregatesJob` schedules

---

### Step 1.5: Delete V1 Models

Delete these entire model files:

| File | Table | Reason |
|------|-------|--------|
| `app/Models/Retail/BrandProduct.php` | `retail.brand_products` | Local product mirror — replaced by live Storefront API |
| `app/Models/Retail/BrandProductSetting.php` | `retail.brand_product_settings` | Product settings — replaced by Shopify metafields |
| `app/Models/Retail/BrandProductMedia.php` | `retail.brand_product_media` | Product media — brands use Shopify native media |
| `app/Models/Retail/BrandProductAffiliateSetting.php` | `retail.brand_product_affiliate_settings` | Per-affiliate pricing — replaced by metafields |
| `app/Models/Retail/BrandProductAffiliateOverride.php` | `retail.brand_product_affiliate_overrides` | Deny/allow overrides — not in V2 |
| `app/Models/Retail/BrandAffiliateSegment.php` | `retail.brand_affiliate_segments` | Affiliate segments — not in V2 |
| `app/Models/Retail/BrandAffiliateSegmentMember.php` | `retail.brand_affiliate_segment_members` | Segment members — not in V2 |
| `app/Models/Retail/BrandAffiliateSettings.php` | `retail.brand_affiliate_settings` | Per-affiliate settings — not in V2 |
| `app/Models/Retail/BrandPromotion.php` | `retail.brand_promotions` | Promotions — not in V2 |
| `app/Models/Retail/ProfessionalSelection.php` | `retail.professional_selections` | V1 product selections — replaced by `affiliate_product_selections` |

**Total: 10 models deleted**

---

### Step 1.6: Delete V1 FormRequests

Delete all FormRequest files that validate requests for deleted controllers:

| File | Reason |
|------|--------|
| `app/Http/Requests/Api/Professional/Store/IndexBrandProductAffiliateSettingRequest.php` | Controller deleted |
| `app/Http/Requests/Api/Professional/Store/UpsertBrandProductAffiliateSettingRequest.php` | Controller deleted |
| `app/Http/Requests/Api/Professional/Store/RemoveBrandProductAffiliateSettingRequest.php` | Controller deleted |
| `app/Http/Requests/Api/Professional/Store/UploadProductMediaRequest.php` | Controller deleted |

> **Note:** Search for any other FormRequest files in the `Store/` directory and delete those too.

---

### Step 1.7: Delete V1 Observer

| File | Reason |
|------|--------|
| `app/Observers/ProfessionalSelectionObserver.php` | Watches `ProfessionalSelection` deletions, depends on `brand_products` table |

**Also remove its registration** from `app/Providers/EventServiceProvider.php` (or wherever observers are registered).

---

### Step 1.8: Clean Up Remaining References

After all deletions, grep the codebase for any remaining references to deleted code:

```bash
# Search for references to deleted models
grep -r "BrandProduct" app/ --include="*.php" -l
grep -r "BrandPromotion" app/ --include="*.php" -l
grep -r "BrandAffiliateSegment" app/ --include="*.php" -l
grep -r "ProfessionalSelection" app/ --include="*.php" -l
grep -r "BrandAffiliateSettings" app/ --include="*.php" -l
grep -r "BrandStoreSettings" app/ --include="*.php" -l

# Search for references to deleted tables
grep -r "brand_products" app/ --include="*.php" -l
grep -r "brand_product_settings" app/ --include="*.php" -l
grep -r "professional_selections" app/ --include="*.php" -l
grep -r "brand_promotions" app/ --include="*.php" -l
grep -r "brand_affiliate_segments" app/ --include="*.php" -l
```

**Known files that will need cleanup (references to deleted code):**

| File | What to clean |
|------|--------------|
| `app/Models/Core/Professional/Professional.php` | Remove `hasMany` relationships to: `BrandProduct`, `BrandProductSetting`, `BrandProductAffiliateSetting`, `BrandProductAffiliateOverride`, `BrandAffiliateSegment`, `BrandPromotion`, `ProfessionalSelection`, `BrandAffiliateSettings`. Keep all other relationships. |
| `app/Models/Retail/RetailOrder.php` | No changes needed — order records stay |
| `app/Models/Retail/OrderItem.php` | Remove `belongsTo(BrandProduct)` relationship. Keep `shopify_product_id` and `shopify_variant_id` references. |
| `app/Models/Retail/BrandStoreSettings.php` | This model STAYS but will be heavily simplified in Phase 2 (column drops). For now, remove any methods that reference deleted models. |
| `app/Models/Retail/CommissionLedgerEntry.php` | Review for any BrandProduct references — should reference order items only |
| `app/Services/Store/BrandPricingService.php` | Gut V1 logic (see Step 1.3) |
| `app/Services/Store/SelectionCleanupService.php` | Gut V1 logic (see Step 1.3) |
| `app/Services/Store/BrandAccessService.php` | Gut V1 logic (see Step 1.3) |
| `app/Services/Store/ShopifyOrderCreationService.php` | Remove any `BrandProduct::find()` lookups. V2 order items use `shopify_product_id` directly from the webhook payload. |
| `app/Services/Store/ShopifyOrderProcessingService.php` | Remove any `BrandProduct` or `BrandProductSetting` lookups. Commission rate now comes from line item attributes (`sidest_commission_rate`). |
| `app/Services/Notifications/CommerceNotificationService.php` | Remove any notification types that reference promotions or segments |
| `app/Console/Kernel.php` (or `routes/console.php`) | Remove schedule entries for deleted jobs |
| `app/Providers/EventServiceProvider.php` | Remove observer registration for `ProfessionalSelectionObserver` |

---

## Phase 2 — Database Migrations

Write these as standard Laravel migrations in `database/migrations/` (or `supabase/migrations/` if that's the convention). Each migration MUST have a `down()` method.

### Migration Ordering (FK dependency order)

Foreign keys must be dropped before their parent tables. The correct order is:

```
Migration 1: Drop FK-dependent V1 tables (children first)
Migration 2: Drop V1 parent tables
Migration 3: Drop V1 columns from surviving tables
Migration 4: Rename analytics tables + update column types
Migration 5: Create V2 replacement table (affiliate_product_selections)
```

---

### Migration 1: Drop V1 Child Tables

These tables have FKs pointing to `retail.brand_products` or other V1 tables. Drop them first.

```
DROP TABLE retail.brand_product_settings;
DROP TABLE retail.brand_product_media;
DROP TABLE retail.brand_product_affiliate_settings;
DROP TABLE retail.brand_product_affiliate_overrides;
DROP TABLE retail.brand_affiliate_segment_members;
DROP TABLE retail.professional_selections;
DROP TABLE retail.sale_events;          -- V1 Bed & Blade sales log
DROP TABLE retail.commission_payout_items;  -- review FK deps first
```

> **Note on `commission_payout_items`:** Check if this table has FKs to any V1 tables. If it only references `commission_payouts` and `commission_ledger_entries`, it STAYS and is NOT dropped.

---

### Migration 2: Drop V1 Parent Tables

After children are gone, drop parent tables:

```
DROP TABLE retail.brand_products;
DROP TABLE retail.brand_affiliate_segments;
DROP TABLE retail.brand_promotions;
DROP TABLE retail.brand_affiliate_settings;
DROP TABLE retail.brand_team_memberships;
DROP TABLE retail.products;             -- V1 curated Bed & Blade catalog
DROP TABLE retail.report_exports;
DROP TABLE retail.report_schedules;
```

---

### Migration 3: Drop V1 Columns from Surviving Tables

**`retail.brand_store_settings`** — drop V1 columns, keep `payout_hold_days`:
```sql
ALTER TABLE retail.brand_store_settings
  DROP COLUMN IF EXISTS default_commission_rate,
  DROP COLUMN IF EXISTS favourite_brand_product_ids,
  DROP COLUMN IF EXISTS product_image_ratio,
  DROP COLUMN IF EXISTS default_affiliate_product_ids,
  DROP COLUMN IF EXISTS allow_affiliate_media,
  DROP COLUMN IF EXISTS checkout_mode,
  DROP COLUMN IF EXISTS default_affiliate_theme_id;
```

**`retail.order_items`** — drop `brand_product_id` FK:
```sql
ALTER TABLE retail.order_items
  DROP CONSTRAINT IF EXISTS order_items_brand_product_id_fkey,
  DROP COLUMN IF EXISTS brand_product_id;
```

> **Note:** `order_items` keeps `shopify_product_id`, `shopify_variant_id`, `product_snapshot` — these are the V2 product references.

---

### Migration 4: Rename Analytics Tables + Update Columns

**Rename "influencer" → "affiliate" terminology:**
```sql
ALTER TABLE analytics.brand_influencer_daily
  RENAME TO brand_affiliate_daily;

ALTER TABLE analytics.brand_influencer_product_daily
  RENAME TO brand_affiliate_product_daily;
```

**Update `brand_product_id` → `shopify_product_gid` in analytics tables:**

These analytics tables currently use `brand_product_id UUID` which references the now-dropped `retail.brand_products` table. Change to `shopify_product_gid TEXT`:

```sql
-- analytics.brand_product_daily
ALTER TABLE analytics.brand_product_daily
  DROP COLUMN IF EXISTS brand_product_id;
ALTER TABLE analytics.brand_product_daily
  ADD COLUMN shopify_product_gid TEXT;

-- analytics.brand_affiliate_product_daily (newly renamed)
ALTER TABLE analytics.brand_affiliate_product_daily
  DROP COLUMN IF EXISTS brand_product_id;
ALTER TABLE analytics.brand_affiliate_product_daily
  ADD COLUMN shopify_product_gid TEXT;

-- analytics.professional_product_daily
ALTER TABLE analytics.professional_product_daily
  DROP COLUMN IF EXISTS brand_product_id;
ALTER TABLE analytics.professional_product_daily
  ADD COLUMN shopify_product_gid TEXT;

-- analytics.store_order_event_items — review product_id column
-- If it references brand_product_id, update to shopify_product_gid
```

> **Note:** These analytics tables should be TRUNCATED before the column change since the old `brand_product_id` UUIDs are meaningless after the parent table is dropped. The V2 aggregation jobs will rebuild them from `retail.orders`.

---

### Migration 5: Create V2 Replacement Table

**Replace `retail.professional_selections` with `retail.affiliate_product_selections`:**

```sql
CREATE TABLE retail.affiliate_product_selections (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  affiliate_professional_id UUID NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
  shopify_product_gid TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (affiliate_professional_id, shopify_product_gid)
);

CREATE INDEX idx_affiliate_product_selections_affiliate
  ON retail.affiliate_product_selections (affiliate_professional_id);
```

> No data migration needed — affiliate product selections will be rebuilt from scratch in V2 since the old `brand_product_id` UUIDs have no mapping to Shopify GIDs.

---

## Phase 3 — Verification

After all changes:

1. **Run tests:** `php artisan test --parallel`
2. **Grep for orphaned references:**
   ```bash
   grep -r "brand_product" app/ --include="*.php" -l
   grep -r "BrandProduct" app/ --include="*.php" -l
   grep -r "professional_selections" app/ --include="*.php" -l
   grep -r "ProfessionalSelection" app/ --include="*.php" -l
   grep -r "BrandPromotion" app/ --include="*.php" -l
   grep -r "BrandAffiliateSegment" app/ --include="*.php" -l
   grep -r "sale_events" app/ --include="*.php" -l
   ```
3. **Check route list:** `php artisan route:list` — confirm no routes reference deleted controllers
4. **Check model relationships:** Verify `Professional.php` has no dangling `hasMany` calls to deleted models
5. **Check scheduled jobs:** `php artisan schedule:list` — confirm deleted jobs are gone

---

## Summary of Everything Removed/Changed

### Files Removed

| Category | Count | Files |
|----------|-------|-------|
| **Controllers** | 12 | `BrandProductsController`, `BrandProductMediaController`, `BrandProductAffiliateSettingController`, `BrandProductAffiliateOverrideController`, `FeaturedProductsController`, `BrandPromotionController`, `BrandAffiliateSegmentController`, `BrandAffiliateSettingsController`, `BrandAffiliateDefaultsController`, `BrandStoreController`, `StoreAnalyticsV2Controller`, `PublicStoreController` |
| **Services** | 9 | `BrandProductCatalogService`, `BrandProductSettingsService`, `ShopifyCatalogSyncService`, `PromotionResolutionService`, `SegmentEvaluationService`, `FeaturedProductsPayloadService`, `PublicStripeCheckoutService`, `OrderAnalyticsAggregateService`, `OrderAnalyticsHourlyAggregateService` |
| **Models** | 10 | `BrandProduct`, `BrandProductSetting`, `BrandProductMedia`, `BrandProductAffiliateSetting`, `BrandProductAffiliateOverride`, `BrandAffiliateSegment`, `BrandAffiliateSegmentMember`, `BrandAffiliateSettings`, `BrandPromotion`, `ProfessionalSelection` |
| **Jobs** | 7 | `RebuildBrandDailyAggregatesJob`, `RebuildBrandHourlyAggregatesJob`, `RebuildProfessionalDailyAggregatesJob`, `RebuildProfessionalHourlyAggregatesJob`, `SendPromotionStartNotificationsJob`, `SendPromotionEndNotificationsJob`, `RefreshActiveSegmentMembersJob` |
| **FormRequests** | 4+ | `IndexBrandProductAffiliateSettingRequest`, `UpsertBrandProductAffiliateSettingRequest`, `RemoveBrandProductAffiliateSettingRequest`, `UploadProductMediaRequest` |
| **Observers** | 1 | `ProfessionalSelectionObserver` |
| **Routes** | 60+ | All `/store/*` product/settings/analytics routes, all public store routes |
| **TOTAL** | **~103+ files and route entries** | |

### Services Modified (Not Deleted)

| Service | What Changes |
|---------|-------------|
| `BrandPricingService` | Gutted — remove local table lookups, keep commission math |
| `SelectionCleanupService` | Simplified — work with Shopify GIDs instead of brand_product_id joins |
| `BrandAccessService` | Simplified — remove deny/allow override resolution |
| `ShopifyOrderCreationService` | Remove BrandProduct lookups — use webhook payload directly |
| `ShopifyOrderProcessingService` | Remove BrandProduct/BrandProductSetting lookups — commission from line item attributes |

### Models Modified (Not Deleted)

| Model | What Changes |
|-------|-------------|
| `Professional.php` | Remove ~10 `hasMany` relationships to deleted models |
| `OrderItem.php` | Remove `belongsTo(BrandProduct)` relationship |
| `BrandStoreSettings.php` | Remove accessors/mutators for dropped columns |
| `CommissionLedgerEntry.php` | Review for BrandProduct references |

---

## Database Table Fate — Complete Reference

### RETAIL Schema

| Table | Fate | Reason |
|-------|------|--------|
| `retail.brand_products` | **REMOVE** | Local product mirror — replaced by live Shopify Storefront API queries |
| `retail.brand_product_settings` | **REMOVE** | Per-product settings — replaced by Shopify product metafields (`sidest.commission_override`, `sidest.affiliate_discount_pct`, `sidest.active`) |
| `retail.brand_product_media` | **REMOVE** | Custom product media — brands use Shopify's native product media |
| `retail.brand_product_affiliate_settings` | **REMOVE** | Per-affiliate product pricing — replaced by Shopify metafields + affiliate product selections |
| `retail.brand_product_affiliate_overrides` | **REMOVE** | Deny/allow product access — affiliates choose their own products in V2 |
| `retail.brand_affiliate_segments` | **REMOVE** | Dynamic affiliate groupings — not in V2 scope |
| `retail.brand_affiliate_segment_members` | **REMOVE** | Segment membership — not in V2 scope |
| `retail.brand_promotions` | **REMOVE** | Time-bounded promotions — not in V2 scope (commission is per-product via metafields) |
| `retail.brand_affiliate_settings` | **REMOVE** | Per-affiliate media toggles — not in V2 scope |
| `retail.brand_team_memberships` | **REMOVE** | Brand team roles — not in V2 scope |
| `retail.products` | **REMOVE** | V1 curated Bed & Blade product catalog — completely replaced |
| `retail.sale_events` | **REMOVE** | V1 Bed & Blade commission sales log — replaced by `retail.orders` + `commission_ledger` |
| `retail.professional_selections` | **REMOVE** | V1 affiliate product selections — replaced by new `retail.affiliate_product_selections` with Shopify GIDs |
| `retail.report_exports` | **REMOVE** | V1 report export system — will be rebuilt if needed |
| `retail.report_schedules` | **REMOVE** | V1 scheduled reports — will be rebuilt if needed |
| `retail.brand_store_settings` | **MODIFY** | Drop 7 columns (commission rate, favourites, image ratio, defaults, checkout mode, theme, media toggle). Keep `payout_hold_days` + `professional_id`. |
| `retail.order_items` | **MODIFY** | Drop `brand_product_id` FK. Keep `shopify_product_id`, `shopify_variant_id`, `product_snapshot`. |
| `retail.affiliate_product_selections` | **CREATE** | New V2 table: `affiliate_professional_id`, `shopify_product_gid TEXT`, `sort_order` |
| `retail.orders` | **KEEP** | Canonical order records — no changes needed |
| `retail.order_event_inbox` | **KEEP** | Webhook inbox — no changes needed |
| `retail.order_attributions` | **KEEP** | Attribution model results — no changes needed |
| `retail.commission_ledger_entries` | **KEEP** | Commission accounting — no changes needed |
| `retail.commission_payouts` | **KEEP** | Payout records — no changes needed |
| `retail.commission_payout_items` | **KEEP** | Payout line items — no changes needed |
| `retail.brand_commission_topups` | **KEEP** | Brand funding/topup — no changes needed |
| `retail.payout_runs` | **KEEP** | Payout execution records — no changes needed |
| `retail.checkout_sessions` | **KEEP** | Checkout sessions — no changes needed |
| `retail.enterprise_shopify_accounts` | **KEEP** | Shopify connection per enterprise — needed for V2 |
| `retail.enterprise_brands` | **KEEP** | Enterprise brand management — needed for V2 |
| `retail.enterprise_products` | **KEEP (REVIEW)** | Enterprise product catalog — also a local mirror, may need removal later but not blocking Pre-Beta |

### ANALYTICS Schema

| Table | Fate | Reason |
|-------|------|--------|
| `analytics.brand_influencer_daily` | **RENAME** → `analytics.brand_affiliate_daily` | Terminology: "influencer" → "affiliate" |
| `analytics.brand_influencer_product_daily` | **RENAME** → `analytics.brand_affiliate_product_daily` | Terminology: "influencer" → "affiliate" |
| `analytics.brand_product_daily` | **MODIFY** | `brand_product_id` → `shopify_product_gid TEXT`. Truncate data. |
| `analytics.brand_affiliate_product_daily` | **MODIFY** | (after rename) `brand_product_id` → `shopify_product_gid TEXT`. Truncate data. |
| `analytics.professional_product_daily` | **MODIFY** | `brand_product_id` → `shopify_product_gid TEXT`. Truncate data. |
| `analytics.store_order_events` | **KEEP (REVIEW)** | May need `product_id` column updated |
| `analytics.store_order_event_items` | **KEEP (REVIEW)** | `product_id` may reference `brand_product_id` — needs update |
| `analytics.brand_metrics_daily` | **KEEP** | Brand-level order metrics — no product FK |
| `analytics.brand_metrics_hourly` | **KEEP** | Hourly brand metrics — no product FK |
| `analytics.brand_commission_daily` | **KEEP** | Commission accrual tracking — no product FK |
| `analytics.brand_payout_daily` | **KEEP** | Payout status — no product FK |
| `analytics.brand_region_daily` | **KEEP** | Regional sales — no product FK |
| `analytics.brand_customer_daily` | **KEEP** | Customer acquisition — no product FK |
| `analytics.professional_metrics_daily` | **KEEP** | Affiliate performance — no product FK |
| `analytics.professional_metrics_hourly` | **KEEP** | Hourly affiliate metrics — no product FK |
| `analytics.professional_customer_daily` | **KEEP** | Affiliate customer metrics — no product FK |
| `analytics.site_visits` | **KEEP** | Site visitor tracking — unrelated to products |
| `analytics.site_metrics_daily` | **KEEP** | Site traffic — unrelated to products |
| `analytics.site_metrics_hourly` | **KEEP** | Site traffic — unrelated to products |
| `analytics.link_clicks` | **KEEP** | Link tracking — unrelated to products |
| `analytics.lead_submissions` | **KEEP** | Lead forms — unrelated to products |
| `analytics.booking_events` | **KEEP** | Booking events — unrelated to products |
| `analytics.booking_metrics_daily` | **KEEP** | Booking metrics — unrelated to products |
| `analytics.booking_metrics_hourly` | **KEEP** | Booking metrics — unrelated to products |

### CORE Schema

| Table | Fate | Reason |
|-------|------|--------|
| `core.professionals` | **KEEP** | Central identity entity |
| `core.sites` | **KEEP** | Professional websites |
| `core.themes` | **KEEP** | Site theme templates |
| `core.blocks` | **KEEP** | Content blocks |
| `core.services` | **KEEP** | Professional services |
| `core.customers` | **KEEP** | Customer contacts |
| `core.comet_staff` | **KEEP** | Internal staff |
| `core.site_images` | **KEEP** | Gallery images |
| `core.site_subdomain_aliases` | **KEEP** | Subdomain aliases |
| `core.notifications` | **KEEP** | Notifications |
| `core.notification_receipts` | **KEEP** | Notification read state |
| `core.email_subscriptions` | **KEEP** | Email preferences |
| `core.service_categories` | **KEEP** | Service categories |
| `core.image_variants` | **KEEP** | Image variants |
| `core.media_variants` | **KEEP** | Media variants |
| `core.site_media` | **KEEP** | Site media |
| `core.enterprises` | **KEEP** | Enterprise entities |
| `core.professional_enterprise_memberships` | **KEEP** | Enterprise memberships |
| `core.influencer_promoter_contracts` | **KEEP** | Promoter contracts |
| `core.enterprise_brand_links` | **KEEP** | Enterprise-brand links |
| `core.brand_partner_links` | **KEEP** | Affiliate-brand partnerships |
| `core.brand_affiliate_invites` | **KEEP** | Brand affiliate invites |
| `core.brand_profiles` | **KEEP** | Brand profile config |
| `core.professional_confirmation_preferences` | **KEEP** | UI confirmation prefs |
| `core.professional_legal_contents` | **KEEP** | Legal content |
| `core.brand_fonts` | **KEEP** | Custom fonts |
| `core.professional_integrations` | **KEEP** | Third-party integrations (Shopify, Square, Fresha) |
| `core.notification_email_preferences` | **KEEP** | Email notification prefs |
| `core.notification_email_policies` | **KEEP** | Email policies |
| `core.waitlist_signups` | **KEEP** | Waitlist tracking |

### PUBLIC Schema

| Table | Fate | Reason |
|-------|------|--------|
| `public.failed_jobs` | **KEEP** | Laravel internals |
| `public.job_batches` | **KEEP** | Laravel internals |

---

## Final Counts

| Action | Tables | Columns | Files |
|--------|--------|---------|-------|
| **REMOVE (drop table)** | 15 | — | — |
| **MODIFY (drop columns)** | 2 | 8 columns dropped | — |
| **MODIFY (column type change)** | 3 | `brand_product_id` → `shopify_product_gid` | — |
| **RENAME** | 2 | — | — |
| **CREATE** | 1 | — | — |
| **KEEP (no change)** | ~57 | — | — |
| **DELETE (code files)** | — | — | ~43+ files |
| **MODIFY (code files)** | — | — | ~8 files |
| **DELETE (routes)** | — | — | 60+ route entries |
