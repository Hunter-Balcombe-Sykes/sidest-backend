<?php

namespace App\Jobs\Stripe;

use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandPartnerLinkAuditor;
use App\Services\Professional\BrandPartnerLinkNotifier;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// Voids all pending commission entries for a disconnected brand-affiliate
// pair when the count exceeds the sync cap (200). Dispatched after
// BrandPartnerLinkLifecycleService commits the disconnect transaction.
//
// Idempotent — voidEntry() uses optimistic locking, so retries are safe.
class VoidPendingCommissionsForLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $affiliateProfessionalId,
        public readonly string $brandProfessionalId,
        public readonly string $reason,
    ) {
        $this->onQueue('stripe');
    }

    public function handle(
        CommissionVoidService $voidService,
        BrandPartnerLinkAuditor $auditor,
        BrandPartnerLinkNotifier $notifier,
    ): void {
        [$affiliate, $brand] = $this->loadProfessionals();

        if (! $affiliate || ! $brand) {
            Log::warning('VoidPendingCommissionsForLinkJob: missing professional, skipping.', [
                'affiliate_id' => $this->affiliateProfessionalId,
                'brand_id' => $this->brandProfessionalId,
            ]);

            return;
        }

        $result = $voidService->runVoidLoop(
            $this->affiliateProfessionalId,
            $this->brandProfessionalId,
            $this->reason,
        );

        $auditor->recordAsyncVoidCompletion(
            $this->brandProfessionalId,
            $this->affiliateProfessionalId,
            $result['count'],
            $result['total_cents'],
            $this->reason,
        );

        $notifier->notifyAffiliateOfRemoval($affiliate, $brand, $result['total_cents']);
        $notifier->notifyBrandOfRemoval($brand, $affiliate);
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        Log::error('VoidPendingCommissionsForLinkJob exhausted all retries', [
            'affiliate_professional_id' => $this->affiliateProfessionalId,
            'brand_professional_id' => $this->brandProfessionalId,
            'reason' => $this->reason,
            'error' => $e->getMessage(),
        ]);
    }

    /** @return array{0: ?Professional, 1: ?Professional} */
    public function loadProfessionals(): array
    {
        return [
            Professional::find($this->affiliateProfessionalId),
            Professional::find($this->brandProfessionalId),
        ];
    }
}
