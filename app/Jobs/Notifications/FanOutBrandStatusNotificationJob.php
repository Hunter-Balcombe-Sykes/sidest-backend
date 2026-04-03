<?php

namespace App\Jobs\Notifications;

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Fans out brand status change notifications (active/deactivated) to all connected affiliates.
class FanOutBrandStatusNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $brandProfessionalId,
        public readonly string $brandStatus, // 'active' | 'deactivated'
    ) {}

    public function handle(NotificationPublisher $publisher): void
    {
        $yearWeek = now()->format('o-W');

        $brandName = (string) DB::table('core.professionals')
            ->where('id', $this->brandProfessionalId)
            ->value(DB::raw("COALESCE(NULLIF(display_name, ''), NULLIF(handle, ''), 'Brand')"));

        $affiliateIds = DB::table('brand_partner_links')
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->pluck('affiliate_professional_id');

        foreach ($affiliateIds as $affiliateId) {
            try {
                if ($this->brandStatus === 'deactivated') {
                    $publisher->publish(
                        professionalId: $affiliateId,
                        frontendType: 'Warning',
                        category: 'brand_status',
                        title: 'Brand program deactivated',
                        body: "{$brandName}'s affiliate program has been deactivated.",
                        dedupeKey: "brand.deactivated.{$this->brandProfessionalId}.{$yearWeek}",
                        ctaUrl: '/account/store',
                        retentionConfigKey: 'brand_status',
                    );
                } else {
                    $publisher->publish(
                        professionalId: $affiliateId,
                        frontendType: 'Info',
                        category: 'brand_status',
                        title: 'Brand program reactivated',
                        body: "{$brandName}'s affiliate program is now active.",
                        dedupeKey: "brand.reactivated.{$this->brandProfessionalId}.{$yearWeek}",
                        ctaUrl: '/account/store',
                        retentionConfigKey: 'brand_status',
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('FanOutBrandStatusNotificationJob affiliate notify failed', [
                    'affiliate_id' => $affiliateId,
                    'message'      => $e->getMessage(),
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
