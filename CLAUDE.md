# Claude Code Instructions

## Project Identity

**Partna** — Laravel 12 + Supabase + PostgreSQL multi-tenant affiliate SaaS platform.
For full business context, domain model, and entity relationships, read `AI_CONTEXT.md`.
For API endpoint reference, read `docs/api.md`.
Cross-project rules (git workflow, cost discipline, pre-agent gate) live in `../CLAUDE.md`.

**Git reminder (shared repo — primary dev is someone else):** Always `git fetch && git pull` + `git log --oneline -10` before any work. Work on a feature branch. Never push without permission.

## Environments

| Env | Git branch | Backend URL | Supabase project ref |
|-----|------------|-------------|----------------------|
| **Production** | `production` | `https://api.partna.au` | `edplucmvkcnokyygxqsb` |
| **Development** | `development` | `https://dev-api.partna.au` | `glncumufgaqcmqhzwrxm` |

Feature branches off `development`. PR → merge into `development` → promote to `production` to deploy prod.

**Push semantics** — when the user says "push to supabase dev/prod", the action is:
1. `supabase link --project-ref <matching-ref>` (interactive; user runs with `!` prefix)
2. `supabase db push --dry-run`
3. `supabase db push`

Dev (`glncumufgaqcmqhzwrxm`) — iterate freely. Prod (`edplucmvkcnokyygxqsb`) — always confirm before step 3 and show dry-run output first. Re-link required when switching projects.

**Fresh prod DB caveat:** the v2 baseline creates `app_backend` as `NOLOGIN` (fail-closed). After pushing migrations to a brand-new Supabase project, run `ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret>'` in the SQL editor before the app can connect. Laravel Cloud `DB_USERNAME` must be `app_backend.<project_ref>` (Supavisor tenant prefix), port 5432 (session mode).

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.2, Laravel 12 |
| Database | PostgreSQL (Supabase-hosted), schemas: `public`, `core`, `site`, `brand`, `commerce`, `notifications`, `analytics`, `billing` |
| Auth | Supabase Auth (JWT) — no backend login; frontend forwards token |
| Cache/Queue | Redis (DB 0 = cache, DB 1 = sessions, DB 2 = queue) |
| Jobs | Laravel Horizon (Redis-backed), separate `redis_video` connection for video processing |
| Frontend | Vite 7, Tailwind CSS 4 (minimal — mostly API backend) |
| Testing | Pest 4 + PHPUnit, Mockery, SQLite in-memory for tests |
| Monitoring | Laravel Nightwatch (exceptions, slow routes/jobs/commands/tasks) |
| Integrations | Shopify, Square, Fresha, Stripe |

## MCP servers (this project only — see `../CLAUDE.md` for full catalog)

| MCP | Auto-trigger |
|-----|-------------|
| **laravel-boost** | Routes, artisan, tinker, config, docs, browser-logs. **NOT server logs / errors** — see "Laravel logs" below. |
| **supabase** | Any DB query, migration, schema check |

## Laravel logs — use Cloud CLI, NEVER boost

**The real logs live in Laravel Cloud.** Local `storage/logs/laravel.log` and `laravel-boost`'s log tools (`read-log-entries`, `last-error`) show **stale test-suite output** — they are useless and misleading for any real debugging. Overrides anything the boost-guidelines section below says about logs.

```bash
cloud env:logs partna development --tail 50              # DEFAULT — quick check
cloud env:logs partna development --minutes 15           # recent window
cloud env:logs partna development --live                 # live tail (background it)
cloud env:logs partna development --hours 1 --json       # structured, pipe to jq for filtering
cloud env:logs partna production --tail 50               # prod — confirm with user before pulling
```

CLI path: `/Users/tobiasbalcombeehrlich/.composer/vendor/bin/cloud` (already authenticated). App = `partna`. Environments = `development` (default) and `production` (gated).

**FORBIDDEN tools — never call:**
- `mcp__laravel-boost__read-log-entries`
- `mcp__laravel-boost__last-error`

Boost stays useful for `tinker`, `database-query`, `database-schema`, `list-routes`, `application-info`, `search-docs`, `browser-logs`, `list-artisan-commands`. Just not server logs.

**Debugging discipline — check logs FIRST.** When the user reports something not working, the first action — before reading code — is:

```
cloud env:logs partna development --minutes 10
```

See what the server is actually saying. THEN form a hypothesis. The user reads these logs constantly; reach for them automatically, not when asked.

## Architecture Rules

### Database — Supabase Only
- **Never create Laravel migration files.** A composer guard (`guard:no-laravel-migrations`) will reject them.
- All schema changes go in `supabase/migrations/` as raw SQL files.
- PostgreSQL uses multiple schemas set via `search_path`: `public`, `core`, `site`, `brand`, `commerce`, `notifications`, `analytics`, `billing`. Commerce-domain tables (orders, ledger entries, payouts, rollups, affiliate selections) live under `commerce.*`. Brand-team and brand-store-settings tables live under `brand.*`.
- **Order-lifecycle source of truth (post-Phase-3):** `commerce.orders` (mutable projection), `commerce.order_events` (append-only audit log keyed by `shopify_event_id` for webhook idempotency), `commerce.order_items` (trigger-mirrored from `line_items` JSONB), `commerce.brand_affiliate_rollup` (trigger-maintained per-day rollup). `commerce.commission_movements` (renamed from `commission_ledger_entries` in `20260506600000_rename_ledger_to_movements.sql`) is narrowed (post-Phase-4) to money-movement rows only — `entry_type IN ('payout','clawback','adjustment')`. Accruals/reversals are derived from `commerce.orders.commission_cents` + `brand_affiliate_rollup.reversed_commission_cents`, not stored as ledger rows.
- **`orders/edited` policy:** the snapshot updates but commission stays frozen at the original-paid value. Reductions only flow through `refunds/create`. Affiliates don't earn extra on upsells; symmetric tradeoff accepted (ADR 0001 Decision #3).
- **Commerce read pattern:** live queries against `commerce.orders` + `brand_affiliate_rollup` + raw event tables, fronted by `CacheLockService::rememberLocked` with a 60s TTL + jitter + SWR (single-flight, push-invalidated on every commerce write).
- **`commerce.orders.payout_id` writer (Phase 3.1):** populated by `CommissionPayoutService` when a payout settles; feeds `paid_cents` in analytics. Backfill SQL lives in `20260506400000_backfill_orders_payout_id.sql`.

### Code Organization
```
app/
  Http/Controllers/Api/{Professional,PublicSite,Staff,Shopify,Webhooks,Internal}/
  Http/Middleware/{Auth,Context,Logging}/
  Http/Requests/                                      — Form Request validation
  Http/Resources/                                     — API response transformers (always use these)
  Jobs/{Analytics,Cache,Notifications,Square,Fresha,Shopify,Stripe}/
  Models/{Core,Brand,Commerce,Analytics,Billing,Views}/ — organized by DB schema
  Models/BaseModel.php                                — all models extend this (forces pgsql connection)
  Observers/                                          — model lifecycle hooks
  Services/{Analytics,Auth,Billing,Cache,Customers,Fresha,Media,Notifications,Professional,PublicSite,Shopify,Site,Square,Store,Stripe,Streaming}/
routes/
  api.php                                             — bootstrap, health, webhooks, Shopify OAuth
  api/{professional,publicSite,staff}.php             — domain-specific routes
config/
  sidest.php                                           — all Partna feature config & limits
```

### Patterns
- **Business logic in `Services/`**, not controllers. Controllers handle HTTP concerns only. There is no separate `Actions/` namespace — single-shot operations live alongside other services in the relevant domain folder (e.g. `Services/Billing/CreateProfessionalSubscriptionAction.php`).
- **Resource classes** for all API responses — never return raw Eloquent models.
- **Form Request classes** for input validation.
- **Observer pattern** for model lifecycle side-effects (auto-triggering jobs, cache invalidation).
- **Feature flags** via env vars (e.g., `SIDEST_VIDEO_UPLOADS_ENABLED`). Check `config/sidest.php` for all flags.
- **UUID primary keys** on all tables.
- **Soft deletes** with 30-day retention (configurable via `SOFT_DELETE_RETENTION_DAYS`).
- **JSON columns** for flexible settings (site.settings, brand product configs, etc.).
- **Authorization via Policies** — never inline 403 checks in controllers. See below.
- **Queue jobs for vendor I/O — check for existing jobs before adding a new one.** Before proposing a new `App\Jobs\<Vendor>\<Action>Job`, run `rg --files-with-matches "<vendor>Service" app/Jobs/` to confirm an analogous job doesn't already exist. Master Pattern 16 (DB-F#SCALE-5) found that `ProvisionBrandDnsJob` already existed but a controller bypassed it — duplicate jobs and bypassed jobs both leak vendor I/O onto request threads.

### Authorization Pattern

All resource-level authorization goes through Laravel Policies in `app/Policies/`. Every policy extends `BasePolicy`.

**Never do this in a controller:**
```php
if (! $this->brandAccess->canManageShopify($pro, $brandId)) {
    return $this->error('Forbidden', 403);
}
// or
abort_unless($pro->id === $resource->professional_id, 403);
```

**Always do this:**
```php
$this->authorizeForUser($pro, 'manage', $integration);
```

**Why `authorizeForUser` not `authorize`:** This app uses Supabase JWT — `Auth::user()` is always null. `authorize()` calls `Gate::forUser(null)`, which silently passes or type-errors depending on the policy. `authorizeForUser($pro, ...)` passes the resolved professional explicitly.

**Skeleton pattern for pre-create checks** (no DB row yet):
```php
$skeleton = new ProfessionalIntegration([
    'professional_id' => $pro->id,
    'provider' => ProfessionalIntegration::PROVIDER_FRESHA,
]);
$this->authorizeForUser($pro, 'manage', $skeleton);
```

**Registering a new policy:** Add one line to `AppServiceProvider::boot()`:
```php
Gate::policy(YourModel::class, YourPolicy::class);
```

**CI enforces:** Direct `BrandAccessService` capability calls (`canManageShopify`, `canManageBrand`, `canReadBrandAnalytics`, `canReadBrandFinancialAnalytics`) and inline 403 aborts in controllers fail the build. When you add a new capability method to `BrandAccessService`, also add it to the `CAPABILITY_PATTERN` in `.github/workflows/ci.yml`.

**Coverage is sweep-tested:** `tests/Feature/Security/PolicyCoverageTest.php` asserts every model under `app/Models/` either has a `Gate::policy()` registration in `AppServiceProvider::boot()` or appears in the `POLICY_EXEMPT` allowlist with a justification. Adding a new tenant-owned model? Register a policy or add an exempt entry — silent omissions fail CI.

**403 vs 404 standard:** Use 404 (not 403) when a resource doesn't exist or doesn't belong to the authenticated user. Use 403 only for role/type restrictions ("brand-only", "staff-only") and policy gate failures. On public (unauthenticated) endpoints, always use 404 for missing/inaccessible resources — returning 403 reveals the resource exists and enables enumeration.

## Shopify Integration Known Quirks

### Affiliate Catalog: Admin API, not Storefront API
`AffiliateProductCatalogService` queries Shopify via Admin API (`access_token`, header `X-Shopify-Access-Token`, URL `/admin/api/{ver}/graphql.json`). It was switched from Storefront API because custom-app storefront tokens are scoped to the **Online Store publication only** — they cannot see products published to Hydrogen sales channels, which is where every Partna brand's catalog lives. Do NOT revert this to Storefront API.

### Admin API `priceRange` amounts are 100× the display value
`priceRange.minVariantPrice.amount` (and max) from Shopify Admin GraphQL returns the price scaled by 100 — a $36.00 AUD product returns `"3600"`. The frontend divides by 100 before display (canonical pattern: `Partna-Frontend/lib/hooks/use-brand-catalog.ts:93` and `products-section.tsx`). **Do not add a backend divide** — the frontend divide is intentional and matches all consumers. Variant-level `ProductVariant.price` (a `Money` scalar) is NOT scaled — it returns the correct dollar string and should not be divided.

### Brand catalog and affiliate catalog are ACTIVE-only
`BrandCatalogService::PRODUCTS_WITH_METAFIELDS` passes `query: "status:active"` to Shopify's `products()`. DRAFT and ARCHIVED products are excluded from the brand commerce table and affiliate metafield map. To read all statuses, use `fetchAllProducts()` instead.

### `disconnected_at` cleared on reinstall
`EmbeddedSetupController::provisionShopifyIntegration` unsets `disconnected_at` from `professional_integrations.provider_metadata` on every provision call (PR #16). `BrandStatusService::determine()` checks `disconnected_at` first — if it's set, the brand stays in Disconnected status regardless of token state. If a brand is stuck in Disconnected after reinstall, confirm the provision-integration call ran and the field was cleared.

## Shopify embedded auth (Comet side)

`/internal/embedded/*` is the API surface for the Partna-Shopify-App embedded app. Tenant identity comes from a Shopify-signed session JWT forwarded by Remix — NOT from a shared static key or trusted header. See `../Partna-Shopify-App/CLAUDE.md` for the Remix-side flow and `../CLAUDE.md` for the cross-repo picture.

### Middleware (`shopify.session` group)

`app/Http/Middleware/Auth/VerifyShopifySessionToken.php` (aliased as `shopify.session` in `bootstrap/app.php`) runs the full validation order on every `/internal/embedded/*` request:

1. Extract Bearer token from `Authorization` header — 401 `token_missing` if absent.
2. `JWT::decode($token, new Key($secret, 'HS256'))` with 10s leeway — 401 `sig_invalid` on any throw (covers signature mismatch, `exp`, `nbf` via `firebase/php-jwt ^6`).
3. `aud == config('services.shopify.api_key')` — 401 `aud_mismatch`.
4. `dest` host (lowercase, parsed) ends with `.myshopify.com` — 401 `dest_invalid`.
5. `iss` host equals `dest` host — 401 `iss_mismatch`. (Both should point at the same Shopify shop URL; mismatch indicates a forged or replayed token.)
6. `jti` claim present and unseen in `Cache::add("partna:shopify-jti:{$jti}", 1, 120)` — 401 `jti_missing` if absent, 401 `jti_replay` if already cached. If the cache backend throws → 503 `cache_unavailable` (fail-closed; never fail-open).
7. Resolve professional via `ShopifyShopResolver::resolveByShopDomain($destHost)` — 404 `shop_unlinked` if absent.
8. Stash on the request attributes: `embedded_professional_id`, `embedded_shop_domain`, `embedded_shopify_user_id` (from `sub`).

Every reject path logs `shopify.session.failed { reason, path, duration_ms, ... }`. Successes log `shopify.session.ok { shop, duration_ms }`. Reason codes are a fixed enum: `sig_invalid | exp | nbf | aud_mismatch | dest_invalid | iss_mismatch | jti_missing | jti_replay | shop_unlinked | cache_unavailable | token_missing | ok`. Controllers read tenant identity from `$request->attributes->get('embedded_professional_id')` etc. — never re-decode the token.

### Configuration invariants

- `SHOPIFY_API_SECRET` (`.env`) matches the secret on the Partna-Shopify-App Vercel deployment exactly. Symmetric HS256 secret. Rotation: synchronised env updates across both platforms within ~60s + `redis-cli --scan --pattern 'partna:shopify-jti:*' | xargs redis-cli del`. Full runbook in the rebuild plan (`~/.claude/plans/we-spent-a-long-humming-phoenix.md`).
- `CACHE_STORE=redis` in production. The middleware fails closed (503) if Redis is unreachable. File/array drivers leak across pods and are unsafe in multi-pod environments.
- `firebase/php-jwt ^6` (composer) — required for `nbf`/`exp` claim handling and the `JWT::decode($token, new Key($secret, 'HS256'))` API.
- Rate limit: `throttle:embedded-by-shop` (60 req/min keyed by `dest` shop domain) applied alongside `shopify.session` on every `/internal/embedded/*` route. The limiter is registered in `RouteServiceProvider::boot()`.
- Admin API version for per-shop access-token validation: `2026-04`. `validateShopifyAccessToken()` hits `https://{shop}/admin/api/2026-04/shop.json` and asserts `data.shop.myshopify_domain === $destHost` (the JWT's `dest` claim). Domain mismatch → reject; 401 → reject; 5xx / network error → allow on the assumption of transient outage.

### Forbidden patterns

- `embedded.key` middleware — DEPRECATED. Deleted in Phase 8 of the rebuild plan.
- `VerifyEmbeddedApiKey` — DELETED. The class no longer exists; the only auth on `/internal/embedded/*` is `shopify.session` (JWT).
- `embedded.dual` / `EmbeddedDualAuth` — DELETED. The cutover-window dispatcher was removed once the Remix side moved to JWT-only.
- `PARTNA_EMBEDDED_API_KEY` env var — DELETED from `.env.example` and `config/services.php`. Remove from Laravel Cloud envs too.
- `if ($expected !== '')` inline auth checks (the historic fail-open pattern in `EmbeddedConnectController`) — replaced by middleware + `$request->attributes->get('embedded_*')`.
- Trusting `X-Shopify-Shop` for tenant identity — the `dest` claim is the sole source. The header may still appear during the dual-auth window for backward compatibility but it is not authoritative.

### Webhook context

`app/uninstalled` is HMAC-validated directly at the Laravel `/api/webhooks/shopify/app-uninstalled` receiver. It does NOT pass through `shopify.session` (no App Bridge JWT in webhook context). The Remix-side handler (`Partna-Shopify-App/app/routes/webhooks.app.uninstalled.tsx`) returns 200 from `authenticate.webhook(request)` only and must not call `/internal/embedded/*` — webhook context carries no token.

## Development Commands

```bash
composer dev      # Start server, queue worker, log tail, and Vite (all concurrently)
composer test     # Clear config + run Pest test suite (enforces no Laravel migrations)
php artisan pint  # Fix code style (Laravel Pint)
php artisan tinker # Interactive REPL
```

## Code Conventions

- 4-space indentation, LF line endings (see `.editorconfig`)
- Follow existing naming patterns — check adjacent files before creating new ones
- Tests: `tests/Feature/{domain}/` for integration, `tests/Unit/` for isolated logic
- Write Pest tests for new features and bug fixes

### Commenting

Comment enough that a reader (Tobias, frontend Claude, future-you) can understand a file without tracing every call. **Not extensive — purposeful.**

- **Always comment**: non-obvious WHY (a constraint, a Shopify quirk, an ordering requirement, a workaround), the contract a method enforces, the meaning of "magic" defaults (e.g. `null = all enabled`), and the shape of complex JSON/array structures.
- **Brief docblocks** on public service methods and controller actions: 1-3 lines explaining purpose + return shape. Use `@return array{...}` shape annotations for complex returns.
- **Inline comments** above non-trivial blocks (filtering, validation, cache busting) — one short line saying *why*, not *what*.
- **Avoid**: paragraph-long essays, comments that just restate the next line, decorative banners, TODO graveyards.
- **Test files**: descriptive `it(...)` names are usually enough — only comment when setup is non-obvious.

When in doubt, ask: "if I deleted this comment, would a new dev have to read 3 other files to understand?" If yes, keep it.

## Audits

### Generating new audits — use the pipeline, not a manual session

When asked to audit code (any phrasing: "let's audit X", "audit these controllers", "audit this", "review for issues", "find bugs in X"), use the dual-worker pipeline at `scripts/audit/audit.sh`:

```bash
scripts/audit/audit.sh \
  --lens "<5-15 word audit theme>" \
  --scope <path> \
  [--scope <path>...]
```

This runs DeepSeek V4 Pro for the first-pass scan, then `claude -p --model sonnet` for adjudication, and emits `audit-YYYY-MM-DD-<lens-slug>.md` in the canonical format. Validated 2026-05-04: ~$0.06–0.25 per audit, ~5–7 minutes wall time, ship-quality output.

- DeepSeek key lives in `scripts/audit/.env` (gitignored). Override by exporting `DEEPSEEK_API_KEY`.
- Claude uses the local `claude` CLI's existing OAuth login. No `ANTHROPIC_API_KEY` required.

**Don't manually generate audit findings in a session unless explicitly told to.** The pipeline is faster, cheaper, and produces consistent format for downstream tooling. Use the standalone scripts (`audit-scan.sh`, `audit-adjudicate.sh`) when you want to inspect drafts before adjudicating, or re-adjudicate without re-scanning.

### Audit format (canonical)

The structure is load-bearing: the audit orchestrator (`audit` CLI) parses these files to feed unattended fix sessions. The canonical reference is **`pilot-stage-1.md`** — no separate conventions doc exists. Required structure per finding:

- Top-level `- [ ]` checkbox + `**#ID**` + tier marker (P0/P1/P2/P3) + Effort tag (S/M/L/XL)
- `Where:` / `Affects:` / `What to do:` (bullets) / `Technical:` / `Plain English:` / `Evidence:` (verbatim code)
- Bundles go under `## Suggested Bundled Sessions`
- Items that should not run unattended go under `### Standalone — do NOT bundle`

Companion files (`*-executive-summary.md`, `audit-ledger-*.md`, `*-legal-coding.md`) are not parsed by the orchestrator — only files with the item-list structure are.

## Workflow

### Plan First
- Enter plan mode for any non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, STOP and re-plan immediately
- Check in with the user before starting implementation

### Execute Autonomously
- When given a bug: just fix it. **First** pull Cloud logs (`cloud env:logs partna development --minutes 10`), then trace errors, then resolve. Never skip the log pull — see "Laravel logs" section above.
- **Check Nightwatch** when diagnosing bugs or performance issues — use it to find exceptions, slow routes, slow jobs, and stack traces before diving into code.
- Use subagents to keep the main context window clean — offload research and exploration.
- One task per subagent for focused execution.
- Go fix failing tests without being told how.

### Verify Before Done
- Never mark a task complete without proving it works.
- Run `composer test` to verify changes pass.
- After fixing bugs, check Nightwatch to confirm the issue is resolved and no new issues surfaced.
- Ask yourself: "Would a staff engineer approve this?"

### Learn Continuously
- After any correction from the user: update memory with the pattern.
- Write rules that prevent the same mistake twice.

## Core Principles

- **Simplicity first.** Make every change as simple as possible. Impact minimal code.
- **Find root causes.** No temporary fixes. No bandaids. Senior developer standards.
- **Minimal blast radius.** Only touch what's necessary. No side-effect bugs.
- **Demand elegance (balanced).** For non-trivial changes, pause and ask "is there a more elegant way?" Skip this for simple, obvious fixes.

## Do NOT

- Create Laravel migration files (use `supabase/migrations/` with raw SQL)
- Modify `.env` directly — reference `.env.example` for available keys
- Return raw Eloquent models from API endpoints (use Resource classes)
- Over-engineer simple fixes — three similar lines > a premature abstraction
- Drown files in comments — see "Commenting" above for the bar

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to enhance the user's satisfaction building Laravel applications.

## Foundational Context
This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5.4
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/nightwatch (NIGHTWATCH) - v1
- laravel/prompts (PROMPTS) - v0
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove it works. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

## Laravel Boost
- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan
- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs
- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging
- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool
- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)
- Boost comes with a powerful `search-docs` tool you should use before any other approaches when dealing with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- The `search-docs` tool is perfect for all Laravel-related packages, including Laravel, Inertia, Livewire, Filament, Tailwind, Pest, Nova, Nightwatch, etc.
- You must use this tool to search for Laravel ecosystem documentation before falling back to other approaches.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries to start. For example: `['rate limiting', 'routing rate limiting', 'routing']`.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax
- You can and should pass multiple queries at once. The most relevant results will be returned first.

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

## PHP

- Always use curly braces for control structures, even if it has one line.

### Constructors
- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

### Type Declarations
- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Comments
- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless there is something very complex going on.

## PHPDoc Blocks
- Add useful array shape type definitions for arrays when appropriate.

## Enums
- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

=== tests rules ===

## Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

## Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Database
- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation
- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources
- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

### Controllers & Validation
- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

### Queues
- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

### Authentication & Authorization
- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

### URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

### Configuration
- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

### Testing
- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

### Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

## Laravel 12

- Use the `search-docs` tool to get version-specific documentation.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

### Laravel 12 Structure
- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

### Database
- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models
- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

## Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== pest/core rules ===

## Pest
### Testing
- If you need to verify a feature is working, write or update a Unit / Feature test.

### Pest Tests
- All tests must be written using Pest. Use `php artisan make:test --pest {name}`.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files - these are core to the application.
- Tests should test all of the happy paths, failure paths, and weird paths.
- Tests live in the `tests/Feature` and `tests/Unit` directories.
- Pest tests look and behave like this:
<code-snippet name="Basic Pest Test Example" lang="php">
it('is true', function () {
    expect(true)->toBeTrue();
});
</code-snippet>

### Running Tests
- Run the minimal number of tests using an appropriate filter before finalizing code edits.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).
- When the tests relating to your changes are passing, ask the user if they would like to run the entire test suite to ensure everything is still passing.

### Pest Assertions
- When asserting status codes on a response, use the specific method like `assertForbidden` and `assertNotFound` instead of using `assertStatus(403)` or similar, e.g.:
<code-snippet name="Pest Example Asserting postJson Response" lang="php">
it('returns all', function () {
    $response = $this->postJson('/api/docs', []);

    $response->assertSuccessful();
});
</code-snippet>

### Mocking
- Mocking can be very helpful when appropriate.
- When mocking, you can use the `Pest\Laravel\mock` Pest function, but always import it via `use function Pest\Laravel\mock;` before using it. Alternatively, you can use `$this->mock()` if existing tests do.
- You can also create partial mocks using the same import or self method.

### Datasets
- Use datasets in Pest to simplify tests that have a lot of duplicated data. This is often the case when testing validation rules, so consider this solution when writing tests for validation rules.

<code-snippet name="Pest Dataset Example" lang="php">
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
</code-snippet>

=== pest/v4 rules ===

## Pest 4

- Pest 4 is a huge upgrade to Pest and offers: browser testing, smoke testing, visual regression testing, test sharding, and faster type coverage.
- Browser testing is incredibly powerful and useful for this project.
- Browser tests should live in `tests/Browser/`.
- Use the `search-docs` tool for detailed guidance on utilizing these features.

### Browser Testing
- You can use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories within Pest 4 browser tests, as well as `RefreshDatabase` (when needed) to ensure a clean state for each test.
- Interact with the page (click, type, scroll, select, submit, drag-and-drop, touch gestures, etc.) when appropriate to complete the test.
- If requested, test on multiple browsers (Chrome, Firefox, Safari).
- If requested, test on different devices and viewports (like iPhone 14 Pro, tablets, or custom breakpoints).
- Switch color schemes (light/dark mode) when appropriate.
- Take screenshots or pause tests for debugging when appropriate.

### Example Tests

<code-snippet name="Pest Browser Test Example" lang="php">
it('may reset the password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create());

    $page = visit('/sign-in'); // Visit on a real browser...

    $page->assertSee('Sign In')
        ->assertNoJavascriptErrors() // or ->assertNoConsoleLogs()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!')

    Notification::assertSent(ResetPassword::class);
});
</code-snippet>

<code-snippet name="Pest Smoke Testing Example" lang="php">
$pages = visit(['/', '/about', '/contact']);

$pages->assertNoJavascriptErrors()->assertNoConsoleLogs();
</code-snippet>

=== tailwindcss/core rules ===

## Tailwind CSS

- Use Tailwind CSS classes to style HTML; check and use existing Tailwind conventions within the project before writing your own.
- Offer to extract repeated patterns into components that match the project's conventions (i.e. Blade, JSX, Vue, etc.).
- Think through class placement, order, priority, and defaults. Remove redundant classes, add classes to parent or child carefully to limit repetition, and group elements logically.
- You can use the `search-docs` tool to get exact examples from the official documentation when needed.

### Spacing
- When listing items, use gap utilities for spacing; don't use margins.

<code-snippet name="Valid Flex Gap Spacing Example" lang="html">
    <div class="flex gap-8">
        <div>Superior</div>
        <div>Michigan</div>
        <div>Erie</div>
    </div>
</code-snippet>

### Dark Mode
- If existing pages and components support dark mode, new pages and components must support dark mode in a similar way, typically using `dark:`.

=== tailwindcss/v4 rules ===

## Tailwind CSS 4

- Always use Tailwind CSS v4; do not use the deprecated utilities.
- `corePlugins` is not supported in Tailwind v4.
- In Tailwind v4, configuration is CSS-first using the `@theme` directive — no separate `tailwind.config.js` file is needed.

<code-snippet name="Extending Theme in CSS" lang="css">
@theme {
  --color-brand: oklch(0.72 0.11 178);
}
</code-snippet>

- In Tailwind v4, you import Tailwind using a regular CSS `@import` statement, not using the `@tailwind` directives used in v3:

<code-snippet name="Tailwind v4 Import Tailwind Diff" lang="diff">
   - @tailwind base;
   - @tailwind components;
   - @tailwind utilities;
   + @import "tailwindcss";
</code-snippet>

### Replaced Utilities
- Tailwind v4 removed deprecated utilities. Do not use the deprecated option; use the replacement.
- Opacity values are still numeric.

| Deprecated |	Replacement |
|------------+--------------|
| bg-opacity-* | bg-black/* |
| text-opacity-* | text-black/* |
| border-opacity-* | border-black/* |
| divide-opacity-* | divide-black/* |
| ring-opacity-* | ring-black/* |
| placeholder-opacity-* | placeholder-black/* |
| flex-shrink-* | shrink-* |
| flex-grow-* | grow-* |
| overflow-ellipsis | text-ellipsis |
| decoration-slice | box-decoration-slice |
| decoration-clone | box-decoration-clone |
</laravel-boost-guidelines>
