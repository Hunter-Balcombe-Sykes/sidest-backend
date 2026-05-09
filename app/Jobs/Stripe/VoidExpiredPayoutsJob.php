<?php

namespace App\Jobs\Stripe;

use App\Models\Retail\CommissionPayout;
use App\Notifications\Affiliate\AffiliatePayoutGraceWarningNotification;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Cancels commission payouts whose 60-day grace window has expired without
// the affiliate connecting Stripe Connect.
//
// Backstop for the UI promise on the affiliate dashboard ("payout will be
// voided in N days if you don't connect Stripe"). Without this job the
// per-payout void_at column was being written but never enforced — the
// older 30-day ledger-entry void path is unrelated and operates one layer
// below. Closes #CR-003.
// Scheduled: daily at 07:00 UTC via routes/console.php.
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
        $this->fireGraceWarnings();

        $stats = $voidService->processExpiredPayouts();

        // Always emit a heartbeat — silence here would mask a stuck scheduler.
        // Promote to notice when real cancellations land so they stand out
        // from routine zero-action passes (matches markPendingFunding's pattern).
        if ($stats['cancelled_count'] > 0 || $stats['voided_entries'] > 0) {
            Log::notice('Expired payout void processing complete', $stats);
        } else {
            Log::info('Expired payout void processing complete', $stats);
        }
    }

    /**
     * Send T-30/T-7/T-1 grace warnings to affiliates who haven't connected Stripe.
     * Tags are written to grace_notifications_sent JSONB to prevent duplicate sends.
     */
    private function fireGraceWarnings(): void
    {
        foreach ([30, 7, 1] as $daysOut) {
            $tag = 'T-' . $daysOut;
            $windowStart = now()->addDays($daysOut)->startOfDay();
            $windowEnd = now()->addDays($daysOut)->endOfDay();

            $candidates = CommissionPayout::query()
                ->whereIn('status', ['pending', 'pending_funds'])
                ->whereBetween('void_at', [$windowStart, $windowEnd])
                ->whereDoesntHave('affiliateProfessional', fn ($q) =>
                    $q->where('stripe_connect_status', 'active'))
                ->get()
                ->filter(fn ($p) => ! in_array($tag, $p->grace_notifications_sent ?? [], true));

            foreach ($candidates as $payout) {
                $payout->affiliateProfessional?->notify(
                    new AffiliatePayoutGraceWarningNotification($payout, $daysOut)
                );

                $sent = $payout->grace_notifications_sent ?? [];
                $sent[] = $tag;
                $payout->forceFill(['grace_notifications_sent' => array_values(array_unique($sent))])->save();
            }
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
