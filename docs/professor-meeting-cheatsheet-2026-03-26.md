# Professor Meeting Cheat Sheet (Updated)

Date: 2026-03-26  
Audience: Prof. Jianzhong Qi  
Goal: Pressure-test architecture decisions before launch using explicit planning assumptions.

## 1) 60-second opening

I am building a multi-tenant affiliate platform where professionals run public storefront pages, drive traffic from social links, and generate commissionable product sales for brands.  
We are pre-launch, so these are scenario-based planning numbers from brand conversations, not production-observed metrics.

One brand indicated they could onboard around 8,000 affiliates. I want to pressure-test likely bottlenecks and decision thresholds before launch, especially around:
- synchronous public analytics writes and cache invalidation fan-out,
- analytics query/computation strategy (live queries vs pre-aggregates),
- cache-miss behavior on public payload reads,
- append-only commission ledger + payout workflow growth,
- raw event/payload storage growth and retention.

## 2) What changed since the last version

- Store analytics is now largely aggregate-table based (`analytics.*_daily`), not only live heavy queries.
- Stripe storefront flow now creates commission ledger entries directly from Stripe payment data (in addition to Shopify-canonical ingestion).
- Commission payouts now use dedicated payout tables (`commission_payouts`, `commission_payout_items`) plus ledger linkage (`payout_id`).
- Brand wallet funding and top-up flows were added (manual balance + card shortfall charging).
- Additional payout/ledger indexes were added for unpaid reversal scans and pending payout claiming.
- Public site analytics event capture is still synchronous and still invalidates analytics cache keys immediately after writes.

## 3) Planning assumptions to state clearly

Core scenario (conservative):
- 8,000 affiliates
- 50 visits/day per affiliate => 400,000 visits/day
- 10 clicks/day per affiliate => 80,000 clicks/day
- 1 sale/day per affiliate => 8,000 orders/day

More aggressive scenario:
- 2 sales/day per affiliate => 16,000 orders/day

Derived workload ranges (planning only):
- public analytics events/day (visits + clicks): 480,000
- order-driven aggregate rebuild jobs/day (brand + affiliate): about 16,000 to 32,000
- ledger rows/day (1-2 entries/order baseline before refunds): about 8,000 to 32,000

Payload growth rough order-of-magnitude:
- Each order payload is stored in both inbox and canonical order tables.
- At 8,000 orders/day and 10-30KB payloads, combined raw payload storage is roughly 58-175GB/year.

Use this line if challenged:
"These are planning assumptions to find first bottlenecks and define thresholds. We are not presenting them as observed production numbers."

## 4) Highest-value questions (priority order)

1. Given this projected load, what do you expect to fail first: DB writes, cache churn, queue backlog, or read-query latency?
2. When should public analytics writes move from inline inserts to buffered/batched ingestion?
3. We currently invalidate many analytics cache keys after every event write. What invalidation strategy would you recommend instead?
4. For public analytics summary, what should remain live-query and what should be pre-aggregated?
5. Should referrer/source classification be computed at ingestion time instead of repeated pattern matching at read time?
6. For public site payload caching, would you prioritize single-flight/request coalescing, stale-while-revalidate, or miss-path optimization first?
7. For commission accounting, when do we introduce balance snapshots/materialized totals while preserving append-only auditability?
8. How would you design indexes for tenant + time range + status filtering across ledger, payout, and order analytics paths?
9. Payout orchestration now includes wallet debit + card charge + transfer. What failure-compensation model would you use for exactly-once financial outcomes?
10. Should payout processing run inline with order processing, or be strictly decoupled with queue/batch cadence?
11. What retention/tiering policy would you apply for raw webhook/order payload JSON to control storage bloat?
12. If you had one week in this codebase, what 2-3 load experiments would you run first before architecture changes?

## 5) Additional "if time permits" questions

- What SLOs would you set first (p95 public API latency, webhook-to-dashboard freshness, payout completion lag)?
- How would you partition queue workers between integrations, analytics rebuilds, and payout jobs to avoid starvation?
- Would you keep UUID primary keys for high-write event tables, or use monotonic IDs for insertion locality?
- At what point does table partitioning become worth it for `site_visits`, `link_clicks`, and ledger tables?
- Which metrics would you instrument before launch to decide when to re-architect (not just "monitor everything")?

## 6) Exact technical evidence to reference in discussion

- Public pageview/click endpoints perform synchronous DB writes and immediate cache invalidation.
- Analytics cache invalidation loops across many date-range keys per write (high fan-out invalidation pattern).
- Professional analytics summary endpoint still runs many grouped queries, including referrer classification via pattern matching.
- Public payload cache has a TTL + negative cache sentinel, but no explicit single-flight lock on misses.
- Shopify webhook ingestion persists event inbox payload, writes canonical orders/items/ledger, and dispatches aggregate rebuild jobs.
- Payout flow now groups eligible ledger rows, creates payout batch/item rows, and executes wallet/card/transfer funding logic.
- Brand wallet top-up flow credits manual balance via Stripe Checkout confirmation with idempotent top-up records.

## 7) Closing question (recommended)

If you were in my position, what would you fix before launch, what would you instrument and defer, and what would you deliberately leave alone for now?
