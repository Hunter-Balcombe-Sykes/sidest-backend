<?php

namespace App\Services\Stripe;

use App\Models\Commerce\CommissionMovement;
use App\Services\Cache\AnalyticsCacheService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Posts a manual money-movement row to commerce.commission_movements with
 * entry_type='adjustment'. Used by staff-admin to credit or debit a
 * brand↔affiliate relationship when an order was mis-attributed or a
 * one-off correction is needed.
 *
 * Idempotency: a caller-supplied {reference} is stored verbatim in
 * idempotency_key (prefixed with 'staff_adjustment:'). A duplicate reference
 * for the same brand+affiliate pair raises DuplicateAdjustmentException so the
 * controller can return 409 — the unique index on idempotency_key is the
 * source of truth, not an in-app pre-check.
 *
 * Side effects: bumps the analytics cache version for both brand and
 * affiliate so cached projections / overviews surface the adjustment without
 * waiting for TTL.
 */
class CommissionAdjustmentService
{
    public function __construct(
        private readonly AnalyticsCacheService $analyticsCache,
    ) {}

    /**
     * @param  int  $amountCents  Signed; positive credits the affiliate, negative is a clawback adjustment.
     * @param  string  $reference  Caller-supplied idempotency token (e.g. a support-ticket ID).
     * @param  array{actor_id: string, actor_email?: ?string}  $actor
     * @return array{id: string, amount_cents: int, currency_code: string, reference: string}
     *
     * @throws DuplicateAdjustmentException When the reference has already been posted.
     */
    public function post(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        int $amountCents,
        string $currencyCode,
        string $reason,
        string $reference,
        array $actor,
    ): array {
        if ($brandProfessionalId === $affiliateProfessionalId) {
            throw new RuntimeException('Brand and affiliate must be different professionals.');
        }
        if ($amountCents === 0) {
            throw new RuntimeException('Adjustment amount must be non-zero.');
        }

        $id = (string) Str::uuid();
        $idempotencyKey = 'staff_adjustment:'.$reference;

        try {
            DB::transaction(function () use ($id, $brandProfessionalId, $affiliateProfessionalId, $amountCents, $currencyCode, $reason, $reference, $idempotencyKey, $actor): void {
                (new CommissionMovement)->forceFill([
                    'id' => $id,
                    'brand_professional_id' => $brandProfessionalId,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'entry_type' => 'adjustment',
                    'status' => 'approved',
                    'amount_cents' => $amountCents,
                    'currency_code' => $currencyCode,
                    'commission_rate' => 0,
                    'rate_source' => 'staff_adjustment',
                    'idempotency_key' => $idempotencyKey,
                    'calculation_metadata' => [
                        'reason' => $reason,
                        'reference' => $reference,
                        'source' => 'staff_admin',
                        'actor_type' => 'staff',
                        'actor_id' => $actor['actor_id'],
                        'actor_email' => $actor['actor_email'] ?? null,
                        'posted_at' => now()->toIso8601String(),
                    ],
                    'occurred_at' => now(),
                ])->save();
            });
        } catch (QueryException $e) {
            // 23505 = unique_violation. The only unique constraint on commission_movements is
            // commission_ledger_entries_idempotency_uq — a duplicate reference for an
            // already-posted adjustment.
            if ($e->getCode() === '23505') {
                throw new DuplicateAdjustmentException($reference, previous: $e);
            }
            throw $e;
        }

        // Push-invalidate both sides so cached commerce overviews + affiliate projections
        // reflect the adjustment immediately rather than waiting for TTL.
        $this->analyticsCache->invalidateAnalytics($brandProfessionalId);
        $this->analyticsCache->invalidateAnalytics($affiliateProfessionalId);

        Log::info('staff.commission.adjustment_posted', [
            'movement_id' => $id,
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'amount_cents' => $amountCents,
            'currency_code' => $currencyCode,
            'reference' => $reference,
            'staff_id' => $actor['actor_id'],
        ]);

        return [
            'id' => $id,
            'amount_cents' => $amountCents,
            'currency_code' => $currencyCode,
            'reference' => $reference,
        ];
    }
}
