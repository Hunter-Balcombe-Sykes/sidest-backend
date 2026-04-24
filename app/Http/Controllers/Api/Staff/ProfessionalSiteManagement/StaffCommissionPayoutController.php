<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\RateLimitException;

// V2: Staff-admin controls for commission payouts. Today only exposes manual
// retry — the daily ProcessCommissionPayoutsJob handles normal flow and the
// Phase 1 pending sweep auto-retries pending batches. This endpoint exists
// for stuck `failed` batches, which the sweep intentionally does NOT pick up
// (to avoid re-triggering a card decline loop). Support uses this after
// fixing the underlying issue (brand updated their card, affiliate completed
// Stripe Connect onboarding, etc.).
class StaffCommissionPayoutController extends ApiController
{
    use NormalizesPerPage;

    public function __construct(
        private readonly CommissionPayoutService $payoutService,
    ) {}

    /**
     * GET /staff/commission-payouts
     *
     * List all payouts platform-wide. Query params: status (pending|processing|completed|failed|...), per_page (default 25, max 100).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $this->normalizePerPage($request, 25, 100);
        $status = $request->query('status');

        $query = DB::table('commerce.commission_payouts')->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return $this->paginated($query->paginate($perPage));
    }

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
     *                      data: {
     *                      id: string,
     *                      status: 'completed'|'pending'|'failed'|...,
     *                      failure_code: string|null,
     *                      failure_reason: string|null,
     *                      processed_at: string|null,
     *                      net_payout_cents: int,
     *                      currency_code: string
     *                      }
     *                      }
     */
    public function retry(Request $request, CommissionPayout $payout): JsonResponse
    {
        if (! in_array($payout->status, ['failed', 'pending'], true)) {
            return $this->error(
                "Payout is in status '{$payout->status}' and cannot be retried. Only 'failed' or 'pending' batches are retryable.",
                422
            );
        }

        // retryPayout() resets status, clears failure_code/reason, and runs
        // processPayoutBatch synchronously. Transient Stripe errors re-throw
        // (Horizon backoff design) — surface them as 503 so staff know to retry.
        try {
            $this->payoutService->retryPayout($payout);
        } catch (ApiConnectionException|RateLimitException $e) {
            return $this->error('Stripe is temporarily unavailable. Please try again in a moment.', 503);
        }
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
