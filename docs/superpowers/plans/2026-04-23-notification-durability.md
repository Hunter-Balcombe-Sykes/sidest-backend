# Notification Durability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make adding a new notification type a 3-edit operation (Mailable class, config line, `$publisher->publish(...)` call) and replace the URL-based dedup hack with atomic column-based dedup.

**Architecture:** Replace `NotificationPublisher`'s check-then-insert + CTA-URL-dedup with `INSERT … ON CONFLICT DO NOTHING` against a new `dedupe_key` column + partial unique index. Collapse the `CATEGORIES` constant and the hardcoded category→Mailable match in `SendTransactionalNotificationEmailJob` into a single config map (`config/sidest.php`: `notification_mailables`) — one source of truth, typo-safe, read by validators and controllers too. Swap the hand-rolled `upsertReceipt` SQL in `NotificationController` for the query builder's native upsert.

**Tech Stack:** Laravel 12, PHP 8.2, Pest 4, PostgreSQL (Supabase migration), raw SQL migrations (no Laravel migrations).

---

## File Structure

**Create:**
- `supabase/migrations/20260423010000_add_dedupe_key_to_notifications.sql` — adds `dedupe_key` column + partial unique index
- `tests/Feature/Notifications/NotificationPublisherTest.php` — tests atomic dedup, CTA URL cleanliness, config-driven email dispatch
- `tests/Feature/Notifications/AddingNewCategoryTest.php` — "the 3-edit promise" regression test

**Modify:**
- `config/sidest.php` (line ~697) — add `notification_mailables` map
- `app/Services/Notifications/NotificationPublisher.php` — atomic upsert, delete `CATEGORIES` constant, delete `withDedupeKey()`
- `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php` — replace `buildMailable()` match with config lookup
- `app/Http/Controllers/Api/Professional/Notifications/NotificationController.php` (lines 90-109) — replace raw SQL `upsertReceipt` with query builder `upsert()`
- `app/Http/Requests/Api/Professional/Notifications/UpdateNotificationEmailPreferencesRequest.php` — read categories from config
- `app/Http/Requests/Api/Staff/Notifications/UpdateNotificationEmailPoliciesRequest.php` — read categories from config
- `app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php` — read categories from config
- `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationEmailPolicyController.php` — read categories from config

**No changes needed** (all 12 `publish()` call sites already pass `dedupeKey:` as a named arg):
- `app/Observers/Core/{CommissionPayoutObserver,ProfessionalIntegrationObserver,BrandAffiliateInviteObserver,CommissionLedgerEntryObserver}.php`
- `app/Jobs/Notifications/{FanOutBrandStatusNotificationJob,SendWeeklyAnalyticsNotificationJob,InviteExpirySweepJob}.php`
- `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php`
- `app/Services/Stripe/CommissionVoidService.php`
- `app/Services/Notifications/CommerceNotificationService.php`
- `app/Http/Controllers/Api/Webhooks/StripeWebhookController.php`

---

## Preflight — read these first

Before starting, open these files once so you have the current shape in mind:

1. `app/Services/Notifications/NotificationPublisher.php` — especially `publish()` at L27 and `withDedupeKey()` at L97
2. `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php` — `buildMailable()` at L104
3. `app/Http/Controllers/Api/Professional/Notifications/NotificationController.php` — `upsertReceipt()` at L92
4. `tests/Feature/Notifications/StaffNotificationRetentionTest.php` — the existing notification test. **This establishes the sqlite-with-attached-schemas pattern every new Pest test in this plan must follow.** Notification models extend `BaseModel` which pins the `pgsql` connection, so tests override the `pgsql` connection to sqlite in-memory and `ATTACH DATABASE` the `notifications` schema.

---

## Task 1: Add `dedupe_key` column + partial unique index

**Files:**
- Create: `supabase/migrations/20260423010000_add_dedupe_key_to_notifications.sql`

Partna uses raw SQL migrations (no Laravel migrations — composer guard rejects them). Josh runs migrations against Supabase manually; the plan's job is to produce the correct SQL file.

The partial unique index is intentional: `dedupe_key` is nullable so non-deduplicated notifications (if any) coexist cleanly, and the unique constraint applies only when `dedupe_key IS NOT NULL`.

- [ ] **Step 1.1: Create the migration file**

```sql
-- supabase/migrations/20260423010000_add_dedupe_key_to_notifications.sql
--
-- Replaces URL-based dedup (CTA URL with ?notif=key appended) with a proper
-- dedupe_key column. Partial unique index so NULL dedupe_keys don't collide.
-- Emitters that want dedup pass a `dedupeKey` to NotificationPublisher::publish();
-- the publisher writes it to this column and relies on ON CONFLICT DO NOTHING
-- for atomic idempotency.

BEGIN;

ALTER TABLE notifications.notifications
    ADD COLUMN IF NOT EXISTS dedupe_key text;

CREATE UNIQUE INDEX IF NOT EXISTS notifications_dedupe_key_per_pro_uq
    ON notifications.notifications (professional_id, dedupe_key)
    WHERE dedupe_key IS NOT NULL;

COMMIT;
```

- [ ] **Step 1.2: Verify SQL against linter (if available) or by eye**

No automated check in repo. Verify by reading:
- `ADD COLUMN IF NOT EXISTS` is idempotent.
- Partial unique index explicitly `WHERE dedupe_key IS NOT NULL` — this is load-bearing; without it, multiple NULL rows would violate uniqueness on Postgres (NULLs are distinct in unique indexes, but being explicit is clearer for readers).
- Wrapped in `BEGIN/COMMIT` to match existing migration style.

- [ ] **Step 1.3: Commit**

```bash
git add supabase/migrations/20260423010000_add_dedupe_key_to_notifications.sql
git commit -m "feat(notifications): add dedupe_key column + partial unique index"
```

---

## Task 2: Add `notification_mailables` config map

**Files:**
- Modify: `config/sidest.php:697` (the existing `'notifications'` block)

This merges two things into one source of truth: the `NotificationPublisher::CATEGORIES` constant and the `buildMailable()` match in `SendTransactionalNotificationEmailJob`. Keys are the valid category strings; values are the Mailable class (or `null` for in-app-only).

- [ ] **Step 2.1: Replace the existing `'notifications'` array block**

Current (config/sidest.php:697-699):

```php
'notifications' => [
    'email_enabled' => (bool) env('NOTIFICATIONS_EMAIL_ENABLED', false),
],
```

Replace with:

```php
'notifications' => [
    'email_enabled' => (bool) env('NOTIFICATIONS_EMAIL_ENABLED', false),

    /*
     * Category registry — single source of truth.
     *
     * Keys are valid category strings (validated in FormRequests).
     * Values are the Mailable class sent for transactional email, or null
     * for in-app-only categories. Adding a new notification type is:
     *   1. Create a Mailable in app/Mail/Notifications/ (skip for in-app only)
     *   2. Add one entry here
     *   3. Call $publisher->publish(category: 'your_key', ...) at the emit site
     * No edits to the publisher, no edits to the email dispatch job.
     */
    'mailables' => [
        'invites'              => \App\Mail\Notifications\InviteNotificationMail::class,
        'commissions'          => \App\Mail\Notifications\CommissionNotificationMail::class,
        'payouts'              => \App\Mail\Notifications\PayoutNotificationMail::class,
        'integrations'         => \App\Mail\Notifications\IntegrationNotificationMail::class,
        'analytics_weekly'     => \App\Mail\Notifications\AnalyticsWeeklyMail::class,
        'analytics_milestones' => \App\Mail\Notifications\AnalyticsMilestoneMail::class,
        'profile_tasks'        => \App\Mail\Notifications\ProfileTaskMail::class,
        'catalog_changes'      => \App\Mail\Notifications\CatalogChangeMail::class,
        'brand_status'         => \App\Mail\Notifications\BrandStatusMail::class,
        'subscriptions'        => \App\Mail\Notifications\SubscriptionMail::class,
        'brand_links'          => \App\Mail\Notifications\BrandLinkMail::class,
    ],
],
```

- [ ] **Step 2.2: Run config cache clear to pick up the change**

```bash
php artisan config:clear
```

Expected: command succeeds silently.

- [ ] **Step 2.3: Verify the config loads without errors**

```bash
php artisan tinker --execute="echo count(config('sidest.notifications.mailables'));"
```

Expected: `11`

- [ ] **Step 2.4: Commit**

```bash
git add config/sidest.php
git commit -m "feat(notifications): add config-driven category→mailable registry"
```

---

## Task 3: Refactor `NotificationPublisher::publish()` to atomic column-based dedup

**Files:**
- Modify: `app/Services/Notifications/NotificationPublisher.php`
- Test: `tests/Feature/Notifications/NotificationPublisherTest.php` (create)

This is the core of the refactor. We:
- Replace check-then-insert + URL smuggling with `INSERT … ON CONFLICT (professional_id, dedupe_key) WHERE dedupe_key IS NOT NULL DO NOTHING`.
- Drop `withDedupeKey()` entirely.
- Drop the `CATEGORIES` constant (the config map is now the source of truth — see Task 5).
- `cta_url` becomes "just a URL" — emitters pass the clean URL, no `?notif=` appendage.

- [ ] **Step 3.1: Write failing tests**

Create `tests/Feature/Notifications/NotificationPublisherTest.php`:

```php
<?php

/** @phpstan-ignore-all */

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Same sqlite-attached-schemas pattern as StaffNotificationRetentionTest.
    // Notification models extend BaseModel which pins the pgsql connection,
    // so point pgsql at sqlite in-memory and ATTACH the notifications schema.
    Config::set('database.connections.pgsql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS notifications");
    } catch (\Throwable $e) {
        // already attached
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        type TEXT NOT NULL,
        category TEXT NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        dedupe_key TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $conn->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications_dedupe_key_per_pro_uq
         ON notifications.notifications (professional_id, dedupe_key)
         WHERE dedupe_key IS NOT NULL'
    );

    Config::set('sidest.notifications.email_enabled', false);
});

it('stores dedupe_key in its own column, not smuggled into the CTA URL', function () {
    $publisher = new NotificationPublisher;

    $publisher->publish(
        professionalId: 'pro-1',
        frontendType: 'Info',
        category: 'invites',
        title: 'Test',
        body: 'Body',
        dedupeKey: 'invite:abc',
        ctaUrl: '/account/invites',
    );

    $row = DB::table('notifications.notifications')->first();

    expect($row->dedupe_key)->toBe('invite:abc');
    expect($row->cta_url)->toBe('/account/invites');       // clean, no ?notif=
    expect($row->cta_url)->not->toContain('notif=');
});

it('deduplicates via ON CONFLICT on (professional_id, dedupe_key)', function () {
    $publisher = new NotificationPublisher;

    $publish = fn () => $publisher->publish(
        professionalId: 'pro-1',
        frontendType: 'Info',
        category: 'invites',
        title: 'Test',
        body: 'Body',
        dedupeKey: 'invite:abc',
        ctaUrl: '/x',
    );

    $publish();
    $publish();
    $publish();

    expect(DB::table('notifications.notifications')->count())->toBe(1);
});

it('allows the same dedupe_key for different professionals', function () {
    $publisher = new NotificationPublisher;

    foreach (['pro-1', 'pro-2'] as $proId) {
        $publisher->publish(
            professionalId: $proId,
            frontendType: 'Info',
            category: 'invites',
            title: 'T',
            body: 'B',
            dedupeKey: 'invite:abc',
            ctaUrl: '/x',
        );
    }

    expect(DB::table('notifications.notifications')->count())->toBe(2);
});

it('rejects empty professional_id or empty title/body silently', function () {
    $publisher = new NotificationPublisher;

    $publisher->publish(
        professionalId: '',
        frontendType: 'Info',
        category: 'invites',
        title: 'T',
        body: 'B',
        dedupeKey: 'k',
    );

    $publisher->publish(
        professionalId: 'pro-1',
        frontendType: 'Info',
        category: 'invites',
        title: '   ',
        body: 'B',
        dedupeKey: 'k2',
    );

    expect(DB::table('notifications.notifications')->count())->toBe(0);
});
```

- [ ] **Step 3.2: Run the failing tests**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationPublisherTest.php
```

Expected: tests FAIL — current implementation writes `cta_url = '/account/invites?notif=invite:abc'`, not `'/account/invites'`, and has no `dedupe_key` column.

- [ ] **Step 3.3: Refactor the publisher**

Open `app/Services/Notifications/NotificationPublisher.php` and replace the whole file with:

```php
<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Models\Core\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: Core notification engine. Publishes with atomic dedup via dedupe_key column,
// optional email dispatch, retention policies, and per-professional category overrides.
class NotificationPublisher
{
    /**
     * Valid category keys — derived from the single source of truth in
     * config/sidest.php. FormRequests and controllers should prefer calling
     * this method directly over importing a constant.
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return array_keys((array) config('sidest.notifications.mailables', []));
    }

    public function publish(
        string $professionalId,
        string $frontendType,
        string $category,
        string $title,
        string $body,
        string $dedupeKey,
        ?string $ctaUrl = null,
        ?string $primaryActionLabel = 'View',
        ?string $secondaryActionLabel = 'Dismiss',
        ?string $secondaryActionUrl = null,
        ?string $retentionConfigKey = null,
    ): void {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            return;
        }

        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            // Require a non-empty dedupe key — this is the whole point of the refactor.
            // Callers should always provide one even if collisions are unlikely.
            return;
        }

        $now = now();
        $type = Notification::normalizeFrontendType($frontendType);
        $retentionKey = $retentionConfigKey ?? 'default';
        $days = config("sidest.notification_retention_days.{$retentionKey}")
            ?? config('sidest.notification_retention_days.default', 30);

        $notificationId = (string) Str::uuid();

        // Atomic upsert: ON CONFLICT on (professional_id, dedupe_key) DO NOTHING.
        // If a notification with this dedupe_key already exists for this pro,
        // this is a no-op — no duplicate row, no race window.
        $inserted = DB::table('notifications.notifications')->insertOrIgnore([
            'id' => $notificationId,
            'professional_id' => $professionalId,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'cta_url' => $ctaUrl ?? '/account/overview',
            'primary_action_label' => $primaryActionLabel,
            'secondary_action_label' => $secondaryActionLabel,
            'secondary_action_url' => $secondaryActionUrl,
            'severity' => Notification::severityForFrontendType($type),
            'starts_at' => $now,
            'ends_at' => $now->copy()->addDays((int) $days),
            'dedupe_key' => $dedupeKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Only dispatch the email job for genuinely-new rows. insertOrIgnore()
        // returns the number of rows actually inserted (0 on conflict).
        if ($inserted > 0 && config('sidest.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(
                $notificationId,
                $category,
                $professionalId,
            )->onQueue('mail');
        }
    }

    public static function resolveEmailEnabled(string $professionalId, string $category): bool
    {
        // Per-professional policy
        $perProMode = DB::table('core.notification_email_policies')
            ->where('professional_id', $professionalId)
            ->where('category_key', $category)
            ->value('mode');

        if ($perProMode === 'force_on') {
            return true;
        }
        if ($perProMode === 'force_off') {
            return false;
        }

        // Global policy
        $globalMode = DB::table('core.notification_email_policies')
            ->whereNull('professional_id')
            ->where('category_key', $category)
            ->value('mode');

        if ($globalMode === 'force_on') {
            return true;
        }
        if ($globalMode === 'force_off') {
            return false;
        }

        // Professional preference
        $preference = DB::table('notifications.notification_email_preferences')
            ->where('professional_id', $professionalId)
            ->where('category_key', $category)
            ->value('enabled');

        if ($preference !== null) {
            return (bool) $preference;
        }

        return true; // default enabled
    }
}
```

Key deltas from the current file:
- `CATEGORIES` constant **removed**. Replaced by `static::categories()` reading `config('sidest.notifications.mailables')`.
- `withDedupeKey()` method **removed**.
- `publish()` no longer appends `?notif=…` to the CTA URL, no longer does `WHERE cta_url = ?` exists-check. Uses `insertOrIgnore()` (which emits `ON CONFLICT DO NOTHING` on Postgres) against the partial unique index from Task 1.
- `$dedupeKey` is now required (empty → no-op return). All existing callers already pass it.
- Email job only dispatches when `insertOrIgnore` actually inserted (avoids spamming emails on dedup hits).

- [ ] **Step 3.4: Run the tests again**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationPublisherTest.php
```

Expected: all 4 tests PASS.

- [ ] **Step 3.5: Run the broader notification suite**

```bash
./vendor/bin/pest tests/Feature/Notifications/
```

Expected: all existing tests (including `StaffNotificationRetentionTest`) still pass. If any fail, fix before moving on — no skipping.

- [ ] **Step 3.6: Commit**

```bash
git add app/Services/Notifications/NotificationPublisher.php tests/Feature/Notifications/NotificationPublisherTest.php
git commit -m "refactor(notifications): atomic dedup via dedupe_key column, remove CATEGORIES constant"
```

---

## Task 4: Refactor `SendTransactionalNotificationEmailJob` to config-driven dispatch

**Files:**
- Modify: `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`
- Test: extend `tests/Feature/Notifications/NotificationPublisherTest.php` (or create a new file)

Replace the category→Mailable match statement (lines 104-120) with a single config lookup. Handles `null` values (in-app-only categories) and missing keys (unknown category → log and return).

- [ ] **Step 4.1: Add a failing test for config-driven dispatch**

Append to `tests/Feature/Notifications/NotificationPublisherTest.php`:

```php
use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Mail\Notifications\InviteNotificationMail;
use App\Models\Core\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

it('dispatches the mailable class resolved from config for the category', function () {
    Mail::fake();
    Config::set('sidest.notifications.email_enabled', true);

    // Seed a notification row the job can load.
    DB::table('notifications.notifications')->insert([
        'id' => 'notif-1',
        'professional_id' => 'pro-1',
        'type' => 'Info',
        'category' => 'invites',
        'title' => 'Welcome',
        'body' => 'You are invited',
        'cta_url' => '/x',
        'primary_action_label' => 'View',
        'secondary_action_label' => 'Dismiss',
        'secondary_action_url' => null,
        'severity' => 'info',
        'starts_at' => now(),
        'ends_at' => now()->addDays(30),
        'dedupe_key' => 'k',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Point pgsql.core.professionals at a temp table with the email.
    DB::connection('pgsql')->statement("ATTACH DATABASE ':memory:' AS core");
    DB::connection('pgsql')->statement(
        'CREATE TABLE IF NOT EXISTS core.professionals (id TEXT PRIMARY KEY, primary_email TEXT)'
    );
    DB::connection('pgsql')->statement(
        'CREATE TABLE IF NOT EXISTS core.notification_email_policies (id TEXT, professional_id TEXT, category_key TEXT, mode TEXT)'
    );
    DB::connection('pgsql')->statement(
        'CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (id TEXT, professional_id TEXT, category_key TEXT, enabled INTEGER)'
    );
    DB::table('core.professionals')->insert(['id' => 'pro-1', 'primary_email' => 'pro@example.com']);

    (new SendTransactionalNotificationEmailJob('notif-1', 'invites', 'pro-1'))->handle();

    Mail::assertSent(InviteNotificationMail::class);
});

it('skips email dispatch when category maps to null (in-app only)', function () {
    Mail::fake();
    Config::set('sidest.notifications.email_enabled', true);
    Config::set('sidest.notifications.mailables.in_app_only_demo', null);

    // Minimal seed — job should bail on null mapping before DB reads matter.
    (new SendTransactionalNotificationEmailJob('n-x', 'in_app_only_demo', 'pro-1'))->handle();

    Mail::assertNothingSent();
});
```

- [ ] **Step 4.2: Run the failing tests**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationPublisherTest.php --filter="dispatches the mailable class resolved from config"
```

Expected: FAIL — the match statement doesn't consult config yet.

- [ ] **Step 4.3: Replace the job's `buildMailable()` with config lookup**

Open `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`.

**Remove** the 11 `use App\Mail\Notifications\*;` lines at the top of the file (they become unreferenced once the match is gone).

**Replace** `buildMailable()` (current lines 104-120):

```php
private function buildMailable(Notification $notification): ?\Illuminate\Mail\Mailable
{
    return match ($this->category) {
        'invites' => new InviteNotificationMail($notification),
        // ... 10 more lines ...
        default => null,
    };
}
```

with:

```php
private function buildMailable(Notification $notification): ?\Illuminate\Mail\Mailable
{
    $class = config("sidest.notifications.mailables.{$this->category}");

    // null or missing key → category is in-app only (or unregistered).
    // handle() treats null as "skip email", so the caller path is the same.
    if (! is_string($class) || ! class_exists($class)) {
        return null;
    }

    $mailable = new $class($notification);

    if (! $mailable instanceof \Illuminate\Mail\Mailable) {
        return null;
    }

    return $mailable;
}
```

- [ ] **Step 4.4: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Notifications/NotificationPublisherTest.php
```

Expected: all tests PASS.

- [ ] **Step 4.5: Commit**

```bash
git add app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php tests/Feature/Notifications/NotificationPublisherTest.php
git commit -m "refactor(notifications): resolve mailable from config map instead of match statement"
```

---

## Task 5: Update FormRequests & controllers to read categories from config

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Notifications/UpdateNotificationEmailPreferencesRequest.php`
- Modify: `app/Http/Requests/Api/Staff/Notifications/UpdateNotificationEmailPoliciesRequest.php`
- Modify: `app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php`
- Modify: `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationEmailPolicyController.php`

Four files currently import `NotificationPublisher::CATEGORIES`. That constant is gone. Switch each to `NotificationPublisher::categories()` (the static method added in Task 3, which reads from config).

- [ ] **Step 5.1: Update `UpdateNotificationEmailPreferencesRequest.php`**

Open the file. Around line 18 (or wherever `NotificationPublisher::CATEGORIES` appears):

```php
$validCategories = implode(',', NotificationPublisher::CATEGORIES);
```

Replace with:

```php
$validCategories = implode(',', NotificationPublisher::categories());
```

- [ ] **Step 5.2: Update `UpdateNotificationEmailPoliciesRequest.php`**

Same replacement — find `NotificationPublisher::CATEGORIES` and replace with `NotificationPublisher::categories()`.

- [ ] **Step 5.3: Update `NotificationEmailPreferenceController.php`**

Around line 65:

```php
}, NotificationPublisher::CATEGORIES);
```

Replace with:

```php
}, NotificationPublisher::categories());
```

- [ ] **Step 5.4: Update `StaffNotificationEmailPolicyController.php`**

Two call sites (lines ~26 and ~56). Replace both occurrences of `NotificationPublisher::CATEGORIES` with `NotificationPublisher::categories()`.

- [ ] **Step 5.5: Verify no remaining references to the constant**

```bash
grep -rn "NotificationPublisher::CATEGORIES" app/ tests/
```

Expected: empty output.

- [ ] **Step 5.6: Run the full test suite**

```bash
composer test
```

Expected: all tests pass. The `composer test` script runs Pest after clearing config.

- [ ] **Step 5.7: Commit**

```bash
git add app/Http/Requests/Api/Professional/Notifications/UpdateNotificationEmailPreferencesRequest.php \
        app/Http/Requests/Api/Staff/Notifications/UpdateNotificationEmailPoliciesRequest.php \
        app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php \
        app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationEmailPolicyController.php
git commit -m "refactor(notifications): read category list from NotificationPublisher::categories()"
```

---

## Task 6: Replace raw-SQL `upsertReceipt` with query-builder `upsert()`

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/Notifications/NotificationController.php` (lines 90-109)

Small, semantics-preserving cleanup. The query builder's `upsert()` emits the same `INSERT … ON CONFLICT DO UPDATE` as the hand-rolled SQL, with less code and no string interpolation.

- [ ] **Step 6.1: Replace `upsertReceipt()` + `RECEIPT_COLUMNS`**

Open `app/Http/Controllers/Api/Professional/Notifications/NotificationController.php`. Delete lines 90-109 (the `RECEIPT_COLUMNS` constant and the entire `upsertReceipt` method).

Insert in their place:

```php
private const RECEIPT_COLUMNS = ['read_at', 'dismissed_at'];

private function upsertReceipt(string $notificationId, string $professionalId, array $set): void
{
    // Whitelist — only read_at / dismissed_at can be set, no other columns.
    $set = array_intersect_key($set, array_flip(self::RECEIPT_COLUMNS));

    DB::table('notifications.notification_receipts')->upsert(
        [array_merge([
            'id' => (string) Str::uuid(),
            'notification_id' => $notificationId,
            'professional_id' => $professionalId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $set)],
        ['notification_id', 'professional_id'], // unique-by columns
        [...array_keys($set), 'updated_at'],     // columns to overwrite on conflict
    );
}
```

- [ ] **Step 6.2: Run the controller tests**

```bash
./vendor/bin/pest tests/ --filter="Notification"
```

Expected: all existing notification tests pass. The behavior is identical — same `ON CONFLICT` semantics under the hood.

- [ ] **Step 6.3: Commit**

```bash
git add app/Http/Controllers/Api/Professional/Notifications/NotificationController.php
git commit -m "refactor(notifications): replace hand-rolled upsert SQL with query builder upsert()"
```

---

## Task 7: Regression test — "adding a new category is 3 edits"

**Files:**
- Create: `tests/Feature/Notifications/AddingNewCategoryTest.php`

A guardrail test that proves the 3-edit promise: adding an entry to `config('sidest.notifications.mailables')` is the only thing the notification system needs to accept and email a new category. If someone re-introduces a central switch/constant, this test breaks.

- [ ] **Step 7.1: Write the test**

```php
<?php

/** @phpstan-ignore-all */

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// Ad-hoc Mailable stand-in — represents "the Mailable a new category would ship with".
class FakeNewCategoryMail extends Mailable
{
    public function __construct(public Notification $notification) {}

    public function build(): self
    {
        return $this->subject('Fake')->html('<p>hi</p>');
    }
}

beforeEach(function () {
    // Same sqlite-attached-schemas bootstrap as NotificationPublisherTest.
    Config::set('database.connections.pgsql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');
    try { $conn->statement("ATTACH DATABASE ':memory:' AS notifications"); } catch (\Throwable) {}
    try { $conn->statement("ATTACH DATABASE ':memory:' AS core"); } catch (\Throwable) {}

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY, professional_id TEXT NULL, type TEXT, category TEXT, title TEXT, body TEXT,
        cta_url TEXT, primary_action_label TEXT, secondary_action_label TEXT, secondary_action_url TEXT,
        severity TEXT, starts_at TEXT, ends_at TEXT, dedupe_key TEXT, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE UNIQUE INDEX IF NOT EXISTS notifications_dedupe_key_per_pro_uq
        ON notifications.notifications (professional_id, dedupe_key) WHERE dedupe_key IS NOT NULL');
    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (id TEXT PRIMARY KEY, primary_email TEXT)');
    $conn->statement('CREATE TABLE IF NOT EXISTS core.notification_email_policies (id TEXT, professional_id TEXT, category_key TEXT, mode TEXT)');
    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (id TEXT, professional_id TEXT, category_key TEXT, enabled INTEGER)');

    DB::table('core.professionals')->insert(['id' => 'pro-1', 'primary_email' => 'pro@example.com']);

    Config::set('sidest.notifications.email_enabled', true);
});

it('accepts a new category as a single config-map edit — no publisher/job changes', function () {
    Mail::fake();

    // EDIT #1 (simulated): register the new category + its mailable in config.
    // In production this would be one line in config/sidest.php.
    Config::set('sidest.notifications.mailables.brand_new_thing', FakeNewCategoryMail::class);

    // `NotificationPublisher::categories()` should now include it without touching the publisher.
    expect(NotificationPublisher::categories())->toContain('brand_new_thing');

    // EDIT #2 (simulated): emit site calls $publisher->publish(category: 'brand_new_thing', ...).
    $publisher = new NotificationPublisher;
    $publisher->publish(
        professionalId: 'pro-1',
        frontendType: 'Info',
        category: 'brand_new_thing',
        title: 'Hello',
        body: 'World',
        dedupeKey: 'brand_new_thing:1',
        ctaUrl: '/x',
    );

    // Notification row created.
    expect(DB::table('notifications.notifications')->where('category', 'brand_new_thing')->count())->toBe(1);

    // Email dispatch job — when it runs, it resolves the Mailable from config.
    $notificationId = DB::table('notifications.notifications')->where('category', 'brand_new_thing')->value('id');
    (new SendTransactionalNotificationEmailJob($notificationId, 'brand_new_thing', 'pro-1'))->handle();

    Mail::assertSent(FakeNewCategoryMail::class);
});
```

- [ ] **Step 7.2: Run the test**

```bash
./vendor/bin/pest tests/Feature/Notifications/AddingNewCategoryTest.php
```

Expected: PASS.

- [ ] **Step 7.3: Run the full test suite one more time**

```bash
composer test
```

Expected: all green. If any test fails, do not commit — diagnose and fix.

- [ ] **Step 7.4: Commit**

```bash
git add tests/Feature/Notifications/AddingNewCategoryTest.php
git commit -m "test(notifications): regression test locking in 3-edit add-a-category contract"
```

---

## Final verification

- [ ] **Verify the migration is in place** for Josh to run:

```bash
ls supabase/migrations/ | grep dedupe_key
```

Expected: `20260423010000_add_dedupe_key_to_notifications.sql`

- [ ] **Grep for any leftover references to the old design:**

```bash
grep -rn "withDedupeKey\|NotificationPublisher::CATEGORIES\|notif=" app/ tests/
```

Expected: empty output (the `notif=` URL-dedup hack is fully gone).

- [ ] **Tell Josh:** the migration file exists at `supabase/migrations/20260423010000_add_dedupe_key_to_notifications.sql` and needs to be run against Supabase before the code is deployed.

---

## Out of scope — explicitly

- **Failure-visibility contract on service-layer try/catch** (`CommerceNotificationService::notifyBookingCompleted` etc. swallowing exceptions). This belongs in the 139 design / 140 sweep for the unified failure policy, not here. Leave the existing try/catch blocks alone.
- **Notification retention policy changes.** `notification_retention_days` config stays as-is.
- **Frontend changes** (notification feed rendering, read/dismiss UI). The response shape from `NotificationController::index()` is unchanged.
- **Backfilling `dedupe_key` for existing rows.** Existing rows stay NULL; they'll age out via retention TTL. No customers, so no data migration needed.
