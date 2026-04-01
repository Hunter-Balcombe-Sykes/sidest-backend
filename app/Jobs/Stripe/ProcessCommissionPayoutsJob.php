<?php

namespace App\Jobs\Stripe;

use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCommissionPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function handle(CommissionPayoutService $service): void
    {
        Log::info('Starting commission payout processing');

        $stats = $service->processEligiblePayouts();

        Log::info('Commission payout processing complete', $stats);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Commission payout job failed', [
            'message' => $e->getMessage(),
        ]);
    }
}
