<?php

namespace App\Jobs;

use App\Models\Retail\BrandPromotion;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every 5 minutes: send start notifications for promotions that just became active.
 */
class SendPromotionStartNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BATCH_SIZE = 200;
    private const PROMOTION_SCAN_CHUNK_SIZE = 200;

    public function handle(NotificationPublisher $notificationPublisher): void
    {
        BrandPromotion::query()
            ->select('id')
            ->where('is_active', true)
            ->whereRaw('starts_at <= NOW()')
            ->whereNull('notification_sent_at')
            ->orderBy('id')
            ->chunkById(self::PROMOTION_SCAN_CHUNK_SIZE, function ($candidatePromotions) use ($notificationPublisher): void {
                foreach ($candidatePromotions as $candidate) {
                    $this->processPromotion((string) $candidate->id, $notificationPublisher);
                }
            }, 'id');
    }

    private function processPromotion(string $promotionId, NotificationPublisher $notificationPublisher): void
    {
        $lockName = 'promotion-start-notify:' . $promotionId;
        $lock = DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS acquired', [$lockName]);
        $acquiredRaw = $lock->acquired ?? false;
        $acquired = $acquiredRaw === true || $acquiredRaw === 't' || $acquiredRaw === 1 || $acquiredRaw === '1';

        if (! $acquired) {
            return;
        }

        try {
            $promotion = DB::transaction(function () use ($promotionId): ?BrandPromotion {
                $promotion = BrandPromotion::query()
                    ->where('id', $promotionId)
                    ->lockForUpdate()
                    ->first();

                if (! $promotion instanceof BrandPromotion) {
                    return null;
                }

                if (
                    ! $promotion->is_active
                    || $promotion->notification_sent_at !== null
                    || $promotion->starts_at === null
                    || $promotion->starts_at->isFuture()
                ) {
                    return null;
                }

                return $promotion;
            });

            if (! $promotion instanceof BrandPromotion) {
                return;
            }

            $this->notifyAffiliates($promotion, $notificationPublisher);

            BrandPromotion::query()
                ->where('id', $promotionId)
                ->whereNull('notification_sent_at')
                ->update(['notification_sent_at' => now()]);
        } catch (Throwable $e) {
            Log::error('SendPromotionStartNotificationsJob: failed to send notifications', [
                'promotion_id' => $promotionId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(hashtext(?))', [$lockName]);
        }
    }

    private function notifyAffiliates(BrandPromotion $promotion, NotificationPublisher $publisher): void
    {
        $promotionId = (string) $promotion->id;
        $title = 'New promotion started';
        $body = "A new promotion is active: {$promotion->name}";

        $notify = function (string $affiliateId) use ($publisher, $promotionId, $title, $body): void {
            try {
                $publisher->publish(
                    professionalId: $affiliateId,
                    frontendType: 'Info',
                    category: 'catalog_changes',
                    title: $title,
                    body: $body,
                    dedupeKey: "promotion.started.{$promotionId}.{$affiliateId}",
                    ctaUrl: '/account/store',
                );
            } catch (Throwable $e) {
                Log::warning('SendPromotionStartNotificationsJob: failed to notify affiliate', [
                    'affiliate_id' => $affiliateId,
                    'promotion_id' => $promotionId,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        match ($promotion->affiliate_scope) {
            'affiliates' => $this->notifyExplicitAffiliates($promotion->affiliate_ids, $notify),
            'segments' => $this->notifySegmentAffiliates($promotion->affiliate_segment_ids, $notify),
            default => $this->notifyAllAffiliates((string) $promotion->brand_professional_id, $notify),
        };
    }

    /**
     * Scope = 'affiliates': bounded list from the promotion, safe to iterate directly.
     *
     * @param  array<int, string>  $affiliateIds
     * @param  callable(string): void  $notify
     */
    private function notifyExplicitAffiliates(array $affiliateIds, callable $notify): void
    {
        foreach ($affiliateIds as $affiliateId) {
            $notify($affiliateId);
        }
    }

    /**
     * Scope = 'segments': stream affiliate IDs from segment membership in chunks.
     *
     * @param  array<int, string>  $segmentIds
     * @param  callable(string): void  $notify
     */
    private function notifySegmentAffiliates(array $segmentIds, callable $notify): void
    {
        $seen = [];

        DB::table('retail.brand_affiliate_segment_members')
            ->select('id', 'affiliate_professional_id')
            ->whereIn('segment_id', $segmentIds)
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($rows) use ($notify, &$seen): void {
                foreach ($rows as $row) {
                    $affiliateId = (string) $row->affiliate_professional_id;

                    if (isset($seen[$affiliateId])) {
                        continue;
                    }

                    $seen[$affiliateId] = true;
                    $notify($affiliateId);
                }
            }, 'id');
    }

    /**
     * Scope = 'all' (default): stream all brand partner links in chunks.
     *
     * @param  callable(string): void  $notify
     */
    private function notifyAllAffiliates(string $brandId, callable $notify): void
    {
        DB::table('core.brand_partner_links')
            ->select('id', 'affiliate_professional_id')
            ->where('brand_professional_id', $brandId)
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($rows) use ($notify): void {
                foreach ($rows as $row) {
                    $notify((string) $row->affiliate_professional_id);
                }
            }, 'id');
    }
}
