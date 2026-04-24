<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionVoidService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

// V2: Core. Processes Stripe Connect events: account updates, checkout completions, transfer status, payment intents. Drives the commission payout lifecycle.
class StripeConnectWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        if (! $sigHeader) {
            return response()->json(['error' => 'Missing signature'], 400);
        }

        // Try both Connect and platform webhook secrets so one endpoint URL
        // can handle events from connected accounts and destination charges.
        $secrets = array_filter([
            config('services.stripe.connect_webhook_secret'),
            config('services.stripe.webhook_secret'),
        ]);

        if ($secrets === []) {
            return response()->json(['error' => 'No webhook secret configured'], 400);
        }

        $event = null;
        foreach ($secrets as $secret) {
            try {
                $event = Webhook::constructEvent($payload, $sigHeader, $secret);
                break;
            } catch (SignatureVerificationException) {
                continue;
            } catch (\Exception $e) {
                Log::warning('Stripe webhook parse error', ['error' => $e->getMessage()]);

                return response()->json(['error' => 'Invalid payload'], 400);
            }
        }

        if (! $event) {
            Log::warning('Stripe webhook signature verification failed for all configured secrets');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Idempotency: atomic insert-or-skip on the UNIQUE stripe_event_id.
        // Stripe event IDs are globally unique across platform + Connect events,
        // so billing.webhook_events covers both this controller and StripeWebhookController.
        $alreadyProcessed = ! DB::table('billing.webhook_events')->insertOrIgnore([
            'id' => Str::uuid()->toString(),
            'stripe_event_id' => $event->id,
            'event_type' => $event->type,
            'payload' => json_encode(json_decode($payload, true)),
            'processed_at' => now(),
        ]);

        if ($alreadyProcessed) {
            return response()->json(['received' => true]);
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
        Log::debug('Stripe checkout session completed (no-op in V2)', [
            'checkout_session_id' => $checkoutSession->id ?? null,
            'connected_account_id' => $connectedAccountId,
        ]);
    }

    private function handleAccountUpdated(object $account): void
    {
        $professional = Professional::where('stripe_connect_account_id', $account->id)->first();

        if (! $professional) {
            Log::debug('Stripe account.updated for unknown account', ['account_id' => $account->id]);

            return;
        }

        // Respect local disconnect: if the professional has soft-disconnected
        // the account, ignore incoming Stripe events until they explicitly
        // reconnect via createOnboardingLink. Prevents a late account.updated
        // event from silently re-activating a disconnected account.
        if ($professional->stripe_connect_status === 'disconnected') {
            Log::debug('Stripe account.updated skipped — account locally disconnected', [
                'professional_id' => $professional->id,
                'account_id' => $account->id,
            ]);

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

            // When an affiliate transitions to 'active', flush any held commissions
            // so they enter the normal payout pipeline immediately.
            if ($status === 'active' && $oldStatus !== 'active') {
                try {
                    app(CommissionVoidService::class)->flushHeldCommissions($professional);
                } catch (\Throwable $e) {
                    Log::warning('Failed to flush held commissions on Stripe connect', [
                        'professional_id' => $professional->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function handleTransferCreated(object $transfer): void
    {
        $payoutId = $transfer->metadata?->sidest_payout_id ?? null;

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
        $payoutId = $transfer->metadata?->sidest_payout_id ?? null;

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
        // Commission payout payment intent
        $payoutId = $paymentIntent->metadata?->sidest_payout_id ?? null;
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
        $payoutId = $paymentIntent->metadata?->sidest_payout_id ?? null;

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
