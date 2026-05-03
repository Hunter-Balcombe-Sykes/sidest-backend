<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Professional\SiteProvisioningService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

// V2: Core. Processes Stripe Billing subscription lifecycle webhooks. Source of truth for subscription state.
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        if (! $sigHeader) {
            return response()->json(['error' => 'Missing signature'], 400);
        }

        $secret = config('services.stripe.webhook_secret');

        if (! $secret) {
            return response()->json(['error' => 'No webhook secret configured'], 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException) {
            Log::warning('Stripe billing webhook signature verification failed');

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::warning('Stripe billing webhook parse error', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // Idempotency: atomic insert-or-skip using DB unique constraint on stripe_event_id.
        // This prevents race conditions where two concurrent requests pass an exists() check.
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
            'customer.subscription.created' => $this->handleSubscriptionCreated($event->data->object, $event),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event->data->object, $event),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event->data->object, $event),
            'invoice.paid' => $this->handleInvoicePaid($event->data->object, $event),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event->data->object, $event),
            default => Log::debug('Unhandled Stripe billing event', ['type' => $event->type]),
        };

        return response()->json(['received' => true]);
    }

    private function handleSubscriptionCreated(object $subscription, object $event): void
    {
        // Resolve professional: prefer stripe_customer_id lookup (tamper-proof),
        // fall back to metadata (set server-side by StripeBillingService).
        $stripeCustomerId = (string) ($subscription->customer ?? '');
        $professional = $stripeCustomerId
            ? Professional::where('stripe_customer_id', $stripeCustomerId)->first()
            : null;

        if (! $professional) {
            $professionalId = $subscription->metadata?->sidest_professional_id ?? null;
            $professional = $professionalId ? Professional::find($professionalId) : null;
        }

        if (! $professional) {
            Log::warning('Stripe subscription.created: could not resolve professional', [
                'subscription_id' => $subscription->id,
                'customer_id' => $stripeCustomerId,
                'metadata_professional_id' => $subscription->metadata?->sidest_professional_id ?? null,
            ]);

            return;
        }

        $priceId = $subscription->items->data[0]->price->id ?? null;
        $plan = Plan::where('stripe_price_id', $priceId)->where('is_active', true)->first();

        if (! $plan) {
            Log::error('Stripe subscription.created with unknown price — customer charged but no local subscription created. Manual reconciliation required.', [
                'price_id' => $priceId,
                'professional_id' => $professional->id,
                'stripe_subscription_id' => $subscription->id,
            ]);

            return;
        }

        DB::transaction(function () use ($professional, $plan, $subscription, $event) {
            // End any existing active subscription (lockForUpdate prevents deadlocks)
            Subscription::query()
                ->where('professional_id', $professional->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->update(['ended_at' => now()]);

            // Create the new Stripe-managed subscription; stripe IDs set directly (not mass-assignable)
            $localSub = new Subscription([
                'id' => Str::uuid()->toString(),
                'professional_id' => $professional->id,
                'plan_id' => $plan->id,
                'provider' => 'stripe',
                'status' => $this->mapStripeStatus($subscription->status),
                'current_period_start' => Carbon::createFromTimestamp($subscription->current_period_start),
                'current_period_end' => Carbon::createFromTimestamp($subscription->current_period_end),
                'cancel_at_period_end' => $subscription->cancel_at_period_end ?? false,
                'provider_payload' => json_decode(json_encode($event), true),
            ]);
            $localSub->stripe_customer_id = (string) $subscription->customer;
            $localSub->stripe_subscription_id = (string) $subscription->id;
            $localSub->save();
        });

        Log::info('Stripe subscription created', [
            'professional_id' => $professional->id,
            'plan_key' => $plan->plan_key,
            'stripe_subscription_id' => $subscription->id,
        ]);
    }

    private function handleSubscriptionUpdated(object $subscription, object $event): void
    {
        $localSub = Subscription::where('stripe_subscription_id', $subscription->id)->first();

        if (! $localSub) {
            Log::debug('Stripe subscription.updated for unknown subscription', [
                'stripe_subscription_id' => $subscription->id,
            ]);

            return;
        }

        $updates = [
            'status' => $this->mapStripeStatus($subscription->status),
            'current_period_start' => Carbon::createFromTimestamp($subscription->current_period_start),
            'current_period_end' => Carbon::createFromTimestamp($subscription->current_period_end),
            'cancel_at_period_end' => $subscription->cancel_at_period_end ?? false,
            'provider_payload' => json_decode(json_encode($event), true),
        ];

        // Check if price changed (plan upgrade/downgrade)
        $priceId = $subscription->items->data[0]->price->id ?? null;
        if ($priceId) {
            $plan = Plan::where('stripe_price_id', $priceId)->where('is_active', true)->first();
            if ($plan && $plan->id !== $localSub->plan_id) {
                $updates['plan_id'] = $plan->id;
            }
        }

        $localSub->update($updates);

        Log::info('Stripe subscription updated', [
            'subscription_id' => $localSub->id,
            'status' => $updates['status'],
        ]);
    }

    private function handleSubscriptionDeleted(object $subscription, object $event): void
    {
        $localSub = Subscription::where('stripe_subscription_id', $subscription->id)->first();

        if (! $localSub) {
            Log::debug('Stripe subscription.deleted for unknown subscription', [
                'stripe_subscription_id' => $subscription->id,
            ]);

            return;
        }

        $localSub->update([
            'status' => Subscription::STATUS_CANCELED,
            'ended_at' => now(),
            'provider_payload' => json_decode(json_encode($event), true),
        ]);

        // For affiliates, fall back to free plan. Brands do NOT get a free fallback.
        $professional = $localSub->professional;
        if ($professional && $professional->professional_type !== 'brand') {
            app(SiteProvisioningService::class)->ensureFreeSubscription($professional);
        }

        Log::info('Stripe subscription deleted', [
            'subscription_id' => $localSub->id,
            'professional_id' => $localSub->professional_id,
            'fallback_to_free' => $professional?->professional_type !== 'brand',
        ]);
    }

    private function handleInvoicePaid(object $invoice, object $event): void
    {
        $stripeSubId = $invoice->subscription ?? null;
        if (! $stripeSubId) {
            return;
        }

        $localSub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
        if (! $localSub) {
            return;
        }

        $updates = [
            'provider_payload' => json_decode(json_encode($event), true),
        ];

        // Reset to active if was past_due (successful retry)
        if ($localSub->status === Subscription::STATUS_PAST_DUE) {
            $updates['status'] = Subscription::STATUS_ACTIVE;
        }

        // Update period dates from invoice
        if (isset($invoice->lines->data[0]->period)) {
            $period = $invoice->lines->data[0]->period;
            $updates['current_period_start'] = Carbon::createFromTimestamp($period->start);
            $updates['current_period_end'] = Carbon::createFromTimestamp($period->end);
        }

        $localSub->update($updates);

        Log::info('Stripe invoice paid', [
            'subscription_id' => $localSub->id,
            'status' => $localSub->fresh()->status,
        ]);
    }

    private function handleInvoicePaymentFailed(object $invoice, object $event): void
    {
        $stripeSubId = $invoice->subscription ?? null;
        if (! $stripeSubId) {
            return;
        }

        $localSub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
        if (! $localSub) {
            return;
        }

        $localSub->update([
            'status' => Subscription::STATUS_PAST_DUE,
            'provider_payload' => json_decode(json_encode($event), true),
        ]);

        // Notify the professional about the payment failure
        $professional = $localSub->professional;
        if ($professional) {
            app(NotificationPublisher::class)->publish(
                professionalId: $professional->id,
                frontendType: 'warning',
                category: 'subscriptions',
                title: 'Payment failed',
                body: 'Your subscription payment could not be processed. Please update your payment method to avoid service interruption.',
                dedupeKey: 'payment-failed-'.$localSub->id.'-'.now()->format('Y-m-d'),
                ctaUrl: '/account/billing',
                primaryActionLabel: 'Update Payment',
            );
        }

        Log::warning('Stripe invoice payment failed', [
            'subscription_id' => $localSub->id,
            'professional_id' => $localSub->professional_id,
        ]);
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active' => Subscription::STATUS_ACTIVE,
            'past_due' => Subscription::STATUS_PAST_DUE,
            'unpaid' => Subscription::STATUS_UNPAID,
            'canceled' => Subscription::STATUS_CANCELED,
            'incomplete' => Subscription::STATUS_INCOMPLETE,
            'incomplete_expired' => Subscription::STATUS_INCOMPLETE_EXPIRED,
            'trialing' => Subscription::STATUS_ACTIVE, // we don't use trials — map to active
            default => $stripeStatus,
        };
    }
}
