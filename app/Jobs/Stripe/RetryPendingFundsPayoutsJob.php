<?php

namespace App\Jobs\Stripe;

use App\Models\Commerce\WalletMovement;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Notifications\Brand\BrandPayoutFundingFailedNotification;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Runs daily at 07:15 UTC. Picks up pending_funds payouts whose next_retry_at has passed,
// calls retryPayout() to advance them, and terminates any that have hit 7 failures.
class RetryPendingFundsPayoutsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public const MAX_ATTEMPTS = 7;

    public function handle(?CommissionPayoutService $service = null): void
    {
        $service ??= app(CommissionPayoutService::class);

        CommissionPayout::query()
            ->where('status', 'pending_funds')
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->chunkById(50, function ($payouts) use ($service): void {
                foreach ($payouts as $payout) {
                    $this->processOne($payout, $service);
                }
            });
    }

    private function processOne(CommissionPayout $payout, CommissionPayoutService $service): void
    {
        if ($payout->funding_failure_count >= self::MAX_ATTEMPTS) {
            $this->markTerminal($payout);

            return;
        }

        try {
            // retryPayout() bumps retry_count so the Stripe PI idempotency key changes;
            // calling processPayoutBatch() directly would reuse the failed PI key.
            $service->retryPayout($payout);
        } catch (\Throwable $e) {
            Log::warning('RetryPendingFundsPayoutsJob retry exception', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
            ]);
        }

        // After the attempt, check if the payout is still stuck — if so, notify the brand
        // so the dashboard banner refreshes. The notification's via() gates email vs
        // database-only based on funding_failure_count (see BrandPayoutFundingFailedNotification).
        $payout->refresh();
        if ($payout->status === 'pending_funds') {
            $payout->brandProfessional?->notify(
                new BrandPayoutFundingFailedNotification($payout, isTerminal: false)
            );
        }
    }

    private function markTerminal(CommissionPayout $payout): void
    {
        DB::transaction(function () use ($payout): void {
            // Re-fetch under lock so we don't race with a concurrent webhook/job.
            $payout = CommissionPayout::query()
                ->where('id', $payout->id)
                ->lockForUpdate()
                ->first();

            if (! $payout || $payout->status === 'failed') {
                return;
            }

            $brand = Professional::query()
                ->where('id', $payout->brand_professional_id)
                ->lockForUpdate()
                ->first();

            $payout->forceFill([
                'status' => 'failed',
                'failure_code' => 'brand_funding_exhausted',
                'failure_reason' => 'Card declined ' . self::MAX_ATTEMPTS . ' times; wallet credited back',
                'failure_category' => 'brand_funding',
            ])->save();

            // Credit the wallet back so the brand isn't out-of-pocket.
            if ($payout->wallet_debit_cents > 0 && $brand) {
                (new WalletMovement)->forceFill([
                    'professional_id' => $brand->id,
                    'direction' => 'credit',
                    'amount_cents' => $payout->wallet_debit_cents,
                    'currency_code' => $payout->currency_code,
                    'reason' => 'retry_refund',
                    'actor_type' => 'job',
                    'actor_id' => self::class,
                    'related_payout_id' => $payout->id,
                    'idempotency_key' => 'retry_refund:' . $payout->id,
                ])->save();

                Professional::where('id', $brand->id)
                    ->increment('stripe_manual_balance_cents', $payout->wallet_debit_cents);

                Log::notice('RetryPendingFundsPayoutsJob reversed wallet debit on terminal failure', [
                    'payout_id' => $payout->id,
                    'reversed_cents' => $payout->wallet_debit_cents,
                ]);
            }

            $brand?->notify(new BrandPayoutFundingFailedNotification($payout, isTerminal: true));
        });
    }
}
