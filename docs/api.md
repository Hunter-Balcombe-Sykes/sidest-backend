# API Reference

This document is the single source of truth for backend so the frontend can build:

- Public mini-site (read-only site payload + lead capture + email subscribe + analytics)
- Barber dashboard (profile + site settings + links + sections + services + gallery + customers + analytics + notifications)
- Staff dashboard (staff-only browsing + admin editing tools)
- Backend: Laravel API (this repo)
- Auth: Supabase Auth (JWT access token)
- Media: Supabase Storage (direct-from-frontend upload)

## Contents

- Environments and Base URLs
- Authentication (Supabase JWT)
- Roles and permissions
- Data Models
- Conventions (headers, errors, pagination, rate limits)
- Public Mini-Site API
- Professional (Barber) Dashboard API
- Staff API
- Supabase Storage uploads (RLS behavior + frontend upload flow)
- Test users and getting tokens
- Insomnia collection
- Frontend env var checklist
- Backend env var checklist
- Known implementation gotchas

## 1) Environments and Base URLs

All endpoints below are served under the Laravel API base URL, with the default /api prefix.

### API base URL

- API base URL is your APP_URL (Laravel). Example: https://api.comet.app
- All API routes live under /api. Example: https://api.comet.app/api/me Public mini-site domain rules Public mini-site routes are domain-scoped. They MUST be called on the mini-site host, not the API host.
- Host pattern: https://{subdomain}.{COMET_PUBLIC_DOMAIN}
- Public API base URL: https://{subdomain}.{COMET_PUBLIC_DOMAIN}/api
- Example: https://joshbarber.localtest.me/api/public/site Local development tip
- Use a wildcard-friendly domain such as localtest.me or lvh.me so subdomains resolve to 127.0.0.1.
- Set COMET_PUBLIC_DOMAIN=localtest.me and APP_URL=http://api.localtest.me (or similar).

## 2) Authentication (Supabase JWT)

### What the frontend sends

All authenticated requests MUST include the Supabase access token:

- Header: Authorization: Bearer <SUPABASE_ACCESS_TOKEN>
- Also send: Accept: application/json
- For JSON bodies: Content-Type: application/json Tokens are verified by the supabase.jwt middleware using Supabase JWKS + issuer/audience settings.

### No login endpoint in Comet

- Comet does not manage passwords or sessions.
- Frontend signs in with Supabase Auth.
- Frontend calls Comet API with the returned access_token.

### Bootstrap required for new users

A Supabase-authenticated user is not automatically a professional in Comet.

**For a new user, call:**

- POST /api/bootstrap This creates the core.professionals and core.sites rows tied to the Supabase user id (sub in the JWT).

If you skip bootstrap, professional routes will return 403 with a message prompting bootstrap.

### `POST /api/bootstrap`

### Auth: Required (Supabase JWT)

**Purpose:** Create a new professional account and associated site. Auto-generates a unique handle from display_name if not provided.

**Request body:**

```json
{
"display_name": "Josh Barber",
"primary_email": "josh@example.com",
"phone": "+61400000000",
"first_name": "Josh",
"last_name": "Barber",
"country_code": "AU",
"timezone": "Australia/Sydney",
"handle": "joshbarber"
}
```

**Field notes:**
- `display_name` (required): Public-facing name (e.g., business name)
- `primary_email` (required): Contact email
- `phone` (required): Contact phone
- `first_name` (required): First name
- `last_name` (optional): Last name
- `country_code` (optional): 2-5 letter country code
- `timezone` (optional): IANA timezone
- `handle` (optional): Unique username/slug (if omitted, auto-generated from display_name)

**Response (201 or 200):**

```json
{
    "professional": {
        "id": "uuid",
        "handle": "josh-barber",
        "display_name": "Josh Barber",
        "primary_email": "josh@example.com",
        "phone": "+61400000000",
        "first_name": "Josh",
        "last_name": "Barber",
        "country_code": "AU",
        "timezone": "Australia/Sydney",
        "status": "active",
        "onboarding_step": 0
    },
    "site": {
        "id": "uuid",
        "professional_id": "uuid",
        "subdomain": "josh-barber",
        "is_published": false
    }
}
```

**Common status codes:** 200 (existing user bootstrapped again), 201 (new professional created), 401 (invalid JWT), 422 (validation error)

### Plans and Subscriptions

#### `GET /api/plans`

- Purpose: list all active subscription plans
- Auth: None
- Rate limit: general

**Response (200):**

```json
{
    "data": [
        {
            "id": "plan_basic",
            "name": "Basic",
            "description": "Perfect for getting started",
            "price_cents": 999,
            "currency_code": "USD",
            "billing_interval": "month",
            "entitlements": {
                "sites": 1,
                "team_members": 1,
                "services": 10
            }
        },
        {
            "id": "plan_pro",
            "name": "Professional",
            "description": "For growing businesses",
            "price_cents": 2499,
            "currency_code": "USD",
            "billing_interval": "month",
            "entitlements": {
                "sites": 3,
                "team_members": 5,
                "services": 100
            }
        }
    ]
}
```

**Common status codes: 200**

#### `GET /api/me/subscription`

- Purpose: get the current professional's active subscription
- Auth: Required (Professional)
- Rate limit: general

**Response (200):**

```json
{
    "data": {
        "id": "sub-123abc",
        "plan_id": "plan_basic",
        "plan": {
            "id": "plan_basic",
            "name": "Basic",
            "price_cents": 999,
            "currency_code": "USD",
            "billing_interval": "month",
            "entitlements": { "sites": 1, "team_members": 1, "services": 10 }
        },
        "status": "active",
        "current_period_start": "2026-01-12T05:12:00Z",
        "current_period_end": "2026-02-12T05:12:00Z",
        "trial_ends_at": null,
        "cancel_at_period_end": false,
        "ended_at": null
    }
}
```

**Common status codes: 200, 401, 404 (no subscription)**

#### `POST /api/me/subscription`

- Purpose: create a new subscription for the professional (usually during signup)
- Auth: Required (Professional)
- Rate limit: general

**Request body:**

```json
{
    "plan_id": "plan_basic",
    "trial_period_days": 14
}
```

**Response (201):**

```json
{
    "data": {
        "id": "sub-123abc",
        "plan_id": "plan_basic",
        "status": "trialing",
        "current_period_start": "2026-01-12T05:12:00Z",
        "current_period_end": "2026-02-12T05:12:00Z",
        "trial_ends_at": "2026-01-26T05:12:00Z"
    }
}
```

**Common status codes: 201, 401, 422 (already has subscription)**

#### `PATCH /api/me/subscription`

- Purpose: change the professional's current subscription plan
- Auth: Required (Professional)
- Rate limit: general

**Request body:**

```json
{
    "plan_id": "plan_pro"
}
```

**Response (200):**

```json
{
    "data": {
        "id": "sub-123abc",
        "plan_id": "plan_pro",
        "status": "active",
        "current_period_start": "2026-01-12T05:12:00Z",
        "current_period_end": "2026-02-12T05:12:00Z"
    }
}
```

**Common status codes: 200, 401, 404 (no subscription), 422 (invalid plan)**

#### `POST /api/me/subscription/cancel`

- Purpose: cancel the subscription at the end of the current billing period
- Auth: Required (Professional)
- Rate limit: general

**Response (200):**

```json
{
    "data": {
        "id": "sub-123abc",
        "status": "active",
        "cancel_at_period_end": true,
        "ended_at": null
    }
}
```

**Common status codes: 200, 401, 404 (no subscription), 422 (already canceled)**

#### `POST /api/me/subscription/resume`

- Purpose: resume a subscription that was scheduled to be canceled
- Auth: Required (Professional)
- Rate limit: general

**Response (200):**

```json
{
    "data": {
        "id": "sub-123abc",
        "status": "active",
        "cancel_at_period_end": false,
        "ended_at": null
    }
}
```

**Common status codes: 200, 401, 404 (no subscription)**

### Common status codes: 200, 201, 401, 422

## 3) Roles and permissions

- Public (anon): no token, can only access public mini-site routes and health routes.
- Professional: valid Supabase JWT AND a core.professionals row where auth_user_id matches JWT sub.
- Staff: valid Supabase JWT AND a core.comet_staff row where auth_user_id matches JWT sub.
- Staff admin: staff plus is_admin = true in core.comet_staff.

### RLS behavior

Comet reads/writes Postgres through Laravel using the configured database user.

- Database table RLS does not gate Comet API calls if the DB user bypasses RLS (typical for server-side roles).
- Supabase Storage DOES use RLS policies. Upload access is controlled by storage.objects policies.

## 4) Data Models

All ids are UUID strings. Timestamps are ISO 8601 strings when returned by the API.

### Professional
| Name                    | Type     | Nullable | Example                                  | Constaints / Notes                                         |
|-------------------------|----------|----------|------------------------------------------|------------------------------------------------------------|
| id                      | uuid     | no       | **4db0c0b4-5e4a-4f8d-8d49-3e5b0b62d9a1** | Primary Key                                                |
| auth_user_id            | uudi     | no       | c1b2... (Supabase user id)               | JWT sub, set at bootstrap                                  |
| handle                  | string   | no       | joshbarber                               | unqiue (case-sensitive), 3-40 char, must start with letter |
| display_name            | string   | no       | Josh Barber                              | Max 80                                                     |
| bio                     | string   | yes      | Mobile Barber in Darwin                  | Max 2000, also mirrored from bio section when updated      |
| primary_email           | email    | no       | josh@example.copm                        | Max 255                                                    |
| phone                   | string   | no       | +6140000000                              | Max 40                                                     |
| first_name              | string   | no       | Josh                                     | Max 80                                                     |
| last_name               | string   | no       | Hunter                                   | Max 80                                                     |
| public_contact_number   | string   | yes      | +6140000000                              | Max 40, public-facing contact                              |
| public_contact_email    | string   | yes      | bookings@example.com                     | Max 255, public-facing contact                             |
| icon_bucket             | string   | yes      | public-assets                            | Supabase Storage bucket                                    |
| icon_path               | string   | yes      | professionals/<proid>/icon.jpg           | Path within bucket                                         |
| headshot_bucket         | string   | yes      | public-assets                            | Supabase Storage Bucket                                    |
| headshot_path           | string   | yes      | professionals/<proid>/headshot.jpg       | Path wihtin bucket                                         |
| location_street_address | string   | yes      | 1 Smith Street                           | Max 255                                                    |
| location_city           | string   | yes      | Darwin                                   | Max 120                                                    |
| location_state          | string   | yes      | NT                                       | Max 120                                                    |
| location_postcode       | string   | yes      | 1800                                     | Max 20                                                     |
| location_country        | string   | yes      | Australia                                | Max 120                                                    |
| status                  | string   | no       | active                                   | active or suspended (staff-admin can update)               |
| onboarding_step         | integer  | yes      | 1                                        | 0+                                                         |
| created_at              | datetime | yes      | 2026-01-12T05:12:00Z                     |                                                            |
| updated_at              | datetime | yes      | 2026-01-12T05:12:00Z                     |                                                            |


### Site
| Name            | Type     | Nullable | Example                   | Constaints / Notes                                                                                                |
|-----------------|----------|----------|---------------------------|-------------------------------------------------------------------------------------------------------------------|
| id              | uuid     | no       | b8e7...                   | Primary Key                                                                                                       |
| professional_id | uudi     | no       | 4db0...                   | Owner / Professional                                                                                              |
| subdomain       | string   | no       | joshbarber                | unqiue (case-sensitive), 3-63,lowercase letters/numbers/hyphen; no leading/trailing hyphen; reserved list blocked |
| is_published    | boolean  | no       | false                     | if false, public site endpoint returns 404 or 403 depending on route                                              |
| theme_id        | uuid     | yes      | 9f23                      | Must exist in themes table                                                                                        |
| settings        | object   | yes      | {...}                     | Freeform JSON object merged on PATCH                                                                              |
| banner_bucket   | string   | yes      | public-assets             | Supabase Storage Bucket                                                                                           |
| banner_path     | string   | yes      | sites/<siteid>/banner.jpg | Path within bucket                                                                                                |
| created_at      | datetime | yes      | 2026-01...                |                                                                                                                   |
| updated_at      | datetime | yes      | 2026-01...                |                                                                                                                   |

### Customer
| Name            | Type     | Nullable | Example                | Constraints / Notes     |
|-----------------|----------|----------|------------------------|-------------------------|
| id              | uuid     | no       | `a3c1...`              | Primary key             |
| professional_id | uuid     | yes      | `4db0...`              | Set by server on create |
| full_name       | string   | no       | `Sam Smith`            | Max 120                 |
| email           | email    | yes      | `sam@example.com`      | Max 255                 |
| phone           | string   | yes      | `+61411111111`         | Max 40                  |
| notes           | string   | yes      | `Prefers Fridays`      | Max 5000                |
| source          | string   | yes      | `manual`               | manual or site_lead     |
| external_id     | string   | yes      | `square:cus_123`       | Max 255                 |
| created_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| updated_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| deleted_at      | datetime | yes      | `2026-01-20T05:12:00Z` | Soft delete timestamp   |

### Service
| Name            | Type     | Nullable | Example                | Constraints / Notes     |
|-----------------|----------|----------|------------------------|-------------------------|
| id              | uuid     | no       | `a3c1...`              | Primary key             |
| professional_id | uuid     | no       | `4db0...`              | Owner professional      |
| category_id     | uuid     | yes      | `c5e2...`              | Optional service category |
| title           | string   | no       | `Standard Haircut`     | Max 255                 |
| description     | string   | yes      | `Professional cut`     | Max 2000                |
| price_cents     | integer  | no       | `3500`                 | Must be positive        |
| currency_code   | string   | yes      | `AUD`                  | ISO 4217 code           |
| duration_minutes| integer  | yes      | `30`                   | Must be positive        |
| is_active       | boolean  | no       | `true`                 | If false: hidden from public site |
| sort_order      | integer  | no       | `0`                    | Non-negative            |
| created_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| updated_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| deleted_at      | datetime | yes      | `2026-01-20T05:12:00Z` | Soft delete timestamp   |

### ServiceCategory
| Name            | Type     | Nullable | Example                | Constraints / Notes     |
|-----------------|----------|----------|------------------------|-------------------------|
| id              | uuid     | no       | `c5e2...`              | Primary key             |
| professional_id | uuid     | no       | `4db0...`              | Owner professional      |
| title           | string   | no       | `Men's Cuts`           | Max 255                 |
| description     | string   | yes      | `All mens haircuts`    | Max 2000                |
| sort_order      | integer  | no       | `0`                    | Non-negative            |
| created_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| updated_at      | datetime | yes      | `2026-01-12T05:12:00Z` |                         |
| deleted_at      | datetime | yes      | `2026-01-20T05:12:00Z` | Soft delete timestamp   |

### Plan
| Name             | Type     | Nullable | Example       | Constraints / Notes              |
|------------------|----------|----------|---------------|----------------------------------|
| id               | string   | no       | `plan_basic`  | Primary key; provider-managed    |
| name             | string   | no       | `Basic`       | Max 255                          |
| description      | string   | yes      | `For starters`| Max 2000                         |
| price_cents      | integer  | no       | `999`         | Price in cents (USD)             |
| currency_code    | string   | no       | `USD`         | 3-letter code                    |
| billing_interval | string   | no       | `month`       | month or year                    |
| entitlements     | object   | no       | See below     | JSON object with plan features   |
| is_active        | boolean  | no       | `true`        |                                  |
| sort_order       | integer  | no       | `0`           | Display order                    |
| created_at       | datetime | yes      | `2026-01-12T05:12:00Z` |                                  |
| updated_at       | datetime | yes      | `2026-01-12T05:12:00Z` |                                  |

### Subscription
| Name                | Type     | Nullable | Example              | Constraints / Notes                                 |
|---------------------|----------|----------|----------------------|-----------------------------------------------------|
| id                  | uuid     | no       | `sub-123...`         | Primary key                                         |
| professional_id     | uuid     | no       | `4db0...`            | Owner professional                                  |
| plan_id             | string   | no       | `plan_basic`         | Foreign key to Plan                                 |
| status              | string   | no       | `active`             | trialing, active, past_due, canceled, ended        |
| current_period_start| datetime | no       | `2026-01-12T05:12:00Z` | Billing period start                               |
| current_period_end  | datetime | no       | `2026-02-12T05:12:00Z` | Billing period end                                 |
| trial_ends_at       | datetime | yes      | `2026-01-19T05:12:00Z` | When trial period ends (if any)                    |
| cancel_at_period_end| boolean  | no       | `false`              | Will cancel at period end if true                  |
| ended_at            | datetime | yes      | `2026-01-20T05:12:00Z` | When subscription ended                            |
| provider_payload    | object   | no       | `{}`                 | External provider data (Stripe, etc)               |
| created_at          | datetime | yes      | `2026-01-12T05:12:00Z` |                                                     |
| updated_at          | datetime | yes      | `2026-01-12T05:12:00Z` |                                                     |

### Link Block (core.blocks where block_group = links)
| Name            | Type    | Nullable | Example                       | Constraints / Notes                                                                       |
|-----------------|---------|----------|-------------------------------|-------------------------------------------------------------------------------------------|
| id              | uuid    | no       | `d5b0...`                     | Primary key                                                                               |
| professional_id | uuid    | no       | `4db0...`                     | Owner professional                                                                        |
| site_id         | uuid    | no       | `b8e7...`                     | Owner site                                                                                |
| block_group     | string  | no       | `links`                       | Always links                                                                              |
| block_type      | string  | no       | `link`                        | Always link                                                                               |
| title           | string  | no       | `Book now`                    | Max 80                                                                                    |
| url             | string  | no       | `https://booking.example.com` | Max 2048; must be valid URL                                                               |
| icon_key        | string  | yes      | `calendar`                    | Must be one of config comet.link_block_icon_keys                                          |
| sort_order      | integer | no       | `0`                           | Non-negative                                                                              |
| is_active       | boolean | no       | `true`                        | If false: hidden from public site and click tracking is forbidden                         |
| settings        | object  | yes      | `{ "open_in_new_tab": true }` | Allowed keys only: open_in_new_tab, rel_nofollow, rel_sponsored, rel_ugc, highlight, note |

### PublicSiteData (view of returned GET /api/public/site)
| Name         | Type    | Nullable | Example                                                   | Constraints / Notes                            |
|--------------|---------|----------|-----------------------------------------------------------|------------------------------------------------|
| published    | boolean | no       | `true`                                                    | Derived from site is_published                 |
| site         | object  | no       | `{ id, subdomain, settings, banner_bucket, banner_path }` |                                                |
| professional | object  | no       | `{ id, handle, display_name, bio, ... }`                  | Includes public-facing image + location fields |
| theme        | object  | yes      | `{ id, key, name, config }`                               | theme.config is an object                      |
| blocks       | array   | no       | `[ LinkBlock \| SectionBlock ]`                           | Only active blocks are returned                |
| gallery      | array   | no       | `[ { id, bucket, path, alt_text, sort_order } ]`          | Only active images returned                    |
| services     | array   | no       | `[ { id, title, price_cents, ... } ]`                     | Only active services returned                  |

### Analytics Event Payloads
| Name                  | Type     | Nullable | Example                 | Constraints / Notes                                                              |
|-----------------------|----------|----------|-------------------------|----------------------------------------------------------------------------------|
| occurred_at           | datetime | no       | `2026-01-12T05:12:00Z`  | Required by validation, but Stage 1-2 currently stores server time (now)         |
| site_id               | uuid     | yes      | `b8e7...`               | Optional if called on the correct subdomain; the API resolves site from host too |
| session_id            | string   | yes      | `sess_abc123`           | Max 255                                                                          |
| visitor_id            | uuid     | yes      | `f2a1...`               | Optional stable client id if you have one                                        |
| referrer              | string   | yes      | `https://instagram.com` | Max 2048; if missing, backend uses request Referer header                        |
| utm_source            | string   | yes      | `instagram`             | Max 120                                                                          |
| utm_medium            | string   | yes      | `social`                | Max 120                                                                          |
| utm_campaign          | string   | yes      | `jan_promo`             | Max 120                                                                          |
| block_id (click only) | uuid     | no       | `d5b0...`               | Must be an active link block belonging to the site                               |

### Plans (core.plans)
| Name            | Type     | Nullable | Example                | Constraints / Notes     |
|-----------------|----------|----------|------------------------|-------------------------|
| id              | uuid     | no       | `a3c1...`              | Primary key             |
| professional_id | uuid     | yes      | `4db0...`              | Set by server on create |
| name            | string   | no       | `Monthly`              | Max 120                 |
| price_cents     | integer  | no       | `1000`                 | Must be positive         |
| currency_code   | string   | no       | `AUD`                  | Must be a valid ISO 4217 code |
| duration_months | integer  | no       | `12`                   | Must be positive         |
| is_active       | boolean  | no       | `true`                 |                         |

### Subscriptions (core.subscriptions)
| Name            | Type     | Nullable | Example                | Constraints / Notes     |
|-----------------|----------|----------|------------------------|-------------------------|
| id              | uuid     | no       | `a3c1...`              | Primary key             |
| professional_id | uuid     | yes      | `4db0...`              | Set by server on create |
| list_key        | string   | no       | `marketing`             | Must be one of marketing,

## 5) Conventions (headers, errors, pagination, rate limits)

### Standard headers

- Accept: application/json
- Content-Type: application/json (for JSON bodies)
- Authorization: Bearer <SUPABASE_ACCESS_TOKEN> (authenticated routes only)

### Standard error format

**Most Comet errors use:**

```json
{
    "message": "Human readable message",
    "errors": {
        "field": [
            "Reason"
        ]
    }
}
```

}

Some framework-level errors (for example abort(404)) may return only:

```json
{ "message": "Not Found" }
```
Common status codes
- 200 OK: successful read or update
- 201 Created: successful create
- 204 No Content: no body (not commonly used yet)
- 400 Bad Request: bad payload or could not determine site from URL
- 401 Unauthorized: missing or invalid token
- 403 Forbidden: valid token but forbidden (missing professional profile, staff required, unpublished site, inactive block)
- 404 Not Found: resource not found, or site not found
- 409 Conflict: cannot delete due to constraints (staff force delete professional)
- 422 Unprocessable Entity: validation errors or business rule (gallery limit)
- 429 Too Many Requests: rate limited Example error responses Validation failure (422): { "message": "Validation failed", "errors": { "handle": ["The handle field is required."] } } Unauthorized (401): { "message": "Missing Bearer token" } Forbidden (403): { "message": "Professional profile missing for this user. Complete bootstrap first." } Not found (404): { "message": "Site not found." } Pagination
- Some list endpoints return { dataKey: [...], meta: {...} }.
- Professional customers list returns { customers: [...], pagination: {...} }.
- Staff list endpoints typically return { customers: [...], meta: {...} }.
- Query params: page, per_page (limits are enforced server-side).

### Rate limits (per IP)

- public-site: 60 requests per minute
- analytics: 120 requests per minute leads: 10 requests per minute

## 6) Public Mini-Site API

All routes below are unauthenticated and domain-scoped to the mini-site host:
https://{subdomain}.{COMET_PUBLIC_DOMAIN}

### `GET /api/public/site`

- Purpose: fetch the published mini-site payload for rendering
- Auth: None
- Rate limit: public-site Response (200): { "published": true, "site": { "id": "…", "subdomain": "…", "settings": { }, "banner_bucket": null, "banner_path": null }, "professional": { "id": "…", "handle": "…", "display_name": "…", "bio": null, "icon_bucket": null, "icon_path": null }, "theme": { "id": "…", "key": "…", "name": "…", "config": { } }, "blocks": [], "gallery": [], "services": [] } Common status codes: 200, 404 (site not found), 403 (site not published)

### `POST /api/public/analytics/pageviews`

- Purpose: record a page view
- Auth: None
- Rate limit: analytics Request body: { "occurred_at": "2026-01-12T05:12:00Z", "site_id": "optional uuid", "session_id": "optional string", "visitor_id": "optional uuid", "referrer": "optional string", "utm_source": "optional string", "utm_medium": "optional string", "utm_campaign": "optional string" } Response (201): { "message": "Pageview recorded", "visit_id": "uuid" } Common status codes: 201, 404, 403, 422, 429

### `POST /api/public/analytics/clicks`

- Purpose: record a link click
- Auth: None
- Rate limit: analytics Request body: { "occurred_at": "2026-01-12T05:12:00Z", "block_id": "uuid", "site_id": "optional uuid", "session_id": "optional string", "visitor_id": "optional uuid", "referrer": "optional string", "utm_source": "optional string", "utm_medium": "optional string", "utm_campaign": "optional string" } Response (201): { "message": "Click recorded", "click_id": "uuid" } Common status codes: 201, 404 (site or block), 403 (unpublished or inactive), 422, 429

### `POST /api/public/customers`

- Purpose: submit a customer lead (name + contact details)
- Auth: None
- Rate limit: leads Request body: { "occurred_at": "2026-01-12T05:12:00Z", "full_name": "Sam Smith", "email": "sam@example.com", "phone": "+61411111111", "notes": "optional", "form_started_at_ms": 1700000000000 } Response (201): { "message": "Lead captured", "lead_id": "uuid" } Common status codes: 201, 404, 403, 422, 429

### `POST /api/public/subscribe`

- Purpose: subscribe an email address to a marketing list for the professional
- Auth: None
- Rate limit: public-site Request body: { "email": "sam@example.com", "full_name": "Sam Smith", "list_key": "marketing" } Response (200): { "ok": true, "subscribed": true, "list_key": "marketing" } Common status codes: 200, 404, 400 (cannot determine site), 422, 429

### `GET /api/public/unsubscribe/{token}`

- Purpose: unsubscribe a recipient using the token stored with the email subscription
- Auth: None
- Note: this route is not domain-scoped; it is served on the API host.

**Response (200):**

```json
{ "ok": true, "unsubscribed": true }
```
Common status codes: 200, 404 (token not found), 429

## 7) Professional (Barber) Dashboard API

All routes below require: Authorization header AND a professional profile (current.pro middleware).

### `GET /api/me`

- Purpose: bootstrap dashboard UI with current professional, site, blocks, services, and customer count
- Auth: Required Response (200): { "uid": "supabase-user-uuid", "professional": { "...": "..." }, "site": { "...": "..." }, "blocks": [], "services": [], "customers_count": 0 } Common status codes: 200, 401, 403

### `PATCH /api/me`

- Purpose: update professional profile fields Request body (all fields optional; if provided they are validated): { "display_name": "Josh Barber", "bio": "Mobile barber", "public_contact_email": "bookings@example.com", "icon_bucket": "public-assets", "icon_path": "professionals/<proId>/icon.jpg" } Response (200): { professional: ... } Common status codes: 200, 401, 403, 422

### `GET /api/site`

- Purpose: fetch site record for the logged-in professional Response (200): { site: ... }

### `PATCH /api/site`

- Purpose: update site settings, subdomain, theme_id, and banner image fields Request body: { "subdomain": "joshbarber", "theme_id": "uuid or null", "settings": { "primary_color": "#000000" }, "banner_bucket": "public-assets", "banner_path": "sites/<siteId>/banner.jpg" } Response (200): { site: ... } Common status codes: 200, 401, 403, 422

### `PATCH /api/site/visibility`

- Purpose: publish or unpublish the mini-site Request body: { "published": true } Response (200): { published: true } Services
- GET /api/services
- POST /api/services
- GET /api/services/{service}
- PATCH /api/services/{service}
- DELETE /api/services/{service}
- POST /api/services/reorder
- POST /api/services/{service}/restore (requires trashed binding)

**Store/Update body:**

```json
{
"title": "Standard cut",
"category": "Mens",
"description": "Optional",
"price_cents": 3500,
"currency_code": "AUD",
"duration_minutes": 30,
"is_active": true
}
```

**Reorder body:**

```json
{ "ids": ["uuid1","uuid2"] }
```

### Service Categories

- GET /api/service-categories
- POST /api/service-categories
- GET /api/service-categories/{category}
- PATCH /api/service-categories/{category}
- DELETE /api/service-categories/{category}
- POST /api/service-categories/reorder
- POST /api/service-categories/{category}/restore (requires trashed binding)

**Store/Update body:**

```json
{
"title": "Men's Cuts",
"description": "Optional",
"sort_order": 0
}
```

**Reorder body:**

```json
{ "ids": ["uuid1","uuid2"] }
```

### Service Layout Reorder

- POST /api/services/reorder-layout

**Body:**

```json
{
  "layout": [
    {
      "type": "category",
      "id": "category-uuid",
      "services": ["service-uuid1", "service-uuid2"]
    },
    {
      "type": "category",
      "id": "category-uuid-2",
      "services": ["service-uuid3"]
    }
  ]
}
```

### `GET /api/analytics`

- Purpose: analytics summary for the logged-in professional
- Query: days=30 or from=YYYY-MM-DD&to=YYYY-MM-DD Response (200): { "range": { "from": "2026-01-01", "to": "2026-01-30" }, "totals": { "visits": 0, "unique_visitors": 0, "clicks": 0, "unique_clickers": 0, "ctr_percent": 0 }, "charts": { "visits_by_day": [], "clicks_by_day": [] }, "top_links": [] } Links (Link blocks)
- GET /api/links
- POST /api/links
- PATCH /api/links/{block}
- DELETE /api/links/{block}
- POST /api/links/reorder Store/Update body: { "title": "Book now", "url": "https://booking.example.com", "icon_key": "calendar", "is_active": true, "settings": { "open_in_new_tab": true } } Common status codes: 200, 201, 401, 403, 404, 422 Sections (Section blocks)

Allowed section block types are defined in config: gallery, services, education, social, booking, bio, work_history, promotional_text

- GET /api/sections
- PUT /api/sections/{blockType}
- POST /api/sections/reorder
- DELETE /api/sections/{blockType} Upsert body: { "is_active": true, "settings": { "text": "About me" } } Note: settings are merged (PATCH-style) when provided. Bio section text also updates professional.bio when sent.

### Customers

- GET /api/customers?search=...&page=1&per_page=25
- GET /api/customers/{customer}
- POST /api/customers
- PATCH /api/customers/{customer}
- DELETE /api/customers/{customer}
- POST /api/customers/{customer}/restore Store/Update body: { "full_name": "Sam Smith", "email": "sam@example.com", "phone": "+61411111111", "notes": "Optional" } Themes
- GET /api/themes
- POST /api/themes/{theme}/select Select response: { site: ... } Uploads prepare
- POST /api/uploads/prepare Request body: { "type": "icon", "content_type": "image/jpeg" } Response (200): { "bucket": "public-assets", "path": "professionals/<proId>/icon.jpg", "upsert": true } Gallery
- GET /api/gallery
- POST /api/gallery
- POST /api/gallery/reorder
- DELETE /api/gallery/{image} Store body: { "bucket": "public-assets", "path": "sites/<siteId>/gallery/<uuid>.jpg", "alt_text": "Optional" } Business rule: max 6 active gallery images (422 if exceeded).

### Notifications

- GET /api/me/notifications
- POST /api/me/notifications/{notification}/read
- POST /api/me/notifications/{notification}/dismiss Email subscribers (marketing list)
- GET /api/email-subscribers?list_key=marketing&status=subscribed&search=...
- GET /api/email-subscribers/export?list_key=marketing&status=subscribed

## 8) Staff API

Staff routes are for internal staff tooling. They require a staff JWT (user must exist in core.comet_staff).

### Staff (non-admin) routes

- GET /api/staff/me
- GET /api/staff/sites/{subdomain}
- GET /api/staff/professionals?q=...&status=...&per_page=...&page=...
- GET /api/staff/professionals/{professional}
- DELETE /api/staff/professionals/{professional} (soft delete)
- POST /api/staff/professionals/{professional}/restore
- GET /api/staff/professionals/{professional}/customers
- GET /api/staff/professionals/{professional}/customers/{customer}
- POST /api/staff/professionals/{professional}/customers/{customer}/restore
- GET /api/staff/professionals/{professional}/services
- GET /api/staff/professionals/{professional}/services/{service}
- POST /api/staff/professionals/{professional}/services/{service}/restore
- GET /api/staff/professionals/{professional}/service-categories
- GET /api/staff/professionals/{professional}/service-categories/{category}
- POST /api/staff/professionals/{professional}/service-categories/{category}/restore
- GET /api/staff/professionals/{professional}/site
- GET /api/staff/professionals/{professional}/analytics
- GET /api/staff/professionals/{professional}/links
- GET /api/staff/professionals/{professional}/sections Staff-admin routes (requires core.comet_staff.is_admin = true)
- PATCH /api/staff/professionals/{professional}/status
- PATCH /api/staff/professionals/{professional}
- DELETE /api/staff/professionals/{professional}/force (hard delete)
- PATCH /api/staff/professionals/{professional}/customers/{customer}
- DELETE /api/staff/professionals/{professional}/customers/{customer} (soft delete)
- DELETE /api/staff/professionals/{professional}/customers/{customer}/hard (hard delete)
- POST /api/staff/professionals/{professional}/services (create)
- PATCH /api/staff/professionals/{professional}/services/{service}
- DELETE /api/staff/professionals/{professional}/services/{service} (soft delete)
- DELETE /api/staff/professionals/{professional}/services/{service}/hard (hard delete)
- POST /api/staff/professionals/{professional}/services/reorder
- POST /api/staff/professionals/{professional}/service-categories (create)
- PATCH /api/staff/professionals/{professional}/service-categories/{category}
- DELETE /api/staff/professionals/{professional}/service-categories/{category} (soft delete)
- DELETE /api/staff/professionals/{professional}/service-categories/{category}/hard (hard delete)
- POST /api/staff/professionals/{professional}/service-categories/reorder
- POST /api/staff/professionals/{professional}/services/reorder-layout
- PATCH /api/staff/professionals/{professional}/site
- POST /api/staff/professionals/{professional}/links
- PATCH /api/staff/professionals/{professional}/links/{block}
- DELETE /api/staff/professionals/{professional}/links/{block}
- POST /api/staff/professionals/{professional}/links/reorder
- PUT /api/staff/professionals/{professional}/sections/{blockType}
- POST /api/staff/professionals/{professional}/sections/reorder
- DELETE /api/staff/professionals/{professional}/sections/{blockType}
- POST /api/staff/notifications Staff analytics summary endpoints Stage 1-2 staff analytics is:
- GET /api/staff/professionals/{professional}/analytics It returns totals, daily charts, and top links for the selected professional.

It requires a staff JWT (core.comet_staff).

## 9) Supabase Storage uploads (RLS behavior + frontend upload flow)

Uploads are direct-from-frontend to Supabase Storage. Comet provides the path rules via /api/uploads/prepare.

### Supported upload types

### icon: professionals/<professional_id>/icon.<ext> (upsert true)

-
- headshot: professionals/<professional_id>/headshot.<ext> (upsert true)
- banner: sites/<site_id>/banner.<ext> (upsert true)
- gallery: sites/<site_id>/gallery/<uuid>.<ext> (upsert false, max 6 active images)

### Allowed content types: image/jpeg, image/png, image/webp

### Frontend upload flow

1. Call POST /api/uploads/prepare with type and content_type.
2. Use Supabase Storage client with the logged-in user session to upload to the returned bucket + path.
3. If type is icon or headshot: PATCH /api/me to set icon_bucket/icon_path or headshot_bucket/headshot_path.
4. If type is banner: PATCH /api/site to set banner_bucket/banner_path.
5. If type is gallery: POST /api/gallery with bucket + path (+ optional alt_text) to create the DB row.

### Storage RLS notes

- Storage access is controlled by policies on storage.objects.
- Paths are validated server-side when creating gallery DB rows.
- If storage upload returns 403, the bucket policy does not allow that path for the current user.

## 10) Test users and getting tokens

Tokens come from Supabase Auth. Comet does not issue tokens.

### Create test users

- Professional user: create in Supabase Auth, then call POST /api/bootstrap once.
- Staff user: create in Supabase Auth, then insert a row into core.comet_staff with auth_user_id = the Supabase user id.
- Staff admin: same as staff user, but set is_admin = true.

### Get an access token via Supabase REST

### Request: POST/auth/v1/token?grant_type=password

### Headers: apikey: SUPABASE_ANON_KEY, Content-Type: application/json

### Body:

Response includes access_token. Use that token as the Authorization Bearer token when calling Comet.
This flow is included in the Insomnia collection as Login requests.

## 11) Insomnia collection

Import the provided Insomnia export JSON.
It contains requests for all Stage 1-2 endpoints plus Supabase login requests.

-
-
- Set workspace environment variables first (api_base_url, public_api_base_url, supabase_url, supabase_anon_key, access_token, subdomain, ids).

## 12) Frontend env var checklist

- SUPABASE_URL
- SUPABASE_ANON_KEY
- API_BASE_URL (example: https://api.comet.app/api)
- PUBLIC_DOMAIN (example: comet.app or localtest.me)
- MEDIA_BUCKET (default: public-assets)
- Optionally: STAFF_DASHBOARD_ENABLED flag if you ship staff tooling in the same frontend

## 13) Backend env var checklist

### Core Laravel

- APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL
- LOG_LEVEL Database
- DB_CONNECTION=pgsql
- DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
- DB_SEARCH_PATH (recommended: public,core,analytics)
- DB_STATEMENT_TIMEOUT (ms), DB_LOCK_TIMEOUT (ms)

### Supabase JWT verification

- SUPABASE_URL
- SUPABASE_ANON_KEY
- SUPABASE_JWT_ISSUER
- SUPABASE_JWT_AUD (default: authenticated)
- SUPABASE_JWKS_URL
- SUPABASE_JWKS_CACHE_SECONDS (default: 600)

### Comet app settings

- COMET_PUBLIC_DOMAIN (used for domain-scoped public routes)
- COMET_MEDIA_BUCKET (default: public-assets)
- SOFT_DELETE_RETENTION_DAYS (default: 30)

### Optional: cache, queues, mail (needed for staff broadcast emails)

- CACHE_STORE, REDIS_URL or REDIS_HOST/REDIS_PASSWORD
- QUEUE_CONNECTION (redis recommended)
- MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS

## 14) Known implementation gotchas

### Domain-scoped public routes

- If you call /api/public/site on the API host instead of {subdomain}.{COMET_PUBLIC_DOMAIN}, the route may not match or may return 404.
- Always use public_api_base_url = https://{subdomain}.{COMET_PUBLIC_DOMAIN}/api for public routes.

### Analytics timestamps

- Public analytics requests require occurred_at for validation, but Stage 1-2 stores server time (now) in the database.

Send the current time anyway.

### Gallery limits and ordering

- Max 6 active gallery images. The prepare endpoint and the gallery store endpoint both enforce this.
- Reorder endpoints accept an ids array; any omitted ids will be appended in existing order.
