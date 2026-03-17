<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Site\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $affiliates = Site::query()
            ->with(['professional'])
            ->where(function ($query) use ($brandId): void {
                $query
                    ->whereRaw("(settings->'brand_partner'->>'professional_id') = ?", [$brandId])
                    ->orWhereRaw("(settings->'brandPartner'->>'professionalId') = ?", [$brandId])
                    ->orWhereRaw("settings->'additional_brand_partners' @> ?", [json_encode([['professional_id' => $brandId]])]);
            })
            ->whereHas('professional', function ($query): void {
                $query
                    ->where('status', 'active')
                    ->where('professional_type', '!=', 'brand');
            })
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Site $site) use ($brandId): array {
                $connectedProfessional = $site->professional;
                $name = trim(implode(' ', array_filter([
                    $connectedProfessional?->first_name,
                    $connectedProfessional?->last_name,
                ])));

                // Determine if this brand is primary or additional for this affiliate
                $settings = is_array($site->settings ?? null) ? $site->settings : [];
                $isPrimary = ($settings['brand_partner']['professional_id'] ?? null) === $brandId;

                return [
                    'id' => $connectedProfessional?->id,
                    'full_name' => $name !== '' ? $name : ($connectedProfessional?->display_name ?? $connectedProfessional?->handle ?? 'Unknown'),
                    'display_name' => $connectedProfessional?->display_name,
                    'handle' => $connectedProfessional?->handle,
                    'professional_type' => $connectedProfessional?->professional_type,
                    'email' => $connectedProfessional?->primary_email ?? $connectedProfessional?->public_contact_email,
                    'phone' => $connectedProfessional?->phone ?? $connectedProfessional?->public_contact_number,
                    'connected_at' => optional($site->updated_at)->toIso8601String(),
                    'is_primary' => $isPrimary,
                ];
            })
            ->filter(fn (array $affiliate): bool => filled($affiliate['id']))
            ->values()
            ->all();

        return $this->success([
            'affiliates' => $affiliates,
        ]);
    }

    public function disconnect(Request $request, string $affiliateId): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can disconnect affiliates.', 403);
        }

        $brandId = $professional->id;

        $site = Site::query()
            ->where('professional_id', $affiliateId)
            ->where(function ($query) use ($brandId): void {
                $query
                    ->whereRaw("(settings->'brand_partner'->>'professional_id') = ?", [$brandId])
                    ->orWhereRaw("(settings->'brandPartner'->>'professionalId') = ?", [$brandId])
                    ->orWhereRaw("settings->'additional_brand_partners' @> ?", [json_encode([['professional_id' => $brandId]])]);
            })
            ->first();

        if (! $site) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        $settings = is_array($site->settings ?? null) ? $site->settings : [];
        $currentPrimaryId = $settings['brand_partner']['professional_id'] ?? null;

        if ($currentPrimaryId === $brandId) {
            // Remove from primary
            unset($settings['brand_partner'], $settings['brandPartner']);

            // Promote first additional to primary
            $additionalPartners = is_array($settings['additional_brand_partners'] ?? null)
                ? $settings['additional_brand_partners']
                : [];
            if (count($additionalPartners) > 0) {
                $newPrimary = array_shift($additionalPartners);
                $settings['brand_partner'] = ['professional_id' => $newPrimary['professional_id']];
                $settings['additional_brand_partners'] = array_values($additionalPartners);
            }
        } else {
            // Remove from additional partners
            $additionalPartners = is_array($settings['additional_brand_partners'] ?? null)
                ? $settings['additional_brand_partners']
                : [];
            $settings['additional_brand_partners'] = array_values(
                array_filter($additionalPartners, fn ($p) => ($p['professional_id'] ?? null) !== $brandId)
            );
        }

        $site->settings = $settings;
        $site->save();

        return $this->success([
            'affiliate_id' => $affiliateId,
            'disconnected' => true,
        ]);
    }
}
