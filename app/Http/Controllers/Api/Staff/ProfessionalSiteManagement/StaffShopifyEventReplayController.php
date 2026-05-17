<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Exceptions\Shopify\InvalidShopDomainException;
use App\Exceptions\Shopify\ShopifyTransportException;
use App\Http\Controllers\Api\ApiController;
use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderEvent;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\ShopDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

// WEBHOOK-1: Staff endpoint to replay a single Shopify webhook event from
// commerce.order_events. The replay re-fetches the order from Shopify (since the
// stored event metadata only keeps {shopify_order_id, financial_status}) and
// re-dispatches the same ProcessShopifyOrderWebhookJob with the original
// shopify_event_id. The job's existing dedup guarantees this is safe by
// construction:
//   - commerce.order_events.shopify_event_id unique partial index catches the
//     duplicate insert and silently no-ops.
//   - commerce.orders LWW upsert (WHERE EXCLUDED.shopify_updated_at >
//     orders.shopify_updated_at) means a re-fetch that returns the same
//     payload as before is a true no-op.
// We do NOT bypass the dedup — replay is a "re-attempt", not a "force-overwrite".
class StaffShopifyEventReplayController extends ApiController
{
    public function __construct(
        private readonly ShopifyAdminClient $shopifyClient,
    ) {}

    /**
     * POST /api/staff/professionals/{professional}/shopify/events/replay
     *
     * Body: { shopify_event_id: string }
     *
     * @return JsonResponse with shape:
     *                      {
     *                      replayed: true,
     *                      shopify_event_id: string,
     *                      order_id: uuid,            // commerce.orders.id
     *                      shopify_order_id: string,
     *                      already_processed: bool,   // true if event existed before dispatch (always true here, since lookup precedes dispatch)
     *                      dispatched: bool,          // true if the job ran sync
     *                      }
     */
    public function invoke(Request $request, Professional $professional): JsonResponse
    {
        $validated = $request->validate([
            'shopify_event_id' => ['required', 'string', 'max:255'],
        ]);
        $shopifyEventId = (string) $validated['shopify_event_id'];

        // Look up the event and join through to the linked order. The order's
        // brand_professional_id is what scopes the event to {professional} — using
        // 404 (not 403) for cross-tenant events so we don't reveal that the event
        // exists for some other brand.
        $event = OrderEvent::query()
            ->where('shopify_event_id', $shopifyEventId)
            ->first();

        if (! $event) {
            return $this->error('Event not found.', 404);
        }

        $order = Order::query()->find($event->order_id);

        if (! $order || (string) $order->brand_professional_id !== (string) $professional->id) {
            return $this->error('Event not found.', 404);
        }

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration || empty($integration->access_token)) {
            return $this->error('No Shopify integration found for this professional.', 404);
        }

        // Rate-limit per event to prevent staff (or a stuck UI) from looping a
        // single event replay and burning the brand's Shopify rate-limit budget.
        // 3 / minute is enough to retry a failed dispatch but small enough that
        // a hot loop trips fast.
        $rateLimitKey = "shopify-event-replay:{$shopifyEventId}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return $this->error('Replay is rate-limited for this event. Try again shortly.', 429)
                ->header('Retry-After', (string) $retryAfter);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $shopDomain = (string) $order->shopify_shop_domain;
        $shopifyOrderId = (string) $order->shopify_order_id;

        try {
            $shop = ShopDomain::fromUntrusted($shopDomain);
        } catch (InvalidShopDomainException $e) {
            return $this->error('Stored shop domain is invalid: '.$e->getMessage(), 422);
        }

        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));
        $path = '/admin/api/'.$apiVersion.'/orders/'.$shopifyOrderId.'.json';

        try {
            $response = $this->shopifyClient->rest(
                method: 'GET',
                shop: $shop,
                accessToken: (string) $integration->access_token,
                path: $path,
            );
        } catch (ShopifyTransportException $e) {
            Log::warning('StaffShopifyEventReplay: Shopify fetch failed', [
                'shopify_event_id' => $shopifyEventId,
                'shopify_order_id' => $shopifyOrderId,
                'shop_domain' => $shopDomain,
                'status' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            return $this->error('Unable to fetch order from Shopify: '.$e->getMessage(), 502);
        }

        $orderPayload = $response->json('order');

        if (! is_array($orderPayload) || empty($orderPayload)) {
            return $this->error('Shopify returned an empty order payload.', 502);
        }

        $staff = $request->attributes->get('partna_staff');

        Log::info('StaffShopifyEventReplay: dispatching', [
            'actor_staff_id' => $staff ? (string) $staff->id : null,
            'brand_professional_id' => (string) $professional->id,
            'shopify_event_id' => $shopifyEventId,
            'shopify_order_id' => $shopifyOrderId,
            'order_id' => (string) $order->id,
        ]);

        // dispatchSync so any failure surfaces in the staff response (not a
        // queue limbo). Source 'manual' distinguishes replay rows from genuine
        // webhook deliveries in commerce.order_events.source for later audit.
        // Same shopify_event_id ensures the unique-index short-circuit fires.
        ProcessShopifyOrderWebhookJob::dispatchSync(
            brandProfessionalId: (string) $professional->id,
            orderPayload: $orderPayload,
            shopifyEventId: $shopifyEventId,
            source: 'manual',
        );

        // The event existed before we dispatched, so the dedup branch was
        // exercised and no second event row was inserted. The order upsert is
        // LWW-guarded, so it's also a no-op unless Shopify's shopify_updated_at
        // has moved forward since the original delivery.
        return $this->success([
            'replayed' => true,
            'shopify_event_id' => $shopifyEventId,
            'order_id' => (string) $order->id,
            'shopify_order_id' => $shopifyOrderId,
            'already_processed' => true,
            'dispatched' => true,
        ]);
    }
}
