<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for a CommissionPayout as seen by the brand.
 *
 * Under Option A the funding/retry lifecycle is gone — a payout either succeeds via
 * destination charge or fails terminally. The brand-facing fields surface the final
 * outcome (status + failure_* + stripe_error_*), the linked PaymentIntent for receipt
 * lookup, and the grace deadline for visibility.
 */
class BrandPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'gross_commission_cents' => (int) $this->gross_commission_cents,
            'net_payout_cents' => (int) $this->net_payout_cents,
            'platform_fee_cents' => (int) $this->platform_fee_cents,
            'currency_code' => $this->currency_code,
            'failure_code' => $this->failure_code,
            'failure_category' => $this->failure_category,
            'failure_reason' => $this->failure_reason,
            'stripe_error_code' => $this->stripe_error_code,
            'stripe_error_message' => $this->stripe_error_message,
            'payment_intent_id' => $this->payment_intent_id,
            'transfer_completed_at' => $this->transfer_completed_at?->toIso8601String(),
            'void_at' => $this->void_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'affiliate' => $this->whenLoaded('affiliateProfessional', fn () => [
                'id' => $this->affiliateProfessional->id,
                'name' => $this->affiliateProfessional->display_name,
            ]),
        ];
    }
}
