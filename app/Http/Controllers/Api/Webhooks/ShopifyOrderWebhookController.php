<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Jobs\Shopify\ProcessShopifyOrderEventJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Retail\OrderEventInbox;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShopifyOrderWebhookController extends ApiController
{
    use NormalizesShopDomain;

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

        return $this->ingestOrderEvent(
            source: 'shopify_orders_webhook',
            externalEventId: $webhookId,
            topic: $topic,
            shopDomain: $shopDomain,
            payload: $payload,
            headers: $request->headers->all()
        );
    }

    public function fallback(Request $request): JsonResponse
    {
        $rawBody = $request->getContent() ?: '';
        $signature = trim((string) $request->header('x-comet-fallback-signature', ''));
        if (! $this->isValidFallbackSignature($rawBody, $signature)) {
            Log::warning('Shopify fallback webhook rejected: invalid signature', [
                'ip' => $request->ip(),
            ]);

            return $this->error('Invalid fallback signature.', 401);
        }

        $validated = $request->validate([
            'shop_domain' => ['required', 'string', 'max:255'],
            'order_id' => ['required', 'string', 'max:255'],
            'event_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payload' => ['sometimes', 'nullable', 'array'],
        ]);

        $shopDomain = $this->normalizeShopDomain((string) ($validated['shop_domain'] ?? ''));
        if ($shopDomain === '') {
            return $this->error('Invalid shop domain.', 422);
        }

        $integrationCandidates = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereRaw("lower(provider_metadata->>'shop_domain') = ?", [$shopDomain])
            ->get(['id', 'professional_id', 'access_token', 'provider_metadata']);

        if ($integrationCandidates->count() !== 1) {
            $reason = $integrationCandidates->isEmpty()
                ? 'MISSING_BRAND_RESOLUTION_FOR_SHOP_DOMAIN'
                : 'AMBIGUOUS_BRAND_RESOLUTION_FOR_SHOP_DOMAIN';

            return $this->success([
                'received' => true,
                'queued' => false,
                'status' => 'rejected',
                'reason' => $reason,
            ]);
        }

        $integration = $integrationCandidates->first();
        if (! $integration instanceof ProfessionalIntegration) {
            return $this->error('Shopify integration resolution failed.', 422);
        }

        $payload = $validated['payload'] ?? null;
        if (! is_array($payload) || $payload === []) {
            try {
                $payload = $this->fetchOrderPayloadFromShopify(
                    integration: $integration,
                    shopDomain: $shopDomain,
                    orderIdInput: (string) ($validated['order_id'] ?? '')
                );
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Shopify fallback fetch failed', [
                    'shop_domain' => $shopDomain,
                    'integration_id' => (string) $integration->id,
                    'order_id' => (string) ($validated['order_id'] ?? ''),
                    'message' => $e->getMessage(),
                ]);

                return $this->error('Unable to fetch Shopify order payload for fallback ingestion.', 422);
            }
        }

        if (! is_array($payload)) {
            return $this->error('Invalid payload for fallback ingestion.', 422);
        }

        $fallbackOrderId = trim((string) ($payload['id'] ?? ($validated['order_id'] ?? '')));
        $externalEventId = trim((string) ($validated['event_id'] ?? ''));
        if ($externalEventId === '') {
            $externalEventId = 'fallback_order_'.$fallbackOrderId;
        }

        $topic = trim((string) ($validated['topic'] ?? 'orders/fallback'));

        return $this->ingestOrderEvent(
            source: 'shopify_orders_fallback',
            externalEventId: $externalEventId,
            topic: $topic,
            shopDomain: $shopDomain,
            payload: $payload,
            headers: $request->headers->all()
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    private function ingestOrderEvent(
        string $source,
        string $externalEventId,
        string $topic,
        string $shopDomain,
        array $payload,
        array $headers
    ): JsonResponse {
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
                'source' => $source,
                'external_event_id' => $externalEventId,
                'event_type' => $topic,
                'shop_domain' => $shopDomain,
                'integration_id' => $integrationId,
                'brand_professional_id' => $brandProfessionalId,
                'payload' => $payload,
                'headers' => $headers,
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

    /**
     * @return array<string, mixed>
     */
    private function fetchOrderPayloadFromShopify(
        ProfessionalIntegration $integration,
        string $shopDomain,
        string $orderIdInput
    ): array {
        $accessToken = trim((string) $integration->access_token);
        if ($accessToken === '') {
            throw ValidationException::withMessages([
                'shop_domain' => ['Connected Shopify integration is missing an access token.'],
            ]);
        }

        $shopDomain = $this->normalizeShopDomain($shopDomain);
        if ($shopDomain === '') {
            throw ValidationException::withMessages([
                'shop_domain' => ['Invalid shop domain.'],
            ]);
        }

        $numericOrderId = $this->extractNumericOrderId($orderIdInput);
        if ($numericOrderId === null) {
            throw ValidationException::withMessages([
                'order_id' => ['order_id must contain a Shopify numeric order id or gid.'],
            ]);
        }

        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));
        if ($apiVersion === '') {
            $apiVersion = '2025-01';
        }

        $url = sprintf(
            'https://%s/admin/api/%s/orders/%s.json',
            $shopDomain,
            $apiVersion,
            $numericOrderId
        );

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Accept' => 'application/json',
        ])
            ->timeout(20)
            ->retry(2, 200)
            ->get($url, ['status' => 'any']);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Shopify order lookup failed with status %d.',
                (int) $response->status()
            ));
        }

        $order = $response->json('order');
        if (! is_array($order)) {
            throw new \RuntimeException('Shopify order lookup returned no order payload.');
        }

        return $order;
    }

    private function extractNumericOrderId(string $orderIdInput): ?string
    {
        $orderIdInput = trim($orderIdInput);
        if ($orderIdInput === '') {
            return null;
        }

        if (preg_match('/(\d+)(?!.*\d)/', $orderIdInput, $matches) !== 1) {
            return null;
        }

        return $matches[1];
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

    private function isValidFallbackSignature(string $rawBody, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $secret = trim((string) config('services.shopify.fallback_secret', ''));
        if ($secret === '') {
            Log::warning('Shopify fallback secret is not configured');

            return app()->environment(['local', 'testing']);
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }
}
