# Account Deletion — Design Spec

**Date:** 2026-04-19
**Status:** Approved (revised after security + FK review)

## Overview

Self-service account deletion for professionals. Two-phase lifecycle: email-confirmed grace period (read-only, public-facing assets offline, cancellable), followed by automated hard delete with financial FK anonymization and Supabase auth cleanup. Staff-managed soft delete pathway is unchanged.

---

## FK Impact Audit (pre-requisite context)

`core.professionals` is referenced by 46 FKs across 27 tables:
- **42 `ON DELETE CASCADE`** — customers, sites, blocks, integrations, notifications, analytics, billing.subscriptions, brand profiles and partner links. These clean up automatically on `forceDelete()`.
- **4 `ON DELETE SET NULL`** — already handled by Postgres.
- **3 `ON DELETE RESTRICT`** — block `forceDelete()` unless addressed:
  - `commerce.commission_payouts.brand_professional_id`
  - `commerce.commission_payouts.affiliate_professional_id`
  - `commerce.brand_commission_topups.brand_professional_id`

These three are the only manual cleanup surface. Strategy: migrate each from `RESTRICT` → `SET NULL` and make the column nullable, so the FK resolves itself when the professional is hard-deleted. Application code that joins these tables must tolerate a nullable professional reference (null = "deleted user"). An accessor/scope can render these as "Deleted user" in staff UI.

---

## Data Model

### New columns on `core.professionals`

| Column | Type | Purpose |
|--------|------|---------|
| `deletion_token_hash` | `text nullable` | SHA-256 hash of the deletion token. **The raw token is never stored.** Nulled after confirmation or cancellation. |
| `deletion_requested_at` | `timestamptz nullable` | Set when professional initiates deletion. Nulled if token expires or deletion is cancelled. |
| `deletion_confirmed_at` | `timestamptz nullable` | Set when professional confirms via email link. Starts the 30-day grace period clock. |
| `deletion_previous_status` | `text nullable` | Snapshot of `status` at time of confirmation. Restored on cancel. Nulled after purge or cancel. |

### Status enum + CHECK constraint

Add `pending_deletion` to the allowed statuses. Status column is currently plain `text` with no CHECK constraint — the migration adds one:

```sql
ALTER TABLE core.professionals
ADD CONSTRAINT professionals_status_check
CHECK (status IN ('active', 'suspended', 'disabled', 'pending_deletion'));
```

### RESTRICT FK migration

The three `RESTRICT` FKs must be altered to `SET NULL`, and their columns made nullable:

```sql
ALTER TABLE commerce.commission_payouts
  ALTER COLUMN brand_professional_id DROP NOT NULL,
  ALTER COLUMN affiliate_professional_id DROP NOT NULL,
  DROP CONSTRAINT commission_payouts_brand_professional_id_fkey,
  DROP CONSTRAINT commission_payouts_affiliate_professional_id_fkey,
  ADD CONSTRAINT commission_payouts_brand_professional_id_fkey
    FOREIGN KEY (brand_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL,
  ADD CONSTRAINT commission_payouts_affiliate_professional_id_fkey
    FOREIGN KEY (affiliate_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

ALTER TABLE commerce.brand_commission_topups
  ALTER COLUMN brand_professional_id DROP NOT NULL,
  DROP CONSTRAINT brand_commission_topups_brand_professional_id_fkey,
  ADD CONSTRAINT brand_commission_topups_brand_professional_id_fkey
    FOREIGN KEY (brand_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;
```

All `CommissionPayout` and `BrandCommissionTopup` queries/resources must be audited to handle nullable `professional_id`. Staff-facing resources render null as "Deleted user".

### No intermediate soft delete

During the grace period, `deleted_at` remains null. The professional stays findable by `auth_user_id` so they can log in and cancel. After 30 days the purge command goes straight to `forceDelete()`. The staff soft-delete pathway (`deleted_at`) is a separate mechanism and is unchanged.

---

## Security

### Token generation + storage

- Token: `Str::random(64)` — 64 characters of cryptographically random alphanumerics (~380 bits of entropy).
- Storage: only the SHA-256 hash lives in `deletion_token_hash`. The raw token exists only in the email sent to the user.
- Comparison: `hash_equals(deletion_token_hash, hash('sha256', $incoming))` — timing-safe.
- Expiry: 24 hours from `deletion_requested_at`. Expired tokens return `410 Gone`.

### Rate limiting

`POST /me/deletion/request` gets `throttle:3,60` (3 requests per hour per authenticated professional). Prevents email-bombing a professional's inbox with a stolen JWT.

### Outstanding obligations precondition

The `request` endpoint blocks (returns `422`) if any of:
- `stripe_manual_balance_cents > 0` (commission funding balance owed).
- Any `commerce.commission_payouts` row with `status != 'paid'` referencing this professional.
- Any `commerce.brand_commission_topups` row with `status != 'completed'` referencing this professional (brand side only).

Error payload enumerates reasons so the frontend can display them:
```json
{
  "message": "Outstanding obligations must be settled before deletion.",
  "reasons": ["unpaid_balance", "pending_payouts"]
}
```

### Audit log

New table `core.professional_deletion_audit` (keyed to the deletion lifecycle, survives the professional's hard delete by snapshotting identity at write time):

| Column | Type |
|--------|------|
| `id` | uuid pk |
| `professional_id` | uuid nullable (SET NULL on FK) |
| `professional_handle_snapshot` | text — captured at write time, survives deletion |
| `professional_email_snapshot` | text — captured at write time |
| `event` | text — one of `requested`, `confirmed`, `cancelled`, `purged`, `purge_failed` |
| `ip_address` | text nullable |
| `user_agent` | text nullable |
| `metadata` | jsonb nullable — e.g. failure reason, previous status |
| `created_at` | timestamptz |

Every state transition writes a row via `AccountDeletionService::logAuditEvent()`.

---

## API Flow

All endpoints under the authenticated professional route group (`supabase.jwt`, `current.pro`).

### Step 1 — Request: `POST /api/professional/me/deletion/request`

Middleware: `throttle:3,60` (rate limit).

1. Check preconditions (outstanding obligations) → `422` if any fail.
2. Generate raw token via `Str::random(64)`.
3. Store `hash('sha256', $token)` in `deletion_token_hash`, set `deletion_requested_at = now()`.
4. Send `AccountDeletionRequestedMail` with the raw token in the confirmation URL.
5. If mail send throws, rollback the column updates (DB transaction) and return `503`.
6. Write audit log row (`event = requested`).
7. `200` on success.

Status does **not** change — no side-effects until confirmed.

Returns:
- `409` if `status === 'pending_deletion'` (already in grace period).
- `403` if `status` is `suspended` or `disabled`.
- `422` on unsettled obligations.
- `429` on rate limit.

### Step 2 — Confirm: `POST /api/professional/me/deletion/confirm`

Request body: `{ "token": "<raw_token>" }`.

1. Compute `hash('sha256', $token)` and look up professional by `deletion_token_hash` using `hash_equals()` on the stored hash.
2. If not found → `404`. If `deletion_requested_at < now() - 24h` → clear token columns, return `410 Gone`.
3. Begin transaction:
   - Set `deletion_previous_status = status`.
   - Set `status = 'pending_deletion'`, `deletion_confirmed_at = now()`.
   - Null `deletion_token_hash` (one-time use).
   - **Immediately delete `professional_integrations` rows** (revokes Shopify/Square/Fresha OAuth where supported, then deletes credentials). Defense-in-depth — credentials don't sit in DB for 30 days.
4. Schedule Stripe subscription cancel-at-period-end (best effort; log-and-continue on failure).
5. Send `AccountDeletionScheduledMail`.
6. Write audit log row (`event = confirmed`).
7. `200` with `{ "deletes_at": "<iso8601>" }`.

### Step 3 — Cancel: `POST /api/professional/me/deletion/cancel`

Available while `status === 'pending_deletion'`. **Not subject to the read-only middleware** (explicit exemption).

1. Begin transaction:
   - Restore `status = deletion_previous_status` (fallback to `'active'` if null).
   - Null `deletion_previous_status`, `deletion_requested_at`, `deletion_confirmed_at`.
2. Attempt Stripe reversal of cancel-at-period-end (best effort; log failure).
3. Send `AccountDeletionCancelledMail`.
4. Write audit log row (`event = cancelled`).
5. `200` on success.

Returns `409` if `status !== 'pending_deletion'`.

---

## Read-only enforcement + public visibility

### Middleware changes

**`LoadCurrentProfessional` (existing) — MUST update.**
Current code at `app/Http/Middleware/Context/LoadCurrentProfessional.php:47`:

```php
if (($professional->status ?? 'active') !== 'active') {
    return response()->json(['message' => 'Your account is suspended.'], 403);
}
```

Change to allow `pending_deletion` through (else they can't cancel):

```php
if (! in_array($professional->status ?? 'active', ['active', 'pending_deletion'], true)) {
    return response()->json(['message' => 'Your account is suspended.'], 403);
}
```

**`EnforcePendingDeletionReadOnly` (new) — runs after `current.pro`.**
If `status === 'pending_deletion'` and request method ∉ `{GET, HEAD, OPTIONS}`, return:

```json
HTTP 423 Locked
{
  "message": "Account is pending deletion.",
  "pending_deletion": true,
  "deletes_at": "2026-05-19T03:20:00Z"
}
```

Cancel and logout endpoints are explicitly exempt.

**`BootstrapController.php:99` — MUST update.**
Current `in_array($professional->status, ['disabled', 'suspended'], true)` adds `'pending_deletion'` so bootstrap rejects profile mutations during grace period.

### Public-facing side effect (by design)

Public site + brand configs + affiliate pages all check `status === 'active'`. With `pending_deletion`, these endpoints return 404 / empty for the duration of the grace period:
- `SiteVisibilityController` — public site returns unavailable
- `HydrogenBrandDesignController`, `HydrogenBrandConfigController` — brand configs unavailable
- `HydrogenAffiliateController` — affiliate pages unavailable

This is the intended behavior: the professional is leaving, so their public-facing assets go offline immediately. On cancel, status flips back to `deletion_previous_status` (typically `active`) and everything comes back online automatically.

---

## Background purge

The existing `sidest:purge-soft-deletes` command gains `purgePendingDeletionProfessionals()`, called at the end of `handle()`. For each professional where `deletion_confirmed_at <= now() - 30 days`:

1. Call Supabase Admin API `DELETE /auth/v1/admin/users/{auth_user_id}`.
   - On `404` (already deleted), treat as success and continue.
   - On any other error, log audit event `purge_failed`, Nightwatch error, skip professional. Retry next day.
2. Call `professional->forceDelete()`. DB handles all cascades: customers, sites, blocks, integrations (already deleted at confirm), billing.subscriptions, notifications, analytics. The three RESTRICT-turned-SET-NULL FKs now null out rather than block.
3. On success, write audit log event `purged` (with professional handle + email snapshots captured before delete).

The command remains idempotent — professionals already purged are naturally skipped because they no longer exist in the DB.

---

## Components

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/Professional/ProfessionalAccountDeletionController.php` | `request`, `confirm`, `cancel` — thin, delegates to service |
| `app/Services/Professional/AccountDeletionService.php` | Token generation (Str::random(64) + sha256), status transitions, integration cleanup, Stripe scheduling, audit logging, Supabase Admin API call |
| `app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php` | Returns 423 on write methods for `pending_deletion` accounts |
| `app/Http/Middleware/Context/LoadCurrentProfessional.php` (modify) | Allow `pending_deletion` through auth gate |
| `app/Http/Controllers/Api/PublicSite/BootstrapController.php` (modify) | Reject `pending_deletion` on profile mutations |
| `app/Mail/Notifications/AccountDeletionRequestedMail.php` | 24hr expiry warning + confirmation link |
| `app/Mail/Notifications/AccountDeletionScheduledMail.php` | Deletion date + cancel link |
| `app/Mail/Notifications/AccountDeletionCancelledMail.php` | Cancellation confirmation |
| `app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php` | Eloquent model for audit table |
| `supabase/migrations/*_add_deletion_fields_to_professionals.sql` | Four new columns + status CHECK constraint + audit table |
| `supabase/migrations/*_nullable_commission_fks.sql` | The three RESTRICT → SET NULL migrations |
| `app/Console/Commands/PurgeSoftDeleted.php` (extend) | New `purgePendingDeletionProfessionals()` method |
| `routes/api/professional.php` (extend) | Three new routes with throttle middleware |

No other new models or observers.

---

## Error Handling & Edge Cases

| Scenario | Behaviour |
|----------|-----------|
| Token not clicked within 24 hours | Next confirm: 410 Gone, token columns cleared. Can re-request. |
| Re-request during grace period | 409 Conflict with `deletes_at` date |
| Suspended/disabled account requests deletion | 403 Forbidden |
| Unsettled obligations on request | 422 Unprocessable with reason array |
| Rate limit exceeded | 429 with Retry-After header |
| Mail send fails on request | Transaction rolled back, 503 returned |
| Stripe OAuth revoke fails on confirm | Log, continue (integration row still deleted) |
| Stripe cancel-at-period-end fails on confirm | Log to Nightwatch, continue |
| Stripe reversal fails on cancel | Log, continue (user still gets account back) |
| Supabase Admin API failure during purge | Audit `purge_failed`, Nightwatch error, skip, retry next day |
| Supabase auth user already deleted (404) | Treat as success, proceed with `forceDelete()` |
| FK violation during purge despite migration | Audit `purge_failed`, Nightwatch, skip, retry |
| Cancel when `status !== 'pending_deletion'` | 409 Conflict |
| Cancel with null `deletion_previous_status` | Fallback to `'active'` |

---

## Testing

**Location:** `tests/Feature/Professional/AccountDeletion/`

| Test file | Coverage |
|-----------|---------|
| `RequestDeletionTest` | Happy path; 409 during grace period; 403 suspended/disabled; 422 with each unsettled-obligation reason; 429 rate limit; mail failure rolls back; token stored as hash only |
| `ConfirmDeletionTest` | Valid token confirms; `deletion_previous_status` snapshot correct; integrations deleted; Stripe scheduled; 410 expired token; 404 wrong token; 404 reused token (one-time use); `hash_equals` used for comparison |
| `CancelDeletionTest` | Grace period cancel restores correct previous status; 409 when not pending_deletion; Stripe reversal attempted; audit entry written |
| `ReadOnlyEnforcementTest` | 423 on write methods; GET/HEAD/OPTIONS pass; cancel + logout endpoints exempt |
| `LoadCurrentProfessionalTest` (modify) | `pending_deletion` account passes auth gate; `suspended`/`disabled` still rejected |
| `PublicVisibilityTest` | Public site/brand/affiliate endpoints return unavailable for `pending_deletion` professionals; restore when cancelled |
| `PurgePendingDeletionTest` | ≥30 days confirmed → Supabase API called, forceDelete called, audit row written; <30 days skipped; Supabase 404 treated as success; Supabase 500 skips forceDelete and logs `purge_failed`; commission_payouts rows have professional_id set null after delete |
| `AuditLogTest` | Every state transition writes an audit row with correct `event`, handle+email snapshots, IP, user agent |

Stripe and Supabase Admin API calls mocked via `Http::fake()`. Tests use SQLite in-memory — the status CHECK constraint and FK SET NULL behavior are covered in a dedicated Pest feature test against the real Supabase migration when that environment is available (otherwise asserted on the SQL text).

---

## Out of Scope (deferred)

- Data export before deletion (GDPR portability) — revisit post-beta.
- Staff-facing "professional deleted their account" notification — nice-to-have, not blocking.
- Re-registration flow/UX after deletion — the DB supports it (unique indexes use `deleted_at IS NULL` and the row is hard-deleted, freeing email/handle), but the sign-up flow itself is unchanged.
