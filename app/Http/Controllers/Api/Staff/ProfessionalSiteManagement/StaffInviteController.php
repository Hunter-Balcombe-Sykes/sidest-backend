<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff views and cancels affiliate invites for a brand. Cancel marks status 'expired' to preserve audit trail — does not hard-delete.
class StaffInviteController extends ApiController
{
    use NormalizesPerPage;

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
}
