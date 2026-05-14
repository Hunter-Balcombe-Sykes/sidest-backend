<?php

namespace App\Jobs\Stripe;

use App\Services\Stripe\CommissionVoidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Daily sweep that voids stale commissions and emits grace-period warnings.
// Previously these operations were called from ProcessCommissionPayoutsJob
// (hourly), but they're keyed on 30-day windows so hourly cadence was wasted
// load — once a day at 06:00 UTC is the right granularity.
//
// Three operations run in sequence:
//   1. processVoidableCommissions — void orders past their window for
//      affiliates without active Stripe Connect
//   2. processBrandUnfundedCommissions — void orders past their window for
//      brands with no payment method on file
//   3. sendGracePeriodWarnings — emit day-20/day-28 grace-period and
//      per-commission void-window warnings
//
// Schedule: routes/console.php at 06:00 UTC (well before the hourly payout
// cron at the top of the hour, so any voided rows are excluded from
// processEligiblePayouts on the next run).
class VoidableCommissionsAndWarningsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // No backoff — tries=1 means no retry, so backoff is moot, but required for hygiene.
    public int $backoff = 0;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('stripe');
    }

    public function handle(CommissionVoidService $voidService): void
    {
        $voidStats = $voidService->processVoidableCommissions();
        $brandVoidStats = $voidService->processBrandUnfundedCommissions();
        $warningStats = $voidService->sendGracePeriodWarnings();

        Log::info('VoidableCommissionsAndWarningsJob complete', [
            'affiliate_void' => $voidStats,
            'brand_void' => $brandVoidStats,
            'warnings' => $warningStats,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('VoidableCommissionsAndWarningsJob failed', [
            'message' => $e->getMessage(),
        ]);
    }
}
