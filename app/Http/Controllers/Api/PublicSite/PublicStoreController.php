<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Models\Retail\ProfessionalSelection;
use App\Services\Public\PublicSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicStoreController extends ApiController
{
    use ResolvesSubdomainFromHost;

    private ?bool $commissionOverrideSupported = null;
    private ?bool $selectionsTableAvailable = null;
    private PublicSiteResolver $siteResolver;

    public function __construct(PublicSiteResolver $siteResolver)
    {
        $this->siteResolver = $siteResolver;
    }

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

        $defaultRate = (float) config('comet.store.default_commission_rate', 15);
        $maxFeatured = (int) config('comet.store.max_featured_products', 10);
        $supportsCommissionOverride = $this->supportsCommissionOverride() && $this->hasSelectionsTable();

        if (! $this->hasSelectionsTable()) {
            return $this->success([
                'selected_products' => $this->getLegacySelectedProducts($site->settings),
                'default_commission_rate' => $defaultRate,
                'max_featured_products' => $maxFeatured,
            ]);
        }

        $columns = ['id', 'shopify_product_id', 'sort_order'];
        if ($supportsCommissionOverride) {
            $columns[] = 'commission_override';
        }

        try {
            $selections = ProfessionalSelection::query()
                ->where('professional_id', $site->professional_id)
                ->orderBy('sort_order')
                ->get($columns);
        } catch (Throwable $e) {
            Log::warning('Public featured-products lookup failed; falling back to legacy settings.', [
                'site_id' => (string) $site->id,
                'professional_id' => (string) $site->professional_id,
                'error' => $e->getMessage(),
            ]);

            return $this->success([
                'selected_products' => $this->getLegacySelectedProducts($site->settings),
                'default_commission_rate' => $defaultRate,
                'max_featured_products' => $maxFeatured,
            ]);
        }

        return $this->success([
            'selected_products' => $this->toSelectionResponse($selections, $supportsCommissionOverride),
            'default_commission_rate' => $defaultRate,
            'max_featured_products' => $maxFeatured,
        ]);
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

    private function hasSelectionsTable(): bool
    {
        if ($this->selectionsTableAvailable !== null) {
            return $this->selectionsTableAvailable;
        }

        try {
            $result = DB::selectOne("select to_regclass('retail.professional_selections') as table_name");
            $this->selectionsTableAvailable = isset($result->table_name) && $result->table_name !== null;
        } catch (Throwable $e) {
            Log::warning('Could not verify retail.professional_selections availability (public store).', [
                'error' => $e->getMessage(),
            ]);
            $this->selectionsTableAvailable = false;
        }

        return $this->selectionsTableAvailable;
    }

    private function supportsCommissionOverride(): bool
    {
        if ($this->commissionOverrideSupported !== null) {
            return $this->commissionOverrideSupported;
        }

        try {
            $this->commissionOverrideSupported = DB::table('information_schema.columns')
                ->where('table_schema', 'retail')
                ->where('table_name', 'professional_selections')
                ->where('column_name', 'commission_override')
                ->exists();
        } catch (Throwable $e) {
            Log::warning('Could not verify commission_override column (public store).', [
                'error' => $e->getMessage(),
            ]);
            $this->commissionOverrideSupported = false;
        }

        return $this->commissionOverrideSupported;
    }

    private function toSelectionResponse(Collection $rows, bool $supportsCommissionOverride): Collection
    {
        return $rows->map(function ($row) use ($supportsCommissionOverride) {
            return [
                'id' => $row->id,
                'shopify_product_id' => $row->shopify_product_id,
                'sort_order' => $row->sort_order,
                'commission_override' => $supportsCommissionOverride ? $row->commission_override : null,
            ];
        })->values();
    }

    /**
     * @param  mixed  $settings
     * @return array<int, mixed>
     */
    private function getLegacySelectedProducts($settings): array
    {
        $siteSettings = is_array($settings) ? $settings : [];
        $selectedProducts = $siteSettings['selected_products'] ?? [];
        return is_array($selectedProducts) ? array_values($selectedProducts) : [];
    }
}
