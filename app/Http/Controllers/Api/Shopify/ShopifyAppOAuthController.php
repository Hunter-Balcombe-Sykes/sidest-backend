<?php

namespace App\Http\Controllers\Api\Shopify;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Services\Shopify\BrandSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// V2: Core. Shopify app install OAuth flow (HMAC validation, token exchange, shop details). Creates brand account on install.
class ShopifyAppOAuthController extends ApiController
{
    use NormalizesShopDomain;

    public function __construct(
        private readonly BrandSignupService $brandSignup,
    ) {}

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
     * We exchange the code for an access token, create the brand account, and redirect
     * to the embedded app.
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

        // Fetch full shop details
        $shopResponse = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
        ])->get("https://{$shop}/admin/api/" . config('services.shopify.api_version') . "/shop.json");

        $shopData = [];
        if ($shopResponse->successful()) {
            $shopData = (array) ($shopResponse->json('shop') ?? []);
        }

        // Create brand account (or handle reinstall)
        try {
            $result = $this->brandSignup->handleOAuthCallback($shop, $accessToken, $shopData, $scopes);
        } catch (\Throwable $e) {
            Log::error('Shopify OAuth: brand signup failed', [
                'shop' => $shop,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Failed to create brand account.', 500);
        }

        Log::info('Shopify OAuth callback successful', [
            'shop' => $shop,
            'professional_id' => (string) $result->professional->id,
            'is_reinstall' => $result->isReinstall,
        ]);

        // Build redirect to embedded app
        $shopHandle = str_replace('.myshopify.com', '', $shop);
        $appHandle = (string) config('services.shopify.app_handle', 'side-st');
        $basePath = "https://admin.shopify.com/store/{$shopHandle}/apps/{$appHandle}";

        if ($result->isReinstall) {
            return redirect()->away($basePath);
        }

        return redirect()->away("{$basePath}/setup");
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
        $actual = (string) ($params['hmac'] ?? '');
        $filtered = array_filter($params, static fn ($key) => $key !== 'hmac', ARRAY_FILTER_USE_KEY);
        ksort($filtered);
        $message = http_build_query($filtered);
        $expected = hash_hmac('sha256', $message, $secret);

        return $actual !== '' && hash_equals($expected, $actual);
    }
}
