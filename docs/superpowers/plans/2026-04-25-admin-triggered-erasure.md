# Admin-Triggered Account Erasure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow staff admins to initiate the existing 30-day account-deletion lifecycle on behalf of a professional/brand user who has requested erasure via support — without requiring the user to perform the email-token confirmation. This is the admin-side counterpart to the self-service flow in `ProfessionalAccountDeletionController`. Required so a UK/EU affiliate emailing support to invoke their GDPR Article 17 right to erasure can be handled through the same audited pipeline rather than the unsafe `forceDestroy` shortcut.

**Architecture:** The admin path reuses every downstream piece of the existing lifecycle (status flip → integration revocation → Stripe cancel-at-period-end → 30-day soft delete → daily `PurgeSoftDeleted` command → R2 cleanup → Supabase auth user deletion → audit). The only differences are the *entry point* (staff controller, no email token) and the *audit metadata* (records the staff actor, GDPR reason, and any obligations override). To keep this DRY, the post-confirmation transaction body in `AccountDeletionService::confirm()` is extracted into a private `executeConfirmation()` method that both the self-service `confirm()` and the new `adminInitiate()` call.

**Tech Stack:** Laravel 12, PostgreSQL (Supabase), PHP 8.2, Pest 4, SQLite in-memory for tests, Laravel Mail.

**Spec / design decisions** (confirmed with Josh 2026-04-25):

- **Skip email token confirmation** — admin is already authenticated via Supabase JWT and gated by `staff.admin` middleware.
- **Mandatory `reason` field** (10–500 chars) captured in audit for GDPR documentation (e.g., support ticket reference + Article cited).
- **Obligations override**: by default, unpaid balance / pending payouts / pending top-ups still block. Admin may pass `override_obligations: true` to proceed; the reason is recorded and the obligations themselves are logged in the audit row's metadata. Right to erasure under Article 17 can be outweighed by legitimate financial-record retention, but the override must be a deliberate choice on record.
- **Reuse the existing email template** (`AccountDeletionScheduledMail`). The user-facing experience is identical (deletion is scheduled, can be cancelled in grace period); the support ticket reference lives in the audit, not in the customer email.
- **Defer immediate-delete-no-grace** — deliberately not building a hard-delete shortcut. If a future request truly demands it, refactor `purge()` to be invokable directly. The existing `DELETE /staff/professionals/{id}/force` is for test-account cleanup only and stays unchanged.
- **Audit table extension over metadata-stuffing** — promote `actor_type`, `actor_id`, `actor_handle_snapshot`, `reason` to proper columns rather than nesting in `metadata` JSON. Regulators may need queryable "show me all admin-initiated erasures" reports.

---

## File Structure

### Created (4)

| Path | Responsibility |
|------|----------------|
| `supabase/migrations/20260425000001_add_actor_to_deletion_audit.sql` | Add `actor_type`, `actor_id`, `actor_handle_snapshot`, `reason` columns; backfill existing rows; widen event CHECK constraint |
| `app/Http/Controllers/Api/Staff/StaffSite/StaffAccountDeletionController.php` | `initiate`, `cancel`, `show` actions |
| `app/Http/Requests/Staff/StaffInitiateDeletionRequest.php` | Validates `reason` (10–500) + `override_obligations` (bool) |
| `tests/Feature/Staff/AccountDeletion/AdminInitiatedDeletionTest.php` | Happy path, obligations gate, override path, cancel, audit shape, 403 for non-admin |

### Modified (5)

| Path | Change |
|------|--------|
| `app/Services/Professional/AccountDeletionService.php` | Extract `executeConfirmation()`; add `adminInitiate()`, `adminCancel()`; thread actor params through `logAuditEvent()` |
| `app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php` | Add `EVENT_ADMIN_INITIATED`, `EVENT_ADMIN_CANCELLED`; extend `$fillable` and `$hidden`; add `ACTOR_TYPE_*` constants |
| `routes/api/staff.php` | Three new routes (initiate + cancel under `staff.admin`; show under regular staff) |
| `tests/Pest.php` | Update `setupProfessionalDeletionAuditTable()` helper with new columns |
| `tests/Feature/Professional/AccountDeletion/AccountDeletionTestCase.php` | Add `actor_type` assertion to existing audit-row checks (regression) |

---

## Task 1: Audit table migration

**Context:** The existing `core.professional_deletion_audit` table assumes the professional is always the actor. We extend it with explicit actor columns so admin-initiated rows can be distinguished, and so the staff handle is preserved even if the staff record is later deleted (same snapshot-survives-cascade pattern as `professional_handle_snapshot`).

The existing event CHECK constraint must also be widened to allow the two new event values.

**Files:**
- Create: `supabase/migrations/20260425000001_add_actor_to_deletion_audit.sql`

- [ ] **Step 1: Write the migration**

```sql
-- Extend deletion audit with actor identity + GDPR reason field
ALTER TABLE core.professional_deletion_audit
  ADD COLUMN IF NOT EXISTS actor_type text,
  ADD COLUMN IF NOT EXISTS actor_id uuid,
  ADD COLUMN IF NOT EXISTS actor_handle_snapshot text,
  ADD COLUMN IF NOT EXISTS reason text;

-- Backfill existing rows: every pre-existing audit row was self-service.
UPDATE core.professional_deletion_audit
SET actor_type = 'professional'
WHERE actor_type IS NULL;

-- Constrain actor_type after backfill.
ALTER TABLE core.professional_deletion_audit
  ALTER COLUMN actor_type SET NOT NULL;

ALTER TABLE core.professional_deletion_audit
  DROP CONSTRAINT IF EXISTS professional_deletion_audit_actor_type_check;

ALTER TABLE core.professional_deletion_audit
  ADD CONSTRAINT professional_deletion_audit_actor_type_check
  CHECK (actor_type IN ('professional', 'staff_admin', 'system'));

-- Widen event CHECK to allow admin events.
ALTER TABLE core.professional_deletion_audit
  DROP CONSTRAINT IF EXISTS professional_deletion_audit_event_check;

ALTER TABLE core.professional_deletion_audit
  ADD CONSTRAINT professional_deletion_audit_event_check
  CHECK (event IN (
    'requested',
    'confirmed',
    'cancelled',
    'purged',
    'purge_failed',
    'admin_initiated',
    'admin_cancelled'
  ));

-- Index for "show me all admin-initiated erasures in date range" reports.
CREATE INDEX IF NOT EXISTS idx_pda_actor_type_created
  ON core.professional_deletion_audit (actor_type, created_at DESC)
  WHERE actor_type <> 'professional';
```

- [ ] **Step 2: Apply migration locally and verify**

Run via Supabase tooling. Verify:
- `\d core.professional_deletion_audit` shows new columns
- `SELECT actor_type, COUNT(*) FROM core.professional_deletion_audit GROUP BY 1` returns existing rows under `professional`
- New CHECK constraint accepts `admin_initiated` (test by inserting and rolling back)

---

## Task 2: Update audit model

**Context:** Add the new event/actor constants, extend `$fillable` so the new columns can be persisted, and hide `actor_handle_snapshot` from serialisation alongside the existing PII hides (staff identity should not leak via API responses).

**Files:**
- Modify: `app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php`

- [ ] **Step 1: Add constants**

Below the existing `EVENT_*` constants:

```php
public const EVENT_ADMIN_INITIATED = 'admin_initiated';

public const EVENT_ADMIN_CANCELLED = 'admin_cancelled';

public const ACTOR_TYPE_PROFESSIONAL = 'professional';

public const ACTOR_TYPE_STAFF_ADMIN = 'staff_admin';

public const ACTOR_TYPE_SYSTEM = 'system';
```

- [ ] **Step 2: Extend `$fillable`**

```php
protected $fillable = [
    'professional_id',
    'professional_handle_snapshot',
    'professional_email_snapshot',
    'event',
    'actor_type',
    'actor_id',
    'actor_handle_snapshot',
    'reason',
    'ip_address',
    'user_agent',
    'metadata',
    'created_at',
];
```

- [ ] **Step 3: Hide actor identity from serialisation**

```php
protected $hidden = [
    'professional_email_snapshot',
    'actor_handle_snapshot',
    'ip_address',
    'user_agent',
];
```

The `actor_handle_snapshot` is staff PII — keep it queryable internally but never expose via API responses.

---

## Task 3: Refactor `AccountDeletionService` and add admin entry points

**Context:** `confirm()` does two things: (1) validate the email token, (2) execute the deletion. The admin path needs (2) but not (1). Extract step 2 into a private `executeConfirmation()` so both paths call the same code. Then add `adminInitiate()` (for the request-and-confirm-in-one staff action) and `adminCancel()` (for cancelling during grace period). Update `logAuditEvent()` to accept actor parameters.

**Files:**
- Modify: `app/Services/Professional/AccountDeletionService.php`

- [ ] **Step 1: Update `logAuditEvent()` signature**

Replace the existing signature with:

```php
/**
 * Append an audit row. Captures handle/email snapshots so the row survives
 * the professional's eventual hard delete. Actor parameters identify who
 * triggered this event — the professional themselves (self-service),
 * a staff admin (support-initiated), or the system (daily purge command).
 */
public function logAuditEvent(
    Professional $professional,
    string $event,
    ?Request $request = null,
    array $metadata = [],
    string $actorType = ProfessionalDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL,
    ?string $actorId = null,
    ?string $actorHandle = null,
    ?string $reason = null,
): void {
    ProfessionalDeletionAuditEntry::create([
        'professional_id' => $professional->id,
        'professional_handle_snapshot' => (string) ($professional->handle ?? ''),
        'professional_email_snapshot' => (string) ($professional->primary_email ?? ''),
        'event' => $event,
        'actor_type' => $actorType,
        'actor_id' => $actorId,
        'actor_handle_snapshot' => $actorHandle,
        'reason' => $reason,
        'ip_address' => $request?->ip(),
        'user_agent' => $request?->userAgent(),
        'metadata' => ! empty($metadata) ? $metadata : null,
        'created_at' => now(),
    ]);
}
```

Existing self-service callers don't pass actor params — they default to `professional` actor.

Audit existing callsites within the service: `request()`, `confirm()`, `cancel()`. Each currently calls `$this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_*, $request)` — these continue to default to `actor_type='professional'` and need no other changes.

The two `purge()` audit writes use `ProfessionalDeletionAuditEntry::create([...])` directly. Update those to also include `'actor_type' => ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM` so the daily command is correctly tagged.

- [ ] **Step 2: Extract `executeConfirmation()`**

Pull the body of `confirm()` after token validation into a new private method:

```php
/**
 * Apply the confirmed deletion: snapshot status, flip to pending_deletion,
 * revoke integration credentials, schedule Stripe cancel-at-period-end,
 * send scheduled email. Shared by self-service confirm() and admin
 * adminInitiate(). Returns the deletes_at timestamp.
 */
private function executeConfirmation(Professional $professional): Carbon
{
    $retentionDays = (int) config('sidest.soft_delete_retention_days', 30);
    $deletesAt = now()->addDays($retentionDays);
    $previousStatus = (string) ($professional->status ?? 'active');

    DB::transaction(function () use ($professional, $previousStatus) {
        $professional->update([
            'deletion_previous_status' => $previousStatus,
            'status' => 'pending_deletion',
            'deletion_confirmed_at' => now(),
            'deletion_token_hash' => null,
        ]);

        // Defense-in-depth: revoke integration credentials immediately
        // rather than leaving them in the DB for the 30-day grace period.
        ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->delete();
    });

    $this->cancelStripeAtPeriodEnd($professional);

    $cancelUrl = rtrim((string) config('app.frontend_url'), '/').'/account/deletion/cancel';

    try {
        Mail::to($professional->primary_email)->send(
            new AccountDeletionScheduledMail(
                displayName: (string) ($professional->display_name ?? 'there'),
                deletesAt: $deletesAt->toDayDateTimeString(),
                cancelUrl: $cancelUrl,
            )
        );
    } catch (\Throwable $e) {
        Log::error('Account deletion scheduled mail failed', [
            'professional_id' => $professional->id,
            'error' => $e->getMessage(),
        ]);
        // Do not fail the confirmation — the deletion is more important
        // than the mail. Cancel flow remains available via logged-in session.
    }

    return $deletesAt;
}
```

Replace the body of `confirm()` (after the token-validation guards) with:

```php
$deletesAt = $this->executeConfirmation($professional);

$this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_CONFIRMED, $request);

return [
    'success' => true,
    'code' => 200,
    'deletes_at' => $deletesAt->toIso8601String(),
];
```

- [ ] **Step 3: Add `adminInitiate()`**

```php
/**
 * Admin-initiated deletion. Skips the email-token confirm step and goes
 * straight to scheduling the 30-day grace period. Used when a professional
 * emails support requesting erasure (e.g., GDPR Article 17 request).
 *
 * @param  Professional  $professional   The user being deleted.
 * @param  string  $staffActorId         SidestStaff.id of the admin invoking this.
 * @param  string  $staffActorHandle     Snapshot of staff name (or email) for audit.
 * @param  string  $reason               GDPR reason / support ticket reference (10–500 chars).
 * @param  bool  $overrideObligations    If true, proceed despite unpaid balance / pending payouts.
 * @return array{success: bool, code: int, error?: string, reasons?: array<string>, deletes_at?: string}
 */
public function adminInitiate(
    Professional $professional,
    string $staffActorId,
    string $staffActorHandle,
    string $reason,
    bool $overrideObligations,
    Request $request,
): array {
    if ($professional->status === 'pending_deletion') {
        return ['success' => false, 'code' => 409, 'error' => 'Deletion already in progress.'];
    }

    $obligations = $this->checkObligations($professional);

    if (! empty($obligations) && ! $overrideObligations) {
        return [
            'success' => false,
            'code' => 422,
            'error' => 'Outstanding obligations must be settled or explicitly overridden.',
            'reasons' => $obligations,
        ];
    }

    $deletesAt = $this->executeConfirmation($professional);

    $metadata = ! empty($obligations)
        ? ['obligations_overridden' => $obligations]
        : [];

    $this->logAuditEvent(
        $professional,
        ProfessionalDeletionAuditEntry::EVENT_ADMIN_INITIATED,
        $request,
        $metadata,
        ProfessionalDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
        $staffActorId,
        $staffActorHandle,
        $reason,
    );

    return [
        'success' => true,
        'code' => 200,
        'deletes_at' => $deletesAt->toIso8601String(),
    ];
}
```

- [ ] **Step 4: Add `adminCancel()`**

A thin wrapper around `cancel()` that records the staff actor:

```php
/**
 * Admin-initiated cancel during grace period. Same lifecycle as self-service
 * cancel() but the audit row records which staff member triggered the cancel.
 *
 * @return array{success: bool, code: int, error?: string}
 */
public function adminCancel(
    Professional $professional,
    string $staffActorId,
    string $staffActorHandle,
    ?string $reason,
    Request $request,
): array {
    if ($professional->status !== 'pending_deletion') {
        return ['success' => false, 'code' => 409, 'error' => 'No pending deletion to cancel.'];
    }

    $previousStatus = $professional->deletion_previous_status;
    if (! is_string($previousStatus) || $previousStatus === '') {
        $previousStatus = 'active';
    }

    DB::transaction(function () use ($professional, $previousStatus) {
        $professional->update([
            'status' => $previousStatus,
            'deletion_requested_at' => null,
            'deletion_confirmed_at' => null,
            'deletion_previous_status' => null,
            'deletion_token_hash' => null,
        ]);
    });

    $this->resumeStripeSubscription($professional);

    try {
        Mail::to($professional->primary_email)->send(
            new AccountDeletionCancelledMail(
                displayName: (string) ($professional->display_name ?? 'there'),
            )
        );
    } catch (\Throwable $e) {
        Log::error('Account deletion cancelled mail failed', [
            'professional_id' => $professional->id,
            'error' => $e->getMessage(),
        ]);
    }

    $this->logAuditEvent(
        $professional,
        ProfessionalDeletionAuditEntry::EVENT_ADMIN_CANCELLED,
        $request,
        [],
        ProfessionalDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
        $staffActorId,
        $staffActorHandle,
        $reason,
    );

    return ['success' => true, 'code' => 200];
}
```

Note: the body intentionally duplicates `cancel()` rather than calling it, because we need to log a *different* event (`EVENT_ADMIN_CANCELLED`) with actor metadata and we want the actor params to be required here. Alternative: refactor `cancel()` to accept actor params and have `adminCancel()` delegate. Pick whichever is cleaner during implementation; the duplication is small.

---

## Task 4: Form Request for staff initiate

**Context:** Validation for the `reason` field. We require 10–500 chars to force support to record something meaningful (not just "yes" or a copy-pasted ticket ID with no context).

**Files:**
- Create: `app/Http/Requests/Staff/StaffInitiateDeletionRequest.php`

- [ ] **Step 1: Create the Form Request**

```php
<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StaffInitiateDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by staff.admin middleware
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'override_obligations' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Reason must be at least 10 characters — record the support ticket reference and the article cited.',
            'reason.max' => 'Reason must be 500 characters or fewer.',
        ];
    }
}
```

---

## Task 5: Staff controller

**Context:** Three actions. `initiate` invokes `adminInitiate()`. `cancel` invokes `adminCancel()`. `show` returns current deletion state plus the recent audit log for support context (read-only, available to all staff not just admin).

**Files:**
- Create: `app/Http/Controllers/Api/Staff/StaffSite/StaffAccountDeletionController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Staff\StaffInitiateDeletionRequest;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Core\Staff\SidestStaff;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// V2: Admin-side counterpart to ProfessionalAccountDeletionController.
// Admin is already authenticated via Supabase JWT and gated by staff.admin
// middleware, so we skip the email-token roundtrip.
class StaffAccountDeletionController extends ApiController
{
    public function __construct(
        private readonly AccountDeletionService $deletionService,
    ) {}

    /**
     * POST /staff/professionals/{professional}/deletion/initiate
     * Body: { reason: string (10-500), override_obligations?: bool }
     */
    public function initiate(
        StaffInitiateDeletionRequest $request,
        Professional $professional,
    ): JsonResponse {
        /** @var SidestStaff $staff */
        $staff = $request->attributes->get('sidest_staff');

        $result = $this->deletionService->adminInitiate(
            professional: $professional,
            staffActorId: (string) $staff->id,
            staffActorHandle: (string) ($staff->name ?? $staff->primary_email ?? ''),
            reason: (string) $request->input('reason'),
            overrideObligations: (bool) $request->input('override_obligations', false),
            request: $request,
        );

        if (! $result['success']) {
            $errors = isset($result['reasons']) ? ['reasons' => $result['reasons']] : [];

            return $this->error($result['error'] ?? 'Initiation failed.', $result['code'], $errors);
        }

        return $this->success([
            'message' => 'Account deletion scheduled.',
            'deletes_at' => $result['deletes_at'],
        ]);
    }

    /**
     * POST /staff/professionals/{professional}/deletion/cancel
     */
    public function cancel(Request $request, Professional $professional): JsonResponse
    {
        /** @var SidestStaff $staff */
        $staff = $request->attributes->get('sidest_staff');

        $result = $this->deletionService->adminCancel(
            professional: $professional,
            staffActorId: (string) $staff->id,
            staffActorHandle: (string) ($staff->name ?? $staff->primary_email ?? ''),
            reason: $request->input('reason') ? (string) $request->input('reason') : null,
            request: $request,
        );

        if (! $result['success']) {
            return $this->error($result['error'] ?? 'Cancel failed.', $result['code']);
        }

        return $this->success([
            'message' => 'Account deletion cancelled.',
        ]);
    }

    /**
     * GET /staff/professionals/{professional}/deletion
     * Returns current deletion state + recent audit entries. Available to all
     * staff (not just admin) so support can answer "where is my erasure
     * request" questions without elevated privileges.
     */
    public function show(Professional $professional): JsonResponse
    {
        $deletesAt = null;
        if ($professional->deletion_confirmed_at) {
            $retentionDays = (int) config('sidest.soft_delete_retention_days', 30);
            $deletesAt = Carbon::parse((string) $professional->deletion_confirmed_at)
                ->addDays($retentionDays)
                ->toIso8601String();
        }

        $auditEntries = ProfessionalDeletionAuditEntry::query()
            ->where('professional_id', $professional->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'event', 'actor_type', 'reason', 'metadata', 'created_at']);

        return $this->success([
            'status' => $professional->status,
            'deletion_requested_at' => optional($professional->deletion_requested_at)->toIso8601String(),
            'deletion_confirmed_at' => optional($professional->deletion_confirmed_at)->toIso8601String(),
            'deletes_at' => $deletesAt,
            'previous_status' => $professional->deletion_previous_status,
            'audit_entries' => $auditEntries,
        ]);
    }
}
```

The `show` action deliberately selects only non-PII columns from the audit table — no IP, no user agent, no `actor_handle_snapshot`. Support staff don't need to see staff names; admin investigations can hit the DB directly.

---

## Task 6: Routes

**Files:**
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Add the controller import**

Near the top of the file, alongside existing `StaffSite` imports:

```php
use App\Http\Controllers\Api\Staff\StaffSite\StaffAccountDeletionController;
```

- [ ] **Step 2: Add the `show` route to the regular staff group**

Inside the first `Route::prefix('staff')->middleware(['supabase.jwt', 'staff', 'throttle:staff'])->group(...)`, alongside the other professional read endpoints:

```php
// View account deletion state + audit log for support context
Route::get('/professionals/{professional}/deletion', [StaffAccountDeletionController::class, 'show']);
```

- [ ] **Step 3: Add `initiate` and `cancel` to the admin group**

Inside the `Route::prefix('staff')->middleware(['supabase.jwt', 'staff', 'staff.admin', 'throttle:staff'])->group(...)`:

```php
// GDPR-triggered erasure: support invokes the same lifecycle as self-service
// but skips the email-token step. Reason field is mandatory.
Route::post('/professionals/{professional}/deletion/initiate', [StaffAccountDeletionController::class, 'initiate']);
Route::post('/professionals/{professional}/deletion/cancel', [StaffAccountDeletionController::class, 'cancel']);
```

---

## Task 7: Tests

**Context:** Validate every guarantee the new flow makes — happy path, obligations gate, override path, audit shape, cancel, regression on existing self-service flow.

**Files:**
- Modify: `tests/Pest.php`
- Modify: `tests/Feature/Professional/AccountDeletion/AccountDeletionTestCase.php`
- Create: `tests/Feature/Staff/AccountDeletion/AdminInitiatedDeletionTest.php`

- [ ] **Step 1: Update `setupProfessionalDeletionAuditTable()` helper**

Locate the helper in `tests/Pest.php` (added in the prior deletion-lifecycle-fixes plan). Update the `CREATE TABLE` to include the new columns:

```php
\Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
    id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
    professional_id TEXT NULL,
    professional_handle_snapshot TEXT NULL,
    professional_email_snapshot TEXT NULL,
    event TEXT NULL,
    actor_type TEXT NULL,
    actor_id TEXT NULL,
    actor_handle_snapshot TEXT NULL,
    reason TEXT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    metadata TEXT NULL,
    created_at TEXT NULL
)');
```

- [ ] **Step 2: Add regression check in existing self-service test case**

Wherever `AccountDeletionTestCase` (or a shared helper) asserts on audit rows after self-service confirm/cancel, add:

```php
expect($auditRow->actor_type)->toBe(ProfessionalDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL);
```

This catches future regressions where the default actor tag is accidentally changed.

- [ ] **Step 3: Create the admin test file**

Create `tests/Feature/Staff/AccountDeletion/AdminInitiatedDeletionTest.php`. Required scenarios (each as its own `it(...)` block):

1. **admin can initiate erasure for a clean account** — POST returns 200 with `deletes_at`; professional `status` becomes `pending_deletion`; `deletion_confirmed_at` is set; `ProfessionalIntegration` rows for the professional are deleted; `AccountDeletionScheduledMail` was queued/sent (use `Mail::fake()`); audit row exists with `event='admin_initiated'`, `actor_type='staff_admin'`, `actor_id` matching the staff fixture, `reason` matching the request body.

2. **admin cannot initiate while another deletion is in flight** — set the professional to `pending_deletion` first; expect 409.

3. **obligations block without override** — seed a pending payout for the professional; POST without `override_obligations`; expect 422 with `reasons: ['pending_payouts']`; professional status unchanged.

4. **obligations are overridden when explicitly requested** — same setup as (3) but pass `override_obligations: true`; expect 200; audit row's `metadata.obligations_overridden` contains `['pending_payouts']`.

5. **reason is required and validated** — POST with empty/short reason expects 422; with reason > 500 chars expects 422.

6. **admin can cancel during grace period** — set status to `pending_deletion` first; POST cancel; status reverts to `deletion_previous_status`; audit row records `event='admin_cancelled'`, `actor_type='staff_admin'`.

7. **admin cancel fails if no deletion in flight** — POST cancel against an active account; expect 409.

8. **non-admin staff get 403 on initiate and cancel** — fixture a `SidestStaff` row that's not an admin; expect 403 from both POST endpoints; expect 200 from GET show (read access for all staff).

9. **GET show returns current deletion state and audit entries** — for an account with prior audit rows, response includes `status`, `deletes_at`, `audit_entries` array; PII fields (`actor_handle_snapshot`, `ip_address`, `user_agent`) are NOT in the response.

10. **Stripe cancel-at-period-end is invoked on admin initiate** — mock `StripeBillingService::cancelSubscriptionAtPeriodEnd` and assert it was called with the subscription's stripe ID.

Use the existing `AccountDeletionTestCase` patterns (or extract a shared admin test base) for fixtures.

- [ ] **Step 4: Run the suite**

```bash
composer test
```

All tests pass. The composer guard `guard:no-laravel-migrations` continues to pass (we only touched `supabase/migrations/`).

---

## Verification checklist

After all tasks complete:

- [ ] `composer test` passes
- [ ] `php artisan pint` reports no violations
- [ ] Migration applied locally; `\d core.professional_deletion_audit` shows the four new columns and the widened CHECK constraint
- [ ] Manual smoke test: as an admin Supabase JWT, hit `POST /staff/professionals/{id}/deletion/initiate` with a real reason — verify the professional's status flips, the email sends, and the audit row shows `actor_type='staff_admin'`
- [ ] Manual smoke test: hit `GET /staff/professionals/{id}/deletion` as regular (non-admin) staff — confirm 200 and that PII fields aren't in the response
- [ ] Self-service deletion still works end-to-end (regression — existing `Mail::fake()` tests cover this)
- [ ] No new Nightwatch exceptions after deploying

---

## Out of scope (deferred)

- **Self-service data export** (Right of Access / Portability under GDPR Article 15/20) — separate plan, ~1–2 days. Needs to assemble profile + bookings + booking_events + integrations + billing + commissions into a JSON dump and email a download link.
- **Admin-triggered data export** — same machinery as above, with a staff endpoint instead of self-service.
- **Immediate hard-delete with no grace period** — defer until a real GDPR request demands it; existing `forceDestroy` is for test cleanup only.
- **Two-person rule on admin erasures** — defer; audit trail is sufficient at current scale (pre-beta, no customers).
