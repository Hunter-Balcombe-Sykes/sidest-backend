# AI_CONTEXT.md — Comet Platform

> **Source of truth for AI tools working on this codebase.**
> Read this before making changes. Update after meaningful progress.

---

## Project Overview

**Comet** is a multi-tenant SaaS affiliate platform that gives influencers and beauty/barbering professionals a branded one-page personal website, connected to a specific brand partner. It replaces link-in-bio tools (like Linktree), adds an affiliate e-commerce shop, booking integrations, and detailed analytics — all within the brand's theme and identity.

**What problem it solves:**
Brands want influencers and professionals to sell their products without managing separate storefronts. Professionals/influencers want a polished all-in-one presence without design effort. Comet sits in the middle, handling the site, commerce, analytics, and commission distribution automatically.

**Main goals:**
1. Give each professional/influencer a published one-page site on a subdomain
2. Connect that site to a specific brand's products, theme, and identity
3. Process commission-based sales — brand fulfils, Comet takes a cut, professional earns commission
4. Provide booking integrations (Square, Fresha) for service professionals
5. Give brands and professionals actionable analytics (views, clicks, sales, earnings)
6. Enable brands to promote specific products via commission adjustments and price overrides

**Current status:** Active development. Core professional features (sites, services, media, integrations) are production-ready. Brand affiliate commerce layer is in progress. Video uploads and enterprise self-service are in progress.

---

## Core Idea

### Plain-English Explanation

- A **brand** (e.g., a haircare company) signs up and connects their product catalogue.
- They invite **influencers/professionals** (barbers, hairdressers, Instagram influencers) as affiliates.
- Each affiliate gets their own subdomain site (e.g., `john.comet.app`) auto-themed in the brand's colours and branding.
- The affiliate can add their own media, links, and bio — but cannot change the brand's theme/palette.
- Customers visit the site, browse products, and purchase. The brand fulfils the order.
- Revenue splits automatically: **brand gets their cut → Comet takes a platform fee → affiliate earns commission**.
- Commission rates and product prices can be adjusted per affiliate by the brand (e.g., run a sale only on an influencer's site, or boost commission on slow-moving stock).
- Service professionals (barbers, hairdressers) can also take bookings via Square or Fresha through the same site.
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
Customer buys product → order recorded → brand fulfils
    ↓
Commission distributed (brand → Comet → affiliate)
    ↓
Analytics recorded for both brand and affiliate dashboards
```

### Key Assumptions

- One professional can be affiliated with one brand at a time (primary brand, can have others).
- Brands control the theme, colours, logo, and product catalogue.
- Professionals control their own media pool, links, bio, and service listings.
- Booking integrations (Square, Fresha) are per-professional, not per-brand.
- Supabase handles all authentication (JWT-based). Laravel does not manage passwords.
- The database lives entirely in Supabase (PostgreSQL). Laravel migrations are disabled — use `supabase/migrations/` only.

---

## Codebase Summary

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
│   │   │   ├── Enterprise/    — Enterprise management (stub/in progress)
│   │   │   └── Webhooks/      — Square, Fresha webhook receivers
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
│   ├── api/enterprise.php     — Enterprise routes (stub)
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
| `ProfessionalIntegration` | `core.professional_integrations` | Encrypted OAuth tokens for Square/Fresha |
| `BrandStoreSettings` | `retail.brand_store_settings` | Commission rate config per brand |
| `ProfessionalSelection` | `retail.professional_selections` | Featured product list per professional |
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
[TBD: E-commerce checkout integration. Schema and featured products API exist. Payment processor and order recording not yet fully explored.]

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
| `retail` | Brand store settings, product settings, professional selections |

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
- Brand store settings (commission rates)
- Per-product overrides (`is_available`, `custom_price`) on `BrandProductSettings`
- Featured products selection per professional
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
- **Enterprise features** — Provisioning service and schema exist. Self-service routes are stubs.
- **B2B retail / affiliate commerce** — Schema and featured product API exist. Order recording, payment processing, and commission distribution flow are [TBD].
- **Shopify integration** — Schema prepared, integration code not yet present.

### Known Issues / Notes
- Laravel database migrations are intentionally disabled (guarded in composer). All schema changes go through `supabase/migrations/`.
- Video upload worker queue (`redis_video`) must be running separately from the default queue.
- `COMET_VIDEO_UPLOADS_ENABLED` must be set to `true` to enable video upload endpoints.

---

## Next Tasks

### Highest Priority
1. **Affiliate commerce order flow** — Define how a product purchase on the affiliate's site is recorded, how commission is calculated and distributed, and what the brand sees.
2. **Enable video uploads** — Ensure `redis_video` queue worker is running, then set feature flag.
3. **Enterprise self-service routes** — Implement enterprise CRUD for owner-type professionals.
4. **Shopify integration** — Connect brand product catalogue via Shopify API (schema ready).
5. **Analytics dashboard for brands** — Brands need to see aggregate performance across all their affiliates.

### Suggested Implementation Order
1. Order recording model + API endpoint (retail schema)
2. Commission calculation service (brand rate → Comet fee → affiliate payout)
3. Stripe Connect for affiliate payouts [NEEDS INPUT: confirm payment provider strategy]
4. Brand analytics endpoints (aggregate affiliate performance)
5. Video upload enablement (infrastructure task)
6. Enterprise self-service CRUD
7. Shopify catalogue sync service

### Open Questions
- [NEEDS INPUT: Who fulfils orders? Does Comet record orders, or does it redirect to brand's Shopify/WooCommerce?]
- [NEEDS INPUT: Commission distribution mechanism — Stripe Connect, manual payout, or other?]
- [NEEDS INPUT: Does the platform fee come from brand or affiliate or both?]
- [NEEDS INPUT: Can an influencer be affiliated with multiple brands simultaneously?]
- [NEEDS INPUT: Is there an in-house booking system planned, or is it always Square/Fresha?]
- [TBD: Public-facing site frontend — is it a separate repo or served from this repo?]

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

---

## Handoff Notes

### What Another AI Should Know Before Continuing

1. **This is a Laravel 12 API only.** There is no Blade frontend. The frontend (likely a separate repo) communicates via JSON API.
2. **Database is Supabase PostgreSQL.** Use `supabase/migrations/` for all schema changes. Eloquent models map to tables in non-public schemas — check the model's `$table` and `$connection` properties.
3. **Auth flow is JWT-first.** Every professional API request must pass `Authorization: Bearer <supabase_jwt>`. The `supabase.jwt` middleware validates against JWKS.
4. **Media is async.** After upload, variants are queued. Don't expect variants to exist immediately after upload.
5. **The retail/commerce layer is incomplete.** Schema and featured products API exist, but order recording, commission distribution, and payment splitting are not yet implemented.
6. **Video uploads are disabled by default.** Set `COMET_VIDEO_UPLOADS_ENABLED=true` and ensure the `redis_video` queue worker is running before testing video flows.

### Fragile Parts
- `PublicSiteResolver` — resolves site by subdomain from HTTP header or query param. Changing subdomain resolution logic affects all public site functionality.
- `SiteCacheService` / `ProfessionalCacheService` — cache invalidation logic must stay in sync with write operations. Missing an invalidation call causes stale public data.
- `ProfessionalIntegration` encrypted token storage — changing the encryption key or casting will break existing OAuth sessions.
- `BrandPartnerLink` slot numbering — slot assignment logic must remain consistent or brand affiliate ordering breaks.
- `supabase/migrations/` — migrations run against a live Supabase project. Always test in a staging environment first. Irreversible migrations must include a rollback plan.

### Unfinished Work In Progress (as of 2026-03-19)
- Video upload workers and enabling the feature flag
- Enterprise self-service API routes
- Affiliate commerce order flow (models, API, commission logic)
- Shopify brand catalogue integration
- Brand-facing aggregate analytics across affiliates
- [NEEDS INPUT: Stripe Connect or payout mechanism for commissions]
