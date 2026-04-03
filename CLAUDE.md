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
  Http/Controllers/Api/{Professional,PublicSite,Staff,Enterprise,Shopify,Webhooks}/
  Http/Middleware/{Auth,Context,Logging}/
  Http/Requests/                                      — Form Request validation
  Http/Resources/                                     — API response transformers (always use these)
  Jobs/{Analytics,Cache,Notifications,Square,Fresha,Shopify,Store}/
  Models/{Core,Retail,Analytics,Billing,Views}/        — organized by DB schema
  Models/BaseModel.php                                — all models extend this (forces pgsql connection)
  Observers/                                          — model lifecycle hooks
  Services/{Branding,Cache,Enterprise,Fresha,Legal,Media,Notifications,Professional,Public,Square,Store,Stripe}/
routes/
  api.php                                             — bootstrap, health, webhooks, Shopify OAuth
  api/{professional,publicSite,staff,enterprise}.php  — domain-specific routes
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

## Workflow

### Plan First
- Enter plan mode for any non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, STOP and re-plan immediately
- Check in with the user before starting implementation

### Execute Autonomously
- When given a bug: just fix it. Read logs, trace errors, resolve without hand-holding.
- Use subagents to keep the main context window clean — offload research and exploration.
- One task per subagent for focused execution.
- Go fix failing tests without being told how.

### Verify Before Done
- Never mark a task complete without proving it works.
- Run `composer test` to verify changes pass.
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
- Add docstrings, comments, or type annotations to code you didn't change
- Over-engineer simple fixes — three similar lines > a premature abstraction
