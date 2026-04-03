<?php

namespace App\Console\Commands;

use App\Jobs\Analytics\RebuildBookingHourlyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteHourlyAggregatesJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// V2: Backfills hourly analytics aggregates for trailing N hours. Used after outages or data corrections.
class BackfillHourlyAnalytics extends Command
{
    protected $signature = 'sidest:analytics:backfill-hourly
        {--hours=24 : Number of trailing hours to backfill (1-168)}
        {--domains=all : all,commerce,site,booking (comma-separated)}';

    protected $description = 'Backfills hourly analytics aggregates from source-of-truth event/order data.';

    public function handle(): int
    {
        $hours = max(1, min(168, (int) $this->option('hours')));
        $domains = $this->resolveDomains((string) $this->option('domains'));

        $start = Carbon::now()->utc()->subHours($hours - 1)->startOfHour();
        $endExclusive = Carbon::now()->utc()->addHour()->startOfHour();

        $hourBuckets = collect();
        $cursor = $start->copy();
        while ($cursor->lt($endExclusive)) {
            $hourBuckets->push($cursor->copy()->toIso8601String());
            $cursor->addHour();
        }

        $this->info("Backfilling {$hourBuckets->count()} hours from {$start->toIso8601String()} to {$endExclusive->toIso8601String()}");
        $this->line('Domains: '.implode(', ', $domains));

        if (in_array('commerce', $domains, true)) {
            $this->backfillCommerce($hourBuckets, $start, $endExclusive);
        }

        if (in_array('site', $domains, true)) {
            $this->backfillSite($hourBuckets, $start, $endExclusive);
        }

        if (in_array('booking', $domains, true)) {
            $this->backfillBooking($hourBuckets, $start, $endExclusive);
        }

        $this->info('Hourly backfill jobs dispatched.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function resolveDomains(string $domainsRaw): array
    {
        $parts = collect(explode(',', strtolower(trim($domainsRaw))))
            ->map(static fn (string $value): string => trim($value))
            ->filter()
            ->values();

        if ($parts->isEmpty() || $parts->contains('all')) {
            return ['commerce', 'site', 'booking'];
        }

        $allowed = ['commerce', 'site', 'booking'];

        return $parts
            ->filter(static fn (string $value): bool => in_array($value, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, string>  $hourBuckets ISO8601 strings
     */
    private function backfillCommerce(Collection $hourBuckets, Carbon $start, Carbon $endExclusive): void
    {
        $this->line('Commerce backfill: V2 rebuild jobs not yet implemented, skipping.');
    }

    /**
     * @param  Collection<int, string>  $hourBuckets ISO8601 strings
     */
    private function backfillSite(Collection $hourBuckets, Carbon $start, Carbon $endExclusive): void
    {
        $professionalIds = DB::table('analytics.site_visits')
            ->select('professional_id')
            ->whereBetween('occurred_at', [$start, $endExclusive])
            ->union(
                DB::table('analytics.link_clicks')
                    ->select('professional_id')
                    ->whereBetween('occurred_at', [$start, $endExclusive])
            )
            ->distinct()
            ->lazy()
            ->map(static fn ($row): string => trim((string) $row->professional_id))
            ->filter();

        $count = 0;
        foreach ($professionalIds as $professionalId) {
            foreach ($hourBuckets as $hour) {
                RebuildSiteHourlyAggregatesJob::dispatch($professionalId, $hour);
            }
            $count++;
        }

        $this->line("Site jobs dispatched: professionals={$count}");
    }

    /**
     * @param  Collection<int, string>  $hourBuckets ISO8601 strings
     */
    private function backfillBooking(Collection $hourBuckets, Carbon $start, Carbon $endExclusive): void
    {
        $professionalIds = DB::table('analytics.booking_events')
            ->select('professional_id')
            ->whereBetween('occurred_at', [$start, $endExclusive])
            ->distinct()
            ->lazy()
            ->map(static fn ($row): string => trim((string) $row->professional_id))
            ->filter();

        $count = 0;
        foreach ($professionalIds as $professionalId) {
            foreach ($hourBuckets as $hour) {
                RebuildBookingHourlyAggregatesJob::dispatch($professionalId, $hour);
            }
            $count++;
        }

        $this->line("Booking jobs dispatched: professionals={$count}");
    }
}
