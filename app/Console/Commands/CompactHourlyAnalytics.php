<?php

namespace App\Console\Commands;

use App\Jobs\Analytics\RebuildBookingDailyAggregatesJob;
use App\Jobs\Analytics\RebuildCommerceDailyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteDailyAggregatesJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

// V2: Compacts hourly analytics older than 24h into daily aggregates. Runs on schedule to control table size.
class CompactHourlyAnalytics extends Command
{
    protected $signature = 'sidest:analytics:compact-hourly
        {--dry-run : Show work without mutating data}
        {--chunk-size=500 : Max jobs per batch (default 500)}
        {--domains=all : all,commerce,site,booking (comma-separated)}';

    protected $description = 'Compacts hourly analytics older than 24h into daily aggregates.';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk-size'));
        $domains   = $this->resolveDomains((string) $this->option('domains'));
        $cutoff    = Carbon::now()->utc()->subHours(24)->startOfHour();

        $this->info('Cutoff hour: '.$cutoff->toIso8601String());
        $this->line('Domains: '.implode(', ', $domains));
        if ($dryRun) {
            $this->warn('Dry run mode is enabled. No rows will be deleted or rebuilt.');
        }

        if (in_array('commerce', $domains, true)) {
            $this->compactCommerce($cutoff, $dryRun, $chunkSize);
        }

        if (in_array('site', $domains, true)) {
            $this->compactSite($cutoff, $dryRun, $chunkSize);
        }

        if (in_array('booking', $domains, true)) {
            $this->compactBooking($cutoff, $dryRun, $chunkSize);
        }

        $this->info('Hourly analytics compaction complete.');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function resolveDomains(string $domainsRaw): array
    {
        $parts = collect(explode(',', strtolower(trim($domainsRaw))))
            ->map(static fn (string $v): string => trim($v))
            ->filter()
            ->values();

        if ($parts->isEmpty() || $parts->contains('all')) {
            return ['commerce', 'site', 'booking'];
        }

        $allowed = ['commerce', 'site', 'booking'];

        return $parts
            ->filter(static fn (string $v): bool => in_array($v, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    private function compactCommerce(Carbon $cutoff, bool $dryRun, int $chunkSize): void
    {
        $staleBrandDayCount = DB::scalar(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM analytics.brand_metrics_hourly
                WHERE hour_start < ?
                GROUP BY brand_professional_id, (hour_start AT TIME ZONE timezone)::date
            ) sub",
            [$cutoff]
        );

        $staleAffiliateDayCount = DB::scalar(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM analytics.professional_metrics_hourly
                WHERE hour_start < ?
                GROUP BY affiliate_professional_id, (hour_start AT TIME ZONE timezone)::date
            ) sub",
            [$cutoff]
        );

        $this->line("Commerce brand-day rebuild keys: {$staleBrandDayCount}");
        $this->line("Commerce affiliate-day rebuild keys: {$staleAffiliateDayCount}");

        // Single JOIN replaces N+1: fetches all (brand, affiliate, day) triples in one query.
        // Streams with lazy() so memory stays O(chunk_size) regardless of row count.
        if (! $dryRun) {
            $batchCount = 0;
            DB::table('analytics.brand_metrics_hourly as bmh')
                ->join(
                    'commerce.commission_ledger_entries as cle',
                    fn ($join) => $join
                        ->on('cle.brand_professional_id', '=', 'bmh.brand_professional_id')
                        ->whereRaw('cle.occurred_at::date = (bmh.hour_start AT TIME ZONE bmh.timezone)::date')
                )
                ->where('bmh.hour_start', '<', $cutoff)
                ->selectRaw('bmh.brand_professional_id')
                ->selectRaw('cle.affiliate_professional_id')
                ->selectRaw('(bmh.hour_start AT TIME ZONE bmh.timezone)::date as day')
                ->distinct()
                ->lazy()
                ->chunk($chunkSize)
                ->each(function (LazyCollection $chunk, int $chunkIndex) use (&$batchCount): void {
                    $jobs = $chunk->map(
                        static fn ($row) => new RebuildCommerceDailyAggregatesJob(
                            (string) $row->brand_professional_id,
                            (string) $row->affiliate_professional_id,
                            (string) $row->day
                        )
                    )->values()->all();

                    Bus::batch($jobs)
                        ->name("commerce-daily-compact:chunk-{$chunkIndex}")
                        ->allowFailures()
                        ->dispatch();

                    $batchCount++;
                });

            $this->line("Commerce daily batches dispatched: {$batchCount}");
        }

        $brandRows     = DB::table('analytics.brand_metrics_hourly')->where('hour_start', '<', $cutoff);
        $affiliateRows = DB::table('analytics.professional_metrics_hourly')->where('hour_start', '<', $cutoff);

        $brandCount     = (clone $brandRows)->count();
        $affiliateCount = (clone $affiliateRows)->count();

        if (! $dryRun) {
            $brandRows->delete();
            $affiliateRows->delete();
        }

        $this->line("Commerce hourly rows aged out: brand={$brandCount}, affiliate={$affiliateCount}");
    }

    private function compactSite(Carbon $cutoff, bool $dryRun, int $chunkSize): void
    {
        $staleDayCount = DB::scalar(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM analytics.site_metrics_hourly
                WHERE hour_start < ?
                GROUP BY professional_id, (hour_start AT TIME ZONE timezone)::date
            ) sub",
            [$cutoff]
        );

        $this->line("Site day rebuild keys: {$staleDayCount}");

        if (! $dryRun) {
            $batchCount = 0;
            DB::table('analytics.site_metrics_hourly')
                ->where('hour_start', '<', $cutoff)
                ->selectRaw('professional_id')
                ->selectRaw('(hour_start AT TIME ZONE timezone)::date as day')
                ->groupBy('professional_id', 'day')
                ->lazy()
                ->chunk($chunkSize)
                ->each(function (LazyCollection $chunk, int $chunkIndex) use (&$batchCount): void {
                    $jobs = $chunk->map(
                        static fn ($row) => new RebuildSiteDailyAggregatesJob(
                            (string) $row->professional_id,
                            (string) $row->day
                        )
                    )->values()->all();

                    Bus::batch($jobs)
                        ->name("site-daily-compact:chunk-{$chunkIndex}")
                        ->allowFailures()
                        ->dispatch();

                    $batchCount++;
                });

            $this->line("Site daily batches dispatched: {$batchCount}");
        }

        $staleRows = DB::table('analytics.site_metrics_hourly')
            ->where('hour_start', '<', $cutoff);

        $count = (clone $staleRows)->count();
        if (! $dryRun) {
            $staleRows->delete();
        }

        $this->line("Site hourly rows aged out: {$count}");
    }

    private function compactBooking(Carbon $cutoff, bool $dryRun, int $chunkSize): void
    {
        $staleDayCount = DB::scalar(
            "SELECT COUNT(*) FROM (
                SELECT 1 FROM analytics.booking_metrics_hourly
                WHERE hour_start < ?
                GROUP BY professional_id, (hour_start AT TIME ZONE timezone)::date
            ) sub",
            [$cutoff]
        );

        $this->line("Booking day rebuild keys: {$staleDayCount}");

        if (! $dryRun) {
            $batchCount = 0;
            DB::table('analytics.booking_metrics_hourly')
                ->where('hour_start', '<', $cutoff)
                ->selectRaw('professional_id')
                ->selectRaw('(hour_start AT TIME ZONE timezone)::date as day')
                ->groupBy('professional_id', 'day')
                ->lazy()
                ->chunk($chunkSize)
                ->each(function (LazyCollection $chunk, int $chunkIndex) use (&$batchCount): void {
                    $jobs = $chunk->map(
                        static fn ($row) => new RebuildBookingDailyAggregatesJob(
                            (string) $row->professional_id,
                            (string) $row->day
                        )
                    )->values()->all();

                    Bus::batch($jobs)
                        ->name("booking-daily-compact:chunk-{$chunkIndex}")
                        ->allowFailures()
                        ->dispatch();

                    $batchCount++;
                });

            $this->line("Booking daily batches dispatched: {$batchCount}");
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
