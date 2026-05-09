<?php

namespace Database\Factories;

use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Professional>
 */
class ProfessionalFactory extends Factory
{
    protected $model = Professional::class;

    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();
        $handle = strtolower($first . $last . fake()->randomNumber(4));

        return [
            'id' => (string) Str::uuid(),
            'auth_user_id' => (string) Str::uuid(),
            'handle' => $handle,
            'handle_lc' => $handle,
            'display_name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'primary_email' => fake()->unique()->safeEmail(),
            'country_code' => 'AU',
            'timezone' => 'Australia/Sydney',
            'professional_type' => 'affiliate',
            'status' => 'active',
            'onboarding_step' => 0,
        ];
    }

    /** Pre-populate Stripe customer + masked card fields for billing tests. */
    public function withCard(): static
    {
        return $this->state(fn () => [
            'stripe_customer_id' => 'cus_' . fake()->bothify('?#?#?#?#'),
            'stripe_payment_method_id' => 'pm_' . fake()->bothify('?#?#?#?#'),
            'stripe_payment_method_brand' => 'visa',
            'stripe_payment_method_last4' => '4242',
        ]);
    }
}
