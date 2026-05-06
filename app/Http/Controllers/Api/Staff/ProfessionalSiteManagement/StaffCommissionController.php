<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff views commission ledger for any professional. Queries by both brand and affiliate sides so it works regardless of professional_type.
class StaffCommissionController extends ApiController
{
    use NormalizesPerPage;

    /**
     * GET /api/staff/professionals/{professional}/commissions
     *
     * Query params: status (pending|approved|reversed|voided), per_page (default 25, max 100)
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $perPage = $this->normalizePerPage($request, 25, 100);
        $status = $request->query('status');

        $query = DB::table('commerce.commission_movements')
            ->where(function ($q) use ($professional): void {
                $q->where('brand_professional_id', $professional->id)
                    ->orWhere('affiliate_professional_id', $professional->id);
            })
            ->orderByDesc('occurred_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        return $this->paginated($paginator);
    }
}
