---
item_id: '#PAY-2'
title: '`transfer.reversed` routed to `handleTransferFailed`; guard silently no-ops
  on completed payouts'
source: pilot-stage-3.md
tier: P2
effort_estimate: S
completed_at: '2026-05-08T02:20:21+00:00'
mode: overnight
commit_sha: 13358df
files_touched:
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Models/Retail/CommissionPayout.php
- supabase/migrations/20260508000000_add_reversed_payout_status.sql
- tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php
test_result: pass
questions_asked: 0
---

# #PAY-2 — `transfer.reversed` routed to `handleTransferFailed`; guard silently no-ops on completed payouts

## Plain English

When Stripe sends money to an affiliate and then takes it back (which can happen for compliance or legal reasons), the system now correctly recognises this as a reversal — distinct from a payment that never went through. The payout record is updated to "reversed", the system flags it as needing manual recovery (because the brand was already charged), and a clear audit trail is written. Previously, if the payment had already been marked as complete, Stripe's reversal notification was silently ignored and the books stayed wrong.

## Technical Summary

- `StripeConnectWebhookController`: added `handleTransferReversed(object $transfer)` — routes `transfer.reversed` events to a dedicated handler instead of reusing `handleTransferFailed`. The new handler sets `status='reversed'`, `failure_code='transfer_reversed'`, `failure_reason='Transfer reversed by Stripe after delivery — funds clawed back'`, and `needs_manual_refund=true`. Critically, the guard only skips if `status` is already `'reversed'` (idempotent) — it does NOT skip `completed` payouts, which is the most common real-world reversal scenario.
- `CommissionPayout` model: added `isReversed(): bool` helper consistent with the existing `isCompleted()` / `isFailed()` methods.
- `supabase/migrations/20260508000000_add_reversed_payout_status.sql`: drops and recreates `cp_status_check` to add `'reversed'` to the allowed values in `commerce.commission_payouts.status`.
- `StripeConnectWebhookControllerEndToEndTest.php`: 6 new tests in a `describe('transfer.reversed webhook')` block covering: completed payout transitions to reversed, in-flight (transferring) payout transitions to reversed, idempotent duplicate delivery, no-op with missing payout ID, failure_code distinction from `transfer_failed_webhook`, and confirming `transfer.failed` still skips completed payouts.

## Decisions Made

- **Guard logic — skip only on `reversed`, not on `completed`/`failed`/`cancelled`**: `completed` is the most operationally significant reversal case and must not be blocked. `failed` and `cancelled` payouts theoretically shouldn't receive a `transfer.reversed` (no funds were sent), but even if they do we still record the reversal rather than silently dropping it.
- **`needs_manual_refund = true` on all reversals**: unlike `transfer.failed` (which has an auto-refund path in `processPayoutBatch`), the webhook path has no safe auto-refund mechanism, so every reversal is flagged for manual ops review.
- **No notification dispatched**: the existing notification infrastructure only handles `wallet_currency_mismatch`; adding a notification for `transfer_reversed` would require a new notification type and was out of scope for this S-effort fix. The `needs_manual_refund` flag is the ops signal.

## Notes

The Stripe SDK emits "Undefined property of Stripe\Event instance: data" notices in tests when `\Stripe\Event::constructFrom` is used without a full event shape — this is pre-existing behaviour in the test suite (present in the existing rollback test) and does not affect test correctness.

## Questions Asked
(none)
