<?php

namespace App\Jobs\Stripe;

use App\Models\Retail\CommissionPayout;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

// Daily backstop (07:30 UTC) for payouts stuck in `transferring` beyond 6 hours.
// ExecuteCommissionPayoutJob marks a payout `transferring` after calling
// Transfer.create. If the `transfer.paid` Connect webhook never arrives
// (missed delivery, retries exhausted), the payout stays stuck indefinitely.
// This job fetches each stuck Transfer from Stripe and applies the terminal state.
class ReconcileStuckTransferringPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Single attempt: idempotent per Transfer, safe to re-schedule tomorrow.
    public int $tries = 1;

    // Generous timeout for large payout backlogs (chunk * Stripe round-trips).
    public int $timeout = 300;

    public function __construct(private readonly ?StripeClient $stripe = null)
    {
        $this->onQueue('stripe');
    }

    public function handle(): void
    {
        $stripe = $this->stripe ?? new StripeClient(array_filter([
            'api_key' => config('services.stripe.secret_key'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));
        $analytics = app(AnalyticsCacheService::class);

        CommissionPayout::query()
            ->where('status', 'transferring')
            ->where('updated_at', '<', now()->subHours(6))
            ->whereNotNull('stripe_transfer_id')
            ->chunkById(100, function ($payouts) use ($stripe, $analytics): void {
                foreach ($payouts as $payout) {
                    $this->reconcileOne($payout, $stripe, $analytics);
                }
            });
    }

    private function reconcileOne(CommissionPayout $payout, StripeClient $stripe, AnalyticsCacheService $analytics): void
    {
        try {
            $transfer = $stripe->transfers->retrieve($payout->stripe_transfer_id);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::warning('payout.reconcile.stripe_error', [
                'payout_id' => $payout->id,
                'transfer_id' => $payout->stripe_transfer_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        match ($transfer->status ?? null) {
            'paid' => $this->markCompleted($payout, $analytics),
            'failed' => $this->markFailed($payout, $transfer, $analytics),
            // 'pending' = still in-flight; leave it for the next daily run.
            'pending' => null,
            default => Log::warning('payout.reconcile.unknown_status', [
                'payout_id' => $payout->id,
                'transfer_status' => $transfer->status ?? null,
            ]),
        };
    }

    private function markCompleted(CommissionPayout $payout, AnalyticsCacheService $analytics): void
    {
        $payout->forceFill([
            'status' => 'completed',
            'transfer_completed_at' => now(),
            'processed_at' => now(),
        ])->save();

        // Bump analytics cache for both parties so dashboards reflect the settled payout.
        if ($payout->affiliate_professional_id) {
            $analytics->bumpAnalyticsVersion($payout->affiliate_professional_id);
        }

        if ($payout->brand_professional_id) {
            $analytics->bumpAnalyticsVersion($payout->brand_professional_id);
        }

        Log::info('payout.reconcile.completed', ['payout_id' => $payout->id]);
    }

    /**
     * Mark a payout failed after the Stripe Transfer is confirmed failed.
     * failure_category is derived from Stripe's failure_code via
     * CommissionPayoutService::categorizeTransferFailure — Transfer failures can
     * be platform-side (insufficient_funds), affiliate-side (account_closed),
     * or config-side (currency_not_supported); hardcoding one category misled
     * ops triage.
     */
    private function markFailed(CommissionPayout $payout, object $transfer, AnalyticsCacheService $analytics): void
    {
        $stripeFailureCode = $transfer->failure_code ?? null;
        $payout->forceFill([
            'status' => 'failed',
            'failure_code' => 'transfer_failed_reconciliation',
            'failure_reason' => 'Stripe Transfer failed; detected by reconciliation job',
            'failure_category' => CommissionPayoutService::categorizeTransferFailure($stripeFailureCode),
            'stripe_error_code' => $stripeFailureCode,
            'stripe_error_message' => $transfer->failure_message ?? null,
            'processed_at' => now(),
        ])->save();

        if ($payout->affiliate_professional_id) {
            $analytics->bumpAnalyticsVersion($payout->affiliate_professional_id);
        }

        Log::warning('payout.reconcile.failed', ['payout_id' => $payout->id]);
    }
}
