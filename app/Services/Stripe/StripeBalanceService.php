<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

// V2: Stripe Balance + upcoming Payouts wrapper scoped to an affiliate's connected account.
//
// All calls pass the `stripe_account` request option so the underlying SDK adds the
// Stripe-Account header — that's what scopes the request to the connected account
// instead of the platform account. Without it Stripe returns the platform balance,
// which is never what we want here.
//
// Cache wrapping is the controller's responsibility (CacheLockService::rememberLocked)
// — this service stays pure for testability.
class StripeBalanceService
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * Affiliate's available + pending balance, AUD only, normalised to cents.
     *
     * @return array{available_cents: int, pending_cents: int, instant_available_cents: int, currency_code: string}
     */
    public function forAffiliate(Professional $affiliate): array
    {
        if (! $affiliate->stripe_connect_account_id) {
            return $this->empty();
        }

        try {
            $balance = $this->stripe->balance->retrieve([], [
                'stripe_account' => $affiliate->stripe_connect_account_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('stripe.balance.retrieve_failed', [
                'account' => $affiliate->stripe_connect_account_id,
                'error' => $e->getMessage(),
            ]);

            return $this->empty();
        }

        return [
            'available_cents' => $this->sumAud($balance->available ?? []),
            'pending_cents' => $this->sumAud($balance->pending ?? []),
            'instant_available_cents' => $this->sumAud($balance->instant_available ?? []),
            'currency_code' => 'AUD',
        ];
    }

    /**
     * Affiliate's in-flight payouts (pending or in_transit).
     *
     * @return array<int, array{id: string, amount_cents: int, currency_code: string, status: string, arrival_date: ?string, method: ?string}>
     */
    public function upcomingFor(Professional $affiliate): array
    {
        if (! $affiliate->stripe_connect_account_id) {
            return [];
        }

        try {
            // Stripe's payouts.list status filter is single-value — fetch with no filter and
            // narrow in PHP. Limit=10 keeps the response bounded; affiliates rarely have more
            // than a handful in flight at once.
            $payouts = $this->stripe->payouts->all(
                ['limit' => 10],
                ['stripe_account' => $affiliate->stripe_connect_account_id],
            );
        } catch (\Throwable $e) {
            Log::warning('stripe.payouts.list_failed', [
                'account' => $affiliate->stripe_connect_account_id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $rows = [];
        foreach (($payouts->data ?? []) as $payout) {
            $status = (string) ($payout->status ?? '');
            if (! in_array($status, ['pending', 'in_transit'], true)) {
                continue;
            }

            $rows[] = [
                'id' => (string) ($payout->id ?? ''),
                'amount_cents' => (int) ($payout->amount ?? 0),
                'currency_code' => strtoupper((string) ($payout->currency ?? 'aud')),
                'status' => $status,
                'arrival_date' => isset($payout->arrival_date)
                    ? \Carbon\CarbonImmutable::createFromTimestamp($payout->arrival_date)->toIso8601String()
                    : null,
                'method' => isset($payout->method) ? (string) $payout->method : null,
            ];
        }

        return $rows;
    }

    /**
     * Affiliate's Stripe auto-payout schedule — pulled off the v1 Account read.
     *
     * Returns null if the schedule isn't available (recipient-only accounts, network failure,
     * unknown shape). Frontend renders a generic fallback in that case.
     *
     * @return array{interval: ?string, delay_days: ?int, weekly_anchor: ?string, monthly_anchor: ?int}|null
     */
    public function payoutScheduleFor(Professional $affiliate): ?array
    {
        if (! $affiliate->stripe_connect_account_id) {
            return null;
        }

        try {
            $account = $this->stripe->accounts->retrieve($affiliate->stripe_connect_account_id);
        } catch (\Throwable $e) {
            Log::warning('stripe.account.schedule_fetch_failed', [
                'account' => $affiliate->stripe_connect_account_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $schedule = $account->settings->payouts->schedule ?? null;
        if (! $schedule) {
            return null;
        }

        return [
            'interval' => isset($schedule->interval) ? (string) $schedule->interval : null,
            'delay_days' => isset($schedule->delay_days) ? (int) $schedule->delay_days : null,
            'weekly_anchor' => isset($schedule->weekly_anchor) ? (string) $schedule->weekly_anchor : null,
            'monthly_anchor' => isset($schedule->monthly_anchor) ? (int) $schedule->monthly_anchor : null,
        ];
    }

    /**
     * @param  iterable<object>  $buckets  Stripe Balance buckets — each has amount + currency fields.
     */
    private function sumAud(iterable $buckets): int
    {
        $total = 0;
        foreach ($buckets as $bucket) {
            $currency = strtolower((string) ($bucket->currency ?? ''));
            if ($currency !== 'aud') {
                continue;
            }
            $total += (int) ($bucket->amount ?? 0);
        }

        return $total;
    }

    /**
     * @return array{available_cents: int, pending_cents: int, instant_available_cents: int, currency_code: string}
     */
    private function empty(): array
    {
        return [
            'available_cents' => 0,
            'pending_cents' => 0,
            'instant_available_cents' => 0,
            'currency_code' => 'AUD',
        ];
    }
}
