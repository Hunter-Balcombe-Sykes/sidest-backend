# Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure

Hunt **tenant-boundary failures**, **secret leakage**, **unverified webhook entry points**, **injection / SSRF / open redirect**, and **PII exposure** across the API surface. This is the **highest-priority pre-pilot lens** — every finding is potentially user-visible and irreversible.

Partna runs on **Supabase JWT auth** (`Auth::user()` always returns null; resolved actor lives at `$request->attributes->get('professional')`). The embedded Shopify-admin surface adds a **Shopify session-token auth path** distinct from Supabase JWT. The Shopify install flow + Cloudflare DNS provisioning + Hydrogen redeploy add several **vendor-callback / install-token / install-secret** entry points.

## Use the lens prefix `SEC` for findings

Number them `SEC-1`, `SEC-2`, … sequentially across the whole audit. **P0 is the default tier for any confirmed tenant-boundary failure.**

## Partna Authorization Doctrine (deviations are findings)

1. **Supabase JWT auth.** `Auth::user()` ALWAYS returns null. Resolved actor lives at `$request->attributes->get('professional')` or via `$this->currentProfessional($request)`.
2. **Authorization through Policies, never inline.** No `abort_unless($x->professional_id === $pro->id, 403)`. Always `$this->authorizeForUser($pro, 'verb', $resource)`.
3. **`authorizeForUser`, not `authorize`.** The standard `authorize()` calls `Gate::forUser(null)` which silently passes.
4. **Policies extend `BasePolicy`.** Not-owned → 404 (`denyAsNotFound()`). Pending-deletion → 423.
5. **Policy registration in `AppServiceProvider::boot()`.** Every tenant-owned model needs `Gate::policy(Model::class, ModelPolicy::class)`.
6. **Brand-only routes use `brand.only` middleware**, affiliate-only routes use `affiliate.only` — no inline `professional_type` checks.

## Findings categories

### (1) Authentication boundary correctness

- `VerifyShopifySessionToken`: signature check (HS256 vs RS256 — must match Shopify's algo), `aud` = our app's client ID, `iss` = expected shop, `exp` + clock-skew tolerance, `nbf` check, `jti` replay protection if applicable, **tenant resolved from session-token claims, never from a body/header/query param the client controls**.
- `VerifyEmbeddedApiKey`: **`hash_equals` only, never `===` / `==`**; no `Log::*` emit of the key value; no fallback-to-bypass when env var is empty (the `VerifyHydrogenApiKey` cautionary tale); env var required at boot in production.
- `VerifyHydrogenApiKey`: same rules — confirm the prior bypass-on-empty has stayed fixed.
- Any middleware that consumes a token and resolves a tenant — the resolution must be cryptographically tied to the token, not a separate field.
- JWKS validation paths: confirm key rotation is handled (cached keys are invalidated when the key set changes); confirm the cached key is keyed by `kid`, not by URL.

### (2) Authorization / policy completeness

- Tenant-owned models without a registered Policy (sweep `app/Models/**/*.php` against `AppServiceProvider::boot()` `Gate::policy` registrations).
- Controllers using `authorize(...)` instead of `authorizeForUser($pro, ...)` — silent pass under Supabase JWT.
- Inline `abort_unless($x->professional_id === $pro->id, 403)` — replace with Policy.
- Inline role-scoping `if ($role === 'brand') { ->where(...) } else { ->where(...) }` (the `#STRIPE-1` shape) — replace with a Policy ability that takes the resolved actor.
- Endpoints that accept a tenant ID from the request without re-authorizing against the resolved actor.
- Bulk endpoints (`PATCH /things` where IDs come from the body) without per-ID authorization.

### (3) Tenant isolation / IDOR

- Queries of the form `Model::find($request->id)` without a `where('professional_id', $pro->id)` clause or a Policy gate.
- Bulk-write endpoints that accept an array of IDs without filtering the IDs against the actor's tenant set.
- Cache keys that omit a tenant scope — `Cache::get('foo')` instead of `Cache::get("brand:{$id}:foo")` on a hot-read path.
- Media / file URLs that are guessable (sequential IDs, predictable hashes) — must be signed or scoped.
- Public endpoints that distinguish "not found" from "not yours" via different status codes — enumeration risk.
- 403 where 404 should be: per CLAUDE.md, missing-or-not-yours = 404.

### (4) Webhook signature verification

- Every webhook controller in `app/Http/Controllers/Api/Webhooks/` must verify the vendor signature before doing any work.
- Shopify HMAC verification, Stripe signature verification (`Webhook::constructEvent`), Cloudflare signed request validation, custom vendors with shared-secret HMAC.
- Webhook event-ID dedup: every webhook must record the vendor event ID and skip on duplicate (matches `commerce.order_events.shopify_event_id`).
- Timing-safe comparison for HMAC checks (`hash_equals`, not `==`).
- Webhook controllers that accept payloads above a sane size limit — DoS risk.
- Webhook controllers that pass the raw payload to a job without sanitisation — log payload bloat + injection risk inside the job.

### (5) Secrets handling & log hygiene

- Hardcoded credentials in source (`config/*.php`, `app/Services/**/*.php`, migrations). Sweep for: `Bearer `, `sk_`, `pk_`, `AKIA`, `AIza`, `xoxb-`, JWT-shaped strings.
- API tokens, signing secrets, DB passwords passed via `Log::*` in any code path — Nightwatch / log aggregator persistence risk.
- Stripe / Shopify / Cloudflare / Hydrogen secrets in env vars: confirm `.env.example` doesn't have real values; confirm `config/services.php` reads via `env()` and not inline literals.
- `dd()` / `dump()` / `Log::debug` on auth-sensitive request bodies.
- Stack traces with secrets in production exception output (Laravel default is fine; verify no custom exception renderer leaks `$request->all()`).
- Cookies without `Secure` / `HttpOnly` / `SameSite` flags on production.

### (6) Input validation & injection

- Raw SQL fragments built from user-supplied data: `DB::raw($input)`, `DB::statement($input)`, `whereRaw($input)`, `->orderByRaw($input)` — SQL-injection risk.
- `Eloquent::orderBy($request->input('sort'))` without an allow-list — SQL-injection via column name.
- Shell-process invocations (`Symfony\Process`, `Illuminate\Process`, backtick operator, `passthru`, `system`, `popen`, `proc_open`) with user-supplied arguments — command-injection risk.
- File-path operations using user input without `realpath` validation — path-traversal risk.
- `Mail::raw($input)` / `Notification::send($input)` with HTML-rendering inputs — XSS via email.
- Form Request classes missing on endpoints that accept user input — validation bypass risk.
- Validation rules that don't constrain length / type / format on free-text fields stored in JSONB.

### (7) SSRF / open redirect / URL parsing

- `Http::get($url)` / `Guzzle->get($url)` / `file_get_contents($url)` with `$url` sourced from user input or an unsanitised vendor response — SSRF risk.
- Domain parsing (`resolveShop`, custom-domain lookups) without strict allow-list against `*.myshopify.com` / known suffix list.
- Redirect endpoints that accept a `next=` / `return_to=` / `host=` parameter without allow-list — open-redirect risk (Shopify OAuth callback is a particular concern).
- DNS resolution / IP fetch operations that can hit internal IPs (169.254.169.254 metadata, RFC1918 ranges) — SSRF risk.
- Cloudflare DNS provisioning paths: confirm the domain being provisioned belongs to the tenant (not a domain the tenant has injected via header/body).

### (8) CORS / CSRF / cookie security

- `config/cors.php` `allowed_origins` containing `*` on routes that issue cookies or use session auth.
- Routes that mutate state via `GET` (CSRF bypass).
- Embedded Shopify app routes: confirm CSRF middleware is consistent — session-token auth replaces CSRF but the boundary must be explicit.
- `Cookie::queue(...)` calls without `secure` + `httpOnly` + `sameSite=lax|strict`.

### (9) Rate limiting on auth & sensitive endpoints

- Login / OAuth callback routes without `throttle` middleware — credential-stuffing risk.
- Password-reset / magic-link endpoints without per-IP or per-email throttling — enumeration + spam.
- Webhook endpoints without throttling — flooding risk.
- Top-up / payment-method / Connect-onboarding endpoints without throttling — DoS on Stripe.
- DNS provisioning, Hydrogen redeploy, catalog sync — per-tenant throttling required to prevent self-DoS.

### (10) PII exposure in responses & logs

- Resource classes (`app/Http/Resources/*`) exposing fields not needed by the caller (email, phone, address, last-4) — audience-confused responses.
- Staff endpoints exposing PII to brand actors via shared Resource classes.
- Public site endpoints (`app/Http/Controllers/Api/PublicSite`) returning anything beyond display name + avatar + content — enumeration of customer base.
- `Log::*` calls with full email / phone / billing address in the message body — log persistence is not GDPR-safe.
- Error responses that include `Model::factory()->raw()`-style payloads with PII fields populated.
- API responses leaking internal IDs (`brand_professional_id`, `affiliate_professional_id`) where opaque UUIDs would serve.

### (11) Vendor-callback trust boundary

- Shopify OAuth callback (`ShopifyAppOAuthController`): state/nonce check, HMAC verification, no replay of the install handoff to attach a different professional.
- Stripe Connect callback: ID match + state token.
- Cloudflare callback / Hydrogen deploy callback: signature or shared-secret verification.
- "Vendor said this brand is connected" paths that don't re-verify via API call — token-replay risk.

## Per-finding requirements

For every finding:
- Cite the category number (1–11).
- Default tier is **P0 for confirmed tenant-boundary failures** (categories 1–4), **P1 for confirmed secret leakage / injection / SSRF** (categories 5–7), **P2 for hygiene gaps**.
- Name the canonical fix: `Policy + authorizeForUser`, `hash_equals`, `Webhook::constructEvent`, `vendor event-ID dedup`, `domain allow-list`, `Form Request with explicit rules`, `Resource class audience split`, `throttle:X,Y` middleware, `signed URL`, `state/nonce + HMAC on OAuth callback`.
- Quote verbatim evidence.

## Out of scope — do NOT re-flag

- The Stripe payout lifecycle audit's already-closed `#STRIPE-1` policy refactor.
- The commerce schema and the Stripe payout pipeline (audited; shipped).
- Booking / Fresha / Square code paths (dropped).
- Dependency / CVE scanning — Composer audit lives elsewhere; only flag in-source CVE indicators.
- Laravel-Cloud-vs-K8s deployment hardening.

## Suggested per-domain scope groups

### Group A — Auth middleware + policies (run first, highest priority)
```
--scope app/Http/Middleware/Auth
--scope app/Policies
--scope app/Providers/AppServiceProvider.php
--scope app/Http/Controllers/Concerns
```

### Group B — Webhook controllers
```
--scope app/Http/Controllers/Api/Webhooks
--scope app/Http/Controllers/Api/Shopify
```

### Group C — Embedded surface (Shopify session-token + install flow)
```
--scope app/Http/Controllers/Api/Internal
--scope app/Http/Controllers/Api/Professional/ShopifyIntegration
```

### Group D — Financial endpoints + Form Requests
```
--scope app/Http/Controllers/Api/Professional/Stripe
--scope app/Http/Controllers/Api/Professional/Brand
--scope app/Http/Controllers/Api/Professional/Affiliate
--scope app/Http/Requests
```

### Group E — Public surface (enumeration / PII risk)
```
--scope app/Http/Controllers/Api/PublicSite
--scope app/Http/Resources
```

### Group F — Vendor I/O services (SSRF / secret handling)
```
--scope app/Services/Shopify
--scope app/Services/Stripe
--scope app/Services/Cloudflare
--scope app/Services/Hydrogen
--scope app/Services/Auth
```

### Group G — Configuration (secret leak, CORS)
```
--scope config
```

## Exhaustiveness directive

Walk every file in scope. Emit a finding for every distinct quotable instance. If three controllers have inline role-scoping, three findings. If one file has both an injection risk and a PII exposure, two findings. **Under-reporting on a security audit is the worst failure mode.**
