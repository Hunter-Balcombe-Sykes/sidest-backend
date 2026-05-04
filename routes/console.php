<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sidest:normalize-professional-types {--dry-run : Show count only without updating}', function () {
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

Schedule::command('sidest:purge-soft-deletes')
    ->dailyAt('03:20')
    ->withoutOverlapping(600)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-soft-deletes');
    });

Schedule::command('sidest:prune-notifications', ['--days' => 30])
    ->dailyAt('03:25')
    ->onFailure(function (?\Throwable $e = null): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-notifications', [
            'exception' => $e ? get_class($e) : null,
            'message' => $e?->getMessage(),
        ]);
    });

Schedule::job(new \App\Jobs\Stripe\ProcessCommissionPayoutsJob)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: process-commission-payouts');
    });

// Closes #CR-003: enforces the 60-day payout grace window the UI promises.
// Runs after the daily payout pass at 06:00 so any payouts that just
// transitioned out of 'pending' aren't candidates here.
Schedule::job(new \App\Jobs\Stripe\VoidExpiredPayoutsJob)
    ->dailyAt('07:00')
    ->withoutOverlapping(600)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: void-expired-payouts');
    });

Schedule::command('sidest:analytics:compact-hourly')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: compact-hourly');
    });

Schedule::command('sidest:analytics:purge-raw-events')
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

// withoutOverlapping(2) matches the every-2-min cadence: if a run exceeds 2 min
// (should be rare now that both Twitch and Kick use batch endpoints), the next
// scheduler tick skips exactly one cycle rather than stacking.
Schedule::job(new \App\Jobs\Streaming\CheckStreamingLiveStatusJob)
    ->everyTwoMinutes()
    ->withoutOverlapping(2)
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: check-streaming-live-status');
    });
