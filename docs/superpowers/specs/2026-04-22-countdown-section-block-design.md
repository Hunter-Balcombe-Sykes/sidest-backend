# Countdown Section Block — Design

**Status:** Draft
**Date:** 2026-04-22
**Owner:** Josh
**Target:** v1 (beta)

## Problem

Affiliates on Partna want to announce time-bounded events on their public page — a product drop, an education release, a limited-time class, a masterclass window. Today there is no first-class way to communicate "this goes live at time X and is available until time Y." Affiliates work around it by editing copy on other blocks, which is low-effort to author but weak as a call to attention: viewers have no urgency signal, no clear event state, and no way to track time until the drop.

A countdown section block closes this gap. It lets an affiliate declare a window (drop_time → expiry_time), author state-specific copy, and optionally attach a CTA that links externally (e.g., Stan.store) or internally (e.g., the affiliate's own shop section with filter params).

## Scope

**In scope (v1):**
- New `countdown` block type in the existing section-block system
- Singleton per site (one countdown at a time per affiliate page)
- Three lifecycle states authored by the affiliate: pre-drop, live, expired
- Per-state copy (headline, subtitle) and optional per-state CTA (label + URL)
- Server persists authoring intent; client derives current state from timestamps and ticks the countdown digits
- Auto-hide once `now >= expiry_time` (block stays in DB, renders nothing)
- Available to all three professional types: brand, professional, influencer

**Out of scope (deferred):**
- Multiple concurrent countdowns per page
- Affiliate-selectable timezone per countdown (beyond the browser's local tz at input time)
- Grace window after expiry
- Filtered product display from the countdown itself (v1 scrolls to shop section; filtering via `#shop?products=...` URL params is a frontend-only convention that works in v1 without backend changes)
- Email notifications / "notify me when live" reminders

## Design decisions

### 1. Reuse the section-block pattern

The countdown is a new `block_type` value in `site.blocks`. No new table, no new endpoints.

- Registration: `'countdown'` added to `config('sidest.section_block_types')`.
- CRUD: served by the existing `ProfessionalSectionBlockController::upsert()` — routes are `PUT /sites/{siteId}/sections/countdown` and `DELETE /sites/{siteId}/sections/countdown`.
- Serialization: existing `Block` resource serialization passes `settings` through unchanged.
- Cache invalidation: the existing block observer already busts the public-site cache on save/update/delete.

Adding the block type is effectively one config-array edit plus new validation rules.

### 2. Singleton, not multi-instance

Each site has at most one active countdown. This matches the existing singleton pattern for other section blocks (one newsletter, one gallery, etc.) and keeps authoring UX simple ("is there a countdown right now? yes/no"). If an affiliate wants to promote a new drop, they edit the existing block.

Multi-instance is a future extension that would require a new controller shape and a rendering/stacking decision on the frontend; deferred until we see real demand.

### 3. Three-state lifecycle derived on the client

The server stores absolute UTC timestamps (`drop_time`, `expiry_time`). The client computes which of three states applies on every tick:

```
now < drop_time                          → pre_drop
drop_time <= now < expiry_time           → live
now >= expiry_time                       → expired (auto-hidden in v1)
```

**Rationale for client-side derivation:** `SiteCacheService` caches the public payload. If state were computed server-side at payload-build time, the cached value would go stale the moment `drop_time` or `expiry_time` passed, forcing cache busts on an arbitrary schedule. Pushing state to the client means the cache only stores raw timestamps and is oblivious to countdown timing — cache TTL becomes orthogonal to lifecycle transitions.

**Rationale for auto-hide at expiry (rather than showing an expired state indefinitely):** affiliates tend to set up drops and then forget about them. An expired-state banner sitting forever on a neglected page is worse than nothing. Auto-hiding is the safer default.

**Why the `expired` state still has authorable copy despite v1 auto-hiding it:** the schema accepts it for forward-compatibility. If we later add a grace window (see non-goals) or change the default to "show expired state until affiliate edits," the copy will already be authorable without a schema change. In v1, the frontend simply never renders the `expired` branch. The editor UI may hide the `expired` copy fields in v1 to avoid confusing affiliates, or mark them as "(not shown in v1)" — that's a frontend/UX call.

### 4. Per-state CTAs with a plain URL field

Each of the three states has an optional `{ label, url }` CTA. The URL is a plain string, validated for shape but not for which kind of target it points at.

- External targets use standard URLs: `https://stan.store/...`
- Internal targets use URL fragments interpreted by the frontend: `#shop`, `#shop?products=abc,def`, `#newsletter`, etc.

**Rationale for a single URL field over a tagged union (`{ kind, url, section_ref }`):** the backend's role is to persist authoring intent, not encode layout semantics. A URL (including fragment + query) is already a web-native expression of "scroll/navigate here with these params." The editor UI (frontend) is the authoritative validation boundary — the affiliate picks a target from a dropdown, not by typing raw strings. A stale `#shop` reference degrades gracefully (scrolls to nothing) rather than crashing. The tagged-union design forces every new internal target type to ship a backend change to extend a `Rule::in()` allowlist, which is drift waiting to happen.

### 5. Browser-local input, UTC storage

Affiliates pick drop/expiry times in their own browser timezone. The frontend sends ISO-8601 with offset (e.g., `2026-04-24T21:00:00+01:00`); the backend parses and stores as UTC. Viewers see a countdown that ticks to the correct absolute moment regardless of their own timezone.

No site-level or per-countdown timezone field is introduced. 99% of affiliates run their business in one timezone and pick drop times relative to their own life. A dedicated `settings.timezone` field can be added later as a purely additive JSONB change.

### 6. Availability for all professional types

Brand, professional, and influencer accounts all get `is_enabled = true` for the countdown block type by default. The use case generalizes:

- **Brands:** product drops, collection launches
- **Professionals:** class schedules, booking windows, limited-time service offers
- **Influencers:** content drops, education releases

Gating more tightly later (e.g., paid tier only) is a one-line change to `syncAllowedSections()`.

## Data model

### Table: `site.blocks`

No schema changes. The countdown uses the existing columns:

| Column | Value |
|---|---|
| `block_type` | `'countdown'` |
| `block_group` | `'sections'` |
| `is_active` | boolean — affiliate's draft/live toggle |
| `is_enabled` | boolean — always `true` for all three professional types |
| `sort_order` | integer — position among other sections |
| `settings` | JSONB — countdown-specific config (see below) |

### `settings` JSON shape

```json
{
  "title": "The Drop",
  "timeline": {
    "drop_time": "2026-04-24T20:00:00Z",
    "expiry_time": "2026-04-26T20:00:00Z"
  },
  "states": {
    "pre_drop": {
      "headline": "Coming Friday",
      "subtitle": "A limited run of three new knits.",
      "cta": { "label": null, "url": null }
    },
    "live": {
      "headline": "It's live",
      "subtitle": "Shop now before they're gone.",
      "cta": { "label": "Shop the drop", "url": "#shop?products=abc,def" }
    },
    "expired": {
      "headline": null,
      "subtitle": null,
      "cta": { "label": null, "url": null }
    }
  }
}
```

All fields are optional except `timeline.drop_time` and `timeline.expiry_time`, which are required when creating a countdown. The frontend falls back to sensible defaults for any null field.

## Validation

Rules added to `UpsertSectionBlockRequest::rules()`, applied conditionally when `block_type === 'countdown'`:

| Field | Rules |
|---|---|
| `settings.title` | nullable, string, max:80 |
| `settings.timeline.drop_time` | required, date (ISO-8601) |
| `settings.timeline.expiry_time` | required, date, `after:settings.timeline.drop_time` |
| `settings.states.{state}.headline` | nullable, string, max:80 |
| `settings.states.{state}.subtitle` | nullable, string, max:200 |
| `settings.states.{state}.cta.label` | nullable, string, max:40, `required_with:settings.states.{state}.cta.url` |
| `settings.states.{state}.cta.url` | nullable, string, max:2048, regex (scheme allowlist), `required_with:settings.states.{state}.cta.label` |

where `{state}` iterates `pre_drop`, `live`, `expired`.

**URL scheme allowlist regex:** accepts `https?://...`, absolute paths (`/...`), and URL fragments (`#...`). Rejects `javascript:`, `data:`, `mailto:`, `tel:`, and arbitrary schemes. This is defense-in-depth — the editor picker won't offer unsafe options, but the rule protects against a compromised client sending crafted payloads.

**String-field sanitization:** all string inputs run through `strip_tags` in `prepareForValidation()`, matching the newsletter-block pattern. Defense-in-depth against a future buggy renderer that forgets to escape.

**Drop time in the past is allowed.** An affiliate setting `drop_time = now` effectively starts the block in the `live` state immediately. `expiry_time` must be strictly after `drop_time` — a zero-width window is rejected.

## API

No new endpoints. Uses existing section-block routes:

```
GET    /api/professional/sites/{siteId}/sections            → list available sections
PUT    /api/professional/sites/{siteId}/sections/countdown  → create or update (recursive settings merge)
DELETE /api/professional/sites/{siteId}/sections/countdown  → soft delete (sets is_active = false)
```

### Public-site payload

The countdown block appears in `GET /public/site` as an entry in the `sections[]` array, alongside other published section blocks:

```json
{
  "id": "...",
  "block_type": "countdown",
  "block_group": "sections",
  "sort_order": 4,
  "is_active": true,
  "settings": { ...shape above... }
}
```

Server-side inclusion rule: `is_active && is_enabled`. Client-side rendering rule: hide entirely when `now >= expiry_time`.

## Config

Single edit to `config/sidest.php`:

```php
'section_block_types' => [
    'gallery', 'services', 'shop', 'booking', 'contacts_collection',
    'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
    'countdown',
],
```

And an append in `ProfessionalSectionBlockController::syncAllowedSections()` so that `countdown` is added to the allowed-sections list for brand, professional, and influencer account types.

## Testing

New Pest feature test file: `tests/Feature/Professional/Site/CountdownSectionBlockTest.php`.

| # | Test |
|---|---|
| 1 | Authenticated affiliate can create a countdown with valid timeline + per-state config (200, correct serialization) |
| 2 | Partial PATCH updates only the specified settings path; other settings intact (exercises recursive merge) |
| 3 | `expiry_time <= drop_time` returns 422 with a clear error |
| 4 | CTA label without URL (or vice-versa) returns 422 |
| 5 | Invalid URL schemes (`javascript:`, `mailto:`, etc.) are rejected by the allowlist regex |
| 6 | Published countdown appears in the public-site payload `sections[]` with settings intact |
| 7 | Draft countdown (`is_active = false`) is NOT in the public payload |
| 8 | Brand, professional, and influencer accounts can each create a countdown (all three enabled by default) |
| 9 | String fields (title, state headlines, CTA labels) have HTML tags stripped on input |

Not tested (out of scope for backend tests):
- Client-side state derivation and countdown ticking
- Cache invalidation specifics (covered by shared observer tests)

## Non-goals / deferred

- **Multiple countdowns per page.** Requires a pluralized controller shape and a stacking decision on the frontend. Revisit when a real affiliate asks.
- **Grace window after expiry.** A `settings.expired_grace_hours` field would let the expired state linger for a configurable window before auto-hiding. Revisit if expired-state copy becomes a common authoring pattern.
- **Per-countdown timezone.** If an affiliate needs to express "drop at 9 PM New York time" while living in London, we'd add `settings.timezone` as an IANA name. Revisit if asked.
- **Filtered product display from the countdown.** URL fragments like `#shop?products=abc,def` work today as frontend-only conventions; promoting this to a structured field is premature.
- **Notify-me / reminder emails.** Out of scope.

## Open questions

None at this time. All product decisions resolved during design.

## Rollout

1. Backend — add config entry, validation rules, availability gating, tests. Ship to dev → staging → production via normal Laravel Cloud auto-deploy. No migration, no data backfill.
2. Frontend (editor) — Tobias adds the countdown authoring UI (timeline picker + three state editors + URL picker).
3. Frontend (public site) — Tobias adds the countdown renderer that ticks time and derives state.
4. Enable for a small pilot group first (likely the initial hair-care brand cohort); monitor Nightwatch for exceptions in the upsert path and public-site render path.
