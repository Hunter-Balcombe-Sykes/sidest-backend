# AI_CONTEXT.md — Side St Platform (V2)

> **Source of truth for AI tools working on this codebase.**
> Read this before making changes. Update after meaningful progress.
> For detailed per-file V2 role descriptions, see `V2_BACKEND_REFERENCE.md`.

---

## Project Overview

**Side St** (codebase still references "Comet" / "OneLink" in places) is a multi-tenant SaaS affiliate platform that gives influencers and beauty/barbering professionals a branded one-page personal website, connected to a specific brand partner. It replaces link-in-bio tools (like Linktree), adds an affiliate e-commerce shop powered by Shopify Hydrogen storefronts, booking integrations, and detailed analytics.

**V2 Architecture:** Product data lives entirely in Shopify (Storefront API + metafields). Affiliate storefronts are Hydrogen apps deployed on Shopify Oxygen. Commission rates come from Shopify product metafields. Payouts flow through Stripe Connect (80% to affiliate, 20% platform fee). Each affiliate is scoped to a single brand.

**What problem it solves:**
Brands want influencers and professionals to sell their products without managing separate storefronts. Professionals/influencers want a polished all-in-one presence without design effort. Side St sits in the middle, handling the site, commerce, analytics, and commission accounting workflows.

**Main goals:**
1. Give each professional/influencer a published one-page site on a subdomain
2. Connect that site to a brand's Shopify store via Hydrogen storefronts
3. Process commission-based sales — brand fulfils via Shopify, Side St records commissions, Stripe Connect handles payouts
4. Provide booking integrations (Square, Fresha) for service professionals
5. Give brands and professionals actionable analytics (views, clicks, sales, earnings)

**Current status:** Active development (V2 pre-beta). V1 dead code has been removed. Core professional features (sites, services, media, integrations) are production-ready. Shopify integration (OAuth, Storefront API tokens, order webhooks) is implemented. Stripe Connect onboarding and commission payout processing are implemented. V2 database migration is in progress.

---

## Core Idea

### Plain-English Explanation

- A **brand** signs up and connects their **Shopify store** via OAuth.
- Side St auto-creates Storefront API tokens and registers order webhooks.
- The brand invites **affiliates** (barbers, hairdressers, Instagram influencers).
- Each affiliate gets their own subdomain site (e.g., `john.sidest.co`) auto-themed in the brand's colours.
- The affiliate's **Hydrogen storefront** fetches products directly from Shopify's Storefront API — no local product tables.
- Customers visit the storefront, browse products, and purchase via Shopify native checkout.
- The `orders/paid` webhook fires → commission recorded in the ledger based on Shopify metafield rates.
- After a hold period, `ProcessCommissionPayoutsJob` transfers 80% of commission to the affiliate via Stripe Connect. Side St takes 20%.
- **Each affiliate belongs to one brand** (single-brand model in V2).
- Service professionals can also take bookings via **Square or Fresha** through the same site.

### How the System Works

```
Brand connects Shopify store (OAuth)
    ↓
Side St creates Storefront API token + registers order webhooks
    ↓
Brand invites affiliate (token-based invite)
    ↓
Affiliate claims invite → site auto-provisioned on subdomain
    ↓
Hydrogen storefront fetches products from Shopify Storefront API
    ↓
Customer visits {brand}.sidest.co/{affiliate} → sees brand-themed storefront
    ↓
Customer buys product → Shopify native checkout
    ↓
orders/paid webhook → commission ledger entry created
    ↓
Daily payout job → Stripe Connect transfer (80/20 split)
```

### Key Assumptions

- Single-brand affiliation per affiliate (V2 constraint).
- Product data lives in Shopify — no local product tables. Commission rates from Shopify metafields.
- Brands control the theme, colours, logo, and product visibility via Shopify collections/metafields.
- Professionals control their own media pool, links, bio, and service listings.
- Booking integrations (Square, Fresha) are per-professional, not per-brand.
- Supabase handles all authentication (JWT-based). Laravel does not manage passwords.
- The database lives entirely in Supabase (PostgreSQL). Laravel migrations are disabled — use `supabase/migrations/` only.

---

## Codebase Summary

**Repositories (V2 — 4 repos, no duplication):**
1. **`sidest-backend` (this repo, Laravel)** — API, Shopify OAuth, webhooks, Stripe, commission ledger, migrations
2. **`sidest-embedded` (Remix)** — Shopify admin embedded app (wizard, product catalog, affiliate management)
3. **`sidest-hydrogen` (Hydrogen/Remix)** — Customer-facing affiliate storefronts (per-brand Oxygen deployment)
4. **`sidest-dashboard` (Next.js)** — Brand/affiliate dashboards (analytics, payout, customization)

**Stack:** Laravel 12 · PHP 8.2+ · PostgreSQL (Supabase) · Redis · Cloudflare R2 · Supabase Auth (JWT)

### Directory Map

```
/
├── app/
│   ├── Models/
│   │   ├── Core/
│   │   │   ├── Professional/ — Professional, BrandProfile, BrandPartnerLink, BrandAffiliateInvite, Customer, Service, ServiceCategory, ProfessionalIntegration, ProfessionalConfirmationPreference
│   │   │   ├── Site/         — Site, Block, SiteMedia, SiteSubdomainAlias, Theme
│   │   │   ├── Notifications/ — Notification, NotificationReceipt, EmailSubscription, NotificationEmailPolicy, NotificationEmailPreference
│   │   │   ├── Staff/        — SidestStaff
│   │   │   ├── Waitlist/     — WaitlistSignup
│   │   │   └── MediaVariant
│   │   ├── Retail/         — CommissionLedgerEntry, CommissionPayout, CommissionPayoutItem, BrandStoreSettings, BrandCommissionTopup, BrandTeamMembership
│   │   ├── Commerce/       — AffiliateProductSelection (V2 new — Shopify GID-based)
│   │   ├── Billing/        — Plan, Subscription
│   │   ├── Analytics/      — SiteVisit, LinkClick, LeadSubmission
│   │   └── Views/          — PublicSitePayload, AllSiteData (read-only views)
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── Professional/  — Authenticated professional/brand/affiliate endpoints
│   │   │   ├── PublicSite/    — Unauthenticated mini-site + storefront endpoints
│   │   │   ├── Staff/         — Internal staff/admin endpoints
│   │   │   ├── Shopify/       — Shopify OAuth controller
│   │   │   ├── Webhooks/      — Shopify (orders/paid, orders/updated, app/uninstalled, shop/update, GDPR), Stripe Connect, Square, Fresha
│   │   │   └── Internal/      — Hydrogen server-to-server endpoints (brand config, affiliate lookup, affiliate products)
│   │   ├── Middleware/        — JWT auth, role guards, plan gates, cache headers
│   │   ├── Requests/          — Form request validation classes
│   │   └── Resources/         — API response transformers
│   ├── Services/
│   │   ├── Analytics/         — Site + booking analytics aggregation
│   │   ├── Auth/              — SupabaseAdminService (admin auth operations)
│   │   ├── Billing/           — Entitlements / plan tier checks
│   │   ├── Cache/             — Site, professional, analytics caching
│   │   ├── Fresha/            — Fresha API client, token management, service sync
│   │   ├── Media/             — Image + video variant processing
│   │   ├── Notifications/     — Notification publishing, commerce notifications, email dispatch
│   │   ├── Professional/      — Brand onboarding, invites, partner links, defaults, site provisioning, section visibility
│   │   ├── Public/            — Public site resolution
│   │   ├── Shopify/           — Brand signup, shop profile auto-fill
│   │   ├── Square/            — Square API client, token management, service sync
│   │   ├── Store/             — Brand access RBAC, pricing, selection cleanup
│   │   └── Stripe/            — Stripe Connect + commission payouts
│   ├── Actions/               — UpdateSiteAction, subscription actions
│   ├── Jobs/                  — Analytics, Cache, Notifications, Shopify, Stripe, Square, Fresha + root-level media jobs
│   ├── Observers/             — Cache invalidation, notifications, integration sync triggers
│   └── Console/Commands/      — Analytics backfill/compact/purge, notification pruning, soft-delete purge
├── routes/
│   ├── api.php                — Main router (health, webhooks, Shopify OAuth, bootstrap, public)
│   ├── api/professional.php   — Professional dashboard routes
│   ├── api/publicSite.php     — Public mini-site routes (subdomain-scoped)
│   └── api/staff.php          — Staff/admin routes
├── supabase/migrations/       — All DB migrations (SQL, NOT Laravel migrations)
├── config/sidest.php           — Feature flags and limits
├── tests/                     — Pest framework tests
├── docs/api.md                — Comprehensive API reference
├── V2_BACKEND_REFERENCE.md    — Detailed per-file V2 role descriptions
├── PLAN.md                    — V2 platform architecture
├── V2-REMOVAL-PLAN.md         — V1 removal checklist
└── AI_CONTEXT.md              — This file
```

### Core Models (V2)

| Model | Table | V2 Role |
|-------|-------|---------|
| `Professional` | `core.professionals` | Central identity — brands and affiliates distinguished by `professional_type` |
| `Site` | `core.sites` | Mini-site config (subdomain, theme, settings, publish state) |
| `Service` | `core.services` | Bookable service with Square/Fresha sync |
| `ServiceCategory` | `core.service_categories` | Groups services for display |
| `Customer` | `core.customers` | Contact/lead records per professional |
| `Block` | `core.blocks` | Site content blocks (links, sections) |
| `SiteMedia` | `core.site_media` | Images/videos with processing states |
| `MediaVariant` | `core.media_variants` | Processed variants (WebP, MP4, HLS, poster) |
| `BrandProfile` | `core.brand_profiles` | Brand business details; `brand_status` gates activation |
| `BrandPartnerLink` | `core.brand_partner_links` | Brand-affiliate connection (V2: single-brand model) |
| `BrandAffiliateInvite` | `core.brand_affiliate_invites` | Token-based invite for affiliate onboarding |
| `ProfessionalIntegration` | `core.professional_integrations` | OAuth connections (Square, Fresha, Shopify) |
| `CommissionLedgerEntry` | `retail.commission_ledger_entries` | Commission per order line from Shopify webhook |
| `CommissionPayout` | `retail.commission_payouts` | Payout lifecycle (pending → completed/failed) |
| `CommissionPayoutItem` | `retail.commission_payout_items` | Links payouts to ledger entries |
| `BrandStoreSettings` | `retail.brand_store_settings` | V2: only `default_commission_rate` + `payout_hold_days` |
| `BrandCommissionTopup` | `retail.brand_commission_topups` | Manual wallet top-ups via Stripe Checkout |
| `BrandTeamMembership` | `retail.brand_team_memberships` | Brand team roles for RBAC |
| `AffiliateProductSelection` | `retail.affiliate_product_selections` | V2 new — uses `shopify_product_gid` (not local UUID) |
| `SidestStaff` | `core.sidest_staff` | Staff/admin accounts for internal dashboard |
| `Notification` | `core.notifications` | In-app notification records |
| `NotificationReceipt` | `core.notification_receipts` | Notification delivery tracking |
| `EmailSubscription` | `core.email_subscriptions` | Notification preference per professional |
| `NotificationEmailPolicy` | `core.notification_email_policies` | Staff-managed email notification policies |
| `NotificationEmailPreference` | `core.notification_email_preferences` | Per-category email preferences |
| `ProfessionalConfirmationPreference` | `core.professional_confirmation_preferences` | UI dialog suppression preferences |
| `SiteSubdomainAlias` | `core.site_subdomain_aliases` | Subdomain alias management |
| `Theme` | `core.themes` | Site theme definitions |
| `WaitlistSignup` | `core.waitlist_signups` | Waitlist feature for pre-launch |
| `Plan` | `billing.plans` | Subscription tiers with entitlements |
| `Subscription` | `billing.subscriptions` | Professional's current plan status |
| `SiteVisit` | `analytics.site_visits` | Page view events |
| `LinkClick` | `analytics.link_clicks` | Link/section click events |
| `LeadSubmission` | `analytics.lead_submissions` | Lead form submissions |

### Key Services (V2)

| Service | V2 Role |
|---------|---------|
| `StripeConnectService` | Stripe Connect Express onboarding, payment methods, wallet top-ups |
| `CommissionPayoutService` | Hybrid-funded commission payouts (wallet + card → Stripe transfer) |
| `BrandAccessService` | Role-based brand RBAC (5 roles, capability-based) |
| `BrandPricingService` | Commission rate defaults + effective rate calc (per-product overrides in Shopify metafields) |
| `SelectionCleanupService` | Cleans affiliate selections on disconnect (Shopify GID-based) |
| `BrandAffiliateInviteService` | Invite lifecycle (create, bulk, CSV, claim, decline) |
| `BrandPartnerLinkService` | Brand-affiliate connection management |
| `BrandOnboardingReadinessService` | Brand activation checklist (images, Shopify, Stripe) |
| `BrandSignupService` | Shopify brand signup workflow (OAuth → provisioning) |
| `ShopProfileAutoFillService` | Auto-populate brand profile from Shopify shop data |
| `SiteProvisioningService` | Site provisioning workflow for new affiliates |
| `AccountTypeDefaultsService` | Applies type-based defaults on affiliate onboarding |
| `SectionVisibilityService` | Section visibility logic for site builder |
| `ConfirmationPreferenceService` | UI confirmation dialog preference management |
| `SiteCacheService` | Public site payload caching with single-flight locking |
| `ProfessionalCacheService` | Multi-lookup professional caching |
| `AnalyticsCacheService` | Analytics-specific caching layer |
| `SiteAnalyticsAggregateService` | Site analytics aggregation (hourly/daily) |
| `BookingAnalyticsAggregateService` | Booking analytics aggregation (hourly/daily) |
| `Entitlements` | Plan entitlement / tier checking |
| `SupabaseAdminService` | Admin Supabase auth operations |
| `ImageVariantService` | WebP variant generation |
| `VideoVariantService` | MP4 + HLS transcoding (feature-flagged) |
| `SquareApiClient` / `SquareServiceSyncService` | Square booking integration |
| `FreshaApiClient` / `FreshaServiceSyncService` | Fresha booking integration |
| `NotificationPublisher` | Core notification engine with dedup and email dispatch |
| `CommerceNotificationService` | Commerce-specific notifications (orders, commissions) |
| `PublicSiteResolver` | Subdomain → site resolution |

---

## How It Works (V2)

### V2 Critical Paths

#### 1. Brand Onboarding
```
Shopify OAuth → ShopifyAppOAuthController (HMAC, token exchange)
  → BrandSignupService (creates professional, brand profile, site)
  → ShopProfileAutoFillService (auto-populates brand profile from Shopify shop data)
  → Post-OAuth setup jobs (dispatched in parallel):
    → CreateStorefrontAccessTokenJob (Storefront API token for Hydrogen)
    → RegisterShopifyWebhooksJob (orders/paid, orders/updated, app/uninstalled, shop/update, GDPR)
    → CreateShopifySalesChannelJob (Hydrogen sales channel)
    → CreateShopifyCollectionsJob (default collections)
    → CreateShopifyMetafieldsJob (commission rate metafields)
    → SyncShopifyBrandLogoJob (pulls logo from Shopify)
    → SetShopifySetupCompleteJob (marks setup complete after all jobs finish)
  → BrandOnboardingReadinessService (checklist: 5+ images, Shopify, Stripe)
  → StripeConnectController@onboard (Stripe Express setup)
```

#### 2. Affiliate Onboarding
```
BrandAffiliateInviteController@store (brand sends invite)
  → BrandAffiliateInviteService (creates invite, sends email)
  → PublicBrandAffiliateInviteController@show (affiliate views invite)
  → BrandAffiliateInviteController@claim (affiliate accepts)
  → BrandPartnerLinkService (creates brand-affiliate link)
  → AccountTypeDefaultsService (applies affiliate site defaults)
```

#### 3. Commission Flow
```
Shopify orders/paid webhook → ShopifyOrderWebhookController
  → ProcessShopifyOrderWebhookJob
  → CommissionLedgerEntry created (status: approved, rate from metafields)
  → CommissionLedgerEntryObserver → notification to affiliate
  → ProcessCommissionPayoutsJob (daily cron)
    → CommissionPayoutService (hybrid: wallet balance first, card for shortfall)
    → Stripe Connect transfer (80% to affiliate, 20% platform fee)
  → CommissionPayoutObserver → notification on failure

Shopify orders/updated webhook → ShopifyOrdersUpdatedWebhookController
  → ProcessShopifyOrderUpdatedWebhookJob
  → Handles refunds and cancellations
  → Updates or reverses CommissionLedgerEntry accordingly
```

#### 4. Storefront Data
```
Hydrogen storefront → PublicShopifyStorefrontController
  → Returns Shopify domain + Storefront API token
  → Hydrogen fetches products directly from Shopify Storefront API
  → No local product data involved

Hydrogen internal API (server-to-server, token-authenticated):
  → HydrogenBrandConfigController — brand theme, logo, colours for storefront rendering
  → HydrogenAffiliateController — affiliate lookup by slug/identifier
  → HydrogenAffiliateProductsController — affiliate's selected products list
```

#### 4a. Shopify Webhook Lifecycle
```
Registered webhooks (via RegisterShopifyWebhooksJob):
  orders/paid    → ShopifyOrderWebhookController → commission ledger entry
  orders/updated → ShopifyOrdersUpdatedWebhookController → refund/cancellation handling
  app/uninstalled → ShopifyAppUninstalledWebhookController → cleanup brand integration
  shop/update    → ShopifyShopUpdateWebhookController → ProcessShopifyShopUpdateJob → sync shop data
  GDPR (customers/redact, customers/data_request, shop/redact) → ShopifyGdprWebhookController

All Shopify webhooks validated via HMAC signature (ValidatesShopifyWebhookHmac concern).
```

#### 5. Public Site Visit
```
Request hits {subdomain}.sidest.co → PublicSiteResolver identifies professional
GET /api/public/site → full site payload (cached in Redis, 15-min TTL)
Analytics events (POST /api/public/analytics/*) recorded asynchronously
```

#### 6. Booking Flow
```
Customer calls POST /api/public/booking/availability → proxied to Square/Fresha
Customer calls POST /api/public/booking/checkout → appointment created in integration
```

#### 7. Media Upload
```
POST /api/uploads → server validates, uploads to R2, records SiteMedia
ProcessImageVariantsJob → generates WebP variants → records MediaVariant
Video: ProcessVideoVariantsJob → FFmpeg MP4 + HLS (feature-flagged off)
```

### Authentication & Middleware Stack

```
Request → VerifySupabaseJwt (validate JWT via JWKS, extract supabase_uid)
        → LoadCurrentProfessional (load Professional from cache)
        → [EnsureSidestStaff] (require staff role)
        → [EnsureSidestAdmin] (require admin role)
        → [RequirePlan] (check subscription entitlement)
        → Controller
```

### Database Schemas

| Schema | Contents |
|--------|----------|
| `public` | Laravel infrastructure (cache, jobs, failed_jobs) |
| `core` | Professionals, sites, services, customers, blocks, media, integrations, brand links, invites, notifications |
| `analytics` | Site visits, link clicks, lead submissions, hourly/daily metrics |
| `billing` | Plans, subscriptions |
| `retail` | Commission ledger, payouts, brand store settings, team memberships, affiliate product selections |

`DB_SEARCH_PATH=public,core,analytics,billing,retail`

### Queue Architecture

| Queue | Connection | Purpose |
|-------|-----------|---------|
| `default` | redis | Notifications, cache warm, payouts |
| `analytics` | redis | Hourly/daily analytics rebuilds |
| `images` | redis | Image variant processing |
| `videos` | redis_video | Video transcoding (dedicated connection) |
| `integrations` | redis | Shopify, Square, Fresha API calls |
| `mail` | redis | Individual email delivery |

---

## What Was Removed in V2

The following V1 code has been deleted. Do NOT recreate these:

- **12 controllers**: BrandProducts, BrandProductMedia, BrandProductAffiliateSetting, BrandProductAffiliateOverride, FeaturedProducts, BrandPromotion, BrandAffiliateSegment, BrandAffiliateSettings, BrandAffiliateDefaults, BrandStore, StoreAnalyticsV2, PublicStore, EnterpriseController
- **9 services**: BrandProductCatalog, BrandProductSettings, ShopifyCatalogSync, PromotionResolution, SegmentEvaluation, FeaturedProductsPayload, PublicStripeCheckout, OrderAnalyticsAggregate, OrderAnalyticsHourlyAggregate
- **10 models**: BrandProduct, BrandProductSetting, BrandProductMedia, BrandProductAffiliateSetting, BrandProductAffiliateOverride, BrandAffiliateSegment, BrandAffiliateSegmentMember, BrandAffiliateSettings, BrandPromotion, ProfessionalSelection
- **7 jobs**: RebuildBrand/ProfessionalDailyAggregates, RebuildBrand/ProfessionalHourlyAggregates, SendPromotionStart/EndNotifications, RefreshActiveSegmentMembers
- **15 database tables** dropped, **60+ routes** removed

**V1 concepts that no longer exist:**
- Local product catalog (`brand_products` table) — products live in Shopify
- Per-affiliate product pricing overrides — commission rates in Shopify metafields
- Promotions and segments — removed entirely
- Multi-brand affiliates — V2 is single-brand per affiliate
- Public store/checkout endpoints — Hydrogen handles checkout natively
- Shopify webhook order processing controller — order processing via Stripe Connect now
- Legal content (auto-generated T&Cs + privacy) — tables dropped, services and controllers removed

---

## Current Progress (V2)

### Fully Implemented
- Professional profile CRUD (types enforced via config: brand, professional, influencer; schema supports additional types)
- Mini-site builder (subdomain routing, blocks, sections, links, themes, subdomain aliases)
- Service and category management with Square/Fresha bidirectional sync
- Customer database (soft delete, restore, marketing preferences)
- Email marketing subscriptions (opt-in/out with token-based unsubscribe)
- Image upload + WebP variant processing (optimised/maximised)
- Gallery and content media pools
- QR code generation per professional
- Square OAuth integration + service sync
- Fresha OAuth integration + service sync
- Brand partner link management (connect/disconnect affiliates)
- Brand affiliate invite system (token, expiry, claim, bulk, CSV import)
- Shopify integration:
  - OAuth flow with auto brand signup and profile auto-fill
  - Storefront API token creation
  - Full webhook suite (orders/paid, orders/updated, app/uninstalled, shop/update, GDPR)
  - Post-OAuth setup: sales channel, collections, metafields, logo sync
  - Refund/cancellation handling via orders/updated webhook
- Hydrogen internal API (brand config, affiliate lookup, affiliate products)
- Stripe Connect Express onboarding and payment method management
- Commission payout processing (hybrid funding: wallet + card → Stripe transfer)
- Commission wallet top-ups via Stripe Checkout
- Brand team RBAC (5 roles with capability-based access)
- Brand onboarding readiness checklist
- Subscription and plan management
- Site analytics (page views, link clicks, leads) with hourly/daily aggregation
- Google Business Profile sync
- Staff dashboard (browse, search, edit, archive, restore, hard delete)
- In-app notification system with email preferences and policies
- Waitlist signup system
- UI confirmation preference management
- Redis caching with single-flight locking across professional and public site layers
- V1 dead code removal (controllers, services, models, jobs, routes)
- V2 comments added to all surviving backend classes

### In Progress
- **V2 database migration** — Dropping V1 tables, creating `affiliate_product_selections`, renaming analytics tables
- **Hydrogen storefront** — Separate repo (`sidest-hydrogen`), not part of this backend
- **Embedded Shopify app** — Separate repo (`sidest-embedded`), brand setup wizard
- **Video uploads** — Code exists, feature-flagged off (`SIDEST_VIDEO_UPLOADS_ENABLED=false`)

### Known Issues / Notes
- Laravel database migrations are intentionally disabled (guarded in composer). All schema changes go through `supabase/migrations/`.
- Video upload worker queue (`redis_video`) must be running separately from the default queue.
- Some V1 references may still exist in model relationships and service code that need cleanup (see V2-REMOVAL-PLAN.md Phase 2).

---

## Rules and Constraints

### Critical Constraints
- **Never use Laravel migrations.** All schema changes use `supabase/migrations/` (plain SQL). There is a composer guard enforcing this.
- **Supabase JWT only for auth.** Never add password-based auth or Laravel Sanctum. Tokens come from Supabase.
- **Multi-schema PostgreSQL.** Always respect schema namespaces (`core.`, `retail.`, `analytics.`, `billing.`). The search path handles bare table names in queries, but migrations must be fully qualified.
- **R2 for all media.** Never store media in local filesystem or Supabase Storage.
- **Products live in Shopify.** Do not create local product tables. Use Storefront API and metafields.
- **Single-brand affiliates.** Each affiliate belongs to one brand only.

### Coding Conventions
- Follow **Laravel conventions** — Eloquent relationships, Form Requests for validation, Service classes for business logic, Action classes for single operations.
- Use **Pest** for all tests. No PHPUnit-style test classes.
- Format with **Laravel Pint** (`./vendor/bin/pint`) before committing.
- Controllers should be thin — delegate to Services and Actions.
- All API responses use **Resource classes** — never return raw Eloquent models.
- Sensitive data (OAuth tokens) must be encrypted at rest (use `encrypted:` Eloquent casting).
- Soft deletes on any user-generated content model.
- Cache invalidation must happen in Observers or after write operations — never leave stale cache.
- Every class has a `// V2:` comment above the class declaration explaining its role.

### API Conventions
- All routes require `Accept: application/json`.
- Public mini-site routes are domain-scoped to `{subdomain}.{SIDEST_PUBLIC_DOMAIN}`.
- Professional/staff routes are on the API host (`APP_URL`).
- Rate limiting: `throttle:public-site` for public, `throttle:analytics` for analytics endpoints.
- Return consistent JSON responses — use Laravel's resource/collection pattern.

### Performance Constraints
- Redis cache is the primary read layer for public site payload — keep cache warm.
- Image variants must be processed asynchronously (queue), never inline.
- Video jobs run on the `redis_video` queue — do not mix with the default queue.
- Single-flight locking on cache warm prevents thundering herd.

---

## AI Working Instructions

When another AI reads this file, it should:
- Read this document and `V2_BACKEND_REFERENCE.md` before making changes.
- Check `// V2:` comments on classes for per-file context.
- Preserve the V2 architecture (Shopify-native products, Stripe Connect, single-brand affiliates) unless explicitly told to change it.
- Never recreate V1 concepts (local product tables, promotions, segments, multi-brand affiliates).
- Explain proposed changes before large refactors.
- Update this file after meaningful implementation.
- Never run `php artisan migrate` — use `supabase/migrations/` for schema changes.
- Check `docs/api.md` for the authoritative API reference before adding or modifying endpoints.

---

## Decisions Log

| Date | Decision | Reason |
|------|----------|--------|
| Pre-2026 | Use Supabase for auth (JWT) instead of Laravel Sanctum | Supabase handles cross-platform auth; avoids managing passwords |
| Pre-2026 | Use Supabase PostgreSQL with multiple schemas | Clean domain separation; maps to business layers |
| Pre-2026 | Disable Laravel migrations; use supabase/migrations only | Supabase manages DB schema; avoids conflicts with RLS and extensions |
| Pre-2026 | Use Cloudflare R2 for all media storage | Cost-effective, S3-compatible, CDN-native |
| Pre-2026 | Feature-flag video uploads | FFmpeg workers must be provisioned separately |
| Pre-2026 | Professional follows brand theme | Brand identity consistency is the core value proposition |
| 2026-03-19 | Created AI_CONTEXT.md as shared AI source of truth | Multiple AI tools working on codebase need a shared orientation document |
| 2026-03-19 | Commission distribution via Stripe Connect (80/20 split) | Automated payouts to affiliates; platform takes 20% |
| 2026-03-19 | No in-house booking system — Square and Fresha only | Reduces scope; may revisit native booking in future |
| 2026-04-03 | **V2 architecture transition** | Products move to Shopify Storefront API, storefronts to Hydrogen, payouts to Stripe Connect |
| 2026-04-03 | Single-brand affiliate model | Simplifies affiliate management; each affiliate belongs to one brand |
| 2026-04-03 | Remove local product tables | Products live in Shopify; `affiliate_product_selections` uses Shopify GIDs |
| 2026-04-03 | Remove promotions and segments | Commission rates handled via Shopify metafields; no need for complex targeting |
| 2026-04-03 | V1 dead code removal | 12 controllers, 9 services, 10 models, 7 jobs, 60+ routes removed |
| 2026-04-03 | Added V2 comments to all surviving backend classes | Enables developers and AI agents to understand each class's V2 role without prior V1 context |
| 2026-04-03 | Created V2_BACKEND_REFERENCE.md | Quick-context document for navigating the backend without reading every file |
| 2026-04-03 | Removed legal content feature | Legal content tables dropped; services and controllers removed. Not needed in V2 affiliate model |
| 2026-04-03 | Expanded Shopify webhook suite | Added orders/updated (refunds), app/uninstalled, shop/update, GDPR webhooks beyond initial orders/paid |
| 2026-04-03 | Post-OAuth Shopify setup pipeline | Auto-creates sales channel, collections, metafields, syncs logo, and marks setup complete after OAuth |
| 2026-04-03 | Hydrogen internal API | Server-to-server endpoints for brand config, affiliate lookup, and affiliate products |
| 2026-04-03 | Brand signup auto-fill | ShopProfileAutoFillService populates brand profile from Shopify shop data during OAuth |
| 2026-04-03 | Renamed Comet → Side St | Full codebase rename across config, routes, middleware, models |
| 2026-04-11 | Shopify OAuth defers account creation | Shop owner email (e.g., CEO) was being used as login. Now caches credentials with encrypted setup token (1hr TTL); brand enters own email in setup wizard |
| 2026-04-11 | Design tokens in `site.settings.design` | Design belongs to the brand, not the Shopify integration. Persists across disconnect/reconnect. Only Shopify-specific data in `provider_metadata` |
| 2026-04-11 | All images through WebP variant pipeline | Consistent CDN delivery. Brand logo/placeholder were previously stored raw — now all go through SiteMedia → R2 → ProcessImageVariantsJob → MediaVariant |
| 2026-04-11 | Open invite links for all active brands | `/join/{handle}` works if brand exists and is active. No toggle needed — `affiliate_visibility` controls directory listing, not link access |
| 2026-04-11 | Custom product photos: two-level toggle | Global brand setting (default: true) → per-affiliate override (nullable, inherits when null). Per-product rejected as over-complex |

---

## Handoff Notes

### What Another AI Should Know Before Continuing

1. **This is a Laravel 12 API backend.** Storefronts are Hydrogen (separate repo). Dashboard is Next.js (separate repo). Embedded app is Remix (separate repo).
2. **Database is Supabase PostgreSQL.** Use `supabase/migrations/` for all schema changes. Eloquent models map to tables in non-public schemas.
3. **Auth flow is JWT-first.** Every professional API request must pass `Authorization: Bearer <supabase_jwt>`. The `VerifySupabaseJwt` middleware validates against JWKS.
4. **Products live in Shopify, not locally.** The `AffiliateProductSelection` model references `shopify_product_gid`, not a local UUID. Commission rates come from Shopify metafields.
5. **V1 dead code is gone.** Do not recreate BrandProduct, ProfessionalSelection, BrandPromotion, BrandAffiliateSegment, or any of the other removed V1 models/services/controllers.
6. **Every class has a `// V2:` comment** above its declaration explaining its role. Read these for quick context.
7. **`V2_BACKEND_REFERENCE.md`** has a complete per-file reference with V2 critical paths, queue architecture, and what was removed.
8. **Video uploads are disabled by default.** Set `SIDEST_VIDEO_UPLOADS_ENABLED=true` and ensure the `redis_video` queue worker is running.

### Fragile Parts
- `PublicSiteResolver` — resolves site by subdomain. Changing resolution logic affects all public site functionality.
- `SiteCacheService` — single-flight locking and cache invalidation must stay in sync with write operations.
- `ProfessionalIntegration` encrypted token storage — changing encryption or casting breaks existing OAuth sessions.
- `CommissionPayoutService` — hybrid funding logic (wallet + card) with Stripe Connect transfers. Complex error handling.
- `BrandPartnerLink` slot numbering — slot assignment must remain consistent.
- `supabase/migrations/` — migrations run against a live Supabase project. Always test in staging first.
