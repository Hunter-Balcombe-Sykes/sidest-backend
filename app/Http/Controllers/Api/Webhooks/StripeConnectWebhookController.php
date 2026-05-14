<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Billing\WebhookEvent;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe Connect-scope webhooks — v1 events fired on connected accounts.
 *
 * Under Option A (destination charges) the platform-scope events (payment_intent.*,
 * charge.*, v2.core.account.*) all fire on the platform endpoint and are handled by
 * StripePlatformWebhookController. This controller is narrowed to the remaining
 * Connect-scope events:
 *
 *   account.updated                  — v1 mirror of v2 account state changes
 *   account.application.deauthorized — connected account owner revoked our access
 *   checkout.session.completed       — brand finished a card/BECS setup session
 *   payment_method.attached/detached — Stripe-side PM lifecycle on the brand's Account
 *
 * Transfer events (transfer.created/paid/failed/reversed) are not subscribed under Option A
 * — destination charges auto-transfer internally and don't emit them on connect scope.
 */
class StripeConnectWebhookController extends Controller
{
    use ValidatesStripeWebhookPayload;

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        if (! $sigHeader) {
            return response()->json(['error' => 'Missing signature'], 400);
        }

        $secret = (string) config('services.stripe.connect_webhook_secret');
        if ($secret === '') {
            Log::error('Stripe Connect webhook hit with no secret configured');

            return response()->json(['error' => 'No webhook secret configured'], 400);
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

        if (! $this->validateEventStructure($event)) {
            return response()->json(['error' => 'Invalid payload structure'], 400);
        }

        // Idempotency: firstOrCreate on stripe_event_id. Stripe event IDs are globally unique
        // across all destinations, so billing.webhook_events covers this controller and the
        // platform and billing controllers without collision.
        $webhookEvent = WebhookEvent::firstOrCreate(
            ['stripe_event_id' => $event->id],
            ['event_type' => $event->type, 'processed_at' => now()]
        );

        if (! $webhookEvent->wasRecentlyCreated) {
            return response()->json(['received' => true]);
        }

        $webhookEvent->forceFill(['payload' => json_decode($payload, true)])->save();

        return $this->handleParsedEvent($event);
    }

    /**
     * Dispatch a verified, de-duplicated Stripe Event to the appropriate handler.
     *
     * Account-scoped events are guarded against payload tampering: the HMAC-signed
     * event->account must match data.object->id. account.application.* is excluded
     * because data.object is an Application (id = ca_xxx), not an Account.
     */
    public function handleParsedEvent(\Stripe\Event $event): JsonResponse
    {
        $isAccountScoped = str_starts_with($event->type, 'account.');
        $isApplicationEvent = str_starts_with($event->type, 'account.application.');

        if ($isAccountScoped && ! $isApplicationEvent) {
            $topLevelAccount = $event->account ?? null;
            $objectId = $event->data->object->id ?? null;

            if ($topLevelAccount === null || $topLevelAccount !== $objectId) {
                Log::warning('stripe.connect.account_mismatch', [
                    'event_id' => $event->id,
                    'event_account' => $topLevelAccount,
                    'object_id' => $objectId,
                ]);

                return response()->json(['error' => 'account_mismatch'], 400);
            }
        }

        match ($event->type) {
            'account.updated' => $this->handleAccountUpdated($event->data->object),
            'account.application.deauthorized' => $this->handleAccountDeauthorized((string) ($event->account ?? '')),
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event->data->object),
            'payment_method.attached', 'payment_method.detached' => $this->handlePaymentMethodLifecycle($event->type, $event->data->object),
            default => Log::debug('Unhandled Stripe Connect event', ['type' => $event->type]),
        };

        return response()->json(['received' => true]);
    }

    /**
     * account.updated — v1 mirror of v2 account state changes on the connected account.
     *
     * Under Option A the canonical source for capability/requirement state is the v2 account
     * itself (re-fetched via syncAccountStatus). v1 account.updated arrives with the legacy
     * Account shape, which doesn't carry the v2 capability tree. We bust the status cache
     * and trigger a sync — syncAccountStatus does the v2 retrieve internally.
     */
    private function handleAccountUpdated(object $account): void
    {
        $accountId = (string) ($account->id ?? '');
        if ($accountId === '') {
            return;
        }

        StripeConnectService::forgetStatusCache($accountId);

        $professional = Professional::where('stripe_connect_account_id', $accountId)->first();
        if (! $professional) {
            Log::debug('Stripe account.updated for unknown account', ['account_id' => $accountId]);

            return;
        }

        // Respect local disconnect — late events shouldn't re-activate a disconnected account.
        if ($professional->stripe_connect_status === 'not_connected') {
            return;
        }

        // syncAccountStatus does the v2 retrieve + dual-capability derive + DB persist.
        app(StripeConnectService::class)->syncAccountStatus($professional);
    }

    /**
     * account.application.deauthorized — connected account owner revoked our access from
     * their Stripe dashboard. Null the connection locally so the payout job stops targeting
     * the account and the UI surfaces the disconnect.
     */
    private function handleAccountDeauthorized(string $stripeAccountId): void
    {
        if ($stripeAccountId === '') {
            Log::warning('stripe.connect.deauthorize_missing_account');

            return;
        }

        $professional = Professional::where('stripe_connect_account_id', $stripeAccountId)->first();

        if (! $professional) {
            Log::debug('Stripe account.application.deauthorized for unknown account', [
                'account_id' => $stripeAccountId,
            ]);

            return;
        }

        $professional->update([
            'stripe_connect_account_id' => null,
            'stripe_connect_status' => 'not_connected',
            'stripe_payment_method_id' => null,
            'stripe_payment_method_brand' => null,
            'stripe_payment_method_last4' => null,
            'payout_method' => null,
        ]);

        StripeConnectService::forgetStatusCache($stripeAccountId);

        Log::info('Stripe Connect account deauthorized via dashboard', [
            'professional_id' => $professional->id,
            'account_id' => $stripeAccountId,
        ]);
    }

    /**
     * checkout.session.completed — brand finished a setup session for their saved card or
     * BECS account. Sync the PaymentMethod onto the Professional record (sets payout_method
     * to 'card' or 'becs' based on the PM type Stripe returns).
     */
    private function handleCheckoutSessionCompleted(object $session): void
    {
        $professionalId = $session->metadata?->sidest_professional_id
            ?? $session->metadata?->professional_id
            ?? null;

        if (! $professionalId) {
            Log::warning('stripe.checkout_completed.missing_professional_id', [
                'session_id' => $session->id ?? null,
                'mode' => $session->mode ?? null,
            ]);

            return;
        }

        $professional = Professional::find($professionalId);
        if (! $professional) {
            Log::warning('stripe.checkout_completed.professional_not_found', [
                'session_id' => $session->id ?? null,
                'professional_id' => $professionalId,
            ]);

            return;
        }

        // Only `setup` sessions are produced by Partna under Option A. `payment` sessions
        // were the legacy wallet top-up flow — gone with the wallet model.
        if (($session->mode ?? null) !== 'setup') {
            Log::info('stripe.checkout_completed.ignored_mode', [
                'session_id' => $session->id ?? null,
                'mode' => $session->mode ?? null,
            ]);

            return;
        }

        app(StripeConnectService::class)->syncBrandPaymentMethodFromCheckoutSession(
            $professional,
            (string) $session->id,
        );
    }

    /**
     * payment_method.attached and payment_method.detached — informational. The setup
     * session flow is already covered by checkout.session.completed (which persists the
     * PM cache fields). These events arrive on the connected account scope and let us
     * log/audit the PM lifecycle independently. No state mutation here.
     */
    private function handlePaymentMethodLifecycle(string $eventType, object $paymentMethod): void
    {
        Log::info('stripe.connect.payment_method_lifecycle', [
            'event_type' => $eventType,
            'payment_method_id' => $paymentMethod->id ?? null,
            'pm_type' => $paymentMethod->type ?? null,
        ]);
    }
}
