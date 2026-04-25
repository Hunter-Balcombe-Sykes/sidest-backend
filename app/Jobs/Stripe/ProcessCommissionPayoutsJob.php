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

    // This job is now a lightweight orchestrator: it creates payout batches and
    // dispatches one ExecuteCommissionPayoutJob per batch. Stripe API calls happen
    // inside those child jobs, not here — so the timeout can be short.
    //
    // Retry guards:
    //   1. processEligiblePayouts re-dispatches any pending/collecting/transferring
    //      batches, so transient failures in child jobs are picked up next run.
    //   2. createPayoutBatch filters accrual entries on whereNull('payout_id') so
    //      we can't double-create a batch for the same ledger rows.
    //   3. ExecuteCommissionPayoutJob uses idempotent resume logic in processPayoutBatch
    //      — each step checks the payout's current status before executing.
    public int $tries = 3;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('stripe');
    }

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
