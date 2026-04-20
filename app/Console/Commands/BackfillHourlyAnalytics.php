<?php

namespace App\Console\Commands;

use App\Jobs\Analytics\RebuildBookingHourlyAggregatesJob;
use App\Jobs\Analytics\RebuildSiteHourlyAggregatesJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

// V2: Backfills hourly analytics aggregates for trailing N hours. Used after outages or data corrections.
class BackfillHourlyAnalytics extends Command
{
    protected $signature = 'sidest:analytics:backfill-hourly
        {--hours=24 : Number of trailing hours to backfill (1-168)}
        {--domains=all : all,commerce,site,booking (comma-separated)}
        {--chunk-size=500 : Max professionals per batch (default 500)}';

    protected $description = 'Backfills hourly analytics aggregates from source-of-truth event/order data.';

    public function handle(): int
    {
        $hours     = max(1, min(168, (int) $this->option('hours')));
        $domains   = $this->resolveDomains((string) $this->option('domains'));
        $chunkSize = max(1, (int) $this->option('chunk-size'));

        $start        = Carbon::now()->utc()->subHours($hours - 1)->startOfHour();
        $endExclusive = Carbon::now()->utc()->addHour()->startOfHour();

        $hourBuckets = collect();
        $cursor      = $start->copy();
        while ($cursor->lt($endExclusive)) {
            $hourBuckets->push($cursor->copy()->toIso8601String());
            $cursor->addHour();
        }

        $this->info("Backfilling {$hourBuckets->count()} hours from {$start->toIso8601String()} to {$endExclusive->toIso8601String()}");
        $this->line('Domains: '.implode(', ', $domains));

        if (in_array('commerce', $domains, true)) {
            $this->backfillCommerce($hourBuckets, $start, $endExclusive, $chunkSize);
        }

        if (in_array('site', $domains, true)) {
            $this->backfillSite($hourBuckets, $start, $endExclusive, $chunkSize);
        }

        if (in_array('booking', $domains, true)) {
            $this->backfillBooking($hourBuckets, $start, $endExclusive, $chunkSize);
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
     * @param  Collection<int, string>  $hourBuckets  ISO8601 strings
     */
    private function backfillCommerce(Collection $hourBuckets, Carbon $start, Carbon $endExclusive, int $chunkSize): void
    {
        $this->line('Commerce backfill: V2 rebuild jobs not yet implemented, skipping.');
    }

    /**
     * @param  Collection<int, string>  $hourBuckets  ISO8601 strings
     */
    private function backfillSite(Collection $hourBuckets, Carbon $start, Carbon $endExclusive, int $chunkSize): void
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
            ->pluck('professional_id')
            ->map(static fn ($id): string => trim((string) $id))
            ->filter()
            ->values();

        if ($professionalIds->isEmpty()) {
            $this->line('Site backfill: no professionals found in range, skipping.');

            return;
        }

        $batchCount = 0;
        foreach ($hourBuckets as $hour) {
            $professionalIds->chunk($chunkSize)->each(function (Collection $chunk, int $chunkIndex) use ($hour, &$batchCount): void {
                $jobs = $chunk->map(
                    static fn (string $id) => new RebuildSiteHourlyAggregatesJob($id, $hour)
                )->all();

                Bus::batch($jobs)
                    ->name("site-hourly-backfill:{$hour}:chunk-{$chunkIndex}")
                    ->allowFailures()
                    ->dispatch();

                $batchCount++;
            });
        }

        $this->line("Site batches dispatched: hours={$hourBuckets->count()}, professionals={$professionalIds->count()}, batches={$batchCount}");
    }

    /**
     * @param  Collection<int, string>  $hourBuckets  ISO8601 strings
     */
    private function backfillBooking(Collection $hourBuckets, Carbon $start, Carbon $endExclusive, int $chunkSize): void
    {
        $professionalIds = DB::table('analytics.booking_events')
            ->select('professional_id')
            ->whereBetween('occurred_at', [$start, $endExclusive])
            ->distinct()
            ->pluck('professional_id')
            ->map(static fn ($id): string => trim((string) $id))
            ->filter()
            ->values();

        if ($professionalIds->isEmpty()) {
            $this->line('Booking backfill: no professionals found in range, skipping.');

            return;
        }

        $batchCount = 0;
        foreach ($hourBuckets as $hour) {
            $professionalIds->chunk($chunkSize)->each(function (Collection $chunk, int $chunkIndex) use ($hour, &$batchCount): void {
                $jobs = $chunk->map(
                    static fn (string $id) => new RebuildBookingHourlyAggregatesJob($id, $hour)
                )->all();

                Bus::batch($jobs)
                    ->name("booking-hourly-backfill:{$hour}:chunk-{$chunkIndex}")
                    ->allowFailures()
                    ->dispatch();

                $batchCount++;
            });
        }

        $this->line("Booking batches dispatched: hours={$hourBuckets->count()}, professionals={$professionalIds->count()}, batches={$batchCount}");
    }
}
