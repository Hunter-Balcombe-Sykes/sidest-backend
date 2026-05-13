<?php

namespace App\Jobs\Stripe;

use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\RateLimitException;

// Batch-processes all eligible commission payouts via CommissionPayoutService.
// Scheduled: hourly via routes/console.php.
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

    public function handle(CommissionPayoutService $payoutService): void
    {
        Log::info('Starting commission payout processing', [
            'attempt' => $this->attempts(),
        ]);

        try {
            $payoutStats = $payoutService->processEligiblePayouts();
        } catch (RateLimitException $e) {
            // Stripe 429: back off exponentially before requeueing so we don't
            // amplify API pressure across concurrent brand payouts.
            // release() avoids burning a retry attempt against a transient throttle.
            $delay = min(120 * (2 ** ($this->attempts() - 1)), 600);
            Log::warning('Stripe rate limit hit in payout orchestration, requeueing with backoff', [
                'delay_seconds' => $delay,
                'attempt' => $this->attempts(),
            ]);
            $this->release($delay);

            return;
        }

        Log::info('Commission payout processing complete', $payoutStats);

        // Void/warning operations moved to the daily VoidableCommissionsAndWarningsJob.
        // They operate on 30-day windows, so hourly cadence was wasted load.
    }

    public function failed(\Throwable $e): void
    {
        // Forward to Nightwatch so the daily payout orchestration failure
        // is observable as a named exception, not a generic queue event.
        // Log::error gives a structured breadcrumb in cloud logs even if
        // Nightwatch is offline.
        report($e);

        Log::error('Commission payout job failed after all retries', [
            'message' => $e->getMessage(),
        ]);
    }
}
