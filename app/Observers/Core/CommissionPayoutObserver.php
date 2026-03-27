<?php

namespace App\Observers\Core;

use App\Models\Retail\CommissionPayout;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Log;

class CommissionPayoutObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function updated(CommissionPayout $payout): void
    {
        try {
            // Payout failed
            if ($payout->isDirty('status') && $payout->status === 'failed') {
                $affiliateId = trim((string) ($payout->affiliate_professional_id ?? ''));
                if ($affiliateId !== '') {
                    $this->publisher->publish(
                        professionalId: $affiliateId,
                        frontendType: 'Critical',
                        category: 'payouts',
                        title: 'Payout failed',
                        body: 'A commission payout could not be processed. Please check your payment details.',
                        dedupeKey: "payout.failed.{$payout->id}",
                        ctaUrl: '/account/store?section=payouts',
                        retentionConfigKey: 'payout',
                    );
                }
            }

            // Payout action required: status=pending AND failure_code newly set
            if (
                $payout->status === 'pending'
                && $payout->isDirty('failure_code')
                && $payout->failure_code !== null
                && $payout->getOriginal('failure_code') === null
            ) {
                $brandId = trim((string) ($payout->brand_professional_id ?? ''));
                if ($brandId !== '') {
                    $this->publisher->publish(
                        professionalId: $brandId,
                        frontendType: 'Warning',
                        category: 'payouts',
                        title: 'Payout action required',
                        body: 'A commission payout requires your attention. Please review your funding balance.',
                        dedupeKey: "payout.action_required.{$payout->id}",
                        ctaUrl: '/account/commerce?section=payouts',
                        retentionConfigKey: 'payout',
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CommissionPayout updated notification failed', [
                'payout_id' => $payout->id,
                'message'   => $e->getMessage(),
            ]);
        }
    }
}
