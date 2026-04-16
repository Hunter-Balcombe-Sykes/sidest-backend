<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff-admin controls for commission payouts. Today only exposes manual
// retry — the daily ProcessCommissionPayoutsJob handles normal flow and the
// Phase 1 pending sweep auto-retries pending batches. This endpoint exists
// for stuck `failed` batches, which the sweep intentionally does NOT pick up
// (to avoid re-triggering a card decline loop). Support uses this after
// fixing the underlying issue (brand updated their card, affiliate completed
// Stripe Connect onboarding, etc.).
class StaffCommissionPayoutController extends ApiController
{
    public function __construct(
        private readonly CommissionPayoutService $payoutService,
    ) {}

    /**
     * POST /staff/commission-payouts/{payout}/retry
     *
     * Manually retry a stuck payout batch. Only `failed` and `pending` batches
     * are retryable — `completed` / `collecting` / `collected` / `transferring`
     * return 422.
     *
     * The retry runs synchronously: by the time the response comes back, the
     * batch has either completed, moved back to pending (with a new failure
     * code), or failed again. The refreshed state is in the response so staff
     * can see what happened without a second request.
     *
     * @return JsonResponse {
     *   data: {
     *     id: string,
     *     status: 'completed'|'pending'|'failed'|...,
     *     failure_code: string|null,
     *     failure_reason: string|null,
     *     processed_at: string|null,
     *     net_payout_cents: int,
     *     currency_code: string
     *   }
     * }
     */
    public function retry(Request $request, CommissionPayout $payout): JsonResponse
    {
        if (! in_array($payout->status, ['failed', 'pending'], true)) {
            return $this->error(
                "Payout is in status '{$payout->status}' and cannot be retried. Only 'failed' or 'pending' batches are retryable.",
                422
            );
        }

        // retryPayout() resets the status to 'pending', clears failure_code/
        // reason, and runs processPayoutBatch. The returned bool tells us
        // whether the batch ended in `completed` (true) or anywhere else
        // (false) — but the refreshed model state is the authoritative record.
        $this->payoutService->retryPayout($payout);
        $payout->refresh();

        return $this->success([
            'id' => $payout->id,
            'status' => $payout->status,
            'failure_code' => $payout->failure_code,
            'failure_reason' => $payout->failure_reason,
            'processed_at' => $payout->processed_at?->toIso8601String(),
            'net_payout_cents' => (int) $payout->net_payout_cents,
            'currency_code' => (string) $payout->currency_code,
        ]);
    }
}
