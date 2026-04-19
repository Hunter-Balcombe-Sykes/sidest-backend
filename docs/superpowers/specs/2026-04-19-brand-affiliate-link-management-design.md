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

### Out of scope for v1

- Preview endpoint (`GET .../pending-commissions-summary`) — staff can call the existing commission list endpoint if needed.
- Bulk staff link operations.
- Restoring deleted links (to restore, POST again).
- Cross-linking two brand accounts (DB check-constraint + service-level guard reject).
- Changes to the `custom_photos_enabled` field.

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
 * Voids all pending (status='pending', payout_id=null) commission entries
 * between an affiliate and a brand. Uses existing voidEntry() optimistic
 * locking per entry, so concurrent sweeps are safe.
 *
 * @return array{count: int, total_cents: int}
 */
public function voidPendingForAffiliateBrand(
    string $affiliateProfessionalId,
    string $brandProfessionalId,
    string $reason,
): array;
```

### Controller changes

- **New** `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php` — two actions (`store`, `destroy`) delegating to the lifecycle service.
- `BrandAffiliateController::disconnect` — body replaced with a single lifecycle call using `DisconnectRequest::forBrand(...)`. The private `syncSiteBrandPartnerSettings` and `invalidateAffiliateCaches` helpers are extracted into a new `BrandPartnerSiteSettingsSync` service (`app/Services/Professional/BrandPartnerSiteSettingsSync.php`) so all three actor paths use identical logic. The `settingsStillReferenceBrand` helper moves with them.
- `BrandPartnerController::disconnect` — same refactor with `DisconnectRequest::forAffiliate(...)`. The stale-settings recovery path is preserved: the lifecycle service calls `BrandPartnerSiteSettingsSync` first; if the link is absent but settings still reference the brand, returns `stale_settings_cleaned: true` instead of 404.

---

## 4. Database Changes

One new table. No changes to `brand_partner_links`, `professionals`, or `commission_ledger_entries`.

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
        CHECK (event_type IN ('created', 'removed')),
    CONSTRAINT brand_partner_link_events_actor_type_check
        CHECK (actor_type IN ('staff', 'brand', 'affiliate')),
    CONSTRAINT brand_partner_link_events_staff_actor_check
        CHECK (
            (actor_type = 'staff' AND staff_user_id IS NOT NULL)
            OR (actor_type <> 'staff')
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
- **`staff_user_id uuid` without a FK** — staff users live in Supabase Auth (`auth.users`); this codebase does not cross-schema FK into `auth.users` (precedent: `StaffCommissionVoidController`).
- **Snapshot columns** (`pending_commission_*`, `slot_at_event`) — captured at event time. Intentional denormalization: the table exists to answer "what was true when this happened," so values are persisted rather than recomputed later.
- **Indexes** — three common query patterns: by brand, by affiliate, by pair (support conversations).

### Eloquent model

Path: `app/Models/Core/Professional/BrandPartnerLinkEvent.php`

Extends `BaseModel`, table `brand.brand_partner_link_events`, no relationships beyond the two professional FKs. Used read-only by the lifecycle service and future staff read-endpoints (not built in v1).

---

## 5. API Endpoints

### 5.1 Staff create

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

### 5.2 Staff remove

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

**Side effects on success:**
- Link row deleted; slots renormalized via `BrandPartnerLinkService::disconnectBrandFromAffiliate`
- If `on_pending_commissions === 'void'`: `CommissionVoidService::voidPendingForAffiliateBrand(..., reason: "link_removed_by_staff: <reason>")`
- `SelectionCleanupService::removeSelectionsForAffiliateBrand(...)` with "Brand connection removed" message
- Site settings sync for affiliate
- Professional caches invalidated for affiliate AND brand
- Audit row written with full snapshot (pending count, voided count, slot, reason)
- Notifications sent to BOTH brand and affiliate (see payloads in §6)

### 5.3 Brand remove (refactored)

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

**Side effects:** same as §5.2 minus the commission void step. Notification to **affiliate only** (brand already knows — they clicked the button). Audit row with `actor_type='brand'`, `actor_professional_id=brand.id`.

### 5.4 Affiliate self-remove (refactored)

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

**Side effects:** same as §5.3. Notification to **brand only**. Audit row with `actor_type='affiliate'`, `actor_professional_id=affiliate.id`.

---

## 6. Notifications

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

## 7. Edge Cases & Concurrency

1. **Simultaneous create + create (two staff members).** DB unique constraint `brand_partner_links_affiliate_brand_uq` rejects the loser. Lifecycle service catches `UniqueConstraintViolationException` and re-throws as the same "link already exists" RuntimeException that `connectBrandToAffiliate` already uses. 409 to client.

2. **Simultaneous remove + remove (two actors).** Existing `disconnectBrandFromAffiliate` uses `lockForUpdate` in a transaction. Second caller sees link missing, returns `false` → 404. No double-fire of notifications or audit. Acceptable.

3. **Remove while scheduled void sweep is running.** `voidPendingForAffiliateBrand` loops the existing per-entry optimistic lock in `voidEntry()`. Entries claimed by the sweep during the same moment return `false` and are skipped in the count. Returned `voided_commission_count` is the truthful count of what this call actually voided. No double-voiding.

4. **Affiliate disconnects when link is already gone but site settings still reference the brand.** Preserved stale-settings recovery path from `BrandPartnerController::disconnect`, relocated into the lifecycle service: when the link is absent and settings still reference the brand, the service syncs settings and returns `stale_settings_cleaned: true`.

5. **Staff creates link to a `deactivated` brand.** Allowed (explicit staff bypass). Affiliate's public site will not render the brand until the brand reactivates — this is correct and should be noted in the API docs.

6. **Staff removes a link for an affiliate who has deactivated their own account.** Allowed. If the professional is later hard-deleted, `actor_professional_id` FK sets NULL in the audit row; everything else is preserved.

7. **`SeedAffiliateDefaultSelectionsJob` still queued when link is removed.** Job is `afterCommit` on create. Its own guard queries the link row and exits cleanly if the link no longer exists. Already safe in current implementation — no change.

8. **Notification delivery failure.** Queued via `afterCommit`. If the worker cannot deliver, the disconnect has already succeeded. Acceptable — notifications are advisory.

---

## 8. Testing Strategy

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

**`tests/Feature/Staff/StaffBrandAffiliateLinkRemoveTest.php`** (~14 tests)
- Happy-path: removes link, writes audit, cleans selections, syncs settings, notifies both
- `on_pending_commissions: keep` → pending commissions untouched
- `on_pending_commissions: void` with reason ≥20 → voids all pending, returns counts
- `on_pending_commissions: void` with reason <20 → 422
- Slot renormalization: removing primary promotes next-slot to 0
- Concurrency: second DELETE returns 404
- Notification payloads include voided amount when applicable
- Unknown link → 404
- Requires `staff.admin` middleware

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

No E2E or Stripe integration tests — `voidEntry` is already covered and the bulk method is a loop over it.

---

## 9. Rollout Considerations

- Pre-beta, single-tenant Supabase instance. No feature flag needed.
- The two refactored endpoints preserve response shape, so the frontend does not need to ship alongside.
- Frontend staff UI for the new endpoints can ship in a follow-up — API is complete and testable without it.
- Add to `docs/api.md`: the two new staff endpoints, the new optional `reason` fields on the two existing disconnect endpoints, and the `BrandPartnerRemoved` notification type.
