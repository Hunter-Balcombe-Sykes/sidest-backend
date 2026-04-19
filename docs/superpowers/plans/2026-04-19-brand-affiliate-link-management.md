# Brand–Affiliate Link Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship two new staff endpoints (create/remove brand-affiliate links) and harmonize all three removal paths (staff, brand, affiliate) around a shared lifecycle service, audit log, notifications, and correctly scoped product-selection cleanup.

**Architecture:** New `BrandPartnerLinkLifecycleService` orchestrates the full create/disconnect flow; thin controllers delegate to it. Audit log in new `brand.brand_partner_link_events` table. `affiliate_product_selections` gains a `brand_professional_id` column so cleanup can scope per-brand. Overflow pending-commission voiding goes async via a new queued job.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL via Supabase migrations, Pest 4 (with `DB::shouldReceive()` + `Mockery::mock()` patterns per existing staff tests), Redis-backed Horizon for the async job.

**Spec:** `docs/superpowers/specs/2026-04-19-brand-affiliate-link-management-design.md`

---

## Key Codebase Facts

- **Migration filename format:** `YYYYMMDDHHMMSS_<snake_case_name>.sql` under `supabase/migrations/`. Latest timestamp as of 2026-04-19: `20260419180000`.
- **No Laravel migrations.** Composer guard (`guard:no-laravel-migrations`) rejects them. All DDL goes in `supabase/migrations/` as raw SQL.
- **Base model:** All models extend `App\Models\BaseModel` which forces `pgsql` connection.
- **Schema prefixes on tables:** Eloquent `$table = 'brand.brand_partner_links'` — the dot-qualified name is how cross-schema tables are declared.
- **Staff middleware exposes staff:** `EnsureSidestStaff` sets `$request->attributes->set('sidest_staff', $staff)`. Read with `$request->attributes->get('sidest_staff')`. `$staff->id` is a UUID string.
- **Staff auth groups in `routes/api/staff.php`:** two groups — one under `staff` middleware (read/list), one under `staff.admin` (mutations). All new endpoints in this plan go under `staff.admin`.
- **Route binding:** `->whereUuid('professional')` on Route::*; `Professional` resolves via implicit model binding. For two params, use `->whereUuid(['brand', 'affiliate'])`.
- **ApiController helpers:**
  - `$this->success($data = null, int $status = 200): JsonResponse` — returns `response()->json($data, $status)` directly, no wrapper object.
  - `$this->error(string $message, int $status = 400, array $errors = []): JsonResponse`
  - `$this->paginated($paginator, string $dataKey = 'data'): JsonResponse`
- **Staff controller style:** inline `$request->validate([...])`, not Form Request classes. See `StaffCommissionVoidController`, `StaffAffiliateStatusController`. Use inline validation in this plan too, including the conditional reason-length rule in Task 16.
- **Notification row shape** (from `BrandAffiliateInviteService::notifyExistingEmailRecipientsBatch`, line 642):
  ```php
  [
      'professional_id' => $professional->id,
      'type' => 'BrandPartnerRemoved',
      'title' => '...',
      'body' => '...',
      'cta_url' => '/...',
      'primary_action_label' => null,
      'secondary_action_label' => null,
      'secondary_action_url' => null,
      'severity' => Notification::severityForFrontendType('BrandPartnerRemoved'),
      'starts_at' => $now,
      'ends_at' => null,
      'created_at' => $now,
      'updated_at' => $now,
  ]
  ```
- **Queued job standard:** implement `ShouldQueue`, use `Dispatchable, InteractsWithQueue, Queueable, SerializesModels`, set `public int $tries = 3;`. No explicit queue connection (uses default).
- **Test pattern:** direct controller instantiation + `Request::create(...)` + `Mockery::mock(ServiceClass::class)` injected via constructor. No `actingAs()` — middleware is not invoked in unit-style feature tests.
- **`BrandPartnerLinkService::PRIMARY_SLOT` = 0, `MAX_ADDITIONAL_PARTNERS` = 3.** 4 slots total (0, 1, 2, 3).
- **`CommissionVoidService::voidEntry(CommissionLedgerEntry $entry, string $reason): bool`** — returns `false` if already claimed by concurrent sweep.

---

## File Map

**New migrations:**
- `supabase/migrations/20260420000000_add_brand_partner_link_events.sql`
- `supabase/migrations/20260420000100_add_brand_professional_id_to_affiliate_product_selections.sql`

**New models:**
- `app/Models/Core/Professional/BrandPartnerLinkEvent.php`

**New enums / DTOs:**
- `app/Services/Professional/Enums/DisconnectActor.php`
- `app/Services/Professional/Enums/CommissionHandling.php`
- `app/Services/Professional/DTO/DisconnectRequest.php`
- `app/Services/Professional/DTO/DisconnectResult.php`

**New services:**
- `app/Services/Professional/BrandPartnerSiteSettingsSync.php`
- `app/Services/Professional/BrandPartnerLinkAuditor.php`
- `app/Services/Professional/BrandPartnerLinkNotifier.php`
- `app/Services/Professional/BrandPartnerLinkLifecycleService.php`

**New job:**
- `app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php`

**New controller:**
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php`

**Modified files:**
- `app/Models/Commerce/AffiliateProductSelection.php` — add `brand_professional_id` to fillable
- `app/Models/Core/Notifications/Notification.php` — register `'BrandPartnerRemoved'` frontend type
- `app/Services/Store/AffiliateProductCatalogService.php` — persist brand on seed
- `app/Services/Store/SelectionCleanupService.php` — scope delete by brand
- `app/Services/Stripe/CommissionVoidService.php` — new `voidPendingForAffiliateBrand()` method
- `app/Http/Controllers/Api/Professional/AffiliateProductController.php` — require `brand_professional_id` on store, accept optional on resetToDefaults
- `app/Http/Controllers/Api/Professional/BrandPartnerController.php` — refactor connect/promote/disconnect to use new services; delete private helpers
- `app/Http/Controllers/Api/Professional/BrandAffiliateController.php` — refactor disconnect to use lifecycle
- `routes/api/staff.php` — add 2 new staff routes + throttle
- `routes/api/professional.php` — apply throttle to refactored disconnect routes
- `docs/api.md` — document new endpoints and changes

**New tests:**
- `tests/Feature/Staff/StaffBrandAffiliateLinkCreateTest.php`
- `tests/Feature/Staff/StaffBrandAffiliateLinkRemoveTest.php`
- `tests/Feature/Professional/BrandAffiliateDisconnectTest.php` (new or refactored)
- `tests/Feature/Professional/BrandPartnerDisconnectTest.php` (new or refactored)
- `tests/Feature/Professional/AffiliateProductSelectionScopedCleanupTest.php`
- `tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php`
- `tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php`
- `tests/Unit/Services/BrandPartnerLinkAuditorTest.php`
- `tests/Unit/Services/BrandPartnerSiteSettingsSyncTest.php`
- `tests/Unit/Services/CommissionVoidServiceBulkVoidTest.php`

---

## Task 1: Migration — `brand_partner_link_events` table

**Files:**
- Create: `supabase/migrations/20260420000000_add_brand_partner_link_events.sql`

- [ ] **Step 1: Write the migration SQL**

Create `supabase/migrations/20260420000000_add_brand_partner_link_events.sql`:

```sql
-- Audit log for brand-affiliate link lifecycle events (create / remove).
-- Rows in this table must outlive the brand_partner_links rows they describe,
-- so we FK to professionals instead and restrict cascade deletes.
CREATE TABLE IF NOT EXISTS brand.brand_partner_link_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,

    event_type text NOT NULL,
    actor_type text NOT NULL,
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

- [ ] **Step 2: Apply migration to Supabase dev branch**

Run via the Supabase MCP or CLI:

```
mcp__claude_ai_Supabase__apply_migration
  project_id: <dev project id>
  name: add_brand_partner_link_events
  query: <the SQL above>
```

Or via local CLI:

```bash
supabase db push
```

Expected: migration applied cleanly, no errors. The table now exists.

- [ ] **Step 3: Verify table + indexes exist**

```sql
SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'brand' AND table_name = 'brand_partner_link_events'
ORDER BY ordinal_position;
```

Expected: 13 columns, matching the schema above.

```sql
SELECT indexname FROM pg_indexes
WHERE schemaname = 'brand' AND tablename = 'brand_partner_link_events';
```

Expected: 4 indexes (PK + 3 named indexes).

- [ ] **Step 4: Commit**

```bash
git add supabase/migrations/20260420000000_add_brand_partner_link_events.sql
git commit -m "feat(db): add brand.brand_partner_link_events audit table"
```

---

## Task 2: Migration — add `brand_professional_id` to `affiliate_product_selections`

**Files:**
- Create: `supabase/migrations/20260420000100_add_brand_professional_id_to_affiliate_product_selections.sql`
- Modify: `app/Models/Commerce/AffiliateProductSelection.php`

- [ ] **Step 1: Write the migration SQL**

Create `supabase/migrations/20260420000100_add_brand_professional_id_to_affiliate_product_selections.sql`:

```sql
-- Selections are now scoped per brand so disconnecting one brand doesn't wipe
-- selections belonging to other brand partners of the same affiliate.
ALTER TABLE commerce.affiliate_product_selections
    ADD COLUMN brand_professional_id uuid
        REFERENCES core.professionals(id) ON DELETE CASCADE;

-- Backfill from brand_partner_links. Pre-beta, each affiliate has at most
-- one brand link; the primary-slot (slot=0) brand is unambiguous.
UPDATE commerce.affiliate_product_selections s
SET brand_professional_id = l.brand_professional_id
FROM brand.brand_partner_links l
WHERE l.affiliate_professional_id = s.affiliate_professional_id
  AND l.slot = 0
  AND s.brand_professional_id IS NULL;

-- Any remaining NULLs are orphans from prior disconnects (selections whose
-- brand no longer links to this affiliate) and are removed.
DELETE FROM commerce.affiliate_product_selections
WHERE brand_professional_id IS NULL;

ALTER TABLE commerce.affiliate_product_selections
    ALTER COLUMN brand_professional_id SET NOT NULL;

CREATE INDEX affiliate_product_selections_brand_idx
    ON commerce.affiliate_product_selections (affiliate_professional_id, brand_professional_id);
```

- [ ] **Step 2: Apply migration**

Run via Supabase MCP or CLI as in Task 1.

- [ ] **Step 3: Verify column and NOT NULL + index**

```sql
SELECT column_name, is_nullable, data_type
FROM information_schema.columns
WHERE table_schema = 'commerce'
  AND table_name = 'affiliate_product_selections'
  AND column_name = 'brand_professional_id';
```

Expected: one row, `is_nullable = 'NO'`, `data_type = 'uuid'`.

- [ ] **Step 4: Update `AffiliateProductSelection` model fillable**

Modify `app/Models/Commerce/AffiliateProductSelection.php`:

```php
protected $fillable = [
    'affiliate_professional_id',
    'brand_professional_id',
    'shopify_product_gid',
    'sort_order',
];
```

Add a relationship:

```php
public function brandProfessional(): BelongsTo
{
    return $this->belongsTo(Professional::class, 'brand_professional_id');
}
```

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20260420000100_add_brand_professional_id_to_affiliate_product_selections.sql \
        app/Models/Commerce/AffiliateProductSelection.php
git commit -m "feat(db): scope affiliate product selections to a brand"
```

---

## Task 3: `BrandPartnerLinkEvent` Eloquent model

**Files:**
- Create: `app/Models/Core/Professional/BrandPartnerLinkEvent.php`

- [ ] **Step 1: Write the model**

Create `app/Models/Core/Professional/BrandPartnerLinkEvent.php`:

```php
<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only audit log for brand-affiliate link lifecycle events.
// Never mutate existing rows — insert a new event row instead.
class BrandPartnerLinkEvent extends BaseModel
{
    use HasUuids;

    protected $table = 'brand.brand_partner_link_events';

    public $timestamps = false; // only created_at; default via DB.

    protected $fillable = [
        'brand_professional_id',
        'affiliate_professional_id',
        'event_type',
        'actor_type',
        'actor_professional_id',
        'staff_user_id',
        'slot_at_event',
        'pending_commission_count',
        'pending_commission_cents',
        'commissions_voided_count',
        'commissions_voided_cents',
        'reason',
    ];

    protected $casts = [
        'slot_at_event' => 'integer',
        'pending_commission_count' => 'integer',
        'pending_commission_cents' => 'integer',
        'commissions_voided_count' => 'integer',
        'commissions_voided_cents' => 'integer',
        'created_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }

    public function actorProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'actor_professional_id');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/Core/Professional/BrandPartnerLinkEvent.php
git commit -m "feat(models): add BrandPartnerLinkEvent"
```

---

## Task 4: Enums and DTOs

**Files:**
- Create: `app/Services/Professional/Enums/DisconnectActor.php`
- Create: `app/Services/Professional/Enums/CommissionHandling.php`
- Create: `app/Services/Professional/DTO/DisconnectRequest.php`
- Create: `app/Services/Professional/DTO/DisconnectResult.php`

- [ ] **Step 1: Create the actor enum**

Create `app/Services/Professional/Enums/DisconnectActor.php`:

```php
<?php

namespace App\Services\Professional\Enums;

// Who initiated a brand-affiliate link disconnect.
// Staff may void pending commissions; Brand and Affiliate always keep.
enum DisconnectActor: string
{
    case Staff = 'staff';
    case Brand = 'brand';
    case Affiliate = 'affiliate';
}
```

- [ ] **Step 2: Create the commission handling enum**

Create `app/Services/Professional/Enums/CommissionHandling.php`:

```php
<?php

namespace App\Services\Professional\Enums;

// How pending commissions are handled on disconnect.
// Keep: leave them in the ledger to follow normal payout/void lifecycle.
// Void: void them immediately with the disconnect reason. Staff-only.
enum CommissionHandling: string
{
    case Keep = 'keep';
    case Void = 'void';
}
```

- [ ] **Step 3: Create the DisconnectRequest DTO**

Create `app/Services/Professional/DTO/DisconnectRequest.php`:

```php
<?php

namespace App\Services\Professional\DTO;

use App\Models\Core\Professional\Professional;
use App\Services\Professional\Enums\CommissionHandling;
use App\Services\Professional\Enums\DisconnectActor;
use LogicException;

// Input to BrandPartnerLinkLifecycleService::disconnect.
// Static factories enforce actor-specific invariants at construction.
final class DisconnectRequest
{
    public function __construct(
        public readonly Professional $brand,
        public readonly Professional $affiliate,
        public readonly DisconnectActor $actor,
        public readonly ?string $reason,
        public readonly CommissionHandling $commissions,
        public readonly ?string $staffUserId,
    ) {
        if ($actor === DisconnectActor::Staff && $staffUserId === null) {
            throw new LogicException('Staff disconnect requires a staff user id.');
        }
        if ($actor !== DisconnectActor::Staff && $commissions === CommissionHandling::Void) {
            throw new LogicException('Only staff may void pending commissions on disconnect.');
        }
    }

    public static function forStaff(
        Professional $brand,
        Professional $affiliate,
        string $reason,
        CommissionHandling $commissions,
        string $staffUserId,
    ): self {
        return new self($brand, $affiliate, DisconnectActor::Staff, $reason, $commissions, $staffUserId);
    }

    public static function forBrand(
        Professional $brand,
        Professional $affiliate,
        ?string $reason,
    ): self {
        return new self($brand, $affiliate, DisconnectActor::Brand, $reason, CommissionHandling::Keep, null);
    }

    public static function forAffiliate(
        Professional $brand,
        Professional $affiliate,
        ?string $reason,
    ): self {
        return new self($brand, $affiliate, DisconnectActor::Affiliate, $reason, CommissionHandling::Keep, null);
    }
}
```

- [ ] **Step 4: Create the DisconnectResult DTO**

Create `app/Services/Professional/DTO/DisconnectResult.php`:

```php
<?php

namespace App\Services\Professional\DTO;

// Summary of side-effects from a disconnect, shaped for HTTP responses.
final class DisconnectResult
{
    public function __construct(
        public readonly bool $disconnected,
        public readonly int $voidedCommissionCount,
        public readonly int $voidedCommissionCents,
        public readonly int $selectionsRemoved,
        public readonly int $pendingCommissionCount = 0,
        public readonly int $pendingCommissionCents = 0,
        public readonly bool $voidedAsync = false,
        public readonly bool $staleSettingsCleaned = false,
    ) {}
}
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/Enums/ app/Services/Professional/DTO/
git commit -m "feat(services): add disconnect enums and DTOs"
```

---

## Task 5: Extract `BrandPartnerSiteSettingsSync` service

Extract the private helpers from `BrandPartnerController` into a standalone service so all three actor paths can use identical logic. Do NOT delete the private methods yet — the refactor of their callers happens in Task 17.

**Files:**
- Create: `app/Services/Professional/BrandPartnerSiteSettingsSync.php`
- Create: `tests/Unit/Services/BrandPartnerSiteSettingsSyncTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/BrandPartnerSiteSettingsSyncTest.php`:

```php
<?php

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\BrandPartnerSiteSettingsSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

it('sets primary brand and additional brands in site settings from links', function () {
    $site = new Site(['settings' => []]);
    $site->setRawAttributes(['id' => (string) Str::uuid(), 'settings' => []], true);

    $links = collect([
        (new BrandPartnerLink(['brand_professional_id' => 'brand-A', 'slot' => 0])),
        (new BrandPartnerLink(['brand_professional_id' => 'brand-B', 'slot' => 1])),
    ]);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('getLinksForAffiliate')->andReturn($links);

    $cache = Mockery::mock(ProfessionalCacheService::class);

    $sync = new BrandPartnerSiteSettingsSync($linkService, $cache);

    // Don't actually save the site — just inspect the mutated settings.
    $sync->syncWithoutPersist($site, 'affiliate-id');

    expect($site->settings['brand_partner']['professional_id'])->toBe('brand-A');
    expect($site->settings['additional_brand_partners'])->toHaveCount(1);
    expect($site->settings['additional_brand_partners'][0]['professional_id'])->toBe('brand-B');
});

it('detects when site settings still reference a brand', function () {
    $site = new Site(['settings' => [
        'brand_partner' => ['professional_id' => 'brand-X'],
        'additional_brand_partners' => [],
    ]]);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $cache = Mockery::mock(ProfessionalCacheService::class);

    $sync = new BrandPartnerSiteSettingsSync($linkService, $cache);

    expect($sync->settingsStillReferenceBrand($site, 'brand-X'))->toBeTrue();
    expect($sync->settingsStillReferenceBrand($site, 'brand-Y'))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
php artisan test tests/Unit/Services/BrandPartnerSiteSettingsSyncTest.php
```

Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/Professional/BrandPartnerSiteSettingsSync.php`:

```php
<?php

namespace App\Services\Professional;

use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;

// Keeps site.settings.brand_partner and .additional_brand_partners in sync
// with the affiliate's current brand_partner_links, and invalidates
// professional caches. Extracted from BrandPartnerController so all three
// disconnect paths (staff, brand, affiliate) use identical logic.
class BrandPartnerSiteSettingsSync
{
    public function __construct(
        private readonly BrandPartnerLinkService $links,
        private readonly ProfessionalCacheService $cache,
    ) {}

    /**
     * Rebuild brand_partner settings on the site and save if changed.
     * Returns true if settings were mutated (and saved).
     */
    public function sync(Site $site, string $affiliateProfessionalId): bool
    {
        $changed = $this->syncWithoutPersist($site, $affiliateProfessionalId);
        if ($changed) {
            $site->save();
        }
        return $changed;
    }

    /**
     * Mutate in-memory settings without persisting. Used by tests and by
     * the lifecycle service which persists within a transaction boundary.
     */
    public function syncWithoutPersist(Site $site, string $affiliateProfessionalId): bool
    {
        $links = $this->links->getLinksForAffiliate($affiliateProfessionalId);
        $settings = is_array($site->settings) ? $site->settings : [];
        $original = $settings;

        $brandPartner = is_array($settings['brand_partner'] ?? null)
            ? $settings['brand_partner']
            : [];

        $primary = $links->firstWhere('slot', BrandPartnerLinkService::PRIMARY_SLOT);
        if ($primary) {
            $brandPartner['professional_id'] = (string) $primary->brand_professional_id;
        } else {
            unset($brandPartner['professional_id'], $brandPartner['professionalId']);
        }

        $settings['brand_partner'] = $brandPartner;
        $settings['additional_brand_partners'] = $links
            ->filter(static fn ($l): bool => (int) $l->slot > BrandPartnerLinkService::PRIMARY_SLOT)
            ->sortBy('slot')
            ->map(static fn ($l): array => ['professional_id' => (string) $l->brand_professional_id])
            ->values()
            ->all();

        if ($settings === $original) {
            return false;
        }

        $site->settings = $settings;
        return true;
    }

    public function settingsStillReferenceBrand(Site $site, string $brandProfessionalId): bool
    {
        $settings = is_array($site->settings) ? $site->settings : [];
        $primaryId = trim((string) (
            $settings['brand_partner']['professional_id']
            ?? $settings['brand_partner']['professionalId']
            ?? ''
        ));
        if ($primaryId === $brandProfessionalId) {
            return true;
        }

        $additional = $settings['additional_brand_partners'] ?? $settings['additionalBrandPartners'] ?? [];
        if (! is_array($additional)) {
            return false;
        }

        foreach ($additional as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $entryId = trim((string) ($entry['professional_id'] ?? $entry['professionalId'] ?? ''));
            if ($entryId === $brandProfessionalId) {
                return true;
            }
        }

        return false;
    }

    /** Invalidate affiliate professional cache. */
    public function invalidateAffiliateCaches(Site $site): void
    {
        $site->loadMissing('professional');
        $professional = $site->professional;
        if (! $professional) {
            return;
        }
        $this->cache->invalidateProfessional($professional);
    }
}
```

- [ ] **Step 4: Run the test to confirm it passes**

```bash
php artisan test tests/Unit/Services/BrandPartnerSiteSettingsSyncTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/BrandPartnerSiteSettingsSync.php \
        tests/Unit/Services/BrandPartnerSiteSettingsSyncTest.php
git commit -m "feat(services): extract BrandPartnerSiteSettingsSync"
```

---

## Task 6: `BrandPartnerLinkAuditor` service

**Files:**
- Create: `app/Services/Professional/BrandPartnerLinkAuditor.php`
- Create: `tests/Unit/Services/BrandPartnerLinkAuditorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/BrandPartnerLinkAuditorTest.php`:

```php
<?php

use App\Models\Core\Professional\BrandPartnerLinkEvent;
use App\Services\Professional\BrandPartnerLinkAuditor;
use App\Services\Professional\Enums\DisconnectActor;
use Illuminate\Support\Str;

it('rejects brand actor with mismatched actor_professional_id', function () {
    $auditor = new BrandPartnerLinkAuditor();
    $brand = (string) Str::uuid();
    $affiliate = (string) Str::uuid();
    $wrong = (string) Str::uuid();

    expect(fn () => $auditor->recordRemoval(
        brandProfessionalId: $brand,
        affiliateProfessionalId: $affiliate,
        actor: DisconnectActor::Brand,
        actorProfessionalId: $wrong,
        staffUserId: null,
        slotAtEvent: 0,
        pendingCount: 0,
        pendingCents: 0,
        voidedCount: 0,
        voidedCents: 0,
        reason: null,
    ))->toThrow(LogicException::class, 'actor_professional_id must match brand');
});

it('rejects affiliate actor with mismatched actor_professional_id', function () {
    $auditor = new BrandPartnerLinkAuditor();
    $brand = (string) Str::uuid();
    $affiliate = (string) Str::uuid();
    $wrong = (string) Str::uuid();

    expect(fn () => $auditor->recordRemoval(
        brandProfessionalId: $brand,
        affiliateProfessionalId: $affiliate,
        actor: DisconnectActor::Affiliate,
        actorProfessionalId: $wrong,
        staffUserId: null,
        slotAtEvent: 0,
        pendingCount: 0,
        pendingCents: 0,
        voidedCount: 0,
        voidedCents: 0,
        reason: null,
    ))->toThrow(LogicException::class, 'actor_professional_id must match affiliate');
});

it('rejects staff actor with null staff_user_id', function () {
    $auditor = new BrandPartnerLinkAuditor();
    $brand = (string) Str::uuid();
    $affiliate = (string) Str::uuid();

    expect(fn () => $auditor->recordRemoval(
        brandProfessionalId: $brand,
        affiliateProfessionalId: $affiliate,
        actor: DisconnectActor::Staff,
        actorProfessionalId: null,
        staffUserId: null,
        slotAtEvent: 0,
        pendingCount: 0,
        pendingCents: 0,
        voidedCount: 0,
        voidedCents: 0,
        reason: 'x',
    ))->toThrow(LogicException::class, 'staff_user_id required');
});
```

- [ ] **Step 2: Run test, expect failure**

```bash
php artisan test tests/Unit/Services/BrandPartnerLinkAuditorTest.php
```

Expected: FAIL — `BrandPartnerLinkAuditor` does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/Professional/BrandPartnerLinkAuditor.php`:

```php
<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\BrandPartnerLinkEvent;
use App\Services\Professional\Enums\DisconnectActor;
use LogicException;

// Inserts rows into brand.brand_partner_link_events after validating the
// actor-specific invariants that cannot be expressed as a DB CHECK:
// - brand actor's actor_professional_id must equal brand_professional_id
// - affiliate actor's actor_professional_id must equal affiliate_professional_id
// - staff actor requires a non-null staff_user_id
class BrandPartnerLinkAuditor
{
    public function recordCreation(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        string $staffUserId,
        int $slotAtEvent,
        string $reason,
    ): BrandPartnerLinkEvent {
        if (trim($staffUserId) === '') {
            throw new LogicException('staff_user_id required for created event');
        }

        return BrandPartnerLinkEvent::query()->create([
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'event_type' => 'created',
            'actor_type' => DisconnectActor::Staff->value,
            'actor_professional_id' => null,
            'staff_user_id' => $staffUserId,
            'slot_at_event' => $slotAtEvent,
            'pending_commission_count' => null,
            'pending_commission_cents' => null,
            'commissions_voided_count' => 0,
            'commissions_voided_cents' => 0,
            'reason' => $reason,
        ]);
    }

    public function recordRemoval(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        DisconnectActor $actor,
        ?string $actorProfessionalId,
        ?string $staffUserId,
        int $slotAtEvent,
        int $pendingCount,
        int $pendingCents,
        int $voidedCount,
        int $voidedCents,
        ?string $reason,
    ): BrandPartnerLinkEvent {
        $this->assertActorInvariants($brandProfessionalId, $affiliateProfessionalId, $actor, $actorProfessionalId, $staffUserId);

        return BrandPartnerLinkEvent::query()->create([
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'event_type' => 'removed',
            'actor_type' => $actor->value,
            'actor_professional_id' => $actorProfessionalId,
            'staff_user_id' => $staffUserId,
            'slot_at_event' => $slotAtEvent,
            'pending_commission_count' => $pendingCount,
            'pending_commission_cents' => $pendingCents,
            'commissions_voided_count' => $voidedCount,
            'commissions_voided_cents' => $voidedCents,
            'reason' => $reason,
        ]);
    }

    /** Recorded by the async void job when it finishes processing overflow. */
    public function recordAsyncVoidCompletion(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        int $voidedCount,
        int $voidedCents,
        string $reason,
    ): BrandPartnerLinkEvent {
        return BrandPartnerLinkEvent::query()->create([
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'event_type' => 'commissions_voided_async',
            'actor_type' => DisconnectActor::Staff->value,
            'actor_professional_id' => null,
            'staff_user_id' => null, // follow-up row; original 'removed' row carries the staff id
            'slot_at_event' => null,
            'pending_commission_count' => null,
            'pending_commission_cents' => null,
            'commissions_voided_count' => $voidedCount,
            'commissions_voided_cents' => $voidedCents,
            'reason' => $reason,
        ]);
    }

    private function assertActorInvariants(
        string $brandId,
        string $affiliateId,
        DisconnectActor $actor,
        ?string $actorProfessionalId,
        ?string $staffUserId,
    ): void {
        match ($actor) {
            DisconnectActor::Staff => (function () use ($staffUserId): void {
                if ($staffUserId === null || trim($staffUserId) === '') {
                    throw new LogicException('staff_user_id required for staff actor');
                }
            })(),
            DisconnectActor::Brand => (function () use ($brandId, $actorProfessionalId): void {
                if ($actorProfessionalId !== $brandId) {
                    throw new LogicException('actor_professional_id must match brand_professional_id for brand actor');
                }
            })(),
            DisconnectActor::Affiliate => (function () use ($affiliateId, $actorProfessionalId): void {
                if ($actorProfessionalId !== $affiliateId) {
                    throw new LogicException('actor_professional_id must match affiliate_professional_id for affiliate actor');
                }
            })(),
        };
    }
}
```

Note the `LogicException` messages include the word "staff_user_id", "brand", or "affiliate" respectively — the test assertions check for these substrings.

- [ ] **Step 4: Run test, expect pass**

```bash
php artisan test tests/Unit/Services/BrandPartnerLinkAuditorTest.php
```

Expected: PASS (all 3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/BrandPartnerLinkAuditor.php \
        tests/Unit/Services/BrandPartnerLinkAuditorTest.php
git commit -m "feat(services): add BrandPartnerLinkAuditor with actor assertions"
```

---

## Task 7: `BrandPartnerLinkNotifier` service + register `BrandPartnerRemoved` type

**Files:**
- Create: `app/Services/Professional/BrandPartnerLinkNotifier.php`
- Modify: `app/Models/Core/Notifications/Notification.php`

- [ ] **Step 1: Register the new frontend type**

Modify `app/Models/Core/Notifications/Notification.php:124-130`. The existing `severityForFrontendType` match expression is:

```php
return match (self::normalizeFrontendType($value)) {
    'Critical' => 'critical',
    'Warning' => 'warning',
    'To do' => 'warning',
    'Success', 'Info', 'Invitation' => 'info',
    default => 'info',
};
```

Change it to:

```php
return match (self::normalizeFrontendType($value)) {
    'Critical' => 'critical',
    'Warning', 'BrandPartnerRemoved' => 'warning',
    'To do' => 'warning',
    'Success', 'Info', 'Invitation' => 'info',
    default => 'info',
};
```

Note: we default `BrandPartnerRemoved` to `warning`. The notifier below will downgrade to `info` when no commissions were voided, using `Notification::severityForFrontendType()` with a fallback.

- [ ] **Step 2: Write the notifier**

Create `app/Services/Professional/BrandPartnerLinkNotifier.php`:

```php
<?php

namespace App\Services\Professional;

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Carbon;

// Inserts rows into notifications.notifications for brand-affiliate link
// removals. Called from the lifecycle service after the disconnect
// transaction commits (failure to persist a notification does not fail
// the disconnect — notifications are advisory).
class BrandPartnerLinkNotifier
{
    /** Notify the affiliate that a link has ended. */
    public function notifyAffiliateOfRemoval(
        Professional $affiliate,
        Professional $brand,
        int $voidedCents,
    ): void {
        $brandName = $this->displayName($brand);
        $severity = $voidedCents > 0 ? 'warning' : 'info';
        $body = $voidedCents > 0
            ? sprintf(
                'You are no longer linked to %s. $%s in pending commissions was voided.',
                $brandName,
                number_format($voidedCents / 100, 2),
            )
            : sprintf('You are no longer linked to %s.', $brandName);

        $this->insert($affiliate->id, [
            'title' => sprintf('Your partnership with %s has ended', $brandName),
            'body' => $body,
            'cta_url' => '/dashboard/brand-partners',
            'severity' => $severity,
        ]);
    }

    /** Notify the brand that an affiliate link has ended. */
    public function notifyBrandOfRemoval(
        Professional $brand,
        Professional $affiliate,
    ): void {
        $affiliateName = $this->displayName($affiliate);

        $this->insert($brand->id, [
            'title' => sprintf('%s has ended your partnership', $affiliateName),
            'body' => 'They are no longer linked to your brand.',
            'cta_url' => '/dashboard/affiliates',
            'severity' => 'info',
        ]);
    }

    private function insert(string $professionalId, array $attrs): void
    {
        $now = Carbon::now();

        Notification::query()->create([
            'professional_id' => $professionalId,
            'type' => 'BrandPartnerRemoved',
            'title' => $attrs['title'],
            'body' => $attrs['body'],
            'cta_url' => $attrs['cta_url'],
            'primary_action_label' => null,
            'secondary_action_label' => null,
            'secondary_action_url' => null,
            'severity' => $attrs['severity'],
            'starts_at' => $now,
            'ends_at' => null,
        ]);
    }

    private function displayName(Professional $p): string
    {
        $name = trim(implode(' ', array_filter([$p->first_name, $p->last_name])));
        if ($name !== '') {
            return $name;
        }
        return (string) ($p->display_name ?? $p->handle ?? 'Partner');
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Professional/BrandPartnerLinkNotifier.php \
        app/Models/Core/Notifications/Notification.php
git commit -m "feat(services): add BrandPartnerLinkNotifier"
```

---

## Task 8: `CommissionVoidService::voidPendingForAffiliateBrand` (capped)

**Files:**
- Modify: `app/Services/Stripe/CommissionVoidService.php`
- Create: `tests/Unit/Services/CommissionVoidServiceBulkVoidTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/CommissionVoidServiceBulkVoidTest.php`:

```php
<?php

use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

// Note: the bulk void method is a loop over the existing voidEntry()
// method. These tests focus on the cap + count logic, not on voidEntry
// internals (those are covered elsewhere).

it('returns overflow: true without voiding when count exceeds cap', function () {
    // Build a query result fake that reports 250 pending entries.
    $queryMock = Mockery::mock();
    $queryMock->shouldReceive('where')->andReturnSelf();
    $queryMock->shouldReceive('whereNull')->andReturnSelf();
    $queryMock->shouldReceive('count')->andReturn(250);

    DB::shouldReceive('table')->with('commerce.commission_ledger_entries')->andReturn($queryMock);

    $svc = Mockery::mock(CommissionVoidService::class)->makePartial();

    $result = $svc->voidPendingForAffiliateBrand(
        (string) Str::uuid(),
        (string) Str::uuid(),
        'reason',
        cap: 200,
    );

    expect($result['overflow'])->toBeTrue();
    expect($result['count'])->toBe(0);
    expect($result['total_cents'])->toBe(0);
});
```

- [ ] **Step 2: Run test, expect failure**

```bash
php artisan test tests/Unit/Services/CommissionVoidServiceBulkVoidTest.php
```

Expected: FAIL — method does not exist.

- [ ] **Step 3: Add the method**

In `app/Services/Stripe/CommissionVoidService.php`, add:

```php
use Illuminate\Support\Facades\DB;

/**
 * Voids up to $cap pending (status='pending', payout_id=null) commission
 * entries for a specific affiliate-brand pair. Uses existing voidEntry()
 * optimistic locking per entry.
 *
 * Returns ['overflow' => true, 'count' => 0, 'total_cents' => 0] without
 * voiding anything if the count of pending entries exceeds $cap — the
 * caller is expected to dispatch VoidPendingCommissionsForLinkJob instead.
 *
 * @return array{count: int, total_cents: int, overflow: bool}
 */
public function voidPendingForAffiliateBrand(
    string $affiliateProfessionalId,
    string $brandProfessionalId,
    string $reason,
    int $cap = 200,
): array {
    $pendingCount = DB::table('commerce.commission_ledger_entries')
        ->where('affiliate_professional_id', $affiliateProfessionalId)
        ->where('brand_professional_id', $brandProfessionalId)
        ->where('status', 'pending')
        ->whereNull('payout_id')
        ->count();

    if ($pendingCount > $cap) {
        return ['count' => 0, 'total_cents' => 0, 'overflow' => true];
    }

    return $this->runVoidLoop($affiliateProfessionalId, $brandProfessionalId, $reason);
}

/** Loops voidEntry() over every pending entry for the pair. */
public function runVoidLoop(
    string $affiliateProfessionalId,
    string $brandProfessionalId,
    string $reason,
): array {
    $voidedCount = 0;
    $voidedCents = 0;

    CommissionLedgerEntry::query()
        ->where('affiliate_professional_id', $affiliateProfessionalId)
        ->where('brand_professional_id', $brandProfessionalId)
        ->where('status', 'pending')
        ->whereNull('payout_id')
        ->orderBy('occurred_at')
        ->chunkById(50, function ($entries) use (&$voidedCount, &$voidedCents, $reason): void {
            foreach ($entries as $entry) {
                if ($this->voidEntry($entry, $reason)) {
                    $voidedCount++;
                    $voidedCents += (int) $entry->amount_cents;
                }
            }
        });

    return ['count' => $voidedCount, 'total_cents' => $voidedCents, 'overflow' => false];
}
```

- [ ] **Step 4: Run test, expect pass**

```bash
php artisan test tests/Unit/Services/CommissionVoidServiceBulkVoidTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Stripe/CommissionVoidService.php \
        tests/Unit/Services/CommissionVoidServiceBulkVoidTest.php
git commit -m "feat(stripe): add capped voidPendingForAffiliateBrand helper"
```

---

## Task 9: `VoidPendingCommissionsForLinkJob`

**Files:**
- Create: `app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php`
- Create: `tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php`:

```php
<?php
/** @phpstan-ignore-all */

use App\Jobs\Stripe\VoidPendingCommissionsForLinkJob;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandPartnerLinkAuditor;
use App\Services\Professional\BrandPartnerLinkNotifier;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Str;

it('runs the void loop, writes audit completion row, and notifies both parties', function () {
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'display_name' => 'Affi']);
    $brand = new Professional(['id' => (string) Str::uuid(), 'display_name' => 'Brand']);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $voidService->shouldReceive('runVoidLoop')
        ->once()
        ->with($affiliate->id, $brand->id, Mockery::on(fn ($r) => str_contains($r, 'link_removed_by_staff')))
        ->andReturn(['count' => 42, 'total_cents' => 12600, 'overflow' => false]);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordAsyncVoidCompletion')
        ->once()
        ->with($brand->id, $affiliate->id, 42, 12600, Mockery::type('string'));

    $notifier = Mockery::mock(BrandPartnerLinkNotifier::class);
    $notifier->shouldReceive('notifyAffiliateOfRemoval')->once()->with(Mockery::type(Professional::class), Mockery::type(Professional::class), 12600);
    $notifier->shouldReceive('notifyBrandOfRemoval')->once()->with(Mockery::type(Professional::class), Mockery::type(Professional::class));

    // Stub Professional::find to return these instances
    // (the job calls Professional::find internally)
    app()->instance('brand-prof-stub', $brand);
    app()->instance('affiliate-prof-stub', $affiliate);

    $job = new VoidPendingCommissionsForLinkJob(
        affiliateProfessionalId: $affiliate->id,
        brandProfessionalId: $brand->id,
        reason: 'link_removed_by_staff: closing account',
    );

    // Use a partial mock on the job to override find() calls
    $jobPartial = Mockery::mock($job)->makePartial();
    $jobPartial->shouldReceive('loadProfessionals')
        ->once()
        ->andReturn([$affiliate, $brand]);

    $jobPartial->handle($voidService, $auditor, $notifier);
});
```

- [ ] **Step 2: Run test, expect failure**

```bash
php artisan test tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php
```

Expected: FAIL — job class does not exist.

- [ ] **Step 3: Write the job**

Create `app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php`:

```php
<?php

namespace App\Jobs\Stripe;

use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandPartnerLinkAuditor;
use App\Services\Professional\BrandPartnerLinkNotifier;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Voids all pending commission entries for a disconnected brand-affiliate
// pair when the count exceeds the sync cap (200). Dispatched after
// BrandPartnerLinkLifecycleService commits the disconnect transaction.
//
// Idempotent — voidEntry() uses optimistic locking, so retries are safe.
class VoidPendingCommissionsForLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $affiliateProfessionalId,
        public readonly string $brandProfessionalId,
        public readonly string $reason,
    ) {}

    public function handle(
        CommissionVoidService $voidService,
        BrandPartnerLinkAuditor $auditor,
        BrandPartnerLinkNotifier $notifier,
    ): void {
        [$affiliate, $brand] = $this->loadProfessionals();

        if (! $affiliate || ! $brand) {
            Log::warning('VoidPendingCommissionsForLinkJob: missing professional, skipping.', [
                'affiliate_id' => $this->affiliateProfessionalId,
                'brand_id' => $this->brandProfessionalId,
            ]);
            return;
        }

        $result = $voidService->runVoidLoop(
            $this->affiliateProfessionalId,
            $this->brandProfessionalId,
            $this->reason,
        );

        $auditor->recordAsyncVoidCompletion(
            $this->brandProfessionalId,
            $this->affiliateProfessionalId,
            $result['count'],
            $result['total_cents'],
            $this->reason,
        );

        $notifier->notifyAffiliateOfRemoval($affiliate, $brand, $result['total_cents']);
        $notifier->notifyBrandOfRemoval($brand, $affiliate);
    }

    /** @return array{0: ?Professional, 1: ?Professional} */
    public function loadProfessionals(): array
    {
        return [
            Professional::find($this->affiliateProfessionalId),
            Professional::find($this->brandProfessionalId),
        ];
    }
}
```

- [ ] **Step 4: Run test, expect pass**

```bash
php artisan test tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php \
        tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php
git commit -m "feat(jobs): add VoidPendingCommissionsForLinkJob"
```

---

## Task 10: Fix `SelectionCleanupService` scoping + update catalog seeding

**Files:**
- Modify: `app/Services/Store/SelectionCleanupService.php`
- Modify: `app/Services/Store/AffiliateProductCatalogService.php`

- [ ] **Step 1: Update `AffiliateProductCatalogService::seedDefaultSelections` to persist `brand_professional_id`**

Find the method in `app/Services/Store/AffiliateProductCatalogService.php` and locate the `AffiliateProductSelection::query()->create([...])` or `upsert` call. Every insertion of a new `affiliate_product_selections` row must include `'brand_professional_id' => $brandProfessionalId`.

Example patch — find the row-building block (the shape will be `['affiliate_professional_id' => ..., 'shopify_product_gid' => ..., 'sort_order' => ...]`) and add `'brand_professional_id' => $brandProfessionalId` to it.

If the method uses `upsert`, update the unique-by array to `['affiliate_professional_id', 'brand_professional_id', 'shopify_product_gid']` so selections for the same product from different brands don't collide. But note: the existing DB unique index is `(affiliate_professional_id, shopify_product_gid)` — since a Shopify product GID globally identifies a single product in a single store, the same GID would never come from two different brands, so no new uniqueness issue arises from the brand column being added.

**Also scope `clearExisting`**: if `seedDefaultSelections` has a `clearExisting: true` branch that deletes existing selections before inserting defaults, add `->where('brand_professional_id', $brandProfessionalId)` to that delete query. Currently (pre-fix), `clearExisting: true` would wipe all of the affiliate's selections across all brands — the same root bug as in `SelectionCleanupService`. The scope fix must be applied here too so `AffiliateProductController::resetToDefaults` (Task 11) can safely reset per-brand.

- [ ] **Step 2: Scope the delete in `SelectionCleanupService`**

Modify `app/Services/Store/SelectionCleanupService.php:31-33`:

```php
$deleted = AffiliateProductSelection::query()
    ->where('affiliate_professional_id', $affiliateProfessionalId)
    ->where('brand_professional_id', $brandProfessionalId)
    ->delete();
```

(Add the `brand_professional_id` where clause.)

- [ ] **Step 3: Run existing SelectionCleanupService tests**

```bash
php artisan test --filter SelectionCleanup
```

Expected: existing tests should PASS if they supplied the brand argument. If any existing test was asserting a bulk-delete behavior (deletes all selections regardless of brand), it is codifying the bug we are fixing — update the test to match the correct scoped behavior.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Store/SelectionCleanupService.php \
        app/Services/Store/AffiliateProductCatalogService.php
git commit -m "fix(store): scope selection cleanup to one brand"
```

---

## Task 11: Update `AffiliateProductController` to require `brand_professional_id` on store + optional scope on reset

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/AffiliateProductController.php`
- Create: `tests/Feature/Professional/AffiliateProductSelectionScopedCleanupTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Professional/AffiliateProductSelectionScopedCleanupTest.php`:

```php
<?php

use App\Http\Controllers\Api\Professional\AffiliateProductController;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('rejects store when affiliate is not linked to the specified brand', function () {
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $unlinkedBrandId = (string) Str::uuid();

    // Mock BrandPartnerLink lookup to return nothing
    $linkQuery = Mockery::mock();
    $linkQuery->shouldReceive('where')->andReturnSelf();
    $linkQuery->shouldReceive('exists')->andReturn(false);
    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($linkQuery);

    $request = Request::create('/', 'POST', [
        'brand_professional_id' => $unlinkedBrandId,
        'shopify_product_gid' => 'gid://shopify/Product/123',
    ]);
    $request->attributes->set('professional', $affiliate);

    $controller = new AffiliateProductController();
    $response = $controller->store($request);

    expect($response->status())->toBe(422);
});
```

- [ ] **Step 2: Run the test, expect failure**

```bash
php artisan test tests/Feature/Professional/AffiliateProductSelectionScopedCleanupTest.php
```

Expected: FAIL — either 200/other status, or controller signature mismatch.

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/Api/Professional/AffiliateProductController.php`, update the `store` method to require `brand_professional_id` and validate affiliate linkage:

```php
public function store(Request $request): JsonResponse
{
    $professional = $this->currentProfessional($request);

    $data = $request->validate([
        'brand_professional_id' => ['required', 'uuid'],
        'shopify_product_gid' => ['required', 'string'],
        'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);

    $linked = DB::table('brand.brand_partner_links')
        ->where('affiliate_professional_id', $professional->id)
        ->where('brand_professional_id', $data['brand_professional_id'])
        ->exists();

    if (! $linked) {
        return $this->error('You are not linked to this brand.', 422);
    }

    $selection = AffiliateProductSelection::query()->create([
        'affiliate_professional_id' => $professional->id,
        'brand_professional_id' => $data['brand_professional_id'],
        'shopify_product_gid' => $data['shopify_product_gid'],
        'sort_order' => $data['sort_order'] ?? 0,
    ]);

    return $this->success(['selection' => $selection]);
}
```

Also update `resetToDefaults` to accept an optional `brand_professional_id`:

```php
public function resetToDefaults(Request $request, AffiliateProductCatalogService $catalog): JsonResponse
{
    $professional = $this->currentProfessional($request);

    $data = $request->validate([
        'brand_professional_id' => ['sometimes', 'uuid'],
    ]);

    if (isset($data['brand_professional_id'])) {
        $catalog->seedDefaultSelections($professional, $data['brand_professional_id'], clearExisting: true);
        return $this->success(['reset' => true, 'brand_professional_id' => $data['brand_professional_id']]);
    }

    // No brand specified — reset across all linked brands.
    $brandIds = DB::table('brand.brand_partner_links')
        ->where('affiliate_professional_id', $professional->id)
        ->pluck('brand_professional_id');

    foreach ($brandIds as $brandId) {
        $catalog->seedDefaultSelections($professional, (string) $brandId, clearExisting: true);
    }

    return $this->success(['reset' => true, 'brand_count' => $brandIds->count()]);
}
```

- [ ] **Step 4: Run test, expect pass**

```bash
php artisan test tests/Feature/Professional/AffiliateProductSelectionScopedCleanupTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Professional/AffiliateProductController.php \
        tests/Feature/Professional/AffiliateProductSelectionScopedCleanupTest.php
git commit -m "feat(affiliate): require brand_professional_id on product selections"
```

---

## Task 12: `BrandPartnerLinkLifecycleService::createForStaff`

**Files:**
- Create: `app/Services/Professional/BrandPartnerLinkLifecycleService.php` (first half — `createForStaff` only)
- Create: `tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php` (first half)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php`:

```php
<?php

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\BrandPartnerLinkAuditor;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\BrandPartnerLinkNotifier;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\BrandPartnerSiteSettingsSync;
use App\Services\Store\SelectionCleanupService;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Str;
use RuntimeException;

it('createForStaff rejects if brand is not type=brand', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional', 'status' => 'active']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional', 'status' => 'active']);

    $svc = new BrandPartnerLinkLifecycleService(
        Mockery::mock(BrandPartnerLinkService::class),
        Mockery::mock(SelectionCleanupService::class),
        Mockery::mock(CommissionVoidService::class),
        Mockery::mock(BrandPartnerLinkNotifier::class),
        Mockery::mock(BrandPartnerLinkAuditor::class),
        Mockery::mock(BrandPartnerSiteSettingsSync::class),
    );

    expect(fn () => $svc->createForStaff($brand, $affiliate, 'reason here', (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'Target brand is not a brand account');
});

it('createForStaff rejects if affiliate is type=brand', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand', 'status' => 'active']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand', 'status' => 'active']);

    $svc = new BrandPartnerLinkLifecycleService(
        Mockery::mock(BrandPartnerLinkService::class),
        Mockery::mock(SelectionCleanupService::class),
        Mockery::mock(CommissionVoidService::class),
        Mockery::mock(BrandPartnerLinkNotifier::class),
        Mockery::mock(BrandPartnerLinkAuditor::class),
        Mockery::mock(BrandPartnerSiteSettingsSync::class),
    );

    expect(fn () => $svc->createForStaff($brand, $affiliate, 'reason here', (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'Cannot link two brand accounts');
});

it('createForStaff happy path inserts link, audit row, and dispatches seed job via BrandPartnerLinkService', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand', 'status' => 'active']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional', 'status' => 'active']);
    $staffId = (string) Str::uuid();
    $reason = 'Manual recovery for lost invite';

    $link = new BrandPartnerLink([
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $brand->id,
        'slot' => 0,
    ]);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('connectBrandToAffiliate')
        ->once()
        ->with($affiliate->id, $brand->id)
        ->andReturn($link);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordCreation')
        ->once()
        ->with($brand->id, $affiliate->id, $staffId, 0, $reason);

    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldReceive('sync')->once();
    $sync->shouldReceive('invalidateAffiliateCaches')->once();

    $svc = new BrandPartnerLinkLifecycleService(
        $linkService,
        Mockery::mock(SelectionCleanupService::class),
        Mockery::mock(CommissionVoidService::class),
        Mockery::mock(BrandPartnerLinkNotifier::class),
        $auditor,
        $sync,
    );

    $result = $svc->createForStaff($brand, $affiliate, $reason, $staffId);

    expect($result->slot)->toBe(0);
    expect($result->brand_professional_id)->toBe($brand->id);
});
```

- [ ] **Step 2: Run the tests, expect failure**

```bash
php artisan test tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php
```

Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the initial service (create path only)**

Create `app/Services/Professional/BrandPartnerLinkLifecycleService.php`:

```php
<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Store\SelectionCleanupService;
use App\Services\Stripe\CommissionVoidService;
use RuntimeException;
use Throwable;

// Orchestrates the full lifecycle of brand-affiliate links — create and
// disconnect — across the three actor types (staff, brand, affiliate).
//
// Responsible for guard evaluation, transactional side-effect ordering,
// and post-commit dispatch of notifications / cache invalidation / jobs.
//
// The primitive DB operations (insert link, delete link + renormalize
// slots) live in BrandPartnerLinkService. This class composes that
// service with auditing, notifications, selection cleanup, commission
// voiding, and site settings sync.
class BrandPartnerLinkLifecycleService
{
    public function __construct(
        private readonly BrandPartnerLinkService $linkService,
        private readonly SelectionCleanupService $selectionCleanup,
        private readonly CommissionVoidService $commissionVoid,
        private readonly BrandPartnerLinkNotifier $notifier,
        private readonly BrandPartnerLinkAuditor $auditor,
        private readonly BrandPartnerSiteSettingsSync $sync,
    ) {}

    /**
     * Staff-only manual link creation. Throws RuntimeException on guard
     * failures so controllers can translate to 422 responses.
     */
    public function createForStaff(
        Professional $brand,
        Professional $affiliate,
        string $reason,
        string $staffUserId,
    ): BrandPartnerLink {
        $this->assertCreateGuards($brand, $affiliate);

        try {
            $link = $this->linkService->connectBrandToAffiliate($affiliate->id, $brand->id);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        $this->auditor->recordCreation($brand->id, $affiliate->id, $staffUserId, (int) $link->slot, $reason);

        $site = Site::query()->where('professional_id', $affiliate->id)->first();
        if ($site) {
            $this->sync->sync($site, $affiliate->id);
            $this->sync->invalidateAffiliateCaches($site);
        }

        return $link;
    }

    private function assertCreateGuards(Professional $brand, Professional $affiliate): void
    {
        if (mb_strtolower(trim((string) $brand->professional_type)) !== 'brand') {
            throw new RuntimeException('Target brand is not a brand account.');
        }

        if (mb_strtolower(trim((string) $affiliate->professional_type)) === 'brand') {
            throw new RuntimeException('Cannot link two brand accounts.');
        }

        if ($brand->status === 'deactivated' || $affiliate->status === 'deactivated') {
            throw new RuntimeException('Cannot link a deactivated professional.');
        }
    }
}
```

- [ ] **Step 4: Run tests, expect pass**

```bash
php artisan test tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php
```

Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/BrandPartnerLinkLifecycleService.php \
        tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php
git commit -m "feat(lifecycle): add BrandPartnerLinkLifecycleService::createForStaff"
```

---

## Task 13: `BrandPartnerLinkLifecycleService::disconnect`

**Files:**
- Modify: `app/Services/Professional/BrandPartnerLinkLifecycleService.php` (add disconnect method)
- Modify: `tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php` (add disconnect tests)

- [ ] **Step 1: Add failing tests**

Append to `tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php`:

```php
it('disconnect (keep) severs link without voiding pending commissions', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('disconnectBrandFromAffiliate')
        ->once()
        ->with($affiliate->id, $brand->id)
        ->andReturn(true);

    // Pre-read of link + pending commissions snapshot
    $linkService->shouldReceive('getLinkForPair')
        ->once()
        ->andReturn(new \App\Models\Core\Professional\BrandPartnerLink([
            'affiliate_professional_id' => $affiliate->id,
            'brand_professional_id' => $brand->id,
            'slot' => 1,
        ]));

    $commissionVoid = Mockery::mock(CommissionVoidService::class);
    $commissionVoid->shouldReceive('pendingSummaryForAffiliateBrand')
        ->once()
        ->andReturn(['count' => 3, 'total_cents' => 4500]);
    // Keep path: no voiding call.
    $commissionVoid->shouldNotReceive('voidPendingForAffiliateBrand');

    $selections = Mockery::mock(SelectionCleanupService::class);
    $selections->shouldReceive('removeSelectionsForAffiliateBrand')->once()->andReturn(4);

    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldReceive('sync')->once();
    $sync->shouldReceive('invalidateAffiliateCaches')->once();
    $sync->shouldReceive('settingsStillReferenceBrand')->andReturn(false);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordRemoval')->once();

    $notifier = Mockery::mock(BrandPartnerLinkNotifier::class);
    $notifier->shouldReceive('notifyAffiliateOfRemoval')->once();
    $notifier->shouldReceive('notifyBrandOfRemoval')->never(); // brand-initiated: no self-notification

    $svc = new BrandPartnerLinkLifecycleService(
        $linkService, $selections, $commissionVoid, $notifier, $auditor, $sync,
    );

    $req = \App\Services\Professional\DTO\DisconnectRequest::forBrand($brand, $affiliate, 'not a fit');
    $result = $svc->disconnect($req);

    expect($result->disconnected)->toBeTrue();
    expect($result->voidedCommissionCount)->toBe(0);
    expect($result->voidedCommissionCents)->toBe(0);
    expect($result->selectionsRemoved)->toBe(4);
});

it('disconnect (staff void, under cap) voids pending commissions inline', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staffId = (string) Str::uuid();

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('disconnectBrandFromAffiliate')->once()->andReturn(true);
    $linkService->shouldReceive('getLinkForPair')->once()->andReturn(new \App\Models\Core\Professional\BrandPartnerLink(['slot' => 0]));

    $commissionVoid = Mockery::mock(CommissionVoidService::class);
    $commissionVoid->shouldReceive('pendingSummaryForAffiliateBrand')->andReturn(['count' => 10, 'total_cents' => 1500]);
    $commissionVoid->shouldReceive('voidPendingForAffiliateBrand')
        ->once()
        ->andReturn(['count' => 10, 'total_cents' => 1500, 'overflow' => false]);

    $selections = Mockery::mock(SelectionCleanupService::class);
    $selections->shouldReceive('removeSelectionsForAffiliateBrand')->once()->andReturn(0);

    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldReceive('sync')->once();
    $sync->shouldReceive('invalidateAffiliateCaches')->once();
    $sync->shouldReceive('settingsStillReferenceBrand')->andReturn(false);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordRemoval')->once();

    $notifier = Mockery::mock(BrandPartnerLinkNotifier::class);
    $notifier->shouldReceive('notifyAffiliateOfRemoval')->once()->with(Mockery::type(Professional::class), Mockery::type(Professional::class), 1500);
    $notifier->shouldReceive('notifyBrandOfRemoval')->once();

    $svc = new BrandPartnerLinkLifecycleService(
        $linkService, $selections, $commissionVoid, $notifier, $auditor, $sync,
    );

    $req = \App\Services\Professional\DTO\DisconnectRequest::forStaff(
        $brand, $affiliate, 'staff voiding pending commissions for migration',
        \App\Services\Professional\Enums\CommissionHandling::Void,
        $staffId,
    );

    $result = $svc->disconnect($req);

    expect($result->voidedCommissionCount)->toBe(10);
    expect($result->voidedCommissionCents)->toBe(1500);
    expect($result->voidedAsync)->toBeFalse();
});

it('disconnect (staff void, over cap) returns voidedAsync=true and does not void inline', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staffId = (string) Str::uuid();

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('disconnectBrandFromAffiliate')->once()->andReturn(true);
    $linkService->shouldReceive('getLinkForPair')->once()->andReturn(new \App\Models\Core\Professional\BrandPartnerLink(['slot' => 2]));

    $commissionVoid = Mockery::mock(CommissionVoidService::class);
    $commissionVoid->shouldReceive('pendingSummaryForAffiliateBrand')->andReturn(['count' => 500, 'total_cents' => 75000]);
    $commissionVoid->shouldReceive('voidPendingForAffiliateBrand')
        ->once()
        ->andReturn(['count' => 0, 'total_cents' => 0, 'overflow' => true]);

    $selections = Mockery::mock(SelectionCleanupService::class);
    $selections->shouldReceive('removeSelectionsForAffiliateBrand')->once()->andReturn(0);

    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldReceive('sync')->once();
    $sync->shouldReceive('invalidateAffiliateCaches')->once();
    $sync->shouldReceive('settingsStillReferenceBrand')->andReturn(false);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordRemoval')->once();

    $notifier = Mockery::mock(BrandPartnerLinkNotifier::class);
    // On overflow, immediate notifications are skipped — the async job sends them.
    $notifier->shouldNotReceive('notifyAffiliateOfRemoval');
    $notifier->shouldNotReceive('notifyBrandOfRemoval');

    $svc = new BrandPartnerLinkLifecycleService(
        $linkService, $selections, $commissionVoid, $notifier, $auditor, $sync,
    );

    $req = \App\Services\Professional\DTO\DisconnectRequest::forStaff(
        $brand, $affiliate, 'bulk void for brand closure',
        \App\Services\Professional\Enums\CommissionHandling::Void,
        $staffId,
    );

    $result = $svc->disconnect($req);

    expect($result->voidedAsync)->toBeTrue();
    expect($result->pendingCommissionCount)->toBe(500);
    expect($result->pendingCommissionCents)->toBe(75000);
});
```

- [ ] **Step 2: Also add `getLinkForPair` and `pendingSummaryForAffiliateBrand` to supporting services**

Add helper to `BrandPartnerLinkService`:

```php
public function getLinkForPair(string $affiliateProfessionalId, string $brandProfessionalId): ?BrandPartnerLink
{
    return BrandPartnerLink::query()
        ->where('affiliate_professional_id', $affiliateProfessionalId)
        ->where('brand_professional_id', $brandProfessionalId)
        ->first();
}
```

Add helper to `CommissionVoidService`:

```php
/** @return array{count: int, total_cents: int} */
public function pendingSummaryForAffiliateBrand(
    string $affiliateProfessionalId,
    string $brandProfessionalId,
): array {
    $row = DB::table('commerce.commission_ledger_entries')
        ->where('affiliate_professional_id', $affiliateProfessionalId)
        ->where('brand_professional_id', $brandProfessionalId)
        ->where('status', 'pending')
        ->whereNull('payout_id')
        ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount_cents), 0) AS t')
        ->first();

    return [
        'count' => (int) ($row->c ?? 0),
        'total_cents' => (int) ($row->t ?? 0),
    ];
}
```

- [ ] **Step 3: Add the disconnect method to the lifecycle service**

Append to `app/Services/Professional/BrandPartnerLinkLifecycleService.php`:

```php
use App\Jobs\Stripe\VoidPendingCommissionsForLinkJob;
use App\Services\Professional\DTO\DisconnectRequest;
use App\Services\Professional\DTO\DisconnectResult;
use App\Services\Professional\Enums\CommissionHandling;
use App\Services\Professional\Enums\DisconnectActor;
use Illuminate\Support\Facades\DB;

public function disconnect(DisconnectRequest $req): DisconnectResult
{
    return DB::transaction(function () use ($req): DisconnectResult {
        // 1. Snapshot
        $link = $this->linkService->getLinkForPair($req->affiliate->id, $req->brand->id);
        $pending = $this->commissionVoid->pendingSummaryForAffiliateBrand(
            $req->affiliate->id,
            $req->brand->id,
        );

        // Stale-settings recovery path: link already gone but site settings still reference the brand.
        if (! $link) {
            $site = Site::query()->where('professional_id', $req->affiliate->id)->first();
            if ($site && $this->sync->settingsStillReferenceBrand($site, $req->brand->id)) {
                $this->sync->sync($site, $req->affiliate->id);
                DB::afterCommit(fn () => $this->sync->invalidateAffiliateCaches($site));
                return new DisconnectResult(
                    disconnected: true,
                    voidedCommissionCount: 0,
                    voidedCommissionCents: 0,
                    selectionsRemoved: 0,
                    staleSettingsCleaned: true,
                );
            }
            return new DisconnectResult(
                disconnected: false,
                voidedCommissionCount: 0,
                voidedCommissionCents: 0,
                selectionsRemoved: 0,
            );
        }

        // 2. Commission handling
        $voidedAsync = false;
        $voidedCount = 0;
        $voidedCents = 0;

        if ($req->actor === DisconnectActor::Staff && $req->commissions === CommissionHandling::Void) {
            $voidReason = 'link_removed_by_staff: ' . ($req->reason ?? '');
            $voidResult = $this->commissionVoid->voidPendingForAffiliateBrand(
                $req->affiliate->id,
                $req->brand->id,
                $voidReason,
            );
            if ($voidResult['overflow']) {
                $voidedAsync = true;
            } else {
                $voidedCount = $voidResult['count'];
                $voidedCents = $voidResult['total_cents'];
            }
        }

        // 3. Delete link + renormalize slots
        $this->linkService->disconnectBrandFromAffiliate($req->affiliate->id, $req->brand->id);

        // 4. Scoped selection cleanup
        $selectionsRemoved = $this->selectionCleanup->removeSelectionsForAffiliateBrand(
            $req->affiliate->id,
            $req->brand->id,
            'Brand connection removed',
            '{count} selected product(s) were removed because this brand connection ended.',
        );

        // 5. Sync site settings (in transaction so atomic with link state)
        $site = Site::query()->where('professional_id', $req->affiliate->id)->first();
        if ($site) {
            $this->sync->sync($site, $req->affiliate->id);
        }

        // 6. Audit
        $actorProfessionalId = match ($req->actor) {
            DisconnectActor::Staff => null,
            DisconnectActor::Brand => $req->brand->id,
            DisconnectActor::Affiliate => $req->affiliate->id,
        };
        $this->auditor->recordRemoval(
            brandProfessionalId: $req->brand->id,
            affiliateProfessionalId: $req->affiliate->id,
            actor: $req->actor,
            actorProfessionalId: $actorProfessionalId,
            staffUserId: $req->staffUserId,
            slotAtEvent: (int) $link->slot,
            pendingCount: $pending['count'],
            pendingCents: $pending['total_cents'],
            voidedCount: $voidedCount,
            voidedCents: $voidedCents,
            reason: $req->reason,
        );

        // 7 & 8. After commit: async job (if overflow), notifications, cache invalidation
        if ($voidedAsync) {
            DB::afterCommit(function () use ($req): void {
                VoidPendingCommissionsForLinkJob::dispatch(
                    affiliateProfessionalId: $req->affiliate->id,
                    brandProfessionalId: $req->brand->id,
                    reason: 'link_removed_by_staff: ' . ($req->reason ?? ''),
                );
            });
            // Skip inline notifications — the async job sends them on completion.
        } else {
            $this->dispatchNotifications($req, $voidedCents);
        }

        if ($site) {
            DB::afterCommit(fn () => $this->sync->invalidateAffiliateCaches($site));
        }

        return new DisconnectResult(
            disconnected: true,
            voidedCommissionCount: $voidedCount,
            voidedCommissionCents: $voidedCents,
            selectionsRemoved: $selectionsRemoved,
            pendingCommissionCount: $pending['count'],
            pendingCommissionCents: $pending['total_cents'],
            voidedAsync: $voidedAsync,
        );
    });
}

private function dispatchNotifications(DisconnectRequest $req, int $voidedCents): void
{
    DB::afterCommit(function () use ($req, $voidedCents): void {
        switch ($req->actor) {
            case DisconnectActor::Staff:
                $this->notifier->notifyAffiliateOfRemoval($req->affiliate, $req->brand, $voidedCents);
                $this->notifier->notifyBrandOfRemoval($req->brand, $req->affiliate);
                break;
            case DisconnectActor::Brand:
                $this->notifier->notifyAffiliateOfRemoval($req->affiliate, $req->brand, $voidedCents);
                break;
            case DisconnectActor::Affiliate:
                $this->notifier->notifyBrandOfRemoval($req->brand, $req->affiliate);
                break;
        }
    });
}
```

- [ ] **Step 4: Run tests, expect pass**

```bash
php artisan test tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php
```

Expected: PASS (all 6 tests: 3 create + 3 disconnect).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/BrandPartnerLinkLifecycleService.php \
        app/Services/Professional/BrandPartnerLinkService.php \
        app/Services/Stripe/CommissionVoidService.php \
        tests/Unit/Services/BrandPartnerLinkLifecycleServiceTest.php
git commit -m "feat(lifecycle): add disconnect with commission + async overflow handling"
```

---

## Task 14: `StaffBrandAffiliateLinkController::store`

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php` (store action only)
- Create: `tests/Feature/Staff/StaffBrandAffiliateLinkCreateTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/StaffBrandAffiliateLinkCreateTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandAffiliateLinkController;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\SidestStaff;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns 201 with the new link on success', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staff = new SidestStaff(['id' => (string) Str::uuid()]);

    $link = new BrandPartnerLink([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $brand->id,
        'slot' => 2,
    ]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('createForStaff')
        ->once()
        ->with(Mockery::type(Professional::class), Mockery::type(Professional::class), 'Lost invite email recovery', $staff->id)
        ->andReturn($link);

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'POST', ['reason' => 'Lost invite email recovery']);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->store($request, $brand, $affiliate);
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(201);
    expect($payload['data']['link']['slot'])->toBe(2);
});

it('returns 422 when reason is too short', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staff = new SidestStaff(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldNotReceive('createForStaff');

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'POST', ['reason' => 'too short']);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->store($request, $brand, $affiliate);
    expect($response->status())->toBe(422);
});

it('returns 409 when link already exists', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staff = new SidestStaff(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('createForStaff')
        ->andThrow(new RuntimeException('You are already connected to this brand partner.'));

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'POST', ['reason' => 'Manual recovery attempt']);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->store($request, $brand, $affiliate);
    expect($response->status())->toBe(409);
});
```

- [ ] **Step 2: Run the test, expect failure**

```bash
php artisan test tests/Feature/Staff/StaffBrandAffiliateLinkCreateTest.php
```

Expected: FAIL — controller does not exist.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

// Staff-admin endpoints for manually creating and removing brand-affiliate
// links. Primarily used for manual recovery when the invite flow fails.
class StaffBrandAffiliateLinkController extends ApiController
{
    public function __construct(
        private readonly BrandPartnerLinkLifecycleService $lifecycle,
    ) {}

    /** POST /api/staff/professionals/{brand}/affiliates/{affiliate} */
    public function store(Request $request, Professional $brand, Professional $affiliate): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $staff = $request->attributes->get('sidest_staff');
        if (! $staff) {
            return $this->error('Staff context missing.', 500);
        }

        try {
            $link = $this->lifecycle->createForStaff($brand, $affiliate, $data['reason'], $staff->id);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status = str_contains($message, 'already connected') ? 409 : 422;
            return $this->error($message, $status);
        }

        return $this->success([
            'link' => [
                'id' => $link->id,
                'brand_professional_id' => $link->brand_professional_id,
                'affiliate_professional_id' => $link->affiliate_professional_id,
                'slot' => (int) $link->slot,
                'created_at' => optional($link->created_at)->toIso8601String(),
            ],
        ], 201);
    }
}
```

- [ ] **Step 4: Run tests, expect pass**

```bash
php artisan test tests/Feature/Staff/StaffBrandAffiliateLinkCreateTest.php
```

Expected: PASS (all 3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php \
        tests/Feature/Staff/StaffBrandAffiliateLinkCreateTest.php
git commit -m "feat(staff): add POST /staff/professionals/{brand}/affiliates/{affiliate}"
```

---

## Task 15: `StaffBrandAffiliateLinkController::destroy`

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php` (add destroy)
- Create: `tests/Feature/Staff/StaffBrandAffiliateLinkRemoveTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Staff/StaffBrandAffiliateLinkRemoveTest.php`:

```php
<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandAffiliateLinkController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\SidestStaff;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\DTO\DisconnectResult;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns 200 with void counts on sync path', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);
    $staff = new SidestStaff(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('disconnect')->once()->andReturn(new DisconnectResult(
        disconnected: true,
        voidedCommissionCount: 5,
        voidedCommissionCents: 7500,
        selectionsRemoved: 3,
        pendingCommissionCount: 5,
        pendingCommissionCents: 7500,
        voidedAsync: false,
    ));

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'DELETE', [
        'reason' => 'migrating off platform per customer request',
        'on_pending_commissions' => 'void',
    ]);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->destroy($request, $brand, $affiliate);
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200);
    expect($payload['data']['voided_commission_count'])->toBe(5);
    expect($payload['data']['voided_commission_cents'])->toBe(7500);
});

it('returns 202 with voided_async:true on async overflow', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);
    $staff = new SidestStaff(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('disconnect')->once()->andReturn(new DisconnectResult(
        disconnected: true,
        voidedCommissionCount: 0,
        voidedCommissionCents: 0,
        selectionsRemoved: 7,
        pendingCommissionCount: 412,
        pendingCommissionCents: 61800,
        voidedAsync: true,
    ));

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'DELETE', [
        'reason' => 'Brand account closure — affiliate notified via email',
        'on_pending_commissions' => 'void',
    ]);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->destroy($request, $brand, $affiliate);
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(202);
    expect($payload['data']['voided_async'])->toBeTrue();
    expect($payload['data']['pending_commission_count'])->toBe(412);
});

it('returns 404 when link does not exist', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);
    $staff = new SidestStaff(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('disconnect')->once()->andReturn(new DisconnectResult(
        disconnected: false,
        voidedCommissionCount: 0,
        voidedCommissionCents: 0,
        selectionsRemoved: 0,
    ));

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'DELETE', [
        'reason' => 'some valid reason here',
        'on_pending_commissions' => 'keep',
    ]);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->destroy($request, $brand, $affiliate);
    expect($response->status())->toBe(404);
});

it('returns 422 when on_pending_commissions is void but reason is under 20 chars', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);
    $staff = new SidestStaff(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldNotReceive('disconnect');

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'DELETE', [
        'reason' => 'too short rsn', // 13 chars
        'on_pending_commissions' => 'void',
    ]);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->destroy($request, $brand, $affiliate);
    expect($response->status())->toBe(422);
});
```

- [ ] **Step 2: Run tests, expect failure**

```bash
php artisan test tests/Feature/Staff/StaffBrandAffiliateLinkRemoveTest.php
```

Expected: FAIL — method `destroy` does not exist.

- [ ] **Step 3: Add the destroy method to the controller**

Append to `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php`:

```php
use App\Services\Professional\DTO\DisconnectRequest;
use App\Services\Professional\Enums\CommissionHandling;

/** DELETE /api/staff/professionals/{brand}/affiliates/{affiliate} */
public function destroy(Request $request, Professional $brand, Professional $affiliate): JsonResponse
{
    // Conditional rule: when on_pending_commissions='void', reason must be at least 20 chars.
    $rules = [
        'reason' => ['required', 'string', 'max:500'],
        'on_pending_commissions' => ['required', 'in:keep,void'],
    ];
    $rules['reason'][] = $request->input('on_pending_commissions') === 'void' ? 'min:20' : 'min:10';

    $data = $request->validate($rules);

    $staff = $request->attributes->get('sidest_staff');
    if (! $staff) {
        return $this->error('Staff context missing.', 500);
    }

    $disconnectRequest = DisconnectRequest::forStaff(
        brand: $brand,
        affiliate: $affiliate,
        reason: $data['reason'],
        commissions: $data['on_pending_commissions'] === 'void'
            ? CommissionHandling::Void
            : CommissionHandling::Keep,
        staffUserId: (string) $staff->id,
    );

    $result = $this->lifecycle->disconnect($disconnectRequest);

    if (! $result->disconnected) {
        return $this->error('Link not found.', 404);
    }

    if ($result->voidedAsync) {
        return $this->success([
            'disconnected' => true,
            'voided_commission_count' => 0,
            'voided_commission_cents' => 0,
            'voided_async' => true,
            'void_job_dispatched_at' => now()->toIso8601String(),
            'pending_commission_count' => $result->pendingCommissionCount,
            'pending_commission_cents' => $result->pendingCommissionCents,
            'selections_removed' => $result->selectionsRemoved,
        ], 202);
    }

    return $this->success([
        'disconnected' => true,
        'voided_commission_count' => $result->voidedCommissionCount,
        'voided_commission_cents' => $result->voidedCommissionCents,
        'selections_removed' => $result->selectionsRemoved,
    ]);
}
```

- [ ] **Step 4: Run tests, expect pass**

```bash
php artisan test tests/Feature/Staff/StaffBrandAffiliateLinkRemoveTest.php
```

Expected: PASS (all 4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php \
        tests/Feature/Staff/StaffBrandAffiliateLinkRemoveTest.php
git commit -m "feat(staff): add DELETE /staff/professionals/{brand}/affiliates/{affiliate}"
```

---

## Task 16: Register staff routes with rate limit

**Files:**
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Register the routes**

Find the `staff.admin` route group in `routes/api/staff.php` (around line 180+, where `StaffAffiliateStatusController` is already registered). Add inside that group:

```php
Route::middleware('throttle:30,1')->group(function (): void {
    Route::post('/professionals/{brand}/affiliates/{affiliate}',
        [\App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandAffiliateLinkController::class, 'store'])
        ->whereUuid(['brand', 'affiliate']);

    Route::delete('/professionals/{brand}/affiliates/{affiliate}',
        [\App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandAffiliateLinkController::class, 'destroy'])
        ->whereUuid(['brand', 'affiliate']);
});
```

- [ ] **Step 2: Verify routes are registered**

```bash
php artisan route:list --path=api/staff/professionals
```

Expected output includes:
```
POST    api/staff/professionals/{brand}/affiliates/{affiliate}     StaffBrandAffiliateLinkController@store
DELETE  api/staff/professionals/{brand}/affiliates/{affiliate}     StaffBrandAffiliateLinkController@destroy
```

- [ ] **Step 3: Commit**

```bash
git add routes/api/staff.php
git commit -m "feat(routes): register staff brand-affiliate link endpoints"
```

---

## Task 17: Refactor `BrandPartnerController` (connect, promote, disconnect) to use new services

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/BrandPartnerController.php`

- [ ] **Step 1: Update `connect` to use `BrandPartnerSiteSettingsSync`**

Find `BrandPartnerController::connect` (line 25). Replace the two private calls at lines 64-65:

```php
$this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
$this->invalidateAffiliateCaches($site);
```

With:

```php
$sync->sync($site, (string) $professional->id);
$sync->invalidateAffiliateCaches($site);
```

And update the method signature to inject the new service:

```php
public function connect(
    Request $request,
    string $brandProfessionalId,
    BrandPartnerLinkService $brandPartnerLinks,
    BrandPartnerSiteSettingsSync $sync,
): JsonResponse {
```

- [ ] **Step 2: Update `promote` the same way**

Find `BrandPartnerController::promote` (line 118). Update signature:

```php
public function promote(
    Request $request,
    string $brandProfessionalId,
    BrandPartnerLinkService $brandPartnerLinks,
    BrandPartnerSiteSettingsSync $sync,
): JsonResponse {
```

Replace lines 139-140:

```php
$sync->sync($site, (string) $professional->id);
$sync->invalidateAffiliateCaches($site);
```

- [ ] **Step 3: Refactor `disconnect` to delegate to the lifecycle service**

Replace the entire `disconnect` method body with:

```php
public function disconnect(
    Request $request,
    string $brandProfessionalId,
    BrandPartnerLinkLifecycleService $lifecycle,
): JsonResponse {
    $professional = $this->currentProfessional($request);

    if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
        return $this->error('Brand accounts cannot manage brand partner connections.', 403);
    }

    $data = $request->validate([
        'reason' => ['nullable', 'string', 'max:500'],
    ]);

    $brand = Professional::query()->whereKey($brandProfessionalId)->first();
    if (! $brand) {
        return $this->error('Brand partner not found.', 404);
    }

    $result = $lifecycle->disconnect(DisconnectRequest::forAffiliate(
        brand: $brand,
        affiliate: $professional,
        reason: $data['reason'] ?? null,
    ));

    if (! $result->disconnected) {
        return $this->error('Brand partner not found in your connections.', 404);
    }

    $response = [
        'disconnected' => true,
        'brand_professional_id' => $brandProfessionalId,
        'selections_removed' => $result->selectionsRemoved,
    ];

    if ($result->staleSettingsCleaned) {
        $response['stale_settings_cleaned'] = true;
    }

    return $this->success($response);
}
```

Add the necessary imports at the top:

```php
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\BrandPartnerSiteSettingsSync;
use App\Services\Professional\DTO\DisconnectRequest;
```

- [ ] **Step 4: Delete private helpers**

Now that nothing else calls them, delete the four private helpers from `BrandPartnerController`:
- `syncSiteBrandPartnerSettings` (line 222)
- `settingsStillReferenceBrand` (line 262)
- `invalidateAffiliateCaches` (line 294)
- `brandToArray` (line 199) — keep this one, it is used by `index` and has no replacement in the extracted service.

- [ ] **Step 5: Run the existing tests and targeted new ones**

```bash
php artisan test tests/Feature/Professional/BrandPartnerDisconnectTest.php 2>/dev/null; \
php artisan test --filter BrandPartner
```

If the existing tests fail because they expected the old response shape, update them to the new shape (adds `selections_removed` field, unchanged `disconnected`, unchanged `brand_professional_id`).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Professional/BrandPartnerController.php
git commit -m "refactor(professional): delegate brand-partner disconnect to lifecycle service"
```

---

## Task 18: Refactor `BrandAffiliateController::disconnect` to use lifecycle service

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/BrandAffiliateController.php`

- [ ] **Step 1: Replace the disconnect method body**

Replace `BrandAffiliateController::disconnect` (line 87):

```php
public function disconnect(
    Request $request,
    string $affiliateId,
    BrandPartnerLinkLifecycleService $lifecycle,
): JsonResponse {
    $professional = $this->currentProfessional($request);

    if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
        return $this->error('Only brand accounts can disconnect affiliates.', 403);
    }

    $data = $request->validate([
        'reason' => ['nullable', 'string', 'max:500'],
    ]);

    $affiliate = Professional::query()->whereKey($affiliateId)->first();
    if (! $affiliate) {
        return $this->error('Affiliate not found.', 404);
    }

    $result = $lifecycle->disconnect(DisconnectRequest::forBrand(
        brand: $professional,
        affiliate: $affiliate,
        reason: $data['reason'] ?? null,
    ));

    if (! $result->disconnected) {
        return $this->error('Affiliate not found for this brand.', 404);
    }

    return $this->success([
        'disconnected' => true,
        'affiliate_id' => $affiliateId,
        'selections_removed' => $result->selectionsRemoved,
    ]);
}
```

Add imports:

```php
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\DTO\DisconnectRequest;
```

Remove the old `BrandPartnerLinkService` and `SelectionCleanupService` parameters from the disconnect signature — they are consumed inside the lifecycle service now. The old unused imports can be removed if no other methods in the controller use them.

- [ ] **Step 2: Run tests**

```bash
php artisan test --filter BrandAffiliate
```

Any test relying on the old response shape should pass since we added a field (`selections_removed`) without removing any. If a test mocks `BrandPartnerLinkService::disconnectBrandFromAffiliate` directly, rewrite it to mock `BrandPartnerLinkLifecycleService::disconnect`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/Professional/BrandAffiliateController.php
git commit -m "refactor(professional): delegate brand-affiliate disconnect to lifecycle service"
```

---

## Task 19: Apply `throttle:30,1` rate limit to refactored professional routes

**Files:**
- Modify: `routes/api/professional.php`

- [ ] **Step 1: Wrap the two disconnect routes**

Find in `routes/api/professional.php`:

```php
Route::delete('/brand-affiliates/{affiliate}', [BrandAffiliateController::class, 'disconnect']);
```

And:

```php
Route::delete('/brand-partners/{brandProfessionalId}', [BrandPartnerController::class, 'disconnect']);
```

Wrap each in throttle middleware. Either add `->middleware('throttle:30,1')` per-route, or group them into a throttle group:

```php
Route::middleware('throttle:30,1')->group(function (): void {
    Route::delete('/brand-affiliates/{affiliate}', [BrandAffiliateController::class, 'disconnect']);
    Route::delete('/brand-partners/{brandProfessionalId}', [BrandPartnerController::class, 'disconnect']);
});
```

(Place within the existing auth-gated group. Do not extract to a separate file.)

- [ ] **Step 2: Verify**

```bash
php artisan route:list --path=api/brand
```

Expected: both routes show `throttle:30,1` in the middleware column.

- [ ] **Step 3: Commit**

```bash
git add routes/api/professional.php
git commit -m "feat(routes): rate-limit brand-affiliate disconnect endpoints"
```

---

## Task 20: Update `docs/api.md` and run full test suite

**Files:**
- Modify: `docs/api.md`
- Verification only: `tests/`

- [ ] **Step 1: Document new endpoints**

In `docs/api.md`, add under a "Staff — Brand-Affiliate Links" section:

```markdown
### POST /api/staff/professionals/{brand}/affiliates/{affiliate}

Staff manually creates a brand-affiliate link, bypassing the invite flow. Used for manual recovery when an affiliate cannot complete the normal invite claim.

**Auth:** `staff.admin` middleware. Rate limit: 30/min per user.

**Request body:**
```json
{ "reason": "string, required, 10–500 chars" }
```

**Response 201:**
```json
{ "data": { "link": { "id": "uuid", "slot": 0, "brand_professional_id": "uuid", "affiliate_professional_id": "uuid", "created_at": "iso8601" } } }
```

**Errors:** 422 (guard failure), 409 (link exists), 403 (not staff admin).

**Bypassed guards (staff override):** `brand.status='active'`, `brand_profile.brand_status`. Enforced guards: type checks, not-deactivated, slot availability.

---

### DELETE /api/staff/professionals/{brand}/affiliates/{affiliate}

Staff removes a brand-affiliate link. Handles pending commissions per `on_pending_commissions`.

**Auth:** `staff.admin`. Rate limit: 30/min per user.

**Request body:**
```json
{
  "reason": "string, required; min 10 if keep, min 20 if void; max 500",
  "on_pending_commissions": "keep | void"
}
```

**Response 200 (sync):** `{ "data": { "disconnected": true, "voided_commission_count": n, "voided_commission_cents": n, "selections_removed": n } }`

**Response 202 (async overflow):** when `on_pending_commissions=void` and pending > 200, returns `voided_async: true` + `pending_commission_{count,cents}`. A queued job processes voiding and sends a completion notification.

**Errors:** 404 (link not found), 422 (validation).

---

### PATCH changes to existing disconnects

`DELETE /api/brand-affiliates/{affiliate}` (brand) and `DELETE /api/brand-partners/{brandProfessionalId}` (affiliate) now:

- Accept optional `reason` in the request body (`nullable, string, max:500`).
- Write an audit row to `brand.brand_partner_link_events`.
- Send a `BrandPartnerRemoved` notification to the other party.
- Include `selections_removed: n` in the success response.
- Pending commissions are **never voided** on these paths — they follow the normal payout/void lifecycle.

---

### Notification: `BrandPartnerRemoved`

New frontend notification type. Severity: `warning` when commissions were voided, `info` otherwise.

---

### Breaking change: POST /api/affiliate/selections

Now requires `brand_professional_id` (uuid) in the request body. The affiliate must have an active `brand_partner_links` row with that brand; otherwise 422.
```

- [ ] **Step 2: Run full test suite**

```bash
composer test
```

Expected: all tests PASS (including the new ones from tasks 5, 6, 8, 9, 10, 11, 12, 13, 14, 15). Address any unrelated failures as part of this task — a green suite is the bar for merge.

- [ ] **Step 3: Run `php artisan pint` to fix code style**

```bash
php artisan pint
```

If it made changes, commit them separately:

```bash
git add -A
git commit -m "chore: apply pint code style"
```

- [ ] **Step 4: Commit docs**

```bash
git add docs/api.md
git commit -m "docs(api): document brand-affiliate link management endpoints"
```

- [ ] **Step 5: Final verification checklist**

Run these manually and confirm each:

- [ ] Both migrations applied to dev Supabase (Task 1, 2)
- [ ] `php artisan route:list | grep affiliate` shows the two new staff routes with `throttle:30,1`
- [ ] `composer test` green
- [ ] Check Nightwatch for any new exceptions since deploy:
  - No `LogicException` from `BrandPartnerLinkAuditor` (indicates a programming bug in actor matching)
  - No `UniqueConstraintViolationException` spikes (indicates the 409 path isn't behaving)
- [ ] Spec's §8 edge cases exercised: try to disconnect a link that is already gone — should get 404 or `stale_settings_cleaned: true`.
