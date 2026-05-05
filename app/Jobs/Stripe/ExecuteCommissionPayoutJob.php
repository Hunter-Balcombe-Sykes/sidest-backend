<?php

namespace App\Jobs\Stripe;

use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Core. Processes a single commission payout (wallet debit → card charge → Stripe transfer).
// Dispatched by ProcessCommissionPayoutsJob for each eligible payout batch.
// Idempotent: processPayoutBatch resumes from the payout's current status so Horizon retries are safe.
// ShouldBeUnique prevents two workers racing on the same payout if the daily cron re-dispatches
// a job that is already queued or processing (e.g. waiting on a backoff delay).
class ExecuteCommissionPayoutJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    // Uniqueness lock TTL — slightly longer than timeout so the lock outlives the job.
    public int $uniqueFor = 180;

    /**
     * Backoff between Horizon retries in seconds.
     * Gives Stripe time to recover from transient outages between attempts.
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function __construct(public readonly string $payoutId)
    {
        $this->onQueue('stripe');
    }

    public function uniqueId(): string
    {
        return $this->payoutId;
    }

    public function handle(CommissionPayoutService $payoutService): void
    {
        $payout = CommissionPayout::find($this->payoutId);

        if (! $payout || $payout->status === 'completed') {
            return;
        }

        $start = microtime(true);
        $payoutService->processPayoutBatch($payout);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        // Alert threshold: 30s = 25% of job timeout. Nightwatch captures warning+
        // level logs — configure an alert on this message to catch batches trending
        // toward the 120s timeout before they start failing.
        if ($durationMs > 30_000) {
            Log::warning('ExecuteCommissionPayoutJob slow payout batch', [
                'payout_id' => $this->payoutId,
                'duration_ms' => $durationMs,
                'attempt' => $this->attempts(),
            ]);
        } else {
            Log::info('ExecuteCommissionPayoutJob completed', [
                'payout_id' => $this->payoutId,
                'duration_ms' => $durationMs,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        // Forward to Nightwatch so this payout failure is observable by payout_id
        // as a named exception, not a generic "queue job failed" event.
        // Log::error gives a structured breadcrumb in cloud logs even if Nightwatch
        // is offline.
        report($e);

        Log::error('ExecuteCommissionPayoutJob exhausted all retries', [
            'payout_id' => $this->payoutId,
            'error' => $e->getMessage(),
        ]);

        // Transition the payout to `failed` so it surfaces in the staff dashboard
        // and unblocks the manual retry flow. Without this, a payout stuck in
        // `collecting` or `transferring` after all retries is invisible to staff
        // and the wallet debit has no error surface. wallet_debit_cents and
        // stripe_payment_intent_id on the record give staff enough context for
        // manual reconciliation.
        $payout = CommissionPayout::find($this->payoutId);
        if ($payout && ! in_array($payout->status, ['completed', 'failed'], true)) {
            $payout->forceFill([
                'status' => 'failed',
                'failure_code' => 'job_exhausted',
                'failure_reason' => 'Payout job exhausted all Horizon retries after transient errors. '
                    .'Check wallet_debit_cents and stripe_payment_intent_id for manual reconciliation. '
                    .$e->getMessage(),
            ])->save();
        }
    }
}
