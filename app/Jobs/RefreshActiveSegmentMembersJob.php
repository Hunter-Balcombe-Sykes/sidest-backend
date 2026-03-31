<?php

namespace App\Jobs;

use App\Models\Retail\BrandAffiliateSegment;
use App\Services\Store\SegmentEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly job: refresh membership cache for all segments that are referenced
 * by at least one currently active promotion.
 */
class RefreshActiveSegmentMembersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SEGMENT_CHUNK_SIZE = 100;

    public function handle(SegmentEvaluationService $evaluator): void
    {
        $segmentIds = DB::table('retail.brand_promotions as bp')
            ->selectRaw('DISTINCT unnest(bp.affiliate_segment_ids) as segment_id')
            ->where('is_active', true)
            ->whereRaw('bp.starts_at <= NOW()')
            ->whereRaw('bp.ends_at > NOW()')
            ->whereRaw("bp.affiliate_scope = 'segments'")
            ->whereRaw("array_length(bp.affiliate_segment_ids, 1) > 0")
            ->pluck('segment_id')
            ->filter(static fn ($id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        if ($segmentIds === []) {
            return;
        }

        BrandAffiliateSegment::query()
            ->whereIn('id', $segmentIds)
            ->orderBy('id')
            ->chunkById(self::SEGMENT_CHUNK_SIZE, function ($segments) use ($evaluator): void {
                $segments->each(static function (BrandAffiliateSegment $segment) use ($evaluator): void {
                    try {
                        $evaluator->evaluate($segment);
                    } catch (Throwable $e) {
                        Log::error('RefreshActiveSegmentMembersJob: failed to evaluate segment', [
                            'segment_id' => (string) $segment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }, 'id');
    }
}
