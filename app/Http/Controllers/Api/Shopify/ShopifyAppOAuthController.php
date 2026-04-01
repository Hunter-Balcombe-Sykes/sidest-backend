<?php

namespace App\Http\Controllers\Api\Shopify;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Jobs\Shopify\RegisterShopifyOrderWebhooksJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyAppOAuthController extends ApiController
{
    use NormalizesShopDomain;

    /**
     * Step 1 — Shopify hits this URL when the merchant clicks install.
     * We validate the request and redirect to Shopify's OAuth consent screen.
     */
    public function install(Request $request): RedirectResponse|JsonResponse
    {
        $shop = $this->normalizeShopDomain((string) $request->query('shop', ''));

        if ($shop === '') {
            return $this->error('Missing shop parameter.', 400);
        }

        if (! $this->isValidShopDomain($shop)) {
            return $this->error('Invalid shop domain.', 400);
        }

        $apiKey = (string) config('services.shopify.api_key');
        $scopes = (string) config('services.shopify.app_scopes', 'read_products,read_orders,write_orders');
        $redirectUri = rtrim((string) config('app.url'), '/') . '/api/shopify/callback';
        $nonce = bin2hex(random_bytes(16));

        // Store nonce in cache for 10 minutes to validate on callback
        cache()->put("shopify_oauth_nonce_{$shop}", $nonce, now()->addMinutes(10));

        $authUrl = "https://{$shop}/admin/oauth/authorize?"
            . http_build_query([
                'client_id' => $apiKey,
                'scope' => $scopes,
                'redirect_uri' => $redirectUri,
                'state' => $nonce,
            ]);

        return redirect()->away($authUrl);
    }

    /**
     * Step 2 — Shopify redirects back here with a code after the merchant approves.
     * We exchange the code for an access token and store it.
     */
    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        $shop = $this->normalizeShopDomain((string) $request->query('shop', ''));
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $hmac = (string) $request->query('hmac', '');

        if ($shop === '' || $code === '' || $state === '' || $hmac === '') {
            return $this->error('Missing required OAuth parameters.', 400);
        }

        // Validate HMAC
        if (! $this->isValidHmac($request->query(), (string) config('services.shopify.api_secret'))) {
            Log::warning('Shopify OAuth: invalid HMAC', ['shop' => $shop]);
            return $this->error('Invalid HMAC signature.', 400);
        }

        // Validate nonce
        $expectedNonce = cache()->pull("shopify_oauth_nonce_{$shop}");
        if ($expectedNonce === null || ! hash_equals($expectedNonce, $state)) {
            Log::warning('Shopify OAuth: invalid nonce', ['shop' => $shop]);
            return $this->error('Invalid state parameter.', 400);
        }

        // Exchange code for access token
        $tokenResponse = Http::post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.api_key'),
            'client_secret' => config('services.shopify.api_secret'),
            'code' => $code,
        ]);

        if (! $tokenResponse->successful()) {
            Log::error('Shopify OAuth: token exchange failed', [
                'shop' => $shop,
                'status' => $tokenResponse->status(),
            ]);
            return $this->error('Failed to exchange OAuth code for access token.', 502);
        }

        $accessToken = (string) ($tokenResponse->json('access_token') ?? '');
        $scopes = (array) ($tokenResponse->json('scope') ? explode(',', (string) $tokenResponse->json('scope')) : []);

        if ($accessToken === '') {
            return $this->error('Empty access token received from Shopify.', 502);
        }

        // Fetch shop details
        $shopResponse = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
        ])->get("https://{$shop}/admin/api/" . config('services.shopify.api_version') . "/shop.json");

        $shopId = null;
        if ($shopResponse->successful()) {
            $shopId = (string) ($shopResponse->json('shop.id') ?? '');
        }

        // Store the integration — find by shop domain, create or update
        $integration = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('external_account_id', $shop)
            ->first();

        $existingMetadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];

        $metadata = array_merge($existingMetadata, [
            'shop_domain' => $shop,
            'shop_id' => $shopId ?: ($existingMetadata['shop_id'] ?? null),
            'scopes' => array_values(array_filter(array_map('trim', $scopes))),
            'webhook_orders_topic' => (string) config('services.shopify.webhook_orders_topic', 'orders/paid'),
            'connected_at' => now()->toIso8601String(),
            'oauth_install' => true,
            'webhook_registration_state' => 'queued',
        ]);

        $integration = ProfessionalIntegration::query()->updateOrCreate(
            [
                'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
                'external_account_id' => $shop,
            ],
            [
                'access_token' => $accessToken,
                'provider_metadata' => $metadata,
            ]
        );

        // Queue webhook registration
        try {
            RegisterShopifyOrderWebhooksJob::dispatch((string) $integration->id);
        } catch (\Throwable $e) {
            Log::warning('Shopify OAuth: failed to queue webhook registration', [
                'shop' => $shop,
                'integration_id' => (string) $integration->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Shopify app installed via OAuth', [
            'shop' => $shop,
            'shop_id' => $shopId,
            'integration_id' => (string) $integration->id,
        ]);

        // Redirect to the Side St dashboard
        $dashboardUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'https://app.sidest.co')), '/');

        return redirect()->away("{$dashboardUrl}/account/commerce");
    }

    private function isValidShopDomain(string $shop): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9\-]+\.myshopify\.com$/', $shop);
    }

    /**
     * Validates the HMAC signature Shopify sends with OAuth callbacks.
     */
    private function isValidHmac(array $params, string $secret): bool
    {
        $params = array_filter($params, static fn ($key) => $key !== 'hmac', ARRAY_FILTER_USE_KEY);
        ksort($params);
        $message = http_build_query($params);
        $expected = hash_hmac('sha256', $message, $secret);
        $actual = (string) ($params['hmac'] ?? request()->query('hmac', ''));

        return hash_equals($expected, $actual);
    }
}
