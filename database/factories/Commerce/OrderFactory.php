<?php

namespace Database\Factories\Commerce;

use App\Models\Commerce\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $gross = $this->faker->numberBetween(5000, 20000);
        $rate = 15.0;

        return [
            'id' => (string) Str::uuid(),
            'shopify_order_id' => (string) $this->faker->unique()->numerify('################'),
            'shopify_shop_domain' => 'test.myshopify.com',
            'brand_professional_id' => (string) Str::uuid(),
            'affiliate_professional_id' => (string) Str::uuid(),
            'status' => 'approved',
            'gross_cents' => $gross,
            'discount_cents' => 0,
            'refund_cents' => 0,
            'net_cents' => $gross,
            'commission_cents' => (int) round($gross * ($rate / 100)),
            'commission_rate' => $rate,
            'rate_source' => 'brand_default',
            'currency_code' => 'AUD',
            'payout_id' => null,
            'shopify_updated_at' => now()->toDateTimeString(),
            'occurred_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];
    }
}
