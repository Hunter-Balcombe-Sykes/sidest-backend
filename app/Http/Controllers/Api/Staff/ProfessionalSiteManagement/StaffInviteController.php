<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ParsesBrandAffiliateInviteCsv;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// V2: Staff manages affiliate invites on behalf of a brand. Reads + cancel
// mirror the read-only staff group; single / bulk / CSV / resend (INVITE-1)
// mirror the brand self-service surface so support can rescue brands stuck on
// CSV imports or large launches. Brand-only — non-brand professionals return
// 422 — and gated on a brand payment method to prevent staff bypassing the
// funding requirement that protects platform float on commission payouts.
class StaffInviteController extends ApiController
{
    use NormalizesPerPage;
    use ParsesBrandAffiliateInviteCsv;

    private const BULK_MAX_ROWS = 500;

    /**
     * GET /api/staff/professionals/{professional}/invites
     *
     * Query params: status (pending|accepted|declined|expired), per_page (default 25)
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $perPage = $this->normalizePerPage($request, 25, 100);
        $status = $request->query('status');

        $query = DB::table('brand.brand_affiliate_invites')
            ->where('brand_professional_id', $professional->id)
            ->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return $this->paginated($query->paginate($perPage));
    }

    /**
     * DELETE /api/staff/professionals/{professional}/invites/{invite}
     *
     * Expires a pending or declined invite. Accepted invites cannot be cancelled.
     */
    public function cancel(Request $request, Professional $professional, BrandAffiliateInvite $invite): JsonResponse
    {
        if ($invite->status === 'accepted') {
            return $this->error('Cannot cancel an accepted invite.', 422);
        }

        if ($invite->status === 'expired') {
            return $this->success(['id' => $invite->id, 'status' => 'expired']);
        }

        $invite->status = 'expired';
        $invite->save();

        return $this->success(['id' => $invite->id, 'status' => 'expired']);
    }

    /**
     * POST /api/staff/professionals/{professional}/invites
     */
    public function store(Request $request, Professional $professional, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        if ($error = $this->assertBrandWithFunding($professional)) {
            return $error;
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

    /**
     * POST /api/staff/professionals/{professional}/invites/bulk
     */
    public function bulk(Request $request, Professional $professional, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        if ($error = $this->assertBrandWithFunding($professional)) {
            return $error;
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

    /**
     * POST /api/staff/professionals/{professional}/invites/import-csv
     */
    public function importCsv(Request $request, Professional $professional, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        if ($error = $this->assertBrandWithFunding($professional)) {
            return $error;
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

    /**
     * POST /api/staff/professionals/{professional}/invites/{invite}/resend
     *
     * Refreshes expiry on a pending/expired invite and re-fires the dashboard
     * notification banner for the recipient. Generic (no-email) invites cannot
     * be resent and return 422.
     */
    public function resend(Request $request, Professional $professional, BrandAffiliateInvite $invite, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        if ($error = $this->assertBrandWithFunding($professional)) {
            return $error;
        }

        try {
            $refreshed = $inviteService->resendInvite($invite);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'invite' => [
                'id' => $refreshed->id,
                'token' => $refreshed->token,
                'status' => $refreshed->status,
                'email' => $refreshed->email,
                'expires_at' => optional($refreshed->expires_at)->toIso8601String(),
            ],
            'action' => 'resent',
        ]);
    }

    /**
     * Gate the write endpoints: the route-bound professional must be a brand
     * (the JWT is staff, so we can't rely on `brand.only` middleware) AND must
     * have a payment method on file (mirrors the self-service brand-funding-gate
     * middleware, which only inspects the JWT subject and is a no-op for staff).
     * Returns null when allowed; otherwise an error JsonResponse to short-circuit.
     */
    private function assertBrandWithFunding(Professional $professional): ?JsonResponse
    {
        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('This professional is not a brand account.', 422);
        }

        if (! app(StripeConnectService::class)->brandHasPaymentMethod($professional)) {
            // 402 + structured payload matches BrandFundingGate so the dashboard
            // can render the same funding-required dialog regardless of which
            // surface (self-service or staff) hit the wall.
            return response()->json([
                'message' => 'A payment method is required before sending affiliate invites.',
                'code' => 'brand_funding_required',
                'data' => [
                    'reason' => 'no_payment_method',
                    'connect_path' => '/account/settings?section=payments',
                ],
            ], 402);
        }

        return null;
    }
}
