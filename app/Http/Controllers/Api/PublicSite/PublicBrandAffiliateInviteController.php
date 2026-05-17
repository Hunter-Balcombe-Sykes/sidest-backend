<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Services\Media\BrandDesignMediaService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use Illuminate\Http\JsonResponse;

// V2: Public invite detail retrieval by token. Used by affiliate claim/decline pages during onboarding.
class PublicBrandAffiliateInviteController extends ApiController
{
    public function show(
        string $token,
        BrandAffiliateInviteService $inviteService,
        BrandDesignMediaService $mediaService,
    ): JsonResponse {
        $invite = $inviteService->findByToken($token);

        if (! $invite) {
            return $this->error('Invite not found.', 404);
        }

        $brandSite = Site::query()
            ->where('professional_id', $invite->brand_professional_id)
            ->first();
        // Resolved from site_media (purpose=logo_full) — same source the brand uploads to.
        $brandLogoUrl = $brandSite ? $mediaService->getLogoFullUrl((string) $brandSite->id) : null;

        $effectiveStatus = $invite->status === 'pending' && $invite->expires_at && $invite->expires_at->isPast()
            ? 'expired'
            : $invite->status;

        return $this->success([
            'invite' => [
                'id' => $invite->id,
                'token' => $invite->token,
                'status' => $effectiveStatus,
                'invite_type' => $invite->invite_type,
                'email' => $invite->email,
                'first_name' => $invite->first_name,
                'last_name' => $invite->last_name,
                'message' => $invite->message,
                'brand_professional_id' => $invite->brand_professional_id,
                'brand_display_name' => $invite->brandProfessional?->display_name ?? $invite->brandProfessional?->handle,
                'brand_handle' => $invite->brandProfessional?->handle,
                'brand_logo_url' => $brandLogoUrl,
                'accepted_at' => optional($invite->accepted_at)->toIso8601String(),
            ],
        ]);
    }
}
