<?php

namespace App\Jobs\Stripe;

use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Core. Processes a single commission payout (wallet debit → card charge → Stripe transfer).
// Dispatched by ProcessCommissionPayoutsJob for each eligible payout batch.
// Idempotent: processPayoutBatch resumes from the payout's current status so Horizon retries are safe.
class ExecuteCommissionPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * Backoff between Horizon retries in seconds.
     * Gives Stripe time to recover from transient outages between attempts.
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function __construct(public readonly string $payoutId) {}

    public function handle(CommissionPayoutService $payoutService): void
    {
        $payout = CommissionPayout::find($this->payoutId);

        if (! $payout || $payout->status === 'completed') {
            return;
        }

        $payoutService->processPayoutBatch($payout);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ExecuteCommissionPayoutJob exhausted all retries', [
            'payout_id' => $this->payoutId,
            'error'     => $e->getMessage(),
        ]);
    }
}
