<?php

namespace App\Console\Commands;

use App\Jobs\Analytics\RebuildBookingDailyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteDailyAggregatesJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// V2: Compacts hourly analytics older than 24h into daily aggregates. Runs on schedule to control table size.
class CompactHourlyAnalytics extends Command
{
    protected $signature = 'sidest:analytics:compact-hourly {--dry-run : Show work without mutating data}';

    protected $description = 'Compacts hourly analytics older than 24h into daily aggregates.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->utc()->subHours(24)->startOfHour();

        $this->info('Cutoff hour: '.$cutoff->toIso8601String());
        if ($dryRun) {
            $this->warn('Dry run mode is enabled. No rows will be deleted or rebuilt.');
        }

        $this->compactCommerce($cutoff, $dryRun);
        $this->compactSite($cutoff, $dryRun);
        $this->compactBooking($cutoff, $dryRun);

        $this->info('Hourly analytics compaction complete.');

        return self::SUCCESS;
    }

    private function compactCommerce(Carbon $cutoff, bool $dryRun): void
    {
        $staleBrandDays = DB::table('analytics.brand_metrics_hourly')
            ->where('hour_start', '<', $cutoff)
            ->selectRaw('brand_professional_id')
            ->selectRaw("(hour_start AT TIME ZONE timezone)::date as day")
            ->groupBy('brand_professional_id', 'day')
            ->get();

        $staleAffiliateDays = DB::table('analytics.professional_metrics_hourly')
            ->where('hour_start', '<', $cutoff)
            ->selectRaw('affiliate_professional_id')
            ->selectRaw("(hour_start AT TIME ZONE timezone)::date as day")
            ->groupBy('affiliate_professional_id', 'day')
            ->get();

        $this->line("Commerce brand-day rebuild keys: {$staleBrandDays->count()}");
        $this->line("Commerce affiliate-day rebuild keys: {$staleAffiliateDays->count()}");

        $brandRows = DB::table('analytics.brand_metrics_hourly')
            ->where('hour_start', '<', $cutoff);
        $affiliateRows = DB::table('analytics.professional_metrics_hourly')
            ->where('hour_start', '<', $cutoff);

        $brandCount = (clone $brandRows)->count();
        $affiliateCount = (clone $affiliateRows)->count();

        if (! $dryRun) {
            $brandRows->delete();
            $affiliateRows->delete();
        }

        $this->line("Commerce hourly rows aged out: brand={$brandCount}, affiliate={$affiliateCount}");
    }

    private function compactSite(Carbon $cutoff, bool $dryRun): void
    {
        $staleDays = DB::table('analytics.site_metrics_hourly')
            ->where('hour_start', '<', $cutoff)
            ->selectRaw('professional_id')
            ->selectRaw("(hour_start AT TIME ZONE timezone)::date as day")
            ->groupBy('professional_id', 'day')
            ->get();

        $this->line("Site day rebuild keys: {$staleDays->count()}");

        if (! $dryRun) {
            foreach ($staleDays as $row) {
                RebuildSiteDailyAggregatesJob::dispatch((string) $row->professional_id, (string) $row->day);
            }
        }

        $staleRows = DB::table('analytics.site_metrics_hourly')
            ->where('hour_start', '<', $cutoff);

        $count = (clone $staleRows)->count();
        if (! $dryRun) {
            $staleRows->delete();
        }

        $this->line("Site hourly rows aged out: {$count}");
    }

    private function compactBooking(Carbon $cutoff, bool $dryRun): void
    {
        $staleDays = DB::table('analytics.booking_metrics_hourly')
            ->where('hour_start', '<', $cutoff)
            ->selectRaw('professional_id')
            ->selectRaw("(hour_start AT TIME ZONE timezone)::date as day")
            ->groupBy('professional_id', 'day')
            ->get();

        $this->line("Booking day rebuild keys: {$staleDays->count()}");

        if (! $dryRun) {
            foreach ($staleDays as $row) {
                RebuildBookingDailyAggregatesJob::dispatch((string) $row->professional_id, (string) $row->day);
            }
        }

        $staleRows = DB::table('analytics.booking_metrics_hourly')
            ->where('hour_start', '<', $cutoff);

        $count = (clone $staleRows)->count();
        if (! $dryRun) {
            $staleRows->delete();
        }

        $this->line("Booking hourly rows aged out: {$count}");
    }
}
