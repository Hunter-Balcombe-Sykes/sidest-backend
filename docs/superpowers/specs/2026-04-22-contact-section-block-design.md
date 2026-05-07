# Contact Section Block — Design

**Status:** Draft
**Date:** 2026-04-22
**Owner:** Josh
**Target:** v1 (beta)

## Problem

Affiliates on Partna have no first-class way to receive direct enquiries from visitors on their public page. The newsletter section captures email subscribers (passive list-building), but there is no form for a visitor to actively send a message — a wholesale enquiry, a press request, a booking question, a collaboration pitch. Affiliates work around this by listing a contact email in their bio, which has no structure, no audit trail, and no CRM integration.

A contact section block closes this gap. It gives affiliates a configurable enquiry form, routes submissions to a notification inbox of their choosing, stores each enquiry in the platform for reference, and automatically adds the submitter to their customer list as a lead.

## Scope

**In scope (v1):**
- New `contact` block type in the existing section-block system
- Singleton per site (one contact form per affiliate page)
- Fields: name, email, optional phone, subject (dropdown), message
- Subject dropdown: platform-default options merged with affiliate-configurable additions
- Affiliate-configurable notification email (dedicated inbox, not their account email)
- DB persistence: `site_enquiries` table, professional reads via dashboard
- Async email notification to affiliate's configured inbox after each submission
- Auto-upsert of submitter as `Customer` with `source = 'enquiry'` (mirrors newsletter behaviour)
- Affiliate can mark enquiries read/unread and soft-delete them
- Available to all three professional types: brand, professional, influencer

**Out of scope (deferred):**
- Multiple contact forms per page
- Reply-to header wiring (affiliate hits reply in their own inbox; can add as one-liner if requested)
- Attachment / file uploads on the enquiry form
- Auto-responder email to the submitter
- Enquiry status beyond read/unread (e.g. "replied", "archived")
- Enquiry analytics / volume stats

## Design decisions

### 1. Separate section block, not an extension of newsletter

Newsletter (passive email capture) and contact (active message) serve distinct UX purposes. Affiliates should be able to have one, the other, or both simultaneously. Merging them would complicate the authoring UI and the data model with no benefit.

### 2. Option B: DB storage + email notification, with customer upsert

Three approaches were considered:

- **A — Pure enquiry:** save to DB, email notification, no customer upsert
- **B — Enquiry + auto customer upsert:** mirrors newsletter; submitter enters CRM as a lead *(chosen)*
- **C — Optional customer upsert (toggle):** affiliate controls it via a `settings.capture_as_lead` boolean

**Rationale for B:** enquiry submitters are more intentional than newsletter signups — they typed a message. Making them leads automatically is the right default. `source = 'enquiry'` clearly distinguishes them from paying customers. The toggle (Option C) adds UI surface for a decision most affiliates will never make; YAGNI applies.

### 3. Subject dropdown: hybrid (platform defaults + affiliate additions)

Platform-default subject options live in `config/sidest.php`:

```php
'contact_subject_defaults' => [
    'General enquiry', 'Booking', 'Press', 'Collaboration', 'Other',
],
```

Affiliates can add custom options via `settings.subject_options` (array of strings). At render time and at submission validation time, the merged list is: platform defaults + affiliate additions. The affiliate cannot remove platform defaults in v1 — they can only extend.

### 4. Notification email is affiliate-configurable, not the account email

Affiliates often have a dedicated inbox for their public-facing site (e.g. `hello@mybrand.com`). Defaulting to their account email would route enquiries to a potentially personal or unmonitored address. The block cannot be published without `notification_email` set — enforced via `SectionVisibilityService`.

### 5. Async email via queued job

The notification email is always dispatched as a queued `SendEnquiryNotificationJob`, not sent synchronously in the controller. This keeps the submission endpoint fast and decouples email delivery failures from submission success. If the job fails, the enquiry record is already in the DB — nothing is lost.

### 6. Enquiries scoped to professional, not site

`site_enquiries.professional_id` is the ownership key, with `site_id` recorded for provenance. This mirrors how `Customer` records work — the professional owns the relationship regardless of which site it came from. If an affiliate ever has multiple sites, their enquiry inbox stays unified.

### 7. ip_hash for privacy-safe rate limiting

The raw IP is never stored (GDPR). A hash is stored to enable abuse detection (e.g. 100 submissions from one IP) without retaining personal data. Same pattern as `PublicEmailSubscriptionController`.

## Data model

### Table: `site.blocks` (no schema changes)

| Column | Value |
|---|---|
| `block_type` | `'contact'` |
| `block_group` | `'sections'` |
| `is_active` | boolean — affiliate's draft/live toggle |
| `is_enabled` | boolean — always `true` for all three professional types |
| `sort_order` | integer |
| `settings` | JSONB — see below |

### `settings` JSON shape

```json
{
  "headline": "Get in touch",
  "description": "Fill out the form and I'll get back to you.",
  "notification_email": "hello@mybrand.com",
  "cta_label": "Send message",
  "subject_options": ["Wholesale", "Stockist enquiry"]
}
```

All fields are nullable except `notification_email`, which is required to publish. `subject_options` is the affiliate's additions only — merged with platform defaults at validation/render time.

### Table: `site.enquiries` (new Supabase migration)

Placed in the `site` schema alongside `site.blocks` and `site.media` — this is site-owned visitor-submitted data.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `professional_id` | UUID FK | owner |
| `site_id` | UUID FK | submission origin |
| `name` | varchar(100) | |
| `email` | varchar(255) | lowercased |
| `phone` | varchar(30) nullable | |
| `subject` | varchar(100) | selected option from merged list |
| `message` | text | |
| `ip_hash` | varchar(64) | SHA-256 of submitter IP |
| `user_agent` | varchar(500) nullable | for abuse audit |
| `read_at` | timestamptz nullable | null = unread |
| `deleted_at` | timestamptz nullable | soft delete |
| `created_at` | timestamptz | |
| `updated_at` | timestamptz | |

Indexes: `(professional_id, created_at DESC)` (inbox list), `(site_id)`, `(ip_hash, created_at)` (abuse queries). Standard RLS policies matching the `site.blocks` pattern.

## Validation

### Block config (`UpsertSectionBlockRequest`, conditioned on `block_type === 'contact'`)

| Field | Rules |
|---|---|
| `settings.headline` | nullable, string, max:80, strip_tags |
| `settings.description` | nullable, string, max:200, strip_tags |
| `settings.notification_email` | nullable, email, max:255 |
| `settings.cta_label` | nullable, string, max:40, strip_tags |
| `settings.subject_options` | nullable, array, max:10 items |
| `settings.subject_options.*` | string, max:60, distinct |

### Public submission (`PublicEnquiryRequest`)

| Field | Rules |
|---|---|
| `name` | required, string, max:100, strip_tags |
| `email` | required, email:rfc, max:255 |
| `phone` | nullable, string, max:30, strip_tags |
| `subject` | required, string, validated against merged subject options list |
| `message` | required, string, min:10, max:2000, strip_tags |
| `website` | nullable, string, max:255 (honeypot) |
| `form_started_at_ms` | required, integer, min:0 (timing check) |

Subject validation resolves the site's block `settings.subject_options`, merges with platform defaults, and checks the submitted value is in that list. Prevents a crafted payload from injecting an arbitrary subject string.

**Bot protection** (same pattern as `PublicCustomerLeadController`):
- `website` field is a honeypot — if filled, the controller pretends success but discards the submission
- `form_started_at_ms` is an epoch-ms timestamp; submissions outside `sidest.form_timing.min_ms` to `sidest.form_timing.max_ms` (default 2500ms–12h) are rejected as `too_fast`

## API

### Block config (existing routes)

```
GET    /api/professional/sites/{siteId}/sections          → includes contact block with can_publish / requirement_reason
PUT    /api/professional/sites/{siteId}/sections/contact  → create or update (recursive settings merge)
DELETE /api/professional/sites/{siteId}/sections/contact  → soft delete (sets is_active = false)
```

### Public submission (new)

```
POST /public/enquiry
```

Middleware: `lead.log`, `throttle:leads`
- Reuses the existing `leads` rate limiter: 3/min per IP + 100/min per subdomain
- Reuses `lead.log` middleware for consistent abuse-rate logging across all lead forms

Registered in `routes/api/publicSite.php` (inside the `/public/*` group).

Request body:
```json
{
  "name": "Sarah Jones",
  "email": "sarah@example.com",
  "phone": "+44 7700 900000",
  "subject": "Wholesale",
  "message": "Hi, I'd love to stock your products...",
  "website": "",
  "form_started_at_ms": 1713787200000
}
```

Site resolved from `X-Site-Subdomain` header or `subdomain` query param (same pattern as newsletter / PublicCustomerLeadController).

Controller flow (`PublicEnquiryController::submit()`):
1. Validate (incl. honeypot + timing)
2. If honeypot filled → log `outcome=honeypot` to `LeadSubmission`, return `{ ok: true }` (fake success)
3. If timing out of range → log `outcome=too_fast`, return 422
4. Resolve site from subdomain
5. Verify `contact` block is active on that site — 422 if not
6. Validate subject against merged subject options
7. Save `Enquiry` record
8. Upsert submitter as `Customer` with `source = 'enquiry'`
9. Log `outcome=created` to `LeadSubmission` (unified analytics with other lead forms)
10. Dispatch `SendEnquiryNotificationJob`
11. Return `{ ok: true }`

Response:
```json
{ "ok": true }
```

### Professional enquiry inbox (new controller)

```
GET    /api/professional/enquiries              → paginated list, newest first
PATCH  /api/professional/enquiries/{id}         → { "read": true|false } mark read/unread
DELETE /api/professional/enquiries/{id}         → soft delete
```

`GET` response shape:
```json
{
  "data": [
    {
      "id": "...",
      "name": "Sarah Jones",
      "email": "sarah@example.com",
      "phone": "+44 7700 900000",
      "subject": "Wholesale",
      "message": "Hi, I'd love to stock your products...",
      "read_at": null,
      "created_at": "2026-04-22T14:00:00Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 3, "total": 52 }
}
```

## Email notification

**Mailable:** `App\Mail\SiteEnquiryNotification`
**Job:** `App\Jobs\Notifications\SendEnquiryNotificationJob`

- To: `settings.notification_email`
- Subject line: `New enquiry from {name} — {subject}`
- Body: name, email, phone (omitted if null), subject, message, submission timestamp, link to dashboard enquiries page
- No reply-to in v1 (affiliate replies from their own inbox)

If the job fails (bad address, mail provider outage), the enquiry record is already saved — no data loss. Horizon retry policy handles transient failures.

## Config

Two additions to `config/sidest.php`:

```php
// 1. Add 'contact' to section_block_types
'section_block_types' => [
    'gallery', 'services', 'shop', 'booking', 'contacts_collection',
    'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
    'countdown', 'contact',
],

// 2. Platform-default subject options for the contact block
'contact_subject_defaults' => [
    'General enquiry', 'Booking', 'Press', 'Collaboration', 'Other',
],
```

`allowed_sections` for each professional type gets `'contact'` appended (brand, professional, influencer all enabled).

## Visibility gate

`SectionVisibilityService` gets one new rule: `contact` block cannot be published unless `settings.notification_email` is a non-empty, valid email. Frontend uses the existing `can_publish` + `requirement_reason` fields to disable the publish toggle with a tooltip.

## Testing

### `tests/Feature/Professional/Site/ContactSectionBlockTest.php`

| # | Test |
|---|---|
| 1 | Affiliate can configure contact block with notification email (200) |
| 2 | Contact block cannot be published without `notification_email` (422) |
| 3 | Brand, professional, and influencer accounts can each configure a contact block |
| 4 | HTML tags in headline/description are stripped on input |

### `tests/Feature/PublicSite/PublicEnquiryTest.php`

| # | Test |
|---|---|
| 5 | Valid submission saves enquiry to DB with correct fields |
| 6 | Valid submission upserts submitter as Customer with `source = 'enquiry'` |
| 7 | Valid submission dispatches `SendEnquiryNotificationJob` |
| 8 | Subject not in merged options list is rejected (422) |
| 9 | Message under 10 chars is rejected (422) |
| 10 | Rate limit: 4th submission in a minute is rejected (429) — `throttle:leads` enforces 3/min per IP |
| 11 | Submission to site with no active contact block is rejected (422) |
| 12 | HTML tags in name/message are stripped on input |
| 13 | Honeypot `website` field filled → returns 200 but saves nothing, logs `outcome=honeypot` |
| 14 | `form_started_at_ms` under `min_ms` threshold → 422 with `outcome=too_fast` |
| 15 | Successful submission logs `outcome=created` to `LeadSubmission` |

### `tests/Feature/Professional/EnquiryTest.php`

| # | Test |
|---|---|
| 16 | Affiliate can list their enquiries (paginated, newest first) |
| 17 | Affiliate can mark an enquiry as read |
| 18 | Affiliate can soft-delete an enquiry |
| 19 | Affiliate cannot read another professional's enquiries (403) |

## Non-goals / deferred

- **Reply-to header:** a one-liner if affiliates request it
- **Auto-responder to submitter:** requires a Mailable + consent considerations
- **Enquiry status beyond read/unread:** revisit if affiliates need workflow features
- **File attachments:** significant complexity (media pipeline, storage), revisit on demand
- **Multiple contact forms per page:** same argument as countdown — revisit when a real affiliate asks
- **Affiliate removing platform-default subject options:** low demand, adds UI complexity

## Open questions

None. All product decisions resolved during design.

## Rollout

1. **Backend** — Supabase migration, model, config, validation, visibility gate, public endpoint, professional inbox endpoints, email job. Ship via normal Laravel Cloud auto-deploy.
2. **Frontend (editor)** — Tobias adds the contact block authoring UI (notification email field, subject option manager, copy fields).
3. **Frontend (public site)** — Tobias adds the contact form renderer.
4. **Monitor** — Check Nightwatch after deploy for exceptions on the submission endpoint and the notification job.
