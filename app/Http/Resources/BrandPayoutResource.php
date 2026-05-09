<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for a CommissionPayout as seen by the brand.
 *
 * Exposes full failure lifecycle fields (failure_code, failure_category,
 * stripe_error_code/message, retry counts) — brands need visibility into
 * why a payout failed and when the next retry is scheduled.
 */
class BrandPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'status'                 => $this->status,
            'gross_commission_cents' => (int) $this->gross_commission_cents,
            'net_payout_cents'       => (int) $this->net_payout_cents,
            'platform_fee_cents'     => (int) $this->platform_fee_cents,
            'currency_code'          => $this->currency_code,
            'failure_code'           => $this->failure_code,
            'failure_category'       => $this->failure_category,
            'failure_reason'         => $this->failure_reason,
            'stripe_error_code'      => $this->stripe_error_code,
            'stripe_error_message'   => $this->stripe_error_message,
            'funding_failure_count'  => (int) ($this->funding_failure_count ?? 0),
            'next_retry_at'          => $this->next_retry_at?->toIso8601String(),
            'last_retry_at'          => $this->last_retry_at?->toIso8601String(),
            'transfer_completed_at'  => $this->transfer_completed_at?->toIso8601String(),
            'void_at'                => $this->void_at?->toIso8601String(),
            'created_at'             => $this->created_at?->toIso8601String(),
            // Nested affiliate relation — only present when eager-loaded.
            'affiliate'              => $this->whenLoaded('affiliateProfessional', fn () => [
                'id'   => $this->affiliateProfessional->id,
                'name' => $this->affiliateProfessional->display_name,
            ]),
        ];
    }
}
