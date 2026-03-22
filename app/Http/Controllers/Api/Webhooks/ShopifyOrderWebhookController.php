<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\Shopify\ProcessShopifyOrderEventJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\OrderEventInbox;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopifyOrderWebhookController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent() ?: '';
        $signature = trim((string) $request->header('x-shopify-hmac-sha256', ''));

        if (! $this->isValidSignature($rawBody, $signature)) {
            Log::warning('Shopify webhook rejected: invalid signature', [
                'ip' => $request->ip(),
                'topic' => $request->header('x-shopify-topic'),
            ]);

            return $this->error('Invalid Shopify webhook signature.', 401);
        }

        $webhookId = trim((string) $request->header('x-shopify-webhook-id', ''));
        if ($webhookId === '') {
            return $this->error('Missing Shopify webhook id.', 400);
        }

        $shopDomain = strtolower(trim((string) $request->header('x-shopify-shop-domain', '')));
        if ($shopDomain === '') {
            return $this->error('Missing Shopify shop domain.', 400);
        }

        $topic = trim((string) $request->header('x-shopify-topic', 'orders/unknown'));
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return $this->success(['received' => true, 'ignored' => 'invalid_payload']);
        }

        $integrationCandidates = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereRaw("lower(provider_metadata->>'shop_domain') = ?", [$shopDomain])
            ->get(['id', 'professional_id']);

        $resolutionStatus = 'pending';
        $rejectionReason = null;
        $integrationId = null;
        $brandProfessionalId = null;

        if ($integrationCandidates->count() === 1) {
            $integration = $integrationCandidates->first();
            $integrationId = (string) $integration->id;
            $brandProfessionalId = (string) $integration->professional_id;
        } elseif ($integrationCandidates->isEmpty()) {
            $resolutionStatus = 'rejected';
            $rejectionReason = 'MISSING_BRAND_RESOLUTION_FOR_SHOP_DOMAIN';
        } else {
            $resolutionStatus = 'rejected';
            $rejectionReason = 'AMBIGUOUS_BRAND_RESOLUTION_FOR_SHOP_DOMAIN';
        }

        try {
            $inbox = OrderEventInbox::query()->create([
                'source' => 'shopify_orders_webhook',
                'external_event_id' => $webhookId,
                'event_type' => $topic,
                'shop_domain' => $shopDomain,
                'integration_id' => $integrationId,
                'brand_professional_id' => $brandProfessionalId,
                'payload' => $payload,
                'headers' => $request->headers->all(),
                'status' => $resolutionStatus,
                'received_at' => now(),
                'processed_at' => $resolutionStatus === 'rejected' ? now() : null,
                'rejection_reason' => $rejectionReason,
                'last_error' => null,
            ]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23505') {
                return $this->success([
                    'received' => true,
                    'duplicate' => true,
                    'shop_domain' => $shopDomain,
                ]);
            }

            throw $e;
        }

        if ($resolutionStatus === 'rejected') {
            return $this->success([
                'received' => true,
                'queued' => false,
                'status' => 'rejected',
                'reason' => $rejectionReason,
                'inbox_id' => (string) $inbox->id,
            ]);
        }

        ProcessShopifyOrderEventJob::dispatch((string) $inbox->id);

        return $this->success([
            'received' => true,
            'queued' => true,
            'status' => 'pending',
            'inbox_id' => (string) $inbox->id,
            'shop_domain' => $shopDomain,
            'topic' => $topic,
        ]);
    }

    private function isValidSignature(string $rawBody, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $secret = trim((string) config('services.shopify.webhook_secret', ''));
        if ($secret === '') {
            Log::warning('Shopify webhook secret is not configured');

            return app()->environment(['local', 'testing']);
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }
}
