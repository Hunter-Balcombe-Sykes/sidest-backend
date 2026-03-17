<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\BrandAffiliateInvite;
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

        $pendingInvites = BrandAffiliateInvite::query()
            ->where('brand_professional_id', $professional->id)
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function (BrandAffiliateInvite $invite): array {
                $name = trim(implode(' ', array_filter([
                    $invite->first_name,
                    $invite->last_name,
                ])));

                return [
                    'id' => 'invite-' . $invite->id,
                    'full_name' => $name !== '' ? $name : ($invite->email ?: ($invite->phone ?: 'Pending invite')),
                    'display_name' => null,
                    'handle' => null,
                    'professional_type' => 'pending',
                    'email' => $invite->email,
                    'phone' => $invite->phone,
                    'connected_at' => 'Pending',
                    'sort_timestamp' => optional($invite->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return $this->success([
            'affiliates' => [...$pendingInvites, ...$affiliates],
        ]);
    }
}
