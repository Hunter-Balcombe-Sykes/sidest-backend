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
        $result = $payoutService->processPayoutBatch($payout);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($result === null) {
            // Two distinct null cases: (a) transfer in-flight — webhook or
            // ReconcileStuckTransferringPayoutsJob will complete it; (b) batch cancelled by
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
                Log::info('ExecuteCommissionPayoutJob parked at transferring — awaiting webhook', [
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

        $walletDebitCents = (int) ($payout->wallet_debit_cents ?? 0);
        $failureReason = 'Payout job exhausted all Horizon retries after transient errors. '
            .$e->getMessage();
        $updates = ['status' => 'failed', 'failure_code' => 'job_exhausted'];

        // If retries exhausted while still in 'collecting', the PaymentIntent was never
        // successfully created — there is no charge to match the wallet debit. Reverse it
        // automatically so the brand's balance is correct without manual intervention.
        // Also clear wallet_debit_cents so retryPayout() starts a fresh debit rather than
        // resuming from 'collecting' (which would skip the debit against a restored balance).
        if ($payout->status === 'collecting' && $walletDebitCents > 0) {
            app(CommissionPayoutService::class)->creditBrandManualBalance(
                (string) $payout->brand_professional_id,
                $walletDebitCents,
                strtoupper((string) $payout->currency_code)
            );
            $updates['wallet_debit_cents'] = 0;
            $failureReason = 'Payout job exhausted all retries — wallet debit of '
                .$walletDebitCents.' cents reversed automatically. '
                .$e->getMessage();

            Log::notice('ExecuteCommissionPayoutJob reversed wallet debit after retry exhaustion', [
                'payout_id' => $this->payoutId,
                'reversed_cents' => $walletDebitCents,
            ]);
        }

        $payout->forceFill(array_merge($updates, ['failure_reason' => $failureReason]))->save();
    }
}
