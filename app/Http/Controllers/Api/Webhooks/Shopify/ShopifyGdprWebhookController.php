<?php

namespace App\Http\Controllers\Api\Webhooks\Shopify;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ValidatesShopifyWebhookHmac;
use App\Jobs\Shopify\Gdpr\ExportCustomerDataJob;
use App\Jobs\Shopify\Gdpr\RedactCustomerJob;
use App\Jobs\Shopify\Gdpr\RedactShopJob;
use App\Models\Core\Gdpr\GdprRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Receives Shopify GDPR webhooks. Validates HMAC, writes an idempotent
// audit row, dispatches a dedicated job per topic onto the `gdpr` queue,
// returns 202. Invalid HMAC returns 401 — returning 200 on a bad signature
// is a silent-acceptance vuln.
class ShopifyGdprWebhookController extends ApiController
{
    use ValidatesShopifyWebhookHmac;

    public function customersDataRequest(Request $request): JsonResponse
    {
        return $this->handleGdprWebhook($request, GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST);
    }

    public function customersRedact(Request $request): JsonResponse
    {
        return $this->handleGdprWebhook($request, GdprRequest::TOPIC_CUSTOMERS_REDACT);
    }

    public function shopRedact(Request $request): JsonResponse
    {
        return $this->handleGdprWebhook($request, GdprRequest::TOPIC_SHOP_REDACT);
    }

    private function handleGdprWebhook(Request $request, string $topic): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
        $shopDomain = mb_strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning("Shopify GDPR webhook ({$topic}): invalid HMAC signature", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        // Validate BEFORE computing hash — if a malformed payload gets cached as
        // RECEIVED, every Shopify retry is silently deduplicated and the compliance
        // action (deletion/export) never runs. Rejecting with 422 tells Shopify to
        // keep retrying so a clean delivery can succeed.
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            Log::warning("Shopify GDPR webhook ({$topic}): malformed JSON body", [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('malformed payload', 422);
        }

        if (! $this->hasRequiredFields($topic, $payload)) {
            Log::warning("Shopify GDPR webhook ({$topic}): payload missing required fields", [
                'shop_domain' => $shopDomain,
                'fields_present' => array_keys($payload),
            ]);

            return $this->error('malformed payload', 422);
        }

        $hash = hash('sha256', $rawBody);

        // firstOrCreate on payload_hash gives us Shopify-retry idempotency:
        // the unique index fails insert on a duplicate, Eloquent fetches the
        // existing row, wasRecentlyCreated=false and we skip dispatch.
        $audit = GdprRequest::firstOrCreate(
            ['payload_hash' => $hash],
            [
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'shopify_shop_id' => is_numeric($payload['shop_id'] ?? null) ? (int) $payload['shop_id'] : null,
                'payload' => $payload,
                'status' => GdprRequest::STATUS_RECEIVED,
                'received_at' => now(),
            ],
        );

        if ($audit->wasRecentlyCreated) {
            match ($topic) {
                GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST => ExportCustomerDataJob::dispatch($audit->id),
                GdprRequest::TOPIC_CUSTOMERS_REDACT => RedactCustomerJob::dispatch($audit->id),
                GdprRequest::TOPIC_SHOP_REDACT => RedactShopJob::dispatch($audit->id),
            };

            Log::info("Shopify GDPR webhook accepted: {$topic}", [
                'gdpr_request_id' => $audit->id,
                'shop_domain' => $shopDomain,
            ]);
        } else {
            Log::info("Shopify GDPR webhook deduplicated: {$topic}", [
                'gdpr_request_id' => $audit->id,
                'shop_domain' => $shopDomain,
            ]);
        }

        return $this->success(['received' => true], 202);
    }

    private function hasRequiredFields(string $topic, array $payload): bool
    {
        if ($topic === GdprRequest::TOPIC_SHOP_REDACT) {
            // shop_domain from the header is sufficient; shop_id is optional
            return true;
        }

        // Both customer topics require email so the job can locate the customer
        return ! empty($payload['customer']['email'] ?? null);
    }
}
