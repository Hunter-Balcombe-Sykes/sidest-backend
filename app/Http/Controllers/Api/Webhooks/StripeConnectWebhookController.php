<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Services\Store\PublicStripeCheckoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeConnectWebhookController extends Controller
{
    public function __construct(
        private readonly PublicStripeCheckoutService $stripeCheckout,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('services.stripe.connect_webhook_secret');

        if (! $sigHeader || ! $secret) {
            return response()->json(['error' => 'Missing signature or secret'], 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException) {
            Log::warning('Stripe Connect webhook signature verification failed');
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::warning('Stripe Connect webhook parse error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        match ($event->type) {
            'account.updated' => $this->handleAccountUpdated($event->data->object),
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event->data->object, (string) ($event->account ?? '')),
            'transfer.created' => $this->handleTransferCreated($event->data->object),
            'transfer.failed' => $this->handleTransferFailed($event->data->object),
            'transfer.reversed' => $this->handleTransferFailed($event->data->object),
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object, (string) ($event->account ?? '')),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
            default => Log::debug('Unhandled Stripe Connect event', ['type' => $event->type]),
        };

        return response()->json(['received' => true]);
    }

    private function handleCheckoutSessionCompleted(object $checkoutSession, string $connectedAccountId): void
    {
        $purpose = trim((string) ($checkoutSession->metadata?->purpose ?? ''));
        if ($purpose !== 'public_store_stripe_checkout') {
            return;
        }

        try {
            app(PublicStripeCheckoutService::class)
                ->finalizeCompletedCheckoutSession($checkoutSession, $connectedAccountId);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout completion finalization failed', [
                'checkout_session_id' => $checkoutSession->id ?? null,
                'connected_account_id' => $connectedAccountId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleAccountUpdated(object $account): void
    {
        $professional = Professional::where('stripe_connect_account_id', $account->id)->first();

        if (! $professional) {
            Log::debug('Stripe account.updated for unknown account', ['account_id' => $account->id]);
            return;
        }

        $status = StripeConnectService::determineAccountStatus($account);

        if ($professional->stripe_connect_status !== $status) {
            $oldStatus = $professional->stripe_connect_status;
            $professional->update(['stripe_connect_status' => $status]);

            Log::info('Stripe Connect status updated', [
                'professional_id' => $professional->id,
                'old_status' => $oldStatus,
                'new_status' => $status,
            ]);
        }
    }

    private function handleTransferCreated(object $transfer): void
    {
        $payoutId = $transfer->metadata?->comet_payout_id ?? null;

        if (! $payoutId) {
            return;
        }

        Log::info('Stripe transfer created', [
            'transfer_id' => $transfer->id,
            'payout_id' => $payoutId,
        ]);
    }

    private function handleTransferFailed(object $transfer): void
    {
        $payoutId = $transfer->metadata?->comet_payout_id ?? null;

        if (! $payoutId) {
            return;
        }

        $payout = CommissionPayout::find($payoutId);
        if ($payout && ! in_array($payout->status, ['failed', 'completed', 'cancelled'], true)) {
            $payout->update([
                'status' => 'failed',
                'failure_code' => 'transfer_failed_webhook',
                'failure_reason' => 'Transfer failed according to Stripe webhook',
            ]);
        }

        Log::warning('Stripe transfer failed', [
            'transfer_id' => $transfer->id,
            'payout_id' => $payoutId,
        ]);
    }

    private function handlePaymentIntentSucceeded(object $paymentIntent, string $connectedAccountId = ''): void
    {
        $purpose = $paymentIntent->metadata?->purpose ?? null;

        // Storefront embedded card payment
        if ($purpose === 'public_store_payment') {
            try {
                $this->stripeCheckout->finalizePaymentIntentOrder(
                    $paymentIntent,
                    $connectedAccountId ?: null,
                );
                Log::info('Storefront payment intent finalized', [
                    'payment_intent_id' => $paymentIntent->id,
                    'checkout_session_token' => $paymentIntent->metadata?->checkout_session_token ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to finalize storefront payment intent', [
                    'payment_intent_id' => $paymentIntent->id,
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // Commission payout payment intent
        $payoutId = $paymentIntent->metadata?->comet_payout_id ?? null;
        if (! $payoutId) {
            return;
        }

        Log::info('Stripe payment intent succeeded for payout', [
            'payment_intent_id' => $paymentIntent->id,
            'payout_id' => $payoutId,
        ]);
    }

    private function handlePaymentIntentFailed(object $paymentIntent): void
    {
        $payoutId = $paymentIntent->metadata?->comet_payout_id ?? null;

        if (! $payoutId) {
            return;
        }

        $payout = CommissionPayout::find($payoutId);
        if ($payout && ! in_array($payout->status, ['failed', 'completed'])) {
            $payout->update([
                'status' => 'failed',
                'failure_code' => 'payment_failed_webhook',
                'failure_reason' => $paymentIntent->last_payment_error?->message ?? 'Payment failed',
            ]);
        }

        Log::warning('Stripe payment intent failed for payout', [
            'payment_intent_id' => $paymentIntent->id,
            'payout_id' => $payoutId,
        ]);
    }
}
