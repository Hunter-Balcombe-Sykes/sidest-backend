# Professional Data Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship self-service and staff-triggered professional data export endpoints that assemble a JSON+CSV zip, upload it to R2, and email a 7-day signed URL to the recipient — fulfilling GDPR portability for whole-account data.

**Architecture:** Two thin controllers dispatch the same `ExportProfessionalDataJob` onto the existing `redis_gdpr` queue. The job calls `DataExportPayloadBuilder` (pure DB → array), then `DataExportZipWriter` (array → zip on disk + sha256), uploads to R2 under `exports/{prof_id}/{audit_id}.zip`, generates a signed URL, and emails it via `ProfessionalDataExportMail`. A new `core.data_export_audit` table tracks every export with status transitions and survives professional hard-delete via snapshot columns.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL via Supabase, Redis (queue), Cloudflare R2 (storage), `ZipArchive` (PHP built-in), Pest 4 + SQLite-in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-04-25-data-export-design.md`

---

## File Structure

### Created
- `supabase/migrations/20260425000002_create_data_export_audit.sql` — audit table.
- `app/Models/Core/Gdpr/DataExportAudit.php` — Eloquent model with status helpers.
- `app/Services/Professional/DataExportPayloadBuilder.php` — pure builder; assembles the payload array.
- `app/Services/Professional/DataExportZipWriter.php` — streams payload+CSVs to a temp zip; returns path+sha256+size+counts.
- `app/Services/Professional/DataExportService.php` — dispatch orchestrator (dedup check, audit insert, queue dispatch).
- `app/Jobs/Gdpr/ExportProfessionalDataJob.php` — queue job that ties builder+writer+R2+mail together.
- `app/Mail/Gdpr/ProfessionalDataExportMail.php` — mailable with signed URL.
- `resources/views/emails/gdpr/professional-data-export.blade.php` — email body.
- `app/Http/Requests/Professional/RequestDataExportRequest.php` — empty-body validator placeholder.
- `app/Http/Requests/Staff/RequestStaffDataExportRequest.php` — `send_to` query validator.
- `app/Http/Controllers/Api/Professional/ProfessionalDataExportController.php` — self-service trigger.
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffDataExportController.php` — staff trigger.
- `tests/Feature/Professional/DataExport/DataExportTestCase.php` — shared SQLite schema bootstrap (mirrors `AccountDeletionTestCase`).
- `tests/Feature/Professional/DataExport/RequestSelfServiceExportTest.php`
- `tests/Feature/Staff/DataExport/RequestStaffExportTest.php`
- `tests/Unit/Services/Professional/DataExportPayloadBuilderTest.php`
- `tests/Unit/Services/Professional/DataExportZipWriterTest.php`
- `tests/Feature/Jobs/Gdpr/ExportProfessionalDataJobTest.php`
- `tests/Feature/Mail/ProfessionalDataExportMailTest.php`
- `tests/Feature/Professional/DataExport/AuditTrailTest.php`

### Modified
- `config/sidest.php` — add two keys to existing `gdpr` block (`signed_url_ttl_days`, `dedup_window_minutes`). `export_retention_days` already exists.
- `routes/api/professional.php` — add `POST /me/data-export`.
- `routes/api/staff.php` — add `POST /professionals/{professional}/data-export`.

### Operational (not in code)
- Cloudflare R2 lifecycle rule on the media bucket: expire `exports/` prefix objects after 30 days. Set up via Cloudflare dashboard at deploy time. Documented in spec.

---

## Task 1: Create the `core.data_export_audit` migration

**Files:**
- Create: `supabase/migrations/20260425000002_create_data_export_audit.sql`

- [ ] **Step 1: Write the SQL migration**

```sql
-- Create core.data_export_audit — audit trail for professional data exports.
--
-- Why: Both self-service and staff-triggered data exports write a row here
-- before the job runs (status=queued). The row survives the professional's
-- hard delete via the *_snapshot columns. record_counts (jsonb) captures
-- what was in the export so we can answer "what did we send and to whom" later.

BEGIN;

CREATE TABLE IF NOT EXISTS core.data_export_audit (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid,
    professional_handle_snapshot text NOT NULL,
    professional_email_snapshot text,
    triggered_by text NOT NULL,
    triggered_by_staff_id uuid,
    recipient_email text NOT NULL,
    send_to text,
    status text NOT NULL DEFAULT 'queued',
    file_path text,
    file_size_bytes bigint,
    file_sha256 text,
    record_counts jsonb,
    error_message text,
    created_at timestamptz DEFAULT now() NOT NULL,
    completed_at timestamptz,
    CONSTRAINT data_export_audit_pkey PRIMARY KEY (id),
    CONSTRAINT data_export_audit_triggered_by_chk CHECK (triggered_by IN ('self', 'staff')),
    CONSTRAINT data_export_audit_send_to_chk CHECK (send_to IS NULL OR send_to IN ('professional', 'staff')),
    CONSTRAINT data_export_audit_status_chk CHECK (status IN ('queued', 'processing', 'completed', 'failed'))
);

ALTER TABLE core.data_export_audit OWNER TO postgres;

ALTER TABLE ONLY core.data_export_audit
    ADD CONSTRAINT data_export_audit_professional_fk
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

ALTER TABLE ONLY core.data_export_audit
    ADD CONSTRAINT data_export_audit_staff_fk
    FOREIGN KEY (triggered_by_staff_id) REFERENCES core.sidest_staff(id) ON DELETE SET NULL;

-- Dedup query: SELECT ... WHERE professional_id = ? AND status IN ('queued','processing') AND created_at > now() - interval '30 minutes'
CREATE INDEX data_export_audit_professional_status_created_idx
    ON core.data_export_audit (professional_id, status, created_at DESC);

-- "Which staff member exported what, when?" — partial index keeps it cheap.
CREATE INDEX data_export_audit_triggered_by_staff_idx
    ON core.data_export_audit (triggered_by_staff_id) WHERE triggered_by_staff_id IS NOT NULL;

ALTER TABLE core.data_export_audit ENABLE ROW LEVEL SECURITY;

CREATE POLICY data_export_audit_app_backend_all
    ON core.data_export_audit
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

GRANT SELECT, INSERT, UPDATE, DELETE ON core.data_export_audit TO app_backend;

COMMENT ON TABLE core.data_export_audit IS
    'Audit trail for professional data exports (self-service + staff-triggered). Snapshot columns survive professional hard-delete.';

COMMIT;
```

- [ ] **Step 2: Apply migration to local Supabase**

Run: `supabase db push` (or whichever local apply command Josh uses)
Expected: migration applies cleanly, no errors.

- [ ] **Step 3: Verify table exists**

Run: `psql $SUPABASE_DB_URL -c "\d core.data_export_audit"`
Expected: shows the table with all columns, indexes, FKs, and the CHECK constraints.

- [ ] **Step 4: Commit**

```bash
git add supabase/migrations/20260425000002_create_data_export_audit.sql
git commit -m "feat(data-export): add core.data_export_audit table"
```

---

## Task 2: Add config keys to `config/sidest.php`

**Files:**
- Modify: `config/sidest.php` — extend the existing `'gdpr' => [...]` block

- [ ] **Step 1: Locate the existing `gdpr` block (around line 822-826)**

Read: `config/sidest.php` and find:

```php
'gdpr' => [
    'queue' => env('GDPR_QUEUE', 'gdpr'),
    'redact_placeholder_domain' => env('GDPR_REDACT_PLACEHOLDER_DOMAIN', 'gdpr.sidest.io'),
    'export_retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 30),
],
```

- [ ] **Step 2: Add two new keys (signed_url_ttl_days, dedup_window_minutes)**

Replace the block with:

```php
'gdpr' => [
    'queue' => env('GDPR_QUEUE', 'gdpr'),
    'redact_placeholder_domain' => env('GDPR_REDACT_PLACEHOLDER_DOMAIN', 'gdpr.sidest.io'),
    'export_retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 30),
    // Signed URL TTL emailed to recipients of a professional data export.
    // Must be <= export_retention_days (file is gone after that anyway).
    'signed_url_ttl_days' => (int) env('GDPR_EXPORT_SIGNED_URL_TTL_DAYS', 7),
    // Dedup window: a second export request for the same professional
    // within this many minutes returns 409 instead of queuing again.
    // Prevents accidental double-clicks AND queue thrashing.
    'dedup_window_minutes' => (int) env('GDPR_EXPORT_DEDUP_WINDOW_MINUTES', 30),
],
```

- [ ] **Step 3: Commit**

```bash
git add config/sidest.php
git commit -m "feat(data-export): add signed_url_ttl + dedup_window config"
```

---

## Task 3: Create the `DataExportAudit` Eloquent model (TDD)

**Files:**
- Create: `app/Models/Core/Gdpr/DataExportAudit.php`
- Test: `tests/Feature/Professional/DataExport/DataExportTestCase.php`
- Test: `tests/Feature/Professional/DataExport/AuditTrailTest.php`

- [ ] **Step 1: Create the shared test bootstrap**

Create `tests/Feature/Professional/DataExport/DataExportTestCase.php` mirroring the `AccountDeletionTestCase.php` pattern:

```php
<?php

namespace Tests\Feature\Professional\DataExport;

use Illuminate\Support\Facades\DB;

// Shared SQLite schema setup for data-export feature tests.
// Mirrors AccountDeletionTestCase — attaches each schema as its own
// in-memory DB so schema-qualified table names (core.*, etc.) resolve.
class DataExportTestCase
{
    public static function boot(): void
    {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
            'sidest.gdpr.queue' => 'gdpr',
            'sidest.gdpr.signed_url_ttl_days' => 7,
            'sidest.gdpr.dedup_window_minutes' => 30,
            'sidest.media_disk' => 'media',
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $conn = DB::connection('pgsql');

        foreach (['core', 'commerce', 'notifications', 'billing', 'site', 'analytics'] as $schema) {
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
            public_contact_email TEXT,
            professional_type TEXT DEFAULT "professional",
            status TEXT DEFAULT "active",
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.sidest_staff (
            id TEXT PRIMARY KEY,
            auth_user_id TEXT,
            role TEXT,
            primary_email TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.data_export_audit (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            professional_handle_snapshot TEXT NOT NULL,
            professional_email_snapshot TEXT,
            triggered_by TEXT NOT NULL,
            triggered_by_staff_id TEXT,
            recipient_email TEXT NOT NULL,
            send_to TEXT,
            status TEXT NOT NULL DEFAULT "queued",
            file_path TEXT,
            file_size_bytes INTEGER,
            file_sha256 TEXT,
            record_counts TEXT,
            error_message TEXT,
            created_at TEXT,
            completed_at TEXT
        )');
    }
}
```

- [ ] **Step 2: Write a failing test for the model's status helpers**

Create `tests/Feature/Professional/DataExport/AuditTrailTest.php`:

```php
<?php

namespace Tests\Feature\Professional\DataExport;

use App\Models\Core\Gdpr\DataExportAudit;
use Illuminate\Support\Str;

beforeEach(function () {
    DataExportTestCase::boot();
});

it('persists with auto-generated uuid and default status queued', function () {
    $audit = DataExportAudit::create([
        'professional_id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    expect($audit->id)->toBeString()->not->toBeEmpty();
    expect($audit->status)->toBe('queued');
});

it('markCompleted updates status, completed_at, file metadata', function () {
    $audit = DataExportAudit::create([
        'professional_id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    $audit->markCompleted(
        filePath: 'exports/abc/def.zip',
        fileSizeBytes: 12345,
        fileSha256: str_repeat('a', 64),
        recordCounts: ['customers' => 10, 'bookings' => 5],
    );

    $audit->refresh();
    expect($audit->status)->toBe('completed');
    expect($audit->completed_at)->not->toBeNull();
    expect($audit->file_path)->toBe('exports/abc/def.zip');
    expect($audit->file_size_bytes)->toBe(12345);
    expect($audit->file_sha256)->toBe(str_repeat('a', 64));
    expect($audit->record_counts)->toBe(['customers' => 10, 'bookings' => 5]);
});

it('markFailed records the error and truncates very long messages', function () {
    $audit = DataExportAudit::create([
        'professional_id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    $longError = str_repeat('x', 3000);
    $audit->markFailed($longError);

    $audit->refresh();
    expect($audit->status)->toBe('failed');
    expect(mb_strlen($audit->error_message))->toBe(2000);
});
```

- [ ] **Step 3: Run the tests — expect failure (model doesn't exist yet)**

Run: `vendor/bin/pest tests/Feature/Professional/DataExport/AuditTrailTest.php`
Expected: FAIL with "Class 'App\Models\Core\Gdpr\DataExportAudit' not found".

- [ ] **Step 4: Create the model**

Create `app/Models/Core/Gdpr/DataExportAudit.php`:

```php
<?php

namespace App\Models\Core\Gdpr;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Audit row for professional data exports (self-service + staff-triggered).
// Snapshot columns survive the professional's hard-delete (FK SET NULL).
class DataExportAudit extends BaseModel
{
    use HasUuids;

    public const TRIGGERED_BY_SELF = 'self';

    public const TRIGGERED_BY_STAFF = 'staff';

    public const SEND_TO_PROFESSIONAL = 'professional';

    public const SEND_TO_STAFF = 'staff';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'core.data_export_audit';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // only created_at + completed_at; no updated_at

    protected $fillable = [
        'professional_id',
        'professional_handle_snapshot',
        'professional_email_snapshot',
        'triggered_by',
        'triggered_by_staff_id',
        'recipient_email',
        'send_to',
        'status',
        'file_path',
        'file_size_bytes',
        'file_sha256',
        'record_counts',
        'error_message',
        'created_at',
        'completed_at',
    ];

    protected $casts = [
        'record_counts' => 'array',
        'file_size_bytes' => 'integer',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // PII fields — never expose in API responses or job payloads
    protected $hidden = [
        'professional_email_snapshot',
        'recipient_email',
        'file_sha256',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $audit) {
            if (! $audit->status) {
                $audit->status = self::STATUS_QUEUED;
            }
            if (! $audit->created_at) {
                $audit->created_at = now();
            }
        });
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function triggeringStaff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'triggered_by_staff_id');
    }

    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markCompleted(
        string $filePath,
        int $fileSizeBytes,
        string $fileSha256,
        array $recordCounts,
    ): void {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'file_path' => $filePath,
            'file_size_bytes' => $fileSizeBytes,
            'file_sha256' => $fileSha256,
            'record_counts' => $recordCounts,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => mb_substr($error, 0, 2000),
            'completed_at' => now(),
        ]);
    }
}
```

- [ ] **Step 5: Run the tests — expect pass**

Run: `vendor/bin/pest tests/Feature/Professional/DataExport/AuditTrailTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Core/Gdpr/DataExportAudit.php tests/Feature/Professional/DataExport/
git commit -m "feat(data-export): DataExportAudit model + status helpers"
```

---

## Task 4: `DataExportPayloadBuilder` service (TDD)

**Files:**
- Create: `app/Services/Professional/DataExportPayloadBuilder.php`
- Test: `tests/Unit/Services/Professional/DataExportPayloadBuilderTest.php`

- [ ] **Step 1: Extend the test bootstrap with the tables the builder reads**

Add to `DataExportTestCase::boot()` (after the existing CREATE TABLE statements) — the builder reads many tables, so seed the schema:

```php
$conn->statement('CREATE TABLE IF NOT EXISTS core.customers (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    email TEXT,
    phone TEXT,
    full_name TEXT,
    source TEXT,
    notes TEXT,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT,
    redacted_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS core.brand_profiles (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    industry TEXT,
    created_at TEXT,
    updated_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS core.brand_partner_links (
    id TEXT PRIMARY KEY,
    brand_professional_id TEXT,
    affiliate_professional_id TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    provider TEXT,
    shop_domain TEXT,
    last_sync_at TEXT,
    access_token TEXT,
    refresh_token TEXT,
    created_at TEXT,
    updated_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS core.services (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    name TEXT,
    duration_minutes INTEGER,
    price_cents INTEGER,
    created_at TEXT,
    deleted_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS core.service_categories (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    name TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    subdomain TEXT,
    settings TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS site.blocks (
    id TEXT PRIMARY KEY,
    site_id TEXT,
    type TEXT,
    sort_order INTEGER,
    settings TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    name TEXT,
    email TEXT,
    phone TEXT,
    subject TEXT,
    message TEXT,
    ip_hash TEXT,
    user_agent TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS core.site_media (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    pool TEXT,
    purpose TEXT,
    path TEXT,
    width INTEGER,
    height INTEGER,
    caption TEXT,
    alt_text TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    email_lc TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS analytics.booking_events (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    occurred_at TEXT,
    status TEXT,
    source TEXT,
    customer_name TEXT,
    customer_email TEXT,
    customer_phone TEXT,
    amount_paid_cents INTEGER,
    currency_code TEXT,
    raw_payload TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    customer_id TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
    id TEXT PRIMARY KEY,
    professional_id TEXT,
    plan_id TEXT,
    status TEXT,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_ledger_entries (
    id TEXT PRIMARY KEY,
    affiliate_professional_id TEXT,
    brand_professional_id TEXT,
    amount_cents INTEGER,
    created_at TEXT
)');

$conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
    id TEXT PRIMARY KEY,
    affiliate_professional_id TEXT,
    brand_professional_id TEXT,
    status TEXT,
    amount_cents INTEGER,
    created_at TEXT
)');
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Services/Professional/DataExportPayloadBuilderTest.php`:

```php
<?php

namespace Tests\Unit\Services\Professional;

use App\Services\Professional\DataExportPayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Professional\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
});

function seedProfessional(string $id, string $handle = 'jane', string $email = 'jane@example.com'): void
{
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => mb_strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);
}

it('builds payload with metadata, profile, schema_version=1', function () {
    $profId = (string) Str::uuid();
    seedProfessional($profId);

    $builder = new DataExportPayloadBuilder;
    $payload = $builder->build($profId);

    expect($payload['metadata']['professional_id'])->toBe($profId);
    expect($payload['metadata']['professional_handle'])->toBe('jane');
    expect($payload['metadata']['schema_version'])->toBe(1);
    expect($payload['metadata']['notes'])->toContain('PII');
    expect($payload['profile']['professional']['handle'])->toBe('jane');
});

it('excludes auth_user_id and any deletion_token from profile', function () {
    $profId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $profId,
        'handle' => 'jane',
        'auth_user_id' => 'auth-uuid-secret',
        'primary_email' => 'jane@example.com',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['profile']['professional'])->not->toHaveKey('auth_user_id');
    expect($payload['profile']['professional'])->not->toHaveKey('deletion_token_hash');
});

it('includes customers belonging to this professional and excludes others', function () {
    $profId = (string) Str::uuid();
    $otherProfId = (string) Str::uuid();
    seedProfessional($profId);
    seedProfessional($otherProfId, 'bob', 'bob@example.com');

    DB::connection('pgsql')->table('core.customers')->insert([
        ['id' => (string) Str::uuid(), 'professional_id' => $profId, 'email' => 'cust1@example.com', 'full_name' => 'Cust One', 'created_at' => '2026-01-01T00:00:00Z'],
        ['id' => (string) Str::uuid(), 'professional_id' => $otherProfId, 'email' => 'other@example.com', 'full_name' => 'Other Cust', 'created_at' => '2026-01-01T00:00:00Z'],
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['customers'])->toHaveCount(1);
    expect($payload['customers'][0]['email'])->toBe('cust1@example.com');
});

it('excludes raw_payload from booking_events (third-party PII)', function () {
    $profId = (string) Str::uuid();
    seedProfessional($profId);

    DB::connection('pgsql')->table('analytics.booking_events')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $profId,
        'customer_name' => 'Customer',
        'customer_email' => 'c@example.com',
        'occurred_at' => '2026-01-01T00:00:00Z',
        'amount_paid_cents' => 5000,
        'currency_code' => 'GBP',
        'raw_payload' => '{"square_secret":"OTHER_PARTY_DATA"}',
        'created_at' => '2026-01-01T00:00:00Z',
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['bookings']['booking_events'])->toHaveCount(1);
    expect($payload['bookings']['booking_events'][0])->not->toHaveKey('raw_payload');
});

it('excludes oauth tokens from integration metadata', function () {
    $profId = (string) Str::uuid();
    seedProfessional($profId);

    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $profId,
        'provider' => 'shopify',
        'shop_domain' => 'jane.myshopify.com',
        'access_token' => 'shpat_secret',
        'refresh_token' => 'rtok_secret',
        'last_sync_at' => '2026-01-01T00:00:00Z',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['integrations'])->toHaveCount(1);
    expect($payload['integrations'][0]['provider'])->toBe('shopify');
    expect($payload['integrations'][0])->not->toHaveKey('access_token');
    expect($payload['integrations'][0])->not->toHaveKey('refresh_token');
});

it('includes brand sections only for brand professionals', function () {
    $profId = (string) Str::uuid();
    seedProfessional($profId);

    DB::connection('pgsql')->table('core.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $profId,
        'industry' => 'beauty',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['profile']['brand_profile'])->not->toBeNull();
    expect($payload['profile']['brand_profile']['industry'])->toBe('beauty');
});

it('omits brand sections for non-brand professionals', function () {
    $profId = (string) Str::uuid();
    seedProfessional($profId);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['profile']['brand_profile'])->toBeNull();
});

it('builds an empty-but-valid payload for a professional with no related data', function () {
    $profId = (string) Str::uuid();
    seedProfessional($profId);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['customers'])->toBe([]);
    expect($payload['enquiries'])->toBe([]);
    expect($payload['bookings']['booking_events'])->toBe([]);
    expect($payload['bookings']['lead_submissions'])->toBe([]);
});
```

- [ ] **Step 3: Run the tests — expect failure (class doesn't exist)**

Run: `vendor/bin/pest tests/Unit/Services/Professional/DataExportPayloadBuilderTest.php`
Expected: FAIL with class-not-found.

- [ ] **Step 4: Implement the builder**

Create `app/Services/Professional/DataExportPayloadBuilder.php`:

```php
<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;

// V2: Pure builder. Assembles the full data-export payload (Ring 1 + 2 of the
// scope rings — see docs/superpowers/specs/2026-04-25-data-export-design.md).
// No I/O beyond DB reads — testable with fixture data, no filesystem touches.
class DataExportPayloadBuilder
{
    private const SCHEMA_VERSION = 1;

    private const PII_DISCLOSURE = 'This export contains personally identifiable information (PII) you collected from your customers via Partna (booking history, enquiries, email subscriptions). Handle in accordance with applicable privacy law.';

    /**
     * Build the full payload for a single professional.
     *
     * @return array{metadata: array, profile: array, site: array, media: array, integrations: array, customers: array, services: array, service_categories: array, enquiries: array, email_subscriptions: array, bookings: array, billing: array, audit: array}
     */
    public function build(string $professionalId): array
    {
        $professional = Professional::query()
            ->withTrashed()
            ->where('id', $professionalId)
            ->firstOrFail();

        return [
            'metadata' => $this->metadata($professional),
            'profile' => $this->profile($professional),
            'site' => $this->site($professionalId),
            'media' => $this->media($professionalId),
            'integrations' => $this->integrations($professionalId),
            'customers' => $this->customers($professionalId),
            'services' => $this->services($professionalId),
            'service_categories' => $this->serviceCategories($professionalId),
            'enquiries' => $this->enquiries($professionalId),
            'email_subscriptions' => $this->emailSubscriptions($professionalId),
            'bookings' => $this->bookings($professionalId),
            'billing' => $this->billing($professionalId),
            'audit' => $this->audit($professionalId),
        ];
    }

    private function metadata(Professional $p): array
    {
        return [
            'professional_id' => $p->id,
            'professional_handle' => $p->handle,
            'exported_at' => now()->toIso8601String(),
            'schema_version' => self::SCHEMA_VERSION,
            'notes' => self::PII_DISCLOSURE,
        ];
    }

    private function profile(Professional $p): array
    {
        // Strip secrets — never let auth or tokens leak into an export.
        $row = $p->toArray();
        unset($row['auth_user_id'], $row['deletion_token_hash']);

        $brandProfile = DB::connection('pgsql')
            ->table('core.brand_profiles')
            ->where('professional_id', $p->id)
            ->first();

        $brandPartnerLinks = DB::connection('pgsql')
            ->table('core.brand_partner_links')
            ->where('brand_professional_id', $p->id)
            ->orWhere('affiliate_professional_id', $p->id)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'professional' => $row,
            'brand_profile' => $brandProfile ? (array) $brandProfile : null,
            'brand_partner_links' => $brandPartnerLinks,
        ];
    }

    private function site(string $professionalId): array
    {
        $site = DB::connection('pgsql')
            ->table('site.sites')
            ->where('professional_id', $professionalId)
            ->first();

        if (! $site) {
            return ['site' => null, 'blocks' => []];
        }

        $blocks = DB::connection('pgsql')
            ->table('site.blocks')
            ->where('site_id', $site->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'site' => (array) $site,
            'blocks' => $blocks,
        ];
    }

    private function media(string $professionalId): array
    {
        $items = DB::connection('pgsql')
            ->table('core.site_media')
            ->select(['id', 'pool', 'purpose', 'path', 'width', 'height', 'caption', 'alt_text', 'created_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return ['site_media' => $items];
    }

    private function integrations(string $professionalId): array
    {
        // Strip access_token and refresh_token — credentials never go in an export.
        return DB::connection('pgsql')
            ->table('core.professional_integrations')
            ->select(['id', 'provider', 'shop_domain', 'last_sync_at', 'created_at', 'updated_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function customers(string $professionalId): array
    {
        return DB::connection('pgsql')
            ->table('core.customers')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function services(string $professionalId): array
    {
        return DB::connection('pgsql')
            ->table('core.services')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function serviceCategories(string $professionalId): array
    {
        return DB::connection('pgsql')
            ->table('core.service_categories')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function enquiries(string $professionalId): array
    {
        // Mirror the redaction in ExportCustomerDataJob — drop ip_hash + user_agent
        // (technical fingerprint, not part of the user-visible enquiry).
        return DB::connection('pgsql')
            ->table('site.enquiries')
            ->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function emailSubscriptions(string $professionalId): array
    {
        return DB::connection('pgsql')
            ->table('notifications.email_subscriptions')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function bookings(string $professionalId): array
    {
        // raw_payload deliberately excluded — it is the full third-party API
        // response (Square/Fresha) and may contain other parties' data
        // (staff member who took the booking, etc.).
        $events = DB::connection('pgsql')
            ->table('analytics.booking_events')
            ->select(['id', 'occurred_at', 'status', 'source', 'customer_name', 'customer_email', 'customer_phone', 'amount_paid_cents', 'currency_code', 'created_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $leads = DB::connection('pgsql')
            ->table('analytics.lead_submissions')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'booking_events' => $events,
            'lead_submissions' => $leads,
        ];
    }

    private function billing(string $professionalId): array
    {
        $subscription = DB::connection('pgsql')
            ->table('billing.subscriptions')
            ->where('professional_id', $professionalId)
            ->first();

        $ledger = DB::connection('pgsql')
            ->table('commerce.commission_ledger_entries')
            ->where('affiliate_professional_id', $professionalId)
            ->orWhere('brand_professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $payouts = DB::connection('pgsql')
            ->table('commerce.commission_payouts')
            ->where('affiliate_professional_id', $professionalId)
            ->orWhere('brand_professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'subscription' => $subscription ? (array) $subscription : null,
            'commission_ledger_entries' => $ledger,
            'commission_payouts' => $payouts,
        ];
    }

    private function audit(string $professionalId): array
    {
        $exports = DB::connection('pgsql')
            ->table('core.data_export_audit')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return [
            'data_export_audit' => $exports,
        ];
    }
}
```

- [ ] **Step 5: Run the tests — expect pass**

Run: `vendor/bin/pest tests/Unit/Services/Professional/DataExportPayloadBuilderTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Professional/DataExportPayloadBuilder.php tests/Unit/Services/Professional/DataExportPayloadBuilderTest.php tests/Feature/Professional/DataExport/DataExportTestCase.php
git commit -m "feat(data-export): DataExportPayloadBuilder service"
```

---

## Task 5: `DataExportZipWriter` service (TDD)

**Files:**
- Create: `app/Services/Professional/DataExportZipWriter.php`
- Test: `tests/Unit/Services/Professional/DataExportZipWriterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Professional/DataExportZipWriterTest.php`:

```php
<?php

namespace Tests\Unit\Services\Professional;

use App\Services\Professional\DataExportZipWriter;
use ZipArchive;

afterEach(function () {
    // Clean up any test temp files
    foreach (glob(sys_get_temp_dir().'/export-*') as $f) {
        @unlink($f);
    }
});

function samplePayload(): array
{
    return [
        'metadata' => [
            'professional_id' => 'prof-1',
            'professional_handle' => 'jane',
            'exported_at' => '2026-04-25T00:00:00Z',
            'schema_version' => 1,
            'notes' => 'note',
        ],
        'profile' => ['professional' => ['id' => 'prof-1', 'handle' => 'jane']],
        'site' => ['site' => null, 'blocks' => []],
        'media' => ['site_media' => []],
        'integrations' => [],
        'customers' => [
            ['id' => 'c1', 'email' => 'a@b.com', 'phone' => null, 'full_name' => 'A B', 'source' => 'manual', 'notes' => null, 'created_at' => '2026-01-01T00:00:00Z'],
            ['id' => 'c2', 'email' => 'c@d.com', 'phone' => '+447000', 'full_name' => 'C D', 'source' => 'shopify', 'notes' => 'VIP', 'created_at' => '2026-01-02T00:00:00Z'],
        ],
        'services' => [],
        'service_categories' => [],
        'enquiries' => [
            ['id' => 'e1', 'name' => 'X', 'email' => 'x@y.com', 'phone' => null, 'subject' => 'Hi', 'message' => 'hello', 'created_at' => '2026-01-03T00:00:00Z'],
        ],
        'email_subscriptions' => [],
        'bookings' => [
            'booking_events' => [
                ['id' => 'b1', 'occurred_at' => '2026-01-04T00:00:00Z', 'status' => 'completed', 'source' => 'square', 'customer_name' => 'A', 'customer_email' => 'a@b.com', 'customer_phone' => null, 'amount_paid_cents' => 5000, 'currency_code' => 'GBP', 'created_at' => '2026-01-04T00:00:00Z'],
            ],
            'lead_submissions' => [],
        ],
        'billing' => [
            'subscription' => null,
            'commission_ledger_entries' => [],
            'commission_payouts' => [],
        ],
        'audit' => ['data_export_audit' => []],
    ];
}

it('writes a zip file containing data.json and CSVs', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    expect($result['path'])->toBeFile();

    $zip = new ZipArchive;
    $zip->open($result['path']);

    expect($zip->locateName('data.json'))->not->toBeFalse();
    expect($zip->locateName('customers.csv'))->not->toBeFalse();
    expect($zip->locateName('enquiries.csv'))->not->toBeFalse();
    expect($zip->locateName('bookings.csv'))->not->toBeFalse();

    $zip->close();
});

it('data.json round-trips through json_decode', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $json = $zip->getFromName('data.json');
    $zip->close();

    $decoded = json_decode($json, true);
    expect($decoded)->not->toBeNull();
    expect($decoded['metadata']['schema_version'])->toBe(1);
    expect($decoded['customers'])->toHaveCount(2);
});

it('customers.csv row count matches record_counts.customers', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $csv = $zip->getFromName('customers.csv');
    $zip->close();

    $lines = array_filter(explode("\n", trim($csv)));
    // 1 header + 2 rows = 3 lines
    expect(count($lines))->toBe(3);
    expect($result['record_counts']['customers'])->toBe(2);
});

it('returns sha256 that matches re-hash of file', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    expect($result['sha256'])->toBe(hash_file('sha256', $result['path']));
    expect(strlen($result['sha256']))->toBe(64);
});

it('returns size matching filesize', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    expect($result['size'])->toBe(filesize($result['path']));
    expect($result['size'])->toBeGreaterThan(0);
});

it('skips CSVs for empty sections (no commission_payouts.csv when empty)', function () {
    $result = (new DataExportZipWriter)->write(samplePayload());

    $zip = new ZipArchive;
    $zip->open($result['path']);
    expect($zip->locateName('commission_payouts.csv'))->toBeFalse();
    $zip->close();
});

it('includes commission_payouts.csv when section is non-empty', function () {
    $payload = samplePayload();
    $payload['billing']['commission_payouts'] = [
        ['id' => 'p1', 'status' => 'paid', 'amount_cents' => 8000, 'created_at' => '2026-01-05T00:00:00Z'],
    ];

    $result = (new DataExportZipWriter)->write($payload);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    expect($zip->locateName('commission_payouts.csv'))->not->toBeFalse();
    $zip->close();
});
```

- [ ] **Step 2: Run the tests — expect failure**

Run: `vendor/bin/pest tests/Unit/Services/Professional/DataExportZipWriterTest.php`
Expected: FAIL with class-not-found.

- [ ] **Step 3: Implement the writer**

Create `app/Services/Professional/DataExportZipWriter.php`:

```php
<?php

namespace App\Services\Professional;

use RuntimeException;
use ZipArchive;

// V2: Streams a payload array into a temp .zip on disk. Returns the path,
// SHA-256 hash, byte size, and a record_counts summary for the audit row.
// Builds CSVs only for sections a non-technical user opens in Excel.
class DataExportZipWriter
{
    /**
     * @return array{path: string, sha256: string, size: int, record_counts: array<string, int>}
     */
    public function write(array $payload): array
    {
        $path = tempnam(sys_get_temp_dir(), 'export-');
        if ($path === false) {
            throw new RuntimeException('Failed to create temp file for export zip.');
        }
        // tempnam creates an empty file; ZipArchive::CREATE refuses to overwrite a
        // non-zip file in some PHP versions. Unlink it so ZipArchive opens fresh.
        @unlink($path);
        $path .= '.zip';

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Failed to open zip for writing: {$path}");
        }

        $zip->addFromString('data.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $recordCounts = $this->recordCounts($payload);

        $this->maybeAddCsv($zip, 'customers.csv', $payload['customers'] ?? [], [
            'id', 'email', 'phone', 'full_name', 'source', 'notes', 'created_at',
        ]);

        $this->maybeAddCsv($zip, 'enquiries.csv', $payload['enquiries'] ?? [], [
            'id', 'name', 'email', 'phone', 'subject', 'message', 'created_at',
        ]);

        $this->maybeAddCsv($zip, 'bookings.csv', $payload['bookings']['booking_events'] ?? [], [
            'id', 'occurred_at', 'status', 'source', 'customer_name', 'customer_email', 'customer_phone', 'amount_paid_cents', 'currency_code', 'created_at',
        ]);

        $this->maybeAddCsv($zip, 'commission_payouts.csv', $payload['billing']['commission_payouts'] ?? [], [
            'id', 'status', 'amount_cents', 'created_at',
        ]);

        $zip->close();

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
            'size' => filesize($path),
            'record_counts' => $recordCounts,
        ];
    }

    /**
     * Add a CSV entry to the zip iff the section has rows. Empty sections are
     * intentionally omitted to keep the zip small for accounts with no
     * customers/bookings yet.
     */
    private function maybeAddCsv(ZipArchive $zip, string $name, array $rows, array $columns): void
    {
        if (empty($rows)) {
            return;
        }

        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $columns);

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $row[$col] ?? '';
            }
            fputcsv($fp, $line);
        }

        rewind($fp);
        $zip->addFromString($name, stream_get_contents($fp));
        fclose($fp);
    }

    /**
     * @return array<string, int>
     */
    private function recordCounts(array $payload): array
    {
        return [
            'customers' => count($payload['customers'] ?? []),
            'services' => count($payload['services'] ?? []),
            'service_categories' => count($payload['service_categories'] ?? []),
            'enquiries' => count($payload['enquiries'] ?? []),
            'email_subscriptions' => count($payload['email_subscriptions'] ?? []),
            'booking_events' => count($payload['bookings']['booking_events'] ?? []),
            'lead_submissions' => count($payload['bookings']['lead_submissions'] ?? []),
            'site_media' => count($payload['media']['site_media'] ?? []),
            'integrations' => count($payload['integrations'] ?? []),
            'commission_ledger_entries' => count($payload['billing']['commission_ledger_entries'] ?? []),
            'commission_payouts' => count($payload['billing']['commission_payouts'] ?? []),
        ];
    }
}
```

- [ ] **Step 4: Run the tests — expect pass**

Run: `vendor/bin/pest tests/Unit/Services/Professional/DataExportZipWriterTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Professional/DataExportZipWriter.php tests/Unit/Services/Professional/DataExportZipWriterTest.php
git commit -m "feat(data-export): DataExportZipWriter service"
```

---

## Task 6: `ProfessionalDataExportMail` mailable + Blade template (TDD)

**Files:**
- Create: `app/Mail/Gdpr/ProfessionalDataExportMail.php`
- Create: `resources/views/emails/gdpr/professional-data-export.blade.php`
- Test: `tests/Feature/Mail/ProfessionalDataExportMailTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mail/ProfessionalDataExportMailTest.php`:

```php
<?php

namespace Tests\Feature\Mail;

use App\Mail\Gdpr\ProfessionalDataExportMail;

it('renders the self-service variant without the staff banner', function () {
    $mail = new ProfessionalDataExportMail(
        signedUrl: 'https://r2.example.com/exports/abc/def.zip?signed=1',
        professionalHandle: 'jane',
        sendTo: 'professional',
        recordCounts: ['customers' => 10, 'booking_events' => 5],
    );

    $rendered = $mail->render();

    expect($rendered)->toContain('https://r2.example.com/exports/abc/def.zip?signed=1');
    expect($rendered)->toContain('7 days');
    expect($rendered)->not->toContain('staff data-handling SOP');
});

it('renders the staff variant with the PII banner', function () {
    $mail = new ProfessionalDataExportMail(
        signedUrl: 'https://r2.example.com/exports/abc/def.zip?signed=1',
        professionalHandle: 'jane',
        sendTo: 'staff',
        recordCounts: ['customers' => 10],
    );

    $rendered = $mail->render();

    expect($rendered)->toContain('staff data-handling SOP');
    expect($rendered)->toContain('jane');
});

it('uses different subject lines for each variant', function () {
    $self = new ProfessionalDataExportMail('https://x', 'jane', 'professional', []);
    $staff = new ProfessionalDataExportMail('https://x', 'jane', 'staff', []);

    expect($self->build()->subject)->toContain('Your Partna data export');
    expect($staff->build()->subject)->toContain('jane');
});
```

- [ ] **Step 2: Run the test — expect failure**

Run: `vendor/bin/pest tests/Feature/Mail/ProfessionalDataExportMailTest.php`
Expected: FAIL with class-not-found.

- [ ] **Step 3: Create the mailable**

Create `app/Mail/Gdpr/ProfessionalDataExportMail.php`:

```php
<?php

namespace App\Mail\Gdpr;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// V2: Emails the recipient (professional or admin staff) a 7-day signed R2
// URL pointing at the data-export zip. Two visual variants — self-service is
// addressed to the professional; staff variant carries a PII-handling banner.
class ProfessionalDataExportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $signedUrl,
        public string $professionalHandle,
        public string $sendTo,
        public array $recordCounts,
    ) {}

    public function build(): static
    {
        $subject = $this->sendTo === 'staff'
            ? "Partna data export — {$this->professionalHandle}"
            : 'Your Partna data export is ready';

        return $this
            ->subject($subject)
            ->view('emails.gdpr.professional-data-export', [
                'signedUrl' => $this->signedUrl,
                'professionalHandle' => $this->professionalHandle,
                'isStaff' => $this->sendTo === 'staff',
                'totalRecords' => array_sum($this->recordCounts),
                'ttlDays' => (int) config('sidest.gdpr.signed_url_ttl_days', 7),
            ]);
    }
}
```

- [ ] **Step 4: Create the Blade template**

Create `resources/views/emails/gdpr/professional-data-export.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your Partna data export</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #111; max-width: 640px; margin: 0 auto; padding: 24px;">
    @if ($isStaff)
        <div style="background: #fff7e6; border: 1px solid #ffd591; padding: 12px 16px; border-radius: 6px; margin-bottom: 24px;">
            <strong>Staff notice:</strong> this export contains customer PII collected by <strong>{{ $professionalHandle }}</strong>. Handle in accordance with the staff data-handling SOP. Do not forward this link.
        </div>
    @endif

    <h2 style="margin-top: 0;">Your Partna data export is ready</h2>

    <p>The data export for <strong>{{ $professionalHandle }}</strong> has been prepared.</p>

    <p><a href="{{ $signedUrl }}" style="display: inline-block; background: #111; color: #fff; padding: 12px 20px; border-radius: 6px; text-decoration: none;">Download the export (.zip)</a></p>

    <p>This link is valid for <strong>{{ $ttlDays }} days</strong>. The file contains roughly <strong>{{ number_format($totalRecords) }}</strong> records across your profile, customers, bookings, and billing history.</p>

    <p><strong>What's inside:</strong> a <code>data.json</code> file with the full machine-readable export, plus per-table CSVs (<code>customers.csv</code>, <code>bookings.csv</code>, <code>enquiries.csv</code>) for the tables you'd typically open in Excel or Numbers.</p>

    @unless ($isStaff)
        <p>If you collected customer information through Partna, this export includes it. You're responsible for handling that information in accordance with applicable privacy law.</p>
    @endunless

    <p>If you didn't request this export, reply to this email — we'll investigate.</p>

    <p>— Partna</p>

    <hr style="border: none; border-top: 1px solid #eee; margin: 32px 0 16px;">

    <p style="font-size: 12px; color: #666;">This message contains a link to a file stored on Cloudflare R2. The link expires in {{ $ttlDays }} days; the file itself is automatically deleted after 30 days.</p>
</body>
</html>
```

- [ ] **Step 5: Run the test — expect pass**

Run: `vendor/bin/pest tests/Feature/Mail/ProfessionalDataExportMailTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Mail/Gdpr/ProfessionalDataExportMail.php resources/views/emails/gdpr/professional-data-export.blade.php tests/Feature/Mail/ProfessionalDataExportMailTest.php
git commit -m "feat(data-export): ProfessionalDataExportMail mailable + view"
```

---

## Task 7: `ExportProfessionalDataJob` (TDD)

**Files:**
- Create: `app/Jobs/Gdpr/ExportProfessionalDataJob.php`
- Test: `tests/Feature/Jobs/Gdpr/ExportProfessionalDataJobTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/Gdpr/ExportProfessionalDataJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs\Gdpr;

use App\Jobs\Gdpr\ExportProfessionalDataJob;
use App\Mail\Gdpr\ProfessionalDataExportMail;
use App\Models\Core\Gdpr\DataExportAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Professional\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
    Storage::fake('media');
    Mail::fake();
});

function seedProfessionalForJob(string $id, string $handle = 'jane', string $email = 'jane@example.com'): void
{
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => mb_strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);
}

it('transitions audit row queued → processing → completed on happy path', function () {
    $profId = (string) Str::uuid();
    seedProfessionalForJob($profId);

    $audit = DataExportAudit::create([
        'professional_id' => $profId,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'send_to' => 'professional',
    ]);

    (new ExportProfessionalDataJob($audit->id))->handle();

    $audit->refresh();
    expect($audit->status)->toBe('completed');
    expect($audit->file_path)->toMatch('#^exports/'.$profId.'/'.$audit->id.'\.zip$#');
    expect($audit->file_size_bytes)->toBeGreaterThan(0);
    expect(strlen($audit->file_sha256))->toBe(64);
    expect($audit->record_counts)->toBeArray();
    expect($audit->completed_at)->not->toBeNull();
});

it('uploads the zip to the configured media disk', function () {
    $profId = (string) Str::uuid();
    seedProfessionalForJob($profId);

    $audit = DataExportAudit::create([
        'professional_id' => $profId,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'send_to' => 'professional',
    ]);

    (new ExportProfessionalDataJob($audit->id))->handle();

    Storage::disk('media')->assertExists("exports/{$profId}/{$audit->id}.zip");
});

it('sends the mailable to the recipient with the signed URL', function () {
    $profId = (string) Str::uuid();
    seedProfessionalForJob($profId);

    $audit = DataExportAudit::create([
        'professional_id' => $profId,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'send_to' => 'professional',
    ]);

    (new ExportProfessionalDataJob($audit->id))->handle();

    Mail::assertSent(ProfessionalDataExportMail::class, function (ProfessionalDataExportMail $mail) {
        return $mail->hasTo('jane@example.com')
            && str_contains($mail->signedUrl, 'exports/');
    });
});

it('aborts gracefully if the professional is hard-deleted between dispatch and run', function () {
    $audit = DataExportAudit::create([
        'professional_id' => null, // Simulates ON DELETE SET NULL after dispatch
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    (new ExportProfessionalDataJob($audit->id))->handle();

    $audit->refresh();
    expect($audit->status)->toBe('failed');
    expect($audit->error_message)->toContain('professional');
    Mail::assertNothingSent();
});

it('marks audit failed when audit row missing (job ran for deleted row)', function () {
    $bogusId = (string) Str::uuid();
    // No row created — job should noop gracefully.
    expect(fn () => (new ExportProfessionalDataJob($bogusId))->handle())->not->toThrow(Throwable::class);
});

it('failed() method marks audit as failed with a meaningful error', function () {
    $profId = (string) Str::uuid();
    seedProfessionalForJob($profId);

    $audit = DataExportAudit::create([
        'professional_id' => $profId,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
    ]);

    $job = new ExportProfessionalDataJob($audit->id);
    $job->failed(new \RuntimeException('queue worker killed'));

    $audit->refresh();
    expect($audit->status)->toBe('failed');
    expect($audit->error_message)->toContain('queue worker killed');
});
```

- [ ] **Step 2: Run the test — expect failure**

Run: `vendor/bin/pest tests/Feature/Jobs/Gdpr/ExportProfessionalDataJobTest.php`
Expected: FAIL with class-not-found.

- [ ] **Step 3: Implement the job**

Create `app/Jobs/Gdpr/ExportProfessionalDataJob.php`:

```php
<?php

namespace App\Jobs\Gdpr;

use App\Mail\Gdpr\ProfessionalDataExportMail;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Services\Professional\DataExportPayloadBuilder;
use App\Services\Professional\DataExportZipWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

// V2: Builds a professional-wide data export zip, uploads to R2, generates a
// signed URL, emails the recipient, and updates the audit row. Designed to
// run on the redis_gdpr queue (660s supervisor timeout).
class ExportProfessionalDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600; // under the 660s supervisor cap

    public function __construct(public string $auditId)
    {
        $this->onQueue(config('sidest.gdpr.queue'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $builder = app(DataExportPayloadBuilder::class);
        $writer = app(DataExportZipWriter::class);
        $audit = DataExportAudit::find($this->auditId);

        if (! $audit) {
            Log::warning('ExportProfessionalDataJob: audit row not found', ['audit_id' => $this->auditId]);

            return;
        }

        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }

        // Professional may have been hard-deleted between dispatch and run —
        // the FK is ON DELETE SET NULL so professional_id will be null.
        if (! $audit->professional_id) {
            $audit->markFailed('professional deleted before export ran');

            return;
        }

        $audit->markProcessing();

        $tmpPath = null;

        try {
            $payload = $builder->build($audit->professional_id);
            $written = $writer->write($payload);
            $tmpPath = $written['path'];

            $disk = Storage::disk(config('sidest.media_disk'));
            $remotePath = "exports/{$audit->professional_id}/{$audit->id}.zip";

            $stream = fopen($written['path'], 'rb');
            $disk->put($remotePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $ttlDays = (int) config('sidest.gdpr.signed_url_ttl_days', 7);
            $signedUrl = $disk->temporaryUrl($remotePath, now()->addDays($ttlDays));

            Mail::to($audit->recipient_email)->send(new ProfessionalDataExportMail(
                signedUrl: $signedUrl,
                professionalHandle: $audit->professional_handle_snapshot,
                sendTo: $audit->send_to ?? 'professional',
                recordCounts: $written['record_counts'],
            ));

            $audit->markCompleted(
                filePath: $remotePath,
                fileSizeBytes: $written['size'],
                fileSha256: $written['sha256'],
                recordCounts: $written['record_counts'],
            );

            Log::info('ExportProfessionalDataJob completed', [
                'audit_id' => $audit->id,
                'professional_id' => $audit->professional_id,
                'size' => $written['size'],
            ]);
        } catch (Throwable $e) {
            $audit->markFailed($e->getMessage());
            Log::error('ExportProfessionalDataJob failed', [
                'audit_id' => $audit->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // let queue retry per $tries/$backoff
        } finally {
            if ($tmpPath && file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /**
     * Called by Laravel after $tries is exhausted. Without this, a stuck job
     * leaves the audit row in 'processing' indefinitely.
     */
    public function failed(Throwable $e): void
    {
        $audit = DataExportAudit::find($this->auditId);
        if ($audit && $audit->status !== DataExportAudit::STATUS_COMPLETED) {
            $audit->markFailed('Job failed after retries: '.$e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Run the test — expect pass**

Run: `vendor/bin/pest tests/Feature/Jobs/Gdpr/ExportProfessionalDataJobTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Gdpr/ExportProfessionalDataJob.php tests/Feature/Jobs/Gdpr/ExportProfessionalDataJobTest.php
git commit -m "feat(data-export): ExportProfessionalDataJob orchestrator"
```

---

## Task 8: `DataExportService::dispatch()` (TDD)

**Files:**
- Create: `app/Services/Professional/DataExportService.php`
- Test: `tests/Feature/Professional/DataExport/DataExportServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Professional/DataExport/DataExportServiceTest.php`:

```php
<?php

namespace Tests\Feature\Professional\DataExport;

use App\Jobs\Gdpr\ExportProfessionalDataJob;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\Professional\DataExportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    DataExportTestCase::boot();
    Queue::fake();
});

function seedProForService(string $id, string $email = 'jane@example.com'): Professional
{
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    return Professional::find($id);
}

it('inserts an audit row with status queued and dispatches the job', function () {
    $pro = seedProForService((string) Str::uuid());
    $service = app(DataExportService::class);

    $audit = $service->dispatch($pro, 'self', null, 'professional');

    expect($audit->status)->toBe('queued');
    expect($audit->triggered_by)->toBe('self');
    expect($audit->recipient_email)->toBe('jane@example.com');
    expect($audit->professional_handle_snapshot)->toBe('jane');

    Queue::assertPushed(ExportProfessionalDataJob::class, fn ($j) => $j->auditId === $audit->id);
});

it('throws OnRecentExportException when an export was queued in the last 30 minutes', function () {
    $pro = seedProForService((string) Str::uuid());
    $service = app(DataExportService::class);

    $first = $service->dispatch($pro, 'self', null, 'professional');

    expect(fn () => $service->dispatch($pro, 'self', null, 'professional'))
        ->toThrow(\App\Exceptions\Gdpr\DataExportInProgressException::class);
});

it('allows a new export after the dedup window passes', function () {
    Carbon::setTestNow('2026-04-25T10:00:00Z');
    $pro = seedProForService((string) Str::uuid());
    $service = app(DataExportService::class);

    $first = $service->dispatch($pro, 'self', null, 'professional');

    // 31 minutes later — past the 30-min window
    Carbon::setTestNow('2026-04-25T10:31:00Z');
    $second = $service->dispatch($pro, 'self', null, 'professional');

    expect($second->id)->not->toBe($first->id);
    Carbon::setTestNow();
});

it('staff dispatch with send_to=staff resolves recipient to the staff email', function () {
    $pro = seedProForService((string) Str::uuid());
    $staffId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.sidest_staff')->insert([
        'id' => $staffId,
        'role' => 'admin',
        'primary_email' => 'admin@sidest.io',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $service = app(DataExportService::class);
    $audit = $service->dispatch($pro, 'staff', $staffId, 'staff');

    expect($audit->recipient_email)->toBe('admin@sidest.io');
    expect($audit->triggered_by_staff_id)->toBe($staffId);
});

it('staff dispatch with send_to=professional resolves recipient to the professional email', function () {
    $pro = seedProForService((string) Str::uuid(), 'jane@example.com');
    $staffId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.sidest_staff')->insert([
        'id' => $staffId,
        'role' => 'support',
        'primary_email' => 'support@sidest.io',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $service = app(DataExportService::class);
    $audit = $service->dispatch($pro, 'staff', $staffId, 'professional');

    expect($audit->recipient_email)->toBe('jane@example.com');
});

it('throws when professional has no recipient email and send_to=professional', function () {
    $pro = seedProForService((string) Str::uuid(), '');
    DB::connection('pgsql')->table('core.professionals')->where('id', $pro->id)->update(['primary_email' => null]);
    $pro->refresh();

    $service = app(DataExportService::class);

    expect(fn () => $service->dispatch($pro, 'self', null, 'professional'))
        ->toThrow(\App\Exceptions\Gdpr\NoRecipientEmailException::class);
});
```

- [ ] **Step 2: Run the test — expect failure**

Run: `vendor/bin/pest tests/Feature/Professional/DataExport/DataExportServiceTest.php`
Expected: FAIL with class-not-found.

- [ ] **Step 3: Create the two exception classes**

Create `app/Exceptions/Gdpr/DataExportInProgressException.php`:

```php
<?php

namespace App\Exceptions\Gdpr;

use RuntimeException;

class DataExportInProgressException extends RuntimeException
{
    public function __construct(public string $existingExportId)
    {
        parent::__construct('A data export is already in progress for this professional.');
    }
}
```

Create `app/Exceptions/Gdpr/NoRecipientEmailException.php`:

```php
<?php

namespace App\Exceptions\Gdpr;

use RuntimeException;

class NoRecipientEmailException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No valid recipient email on file.');
    }
}
```

- [ ] **Step 4: Implement `DataExportService`**

Create `app/Services/Professional/DataExportService.php`:

```php
<?php

namespace App\Services\Professional;

use App\Exceptions\Gdpr\DataExportInProgressException;
use App\Exceptions\Gdpr\NoRecipientEmailException;
use App\Jobs\Gdpr\ExportProfessionalDataJob;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;

// V2: Single dispatch entry point for professional data exports. Inserts the
// audit row, runs the dedup check, and queues the job. Both controllers
// (self-service + staff) call this with different parameters — the only
// branching is recipient resolution.
class DataExportService
{
    /**
     * @param  'self'|'staff'  $triggeredBy
     * @param  'professional'|'staff'  $sendTo
     */
    public function dispatch(
        Professional $professional,
        string $triggeredBy,
        ?string $staffId,
        string $sendTo,
    ): DataExportAudit {
        $recipient = $this->resolveRecipient($professional, $staffId, $sendTo);

        if (! $recipient) {
            throw new NoRecipientEmailException;
        }

        return DB::connection('pgsql')->transaction(function () use ($professional, $triggeredBy, $staffId, $sendTo, $recipient) {
            // Lock the professional row for the duration of the dedup check.
            // Two concurrent requests serialize through this — only one wins.
            DB::connection('pgsql')
                ->table('core.professionals')
                ->where('id', $professional->id)
                ->lockForUpdate()
                ->first();

            $existing = $this->findRecentInFlight($professional->id);
            if ($existing) {
                throw new DataExportInProgressException($existing->id);
            }

            $audit = DataExportAudit::create([
                'professional_id' => $professional->id,
                'professional_handle_snapshot' => $professional->handle,
                'professional_email_snapshot' => $professional->primary_email,
                'triggered_by' => $triggeredBy,
                'triggered_by_staff_id' => $staffId,
                'recipient_email' => $recipient,
                'send_to' => $sendTo,
            ]);

            ExportProfessionalDataJob::dispatch($audit->id);

            return $audit;
        });
    }

    /**
     * Find any audit row for this professional in 'queued' or 'processing'
     * status created within the dedup window. Used both by the dedup check
     * and by callers that want to surface the existing export id.
     */
    public function findRecentInFlight(string $professionalId): ?DataExportAudit
    {
        $windowMinutes = (int) config('sidest.gdpr.dedup_window_minutes', 30);

        return DataExportAudit::query()
            ->where('professional_id', $professionalId)
            ->whereIn('status', [DataExportAudit::STATUS_QUEUED, DataExportAudit::STATUS_PROCESSING])
            ->where('created_at', '>', now()->subMinutes($windowMinutes))
            ->first();
    }

    private function resolveRecipient(Professional $professional, ?string $staffId, string $sendTo): ?string
    {
        if ($sendTo === 'staff' && $staffId) {
            $staff = PartnaStaff::find($staffId);

            return $staff?->primary_email;
        }

        return $professional->public_contact_email
            ?: $professional->primary_email;
    }
}
```

- [ ] **Step 5: Run the test — expect pass**

Run: `vendor/bin/pest tests/Feature/Professional/DataExport/DataExportServiceTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Professional/DataExportService.php app/Exceptions/Gdpr/ tests/Feature/Professional/DataExport/DataExportServiceTest.php
git commit -m "feat(data-export): DataExportService dispatch + dedup"
```

---

## Task 9: Form requests

**Files:**
- Create: `app/Http/Requests/Professional/RequestDataExportRequest.php`
- Create: `app/Http/Requests/Staff/RequestStaffDataExportRequest.php`

- [ ] **Step 1: Create the self-service request (placeholder for future filters)**

Create `app/Http/Requests/Professional/RequestDataExportRequest.php`:

```php
<?php

namespace App\Http\Requests\Professional;

use Illuminate\Foundation\Http\FormRequest;

// V2: Currently empty body; placeholder for future filtered/partial exports
// (e.g. ?include=customers,bookings). Keeping the class lets us evolve later
// without refactoring the controller signature.
class RequestDataExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
```

- [ ] **Step 2: Create the staff request**

Create `app/Http/Requests/Staff/RequestStaffDataExportRequest.php`:

```php
<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// V2: Validates the `send_to` query param on staff-triggered data exports.
// Default is 'professional' (the safer mode). 'staff' requires admin role —
// enforced in the controller, not here.
class RequestStaffDataExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/Professional/RequestDataExportRequest.php app/Http/Requests/Staff/RequestStaffDataExportRequest.php
git commit -m "feat(data-export): form requests for self-service + staff"
```

---

## Task 10: Self-service controller + route (TDD)

**Files:**
- Create: `app/Http/Controllers/Api/Professional/ProfessionalDataExportController.php`
- Modify: `routes/api/professional.php`
- Test: `tests/Feature/Professional/DataExport/RequestSelfServiceExportTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Professional/DataExport/RequestSelfServiceExportTest.php`:

```php
<?php

namespace Tests\Feature\Professional\DataExport;

use App\Jobs\Gdpr\ExportProfessionalDataJob;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    DataExportTestCase::boot();
    Queue::fake();
});

function seedActivePro(string $id, string $email = 'jane@example.com', string $status = 'active'): Professional
{
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => $email,
        'status' => $status,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    return Professional::find($id);
}

it('returns 202 + audit row on happy path', function () {
    $pro = seedActivePro((string) Str::uuid());

    $response = $this->withProfessional($pro)
        ->postJson('/api/professional/me/data-export');

    $response->assertStatus(202);
    $response->assertJsonPath('status', 'queued');
    $response->assertJsonPath('recipient_email', 'jane@example.com');
    expect(DataExportAudit::where('professional_id', $pro->id)->count())->toBe(1);
    Queue::assertPushed(ExportProfessionalDataJob::class);
});

it('returns 409 when an export is in flight inside the 30-min dedup window', function () {
    $pro = seedActivePro((string) Str::uuid());
    DataExportAudit::create([
        'professional_id' => $pro->id,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'status' => 'queued',
    ]);

    $response = $this->withProfessional($pro)
        ->postJson('/api/professional/me/data-export');

    $response->assertStatus(409);
    $response->assertJsonStructure(['existing_export_id']);
});

it('returns 422 when professional has no recipient email', function () {
    $pro = seedActivePro((string) Str::uuid());
    DB::connection('pgsql')->table('core.professionals')->where('id', $pro->id)->update(['primary_email' => null]);

    $response = $this->withProfessional($pro->fresh())
        ->postJson('/api/professional/me/data-export');

    $response->assertStatus(422);
});

// Note: 403 for suspended/disabled is enforced by LoadCurrentProfessional middleware,
// already covered by existing middleware tests. Only assert here that pending_deletion
// IS allowed through (the GDPR portability point).
it('allows export during pending_deletion grace period', function () {
    $pro = seedActivePro((string) Str::uuid(), 'jane@example.com', 'pending_deletion');

    $response = $this->withProfessional($pro)
        ->postJson('/api/professional/me/data-export');

    $response->assertStatus(202);
});
```

The `withProfessional()` helper is the same pattern used in existing professional-side tests. If it doesn't exist yet, mirror what `tests/Feature/Professional/AccountDeletion/RequestDeletionTest.php` does to inject the authenticated professional via `request()->attributes->set('professional', $pro)`. Reuse the existing helper rather than redefining.

- [ ] **Step 2: Run the test — expect failure**

Run: `vendor/bin/pest tests/Feature/Professional/DataExport/RequestSelfServiceExportTest.php`
Expected: FAIL — route 404 / class-not-found.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Professional/ProfessionalDataExportController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Professional;

use App\Exceptions\Gdpr\DataExportInProgressException;
use App\Exceptions\Gdpr\NoRecipientEmailException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Professional\RequestDataExportRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\DataExportService;
use Illuminate\Http\JsonResponse;

// V2: Self-service data export. Thin controller — all logic in DataExportService.
// Exempt from EnforcePendingDeletionReadOnly middleware via route definition
// (a leaving professional must be able to export their data — GDPR portability).
class ProfessionalDataExportController extends ApiController
{
    public function __construct(
        private readonly DataExportService $exportService,
    ) {}

    public function store(RequestDataExportRequest $request): JsonResponse
    {
        /** @var Professional $professional */
        $professional = $request->attributes->get('professional');

        try {
            $audit = $this->exportService->dispatch(
                professional: $professional,
                triggeredBy: 'self',
                staffId: null,
                sendTo: 'professional',
            );
        } catch (DataExportInProgressException $e) {
            return $this->error('An export is already in progress.', 409, [
                'existing_export_id' => $e->existingExportId,
            ]);
        } catch (NoRecipientEmailException) {
            return $this->error('No valid recipient email on file.', 422);
        }

        return $this->success([
            'export_id' => $audit->id,
            'status' => $audit->status,
            'recipient_email' => $audit->recipient_email,
            'message' => "Your data export is being prepared. You'll receive an email at {$audit->recipient_email} within a few minutes with a download link valid for 7 days.",
        ], 202);
    }
}
```

- [ ] **Step 4: Add the route**

Modify `routes/api/professional.php`. After the `me/deletion` route group (around line 66-95), add:

```php
        // Data export — exempt from EnforcePendingDeletionReadOnly so a
        // professional in their grace period can still pull their data
        // (the whole point of GDPR portability). Rate-limited 1/24h.
        Route::post('/me/data-export', [ProfessionalDataExportController::class, 'store'])
            ->withoutMiddleware([EnforcePendingDeletionReadOnly::class])
            ->middleware('throttle:1,1440');
```

Add the import at the top of the file:

```php
use App\Http\Controllers\Api\Professional\ProfessionalDataExportController;
```

- [ ] **Step 5: Run the test — expect pass**

Run: `vendor/bin/pest tests/Feature/Professional/DataExport/RequestSelfServiceExportTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Professional/ProfessionalDataExportController.php routes/api/professional.php tests/Feature/Professional/DataExport/RequestSelfServiceExportTest.php
git commit -m "feat(data-export): self-service POST /me/data-export"
```

---

## Task 11: Staff controller + route (TDD)

**Files:**
- Create: `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffDataExportController.php`
- Modify: `routes/api/staff.php`
- Test: `tests/Feature/Staff/DataExport/RequestStaffExportTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/DataExport/RequestStaffExportTest.php`:

```php
<?php

namespace Tests\Feature\Staff\DataExport;

use App\Jobs\Gdpr\ExportProfessionalDataJob;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Professional\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
    Queue::fake();
});

function seedStaff(string $role): PartnaStaff
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.sidest_staff')->insert([
        'id' => $id,
        'role' => $role,
        'primary_email' => $role.'@sidest.io',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    return PartnaStaff::find($id);
}

function seedProForStaff(string $email = 'jane@example.com'): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    return Professional::find($id);
}

it('returns 202 with send_to=professional (default) for any staff role', function () {
    $staff = seedStaff('support');
    $pro = seedProForStaff();

    $response = $this->withStaff($staff)
        ->postJson("/api/staff/professionals/{$pro->id}/data-export");

    $response->assertStatus(202);
    $response->assertJsonPath('send_to', 'professional');
    expect(DataExportAudit::where('professional_id', $pro->id)->first()->recipient_email)
        ->toBe('jane@example.com');
});

it('returns 202 with send_to=staff when caller is admin', function () {
    $staff = seedStaff('admin');
    $pro = seedProForStaff();

    $response = $this->withStaff($staff)
        ->postJson("/api/staff/professionals/{$pro->id}/data-export?send_to=staff");

    $response->assertStatus(202);
    $response->assertJsonPath('send_to', 'staff');
    expect(DataExportAudit::where('professional_id', $pro->id)->first()->recipient_email)
        ->toBe('admin@sidest.io');
});

it('returns 403 with send_to=staff when caller is non-admin', function () {
    $staff = seedStaff('support');
    $pro = seedProForStaff();

    $response = $this->withStaff($staff)
        ->postJson("/api/staff/professionals/{$pro->id}/data-export?send_to=staff");

    $response->assertStatus(403);
    Queue::assertNothingPushed();
});

it('returns 404 for missing professional', function () {
    $staff = seedStaff('admin');

    $response = $this->withStaff($staff)
        ->postJson('/api/staff/professionals/'.(string) Str::uuid().'/data-export');

    $response->assertStatus(404);
});

it('returns 409 when an export is already in flight (dedup applies to staff too)', function () {
    $staff = seedStaff('admin');
    $pro = seedProForStaff();

    DataExportAudit::create([
        'professional_id' => $pro->id,
        'professional_handle_snapshot' => 'jane',
        'triggered_by' => 'self',
        'recipient_email' => 'jane@example.com',
        'status' => 'queued',
    ]);

    $response = $this->withStaff($staff)
        ->postJson("/api/staff/professionals/{$pro->id}/data-export");

    $response->assertStatus(409);
});
```

The `withStaff()` helper exists in the existing staff test suites (e.g. `tests/Feature/Staff/`). Reuse that pattern; if it isn't there yet, mirror the existing pattern that injects the authenticated staff via `request()->attributes->set('staff', $staff)`.

- [ ] **Step 2: Run the test — expect failure**

Run: `vendor/bin/pest tests/Feature/Staff/DataExport/RequestStaffExportTest.php`
Expected: FAIL — route 404.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffDataExportController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Exceptions\Gdpr\DataExportInProgressException;
use App\Exceptions\Gdpr\NoRecipientEmailException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Staff\RequestStaffDataExportRequest;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\Professional\DataExportService;
use Illuminate\Http\JsonResponse;

// V2: Staff-triggered data export. Same DataExportService as self-service —
// only difference is the recipient resolution path. send_to=staff requires
// admin role (data exfiltration to a Partna inbox). Default send_to=professional
// (the safer mode) is allowed for any staff role.
class StaffDataExportController extends ApiController
{
    public function __construct(
        private readonly DataExportService $exportService,
    ) {}

    public function store(
        RequestStaffDataExportRequest $request,
        Professional $professional,
    ): JsonResponse {
        /** @var PartnaStaff $staff */
        $staff = $request->attributes->get('staff');

        $sendTo = (string) $request->validated('send_to');

        if ($sendTo === 'staff' && $staff->role !== 'admin') {
            return $this->error('Only admin staff can receive exports directly.', 403);
        }

        try {
            $audit = $this->exportService->dispatch(
                professional: $professional,
                triggeredBy: 'staff',
                staffId: $staff->id,
                sendTo: $sendTo,
            );
        } catch (DataExportInProgressException $e) {
            return $this->error('An export is already in progress.', 409, [
                'existing_export_id' => $e->existingExportId,
            ]);
        } catch (NoRecipientEmailException) {
            return $this->error('No valid recipient email on file.', 422);
        }

        return $this->success([
            'export_id' => $audit->id,
            'status' => $audit->status,
            'recipient_email' => $audit->recipient_email,
            'send_to' => $audit->send_to,
            'professional' => [
                'id' => $professional->id,
                'handle' => $professional->handle,
            ],
        ], 202);
    }
}
```

- [ ] **Step 4: Add the route**

Modify `routes/api/staff.php`. Inside the staff route group, alongside the existing `professionals/{professional}/...` routes, add:

```php
        // Data export — staff-triggered. ?send_to=staff requires admin role
        // (enforced in the controller). Same 30-min dedup window as self-service.
        Route::post('/professionals/{professional}/data-export', [StaffDataExportController::class, 'store']);
```

Add the import at the top:

```php
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffDataExportController;
```

- [ ] **Step 5: Run the test — expect pass**

Run: `vendor/bin/pest tests/Feature/Staff/DataExport/RequestStaffExportTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Run the full data-export test suite to verify nothing regressed**

Run: `vendor/bin/pest tests/Feature/Professional/DataExport tests/Feature/Staff/DataExport tests/Unit/Services/Professional tests/Feature/Jobs/Gdpr/ExportProfessionalDataJobTest.php tests/Feature/Mail/ProfessionalDataExportMailTest.php`
Expected: ALL PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffDataExportController.php routes/api/staff.php tests/Feature/Staff/DataExport/RequestStaffExportTest.php
git commit -m "feat(data-export): staff POST /staff/professionals/{id}/data-export"
```

---

## Task 12: Run pint + the full test suite + finalize

- [ ] **Step 1: Run code style fixer**

Run: `php artisan pint`
Expected: clean output, possibly small style fixes.

- [ ] **Step 2: Run the full test suite**

Run: `composer test`
Expected: ALL PASS. The composer guard against Laravel migrations should also pass (we only added a Supabase migration).

- [ ] **Step 3: If pint changed anything, commit those style fixes**

```bash
git add -A
git commit -m "style: apply pint to data-export changes"
```

- [ ] **Step 4: Add the operational checklist note to a deploy README (if one exists)**

Read: check whether the repo has a `DEPLOY.md`, `OPERATIONS.md`, or similar runbook. If yes, add a section noting the manual R2 lifecycle rule. If no such doc exists, leave the note in the spec only — do not create one. Skip this step if no runbook is present.

If a runbook exists, append:

```markdown
### Data export R2 lifecycle (one-time, per environment)

The data-export feature stores zips at `exports/{professional_id}/{export_id}.zip` on the media R2 bucket. Configure a Cloudflare R2 lifecycle rule that expires objects under the `exports/` prefix after 30 days.

- Cloudflare dashboard → R2 → bucket → Settings → Object lifecycle rules
- Prefix: `exports/`
- Action: Expire objects after 30 days

Without this rule, exports accumulate forever and storage costs grow unbounded.
```

- [ ] **Step 5: Final commit (if anything was added)**

```bash
git add -A
git commit -m "docs(data-export): note R2 lifecycle rule in deploy runbook"
```

If no runbook existed and nothing was added, skip this step.

---

## Self-Review Checklist (after implementation)

Before marking this plan complete, the implementer should verify:

1. **Spec coverage:**
   - ✅ `core.data_export_audit` table created with all columns from spec — Task 1
   - ✅ Config keys `signed_url_ttl_days`, `dedup_window_minutes` — Task 2 (`export_retention_days` already exists pre-task)
   - ✅ `DataExportAudit` model with status helpers — Task 3
   - ✅ `DataExportPayloadBuilder` excludes auth_user_id, deletion_token_hash, OAuth tokens, raw_payload, includes ring 1+2 — Task 4
   - ✅ `DataExportZipWriter` produces zip with data.json + per-table CSVs, returns sha256+size+counts — Task 5
   - ✅ `ProfessionalDataExportMail` with conditional staff banner — Task 6
   - ✅ `ExportProfessionalDataJob` with `tries=3`, `backoff=[60,300,900]`, `failed()` method, runs on `redis_gdpr` queue — Task 7
   - ✅ `DataExportService::dispatch()` with 30-min dedup, recipient resolution, `FOR UPDATE` lock — Task 8
   - ✅ Form requests for both endpoints — Task 9
   - ✅ Self-service controller exempt from `EnforcePendingDeletionReadOnly`, `throttle:1,1440` — Task 10
   - ✅ Staff controller with admin gate on `send_to=staff` — Task 11
   - ✅ R2 lifecycle rule documented as a manual ops step — Task 12

2. **Manual verification (deferred to staging):**
   - Real upload to R2 staging bucket; signed URL works in browser.
   - Real email lands with correct attachment-link content.
   - Lifecycle rule deletes objects after 30 days (verify after 31+ days).

3. **Out of scope (per spec):**
   - GET status endpoint — not built, intentional.
   - Re-issuance of expired URLs — not built, intentional.
   - Filtered/partial exports — request class is in place but no fields validated yet.
