<?php

namespace Database\Factories;

use App\Models\Retail\CommissionPayout;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommissionPayout>
 */
class CommissionPayoutFactory extends Factory
{
    protected $model = CommissionPayout::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'status' => 'pending',
            'gross_commission_cents' => 0,
            'platform_fee_cents' => 0,
            'net_payout_cents' => 0,
            'currency_code' => 'AUD',
            'ledger_entry_count' => 0,
            'retry_count' => 0,
            'needs_manual_refund' => false,
            'wallet_debit_cents' => 0,
            'charge_cents' => 0,

            // Lifecycle columns
            'transfer_completed_at' => null,
            'stripe_error_code' => null,
            'stripe_error_message' => null,
            'next_retry_at' => null,
            'last_retry_at' => null,
            'funding_failure_count' => 0,
            'failure_category' => null,
            'grace_notifications_sent' => [],
        ];
    }
}
