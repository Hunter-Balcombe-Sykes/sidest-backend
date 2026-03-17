<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
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
                'accepted_at' => optional($invite->accepted_at)->toIso8601String(),
                'expires_at' => optional($invite->expires_at)->toIso8601String(),
            ],
        ]);
    }
}
