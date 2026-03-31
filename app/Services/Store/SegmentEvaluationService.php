<?php

namespace App\Services\Store;

use App\Models\Retail\BrandAffiliateSegment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SegmentEvaluationService
{
    private const MAX_SEGMENT_SIZE = 200;

    /**
     * Evaluate the segment criteria and refresh the cached membership.
     *
     * Uses an advisory lock to prevent concurrent evaluations of the same segment.
     * Upserts changed members and deletes removed ones — minimises I/O vs DELETE+INSERT.
     */
    public function evaluate(BrandAffiliateSegment $segment): void
    {
        DB::transaction(function () use ($segment): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', [
                'segment-eval:' . $segment->id,
            ]);

            $members = $this->queryMembers($segment);

            if ($members !== []) {
                DB::table('retail.brand_affiliate_segment_members')
                    ->upsert(
                        array_map(static fn (array $m): array => array_merge($m, [
                            'segment_id' => $segment->id,
                            'created_at' => now()->toDateTimeString(),
                        ]), $members),
                        ['segment_id', 'affiliate_professional_id'],
                        ['rank', 'metric_value', 'created_at']
                    );
            }

            $keepIds = array_column($members, 'affiliate_professional_id');

            $query = DB::table('retail.brand_affiliate_segment_members')
                ->where('segment_id', $segment->id);

            if ($keepIds !== []) {
                $query->whereNotIn('affiliate_professional_id', $keepIds);
            }

            $query->delete();

            $segment->members_refreshed_at = now();
            $segment->save();
        });
    }

    /**
     * @return array<int, array{affiliate_professional_id: string, rank: int, metric_value: int}>
     */
    private function queryMembers(BrandAffiliateSegment $segment): array
    {
        $brandId = (string) $segment->brand_professional_id;
        $size = max(0, (int) $segment->size);

        if ($size > self::MAX_SEGMENT_SIZE) {
            Log::warning('SegmentEvaluationService: segment size exceeds cap, clamping', [
                'segment_id' => (string) $segment->id,
                'requested_size' => $size,
                'cap' => self::MAX_SEGMENT_SIZE,
            ]);
            $size = self::MAX_SEGMENT_SIZE;
        }

        if ($size === 0) {
            return [];
        }

        return match ($segment->criteria) {
            'highest_revenue' => $this->queryAnalytics($brandId, 'net_cents', 'DESC', $size, $segment->lookback_days),
            'lowest_revenue' => $this->queryAnalytics($brandId, 'net_cents', 'ASC', $size, $segment->lookback_days),
            'most_orders' => $this->queryAnalytics($brandId, 'orders_count', 'DESC', $size, $segment->lookback_days),
            'fewest_orders' => $this->queryAnalytics($brandId, 'orders_count', 'ASC', $size, $segment->lookback_days),
            'highest_commission' => $this->queryAnalytics($brandId, 'commission_net_cents', 'DESC', $size, $segment->lookback_days),
            'lowest_commission' => $this->queryAnalytics($brandId, 'commission_net_cents', 'ASC', $size, $segment->lookback_days),
            'newest' => $this->queryNewest($brandId, $size),
            'professional_type' => $this->queryProfessionalType($brandId, (string) $segment->professional_type_filter, $size),
            default => [],
        };
    }

    /**
     * @return array<int, array{affiliate_professional_id: string, rank: int, metric_value: int}>
     */
    private function queryAnalytics(
        string $brandId,
        string $metricColumn,
        string $direction,
        int $size,
        ?int $lookbackDays
    ): array {
        $primaryCurrency = $this->resolvePrimaryCurrency($brandId);

        $query = DB::table('analytics.brand_influencer_daily')
            ->selectRaw("affiliate_professional_id, SUM({$metricColumn}) as metric_value")
            ->where('brand_professional_id', $brandId)
            ->where('currency_code', $primaryCurrency)
            ->groupBy('affiliate_professional_id')
            ->orderByRaw("metric_value {$direction}")
            ->limit($size);

        if ($lookbackDays !== null && $lookbackDays > 0) {
            $query->whereRaw("day >= CURRENT_DATE - INTERVAL '{$lookbackDays} days'");
        }

        return $this->buildMemberRows($query->get()->all());
    }

    /**
     * @return array<int, array{affiliate_professional_id: string, rank: int, metric_value: int}>
     */
    private function queryNewest(string $brandId, int $size): array
    {
        $rows = DB::table('core.brand_partner_links')
            ->selectRaw('affiliate_professional_id, EXTRACT(EPOCH FROM created_at)::bigint as metric_value')
            ->where('brand_professional_id', $brandId)
            ->orderByDesc('created_at')
            ->limit($size)
            ->get()
            ->all();

        return $this->buildMemberRows($rows);
    }

    /**
     * @return array<int, array{affiliate_professional_id: string, rank: int, metric_value: int}>
     */
    private function queryProfessionalType(string $brandId, string $typeFilter, int $size): array
    {
        $rows = DB::table('core.brand_partner_links as bpl')
            ->join('core.professionals as p', 'p.id', '=', 'bpl.affiliate_professional_id')
            ->selectRaw('bpl.affiliate_professional_id, 0 as metric_value')
            ->where('bpl.brand_professional_id', $brandId)
            ->where('p.professional_type', $typeFilter)
            ->orderBy('bpl.created_at')
            ->limit($size)
            ->get()
            ->all();

        return $this->buildMemberRows($rows);
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array{affiliate_professional_id: string, rank: int, metric_value: int}>
     */
    private function buildMemberRows(array $rows): array
    {
        $members = [];

        foreach ($rows as $rank => $row) {
            $affiliateId = (string) ($row->affiliate_professional_id ?? '');
            if ($affiliateId === '') {
                continue;
            }

            $members[] = [
                'affiliate_professional_id' => $affiliateId,
                'rank' => $rank + 1,
                'metric_value' => (int) ($row->metric_value ?? 0),
            ];
        }

        return $members;
    }

    /**
     * Resolve the brand's primary currency for analytics queries.
     * Uses the most frequent currency_code from the brand's orders, or AUD as fallback.
     */
    private function resolvePrimaryCurrency(string $brandId): string
    {
        $currency = DB::table('retail.orders')
            ->selectRaw('currency_code, COUNT(*) as cnt')
            ->where('brand_professional_id', $brandId)
            ->groupBy('currency_code')
            ->orderByDesc('cnt')
            ->value('currency_code');

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'AUD';
    }
}
