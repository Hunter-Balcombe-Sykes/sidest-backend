# Feature Flag Overrides (FF-1) — Design

**Date:** 2026-05-17
**Audit reference:** FF-1 (per-tenant feature flag overrides)
**Status:** Design approved, ready for implementation plan

## Motivation

Partna has 12 `config('partna.features.*')` callsites today. Ten of them guard dropped features (Square sync, Fresha sync, smart booking — see `project_booking_dropped.md`); the remaining two are global-only flags that don't need tenant scoping. The audit-flagged FF-1 finding is real but the *current* codebase doesn't justify it on its own.

Building it now is justified by **future use**: gradual rollout of new features (closed beta, percentage ramp, per-tenant kill-switch). This spec designs the system for that use case from day one rather than the narrower "override existing config" framing.

Three rollout modes share one resolver and one storage layer:

- **Allowlist:** flag off by default, on for tenants explicitly opted in.
- **Percentage rollout:** flag on for X% of tenants, deterministic by `crc32(key . professional_id) % 100`. Ramping 5% → 25% → 100% never removes a tenant who was previously enabled.
- **Per-tenant override:** force on or force off for a specific professional or brand regardless of rollout.

## Resolution model

Every flag check resolves in this order; first match wins:

1. **Brand override** — if `$brand` is passed, `feature_flag_overrides` row with `brand_id = $brand->id, flag_key = $key, expires_at IS NULL OR > now()`.
2. **Professional override** — same table, `professional_id = $pro->id`.
3. **Percentage rollout** — `feature_flags.rollout_percent` (0–100). Bucket = `crc32($key . $professional_id) % 100`. Enabled if `bucket < rollout_percent`. Requires `$pro` (rollout is per-professional, not per-brand).
4. **Global default** — `feature_flags.default_enabled`. Falls back to `config('partna.features.'.$key)` if no row exists (lets us register a flag in code before adding the DB row).

When `$pro` is null (anonymous context — public site, captcha middleware), steps 1–3 skip and only the global default applies.

## Storage schema

Two tables in the `core` schema. Raw SQL migration in `supabase/migrations/<ts>_create_feature_flags.sql`.

### `core.feature_flags`

| Column | Type | Notes |
|---|---|---|
| `key` | `text` PK | e.g. `video_uploads` |
| `description` | `text` | Human-readable purpose |
| `default_enabled` | `boolean` NOT NULL DEFAULT false | Value when no override and rollout doesn't match |
| `rollout_percent` | `smallint` NOT NULL DEFAULT 0 | 0–100, CHECK constraint |
| `created_at` / `updated_at` | `timestamptz` NOT NULL | |

### `core.feature_flag_overrides`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `flag_key` | `text` NOT NULL, FK → `feature_flags.key` ON DELETE CASCADE | |
| `professional_id` | `uuid` nullable, FK → `professionals.id` ON DELETE CASCADE | |
| `brand_id` | `uuid` nullable, FK → `brands.id` ON DELETE CASCADE | |
| `enabled` | `boolean` NOT NULL | |
| `reason` | `text` nullable | Audit note ("beta partner", "kill-switch incident #1234") |
| `expires_at` | `timestamptz` nullable | Resolver ignores expired rows |
| `created_by` | `uuid` nullable | Staff professional_id who set it |
| `created_at` / `updated_at` | `timestamptz` NOT NULL | |

**Constraints:**

- `CHECK ((professional_id IS NOT NULL) OR (brand_id IS NOT NULL))` — at least one scope set.
- `UNIQUE (flag_key, professional_id) WHERE brand_id IS NULL` — one pro-scope override per flag.
- `UNIQUE (flag_key, brand_id) WHERE brand_id IS NOT NULL` — one brand-scope override per flag.
- Index on `(professional_id, flag_key)` and `(brand_id, flag_key)` for resolver lookups.

**Why not a `scope` enum:** keeps the door open for adding `store_id` (per `project_stores_feature_plan`) without an enum migration. One nullable column + one index per new scope.

## Resolver service

**Class:** `App\Services\FeatureFlags\FeatureFlagService`

**Public API:**

```php
public function enabled(string $key, ?Professional $pro = null, ?Brand $brand = null): bool
public function allFor(?Professional $pro = null, ?Brand $brand = null): array  // ['key' => bool, ...]
public function setOverride(string $key, OverrideScope $scope, bool $enabled, ?string $reason, ?Carbon $expiresAt): void
public function clearOverride(string $key, OverrideScope $scope): void
```

`OverrideScope` is a small value object: `OverrideScope::forProfessional($id)` / `OverrideScope::forBrand($id)`. Keeps `setOverride` from growing nullable-param soup.

**Helper:** `feature(string $key, ?Professional $pro = null, ?Brand $brand = null): bool` — global helper autoloaded via `composer.json`'s `files` directive (`app/helpers.php`). Thin wrapper around `app(FeatureFlagService::class)->enabled(...)`. Used at all callsites.

## Caching

Hot-path correctness demands no DB roundtrip per flag check. Three Redis keys:

| Key | Contents | TTL | Invalidated when |
|---|---|---|---|
| `ff:registry` | All `feature_flags` rows as `[key => [default, rollout]]` | 5 min + SWR | Flag created / edited / deleted |
| `ff:pro:{professional_id}` | Non-expired pro-scope overrides for that pro, `[key => enabled]` | 5 min + SWR | Any override write for that pro |
| `ff:brand:{brand_id}` | Non-expired brand-scope overrides for that brand, `[key => enabled]` | 5 min + SWR | Any override write for that brand |

Resolver fetches all relevant keys in a single Redis `mget`, resolves in memory. **Hot path: 1 Redis roundtrip, 0 DB queries.**

TTL is a backstop. Writes push-invalidate via `Cache::forget` immediately. Pattern matches the commerce read-cache approach already documented in CLAUDE.md.

**Redis-down fallback:** resolver falls back to direct DB query and logs `feature_flags.cache_unavailable` to Nightwatch. Does NOT fail closed — wrong default during a Redis outage is recoverable (feature visible to wrong tenant); blocking every flag check would brown out the whole app. This differs from the Shopify session JWT path, which fails closed for security reasons.

**Expired overrides:** filtered at read time (`expires_at IS NULL OR expires_at > now()`). Hard-deleted nightly by `feature-flags:prune-expired` artisan command.

## Admin surface (staff-only)

Routes in `routes/api/staff.php`, gated by `FeatureFlagPolicy` (staff role). Registered in `AppServiceProvider::boot()` per CLAUDE.md policy convention.

| Method + path | Purpose |
|---|---|
| `GET /staff/feature-flags` | List all flags (registry + active override counts per flag) |
| `POST /staff/feature-flags` | Create a flag |
| `PATCH /staff/feature-flags/{key}` | Update description / default / rollout% |
| `DELETE /staff/feature-flags/{key}` | Delete flag (cascades overrides) |
| `GET /staff/feature-flags/{key}/overrides` | List overrides for a flag |
| `POST /staff/feature-flags/{key}/overrides` | Create override |
| `DELETE /staff/feature-flags/overrides/{id}` | Remove an override |

All responses use API Resources per project convention. All inputs use Form Requests with validation rules.

**Out of scope for v1 (explicit):**

- No frontend UI — staff hit the API via curl/Postman until there's a real need.
- No dedicated audit log table — `created_by` column + Nightwatch logs are sufficient.
- No flag dependencies / prerequisites — YAGNI.
- No string-valued variants (A/B testing) — booleans only. Add a `variant` column later if needed.
- No "preview flag state for tenant X" endpoint — derive client-side from `GET overrides` + the registry.

## Callsite migration

Current callsites split into three actions. See full list via `rg "config\('partna\.features\." app/`.

| Callsite group | Action |
|---|---|
| 10× `square_sync`, `fresha_sync`, `smart_booking` guards across `Observers/Core/ServiceObserver.php`, `Jobs/{Square,Fresha}/SyncCatalogDeltaJob.php`, `Controllers/Api/Webhooks/{Square,Fresha}CatalogWebhookController.php`, `Services/Professional/SectionVisibilityService.php`, `Http/Requests/.../StaffUpdateSiteRequest.php`, `Http/Requests/.../UpdateSiteRequest.php`, `Controllers/Api/Professional/SiteManagement/ProfessionalSiteController.php` | **Delete.** Booking/Square/Fresha dropped per `project_booking_dropped.md`. The flag guards are dead code on top of dead code. |
| 1× `captcha` in `Http/Middleware/VerifyTurnstileCaptcha.php` | **Leave on `config()`.** Global-only, anonymous context. Resolver provides no benefit. |
| Net-new: `video_uploads` (referenced in CLAUDE.md, not currently checked) | **Wire as the first real per-tenant flag** via `feature('video_uploads', $pro)` at the video-upload controller entry point. Proves the system end-to-end. |

## Testing

All Pest, under `tests/Feature/FeatureFlags/` unless noted.

| Test | Asserts |
|---|---|
| `ResolverPrecedenceTest` | Brand override > pro override > rollout > default. One test per precedence step. |
| `RolloutDeterminismTest` | Same `(key, professional_id)` always buckets identically. Ramping 25% → 50% never removes a tenant who was in at 25%. |
| `RolloutDistributionTest` | At 50% rollout across 1000 synthetic pro IDs, enabled count lands in 450–550. |
| `ExpiredOverrideTest` | Override with `expires_at < now()` is ignored. |
| `NullProTest` | `feature('captcha')` with no `$pro` returns global default; never throws. |
| `CacheInvalidationTest` | Writing an override forgets the relevant cache key; next `enabled()` reflects the change. |
| `RedisDownFallbackTest` (Unit) | Resolver falls back to DB when cache driver throws; logs warning. |
| `StaffFeatureFlagsControllerTest` | All 7 admin endpoints: auth gate, validation, happy path, 404s. |
| `PolicyCoverageTest` (existing sweep) | Auto-passes once `Gate::policy(FeatureFlag::class, FeatureFlagPolicy::class)` is registered. |
| `PruneExpiredCommandTest` | `feature-flags:prune-expired` deletes only expired rows. |

No browser tests — no UI in v1.

## Rollout plan

1. Push migration to dev Supabase (`glncumufgaqcmqhzwrxm`). Tables empty, no app code uses them — safe to land alone.
2. Deploy resolver + helper + policy + admin endpoints to dev. Verify with curl.
3. Single PR: delete the 10 booking-era flag guards, wire `feature('video_uploads', $pro)` into the video-upload controller, register `video_uploads` in `feature_flags` table (default off, no rollout).
4. Push migration to prod Supabase (`edplucmvkcnokyygxqsb`) with dry-run + explicit confirm per CLAUDE.md push semantics.
5. Deploy app code to prod.
6. Smoke test: create one override for own pro account, verify resolution, clear it.

**Backout:** `feature()` is grep-able. If the system misbehaves in prod, replace all calls with literal `false` (or revert the wiring PR). Tables are additive; no existing column is touched.

**Estimated effort:** 1–1.5 days focused.

## Open questions

None at design time. All scoping decisions resolved during brainstorming:

- Scope unit: both professional and brand (with brand winning precedence).
- Callsite API: global `feature()` helper.
- Admin UI: none in v1 (API only).
- Variants: booleans only in v1.
