<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Services\Professional\BrandAffiliateInviteService;
use Illuminate\Http\JsonResponse;

class PublicBrandAffiliateInviteController extends ApiController
{
    public function show(string $token, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $invite = $inviteService->findByToken($token);

        if (! $invite) {
            return $this->error('Invite not found.', 404);
        }

        $brandSite = Site::query()
            ->where('professional_id', $invite->brand_professional_id)
            ->first();
        $brandSiteSettings = is_array($brandSite?->settings ?? null) ? $brandSite->settings : [];
        $brandDesign = is_array($brandSiteSettings['design'] ?? null) ? $brandSiteSettings['design'] : [];
        $brandMedia = is_array($brandDesign['media'] ?? null) ? $brandDesign['media'] : [];
        $brandLogoUrl = $brandMedia['brand_logo_url'] ?? null;

        return $this->success([
            'invite' => [
                'id' => $invite->id,
                'token' => $invite->token,
                'status' => $invite->status,
                'invite_type' => $invite->invite_type,
                'email' => $invite->email,
                'phone' => $invite->phone,
                'first_name' => $invite->first_name,
                'last_name' => $invite->last_name,
                'message' => $invite->message,
                'brand_professional_id' => $invite->brand_professional_id,
                'brand_display_name' => $invite->brandProfessional?->display_name ?? $invite->brandProfessional?->handle,
                'brand_handle' => $invite->brandProfessional?->handle,
                'brand_logo_url' => is_string($brandLogoUrl) ? $brandLogoUrl : null,
                'accepted_at' => optional($invite->accepted_at)->toIso8601String(),
            ],
        ]);
    }
}
