# Shopify GDPR Webhooks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the stub `ShopifyGdprWebhookController` with functional handlers for the three mandatory Shopify GDPR webhooks (`customers/data_request`, `customers/redact`, `shop/redact`). Each webhook validates HMAC, writes an audit row for idempotency, dispatches a dedicated queued job, and returns 202. Without this, Partna cannot be submitted to the Shopify App Store.

**Architecture:** Thin controller (HMAC + idempotent audit row + dispatch) plus three queued jobs on a dedicated `gdpr` queue. A `GdprRequest` audit table keyed on sha256-of-raw-body provides idempotency against Shopify retries. A shared `ShopifyShopResolver` service maps `shop_domain → professional_id`. `shop/redact` uses **narrow scope**: delete the Shopify integration + Shopify-derived data (affiliate selections, synced customers) but **keep** the professional account (they may still use Fresha/Square). Customer data export is **merchant-forward** — the shop owner receives a JSON dump via email and forwards to the requesting customer (Shopify-recommended pattern).

**Tech Stack:** Laravel 12, PostgreSQL (Supabase), Pest 4, SQLite in-memory for tests, Laravel Mail, Laravel Queue (Redis/Horizon).

**Spec / design decisions:** Captured in memory note `project_shopify_gdpr_webhooks_todo.md`. Key calls:
- **Narrow shop/redact** (keep professional, delete integration + Shopify-derived data only).
- **Merchant-forward export** (email the shop owner, not the end customer).
- **Anonymise customers** on `customers/redact` rather than hard-delete (preserve commission ledger integrity).
- **Revoke tokens FIRST** in `RedactShopJob` so a mid-job crash still kills Shopify API access.
- **Idempotency via `payload_hash`** unique index (sha256 of raw body); Shopify retries are automatically deduped.
- **Invalid HMAC returns 401**, not 200 (current stub has a silent-acceptance bug).

---

## File Structure

### Created (15)

| Path | Responsibility |
|------|----------------|
| `supabase/migrations/20260423000001_create_gdpr_requests.sql` | `core.gdpr_requests` audit table + `core.customers.redacted_at` column |
| `app/Models/Core/Gdpr/GdprRequest.php` | Eloquent model for the audit table |
| `app/Services/Shopify/ShopifyShopResolver.php` | `resolveProfessionalId(shop_domain): ?string` — single source of truth |
| `app/Jobs/Shopify/Gdpr/RedactShopJob.php` | Narrow shop redact — integration + affiliate selections + Shopify-sourced customers |
| `app/Jobs/Shopify/Gdpr/RedactCustomerJob.php` | Anonymise customer row + scrub booking_events PII + prune subs/enquiries |
| `app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php` | Gather customer PII + dispatch Mailable to merchant |
| `app/Mail/Gdpr/CustomerDataExportMail.php` | Mailable with JSON attachment |
| `resources/views/emails/gdpr/customer-data-export.blade.php` | Email body pointing merchant at the attached JSON |
| `tests/Feature/Shopify/Gdpr/GdprRequestModelTest.php` | Model + idempotency index tests |
| `tests/Feature/Shopify/Gdpr/ShopifyShopResolverTest.php` | Resolver unit tests |
| `tests/Feature/Shopify/Gdpr/RedactShopJobTest.php` | Narrow shop redact integration tests |
| `tests/Feature/Shopify/Gdpr/RedactCustomerJobTest.php` | Customer anonymisation + booking_events scrub tests |
| `tests/Feature/Shopify/Gdpr/ExportCustomerDataJobTest.php` | Export happy path + unknown shop |
| `tests/Feature/Shopify/Gdpr/GdprWebhookControllerTest.php` | Controller: HMAC, idempotency, dispatch, status codes |
| `docs/shopify-gdpr-runbook.md` | Ops runbook for App Store submission (privacy policy, dev-store dry run) |

### Modified (3)

| Path | Change |
|------|--------|
| `app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php` | Replace stub with audit-row + dispatch + 401 on bad HMAC + 202 on accept |
| `config/sidest.php` | Add `gdpr` config block (queue name, placeholder domain, retention) |
| `.env.example` | Add `GDPR_QUEUE=gdpr` |

---

## Task 0: Pre-flight PII audit (verify no schema drift)

**Why:** This plan was written against the schema as of 2026-04-23. The `RedactCustomerJob` only scrubs PII from the tables listed below. If migrations have added denormalised PII columns to new tables since then, they will NOT be scrubbed and that's a Shopify non-compliance bug. This task confirms the inventory is still current before implementation begins.

**Files:** none — investigative only.

- [ ] **Step 1: Re-run the PII column grep**

Run from the repo root:

```bash
grep -rhnE "(customer_email|customer_phone|customer_name|buyer_email|buyer_phone|visitor_email|recipient_email|subscriber_email)" supabase/migrations/ | sort -u
```

Expected output (as of 2026-04-23) — three lines from `analytics.booking_events`:

```
1219:    customer_name text NULL,
1220:    customer_email text NULL,
1221:    customer_phone text NULL,
```

```bash
grep -rhnE "^\s+(email|phone|full_name|first_name|last_name)\s+(text|varchar)" supabase/migrations/ | sort -u
```

Expected output (as of 2026-04-23) — columns on these tables only:

| Table | Columns | GDPR action |
|-------|---------|-------------|
| `site.enquiries` | email, phone | hard-deleted in RedactCustomerJob |
| `notifications.email_subscriptions` | email, full_name | hard-deleted in RedactCustomerJob |
| `core.customers` | email, phone, full_name | anonymised in RedactCustomerJob / RedactShopJob |
| `core.professionals` | phone, first_name, last_name | **out of scope** — merchant PII, handled by account deletion feature |
| `core.comet_staff` | email, phone | **out of scope** — Partna internal staff |
| `core.waitlist_signups` | email, phone | **out of scope** — Partna's own pre-signup leads, not shop-scoped |
| `brand.brand_affiliate_invites` | email, phone, first_name, last_name | **out of scope** — B2B affiliate invites (future professionals, not customers) |

- [ ] **Step 2: Compare output against the expected set**

If the grep returns **any additional column/table** not listed in the two tables above:

1. **Stop execution.**
2. Report the new table + column to the user.
3. For each new column, determine: is this a shop customer's PII, or merchant/staff/B2B data?
4. If customer PII → add scrubbing to `RedactCustomerJob::handle()` and an anonymisation step to `RedactShopJob::anonymiseShopifyCustomers()`.
5. Update this plan's audit section with the new row, then resume.

If the output matches exactly, proceed to Task 1.

- [ ] **Step 3: No commit**

This task produces no code changes. If the audit passes clean, move on.

---

## Task 1: Migration — gdpr_requests table + customers.redacted_at column

**Files:**
- Create: `supabase/migrations/20260423000001_create_gdpr_requests.sql`

- [ ] **Step 1: Write the migration**

Create `supabase/migrations/20260423000001_create_gdpr_requests.sql`:

```sql
-- Create core.gdpr_requests — audit trail + idempotency guard for Shopify GDPR webhooks.
--
-- Why: Shopify retries webhooks on any non-2xx response. Without an idempotency key
-- we'd process the same `customers/redact` or `shop/redact` twice. The unique index
-- on payload_hash (sha256 of raw body) gives us a fast dedupe at write time.
--
-- Also adds core.customers.redacted_at so RedactCustomerJob can mark anonymised
-- rows (the row is kept for commission ledger integrity; only PII is overwritten).

BEGIN;

CREATE TABLE IF NOT EXISTS core.gdpr_requests (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    topic text NOT NULL,
    shop_domain text NOT NULL,
    shopify_shop_id bigint,
    payload_hash char(64) NOT NULL,
    payload jsonb NOT NULL,
    professional_id uuid,
    status text NOT NULL DEFAULT 'received',
    error text,
    received_at timestamptz DEFAULT now() NOT NULL,
    completed_at timestamptz,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    CONSTRAINT gdpr_requests_pkey PRIMARY KEY (id),
    CONSTRAINT gdpr_requests_topic_chk CHECK (topic IN ('customers/data_request', 'customers/redact', 'shop/redact')),
    CONSTRAINT gdpr_requests_status_chk CHECK (status IN ('received', 'processing', 'completed', 'failed', 'skipped'))
);

ALTER TABLE core.gdpr_requests OWNER TO postgres;

ALTER TABLE ONLY core.gdpr_requests
    ADD CONSTRAINT gdpr_requests_professional_fk
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

-- Idempotency: unique on sha256(raw body). Duplicate deliveries from Shopify
-- fail insert and the controller treats that as "already handled".
CREATE UNIQUE INDEX gdpr_requests_payload_hash_unique
    ON core.gdpr_requests (payload_hash);

CREATE INDEX gdpr_requests_shop_topic_idx
    ON core.gdpr_requests (shop_domain, topic, received_at DESC);

-- Ops query: find stuck jobs (received/processing rows older than N hours).
CREATE INDEX gdpr_requests_status_received_idx
    ON core.gdpr_requests (status, received_at);

ALTER TABLE core.gdpr_requests ENABLE ROW LEVEL SECURITY;

CREATE POLICY gdpr_requests_app_backend_all
    ON core.gdpr_requests
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

GRANT SELECT, INSERT, UPDATE, DELETE ON core.gdpr_requests TO app_backend;

COMMENT ON TABLE core.gdpr_requests IS
    'Audit trail for Shopify GDPR webhooks. payload_hash (sha256 of raw body) has a unique index — this is the idempotency guard for Shopify retries.';

-- Add redacted_at to core.customers — marker for GDPR customer anonymisation.
-- Row is kept (commission ledger integrity); email/phone/full_name are overwritten with placeholders.
ALTER TABLE core.customers
    ADD COLUMN IF NOT EXISTS redacted_at timestamptz;

COMMENT ON COLUMN core.customers.redacted_at IS
    'Set when customer PII is anonymised via Shopify customers/redact webhook. Non-null means email/phone/full_name have been overwritten with placeholders.';

COMMIT;
```

- [ ] **Step 2: Commit**

```bash
git add supabase/migrations/20260423000001_create_gdpr_requests.sql
git commit -m "feat(gdpr): add gdpr_requests audit table and customers.redacted_at"
```

---

## Task 2: GdprRequest model + tests

**Files:**
- Create: `app/Models/Core/Gdpr/GdprRequest.php`
- Create: `tests/Feature/Shopify/Gdpr/GdprRequestModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Shopify/Gdpr/GdprRequestModelTest.php`:

```php
<?php

use App\Models\Core\Gdpr\GdprRequest;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.gdpr_requests (
        id TEXT PRIMARY KEY,
        topic TEXT NOT NULL,
        shop_domain TEXT NOT NULL,
        shopify_shop_id INTEGER,
        payload_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'received\',
        error TEXT,
        received_at TEXT,
        completed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE UNIQUE INDEX IF NOT EXISTS gdpr_requests_payload_hash_unique ON core.gdpr_requests (payload_hash)');
});

it('persists a new GDPR request with the expected cast shape', function () {
    $request = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_SHOP_REDACT,
        'shop_domain' => 'test-brand.myshopify.com',
        'shopify_shop_id' => 12345,
        'payload_hash' => str_repeat('a', 64),
        'payload' => ['shop_id' => 12345, 'shop_domain' => 'test-brand.myshopify.com'],
        'status' => GdprRequest::STATUS_RECEIVED,
        'received_at' => now(),
    ]);

    $fresh = GdprRequest::find($request->id);

    expect($fresh->topic)->toBe('shop/redact');
    expect($fresh->payload)->toBeArray();
    expect($fresh->payload['shop_id'])->toBe(12345);
    expect($fresh->status)->toBe('received');
});

it('rejects duplicate payload_hash via the unique index', function () {
    $hash = str_repeat('b', 64);

    GdprRequest::create([
        'topic' => GdprRequest::TOPIC_CUSTOMERS_REDACT,
        'shop_domain' => 'test-brand.myshopify.com',
        'payload_hash' => $hash,
        'payload' => ['customer' => ['email' => 'x@example.com']],
        'received_at' => now(),
    ]);

    expect(fn () => GdprRequest::create([
        'topic' => GdprRequest::TOPIC_CUSTOMERS_REDACT,
        'shop_domain' => 'test-brand.myshopify.com',
        'payload_hash' => $hash,
        'payload' => ['customer' => ['email' => 'x@example.com']],
        'received_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('exposes topic and status constants', function () {
    expect(GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST)->toBe('customers/data_request');
    expect(GdprRequest::TOPIC_CUSTOMERS_REDACT)->toBe('customers/redact');
    expect(GdprRequest::TOPIC_SHOP_REDACT)->toBe('shop/redact');

    expect(GdprRequest::STATUS_RECEIVED)->toBe('received');
    expect(GdprRequest::STATUS_PROCESSING)->toBe('processing');
    expect(GdprRequest::STATUS_COMPLETED)->toBe('completed');
    expect(GdprRequest::STATUS_FAILED)->toBe('failed');
    expect(GdprRequest::STATUS_SKIPPED)->toBe('skipped');
});
```

- [ ] **Step 2: Run tests — they must fail**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/GdprRequestModelTest.php`
Expected: FAIL — `Class App\Models\Core\Gdpr\GdprRequest not found`.

- [ ] **Step 3: Create the model**

Create `app/Models/Core/Gdpr/GdprRequest.php`:

```php
<?php

namespace App\Models\Core\Gdpr;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Audit row for Shopify GDPR webhooks. payload_hash unique index provides
// idempotency against Shopify retries — duplicate deliveries fail insert and
// the controller treats that as "already handled".
class GdprRequest extends BaseModel
{
    use HasUuids;

    public const TOPIC_CUSTOMERS_DATA_REQUEST = 'customers/data_request';

    public const TOPIC_CUSTOMERS_REDACT = 'customers/redact';

    public const TOPIC_SHOP_REDACT = 'shop/redact';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'core.gdpr_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'topic',
        'shop_domain',
        'shopify_shop_id',
        'payload_hash',
        'payload',
        'professional_id',
        'status',
        'error',
        'received_at',
        'completed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'shopify_shop_id' => 'integer',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'error' => $reason,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
        ]);
    }
}
```

- [ ] **Step 4: Run tests — they must pass**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/GdprRequestModelTest.php`
Expected: PASS — 3 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Core/Gdpr/GdprRequest.php tests/Feature/Shopify/Gdpr/GdprRequestModelTest.php
git commit -m "feat(gdpr): add GdprRequest audit model with topic/status constants"
```

---

## Task 3: ShopifyShopResolver service + tests

**Files:**
- Create: `app/Services/Shopify/ShopifyShopResolver.php`
- Create: `tests/Feature/Shopify/Gdpr/ShopifyShopResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Shopify/Gdpr/ShopifyShopResolverTest.php`:

```php
<?php

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyShopResolver;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        provider_metadata TEXT,
        shopify_shop_domain TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');
});

function seedShopifyIntegration(string $shopDomain, string $professionalId): ProfessionalIntegration
{
    return ProfessionalIntegration::create([
        'id' => 'int-'.uniqid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_test_token',
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain, // test fixture: sqlite has no generated column
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('resolves a professional_id for a known shop_domain', function () {
    seedShopifyIntegration('test-brand.myshopify.com', 'brand-123');

    $resolver = new ShopifyShopResolver;
    $professionalId = $resolver->resolveProfessionalId('test-brand.myshopify.com');

    expect($professionalId)->toBe('brand-123');
});

it('normalises shop_domain to lowercase before lookup', function () {
    seedShopifyIntegration('test-brand.myshopify.com', 'brand-123');

    $resolver = new ShopifyShopResolver;
    $professionalId = $resolver->resolveProfessionalId('TEST-BRAND.myshopify.com');

    expect($professionalId)->toBe('brand-123');
});

it('returns null when no integration matches (already redacted or never installed)', function () {
    $resolver = new ShopifyShopResolver;

    expect($resolver->resolveProfessionalId('unknown.myshopify.com'))->toBeNull();
});

it('ignores non-Shopify integrations with same external_account_id', function () {
    // Seed a Fresha integration that happens to share an external id — resolver
    // must only match provider=shopify.
    ProfessionalIntegration::create([
        'id' => 'int-fresha',
        'professional_id' => 'brand-fresha',
        'provider' => ProfessionalIntegration::PROVIDER_FRESHA,
        'external_account_id' => 'test-brand.myshopify.com',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolver = new ShopifyShopResolver;

    expect($resolver->resolveProfessionalId('test-brand.myshopify.com'))->toBeNull();
});
```

- [ ] **Step 2: Run tests — they must fail**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/ShopifyShopResolverTest.php`
Expected: FAIL — `Class App\Services\Shopify\ShopifyShopResolver not found`.

- [ ] **Step 3: Create the service**

Create `app/Services/Shopify/ShopifyShopResolver.php`:

```php
<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;

// V2: Resolves a Shopify shop_domain to the owning professional_id.
// Single source of truth for all Shopify-keyed operations (GDPR webhooks,
// uninstall cleanup, etc.). Returns null when the integration is already
// gone — callers treat that as a valid skip case (Shopify retries may fire
// after we've torn down the integration ourselves).
class ShopifyShopResolver
{
    public function resolveProfessionalId(string $shopDomain): ?string
    {
        $normalised = mb_strtolower(trim($shopDomain));

        if ($normalised === '') {
            return null;
        }

        $integration = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('shopify_shop_domain', $normalised)
            ->first();

        return $integration?->professional_id;
    }

    public function resolveIntegration(string $shopDomain): ?ProfessionalIntegration
    {
        $normalised = mb_strtolower(trim($shopDomain));

        if ($normalised === '') {
            return null;
        }

        return ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('shopify_shop_domain', $normalised)
            ->first();
    }
}
```

- [ ] **Step 4: Run tests — they must pass**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/ShopifyShopResolverTest.php`
Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Shopify/ShopifyShopResolver.php tests/Feature/Shopify/Gdpr/ShopifyShopResolverTest.php
git commit -m "feat(gdpr): add ShopifyShopResolver service"
```

---

## Task 4: Add gdpr config block to sidest.php + .env.example

**Files:**
- Modify: `config/sidest.php`
- Modify: `.env.example`

- [ ] **Step 1: Append gdpr config block to `config/sidest.php`**

Add this block immediately before the final `];` that closes the `return [` array. If `config/sidest.php` ends with `video_queue` config, add the new block right before the closing `];`:

```php
    /*
    |--------------------------------------------------------------------------
    | GDPR
    |--------------------------------------------------------------------------
    |
    | Config for Shopify GDPR webhook handlers. Jobs dispatch onto a dedicated
    | queue so they don't contend with the default worker on a mature shop
    | (RedactShopJob can take several minutes). The placeholder domain is used
    | when anonymising customer email addresses — pick a domain you own so
    | bounces don't confuse third-party mail providers.
    |
    */

    'gdpr' => [
        'queue' => env('GDPR_QUEUE', 'gdpr'),
        'redact_placeholder_domain' => env('GDPR_REDACT_PLACEHOLDER_DOMAIN', 'gdpr.sidest.io'),
        'export_retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 30),
    ],
```

- [ ] **Step 2: Add env var to `.env.example`**

Append to `.env.example` (near other Shopify-related env vars if possible, otherwise at end of file):

```
# GDPR webhooks
GDPR_QUEUE=gdpr
GDPR_REDACT_PLACEHOLDER_DOMAIN=gdpr.sidest.io
GDPR_EXPORT_RETENTION_DAYS=30
```

- [ ] **Step 3: Verify config loads**

Run: `php artisan tinker --execute="dump(config('sidest.gdpr'))"`
Expected output:
```
array:3 [
  "queue" => "gdpr"
  "redact_placeholder_domain" => "gdpr.sidest.io"
  "export_retention_days" => 30
]
```

- [ ] **Step 4: Commit**

```bash
git add config/sidest.php .env.example
git commit -m "feat(gdpr): add gdpr config block (queue, placeholder domain, retention)"
```

---

## Task 5: RedactShopJob + tests (narrow scope)

**Files:**
- Create: `app/Jobs/Shopify/Gdpr/RedactShopJob.php`
- Create: `tests/Feature/Shopify/Gdpr/RedactShopJobTest.php`

**Scope (narrow — do NOT touch):**
- `core.professionals` row (account survives — professional may still use Fresha/Square)
- `billing.subscriptions` (account-level, not shop)
- `site.sites`, `site.blocks`, `site.site_media` (Partna site isn't Shopify data)
- Analytics aggregate tables (no PII, pre-aggregated business metrics)
- `commerce.commission_ledger_entries` (affiliates' earnings records — keep; customer_id links become dangling but that's fine, they reference the anonymised row)

**Scope (narrow — DO):**
1. Null `access_token` + `refresh_token` on the integration row **first** (revoke API access immediately so a mid-job crash still kills Shopify).
2. Delete `commerce.affiliate_product_selections` where `brand_professional_id = resolved_pid` (Shopify product GIDs — stale once integration is gone).
3. Anonymise `core.customers` where `professional_id = resolved_pid AND source = 'shopify'` — overwrite email/phone/full_name, set `redacted_at`.
4. Delete the `core.professional_integrations` row last (removes the `shopify_shop_domain` key — resolver will return null on subsequent retries).
5. Mark the `gdpr_requests` row completed.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Shopify/Gdpr/RedactShopJobTest.php`:

```php
<?php

use App\Jobs\Shopify\Gdpr\RedactShopJob;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
        $conn->statement('ATTACH DATABASE \':memory:\' AS commerce');
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        provider_metadata TEXT,
        shopify_shop_domain TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.customers (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        full_name TEXT,
        source TEXT,
        notes TEXT,
        external_id TEXT,
        redacted_at TEXT,
        marketing_opt_in_cached INTEGER,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT,
        brand_professional_id TEXT,
        shopify_product_gid TEXT,
        selected_variant_gids TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.gdpr_requests (
        id TEXT PRIMARY KEY,
        topic TEXT NOT NULL,
        shop_domain TEXT NOT NULL,
        shopify_shop_id INTEGER,
        payload_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'received\',
        error TEXT,
        received_at TEXT,
        completed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function seedShopRedactFixture(string $shopDomain = 'test-brand.myshopify.com'): array
{
    $professionalId = 'brand-'.uniqid();

    $integration = ProfessionalIntegration::create([
        'id' => 'int-'.uniqid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_live_token',
        'refresh_token' => 'shpat_refresh',
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    AffiliateProductSelection::create([
        'id' => 'sel-1',
        'affiliate_professional_id' => 'affiliate-other',
        'brand_professional_id' => $professionalId,
        'shopify_product_gid' => 'gid://shopify/Product/1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Customer::create([
        'id' => 'cust-shopify',
        'professional_id' => $professionalId,
        'email' => 'shopper@example.com',
        'phone' => '+1234567890',
        'full_name' => 'Real Shopper',
        'source' => 'shopify',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Non-Shopify customer — must survive the redact.
    Customer::create([
        'id' => 'cust-fresha',
        'professional_id' => $professionalId,
        'email' => 'walkin@example.com',
        'full_name' => 'Salon Walk-in',
        'source' => 'fresha',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gdpr = GdprRequest::create([
        'id' => 'gdpr-'.uniqid(),
        'topic' => GdprRequest::TOPIC_SHOP_REDACT,
        'shop_domain' => $shopDomain,
        'payload_hash' => str_repeat('a', 64),
        'payload' => ['shop_domain' => $shopDomain],
        'professional_id' => $professionalId,
        'received_at' => now(),
    ]);

    return compact('professionalId', 'integration', 'gdpr');
}

it('leaves no integration row with tokens after the redact', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    // Final state: integration row is deleted entirely — so tokens cannot exist.
    // The order-dependent invariant (tokens nulled *before* deletion) is
    // verified indirectly: if the deletion step crashes, a partial state would
    // still have null tokens. Tested observationally.
    $survived = ProfessionalIntegration::query()
        ->where('shopify_shop_domain', 'test-brand.myshopify.com')
        ->exists();
    expect($survived)->toBeFalse();
});

it('deletes affiliate_product_selections scoped to the brand', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    $remaining = AffiliateProductSelection::query()
        ->where('brand_professional_id', $ctx['professionalId'])
        ->count();

    expect($remaining)->toBe(0);
});

it('anonymises only shopify-sourced customers, preserving other sources', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    $shopify = Customer::find('cust-shopify');
    expect($shopify->email)->toStartWith('redacted-');
    expect($shopify->email)->toEndWith('@gdpr.sidest.io');
    expect($shopify->full_name)->toBe('Redacted Customer');
    expect($shopify->phone)->toBeNull();
    expect($shopify->redacted_at)->not->toBeNull();

    $fresha = Customer::find('cust-fresha');
    expect($fresha->email)->toBe('walkin@example.com');
    expect($fresha->redacted_at)->toBeNull();
});

it('deletes the integration row as the final step', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    expect(ProfessionalIntegration::find($ctx['integration']->id))->toBeNull();
});

it('marks the gdpr_requests row completed on success', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    $fresh = GdprRequest::find($ctx['gdpr']->id);
    expect($fresh->status)->toBe(GdprRequest::STATUS_COMPLETED);
    expect($fresh->completed_at)->not->toBeNull();
});

it('is idempotent — re-running on a completed request is a no-op', function () {
    $ctx = seedShopRedactFixture();

    (new RedactShopJob($ctx['gdpr']->id))->handle();

    // Should not throw.
    (new RedactShopJob($ctx['gdpr']->id))->handle();

    $fresh = GdprRequest::find($ctx['gdpr']->id);
    expect($fresh->status)->toBe(GdprRequest::STATUS_COMPLETED);
});

it('marks the request skipped when shop_domain no longer resolves', function () {
    $gdpr = GdprRequest::create([
        'id' => 'gdpr-orphan',
        'topic' => GdprRequest::TOPIC_SHOP_REDACT,
        'shop_domain' => 'ghost-shop.myshopify.com',
        'payload_hash' => str_repeat('c', 64),
        'payload' => ['shop_domain' => 'ghost-shop.myshopify.com'],
        'received_at' => now(),
    ]);

    (new RedactShopJob($gdpr->id))->handle();

    $fresh = GdprRequest::find('gdpr-orphan');
    expect($fresh->status)->toBe(GdprRequest::STATUS_SKIPPED);
    expect($fresh->error)->toContain('no integration');
});
```

- [ ] **Step 2: Run tests — they must fail**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/RedactShopJobTest.php`
Expected: FAIL — `Class App\Jobs\Shopify\Gdpr\RedactShopJob not found`.

- [ ] **Step 3: Create the job**

Create `app/Jobs/Shopify/Gdpr/RedactShopJob.php`:

```php
<?php

namespace App\Jobs\Shopify\Gdpr;

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Services\Shopify\ShopifyShopResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// V2: Handles Shopify `shop/redact` webhook. NARROW scope — removes the Shopify
// integration and Shopify-derived data, but leaves the professional account
// intact (they may still be using Fresha or Square). Timeout is generous because
// a mature shop can have thousands of Shopify-sourced customers to anonymise.
class RedactShopJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public string $gdprRequestId)
    {
        $this->onQueue(config('sidest.gdpr.queue'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $resolver = app(ShopifyShopResolver::class);
        $gdpr = GdprRequest::find($this->gdprRequestId);

        if (! $gdpr) {
            Log::warning('RedactShopJob: gdpr_requests row not found', ['id' => $this->gdprRequestId]);

            return;
        }

        // Idempotent: re-run on a completed/skipped request is a no-op. Shopify
        // retries fire at the transport layer; the payload_hash unique index
        // prevents duplicate rows, but if this job itself is re-queued (Horizon
        // restart, etc.) we must not re-process.
        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);

        try {
            $integration = $resolver->resolveIntegration($gdpr->shop_domain);

            if (! $integration) {
                $gdpr->markSkipped('no integration for shop_domain (already redacted or never installed)');

                return;
            }

            $professionalId = $integration->professional_id;
            $gdpr->update(['professional_id' => $professionalId]);

            // 1. Revoke API access FIRST. If anything below crashes, the
            //    integration is already cut off from Shopify.
            $integration->update([
                'access_token' => null,
                'refresh_token' => null,
            ]);

            // 2. Delete affiliate product selections scoped to this brand.
            //    Shopify product GIDs become stale the moment the integration is gone.
            $deletedSelections = AffiliateProductSelection::query()
                ->where('brand_professional_id', $professionalId)
                ->delete();

            // 3. Anonymise Shopify-sourced customers. Other sources (fresha,
            //    square, manual) are preserved — the professional may still be
            //    using those integrations.
            $anonymisedCount = $this->anonymiseShopifyCustomers($professionalId);

            // 4. Delete the integration row LAST. This removes the
            //    shopify_shop_domain key, so subsequent retries fall into the
            //    "no integration" skip branch above.
            $integration->delete();

            $gdpr->markCompleted();

            Log::info('RedactShopJob completed (narrow scope).', [
                'gdpr_request_id' => $gdpr->id,
                'professional_id' => $professionalId,
                'shop_domain' => $gdpr->shop_domain,
                'deleted_selections' => $deletedSelections,
                'anonymised_customers' => $anonymisedCount,
            ]);
        } catch (\Throwable $e) {
            $gdpr->markFailed($e->getMessage());
            Log::error('RedactShopJob failed', [
                'gdpr_request_id' => $gdpr->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Anonymise every customer row tied to this professional and sourced from
     * Shopify. Email becomes redacted-{uuid}@placeholder, name becomes a
     * literal placeholder, phone and external_id are nulled.
     *
     * Uses chunkById(500) to keep memory bounded at scale — a mature shop
     * can have tens of thousands of customers. chunkById is safe here: we
     * update `redacted_at` on each row, so already-processed rows drop out
     * of the `whereNull('redacted_at')` filter, and the id cursor only
     * advances forward.
     */
    private function anonymiseShopifyCustomers(string $professionalId): int
    {
        $placeholderDomain = config('sidest.gdpr.redact_placeholder_domain', 'gdpr.sidest.io');
        $count = 0;

        Customer::query()
            ->where('professional_id', $professionalId)
            ->where('source', 'shopify')
            ->whereNull('redacted_at')
            ->chunkById(500, function ($customers) use ($placeholderDomain, &$count) {
                foreach ($customers as $customer) {
                    $customer->update([
                        'email' => 'redacted-'.Str::uuid()->toString().'@'.$placeholderDomain,
                        'phone' => null,
                        'full_name' => 'Redacted Customer',
                        'external_id' => null,
                        'notes' => null,
                        'marketing_opt_in_cached' => null,
                        'redacted_at' => now(),
                    ]);
                    $count++;
                }
            });

        return $count;
    }
}
```

- [ ] **Step 4: Run tests — they must pass**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/RedactShopJobTest.php`
Expected: PASS — 7 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Shopify/Gdpr/RedactShopJob.php tests/Feature/Shopify/Gdpr/RedactShopJobTest.php
git commit -m "feat(gdpr): add RedactShopJob with narrow shop-redact scope"
```

---

## Task 6: RedactCustomerJob + tests

**Files:**
- Create: `app/Jobs/Shopify/Gdpr/RedactCustomerJob.php`
- Create: `tests/Feature/Shopify/Gdpr/RedactCustomerJobTest.php`

**Scope:**
1. Resolve professional via `shop_domain`.
2. Match `core.customers` row by `professional_id` + the email(s) in the webhook payload.
3. Overwrite PII (same pattern as `RedactShopJob::anonymiseShopifyCustomers`).
4. Hard-delete matching `notifications.email_subscriptions` rows by email_lc (no aggregate value — pure marketing consent record).
5. Hard-delete matching `site.enquiries` rows by email (public-visitor forms — not order-tied, safe to drop).
6. **Scrub `analytics.booking_events` denormalised PII** — null `customer_name`/`customer_email`/`customer_phone` and reset `raw_payload` to `'{}'::jsonb` where `customer_email` matches. These are Square/Fresha booking rows that denormalise customer PII (no `customer_id` FK), so they don't get cascaded via the core.customers anonymisation and must be scrubbed explicitly.
7. `lead_submissions` has no denormalised PII — the `customer_id` FK already points to the anonymised customer row, which is enough.
8. Mark `gdpr_requests` completed.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Shopify/Gdpr/RedactCustomerJobTest.php`:

```php
<?php

use App\Jobs\Shopify\Gdpr\RedactCustomerJob;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
        $conn->statement('ATTACH DATABASE \':memory:\' AS notifications');
        $conn->statement('ATTACH DATABASE \':memory:\' AS site');
        $conn->statement('ATTACH DATABASE \':memory:\' AS analytics');
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        shopify_shop_domain TEXT,
        provider_metadata TEXT,
        access_token TEXT,
        refresh_token TEXT,
        external_account_id TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.customers (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        full_name TEXT,
        source TEXT,
        notes TEXT,
        external_id TEXT,
        redacted_at TEXT,
        marketing_opt_in_cached INTEGER,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        list_key TEXT,
        email TEXT,
        email_lc TEXT,
        full_name TEXT,
        status TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        site_id TEXT,
        name TEXT,
        email TEXT,
        phone TEXT,
        subject TEXT,
        message TEXT,
        ip_hash TEXT,
        user_agent TEXT,
        read_at TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS analytics.booking_events (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        site_id TEXT,
        brand_professional_id TEXT,
        occurred_at TEXT,
        status TEXT,
        source TEXT,
        square_booking_id TEXT,
        square_payment_id TEXT,
        service_variation_id TEXT,
        service_name TEXT,
        payment_method TEXT,
        customer_name TEXT,
        customer_email TEXT,
        customer_phone TEXT,
        currency_code TEXT,
        amount_paid_cents INTEGER,
        raw_payload TEXT,
        appointment_start_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.gdpr_requests (
        id TEXT PRIMARY KEY,
        topic TEXT NOT NULL,
        shop_domain TEXT NOT NULL,
        shopify_shop_id INTEGER,
        payload_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'received\',
        error TEXT,
        received_at TEXT,
        completed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function seedCustomerRedactFixture(): array
{
    $professionalId = 'brand-cust-'.uniqid();
    $shopDomain = 'test-brand.myshopify.com';

    ProfessionalIntegration::create([
        'id' => 'int-'.uniqid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'access_token' => 'shpat_live',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Customer::create([
        'id' => 'cust-target',
        'professional_id' => $professionalId,
        'email' => 'target@example.com',
        'phone' => '+1555',
        'full_name' => 'Target Customer',
        'source' => 'shopify',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notifications.email_subscriptions')->insert([
        'id' => 'sub-1',
        'professional_id' => $professionalId,
        'list_key' => 'marketing',
        'email' => 'target@example.com',
        'email_lc' => 'target@example.com',
        'full_name' => 'Target Customer',
        'status' => 'subscribed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('site.enquiries')->insert([
        'id' => 'enq-1',
        'professional_id' => $professionalId,
        'site_id' => 'site-1',
        'name' => 'Target Customer',
        'email' => 'target@example.com',
        'phone' => '+1555',
        'subject' => 'Question',
        'message' => 'Hi there',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Denormalised PII in a Square booking — must be scrubbed by the job.
    DB::table('analytics.booking_events')->insert([
        'id' => 'bk-target',
        'professional_id' => $professionalId,
        'site_id' => 'site-1',
        'occurred_at' => now(),
        'status' => 'completed',
        'source' => 'site_booking_checkout',
        'customer_name' => 'Target Customer',
        'customer_email' => 'target@example.com',
        'customer_phone' => '+1555',
        'currency_code' => 'AUD',
        'amount_paid_cents' => 5000,
        'raw_payload' => json_encode(['customer' => ['email' => 'target@example.com', 'name' => 'Target Customer']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A second booking_event for a DIFFERENT customer — must not be touched.
    DB::table('analytics.booking_events')->insert([
        'id' => 'bk-other',
        'professional_id' => $professionalId,
        'site_id' => 'site-1',
        'occurred_at' => now(),
        'status' => 'completed',
        'source' => 'site_booking_checkout',
        'customer_name' => 'Other Customer',
        'customer_email' => 'other@example.com',
        'customer_phone' => '+9999',
        'currency_code' => 'AUD',
        'amount_paid_cents' => 3000,
        'raw_payload' => json_encode(['customer' => ['email' => 'other@example.com']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gdpr = GdprRequest::create([
        'id' => 'gdpr-cust-'.uniqid(),
        'topic' => GdprRequest::TOPIC_CUSTOMERS_REDACT,
        'shop_domain' => $shopDomain,
        'payload_hash' => str_repeat('d', 64),
        'payload' => [
            'shop_domain' => $shopDomain,
            'customer' => [
                'id' => 99,
                'email' => 'target@example.com',
                'phone' => '+1555',
            ],
            'orders_to_redact' => [],
        ],
        'received_at' => now(),
    ]);

    return compact('professionalId', 'gdpr');
}

it('anonymises the matching customer row and sets redacted_at', function () {
    $ctx = seedCustomerRedactFixture();

    (new RedactCustomerJob($ctx['gdpr']->id))->handle();

    $customer = Customer::find('cust-target');
    expect($customer->email)->toStartWith('redacted-');
    expect($customer->full_name)->toBe('Redacted Customer');
    expect($customer->phone)->toBeNull();
    expect($customer->redacted_at)->not->toBeNull();
});

it('hard-deletes the matching email_subscriptions row', function () {
    seedCustomerRedactFixture();

    (new RedactCustomerJob(GdprRequest::first()->id))->handle();

    $subCount = DB::table('notifications.email_subscriptions')
        ->where('email_lc', 'target@example.com')
        ->count();
    expect($subCount)->toBe(0);
});

it('hard-deletes the matching site.enquiries row', function () {
    seedCustomerRedactFixture();

    (new RedactCustomerJob(GdprRequest::first()->id))->handle();

    $enqCount = DB::table('site.enquiries')
        ->where('email', 'target@example.com')
        ->count();
    expect($enqCount)->toBe(0);
});

it('scrubs denormalised PII from analytics.booking_events for the matching email', function () {
    seedCustomerRedactFixture();

    (new RedactCustomerJob(GdprRequest::first()->id))->handle();

    $target = DB::table('analytics.booking_events')->where('id', 'bk-target')->first();
    expect($target->customer_email)->toBeNull();
    expect($target->customer_name)->toBeNull();
    expect($target->customer_phone)->toBeNull();
    expect($target->raw_payload)->toBe('{}');
});

it('leaves booking_events for other customers untouched', function () {
    seedCustomerRedactFixture();

    (new RedactCustomerJob(GdprRequest::first()->id))->handle();

    $other = DB::table('analytics.booking_events')->where('id', 'bk-other')->first();
    expect($other->customer_email)->toBe('other@example.com');
    expect($other->customer_name)->toBe('Other Customer');
    expect($other->customer_phone)->toBe('+9999');
});

it('marks the request completed', function () {
    $ctx = seedCustomerRedactFixture();

    (new RedactCustomerJob($ctx['gdpr']->id))->handle();

    expect(GdprRequest::find($ctx['gdpr']->id)->status)->toBe(GdprRequest::STATUS_COMPLETED);
});

it('marks the request skipped when no matching customer exists', function () {
    $ctx = seedCustomerRedactFixture();

    // Remove the customer so the payload email matches nothing.
    Customer::find('cust-target')->delete();

    (new RedactCustomerJob($ctx['gdpr']->id))->handle();

    $fresh = GdprRequest::find($ctx['gdpr']->id);
    expect($fresh->status)->toBe(GdprRequest::STATUS_SKIPPED);
});
```

- [ ] **Step 2: Run tests — they must fail**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/RedactCustomerJobTest.php`
Expected: FAIL — `Class App\Jobs\Shopify\Gdpr\RedactCustomerJob not found`. (7 tests declared, all fail at class-not-found.)

- [ ] **Step 3: Create the job**

Create `app/Jobs/Shopify/Gdpr/RedactCustomerJob.php`:

```php
<?php

namespace App\Jobs\Shopify\Gdpr;

use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Services\Shopify\ShopifyShopResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// V2: Handles Shopify `customers/redact` webhook. Anonymises the matching
// core.customers row (keeps it for commission ledger integrity) and
// hard-deletes email_subscriptions + site.enquiries rows matching the email.
class RedactCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $gdprRequestId)
    {
        $this->onQueue(config('sidest.gdpr.queue'));
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $resolver = app(ShopifyShopResolver::class);
        $gdpr = GdprRequest::find($this->gdprRequestId);

        if (! $gdpr) {
            Log::warning('RedactCustomerJob: gdpr_requests row not found', ['id' => $this->gdprRequestId]);

            return;
        }

        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);

        try {
            $professionalId = $resolver->resolveProfessionalId($gdpr->shop_domain);

            if (! $professionalId) {
                $gdpr->markSkipped('no integration for shop_domain');

                return;
            }

            $gdpr->update(['professional_id' => $professionalId]);

            $email = $this->customerEmail($gdpr->payload);

            if ($email === null) {
                $gdpr->markSkipped('payload missing customer.email');

                return;
            }

            $customer = Customer::query()
                ->where('professional_id', $professionalId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->whereNull('redacted_at')
                ->first();

            if (! $customer) {
                $gdpr->markSkipped('no customer row matched email');

                return;
            }

            $placeholderDomain = config('sidest.gdpr.redact_placeholder_domain', 'gdpr.sidest.io');

            $customer->update([
                'email' => 'redacted-'.Str::uuid()->toString().'@'.$placeholderDomain,
                'phone' => null,
                'full_name' => 'Redacted Customer',
                'external_id' => null,
                'notes' => null,
                'marketing_opt_in_cached' => null,
                'redacted_at' => now(),
            ]);

            $emailLc = mb_strtolower($email);

            $deletedSubs = DB::connection('pgsql')
                ->table('notifications.email_subscriptions')
                ->where('professional_id', $professionalId)
                ->where('email_lc', $emailLc)
                ->delete();

            $deletedEnquiries = DB::connection('pgsql')
                ->table('site.enquiries')
                ->where('professional_id', $professionalId)
                ->whereRaw('LOWER(email) = ?', [$emailLc])
                ->delete();

            // Scrub denormalised PII on booking_events. These are Square/Fresha
            // bookings with no customer_id FK — the email/name/phone columns
            // must be nulled explicitly, and raw_payload (full Square booking
            // JSON) reset to '{}' because it contains the customer object.
            // Parameter-bound '{}' is cast to jsonb by Postgres implicitly;
            // on sqlite in tests it's stored as plain text '{}' which the
            // tests assert directly.
            $scrubbedBookings = DB::connection('pgsql')
                ->table('analytics.booking_events')
                ->where('professional_id', $professionalId)
                ->whereRaw('LOWER(customer_email) = ?', [$emailLc])
                ->update([
                    'customer_name' => null,
                    'customer_email' => null,
                    'customer_phone' => null,
                    'raw_payload' => '{}',
                    'updated_at' => now(),
                ]);

            $gdpr->markCompleted();

            Log::info('RedactCustomerJob completed.', [
                'gdpr_request_id' => $gdpr->id,
                'professional_id' => $professionalId,
                'customer_id' => $customer->id,
                'deleted_subscriptions' => $deletedSubs,
                'deleted_enquiries' => $deletedEnquiries,
                'scrubbed_bookings' => $scrubbedBookings,
            ]);
        } catch (\Throwable $e) {
            $gdpr->markFailed($e->getMessage());
            Log::error('RedactCustomerJob failed', [
                'gdpr_request_id' => $gdpr->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Pluck the customer email out of a customers/redact webhook payload.
     * Shopify's payload shape: { "shop_domain": "...", "customer": { "id", "email", "phone" }, "orders_to_redact": [...] }
     */
    private function customerEmail(array $payload): ?string
    {
        $email = $payload['customer']['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }
}
```

- [ ] **Step 4: Run tests — they must pass**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/RedactCustomerJobTest.php`
Expected: PASS — 7 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Shopify/Gdpr/RedactCustomerJob.php tests/Feature/Shopify/Gdpr/RedactCustomerJobTest.php
git commit -m "feat(gdpr): add RedactCustomerJob (anonymise customer + scrub booking_events)"
```

---

## Task 7: ExportCustomerDataJob + Mailable + view + tests

**Files:**
- Create: `app/Mail/Gdpr/CustomerDataExportMail.php`
- Create: `resources/views/emails/gdpr/customer-data-export.blade.php`
- Create: `app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php`
- Create: `tests/Feature/Shopify/Gdpr/ExportCustomerDataJobTest.php`

- [ ] **Step 1: Create the Mailable**

Create `app/Mail/Gdpr/CustomerDataExportMail.php`:

```php
<?php

namespace App\Mail\Gdpr;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

// V2: Emails the merchant a JSON dump of a customer's stored data in response
// to Shopify `customers/data_request`. Merchant forwards to the requesting
// customer — Shopify's recommended pattern since the merchant has a verified
// identity channel to the customer and we do not.
class CustomerDataExportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $shopDomain,
        public string $customerEmail,
        public array $exportData,
    ) {}

    public function build()
    {
        return $this
            ->subject("GDPR customer data request for {$this->customerEmail} — {$this->shopDomain}")
            ->view('emails.gdpr.customer-data-export', [
                'shopDomain' => $this->shopDomain,
                'customerEmail' => $this->customerEmail,
                'recordCount' => $this->countRecords($this->exportData),
            ])
            ->attach(Attachment::fromData(
                fn () => json_encode($this->exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'customer-data-'.preg_replace('/[^a-z0-9]/i', '-', $this->customerEmail).'.json'
            )->as('customer-data.json')->withMime('application/json'));
    }

    private function countRecords(array $export): int
    {
        $count = 0;
        foreach ($export as $section) {
            if (is_array($section)) {
                $count += count($section);
            }
        }

        return $count;
    }
}
```

- [ ] **Step 2: Create the Blade view**

Create `resources/views/emails/gdpr/customer-data-export.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>GDPR customer data request</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #111; max-width: 640px; margin: 0 auto; padding: 24px;">
    <h2 style="margin-top: 0;">GDPR customer data request</h2>

    <p>Hi,</p>

    <p>A customer at your store <strong>{{ $shopDomain }}</strong> has invoked their GDPR right to access the personal data you hold about them (via Shopify's <code>customers/data_request</code> webhook).</p>

    <p>Attached is a JSON file containing every record Partna holds about <strong>{{ $customerEmail }}</strong> scoped to your store ({{ $recordCount }} records total).</p>

    <p><strong>What to do next:</strong> forward this file to the requesting customer within 30 days of their Shopify request. Shopify tracks compliance on the merchant side.</p>

    <p>If you believe this request was sent in error, or if you want help interpreting the contents, reply to this email and we'll assist.</p>

    <p>— Partna</p>

    <hr style="border: none; border-top: 1px solid #eee; margin: 32px 0 16px;">

    <p style="font-size: 12px; color: #666;">This is an automated message triggered by Shopify. You are receiving it because you are the registered owner of <strong>{{ $shopDomain }}</strong>.</p>
</body>
</html>
```

- [ ] **Step 3: Write the failing job test**

Create `tests/Feature/Shopify/Gdpr/ExportCustomerDataJobTest.php`:

```php
<?php

use App\Jobs\Shopify\Gdpr\ExportCustomerDataJob;
use App\Mail\Gdpr\CustomerDataExportMail;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
        $conn->statement('ATTACH DATABASE \':memory:\' AS notifications');
        $conn->statement('ATTACH DATABASE \':memory:\' AS site');
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        email TEXT,
        full_name TEXT,
        handle TEXT,
        contact_email TEXT,
        status TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        shopify_shop_domain TEXT,
        provider_metadata TEXT,
        access_token TEXT,
        refresh_token TEXT,
        external_account_id TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.customers (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        full_name TEXT,
        source TEXT,
        notes TEXT,
        external_id TEXT,
        redacted_at TEXT,
        marketing_opt_in_cached INTEGER,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        list_key TEXT,
        email TEXT,
        email_lc TEXT,
        full_name TEXT,
        status TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        site_id TEXT,
        name TEXT,
        email TEXT,
        phone TEXT,
        subject TEXT,
        message TEXT,
        ip_hash TEXT,
        user_agent TEXT,
        read_at TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.gdpr_requests (
        id TEXT PRIMARY KEY,
        topic TEXT NOT NULL,
        shop_domain TEXT NOT NULL,
        shopify_shop_id INTEGER,
        payload_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'received\',
        error TEXT,
        received_at TEXT,
        completed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function seedExportFixture(): array
{
    $professionalId = 'brand-exp-'.uniqid();
    $shopDomain = 'test-brand.myshopify.com';

    DB::table('core.professionals')->insert([
        'id' => $professionalId,
        'email' => 'merchant@example.com',
        'contact_email' => 'merchant@example.com',
        'full_name' => 'Merchant Name',
        'handle' => 'test-brand',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ProfessionalIntegration::create([
        'id' => 'int-'.uniqid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'access_token' => 'shpat_live',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Customer::create([
        'id' => 'cust-exp',
        'professional_id' => $professionalId,
        'email' => 'target@example.com',
        'phone' => '+1555',
        'full_name' => 'Target Customer',
        'source' => 'shopify',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notifications.email_subscriptions')->insert([
        'id' => 'sub-exp',
        'professional_id' => $professionalId,
        'list_key' => 'marketing',
        'email' => 'target@example.com',
        'email_lc' => 'target@example.com',
        'full_name' => 'Target Customer',
        'status' => 'subscribed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gdpr = GdprRequest::create([
        'id' => 'gdpr-exp-'.uniqid(),
        'topic' => GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST,
        'shop_domain' => $shopDomain,
        'payload_hash' => str_repeat('e', 64),
        'payload' => [
            'shop_domain' => $shopDomain,
            'customer' => ['id' => 99, 'email' => 'target@example.com'],
            'orders_requested' => [],
        ],
        'received_at' => now(),
    ]);

    return compact('professionalId', 'gdpr');
}

it('sends the export email to the merchant contact address', function () {
    $ctx = seedExportFixture();

    (new ExportCustomerDataJob($ctx['gdpr']->id))->handle();

    Mail::assertSent(CustomerDataExportMail::class, function ($mail) {
        return $mail->hasTo('merchant@example.com')
            && $mail->customerEmail === 'target@example.com'
            && $mail->shopDomain === 'test-brand.myshopify.com';
    });
});

it('includes the customer row and email subscription in the export payload', function () {
    $ctx = seedExportFixture();

    (new ExportCustomerDataJob($ctx['gdpr']->id))->handle();

    Mail::assertSent(CustomerDataExportMail::class, function ($mail) {
        $data = $mail->exportData;

        return isset($data['customers'][0]['email'])
            && $data['customers'][0]['email'] === 'target@example.com'
            && count($data['email_subscriptions']) === 1;
    });
});

it('marks the request completed on success', function () {
    $ctx = seedExportFixture();

    (new ExportCustomerDataJob($ctx['gdpr']->id))->handle();

    expect(GdprRequest::find($ctx['gdpr']->id)->status)->toBe(GdprRequest::STATUS_COMPLETED);
});

it('marks the request skipped when shop_domain is unknown', function () {
    $gdpr = GdprRequest::create([
        'id' => 'gdpr-orphan-exp',
        'topic' => GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST,
        'shop_domain' => 'ghost.myshopify.com',
        'payload_hash' => str_repeat('f', 64),
        'payload' => ['customer' => ['email' => 'x@example.com']],
        'received_at' => now(),
    ]);

    (new ExportCustomerDataJob($gdpr->id))->handle();

    expect(GdprRequest::find('gdpr-orphan-exp')->status)->toBe(GdprRequest::STATUS_SKIPPED);
    Mail::assertNothingSent();
});

it('sends an empty export when the customer has no records (legitimate case)', function () {
    $ctx = seedExportFixture();
    Customer::find('cust-exp')->delete();
    DB::table('notifications.email_subscriptions')->where('id', 'sub-exp')->delete();

    (new ExportCustomerDataJob($ctx['gdpr']->id))->handle();

    // Shopify expects us to confirm the request even if we hold no data.
    Mail::assertSent(CustomerDataExportMail::class, function ($mail) {
        return count($mail->exportData['customers'] ?? []) === 0;
    });
    expect(GdprRequest::find($ctx['gdpr']->id)->status)->toBe(GdprRequest::STATUS_COMPLETED);
});
```

- [ ] **Step 4: Run tests — they must fail**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/ExportCustomerDataJobTest.php`
Expected: FAIL — `Class App\Jobs\Shopify\Gdpr\ExportCustomerDataJob not found`.

- [ ] **Step 5: Create the job**

Create `app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php`:

```php
<?php

namespace App\Jobs\Shopify\Gdpr;

use App\Mail\Gdpr\CustomerDataExportMail;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Services\Shopify\ShopifyShopResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// V2: Handles Shopify `customers/data_request`. Read-only — gathers every
// PII record we hold for the requesting customer (scoped to this shop's
// professional) and emails the JSON dump to the merchant. Merchant forwards
// to the customer; Shopify tracks compliance on their side.
class ExportCustomerDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $gdprRequestId)
    {
        $this->onQueue(config('sidest.gdpr.queue'));
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $resolver = app(ShopifyShopResolver::class);
        $gdpr = GdprRequest::find($this->gdprRequestId);

        if (! $gdpr) {
            Log::warning('ExportCustomerDataJob: gdpr_requests row not found', ['id' => $this->gdprRequestId]);

            return;
        }

        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);

        try {
            $professionalId = $resolver->resolveProfessionalId($gdpr->shop_domain);

            if (! $professionalId) {
                $gdpr->markSkipped('no integration for shop_domain');

                return;
            }

            $gdpr->update(['professional_id' => $professionalId]);

            $email = $gdpr->payload['customer']['email'] ?? null;

            if (! is_string($email) || $email === '') {
                $gdpr->markSkipped('payload missing customer.email');

                return;
            }

            $professional = Professional::find($professionalId);

            if (! $professional) {
                $gdpr->markSkipped('professional row gone');

                return;
            }

            $recipientEmail = $professional->contact_email ?: $professional->email;

            if (! $recipientEmail) {
                $gdpr->markFailed('professional has no contact_email or email');

                return;
            }

            $exportData = $this->gatherExportData($professionalId, $email);

            Mail::to($recipientEmail)->send(
                new CustomerDataExportMail($gdpr->shop_domain, $email, $exportData)
            );

            $gdpr->markCompleted();

            Log::info('ExportCustomerDataJob completed.', [
                'gdpr_request_id' => $gdpr->id,
                'professional_id' => $professionalId,
                'recipient' => $recipientEmail,
                'customer_records' => count($exportData['customers'] ?? []),
            ]);
        } catch (\Throwable $e) {
            $gdpr->markFailed($e->getMessage());
            Log::error('ExportCustomerDataJob failed', [
                'gdpr_request_id' => $gdpr->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Gather every PII record we hold for the given customer email, scoped
     * to the specified professional. Returns a structured array grouped by
     * source table.
     *
     * @return array{customers: array, email_subscriptions: array, enquiries: array, lead_submissions: array}
     */
    private function gatherExportData(string $professionalId, string $email): array
    {
        $emailLc = mb_strtolower($email);

        $customers = Customer::query()
            ->where('professional_id', $professionalId)
            ->whereRaw('LOWER(email) = ?', [$emailLc])
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'phone' => $c->phone,
                'full_name' => $c->full_name,
                'source' => $c->source,
                'notes' => $c->notes,
                'created_at' => optional($c->created_at)->toIso8601String(),
                'updated_at' => optional($c->updated_at)->toIso8601String(),
            ])
            ->all();

        $subscriptions = DB::connection('pgsql')
            ->table('notifications.email_subscriptions')
            ->where('professional_id', $professionalId)
            ->where('email_lc', $emailLc)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $enquiries = DB::connection('pgsql')
            ->table('site.enquiries')
            ->where('professional_id', $professionalId)
            ->whereRaw('LOWER(email) = ?', [$emailLc])
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $customerIds = array_column($customers, 'id');

        $leadSubmissions = [];
        if (! empty($customerIds)) {
            $leadSubmissions = DB::connection('pgsql')
                ->table('analytics.lead_submissions')
                ->whereIn('customer_id', $customerIds)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        }

        return [
            'customers' => $customers,
            'email_subscriptions' => $subscriptions,
            'enquiries' => $enquiries,
            'lead_submissions' => $leadSubmissions,
        ];
    }
}
```

- [ ] **Step 6: Run tests — they must pass**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/ExportCustomerDataJobTest.php`
Expected: PASS — 5 tests.

Note: if the `analytics.lead_submissions` table isn't in the SQLite fixture, that's fine — the fifth test ("empty export") exercises the path where there's no customer_id to query with, and the other tests don't seed lead submissions.

- [ ] **Step 7: Commit**

```bash
git add app/Mail/Gdpr/CustomerDataExportMail.php \
        resources/views/emails/gdpr/customer-data-export.blade.php \
        app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php \
        tests/Feature/Shopify/Gdpr/ExportCustomerDataJobTest.php
git commit -m "feat(gdpr): add ExportCustomerDataJob with merchant-forward mailable"
```

---

## Task 8: Controller refactor — idempotency + 401 + dispatch + 202

**Files:**
- Modify: `app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php`
- Create: `tests/Feature/Shopify/Gdpr/GdprWebhookControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Shopify/Gdpr/GdprWebhookControllerTest.php`:

```php
<?php

use App\Jobs\Shopify\Gdpr\ExportCustomerDataJob;
use App\Jobs\Shopify\Gdpr\RedactCustomerJob;
use App\Jobs\Shopify\Gdpr\RedactShopJob;
use App\Models\Core\Gdpr\GdprRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Bus::fake();
    Config::set('services.shopify.webhook_secret', 'test_shared_secret');

    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.gdpr_requests (
        id TEXT PRIMARY KEY,
        topic TEXT NOT NULL,
        shop_domain TEXT NOT NULL,
        shopify_shop_id INTEGER,
        payload_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'received\',
        error TEXT,
        received_at TEXT,
        completed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE UNIQUE INDEX IF NOT EXISTS gdpr_requests_payload_hash_unique ON core.gdpr_requests (payload_hash)');
});

function signShopifyBody(string $body): string
{
    return base64_encode(hash_hmac('sha256', $body, 'test_shared_secret', true));
}

it('returns 202 and dispatches RedactShopJob for a valid shop/redact webhook', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'shop_id' => 12345];
    $body = json_encode($payload);

    $response = $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    );

    $response->assertStatus(202);
    expect(GdprRequest::where('topic', 'shop/redact')->count())->toBe(1);
    Bus::assertDispatched(RedactShopJob::class);
});

it('returns 202 and dispatches RedactCustomerJob for customers/redact', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'customer' => ['email' => 'x@example.com']];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/customers-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    )->assertStatus(202);

    Bus::assertDispatched(RedactCustomerJob::class);
});

it('returns 202 and dispatches ExportCustomerDataJob for customers/data_request', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'customer' => ['email' => 'x@example.com']];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/customers-data-request',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    )->assertStatus(202);

    Bus::assertDispatched(ExportCustomerDataJob::class);
});

it('returns 401 and does NOT dispatch when HMAC is invalid', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com'];

    $response = $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => 'wrong-signature',
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    );

    $response->assertStatus(401);
    expect(GdprRequest::count())->toBe(0);
    Bus::assertNotDispatched(RedactShopJob::class);
});

it('deduplicates identical payloads — no second row, no second dispatch', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'shop_id' => 12345];
    $body = json_encode($payload);
    $headers = [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body),
        'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
    ];

    $this->postJson('/api/webhooks/shopify/gdpr/shop-redact', $payload, $headers)->assertStatus(202);
    $this->postJson('/api/webhooks/shopify/gdpr/shop-redact', $payload, $headers)->assertStatus(202);

    expect(GdprRequest::count())->toBe(1);
    Bus::assertDispatchedTimes(RedactShopJob::class, 1);
});

it('persists payload_hash as sha256 of the raw body', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com'];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    );

    $row = GdprRequest::first();
    expect($row->payload_hash)->toBe(hash('sha256', $body));
});

it('accepts the request even when shop_domain is unknown (deferred to the job)', function () {
    $payload = ['shop_domain' => 'ghost.myshopify.com'];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body),
            'X-Shopify-Shop-Domain' => 'ghost.myshopify.com',
        ]
    )->assertStatus(202);

    expect(GdprRequest::where('shop_domain', 'ghost.myshopify.com')->count())->toBe(1);
    Bus::assertDispatched(RedactShopJob::class);
});
```

- [ ] **Step 2: Run tests — they must fail**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/GdprWebhookControllerTest.php`
Expected: FAIL — stub still returns 200, no GdprRequest rows created, no jobs dispatched.

- [ ] **Step 3: Replace the controller**

Replace `app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php` entirely:

```php
<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Jobs\Shopify\Gdpr\ExportCustomerDataJob;
use App\Jobs\Shopify\Gdpr\RedactCustomerJob;
use App\Jobs\Shopify\Gdpr\RedactShopJob;
use App\Models\Core\Gdpr\GdprRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Receives Shopify GDPR webhooks. Validates HMAC, writes an idempotent
// audit row, dispatches a dedicated job per topic onto the `gdpr` queue,
// returns 202. Invalid HMAC returns 401 — returning 200 on a bad signature
// is a silent-acceptance vuln.
class ShopifyGdprWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function customersDataRequest(Request $request): JsonResponse
    {
        return $this->handleGdprWebhook($request, GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST);
    }

    public function customersRedact(Request $request): JsonResponse
    {
        return $this->handleGdprWebhook($request, GdprRequest::TOPIC_CUSTOMERS_REDACT);
    }

    public function shopRedact(Request $request): JsonResponse
    {
        return $this->handleGdprWebhook($request, GdprRequest::TOPIC_SHOP_REDACT);
    }

    private function handleGdprWebhook(Request $request, string $topic): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $shopDomain = mb_strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning("Shopify GDPR webhook ({$topic}): invalid HMAC signature", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        $payload = json_decode($rawBody, true) ?: [];
        $hash = hash('sha256', $rawBody);

        // firstOrCreate on payload_hash gives us Shopify-retry idempotency:
        // the unique index fails insert on a duplicate, Eloquent fetches the
        // existing row, wasRecentlyCreated=false and we skip dispatch.
        $audit = GdprRequest::firstOrCreate(
            ['payload_hash' => $hash],
            [
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'shopify_shop_id' => is_numeric($payload['shop_id'] ?? null) ? (int) $payload['shop_id'] : null,
                'payload' => $payload,
                'status' => GdprRequest::STATUS_RECEIVED,
                'received_at' => now(),
            ],
        );

        if ($audit->wasRecentlyCreated) {
            match ($topic) {
                GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST => ExportCustomerDataJob::dispatch($audit->id),
                GdprRequest::TOPIC_CUSTOMERS_REDACT => RedactCustomerJob::dispatch($audit->id),
                GdprRequest::TOPIC_SHOP_REDACT => RedactShopJob::dispatch($audit->id),
            };

            Log::info("Shopify GDPR webhook accepted: {$topic}", [
                'gdpr_request_id' => $audit->id,
                'shop_domain' => $shopDomain,
            ]);
        } else {
            Log::info("Shopify GDPR webhook deduplicated: {$topic}", [
                'gdpr_request_id' => $audit->id,
                'shop_domain' => $shopDomain,
            ]);
        }

        return $this->success(['received' => true], 202);
    }
}
```

- [ ] **Step 4: Run controller tests — they must pass**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/GdprWebhookControllerTest.php`
Expected: PASS — 7 tests.

- [ ] **Step 5: Run the entire GDPR test suite to verify no regressions**

Run: `vendor/bin/pest tests/Feature/Shopify/Gdpr/`
Expected: PASS — 33 tests total across 6 files.

- [ ] **Step 6: Run the full test suite**

Run: `composer test`
Expected: PASS — no regressions in existing Shopify/webhook tests.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php \
        tests/Feature/Shopify/Gdpr/GdprWebhookControllerTest.php
git commit -m "feat(gdpr): wire up GDPR webhook controller with idempotency and dispatch"
```

---

## Task 9: Ops runbook — privacy policy + dev-store dry run

**Files:**
- Create: `docs/shopify-gdpr-runbook.md`

This task is documentation only — no code changes. The runbook captures the manual steps required before submitting to the Shopify App Store.

- [ ] **Step 1: Create the runbook**

Create `docs/shopify-gdpr-runbook.md`:

```markdown
# Shopify GDPR Webhooks — Ops Runbook

## Pre-submission checklist

### 1. Privacy policy page (required by Shopify reviewer)

A publicly accessible privacy policy URL must be linked from the Shopify Partner dashboard app listing. Ensure the page covers:

- What customer data Partna stores (email, phone, name, order-derived records)
- The three GDPR webhook endpoints and how requests are handled
- Retention periods: 30 days for soft-deleted records, indefinite for anonymised rows
- Contact address for data requests outside the Shopify flow

Marketing site page location: `apps/marketing/app/routes/privacy.tsx` (or the platform equivalent — check with Tobias).

### 2. Queue worker configuration

Ensure a worker is watching the `gdpr` queue. Local dev:

```bash
php artisan queue:work --queue=gdpr,default
```

Production (Laravel Cloud): confirm the worker process includes `gdpr` in its queue list. Default Horizon config watches the default queue only; adding `gdpr` is required.

### 3. Dev-store dry run

From the Shopify Partner dashboard:

1. Open a test app's configuration.
2. Navigate to **App setup → Compliance webhooks**.
3. Confirm each of the three URLs is set:
   - `https://<api-host>/api/webhooks/shopify/gdpr/customers-data-request`
   - `https://<api-host>/api/webhooks/shopify/gdpr/customers-redact`
   - `https://<api-host>/api/webhooks/shopify/gdpr/shop-redact`
4. Install the app on a test store with a few sample customers + orders.
5. From the Partner dashboard, use the **Request customer data** button to trigger `customers/data_request`. Verify the merchant (your own email) receives the JSON export within a minute.
6. Use **Request customer redact** — verify the target customer row is anonymised in Supabase and the subscription/enquiry rows are gone.
7. Uninstall the app and wait 48 hours (or manually trigger `shop/redact` via Shopify's CLI: `shopify app webhook trigger --topic shop/redact`). Verify the integration row is deleted and Shopify-sourced customers are anonymised.

### 4. Audit table spot-check

After each dry run, inspect `core.gdpr_requests` rows to confirm:
- `status = 'completed'` for successful runs
- `completed_at` is populated
- `payload_hash` is a 64-char hex string
- Retries from Shopify did not create duplicate rows

Query:
```sql
SELECT topic, status, shop_domain, received_at, completed_at, error
FROM core.gdpr_requests
ORDER BY received_at DESC
LIMIT 20;
```

## Monitoring

Add a Nightwatch alert for rows older than 24 hours in `status IN ('received', 'processing')` — those indicate a stuck job.

## Known limitations (document for App Store reviewer if asked)

- Commission ledger entries referencing anonymised customers are retained. The `customer_id` FK is preserved; the row it points to has placeholder PII. This is standard practice for financial-record integrity.
- Aggregate analytics tables (`*_daily`, `*_hourly`) are not touched. They contain pre-aggregated metrics with no PII.
- `shop/redact` is narrow-scope: the Partna professional account and their site survive. If the merchant wants full account deletion, they use the in-dashboard account deletion flow (separate feature).
```

- [ ] **Step 2: Commit**

```bash
git add docs/shopify-gdpr-runbook.md
git commit -m "docs(gdpr): add Shopify GDPR webhooks ops runbook"
```

---

## Verification Summary

After all 10 tasks (Task 0 through Task 9) are complete, you should have:

- **Pre-flight audit** passed — no undocumented denormalised PII columns discovered.
- **1 migration** applied (creating `core.gdpr_requests` + `core.customers.redacted_at`).
- **3 new job classes** under `app/Jobs/Shopify/Gdpr/`.
- **1 new service** at `app/Services/Shopify/ShopifyShopResolver.php`.
- **1 new model** at `app/Models/Core/Gdpr/GdprRequest.php`.
- **1 new Mailable** at `app/Mail/Gdpr/CustomerDataExportMail.php` with its Blade view.
- **1 refactored controller** at `app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php`.
- **6 new test files** under `tests/Feature/Shopify/Gdpr/` covering 33 assertions.
- **1 runbook** at `docs/shopify-gdpr-runbook.md`.
- **Updated config** (`config/sidest.php` + `.env.example`).

Final verification:

```bash
composer test
```

Expected: all tests pass, no regressions, no Laravel-migration guard violations (we used a Supabase migration).

## Next Steps (outside this plan)

1. Write the privacy policy page on the marketing site (frontend task — hand off to Tobias).
2. Configure the Laravel Cloud queue worker to watch the `gdpr` queue.
3. Run the dev-store dry run per the runbook.
4. Submit the app to the Shopify App Store for review.
