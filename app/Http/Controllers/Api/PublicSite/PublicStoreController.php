<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\CheckoutSession;
use App\Services\Public\PublicSiteResolver;
use App\Services\Store\BrandProductCatalogService;
use App\Services\Store\FeaturedProductsPayloadService;
use App\Services\Store\PublicStripeCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicStoreController extends ApiController
{
    use ResolvesSubdomainFromHost;

    public function __construct(
        private readonly PublicSiteResolver $siteResolver,
        private readonly FeaturedProductsPayloadService $featuredProductsPayloads,
        private readonly BrandProductCatalogService $catalog,
        private readonly PublicStripeCheckoutService $stripeCheckout,
    ) {}

    /**
     * GET /public/store/featured-products
     * GET /public/store/featured-products-by-slug (header-based fallback)
     * Returns default product selections payload for the resolved site.
     */
    public function featuredProducts(Request $request): JsonResponse
    {
        $subdomain = $this->resolveSiteSubdomain($request);
        if (! $subdomain) {
            return $this->error('Missing site identifier.', 400);
        }

        $site = $this->siteResolver->resolvePublishedSite($subdomain);
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $affiliateProfessionalId = (string) $site->professional_id;
        $payload = $this->safeFeaturedProductsPayload(
            $affiliateProfessionalId,
            'public_store',
            $subdomain
        );

        return $this->success($payload);
    }

    /**
     * POST /public/store/checkout-session
     * POST /public/store/checkout-session-by-slug (header-based fallback)
     * Creates deterministic attribution token for Shopify-confirmed checkout.
     */
    public function createCheckoutSession(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'line_items' => ['sometimes', 'array', 'max:100'],
            'line_items.*.brand_product_id' => ['sometimes', 'nullable', 'uuid'],
            'line_items.*.shopify_product_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_items.*.shopify_variant_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_items.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_items.*.quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'line_items.*.unit_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'line_items.*.line_total_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'context' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $subdomain = $this->resolveSiteSubdomain($request);
        if (! $subdomain) {
            return $this->error('Missing site identifier.', 400);
        }

        $site = $this->siteResolver->resolvePublishedSite($subdomain);
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $affiliateProfessionalId = (string) $site->professional_id;
        $connectedBrandIds = DB::table('core.brand_partner_links')
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->pluck('brand_professional_id')
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($connectedBrandIds === []) {
            return response()->json([
                'message' => 'No connected brand for storefront checkout.',
                'code' => 'NO_CONNECTED_BRAND',
            ], 422);
        }

        if (count($connectedBrandIds) > 1) {
            return response()->json([
                'message' => 'Multiple connected brands are not supported for checkout sessions.',
                'code' => 'MULTIPLE_BRANDS_NOT_SUPPORTED',
            ], 409);
        }

        $brandProfessionalId = $connectedBrandIds[0];
        $token = 'comet_session_'.Str::random(64);
        $ttlMinutes = max(5, (int) config('comet.store.checkout_session_ttl_minutes', 120));
        $expiresAt = now()->addMinutes($ttlMinutes);

        $currencyCode = strtoupper(trim((string) ($validated['currency_code'] ?? 'AUD')));
        if ($currencyCode === '') {
            $currencyCode = 'AUD';
        }

        CheckoutSession::query()->create([
            'token' => $token,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'brand_professional_id' => $brandProfessionalId,
            'site_id' => (string) $site->id,
            'status' => 'active',
            'expires_at' => $expiresAt,
            'context_snapshot' => [
                'source' => 'public_store_checkout_session',
                'subdomain' => $subdomain,
                'site_id' => (string) $site->id,
                'affiliate_professional_id' => $affiliateProfessionalId,
                'brand_professional_id' => $brandProfessionalId,
                'currency_code' => $currencyCode,
                'checkout_mode' => $this->resolveCheckoutMode($brandProfessionalId),
                'line_items' => $validated['line_items'] ?? [],
                'context' => $validated['context'] ?? [],
                'created_at' => now()->toIso8601String(),
            ],
        ]);

        return $this->success([
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'affiliate_professional_id' => $affiliateProfessionalId,
            'brand_professional_id' => $brandProfessionalId,
            'site_id' => (string) $site->id,
            'currency_code' => $currencyCode,
            'checkout_mode' => $this->resolveCheckoutMode($brandProfessionalId),
        ], 201);
    }

    /**
     * POST /public/store/stripe-checkout
     * POST /public/store/stripe-checkout-by-slug (header-based fallback)
     * Creates a hosted Stripe Checkout session for storefront orders.
     */
    public function createStripeCheckout(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'checkout_session_token' => ['required', 'string', 'max:255'],
            'success_url' => ['required', 'url'],
            'cancel_url' => ['required', 'url'],
            'customer' => ['required', 'array'],
            'customer.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.email' => ['required', 'string', 'email:rfc', 'max:255'],
            'customer.phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.address1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.address2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.zip' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $subdomain = $this->resolveSiteSubdomain($request);
        if (! $subdomain) {
            return $this->error('Missing site identifier.', 400);
        }

        $site = $this->siteResolver->resolvePublishedSite($subdomain);
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $checkoutSession = CheckoutSession::query()
            ->where('token', (string) $validated['checkout_session_token'])
            ->where('site_id', (string) $site->id)
            ->first();

        if (! $checkoutSession) {
            return $this->error('Checkout session not found.', 404);
        }

        if ((string) $checkoutSession->status !== 'active') {
            return $this->error('Checkout session is no longer active.', 422);
        }

        try {
            $result = $this->stripeCheckout->createHostedCheckoutSession(
                $checkoutSession,
                (array) $validated['customer'],
                (string) $validated['success_url'],
                (string) $validated['cancel_url'],
            );

            return $this->success($result, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * POST /public/store/payment-intent
     * Creates a PaymentIntent on the brand's Express account for embedded card checkout.
     */
    public function createPaymentIntent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'checkout_session_token' => ['required', 'string', 'max:255'],
            'customer' => ['required', 'array'],
            'customer.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.email' => ['required', 'string', 'email:rfc', 'max:255'],
            'customer.phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.address1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.zip' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        $validated = $validator->validated();
        $subdomain = $this->resolveSiteSubdomain($request);
        if (! $subdomain) {
            return $this->error('Missing site identifier.', 400);
        }

        $site = $this->siteResolver->resolvePublishedSite($subdomain);
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $checkoutSession = CheckoutSession::query()
            ->where('token', (string) $validated['checkout_session_token'])
            ->where('site_id', (string) $site->id)
            ->first();

        if (! $checkoutSession) {
            return $this->error('Checkout session not found.', 404);
        }

        if ((string) $checkoutSession->status !== 'active') {
            return $this->error('Checkout session is no longer active.', 422);
        }

        try {
            $result = $this->stripeCheckout->createPaymentIntent(
                $checkoutSession,
                (array) $validated['customer'],
            );

            return $this->success($result, 201);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /**
     * Removed during Shopify-canonical analytics cutover.
     */
    public function recordOrderAnalytics(): JsonResponse
    {
        return response()->json([
            'message' => 'Direct order analytics ingestion has been removed. Use checkout-session + Shopify order webhooks.',
            'code' => 'STORE_ORDER_ANALYTICS_REMOVED',
        ], 410);
    }

    private function resolveSiteSubdomain(Request $request): ?string
    {
        $fromHeader = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($fromHeader !== '') {
            return strtolower($fromHeader);
        }

        $fromQuery = trim((string) $request->query('slug', ''));
        if ($fromQuery !== '') {
            return strtolower($fromQuery);
        }

        $fromInput = trim((string) $request->input('slug', ''));
        if ($fromInput !== '') {
            return strtolower($fromInput);
        }

        $fromHost = $this->resolveSubdomainFromHost($request);
        if (is_string($fromHost) && $fromHost !== '') {
            return strtolower($fromHost);
        }

        return null;
    }

    private function resolveCheckoutMode(string $brandProfessionalId): string
    {
        $checkoutMode = BrandStoreSettings::query()
            ->where('professional_id', $brandProfessionalId)
            ->value('checkout_mode');

        $checkoutMode = strtolower(trim((string) $checkoutMode));

        return in_array($checkoutMode, ['shopify', 'stripe'], true)
            ? $checkoutMode
            : 'shopify';
    }

    private function resolveCheckoutModeForAffiliate(string $affiliateProfessionalId): string
    {
        if ($affiliateProfessionalId === '') {
            return 'shopify';
        }

        $brandProfessionalId = DB::table('core.brand_partner_links')
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->orderByDesc('created_at')
            ->value('brand_professional_id');

        $brandProfessionalId = trim((string) $brandProfessionalId);

        return $brandProfessionalId !== ''
            ? $this->resolveCheckoutMode($brandProfessionalId)
            : 'shopify';
    }

    /**
     * @return array{
     *   selected_products: array<int, array<string, mixed>>,
     *   default_product_selections: array<int, array<string, mixed>>,
     *   default_commission_rate: float,
     *   max_featured_products: int,
     *   max_default_product_selections: int,
     *   checkout_mode: string
     * }
     */
    private function safeFeaturedProductsPayload(string $affiliateProfessionalId, string $context, string $subdomain): array
    {
        try {
            return $this->featuredProductsPayloads->build($affiliateProfessionalId, $context);
        } catch (\Throwable $e) {
            Log::error('Public featured products payload build failed; falling back to direct catalog selections.', [
                'subdomain' => $subdomain,
                'affiliate_professional_id' => $affiliateProfessionalId,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);

            $selectedProducts = [];
            try {
                $selectedProducts = $this->catalog->selectedProductsForProfessional($affiliateProfessionalId);
            } catch (\Throwable $catalogError) {
                Log::warning('Public featured products catalog fallback failed.', [
                    'subdomain' => $subdomain,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'context' => $context,
                    'error' => $catalogError->getMessage(),
                ]);
            }

            $defaultCommissionRate = (float) config('comet.store.default_commission_rate', 15);
            $maxFeaturedProducts = (int) config('comet.store.max_featured_products', 10);

            return [
                'selected_products' => $selectedProducts,
                'default_product_selections' => $selectedProducts,
                'default_commission_rate' => $defaultCommissionRate,
                'max_featured_products' => $maxFeaturedProducts,
                'max_default_product_selections' => $maxFeaturedProducts,
                'checkout_mode' => $this->resolveCheckoutModeForAffiliate($affiliateProfessionalId),
            ];
        }
    }
}
