<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Professional\BrandAffiliateInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BrandAffiliateInviteController extends ApiController
{
    use ResolveCurrentProfessional;

    public function store(Request $request, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can generate affiliate invites.', 403);
        }

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $invite = $inviteService->createInvite($professional, $data);

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
                'created_at' => optional($invite->created_at)->toIso8601String(),
            ],
        ], 201);
    }

    public function claim(Request $request, string $token, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $invite = $inviteService->findByToken($token);

        if (! $invite) {
            return $this->error('Invite not found.', 404);
        }

        try {
            $claimedInvite = $inviteService->claimInvite($invite, $professional);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'invite' => [
                'id' => $claimedInvite->id,
                'status' => $claimedInvite->status,
                'claimed_professional_id' => $claimedInvite->claimed_professional_id,
                'accepted_at' => optional($claimedInvite->accepted_at)->toIso8601String(),
            ],
        ]);
    }
}
