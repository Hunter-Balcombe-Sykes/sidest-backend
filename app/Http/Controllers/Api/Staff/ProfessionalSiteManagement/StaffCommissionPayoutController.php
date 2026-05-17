<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Models\Commerce\CommissionPayout;
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
     * List all payouts platform-wide. Query params:
     *   status              — pending|processing|completed|failed|cancelled
     *   failure_code        — filter by failure code, e.g. wallet_currency_mismatch
     *   needs_manual_refund — true|false (filter for stuck double-failure cases)
     *   per_page            — default 25, max 100
     *
     * Tip: ?status=failed&failure_code=charge_failed lists all payouts blocked on a
     * charge failure (may require admin action).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $this->normalizePerPage($request, 25, 100);
        $status = $request->query('status');
        $failureCode = $request->query('failure_code');
        $needsManualRefund = $request->query('needs_manual_refund');

        $query = DB::table('commerce.commission_payouts')->orderByDesc('created_at');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        if (is_string($failureCode) && $failureCode !== '') {
            $query->where('failure_code', $failureCode);
        }

        if ($needsManualRefund === 'true') {
            $query->where('needs_manual_refund', true);
        } elseif ($needsManualRefund === 'false') {
            $query->where('needs_manual_refund', false);
        }

        return $this->paginated($query->paginate($perPage));
    }

    /**
     * POST /staff/commission-payouts/{payout}/retry
     *
     * Manually retry a stuck payout batch. Only `failed` and `pending` batches
     * are retryable — `completed` / `cancelled` return 422.
     *
     * Payouts with needs_manual_refund=true must be acknowledged first via
     * POST /staff/commission-payouts/{payout}/acknowledge-manual-refund.
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
     *                      needs_manual_refund: bool,
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

        // The auto-refund failed, so the brand may still be charged. Staff must
        // verify in Stripe and call the acknowledge endpoint before retrying.
        if ($payout->needs_manual_refund) {
            return $this->error(
                'This payout requires manual refund verification. Confirm the brand has been refunded in Stripe, then call POST /staff/commission-payouts/{payout}/acknowledge-manual-refund before retrying.',
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
            'needs_manual_refund' => (bool) $payout->needs_manual_refund,
            'processed_at' => $payout->processed_at?->toIso8601String(),
            'net_payout_cents' => (int) $payout->net_payout_cents,
            'currency_code' => (string) $payout->currency_code,
        ]);
    }

    /**
     * POST /staff/commission-payouts/{payout}/acknowledge-manual-refund
     *
     * Staff acknowledges they have manually issued (or confirmed the absence of)
     * the Stripe refund for a payout where the auto-refund failed after a transfer
     * failure. This clears the needs_manual_refund block and makes the payout
     * retryable via the /retry endpoint.
     *
     * The stripe_payment_intent_id is cleared so the retry creates a fresh
     * PaymentIntent — reusing the same PI after a manual refund would hit
     * Stripe's 24h idempotency window and return the already-refunded intent.
     *
     * @return JsonResponse {
     *                      data: {
     *                      id: string,
     *                      status: string,
     *                      failure_code: string|null,
     *                      needs_manual_refund: false
     *                      }
     *                      }
     */
    public function acknowledgeManualRefund(Request $request, CommissionPayout $payout): JsonResponse
    {
        if (! $payout->needs_manual_refund) {
            return $this->error('This payout does not have a pending manual refund flag.', 422);
        }

        $payout->forceFill([
            'needs_manual_refund' => false,
            // failure_code stays 'transfer_failed_refund_needed' → change to
            // 'transfer_failed' so retryPayout() no longer has a code-based block.
            'failure_code' => 'transfer_failed',
            // Clear PI so retry creates a fresh PaymentIntent (avoids Stripe
            // returning the already-refunded PI within the 24h idempotency TTL).
            'stripe_payment_intent_id' => null,
        ])->save();

        return $this->success([
            'id' => $payout->id,
            'status' => $payout->status,
            'failure_code' => $payout->failure_code,
            'needs_manual_refund' => false,
        ]);
    }
}
