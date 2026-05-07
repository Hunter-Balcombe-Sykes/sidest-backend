# Account Deletion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement self-service account deletion for professionals — email-confirmed grace period, read-only enforcement, automated hard delete after 30 days with FK anonymization and Supabase auth cleanup.

**Architecture:** Extend existing `core.professionals` with deletion state columns, add a new `pending_deletion` status, migrate three RESTRICT FKs to SET NULL, add a dedicated audit table. Business logic lives in a new `AccountDeletionService`; HTTP is handled by a thin controller; background hard-delete reuses the existing `sidest:purge-soft-deletes` command.

**Tech Stack:** Laravel 12, PostgreSQL (Supabase), Pest 4, SQLite in-memory for tests, Laravel Mail, Http facade for Supabase Admin API.

**Spec:** `docs/superpowers/specs/2026-04-19-account-deletion-design.md`

---

## File Structure

### Created

| Path | Responsibility |
|------|---------------|
| `supabase/migrations/20260419000001_add_deletion_fields_to_professionals.sql` | New columns + status CHECK + audit table |
| `supabase/migrations/20260419000002_nullable_commission_fks.sql` | 3 RESTRICT → SET NULL FK migrations |
| `app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php` | Eloquent model for audit table |
| `app/Services/Professional/AccountDeletionService.php` | All deletion business logic |
| `app/Http/Controllers/Api/Professional/ProfessionalAccountDeletionController.php` | Thin HTTP layer: request/confirm/cancel |
| `app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php` | 423 on writes for `pending_deletion` accounts |
| `app/Mail/Notifications/AccountDeletionRequestedMail.php` | Confirmation email (token link) |
| `app/Mail/Notifications/AccountDeletionScheduledMail.php` | Scheduled email (deletion date + cancel link) |
| `app/Mail/Notifications/AccountDeletionCancelledMail.php` | Cancellation confirmation |
| `resources/views/emails/account/deletion-requested.blade.php` | Blade view |
| `resources/views/emails/account/deletion-scheduled.blade.php` | Blade view |
| `resources/views/emails/account/deletion-cancelled.blade.php` | Blade view |
| `tests/Feature/Professional/AccountDeletion/AccountDeletionTestCase.php` | Shared SQLite schema setup |
| `tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php` | Request flow tests |
| `tests/Feature/Professional/AccountDeletion/ConfirmDeletionTest.php` | Confirm flow tests |
| `tests/Feature/Professional/AccountDeletion/CancelDeletionTest.php` | Cancel flow tests |
| `tests/Feature/Professional/AccountDeletion/ReadOnlyEnforcementTest.php` | Middleware tests |
| `tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php` | Purge command tests |

### Modified

| Path | Change |
|------|--------|
| `app/Models/Core/Professional/Professional.php` | Add 4 new fields to `$fillable` + datetime casts |
| `app/Http/Middleware/Context/LoadCurrentProfessional.php:47` | Allow `pending_deletion` through auth gate |
| `app/Http/Controllers/Api/PublicSite/BootstrapController.php:99` | Reject `pending_deletion` on profile mutations |
| `app/Console/Commands/PurgeSoftDeleted.php` | Add `purgePendingDeletionProfessionals()` call |
| `routes/api/professional.php` | Register 3 new routes with throttle + middleware |

---

## Task 1: Supabase Migrations

**Files:**
- Create: `supabase/migrations/20260419000001_add_deletion_fields_to_professionals.sql`
- Create: `supabase/migrations/20260419000002_nullable_commission_fks.sql`

- [ ] **Step 1: Create deletion fields migration**

Create `supabase/migrations/20260419000001_add_deletion_fields_to_professionals.sql`:

```sql
-- Add deletion state columns to core.professionals
ALTER TABLE core.professionals
  ADD COLUMN IF NOT EXISTS deletion_token_hash text,
  ADD COLUMN IF NOT EXISTS deletion_requested_at timestamptz,
  ADD COLUMN IF NOT EXISTS deletion_confirmed_at timestamptz,
  ADD COLUMN IF NOT EXISTS deletion_previous_status text;

-- Index for fast token lookup during confirmation
CREATE INDEX IF NOT EXISTS idx_professionals_deletion_token_hash
  ON core.professionals (deletion_token_hash)
  WHERE deletion_token_hash IS NOT NULL;

-- Index for efficient purge query (finding accounts past grace period)
CREATE INDEX IF NOT EXISTS idx_professionals_pending_deletion_cutoff
  ON core.professionals (deletion_confirmed_at)
  WHERE status = 'pending_deletion';

-- Add status CHECK constraint (currently plain text with no validation)
ALTER TABLE core.professionals
  DROP CONSTRAINT IF EXISTS professionals_status_check;

ALTER TABLE core.professionals
  ADD CONSTRAINT professionals_status_check
  CHECK (status IN ('active', 'suspended', 'disabled', 'pending_deletion'));

-- Audit table — survives the professional's hard delete via identity snapshots
CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid REFERENCES core.professionals(id) ON DELETE SET NULL,
    professional_handle_snapshot text NOT NULL,
    professional_email_snapshot text NOT NULL,
    event text NOT NULL CHECK (event IN ('requested', 'confirmed', 'cancelled', 'purged', 'purge_failed')),
    ip_address text,
    user_agent text,
    metadata jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE core.professional_deletion_audit OWNER TO postgres;

CREATE INDEX idx_pda_professional_id ON core.professional_deletion_audit (professional_id);
CREATE INDEX idx_pda_event_created ON core.professional_deletion_audit (event, created_at DESC);
```

- [ ] **Step 2: Create commission FK nullability migration**

Create `supabase/migrations/20260419000002_nullable_commission_fks.sql`:

```sql
-- Migrate 3 RESTRICT FKs to SET NULL so forceDelete() on a professional does not
-- block. Columns become nullable; application code must tolerate null professional
-- references (rendered as "Deleted user" in staff UI).

-- commerce.commission_payouts — brand_professional_id + affiliate_professional_id
ALTER TABLE commerce.commission_payouts
    ALTER COLUMN brand_professional_id DROP NOT NULL,
    ALTER COLUMN affiliate_professional_id DROP NOT NULL;

ALTER TABLE commerce.commission_payouts
    DROP CONSTRAINT IF EXISTS commission_payouts_brand_professional_id_fkey,
    DROP CONSTRAINT IF EXISTS commission_payouts_affiliate_professional_id_fkey;

ALTER TABLE commerce.commission_payouts
    ADD CONSTRAINT commission_payouts_brand_professional_id_fkey
      FOREIGN KEY (brand_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL,
    ADD CONSTRAINT commission_payouts_affiliate_professional_id_fkey
      FOREIGN KEY (affiliate_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

-- commerce.brand_commission_topups — brand_professional_id
ALTER TABLE commerce.brand_commission_topups
    ALTER COLUMN brand_professional_id DROP NOT NULL;

ALTER TABLE commerce.brand_commission_topups
    DROP CONSTRAINT IF EXISTS brand_commission_topups_brand_professional_id_fkey;

ALTER TABLE commerce.brand_commission_topups
    ADD CONSTRAINT brand_commission_topups_brand_professional_id_fkey
      FOREIGN KEY (brand_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;
```

- [ ] **Step 3: Commit**

```bash
cd "/Users/joshuahunter/Herd/Partna/backend"
git add supabase/migrations/20260419000001_add_deletion_fields_to_professionals.sql \
        supabase/migrations/20260419000002_nullable_commission_fks.sql
git commit -m "$(cat <<'EOF'
feat(schema): add account deletion fields + audit table + nullable commission FKs

- Add deletion_token_hash, deletion_requested_at, deletion_confirmed_at,
  deletion_previous_status columns to core.professionals
- Add status CHECK constraint (active|suspended|disabled|pending_deletion)
- Add core.professional_deletion_audit table for lifecycle audit trail
- Migrate 3 RESTRICT FKs (commission_payouts x2, brand_commission_topups) to
  SET NULL so forceDelete() on a professional does not block

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Professional Model — Add Deletion Fields

**Files:**
- Modify: `app/Models/Core/Professional/Professional.php`

- [ ] **Step 1: Add fields to `$fillable`**

Find the `$fillable` array (around line 47) and add the four deletion fields at the end, just before the closing `];`:

```php
    protected $fillable = [
        // ... existing fields ...
        'stripe_grace_period_ends_at',

        // Account deletion lifecycle
        'deletion_token_hash',
        'deletion_requested_at',
        'deletion_confirmed_at',
        'deletion_previous_status',
    ];
```

- [ ] **Step 2: Add datetime casts**

Find the `$casts` array (around line 87) and add datetime casts for the two timestamp fields:

```php
    protected $casts = [
        'onboarding_step' => 'integer',
        'stripe_manual_balance_cents' => 'integer',
        'stripe_grace_period_ends_at' => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
        'deleted_at'      => 'datetime',
        'deletion_requested_at' => 'datetime',
        'deletion_confirmed_at' => 'datetime',
    ];
```

- [ ] **Step 3: Hide deletion_token_hash**

Find the `$hidden` array (around line 37) and add `deletion_token_hash`:

```php
    protected $hidden = [
        'auth_user_id',
        'stripe_connect_account_id',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'stripe_commission_funding_mode',
        'stripe_manual_balance_cents',
        'stripe_manual_balance_currency',
        'deletion_token_hash',
    ];
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/Core/Professional/Professional.php
git commit -m "$(cat <<'EOF'
feat(professional): add deletion lifecycle fields to model

- Fillable/casts for deletion_token_hash, deletion_requested_at,
  deletion_confirmed_at, deletion_previous_status
- Hide deletion_token_hash from API responses

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: ProfessionalDeletionAuditEntry Model

**Files:**
- Create: `app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php`

- [ ] **Step 1: Create the model**

Create `app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php`:

```php
<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Audit trail for professional account deletion lifecycle. Rows survive the
// professional's hard delete via handle/email snapshots captured at write time.
class ProfessionalDeletionAuditEntry extends BaseModel
{
    use HasUuids;

    protected $table = 'core.professional_deletion_audit';

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // only created_at; no updated_at

    public const EVENT_REQUESTED = 'requested';
    public const EVENT_CONFIRMED = 'confirmed';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_PURGED = 'purged';
    public const EVENT_PURGE_FAILED = 'purge_failed';

    protected $fillable = [
        'professional_id',
        'professional_handle_snapshot',
        'professional_email_snapshot',
        'event',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php
git commit -m "$(cat <<'EOF'
feat(model): add ProfessionalDeletionAuditEntry

Eloquent model for core.professional_deletion_audit. Rows survive the
professional's hard delete via handle/email snapshots captured at write time.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Mail Classes + Blade Views

**Files:**
- Create: `app/Mail/Notifications/AccountDeletionRequestedMail.php`
- Create: `app/Mail/Notifications/AccountDeletionScheduledMail.php`
- Create: `app/Mail/Notifications/AccountDeletionCancelledMail.php`
- Create: `resources/views/emails/account/deletion-requested.blade.php`
- Create: `resources/views/emails/account/deletion-scheduled.blade.php`
- Create: `resources/views/emails/account/deletion-cancelled.blade.php`

- [ ] **Step 1: Create AccountDeletionRequestedMail**

Create `app/Mail/Notifications/AccountDeletionRequestedMail.php`:

```php
<?php

namespace App\Mail\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Confirmation email sent when a professional requests account deletion.
// Contains a token-bearing link that expires in 24 hours.
class AccountDeletionRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $displayName,
        public readonly string $confirmationUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Confirm your account deletion request')
            ->view('emails.account.deletion-requested');
    }
}
```

- [ ] **Step 2: Create AccountDeletionScheduledMail**

Create `app/Mail/Notifications/AccountDeletionScheduledMail.php`:

```php
<?php

namespace App\Mail\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Sent after confirmation — 30-day grace period is running. Includes the
// scheduled deletion date and a one-click cancel link.
class AccountDeletionScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $displayName,
        public readonly string $deletesAt,
        public readonly string $cancelUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Your account is scheduled for deletion')
            ->view('emails.account.deletion-scheduled');
    }
}
```

- [ ] **Step 3: Create AccountDeletionCancelledMail**

Create `app/Mail/Notifications/AccountDeletionCancelledMail.php`:

```php
<?php

namespace App\Mail\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Sent when a professional cancels their pending deletion during the grace period.
class AccountDeletionCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $displayName,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Your account deletion has been cancelled')
            ->view('emails.account.deletion-cancelled');
    }
}
```

- [ ] **Step 4: Create deletion-requested view**

Create `resources/views/emails/account/deletion-requested.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm your account deletion</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; }
        h2 { margin-top: 0; font-size: 20px; color: #111; }
        p { color: #444; line-height: 1.6; }
        .btn { display: inline-block; margin-top: 16px; padding: 12px 24px; background: #c0392b; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; }
        .warn { color: #888; font-size: 13px; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Confirm your account deletion</h2>
        <p>Hi {{ $displayName }},</p>
        <p>We received a request to delete your Partna account. To confirm, click the button below.</p>
        <a href="{{ $confirmationUrl }}" class="btn">Confirm deletion</a>
        <p class="warn">This link expires in 24 hours. If you did not request this, ignore this email and your account will remain active.</p>
    </div>
</body>
</html>
```

- [ ] **Step 5: Create deletion-scheduled view**

Create `resources/views/emails/account/deletion-scheduled.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your account is scheduled for deletion</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; }
        h2 { margin-top: 0; font-size: 20px; color: #111; }
        p { color: #444; line-height: 1.6; }
        .btn { display: inline-block; margin-top: 16px; padding: 12px 24px; background: #111; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; }
        .date { font-weight: 600; color: #111; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Your account is scheduled for deletion</h2>
        <p>Hi {{ $displayName }},</p>
        <p>Your Partna account will be permanently deleted on <span class="date">{{ $deletesAt }}</span>.</p>
        <p>Your account is now in read-only mode and your public site, brand configuration, and affiliate pages are offline. You can still log in to cancel the deletion at any time during this window.</p>
        <a href="{{ $cancelUrl }}" class="btn">Cancel deletion</a>
    </div>
</body>
</html>
```

- [ ] **Step 6: Create deletion-cancelled view**

Create `resources/views/emails/account/deletion-cancelled.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your account deletion has been cancelled</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 560px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; }
        h2 { margin-top: 0; font-size: 20px; color: #111; }
        p { color: #444; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Your account deletion has been cancelled</h2>
        <p>Hi {{ $displayName }},</p>
        <p>Your Partna account deletion has been cancelled. Your account is active again and your public site, brand configuration, and affiliate pages are back online.</p>
        <p>If you did not request this, please contact support immediately.</p>
    </div>
</body>
</html>
```

- [ ] **Step 7: Commit**

```bash
git add app/Mail/Notifications/AccountDeletion*.php resources/views/emails/account/
git commit -m "$(cat <<'EOF'
feat(mail): add account deletion email classes + views

- AccountDeletionRequestedMail: 24hr-expiry confirmation link
- AccountDeletionScheduledMail: grace period active, deletion date + cancel link
- AccountDeletionCancelledMail: cancellation confirmation

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: AccountDeletionService — Skeleton + Obligations Check

**Files:**
- Create: `app/Services/Professional/AccountDeletionService.php`
- Create: `tests/Feature/Professional/AccountDeletion/AccountDeletionTestCase.php`
- Create: `tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php`

- [ ] **Step 1: Create the shared test setup file**

Create `tests/Feature/Professional/AccountDeletion/AccountDeletionTestCase.php`:

```php
<?php

namespace Tests\Feature\Professional\AccountDeletion;

use Illuminate\Support\Facades\DB;

// Shared SQLite schema setup for account-deletion feature tests.
// Mirrors the pattern from BrandBootstrapTest — attaches each schema as its own
// in-memory DB so schema-qualified table names (core.*, commerce.*, etc.) resolve.
class AccountDeletionTestCase
{
    public static function boot(): void
    {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
            'supabase.url' => 'https://test.supabase.co',
            'supabase.service_role_key' => 'test-service-role-key',
            'app.frontend_url' => 'https://app.sidest.test',
            'sidest.soft_delete_retention_days' => 30,
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $conn = DB::connection('pgsql');

        foreach (['core', 'commerce', 'notifications', 'billing'] as $schema) {
            try {
                $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
            } catch (\Throwable) {
            }
        }

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
            id TEXT PRIMARY KEY,
            auth_user_id TEXT,
            handle TEXT,
            handle_lc TEXT,
            display_name TEXT,
            primary_email TEXT,
            professional_type TEXT DEFAULT "professional",
            status TEXT DEFAULT "active",
            onboarding_step INTEGER DEFAULT 0,
            stripe_manual_balance_cents INTEGER DEFAULT 0,
            deletion_token_hash TEXT,
            deletion_requested_at TEXT,
            deletion_confirmed_at TEXT,
            deletion_previous_status TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            professional_handle_snapshot TEXT NOT NULL,
            professional_email_snapshot TEXT NOT NULL,
            event TEXT NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            metadata TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            provider TEXT,
            access_token TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
            id TEXT PRIMARY KEY,
            brand_professional_id TEXT,
            affiliate_professional_id TEXT,
            status TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.brand_commission_topups (
            id TEXT PRIMARY KEY,
            brand_professional_id TEXT,
            status TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            stripe_subscription_id TEXT,
            status TEXT,
            cancel_at_period_end INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT
        )');
    }
}
```

- [ ] **Step 2: Write failing test for obligations check**

Create `tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Professional\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function makeProfessional(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'test-' . substr($id, 0, 8),
        'handle_lc' => 'test-' . substr($id, 0, 8),
        'display_name' => 'Test Pro',
        'primary_email' => 'test-' . substr($id, 0, 8) . '@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($data);
    return Professional::query()->where('id', $id)->first();
}

it('rejects request when professional has unpaid balance', function () {
    $pro = makeProfessional(['stripe_manual_balance_cents' => 1000]);

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(422)
        ->and($result['reasons'])->toContain('unpaid_balance');
});

it('rejects request when professional has pending commission payouts', function () {
    $pro = makeProfessional();

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $pro->id,
        'affiliate_professional_id' => (string) Str::uuid(),
        'status' => 'pending',
        'amount_cents' => 500,
        'created_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeFalse()
        ->and($result['reasons'])->toContain('pending_payouts');
});

it('rejects request when brand has pending topups', function () {
    $pro = makeProfessional();

    DB::connection('pgsql')->table('commerce.brand_commission_topups')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $pro->id,
        'status' => 'pending',
        'amount_cents' => 5000,
        'created_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeFalse()
        ->and($result['reasons'])->toContain('pending_topups');
});
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
cd "/Users/joshuahunter/Herd/Partna/backend"
php artisan config:clear && ./vendor/bin/pest tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php
```

Expected: FAIL with "Class AccountDeletionService not found".

- [ ] **Step 4: Create AccountDeletionService with obligations check**

Create `app/Services/Professional/AccountDeletionService.php`:

```php
<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: All account-deletion business logic. Called from
// ProfessionalAccountDeletionController for request/confirm/cancel flows, and
// from PurgeSoftDeleted command for hard-delete after grace period.
class AccountDeletionService
{
    /**
     * Initiate a deletion request. Checks preconditions, stores hashed token,
     * sends confirmation email.
     *
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>}
     */
    public function request(Professional $professional, Request $request): array
    {
        $obligations = $this->checkObligations($professional);
        if (! empty($obligations)) {
            return [
                'success' => false,
                'code' => 422,
                'error' => 'Outstanding obligations must be settled before deletion.',
                'reasons' => $obligations,
            ];
        }

        // Token generation + mail sending implemented in later tasks.
        return ['success' => true, 'code' => 200];
    }

    /**
     * Check for unsettled financial obligations. Returns reason codes.
     *
     * @return array<string>
     */
    private function checkObligations(Professional $professional): array
    {
        $reasons = [];

        if ((int) ($professional->stripe_manual_balance_cents ?? 0) > 0) {
            $reasons[] = 'unpaid_balance';
        }

        $hasPendingPayouts = DB::connection('pgsql')
            ->table('commerce.commission_payouts')
            ->where(function ($q) use ($professional) {
                $q->where('brand_professional_id', $professional->id)
                  ->orWhere('affiliate_professional_id', $professional->id);
            })
            ->where('status', '!=', 'paid')
            ->exists();

        if ($hasPendingPayouts) {
            $reasons[] = 'pending_payouts';
        }

        $hasPendingTopups = DB::connection('pgsql')
            ->table('commerce.brand_commission_topups')
            ->where('brand_professional_id', $professional->id)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($hasPendingTopups) {
            $reasons[] = 'pending_topups';
        }

        return $reasons;
    }

    /**
     * Append an audit row. Captures handle/email snapshots so the row survives
     * the professional's eventual hard delete.
     */
    public function logAuditEvent(
        Professional $professional,
        string $event,
        ?Request $request = null,
        array $metadata = []
    ): void {
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => $professional->id,
            'professional_handle_snapshot' => (string) ($professional->handle ?? ''),
            'professional_email_snapshot' => (string) ($professional->primary_email ?? ''),
            'event' => $event,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => ! empty($metadata) ? $metadata : null,
            'created_at' => now(),
        ]);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php
```

Expected: 3 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Professional/AccountDeletionService.php \
        tests/Feature/Professional/AccountDeletion/
git commit -m "$(cat <<'EOF'
feat(deletion): add AccountDeletionService with obligations precondition

Service checks for unpaid balance, pending commission payouts, and pending
brand commission topups. Tests verify each reason code fires independently.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: AccountDeletionService — Request Method (Token + Mail)

**Files:**
- Modify: `app/Services/Professional/AccountDeletionService.php`
- Modify: `tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php`

- [ ] **Step 1: Add failing tests for happy path + rollback**

Append to `tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php`:

```php
it('stores hashed token, sets requested_at, and sends confirmation mail', function () {
    $pro = makeProfessional();

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $pro->refresh();
    expect($pro->deletion_token_hash)->not->toBeNull()
        ->and(strlen($pro->deletion_token_hash))->toBe(64) // sha256 hex
        ->and($pro->deletion_requested_at)->not->toBeNull()
        ->and($pro->status)->toBe('active'); // status does NOT change on request

    Mail::assertSent(\App\Mail\Notifications\AccountDeletionRequestedMail::class, function ($mail) use ($pro) {
        return $mail->hasTo($pro->primary_email);
    });
});

it('writes a requested audit entry on successful request', function () {
    $pro = makeProfessional();
    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '1.2.3.4', 'HTTP_USER_AGENT' => 'TestAgent']);

    $service->request($pro, $request);

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('professional_id', $pro->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->event)->toBe('requested')
        ->and($audit->professional_handle_snapshot)->toBe($pro->handle)
        ->and($audit->professional_email_snapshot)->toBe($pro->primary_email)
        ->and($audit->ip_address)->toBe('1.2.3.4')
        ->and($audit->user_agent)->toBe('TestAgent');
});

it('rolls back token storage if mail send throws', function () {
    $pro = makeProfessional();

    Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

    $service = new AccountDeletionService();
    $request = Request::create('/', 'POST');

    $result = $service->request($pro, $request);

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(503);

    $pro->refresh();
    expect($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php
```

Expected: FAIL — token not stored, mail not sent.

- [ ] **Step 3: Implement token generation + mail in request method**

Replace the `request` method in `app/Services/Professional/AccountDeletionService.php` with the full implementation. Also add a `use` statement for the mail class and `Mail`/`Log` facades. The complete updated file:

```php
<?php

namespace App\Services\Professional;

use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// V2: All account-deletion business logic. Called from
// ProfessionalAccountDeletionController for request/confirm/cancel flows, and
// from PurgeSoftDeleted command for hard-delete after grace period.
class AccountDeletionService
{
    /**
     * Initiate a deletion request. Checks preconditions, stores hashed token,
     * sends confirmation email. Rolls back token storage if mail send fails.
     *
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>}
     */
    public function request(Professional $professional, Request $request): array
    {
        $obligations = $this->checkObligations($professional);
        if (! empty($obligations)) {
            return [
                'success' => false,
                'code' => 422,
                'error' => 'Outstanding obligations must be settled before deletion.',
                'reasons' => $obligations,
            ];
        }

        $rawToken = Str::random(64);
        $tokenHash = hash('sha256', $rawToken);

        $professional->update([
            'deletion_token_hash' => $tokenHash,
            'deletion_requested_at' => now(),
        ]);

        $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
            . '/account/deletion/confirm?token=' . $rawToken;

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionRequestedMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                    confirmationUrl: $confirmationUrl,
                )
            );
        } catch (\Throwable $e) {
            // Mail failed — roll back token so user can retry cleanly.
            $professional->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);

            Log::error('Account deletion request mail failed', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'code' => 503,
                'error' => 'Failed to send confirmation email. Please try again.',
            ];
        }

        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_REQUESTED, $request);

        return ['success' => true, 'code' => 200];
    }

    /**
     * Check for unsettled financial obligations. Returns reason codes.
     *
     * @return array<string>
     */
    private function checkObligations(Professional $professional): array
    {
        $reasons = [];

        if ((int) ($professional->stripe_manual_balance_cents ?? 0) > 0) {
            $reasons[] = 'unpaid_balance';
        }

        $hasPendingPayouts = DB::connection('pgsql')
            ->table('commerce.commission_payouts')
            ->where(function ($q) use ($professional) {
                $q->where('brand_professional_id', $professional->id)
                  ->orWhere('affiliate_professional_id', $professional->id);
            })
            ->where('status', '!=', 'paid')
            ->exists();

        if ($hasPendingPayouts) {
            $reasons[] = 'pending_payouts';
        }

        $hasPendingTopups = DB::connection('pgsql')
            ->table('commerce.brand_commission_topups')
            ->where('brand_professional_id', $professional->id)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($hasPendingTopups) {
            $reasons[] = 'pending_topups';
        }

        return $reasons;
    }

    /**
     * Append an audit row. Captures handle/email snapshots so the row survives
     * the professional's eventual hard delete.
     */
    public function logAuditEvent(
        Professional $professional,
        string $event,
        ?Request $request = null,
        array $metadata = []
    ): void {
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => $professional->id,
            'professional_handle_snapshot' => (string) ($professional->handle ?? ''),
            'professional_email_snapshot' => (string) ($professional->primary_email ?? ''),
            'event' => $event,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => ! empty($metadata) ? $metadata : null,
            'created_at' => now(),
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php
```

Expected: all 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/AccountDeletionService.php \
        tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php
git commit -m "$(cat <<'EOF'
feat(deletion): implement request method with hashed token + mail + audit

- Generates 64-char random token, stores sha256 hash (raw token never persisted)
- Sends AccountDeletionRequestedMail with token-bearing confirmation link
- Rolls back token storage on mail failure
- Logs 'requested' audit event with IP + user agent

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: AccountDeletionService — Confirm Method

**Files:**
- Modify: `app/Services/Professional/AccountDeletionService.php`
- Create: `tests/Feature/Professional/AccountDeletion/ConfirmDeletionTest.php`

- [ ] **Step 1: Write failing tests for confirm flow**

Create `tests/Feature/Professional/AccountDeletion/ConfirmDeletionTest.php`:

```php
<?php

use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Professional\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function seedRequestedProfessional(string $rawToken = 'a-raw-token-64-chars-long-for-testing-purposes-1234567890123456', array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-' . substr($id, 0, 6),
        'handle_lc' => 'pro-' . substr($id, 0, 6),
        'display_name' => 'Pro',
        'primary_email' => 'pro-' . substr($id, 0, 6) . '@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
        'deletion_token_hash' => hash('sha256', $rawToken),
        'deletion_requested_at' => now()->toIso8601String(),
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($data);
    return Professional::query()->where('id', $id)->first();
}

it('confirms with valid token: flips status, snapshots previous status, nulls token', function () {
    $rawToken = 'raw-token-' . Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    $service = new AccountDeletionService();
    $result = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200)
        ->and($result['deletes_at'])->not->toBeEmpty();

    $pro->refresh();
    expect($pro->status)->toBe('pending_deletion')
        ->and($pro->deletion_previous_status)->toBe('active')
        ->and($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_confirmed_at)->not->toBeNull();

    Mail::assertSent(AccountDeletionScheduledMail::class);
});

it('deletes professional integrations at confirm time (security)', function () {
    $rawToken = 'raw-token-' . Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'provider' => 'shopify',
        'access_token' => 'shpat_secret_token',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService();
    $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    $count = DB::connection('pgsql')->table('core.professional_integrations')
        ->where('professional_id', $pro->id)->count();

    expect($count)->toBe(0);
});

it('rejects with 410 when token is older than 24 hours', function () {
    $rawToken = 'raw-token-' . Str::random(54);
    $pro = seedRequestedProfessional($rawToken, [
        'deletion_requested_at' => Carbon::now()->subHours(25)->toIso8601String(),
    ]);

    $service = new AccountDeletionService();
    $result = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(410);

    $pro->refresh();
    expect($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});

it('rejects with 404 when token does not match', function () {
    $rawToken = 'raw-token-' . Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    $service = new AccountDeletionService();
    $result = $service->confirm($pro, 'wrong-token', Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(404);

    $pro->refresh();
    expect($pro->status)->toBe('active');
});

it('rejects with 404 when no deletion request exists', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'plain',
        'handle_lc' => 'plain',
        'display_name' => 'Plain',
        'primary_email' => 'plain@example.com',
        'status' => 'active',
    ]);
    $pro = Professional::query()->where('id', $id)->first();

    $service = new AccountDeletionService();
    $result = $service->confirm($pro, 'any-token', Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(404);
});

it('writes confirmed audit event', function () {
    $rawToken = 'raw-token-' . Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    $service = new AccountDeletionService();
    $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('professional_id', $pro->id)
        ->where('event', 'confirmed')
        ->first();

    expect($audit)->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/ConfirmDeletionTest.php
```

Expected: FAIL — `confirm` method does not exist.

- [ ] **Step 3: Add confirm method to AccountDeletionService**

Add new imports at the top of `app/Services/Professional/AccountDeletionService.php`:

```php
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Stripe\StripeBillingService;
```

Add the `confirm` method inside the class (after `request`):

```php
    /**
     * Confirm deletion via token. Snapshots previous status, flips to
     * pending_deletion, deletes integration credentials, schedules Stripe
     * cancel-at-period-end, sends scheduled mail.
     *
     * @return array{success: bool, code: int, error?: string, deletes_at?: string}
     */
    public function confirm(Professional $professional, string $rawToken, Request $request): array
    {
        // No deletion request on file?
        if (! $professional->deletion_token_hash || ! $professional->deletion_requested_at) {
            return ['success' => false, 'code' => 404, 'error' => 'No deletion request found.'];
        }

        // Token expired?
        $requestedAt = $professional->deletion_requested_at instanceof \DateTimeInterface
            ? \Illuminate\Support\Carbon::instance($professional->deletion_requested_at)
            : \Illuminate\Support\Carbon::parse((string) $professional->deletion_requested_at);

        if ($requestedAt->lt(now()->subHours(24))) {
            $professional->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);
            return ['success' => false, 'code' => 410, 'error' => 'Confirmation token has expired.'];
        }

        // Token mismatch? Timing-safe comparison.
        if (! hash_equals((string) $professional->deletion_token_hash, hash('sha256', $rawToken))) {
            return ['success' => false, 'code' => 404, 'error' => 'Invalid token.'];
        }

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

        $cancelUrl = rtrim((string) config('app.frontend_url'), '/') . '/account/deletion/cancel';

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
            // Do not fail the confirm — the deletion itself is more important
            // than the mail delivery. Cancel flow is still available via logged-in session.
        }

        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_CONFIRMED, $request);

        return [
            'success' => true,
            'code' => 200,
            'deletes_at' => $deletesAt->toIso8601String(),
        ];
    }

    /**
     * Schedule Stripe subscription to cancel at the end of the current billing
     * period. Best effort — log and continue on failure.
     */
    private function cancelStripeAtPeriodEnd(Professional $professional): void
    {
        try {
            $subscription = DB::connection('pgsql')
                ->table('billing.subscriptions')
                ->where('professional_id', $professional->id)
                ->whereNotNull('stripe_subscription_id')
                ->first();

            if (! $subscription || empty($subscription->stripe_subscription_id)) {
                return;
            }

            if (! config('services.stripe.secret_key')) {
                return; // Stripe not configured (e.g. test env) — skip.
            }

            $billing = app(StripeBillingService::class);
            $billing->cancelSubscriptionAtPeriodEnd($subscription->stripe_subscription_id);
        } catch (\Throwable $e) {
            Log::error('Stripe cancel-at-period-end failed during deletion confirm', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/ConfirmDeletionTest.php
```

Expected: all 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/AccountDeletionService.php \
        tests/Feature/Professional/AccountDeletion/ConfirmDeletionTest.php
git commit -m "$(cat <<'EOF'
feat(deletion): implement confirm method

- Timing-safe token comparison via hash_equals
- Snapshots previous status to deletion_previous_status
- Deletes professional_integrations rows immediately (credential security)
- Schedules Stripe cancel-at-period-end (best effort)
- Sends AccountDeletionScheduledMail with grace-period date + cancel link
- Returns 410 on expired token, 404 on missing/invalid token

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: AccountDeletionService — Cancel Method

**Files:**
- Modify: `app/Services/Professional/AccountDeletionService.php`
- Create: `tests/Feature/Professional/AccountDeletion/CancelDeletionTest.php`

- [ ] **Step 1: Write failing tests for cancel flow**

Create `tests/Feature/Professional/AccountDeletion/CancelDeletionTest.php`:

```php
<?php

use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Professional\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function seedPendingDeletionProfessional(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-' . substr($id, 0, 6),
        'handle_lc' => 'pro-' . substr($id, 0, 6),
        'display_name' => 'Pro',
        'primary_email' => 'pro-' . substr($id, 0, 6) . '@example.com',
        'status' => 'pending_deletion',
        'deletion_previous_status' => 'active',
        'deletion_confirmed_at' => now()->toIso8601String(),
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($data);
    return Professional::query()->where('id', $id)->first();
}

it('restores previous status on cancel', function () {
    $pro = seedPendingDeletionProfessional(['deletion_previous_status' => 'active']);

    $service = new AccountDeletionService();
    $result = $service->cancel($pro, Request::create('/', 'POST'));

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $pro->refresh();
    expect($pro->status)->toBe('active')
        ->and($pro->deletion_previous_status)->toBeNull()
        ->and($pro->deletion_confirmed_at)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});

it('falls back to active when previous_status is null', function () {
    $pro = seedPendingDeletionProfessional(['deletion_previous_status' => null]);

    $service = new AccountDeletionService();
    $service->cancel($pro, Request::create('/', 'POST'));

    $pro->refresh();
    expect($pro->status)->toBe('active');
});

it('sends cancellation mail', function () {
    $pro = seedPendingDeletionProfessional();

    $service = new AccountDeletionService();
    $service->cancel($pro, Request::create('/', 'POST'));

    Mail::assertSent(AccountDeletionCancelledMail::class, function ($mail) use ($pro) {
        return $mail->hasTo($pro->primary_email);
    });
});

it('writes cancelled audit event', function () {
    $pro = seedPendingDeletionProfessional();

    $service = new AccountDeletionService();
    $service->cancel($pro, Request::create('/', 'POST'));

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('professional_id', $pro->id)
        ->where('event', 'cancelled')
        ->first();

    expect($audit)->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/CancelDeletionTest.php
```

Expected: FAIL — `cancel` method does not exist.

- [ ] **Step 3: Add cancel method and Stripe resume helper**

Add this import at the top of `app/Services/Professional/AccountDeletionService.php`:

```php
use App\Mail\Notifications\AccountDeletionCancelledMail;
```

Add the `cancel` method inside the class (after `confirm`):

```php
    /**
     * Cancel a pending deletion during the grace period. Restores previous
     * status, clears deletion timestamps, attempts to reverse Stripe
     * cancel-at-period-end, sends cancellation mail.
     *
     * @return array{success: bool, code: int}
     */
    public function cancel(Professional $professional, Request $request): array
    {
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

        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_CANCELLED, $request);

        return ['success' => true, 'code' => 200];
    }

    /**
     * Reverse Stripe subscription cancel-at-period-end. Best effort — if the
     * billing period already ended, the subscription is gone and we log-and-continue.
     */
    private function resumeStripeSubscription(Professional $professional): void
    {
        try {
            $subscription = DB::connection('pgsql')
                ->table('billing.subscriptions')
                ->where('professional_id', $professional->id)
                ->whereNotNull('stripe_subscription_id')
                ->first();

            if (! $subscription || empty($subscription->stripe_subscription_id)) {
                return;
            }

            if (! config('services.stripe.secret_key')) {
                return;
            }

            $billing = app(StripeBillingService::class);
            $billing->resumeSubscription($subscription->stripe_subscription_id);
        } catch (\Throwable $e) {
            Log::error('Stripe subscription resume failed during deletion cancel', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/CancelDeletionTest.php
```

Expected: 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/AccountDeletionService.php \
        tests/Feature/Professional/AccountDeletion/CancelDeletionTest.php
git commit -m "$(cat <<'EOF'
feat(deletion): implement cancel method

- Restores deletion_previous_status (falls back to active if null)
- Clears all deletion timestamps + token hash
- Attempts Stripe resume-subscription (best effort)
- Sends AccountDeletionCancelledMail
- Logs 'cancelled' audit event

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: AccountDeletionService — Purge Method (Supabase + ForceDelete)

**Files:**
- Modify: `app/Services/Professional/AccountDeletionService.php`
- Create: `tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php`

- [ ] **Step 1: Write failing tests for purge**

Create `tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Professional\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
});

function seedPurgeableProfessional(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $authId = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => $authId,
        'handle' => 'purge-' . substr($id, 0, 6),
        'handle_lc' => 'purge-' . substr($id, 0, 6),
        'display_name' => 'To Purge',
        'primary_email' => 'purge-' . substr($id, 0, 6) . '@example.com',
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($data);
    return Professional::query()->where('id', $id)->first();
}

it('calls Supabase Admin API and hard-deletes professional on success', function () {
    $pro = seedPurgeableProfessional();

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    $service = new AccountDeletionService();
    $result = $service->purge($pro);

    expect($result)->toBeTrue();

    $stillExists = DB::connection('pgsql')->table('core.professionals')
        ->where('id', $pro->id)->exists();
    expect($stillExists)->toBeFalse();

    Http::assertSent(function ($request) use ($pro) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), "/auth/v1/admin/users/{$pro->auth_user_id}");
    });
});

it('treats Supabase 404 as success and still hard-deletes professional', function () {
    $pro = seedPurgeableProfessional();

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response(['message' => 'User not found'], 404),
    ]);

    $service = new AccountDeletionService();
    $result = $service->purge($pro);

    expect($result)->toBeTrue();

    $stillExists = DB::connection('pgsql')->table('core.professionals')
        ->where('id', $pro->id)->exists();
    expect($stillExists)->toBeFalse();
});

it('skips hard delete and logs purge_failed when Supabase returns 500', function () {
    $pro = seedPurgeableProfessional();

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response(['message' => 'server error'], 500),
    ]);

    $service = new AccountDeletionService();
    $result = $service->purge($pro);

    expect($result)->toBeFalse();

    $stillExists = DB::connection('pgsql')->table('core.professionals')
        ->where('id', $pro->id)->exists();
    expect($stillExists)->toBeTrue();

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('event', 'purge_failed')
        ->where('professional_id', $pro->id)
        ->first();
    expect($audit)->not->toBeNull();
});

it('writes purged audit row with handle + email snapshots', function () {
    $pro = seedPurgeableProfessional(['handle' => 'snapshot-me', 'primary_email' => 'snapshot@example.com']);

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    $service = new AccountDeletionService();
    $service->purge($pro);

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('event', 'purged')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->professional_handle_snapshot)->toBe('snapshot-me')
        ->and($audit->professional_email_snapshot)->toBe('snapshot@example.com')
        ->and($audit->professional_id)->toBeNull(); // professional is deleted, FK set null
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php
```

Expected: FAIL — `purge` method does not exist.

- [ ] **Step 3: Add purge method and Supabase helper**

Add this import at the top of `app/Services/Professional/AccountDeletionService.php`:

```php
use Illuminate\Support\Facades\Http;
```

Add the `purge` and `deleteSupabaseAuthUser` methods inside the class (after `cancel`):

```php
    /**
     * Hard-delete a professional whose grace period has elapsed. Called by
     * PurgeSoftDeleted command. Returns false on any failure so the caller
     * can retry on the next daily run.
     */
    public function purge(Professional $professional): bool
    {
        $handleSnapshot = (string) ($professional->handle ?? '');
        $emailSnapshot = (string) ($professional->primary_email ?? '');
        $authUserId = (string) ($professional->auth_user_id ?? '');

        // Step 1: delete Supabase auth user. If this fails, do NOT hard-delete
        // the DB row — we'd end up with an orphaned auth user and no way to retry.
        if ($authUserId !== '' && ! $this->deleteSupabaseAuthUser($authUserId)) {
            ProfessionalDeletionAuditEntry::create([
                'professional_id' => $professional->id,
                'professional_handle_snapshot' => $handleSnapshot,
                'professional_email_snapshot' => $emailSnapshot,
                'event' => ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
                'metadata' => ['reason' => 'supabase_deletion_failed'],
                'created_at' => now(),
            ]);
            return false;
        }

        // Step 2: hard-delete professional row. DB handles cascades (42 FKs CASCADE,
        // 3 previously-RESTRICT FKs now SET NULL). forceDelete triggers model events.
        try {
            $professional->forceDelete();
        } catch (\Throwable $e) {
            Log::error('Professional forceDelete failed during purge', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
            ProfessionalDeletionAuditEntry::create([
                'professional_id' => $professional->id,
                'professional_handle_snapshot' => $handleSnapshot,
                'professional_email_snapshot' => $emailSnapshot,
                'event' => ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
                'metadata' => ['reason' => 'force_delete_failed', 'error' => $e->getMessage()],
                'created_at' => now(),
            ]);
            return false;
        }

        // Step 3: audit row — professional_id FK is SET NULL, snapshots preserve identity.
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => null,
            'professional_handle_snapshot' => $handleSnapshot,
            'professional_email_snapshot' => $emailSnapshot,
            'event' => ProfessionalDeletionAuditEntry::EVENT_PURGED,
            'created_at' => now(),
        ]);

        return true;
    }

    /**
     * Call Supabase Admin API to delete an auth user. 404 is treated as success
     * (user already deleted). Any other non-2xx response is a failure.
     */
    private function deleteSupabaseAuthUser(string $authUserId): bool
    {
        $baseUrl = rtrim((string) config('supabase.url'), '/');
        $serviceKey = (string) config('supabase.service_role_key');

        if ($baseUrl === '' || $serviceKey === '') {
            Log::error('Supabase credentials not configured; cannot delete auth user', [
                'auth_user_id' => $authUserId,
            ]);
            return false;
        }

        $response = Http::withHeaders([
            'apikey' => $serviceKey,
            'Authorization' => 'Bearer ' . $serviceKey,
        ])->delete("{$baseUrl}/auth/v1/admin/users/{$authUserId}");

        if ($response->status() === 404) {
            return true;
        }

        if (! $response->successful()) {
            Log::error('Supabase auth user deletion failed', [
                'auth_user_id' => $authUserId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        }

        return true;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php
```

Expected: 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/AccountDeletionService.php \
        tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php
git commit -m "$(cat <<'EOF'
feat(deletion): implement purge method with Supabase auth cleanup

- Deletes Supabase auth user via Admin API (404 = already-deleted = success)
- Hard-deletes professional row (DB cascades handle related data)
- Writes 'purged' audit row (professional_id nulled via FK SET NULL, identity
  preserved via handle/email snapshots)
- Writes 'purge_failed' audit row on any failure; returns false for retry

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: EnforcePendingDeletionReadOnly Middleware

**Files:**
- Create: `app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php`
- Create: `tests/Feature/Professional/AccountDeletion/ReadOnlyEnforcementTest.php`

- [ ] **Step 1: Write failing middleware tests**

Create `tests/Feature/Professional/AccountDeletion/ReadOnlyEnforcementTest.php`:

```php
<?php

use App\Http\Middleware\Context\EnforcePendingDeletionReadOnly;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Professional\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
});

function makeProWithStatus(string $status, ?string $confirmedAt = null): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'h-' . substr($id, 0, 6),
        'handle_lc' => 'h-' . substr($id, 0, 6),
        'display_name' => 'H',
        'primary_email' => 'h-' . substr($id, 0, 6) . '@example.com',
        'status' => $status,
        'deletion_confirmed_at' => $confirmedAt,
    ]);

    return Professional::query()->where('id', $id)->first();
}

it('returns 423 on POST when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me', 'POST');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly();
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(423);
    $body = json_decode($response->getContent(), true);
    expect($body['pending_deletion'])->toBeTrue()
        ->and($body['deletes_at'])->not->toBeEmpty();
});

it('returns 423 on PATCH when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me', 'PATCH');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly();
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(423);
});

it('returns 423 on DELETE when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me/services/xyz', 'DELETE');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly();
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->status())->toBe(423);
});

it('passes through GET even when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me', 'GET');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly();
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('passes through HEAD even when status is pending_deletion', function () {
    $pro = makeProWithStatus('pending_deletion', now()->toIso8601String());
    $request = Request::create('/api/professional/me', 'HEAD');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly();
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('');  // HEAD strips body
});

it('passes through POST when status is active', function () {
    $pro = makeProWithStatus('active');
    $request = Request::create('/api/professional/me', 'POST');
    $request->attributes->set('professional', $pro);

    $middleware = new EnforcePendingDeletionReadOnly();
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('passes through when no professional attribute is set', function () {
    $request = Request::create('/api/professional/me', 'POST');

    $middleware = new EnforcePendingDeletionReadOnly();
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/ReadOnlyEnforcementTest.php
```

Expected: FAIL — middleware class does not exist.

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php`:

```php
<?php

namespace App\Http\Middleware\Context;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

// V2: Blocks write requests (non-GET/HEAD/OPTIONS) for professionals with
// status = pending_deletion. Returns 423 Locked with the scheduled deletion
// date so the frontend can render a cancel prompt.
//
// IMPORTANT: the cancel route must be excluded via withoutMiddleware() or this
// creates a logic deadlock — pending_deletion accounts could never cancel.
class EnforcePendingDeletionReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $professional = $request->attributes->get('professional');

        if (! $professional || (($professional->status ?? null) !== 'pending_deletion')) {
            return $next($request);
        }

        // Allow safe methods through — status and audit info is still readable.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $retentionDays = (int) config('sidest.soft_delete_retention_days', 30);
        $confirmedAt = $professional->deletion_confirmed_at;

        $deletesAt = null;
        if ($confirmedAt instanceof \DateTimeInterface) {
            $deletesAt = Carbon::instance($confirmedAt)->addDays($retentionDays)->toIso8601String();
        } elseif (is_string($confirmedAt) && $confirmedAt !== '') {
            $deletesAt = Carbon::parse($confirmedAt)->addDays($retentionDays)->toIso8601String();
        }

        return response()->json([
            'message' => 'Account is pending deletion.',
            'pending_deletion' => true,
            'deletes_at' => $deletesAt,
        ], 423);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/ReadOnlyEnforcementTest.php
```

Expected: 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php \
        tests/Feature/Professional/AccountDeletion/ReadOnlyEnforcementTest.php
git commit -m "$(cat <<'EOF'
feat(middleware): add EnforcePendingDeletionReadOnly

Returns 423 Locked on write methods (POST/PATCH/DELETE/PUT) for professionals
with status = pending_deletion. GET/HEAD/OPTIONS pass through. Response body
includes deletes_at ISO timestamp.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Update LoadCurrentProfessional Middleware

**Files:**
- Modify: `app/Http/Middleware/Context/LoadCurrentProfessional.php`

- [ ] **Step 1: Update status check to allow pending_deletion**

Open `app/Http/Middleware/Context/LoadCurrentProfessional.php` and replace lines 47-56:

**Before:**
```php
        if (($professional->status ?? 'active') !== 'active') {
            Log::info('LoadCurrentProfessional suspended account', [
                'uid'   => $uid,
                'status'=> $professional->status ?? null,
            ]);

            return response()->json([
                'message' => 'Your account is suspended.'
            ], 403);
        }
```

**After:**
```php
        $status = $professional->status ?? 'active';
        if (! in_array($status, ['active', 'pending_deletion'], true)) {
            Log::info('LoadCurrentProfessional blocked account', [
                'uid'   => $uid,
                'status'=> $status,
            ]);

            return response()->json([
                'message' => 'Your account is not active. Contact support.'
            ], 403);
        }
```

- [ ] **Step 2: Verify existing tests still pass**

```bash
cd "/Users/joshuahunter/Herd/Partna/backend"
composer test
```

Expected: all tests PASS (no regression).

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/Context/LoadCurrentProfessional.php
git commit -m "$(cat <<'EOF'
fix(auth): allow pending_deletion through LoadCurrentProfessional gate

Previously status !== 'active' returned 403 — this would have locked out
any professional in the deletion grace period, preventing cancel. Now
both 'active' and 'pending_deletion' pass; writes are blocked later by
EnforcePendingDeletionReadOnly middleware.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Update BootstrapController

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/BootstrapController.php`

- [ ] **Step 1: Add pending_deletion to the rejection list**

Open `app/Http/Controllers/Api/PublicSite/BootstrapController.php` and find line 99:

**Before:**
```php
                if (in_array($professional->status, ['disabled', 'suspended'], true)) {
                    return $this->error('Account is disabled. Contact support.', 403);
                }
```

**After:**
```php
                if (in_array($professional->status, ['disabled', 'suspended', 'pending_deletion'], true)) {
                    return $this->error('Account is disabled. Contact support.', 403);
                }
```

- [ ] **Step 2: Verify existing tests still pass**

```bash
./vendor/bin/pest tests/Feature/Brand/BrandBootstrapTest.php
```

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/BootstrapController.php
git commit -m "$(cat <<'EOF'
fix(bootstrap): reject pending_deletion professionals on profile mutation

Previously bootstrap only rejected 'disabled'/'suspended' — meaning a
pending_deletion professional could still mutate their profile via
/api/bootstrap. Read-only enforcement now applies here too.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: Controller + Routes

**Files:**
- Create: `app/Http/Controllers/Api/Professional/ProfessionalAccountDeletionController.php`
- Modify: `routes/api/professional.php`

- [ ] **Step 1: Create the controller**

Create `app/Http/Controllers/Api/Professional/ProfessionalAccountDeletionController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// V2: Self-service account deletion endpoints. Thin HTTP layer that delegates to
// AccountDeletionService. Three endpoints: request (initiate), confirm (apply
// via email token), cancel (revert during grace period).
class ProfessionalAccountDeletionController extends ApiController
{
    public function __construct(
        private readonly AccountDeletionService $deletionService,
    ) {}

    /**
     * POST /api/professional/me/deletion/request
     * Sends a confirmation email with a token-bearing link.
     */
    public function request(Request $request): JsonResponse
    {
        /** @var Professional $professional */
        $professional = $request->attributes->get('professional');

        if ($professional->status === 'pending_deletion') {
            $deletesAt = null;
            if ($professional->deletion_confirmed_at) {
                $retentionDays = (int) config('sidest.soft_delete_retention_days', 30);
                $deletesAt = Carbon::parse((string) $professional->deletion_confirmed_at)
                    ->addDays($retentionDays)
                    ->toIso8601String();
            }

            return $this->error('Account deletion already in progress.', 409, [
                'deletes_at' => $deletesAt,
            ]);
        }

        if (in_array($professional->status, ['suspended', 'disabled'], true)) {
            return $this->error('Suspended accounts cannot request deletion. Contact support.', 403);
        }

        $result = $this->deletionService->request($professional, $request);

        if (! $result['success']) {
            $errors = [];
            if (isset($result['reasons'])) {
                $errors['reasons'] = $result['reasons'];
            }
            return $this->error($result['error'] ?? 'Request failed.', $result['code'], $errors);
        }

        return $this->success([
            'message' => 'Confirmation email sent. Check your inbox to confirm deletion.',
        ]);
    }

    /**
     * POST /api/professional/me/deletion/confirm
     * Body: { "token": "<raw_token>" }
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'min:32'],
        ]);

        /** @var Professional $professional */
        $professional = $request->attributes->get('professional');

        $result = $this->deletionService->confirm($professional, (string) $request->input('token'), $request);

        if (! $result['success']) {
            return $this->error($result['error'] ?? 'Confirmation failed.', $result['code']);
        }

        return $this->success([
            'message' => 'Account deletion scheduled.',
            'deletes_at' => $result['deletes_at'],
        ]);
    }

    /**
     * POST /api/professional/me/deletion/cancel
     * Exempted from EnforcePendingDeletionReadOnly middleware via route definition.
     */
    public function cancel(Request $request): JsonResponse
    {
        /** @var Professional $professional */
        $professional = $request->attributes->get('professional');

        if ($professional->status !== 'pending_deletion') {
            return $this->error('No pending deletion to cancel.', 409);
        }

        $this->deletionService->cancel($professional, $request);

        return $this->success([
            'message' => 'Account deletion cancelled. Your account is active again.',
        ]);
    }
}
```

- [ ] **Step 2: Register routes**

Open `routes/api/professional.php`. At the top, add this import after the other `App\Http\Controllers` imports (alphabetically near `ProfessionalAnalyticsController`):

```php
use App\Http\Controllers\Api\Professional\ProfessionalAccountDeletionController;
use App\Http\Middleware\Context\EnforcePendingDeletionReadOnly;
```

Find the authenticated middleware group starting at line 52 (`Route::middleware(['supabase.jwt', 'current.pro', 'throttle:authenticated'])`). Add `EnforcePendingDeletionReadOnly::class` to the middleware array:

**Before:**
```php
Route::middleware(['supabase.jwt', 'current.pro', 'throttle:authenticated'])
    ->group(function () {
```

**After:**
```php
Route::middleware(['supabase.jwt', 'current.pro', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])
    ->group(function () {
```

Then inside that group, add the three deletion routes. Place them near the `/me` routes (after line 57 `Route::patch('/me', ...`):

```php
        // Account Deletion — self-service lifecycle
        Route::prefix('me/deletion')->group(function () {
            Route::post('/request', [ProfessionalAccountDeletionController::class, 'request'])
                ->middleware('throttle:3,60');
            Route::post('/confirm', [ProfessionalAccountDeletionController::class, 'confirm']);
            Route::post('/cancel', [ProfessionalAccountDeletionController::class, 'cancel'])
                ->withoutMiddleware([EnforcePendingDeletionReadOnly::class]);
        });
```

- [ ] **Step 3: Clear route cache and verify routes are registered**

```bash
php artisan route:clear
php artisan route:list --path=api/professional/me/deletion
```

Expected output contains three lines:

```
POST      api/professional/me/deletion/request  ...
POST      api/professional/me/deletion/confirm  ...
POST      api/professional/me/deletion/cancel   ...
```

- [ ] **Step 4: Verify the full test suite still passes**

```bash
composer test
```

Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Professional/ProfessionalAccountDeletionController.php \
        routes/api/professional.php
git commit -m "$(cat <<'EOF'
feat(api): add deletion endpoints + wire middleware

- POST /me/deletion/request (throttle:3,60): initiate, returns 409 during grace
- POST /me/deletion/confirm: apply via email token
- POST /me/deletion/cancel: revert during grace period (withoutMiddleware
  EnforcePendingDeletionReadOnly, else logic deadlock)
- Add EnforcePendingDeletionReadOnly to professional route group

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: PurgeSoftDeleted Command Extension

**Files:**
- Modify: `app/Console/Commands/PurgeSoftDeleted.php`
- Modify: `tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php` (add command-level test)

- [ ] **Step 1: Write failing test for command-level purge**

Append to `tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php`:

```php
it('command purges professionals past 30 days but skips within grace', function () {
    AccountDeletionTestCase::boot(); // re-init DB for command-level test

    Http::fake([
        'test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200),
    ]);

    // Past grace — should be purged
    $purgeable = seedPurgeableProfessional([
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
    ]);

    // Within grace — should be skipped
    $withinGrace = seedPurgeableProfessional([
        'deletion_confirmed_at' => now()->subDays(5)->toIso8601String(),
    ]);

    \Illuminate\Support\Facades\Artisan::call('sidest:purge-soft-deletes');

    $purgeableExists = DB::connection('pgsql')->table('core.professionals')
        ->where('id', $purgeable->id)->exists();
    $withinGraceExists = DB::connection('pgsql')->table('core.professionals')
        ->where('id', $withinGrace->id)->exists();

    expect($purgeableExists)->toBeFalse()
        ->and($withinGraceExists)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php --filter "command purges"
```

Expected: FAIL — either a query error or the `purgeable` row still exists because the command doesn't know about pending_deletion yet.

- [ ] **Step 3: Extend the command**

Replace the full contents of `app/Console/Commands/PurgeSoftDeleted.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\SiteMedia;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

// V2: Hard-deletes soft-deleted rows (customers, services, media) past retention
// window, AND hard-deletes professionals whose self-service deletion grace period
// has elapsed (via AccountDeletionService).
class PurgeSoftDeleted extends Command
{
    protected $signature = 'sidest:purge-soft-deletes {--days= : Override retention days}';
    protected $description = 'Permanently delete soft-deleted rows and pending-deletion professionals older than retention window.';

    public function handle(AccountDeletionService $deletionService): int
    {
        $days = (int) ($this->option('days') ?: config('sidest.soft_delete_retention_days', 30));
        $cutoff = now()->subDays($days);

        $this->info("Purging soft-deleted rows older than {$days} days (before {$cutoff}).");

        $total = 0;

        $total += $this->purgeModel(Customer::class, $cutoff);
        $total += $this->purgeModel(Service::class, $cutoff);
        $total += $this->purgeModel(SiteMedia::class, $cutoff);

        $this->info("Done with soft deletes. Force-deleted {$total} rows.");

        // Pending-deletion professionals past grace period
        $this->purgePendingDeletionProfessionals($cutoff, $deletionService);

        return self::SUCCESS;
    }

    private function purgeModel(string $modelClass, Carbon $cutoff): int
    {
        $count = 0;

        $modelClass::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->orderBy('deleted_at')
            ->chunk(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $row->forceDelete();
                    $count++;
                }
            });

        $this->line(class_basename($modelClass) . ": {$count}");
        return $count;
    }

    /**
     * Hard-delete professionals whose grace period has elapsed. Each is handled
     * via AccountDeletionService::purge() which calls Supabase Admin API first.
     * Failures are logged to the audit table and retried on the next run.
     */
    private function purgePendingDeletionProfessionals(Carbon $cutoff, AccountDeletionService $deletionService): void
    {
        $purged = 0;
        $failed = 0;

        Professional::query()
            ->where('status', 'pending_deletion')
            ->where('deletion_confirmed_at', '<', $cutoff)
            ->orderBy('deletion_confirmed_at')
            ->chunk(100, function ($professionals) use ($deletionService, &$purged, &$failed) {
                foreach ($professionals as $professional) {
                    if ($deletionService->purge($professional)) {
                        $purged++;
                    } else {
                        $failed++;
                    }
                }
            });

        $this->line("PendingDeletion professionals: {$purged} purged, {$failed} failed (will retry next run).");
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php
```

Expected: all 5 tests PASS (4 service-level + 1 command-level).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/PurgeSoftDeleted.php \
        tests/Feature/Professional/AccountDeletion/PurgePendingDeletionTest.php
git commit -m "$(cat <<'EOF'
feat(purge): extend PurgeSoftDeleted to handle pending-deletion professionals

Runs after soft-delete purge. Chunked (100/batch). Each professional goes
through AccountDeletionService::purge(): Supabase Admin API delete, then
forceDelete. Failures are logged to audit table and retried next run.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: Full Test Suite Verification

**Files:**
- None (verification only)

- [ ] **Step 1: Run the full test suite**

```bash
cd "/Users/joshuahunter/Herd/Partna/backend"
composer test
```

Expected: ALL tests PASS (no regression from the middleware and controller changes).

- [ ] **Step 2: Check for Pint style issues**

```bash
php artisan pint --test app/Services/Professional/AccountDeletionService.php \
                       app/Http/Controllers/Api/Professional/ProfessionalAccountDeletionController.php \
                       app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php \
                       app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php \
                       app/Mail/Notifications/AccountDeletion*.php \
                       app/Console/Commands/PurgeSoftDeleted.php
```

Expected: PASS with no style issues. If it reports style issues, run without `--test` to auto-fix.

- [ ] **Step 3: If Pint made changes, commit them**

```bash
git add -u
git diff --cached --quiet || git commit -m "$(cat <<'EOF'
style: pint auto-fix on account deletion files

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

(The `git diff --cached --quiet` check is `|| commit` — skip the commit if nothing was staged.)

- [ ] **Step 4: Update docs/api.md**

Open `docs/api.md` and add a new section describing the three endpoints. Place it near the other `/me` routes. Add:

```markdown
### Account Deletion

Self-service lifecycle: email-confirmed grace period → 30-day read-only window → hard delete.

#### `POST /api/professional/me/deletion/request`

Initiates deletion. Sends confirmation email (expires 24h). Rate-limited 3/hour.

- `200` — confirmation email sent
- `409` — already in grace period (body: `deletes_at`)
- `403` — account is suspended/disabled
- `422` — unsettled obligations (body: `reasons: ["unpaid_balance", "pending_payouts", "pending_topups"]`)
- `429` — rate limited
- `503` — mail send failed (safe to retry)

#### `POST /api/professional/me/deletion/confirm`

Body: `{ "token": "<from email>" }`. Status → `pending_deletion`, Stripe cancel-at-period-end scheduled, integration credentials deleted.

- `200` — body: `deletes_at` ISO timestamp
- `410` — token expired (>24h since request)
- `404` — token invalid or no deletion request

#### `POST /api/professional/me/deletion/cancel`

Restores previous status. Exempt from read-only middleware.

- `200` — account reactivated
- `409` — no pending deletion

#### Read-only enforcement

During grace period, all non-GET/HEAD/OPTIONS requests return:

```json
HTTP 423 Locked
{
  "message": "Account is pending deletion.",
  "pending_deletion": true,
  "deletes_at": "2026-05-19T03:20:00Z"
}
```
```

- [ ] **Step 5: Commit the docs update**

```bash
git add docs/api.md
git commit -m "$(cat <<'EOF'
docs(api): document account deletion endpoints

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec coverage:**
- [x] Data model: 4 columns + CHECK constraint + audit table — Task 1
- [x] Token security (sha256 + hash_equals): Task 6 + 7
- [x] Rate limiting (throttle:3,60): Task 13
- [x] Outstanding obligations precondition: Task 5
- [x] Audit log: Tasks 5, 6, 7, 8, 9
- [x] RESTRICT→SET NULL FK migration: Task 1
- [x] Request flow: Task 6
- [x] Confirm flow (+ integration deletion, Stripe cancel): Task 7
- [x] Cancel flow (+ previous_status restore, Stripe resume): Task 8
- [x] Purge flow (Supabase + forceDelete + retry-on-failure): Task 9
- [x] EnforcePendingDeletionReadOnly middleware: Task 10
- [x] LoadCurrentProfessional update: Task 11
- [x] BootstrapController update: Task 12
- [x] Controller + routes (with withoutMiddleware on cancel): Task 13
- [x] Purge command extension: Task 14
- [x] All 5 test files listed in spec: Tasks 5-10, 14

**Public site behavior during grace period:** Intentionally not a task — the spec documents this as a side effect of `SiteVisibilityController` et al already checking `status === 'active'`. No code change needed; the status flip in confirm is enough.

**Data export / GDPR / staff notification:** Explicitly out of scope per spec — not included.

**Type consistency:** `AccountDeletionService` return shape is documented as `array{success: bool, code: int, ...}` consistently across request/confirm/cancel; controller destructures the same keys. `ProfessionalDeletionAuditEntry::EVENT_*` constants are used everywhere instead of string literals.

**Placeholder scan:** No TBDs, no "implement later", no "similar to Task N". All code blocks are complete. All test assertions are explicit.

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-04-19-account-deletion.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
