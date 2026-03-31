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
        $affiliateIds = $this->resolveAffiliateIds($promotion);

        if ($affiliateIds === []) {
            return;
        }

        $promotionId = (string) $promotion->id;
        $title = 'New promotion started';
        $body = "A new promotion is active: {$promotion->name}";

        foreach (array_chunk($affiliateIds, self::BATCH_SIZE) as $batch) {
            foreach ($batch as $affiliateId) {
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
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveAffiliateIds(BrandPromotion $promotion): array
    {
        $brandId = (string) $promotion->brand_professional_id;

        return match ($promotion->affiliate_scope) {
            'affiliates' => $promotion->affiliate_ids,
            'segments' => DB::table('retail.brand_affiliate_segment_members')
                ->whereIn('segment_id', $promotion->affiliate_segment_ids)
                ->distinct()
                ->pluck('affiliate_professional_id')
                ->map(static fn ($id): string => (string) $id)
                ->all(),
            default => DB::table('core.brand_partner_links')
                ->where('brand_professional_id', $brandId)
                ->pluck('affiliate_professional_id')
                ->map(static fn ($id): string => (string) $id)
                ->all(),
        };
    }
}
