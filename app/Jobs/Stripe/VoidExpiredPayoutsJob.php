<?php

namespace App\Jobs\Stripe;

use App\Services\Stripe\CommissionVoidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Nightly cron: cancels commission payouts whose 60-day grace window
// has expired without the affiliate connecting Stripe Connect.
//
// Backstop for the UI promise on the affiliate dashboard ("payout will be
// voided in N days if you don't connect Stripe"). Without this job the
// per-payout void_at column was being written but never enforced — the
// older 30-day ledger-entry void path is unrelated and operates one layer
// below. Closes #CR-003.
class VoidExpiredPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Generous timeout: the partial index keeps the scan tight, but the
    // chunk loop voids ledger entries inside a transaction per payout.
    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('stripe');
    }

    /** Backoff between retries: short for the first, longer for the second. */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(CommissionVoidService $voidService): void
    {
        $stats = $voidService->processExpiredPayouts();

        if ($stats['cancelled_count'] > 0 || $stats['voided_entries'] > 0) {
            Log::info('Expired payout void processing complete', $stats);
        }
    }

    public function failed(\Throwable $e): void
    {
        // Forward to the global handler so Nightwatch logs the failure.
        // Log::error gives us a structured breadcrumb in cloud logs even
        // if Nightwatch is offline.
        report($e);

        Log::error('VoidExpiredPayoutsJob failed after all retries', [
            'message' => $e->getMessage(),
        ]);
    }
}
