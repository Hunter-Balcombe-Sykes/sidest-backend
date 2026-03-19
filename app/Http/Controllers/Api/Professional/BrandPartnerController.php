<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Store\SelectionCleanupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandPartnerController extends ApiController
{
    use NormalizesPerPage;
    use ResolveCurrentProfessional;
    use ReturnsPaginatedResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = $this->normalizePerPage($request, 25, 100);

        $page = Professional::query()
            ->where('professional_type', 'brand')
            ->where('status', 'active')
            ->with('site')
            ->orderByRaw('COALESCE(display_name, handle) asc')
            ->paginate($perPage)
            ->appends($request->query());

        $brands = $page->getCollection()
            ->map(function (Professional $professional): array {
                return [
                    'id' => $professional->id,
                    'display_name' => $professional->display_name,
                    'handle' => $professional->handle,
                    'subdomain' => $professional->site?->subdomain,
                ];
            })
            ->values()
            ->all();

        $payload = $this->paginatedResponse($page, 'brands');
        $payload['brands'] = $brands;

        return $this->success($payload);
    }

    public function promote(
        Request $request,
        string $brandProfessionalId,
        BrandPartnerLinkService $brandPartnerLinks
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $promoted = $brandPartnerLinks->promoteBrandToPrimary((string) $professional->id, (string) $brandProfessionalId);
        if (! $promoted) {
            return $this->error('Brand partner not found in your additional partners.', 404);
        }

        return $this->success([
            'promoted' => true,
            'primary_professional_id' => $brandProfessionalId,
        ]);
    }

    public function disconnect(
        Request $request,
        string $brandProfessionalId,
        BrandPartnerLinkService $brandPartnerLinks,
        SelectionCleanupService $selectionCleanup
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $disconnected = $brandPartnerLinks->disconnectBrandFromAffiliate((string) $professional->id, (string) $brandProfessionalId);

        if (! $disconnected) {
            return $this->error('Brand partner not found in your connections.', 404);
        }

        $selectionCleanup->removeSelectionsForAffiliateBrand(
            (string) $professional->id,
            (string) $brandProfessionalId,
            'Brand connection removed',
            '{count} selected product(s) were removed because this brand connection ended.'
        );

        return $this->success([
            'disconnected' => true,
            'brand_professional_id' => $brandProfessionalId,
        ]);
    }
}
