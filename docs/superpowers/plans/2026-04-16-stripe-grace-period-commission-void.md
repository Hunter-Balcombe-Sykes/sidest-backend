# Affiliate Stripe Grace Period + Commission Void Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Affiliates get 30 days to connect Stripe. After that, commissions past their 30-day void window are voided (Partna keeps 20% fee, affiliate's 80% returns to brand). Warning notifications nudge connection.

**Architecture:** New `CommissionVoidService` handles void logic, called from the existing `ProcessCommissionPayoutsJob`. Grace period column on `professionals` gates the payout service. Stripe reconnection flushes eligible held commissions via webhook handler. Notifications use existing `NotificationPublisher`.

**Tech Stack:** Laravel 12, Supabase PostgreSQL, Pest tests with SQLite in-memory

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `supabase/migrations/20260416000000_add_commission_grace_period.sql` | Schema: new columns + `voided` status |
| Create | `app/Services/Stripe/CommissionVoidService.php` | Void logic, grace period checks, warning notifications |
| Create | `tests/Feature/Stripe/CommissionVoidServiceTest.php` | Full test coverage for void service |
| Modify | `app/Models/Core/Professional/Professional.php` | Add `stripe_grace_period_ends_at` to fillable/casts |
| Modify | `app/Models/Retail/CommissionLedgerEntry.php` | Add `voided_at`, `void_reason` to fillable/casts |
| Modify | `app/Services/Stripe/CommissionPayoutService.php` | Skip commissions for unconnected affiliates in grace (don't fail) |
| Modify | `app/Jobs/Stripe/ProcessCommissionPayoutsJob.php` | Call void service after payout processing |
| Modify | `app/Observers/Core/CommissionLedgerEntryObserver.php` | Add `notifyVoided()` on status change to `voided` |
| Modify | `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php` | Flush held commissions on Stripe connect |
| Modify | `app/Services/Stripe/StripeConnectService.php` | Set grace period on account creation |
| Modify | `config/sidest.php` | Add `commission_void_window_days` and `grace_period_days` config |

---

## Task 1: Database Migration

**Files:**
- Create: `supabase/migrations/20260416000000_add_commission_grace_period.sql`

- [ ] **Step 1: Write the migration SQL**

```sql
-- Grace period: 30 days from affiliate creation to connect Stripe
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_grace_period_ends_at timestamptz;

COMMENT ON COLUMN core.professionals.stripe_grace_period_ends_at
    IS 'Deadline for affiliate to connect Stripe. Set to created_at + 30 days on account creation. NULL = no grace period (brand or already connected).';

-- Void tracking on commission entries
ALTER TABLE commerce.commission_ledger_entries
    ADD COLUMN IF NOT EXISTS voided_at timestamptz,
    ADD COLUMN IF NOT EXISTS void_reason text;

COMMENT ON COLUMN commerce.commission_ledger_entries.voided_at
    IS 'When this commission was voided (affiliate failed to connect Stripe in time).';
COMMENT ON COLUMN commerce.commission_ledger_entries.void_reason
    IS 'Reason for void: no_stripe_connected, grace_period_expired, etc.';

-- Expand status constraint to include 'voided'
ALTER TABLE commerce.commission_ledger_entries
    DROP CONSTRAINT IF EXISTS commission_ledger_status_check;
ALTER TABLE commerce.commission_ledger_entries
    ADD CONSTRAINT commission_ledger_status_check
    CHECK (status IN ('pending', 'approved', 'paid', 'reversed', 'disputed', 'voided'));

-- Index for the void cron: find pending commissions past their void window
CREATE INDEX IF NOT EXISTS idx_cle_voidable
    ON commerce.commission_ledger_entries (affiliate_professional_id, status, created_at)
    WHERE status = 'pending' AND payout_id IS NULL;

-- Backfill grace period for existing affiliates/influencers who haven't connected Stripe.
-- Sets their grace period to created_at + 30 days. Already-connected affiliates get NULL.
UPDATE core.professionals
SET stripe_grace_period_ends_at = created_at + INTERVAL '30 days'
WHERE professional_type IN ('influencer', 'professional')
  AND stripe_connect_status IN ('not_connected', 'onboarding')
  AND stripe_grace_period_ends_at IS NULL;
```

- [ ] **Step 2: Apply migration to Supabase**

Run: `supabase migration up` (or apply via Supabase dashboard)
Expected: Migration applies cleanly, no errors.

- [ ] **Step 3: Commit**

```bash
git add supabase/migrations/20260416000000_add_commission_grace_period.sql
git commit -m "feat(schema): add grace period + commission void columns and voided status"
```

---

## Task 2: Config + Model Updates

**Files:**
- Modify: `config/sidest.php` (store section, ~line 369)
- Modify: `app/Models/Core/Professional/Professional.php` (fillable ~line 47, casts ~line 86)
- Modify: `app/Models/Retail/CommissionLedgerEntry.php` (fillable ~line 22, casts ~line 37)

- [ ] **Step 1: Add config keys to `config/sidest.php`**

In the `'store'` array (after `'platform_fee_percent'` line ~375), add:

```php
'grace_period_days' => (int) env('SIDEST_STORE_GRACE_PERIOD_DAYS', 30),
'commission_void_window_days' => (int) env('SIDEST_STORE_COMMISSION_VOID_WINDOW_DAYS', 30),
```

- [ ] **Step 2: Update Professional model**

In `$fillable` array, add after `'stripe_manual_balance_currency'`:
```php
'stripe_grace_period_ends_at',
```

In `$casts` array, add:
```php
'stripe_grace_period_ends_at' => 'datetime',
```

- [ ] **Step 3: Update CommissionLedgerEntry model**

In `$fillable` array, add after `'payout_id'`:
```php
'voided_at',
'void_reason',
```

In `$casts` array, add:
```php
'voided_at' => 'datetime',
```

- [ ] **Step 4: Commit**

```bash
git add config/sidest.php app/Models/Core/Professional/Professional.php app/Models/Retail/CommissionLedgerEntry.php
git commit -m "feat(models): add grace period + void fields to Professional and CommissionLedgerEntry"
```

---

## Task 3: CommissionVoidService — Core Logic

**Files:**
- Create: `app/Services/Stripe/CommissionVoidService.php`

- [ ] **Step 1: Create the void service**

```php
<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles voiding commissions for affiliates who haven't connected Stripe.
 *
 * Two phases run on the daily cron:
 * 1. processVoidableCommissions() — void entries past their 30-day window
 * 2. sendGracePeriodWarnings() — nudge affiliates to connect Stripe
 *
 * Called from ProcessCommissionPayoutsJob after normal payout processing.
 */
class CommissionVoidService
{
    private int $voidWindowDays;
    private int $gracePeriodDays;
    private float $platformFeePercent;

    public function __construct(private readonly NotificationPublisher $publisher)
    {
        $this->voidWindowDays = (int) config('sidest.store.commission_void_window_days', 30);
        $this->gracePeriodDays = (int) config('sidest.store.grace_period_days', 30);
        $this->platformFeePercent = (float) config('sidest.store.platform_fee_percent', 20);
    }

    /**
     * Find and void all pending commissions past their void window
     * for affiliates without active Stripe accounts.
     *
     * @return array{voided_count: int, voided_cents: int}
     */
    public function processVoidableCommissions(): array
    {
        $cutoff = now()->subDays($this->voidWindowDays);

        // Find pending commissions older than the void window where the
        // affiliate still hasn't connected Stripe (not 'active' status).
        $voidable = CommissionLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('entry_type', 'accrual')
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->whereHas('affiliateProfessional', function ($q) {
                $q->where('stripe_connect_status', '!=', 'active');
            })
            ->with('affiliateProfessional:id,display_name,stripe_connect_status')
            ->get();

        $stats = ['voided_count' => 0, 'voided_cents' => 0];

        foreach ($voidable as $entry) {
            try {
                $this->voidEntry($entry, 'no_stripe_connected');
                $stats['voided_count']++;
                $stats['voided_cents'] += $entry->amount_cents;
            } catch (\Throwable $e) {
                Log::error('Failed to void commission entry', [
                    'entry_id' => $entry->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($stats['voided_count'] > 0) {
            Log::info('Commission void processing complete', $stats);
        }

        return $stats;
    }

    /**
     * Void a single commission entry. The affiliate's 80% is returned to
     * the brand (by simply not paying it out). Partna's 20% fee is still
     * collected from the brand via the normal payout flow — but since the
     * affiliate isn't getting paid, we just mark the entry voided.
     *
     * The actual fee collection happens separately if needed; for now voiding
     * means the commission is dead and won't appear in future payout batches.
     */
    public function voidEntry(CommissionLedgerEntry $entry, string $reason): void
    {
        $entry->update([
            'status' => 'voided',
            'voided_at' => now(),
            'void_reason' => $reason,
        ]);
    }

    /**
     * Send warning notifications to affiliates approaching void deadlines.
     *
     * Grace period warnings (day 20 and day 28 after signup):
     *   Sent to affiliates still within their grace period who haven't connected.
     *
     * Per-commission warnings (5 days before void window expires):
     *   Sent to post-grace affiliates for each commission about to void.
     *
     * @return array{warnings_sent: int}
     */
    public function sendGracePeriodWarnings(): array
    {
        $stats = ['warnings_sent' => 0];

        $stats['warnings_sent'] += $this->sendSignupWarnings();
        $stats['warnings_sent'] += $this->sendPerCommissionWarnings();

        return $stats;
    }

    /**
     * Day 20 and day 28 warnings for affiliates in their initial grace period.
     */
    private function sendSignupWarnings(): int
    {
        $sent = 0;

        // Day 20 warning: grace period ends in 10 days
        $day20Window = now()->subDays($this->gracePeriodDays - 10);
        $day20Start = $day20Window->copy()->startOfDay();
        $day20End = $day20Window->copy()->endOfDay();

        $day20Affiliates = Professional::query()
            ->whereIn('professional_type', ['influencer', 'professional'])
            ->where('stripe_connect_status', '!=', 'active')
            ->whereNotNull('stripe_grace_period_ends_at')
            ->where('stripe_grace_period_ends_at', '>', now())
            ->whereBetween('stripe_grace_period_ends_at', [
                now()->addDays(9)->startOfDay(),
                now()->addDays(11)->endOfDay(),
            ])
            ->get();

        foreach ($day20Affiliates as $affiliate) {
            $pendingAmount = $this->getPendingCommissionCents($affiliate->id);
            if ($pendingAmount <= 0) {
                continue;
            }

            $this->publisher->publish(
                professionalId: $affiliate->id,
                frontendType: 'Warning',
                category: 'commissions',
                title: 'Connect Stripe — 10 days left',
                body: sprintf(
                    'Connect your Stripe account within 10 days or your %s in pending earnings will be forfeited.',
                    $this->formatMoney($pendingAmount, 'AUD'),
                ),
                dedupeKey: "stripe_warning.day20.{$affiliate->id}",
                ctaUrl: '/account/settings?section=stripe',
                retentionConfigKey: 'commission',
            );
            $sent++;
        }

        // Day 28 warning: grace period ends in 2 days
        $day28Affiliates = Professional::query()
            ->whereIn('professional_type', ['influencer', 'professional'])
            ->where('stripe_connect_status', '!=', 'active')
            ->whereNotNull('stripe_grace_period_ends_at')
            ->where('stripe_grace_period_ends_at', '>', now())
            ->whereBetween('stripe_grace_period_ends_at', [
                now()->addDays(1)->startOfDay(),
                now()->addDays(3)->endOfDay(),
            ])
            ->get();

        foreach ($day28Affiliates as $affiliate) {
            $pendingAmount = $this->getPendingCommissionCents($affiliate->id);
            if ($pendingAmount <= 0) {
                continue;
            }

            $this->publisher->publish(
                professionalId: $affiliate->id,
                frontendType: 'Warning',
                category: 'commissions',
                title: 'Connect Stripe — 2 days left',
                body: sprintf(
                    '2 days left — connect Stripe now or your %s in pending earnings will be forfeited.',
                    $this->formatMoney($pendingAmount, 'AUD'),
                ),
                dedupeKey: "stripe_warning.day28.{$affiliate->id}",
                ctaUrl: '/account/settings?section=stripe',
                retentionConfigKey: 'commission',
            );
            $sent++;
        }

        return $sent;
    }

    /**
     * Per-commission warning: 5 days before each commission's void window.
     * For affiliates who are past their initial grace period.
     */
    private function sendPerCommissionWarnings(): int
    {
        $sent = 0;
        $warningCutoff = now()->addDays(5);

        // Find pending commissions that will void within 5 days, belonging to
        // affiliates without active Stripe and past their grace period.
        $atRiskEntries = CommissionLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('entry_type', 'accrual')
            ->where('status', 'pending')
            ->where('created_at', '<=', $warningCutoff->copy()->subDays($this->voidWindowDays))
            ->whereHas('affiliateProfessional', function ($q) {
                $q->where('stripe_connect_status', '!=', 'active')
                  ->where(function ($q2) {
                      $q2->whereNull('stripe_grace_period_ends_at')
                         ->orWhere('stripe_grace_period_ends_at', '<=', now());
                  });
            })
            ->with('affiliateProfessional:id,display_name')
            ->get();

        foreach ($atRiskEntries as $entry) {
            $voidDate = $entry->created_at->addDays($this->voidWindowDays);
            $daysLeft = (int) now()->diffInDays($voidDate, false);

            if ($daysLeft < 0 || $daysLeft > 5) {
                continue;
            }

            $this->publisher->publish(
                professionalId: $entry->affiliate_professional_id,
                frontendType: 'Warning',
                category: 'commissions',
                title: 'Commission expiring soon',
                body: sprintf(
                    'Connect Stripe within %d days or your %s commission from %s will be forfeited.',
                    $daysLeft,
                    $this->formatMoney($entry->amount_cents, $entry->currency_code),
                    $entry->occurred_at->format('M j'),
                ),
                dedupeKey: "stripe_warning.commission.{$entry->id}",
                ctaUrl: '/account/settings?section=stripe',
                retentionConfigKey: 'commission',
            );
            $sent++;
        }

        return $sent;
    }

    /**
     * Flush eligible held commissions when an affiliate connects Stripe.
     * Called from the webhook handler when status transitions to 'active'.
     *
     * Finds all pending commissions for this affiliate where the hold period
     * has elapsed and the void window hasn't expired, then marks them approved
     * so the normal payout cron picks them up.
     *
     * @return int Number of commissions flushed to approved
     */
    public function flushHeldCommissions(Professional $affiliate): int
    {
        $count = CommissionLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('entry_type', 'accrual')
            ->where('status', 'pending')
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('created_at', '>', now()->subDays($this->voidWindowDays))
            ->update(['status' => 'approved']);

        if ($count > 0) {
            Log::info('Flushed held commissions on Stripe connect', [
                'affiliate_id' => $affiliate->id,
                'count' => $count,
            ]);
        }

        return $count;
    }

    /**
     * Check if an affiliate is within their grace period (Stripe not yet required).
     */
    public function isInGracePeriod(Professional $affiliate): bool
    {
        return $affiliate->stripe_grace_period_ends_at !== null
            && $affiliate->stripe_grace_period_ends_at->isFuture();
    }

    private function getPendingCommissionCents(string $affiliateId): int
    {
        return (int) CommissionLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('entry_type', 'accrual')
            ->where('status', 'pending')
            ->where('affiliate_professional_id', $affiliateId)
            ->sum('amount_cents');
    }

    private function formatMoney(int $cents, string $currencyCode): string
    {
        $prefix = match (strtoupper($currencyCode)) {
            'USD'   => '$',
            'GBP'   => '£',
            'EUR'   => '€',
            'AUD'   => 'A$',
            default => strtoupper($currencyCode) . ' ',
        };

        return $prefix . number_format($cents / 100, 2, '.', ',');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Stripe/CommissionVoidService.php
git commit -m "feat(commissions): add CommissionVoidService for grace period + void logic"
```

---

## Task 4: Wire Grace Period into StripeConnectService

**Files:**
- Modify: `app/Services/Stripe/StripeConnectService.php` (~line 52)

- [ ] **Step 1: Set grace period on account creation**

In `createConnectAccount()`, after the `$professional->update(...)` call at line 52, add the grace period assignment. Replace the existing update block:

```php
        $professional->update([
            'stripe_connect_account_id' => $account->id,
            'stripe_connect_status' => 'onboarding',
            'stripe_grace_period_ends_at' => $professional->stripe_grace_period_ends_at
                ?? now()->addDays((int) config('sidest.store.grace_period_days', 30)),
        ]);
```

This uses `??` so that if a grace period was already set (e.g. backfilled), it isn't overwritten.

- [ ] **Step 2: Commit**

```bash
git add app/Services/Stripe/StripeConnectService.php
git commit -m "feat(stripe): set grace period on Connect account creation"
```

---

## Task 5: Modify CommissionPayoutService — Skip Grace Period Affiliates

**Files:**
- Modify: `app/Services/Stripe/CommissionPayoutService.php` (~line 282-290)

- [ ] **Step 1: Update processPayoutBatch to handle grace period**

In `processPayoutBatch()`, replace the affiliate Stripe check at lines 287-290:

```php
        if (! $affiliate?->stripe_connect_account_id || $affiliate->stripe_connect_status !== 'active') {
            $this->failPayout($payout, 'affiliate_not_connected', 'Affiliate Stripe Connect account is not active');
            return false;
        }
```

With grace-period-aware logic:

```php
        if (! $affiliate?->stripe_connect_account_id || $affiliate->stripe_connect_status !== 'active') {
            // During grace period or within void window: skip this batch silently
            // so the void service can handle it on its own schedule. Don't fail
            // the payout — it will either be flushed when Stripe connects or
            // voided when the window expires.
            $this->markPendingFunding($payout, 'affiliate_not_connected', 'Affiliate Stripe Connect account is not active — holding for grace period');
            return null;
        }
```

This changes the return from `false` (permanent failure) to `null` (pending), so the batch stays retryable.

- [ ] **Step 2: Commit**

```bash
git add app/Services/Stripe/CommissionPayoutService.php
git commit -m "fix(payouts): hold batches for unconnected affiliates instead of failing"
```

---

## Task 6: Wire Void Service into Payout Job

**Files:**
- Modify: `app/Jobs/Stripe/ProcessCommissionPayoutsJob.php` (~line 41)

- [ ] **Step 1: Add void processing after payouts**

Replace the `handle()` method:

```php
    public function handle(CommissionPayoutService $payoutService, CommissionVoidService $voidService): void
    {
        Log::info('Starting commission payout processing', [
            'attempt' => $this->attempts(),
        ]);

        $payoutStats = $payoutService->processEligiblePayouts();
        Log::info('Commission payout processing complete', $payoutStats);

        // Void commissions past their window for unconnected affiliates
        $voidStats = $voidService->processVoidableCommissions();

        // Send warning notifications to affiliates approaching deadlines
        $warningStats = $voidService->sendGracePeriodWarnings();

        if ($voidStats['voided_count'] > 0 || $warningStats['warnings_sent'] > 0) {
            Log::info('Commission void/warning processing complete', [
                ...$voidStats,
                ...$warningStats,
            ]);
        }
    }
```

Add the import at the top of the file:

```php
use App\Services\Stripe\CommissionVoidService;
```

- [ ] **Step 2: Commit**

```bash
git add app/Jobs/Stripe/ProcessCommissionPayoutsJob.php
git commit -m "feat(jobs): wire CommissionVoidService into daily payout cron"
```

---

## Task 7: Observer — Notify on Void

**Files:**
- Modify: `app/Observers/Core/CommissionLedgerEntryObserver.php` (~line 43)

- [ ] **Step 1: Add voided status handler to `updated()` method**

In the `updated()` method, add a case for `voided` after the `reversed` check (~line 49):

```php
            if ($entry->status === 'voided') {
                $this->notifyVoided($entry);
                return;
            }
```

- [ ] **Step 2: Add `notifyVoided()` method**

Add after the existing `notifyReversed()` method (~line 95):

```php
    private function notifyVoided(CommissionLedgerEntry $entry): void
    {
        $affiliateId = trim((string) ($entry->affiliate_professional_id ?? ''));
        if ($affiliateId === '') {
            return;
        }

        $amount = $this->formatMoney((int) ($entry->amount_cents ?? 0), (string) ($entry->currency_code ?? 'AUD'));

        $this->publisher->publish(
            professionalId: $affiliateId,
            frontendType: 'Warning',
            category: 'commissions',
            title: 'Commission forfeited',
            body: "A commission of {$amount} has been forfeited because your Stripe account was not connected in time.",
            dedupeKey: "commission.voided.{$entry->id}",
            ctaUrl: '/account/settings?section=stripe',
            retentionConfigKey: 'commission',
        );
    }
```

- [ ] **Step 3: Commit**

```bash
git add app/Observers/Core/CommissionLedgerEntryObserver.php
git commit -m "feat(observer): notify affiliate when commission is voided"
```

---

## Task 8: Webhook — Flush on Stripe Connect

**Files:**
- Modify: `app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php` (~line 100)

- [ ] **Step 1: Add flush logic to `handleAccountUpdated()`**

At the end of `handleAccountUpdated()`, after the status update block (~line 106), add:

```php
            // When an affiliate transitions to 'active', flush any held commissions
            // so they enter the normal payout pipeline immediately.
            if ($status === 'active' && $oldStatus !== 'active') {
                try {
                    app(CommissionVoidService::class)->flushHeldCommissions($professional);
                } catch (\Throwable $e) {
                    Log::warning('Failed to flush held commissions on Stripe connect', [
                        'professional_id' => $professional->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
```

Add the import at the top:

```php
use App\Services\Stripe\CommissionVoidService;
```

Note: The flush call is inside the existing `if ($professional->stripe_connect_status !== $status)` block, nested inside the status update. This ensures it only fires when the status actually changes to `active`.

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
git commit -m "feat(webhooks): flush held commissions when affiliate connects Stripe"
```

---

## Task 9: Tests

**Files:**
- Create: `tests/Feature/Stripe/CommissionVoidServiceTest.php`

The test file uses the existing SQLite in-memory approach. The `setupProfessionalsTable()` helper is available from the test suite. We need to create the `commission_ledger_entries` table in the test setup.

- [ ] **Step 1: Write the test file**

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupProfessionalsTable();

    DB::connection('pgsql')->statement('
        CREATE TABLE IF NOT EXISTS commerce.commission_ledger_entries (
            id TEXT PRIMARY KEY,
            shopify_order_id TEXT,
            brand_professional_id TEXT NOT NULL,
            affiliate_professional_id TEXT NOT NULL,
            entry_type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\',
            amount_cents INTEGER NOT NULL,
            currency_code TEXT NOT NULL DEFAULT \'AUD\',
            commission_rate REAL NOT NULL,
            rate_source TEXT NOT NULL,
            idempotency_key TEXT NOT NULL,
            calculation_metadata TEXT NOT NULL DEFAULT \'{}\',
            payout_id TEXT,
            voided_at TEXT,
            void_reason TEXT,
            occurred_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ');

    // Ensure stripe_grace_period_ends_at column exists on professionals table
    try {
        DB::connection('pgsql')->statement(
            'ALTER TABLE core.professionals ADD COLUMN stripe_grace_period_ends_at TEXT'
        );
    } catch (\Throwable) {
        // Column may already exist
    }
});

function seedAffiliate(string $id, string $stripeStatus = 'not_connected', ?string $gracePeriodEndsAt = null): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "affiliate-{$id}",
        'handle_lc' => "affiliate-{$id}",
        'display_name' => "Test Affiliate {$id}",
        'professional_type' => 'influencer',
        'status' => 'active',
        'stripe_connect_status' => $stripeStatus,
        'stripe_grace_period_ends_at' => $gracePeriodEndsAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function seedBrand(string $id): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => "Test Brand {$id}",
        'professional_type' => 'brand',
        'status' => 'active',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function seedCommission(string $id, string $brandId, string $affiliateId, int $amountCents = 1000, ?string $createdAt = null): void
{
    $now = $createdAt ?? now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_ledger_entries')->insert([
        'id' => $id,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'entry_type' => 'accrual',
        'status' => 'pending',
        'amount_cents' => $amountCents,
        'currency_code' => 'AUD',
        'commission_rate' => 15.0,
        'rate_source' => 'brand_default',
        'idempotency_key' => "test-{$id}",
        'calculation_metadata' => '{}',
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('voids commissions past the void window for unconnected affiliates', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->andReturnNull();
    $service = new CommissionVoidService($publisher);

    seedBrand('brand-1');
    seedAffiliate('aff-1', 'not_connected');

    // Commission created 31 days ago — past the 30-day void window
    seedCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(31)->toDateTimeString());

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(1);
    expect($stats['voided_cents'])->toBe(1000);

    $entry = CommissionLedgerEntry::find('c1');
    expect($entry->status)->toBe('voided');
    expect($entry->void_reason)->toBe('no_stripe_connected');
    expect($entry->voided_at)->not->toBeNull();
});

it('does not void commissions within the void window', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedBrand('brand-1');
    seedAffiliate('aff-1', 'not_connected');

    // Commission created 20 days ago — still within the 30-day window
    seedCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(20)->toDateTimeString());

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('pending');
});

it('does not void commissions for affiliates with active Stripe', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedBrand('brand-1');
    seedAffiliate('aff-1', 'active');

    seedCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(31)->toDateTimeString());

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('pending');
});

it('flushes held commissions to approved on Stripe connect', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedBrand('brand-1');
    seedAffiliate('aff-1', 'active');

    // Commission created 10 days ago — within void window
    seedCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(10)->toDateTimeString());
    // Commission created 35 days ago — past void window, should NOT be flushed
    seedCommission('c2', 'brand-1', 'aff-1', 2000, now()->subDays(35)->toDateTimeString());

    $affiliate = Professional::find('aff-1');
    $count = $service->flushHeldCommissions($affiliate);

    expect($count)->toBe(1);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('approved');
    expect(CommissionLedgerEntry::find('c2')->status)->toBe('pending');
});

it('identifies affiliates within grace period', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedAffiliate('aff-in', 'not_connected', now()->addDays(10)->toDateTimeString());
    seedAffiliate('aff-out', 'not_connected', now()->subDays(5)->toDateTimeString());

    $inGrace = Professional::find('aff-in');
    $outGrace = Professional::find('aff-out');

    expect($service->isInGracePeriod($inGrace))->toBeTrue();
    expect($service->isInGracePeriod($outGrace))->toBeFalse();
});

it('does not void commissions that already have a payout_id', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedBrand('brand-1');
    seedAffiliate('aff-1', 'not_connected');

    seedCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(31)->toDateTimeString());
    // Simulate that this commission is already linked to a payout
    DB::connection('pgsql')->table('commerce.commission_ledger_entries')
        ->where('id', 'c1')
        ->update(['payout_id' => 'some-payout-id']);

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `composer test -- tests/Feature/Stripe/CommissionVoidServiceTest.php`
Expected: All 6 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Stripe/CommissionVoidServiceTest.php
git commit -m "test(commissions): add CommissionVoidService test coverage"
```

---

## Task 10: Final Verification

- [ ] **Step 1: Run full test suite**

Run: `composer test`
Expected: All tests pass, no regressions.

- [ ] **Step 2: Review migration is correct**

Check the migration file reads cleanly and the SQL is valid PostgreSQL.

- [ ] **Step 3: Final commit (if any cleanup needed)**

```bash
git add -A
git commit -m "chore: cleanup from grace period + void implementation"
```
