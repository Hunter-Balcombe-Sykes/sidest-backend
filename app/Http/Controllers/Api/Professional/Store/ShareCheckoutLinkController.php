<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShareCheckoutLinkController extends ApiController
{
    use ResolveCurrentProfessional;

    private const CART_CREATE_MUTATION = <<<'GRAPHQL'
mutation cartCreate($input: CartInput!) {
  cartCreate(input: $input) {
    cart {
      id
      checkoutUrl
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    public function __construct(
        private readonly AffiliateProductCatalogService $catalogService
    ) {}

    /**
     * POST /share/checkout-link
     *
     * Creates a Shopify cart pre-filled with the requested products and returns
     * the Shopify checkout URL. Product GIDs are resolved to variant GIDs via
     * the brand's cached catalog (first available variant per product).
     */
    public function store(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $validated = $request->validate([
            'affiliate_slug' => ['required', 'string'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.product_gid' => ['required', 'string'],
            'line_items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        if ($pro->handle !== $validated['affiliate_slug']) {
            return $this->error('Affiliate not found.', 404);
        }

        try {
            $resolved = $this->catalogService->resolveAffiliateBrandIntegration($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }

        $integration = $resolved['integration'];
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $storefrontToken = trim((string) ($integration->storefront_token ?? ''));

        if ($shopDomain === '' || $storefrontToken === '') {
            return $this->error('Storefront is not yet configured. Please try again shortly.', 503);
        }

        // Resolve product GIDs → variant GIDs from the brand's cached catalog.
        $catalog = $this->catalogService->fetchActiveCatalog($resolved['brand_professional_id']);
        $catalogByGid = [];
        foreach ($catalog as $product) {
            $catalogByGid[$product['gid'] ?? ''] = $product;
        }

        $lines = [];
        foreach ($validated['line_items'] as $item) {
            $productGid = $item['product_gid'];
            $quantity = (int) $item['quantity'];

            $product = $catalogByGid[$productGid] ?? null;
            if (! $product) {
                return $this->error("Product not found: {$productGid}", 422);
            }

            $variants = $product['variants'] ?? [];
            if (empty($variants)) {
                return $this->error("Product has no available variants: {$productGid}", 422);
            }

            $variantGid = $variants[0]['gid'] ?? '';
            if ($variantGid === '') {
                return $this->error("Product has no available variants: {$productGid}", 422);
            }

            $lines[] = [
                'merchandiseId' => $variantGid,
                'quantity' => $quantity,
            ];
        }

        $apiVersion = config('services.shopify.api_version', '2025-01');
        $url = "https://{$shopDomain}/api/{$apiVersion}/graphql.json";

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'X-Shopify-Storefront-Access-Token' => $storefrontToken,
                ])
                ->post($url, [
                    'query' => self::CART_CREATE_MUTATION,
                    'variables' => [
                        'input' => ['lines' => $lines],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Share checkout-link: Storefront API request failed.', [
                    'professional_id' => $pro->id,
                    'status' => $response->status(),
                ]);

                return $this->error('Unable to create checkout. Please try again.', 502);
            }

            $data = $response->json();

            if (! empty(Arr::get($data, 'errors', []))) {
                Log::warning('Share checkout-link: GraphQL errors.', [
                    'professional_id' => $pro->id,
                    'errors' => $data['errors'],
                ]);

                return $this->error('Unable to create checkout. Please try again.', 502);
            }

            $userErrors = Arr::get($data, 'data.cartCreate.userErrors', []);
            if (! empty($userErrors)) {
                Log::warning('Share checkout-link: cartCreate user errors.', [
                    'professional_id' => $pro->id,
                    'userErrors' => $userErrors,
                ]);

                return $this->error('Unable to create checkout. Please try again.', 502);
            }

            $checkoutUrl = Arr::get($data, 'data.cartCreate.cart.checkoutUrl');
            if (! $checkoutUrl) {
                return $this->error('Unable to create checkout. Please try again.', 502);
            }

            return $this->success(['checkout_url' => $checkoutUrl]);
        } catch (\Throwable $e) {
            Log::error('Share checkout-link: cart creation exception.', [
                'professional_id' => $pro->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Unable to create checkout. Please try again.', 502);
        }
    }
}
