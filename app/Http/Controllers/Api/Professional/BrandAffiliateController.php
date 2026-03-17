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

        $affiliates = Site::query()
            ->with(['professional'])
            ->where(function ($query) use ($professional): void {
                $query
                    ->whereRaw("(settings->'brand_partner'->>'professional_id') = ?", [$professional->id])
                    ->orWhereRaw("(settings->'brandPartner'->>'professionalId') = ?", [$professional->id]);
            })
            ->whereHas('professional', function ($query): void {
                $query
                    ->where('status', 'active')
                    ->where('professional_type', '!=', 'brand');
            })
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Site $site): array {
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
                    'connected_at' => optional($site->updated_at)->toIso8601String(),
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

        $site = Site::query()
            ->where('professional_id', $affiliateId)
            ->where(function ($query) use ($professional): void {
                $query
                    ->whereRaw("(settings->'brand_partner'->>'professional_id') = ?", [$professional->id])
                    ->orWhereRaw("(settings->'brandPartner'->>'professionalId') = ?", [$professional->id]);
            })
            ->first();

        if (! $site) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        $settings = is_array($site->settings ?? null) ? $site->settings : [];
        unset($settings['brand_partner'], $settings['brandPartner']);
        $site->settings = $settings;
        $site->save();

        return $this->success([
            'affiliate_id' => $affiliateId,
            'disconnected' => true,
        ]);
    }
}
