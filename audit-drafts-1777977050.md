- [ ] **PLANV3-1** · P1 — Missing stub-creation logic for out-of-order `orders/edited` before `orders/paid`
    - **Where:** docs/analytics-rebuild-plan.md — "Out-of-order handling" and "Webhook Ingest" sections
    - **Affects:** Order state and frozen commission correctness when Shopify delivers the edited webhook before the paid webhook
    - **Effort:** S (~0.5–1h) to amend the plan; L (~1–2d) to implement the missing handler logic later
    - **What to do:**
        - Extend the out-of-order handling to cover all order topics, not only refunds-before-paid.
        - Clarify that handlers for `orders/edited`, `orders/cancelled`, etc., must upsert a stub row when the order is missing (similar to refunds-before-paid).
        - Ensure the stub uses `shopify_updated_at` from the inbound event so that a later `orders/paid` with a higher timestamp wins.
    - **Technical:** The plan carefully addresses Race 2 (refunds-before-paid) but ignores the same race for `orders/edited` and other order topics. Shopify can deliver `orders/edited` before `orders/create` or `orders/paid`. Without a stub creation path, the first seen event would skip the order entirely, leaving the order missing until `orders/paid` arrives — or if `orders/paid` never fires, leaving the order perpetually un-totaled. The existing LWW upsert allows any topic to insert, but the handler must be written to attempt an insert with whatever fields it knows (status, gross approximation, etc.) rather than assuming the order exists.
    - **Plain English:** The plan builds a safety net for one specific “arrive in the wrong order” scenario (refunds before the order is paid) but forgets the same problem can happen with other updates. If Shopify sends an “order edited” notice before the “order paid” notice, the system currently would have nowhere to put the edit and would either crash or ignore it. The fix is to treat every incoming notice as a potential first-seen notice and create a placeholder row that later events can fill in.
    - **Evidence:**
        > **Race 2 — `refunds/create` before the parent `orders/paid` is recorded** (gap noted in audit). The refund handler must:
        > 1. Look up the order. If absent: insert a stub `commerce.orders` row…
        > … (no equivalent language for `orders/edited`, `orders/cancelled`, etc.)

- [ ] **PLANV3-2** · P1 — `commerce.order_items` per-item commission has no stated population mechanism
    - **Where:** docs/analytics-rebuild-plan.md — Schema section for `commerce.order_items`
    - **Affects:** Top-products / GMV-by-SKU analytics (broken), order item-level commission reporting
    - **Effort:** S (~1h) to adjust plan; M (~2–4h) to implement a reliable population strategy
    - **What to do:**
        - Decide whether per-item commission is embedded in `line_items` JSONB before it hits the trigger, or whether the trigger itself computes it.
        - If embedded, add explicit step: “PHP handler computes per-line-item commission and merges it into the `line_items` JSONB before upserting the order.”
        - If trigger-based, document how the trigger can safely derive `commission_cents` and `commission_rate` from the frozen order-level totals (likely proportional split), and ensure rounding is handled.
    - **Technical:** The `order_items` table includes `commission_cents` and `commission_rate` as NOT NULL columns, and the plan says it is populated by a trigger on `UPDATE OF line_items`. The trigger can only see raw JSONB; it has no access to the business logic that determines commission rates (product metafield, brand default, etc.). Unless the PHP handler pre-computes per-item commission and stores it inside the JSONB, the trigger will be forced to insert 0 or an incorrect split. This renders the item-level commission columns unusable for accurate analytics.
    - **Plain English:** The plan says “we’ll automatically copy the line items from the order into a separate table,” but that separate table also needs to know how much commission each item earned. The automatic copy can’t figure that out — it’s like asking a printer to fill in tax amounts on an invoice. The plan needs to say who does the math, when, and where the answer is stored.
    - **Evidence:**
        ```sql
        CREATE TABLE commerce.order_items (
            ...
            commission_cents bigint NOT NULL,
            commission_rate numeric(7,4) NOT NULL,
            ...
        );
        ```
        > Normalized mirror of `line_items` JSONB, populated by `AFTER INSERT OR UPDATE OF line_items` trigger that diffs JSONB and reconciles rows.
        (No source for commission values described.)

- [ ] **PLANV3-3** · P1 — `brand_affiliate_rollup` trigger logic underspecified; likely incorrect for refund reversals and status changes
    - **Where:** docs/analytics-rebuild-plan.md — "commerce.brand_affiliate_rollup (trigger-maintained)" section
    - **Affects:** All dashboard reports using the rollup (brand totals, affiliate breakdowns); could show stale/inflated commission after refunds or cancellations
    - **Effort:** M (~2–4h) to specify the trigger logic; L (~1–2d) to implement and test
    - **What to do:**
        - Write the exact trigger function covering: insert of a new order (positive deltas for counts, gross, commission); update of an existing order (signed deltas for any changed numeric fields, including gross/refund/commission changes from webhooks).
        - Explicitly handle status transitions: when status changes from ‘approved’ to ‘cancelled’/‘voided’, produce negative deltas that reverse the previously counted commission and order count.
        - Define how `reversed_commission_cents` is incremented: should come from increases in `refund_cents` * weighted commission rate, or from a separate commission movement; the plan must pick one and document it.
        - Add handling for when `commission_cents` itself is modified (e.g., manual adjustment) so the rollup reflects the new value.
    - **Technical:** The plan states the rollup is maintained by a trigger with “signed-delta INSERT … ON CONFLICT DO UPDATE”, but provides no trigger definition. Without it, the implementation is likely to be naively add-only on insert, meaning later refunds or cancellations leave the agent’s dashboard showing commission that has been reversed. The schema includes `reversed_commission_cents` but no logic for populating it. Additionally, a status change from approved to cancelled must produce negative deltas for all previously-added figures; an INSERT-only trigger will fail to do so.
    - **Plain English:** The plan sketches a “live summary table” that updates automatically when orders change, but it doesn’t describe the rules those automatic updates should follow. It’s like saying “this spreadsheet cell will always show the current total” without writing the formula. Without that formula, the summary will keep adding but never subtracting — so after a refund or cancellation, the dashboard would still show the original, incorrect numbers.
    - **Evidence:**
        > Maintained by `AFTER INSERT OR UPDATE` trigger on `commerce.orders` using signed-delta `INSERT ... ON CONFLICT DO UPDATE`.
        … (no trigger function body, no status-transition logic described)

- [ ] **PLANV3-4** · P2 — Post-payout clawbacks not reflected in `brand_affiliate_rollup`; rollup and money-movement tables diverge
    - **Where:** docs/analytics-rebuild-plan.md — Rollup section and Commission Movements description
    - **Affects:** Long-term commission totals shown on dashboards after clawbacks (e.g., refunds occurring after payout)
    - **Effort:** M (~2–4h) to design integration; M (~2–4h) to implement a trigger or job that updates rollup when a clawback is recorded
    - **What to do:**
        - Decide whether clawbacks affect the `orders.commission_cents` column (and thus fire the rollup trigger) or are recorded only in `commission_movements`.
        - If the latter, add a mechanism (trigger on `commission_movements` or a scheduled job) that syncs `reversed_commission_cents` in the rollup with clawback amounts.
        - Document that clawbacks will reduce the `commission_cents - reversed_commission_cents` value in the rollup so read-path queries remain accurate.
    - **Technical:** The plan narrows `commission_movements` to money-movement rows (payouts, clawbacks, adjustments) and keeps the rollup trigger on `orders` only. A clawback created as a `commission_movement` row does not cause any order update, so the rollup will not see it. As a result, the dashboard’s per-affiliate summary (which reads from the rollup) will overstate net commission after payouts and clawbacks have occurred, breaking reconciliation.
    - **Plain English:** If a refund happens after an affiliate has already been paid, the system records a “clawback” in the accounting ledger. But the live dashboard numbers are fed from a separate summary table that only listens to order changes, not accounting changes. Over time, those two numbers will drift apart, and the dashboard will show the affiliate as having earned more than they actually did — a trust and reconciliation problem.
    - **Evidence:**
        > `commerce.commission_movements` (renamed from `commission_ledger_entries`, scope reduced) … only money-movement rows.
        > The rollup is maintained by `AFTER INSERT OR UPDATE` trigger on `commerce.orders`…
        (No link between clawback rows and rollup updates described.)

- [ ] **PLANV3-5** · P2 — GDPR redaction for `order_events.metadata` may miss PII fields due to unspecified stripping paths
    - **Where:** docs/analytics-rebuild-plan.md — GDPR redaction (audit-derived) section
    - **Affects:** Customer PII exposure risk under GDPR / data subject requests; non-trivial if the event metadata contains refund notes, customer service notes, etc.
    - **Effort:** S (~1h) to audit and list all PII-carrying paths in metadata; S (~1h) to encode them in the `jsonb_strip_pii` function
    - **What to do:**
        - Perform an audit of all webhook and system events that write to `order_events.metadata` and catalogue every path that could contain PII (customer name, email, phone, address, gift notes, etc.).
        - Encode those paths explicitly in the `jsonb_strip_pii` function (empty-string-aware nullification) rather than relying on a generic “strip known PII paths” heuristic.
        - Add a test that inserts a synthetic event with sample PII and verifies that after GDPR redaction the JSONB contains no identifiable data.
    - **Technical:** The plan says `metadata = jsonb_strip_pii(metadata)` but does not enumerate the JSON paths to be cleaned. Event metadata may include free-text fields like refund notes or adjustment reasons that could contain customer PII not captured by the standard `customer.*` paths. Without a closed list, the redaction function may pass through PII, violating GDPR obligations. This is especially sensitive because `order_events` is kept indefinitely as an audit log.
    - **Plain English:** An “anonymise” function is only as good as the list of fields it knows to wipe. If a staff member writes a refund note that includes the customer’s name or email, and the function isn’t told to look in the “notes” bucket, that personal data sticks around forever. The plan should name every bucket, not just say “strip known fields.”
    - **Evidence:**
        > **GDPR redaction (audit-derived)**
        > … `metadata = jsonb_strip_pii(metadata)` on `commerce.order_events`.
        > `jsonb_strip_pii` is a new SQL function that takes a JSONB and a list of paths to NULL out.
        (No enumerated list of paths, no handling of nested free-text fields.)
