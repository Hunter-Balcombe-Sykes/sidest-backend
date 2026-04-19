# Account Deletion — Design Spec

**Date:** 2026-04-19
**Status:** Approved

## Overview

Self-service account deletion for professionals. Two-phase lifecycle: email-confirmed grace period (read-only, cancellable), followed by automated hard delete with financial anonymization and Supabase auth cleanup. Staff-managed soft delete pathway is unchanged.

---

## Data Model

### New columns on `core.professionals`

| Column | Type | Purpose |
|--------|------|---------|
| `deletion_token` | `text nullable` | UUID token sent via email to confirm intent. Nulled after confirmation or cancellation. |
| `deletion_requested_at` | `timestamptz nullable` | Set when professional initiates deletion. Nulled if token expires or deletion is cancelled. |
| `deletion_confirmed_at` | `timestamptz nullable` | Set when professional clicks the confirmation link. Starts the 30-day grace period clock. |

### Status change

`pending_deletion` added to the `status` enum/check constraint on `core.professionals`. Existing statuses (`active`, `suspended`, etc.) are unaffected.

### No intermediate soft delete

During the grace period, `deleted_at` remains null. The professional is findable by `auth_user_id` and can log in. After 30 days the purge command hard-deletes directly — no intermediate `deleted_at` step. The staff soft-delete pathway (`deleted_at`) is a separate, unrelated mechanism.

### Anonymization

Before hard delete, any financial rows with a `professional_id` FK (commissions, payouts) have that column set to `null`. This prevents FK constraint violations on `forceDelete()` and preserves financial records for accounting and legal audit trails.

---

## API Flow

All endpoints under the authenticated professional route group (`supabase.jwt`, `current.pro`).

### Step 1 — Request: `POST /api/professional/me/deletion/request`

- Sets `deletion_requested_at = now()`, generates UUID `deletion_token`.
- Sends `AccountDeletionRequestedMail` with a token-bearing confirmation link (24-hour expiry).
- Status does **not** change — no effect until confirmed.
- Returns 409 if `status === pending_deletion` (already in grace period).
- Returns 403 if `status === suspended`.

### Step 2 — Confirm: `POST /api/professional/me/deletion/confirm/{token}`

- Validates token matches and has not expired (24 hours from `deletion_requested_at`).
- Sets `status = pending_deletion`, `deletion_confirmed_at = now()`, nulls `deletion_token`.
- Schedules Stripe subscription cancel-at-period-end.
- Sends `AccountDeletionScheduledMail` with the calculated deletion date and a cancel link.
- Expired token → 410 Gone. Wrong token → 404.

### Step 3 — Cancel: `POST /api/professional/me/deletion/cancel`

- Available any time while `status === pending_deletion`.
- Resets `status → active`, nulls `deletion_requested_at` and `deletion_confirmed_at`.
- Attempts to reverse Stripe cancel-at-period-end (best effort; logs failure if billing period already ended).
- Sends `AccountDeletionCancelledMail`.

### Read-only enforcement

`EnforcePendingDeletionReadOnly` middleware runs after `current.pro` in the professional route group. If `status === pending_deletion` and request method is not `GET` or `HEAD`, returns:

```json
HTTP 423 Locked
{
  "message": "Account is pending deletion.",
  "pending_deletion": true,
  "deletes_at": "2026-05-19T03:20:00Z"
}
```

The cancel endpoint is explicitly excluded from this middleware.

### Background purge

The existing `sidest:purge-soft-deletes` command gains a new `purgePendingDeletionProfessionals()` method called at the end of `handle()`. For each professional where `deletion_confirmed_at <= now() - 30 days`:

1. Anonymize: null `professional_id` on financial rows (commissions, payouts).
2. Call Supabase Admin API `DELETE /auth/v1/admin/users/{auth_user_id}`.
3. Call `professional->forceDelete()`.

Failures at any step are logged to Nightwatch and that professional is skipped — retried on the next daily run.

---

## Components

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/Professional/ProfessionalAccountDeletionController.php` | `request`, `confirm`, `cancel` methods — thin, delegates to service |
| `app/Services/Professional/AccountDeletionService.php` | All business logic: token generation, status transitions, Stripe, anonymization, Supabase Admin API call |
| `app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php` | Returns 423 on write requests for `pending_deletion` accounts |
| `app/Mail/Notifications/AccountDeletionRequestedMail.php` | Confirmation link email, 24hr expiry warning |
| `app/Mail/Notifications/AccountDeletionScheduledMail.php` | Grace period active, deletion date, cancel link |
| `app/Mail/Notifications/AccountDeletionCancelledMail.php` | Deletion cancelled confirmation |
| `supabase/migrations/*_add_deletion_columns_to_professionals.sql` | Adds three columns + new status value |
| `app/Console/Commands/PurgeSoftDeleted.php` (extended) | New `purgePendingDeletionProfessionals()` method |

No new tables, models, or observers.

---

## Error Handling & Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| Token not clicked within 24 hours | On next confirm attempt: 410 Gone. Token + requested_at silently nulled. Can re-request. |
| Re-request during grace period | 409 Conflict with `deletes_at` date |
| Suspended account requests deletion | 403 Forbidden — staff handles suspended accounts |
| Supabase Admin API failure during purge | Log to Nightwatch, skip `forceDelete()`, retry next day |
| Stripe cancellation failure during confirm | Log to Nightwatch, proceed with deletion (billing is secondary) |
| `forceDelete()` FK violation despite anonymization | Log to Nightwatch, skip, retry next day |
| Supabase auth user already deleted (404) | Treat as success, proceed with `forceDelete()` |

---

## Testing

**Location:** `tests/Feature/Professional/AccountDeletion/`

| Test file | Coverage |
|-----------|---------|
| `RequestDeletionTest` | Successful request, email sent, token stored; 409 during grace period; 403 for suspended |
| `ConfirmDeletionTest` | Valid token confirms, status flips, Stripe called, mail sent; 410 for expired token; 404 for wrong token |
| `CancelDeletionTest` | Grace period cancel resets status, Stripe reversed, mail sent |
| `ReadOnlyEnforcementTest` | 423 on write routes, 200 on GET routes, cancel endpoint exempt |
| `PurgePendingDeletionTest` | FK rows anonymized, Supabase called, forceDelete called; within-grace-period skipped; Supabase failure skips forceDelete; FK violation skips and logs |

Stripe and Supabase Admin API calls mocked via `Http::fake()`.
