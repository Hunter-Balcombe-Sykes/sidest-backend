<?php

namespace Database\Factories;

use App\Models\Retail\CommissionPayout;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommissionPayout>
 */
class CommissionPayoutFactory extends Factory
{
    protected $model = CommissionPayout::class;

    /**
     * Minimal defaults — only columns every test schema is guaranteed to have. Lifecycle
     * fields (stripe_error_*, failure_*, transfer_completed_at, etc.) are set per-test
     * via state methods or `create(['key' => ...])` overrides so a test using an inline
     * SQLite CREATE TABLE without those columns doesn't break on the baseline insert.
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'brand_professional_id' => (string) Str::uuid(),
            'affiliate_professional_id' => (string) Str::uuid(),
            'status' => 'pending',
            'gross_commission_cents' => 0,
            'platform_fee_cents' => 0,
            'net_payout_cents' => 0,
            'currency_code' => 'AUD',
            'ledger_entry_count' => 0,
            'retry_count' => 0,
            'needs_manual_refund' => false,
        ];
    }

    /**
     * CommissionPayout uses `$guarded = ['*']` — every write goes through forceFill()
     * in production. Override the factory's model construction to use forceFill so
     * test inserts don't hit MassAssignmentException.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Model
    {
        $modelClass = $this->modelName();

        return (new $modelClass)->forceFill($attributes);
    }
}
