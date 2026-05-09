<?php

namespace Database\Factories\Commerce;

use App\Models\Commerce\WalletMovement;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WalletMovementFactory extends Factory
{
    protected $model = WalletMovement::class;

    public function definition(): array
    {
        return [
            'id'              => Str::uuid()->toString(),
            'professional_id' => Professional::factory(),
            'direction'       => 'credit',
            'amount_cents'    => 5000,
            'currency_code'   => 'AUD',
            'reason'          => 'top_up',
            'actor_type'      => 'system',
            'actor_id'        => null,
            'idempotency_key' => 'test:' . Str::uuid()->toString(),
            'metadata'        => [],
            'occurred_at'     => now(),
        ];
    }

    /** Convenience state for debit movements (payout_debit, clawback_debit, etc.). */
    public function debit(): static
    {
        return $this->state(['direction' => 'debit']);
    }

    /** Tag the movement as originating from a Stripe webhook event. */
    public function fromWebhook(string $eventId): static
    {
        return $this->state([
            'actor_type' => 'webhook',
            'actor_id'   => $eventId,
        ]);
    }
}
