<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Enforces the canonical Shopify webhook ingestion sequence:
//   1. HMAC verification (401 on failure)
//   2. Atomic cache claim — no pre-check probe, so callers cannot infer dedup state
//      without a valid signature (Cache::add is the only dedup gate, not Cache::has)
//   3. Optional DB-level dedup (override claimWebhookEvent)
//   4. Shop domain lookup (200 if unknown)
//   5. JSON decode — 422 so Shopify retries; prevents silent event loss
//   6. dispatchWebhookJob — cache key released on failure so Shopify can retry
//
// Bugs fixed vs earlier controllers:
//   • Cache::has before HMAC → webhook-ID enumeration without a valid signature
//   • json_decode failure returning 200 → Shopify stops retrying, event lost permanently
//   • Uncaught dispatch exception left cache slot claimed → retries deduped, event lost
trait HandlesShopifyWebhook
{
    use DedupesShopifyWebhookEvent;
    use ValidatesShopifyWebhookHmac;

    /** Shopify topic string, e.g. "orders/paid". Used in log messages and DB dedup. */
    abstract protected function topic(): string;

    /**
     * Cache key prefix for atomic dedup, e.g. "shopify:webhook:order".
     * The trait appends ":{$webhookId}" to form the full key.
     */
    abstract protected function dedupCachePrefix(): string;

    /**
     * Dispatch the appropriate job. Only called after HMAC, dedup, shop lookup,
     * and JSON decode all succeed.
     */
    abstract protected function dispatchWebhookJob(
        ProfessionalIntegration $integration,
        array $payload,
        string $eventId,
    ): void;

    /**
     * Optional DB-level dedup hook. Returns true to proceed, false to short-circuit
     * as a duplicate. Override with:
     *   return $this->claimShopifyWebhookEvent($webhookId, $this->topic());
     * to enable the durable billing.webhook_events layer beneath the cache fast-path.
     */
    protected function claimWebhookEvent(string $webhookId): bool
    {
        return true;
    }

    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $eventId = (string) $request->header('X-Shopify-Event-Id', '');
        $shopDomain = mb_strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        // 1. HMAC first — dedup state is never exposed without a valid signature.
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning("Shopify {$this->topic()} webhook: invalid HMAC signature", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        // 2. Atomic cache claim — Cache::add returns false if the key exists,
        //    deduplicating without a separate Cache::has probe.
        $cacheKey = null;
        if ($webhookId !== '') {
            $cacheKey = "{$this->dedupCachePrefix()}:{$webhookId}";
            if (! Cache::add($cacheKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }

        // 3. DB-level dedup (opt-in — override claimWebhookEvent to enable).
        if (! $this->claimWebhookEvent($webhookId)) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        // 4. Shop domain lookup. Unknown domain is a soft 200 — not our shop.
        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            Log::warning("Shopify {$this->topic()} webhook: unknown shop domain", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->success(['received' => true]);
        }

        // 5. JSON decode — 422 tells Shopify to retry, preventing permanent event loss.
        //    Returning 200 on decode failure would silently discard the event.
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            Log::warning("Shopify {$this->topic()} webhook: malformed JSON body", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('malformed payload', 422);
        }

        // 6. Dispatch — release the cache slot on failure so Shopify can retry.
        //    Without this, an uncaught exception would leave the slot claimed and
        //    subsequent retries would be deduped, permanently losing the event.
        try {
            $this->dispatchWebhookJob($integration, $payload, $eventId);
        } catch (\Throwable $e) {
            if ($cacheKey !== null) {
                Cache::forget($cacheKey);
            }
            throw $e;
        }

        return $this->success(['received' => true]);
    }
}
