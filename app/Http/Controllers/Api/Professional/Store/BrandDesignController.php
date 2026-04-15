<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Resources\BrandDesignResource;
use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Brand design surface. Reads the unified design shape from site.settings.design and
// triggers a Shopify re-sync. The old theme_tokens / sitepage_overrides storage was
// retired — the Shopify importer now writes directly into site.settings.design, and
// the brand edits that same JSON from the dashboard (single source of truth).
class BrandDesignController extends ApiController
{
    use ResolveCurrentProfessional;

    // Defaults applied when a brand hasn't made an explicit selection yet.
    // Each enum's "middle" value is treated as the resting default so new
    // brands land on a sensible shape/weight/spacing without having to pick.
    // Resolution happens on read (here) — stored value stays NULL so the
    // difference between "default by convention" and "explicitly default" is
    // preserved at the DB layer.
    private const DEFAULT_FONT_FAMILY = 'helvetica_neue';
    private const DEFAULT_CORNER_RADIUS = 'default';
    private const DEFAULT_BORDER_THICKNESS = 'default';
    private const DEFAULT_SECTION_SPACING = 'default';

    /**
     * Return the current resolved brand-design shape for the authenticated brand.
     *
     * @return JsonResponse {
     *     colors: { background, text, accent, border },
     *     corner_radius: 'square'|'default'|'pill',          (default applied upstream)
     *     border_thickness: 'hairline'|'default'|'bold',     (default applied upstream)
     *     section_spacing: 'tight'|'default'|'spacious',     (default applied upstream)
     *     logo: { full_url, square_url },
     *     slogan: string|null,
     *     font_family: string,
     *     shopify_connected: bool
     * }
     */
    public function show(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $site = Site::where('professional_id', $pro->id)->first();
        $settings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];

        $colors = is_array($design['colors'] ?? null) ? $design['colors'] : [];
        $logo = is_array($design['logo'] ?? null) ? $design['logo'] : [];

        return $this->success(new BrandDesignResource([
            'colors' => [
                'background' => $colors['background'] ?? null,
                'text' => $colors['text'] ?? null,
                'accent' => $colors['accent'] ?? null,
                'border' => $colors['border'] ?? null,
            ],
            // Fall back to the "middle" value for any unset bucket so the UI
            // always has a selected option — mirrors the font_family fallback.
            'corner_radius' => is_string($design['corner_radius'] ?? null) && $design['corner_radius'] !== ''
                ? $design['corner_radius']
                : self::DEFAULT_CORNER_RADIUS,
            'border_thickness' => is_string($design['border_thickness'] ?? null) && $design['border_thickness'] !== ''
                ? $design['border_thickness']
                : self::DEFAULT_BORDER_THICKNESS,
            'section_spacing' => is_string($design['section_spacing'] ?? null) && $design['section_spacing'] !== ''
                ? $design['section_spacing']
                : self::DEFAULT_SECTION_SPACING,
            'logo' => [
                'full_url' => $logo['full_url'] ?? null,
                'square_url' => $logo['square_url'] ?? null,
            ],
            'slogan' => $design['slogan'] ?? null,
            // Fall back to the default for any brand whose row predates the
            // seed migration or who explicitly cleared their selection.
            'font_family' => is_string($design['font_family'] ?? null) && $design['font_family'] !== ''
                ? $design['font_family']
                : self::DEFAULT_FONT_FAMILY,
            'shopify_connected' => $this->brandIntegration($pro->id) !== null,
        ]));
    }

    /**
     * Trigger a full refresh of brand design values from the brand's Shopify store.
     * The importer overwrites any field for which Shopify returns a value; fields
     * Shopify has no answer for are left as-is (so user edits in Sidest persist).
     */
    public function resync(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $integration = $this->brandIntegration($pro->id);

        if (! $integration) {
            return $this->error('Your Shopify store is not connected.', 422);
        }

        SyncShopifyBrandDesignJob::dispatch((string) $integration->id);

        return $this->success([
            'status' => 'queued',
            'message' => 'Brand design refresh queued. Values will update shortly.',
        ], 202);
    }

    private function brandIntegration(string $professionalId): ?ProfessionalIntegration
    {
        return ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();
    }
}
