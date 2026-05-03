# Brand–Affiliate Link Management — Design

**Date:** 2026-04-19
**Status:** Approved design, ready for implementation planning
**Scope:** Staff manual link create/remove + harmonization of brand and affiliate disconnect paths

---

## 1. Motivation

The invite flow (`BrandAffiliateInviteService`) is the only way brand–affiliate links get created today. When that flow fails (lost invite email, claim errors, platform migration), there is no way for staff to wire up a partnership directly.

Brand- and affiliate-initiated disconnects already exist (`BrandAffiliateController::disconnect`, `BrandPartnerController::disconnect`), but three gaps apply across all three removal paths:

1. **No handling of pending commissions.** Links sever cleanly, but nothing communicates or records what happened to money the affiliate had earned.
2. **No notifications.** The affected party finds out the next time they look at their dashboard.
3. **No audit trail.** We cannot reconstruct who removed a link, when, or why.

This design adds two new staff endpoints and harmonizes all three removal paths around a single lifecycle service that handles notifications, audit, and — for staff only — optional pending-commission voiding.

It also fixes a latent scoping bug in `SelectionCleanupService` that wipes all of an affiliate's product selections (regardless of brand) on disconnect — benign in the prior single-brand world, incorrect in the 4-slot model the schema already allows.

---

## 2. Scope

### New endpoints

| Method | Path | Actor | Auth middleware |
|---|---|---|---|
| `POST` | `/api/staff/professionals/{brand}/affiliates/{affiliate}` | staff | `staff.admin` |
| `DELETE` | `/api/staff/professionals/{brand}/affiliates/{affiliate}` | staff | `staff.admin` |

### Refactored endpoints (behavior expanded)

| Method | Path | Actor | Changes |
|---|---|---|---|
| `DELETE` | `/api/brand-affiliates/{affiliate}` | brand | Adds audit log, affiliate notification, optional `reason`. Backward-compatible response shape. |
| `DELETE` | `/api/brand-partners/{brandProfessionalId}` | affiliate | Adds audit log, brand notification, optional `reason`. Preserves existing stale-settings recovery path. Backward-compatible response shape. |

### Commission handling per actor

| Actor | Behavior | Rationale |
|---|---|---|
| Brand | Always `keep`. Link severs, pending commissions follow normal payout/void lifecycle. | Pending commissions are money already earned from completed orders. Brands cannot use disconnection as a mechanism to avoid payout liability. |
| Affiliate | Always `keep`. | Earned money still belongs to them; no reason to block disconnect on pending commissions. |
| Staff | Default `keep`. `void` option exists but is gated by `on_pending_commissions: "void"` + `reason` min length 20. | Staff uses `void` only for legitimate edge cases (migration off platform, confirmed fraud/dispute, brand account closure). Separate `POST /staff/commissions/{commission}/void` already exists for single-commission disputes. |

### Also in scope (corrective work uncovered during review)

- **Scope the selection cleanup to a single brand.** `SelectionCleanupService::removeSelectionsForAffiliateBrand` currently deletes every `affiliate_product_selections` row for the affiliate, ignoring the brand parameter. With up to 4 brand partners per affiliate, removing one brand must not delete selections owned by other brands. Fixed by adding a `brand_professional_id` column to `commerce.affiliate_product_selections` and scoping the delete.
- **Update `BrandPartnerController::connect` and `::promote`** to use the new `BrandPartnerSiteSettingsSync` service (they currently call the private helpers we are extracting).

### Out of scope for v1

- Preview endpoint (`GET .../pending-commissions-summary`) — staff can call the existing commission list endpoint if needed.
- Bulk staff link operations.
- Restoring deleted links (to restore, POST again).
- Cross-linking two brand accounts (DB check-constraint + service-level guard reject).
- Changes to the `custom_photos_enabled` field.
- Audit log retention / archival policy (unbounded growth acceptable pre-beta).

---

## 3. Service Architecture

### New service — `BrandPartnerLinkLifecycleService`

Path: `app/Services/Professional/BrandPartnerLinkLifecycleService.php`

Owns the full lifecycle for link create/remove across all three actors. Controllers delegate to this service; it composes existing primitives.

```php
class BrandPartnerLinkLifecycleService
{
    public function __construct(
        private BrandPartnerLinkService $linkService,
        private SelectionCleanupService $selectionCleanup,
        private CommissionVoidService $commissionVoid,
        private BrandPartnerLinkNotifier $notifier,
        private BrandPartnerLinkAuditor $auditor,
        private ProfessionalCacheService $cache,
    ) {}

    /**
     * Staff-only create. Throws RuntimeException on guard failures so the
     * controller can translate to 422/409 responses.
     */
    public function createForStaff(
        Professional $brand,
        Professional $affiliate,
        string $reason,
        string $staffUserId,
    ): BrandPartnerLink;

    /**
     * Remove a link with explicit actor context. Returns a summary of
     * side-effects for the HTTP response.
     */
    public function disconnect(DisconnectRequest $req): DisconnectResult;
}

final class DisconnectRequest
{
    public function __construct(
        public Professional $brand,
        public Professional $affiliate,
        public DisconnectActor $actor,          // Staff | Brand | Affiliate
        public ?string $reason,                 // Required for Staff, optional for Brand/Affiliate
        public CommissionHandling $commissions, // Keep | Void. Void only allowed when actor=Staff.
        public ?string $staffUserId,            // Required when actor=Staff, null otherwise.
    ) {}

    public static function forStaff(...): self;
    public static function forBrand(...): self;
    public static function forAffiliate(...): self;
}

final class DisconnectResult
{
    public function __construct(
        public bool $disconnected,
        public int $voidedCommissionCount,
        public int $voidedCommissionCents,
        public int $selectionsRemoved,
        public bool $staleSettingsCleaned = false,
    ) {}
}
```

The `DisconnectActor` and `CommissionHandling` enums live alongside the service under `app/Services/Professional/Enums/`.

### Supporting collaborators

Two thin helpers to keep the lifecycle service focused:

- `app/Services/Professional/BrandPartnerLinkNotifier.php` — inserts rows into `notifications.notifications` using the existing schema. Single responsibility: format + persist.
- `app/Services/Professional/BrandPartnerLinkAuditor.php` — inserts rows into `brand.brand_partner_link_events`. Single responsibility: persist audit snapshots.
- `app/Services/Professional/BrandPartnerSiteSettingsSync.php` — owns `syncSiteBrandPartnerSettings`, `invalidateAffiliateCaches`, and `settingsStillReferenceBrand` (all previously private helpers in `BrandPartnerController`). Single responsibility: keep the affiliate's `site.settings.brand_partner` + `additional_brand_partners` consistent with `brand_partner_links` state, and invalidate caches after a change.

### Existing services — changes

- `BrandPartnerLinkService` — **no changes**. Remains the DB-level primitive layer (`connectBrandToAffiliate`, `disconnectBrandFromAffiliate`, `promoteBrandToPrimary`, slot normalization).
- `CommissionVoidService` — **gains one public method**:

```php
/**
 * Voids up to $cap pending (status='pending', payout_id=null) commission
 * entries between an affiliate and a brand. Uses existing voidEntry()
 * optimistic locking per entry, so concurrent sweeps are safe.
 *
 * If the count of pending entries exceeds $cap, returns ['overflow' => true]
 * without voiding any entries — caller is expected to dispatch
 * VoidPendingCommissionsForLinkJob for async processing.
 *
 * @return array{count: int, total_cents: int, overflow: bool}
 */
public function voidPendingForAffiliateBrand(
    string $affiliateProfessionalId,
    string $brandProfessionalId,
    string $reason,
    int $cap = 200,
): array;
```

**Sync cap + async fallback:** if `pending_count > cap` (200), the method returns `['overflow' => true]` and voids nothing inline. The lifecycle service then dispatches a new job (`app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php`) that loops `voidEntry()` in batches on the queue, and the HTTP response returns 202 Accepted with a `void_job_id`. For the common pre-beta case (few pending commissions per affiliate-brand pair), the sync path handles everything in-request. The cap prevents a single staff click from holding hundreds of row locks or blowing the HTTP timeout.

### New job — `VoidPendingCommissionsForLinkJob`

Path: `app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php`

```php
class VoidPendingCommissionsForLinkJob implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly string $affiliateProfessionalId,
        public readonly string $brandProfessionalId,
        public readonly string $reason,
    ) {}

    public function handle(CommissionVoidService $voidService): void;
}
```

Loops `voidEntry()` in chunks of 50, each chunk in its own short transaction. Emits a final notification to affiliate (and brand if staff-initiated) with the total voided amount when the job completes. Idempotent — if the job is retried mid-flight, already-voided entries are skipped by the optimistic lock in `voidEntry()`.

### Controller changes

- **New** `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php` — two actions (`store`, `destroy`) delegating to the lifecycle service.
- `BrandAffiliateController::disconnect` — body replaced with a single lifecycle call using `DisconnectRequest::forBrand(...)`. The private `syncSiteBrandPartnerSettings` and `invalidateAffiliateCaches` helpers are extracted into a new `BrandPartnerSiteSettingsSync` service (`app/Services/Professional/BrandPartnerSiteSettingsSync.php`) so all three actor paths use identical logic. The `settingsStillReferenceBrand` helper moves with them.
- `BrandPartnerController::disconnect` — same refactor with `DisconnectRequest::forAffiliate(...)`. The stale-settings recovery path is preserved: the lifecycle service calls `BrandPartnerSiteSettingsSync` first; if the link is absent but settings still reference the brand, returns `stale_settings_cleaned: true` instead of 404.
- **`BrandPartnerController::connect`** — update to call `BrandPartnerSiteSettingsSync` (currently invokes the private helpers directly). Connection flow behavior unchanged; only the source of the helper changes. (This is essential: if we extract without updating this method, it calls deleted privates and the connect endpoint breaks.)
- **`BrandPartnerController::promote`** — same extraction update as `connect`.

---

## 4. Database Changes

One new table, one existing table gets a new column. No changes to `brand_partner_links`, `professionals`, or `commission_ledger_entries`.

### Migration

Path: `supabase/migrations/<timestamp>_add_brand_partner_link_events.sql`

```sql
CREATE TABLE IF NOT EXISTS brand.brand_partner_link_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,

    event_type text NOT NULL,  -- 'created' | 'removed'
    actor_type text NOT NULL,  -- 'staff' | 'brand' | 'affiliate'
    actor_professional_id uuid REFERENCES core.professionals(id) ON DELETE SET NULL,
    staff_user_id uuid,

    slot_at_event smallint,
    pending_commission_count integer,
    pending_commission_cents bigint,
    commissions_voided_count integer DEFAULT 0,
    commissions_voided_cents bigint DEFAULT 0,

    reason text,

    created_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT brand_partner_link_events_event_type_check
        CHECK (event_type IN ('created', 'removed', 'commissions_voided_async')),
    CONSTRAINT brand_partner_link_events_actor_type_check
        CHECK (actor_type IN ('staff', 'brand', 'affiliate')),
    CONSTRAINT brand_partner_link_events_staff_actor_check
        CHECK (
            (actor_type = 'staff' AND staff_user_id IS NOT NULL)
            OR (actor_type <> 'staff')
        ),
    CONSTRAINT brand_partner_link_events_professional_actor_check
        CHECK (
            actor_type = 'staff'
            OR actor_professional_id IS NOT NULL
        ),
    CONSTRAINT brand_partner_link_events_slot_range
        CHECK (slot_at_event IS NULL OR slot_at_event BETWEEN 0 AND 3)
);

CREATE INDEX brand_partner_link_events_brand_idx
    ON brand.brand_partner_link_events (brand_professional_id, created_at DESC);
CREATE INDEX brand_partner_link_events_affiliate_idx
    ON brand.brand_partner_link_events (affiliate_professional_id, created_at DESC);
CREATE INDEX brand_partner_link_events_pair_idx
    ON brand.brand_partner_link_events (affiliate_professional_id, brand_professional_id, created_at DESC);
```

### Schema decisions

- **No FK to `brand_partner_links`** — an event for a removal must outlive the link it described.
- **`ON DELETE RESTRICT` on professional FKs** — audit history is never silently wiped by a cascaded professional delete.
- **`staff_user_id uuid` stores `core.sidest_staff.id`.** The `EnsureSidestStaff` middleware (`app/Http/Middleware/Auth/EnsureSidestStaff.php`) resolves `SidestStaff` by `auth_user_id = supabase_uid` and attaches the model via `$request->attributes->set('sidest_staff', $staff)`. Staff controllers read it with `$request->attributes->get('sidest_staff')` and the lifecycle service receives `$staff->id` as `staffUserId`. We intentionally store the internal `sidest_staff.id` (the canonical record of who was staff at the time of the action) rather than the raw Supabase UID, and do not FK into `core.sidest_staff` because audit rows must outlive staff deactivations. No precedent exists yet — `StaffCommissionVoidController` records no staff identifier; this spec establishes the pattern.
- **Snapshot columns** (`pending_commission_*`, `slot_at_event`) — captured at event time. Intentional denormalization: the table exists to answer "what was true when this happened," so values are persisted rather than recomputed later.
- **Indexes** — three common query patterns: by brand, by affiliate, by pair (support conversations).
- **Service-level integrity assertion** — the DB constraints above cover the simple "staff must have staff_user_id / non-staff must have actor_professional_id" shape, but cannot express the stricter rule that `actor_type='brand' ⇒ actor_professional_id = brand_professional_id` and `actor_type='affiliate' ⇒ actor_professional_id = affiliate_professional_id`. `BrandPartnerLinkAuditor` enforces this in PHP before insert and throws `LogicException` on violation. Unit test covers all six combinations (valid + invalid per actor type).

### Eloquent model

Path: `app/Models/Core/Professional/BrandPartnerLinkEvent.php`

Extends `BaseModel`, table `brand.brand_partner_link_events`, no relationships beyond the two professional FKs. Used read-only by the lifecycle service and future staff read-endpoints (not built in v1).

### Existing table change — `commerce.affiliate_product_selections`

Second migration: `supabase/migrations/<timestamp+1>_add_brand_professional_id_to_affiliate_product_selections.sql`

```sql
ALTER TABLE commerce.affiliate_product_selections
    ADD COLUMN brand_professional_id uuid
        REFERENCES core.professionals(id) ON DELETE CASCADE;

-- Backfill from brand_partner_links. Pre-beta, each affiliate has at most
-- one brand link, so the join is unambiguous. If an affiliate has multiple
-- links, we populate using the primary-slot brand (slot=0); other-slot
-- selections are a data anomaly that would need manual resolution, but
-- no such rows exist as of 2026-04-19.
UPDATE commerce.affiliate_product_selections s
SET brand_professional_id = l.brand_professional_id
FROM brand.brand_partner_links l
WHERE l.affiliate_professional_id = s.affiliate_professional_id
  AND l.slot = 0
  AND s.brand_professional_id IS NULL;

-- Any remaining NULLs (selections without a matching link — orphans from
-- prior disconnects) are deleted to keep the data clean.
DELETE FROM commerce.affiliate_product_selections
WHERE brand_professional_id IS NULL;

ALTER TABLE commerce.affiliate_product_selections
    ALTER COLUMN brand_professional_id SET NOT NULL;

CREATE INDEX affiliate_product_selections_brand_idx
    ON commerce.affiliate_product_selections (affiliate_professional_id, brand_professional_id);
```

**Rationale:** the cleanup service needs to scope deletes by brand. Selections have no brand pointer today, which is why `removeSelectionsForAffiliateBrand` ignores its `$brandProfessionalId` parameter and wipes everything. Persisting the brand on each selection is the clean fix.

**Corresponding code changes:**
- `AffiliateProductSelection::$fillable` — add `brand_professional_id`
- `AffiliateProductCatalogService::seedDefaultSelections` — persist `brand_professional_id` (it already has the brand ID from the caller)
- `AffiliateProductController::store` — require `brand_professional_id` in request body; validate that the caller has an active `brand_partner_links` row for the pair before inserting
- `AffiliateProductController::resetToDefaults` — accept optional `brand_professional_id`; if provided, scope the reset to that brand only; if omitted, reset across all linked brands
- `SelectionCleanupService::removeSelectionsForAffiliateBrand` — add `->where('brand_professional_id', $brandProfessionalId)` to the delete query

**Coverage in tests:** new Pest test `tests/Feature/Professional/AffiliateProductSelectionScopedCleanupTest.php` — affiliate linked to brands A and B, with selections from both, disconnects brand A → only A's selections deleted, B's retained.

---

## 5. Transaction Boundaries & Ordering

The lifecycle service wraps the critical path in a single DB transaction. Each side-effect is explicitly placed either inside the transaction (rolls back with the link change) or after commit (durable even if a later step fails).

### Create (staff) — ordered steps

Inside `DB::transaction`:
1. Re-read guards under lock (professional statuses, existing link, slot availability).
2. Insert `brand_partner_links` row (delegates to `BrandPartnerLinkService::connectBrandToAffiliate` which already uses `lockForUpdate`).
3. Insert `brand_partner_link_events` audit row (`event_type='created'`).
4. Sync `site.settings` via `BrandPartnerSiteSettingsSync` (in-transaction so settings mirror link state atomically).

After commit:
5. Dispatch `SeedAffiliateDefaultSelectionsJob` (existing `afterCommit` behavior preserved).
6. Invalidate affiliate professional cache via `ProfessionalCacheService`.

No notifications on create.

### Remove (any actor) — ordered steps

Inside `DB::transaction`:
1. Re-read link + pending commission snapshot under lock. This snapshot (`pending_commission_count`, `pending_commission_cents`) feeds both the audit row and the HTTP response.
2. Decide commission handling:
   - If actor=Brand, actor=Affiliate, or actor=Staff with `commissionHandling=Keep` → no voiding.
   - If actor=Staff with `commissionHandling=Void` AND pending count ≤ 200 → call `voidPendingForAffiliateBrand()` inline. Record the returned count/cents for the audit row and response.
   - If actor=Staff with `commissionHandling=Void` AND pending count > 200 → skip inline voiding. Mark the in-memory result as `voidedAsync=true`. The audit row for this request is written with `pending_commission_*` set and `commissions_voided_*` zeroed (the async job will write its own follow-up audit row with `event_type='commissions_voided_async'` when it finishes).
3. Delete the link row + renormalize slots (`BrandPartnerLinkService::disconnectBrandFromAffiliate`).
4. Scoped selection cleanup: `SelectionCleanupService::removeSelectionsForAffiliateBrand($affiliate, $brand, title, body)` (now correctly scoped per §4).
5. Sync `site.settings` via `BrandPartnerSiteSettingsSync`. Preserves stale-settings recovery path — if step 3 found the link absent but settings still reference the brand, proceed with just the sync and flag `staleSettingsCleaned=true` in the result.
6. Insert `brand_partner_link_events` audit row with full snapshot.

After commit (each step uses `DB::afterCommit` or the equivalent in the listener):
7. If `voidedAsync=true`: dispatch `VoidPendingCommissionsForLinkJob`. (Dispatching inside the transaction is safe because it's an `afterCommit` callback — the job only enqueues once the disconnect is durable. This avoids a race where the worker picks up the job before the link delete is visible.)
8. Dispatch notifications to the correct recipients (affiliate / brand / both, per actor). Persistence failure does not fail the request.
9. Invalidate affiliate professional cache (and brand cache for staff-initiated removals).

### Failure modes

- **Any in-transaction step throws:** full rollback. No audit row, no link change, no notification. Client sees 5xx.
- **After-commit step throws:** logged via Nightwatch, but HTTP request is already 2xx. Recovery: notifications are advisory; cache will naturally refresh on next read; seed job has its own retry/guard logic.

The `event_type='commissions_voided_async'` value is already allowed by the main migration's CHECK constraint (§4). A secondary audit row with this type is inserted by `VoidPendingCommissionsForLinkJob` when the async path completes, linked to the original `removed` event by the shared `(affiliate_professional_id, brand_professional_id)` pair and a close `created_at` timestamp.

---

## 6. API Endpoints

**Rate limiting (applies to all four endpoints in this section):** Laravel `throttle:30,1` middleware — 30 requests per minute per authenticated user. Rationale: none of these endpoints is called in rapid succession during normal usage; a staff member might create 10–20 links during an onboarding session, and brand/affiliate removals are single-click user actions. A 30/min cap limits the blast radius of a compromised session or a runaway script without constraining legitimate flows. Apply at the route group level in `routes/api/staff.php` (new group) and on the two existing professional routes being refactored.

### 6.1 Staff create

```http
POST /api/staff/professionals/{brand}/affiliates/{affiliate}
Content-Type: application/json

{
  "reason": "Lost invite email — manual recovery for <affiliate email>"
}
```

**Route declaration** (in `routes/api/staff.php`, under the `staff.admin` middleware group):

```php
Route::post('/professionals/{brand}/affiliates/{affiliate}',
    [StaffBrandAffiliateLinkController::class, 'store'])
    ->whereUuid(['brand', 'affiliate']);
```

**Form Request — `StaffCreateBrandAffiliateLinkRequest`:**
- `reason` — required, string, min:10, max:500

**Guards (422 on failure, with a single message body):**
- `brand->professional_type === 'brand'`
- `affiliate->professional_type !== 'brand'`
- Neither professional has `status === 'deactivated'`
- Affiliate has a free slot among 0–3
- No existing link for the pair (explicit check + DB unique index as backstop)

**Explicitly not enforced (staff bypass):**
- `brand->status === 'active'`
- `brand_profile.brand_status !== 'deactivated'`

**Happy-path response (201):**

```json
{
  "success": true,
  "data": {
    "link": {
      "id": "uuid",
      "brand_professional_id": "uuid",
      "affiliate_professional_id": "uuid",
      "slot": 2,
      "created_at": "2026-04-19T17:42:11Z"
    }
  }
}
```

**Errors:**
- `409` when link already exists: `{ "error": "Link already exists.", "link_id": "uuid", "slot": n }`
- `422` for any guard failure, single message body

**Side effects on success:**
- Row inserted in `brand.brand_partner_links`
- `SeedAffiliateDefaultSelectionsJob` dispatched (existing `connectBrandToAffiliate` behavior)
- Audit row written: `event_type='created'`, `actor_type='staff'`, `staff_user_id`, `slot_at_event`, `reason`
- Site settings sync for affiliate's site
- Professional caches invalidated for the affiliate
- **No notifications on create** (per notification policy below)

### 6.2 Staff remove

```http
DELETE /api/staff/professionals/{brand}/affiliates/{affiliate}
Content-Type: application/json

{
  "reason": "Brand account closure — affiliate notified via email 2026-04-18",
  "on_pending_commissions": "keep"
}
```

**Form Request — `StaffRemoveBrandAffiliateLinkRequest`:**
- `reason` — required, string, min:10, max:500
- `on_pending_commissions` — required, `in:keep,void`
- When `on_pending_commissions === 'void'`: `reason` min becomes 20 (enforced via `withValidator` hook)

**Guards:**
- Link must exist for the pair → 404

**Response (200):**

```json
{
  "success": true,
  "data": {
    "disconnected": true,
    "voided_commission_count": 0,
    "voided_commission_cents": 0,
    "selections_removed": 7
  }
}
```

`voided_commission_*` are non-zero only when `on_pending_commissions: "void"` was specified.

**Async response (202)** — when `on_pending_commissions: "void"` AND pending commission count exceeds the sync cap (200):

```json
{
  "success": true,
  "data": {
    "disconnected": true,
    "voided_commission_count": 0,
    "voided_commission_cents": 0,
    "voided_async": true,
    "void_job_dispatched_at": "2026-04-19T17:42:11Z",
    "pending_commission_count": 412,
    "pending_commission_cents": 61800,
    "selections_removed": 7
  }
}
```

The link and selections are already severed. The queued `VoidPendingCommissionsForLinkJob` processes the voiding over ~1–2 minutes; a follow-up notification is sent to the affiliate (and brand) when it completes, with final counts. Staff UI can poll `GET /api/staff/professionals/{brand}/affiliates/{affiliate}/link-events` (not built in v1) to observe progress, or just wait for the completion notification.

**Side effects on success:**
- Link row deleted; slots renormalized via `BrandPartnerLinkService::disconnectBrandFromAffiliate`
- If `on_pending_commissions === 'void'`: `CommissionVoidService::voidPendingForAffiliateBrand(..., reason: "link_removed_by_staff: <reason>")`
- `SelectionCleanupService::removeSelectionsForAffiliateBrand(...)` with "Brand connection removed" message
- Site settings sync for affiliate
- Professional caches invalidated for affiliate AND brand
- Audit row written with full snapshot (pending count, voided count, slot, reason)
- Notifications sent to BOTH brand and affiliate (see payloads in §7)

### 6.3 Brand remove (refactored)

```http
DELETE /api/brand-affiliates/{affiliate}
Content-Type: application/json

{
  "reason": "optional — e.g. 'not a fit'"
}
```

**Form Request (new):** `BrandDisconnectAffiliateRequest`
- `reason` — nullable, string, max:500

**Guards (unchanged):**
- Caller's `professional_type === 'brand'` → 403 if not
- Link must exist for caller+affiliate → 404

**Commission handling:** always `keep` (not exposed as a parameter).

**Response (backward-compatible, new `selections_removed` field):**

```json
{
  "success": true,
  "data": {
    "disconnected": true,
    "affiliate_id": "uuid",
    "selections_removed": 7
  }
}
```

**Side effects:** same as §6.2 minus the commission void step. Notification to **affiliate only** (brand already knows — they clicked the button). Audit row with `actor_type='brand'`, `actor_professional_id=brand.id`.

### 6.4 Affiliate self-remove (refactored)

```http
DELETE /api/brand-partners/{brandProfessionalId}
Content-Type: application/json

{
  "reason": "optional"
}
```

**Form Request (new):** `AffiliateDisconnectBrandRequest`
- `reason` — nullable, string, max:500

**Guards (unchanged):**
- Caller's `professional_type !== 'brand'` → 403 if it is
- Caller's site must exist → 404
- Link must exist → 404 (with the existing stale-settings recovery path preserved)

**Commission handling:** always `keep`.

**Response (backward-compatible, new `selections_removed` field):**

```json
{
  "success": true,
  "data": {
    "disconnected": true,
    "brand_professional_id": "uuid",
    "selections_removed": 3
  }
}
```

**Stale-settings recovery response** (when the link is absent but site settings still reference the brand — preserved behavior):

```json
{
  "success": true,
  "data": {
    "disconnected": true,
    "brand_professional_id": "uuid",
    "stale_settings_cleaned": true
  }
}
```

**Side effects:** same as §6.3. Notification to **brand only**. Audit row with `actor_type='affiliate'`, `actor_professional_id=affiliate.id`.

---

## 7. Notifications

Written to `notifications.notifications` table (existing pattern from `BrandAffiliateInviteService`).

**Policy:** notifications fire **only on removal**, never on creation. Creations are preceded by out-of-band context (phone/email) so the parties already know; removals are the surprising event and deserve in-app communication.

**To affiliate — removal by staff or brand:**

```
title:    "Your partnership with {brand display_name} has ended"
body:     (no voided commissions)
          "You are no longer linked to {brand display_name}."
          (with voided commissions)
          "You are no longer linked to {brand display_name}. ${X.XX} in pending commissions was voided."
cta_url:  "/dashboard/brand-partners"
type:     "BrandPartnerRemoved"
severity: "warning" when commissions voided, else "info"
```

**To brand — removal by staff or affiliate:**

```
title:    "{affiliate display_name} has ended your partnership"
body:     "They are no longer linked to your brand."
cta_url:  "/dashboard/affiliates"
type:     "BrandPartnerRemoved"
severity: "info"
```

**Frontend type registration:** `BrandPartnerRemoved` must be added to `Notification::severityForFrontendType()`. One-liner change.

**Delivery:** notifications are persisted via `afterCommit` so they only fire if the lifecycle transaction succeeded. Failure to persist a notification does not fail the request — notifications are advisory, matching existing invite-notification behavior.

---

## 8. Edge Cases & Concurrency

1. **Simultaneous create + create (two staff members).** DB unique constraint `brand_partner_links_affiliate_brand_uq` rejects the loser. Lifecycle service catches `UniqueConstraintViolationException` and re-throws as the same "link already exists" RuntimeException that `connectBrandToAffiliate` already uses. 409 to client.

2. **Simultaneous remove + remove (two actors).** Existing `disconnectBrandFromAffiliate` uses `lockForUpdate` in a transaction. Second caller sees link missing, returns `false` → 404. No double-fire of notifications or audit. Acceptable.

3. **Remove while scheduled void sweep is running.** `voidPendingForAffiliateBrand` loops the existing per-entry optimistic lock in `voidEntry()`. Entries claimed by the sweep during the same moment return `false` and are skipped in the count. Returned `voided_commission_count` is the truthful count of what this call actually voided. No double-voiding.

4. **Affiliate disconnects when link is already gone but site settings still reference the brand.** Preserved stale-settings recovery path from `BrandPartnerController::disconnect`, relocated into the lifecycle service: when the link is absent and settings still reference the brand, the service syncs settings and returns `stale_settings_cleaned: true`.

5. **Staff creates link to a `deactivated` brand.** Allowed (explicit staff bypass). Affiliate's public site will not render the brand until the brand reactivates — this is correct and should be noted in the API docs.

6. **Staff removes a link for an affiliate who has deactivated their own account.** Allowed. If the professional is later hard-deleted, `actor_professional_id` FK sets NULL in the audit row; everything else is preserved.

7. **`SeedAffiliateDefaultSelectionsJob` still queued when link is removed.** Job is `afterCommit` on create. Its own guard queries the link row and exits cleanly if the link no longer exists. Already safe in current implementation — no change.

8. **Notification delivery failure.** Queued via `afterCommit`. If the worker cannot deliver, the disconnect has already succeeded. Acceptable — notifications are advisory.

---

## 9. Testing Strategy

### Pest feature tests

**`tests/Feature/Staff/StaffBrandAffiliateLinkCreateTest.php`** (~12 tests)
- Happy-path: creates link, returns 201, writes audit, dispatches seed job, syncs site settings
- Rejects when brand is not `professional_type='brand'`
- Rejects when affiliate IS `professional_type='brand'`
- Rejects when affiliate already has 4 brand partners
- Rejects when link already exists (409)
- Rejects when reason < 10 chars (422)
- Allows creation when `brand.status='paused'` (bypass test)
- Allows creation when `brand_profile.brand_status='deactivated'` (bypass test)
- Rejects when either professional is `deactivated`
- Requires `staff.admin` middleware

**`tests/Feature/Staff/StaffBrandAffiliateLinkRemoveTest.php`** (~16 tests)
- Happy-path: removes link, writes audit, cleans selections (scoped to this brand only), syncs settings, notifies both
- `on_pending_commissions: keep` → pending commissions untouched
- `on_pending_commissions: void` with reason ≥20 AND count ≤200 → voids all pending inline, returns counts
- `on_pending_commissions: void` with count >200 → returns 202, dispatches `VoidPendingCommissionsForLinkJob`, link already severed
- `on_pending_commissions: void` with reason <20 → 422
- Slot renormalization: removing primary promotes next-slot to 0
- Concurrency: second DELETE returns 404
- Notification payloads include voided amount when applicable (sync path)
- Unknown link → 404
- Requires `staff.admin` middleware
- Rate limit `throttle:30,1` rejects 31st request in a minute with 429

**`tests/Feature/Professional/BrandAffiliateDisconnectTest.php`** (refactor existing; ~8 tests)
- Notification sent to affiliate on brand-initiated disconnect
- Audit row written with `actor_type='brand'`
- Pending commissions untouched (keep)
- Selections cleaned, settings synced
- `reason` persisted to audit when provided
- Backward-compatible response shape

**`tests/Feature/Professional/BrandPartnerDisconnectTest.php`** (refactor existing; ~8 tests)
- Notification sent to brand on affiliate-initiated disconnect
- Audit row written with `actor_type='affiliate'`
- Pending commissions untouched (keep)
- Stale-settings recovery path still works
- Backward-compatible response shape

### Unit tests

**`tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php`**
- Orchestration with mocked dependencies; verifies each side effect (link DB op, selection cleanup, settings sync, notification, audit, cache invalidation) is called exactly once per success path
- Transaction rollback when a mid-flight step throws — no partial state, no audit row, no notification
- `CommissionHandling::Void` rejected when actor is not Staff

**`tests/Unit/Services/CommissionVoidServiceBulkVoidTest.php`**
- New `voidPendingForAffiliateBrand` method: sums correctly, skips already-voided entries, returns accurate counts, preserves per-entry optimistic lock behavior
- Returns `overflow: true` without voiding anything when pending count > cap

**`tests/Unit/Services/BrandPartnerLinkAuditorTest.php`**
- Rejects `actor_type='brand'` with `actor_professional_id != brand_professional_id` (throws `LogicException`)
- Rejects `actor_type='affiliate'` with `actor_professional_id != affiliate_professional_id` (throws `LogicException`)
- Accepts valid actor + professional combinations for all three actor types
- `actor_type='staff'` accepts null `actor_professional_id` with a non-null `staff_user_id`

**`tests/Feature/Professional/AffiliateProductSelectionScopedCleanupTest.php`** (~4 tests)
- Affiliate linked to brands A and B with selections from both; disconnect A → A's selections deleted, B's retained
- Affiliate with selections only for the brand being disconnected → all deleted
- `POST /affiliate/selections` with `brand_professional_id` that affiliate is not linked to → 422
- Backfill migration dry-run sanity test (covers the baseline `UPDATE … FROM brand_partner_links` path)

**`tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php`** (~3 tests)
- Processes commissions in chunks, idempotent on retry
- Writes a follow-up audit row with `event_type='commissions_voided_async'` on completion
- Sends completion notification to affiliate and brand

No E2E or Stripe integration tests — `voidEntry` is already covered and the bulk path is a loop over it.

---

## 10. Rollout Considerations

- Pre-beta, single-tenant Supabase instance. No feature flag needed.
- **Migration order matters.** Run `add_brand_partner_link_events.sql` first (independent), then `add_brand_professional_id_to_affiliate_product_selections.sql` (depends on `brand_partner_links` which already exists). The backfill step in the second migration must run inside the same transaction as the column add + NOT NULL set so no concurrent writes can slip in with null.
- The two refactored endpoints preserve response shape (new optional field `selections_removed` is additive). Frontend can ship independently.
- **The `AffiliateProductController::store` change is mildly breaking** — it now requires `brand_professional_id` in the request body. Frontend must update alongside this backend change. Coordinate with Tobias before merging. (The `resetToDefaults` change is backward-compatible: the parameter is optional.)
- Frontend staff UI for the new endpoints can ship in a follow-up — API is complete and testable without it.
- Add to `docs/api.md`: the two new staff endpoints, the new optional `reason` fields on the existing disconnect endpoints, the new required `brand_professional_id` field on `POST /affiliate/selections`, and the `BrandPartnerRemoved` notification type.
- **Post-deploy verification:** check Nightwatch for (a) exceptions in the backfill migration, (b) UniqueConstraintViolation spikes indicating client retry storms, (c) slow-query reports on the new audit table's indexes. Any `LogicException` from `BrandPartnerLinkAuditor` is an in-house bug and should page.
