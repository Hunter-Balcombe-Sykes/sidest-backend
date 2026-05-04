# Claude Code Instructions

## Project Identity

**Side St** — Laravel 12 + Supabase + PostgreSQL multi-tenant affiliate SaaS platform.
For full business context, domain model, and entity relationships, read `AI_CONTEXT.md`.
For API endpoint reference, read `docs/api.md`.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.2, Laravel 12 |
| Database | PostgreSQL (Supabase-hosted), schemas: `public`, `core`, `retail`, `analytics`, `billing` |
| Auth | Supabase Auth (JWT) — no backend login; frontend forwards token |
| Cache/Queue | Redis (DB 0 = cache, DB 1 = sessions, DB 2 = queue) |
| Jobs | Laravel Horizon (Redis-backed), separate `redis_video` connection for video processing |
| Frontend | Vite 7, Tailwind CSS 4 (minimal — mostly API backend) |
| Testing | Pest 4 + PHPUnit, Mockery, SQLite in-memory for tests |
| Monitoring | Laravel Nightwatch (exceptions, slow routes/jobs/commands/tasks) |
| Integrations | Shopify, Square, Fresha, Stripe |

## Architecture Rules

### Database — Supabase Only
- **Never create Laravel migration files.** A composer guard (`guard:no-laravel-migrations`) will reject them.
- All schema changes go in `supabase/migrations/` as raw SQL files.
- PostgreSQL uses multiple schemas set via `search_path`: `public`, `core`, `analytics`, `billing`, `retail`.

### Code Organization
```
app/
  Actions/{Customer,Professional,Site,Subscription}/  — single-responsibility action classes
  Http/Controllers/Api/{Professional,PublicSite,Staff,Shopify,Webhooks,Internal}/
  Http/Middleware/{Auth,Context,Logging}/
  Http/Requests/                                      — Form Request validation
  Http/Resources/                                     — API response transformers (always use these)
  Jobs/{Analytics,Cache,Notifications,Square,Fresha,Shopify,Stripe}/
  Models/{Core,Retail,Commerce,Analytics,Billing,Views}/ — organized by DB schema
  Models/BaseModel.php                                — all models extend this (forces pgsql connection)
  Observers/                                          — model lifecycle hooks
  Services/{Analytics,Auth,Billing,Cache,Fresha,Media,Notifications,Professional,Public,Shopify,Square,Store,Stripe}/
routes/
  api.php                                             — bootstrap, health, webhooks, Shopify OAuth
  api/{professional,publicSite,staff}.php             — domain-specific routes
config/
  sidest.php                                           — all Side St feature config & limits
```

### Patterns
- **Business logic in `Services/`**, not controllers. Controllers handle HTTP concerns only.
- **`Actions/`** for reusable, single-responsibility operations (e.g., creating a customer, provisioning a site).
- **Resource classes** for all API responses — never return raw Eloquent models.
- **Form Request classes** for input validation.
- **Observer pattern** for model lifecycle side-effects (auto-triggering jobs, cache invalidation).
- **Feature flags** via env vars (e.g., `SIDEST_VIDEO_UPLOADS_ENABLED`). Check `config/sidest.php` for all flags.
- **UUID primary keys** on all tables.
- **Soft deletes** with 30-day retention (configurable via `SOFT_DELETE_RETENTION_DAYS`).
- **JSON columns** for flexible settings (site.settings, brand product configs, etc.).
- **Authorization via Policies** — never inline 403 checks in controllers. See below.

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
- When given a bug: just fix it. Read logs, trace errors, resolve without hand-holding.
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
