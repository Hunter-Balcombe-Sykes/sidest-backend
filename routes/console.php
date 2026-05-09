<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('partna:normalize-professional-types {--dry-run : Show count only without updating}', function () {
    $allowed = ['professional', 'influencer', 'brand'];

    $query = DB::table('professionals')->where(function ($builder) use ($allowed): void {
        $builder
            ->whereNull('professional_type')
            ->orWhereRaw('LOWER(TRIM(professional_type)) NOT IN (?, ?, ?)', $allowed);
    });

    $count = (clone $query)->count();

    if ((bool) $this->option('dry-run')) {
        $this->info("Would normalize {$count} professional record(s) to professional_type=professional.");

        return;
    }

    if ($count === 0) {
        $this->info('No professional records required normalization.');

        return;
    }

    $updated = $query->update([
        'professional_type' => 'professional',
        'updated_at' => now(),
    ]);

    $this->info("Normalized {$updated} professional record(s) to professional_type=professional.");
})->purpose('Normalize legacy professional_type values to professional.');

Schedule::command('partna:purge-soft-deletes')
    ->dailyAt('03:20')
    ->withoutOverlapping(600)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-soft-deletes');
    });

Schedule::command('partna:prune-notifications', ['--days' => 30])
    ->dailyAt('03:25')
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-notifications', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

Schedule::job(new \App\Jobs\Stripe\ProcessCommissionPayoutsJob)
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: process-commission-payouts');
    });

// Closes #CR-003: enforces the 60-day payout grace window the UI promises.
// Runs daily after payout processing. Voiding is keyed off void_at (60-day
// window) so hourly cadence would be noise — once daily is sufficient.
Schedule::job(new \App\Jobs\Stripe\VoidExpiredPayoutsJob)
    ->dailyAt('07:00')
    ->withoutOverlapping(600)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: void-expired-payouts');
    });

Schedule::command('partna:analytics:purge-raw-events')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-raw-events');
    });

Schedule::job(new \App\Jobs\Notifications\InviteExpirySweepJob)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: invite-expiry-sweep');
    });

Schedule::job(new \App\Jobs\Notifications\NudgeStuckOnboardingJob)
    ->dailyAt('09:00')
    ->withoutOverlapping(600)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: nudge-stuck-onboarding');
    });

Schedule::job(new \App\Jobs\Notifications\SendWeeklyAnalyticsNotificationJob)
    ->weeklyOn(1, '09:00') // Monday 9 AM UTC
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: send-weekly-analytics-notification');
    });

Schedule::command('queue:prune-failed --hours=72')
    ->daily()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-failed-jobs');
    });

// Snapshots queue throughput / runtime metrics into Redis so the Horizon
// Metrics tab has data to render. Without this, "Jobs per minute" and
// "Runtime" graphs stay flat-empty. Five minutes is Horizon's standard cadence.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: horizon-snapshot');
    });

// withoutOverlapping(2) matches the every-2-min cadence: if a run exceeds 2 min
// (should be rare now that both Twitch and Kick use batch endpoints), the next
// scheduler tick skips exactly one cycle rather than stacking.
Schedule::job(new \App\Jobs\Streaming\CheckStreamingLiveStatusJob)
    ->everyTwoMinutes()
    ->withoutOverlapping(2)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: check-streaming-live-status');
    });

// Daily reconciler: finds `transferring` payouts stuck > 6h and flips them
// to completed/failed by fetching Transfer.status from Stripe. Backstop for
// missed `transfer.paid` Connect webhooks (exhausted retries, delivery gaps).
Schedule::job(new \App\Jobs\Stripe\ReconcileStuckTransferringPayoutsJob)
    ->dailyAt('07:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: reconcile-stuck-transferring-payouts');
    });

// Phase 3 backstop reconciler. Cron expression is env-overridable: set
// PARTNA_RECONCILER_SCHEDULE='0 * * * *' for the first 60 days post-launch,
// then revert to the default daily-at-3am value.
Schedule::command('partna:reconcile-shopify-orders')
    ->cron(config('partna.reconciler.schedule', '0 3 * * *'))
    ->withoutOverlapping(60 * 60) // 1h overlap guard
    ->description('Backstop reconcile of Shopify orders against commerce.orders (Phase 3)')
    ->onFailure(function (): void {
        \Log::error('partna:reconcile-shopify-orders schedule failure');
    });
