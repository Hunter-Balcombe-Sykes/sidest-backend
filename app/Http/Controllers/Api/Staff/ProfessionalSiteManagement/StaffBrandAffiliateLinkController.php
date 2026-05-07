<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\DTO\DisconnectRequest;
use App\Services\Professional\Enums\CommissionHandling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

// Staff-admin endpoints for manually creating and removing brand-affiliate
// links. Primarily used for manual recovery when the invite flow fails.
class StaffBrandAffiliateLinkController extends ApiController
{
    public function __construct(
        private readonly BrandPartnerLinkLifecycleService $lifecycle,
    ) {}

    /** POST /api/staff/professionals/{brand}/affiliates/{affiliate} */
    public function store(Request $request, Professional $brand, Professional $affiliate): JsonResponse
    {
        try {
            $data = $request->validate([
                'reason' => ['required', 'string', 'min:10', 'max:500'],
            ]);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        $staff = $request->attributes->get('partna_staff');
        if (! $staff) {
            return $this->error('Staff context missing.', 500);
        }

        try {
            $link = $this->lifecycle->createForStaff($brand, $affiliate, $data['reason'], (string) $staff->id);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status = str_contains($message, 'already connected') ? 409 : 422;

            return $this->error($message, $status);
        }

        return $this->success([
            'data' => [
                'link' => [
                    'id' => $link->id,
                    'brand_professional_id' => $link->brand_professional_id,
                    'affiliate_professional_id' => $link->affiliate_professional_id,
                    'slot' => (int) $link->slot,
                    'created_at' => optional($link->created_at)->toIso8601String(),
                ],
            ],
        ], 201);
    }

    /** DELETE /api/staff/professionals/{brand}/affiliates/{affiliate} */
    public function destroy(Request $request, Professional $brand, Professional $affiliate): JsonResponse
    {
        // Conditional rule: when on_pending_commissions='void', reason must be at least 20 chars.
        $rules = [
            'reason' => ['required', 'string', 'max:500'],
            'on_pending_commissions' => ['required', 'in:keep,void'],
        ];
        $rules['reason'][] = $request->input('on_pending_commissions') === 'void' ? 'min:20' : 'min:10';

        try {
            $data = $request->validate($rules);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        $staff = $request->attributes->get('partna_staff');
        if (! $staff) {
            return $this->error('Staff context missing.', 500);
        }

        $disconnectRequest = DisconnectRequest::forStaff(
            brand: $brand,
            affiliate: $affiliate,
            reason: $data['reason'],
            commissions: $data['on_pending_commissions'] === 'void'
                ? CommissionHandling::Void
                : CommissionHandling::Keep,
            staffUserId: (string) ($staff->id ?? ''),
        );

        $result = $this->lifecycle->disconnect($disconnectRequest);

        if (! $result->disconnected) {
            return $this->error('Link not found.', 404);
        }

        if ($result->voidedAsync) {
            return $this->success([
                'data' => [
                    'disconnected' => true,
                    'voided_commission_count' => 0,
                    'voided_commission_cents' => 0,
                    'voided_async' => true,
                    'void_job_dispatched_at' => now()->toIso8601String(),
                    'pending_commission_count' => $result->pendingCommissionCount,
                    'pending_commission_cents' => $result->pendingCommissionCents,
                    'selections_removed' => $result->selectionsRemoved,
                ],
            ], 202);
        }

        return $this->success([
            'data' => [
                'disconnected' => true,
                'voided_commission_count' => $result->voidedCommissionCount,
                'voided_commission_cents' => $result->voidedCommissionCents,
                'selections_removed' => $result->selectionsRemoved,
            ],
        ]);
    }
}
