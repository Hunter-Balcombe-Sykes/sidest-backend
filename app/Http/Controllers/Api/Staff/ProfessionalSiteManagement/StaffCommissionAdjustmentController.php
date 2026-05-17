<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\PostCommissionAdjustmentRequest;
use App\Services\Stripe\CommissionAdjustmentService;
use App\Services\Stripe\DuplicateAdjustmentException;
use Illuminate\Http\JsonResponse;

// LEDGER-1 (audit 2026-05-08): manual commission adjustment endpoint. Writes
// a single row to commerce.commission_movements with entry_type='adjustment'.
// Money source-of-truth surface — gated behind staff.admin middleware in
// routes/api/staff.php and idempotent on the caller-supplied {reference}.
class StaffCommissionAdjustmentController extends ApiController
{
    public function __construct(
        private readonly CommissionAdjustmentService $adjustments,
    ) {}

    /**
     * POST /api/staff/commissions/adjust
     *
     * Body: { brand_professional_id, affiliate_professional_id, amount_cents,
     *         currency_code?, reason, reference }
     *
     * Returns 201 on success with the movement id. 409 if {reference} has
     * already been used (idempotent re-submit). 422 on validation failure.
     */
    public function store(PostCommissionAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $staff = $request->attributes->get('partna_staff');
        $actor = [
            'actor_id' => $staff && isset($staff->id) ? (string) $staff->id : 'unknown',
            'actor_email' => $staff?->email ?? null,
        ];

        try {
            $result = $this->adjustments->post(
                brandProfessionalId: $data['brand_professional_id'],
                affiliateProfessionalId: $data['affiliate_professional_id'],
                amountCents: (int) $data['amount_cents'],
                currencyCode: $data['currency_code'] ?? 'AUD',
                reason: $data['reason'],
                reference: $data['reference'],
                actor: $actor,
            );
        } catch (DuplicateAdjustmentException $e) {
            return $this->error(
                "An adjustment with reference '{$e->reference}' has already been posted.",
                409,
            );
        }

        return $this->success($result, 201);
    }
}
