<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Professional\Professional;
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
// Dispatches one SendBrandStatusNotificationJob per affiliate so failures isolate
// and retry independently.
class FanOutBrandStatusNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $brandProfessionalId,
        public readonly string $brandStatus, // 'live' | 'building' | 'systems_down'
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
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

        DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->chunkById(500, function ($rows) use ($brandName, $yearWeek) {
                foreach ($rows as $row) {
                    SendBrandStatusNotificationJob::dispatch(
                        affiliateProfessionalId: $row->affiliate_professional_id,
                        brandProfessionalId: $this->brandProfessionalId,
                        brandName: $brandName,
                        brandStatus: $this->brandStatus,
                        yearWeek: $yearWeek,
                    );
                }
            });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FanOutBrandStatusNotificationJob failed', [
            'brand_professional_id' => $this->brandProfessionalId,
            'brand_status' => $this->brandStatus,
            'message' => $e->getMessage(),
        ]);
    }
}
