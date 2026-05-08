---
item_id: '#PAY-1'
title: Currency-mismatch payout loops forever; `void_at` not reset on `pending_funds`
source: pilot-stage-3.md
tier: P1
effort_estimate: S
completed_at: '2026-05-08T02:11:24+00:00'
mode: overnight
commit_sha: 55d2d44
files_touched:
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionPayoutController.php
- app/Services/Stripe/CommissionPayoutService.php
- tests/Feature/Stripe/CommissionPayoutServiceTest.php
test_result: pass
questions_asked: 0
---

# #PAY-1 — Currency-mismatch payout loops forever; `void_at` not reset on `pending_funds`

## Plain English

When a brand's wallet is in a different currency than the commission being paid (e.g. pounds vs. dollars), the system correctly pauses the payout and notifies the brand. But before this fix, two things went wrong: the automated sweep kept retrying the payout on every run — like a mail carrier reattempting an undeliverable letter thousands of times — and the payout's 60-day expiry clock wasn't being reset each time the system decided to wait, so the affiliate's unpaid earnings could be silently wiped after 60 days even though the brand was still resolving the issue. Both problems are now fixed, and admins can look up all stuck payouts via the staff API.

## Technical Summary

**Files changed:**
- `app/Services/Stripe/CommissionPayoutService.php`
- `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffCommissionPayoutController.php`
- `tests/Feature/Stripe/CommissionPayoutServiceTest.php`

**`markPendingFunding()`**: Added `'void_at' => now()->addDays($this->gracePeriodDays)` to the `forceFill` array. Previously `void_at` was stamped once at `createPayoutBatch` and never updated, so `VoidExpiredPayoutsJob` could void a payout (and erase `orders.payout_id`) while it was legitimately waiting on a brand to resolve a currency mismatch.

**`processEligiblePayouts()`**: Added a `where(function)` clause to the existing Eloquent query that excludes rows where `failure_code = 'wallet_currency_mismatch'`. Because SQL `!=` excludes NULLs, the clause is written as `whereNull('failure_code')->orWhere('failure_code', '!=', 'wallet_currency_mismatch')` so normal payouts (null failure_code) are still picked up. Payouts re-enter the sweep automatically once an admin calls `/retry`, which clears the failure_code.

**`StaffCommissionPayoutController::index()`**: Added `failure_code` as an optional query parameter alongside the existing `status` and `needs_manual_refund` filters. This satisfies the "admin visibility" requirement without a dedicated endpoint — staff can query `?status=pending_funds&failure_code=wallet_currency_mismatch` to surface all stuck payouts.

**Two new tests added**: one verifying `void_at` is updated to `now()+60d` after `processPayoutBatch` hits a mismatch; one verifying `processEligiblePayouts` returns `batches_requeued=0` when the only pending_funds payout carries `wallet_currency_mismatch`.

## Decisions Made

- **Query-level filter vs. foreach skip**: Chose to exclude currency-mismatch payouts in the Eloquent query rather than a `continue` in the foreach loop, so the DB does less work on every scheduler tick and the intent is explicit at the query level.
- **Admin endpoint strategy**: Chose to extend the existing `index()` filter rather than add a new dedicated endpoint (e.g. `/stuck`). The existing filter pattern was sufficient and adding a third parameter kept blast radius minimal.
- **No 48h time-based filter**: The audit item mentioned ">48 hours" as context for what's worth alerting on, not as a hard filter requirement. Admins can sort the response by `created_at` to identify long-stuck payouts; adding a time filter would have required either a new param or a separate route.

## Notes

The `retryPayout()` method already clears `failure_code` when it resets payout status, so the "admin clears the flag" path described in the audit item works correctly through the existing `/retry` endpoint — no additional change needed there.

## Questions Asked
(none)
