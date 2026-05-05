<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use App\Models\Retail\CommissionPayout;
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

    public function __construct(private readonly NotificationPublisher $publisher)
    {
        $this->voidWindowDays = (int) config('sidest.store.commission_void_window_days', 30);
        $this->gracePeriodDays = (int) config('sidest.store.grace_period_days', 30);
    }

    /**
     * Find and void all pending commissions past their void window
     * for affiliates without active Stripe accounts.
     *
     * @return array{voided_count: int, voided_cents: int}
     */
    public function processVoidableCommissions(): array
    {
        $cutoff = now()->utc()->subDays($this->voidWindowDays);
        $stats = ['voided_count' => 0, 'voided_cents' => 0];

        // Chunk to avoid OOM on large result sets. Each entry is voided with
        // an optimistic lock so a concurrent flush won't be overwritten.
        CommissionLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('entry_type', 'accrual')
            ->where('status', 'pending')
            ->where('occurred_at', '<=', $cutoff)
            ->whereHas('affiliateProfessional', function ($q) {
                $q->where('stripe_connect_status', '!=', 'active');
            })
            ->chunkById(500, function ($entries) use (&$stats) {
                foreach ($entries as $entry) {
                    try {
                        if ($this->voidEntry($entry, 'no_stripe_connected')) {
                            $stats['voided_count']++;
                            $stats['voided_cents'] += $entry->amount_cents;
                        }
                    } catch (\Throwable $e) {
                        Log::error('Failed to void commission entry', [
                            'entry_id' => $entry->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if ($stats['voided_count'] > 0) {
            Log::info('Commission void processing complete', $stats);
        }

        return $stats;
    }

    /**
     * Void a single commission entry. Uses an optimistic lock (WHERE status = pending)
     * so a concurrent flush-to-approved from a Stripe webhook can't be overwritten.
     *
     * @return bool True if the entry was voided, false if it was already claimed.
     */
    public function voidEntry(CommissionLedgerEntry $entry, string $reason): bool
    {
        $updated = CommissionLedgerEntry::query()
            ->where('id', $entry->id)
            ->where('status', 'pending')
            ->whereNull('payout_id')
            ->update([
                'status' => 'voided',
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

        if ($updated === 0) {
            Log::info('Skipped voiding commission — status changed concurrently', [
                'entry_id' => $entry->id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Cancel commission payouts whose 60-day grace window has expired
     * without the affiliate connecting Stripe Connect.
     *
     * Scans pending/pending_funds payouts past their void_at via the
     * partial index commission_payouts_void_at_idx, then for each one
     * checks the affiliate's Stripe status and cancels if not active.
     * Linked ledger entries are marked voided in the same transaction so
     * the affiliate's ledger UI shows a consistent "expired" state — leaving
     * them linked to a cancelled payout would orphan them (never re-eligible
     * because the payout creator filters whereNull('payout_id')).
     *
     * Called from VoidExpiredPayoutsJob (nightly cron).
     *
     * @return array{cancelled_count: int, cancelled_cents: int, voided_entries: int}
     */
    public function processExpiredPayouts(): array
    {
        $stats = ['cancelled_count' => 0, 'cancelled_cents' => 0, 'voided_entries' => 0];

        // Pre-fetch inactive affiliate IDs so the main query uses set membership
        // (hash join) instead of a correlated per-row EXISTS probe against professionals.
        $inactiveAffiliateIds = Professional::query()
            ->where('stripe_connect_status', '!=', 'active')
            ->pluck('id')
            ->all();

        if ($inactiveAffiliateIds === []) {
            return $stats;
        }

        CommissionPayout::query()
            ->whereIn('status', ['pending', 'pending_funds'])
            ->where('void_at', '<', now())
            ->whereIn('affiliate_professional_id', $inactiveAffiliateIds)
            ->with('brandProfessional:id,display_name')
            ->orderBy('void_at')
            ->chunkById(200, function ($payouts) use (&$stats): void {
                foreach ($payouts as $payout) {
                    try {
                        $this->cancelExpiredPayout($payout, $stats);
                    } catch (\Throwable $e) {
                        Log::error('Failed to cancel expired payout', [
                            'payout_id' => $payout->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    /**
     * Cancel a single expired payout and void its linked ledger entries.
     * Optimistic lock guards against an in-flight ExecuteCommissionPayoutJob
     * advancing the status to 'collecting' between the SELECT and UPDATE.
     */
    private function cancelExpiredPayout(CommissionPayout $payout, array &$stats): void
    {
        $cancelled = false;

        DB::transaction(function () use ($payout, &$stats, &$cancelled): void {
            $updated = CommissionPayout::query()
                ->where('id', $payout->id)
                ->whereIn('status', ['pending', 'pending_funds'])
                ->update([
                    'status' => 'cancelled',
                    'failure_code' => 'grace_expired',
                    'failure_reason' => 'Affiliate did not connect Stripe Connect within the grace period.',
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                Log::info('Skipped cancelling expired payout — status changed concurrently', [
                    'payout_id' => $payout->id,
                ]);

                return;
            }

            $voidedEntries = CommissionLedgerEntry::query()
                ->where('payout_id', $payout->id)
                ->whereIn('status', ['pending', 'approved'])
                ->update([
                    'status' => 'voided',
                    'voided_at' => now(),
                    'void_reason' => 'payout_grace_expired',
                    'updated_at' => now(),
                ]);

            $stats['cancelled_count']++;
            $stats['cancelled_cents'] += (int) $payout->gross_commission_cents;
            $stats['voided_entries'] += (int) $voidedEntries;
            $cancelled = true;
        });

        if ($cancelled) {
            $this->publisher->publish(
                professionalId: $payout->affiliate_professional_id,
                frontendType: 'Warning',
                category: 'commissions',
                title: 'Commission payout expired',
                body: sprintf(
                    'Your %s payout from %s has been cancelled because Stripe Connect was not activated within the grace period.',
                    $this->formatMoney($payout->net_payout_cents, $payout->currency_code),
                    $payout->brandProfessional?->display_name ?? 'a brand',
                ),
                dedupeKey: "payout_voided.{$payout->id}",
                ctaUrl: '/account/settings?section=stripe',
                retentionConfigKey: 'commission',
            );
        }
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
        $stats['warnings_sent'] += $this->sendPerPayoutWarnings();

        return $stats;
    }

    /**
     * Day 20 and day 28 warnings for affiliates in their initial grace period.
     */
    private function sendSignupWarnings(): int
    {
        $sent = 0;

        // Collect affiliates in both warning windows (day 20 and day 28) then
        // batch-load their pending commission totals to avoid N+1 queries.
        $warningWindows = [
            'day20' => [
                'range' => [now()->addDays(10)->startOfDay(), now()->addDays(10)->endOfDay()],
                'title' => 'Connect Stripe — 10 days left',
                'body' => 'Connect your Stripe account within 10 days or your %s in pending earnings will be forfeited.',
            ],
            'day28' => [
                'range' => [now()->addDays(2)->startOfDay(), now()->addDays(2)->endOfDay()],
                'title' => 'Connect Stripe — 2 days left',
                'body' => '2 days left — connect Stripe now or your %s in pending earnings will be forfeited.',
            ],
        ];

        foreach ($warningWindows as $key => $window) {
            Professional::query()
                ->whereIn('professional_type', ['influencer', 'professional'])
                ->where('stripe_connect_status', '!=', 'active')
                ->whereNotNull('stripe_grace_period_ends_at')
                ->where('stripe_grace_period_ends_at', '>', now())
                ->whereBetween('stripe_grace_period_ends_at', $window['range'])
                ->chunkById(200, function ($affiliates) use (&$sent, $key, $window) {
                    // Batch-load pending amounts for the chunk to avoid N+1
                    $pendingAmounts = $this->getPendingCommissionCentsBatch(
                        $affiliates->pluck('id')->all()
                    );

                    foreach ($affiliates as $affiliate) {
                        $amount = $pendingAmounts[$affiliate->id] ?? 0;
                        if ($amount <= 0) {
                            continue;
                        }

                        $this->publisher->publish(
                            professionalId: $affiliate->id,
                            frontendType: 'Warning',
                            category: 'commissions',
                            title: $window['title'],
                            body: sprintf($window['body'], $this->formatMoney($amount, 'AUD')),
                            dedupeKey: "stripe_warning.{$key}.{$affiliate->id}",
                            ctaUrl: '/account/settings?section=stripe',
                            retentionConfigKey: 'commission',
                        );
                        $sent++;
                    }
                });
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
        $voidWindowDays = $this->voidWindowDays;

        // Chunk to avoid OOM on large result sets.
        CommissionLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('entry_type', 'accrual')
            ->where('status', 'pending')
            ->where('occurred_at', '<=', $warningCutoff->copy()->subDays($voidWindowDays))
            ->whereHas('affiliateProfessional', function ($q) {
                $q->where('stripe_connect_status', '!=', 'active')
                    ->where(function ($q2) {
                        $q2->whereNull('stripe_grace_period_ends_at')
                            ->orWhere('stripe_grace_period_ends_at', '<=', now());
                    });
            })
            ->with('affiliateProfessional:id,display_name')
            ->chunkById(500, function ($entries) use (&$sent, $voidWindowDays) {
                foreach ($entries as $entry) {
                    $voidDate = $entry->occurred_at->addDays($voidWindowDays);
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
            });

        return $sent;
    }

    /**
     * Per-payout warning: 10 and 2 days before each payout's void_at deadline.
     * Mirrors sendSignupWarnings but is keyed off payout.void_at — the actual
     * deadline enforced by processExpiredPayouts — so warnings align with enforcement.
     */
    private function sendPerPayoutWarnings(): int
    {
        $sent = 0;

        $warningWindows = [
            'day10' => [
                'range' => [now()->addDays(10)->startOfDay(), now()->addDays(10)->endOfDay()],
                'title' => 'Connect Stripe — 10 days left',
                'body' => 'Connect Stripe within 10 days or your %s payout will be cancelled.',
            ],
            'day2' => [
                'range' => [now()->addDays(2)->startOfDay(), now()->addDays(2)->endOfDay()],
                'title' => 'Connect Stripe — 2 days left',
                'body' => '2 days left — connect Stripe now or your %s payout will be cancelled.',
            ],
        ];

        // Pre-fetch once for both warning windows (day 10 and day 2) to avoid
        // a correlated subquery on each chunk pass.
        $inactiveAffiliateIds = Professional::query()
            ->where('stripe_connect_status', '!=', 'active')
            ->pluck('id')
            ->all();

        if ($inactiveAffiliateIds === []) {
            return $sent;
        }

        foreach ($warningWindows as $key => $window) {
            CommissionPayout::query()
                ->whereIn('status', ['pending', 'pending_funds'])
                ->whereBetween('void_at', $window['range'])
                ->whereIn('affiliate_professional_id', $inactiveAffiliateIds)
                ->chunkById(200, function ($payouts) use (&$sent, $key, $window): void {
                    foreach ($payouts as $payout) {
                        $this->publisher->publish(
                            professionalId: $payout->affiliate_professional_id,
                            frontendType: 'Warning',
                            category: 'commissions',
                            title: $window['title'],
                            body: sprintf($window['body'], $this->formatMoney($payout->net_payout_cents, $payout->currency_code)),
                            dedupeKey: "stripe_warning.payout.{$key}.{$payout->id}",
                            ctaUrl: '/account/settings?section=stripe',
                            retentionConfigKey: 'commission',
                        );
                        $sent++;
                    }
                });
        }

        return $sent;
    }

    /**
     * Flush eligible held commissions when an affiliate connects Stripe.
     * Called from the webhook handler when status transitions to 'active'.
     *
     * Finds all pending commissions for this affiliate where the void window
     * hasn't expired, then marks them approved so the normal payout cron
     * picks them up.
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
            ->where('occurred_at', '>', now()->utc()->subDays($this->voidWindowDays))
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

    /**
     * Batch-load pending commission totals for multiple affiliates in one query.
     *
     * @param  string[]  $affiliateIds
     * @return array<string, int> affiliate_id => total_pending_cents
     */
    /**
     * Voids up to $cap pending commission entries for a specific affiliate-brand pair.
     * Returns overflow: true (without voiding) when count exceeds cap — caller should
     * dispatch VoidPendingCommissionsForLinkJob instead.
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

    /**
     * @return array{count: int, total_cents: int}
     */
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

    private function getPendingCommissionCentsBatch(array $affiliateIds): array
    {
        if ($affiliateIds === []) {
            return [];
        }

        return CommissionLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('entry_type', 'accrual')
            ->where('status', 'pending')
            ->whereIn('affiliate_professional_id', $affiliateIds)
            ->groupBy('affiliate_professional_id')
            ->pluck(\Illuminate\Support\Facades\DB::raw('SUM(amount_cents)'), 'affiliate_professional_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function formatMoney(int $cents, string $currencyCode): string
    {
        $prefix = match (strtoupper($currencyCode)) {
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'AUD' => 'A$',
            default => strtoupper($currencyCode).' ',
        };

        return $prefix.number_format($cents / 100, 2, '.', ',');
    }
}
