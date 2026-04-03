# V2 Backend Reference

> Quick-context document for developers and AI agents. Describes what every surviving
> backend component does in V2 and why it exists.
>
> **V2 = Shopify-native affiliate storefronts (Hydrogen/Oxygen) + Stripe Connect payouts.**
> Product data lives in Shopify, not local tables. Affiliate selections use Shopify GIDs.
> Commission rates come from Shopify metafields. Storefronts are Hydrogen apps, not Laravel views.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                        V2 SYSTEM MAP                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Shopify Store ──OAuth──> Laravel Backend <──JWT──> Supabase    │
│       │                       │                                 │
│       │ Storefront API        │ REST API                        │
│       ▼                       ▼                                 │
│  Hydrogen Storefront    Next.js Dashboard                       │
│  ({brand}.sidest.co)    (brand + affiliate)                     │
│       │                       │                                 │
│       │ orders/paid           │ manage affiliates,              │
│       ▼                       │ commissions, payouts            │
│  Webhook ──> Commission       │                                 │
│  Ledger ──> Stripe Connect ───┘                                 │
│             (80/20 split)                                       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### What Changed from V1

| Aspect | V1 | V2 |
|--------|----|----|
| Product data | Local `brand_products` table synced from Shopify | Live Shopify Storefront API (no local table) |
| Affiliate selections | `retail.professional_selections` with local UUID | `retail.affiliate_product_selections` with `shopify_product_gid` |
| Commission rates | Per-product in local settings tables + per-affiliate overrides | Shopify product metafields (`sidest.commission_override`) |
| Affiliate scope | Multi-brand (up to 4 brands) | Single-brand constraint |
| Storefronts | Laravel-rendered mini-site | Hydrogen on Shopify Oxygen (per-brand deployment) |
| Payments | Internal ledger, manual bank transfer | Stripe Connect (auto 80/20 split, 30-day hold) |
| Promotions | Time-bounded campaigns with segment targeting | Removed entirely |
| Segments | Dynamic affiliate groupings | Removed entirely |

### What Was Removed (V1-only dead code)

- **12 controllers**: BrandProducts, BrandProductMedia, BrandProductAffiliateSetting, BrandProductAffiliateOverride, FeaturedProducts, BrandPromotion, BrandAffiliateSegment, BrandAffiliateSettings, BrandAffiliateDefaults, BrandStore, StoreAnalyticsV2, PublicStore
- **9 services**: BrandProductCatalog, BrandProductSettings, ShopifyCatalogSync, PromotionResolution, SegmentEvaluation, FeaturedProductsPayload, PublicStripeCheckout, OrderAnalyticsAggregate, OrderAnalyticsHourlyAggregate
- **10 models**: BrandProduct, BrandProductSetting, BrandProductMedia, BrandProductAffiliateSetting, BrandProductAffiliateOverride, BrandAffiliateSegment, BrandAffiliateSegmentMember, BrandAffiliateSettings, BrandPromotion, ProfessionalSelection
- **7 jobs**: RebuildBrand/ProfessionalDailyAggregates, RebuildBrand/ProfessionalHourlyAggregates, SendPromotionStart/EndNotifications, RefreshActiveSegmentMembers
- **15 database tables** dropped, **60+ routes** removed

---

## Controllers

### Base

| Controller | V2 Role |
|-----------|---------|
| `ApiController` | Abstract base. Provides `success()`, `error()`, `paginated()` response helpers for all API endpoints. |
| `HealthController` | Liveness/readiness probe. Checks DB + Redis connectivity for deployment health checks. |

### Professional (Authenticated Brand/Affiliate Dashboard)

| Controller | V2 Role |
|-----------|---------|
| `ProfessionalController` | Returns authenticated professional's full profile (site, services, legal content). Entry point for dashboard data. |
| `BrandAffiliateController` | Brand views/disconnects their connected affiliates. V2: single-brand constraint means each affiliate has exactly one brand. |
| `BrandAffiliateInviteController` | Brand creates/manages affiliate invitations (single, bulk, CSV import). Affiliates claim or decline via token. Core V2 onboarding flow. |
| `BrandPartnerController` | Affiliate connects to/disconnects from brand partners. V2: simplified to single-brand model. |
| `BrandProfileController` | Brand updates business profile (ABN, industries, affiliate visibility). Used by embedded app wizard. |
| `BrandOnboardingReadinessController` | Returns brand setup checklist (images, Shopify connected, Stripe connected). Gates brand activation. |
| `ShopifyIntegrationController` | Shopify store connection management. V2: core integration — connects brand to their Shopify store, registers order webhooks, creates Storefront API tokens. |
| `StripeConnectController` | Stripe Connect Express onboarding, payment methods, commission top-ups, payout history. V2: required for affiliate payouts (80/20 split). |
| `SubscriptionController` | Manages professional subscription lifecycle (create, change plan, cancel, resume). Billing foundation. |
| `ProfessionalAnalyticsController` | Site visit analytics (visits, clicks, devices, countries, sources). Survives V2 unchanged. |
| `BookingAnalyticsController` | Booking analytics (counts, revenue, customers). Unrelated to commerce — serves Square/Fresha booking data. |
| `ProfessionalCustomerController` | CRUD for customer contacts. Supports lead capture and email subscribers. |
| `ProfessionalUploadController` | Media management (images, videos, brand logos). Handles upload, processing pipeline, and deletion. |
| `ProfessionalSiteController` | Site settings management (subdomain, theme, settings JSON, publish status). |
| `ProfessionalThemeController` | Lists and selects site themes. |
| `ProfessionalServiceController` | CRUD + reorder for services. Integrates with Square/Fresha sync. |
| `ProfessionalServiceCategoryController` | CRUD + reorder for service categories. |
| `ProfessionalSectionBlockController` | Manages site section visibility (gallery, services, shop, booking, bio). |
| `ProfessionalLinkBlockController` | CRUD + reorder for custom link blocks on site. |
| `ProfessionalGalleryController` | Gallery image management (list, reorder, delete). |
| `ProfessionalGoogleBusinessProfileController` | Google Business Profile settings (hours, location, contact). |
| `ProfessionalLegalContentController` | Privacy policy and terms of service management. |
| `NotificationController` | In-app notification listing, read, dismiss. |
| `NotificationEmailPreferenceController` | Per-category email notification opt-in/out. |
| `ProfessionalEmailSubscriptionController` | Lists and exports email subscribers. |
| `ConfirmationPreferenceController` | UI confirmation dialog preferences. |
| `PlanController` | Lists available subscription plans. |
| `AffiliateInviteController` | Non-brand professional views their pending affiliate invitations. |
| `SquareIntegrationController` | Square POS connection for booking/service sync. Unrelated to V2 commerce. |
| `FreshaIntegrationController` | Fresha POS connection for booking/service sync. Unrelated to V2 commerce. |

### Public Site (Unauthenticated)

| Controller | V2 Role |
|-----------|---------|
| `BootstrapController` | Account signup/update. Creates professional + site, applies type defaults, handles affiliate invite claims and brand connections. V2: entry point for Shopify OAuth signup flow. |
| `PublicSiteController` | Serves published site data by subdomain. Cached (95% of traffic). V2: still powers mini-site pages; Hydrogen storefronts fetch brand config separately. |
| `PublicShopifyStorefrontController` | **V2 new.** Serves Shopify Storefront API credentials (domain + token) to Hydrogen storefronts. Enables product display without exposing admin tokens. |
| `PublicBookingController` | Public booking flow (config, services, availability, checkout via Square). Unrelated to V2 commerce. |
| `PublicCustomerLeadController` | Captures lead form submissions with spam detection. Creates/updates customers. |
| `PublicEmailSubscriptionController` | Newsletter signup with customer upsert. |
| `PublicEmailUnsubscribeController` | Token-based email unsubscribe. |
| `PublicMarketingPreferenceController` | Marketing email preference management. |
| `PublicBrandAffiliateInviteController` | Public invite detail retrieval for sharing/claim pages. |
| `PublicSignupAvailabilityController` | Handle/email/phone availability check during signup. |
| `PublicWaitlistController` | Waitlist signup capture. |
| `QrCodeController` | QR code generation and redirect. |
| `SiteVisibilityController` | Toggle site publish status. |
| `AnalyticsController` | Records pageview and click analytics events. |

### Shopify

| Controller | V2 Role |
|-----------|---------|
| `ShopifyAppOAuthController` | **V2 core.** Handles Shopify app install OAuth flow (HMAC validation, token exchange, shop details). Entry point for brand Shopify connection. |

### Staff (Internal Admin)

| Controller | V2 Role |
|-----------|---------|
| `StaffProfessionalController` | Staff browses, searches, manages professionals (status, archive, hard delete). |
| `StaffSiteManagementController` | Staff updates site settings with force-publish override. |
| `StaffCustomerManagementController` | Staff manages professional's customers. |
| `StaffServiceManagementController` | Staff manages services with hard delete. |
| `StaffServiceCategoryManagementController` | Staff manages service categories with hard delete. |
| `StaffLinkBlockManagementController` | Staff manages custom links. |
| `StaffSectionManagementController` | Staff manages section blocks. |
| `StaffSubscriptionManagementController` | Staff manages subscriptions. |
| `StaffAnalyticsController` | Staff views professional's analytics. |
| `StaffMeController` | Returns authenticated staff member info. |
| `StaffNotificationController` | Staff creates global/targeted notifications with email broadcast. |
| `StaffNotificationEmailPolicyController` | Staff manages notification email policies (global and per-professional). |
| `StaffSiteController` | Staff views site data (including unpublished). |

### Webhooks

| Controller | V2 Role |
|-----------|---------|
| `StripeConnectWebhookController` | **V2 core.** Processes Stripe Connect events: account updates, checkout completions, transfer status, payment intents. Drives the commission payout lifecycle. |
| `SquareCatalogWebhookController` | Square catalog webhook. Triggers service sync from Square. Unrelated to V2 commerce. |
| `FreshaCatalogWebhookController` | Fresha catalog webhook. Triggers service sync from Fresha. Unrelated to V2 commerce. |

---

## Services

### Analytics

| Service | V2 Role |
|---------|---------|
| `SiteAnalyticsAggregateService` | Aggregates site visits and clicks into hourly/daily metrics. Survives V2 unchanged. |
| `BookingAnalyticsAggregateService` | Aggregates booking metrics (counts, revenue, customers). Square/Fresha booking data, not commerce. |

### Billing

| Service | V2 Role |
|---------|---------|
| `Entitlements` | Subscription tier checks and feature entitlement resolution. Gates premium features. |

### Cache

| Service | V2 Role |
|---------|---------|
| `SiteCacheService` | Public site payload caching with single-flight locking. Handles 95% of traffic. V2: simplified (no more product payload caching). |
| `ProfessionalCacheService` | Multi-lookup professional caching (by ID, handle, auth ID). Defensive validation prevents stale data. |
| `AnalyticsCacheService` | Visit/click stats caching with version-token invalidation. |
| `CacheKeyGenerator` | Central cache key naming convention. All cache keys flow through here. |

### Store / Commerce

| Service | V2 Role |
|---------|---------|
| `BrandAccessService` | **V2 core.** Role-based access control for brand operations. 5 roles (owner, finance, marketing, analyst, read_only) with capability checks. |
| `BrandPricingService` | **V2 simplified.** Commission rate calculation. V2: only needs `defaultCommissionRate()` and `effectiveCommissionRate()` — per-product overrides now live in Shopify metafields. |
| `SelectionCleanupService` | **V2 updated.** Cleans up affiliate product selections when brand relationship ends. V2: works with `affiliate_product_selections` table (Shopify GIDs). |

### Stripe

| Service | V2 Role |
|---------|---------|
| `StripeConnectService` | **V2 core.** Stripe Connect Express onboarding, payment method collection, wallet top-ups, and manual top-up checkout sessions. Required for affiliate payout flow. |
| `CommissionPayoutService` | **V2 core.** Processes eligible commission payouts with hybrid funding (wallet balance first, card charge for shortfall). Transfers net amount to affiliate via Stripe Connect. Implements hold days and minimum thresholds. |

### Professional

| Service | V2 Role |
|---------|---------|
| `BrandPartnerLinkService` | Manages brand-affiliate connections (up to 1 primary + 3 additional). V2: simplified to single-brand model. |
| `BrandAffiliateInviteService` | Affiliate invitation lifecycle (create, bulk, CSV import, claim, decline). Core V2 onboarding. |
| `BrandOnboardingReadinessService` | Brand activation checklist (images, Shopify, Stripe). V2: gates brand going live. |
| `AccountTypeDefaultsService` | Applies account-type defaults to new professionals and their sites. Handles affiliate-specific overlays. |
| `ConfirmationPreferenceService` | UI confirmation dialog skip preferences. |
| `SectionVisibilityService` | Validates section visibility requirements (gallery needs images, booking needs integration). |

### Media

| Service | V2 Role |
|---------|---------|
| `ImageVariantService` | Generates WebP variants from uploads via GD. Handles content-hashed storage on R2. |
| `VideoVariantService` | Transcodes videos to MP4 + HLS via FFmpeg. Feature-flagged (`SIDEST_VIDEO_UPLOADS_ENABLED`). |

### Notifications

| Service | V2 Role |
|---------|---------|
| `NotificationPublisher` | Core notification publishing with deduplication, optional email dispatch, and retention policies. |
| `CommerceNotificationService` | V2: publishes booking completion notifications. Commission/payout notifications handled by observers. |

### Legal

| Service | V2 Role |
|---------|---------|
| `ProfessionalLegalContentService` | Generates templated privacy policies and terms of service. Allows manual overrides. |

### Public

| Service | V2 Role |
|---------|---------|
| `PublicSiteResolver` | Resolves published sites by subdomain with alias fallback. |

### Integrations (Square)

| Service | V2 Role |
|---------|---------|
| `SquareApiClient` | Square Catalog API client for booking services. Token refresh on 401. |
| `SquareServiceSyncService` | Bidirectional service sync between Square and Comet. |
| `SquareTokenService` | Square OAuth2 token management with auto-refresh. |

### Integrations (Fresha)

| Service | V2 Role |
|---------|---------|
| `FreshaApiClient` | Fresha Partner API client for services, bookings, availability. Token refresh on 401. |
| `FreshaServiceSyncService` | Bidirectional service sync between Fresha and Comet. |
| `FreshaTokenService` | Fresha OAuth2 token management with auto-refresh. |

---

## Jobs

### Analytics

| Job | Queue | V2 Role |
|-----|-------|---------|
| `RebuildSiteHourlyAggregatesJob` | analytics | Rebuilds site visit/click metrics for a professional's hour. |
| `RebuildSiteDailyAggregatesJob` | analytics | Rebuilds site visit/click metrics for a professional's day. |
| `RebuildBookingHourlyAggregatesJob` | analytics | Rebuilds booking metrics for a professional's hour. |
| `RebuildBookingDailyAggregatesJob` | analytics | Rebuilds booking metrics for a professional's day. |

### Cache

| Job | Queue | V2 Role |
|-----|-------|---------|
| `WarmPublicSiteCacheJob` | default | Pre-warms public site cache after publish. Prevents cold-cache latency. |

### Media

| Job | Queue | V2 Role |
|-----|-------|---------|
| `ProcessImageVariantsJob` | images | Generates WebP variants for uploaded images. Updates media state (pending → ready/failed). |
| `ProcessVideoVariantsJob` | videos | Transcodes video to MP4 + HLS. Feature-flagged. Uses dedicated `redis_video` connection. |
| `DeleteMediaArtifactsJob` | videos | Async cleanup of HLS segments and storage artifacts for deleted video media. |

### Notifications

| Job | Queue | V2 Role |
|-----|-------|---------|
| `FanOutBrandStatusNotificationJob` | default | Notifies all affiliates when a brand activates or deactivates. |
| `InviteExpirySweepJob` | default | Marks expired affiliate invites and notifies brand managers. |
| `SendTransactionalNotificationEmailJob` | default | Sends category-specific transactional emails (invites, commissions, payouts). |
| `SendStaffBroadcastEmailsJob` | default | Fans out staff broadcast emails to subscribers (500/batch). |
| `SendStaffBroadcastEmailToSubscriberJob` | mail | Sends individual broadcast email respecting unsubscribe preferences. |
| `SendWeeklyAnalyticsNotificationJob` | default | Weekly sales/commission rollup notification for all active professionals. |

### Shopify

| Job | Queue | V2 Role |
|-----|-------|---------|
| `CreateStorefrontAccessTokenJob` | integrations | **V2 core.** Creates Shopify Storefront API token ("Side St") via GraphQL. Stored in integration metadata. Required for Hydrogen to fetch products. |
| `RegisterShopifyOrderWebhooksJob` | integrations | **V2 core.** Registers `orders/paid` webhook via GraphQL. Required for commission recording. |

### Stripe

| Job | Queue | V2 Role |
|-----|-------|---------|
| `ProcessCommissionPayoutsJob` | default | **V2 core.** Batch-processes all eligible commission payouts via `CommissionPayoutService`. |

### Square / Fresha

| Job | Queue | V2 Role |
|-----|-------|---------|
| `PushServiceToSquareJob` | integrations | Pushes service mutations to Square. Booking integration only. |
| `SyncSquareCatalogDeltaJob` | integrations | Delta/full catalog sync from Square. |
| `PushServiceToFreshaJob` | integrations | Pushes service mutations to Fresha. |
| `SyncFreshaCatalogDeltaJob` | integrations | Delta/full catalog sync from Fresha. |

---

## Console Commands

| Command | V2 Role |
|---------|---------|
| `comet:analytics:backfill-hourly` | Backfills hourly analytics aggregates for trailing N hours. Used after outages or data corrections. |
| `comet:analytics:compact-hourly` | Compacts hourly analytics older than 24h into daily aggregates. Runs on schedule. |
| `comet:analytics:purge-raw-events` | Deletes raw analytics events older than retention window (min 30 days). Aggregate data preserved. |
| `comet:prune-notifications` | Deletes expired notifications older than N days. |
| `comet:purge-soft-deletes` | Hard-deletes soft-deleted rows past retention window (30 days default). |
| `cache:stats` | Shows Redis cache hit rate and memory usage. Diagnostic tool. |

---

## Actions

| Action | V2 Role |
|--------|---------|
| `UpdateSiteAction` | Site update with business logic: subdomain cooldown, theme defaults, settings merge, publish validation. |
| `CreateProfessionalSubscriptionAction` | Creates subscription record with trial support. |
| `ChangeProfessionalPlanAction` | Changes subscription plan. |
| `CancelProfessionalSubscriptionAction` | Cancels subscription at period end. |

---

## Observers

| Observer | V2 Role |
|----------|---------|
| `ProfessionalObserver` | Invalidates professional cache + refreshes legal templates on update/delete/restore. |
| `SiteObserver` | Invalidates site cache, refreshes legal templates, dispatches cache warm on publish. |
| `BlockObserver` | Invalidates site cache when blocks change. |
| `ServiceObserver` | Invalidates cache, re-evaluates booking visibility, dispatches Square/Fresha sync. |
| `CustomerObserver` | Invalidates customer count cache. |
| `SiteMediaObserver` | Re-evaluates gallery section visibility when gallery images change. |
| `ProfessionalIntegrationObserver` | Publishes connect/disconnect notifications, re-evaluates booking visibility. |
| `ProfessionalLegalContentObserver` | Invalidates site cache when legal content changes. |
| `BrandAffiliateInviteObserver` | Publishes invite notifications (received, accepted, declined). |
| `BrandProfileObserver` | Dispatches affiliate notification fan-out when brand status changes. |
| `CommissionLedgerEntryObserver` | **V2 core.** Publishes commission earned/reversed notifications to affiliates. |
| `CommissionPayoutObserver` | **V2 core.** Publishes payout failed/action-required notifications. |

---

## Middleware

| Middleware | V2 Role |
|-----------|---------|
| `VerifySupabaseJwt` | JWT authentication via Supabase JWKS. All authenticated routes. |
| `EnsureSidestStaff` | Staff role gate. |
| `EnsureSidestAdmin` | Admin role gate (subset of staff). |
| `LoadCurrentProfessional` | Loads professional into request context. Checks active/suspended status. |
| `AddPublicCacheHeaders` | Cache-Control headers. Public GET = 15min cache; authenticated = no-store. |
| `SecureHeaders` | Security headers (XFO, CSP, HSTS, etc). |
| `RequirePlan` | Subscription tier gate for premium features. |
| `LogLeadRateLimits` | Logs rate-limited lead submissions to analytics. |

---

## Models by Schema

### core.*

| Model | Table | V2 Role |
|-------|-------|---------|
| `Professional` | `core.professionals` | Central identity. Brands and affiliates are both professionals with different `professional_type`. |
| `Customer` | `core.customers` | Contact/lead records. Supports email subscribers and marketing opt-in. |
| `Service` | `core.services` | Bookable services. Syncs with Square/Fresha. |
| `ServiceCategory` | `core.service_categories` | Groups services for display. |
| `BrandProfile` | `core.brand_profiles` | Brand business details (ABN, industries, status). V2: `brand_status` gates activation. |
| `BrandPartnerLink` | `core.brand_partner_links` | Brand-affiliate relationship. V2: single-brand model (slot 0 = primary). |
| `BrandAffiliateInvite` | `core.brand_affiliate_invites` | Invitation tokens for affiliate onboarding. |
| `ProfessionalIntegration` | `core.professional_integrations` | OAuth connections (Square, Fresha, Shopify). Stores tokens and metadata. |
| `ProfessionalLegalContent` | `core.professional_legal_contents` | Privacy policy and terms (generated + manual). |
| `ProfessionalConfirmationPreference` | `core.professional_confirmation_preferences` | UI confirmation skip preferences. |
| `Site` | `core.sites` | Professional's mini-site config (subdomain, theme, settings, publish state). |
| `Block` | `core.blocks` | Site content blocks (links, sections). |
| `Theme` | `core.themes` | Site theme configs. |
| `SiteMedia` | `core.site_media` | Images and videos with processing states. |
| `SiteSubdomainAlias` | `core.site_subdomain_aliases` | Subdomain redirects after handle changes. |
| `MediaVariant` | `core.media_variants` | Processed media variants (WebP, MP4, HLS, poster). |
| `SidestStaff` | `core.sidest_staff` | Internal staff accounts. |
| `WaitlistSignup` | `core.waitlist_signups` | Pre-launch waitlist entries. |

### retail.* (commerce)

| Model | Table | V2 Role |
|-------|-------|---------|
| `CommissionLedgerEntry` | `retail.commission_ledger_entries` | **V2 core.** Records commission earned per order line. Entries created by `orders/paid` webhook. |
| `CommissionPayout` | `retail.commission_payouts` | **V2 core.** Tracks payout lifecycle (pending → processing → completed/failed). |
| `CommissionPayoutItem` | `retail.commission_payout_items` | Links payouts to ledger entries. |
| `BrandStoreSettings` | `retail.brand_store_settings` | V2: simplified to `default_commission_rate` and `payout_hold_days`. |
| `BrandCommissionTopup` | `retail.brand_commission_topups` | Manual wallet top-ups via Stripe Checkout. |
| `BrandTeamMembership` | `retail.brand_team_memberships` | Brand team roles (owner, finance, marketing, analyst, read_only). |
| `AffiliateProductSelection` | `retail.affiliate_product_selections` | **V2 new.** Affiliate's selected products using `shopify_product_gid` (not local UUID). |

### analytics.*

| Model | Table | V2 Role |
|-------|-------|---------|
| `SiteVisit` | `analytics.site_visits` | Raw page view events. |
| `LinkClick` | `analytics.link_clicks` | Raw link/section click events. |
| `LeadSubmission` | `analytics.lead_submissions` | Raw lead form submissions (includes rate-limited attempts). |

### billing.*

| Model | Table | V2 Role |
|-------|-------|---------|
| `Plan` | `billing.plans` | Subscription plan definitions. |
| `Subscription` | `billing.subscriptions` | Professional subscription records. |

### notifications.*

| Model | Table | V2 Role |
|-------|-------|---------|
| `Notification` | `notifications.notifications` | In-app notification records. |
| `NotificationReceipt` | `notifications.notification_receipts` | Per-professional read/dismiss state. |
| `NotificationEmailPreference` | `notifications.notification_email_preferences` | Per-category email opt-in/out. |
| `NotificationEmailPolicy` | `notifications.notification_email_policies` | Staff-managed email policies (force_on/off). |
| `EmailSubscription` | `notifications.email_subscriptions` | Newsletter subscriptions. |

### Views

| Model | Table | V2 Role |
|-------|-------|---------|
| `PublicSitePayload` | `site.public_site_payload` (VIEW) | Pre-computed public site data. Read by `SiteCacheService`. |
| `AllSiteData` | `site.all_site_data` (VIEW) | Aggregate site data view for staff. |

---

## Queue Architecture

| Queue | Connection | Purpose |
|-------|-----------|---------|
| `default` | redis | General jobs (notifications, cache warm, payouts) |
| `analytics` | redis | Analytics aggregation (hourly/daily rebuilds) |
| `images` | redis | Image variant processing |
| `videos` | redis_video | Video transcoding (dedicated connection to avoid blocking) |
| `integrations` | redis | Square, Fresha, Shopify API calls |
| `mail` | redis | Individual email delivery |

---

## V2 Critical Paths

### 1. Brand Onboarding
```
Shopify OAuth → ShopifyAppOAuthController
  → CreateStorefrontAccessTokenJob (Storefront API token)
  → RegisterShopifyOrderWebhooksJob (orders/paid webhook)
  → BootstrapController (professional + site creation)
  → BrandOnboardingReadinessService (checklist: images, Shopify, Stripe)
  → StripeConnectController@onboard (Stripe Express setup)
```

### 2. Affiliate Onboarding
```
BrandAffiliateInviteController@store (brand sends invite)
  → BrandAffiliateInviteService (creates invite, sends email)
  → PublicBrandAffiliateInviteController@show (affiliate views invite)
  → BrandAffiliateInviteController@claim (affiliate accepts)
  → BrandPartnerLinkService (creates brand-affiliate link)
  → AccountTypeDefaultsService (applies affiliate site defaults)
```

### 3. Commission Flow
```
Shopify orders/paid webhook
  → CommissionLedgerEntry created (status: approved, amount from metafields)
  → CommissionLedgerEntryObserver → notification to affiliate
  → ProcessCommissionPayoutsJob (daily cron)
    → CommissionPayoutService (hybrid funding: wallet + card)
    → Stripe Connect transfer (80% to affiliate, 20% platform fee)
  → CommissionPayoutObserver → notification on failure
```

### 4. Storefront Data
```
Hydrogen storefront requests → PublicShopifyStorefrontController
  → Returns Shopify domain + Storefront API token
  → Hydrogen fetches products directly from Shopify Storefront API
  → No local product data involved
```
