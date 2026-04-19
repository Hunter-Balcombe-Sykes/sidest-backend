<?php

namespace App\Jobs\Stripe;

use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Core. Batch-processes all eligible commission payouts via CommissionPayoutService. Runs on daily cron.
class ProcessCommissionPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Retry on transient failures (DB blip, Stripe timeout before the call
    // lands, queue worker OOM). Idempotency comes from two existing guards:
    //   1. Phase 1 of processEligiblePayouts sweeps any batch in status=pending
    //      and re-runs processPayoutBatch on it. Most partial failures land
    //      in pending via markPendingFunding() so retries pick them up cleanly.
    //   2. Phase 2 filters accrual entries on whereNull('payout_id'). Once a
    //      batch is created and linked, those entries are skipped on re-entry
    //      so we can't double-create a batch for the same ledger rows.
    // A retry will NOT resume a batch stuck mid-collect (wallet debited but
    // status not yet updated to 'pending'); that rare case still needs manual
    // intervention and is covered by the failure log + future audit dashboard.
    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Backoff between retries in seconds. 1 minute for the first retry
     * (typical network blip), 3 minutes for the second (longer Stripe outage).
     */
    public function backoff(): array
    {
        return [60, 180];
    }

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

    public function failed(\Throwable $e): void
    {
        Log::error('Commission payout job failed after all retries', [
            'message' => $e->getMessage(),
        ]);
    }
}
