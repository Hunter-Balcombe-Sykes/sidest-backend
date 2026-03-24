# Shopify-Canonical Brand + Professional Analytics (Gap-Closed Plan)

## Summary
Implement a backend-only analytics system sourced from Shopify-confirmed orders, with deterministic attribution and auditable financials.  
Applied decisions:
- Professionals are single-brand for shop flows.
- Legacy analytics endpoints are removed immediately.
- One-brand rule is enforced in API/services (not DB constraint).
- Add minimal payout-run audit table (no payout items/batches).
- Campaign/referral/discount analytics remain out of scope.

## Key Implementation Changes

### 1) Data model and migrations
Create/adjust these tables and constraints:

1. `retail.checkout_sessions`
- Keep token-based session attribution.
- Require `affiliate_professional_id`, `brand_professional_id`, `site_id`, `expires_at`, `status`.
- `context_snapshot` stores attribution context and pricing/commission resolution inputs used for audit.
- Indexes: `token` unique, `(affiliate_professional_id, status)`, `(brand_professional_id, status)`.

2. `retail.order_event_inbox`
- Keep idempotent webhook inbox with `UNIQUE(source, external_event_id)`.
- Add `shop_domain` and `integration_id` for deterministic brand resolution.
- Statuses: `pending|processing|processed|rejected|failed`.

3. `retail.orders`
- Use explicit status dimensions:
  - `lifecycle_status`: `open|closed|cancelled`
  - `financial_status`: `pending|authorized|paid|partially_refunded|refunded|voided`
  - `fulfillment_status`: `unfulfilled|partial|fulfilled|restocked`
- Store money fields in cents: `gross_cents`, `refunded_cents`, `returned_cents`, `net_cents`.
- Add timestamps: `ordered_at`, `paid_at`, `cancelled_at`, `closed_at`, `updated_at`.
- Store `brand_professional_id`, `affiliate_professional_id`, `checkout_session_token`.
- Indexes: `(brand_professional_id, ordered_at)`, `(affiliate_professional_id, ordered_at)`, `shopify_order_id` unique.

4. `retail.order_items` (fixes prior schema bug)
- Add explicit `brand_professional_id` column.
- Store `brand_product_id`, qty, line amounts, refund/return amounts, product snapshot.
- Add trigger validation: item brand must equal parent order brand.

5. `retail.order_attributions`
- One immutable row per order with `model`, `model_version`, `reason`, `lineage`.

6. `retail.commission_ledger_entries`
- Append-only with idempotency key.
- Keep `entry_type` (`accrual|reversal|payout`) and status (`pending|approved|paid|reversed|disputed`).
- Add optional `payout_run_id` FK.
- Persist `commission_rate` and calculation metadata to keep financials deterministic after config changes.

7. `retail.payout_runs` (new minimal audit table)
- Columns: `id`, `brand_professional_id`, `period_start`, `period_end`, `scheduled_for`, `executed_at`, `status`, `total_cents`, `currency_code`, `external_reference`, `notes`, `created_by_professional_id`.
- No payout item table in MVP.

8. `retail.report_exports` and `retail.report_schedules`
- Keep async export tracking and schedule persistence.

9. `core.professional_integrations`
- Add app-level Shopify provider support (`shopify` constant/relation/validation paths).
- Store `shop_domain` and webhook IDs in `provider_metadata`.

10. Aggregate tables (daily physical only)
- `analytics.brand_metrics_daily`
- `analytics.brand_influencer_daily`
- `analytics.brand_product_daily`
- `analytics.brand_influencer_product_daily` (needed for product-by-influencer and self product analytics)
- `analytics.brand_commission_daily`
- `analytics.brand_payout_daily`
- `analytics.brand_region_daily`
- `analytics.brand_customer_daily` (phase-1 fields)
- `analytics.professional_metrics_daily` (self totals in professional timezone)
- `analytics.professional_product_daily` (self product views)

All daily tables must define explicit PK/unique grain, currency, timezone, and `updated_at`.

### 2) Attribution, ingestion, and financial logic
1. Shopify webhook flow
- Route: `POST /webhooks/shopify/orders`.
- Validate HMAC, dedupe by `X-Shopify-Webhook-Id`, write inbox, async process.
- Resolve brand via `professional_integrations(provider='shopify', provider_metadata.shop_domain=<header>)`.
- If brand resolution is ambiguous/missing, reject inbox row with reason.

2. Single-brand API enforcement
- `POST /public/store/checkout-session` resolves affiliate from site.
- Enforce exactly one connected brand for shop context:
  - 0 brands => 422
  - >1 brands => 409 `MULTIPLE_BRANDS_NOT_SUPPORTED`
- Mint token only for the single resolved brand.

3. Order attribution
- Only valid `comet_session` token orders are normalized into canonical `retail.orders`.
- Missing/invalid/expired token => inbox `rejected`; excluded from KPI aggregates.
- Attribution model is deterministic and single-source (`checkout_session_brand_owner`).

4. Commission determinism
- Resolve commission rate by priority:
  - affiliate-product override
  - brand-product override
  - brand default
- Persist chosen rate/source on accrual entries.
- Refund/return reversals are proportional and append-only.
- Historical commission changes never rewrite prior accrual rows.

5. Payout obligations and history
- Upcoming obligations from approved/unpaid ledger state.
- Paid history from payout ledger entries linked to `payout_runs`.
- `brand_payout_daily` materializes payout KPIs for fast queries.

### 3) API contracts, filters, and permissions
1. Brand analytics endpoints
- `GET /store/brand-analytics/overview`
- `GET /store/brand-analytics/influencers`
- `GET /store/brand-analytics/influencers/{professionalId}`
- `GET /store/brand-analytics/products`
- `GET /store/brand-analytics/products/{brandProductId}`
- `GET /store/brand-analytics/commissions`
- `GET /store/brand-analytics/payouts`
- `GET /store/brand-analytics/timeseries`
- export/schedule endpoints as planned

2. Self/professional analytics endpoints
- `GET /store/my-analytics/overview`
- `GET /store/my-analytics/products`
- `GET /store/my-analytics/products/{brandProductId}`
- `GET /store/my-analytics/commissions`
- `GET /store/my-analytics/payouts`
- `GET /store/my-analytics/timeseries`
- export endpoints as planned

3. Legacy endpoint handling (your choice: immediate removal)
- Remove analytics behavior from:
  - `GET /store/analytics`
  - `GET /store/brand-analytics`
- Return `410 Gone` with migration error payload during cutover window, then remove routes.

4. Common filters and list behavior
- `from`, `to`, `group_by=day|week|month`
- `product_ids[]`, `categories[]`, `collections[]`, `regions[]`
- `lifecycle_status[]`, `financial_status[]`, `payout_status[]`
- `sort_by`, `sort_dir`, `page`, `per_page` (max 100)
- Weekly/monthly rollup groups from daily `day` buckets with Monday week start (no `AT TIME ZONE` on date columns).

5. Permissions
- Brand endpoints require brand in `managedBrandIds`.
- Self endpoints hard-scope to authenticated professional as affiliate.
- Enforce scope in SQL predicates, not controller-only checks.
- MVP keeps existing access model (no brand team role matrix yet).

### 4) Jobs, rollout, and observability
1. Jobs
- `RegisterShopifyOrderWebhooksJob`
- `ProcessShopifyOrderEventJob`
- `RebuildBrandDailyAggregatesJob`
- `RebuildProfessionalDailyAggregatesJob`
- export generation jobs

2. Aggregate rebuild strategy
- Event processor marks affected `(brand, affiliate, day)` dirty keys.
- Rebuild jobs upsert deterministic daily rows for dirty keys.
- Handle late events by reprocessing prior N days (e.g., 35-day sliding window).

3. Rollout order
- Deploy schema + grants.
- Deploy Shopify integration/provider support.
- Deploy checkout-session endpoint and frontend token write.
- Deploy webhook inbox/normalizer in shadow.
- Deploy ledger + payout runs.
- Deploy aggregate writers and validate parity.
- Remove legacy analytics endpoints (410 cutover), enable new endpoints.
- Enable exports/report schedules.

## Test Plan
1. Attribution/inbox
- HMAC valid/invalid.
- Dedup by webhook id.
- Brand resolution by `shop_domain`.
- Invalid/missing token rejection with audit reason.
- Token maps to correct affiliate/brand.

2. Single-brand enforcement
- `checkout-session` returns 422 for no brand link.
- Returns 409 when affiliate has >1 brand links.
- Normalizer rejects cross-brand line items.

3. Financial correctness
- Commission priority resolution and persisted source.
- Partial/full refund reversals are proportional and deterministic.
- Ledger append-only (no updates to posted rows).

4. Aggregate correctness
- Daily tables match normalized raw data.
- Weekly/monthly rollups correct from daily.
- Boundary-day correctness per stored timezone buckets.

5. API/security
- Brand scope enforced by SQL predicates.
- Self scope cannot be overridden by params.
- Filters/sort/pagination deterministic.
- Legacy endpoints return 410 during cutover.

## Assumptions and defaults
- Shop analytics supports one connected brand per professional for MVP shop flows.
- Campaign/referral/discount analytics remain out of scope.
- No historical backfill in MVP.
- No brand team role matrix in MVP; current access model applies.
- Shopify confirmed orders are canonical source of truth.
