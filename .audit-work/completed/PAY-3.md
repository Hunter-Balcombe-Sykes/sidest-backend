---
item_id: '#PAY-3'
title: Post-creation refund window unguarded; `processPayoutBatch` doesn't re-validate
  order state after batch is in `collecting`
source: pilot-stage-3.md
tier: P2
effort_estimate: M
completed_at: '2026-05-08T02:33:46+00:00'
mode: overnight
commit_sha: f1f1545
files_touched:
- app/Services/Stripe/CommissionPayoutService.php
- tests/Feature/Stripe/CommissionPayoutServiceTest.php
test_result: pass
questions_asked: 0
---

# #PAY-3 — Post-creation refund window unguarded; `processPayoutBatch` doesn't re-validate order state after batch is in `collecting`

## Plain English

When the system decides which sales to pay out, it locks in the list and dollar amounts at that moment. Between locking in the list and actually sending the money, a customer could return an item and get a refund. The fix adds a double-check immediately before any money moves: it re-reads every order in the batch, removes any that have since been refunded, and either rebuilds the payout with the remaining valid orders or cancels it entirely if nothing is left. A belt-and-suspenders guard also prevents a zero-dollar Stripe transfer if the math somehow reaches zero during an in-progress retry.

## Technical Summary

**Files changed:**
- `app/Services/Stripe/CommissionPayoutService.php` — new private `revalidatePayoutOrders(CommissionPayout): ?CommissionPayout` method; one call injected in `processPayoutBatch` (pending-status path only); one guard added in the `collecting`/`collected`/`transferring` resume branch.
- `tests/Feature/Stripe/CommissionPayoutServiceTest.php` — new `payoutSvc_seedPayoutItem` helper; three new test cases under `#PAY-3` section.

**New behavior:**
- `revalidatePayoutOrders` runs inside a `DB::transaction` with `lockForUpdate` on `commerce.orders WHERE payout_id = ?`, eliminating the TOCTOU race against a concurrent `refunds/create` webhook.
- Stale orders (status != 'approved' OR refund_cents > 0) have their `payout_id` cleared and their `commission_payout_items` row deleted.
- If no valid orders remain, the payout transitions to `cancelled`; if the rebuilt net is ≤ 0 (platform fee consumed everything), same result.
- If some orders remain, `gross_commission_cents`, `platform_fee_cents`, `net_payout_cents`, and `ledger_entry_count` are rewritten to reflect only the valid orders; `$payout->fresh()` is returned so `processPayoutBatch`'s `$amountToCollect` sees the corrected gross.
- The `net_payout_cents <= 0` guard in the `collecting`/`transferring` resume path fails the payout with `net_payout_zero` rather than attempting a zero-value Stripe transfer.
- `createPayoutBatch` docblock updated to remove the "acceptable pre-beta; tracked for Phase 4" acknowledgment.

## Decisions Made

- **Cancel vs void for zero-remaining payouts**: Used `cancelled` (not `voided` or `failed`) to match the status already used by `CommissionVoidService::processExpiredPayouts()` for similar clean-termination scenarios.
- **Revalidation only on `pending` status**: The check is skipped for `collecting`/`collected`/`transferring` because the wallet debit is already committed in those states — trying to rebuild after a debit would require crediting the wallet back and complicates the idempotent resume logic. The `net_payout_cents` guard in the resume path is the safety net for those states.
- **`$payout->fresh()` after rebuild**: Returns a freshly fetched model so the caller's local variable reflects the DB-committed values rather than the in-memory Eloquent object with partial updates from `forceFill`.
- **No rebuild if stale list is empty**: Short-circuits immediately (no DB write) when all orders are still valid, keeping the zero-refund fast path overhead to a single indexed read.

## Notes

The config default for `platform_fee_percent` is 20% (in `config/partna.php`), not 3% as suggested by the constructor fallback. One test iteration caught this because the expected transfer amount of 9700 (3% fee) was wrong — the actual net after revalidation is 8000 (20% of 10000). Tests now use the correct value.

## Questions Asked
(none)
