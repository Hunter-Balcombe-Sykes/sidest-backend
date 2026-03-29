<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('comet:normalize-professional-types {--dry-run : Show count only without updating}', function () {
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

Schedule::command('comet:purge-soft-deletes')
    ->dailyAt('03:20')
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: purge-soft-deletes');
    });

Schedule::command('comet:prune-notifications', ['--days' => 30])
    ->dailyAt('03:25')
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: prune-notifications');
    });

Schedule::job(new \App\Jobs\Stripe\ProcessCommissionPayoutsJob())
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: process-commission-payouts');
    });

Schedule::command('comet:analytics:compact-hourly')
    ->hourly()
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: compact-hourly');
    });

Schedule::job(new \App\Jobs\Notifications\InviteExpirySweepJob())
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: invite-expiry-sweep');
    });

Schedule::job(new \App\Jobs\Notifications\SendWeeklyAnalyticsNotificationJob())
    ->weeklyOn(1, '09:00') // Monday 9 AM UTC
    ->withoutOverlapping()
    ->onFailure(function (): void {
        \Illuminate\Support\Facades\Log::error('Scheduled task failed: send-weekly-analytics-notification');
    });
