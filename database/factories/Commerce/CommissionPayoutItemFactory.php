<?php

namespace Database\Factories\Commerce;

use App\Models\Commerce\CommissionPayoutItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommissionPayoutItem>
 */
class CommissionPayoutItemFactory extends Factory
{
    protected $model = CommissionPayoutItem::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'payout_id' => (string) Str::uuid(),
            'order_id' => (string) Str::uuid(),
            'amount_cents' => $this->faker->numberBetween(1000, 10000),
            'created_at' => now()->toDateTimeString(),
        ];
    }
}
