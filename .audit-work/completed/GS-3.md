---
item_id: '#GS-3'
title: Centralize TTL constants in `config/cache.php` (currently scattered as magic
  numbers)
source: audit-2026-05-07-caching-foundation.md
tier: P2
effort_estimate: S
completed_at: '2026-05-08T03:36:12+00:00'
mode: overnight
commit_sha: 9dc7f90
files_touched:
- app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersCancelledWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersEditedWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersUpdatedWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyRefundsCreateWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyShopUpdateWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php
- app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Store/BrandCatalogService.php
- config/partna.php
test_result: pass
questions_asked: 0
---

# #GS-3 — Centralize TTL constants in `config/cache.php` (currently scattered as magic numbers)

## Plain English

All the "how long do we cache X" rules were written as hardcoded numbers scattered across a dozen different files. Now they live in one place — `config/partna.php` under a `cache.ttls` section. Every TTL has a descriptive name and can be changed via an environment variable plus one artisan command, with no code deploy needed. Nothing actually changed about how long things are cached — only where the numbers live.

## Technical Summary

Added `partna.cache.ttls` array to `config/partna.php` with 9 env-overridable integer keys (seconds): `public_payload` (900s), `analytics_short` (300s), `auth_id_lookup` (1800s), `professional_model` (60s), `professional_handle_lookup` (3600s), `webhook_idempotency` (86400s), `brand_admin_catalog` (300s), `collection_gid` (3600s), `product_custom_photos` (60s).

Files updated to consume `config('partna.cache.ttls.*')`:
- `SiteCacheService` — removed `private const PAYLOAD_TTL_SECONDS = 900`; updated `jitteredPayloadTtl()` and `getSiteLinkBlocks()`.
- `AnalyticsCacheService` — replaced both `now()->addMinutes(5)` calls.
- `ProfessionalCacheService` — replaced 8 inline TTLs across `getIdByAuthId`, `getIdByHandle`, `getPayloadById`, `getByAuthId` (model + cache put), `getActiveServices`, `getDashboardServices`, `getBrandStoreSettings`, `getBrandPartnerStatus`, `getCustomerCount`.
- `BrandCatalogService` — removed 3 unused/used class constants (`CATALOG_CACHE_TTL_MINUTES`, `COLLECTION_GID_CACHE_TTL_MINUTES`, `PRODUCT_CUSTOM_PHOTOS_TTL_SECONDS`); replaced 5 call sites.
- 9 webhook controllers — replaced `now()->addHours(24)` dedup window in each.

Pure refactor: all default values identical to prior hardcoded values, 1635 tests passed.

## Decisions Made

- **`config/partna.php` over `config/cache.php`**: partna.php is the established home for application-level feature config (CLAUDE.md confirms this); cache.php is framework infrastructure. Keeps all operational knobs in one file.
- **`public_payload` reused for `getCustomerCount` (15m)**: both are 15-minute warm reads that would be tuned together in a Redis incident. If they diverge later, adding a separate key is a one-liner.
- **`analytics_short` reused for `getBrandPartnerStatus` (5m)**: same tier as analytics stats (5-min warm read), semantically compatible.
- **`auth_id_lookup` reused for services/settings/store-settings caches (30m)**: same TTL tier as the auth mapping; all benefit from the same tuning knob (if you shorten auth TTLs under load, you want to shorten profile data too).
- **Removed `CATALOG_CACHE_TTL_MINUTES = 10` constant**: was defined but never referenced anywhere in `BrandCatalogService`; the actual `fetchBrandCatalog` call used `5` minutes, not `10`. Removing the dead constant avoids confusion. The live value (5m) is now in `brand_admin_catalog`.
- **Int seconds for `rememberLocked` args**: `CacheLockService::rememberLocked` accepts `DateTimeInterface|int $ttl`; passing the raw int from config is cleaner than wrapping in `now()->addSeconds(...)` for every call.

## Notes

- The `CATALOG_CACHE_TTL_MINUTES = 10` constant was a latent inconsistency — defined as 10 min but `fetchBrandCatalog` was actually using 5 min. This refactor preserves the behavior (5 min) and removes the confusing dead constant.
- All 9 webhook controllers use an identical dedup pattern (`Cache::add($key, true, <ttl>)`); the single `webhook_idempotency` key covers all of them cleanly.
- The `professional_handle_lookup` key (3600s) coincidentally equals `collection_gid` (3600s) in default value but serves a completely different traffic tier — kept separate for independent tuning.

## Questions Asked
(none)
