<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\BrandDesignResource;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Media\BrandDesignMediaService;
use Illuminate\Http\JsonResponse;

// Staff inspector for the resolved brand-design shape (#BRAND-DESIGN-1, read part).
// Mirrors BrandDesignController::show. Resync POST is an admin write and not part
// of this bundle — a future session can extend the admin-write group.
class StaffBrandDesignController extends ApiController
{
    // Defaults must stay in sync with the brand-facing controller so a missing key
    // resolves to the same selected option on staff vs brand dashboards.
    private const DEFAULT_FONT_FAMILY = 'helvetica_neue';

    private const DEFAULT_CORNER_RADIUS = 'default';

    private const DEFAULT_BORDER_THICKNESS = 'default';

    private const DEFAULT_SECTION_SPACING = 'default';

    private const DEFAULT_THEME_MODE = 'light';

    public function __construct(
        private readonly BrandDesignMediaService $brandDesign,
    ) {}

    /**
     * GET /staff/professionals/{professional}/brand/design
     */
    public function show(Professional $professional): JsonResponse
    {
        $site = $professional->site;
        $settings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];

        $colors = is_array($design['colors'] ?? null) ? $design['colors'] : [];

        $designMedia = $site
            ? $this->brandDesign->listDesignMedia((string) $site->id)
            : ['logo' => ['full_url' => null, 'square_url' => null], 'placeholders' => []];

        return $this->success(new BrandDesignResource([
            'colors' => [
                'accent' => $colors['accent'] ?? null,
            ],
            'theme_mode' => is_string($design['theme_mode'] ?? null) && $design['theme_mode'] !== ''
                ? $design['theme_mode']
                : self::DEFAULT_THEME_MODE,
            'corner_radius' => is_string($design['corner_radius'] ?? null) && $design['corner_radius'] !== ''
                ? $design['corner_radius']
                : self::DEFAULT_CORNER_RADIUS,
            'border_thickness' => is_string($design['border_thickness'] ?? null) && $design['border_thickness'] !== ''
                ? $design['border_thickness']
                : self::DEFAULT_BORDER_THICKNESS,
            'section_spacing' => is_string($design['section_spacing'] ?? null) && $design['section_spacing'] !== ''
                ? $design['section_spacing']
                : self::DEFAULT_SECTION_SPACING,
            'logo' => $designMedia['logo'],
            'slogan' => $design['slogan'] ?? null,
            'font_family' => is_string($design['font_family'] ?? null) && $design['font_family'] !== ''
                ? $design['font_family']
                : self::DEFAULT_FONT_FAMILY,
            'placeholders' => $designMedia['placeholders'],
            'shopify_connected' => ProfessionalIntegration::query()
                ->where('professional_id', $professional->id)
                ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                ->exists(),
        ]));
    }
}
