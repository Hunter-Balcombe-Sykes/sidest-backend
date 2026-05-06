<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Commerce\Order;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff admin manually voids a single approved commission. Phase 4+: the
// "commission" route binding now resolves to a commerce.orders row (the
// legacy ledger accrual rows were dropped — the renamed
// commerce.commission_movements table holds payout/clawback/adjustment only).
// Reasons are prefixed with 'staff_manual:' so voided orders are auditable as
// staff-initiated vs system-initiated in commerce.order_events.
class StaffCommissionVoidController extends ApiController
{
    public function __construct(
        private readonly CommissionVoidService $voidService,
    ) {}

    /**
     * POST /api/staff/commissions/{commission}/void
     *
     * Body: { "reason": string }
     * Only approved orders with no payout_id are voidable.
     *
     * The {commission} route param is bound to commerce.orders.id (preserving
     * the URL contract; only the underlying entity changed).
     */
    public function void(Request $request, Order $commission): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($commission->status !== 'approved') {
            return $this->error(
                "Commission is '{$commission->status}' and cannot be voided. Only approved orders are voidable.",
                422
            );
        }

        if ($commission->payout_id !== null) {
            return $this->error('Commission is already attached to a payout batch and cannot be voided.', 422);
        }

        $voided = $this->voidService->voidOrder($commission, 'staff_manual: '.$data['reason']);

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
