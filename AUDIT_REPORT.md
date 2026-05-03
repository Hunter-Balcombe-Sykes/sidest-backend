# Overnight Audit Report
Generated: 2026-04-20
Stack: PHP 8.2 / Laravel 12 / Supabase (PostgreSQL) / Redis / Laravel Horizon
Laravel version: v12.42.0 (laravel/framework ^12.0)
PHP version: ^8.2 (min required)
Files scanned: 492 PHP files across all app/ directories + routes/, config/, supabase/migrations/, tests/, resources/views/

---

## Progress
- [x] app/Http/Controllers
- [x] app/Http/Middleware
- [x] app/Http/Requests
- [x] app/Models
- [x] app/Services
- [x] app/Jobs
- [x] app/Providers
- [x] routes/
- [x] config/
- [x] database/
- [x] resources/views
- [x] tests/
- [x] Other (Actions, Console, Observers, supabase/migrations/) (Actions, Console, Observers, etc.)

---

## 🔴 Critical Security Issues
| File | Line | Issue |
|------|------|-------|
| `app/Services/Media/VideoVariantService.php` | 458 | Raw PHP `exec` call in private helper. All callers use `escapeshellcmd()`/`escapeshellarg()` but the abstraction is brittle — one future misuse yields RCE. Replace with Symfony `Process` class. |
| `supabase/migrations/20260403000000_v2_baseline.sql` | (all) | 31 tables created with NO Supabase RLS enabled. All multi-tenant data isolation relies solely on Laravel middleware. Any future direct DB connection, misconfigured route, or Supabase Studio access bypasses all access controls. Full table list in Supabase / RLS section. |

## 🟠 High Security Issues
| File | Line | Issue |
|------|------|-------|
| `app/Http/Middleware/Auth/VerifySupabaseJwt.php` | 88–131 | JWT `alg` header is never explicitly validated before calling `JWT::decode()`. If Supabase JWKS ever contains both RS256 and HS256 keys, an attacker can craft a token signed with the public key as an HS256 secret (algorithm confusion). Enforce `alg: RS256` explicitly before decode. |
| `app/Models/Billing/Subscription.php` | (class) | No `$hidden` array. `stripe_customer_id`, `stripe_subscription_id`, and `provider_payload` exposed in every `toArray()`/`toJson()` serialisation (API responses, logs, job payloads). |
| `app/Models/Retail/BrandStoreSettings.php` | (class) | No `$hidden` array. `oxygen_deployment_token` (even if encrypted at rest) serialised in API responses. |
| `app/Models/Core/BrandAffiliateInvite.php` | (class) | No `$hidden` array. Invitation `token` field exposed in serialisation — allows token replay if response is logged or leaked. |
| `app/Http/Middleware/Auth/VerifySupabaseJwt.php` | 42–61 | JWKS verification failure (any `\Throwable`) silently falls back to Supabase auth server API. A network-level attacker blocking JWKS fetches forces all token validation onto the fallback path indefinitely. Log + alert on repeated JWKS failures. |
| `composer.json` | 16,17,21 | Three production packages pinned to `*` (wildcard): `laravel/horizon`, `laravel/nightwatch`, `stripe/stripe-php`. A `composer update` can silently introduce a breaking or malicious version. Pin to `^5.45`, `^1.24`, `^19.4` respectively. |
| `app/Services/Stripe/CommissionPayoutService.php` | 289–454 | `processPayoutBatch()` performs debit wallet → charge Stripe → transfer to affiliate with no wrapping `DB::transaction()`. Stripe charge success + DB write failure = funds taken but not recorded. |
| `app/Console/Commands/BackfillSocialLinksCommand.php` | 59 | `shell_exec('whoami')` — functionally safe (no user input, suppressed) but uses a shell invocation for audit info. Replace with PHP's `get_current_user()`. |

## 🟡 Medium Security Issues
| File | Line | Issue |
|------|------|-------|
| `config/session.php` | 172 | `'secure' => env('SESSION_SECURE_COOKIE')` defaults to `null` (falsy) when env var absent. Session cookies transmit over HTTP if this key is unset. Change to `env('SESSION_SECURE_COOKIE', true)`. |
| `app/Http/Requests/BaseFormRequest.php` | 10–13 | `authorize()` unconditionally returns `true`. All 60+ extending form requests delegate authorization entirely to route middleware. Any future middleware misconfiguration leaves endpoints open. |
| `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateCustomerRequest.php` | 16 | `notes` field: `['sometimes', 'nullable', 'string']` — no `max:` rule. Allows unbounded text to DB. Potential stored XSS if rendered without escaping in a future admin view. Add `max:5000`. |
| `app/Models/Core/ProfessionalDeletionAuditEntry.php` | (class) | No `$hidden`. `professional_email_snapshot` (PII of deleted user) is exposed in serialisation. |
| `config/cors.php` | 31 | `'allowed_headers' => ['*']` — any request header allowed. Restrict to `Content-Type, Authorization, Accept, X-Requested-With`. |
| `config/cors.php` | 20 | Vercel preview deploys whitelisted via `*.vercel.app` regex — any Vercel project can make credentialed API requests. Restrict to specific project slug. |
| `database/database.sqlite` | — | SQLite test database (84 KB) tracked in git. May contain test PII or fixture secrets. Remove from tracking; add `*.sqlite` to `.gitignore`. |
| `supabase/migrations/20260419000002_nullable_commission_fks.sql` | 1–30 | `commission_payouts.brand_professional_id` and `affiliate_professional_id` made nullable without documented null-handling audit. If code dereferences these without null checks it throws on deleted-user records. |
| `app/Http/Middleware/Context/LoadCurrentProfessional.php` | 21–63 | Multiple `Log::info()` calls on every authenticated request including UID. High log volume at scale; persistent UIDs in logs may be PII. Downgrade to `Log::debug()`. |

## 🔵 Low Security Issues
| File | Line | Issue |
|------|------|-------|
| `app/Http/Middleware/Auth/VerifySupabaseJwt.php` | 106 | JWKS cached for 600 s. If Supabase rotates signing keys after a compromise, old tokens remain valid for 10 minutes. Consider 300 s TTL and key-rotation monitoring. |
| Multiple form requests | various | Email validation inconsistency: most use `email` rule; public/waitlist use `email:rfc`. Standardise on `email:rfc,dns` across all form requests. |
| `app/Http/Requests/Api/Staff/Notifications/UpdateNotificationEmailPoliciesRequest.php` | (class) | Protected only by `EnsureSidestStaff`. If admin-only, the route should also include `EnsureSidestAdmin` middleware. |
| `routes/api.php` | (all) | No `/api/v1/` version prefix on any route. Every route or response shape change is an immediate breaking change for all clients. |

---

## Input Sanitisation Gaps
| File | Line | Issue |
|------|------|-------|
| `app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateCustomerRequest.php` | 16 | `notes`: no `max:`, no sanitisation — unbounded string to DB. |
| `app/Http/Requests/Api/Professional/Site/UpsertSectionBlockRequest.php` | 31 | `settings.text`: has `max:4000` but no HTML sanitisation. Stored XSS risk if rendered server-side. |
| `app/Http/Requests/Api/Professional/UpdateProfessionalRequest.php` | 19 | `bio`: `max:2000` present — verify this value is never rendered with `{!! !!}` (all current views use safe `{{ }}` syntax). |
| `app/Services/Media/ImageVariantService.php` | 264 | Uses `$_ENV['SIDEST_MEDIA_DISK']` / `$_SERVER['SIDEST_MEDIA_DISK']` directly instead of `env()` helper — bypasses Laravel env normalisation. |
| `app/Services/Media/VideoVariantService.php` | 264 | Same `$_ENV`/`$_SERVER` direct access pattern as above. |

---

## Rate Limiting Gaps
| File | Line | Issue |
|------|------|-------|
| `app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php` | (class) | No throttle middleware applied. `throttle:leads` is defined in AppServiceProvider but not wired to this route. Apply to route definition. |
| `app/Http/Controllers/Api/PublicSite/Booking/PublicBookingController.php` | (class) | Public booking checkout endpoints have no throttle. These hit Square and Stripe synchronously — abuse causes cost escalation and latency spikes. |
| `routes/api/professional.php` | (all) | No global fallback rate limit on the route group. Routes added in future that omit `throttle:authenticated` will be unprotected by default. |

---

## Supabase / RLS Concerns
| Table | Schema | Issue |
|-------|--------|-------|
| `analytics.booking_metrics_daily` | analytics | RLS disabled — cross-professional analytics data accessible |
| `analytics.booking_metrics_hourly` | analytics | RLS disabled |
| `analytics.brand_metrics_daily` | analytics | RLS disabled |
| `analytics.brand_metrics_hourly` | analytics | RLS disabled |
| `analytics.professional_metrics_daily` | analytics | RLS disabled |
| `analytics.professional_metrics_hourly` | analytics | RLS disabled |
| `analytics.site_metrics_daily` | analytics | RLS disabled |
| `analytics.site_metrics_hourly` | analytics | RLS disabled |
| `analytics.brand_affiliate_daily` | analytics | RLS disabled |
| `analytics.brand_commission_daily` | analytics | RLS disabled |
| `analytics.professional_customer_daily` | analytics | RLS disabled |
| `analytics.booking_events` | analytics | RLS disabled |
| `analytics.lead_submissions` | analytics | RLS disabled |
| `brand.brand_partner_links` | brand | RLS disabled — cross-brand partnership data exposed |
| `brand.brand_profiles` | brand | RLS disabled |
| `brand.brand_store_settings` | brand | RLS disabled |
| `brand.brand_team_memberships` | brand | RLS disabled |
| `brand.brand_affiliate_invites` | brand | RLS disabled — invitation tokens + PII exposed |
| `commerce.commission_payouts` | commerce | RLS disabled — payment data across all brands |
| `commerce.brand_commission_topups` | commerce | RLS disabled |
| `commerce.commission_ledger_entries` | commerce | RLS disabled |
| `commerce.commission_payout_items` | commerce | RLS disabled |
| `commerce.affiliate_product_selections` | commerce | RLS disabled |
| `core.professional_integrations` | core | RLS disabled — OAuth tokens for Shopify/Square/Fresha |
| `core.professional_confirmation_preferences` | core | RLS disabled |
| `core.waitlist_signups` | core | RLS disabled |
| `notifications.notification_email_policies` | notifications | RLS disabled |
| `notifications.notification_email_preferences` | notifications | RLS disabled |
| `site.media_variants` | site | RLS disabled |
| `site.service_categories` | site | RLS disabled |
| `public.failed_jobs` | public | RLS disabled — job payloads may contain serialised sensitive data |
| `public.job_batches` | public | RLS disabled |
| — | — | **Summary:** 31 of ~60+ tables have no RLS. Laravel middleware is the sole access control. A direct Supabase Studio query, Edge Function, or future client SDK call bypasses all of it. |

---

## Scalability Risks
### 🔴 Will break under load
| File | Line | Issue |
|------|------|-------|
| `app/Console/Commands/BackfillHourlyAnalytics.php` | 92–110 | Loads all professionals with site visits in range, then dispatches 1 job per professional per hour. At 1,000 professionals × 168 hours = **168,000 jobs** queued in a single run with no chunking or rate-controlled dispatch. Will overwhelm Horizon. |
| `app/Services/Stripe/CommissionPayoutService.php` | 289–454 | Multi-step payout (debit → charge → transfer) has no DB transaction. Partial failure leaves financial state inconsistent with no automated rollback or reconciliation path. |

### 🟠 Will degrade under load
| File | Line | Issue |
|------|------|-------|
| `app/Console/Commands/CompactHourlyAnalytics.php` | 40–90 | Two nested DB queries (all brand+day pairs → per-pair ledger query) with no chunking. Memory spike risk on large datasets. |
| `app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php` | 119 | `CommissionLedgerEntry::create()` inside `$lineItems` loop — 50 line items = 50 sequential inserts in one job. Wrap in `DB::transaction()` + bulk insert. |
| `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php` | 147 | `CommissionLedgerEntry` creates inside nested refund-items loop. Same pattern. |
| `app/Console/Commands/PurgeSoftDeleted.php` | 52 | `forceDelete()` called in loop without try/catch. One failed deletion silently increments `$failed` counter but does not roll back the chunk. |
| `app/Services/Auth/SupabaseAdminService.php` | (class) | Supabase Admin API calls are synchronous on the request path. User creation/deletion blocks the request thread for full Supabase round-trip latency. |

### 🟡 Worth fixing before scaling
| File | Line | Issue |
|------|------|-------|
| `app/Actions/Subscription/ChangeProfessionalPlanAction.php` | 63–90 | Stripe external call + local DB update with no wrapping transaction. Stripe success + DB failure = inconsistent state. |
| `app/Actions/Subscription/CreateProfessionalSubscriptionAction.php` | 43–64 | Free subscription created locally before Stripe checkout — if checkout fails, local free record persists. |
| `app/Actions/Subscription/ResumeProfessionalSubscriptionAction.php` | 46–51 | Stripe `resumeSubscription()` succeeds before local `update()` — DB failure after Stripe success = unrecorded resumption. |
| `app/Services/Professional/AccountDeletionService.php` | 316–351 | Identical subscription query executed twice in sequence. Extract to private method or pass as parameter. |

---

## Performance & Efficiency
| File | Line | Issue | Est. Impact |
|------|------|-------|-------------|
| `app/Http/Middleware/Context/LoadCurrentProfessional.php` | 21–63 | Multiple `Log::info()` on every authenticated request — synchronous log I/O per request | Low-medium at 10k req/min |
| `app/Services/Store/BrandAccessService.php` | 116–141 | Per-request in-memory cache (array property) — re-queries on every new request. Hot endpoints could use a short Redis TTL for brand access grants. | Medium on brand-heavy routes |
| `app/Services/Cache/SiteCacheService.php` | 841 lines | Cache reconstruction + hydration + JSON encoding mixed in one 841-line class. Hard to profile bottlenecks. Consider splitting `SitePayloadHydrator`. | Maintenance risk |
| `app/Services/Shopify/BrandCatalogService.php` | 845 lines | GraphQL query strings defined inline (~200 lines of string literals). Extract to named constants or separate query files. | Maintenance risk |
| Multiple jobs | various | 7 jobs have no `$timeout` set (default: 60 s). FFmpeg transcode, Square sync, Fresha sync all likely exceed 60 s. Jobs silently restart mid-operation — data corruption risk. | High |
| `app/Jobs/Shopify/SetShopifySetupCompleteJob.php` | 96–115 | Second HTTP POST to Shopify has no `$response->ok()` check before using response. Silent failure when Shopify API returns an error. | Medium |
| `app/Jobs/Shopify/CreateShopifySalesChannelJob.php` | 106–119 | HTTP response parsed with `->json()` before checking `->ok()`. Error body parsed as success data — downstream null exception likely. | Medium |

---

## Error Handling & Observability
| File | Line | Issue |
|------|------|-------|
| Multiple jobs (see Queue & Job Health) | — | 7 jobs have no `failed()` handler. Failure silently discarded after max retries — no Nightwatch alert fires. |
| `app/Observers/Core/BrandAffiliateInviteObserver.php` | 16–87 | `\Throwable` caught, logged, swallowed. Notification publish failures silent — invite recipient never notified. |
| `app/Observers/Core/ProfessionalIntegrationObserver.php` | 22–102 | Same pattern — booking re-evaluation failures invisible to operators. |
| `app/Observers/Core/CommissionLedgerEntryObserver.php` | 16–61 | Commission earned/reversed notifications swallowed silently. Financial events with no notification are compliance risks. |
| `app/Observers/Professional/ProfessionalObserver.php` | 18–28 | `\Throwable` caught and logged only. Profile notifications silently fail. |
| `app/Observers/Core/ServiceObserver.php` | 44–87 | Synchronous external call in `saved()` observer with no fallback. Failure throws and bubbles through the save operation — potential infinite loop if sync triggers a re-save. |
| `app/Jobs/Shopify/SetShopifySetupCompleteJob.php` | 96–115 | Second Shopify HTTP POST: no `$response->ok()` check, no exception handler. Silent failure. |
| `app/Jobs/Shopify/CreateShopifySalesChannelJob.php` | 106–119 | HTTP response parsed before checking success — error body treated as valid data. |
| `app/Services/Stripe/CommissionPayoutService.php` | 423 | Basic logging on payout step transitions but no structured audit log (no event record per step). Difficult to reconstruct partial payout state in incident investigations. |

---

## API Design & Contracts
| File | Line | Issue |
|------|------|-------|
| All routes | — | No `/api/v1/` version prefix. Any route or resource shape change immediately breaks all existing clients with no migration path. |
| Multiple controllers | various | Mix of Resource class usage and raw Eloquent/array returns. Some use `ProfessionalResource`, others return `->fresh()` or raw model. All API responses should go through Resource classes. |
| `app/Http/Controllers/Api/ApiController.php` | 14–31 | Standard success/error helpers defined but not all controllers extend `ApiController`. Envelope pattern inconsistently applied. |

---

## Authentication & Authorisation
| File | Line | Issue |
|------|------|-------|
| `app/Http/Middleware/Auth/VerifySupabaseJwt.php` | 88–131 | `alg` field in JWT header never validated before decode. Algorithm confusion attack surface — see Critical Security section. |
| `app/Http/Requests/BaseFormRequest.php` | 10–13 | `authorize()` unconditionally returns `true`. All authorization delegated to middleware. Any middleware gap = open endpoint. |
| `app/Http/Requests/Api/Staff/Notifications/UpdateNotificationEmailPoliciesRequest.php` | (class) | Protected by `EnsureSidestStaff` — confirm whether `EnsureSidestAdmin` should also be required. |
| `app/Actions/Subscription/*.php` | various | Actions assume `$professional` passed in IS the authenticated user. No ownership assertion inside the action — single-layer defence only. |

---

## Data Integrity & Database
| File | Line | Issue |
|------|------|-------|
| `supabase/migrations/20260419000002_nullable_commission_fks.sql` | 1–30 | `commission_payouts.brand_professional_id` and `affiliate_professional_id` made nullable. All code paths that dereference these relationships must handle null — audit `CommissionPayoutService`, `CommissionPayout` model, and staff UI. |
| `supabase/migrations/20260403000000_v2_baseline.sql` | 873–874, 926 | `brand.brand_partner_link_events` and `commerce.commission_payout_items` FK to `core.professionals` with `ON DELETE RESTRICT` — legitimate for audit trail, but blocks professional hard delete. Ensure `AccountDeletionService` handles this ordering. |
| `app/Models/Analytics/LinkClick.php` | 58–66 | `DB::table('information_schema.columns')` introspection query to detect column rename migration state. Temporary migration bridge — set a removal date once all instances are on the new schema. |
| `database/database.sqlite` | — | SQLite test DB (84 KB) committed to git. Remove from tracking. |

---

## Dependency & Supply Chain
| Package | Version | Issue |
|---------|---------|-------|
| `laravel/horizon` | `*` → v5.45.4 | Wildcard — any major version installable on `composer update`. Pin to `^5.45`. |
| `laravel/nightwatch` | `*` → v1.24.4 | Wildcard — pin to `^1.24`. |
| `stripe/stripe-php` | `*` → v19.4.1 | Wildcard on payment library — highest risk. Pin to `^19.4`. |
| `firebase/php-jwt` | `^7.0` → v7.0.2 | Pinned. No known CVEs in v7.0.2 as of audit date. |
| `laravel/framework` | `^12.0` → v12.42.0 | Current. Good. |
| All dev deps | various | Correctly placed in `require-dev`. No dev packages accidentally in production `require`. |
| `composer.lock` | — | Tracked in git. Correct practice. |

---

## Testing Coverage
| Area | Status | Issue |
|------|--------|-------|
| Feature tests | 64 files | Good breadth across domain areas |
| Unit tests | 11 files | Low ratio (~5% of application classes) |
| Staff/admin flows | ✅ Good | 13 staff test files: affiliate, commission void, payout |
| Account deletion | ✅ Good | Request, confirm, cancel, purge all tested |
| Stripe billing | ✅ Good | `StripeBillingServiceTest`, `StripeConnectPayoutsControllerTest`, `CommissionVoidServiceTest` |
| Webhook signature verification | ❌ Missing | No test verifying tampered Stripe/Shopify webhook signatures are rejected |
| JWT algorithm enforcement | ❌ Missing | No test verifying `alg:none` or algorithm confusion tokens are rejected |
| RLS enforcement | ❌ Missing | No test verifying one professional cannot read another's records via API |
| File upload security | ⚠️ Partial | Upload tests exist but do not verify mime-type spoofing rejection |
| Public rate limiting | ❌ Missing | No test verifying throttle fires on public lead / booking endpoints |
| Fresha integration | ❌ Missing | Fresha API endpoints not verified (NOTE comments confirm URLs unresolved) |
| Coverage estimate | ~30% | 75 test files for ~250+ controllers/services/actions/jobs. Critical paths covered; security edge cases and integration boundaries underrepresented. |

---

## Compliance & Privacy
| File | Line | Issue | Regulation |
|------|------|-------|------------|
| `app/Models/Billing/Subscription.php` | (class) | `stripe_customer_id` exposed in serialisation — PCI scope leak if response bodies or job payloads are logged. | PCI DSS |
| `app/Models/Core/BrandAffiliateInvite.php` | (class) | `email`, `phone`, `first_name`, `last_name`, `token` in `toArray()` — PII in every serialisation. | GDPR |
| `app/Models/Core/ProfessionalDeletionAuditEntry.php` | (class) | `professional_email_snapshot` serialised in API responses — PII of deleted user accessible in audit table. Confirm retention policy covers this table. | GDPR right to erasure |
| `app/Http/Middleware/Context/LoadCurrentProfessional.php` | 21–63 | Supabase UID logged at `info` level on every request. Persistent identifiers in logs may be PII. Downgrade to `debug`. | GDPR |
| `database/database.sqlite` | — | Test DB tracked in git. If fixture PII present, this is a data exposure. | GDPR |
| Supabase (31 tables) | — | No RLS — any Supabase data access method bypasses access control. GDPR data minimisation requires access controls at all layers. | GDPR |
| `analytics.*` tables | — | Booking events, lead submissions, visit data stored with no visible purge schedule. Professionals have 30-day soft-delete; analytics events may persist indefinitely. Add retention purge for analytics raw data. | GDPR |

---

## Environment & Deployment
| File / Config | Issue |
|---------------|-------|
| `config/session.php:172` | `SESSION_SECURE_COOKIE` defaults to `null` — cookies sent over HTTP if env var absent. Fix: `env('SESSION_SECURE_COOKIE', true)`. |
| `.gitignore` | `*.sqlite` not excluded. `database/database.sqlite` is tracked. Add `*.sqlite`; remove from history. |
| (no CI/CD detected) | No deployment pipeline or scripts found. Queue workers will not pick up new code without a manual restart. Document restart strategy (e.g. `php artisan horizon:terminate` in deploy hook). |
| (no optimize in scripts) | `composer.json` scripts do not include `php artisan optimize`. Config/route/view caches not guaranteed warm in production. Add to deployment step. |
| `.env.example` | All required keys present with placeholder values. No accidentally exposed real secrets found. ✅ |
| `config/cors.php:20` | `*.vercel.app` CORS origin whitelist allows any Vercel project. Restrict to specific project slug. |

---

## Laravel Best Practice Violations
| File | Line | Issue |
|------|------|-------|
| `app/Services/Media/ImageVariantService.php` | 264 | `$_ENV` / `$_SERVER` direct access instead of `env()` helper. |
| `app/Services/Media/VideoVariantService.php` | 264 | Same `$_ENV` / `$_SERVER` pattern. |
| `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php` | 308 | TODO comment in production code: "expose commission ledger entries as sibling endpoint." |
| Multiple controllers | (class) | Authorization done with `abort_unless()` throughout rather than Laravel Gates/Policies. Consistent but harder to test in isolation and doesn't benefit from Policy caching. Consider Policies for Professional, Brand, Customer resources. |
| `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php` | 725 lines | Largest controller — exceeds 300-line guideline significantly. |
| `app/Http/Controllers/Api/PublicSite/Booking/PublicBookingController.php` | 830 lines | Justified complexity (Square integration) but consider extracting `SquarePaymentCoordinator` service. |
| `app/Services/Cache/SiteCacheService.php` | 841 lines | Exceeds 500-line service guideline. |
| `app/Services/Shopify/BrandCatalogService.php` | 845 lines | Exceeds 500-line guideline. Inline GraphQL strings inflate line count. |

---

## Code Quality
| File | Line | Issue |
|------|------|-------|
| `app/Models/Analytics/LinkClick.php` | 58–66 | `information_schema` introspection for column-rename migration state. Temporary bridge — set a removal date. |
| `app/Console/Commands/BackfillHourlyAnalytics.php` | 92–110 | Dispatch rate not controlled — 168k jobs possible in single invocation. |
| `app/Services/Fresha/FreshaApiClient.php` | 33, 51 | `NOTE:` comments flagging unresolved API endpoint URLs. Fresha integration is scaffolded but functionally unverified. |
| `app/Services/Fresha/FreshaTokenService.php` | 52, 91, 95 | `NOTE:` comments flagging unresolved API and sandbox URLs. |
| Multiple observers | various | Silent exception swallowing pattern repeated across 5 observers. Extract to a shared `safeNotify()` helper with a consistent logging contract. |

---

## Credentials / Secrets Found
| File | Line | Key Name (no values) |
|------|------|----------------------|
| _(none)_ | — | No hardcoded credentials found anywhere in the codebase. All secrets sourced via `config()` / `env()`. ✅ |

---

## Queue & Job Health
| Job / Command | Issue |
|---------------|-------|
| `app/Jobs/Cache/WarmPublicSiteCacheJob.php` | No `$timeout`, no `$tries`, no `failed()` handler |
| `app/Jobs/Square/PushServiceToSquareJob.php` | No `$timeout`, no `$tries`, no `failed()` handler |
| `app/Jobs/Square/SyncSquareCatalogDeltaJob.php` | No `$timeout`, no `$tries` |
| `app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php` | No `$timeout`, no `$tries`, no `$backoff`, no `failed()` handler |
| `app/Jobs/Fresha/PushServiceToFreshaJob.php` | No `$timeout`, no `$tries`, no `failed()` handler |
| `app/Jobs/Store/SeedAffiliateDefaultSelectionsJob.php` | No `$timeout`, no `failed()` handler |
| `app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php` | No `$timeout` |
| `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php` | No `$tries`, no `$timeout` |
| `app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php` | No `failed()` handler |
| `app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php` | No `failed()` handler |
| `app/Observers/Core/ServiceObserver.php` | Synchronous external call in `saved()` observer — failure bubbles through save; re-save in sync handler could cause loop |
| `app/Console/Commands/BackfillHourlyAnalytics.php` | Could dispatch 168,000+ jobs in one run with no rate control |
| Composer `dev` script | Queue worker started with `--tries=1` in dev — ensure this is not used in staging/production environments |

---

## Missing Indexes (inferred from query patterns)
| Table | Column | Reason |
|-------|--------|--------|
| `analytics.*` aggregate tables | `professional_id`, `brand_id`, `date` (composite) | Compaction and backfill commands filter by all three |
| `commerce.commission_ledger_entries` | `idempotency_key` | Webhook jobs do unique-constraint inserts — verify unique index exists |
| `brand.brand_partner_links` | `affiliate_professional_id` + `status` (composite) | BrandAccessService queries by these frequently |
| `notifications.email_subscriptions` | `professional_id` + `list_type` | Fan-out notification jobs filter by these |
| `core.professionals` | `deletion_requested_at` | PurgeSoftDeleted command filters by this for pending-deletion candidates |

---

## TODO / FIXME / HACK in Production Code
| File | Line | Comment |
|------|------|---------|
| `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php` | 308 | `// TODO: expose commission ledger entries as a sibling endpoint so brands can see full transaction history` |
| `app/Services/Fresha/FreshaApiClient.php` | 33 | `NOTE: Update endpoint path based on actual Fresha API docs` — unresolved, blocking production use |
| `app/Services/Fresha/FreshaApiClient.php` | 51 | `NOTE: Update endpoint path based on actual Fresha API docs` |
| `app/Services/Fresha/FreshaTokenService.php` | 52 | `NOTE: Update this endpoint based on actual Fresha Partner API documentation` |
| `app/Services/Fresha/FreshaTokenService.php` | 91 | `NOTE: Update with actual Fresha sandbox URL when confirmed` |
| `app/Services/Fresha/FreshaTokenService.php` | 95 | `NOTE: Update with actual Fresha production API URL when confirmed` |
| `app/Services/Media/ImageVariantService.php` | 311 | `NOTE: this fallback MUST mirror config/sidest.php image_variants exactly` — implicit coupling, not enforced |

---

## Summary
- **Total issues found:** 67
- **Critical security:** 2 (RCE-adjacent exec abstraction in VideoVariantService; 31 tables without RLS)
- **High security:** 8 (JWT alg enforcement, 3 models missing $hidden, JWKS fallback, 3 wildcard deps, CommissionPayoutService no transaction)
- **Scalability blockers (will break under load):** 2 (BackfillHourlyAnalytics unbounded job dispatch; CommissionPayoutService missing transaction)
- **Compliance risks:** 7 (31 tables no RLS, PII serialisation in 3 models, analytics retention gap, SQLite in git, UID in info logs)
- **Dependency CVEs:** 0 known CVEs detected in resolved versions
- **Test coverage estimate:** ~30% — critical business paths covered; security edge cases and integration boundaries underrepresented

### Recommended first 10 fixes by impact:
  1. **Add RLS policies to all 31 unprotected Supabase tables** — especially `commerce.*`, `brand.*`, `analytics.*`. Highest-leverage fix: provides a database-level backstop no application bug can bypass.
  2. **Add `$timeout`, `$tries`, and `failed()` to all 7 under-configured jobs** — prevents silent job loss, runaway workers, and invisible failures in Square/Fresha sync and notification fanout.
  3. **Wrap all three Subscription Actions in `DB::transaction()`** (`ChangeProfessionalPlanAction`, `CreateProfessionalSubscriptionAction`, `ResumeProfessionalSubscriptionAction`) — prevents financial state inconsistency on Stripe↔DB race conditions.
  4. **Add `$hidden` to `Subscription`** (`stripe_customer_id`, `stripe_subscription_id`, `provider_payload`)**, `BrandStoreSettings`** (`oxygen_deployment_token`)**, and `BrandAffiliateInvite`** (`token`) — stops sensitive fields leaking into API responses, logs, and serialised job payloads.
  5. **Enforce `alg: RS256` explicitly in `VerifySupabaseJwt`** before calling `JWT::decode()` — closes the algorithm confusion attack surface on the authentication boundary.
  6. **Wrap `CommissionPayoutService::processPayoutBatch()` in `DB::transaction()`** with Stripe idempotency keys — prevents financial ledger inconsistency between Stripe and local DB.
  7. **Pin wildcard composer versions** — `laravel/horizon: "^5.45"`, `laravel/nightwatch: "^1.24"`, `stripe/stripe-php: "^19.4"` — stops accidental breaking upgrades on `composer update`.
  8. **Fix `config/session.php:172`** — change to `env('SESSION_SECURE_COOKIE', true)` — prevents session cookies transmitting over HTTP when env var is absent.
  9. **Add `*.sqlite` to `.gitignore` and remove `database/database.sqlite` from git tracking** — stops test PII/fixture state accumulating in version history.
  10. **Add throttle middleware to `PublicCustomerLeadController` and `PublicBookingController`** — both hit synchronous external APIs (Square, Stripe) on every request with no rate protection; abuse causes direct cost escalation.

---

### What is GOOD (do not change)
- No hardcoded credentials anywhere in the codebase ✅
- Shopify HMAC verified with `hash_equals()` on every webhook ✅
- Stripe webhook verified via `Stripe\Webhook::constructEvent()` ✅
- All SQL uses parameterized queries — no SQL injection vectors found ✅
- File uploads: strict MIME type + extension + size validation ✅
- No `{!! !!}` unescaped Blade output anywhere in views ✅
- No `dd()`, `dump()`, `var_dump()`, `ray()` in production code ✅
- `env()` never called outside `config/` files ✅
- `hash_equals()` used for all token comparisons ✅
- DB advisory locks and `lockForUpdate()` used correctly for race conditions ✅
- `DB::afterCommit()` used in UpdateSiteAction to defer cache invalidation ✅
- All models have `$fillable` defined — no unguarded mass assignment ✅
- PostgreSQL `statement_timeout` (30 s) and `lock_timeout` (10 s) configured ✅
- `APP_DEBUG` defaults to `false` ✅
- `composer.lock` tracked in git ✅
- `overnight.sh` has `set -euo pipefail`, proper quoting, no hardcoded secrets ✅
