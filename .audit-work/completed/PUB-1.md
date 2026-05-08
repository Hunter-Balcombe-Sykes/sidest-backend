---
item_id: '#PUB-1'
title: Public lead-capture endpoints rely on weak passive bot defenses (reconstructed
  from orphaned adjudicator output)
source: pilot-stage-3.md
tier: P2
effort_estimate: M
completed_at: '2026-05-08T02:45:14+00:00'
mode: overnight
commit_sha: 12c4c4d
files_touched:
- .env.example
- app/Http/Middleware/VerifyTurnstileCaptcha.php
- bootstrap/app.php
- config/partna.php
- config/services.php
- routes/api.php
- tests/Feature/PublicSite/CaptchaMiddlewareTest.php
test_result: pass
questions_asked: 0
---

# #PUB-1 — Public lead-capture endpoints rely on weak passive bot defenses (reconstructed from orphaned adjudicator output)

## Plain English

Three public forms — the enquiry form, the customer lead form, and the waitlist — now have a Cloudflare Turnstile CAPTCHA check wired up behind a feature flag. When you're ready to turn it on (after updating the frontend to include the Turnstile widget), you set `SIDEST_CAPTCHA_ENABLED=true` in your environment and add your Turnstile secret key. Until then the flag defaults to off and everything works exactly as before. The old passive tricks (honeypot field, timing check) are still in place — the CAPTCHA sits in front of them as the primary gate.

## Technical Summary

**New file:** `app/Http/Middleware/VerifyTurnstileCaptcha.php` — reads `cf_turnstile_response` from the request body, POSTs it to `https://challenges.cloudflare.com/turnstile/v0/siteverify` via `Http::asForm()`, and returns 422 on failure or 503 if the secret key is unconfigured / Cloudflare is unreachable. Bypasses entirely when `partna.features.captcha` is false.

**Modified files:**
- `config/services.php` — added `services.turnstile.secret_key` → `CLOUDFLARE_TURNSTILE_SECRET_KEY`
- `config/partna.php` — added `features.captcha` → `SIDEST_CAPTCHA_ENABLED` (default false) with explanatory comment
- `bootstrap/app.php` — imported `VerifyTurnstileCaptcha`, registered alias `captcha`
- `routes/api.php` — appended `captcha` to middleware arrays for all three lead-capture routes (`/public/waitlist`, `/public/customers`, `/public/enquiry`)
- `.env.example` — added `SIDEST_CAPTCHA_ENABLED=false` in the feature flags section and `CLOUDFLARE_TURNSTILE_SECRET_KEY=` with setup instructions near the existing Cloudflare DNS vars

**New test file:** `tests/Feature/PublicSite/CaptchaMiddlewareTest.php` — 10 tests covering: feature-flag bypass, missing token on all three routes, Turnstile API failure, valid token pass-through, missing secret key (503), and middleware wiring on all three routes via `gatherMiddleware()`.

## Decisions Made

- **Feature flag default = false:** avoids a breaking API contract change before frontends are updated to send the `cf_turnstile_response` field. Josh sets `SIDEST_CAPTCHA_ENABLED=true` when ready.
- **Fail closed on network errors:** a Cloudflare outage returns 503 (not a pass-through). Security over availability for an unauthenticated lead-capture endpoint during an outage window.
- **Token field name `cf_turnstile_response`:** snake_case of the standard Cloudflare widget field `cf-turnstile-response`. Consistent with the existing snake_case API contract.
- **Config in `services.php` not `partna.php`:** Turnstile is a third-party credential, matching the existing pattern for Stripe, Square, Cloudflare DNS, etc.

## Notes

The existing passive defenses (honeypot `website` field + `form_started_at_ms` timing check) in `PublicEnquiryController` are untouched — they remain as defense-in-depth per the audit item's explicit recommendation. `PublicWaitlistController` still has no passive checks, but the CAPTCHA gate is the stronger control anyway.

## Questions Asked
(none)
