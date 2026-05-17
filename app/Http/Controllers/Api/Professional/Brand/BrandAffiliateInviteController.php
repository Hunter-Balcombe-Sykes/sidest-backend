<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ParsesBrandAffiliateInviteCsv;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

// V2: Brand creates/manages affiliate invitations (single, bulk, CSV). Affiliates claim or decline via token. Core V2 onboarding flow.
class BrandAffiliateInviteController extends ApiController
{
    use ParsesBrandAffiliateInviteCsv;
    use ResolveCurrentProfessional;

    private const BULK_MAX_ROWS = 500;

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
                $effectiveStatus = $invite->status === 'pending' && $invite->expires_at && $invite->expires_at->isPast()
                    ? 'expired'
                    : $invite->status;

                return [
                    'id' => $invite->id,
                    'status' => $effectiveStatus,
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
            'phone' => ['nullable', 'string', 'max:30'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:500'],
            'expiration' => ['nullable', 'string', 'in:24h,7d,30d,none'],
        ]);

        try {
            $result = $inviteService->createOrRefreshInvite($professional, $data);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $invite = $result['invite'];
        $action = (string) ($result['action'] ?? 'created');

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
                'accepted_at' => optional($invite->accepted_at)->toIso8601String(),
            ],
            'action' => $action,
        ], $action === 'refreshed' ? 200 : 201);
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

    public function bulk(Request $request, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can generate affiliate invites.', 403);
        }

        $data = $request->validate([
            'invites' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX_ROWS],
        ]);

        try {
            $result = $inviteService->processBulkInvites($professional, $data['invites']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($result);
    }

    public function importCsv(Request $request, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can generate affiliate invites.', 403);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $rows = $this->parseInviteCsvRows($data['file'], self::BULK_MAX_ROWS);
            $result = $inviteService->processBulkInvites($professional, $rows);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($result);
    }

    public function claim(
        Request $request,
        string $token,
        BrandAffiliateInviteService $inviteService,
        BrandPartnerLinkService $brandPartnerLinks
    ): JsonResponse {
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

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if ($site) {
            $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
            app(ProfessionalCacheService::class)->invalidateProfessional($professional);
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

    private function syncSiteBrandPartnerSettings(
        Site $site,
        BrandPartnerLinkService $brandPartnerLinks,
        string $affiliateProfessionalId
    ): void {
        $links = $brandPartnerLinks->getLinksForAffiliate($affiliateProfessionalId);
        $settings = is_array($site->settings) ? $site->settings : [];

        $brandPartner = is_array($settings['brand_partner'] ?? null)
            ? $settings['brand_partner']
            : [];

        $primaryLink = $links->firstWhere('slot', BrandPartnerLinkService::PRIMARY_SLOT);
        if ($primaryLink) {
            $brandPartner['professional_id'] = (string) $primaryLink->brand_professional_id;
        } else {
            unset($brandPartner['professional_id'], $brandPartner['professionalId']);
        }

        $settings['brand_partner'] = $brandPartner;
        $settings['additional_brand_partners'] = $links
            ->filter(static fn ($link): bool => (int) $link->slot > BrandPartnerLinkService::PRIMARY_SLOT)
            ->sortBy('slot')
            ->map(static fn ($link): array => [
                'professional_id' => (string) $link->brand_professional_id,
            ])
            ->values()
            ->all();

        $site->settings = $settings;
        $site->save();
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
