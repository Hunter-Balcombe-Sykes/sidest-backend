# Database Audit: Moving to Shopify Hydrogen/Oxygen

## Context

We're proposing that each affiliate page (e.g., `josh.eco.com`) becomes a **Shopify Hydrogen storefront** hosted on **Oxygen**, backed by the brand's Shopify store. This means Shopify handles: product display, cart, checkout, payments, and storefront analytics natively.

---

## KEEP (Still Needed)

### `core.professionals`
**Keep.** This is our identity layer. We still need to know who affiliates, brands, and influencers are, their types, handles, contact info, onboarding status, etc. Even with Hydrogen storefronts, Side St is still the **relationship manager** between brands and affiliates.

### `core.brand_partner_links`
**Keep.** The affiliate <-> brand relationship mapping is core to our business logic. Shopify doesn't know that "Josh" is an affiliate of "Brand X" -- Side St does.

### `core.brand_affiliate_invites`
**Keep.** The invite/claim flow for onboarding affiliates to brands is a Side St feature, not a Shopify feature.

### `core.brand_profiles`
**Keep.** ABN, legal business name, industries, etc. -- this is Side St's brand metadata, independent of Shopify.

### `core.enterprises`
**Keep.** Enterprise management (multi-brand orgs) is a Side St concept.

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
**Keep.** Email marketing/opt-in is a Side St feature.

### `billing.plans` + `billing.subscriptions`
**Keep.** Side St's own SaaS billing for brands/affiliates.

### `core.professional_legal_contents`
**Keep.** Privacy policies, T&Cs -- legal content for affiliate sites.

### `core.professional_confirmation_preferences`
**Keep.** UI preferences.

### `core.influencer_promoter_contracts`
**Keep.** Contract management between influencers and promoter enterprises.

---

## DELETE (No Longer Needed)

### `core.sites`
**Delete.** The entire concept of Side St-hosted mini-sites with subdomains goes away. Hydrogen storefronts on Oxygen replace this. Subdomain routing (`{subdomain}.comet.app`) is replaced by Shopify's domain management or custom domain pointing to Oxygen.

### `core.blocks`
**Delete.** The modular block system (links, gallery, sections, shop, booking blocks) was for building the Side St-hosted site. Hydrogen storefronts have their own component system.

### `core.site_media`
**Delete.** Media uploaded for Side St site pages. Hydrogen storefronts would use Shopify's media/CDN or a separate asset pipeline.

### `core.media_variants`
**Delete.** Processed image/video variants (WebP, MP4, HLS) for the Side St site. No longer relevant.

### `core.themes`
**Delete.** Side St's theme system. Hydrogen has its own theming/component architecture.

### `core.site_subdomain_aliases`
**Delete.** Custom domain aliasing for Side St-hosted sites. Oxygen/Shopify handles this now.

### `core.all_site_data` (likely a view)
**Delete.** Denormalized view that joins sites, blocks, themes, professionals -- all going away.

### `core.public_site_payload` (likely a materialized view/cache)
**Delete.** Cached public site JSON payload -- no longer relevant.

### `core.brand_fonts`
**Delete.** Custom fonts for Side St-hosted brand themes. Hydrogen storefronts manage their own typography.

### `retail.checkout_sessions`
**Delete.** This was our attribution mechanism -- a token passed through Shopify order metadata to link a Shopify order back to an affiliate. With Hydrogen, we have **much better options** (see Changes section).

### `retail.professional_selections`
**Delete.** Featured product picks per affiliate. This curation would happen within the Hydrogen storefront itself, likely via Shopify metafields or Hydrogen components.

### `retail.brand_product_media`
**Delete.** Affiliate-uploaded product media. Hydrogen storefronts use Shopify's product media.

### `analytics.site_visits`
**Delete.** Side St site visit tracking. Shopify Analytics + Hydrogen's built-in analytics replace this.

### `analytics.link_clicks`
**Delete.** Link click tracking for Side St site blocks. No more link blocks.

### `analytics.site_metrics_daily` + `analytics.site_metrics_hourly`
**Delete.** Aggregated site visit metrics -- replaced by Shopify Analytics.

### `analytics.lead_submissions`
**Delete.** Lead form submissions on Side St sites. Would need to be rebuilt as a Hydrogen component if still wanted.

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

**Change:** This becomes a lightweight "product settings" table rather than a full product catalog mirror. We might drop `title`, `handle`, `image_url`, `price_cents`, `description`, `product_type`, `tags`, `images` (all queryable from Shopify in real-time) and keep only `shopify_product_id` + Side St-specific metadata.

### `retail.brand_product_settings`
**Keep.** Commission rates, featured status, availability per product -- Side St business logic that Shopify doesn't handle.

### `retail.brand_product_affiliate_overrides`
**Keep.** Per-affiliate product access control (deny/allow). This is Side St's access layer on top of Shopify's catalog.

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

**Question:** Does the Hydrogen/Oxygen move affect booking? If professionals who don't sell products still use Side St for booking, we keep the entire booking stack. If booking is also moving to Shopify (via Shopify's booking apps), this whole subsystem can go.

### 2. What happens to non-brand professionals?
We mentioned "professionals will need to be kept as we still want to keep site pages for those who don't want to connect to brands without Shopify stores."

**This means we're running TWO systems in parallel:**
- **Hydrogen storefronts** for brand-affiliated professionals (`josh.brand.com`)
- **Side St-hosted sites** for independent professionals (`josh.comet.app`)

If so, we **cannot delete** `sites`, `blocks`, `themes`, `site_media`, etc. -- they're still needed for the non-Shopify path. This significantly complicates things. We'd need a clear fork: `professional.storefront_type = 'comet' | 'hydrogen'`.

### 3. Subdomain strategy
Currently: `josh.comet.app`. We mentioned `josh.eco.com`.

**Question:** Who manages DNS/domains? With Oxygen, Shopify handles hosting, but we'd need:
- A wildcard DNS setup pointing `*.eco.com` to Oxygen
- OR per-affiliate domain provisioning
- **How does Side St know which Hydrogen storefront belongs to which affiliate?** We still need a mapping table (even if `sites` is deleted, we need something like `hydrogen_storefronts`).

### 4. Affiliate customization
Currently affiliates customize their Side St pages (bio, links, gallery, media).

**Question:** What customization do affiliates get on their Hydrogen storefront? If it's just "here's the brand's store with your name on it" -- simpler. If affiliates can customize layout, content, featured products -- we need a customization layer, possibly stored in Side St and served to Hydrogen via API, or via Shopify metafields.

### 5. Enterprise product tables
`retail.enterprise_shopify_accounts`, `retail.enterprise_products`, `retail.enterprise_brands` -- these are an enterprise-level product management layer.

**Question:** Does the Hydrogen move change how enterprises manage multiple Shopify stores? Or does this stay the same?

### 6. Analytics source of truth
With Hydrogen, we get Shopify Analytics for free (traffic, conversion, AOV, etc.).

**Question:** Is Shopify Analytics sufficient, or do we still need Side St-level analytics? If we need affiliate-attributed analytics (which Shopify doesn't natively do), we still need our own tracking -- but it would be implemented as a Hydrogen component (e.g., an analytics pixel) rather than Side St's current `site_visits`/`link_clicks` system.

### 7. Stripe checkout path
We have `PublicStripeCheckoutService` for non-Shopify checkout.

**Question:** If ALL commerce goes through Shopify checkout (via Hydrogen), do we still need direct Stripe checkout? Or does Stripe only remain for:
- Commission payouts (Stripe Connect)
- Side St SaaS billing
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
  2. Keep the Side St-hosted site as a fallback for non-Shopify brands
  3. Plan for future platform integrations (WooCommerce, BigCommerce) down the line

**Bottom line:** This is a reasonable bet for our vertical. Most affiliate/influencer-friendly brands are on Shopify. But making it the ONLY path would cut off ~20-30% of the addressable market. The dual-system approach (Hydrogen for Shopify brands, Side St sites for everyone else) hedges this risk but adds complexity.

---

## Summary Table

| Category | Tables | Verdict |
|----------|--------|---------|
| Identity/Relationships | `professionals`, `brand_partner_links`, `invites`, `enterprises` | **KEEP** |
| Side St Sites | `sites`, `blocks`, `themes`, `site_media`, `media_variants`, `brand_fonts`, `subdomain_aliases` | **DELETE** (or keep if dual-system) |
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
