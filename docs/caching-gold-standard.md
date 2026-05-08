# Gold Standard Caching for Shopify / Hydrogen / Oxygen

A strategic reference for money-sensitive headless e-commerce systems, with specific application to the Partna backend.

Distilled from Shopify's official documentation, Hydrogen GitHub discussions, vendor blogs (Netlify, Hookdeck, Truestorefront), Shopify Engineering posts, and community/forum threads. Sources at the end.

---

## 1. Executive summary — the operating posture

- **Catalog/display data:** short TTL + stale-while-revalidate at the edge, version-keys at the origin, push-invalidate from webhooks with a 5–10s delay buffer.
- **Money paths (commissions, payouts, refunds, accruals):** never cache the calculation; snapshot the result at write time and treat the snapshot as immutable truth.
- **Customer-bound data (cart, checkout, account, B2B price):** `CacheNone()`, full stop. Most production Shopify breaches involve cross-customer cache leaks.
- **Webhook handlers:** idempotency keyed on `X-Shopify-Webhook-Id`, not order ID. Shopify retries 8x over 4 hours.
- **Oxygen edge:** **no purge API.** TTL is the only invalidation lever. Plan around this constraint.
- **Hydrogen's defaults are deliberately under-tuned for safety** — `CacheShort` is 1s+9s, the framework default is 1s+86399s SWR. Shopify expects you to upgrade most queries to `CacheLong` once you understand them.
- **Storefront API itself has cache propagation lag** — sometimes seconds, sometimes (documented bug) days. You cannot evict Shopify's internal edge cache.
- **Cache stampede protection is your problem, not Shopify's.** Hydrogen's in-memory single-flight only coalesces within a single V8 isolate. At your origin, you must dedup at Redis.
- **Webhook delivery is at-least-once, never exactly-once.** Idempotency on `X-Shopify-Webhook-Id` is mandatory for every money-sensitive handler.

---

## 2. Money-sensitive paths — the "never cache" list

These data paths must always read live, every time, with no edge or app-level caching:

| Path | Why never |
|---|---|
| **Cart state** (`cart`, `cartLines`, `cartBuyerIdentityUpdate`) | Mutates per-keystroke; cached cart can resurrect deleted items (Hydrogen #2391) |
| **Checkout URLs / checkout tokens** | One-shot, signed, customer-bound |
| **Customer Account API queries** | Personal info; framework already excludes from cache |
| **B2B / wholesale prices via `@inContext(buyer:...)`** | Shared-cache leak = wrong customer pays wrong price |
| **Webhook ingestion handlers** | Idempotency table is the source of truth, not a cache |
| **Commission rate lookups at order-paid time** | Reading a stale commission rate at write time is permanent — there is no "fix it later" once the row is in `commission_movements` |
| **Order-paid → commission accrual write path** | Always live SQL; the snapshot is the audit log |
| **Refunds / clawbacks** | Symmetric to accrual; must be live |
| **Payouts / ledger settlement** | "Compute live, never cache" — every public affiliate vendor doc agrees |
| **Discount code application** | Codes can be revoked, expired, capacity-limited — caching = giving away money |
| **Inventory at decision-to-sell** (commit-to-cart, checkout) | Oversell risk; cart and checkout already bypass Hydrogen cache by default |

---

## 3. Money-sensitive paths — the "cache carefully" list

| Data | Strategy | TTL | Invalidation trigger |
|---|---|---|---|
| Product list / collection (anonymous browse) | `CacheLong` (1h fresh + 23h SWR) | 1h | `products/update`, `collections/update` webhook → SWR-natural revalidation, accept lag |
| Product detail page (anonymous) | `CacheLong` for descriptions/images, `CacheShort` (1s+9s) for price/variant | mixed | `products/update`; for price specifically prefer 5-min TTL to bound mispricing exposure |
| Inventory display (browse-tier UX, not commit) | `CacheShort` | 10s total | Accept eventual consistency; never read cached value at add-to-cart |
| **Brand design tokens / settings (Partna)** | Custom (matched to Oxygen SWR) | **5s + 5s** — short fresh + bounded SWR is the canonical match for Oxygen edge that doesn't honor `swr` natively |
| **Storefront-up probe (Partna)** | Single-flight + short TTL | 60s with single-flight (matches `CacheLockService::rememberLocked`) | Auto-expire |
| Analytics summaries (counts, revenue, payout-to-date) | App-level Redis | 60–300s with SWR + lock | Push-invalidate on webhook write to underlying tables |
| Affiliate projections (forecast, not actuals) | App-level Redis | 5–15 min | TTL only; forecasts are inherently stale |
| Rate-limit cost cache (Admin API budget tracker) | In-memory worker | 1s | Auto-expire |

**Reasoning on prices specifically:** the consensus across the Netlify guide, the Truestorefront guide, and community threads is that 5-minute pricing TTL with on-demand revalidation via webhook is the upper bound merchants tolerate; 1-hour pricing cache with SWR is acceptable only if you trust SWR to refresh fast enough under your traffic. Partna's 5-minute Shopify catalog cache is in the safe zone.

---

## 4. Hydrogen caching strategies

### The four canonical strategies (Hydrogen latest)

| Strategy | Cache-Control header | Total budget | Recommended for |
|---|---|---|---|
| `CacheShort()` | `public, max-age=1, stale-while-revalidate=9` | 10s | Inventory, prices in unstable conditions, search counts |
| `CacheLong()` | `public, max-age=3600, stale-while-revalidate=82800` | 24h | Product titles/descriptions/images, navigation, blog posts, footer |
| `CacheNone()` | `no-store` | 0 | Cart, checkout, customer queries, B2B pricing, anything personalized |
| `CacheCustom({...})` | User-defined | User-defined | Anything that needs precise tuning |
| **Default (no override)** | `public, max-age=1, stale-while-revalidate=86399` | 24h | Hydrogen's fallback |

### Two layers, two responsibilities

1. **Sub-request cache** — caches each Storefront/third-party API response by query+variables, scoped to the deployment. Controlled per-query via the `cache:` option.
2. **Full-page cache** — caches the rendered HTML response at the Oxygen edge. Controlled by the loader's response `Cache-Control` (and `Oxygen-Cache-Control`) header.

**Critical security note from the docs:** "Using `CacheNone()` on the subrequest prevents caching the API response, but Remix can still cache the rendered HTML response." You must set BOTH the subrequest to `CacheNone()` AND the page response to `no-store` or `private, max-age=N` for personalized data.

### `createWithCache` for third-party data

For non-Storefront-API calls (e.g., Partna's Laravel backend), use `createWithCache` to wrap a `fetch` with a strategy + cache key.

**Critical pitfall** documented by Shopify: "If caching data for logged-in users, then make sure to add something unique to the user in the cache key, such as their email address."

`withCache.fetch` only caches `response.ok` by default. APIs that return 200 with embedded errors require `shouldCacheResponse` overrides — relevant when stubbing successful payloads from Laravel that contain a degraded-mode flag.

### What Hydrogen does NOT cache

- **Customer Account API queries** are excluded from caching at the framework level.
- POST GraphQL operations are not cached unless explicitly opted-in.

### Default safety vs default performance

The Hydrogen team explicitly chose `CacheShort` semantics as the framework default because it's "the safest setting to start with but not the best setting for caching performance." **You are expected to upgrade most queries to `CacheLong` once you understand them.**

---

## 5. Oxygen edge caching

### How it works

- Oxygen runs Hydrogen workers on Cloudflare's `workerd` runtime.
- Sub-request cache uses Cloudflare's Cache API. **"The contents of the cache aren't accessible outside of the originating data center"** — meaning each Oxygen DC is its own cache shard.
- Full-page cache gates on the `Oxygen-Cache-Control` response header.

### Cacheability rules (full-page)

A response is cached only if ALL of:

1. GET request
2. 2XX or 3XX status
3. `Oxygen-Cache-Control` header with `public` directive AND non-zero `max-age` or `s-maxage`
4. `Vary` header is set and not `Vary: *`

If `Set-Cookie` is present, the response is NOT cached automatically.

### Stale-while-revalidate at the edge

- **The underlying Cloudflare `cache.put`/`cache.match` does not honor `stale-while-revalidate`** — that's a documented Oxygen runtime limitation.
- Oxygen's full-page cache implements its own SWR layer in userland: when a stored response is past `max-age`, the stale copy is served and the worker is invoked in the background to refresh.

### Invalidation

- **No purge API for the live worker.** You wait for TTL, or redeploy. A new deploy "starts with a fresh cache" — Oxygen "won't be able to serve a cached response from a previous deployment on a new deployment."
- Cloudflare's Cache API has selective `cache.delete()`, but Oxygen has not exposed a tag-based purge primitive at the platform level.
- **Practical consequence:** you cannot push a price-change webhook to Oxygen and force a global flush. You can either lower TTL to your tolerable staleness window, or redeploy on every catalog mutation (not viable at scale).

### `oxygen-full-page-cache: uncacheable` debugging

This response header doesn't mean "broken" — it means "the worker has opted in but this specific request hasn't yet promoted to the cache tier." States cycle: `uncacheable` → `miss` → `hit`. **Pages that don't get a hit every ~2 minutes drop back to `uncacheable`.** Per-worker, per-DC. So in low-traffic pre-beta conditions, the full-page cache barely fires.

### Personalization at the edge

Use `private, max-age=N` for per-user cacheable content (browser caches, shared/edge caches do not). Use `no-store` for anything truly secret. Never `public` on a route that returns customer data — that has been the single most-warned-about footgun in every official doc.

**`no-cache` directive is silently dropped by Oxygen.** Use `no-store` (full disable) or `private` (per-user only).

---

## 6. Shopify Storefront / Admin API caching

### Storefront API

- **No fixed RPM limit for real buyer traffic.** Bots get throttled (`430 Shopify Security Rejection` for unsigned anonymous bots; signed Web Bot Auth gets higher allowances).
- **Storefront API responses do not return useful Cache-Control headers for the consumer to honor as-is.** The Hydrogen client wraps responses in its own cache layer keyed by query+variables.
- **Edge caching is internal to Shopify** and routes via data centers tagged in the `X-DC` response header. There have been documented incidents where one DC's edge cache was stuck on stale metaobjects for a week with no operator-visible cause; resolution required Partner Support.
- **Practical implication:** treat every Storefront API read as eventually consistent with Admin writes. There is no "purge from outside" lever.

### Admin API

- **REST**: leaky bucket, 60 marbles, 2/s leak rate (Standard), 429 with `Retry-After`-style guidance via `X-Shopify-Shop-Api-Call-Limit` header.
- **GraphQL**: cost-based — Standard 100 pts/s sustained, Plus 1000 pts/s, Enterprise 2000 pts/s; max single query 1000 pts.
- **Official caching guidance for Admin API:** "Use caching for data that your app uses often." That's it. Shopify expects you to build it.
- **Mature pattern for high-volume Admin reads:** cache catalog/metafield reads by a versioned key (e.g., `product:{id}:v{updated_at_epoch}`), invalidate on `products/update` webhook, fall back to TTL of 5 minutes. Partna's current 5-minute Redis TTL on Shopify catalog is on-spec.

### Headers worth knowing

- `X-Shopify-Webhook-Id` (deduplication key — most important header for money paths)
- `X-Shopify-Hmac-Sha256` (HMAC verification, raw body)
- `X-Shopify-Shop-Api-Call-Limit` (REST rate-limit observability)
- `X-Shopify-Topic`, `X-Shopify-Triggered-At` (use the latter to detect out-of-order delivery)

---

## 7. Invalidation patterns — the heart of this

Five patterns dominate, in order of sophistication:

### 7.1 TTL-only (passive)

Cache expires, fresh read happens. Simple, robust, lossy on freshness. **Use when: invalidation cost > acceptable staleness.** Hydrogen defaults universally use this.

### 7.2 TTL + stale-while-revalidate

Serve stale immediately; refresh in background. The Hydrogen/Oxygen default. **Use when: latency matters and slight staleness is acceptable.**

- Caveat: under low traffic, SWR doesn't fire often enough to keep the cache warm. Pre-beta this matters.
- Caveat: Oxygen Cache API doesn't support SWR natively — Hydrogen layers it on. App-side Redis SWR is more direct than Oxygen's.

### 7.3 Webhook-driven push invalidation

Receive `products/update` / `inventory_levels/update` / `collections/update` → invalidate or refresh affected keys.

- **The race condition**: Shopify fires the webhook before the Storefront API reflects the change. Re-fetching immediately re-caches stale data.
- **The fix**: queue the revalidation 2–10 seconds after webhook receipt (community consensus). For prices specifically, lean toward 10 seconds.
- **The dedup**: every webhook handler MUST treat `X-Shopify-Webhook-Id` as a unique key in a short-TTL store (24–48h). Shopify retries 8x over 4h.

### 7.4 Tag-based invalidation

`Cache-Tag: product:{id}` set on response; webhook → `purgeCache({ tags: [...] })`. **Used by Netlify; not exposed by Oxygen at the platform level.** This is the gold-standard pattern Oxygen lacks today.

### 7.5 Generation-counter / version-key

`product:{id}:v{updated_at_epoch}` — cache key changes when source-of-truth `updated_at` changes. Stale entries become unreachable, garbage-collected by TTL.

- **This is the strongest invalidation pattern when no purge API exists.** It works around Oxygen's missing purge.
- Cost: writes-to-source must publish the new version; readers must look up the version before computing the cache key (one extra lookup, often hot in memory).
- For commission-rate-bearing tables in Postgres, the version is a row's `updated_at`. Bake this into every analytics cache key and you can never read a rate older than the row.

### Hybrid is the standard

A real production stack uses all five at different layers:

- **L1**: in-memory in-isolate (single-flight via `singleflight` package or `CacheLock`)
- **L2**: Redis with version-key + TTL + soft SWR
- **L3**: edge (Oxygen) with `CacheLong` + SWR (TTL-only, no purge)
- **Webhook invalidation** pushes new versions into L2; L3 follows on TTL.

---

## 8. What happens when X changes

### Price change

- **Optimistic**: merchant edits price in Admin → `products/update` fires → handler waits 5–10s → refetches Storefront API → updates local cache → publishes new version key.
- **Realistic**: Storefront API edge in some DC serves the old price for 1–60+ seconds beyond the webhook. Anonymous browse pages on Hydrogen with `CacheLong` may show old price for up to 24h until SWR refreshes (driven by traffic).
- **At checkout**: Shopify recalculates server-side. The risk is purely UX (sticker-shock at checkout when cart shows $X and checkout shows $Y).
- **Recommendation**: prices on PDP at most `CacheShort` (10s) or `CacheCustom({maxAge: 60, staleWhileRevalidate: 300})`; never `CacheLong` for the price field even if titles are CacheLong.

### Catalog removal (product unpublished/archived/deleted)

- **Most dangerous case.** A cached product page can sell something that no longer exists; cart-add fails, customer churns, or worse — the order goes through against a stale variant ID.
- `products/delete` and `products/update` (with `status: ARCHIVED`) webhooks should both trigger eviction.
- On Oxygen with no purge API: TTL is your only lever. A merchant who archives a product expects it gone in seconds, not hours. **This argues for short TTL on product detail pages even though descriptions are stable.**
- Shopify's Storefront API will eventually 404 the product, but with documented propagation lag.
- **Pattern**: store a "known-deleted" set in your backend (push from `products/delete` webhook), short-circuit the cached-Hydrogen response if the SKU is in that set.

### Commission rate update

- **The only correct posture is: rates are read at write time, not at read time.** When `orders/paid` fires, the handler reads the *current* commission rate from the `affiliates`/`brand_commission_overrides` table (live DB, never cache) and writes the resulting `commission_cents` into `commerce.orders` as a snapshot. That snapshot is the truth forever after.
- **Mid-order rate changes**: if a merchant updates a commission rate at 14:00 and an `orders/paid` arrives at 14:00:30 with a `Triggered-At` of 13:59:55, you must decide whether to honor the 14:00 rate (current at processing) or the 13:59:55 rate (current at trigger). Refersion/Social Snowball both honor "rate at order creation" — i.e., what the affiliate was promised when the click landed.
- **Consequence for caching**: any cache that derives commission_cents from a rate must be keyed by the rate version. Bumping the rate without bumping cache versions = paying an affiliate the wrong amount.

### Refund issued

- `refunds/create` webhook → reverse the corresponding commission accrual via `brand_affiliate_rollup.reversed_commission_cents`.
- **Cache implication**: every analytics cache that includes "lifetime earnings" or "this-month earnings" must invalidate on refund webhook. If cached for 5 minutes, you can show a wrong earnings number for 5 minutes. Tolerable.
- **Refund-after-payout edge case**: the `commission_movements` clawback row is created. Caching this is fine; just push-invalidate the affected payout summary on webhook.

### Inventory drop to zero

- `inventory_levels/update` payload only contains the new `available` value, not the delta. Without a previous-value cache, you can't reconstruct the change.
- **Pattern**: cache the last-known inventory level keyed by `inventory_item_id`; when the webhook fires, compute delta = new − cached, then write back.
- For storefront caching: drop-to-zero events should evict any cached "in stock" variant decoration. `CacheShort` on inventory display is the floor.
- **2026-01 API gotcha**: `inventory_levels/update` started returning empty payloads when multiple items updated simultaneously. Several apps lost inventory-sync correctness for days. Lesson: **never trust webhook payload for source-of-truth; always re-query Shopify for the current state, with the dedup ID gating the work.**

### Brand updates store settings

- Push-invalidate the brand-design payload key in Laravel cache.
- Hydrogen's `withCache.fetch` to `/api/brand-design` will miss next request → fetch fresh.
- Oxygen edge cache will serve old payload until Oxygen's TTL expires; if the staleness budget is 5s + 5s SWR, real propagation is ~5–10s end-to-end. Match Oxygen's `Oxygen-Cache-Control` `max-age` to Laravel-side TTL or there will be weird "edge fresher than origin" inversions.
- **Partna's existing 5s Redis matched to Oxygen SWR is exactly the right pattern.**

---

## 9. Common pitfalls and horror stories

### 9.1 The Storefront API metaobject staleness bug

Multiple developers reported the Storefront API returning stale metaobject data for hours or even days. Root cause was identified as a Shopify edge cache invalidation bug in specific data centers (visible via `X-DC` header). One reported: "spontaneously resolved after a week." Resolution required Partner Support intervention. Workaround: **none** — you cannot evict a Shopify-internal edge cache from outside.

### 9.2 Webhook-fires-before-API-reflects (THE classic race)

Vercel/commerce #1239 documented this for Next.js but the bug is universal: webhook arrives, your handler fires `revalidate()`, Storefront API still returns old data, you cache the old data for the next TTL window. **Fix: deliberate 2–10s delay before refetch on webhook, or version-key pattern that lets the next traffic-driven read miss naturally.**

### 9.3 Cart deletion that didn't stick

Hydrogen issue #2391 (2024.7.2): items deleted from cart reappear when navigating back from checkout. Root cause was cache layering between client-side cart state and server-rendered cart route. Lesson: **cart and any mutation-derived view must be `CacheNone`, AND the route must opt out of full-page cache, AND client-side cart-count cannot be hydrated from cached HTML.**

### 9.4 The full-page cache leaking customer data

Discussion #2513 surfaces this as the #1 critical issue: server-rendered cart/personalization data was being cached at the edge and served to all users. Resolution: **move all personalized data to client-side fetches after page render**, mark all customer routes `private` or `no-store`. Every Shopify caching doc warns about this — and it still happens because the failure mode is silent until a customer notices someone else's name in their cart.

### 9.5 Cross-isolate stampede

Hydrogen's in-memory single-flight only coalesces within a single V8 isolate. Under burst traffic across multiple isolates in the same DC, the same cache key can be revalidated 5–10x in parallel. Hydrogen's mitigation: trust that the Storefront API has its own deduplication layer. Reality at the origin (Laravel + Redis): you must not rely on edge dedup. Partna's `CacheLockService::rememberLocked` correctly pushes the dedup boundary to Redis, which is shared. **This is the right move.**

### 9.6 Inventory webhook empty-payload gotcha

On 2026-01 API version, `inventory_levels/update` started returning empty payloads when multiple items updated simultaneously. Several apps lost inventory-sync correctness for days before noticing. Lesson: **never trust webhook payload for source-of-truth; always re-query Shopify for the current state, with the dedup ID gating the work.**

### 9.7 Affiliate clawback hell

Refersion and Social Snowball both publicly document the policy of **holding payouts until refund window passes** — typically 30 days. Reason: orders mutate (`orders/edited`), refunds happen, and a commission row written at `orders/paid` is provisionally true at best. Mature systems treat the ledger entry as accrued-but-not-payable until the no-refund window closes. Caching commission "lifetime earnings" without consuming the refund webhook stream causes the dreaded "I was paid less than my dashboard said" support ticket. Partna's `commission_movements` model with explicit `payout` / `clawback` / `adjustment` types is the right shape; ensure all caches keyed off it bust on `refunds/create`.

### 9.8 No-cache directive silently ignored

The `no-cache` directive is not supported by Oxygen — it's silently dropped. If you copied a Cache-Control template from a generic guide and used `no-cache` thinking it disables caching, you'd cache. Use `no-store` (full disable) or `private` (per-user only).

---

## 10. Quick-reference recommended pattern table

| Data type | Strategy (Hydrogen) | TTL | Invalidation trigger |
|---|---|---|---|
| Product title/description/images | `CacheLong()` | 1h+23h SWR | `products/update` (TTL-driven, accept lag) |
| Product price (storefront browse) | `CacheCustom({maxAge: 60, swr: 300})` | 1m fresh, 5m total | `products/update` + 10s delay |
| Product variant availability | `CacheShort()` | 1s+9s SWR | `inventory_levels/update` |
| Collection / category list | `CacheLong()` | 1h+23h SWR | `collections/update` |
| Navigation / footer / static pages | `CacheLong()` | 1h+23h SWR | Manual on CMS change |
| Search results | `CacheShort()` or `CacheNone()` | <=10s | None — query-keyed naturally |
| Cart state (`cart`, lines, totals) | `CacheNone()` | 0 | Always live |
| Checkout URL | `CacheNone()` | 0 | Always live |
| Customer account / orders | `CacheNone()` (framework-enforced) | 0 | Always live |
| B2B contextual price (`@inContext`) | `CacheNone()` unless cache key includes buyer/companyLocation | 0 default | Always live |
| Discount code validity | `CacheNone()` or trivial TTL | 0–5s | Always live |
| **Brand design tokens (Partna)** | `CacheCustom({maxAge: 5, swr: 5})` | 5s+5s | Push-invalidate from Laravel on settings save |
| **Storefront-up probe (Partna)** | App-level single-flight + 60s | 60s | Auto-expire |
| Analytics summary (revenue, counts) | App-level Redis SWR | 60–300s | Push on `orders/paid`, `refunds/create`, `orders/edited` |
| Affiliate projections (forecasts) | App-level Redis | 5–15m | TTL only |
| Commission rate read | **Never cache** | 0 | Live DB |
| Webhook idempotency record | Redis | 24–48h | Auto-expire after retry window |
| Shopify Admin API catalog (Partna) | App-level Redis with version key | 5m | `products/update` webhook |

---

## 11. Specific gaps in Partna's current setup

Calling these out because the report is meant to drive planning:

1. **`X-Shopify-Webhook-Id` dedup table for commission-bearing webhooks.** Verify idempotency is keyed on this header (not the order ID). Shopify's at-least-once delivery will eventually double-credit a commission. Shopify retries 8x in 4h.
2. **Webhook → revalidate race**: if the `products/update` handler refetches Storefront/Admin immediately, it may be caching pre-propagation data. Add a 5–10s delay or trust TTL.
3. **Version-key invalidation is weaker than tag invalidation.** Without an Oxygen tag-purge API, version-keys (e.g. `brand:{id}:v{updated_at_epoch}`) are the strongest tool — adopt them on every cache key derived from a mutable Postgres row.
4. **Oxygen's full-page cache won't promote to `hit` until traffic crosses ~30/min per page.** Pre-beta, the edge cache barely fires. Today's perf wins are mostly Laravel-side; budget Oxygen wins for after launch.
5. **Cross-isolate stampede on the brand-design 5s window is plausible at peak.** `CacheLockService::rememberLocked` is the right answer at the origin. Verify there's no path that bypasses it.
6. **Analytics caching of refund-affected views**: confirm every analytics key invalidates on `refunds/create` and `orders/edited`, not just `orders/paid`. Order edits and refunds are where commission deltas actually happen post-accrual.
7. **B2B / wholesale**: not in current scope but if wholesale tiers are added, the entire price-bearing portion of cached responses must move to `CacheNone` or include `companyLocationId` + customer in the cache key.

---

## 12. Sources

### Shopify official docs

- [Caching Shopify API data with Hydrogen and Oxygen](https://shopify.dev/docs/storefronts/headless/hydrogen/caching)
- [Oxygen Full-page cache](https://shopify.dev/docs/storefronts/headless/hydrogen/caching/full-page-cache)
- [Caching third-party API data with Hydrogen and Oxygen](https://shopify.dev/docs/storefronts/headless/hydrogen/caching/third-party)
- [Oxygen runtime](https://shopify.dev/docs/storefronts/headless/hydrogen/deployments/oxygen-runtime)
- [CacheLong utility](https://shopify.dev/docs/api/hydrogen/latest/utilities/cachelong)
- [CacheShort utility](https://shopify.dev/docs/api/hydrogen/latest/utilities/cacheshort)
- [createStorefrontClient](https://shopify.dev/docs/api/hydrogen/2025-01/utilities/createstorefrontclient)
- [Shopify API limits](https://shopify.dev/docs/api/usage/limits)
- [REST Admin API rate limits](https://shopify.dev/docs/api/admin-rest/usage/rate-limits)
- [Contextual queries / @inContext](https://shopify.dev/docs/storefronts/headless/building-with-the-storefront-api/in-context)
- [Headless with B2B](https://shopify.dev/docs/storefronts/headless/bring-your-own-stack/b2b)

### Shopify Engineering

- [Building Blocks of High Performance Hydrogen-powered Storefronts](https://shopify.engineering/high-performance-hydrogen-powered-storefronts)

### Shopify GitHub discussions and issues

- [RFC: Hydrogen Caching #640](https://github.com/Shopify/hydrogen/discussions/640)
- [RFC: Cache support in Hydrogen v1 #98](https://github.com/Shopify/hydrogen-v1/discussions/98)
- [oxygen-full-page-cache: uncacheable #2513](https://github.com/Shopify/hydrogen/discussions/2513)
- [Cache stampede prevention #1274](https://github.com/Shopify/hydrogen/discussions/1274)
- [Storefront API Caching Issue - Stale Metaobjects #199](https://github.com/Shopify/storefront-api-feedback/discussions/199)
- [Cart Bug: Deleted Items Reappear #2391](https://github.com/Shopify/hydrogen/issues/2391)

### Community / vendor / blog

- [Vercel commerce #1239 — Webhook premature trigger](https://github.com/vercel/commerce/issues/1239)
- [Netlify guide — Hydrogen + cache tags](https://developers.netlify.com/guides/load-your-hydrogen-e-commerce-site-pages-faster-with-netlifys-advanced-caching-primitives/)
- [Truestorefront — Caching Strategies for Hydrogen](https://truestorefront.com/blog/caching-strategies-for-shopify-hydrogen-stores)
- [Hookdeck — Shopify webhooks best practices](https://hookdeck.com/webhooks/platforms/shopify-webhooks-best-practices-revised-and-extended)
- [Hookdeck — Handling duplicate Shopify webhook events](https://hookdeck.com/webhooks/platforms/how-to-handle-duplicate-shopify-webhook-events)
- [Shopify Community — automated method to clear product page cache](https://community.shopify.com/c/technical-q-a/api-automated-method-to-clear-product-page-cache/m-p/2315533)
- [Shopify Community — clear Storefront API cache when updating product](https://community.shopify.com/c/technical-q-a/clear-storefront-api-cache-when-updating-product/m-p/2576417)
- [Shopify Community — inventory webhook empty payload (2026-01)](https://community.shopify.dev/t/receiving-empty-payload-on-inventory-levels-update-webhook-when-multiple-inventory-items-are-updated-shopify-react-router-app-api-2026-01/26155)
- [Shopify Community — orders/edited vs orders/updated](https://community.shopify.dev/t/webhook-orders-edited-vs-orders-updated/2700)

### Cache stampede / single-flight literature

- [Vattani et al. — Optimal Probabilistic Cache Stampede Prevention (XFetch)](https://cseweb.ucsd.edu/~avattani/papers/cache_stampede.pdf)
- [Cache stampede — Wikipedia](https://en.wikipedia.org/wiki/Cache_stampede)
- [Redis Patterns — Cache Stampede Prevention (antirez)](https://redis.antirez.com/fundamental/cache-stampede-prevention.html)
- [Internet Archive — XFetch implementation](https://github.com/internetarchive/xfetch)

---

## 13. Defensible operating posture (TL;DR)

- At-rest TTLs short
- Version-keys on every Postgres-backed cache
- Push-invalidate from webhooks with a 5–10s delay buffer
- Idempotency on `X-Shopify-Webhook-Id` for every money handler
- Never cache anything customer-bound
- Never cache commission-rate reads at write time
- Accept that Oxygen's missing purge API means the edge layer is always TTL-bounded, never push-bounded

The rest is tuning.
