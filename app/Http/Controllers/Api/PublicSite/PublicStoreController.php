<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Services\Public\PublicSiteResolver;
use App\Services\Store\FeaturedProductsPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicStoreController extends ApiController
{
    use ResolvesSubdomainFromHost;

    public function __construct(
        private readonly PublicSiteResolver $siteResolver,
        private readonly FeaturedProductsPayloadService $featuredProductsPayloads
    ) {}

    /**
     * GET /public/store/featured-products
     * GET /public/store/featured-products-by-slug (header-based fallback)
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
