<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for a CommissionPayout as seen by the affiliate.
 *
 * Similar to BrandPayoutResource but with one privacy rule:
 * failure_category='brand_funding' is hidden from the affiliate — they
 * should not know whether the brand ran out of wallet balance or had no
 * payment method. They see null and can contact support if needed.
 */
class AffiliatePayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Redact brand-side funding failures from the affiliate view.
        $failureCategory = $this->failure_category === 'brand_funding'
            ? null
            : $this->failure_category;

        return [
            'id'                     => $this->id,
            'status'                 => $this->status,
            'gross_commission_cents' => (int) $this->gross_commission_cents,
            'net_payout_cents'       => (int) $this->net_payout_cents,
            'platform_fee_cents'     => (int) $this->platform_fee_cents,
            'currency_code'          => $this->currency_code,
            'failure_code'           => $this->failure_code,
            'failure_category'       => $failureCategory,
            'failure_reason'         => $this->failure_reason,
            'stripe_error_code'      => $this->stripe_error_code,
            'stripe_error_message'   => $this->stripe_error_message,
            'funding_failure_count'  => (int) ($this->funding_failure_count ?? 0),
            'next_retry_at'          => $this->next_retry_at?->toIso8601String(),
            'last_retry_at'          => $this->last_retry_at?->toIso8601String(),
            'transfer_completed_at'  => $this->transfer_completed_at?->toIso8601String(),
            'void_at'                => $this->void_at?->toIso8601String(),
            'created_at'             => $this->created_at?->toIso8601String(),
            // Nested brand relation — only present when eager-loaded.
            'brand'                  => $this->whenLoaded('brandProfessional', fn () => [
                'id'   => $this->brandProfessional->id,
                'name' => $this->brandProfessional->display_name,
            ]),
        ];
    }
}
