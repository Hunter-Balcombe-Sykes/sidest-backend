<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff admin manually voids a single pending commission entry. Prefixes the reason
// with 'staff_manual:' so voided entries are auditable as staff-initiated vs system-initiated.
class StaffCommissionVoidController extends ApiController
{
    public function __construct(
        private readonly CommissionVoidService $voidService,
    ) {}

    /**
     * POST /api/staff/commissions/{commission}/void
     *
     * Body: { "reason": string }
     * Only pending entries with no payout_id are voidable.
     */
    public function void(Request $request, CommissionLedgerEntry $commission): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($commission->status !== 'pending') {
            return $this->error(
                "Commission is '{$commission->status}' and cannot be voided. Only pending entries are voidable.",
                422
            );
        }

        if ($commission->payout_id !== null) {
            return $this->error('Commission is already attached to a payout batch and cannot be voided.', 422);
        }

        $voided = $this->voidService->voidEntry($commission, 'staff_manual: '.$data['reason']);

        if (! $voided) {
            return $this->error(
                'Commission was claimed by a concurrent process. Refresh and try again.',
                409
            );
        }

        return $this->success([
            'id' => $commission->id,
            'voided' => true,
        ]);
    }
}
