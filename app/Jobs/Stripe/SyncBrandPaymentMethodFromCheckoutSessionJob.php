<?php

namespace App\Jobs\Stripe;

use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync a brand's saved PaymentMethod from a completed Stripe Checkout setup session.
 *
 * Moved out of StripeConnectWebhookController::handleCheckoutSessionCompleted to
 * keep the webhook handler off Stripe's 10s ack deadline — the underlying call
 * does a Session::retrieve round-trip and a DB write while Stripe waits.
 *
 * Idempotent: re-syncing the same completed session overwrites the brand's
 * PM cache columns with the same values. Safe to retry.
 */
class SyncBrandPaymentMethodFromCheckoutSessionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    // Match the longest tries+backoff window so a re-dispatch from a retried
    // webhook can't race the in-flight job.
    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $professionalId,
        public readonly string $checkoutSessionId,
    ) {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return $this->checkoutSessionId;
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(StripeConnectService $stripeConnect): void
    {
        $professional = Professional::find($this->professionalId);

        if (! $professional) {
            Log::warning('stripe.sync_pm_from_session.professional_not_found', [
                'professional_id' => $this->professionalId,
                'checkout_session_id' => $this->checkoutSessionId,
            ]);

            return;
        }

        $stripeConnect->syncBrandPaymentMethodFromCheckoutSession(
            $professional,
            $this->checkoutSessionId,
        );
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('stripe.sync_pm_from_session.failed', [
            'professional_id' => $this->professionalId,
            'checkout_session_id' => $this->checkoutSessionId,
            'error' => $e->getMessage(),
        ]);
    }
}
