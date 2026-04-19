<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\DTO\DisconnectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Brand views and disconnects their connected affiliates. Single-brand constraint means each affiliate has exactly one brand.
class BrandAffiliateController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can view affiliates.', 403);
        }

        $brandId = $professional->id;

        $links = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->orderByDesc('updated_at')
            ->get(['affiliate_professional_id', 'slot', 'custom_photos_enabled', 'updated_at']);

        $affiliateIds = $links
            ->pluck('affiliate_professional_id')
            ->unique()
            ->values()
            ->all();

        $sitesByProfessionalId = Site::query()
            ->with(['professional'])
            ->whereIn('professional_id', $affiliateIds)
            ->whereHas('professional', function ($query): void {
                $query
                    ->where('status', 'active')
                    ->where('professional_type', '!=', 'brand');
            })
            ->get()
            ->keyBy('professional_id');

        $affiliates = $links
            ->map(function (BrandPartnerLink $link) use ($sitesByProfessionalId): ?array {
                /** @var Site|null $site */
                $site = $sitesByProfessionalId->get($link->affiliate_professional_id);
                if (! $site) {
                    return null;
                }

                $connectedProfessional = $site->professional;
                $name = trim(implode(' ', array_filter([
                    $connectedProfessional?->first_name,
                    $connectedProfessional?->last_name,
                ])));

                return [
                    'id' => $connectedProfessional?->id,
                    'full_name' => $name !== '' ? $name : ($connectedProfessional?->display_name ?? $connectedProfessional?->handle ?? 'Unknown'),
                    'display_name' => $connectedProfessional?->display_name,
                    'handle' => $connectedProfessional?->handle,
                    'professional_type' => $connectedProfessional?->professional_type,
                    'email' => $connectedProfessional?->primary_email ?? $connectedProfessional?->public_contact_email,
                    'phone' => $connectedProfessional?->phone ?? $connectedProfessional?->public_contact_number,
                    'connected_at' => optional($link->updated_at)->toIso8601String(),
                    'is_primary' => (int) $link->slot === BrandPartnerLinkService::PRIMARY_SLOT,
                    'custom_photos_enabled' => $link->custom_photos_enabled,
                ];
            })
            ->filter(fn (?array $affiliate): bool => is_array($affiliate) && filled($affiliate['id']))
            ->values()
            ->all();

        return $this->success([
            'affiliates' => $affiliates,
        ]);
    }

    public function disconnect(
        Request $request,
        string $affiliateId,
        BrandPartnerLinkLifecycleService $lifecycle,
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can disconnect affiliates.', 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $affiliate = Professional::query()->whereKey($affiliateId)->first();
        if (! $affiliate) {
            return $this->error('Affiliate not found.', 404);
        }

        $result = $lifecycle->disconnect(DisconnectRequest::forBrand(
            brand: $professional,
            affiliate: $affiliate,
            reason: $data['reason'] ?? null,
        ));

        if (! $result->disconnected) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        return $this->success([
            'disconnected' => true,
            'affiliate_id' => $affiliateId,
            'selections_removed' => $result->selectionsRemoved,
        ]);
    }

    public function updateCustomPhotos(Request $request, string $affiliateId): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can manage affiliate photo permissions.', 403);
        }

        $request->validate([
            'enabled' => ['present', 'nullable', 'boolean'],
        ]);

        $link = BrandPartnerLink::query()
            ->where('brand_professional_id', $professional->id)
            ->where('affiliate_professional_id', $affiliateId)
            ->first();

        if (! $link) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        $link->custom_photos_enabled = $request->input('enabled');
        $link->save();

        return $this->success([
            'affiliate_id' => $affiliateId,
            'custom_photos_enabled' => $link->custom_photos_enabled,
        ]);
    }
}
