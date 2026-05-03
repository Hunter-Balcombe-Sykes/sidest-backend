<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Professional\Professional;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Fans out brand status change notifications to all connected affiliates.
// Only fires for affiliate-facing status transitions (live, building, systems_down).
// preview transitions are internal wizard state and don't notify affiliates.
class FanOutBrandStatusNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $brandProfessionalId,
        public readonly string $brandStatus, // 'live' | 'building' | 'systems_down'
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationPublisher $publisher): void
    {
        $brand = Professional::find($this->brandProfessionalId);

        if (! $brand) {
            Log::warning('FanOutBrandStatusNotificationJob: brand not found, skipping fan-out', [
                'brand_professional_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        $yearWeek = now()->format('o-W');

        $brandName = (string) ($brand->display_name ?: $brand->handle ?: 'Brand');

        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->pluck('affiliate_professional_id');

        foreach ($affiliateIds as $affiliateId) {
            try {
                match ($this->brandStatus) {
                    'building' => $publisher->publish(
                        professionalId: $affiliateId,
                        frontendType: 'Warning',
                        category: 'brand_status',
                        title: 'Brand program paused',
                        body: "{$brandName}'s affiliate program is no longer active.",
                        dedupeKey: "brand.building.{$this->brandProfessionalId}.{$yearWeek}",
                        ctaUrl: '/account/store',
                        retentionConfigKey: 'brand_status',
                    ),
                    'systems_down' => $publisher->publish(
                        professionalId: $affiliateId,
                        frontendType: 'Warning',
                        category: 'brand_status',
                        title: 'Brand program temporarily unavailable',
                        body: "{$brandName}'s affiliate program is temporarily unavailable due to a platform issue.",
                        dedupeKey: "brand.systems_down.{$this->brandProfessionalId}.{$yearWeek}",
                        ctaUrl: '/account/store',
                        retentionConfigKey: 'brand_status',
                    ),
                    default => $publisher->publish(
                        professionalId: $affiliateId,
                        frontendType: 'Info',
                        category: 'brand_status',
                        title: 'Brand program now active',
                        body: "{$brandName}'s affiliate program is now active.",
                        dedupeKey: "brand.live.{$this->brandProfessionalId}.{$yearWeek}",
                        ctaUrl: '/account/store',
                        retentionConfigKey: 'brand_status',
                    ),
                };
            } catch (\Throwable $e) {
                Log::warning('FanOutBrandStatusNotificationJob affiliate notify failed', [
                    'affiliate_id' => $affiliateId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Brand status fan-out job failed', [
            'brand_professional_id' => $this->brandProfessionalId,
            'brand_status' => $this->brandStatus,
            'message' => $e->getMessage(),
        ]);
    }
}
