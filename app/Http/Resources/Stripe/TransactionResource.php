<?php

namespace App\Http\Resources\Stripe;

use Illuminate\Http\Resources\Json\JsonResource;

// V2: Single normalised row for the Stripe Transactions table. Backs charge / refund / transfer / reversal
// rows uniformly so the frontend table renders one component regardless of underlying Stripe type.
//
// Source is an array, NOT an Eloquent model — built by StripeTransactionFetcher from Stripe-side objects
// merged with our local CommissionPayout joins for counterparty identity and payout linkage.
class TransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this['id'],
            'type' => $this['type'],
            'amount_cents' => $this['amount_cents'],
            'currency_code' => $this['currency_code'],
            'status' => $this['status'],
            'description' => $this['description'],
            'occurred_at' => $this['occurred_at'],
            'payout_id' => $this['payout_id'] ?? null,
            'orders_count' => $this['orders_count'] ?? null,
            'brand' => $this['brand'] ?? null,
            'affiliate' => $this['affiliate'] ?? null,
            'stripe_dashboard_url' => $this['stripe_dashboard_url'] ?? null,
            'raw_stripe_id' => $this['raw_stripe_id'],
        ];
    }
}
