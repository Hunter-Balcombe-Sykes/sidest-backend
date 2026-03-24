# AI_CONTEXT.md — OneLink Platform

> **Source of truth for AI tools working on this codebase.**
> Read this before making changes. Update after meaningful progress.

---

## Project Overview

**OneLink** is a multi-tenant SaaS affiliate platform that gives influencers and beauty/barbering professionals a branded one-page personal website, connected to a specific brand partner. It replaces link-in-bio tools (like Linktree), adds an affiliate e-commerce shop, booking integrations, and detailed analytics — all within the brand's theme and identity.

**What problem it solves:**
Brands want influencers and professionals to sell their products without managing separate storefronts. Professionals/influencers want a polished all-in-one presence without design effort. Comet sits in the middle, handling the site, commerce, analytics, and commission accounting workflows.

**Main goals:**
1. Give each professional/influencer a published one-page site on a subdomain
2. Connect that site to a specific brand's products, theme, and identity
3. Process commission-based sales — brand fulfils, Comet takes a cut, professional earns commission
4. Provide booking integrations (Square, Fresha) for service professionals
5. Give brands and professionals actionable analytics (views, clicks, sales, earnings)
6. Enable brands to promote specific products via commission adjustments and price overrides

**Current status:** Active development. Core professional features (sites, services, media, integrations) are production-ready. The affiliate commerce foundation is implemented (brand-scoped catalog, availability controls, deny/allow overrides, per-affiliate pricing settings, strict selections, manager APIs), plus Shopify-canonical order ingestion, attribution, commission ledgering, and brand/self analytics APIs. Unified brand RBAC now resolves access from direct brand ownership, enterprise links, and `retail.brand_team_memberships`. Video uploads remain feature-flagged off. Export/schedule analytics runtime and payout transfer execution are still in progress.

---

## Core Idea

### Plain-English Explanation

- A **brand** (e.g., a haircare company) signs up and connects their product catalogue.
- They invite **influencers/professionals** (barbers, hairdressers, Instagram influencers) as affiliates.
- Each affiliate gets their own subdomain site (e.g., `john.comet.app`) auto-themed in the brand's colours and branding.
- The affiliate can add their own media, links, and bio — but cannot change the brand's theme/palette.
- Customers visit the site, browse products, and purchase. The brand fulfils the order.
- Comet mints a deterministic checkout-session token, the token is written into Shopify order metadata, and confirmed Shopify order webhooks become the canonical analytics source.
- Commission entries are posted to an append-only ledger (accrual/reversal/payout states). Automated payout transfer execution is not yet enabled.
- Commission rates and product prices can be adjusted per affiliate by the brand (e.g., run a sale only on an influencer's site, or boost commission on slow-moving stock).
- **An affiliate can be connected to multiple brands.** One primary brand may be designated in future.
- Service professionals (barbers, hairdressers) can also take bookings via **Square or Fresha** through the same site. No in-house booking system is planned.
- Brands and affiliates both see analytics: page views, link clicks, products sold, revenue earned.

### How the System Works

```
Brand sets up catalogue + theme
    ↓
Brand invites affiliate (token-based invite)
    ↓
Affiliate claims invite → site auto-provisioned on subdomain
    ↓
Affiliate customises content (media, links, bio) within brand theme
    ↓
Customer visits subdomain → sees brand-themed site
    ↓
Customer buys product → checkout-session token attached to Shopify order
    ↓
Shopify webhook ingested → canonical order + ledger normalized
    ↓
Daily aggregates rebuilt for brand and affiliate dashboards
```

### Key Assumptions

- Multi-brand affiliation is enabled; product selection cap is global (10) per professional across all connected brands.
- Brands control the theme, colours, logo, and product catalogue.
- Professionals control their own media pool, links, bio, and service listings.
- Booking integrations (Square, Fresha) are per-professional, not per-brand.
- Supabase handles all authentication (JWT-based). Laravel does not manage passwords.
- The database lives entirely in Supabase (PostgreSQL). Laravel migrations are disabled — use `supabase/migrations/` only.

---

## Codebase Summary

**Repositories:**
- **Backend (this repo):** `https://github.com/Hunter-Balcombe-Sykes/Comet-Backend` — branch `develop`
- **Frontend:** `https://github.com/hunterbalcombesykes/Commet-web` — branch `main`, deployed on Vercel

**Stack:** Laravel 12 · PHP 8.2+ · PostgreSQL (Supabase) · Redis · Cloudflare R2 · Supabase Auth (JWT)

### Directory Map

```
/
├── app/
│   ├── Models/
│   │   ├── Core/           — Main domain models (Professional, Site, Service, Customer, Block, SiteMedia, etc.)
│   │   ├── Retail/         — Commerce models (BrandStoreSettings, EnterpriseProduct, ProfessionalSelection, etc.)
│   │   ├── Billing/        — Plan, Subscription
│   │   ├── Analytics/      — SiteVisit, LinkClick, LeadSubmission
│   │   └── Views/          — Read-only aggregation models (PublicSitePayload, AllSiteData)
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── Professional/  — Authenticated professional endpoints
│   │   │   ├── PublicSite/    — Unauthenticated mini-site endpoints
│   │   │   ├── Staff/         — Internal staff/admin endpoints
│   │   │   ├── Enterprise/    — Enterprise self-service management endpoints
│   │   │   └── Webhooks/      — Square, Fresha, Shopify webhook receivers
│   │   ├── Middleware/        — JWT auth, role guards, plan gates
│   │   ├── Requests/          — Form request validation classes
│   │   └── Controllers/Concerns/ — Shared traits (ResolveCurrentProfessional, ResolveCurrentSite)
│   ├── Services/              — External integrations, caching, media processing
│   ├── Actions/               — Single-responsibility action classes (subscriptions, site ops)
│   ├── Jobs/                  — Queue workers (image/video processing, email, cache warming)
│   └── Observers/             — Eloquent model lifecycle hooks
├── routes/
│   ├── api.php                — Main router (includes sub-files)
│   ├── api/professional.php   — 40+ professional routes
│   ├── api/publicSite.php     — Public mini-site routes (subdomain-scoped)
│   ├── api/staff.php          — Staff/admin routes
│   ├── api/enterprise.php     — Enterprise self-service routes
│   └── web.php                — QR code redirect only
├── supabase/migrations/       — All DB migrations (SQL, NOT Laravel migrations)
├── database/
│   ├── factories/
│   └── seeders/
├── config/                    — Laravel config files
├── tests/                     — Pest framework tests
├── docs/api.md                — Comprehensive API reference
├── composer.json
├── .env.example
└── AI_CONTEXT.md              — This file
```

### Core Models

| Model | Table | Purpose |
|-------|-------|---------|
| `Professional` | `core.professionals` | User account — barber, influencer, brand owner, promoter, etc. |
| `Site` | `core.sites` | Published mini-site per professional (subdomain, theme, publish state) |
| `Service` | `core.services` | Service offering with price, duration, Square/Fresha sync IDs |
| `ServiceCategory` | `core.service_categories` | Groups services |
| `Customer` | `core.customers` | Client/lead records per professional |
| `Block` | `core.blocks` | Modular site sections (links, gallery, text, etc.) |
| `SiteMedia` | `core.site_media` | Images/videos uploaded by professional (gallery or content pool) |
| `MediaVariant` | `core.media_variants` | Processed media artifacts (WebP, MP4, HLS) |
| `BrandPartnerLink` | `core.brand_partner_links` | Brand ↔ affiliate relationship with slot numbering |
| `BrandAffiliateInvite` | `core.brand_affiliate_invites` | Token-based invite (expiring) for affiliate onboarding |
| `ProfessionalIntegration` | `core.professional_integrations` | Encrypted OAuth/provider metadata for Square/Fresha/Shopify |
| `BrandProduct` | `retail.brand_products` | Full Shopify-synced product catalog per brand |
| `BrandProductSetting` | `retail.brand_product_settings` | Global brand availability/featured/commission/discount settings per brand product |
| `BrandProductAffiliateOverride` | `retail.brand_product_affiliate_overrides` | Per-affiliate access overrides (`deny` blocks; `allow` can bypass availability, with deny precedence) |
| `BrandProductAffiliateSetting` | `retail.brand_product_affiliate_settings` | Per-affiliate commission/discount/custom-price overrides on brand products |
| `BrandStoreSettings` | `retail.brand_store_settings` | Commission rate config per brand |
| `ProfessionalSelection` | `retail.professional_selections` | Featured product list per professional |
| `CheckoutSession` | `retail.checkout_sessions` | Tokenized checkout attribution context for deterministic order ownership |
| `OrderEventInbox` | `retail.order_event_inbox` | Idempotent Shopify/fallback event inbox with processing status |
| `RetailOrder` | `retail.orders` | Canonical normalized order header used for analytics |
| `OrderItem` | `retail.order_items` | Canonical normalized order line items |
| `CommissionLedgerEntry` | `retail.commission_ledger_entries` | Append-only commission accrual/reversal/payout accounting |
| `EnterpriseBrandLink` | `core.enterprise_brand_links` | Links distributor enterprises to managed brand professional accounts |
| `BrandTeamMembership` | `retail.brand_team_memberships` | Brand-scoped role assignments (`owner`, `finance`, `marketing`, `analyst`, `read_only`) |
| `Plan` | `billing.plans` | Subscription tiers with entitlements |
| `Subscription` | `billing.subscriptions` | Professional's current plan status |
| `SiteVisit` | `analytics.site_visits` | Page view events with UTM, device, geo |
| `LinkClick` | `analytics.link_clicks` | Block/link click events |
| `LeadSubmission` | `analytics.lead_submissions` | Form/lead capture events |

### Key Services

| Service | Purpose |
|---------|---------|
| `ProfessionalCacheService` | Redis cache for professional payload, services, customer count |
| `SiteCacheService` | Redis cache for blocks, links, sections |
| `ImageVariantService` | Generate WebP image variants (optimised + maximised) |
| `VideoVariantService` | FFmpeg MP4 + HLS transcoding (feature-flagged off) |
| `SquareApiClient` / `SquareServiceSyncService` | Square OAuth + service catalogue sync |
| `FreshaApiClient` / `FreshaServiceSyncService` | Fresha OAuth + service sync |
| `BrandAffiliateInviteService` | Invite token generation, claiming, expiry |
| `BrandPartnerLinkService` | Connect/disconnect brand-affiliate relationships |
| `BrandAccessService` | Capability-based brand RBAC resolver across direct brand ownership, enterprise links, and brand-team memberships |
| `BrandProductCatalogService` | Builds affiliate-visible, manager-catalog, and storefront-selected product payloads |
| `BrandProductSettingsService` | Ensures synced `brand_products` always have settings rows |
| `BrandPricingService` | Effective commission + discount price calculation (ceil to nearest 5 cents) |
| `SelectionCleanupService` | Removes invalid selections, notifies affected professionals, invalidates site cache |
| `ShopifyOrderProcessingService` | Validates session token, normalizes Shopify events, writes canonical orders/items/ledger |
| `OrderAnalyticsAggregateService` | Deterministic rebuild of brand/self daily analytics tables from canonical data |
| `EnterpriseProvisioningService` | Auto-provision enterprise for owner-type professionals |
| `FeaturedProductsPayloadService` | Format product list for public API response |
| `ProfessionalLegalContentService` | Auto-generate T&Cs and privacy policy |
| `PublicSiteResolver` | Resolve public site by subdomain header or ID |

### Key Dependencies

| Package | Use |
|---------|-----|
| `laravel/framework` 12.x | Core framework |
| `predis/predis` | Redis client (cache + queue) |
| `aws/aws-sdk-php` | Cloudflare R2 (S3-compatible) media storage |
| `simplesoftwareio/simple-qrcode` | QR code generation per professional |
| `php-open-source-saver/php-jwt` | Supabase JWT validation |
| `spatie/laravel-data` | Typed data objects |
| `pestphp/pest` | Test framework |

---

## How It Works

### End-to-End Flow

#### Professional Onboarding
1. Professional authenticates via Supabase → frontend sends `POST /api/bootstrap` with JWT.
2. Laravel creates `Professional` and `Site` records. Enterprise provisioned if applicable.
3. Professional configures site (theme from brand, custom media, links, services).
4. Site published → available at `{handle}.{COMET_PUBLIC_DOMAIN}`.

#### Brand Affiliate Invite Flow
1. Brand professional calls `POST /api/brand-affiliate-invites` → generates token.
2. Invite sent to affiliate (email/link).
3. Affiliate calls `POST /api/brand-affiliate-invites/{token}/claim` → `BrandPartnerLink` created.
4. Affiliate's site now inherits brand theme, shows brand products.

#### Public Site Visit
1. Request hits `{subdomain}.comet.app` → `PublicSiteResolver` identifies professional.
2. `GET /api/public/site` returns full site payload (cached in Redis).
3. Analytics events (`POST /api/public/analytics/*`) recorded asynchronously.

#### Product Purchase Flow
1. Public storefront requests `POST /api/public/store/checkout-session` (or `.../checkout-session-by-slug`) and receives `comet_session` token.
2. Frontend/server writes `comet_session` into Shopify order metadata (note attributes).
3. Shopify sends `POST /api/webhooks/shopify/orders` (or fallback endpoint) to Comet.
4. Event is deduplicated in `retail.order_event_inbox`, resolved to brand integration, and processed async.
5. Processor validates token + brand consistency, writes canonical `retail.orders` / `retail.order_items`. Attribution is implicit via `affiliate_professional_id` set from the checkout session — no separate attribution table.
6. Commission accrual/reversal entries are appended in `retail.commission_ledger_entries`.
7. Aggregate rebuild jobs update daily brand/self analytics tables for dashboard APIs.

#### Booking Flow
1. Customer calls `POST /api/public/booking/availability` → proxied to Square/Fresha.
2. Customer calls `POST /api/public/booking/checkout` → appointment created in integration.

#### Media Upload Flow
1. Professional calls `POST /api/uploads` → server validates, uploads to R2, records `SiteMedia`.
2. `ProcessImageVariantsJob` dispatched → generates WebP variants → records `MediaVariant`.
3. Video path: `ProcessVideoVariantsJob` → FFmpeg MP4 + HLS (currently feature-flagged off).

### Authentication & Middleware Stack

```
Request → supabase.jwt (validate JWT, extract supabase_uid)
        → current.pro (load Professional from supabase_uid)
        → [staff] (require staff role)
        → [staff.admin] (require staff admin role)
        → [require.plan] (check entitlement)
        → Controller
```

### Database Schemas

| Schema | Contents |
|--------|----------|
| `public` | Laravel infrastructure (cache, jobs, failed_jobs) |
| `core` | Main domain (professionals, sites, services, customers, blocks, media, integrations, brand links) |
| `analytics` | Event tracking (site_visits, link_clicks, lead_submissions) |
| `billing` | Plans, subscriptions |
| `retail` | Brand catalog/settings (`brand_products`, `brand_product_settings`, affiliate settings/overrides), store settings, selections, commerce tables |

`DB_SEARCH_PATH=public,core,analytics,billing,retail` — queries can reference tables without schema prefix.

### Caching Strategy
- Professional payload + services cached on read, invalidated on write.
- Public site blocks, links, sections cached per site.
- Cache keys generated by `CacheKeyGenerator` using professional/site ID.
- Cache driver: Redis via Predis.

---

## Current Progress

### Fully Implemented
- Professional profile CRUD (types: barber, hairdresser, influencer, promoter, brand, etc.)
- Mini-site builder (subdomain routing, blocks, sections, links, themes)
- Service and category management with Square/Fresha sync field support
- Customer database (soft delete, restore, marketing preferences)
- Email marketing subscriptions (opt-in/out with token-based unsubscribe)
- Image upload + WebP variant processing (optimised/maximised)
- Gallery and content media pools
- QR code generation per professional
- Square OAuth integration + service catalogue sync
- Fresha OAuth integration + service sync
- Brand partner link management (connect/disconnect affiliates)
- Brand affiliate invite system (token, expiry, claim flow)
- Brand affiliate commerce foundation:
  - `brand_products` full catalog + `brand_product_settings`
  - global availability rules with per-affiliate deny/allow access overrides
  - per-affiliate commission/discount/custom price overrides
  - enterprise brand-management links (`distributor` enterprise type)
  - strict selection validation by `brand_product_id`
  - global 10-product cap per professional across brands
  - featured cap 10 per brand
- Store API cutover and catalog controls:
  - `PUT /api/store/featured-products` hard-cutover payload (`selected_products[{brand_product_id,...}]`)
  - `GET /api/store/available-products` affiliate-visible catalog
  - `GET/PATCH /api/store/brand-products` + bulk patch
  - affiliate override management endpoints (`deny` + `allow`)
  - per-affiliate product pricing endpoints (`GET|PUT|DELETE /api/store/affiliate-product-settings`)
  - legacy product-settings write flow removed (`PUT /api/store/brand-product-settings`)
  - featured-products reads no longer fall back to `site.settings.selected_products`
- Shopify-canonical order analytics pipeline:
  - public checkout-session attribution endpoint (`POST /api/public/store/checkout-session`)
  - Shopify orders webhook ingestion (`POST /api/webhooks/shopify/orders`)
  - secure fallback ingestion path (`POST /api/webhooks/shopify/orders/fallback`)
  - canonical order normalization (`retail.orders`, `retail.order_items`)
  - append-only commission ledger accrual/reversal handling
  - deterministic daily aggregate rebuild jobs (brand + professional)
- Professional analytics cutover:
  - new brand endpoints `/api/store/brand-analytics/*`
  - new self endpoints `/api/store/my-analytics/*`
- Shopify integration management endpoints for brand accounts:
  - `/api/shopify/status`, `/api/shopify/connect`, `/api/shopify/disconnect`, `/api/shopify/token`, `/api/shopify/webhooks/register`
- Selection auto-cleanup + notification on unavailability/deny/disconnect
- Subscription and plan management (Stripe-backed)
- Site analytics (page views, link clicks, leads)
- Legal content (auto-generated T&Cs + privacy, manual override)
- Google Business Profile sync
- Staff dashboard (browse, search, edit, soft delete, restore, hard delete)
- Soft delete + restore on all major entities
- In-app notifications system
- Redis caching across professional and public site layers

### Partially Implemented / In Progress
- **Video uploads** — Code exists (`ProcessVideoVariantsJob`, FFmpeg), feature-flagged off (`COMET_VIDEO_UPLOADS_ENABLED=false`). Needs video workers running before enabling.
- **Frontend checkout-session bridge** — Public checkout clients must call checkout-session and write `comet_session` into Shopify order metadata in all flows.
- **Shopify product ingest runtime** — Catalog ingest/sync into `retail.brand_products` is still not fully automated end-to-end.

### Known Issues / Notes
- Laravel database migrations are intentionally disabled (guarded in composer). All schema changes go through `supabase/migrations/`.
- Video upload worker queue (`redis_video`) must be running separately from the default queue.
- `COMET_VIDEO_UPLOADS_ENABLED` must be set to `true` to enable video upload endpoints.

---

## Next Tasks

### Highest Priority
1. **Frontend checkout token wiring** — Ensure every storefront order path calls checkout-session and persists `comet_session` on Shopify orders.
2. **Shopify product ingest runtime** — Implement/finish production ingest + sync bootstrap for brand product catalog rows.
3. **Enable video uploads** — Ensure `redis_video` queue worker is running, then set feature flag.

### Suggested Implementation Order
1. Frontend checkout-session + token write integration
2. Shopify catalog sync service/runtime bootstrap
3. Video upload enablement (infrastructure task)

### Open Questions
- [TBD: Should fallback webhook ingestion be enabled in production permanently or restricted to break-glass use only?]

---

## Rules and Constraints

### Critical Constraints
- **Never use Laravel migrations.** All schema changes use `supabase/migrations/` (plain SQL). There is a composer guard enforcing this.
- **Supabase JWT only for auth.** Never add password-based auth or Laravel Sanctum. Tokens come from Supabase.
- **Multi-schema PostgreSQL.** Always respect schema namespaces (`core.`, `retail.`, `analytics.`, `billing.`). The search path handles bare table names in queries, but migrations must be fully qualified.
- **R2 for all media.** Never store media in local filesystem or Supabase Storage (legacy, being phased out).

### Coding Conventions
- Follow **Laravel conventions** — Eloquent relationships, Form Requests for validation, Service classes for business logic, Action classes for single operations.
- Use **Pest** for all tests. No PHPUnit-style test classes.
- Format with **Laravel Pint** (`./vendor/bin/pint`) before committing.
- Controllers should be thin — delegate to Services and Actions.
- Use `Concerns\ResolveCurrentProfessional` and `Concerns\ResolveCurrentSite` traits in controllers, don't query the professional directly.
- Sensitive data (OAuth tokens) must be encrypted at rest (use `encrypted:` Eloquent casting).
- Soft deletes on any user-generated content model.
- Cache invalidation must happen in Observers or after write operations — never leave stale cache.

### API Conventions
- All routes require `Accept: application/json`.
- Public mini-site routes are domain-scoped to `{subdomain}.{COMET_PUBLIC_DOMAIN}`.
- Professional/staff routes are on the API host (`APP_URL`).
- Rate limiting: `throttle:public-site` for public, `throttle:analytics` for analytics endpoints.
- Return consistent JSON responses — use Laravel's resource/collection pattern.

### Performance Constraints
- Redis cache is the primary read layer for public site payload — keep cache warm.
- Image variants must be processed asynchronously (queue), never inline.
- Video jobs run on the `redis_video` queue — do not mix with the default queue.

---

## AI Working Instructions

When another AI reads this file, it should:
- Read this document before making any changes to the codebase.
- Preserve the existing architecture (multi-schema DB, action/service pattern, Supabase JWT) unless there is a strong, discussed reason not to.
- Explain proposed changes before large refactors — write the plan in a comment or update this file.
- Update this file (specifically **Current Progress** and **Decisions Log**) after meaningful implementation.
- Keep notes concise and factual.
- Avoid duplicating outdated information — update existing entries rather than appending.
- Add new architectural decisions to the **Decisions Log** below.
- Never run `php artisan migrate` — use `supabase/migrations/` for schema changes.
- Check `docs/api.md` for the authoritative API reference before adding or modifying endpoints.

---

## Decisions Log

| Date | Decision | Reason |
|------|----------|--------|
| Pre-2026 | Use Supabase for auth (JWT) instead of Laravel Sanctum | Supabase handles cross-platform auth; avoids managing passwords |
| Pre-2026 | Use Supabase PostgreSQL with multiple schemas (core, retail, analytics, billing) | Clean domain separation; maps to business layers |
| Pre-2026 | Disable Laravel migrations; use supabase/migrations (SQL) only | Supabase manages DB schema; avoids conflicts with RLS and extensions |
| Pre-2026 | Use Cloudflare R2 for all media storage | Cost-effective, S3-compatible, CDN-native |
| Pre-2026 | Feature-flag video uploads (`COMET_VIDEO_UPLOADS_ENABLED`) | FFmpeg workers must be provisioned separately before enabling |
| Pre-2026 | Professional follows brand theme (no free-range customisation) | Brand identity consistency is the core value proposition for brands |
| Pre-2026 | Commission and price overrides are set by brand, not affiliate | Brands control pricing strategy for their affiliate channel |
| 2026-03-19 | Created AI_CONTEXT.md as shared AI source of truth | Multiple AI tools working on codebase need a shared orientation document |
| 2026-03-19 | Order fulfilment is handled entirely by brand via Shopify app | Comet records the order and pushes it to the brand's Shopify store; Comet never ships product |
| 2026-03-19 | Commission distribution via Stripe Connect (automatic) | Automated payouts to affiliates; Comet takes platform fee from the brand's monthly subscription (~$200/mo), not from the transaction |
| 2026-03-19 | Platform fee model: brand pays monthly subscription (~$200/mo) | Simpler billing — brand pays for access, not per-transaction fees on affiliate side |
| 2026-03-19 | Affiliates can connect to multiple brands | One affiliate can represent multiple brands; one "primary" brand may be designated in future but not yet decided |
| 2026-03-19 | No in-house booking system — Square and Fresha integrations only | Reduces scope; may revisit native booking in future |
| 2026-03-20 | Featured selection contract is hard-cutover to `brand_product_id` payload | Prevents cross-brand Shopify ID ambiguity and enforces strict brand-scoped selection validation |
| 2026-03-20 | Availability model is global + per-affiliate deny/allow access overrides (deny precedence) | Keeps governance centralized at brand level while allowing affiliate-specific exceptions |
| 2026-03-22 | Added `retail.brand_product_affiliate_settings` and API support for per-affiliate commission/discount/custom price | Brands can run affiliate-specific pricing and commission strategies without changing global brand product settings |
| 2026-03-20 | Product selection cap is global 10 per professional across brands; featured cap is 10 per brand | Maintains consistent storefront limits while supporting multi-brand catalogs |
| 2026-03-20 | Distributor enterprises can manage linked brands via `core.enterprise_brand_links` | Enables parent/distributor operating model without transferring brand ownership |
| 2026-03-20 | Removed legacy brand product-settings flow and site-settings product fallback | Prevents legacy writes from deleting modern catalog settings and keeps read/write paths strictly brand-product scoped |
| 2026-03-22 | Shopify confirmed order events are canonical for store analytics | Prevents client-side tampering and supports lifecycle-accurate refunds/cancellations for financial metrics |
| 2026-03-22 | Added signed fallback Shopify order ingestion endpoint (`/api/webhooks/shopify/orders/fallback`) | Provides controlled recovery path while still fetching canonical order data from Shopify |

---

## Handoff Notes

### What Another AI Should Know Before Continuing

1. **This is a Laravel 12 API only.** There is no Blade frontend. The frontend is a separate repo (`https://github.com/hunterbalcombesykes/Commet-web`, branch `main`, deployed on Vercel) and communicates via JSON API.
2. **Database is Supabase PostgreSQL.** Use `supabase/migrations/` for all schema changes. Eloquent models map to tables in non-public schemas — check the model's `$table` and `$connection` properties.
3. **Auth flow is JWT-first.** Every professional API request must pass `Authorization: Bearer <supabase_jwt>`. The `supabase.jwt` middleware validates against JWKS.
4. **Media is async.** After upload, variants are queued. Don't expect variants to exist immediately after upload.
5. **The retail/commerce layer is mid-flight.** Brand-scoped catalog/availability/selection governance is implemented, and Shopify-canonical order recording + attribution + analytics ingestion are in place. Export runtime and payout transfer execution are still pending.
6. **Video uploads are disabled by default.** Set `COMET_VIDEO_UPLOADS_ENABLED=true` and ensure the `redis_video` queue worker is running before testing video flows.

### Fragile Parts
- `PublicSiteResolver` — resolves site by subdomain from HTTP header or query param. Changing subdomain resolution logic affects all public site functionality.
- `SiteCacheService` / `ProfessionalCacheService` — cache invalidation logic must stay in sync with write operations. Missing an invalidation call causes stale public data.
- `ProfessionalIntegration` encrypted token storage — changing the encryption key or casting will break existing OAuth sessions.
- `BrandPartnerLink` slot numbering — slot assignment logic must remain consistent or brand affiliate ordering breaks.
- `supabase/migrations/` — migrations run against a live Supabase project. Always test in a staging environment first. Irreversible migrations must include a rollback plan.

### Unfinished Work In Progress (as of 2026-03-22)
- Video upload workers and enabling the feature flag
- Frontend checkout-session token wiring across all checkout paths
- Shopify brand catalogue ingest/sync runtime completion
- Analytics exports/report schedule APIs + generation jobs
- [NEEDS INPUT: payout rail/account model for commission transfer execution]
