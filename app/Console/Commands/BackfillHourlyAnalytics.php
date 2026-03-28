<?php

namespace App\Console\Commands;

use App\Services\Analytics\BookingAnalyticsAggregateService;
use App\Services\Analytics\SiteAnalyticsAggregateService;
use App\Services\Store\OrderAnalyticsHourlyAggregateService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillHourlyAnalytics extends Command
{
    protected $signature = 'comet:analytics:backfill-hourly
        {--hours=24 : Number of trailing hours to backfill (1-168)}
        {--domains=all : all,commerce,site,booking (comma-separated)}';

    protected $description = 'Backfills hourly analytics aggregates from source-of-truth event/order data.';

    public function handle(
        OrderAnalyticsHourlyAggregateService $commerceAggregates,
        SiteAnalyticsAggregateService $siteAggregates,
        BookingAnalyticsAggregateService $bookingAggregates
    ): int {
        $hours = max(1, min(168, (int) $this->option('hours')));
        $domains = $this->resolveDomains((string) $this->option('domains'));

        $start = Carbon::now()->utc()->subHours($hours - 1)->startOfHour();
        $endExclusive = Carbon::now()->utc()->addHour()->startOfHour();

        $hourBuckets = collect();
        $cursor = $start->copy();
        while ($cursor->lt($endExclusive)) {
            $hourBuckets->push($cursor->copy());
            $cursor->addHour();
        }

        $this->info("Backfilling {$hourBuckets->count()} hours from {$start->toIso8601String()} to {$endExclusive->toIso8601String()}");
        $this->line('Domains: '.implode(', ', $domains));

        if (in_array('commerce', $domains, true)) {
            $this->backfillCommerce($commerceAggregates, $hourBuckets, $start, $endExclusive);
        }

        if (in_array('site', $domains, true)) {
            $this->backfillSite($siteAggregates, $hourBuckets, $start, $endExclusive);
        }

        if (in_array('booking', $domains, true)) {
            $this->backfillBooking($bookingAggregates, $hourBuckets, $start, $endExclusive);
        }

        $this->info('Hourly backfill complete.');

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
     * @param  Collection<int, Carbon>  $hourBuckets
     */
    private function backfillCommerce(
        OrderAnalyticsHourlyAggregateService $commerceAggregates,
        Collection $hourBuckets,
        Carbon $start,
        Carbon $endExclusive
    ): void {
        $brandIds = DB::table('retail.orders')
            ->where('ordered_at', '>=', $start)
            ->where('ordered_at', '<', $endExclusive)
            ->whereNotNull('brand_professional_id')
            ->pluck('brand_professional_id')
            ->merge(
                DB::table('retail.commission_ledger_entries')
                    ->where('occurred_at', '>=', $start)
                    ->where('occurred_at', '<', $endExclusive)
                    ->whereNotNull('brand_professional_id')
                    ->pluck('brand_professional_id')
            )
            ->map(static fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        $affiliateIds = DB::table('retail.orders')
            ->where('ordered_at', '>=', $start)
            ->where('ordered_at', '<', $endExclusive)
            ->whereNotNull('affiliate_professional_id')
            ->pluck('affiliate_professional_id')
            ->merge(
                DB::table('retail.commission_ledger_entries')
                    ->where('occurred_at', '>=', $start)
                    ->where('occurred_at', '<', $endExclusive)
                    ->whereNotNull('affiliate_professional_id')
                    ->pluck('affiliate_professional_id')
            )
            ->map(static fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        $this->line("Commerce entities: brands={$brandIds->count()}, affiliates={$affiliateIds->count()}");

        foreach ($brandIds as $brandId) {
            foreach ($hourBuckets as $hour) {
                $commerceAggregates->rebuildBrandHour($brandId, $hour);
            }
        }

        foreach ($affiliateIds as $affiliateId) {
            foreach ($hourBuckets as $hour) {
                $commerceAggregates->rebuildProfessionalHour($affiliateId, $hour);
            }
        }
    }

    /**
     * @param  Collection<int, Carbon>  $hourBuckets
     */
    private function backfillSite(
        SiteAnalyticsAggregateService $siteAggregates,
        Collection $hourBuckets,
        Carbon $start,
        Carbon $endExclusive
    ): void {
        $professionalIds = DB::table('analytics.site_visits')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $endExclusive)
            ->pluck('professional_id')
            ->merge(
                DB::table('analytics.link_clicks')
                    ->where('occurred_at', '>=', $start)
                    ->where('occurred_at', '<', $endExclusive)
                    ->pluck('professional_id')
            )
            ->map(static fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        $this->line("Site entities: professionals={$professionalIds->count()}");

        foreach ($professionalIds as $professionalId) {
            foreach ($hourBuckets as $hour) {
                $siteAggregates->rebuildProfessionalHour($professionalId, $hour);
            }
        }
    }

    /**
     * @param  Collection<int, Carbon>  $hourBuckets
     */
    private function backfillBooking(
        BookingAnalyticsAggregateService $bookingAggregates,
        Collection $hourBuckets,
        Carbon $start,
        Carbon $endExclusive
    ): void {
        $professionalIds = DB::table('analytics.booking_events')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $endExclusive)
            ->pluck('professional_id')
            ->map(static fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        $this->line("Booking entities: professionals={$professionalIds->count()}");

        foreach ($professionalIds as $professionalId) {
            foreach ($hourBuckets as $hour) {
                $bookingAggregates->rebuildProfessionalHour($professionalId, $hour);
            }
        }
    }
}

