# Database Audit: Moving to Shopify Hydrogen/Oxygen

## Context

We're proposing that each affiliate page (e.g., `josh.eco.com`) becomes a **Shopify Hydrogen storefront** hosted on **Oxygen**, backed by the brand's Shopify store. This means Shopify handles: product display, cart, checkout, payments, and storefront analytics natively.

---

## KEEP (Still Needed)

### `core.professionals`
**Keep.** This is our identity layer. We still need to know who affiliates, brands, and influencers are, their types, handles, contact info, onboarding status, etc. Even with Hydrogen storefronts, Comet is still the **relationship manager** between brands and affiliates.

### `core.brand_partner_links`
**Keep.** The affiliate <-> brand relationship mapping is core to our business logic. Shopify doesn't know that "Josh" is an affiliate of "Brand X" -- Comet does.

### `core.brand_affiliate_invites`
**Keep.** The invite/claim flow for onboarding affiliates to brands is a Comet feature, not a Shopify feature.

### `core.brand_profiles`
**Keep.** ABN, legal business name, industries, etc. -- this is Comet's brand metadata, independent of Shopify.

### `core.enterprises`
**Keep.** Enterprise management (multi-brand orgs) is a Comet concept.

### `core.enterprise_brand_links`
**Keep.** Enterprise <-> brand RBAC mapping.

### `core.professional_enterprise_memberships`
**Keep.** Enterprise team membership.

### `core.professional_integrations`
**Keep, but changes** (see Changes section). We still need Shopify OAuth tokens/credentials to manage the Hydrogen storefronts programmatically.

### `core.notifications` + `core.notification_receipts`
**Keep.** In-app notifications are platform-level, not storefront-level.

### `core.comet_staff`
**Keep.** Internal staff management.

### `core.waitlist_signups`
**Keep.** Marketing/onboarding funnel.

### `core.customers`
**Keep.** We may still want a CRM layer for affiliate-level customer tracking, email lists, lead capture -- even if Shopify handles checkout customers.

### `core.email_subscriptions`
**Keep.** Email marketing/opt-in is a Comet feature.

### `billing.plans` + `billing.subscriptions`
**Keep.** Comet's own SaaS billing for brands/affiliates.

### `core.professional_legal_contents`
**Keep.** Privacy policies, T&Cs -- legal content for affiliate sites.

### `core.professional_confirmation_preferences`
**Keep.** UI preferences.

### `core.influencer_promoter_contracts`
**Keep.** Contract management between influencers and promoter enterprises.

---

## DELETE (No Longer Needed)

### `core.sites`
**Delete.** The entire concept of Comet-hosted mini-sites with subdomains goes away. Hydrogen storefronts on Oxygen replace this. Subdomain routing (`{subdomain}.comet.app`) is replaced by Shopify's domain management or custom domain pointing to Oxygen.

### `core.blocks`
**Delete.** The modular block system (links, gallery, sections, shop, booking blocks) was for building the Comet-hosted site. Hydrogen storefronts have their own component system.

### `core.site_media`
**Delete.** Media uploaded for Comet site pages. Hydrogen storefronts would use Shopify's media/CDN or a separate asset pipeline.

### `core.media_variants`
**Delete.** Processed image/video variants (WebP, MP4, HLS) for the Comet site. No longer relevant.

### `core.themes`
**Delete.** Comet's theme system. Hydrogen has its own theming/component architecture.

### `core.site_subdomain_aliases`
**Delete.** Custom domain aliasing for Comet-hosted sites. Oxygen/Shopify handles this now.

### `core.all_site_data` (likely a view)
**Delete.** Denormalized view that joins sites, blocks, themes, professionals -- all going away.

### `core.public_site_payload` (likely a materialized view/cache)
**Delete.** Cached public site JSON payload -- no longer relevant.

### `core.brand_fonts`
**Delete.** Custom fonts for Comet-hosted brand themes. Hydrogen storefronts manage their own typography.

### `retail.checkout_sessions`
**Delete.** This was our attribution mechanism -- a token passed through Shopify order metadata to link a Shopify order back to an affiliate. With Hydrogen, we have **much better options** (see Changes section).

### `retail.professional_selections`
**Delete.** Featured product picks per affiliate. This curation would happen within the Hydrogen storefront itself, likely via Shopify metafields or Hydrogen components.

### `retail.brand_product_media`
**Delete.** Affiliate-uploaded product media. Hydrogen storefronts use Shopify's product media.

### `analytics.site_visits`
**Delete.** Comet site visit tracking. Shopify Analytics + Hydrogen's built-in analytics replace this.

### `analytics.link_clicks`
**Delete.** Link click tracking for Comet site blocks. No more link blocks.

### `analytics.site_metrics_daily` + `analytics.site_metrics_hourly`
**Delete.** Aggregated site visit metrics -- replaced by Shopify Analytics.

### `analytics.lead_submissions`
**Delete.** Lead form submissions on Comet sites. Would need to be rebuilt as a Hydrogen component if still wanted.

### `analytics.store_order_events` + `analytics.store_order_event_items`
**Likely delete.** These appear to be a legacy/denormalized event log. The `retail.orders` + `retail.order_items` tables are the canonical source.

### `analytics.booking_events`
**Question** (see Questions section).

### `core.services` + `core.service_categories`
**Question** (see Questions section).

### `retail.sale_events`
**Delete.** Appears to be a legacy event table.

---

## CHANGES (Keep But Modify)

### `retail.brand_products`
**Keep, but rethink.** We currently sync Shopify products into this table. With Hydrogen, the storefront reads products **directly from Shopify's Storefront API**. However, we still need this table for:
- Commission rate assignments per product
- Availability/access control per affiliate
- Analytics aggregation

**Change:** This becomes a lightweight "product settings" table rather than a full product catalog mirror. We might drop `title`, `handle`, `image_url`, `price_cents`, `description`, `product_type`, `tags`, `images` (all queryable from Shopify in real-time) and keep only `shopify_product_id` + Comet-specific metadata.

### `retail.brand_product_settings`
**Keep.** Commission rates, featured status, availability per product -- Comet business logic that Shopify doesn't handle.

### `retail.brand_product_affiliate_overrides`
**Keep.** Per-affiliate product access control (deny/allow). This is Comet's access layer on top of Shopify's catalog.

### `retail.brand_product_affiliate_settings`
**Keep.** Per-affiliate commission/discount overrides.

### `retail.brand_store_settings`
**Keep, but simplify.** `checkout_mode` becomes irrelevant (always Shopify checkout). Keep: `default_commission_rate`, `payout_hold_days`, `default_affiliate_product_ids`. Remove: `favourite_brand_product_ids` (curation moves to Hydrogen), `checkout_mode`.

### `retail.brand_affiliate_settings`
**Keep, but simplify.** `allow_affiliate_media` may become irrelevant if media is managed in Hydrogen/Shopify.

### `core.professional_integrations`
**Change.** This becomes MORE important -- it's how we store the Shopify Storefront API token, Admin API token, and shop domain for each brand. We may need new fields for Hydrogen-specific config (e.g., Oxygen deployment IDs, storefront IDs).

### `retail.orders` + `retail.order_items`
**Keep, but the ingestion mechanism changes dramatically.** Currently we use `checkout_sessions` token to attribute orders. With Hydrogen, we have better options:
- **Shopify Draft Orders API** -- create orders with affiliate metadata baked in
- **Cart attributes / note attributes** -- embed affiliate ID directly in Shopify cart
- **Shopify App Proxy / App Bridge** -- our Hydrogen storefront can inject affiliate context natively
- **Metafields on orders** -- write affiliate_id, brand_id as order metafields

The webhook ingestion (`order_event_inbox` -> `ProcessShopifyOrderEventJob`) stays, but attribution becomes simpler and more reliable.

### `retail.order_event_inbox`
**Keep.** Webhook inbox pattern is solid regardless of storefront tech.

### `retail.commission_ledger_entries`
**Keep.** Commission accounting doesn't change.

### `retail.commission_payouts` + `retail.commission_payout_items`
**Keep.** Payout logic doesn't change.

### `retail.brand_commission_topups`
**Keep.** Brand wallet funding doesn't change.

### `retail.brand_promotions`
**Keep.** Time-bound promotions with commission/discount adjustments. But discount application may need to work differently -- we'd apply discounts via Shopify Discount Functions or automatic discounts rather than custom frontend logic.

### `retail.brand_affiliate_segments` + `retail.brand_affiliate_segment_members`
**Keep.** Segment-based affiliate management is platform-level.

### `retail.brand_team_memberships`
**Keep.** Brand team RBAC.

### Analytics aggregation tables (order-related)
**Keep all of these:**
- `analytics.brand_metrics_daily` / `analytics.brand_metrics_hourly`
- `analytics.brand_influencer_daily`
- `analytics.brand_influencer_product_daily`
- `analytics.brand_product_daily`
- `analytics.brand_commission_daily`
- `analytics.professional_metrics_daily` / `analytics.professional_metrics_hourly`
- `analytics.professional_product_daily`
- `analytics.professional_customer_daily`

These aggregate **order/commission data** which still lives in our DB. They're our analytics layer on top of our canonical order tables.

---

## QUESTIONS / DECISIONS NEEDED

### 1. Booking -- Keep or Kill?
We have a full booking system: `core.services`, `core.service_categories`, `analytics.booking_events`, `analytics.booking_metrics_daily/hourly`, with Square/Fresha integrations.

**Question:** Does the Hydrogen/Oxygen move affect booking? If professionals who don't sell products still use Comet for booking, we keep the entire booking stack. If booking is also moving to Shopify (via Shopify's booking apps), this whole subsystem can go.

### 2. What happens to non-brand professionals?
We mentioned "professionals will need to be kept as we still want to keep site pages for those who don't want to connect to brands without Shopify stores."

**This means we're running TWO systems in parallel:**
- **Hydrogen storefronts** for brand-affiliated professionals (`josh.brand.com`)
- **Comet-hosted sites** for independent professionals (`josh.comet.app`)

If so, we **cannot delete** `sites`, `blocks`, `themes`, `site_media`, etc. -- they're still needed for the non-Shopify path. This significantly complicates things. We'd need a clear fork: `professional.storefront_type = 'comet' | 'hydrogen'`.

### 3. Subdomain strategy
Currently: `josh.comet.app`. We mentioned `josh.eco.com`.

**Question:** Who manages DNS/domains? With Oxygen, Shopify handles hosting, but we'd need:
- A wildcard DNS setup pointing `*.eco.com` to Oxygen
- OR per-affiliate domain provisioning
- **How does Comet know which Hydrogen storefront belongs to which affiliate?** We still need a mapping table (even if `sites` is deleted, we need something like `hydrogen_storefronts`).

### 4. Affiliate customization
Currently affiliates customize their Comet pages (bio, links, gallery, media).

**Question:** What customization do affiliates get on their Hydrogen storefront? If it's just "here's the brand's store with your name on it" -- simpler. If affiliates can customize layout, content, featured products -- we need a customization layer, possibly stored in Comet and served to Hydrogen via API, or via Shopify metafields.

### 5. Enterprise product tables
`retail.enterprise_shopify_accounts`, `retail.enterprise_products`, `retail.enterprise_brands` -- these are an enterprise-level product management layer.

**Question:** Does the Hydrogen move change how enterprises manage multiple Shopify stores? Or does this stay the same?

### 6. Analytics source of truth
With Hydrogen, we get Shopify Analytics for free (traffic, conversion, AOV, etc.).

**Question:** Is Shopify Analytics sufficient, or do we still need Comet-level analytics? If we need affiliate-attributed analytics (which Shopify doesn't natively do), we still need our own tracking -- but it would be implemented as a Hydrogen component (e.g., an analytics pixel) rather than Comet's current `site_visits`/`link_clicks` system.

### 7. Stripe checkout path
We have `PublicStripeCheckoutService` for non-Shopify checkout.

**Question:** If ALL commerce goes through Shopify checkout (via Hydrogen), do we still need direct Stripe checkout? Or does Stripe only remain for:
- Commission payouts (Stripe Connect)
- Comet SaaS billing
- Brand wallet top-ups

---

## Side Question: Do Most Brands Use Shopify?

**Short answer: Shopify is dominant in SMB/mid-market but NOT universal.**

### Shopify's market position
- ~4.4M stores globally, ~28% of US e-commerce platforms
- Dominates DTC (direct-to-consumer) brands, influencer-driven brands, and SMBs
- Very strong in fashion, beauty, health, lifestyle -- exactly our affiliate/influencer vertical

### Who DOESN'T use Shopify
- **Enterprise/luxury brands** -- many use Salesforce Commerce Cloud, Adobe Commerce (Magento), or custom builds (e.g., Nike, Gucci, LVMH)
- **Marketplace sellers** -- brands primarily on Amazon/eBay may not have a Shopify store
- **B2B brands** -- often use specialized platforms (BigCommerce, WooCommerce, custom)
- **International brands** -- Shopify adoption varies by region; parts of Europe/Asia use local platforms
- **Brands with complex catalogs** -- configurable products, subscriptions at scale may use headless alternatives (commercetools, Medusa)

### Risk assessment
- For our target market (DTC brands working with influencers/affiliates), Shopify is likely **70-80%+ of potential partners**. This is our sweet spot.
- We **would** lock out some bigger brands on other platforms. The risk is real but manageable if we:
  1. Start with Shopify-only (Hydrogen/Oxygen) as the primary path
  2. Keep the Comet-hosted site as a fallback for non-Shopify brands
  3. Plan for future platform integrations (WooCommerce, BigCommerce) down the line

**Bottom line:** This is a reasonable bet for our vertical. Most affiliate/influencer-friendly brands are on Shopify. But making it the ONLY path would cut off ~20-30% of the addressable market. The dual-system approach (Hydrogen for Shopify brands, Comet sites for everyone else) hedges this risk but adds complexity.

---

## Summary Table

| Category | Tables | Verdict |
|----------|--------|---------|
| Identity/Relationships | `professionals`, `brand_partner_links`, `invites`, `enterprises` | **KEEP** |
| Comet Sites | `sites`, `blocks`, `themes`, `site_media`, `media_variants`, `brand_fonts`, `subdomain_aliases` | **DELETE** (or keep if dual-system) |
| Retail Commerce | `brand_products`, `product_settings`, `overrides`, `affiliate_settings` | **KEEP (simplify)** |
| Orders/Commissions | `orders`, `order_items`, `commission_ledger`, `payouts` | **KEEP** |
| Checkout Sessions | `checkout_sessions` | **DELETE** (better attribution via Hydrogen) |
| Promotions/Segments | `brand_promotions`, `segments` | **KEEP** |
| Site Analytics | `site_visits`, `link_clicks`, `site_metrics` | **DELETE** (Shopify Analytics) |
| Order Analytics | all order/commission aggregation tables | **KEEP** |
| Booking | `services`, `booking_events`, `booking_metrics` | **QUESTION** |
| Billing | `plans`, `subscriptions` | **KEEP** |
| Notifications | `notifications`, `receipts` | **KEEP** |
| CRM | `customers`, `email_subscriptions` | **KEEP** |

---

# V2 Addendum: Shopify Hydrogen/Oxygen + Shopify App Architecture

This section captures the rest of the discussion after the initial DB audit.

## 1) What Hydrogen/Oxygen Actually Changes

Hydrogen/Oxygen is primarily a **storefront architecture change**.

- Hydrogen storefront (React) replaces Comet-rendered storefront UI for Shopify-connected brands.
- Oxygen is Shopify's edge hosting runtime for Hydrogen storefronts.
- Product browsing, cart, checkout initiation, and storefront rendering move to Hydrogen.
- Checkout remains Shopify-hosted checkout.

### What this means technically

- Storefront data fetch is primarily via Shopify **Storefront API (GraphQL)**.
- Store management, webhooks, metafields, discounts setup, and order reads use Shopify **Admin API (GraphQL-first)**.
- Affiliate attribution should move from custom checkout session tokens to Shopify-native cart/order metadata paths.

## 2) Frontend vs Backend Scope (High Level)

## Mostly Frontend Changes

- Rebuild storefront experience in Hydrogen.
- Add affiliate context capture in storefront (URL param/cookie/session approach).
- Push affiliate attribution into cart metadata before checkout.
- Optional checkout UI extension and web pixel work.

## Backend Changes Still Required

- Shopify app installation/auth flow.
- Per-store token management.
- Webhook registration + processing.
- Order ingestion + affiliate attribution mapping.
- Commission calculation, ledgering, payout eligibility logic.
- Aggregated affiliate analytics for reporting.

## What does not disappear

Your commission and payout domain logic remains backend-heavy and remains your core IP.

## 3) Shopify App: What It Is (Practical Architecture)

A Shopify app typically has three delivery surfaces:

1. App backend + embedded admin UI app (merchant-facing inside Shopify Admin).
2. App extensions (checkout UI, web pixels, admin blocks, etc).
3. Integration touchpoints into the brand's Hydrogen storefront.

## Shopify App components split

| Component | Purpose | FE/BE | Hosted by |
|---|---|---|---|
| OAuth + token exchange | Install/auth app into a brand store | Backend | You |
| Admin API calls | Orders, metafields, settings, webhooks | Backend | You |
| Embedded admin dashboard | Merchant UI for settings/commissions | Frontend | You (embedded iframe in admin) |
| App Bridge integration | Embedded app behaviors in Shopify Admin | Frontend | You |
| Polaris UI | Native Shopify-look admin UI components | Frontend | You |
| Checkout UI extension | Checkout blocks/content | Frontend extension | Shopify |
| Web pixel extension | Tracking events in storefront | Frontend extension | Shopify |
| Shopify Function | Server-side discount/validation logic | Backend extension (WASM) | Shopify |

## 4) React Router vs Laravel for the Shopify App Shell

## Option A: Laravel-first app backend

### Pros

- Reuses existing Laravel domain layer and current integration patterns.
- Fewer moving parts at startup stage if team is PHP-heavy.
- Single primary business backend remains source of truth.

### Cons

- Fewer official Shopify examples for Laravel-first embedded app flows.
- More DIY around embedded session-token conventions and tooling ergonomics.
- Higher dependency on community package quality if used.

## Option B: React Router Shopify app shell + Laravel business backend

### Pros

- Aligns with Shopify's first-party app stack and most docs/examples.
- Embedded app auth/session + App Bridge + Polaris path is smoother.
- Lower long-term friction when following new Shopify platform guidance.

### Cons

- Additional codebase/deployment surface.
- Must design clear boundary so business logic stays in Laravel and is not duplicated.

## Recommendation from discussion

If going deeper into Shopify ecosystem (Hydrogen/Oxygen + extensions + embedded app), use:

- **React Router for Shopify app shell and admin UX**, and
- **Laravel as core business API/commission engine**.

This keeps Shopify-specific ergonomics while preserving your existing domain system.

## 5) Repository / Deployment Impact

## React Router path

Typically becomes:

1. Laravel API/business backend.
2. Existing Next.js product/dashboard frontend.
3. Shopify App (React Router embedded app + extensions).
4. Hydrogen SDK package for storefront integration.

## Laravel-only Shopify app path

Typically becomes:

1. Laravel backend (extended with Shopify app flow).
2. Existing Next.js product/dashboard frontend.
3. Hydrogen SDK package.

Tradeoff is fewer repos vs stronger first-party Shopify alignment.

## 6) Order + Commission Ownership: Shopify vs Comet

## Core conclusion

Shopify is source of truth for **commerce orders**, but not for your affiliate commission system.

- Shopify stores order, payment, refunds, fulfillments, etc.
- Shopify does **not** run affiliate commission ledger, payout lifecycle, hold periods, or affiliate commission analytics in the form your platform needs.

So yes, Laravel still needs to persist:

- attributed orders/order items,
- commission ledger entries,
- payout records and payout itemization,
- affiliate/brand rollup analytics for dashboarding.

## Example flow (affiliate order)

1. Customer lands on `sarah.evo.com`.
2. Hydrogen storefront tags cart with affiliate context.
3. Customer completes Shopify-hosted checkout.
4. Shopify emits order webhook(s).
5. Laravel ingests order, resolves affiliate attribution, writes order records.
6. Laravel computes commission (e.g., 15%) and writes ledger entry.
7. Analytics aggregates update (day/hour/affiliate/brand/product views).
8. Payout process later converts eligible ledger rows into payout records.

## 7) Analytics Ownership Model

## Shopify-native analytics

Shopify gives strong store-level analytics (sales, products, conversion, traffic views).

## Comet-required analytics

For platform-level affiliate economics, Comet still needs internal analytics because you require:

- affiliate-level revenue,
- affiliate commission earned/paid/pending,
- brand-to-affiliate performance slices,
- time-bucketed payout and accrual reporting,
- promotion/segment performance tied to your commission model.

That is why your order/commission aggregation tables remain strategically important.

## 8) Attribution Strategy Shift

From discussion:

- Existing checkout-session-token model can be replaced with metadata-based attribution flow in Shopify.
- Preferred direction is cart/order metadata + webhook ingestion + Laravel reconciliation.
- This reduces attribution fragility and keeps commission logic centralized in your backend.

## 9) Practical Build Plan (React Router + Laravel)

1. Scaffold Shopify app with React Router.
2. Complete install/auth and secure token handling.
3. Build embedded admin pages (Polaris/App Bridge).
4. Connect those pages to Laravel APIs for business data/actions.
5. Implement extensions needed (checkout UI, pixel, optional function).
6. Build/publish Hydrogen SDK for affiliate context + cart metadata.
7. Update webhook ingestion + attribution mapping logic in Laravel.
8. Keep commission ledger/payout/analytics pipeline in Laravel.

## 10) Open Decisions Still Pending

1. Dual-system question: keep Comet-hosted sites for non-Shopify professionals or fully move storefront strategy.
2. Booking subsystem future: keep current services/booking stack or migrate strategy.
3. Subdomain/domain ownership model on Oxygen for affiliate storefront routing.
4. Affiliate customization depth in Hydrogen (minimal brand skin vs deep per-affiliate personalization).
5. Scope of Shopify-only strategy vs multi-platform future support.

## 11) Net Position (V2)

- Hydrogen/Oxygen is a major storefront and integration strategy shift.
- It does **not** replace your affiliate commission and payout backend.
- Your Laravel system remains critical as the platform brain.
- React Router for Shopify app shell is likely the least-friction path for long-term Shopify ecosystem alignment.
- Shopify handles checkout and order events; Comet handles attribution, commission accounting, payouts, and affiliate economics analytics.
