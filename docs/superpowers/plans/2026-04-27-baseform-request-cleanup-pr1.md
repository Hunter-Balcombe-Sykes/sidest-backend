# BaseFormRequest Cleanup (PR 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate six redundant `authorize() { return true; }` overrides by reparenting six Form Request subclasses to `BaseFormRequest`, then mark `BaseFormRequest::authorize()` as `final` so the pattern is structurally enforced.

**Architecture:** Mechanical refactor with no behaviour change. Authorization in this app runs entirely via route-group middleware (`VerifySupabaseJwt`, `LoadCurrentProfessional`, staff guards) and Policies invoked from controllers via `authorizeForUser($pro, …)`. The `authorize()` method on every Form Request is dead code; `BaseFormRequest` already returns `true` and documents this. Six classes still extend `Illuminate\Foundation\Http\FormRequest` directly and redeclare the no-op override. Lift them onto `BaseFormRequest`; remove the dead code; mark the base method `final` so future contributors can't accidentally reintroduce a partial gate.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4. No new dependencies.

---

## File Structure

**Modify (1):**
- `app/Http/Requests/BaseFormRequest.php` — mark `authorize()` as `final`.

**Modify (6 — Form Request subclasses):**
- `app/Http/Requests/Professional/RequestDataExportRequest.php`
- `app/Http/Requests/Api/Professional/Notifications/UpdateNotificationEmailPreferencesRequest.php`
- `app/Http/Requests/Api/Staff/Notifications/UpdateNotificationEmailPoliciesRequest.php`
- `app/Http/Requests/Api/PublicSite/UpdateVisibilityRequest.php` *(already extends `BaseFormRequest` but still redeclares `authorize()`)*
- `app/Http/Requests/Staff/RequestStaffDataExportRequest.php`
- `app/Http/Requests/Staff/StaffInitiateDeletionRequest.php`

**No new files. No new tests.** Regression coverage is the existing feature suite (`tests/Feature/Validation/RequestValidationTest.php`, `tests/Feature/Professional/DataExport/RequestSelfServiceExportTest.php`, `tests/Feature/Staff/DataExport/RequestStaffExportTest.php`, `tests/Feature/Staff/AccountDeletion/AdminInitiatedDeletionTest.php`). The `final` modifier itself is the structural guard going forward.

---

## Task 1: Capture green baseline

**Files:**
- None modified — read-only sanity check.

- [ ] **Step 1: Run the full suite to confirm green starting state**

Run: `composer test`
Expected: all tests pass. If any fail before changes, stop and investigate — they are not caused by this plan.

---

## Task 2: Reparent `RequestDataExportRequest`

**Files:**
- Modify: `app/Http/Requests/Professional/RequestDataExportRequest.php`

- [ ] **Step 1: Replace the file contents**

```php
<?php

namespace App\Http\Requests\Professional;

use App\Http\Requests\BaseFormRequest;

// V2: Currently empty body; placeholder for future filtered/partial exports
// (e.g. ?include=customers,bookings). Keeping the class lets us evolve later
// without refactoring the controller signature.
class RequestDataExportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 2: Run the targeted feature test**

Run: `php artisan test tests/Feature/Professional/DataExport/RequestSelfServiceExportTest.php`
Expected: PASS.

---

## Task 3: Reparent `UpdateNotificationEmailPreferencesRequest`

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Notifications/UpdateNotificationEmailPreferencesRequest.php`

- [ ] **Step 1: Replace the file contents**

```php
<?php

namespace App\Http\Requests\Api\Professional\Notifications;

use App\Http\Requests\BaseFormRequest;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Validation\Rule;

// V2: Validates notification email preference updates — array of category/enabled pairs constrained to known categories.
class UpdateNotificationEmailPreferencesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.category' => ['required', 'string', Rule::in(NotificationPublisher::categories())],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
```

- [ ] **Step 2: Run any notification-related tests**

Run: `php artisan test --filter=Notification`
Expected: PASS (if nothing matches, that is fine — coverage will surface in `composer test` later).

---

## Task 4: Reparent `UpdateNotificationEmailPoliciesRequest`

**Files:**
- Modify: `app/Http/Requests/Api/Staff/Notifications/UpdateNotificationEmailPoliciesRequest.php`

- [ ] **Step 1: Replace the file contents**

```php
<?php

namespace App\Http\Requests\Api\Staff\Notifications;

use App\Http\Requests\BaseFormRequest;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Validation\Rule;

// V2: Validates bulk update of notification email policies — requires an array of category/mode pairs with modes: default, force_on, or force_off.
class UpdateNotificationEmailPoliciesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'policies' => ['required', 'array', 'min:1'],
            'policies.*.category' => ['required', 'string', Rule::in(NotificationPublisher::categories())],
            'policies.*.mode' => ['required', 'string', 'in:default,force_on,force_off'],
        ];
    }
}
```

- [ ] **Step 2: No targeted test — covered by Task 8's full suite run**

---

## Task 5: Drop redundant `authorize()` from `UpdateVisibilityRequest`

**Files:**
- Modify: `app/Http/Requests/Api/PublicSite/UpdateVisibilityRequest.php`

This file already extends `BaseFormRequest`; only the redundant override needs to go.

- [ ] **Step 1: Replace the file contents**

```php
<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;

// V2: Validates site visibility toggle — requires a single boolean published flag.
class UpdateVisibilityRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'published' => ['required', 'boolean'],
        ];
    }
}
```

- [ ] **Step 2: Run any visibility-related tests**

Run: `php artisan test --filter=Visibility`
Expected: PASS.

---

## Task 6: Reparent `RequestStaffDataExportRequest`

**Files:**
- Modify: `app/Http/Requests/Staff/RequestStaffDataExportRequest.php`

- [ ] **Step 1: Replace the file contents**

```php
<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// V2: Validates the `send_to` query param on staff-triggered data exports.
// Default is 'professional' (the safer mode). 'staff' requires admin role —
// enforced in the controller, not here.
class RequestStaffDataExportRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'send_to' => $this->query('send_to', 'professional'),
        ]);
    }

    public function rules(): array
    {
        return [
            'send_to' => ['required', Rule::in(['professional', 'staff'])],
        ];
    }
}
```

- [ ] **Step 2: Run the staff data export test**

Run: `php artisan test tests/Feature/Staff/DataExport/RequestStaffExportTest.php`
Expected: PASS.

---

## Task 7: Reparent `StaffInitiateDeletionRequest`

**Files:**
- Modify: `app/Http/Requests/Staff/StaffInitiateDeletionRequest.php`

- [ ] **Step 1: Replace the file contents**

```php
<?php

namespace App\Http\Requests\Staff;

use App\Http\Requests\BaseFormRequest;

class StaffInitiateDeletionRequest extends BaseFormRequest
{
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

- [ ] **Step 2: Run the staff-initiated deletion test**

Run: `php artisan test tests/Feature/Staff/AccountDeletion/AdminInitiatedDeletionTest.php`
Expected: PASS.

(Note: `tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php` is the *self-serve* deletion test and does not exercise `StaffInitiateDeletionRequest`. The admin-initiated test above is the right one.)

---

## Task 8: Mark `BaseFormRequest::authorize()` as `final`

**Files:**
- Modify: `app/Http/Requests/BaseFormRequest.php:17` — change method signature from `public function authorize(): bool` to `final public function authorize(): bool`.

**Why now, not earlier:** if `final` is added before Tasks 2–7, PHP will fail to load the six subclasses still overriding it, and `composer test` blows up before the cleanup is finished. Add the modifier last.

- [ ] **Step 1: Edit the method signature**

Replace:
```php
    public function authorize(): bool
    {
        return true;
    }
```

With:
```php
    final public function authorize(): bool
    {
        return true;
    }
```

- [ ] **Step 2: Update the docblock to reflect the new structural guarantee**

Replace the docblock above the method with:
```php
    /**
     * Authorization is enforced entirely by route middleware (VerifySupabaseJwt,
     * LoadCurrentProfessional, staff guards) and resource Policies invoked from
     * controllers via `authorizeForUser($pro, ...)`. This method is intentionally
     * `final` — Supabase JWT means `Auth::user()` is always null, so any logic
     * here would gate against a null user and create a second, inconsistent
     * authorization surface. Do NOT override.
     */
```

- [ ] **Step 3: Run the full suite**

Run: `composer test`
Expected: all tests pass. If any subclass still overrides `authorize()`, PHP will report a fatal error citing the offending class — go back to Tasks 2–7 and finish that file.

---

## Task 9: Run Pint, then commit

**Files:**
- All modified files above.

- [ ] **Step 1: Apply code style**

Run: `php artisan pint`
Expected: any whitespace/import-order tweaks applied.

- [ ] **Step 2: Re-run the full suite after Pint**

Run: `composer test`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/BaseFormRequest.php \
        app/Http/Requests/Professional/RequestDataExportRequest.php \
        app/Http/Requests/Api/Professional/Notifications/UpdateNotificationEmailPreferencesRequest.php \
        app/Http/Requests/Api/Staff/Notifications/UpdateNotificationEmailPoliciesRequest.php \
        app/Http/Requests/Api/PublicSite/UpdateVisibilityRequest.php \
        app/Http/Requests/Staff/RequestStaffDataExportRequest.php \
        app/Http/Requests/Staff/StaffInitiateDeletionRequest.php

git commit -m "refactor(requests): reparent stragglers to BaseFormRequest and seal authorize()"
```

---

## Self-Review Notes

- **Spec coverage:** Six subclasses identified in the audit are each covered by Tasks 2–7; `final` modifier is Task 8; full-suite verification in Task 1 (baseline) and Task 8/9 (post-change).
- **No behaviour change:** all six subclasses' `authorize()` returned `true`; `BaseFormRequest::authorize()` returns `true`. The diff for the runtime is zero.
- **Why `final` is safe:** grep across the entire `app/Http/Requests/` tree confirms no subclass other than these six overrides `authorize()`. After Task 7, none do.
- **Why no new tests:** there is no new behaviour to test. The `final` modifier itself is the structural guard. The existing suite confirms no regression.
