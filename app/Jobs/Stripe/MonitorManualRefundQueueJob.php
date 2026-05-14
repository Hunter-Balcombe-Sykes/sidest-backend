<?php

namespace App\Jobs\Stripe;

use App\Models\Retail\CommissionPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Daily digest of CommissionPayouts that need manual refund attention from ops.
//
// Flag sources (any one drives inclusion):
//   - mid-flight refund (refund webhook arrived while payout was collecting/transferring) →
//     handleOrderRefund flagged needs_manual_refund=true
//   - post-payout Stripe Transfer Reversal failed (typically insufficient
//     connected-account balance) → clawbackCompletedPayout flagged the payout
//   - auto-refund-after-transfer-failure failed → CommissionPayoutService flagged it
//
// Output: a single Log::warning per run with the list of open payouts. Nightwatch
// is configured to alert on warning+ for the stripe queue, so ops sees this in their
// daily check. A future iteration can wire this into a dedicated Slack channel.
//
// Scheduled: daily at 08:00 UTC (after the morning VoidExpired sweep).
class MonitorManualRefundQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('stripe');
    }

    public function handle(): void
    {
        $open = CommissionPayout::query()
            ->where('needs_manual_refund', true)
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->with(['brandProfessional:id,display_name', 'affiliateProfessional:id,display_name'])
            ->orderBy('updated_at')
            ->get();

        if ($open->isEmpty()) {
            Log::info('payout.manual_refund_digest.empty');

            return;
        }

        $lines = $open->map(fn (CommissionPayout $p) => sprintf(
            '%s | %s → %s | status=%s gross=%d %s',
            $p->id,
            $p->brandProfessional?->display_name ?? '?',
            $p->affiliateProfessional?->display_name ?? '?',
            $p->status,
            (int) $p->gross_commission_cents,
            strtoupper((string) $p->currency_code),
        ))->all();

        Log::warning('payout.manual_refund_digest', [
            'count' => $open->count(),
            'payouts' => $lines,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('MonitorManualRefundQueueJob failed', [
            'message' => $e->getMessage(),
        ]);
    }
}
