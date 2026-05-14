<?php

namespace App\Jobs\Stripe;

use App\Models\Commerce\Order;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
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

        // Terminal-state guard: completed/failed/cancelled payouts must not re-enter
        // processPayoutBatch. The BECS T+2 settlement window exceeds Stripe's 24h
        // idempotency-key cache, so a job dispatched by the daily sweep that runs
        // AFTER the payment_intent.payment_failed webhook already marked the payout
        // 'failed' would otherwise fall through to a fresh paymentIntents->create
        // — Stripe forgets the original key past 24h and issues a duplicate PI,
        // charging the brand twice for commission they already disputed.
        if (! $payout || in_array($payout->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        $start = microtime(true);
        $result = $payoutService->processPayoutBatch($payout);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($result === null) {
            // Two distinct null cases: (a) transfer in-flight — webhook will complete it; (b) batch cancelled by
            // revalidatePayoutOrders because all linked orders became ineligible — terminal,
            // no webhook will ever arrive. Re-read status so the operations log distinguishes
            // them; otherwise a cancelled payout looks "stuck in transferring" indefinitely.
            $current = CommissionPayout::find($this->payoutId);
            if ($current && $current->status === 'cancelled') {
                Log::info('ExecuteCommissionPayoutJob cancelled by order revalidation', [
                    'payout_id' => $this->payoutId,
                    'duration_ms' => $durationMs,
                ]);
            } else {
                Log::info('ExecuteCommissionPayoutJob parked at processing — awaiting webhook', [
                    'payout_id' => $this->payoutId,
                    'duration_ms' => $durationMs,
                ]);
            }

            return;
        }

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

        $payout = CommissionPayout::find($this->payoutId);
        if (! $payout || in_array($payout->status, ['completed', 'failed'], true)) {
            return;
        }

        $payout->forceFill([
            'status' => 'failed',
            'failure_code' => 'job_exhausted',
            'failure_reason' => 'Payout job exhausted all Horizon retries after transient errors. '.$e->getMessage(),
            'processed_at' => now(),
        ])->save();

        // Release linked orders + their payout_items back to the sweep pool. Without this,
        // the daily sweep (whereNull('payout_id')) ignores the orders and the cpi_unique_order
        // partial index blocks the next batch from claiming them. Mirrors failPayout's cleanup.
        CommissionPayoutItem::where('payout_id', $this->payoutId)->delete();
        Order::where('payout_id', $this->payoutId)->update(['payout_id' => null]);
    }
}
