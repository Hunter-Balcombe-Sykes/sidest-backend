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

    public function index(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can view affiliate invites.', 403);
        }

        $invites = $professional->brandAffiliateInvites()
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($invite): array {
                return [
                    'id' => $invite->id,
                    'status' => $invite->status,
                    'invite_type' => $invite->invite_type,
                    'email' => $invite->email,
                    'first_name' => $invite->first_name,
                    'last_name' => $invite->last_name,
                    'message' => $invite->message,
                    'token' => $invite->token,
                    'created_at' => optional($invite->created_at)->toIso8601String(),
                    'accepted_at' => optional($invite->accepted_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return $this->success([
            'invites' => $invites,
        ]);
    }

    public function store(Request $request, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can generate affiliate invites.', 403);
        }

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $invite = $inviteService->createInvite($professional, $data);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'invite' => [
                'id' => $invite->id,
                'token' => $invite->token,
                'status' => $invite->status,
                'invite_type' => $invite->invite_type,
                'email' => $invite->email,
                'first_name' => $invite->first_name,
                'last_name' => $invite->last_name,
                'message' => $invite->message,
                'created_at' => optional($invite->created_at)->toIso8601String(),
            ],
        ], 201);
    }

    public function availability(Request $request, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can check affiliate invite availability.', 403);
        }

        $data = $request->validate([
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        return $this->success(
            $inviteService->checkRecipientAvailability(
                $professional,
                $data['email'] ?? null,
                null,
            )
        );
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

    public function decline(Request $request, string $token, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $invite = $inviteService->findByToken($token);

        if (! $invite) {
            return $this->error('Invite not found.', 404);
        }

        try {
            $declinedInvite = $inviteService->declineInvite($invite, $professional);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'invite' => [
                'id' => $declinedInvite->id,
                'status' => $declinedInvite->status,
            ],
        ]);
    }

    public function destroy(Request $request, string $inviteId): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can delete affiliate invites.', 403);
        }

        $invite = $professional->brandAffiliateInvites()
            ->whereKey($inviteId)
            ->first();

        if (! $invite) {
            return $this->error('Invite not found.', 404);
        }

        $invite->delete();

        return $this->success([
            'invite_id' => $inviteId,
            'deleted' => true,
        ]);
    }
}
