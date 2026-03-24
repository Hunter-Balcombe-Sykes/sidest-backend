<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Models\Retail\CheckoutSession;
use App\Services\Public\PublicSiteResolver;
use App\Services\Store\FeaturedProductsPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicStoreController extends ApiController
{
    use ResolvesSubdomainFromHost;

    public function __construct(
        private readonly PublicSiteResolver $siteResolver,
        private readonly FeaturedProductsPayloadService $featuredProductsPayloads,
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

        return $this->success(
            $this->featuredProductsPayloads->build(
                (string) $site->professional_id,
                'public_store'
            )
        );
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
        ], 201);
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
}
