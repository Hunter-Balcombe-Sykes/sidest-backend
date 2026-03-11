# API Reference

This document is the single source of truth for backend so the frontend can build:

- Public mini-site (read-only site payload + lead capture + email subscribe + analytics)
- Barber dashboard (profile + site settings + links + sections + services + gallery + customers + analytics + notifications)
- Staff dashboard (staff-only browsing + admin editing tools)
- Backend: Laravel API (this repo)
- Auth: Supabase Auth (JWT access token)
- Media: Laravel Cloud Object Storage (S3-compatible / Cloudflare R2) with server-side WebP processing

## Contents

- Environments and Base URLs
- Authentication (Supabase JWT)
- Roles and permissions
- Data Models
- Conventions (headers, errors, pagination, rate limits)
- Public Mini-Site API
- Professional (Barber) Dashboard API
- Staff API
- Image uploads & processing (server-side WebP via queue)
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

**Note:** Bootstrap automatically creates a free-tier subscription for new professionals. If a free plan (plan_key = `free`) exists in the plans table, the professional will be subscribed to it with status `active` and provider `internal`.

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

**Status logic:** If `trial_period_days` is provided and > 0, the subscription starts with status `trialing`. Otherwise it starts as `active`.

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

**Validation rules:**
- Subscription must exist (404 if not)
- Subscription must be active (status in `trialing` or `active`, and `ended_at` is null) — returns 422 otherwise
- Subscription must be scheduled for cancellation (`cancel_at_period_end` = true) — returns 422 otherwise
- Current billing period must not have ended (`current_period_end` must be in the future) — returns 422 otherwise

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

**Common status codes: 200, 401, 404 (no subscription), 422 (not active / not scheduled for cancellation / period ended)**

### Common status codes: 200, 201, 401, 422

## 3) Roles and permissions

- Public (anon): no token, can only access public mini-site routes and health routes.
- Professional: valid Supabase JWT AND a core.professionals row where auth_user_id matches JWT sub.
- Staff: valid Supabase JWT AND a core.comet_staff row where auth_user_id matches JWT sub.
- Staff admin: staff plus is_admin = true in core.comet_staff.

### RLS behavior

Comet reads/writes Postgres through Laravel using the configured database user.

- Database table RLS does not gate Comet API calls if the DB user bypasses RLS (typical for server-side roles).
- Image uploads go through the Comet API (server-side), not through Supabase Storage. Supabase Storage is not used at all — all media is stored on Laravel Cloud Object Storage (Cloudflare R2).

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
| location_street_address | string   | yes      | 1 Smith Street                           | Max 255                                                    |
| location_city           | string   | yes      | Darwin                                   | Max 120                                                    |
| location_state          | string   | yes      | NT                                       | Max 120                                                    |
| location_postcode       | string   | yes      | 1800                                     | Max 20                                                     |
| location_country        | string   | yes      | Australia                                | Max 120                                                    |
| status                  | string   | no       | active                                   | active or suspended (staff-admin can update)               |
| onboarding_step         | integer  | yes      | 1                                        | 0+                                                         |
| created_at              | datetime | yes      | 2026-01-12T05:12:00Z                     |                                                            |
| updated_at              | datetime | yes      | 2026-01-12T05:12:00Z                     |                                                            |
| square_access_token     | string   | yes      | *(encrypted)*                            | Encrypted; Square OAuth access token                       |
| square_refresh_token    | string   | yes      | *(encrypted)*                            | Encrypted; Square OAuth refresh token                      |
| square_merchant_id      | string   | yes      | `MERCHANT_ABC`                           | Square merchant identifier                                 |
| square_token_expires_at | datetime | yes      | `2026-03-01T00:00:00Z`                   | When the Square access token expires                       |
| square_last_synced_at   | datetime | yes      | `2026-02-20T12:00:00Z`                   | Last successful Square catalog sync                        |
| fresha_access_token     | string   | yes      | *(encrypted)*                            | Encrypted; Fresha OAuth access token                       |
| fresha_refresh_token    | string   | yes      | *(encrypted)*                            | Encrypted; Fresha OAuth refresh token                      |
| fresha_business_id      | string   | yes      | `biz_123`                                | Fresha business identifier                                 |
| fresha_token_expires_at | datetime | yes      | `2026-03-01T00:00:00Z`                   | When the Fresha access token expires                       |
| fresha_partner_id       | string   | yes      | `partner_456`                            | Fresha partner identifier                                  |
| fresha_last_synced_at   | datetime | yes      | `2026-02-20T12:00:00Z`                   | Last successful Fresha catalog sync                        |


### Site
| Name            | Type     | Nullable | Example                   | Constaints / Notes                                                                                                |
|-----------------|----------|----------|---------------------------|-------------------------------------------------------------------------------------------------------------------|
| id              | uuid     | no       | b8e7...                   | Primary Key                                                                                                       |
| professional_id | uudi     | no       | 4db0...                   | Owner / Professional                                                                                              |
| subdomain       | string   | no       | joshbarber                | unqiue (case-sensitive), 3-63,lowercase letters/numbers/hyphen; no leading/trailing hyphen; reserved list blocked |
| is_published    | boolean  | no       | false                     | if false, public site endpoint returns 404 or 403 depending on route                                              |
| theme_id        | uuid     | yes      | 9f23                      | Must exist in themes table                                                                                        |
| settings        | object   | yes      | {...}                     | Freeform JSON object merged on PATCH                                                                              |
| created_at      | datetime | yes      | 2026-01...                |                                                                                                                   |
| updated_at      | datetime | yes      | 2026-01...                |                                                                                                                   |

### SiteImage (core.site_images)

All images (gallery showcase and content/branding) live in the `site_images` table, organised into **pools**. The frontend assigns purpose by choosing from the variants map (`optimized` or `maximized`) for each image.

| Name       | Type     | Nullable | Example                                        | Constraints / Notes                                              |
|------------|----------|----------|-------------------------------------------------|------------------------------------------------------------------|
| id         | uuid     | no       | `f7a2...`                                       | Primary key                                                      |
| site_id    | uuid     | no       | `b8e7...`                                       | FK → sites.id                                                    |
| pool       | string   | no       | `gallery`                                       | `gallery` or `content`                                           |
| path       | string   | no       | `images/<proId>/<imageId>/original_abc123.jpg`  | Path to original file on the media disk                          |
| alt_text   | string   | yes      | `Fade haircut example`                          | Max 255                                                          |
| sort_order | integer  | no       | `0`                                             | Non-negative; used for gallery ordering                          |
| is_active  | boolean  | no       | `true`                                          | Soft visibility flag                                             |
| created_at | datetime | yes      | `2026-03-02T10:00:00Z`                          |                                                                  |
| updated_at | datetime | yes      | `2026-03-02T10:00:00Z`                          |                                                                  |
| deleted_at | datetime | yes      | `null`                                          | Soft delete                                                      |

**Pool limits** (configurable via env):
- `gallery`: max 5 images (env `COMET_GALLERY_IMAGE_MAX`)
- `content`: max 5 images (env `COMET_CONTENT_IMAGE_MAX`)

### ImageVariant (core.image_variants)

Each `SiteImage` gets a set of universal WebP variants generated server-side via a queue job. Content-hashed filenames enable aggressive CDN caching (`Cache-Control: public, max-age=31536000, immutable`).

| Name         | Type     | Nullable | Example                                         | Constraints / Notes                               |
|--------------|----------|----------|--------------------------------------------------|----------------------------------------------------|
| id           | uuid     | no       | `c3d4...`                                        | Primary key                                        |
| image_id     | uuid     | no       | `f7a2...`                                        | FK → site_images.id (cascade delete)               |
| variant      | string   | no       | `optimized`                                      | One of: optimized, maximized                        |
| disk         | string   | no       | `media`                                          | Storage disk name                                  |
| path         | string   | no       | `images/<proId>/<imgId>/optimized_abc123def456.webp` | Content-hashed filename                        |
| format       | string   | no       | `webp`                                           | Always WebP                                        |
| width        | integer  | no       | `3024`                                           | Actual output width in pixels                      |
| height       | integer  | no       | `4032`                                           | Actual output height in pixels                     |
| file_size    | integer  | no       | `3200`                                           | Bytes                                              |
| content_hash | string   | no       | `abc123def456ghij`                               | First 16 hex chars of SHA-256                      |
| created_at   | datetime | yes      | `2026-03-02T10:00:05Z`                           |                                                    |
| updated_at   | datetime | yes      | `2026-03-02T10:00:05Z`                           |                                                    |

**Variant profiles:**

| Variant   | Resolution policy   | Quality policy                                  | Typical use                             |
|-----------|---------------------|--------------------------------------------------|-----------------------------------------|
| optimized | Preserve original   | Adaptive quality, targets `COMET_IMAGE_TARGET_KB` (default 500KB) | Fast page loads / default display |
| maximized | Preserve original   | Highest quality (`COMET_IMAGE_MAXIMIZED_QUALITY`, default 100)    | Zoom/full-detail display          |

### Customer
| Name                      | Type     | Nullable | Example                | Constraints / Notes                                                         |
|---------------------------|----------|----------|------------------------|-----------------------------------------------------------------------------|
| id                        | uuid     | no       | `a3c1...`              | Primary key                                                                 |
| professional_id           | uuid     | yes      | `4db0...`              | Set by server on create                                                     |
| full_name                 | string   | no       | `Sam Smith`            | Max 120                                                                     |
| email                     | email    | yes      | `sam@example.com`      | Max 255                                                                     |
| phone                     | string   | yes      | `+61411111111`         | Max 40                                                                      |
| notes                     | string   | yes      | `Prefers Fridays`      | Max 5000                                                                    |
| source                    | string   | yes      | `manual`               | manual, site, or other; staff can set when creating/updating               |
| external_id               | string   | yes      | `square:cus_123`       | Max 255; external system reference                                         |
| marketing_opt_in_cached   | boolean  | no       | `true`                 | Cache of EmailSubscription status (defaults to true). Source of truth is EmailSubscription. Set to false if customer explicitly opts-out. |
| created_at                | datetime | yes      | `2026-01-12T05:12:00Z` |                                                                             |
| updated_at                | datetime | yes      | `2026-01-12T05:12:00Z` |                                                                             |
| deleted_at                | datetime | yes      | `2026-01-20T05:12:00Z` | Soft delete timestamp                                                       |

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
| square_catalog_object_id | string | yes | `ITEM_ABC`           | Square catalog item ID  |
| square_variation_id      | string | yes | `VAR_123`            | Square item variation ID |
| square_service_version   | integer| yes | `1`                  | Square object version (optimistic locking) |
| square_last_synced_at    | datetime| yes| `2026-02-20T12:00:00Z` | Last Square sync timestamp |
| square_sync_error        | string | yes | `null`               | Last Square sync error message |
| fresha_service_id        | string | yes | `svc_789`            | Fresha service ID       |
| fresha_variation_id      | string | yes | `var_012`            | Fresha service variation ID |
| fresha_service_version   | integer| yes | `1`                  | Fresha object version (optimistic locking) |
| fresha_last_synced_at    | datetime| yes| `2026-02-20T12:00:00Z` | Last Fresha sync timestamp |
| fresha_sync_error        | string | yes | `null`               | Last Fresha sync error message |

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
| id               | uuid     | no       | `a1b2...`     | Primary key                      |
| plan_key         | string   | no       | `free`        | Unique slug: free / pro / elite  |
| name             | string   | no       | `Free`        | Max 255                          |
| description      | string   | yes      | `For starters`| Max 2000                         |
| stripe_price_id  | string   | no       | `price_...`   | Stripe price ID (unique)         |
| price_cents      | integer  | no       | `0`           | Price in smallest currency unit  |
| currency_code    | string   | no       | `AUD`         | ISO 4217, default AUD            |
| billing_interval | string   | no       | `month`       | month or year                    |
| entitlements     | object   | yes      | See below     | JSON object with plan features   |
| is_active        | boolean  | no       | `true`        |                                  |
| sort_order       | integer  | no       | `0`           | Display order                    |
| created_at       | datetime | yes      | `2026-01-12T05:12:00Z` |                        |
| updated_at       | datetime | yes      | `2026-01-12T05:12:00Z` |                        |

### Subscription
| Name                | Type     | Nullable | Example              | Constraints / Notes                                 |
|---------------------|----------|----------|----------------------|-----------------------------------------------------|
| id                  | uuid     | no       | `sub-123...`         | Primary key                                         |
| professional_id     | uuid     | no       | `4db0...`            | Owner professional                                  |
| plan_id             | uuid     | no       | `a1b2...`            | Foreign key to Plan                                 |
| provider            | string   | no       | `stripe`             | stripe (default) or internal (free plan seed)       |
| stripe_customer_id  | string   | yes      | `cus_...`            | Stripe customer ID                                  |
| stripe_subscription_id | string | yes     | `sub_...`            | Stripe subscription ID (unique)                     |
| status              | string   | no       | `active`             | trialing, active, past_due, canceled, ended         |
| current_period_start| datetime | yes      | `2026-01-12T05:12:00Z` | Billing period start                              |
| current_period_end  | datetime | yes      | `2026-02-12T05:12:00Z` | Billing period end (null for free plans)           |
| trial_ends_at       | datetime | yes      | `2026-01-19T05:12:00Z` | When trial period ends (if any)                   |
| cancel_at_period_end| boolean  | no       | `false`              | Will cancel at period end if true                   |
| ended_at            | datetime | yes      | `2026-01-20T05:12:00Z` | When subscription ended                           |
| provider_payload    | object   | yes      | `{}`                 | External provider data (Stripe, etc)                |
| created_at          | datetime | yes      | `2026-01-12T05:12:00Z` |                                                   |
| updated_at          | datetime | yes      | `2026-01-12T05:12:00Z` |                                                   |

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
| Name         | Type    | Nullable | Example                                                   | Constraints / Notes                                       |
|--------------|---------|----------|-----------------------------------------------------------|-----------------------------------------------------------|
| published    | boolean | no       | `true`                                                    | Derived from site is_published                            |
| site         | object  | no       | `{ id, subdomain, settings, gallery, content_images }` | Includes gallery + content image pools with variant URLs  |
| professional | object  | no       | `{ id, handle, display_name, bio, ... }`                  | Includes public-facing location fields                    |
| theme        | object  | yes      | `{ id, key, name, config }`                               | theme.config is an object                                 |
| blocks       | array   | no       | `[ LinkBlock \| SectionBlock ]`                           | Only active blocks are returned                           |
| gallery      | array   | no       | `[ { id, pool, alt_text, sort_order, variants: {...} } ]` | Only active gallery-pool images; variants are URL maps    |
| services     | array   | no       | `[ { id, title, price_cents, ... } ]`                     | Only active services returned                             |

### Analytics Event Payloads
| Name                  | Type   | Nullable | Example                 | Constraints / Notes                                                                                                     |
|-----------------------|--------|----------|-------------------------|-------------------------------------------------------------------------------------------------------------------------|
| site_id               | uuid   | yes      | `b8e7...`               | Required unless subdomain is resolved from route or `X-Site-Subdomain` header                                          |
| session_id            | uuid   | yes      | `7f1e4d6b-...`          | Optional per-session ID                                                                                                 |
| visitor_id            | uuid   | yes      | `f2a1...`               | Optional stable visitor ID                                                                                              |
| referrer              | string | yes      | `https://instagram.com` | Max 2048; if missing, backend uses request `Referer` header                                                            |
| utm_source            | string | yes      | `instagram`             | Max 255                                                                                                                 |
| utm_medium            | string | yes      | `social`                | Max 255                                                                                                                 |
| utm_campaign          | string | yes      | `jan_promo`             | Max 255                                                                                                                 |
| block_id (click only) | uuid   | no       | `d5b0...`               | Must belong to the site, be active, and be trackable: `links/link` or `sections/{gallery,services,shop,booking}`     |

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

### Browser CORS

- Frontend browser origins must be allowed by backend CORS config.
- If requests fail in browser but work in Postman/curl, add your frontend origin to `config/cors.php` allowed origins/patterns.

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
- analytics: 120 requests per minute
- leads: 3 requests per minute per IP, plus 100 requests per minute per subdomain

## 6) Public Mini-Site API

All routes below are unauthenticated.

Frontend can connect in 2 modes:

1. Domain-scoped mini-site host  
`https://{subdomain}.{COMET_PUBLIC_DOMAIN}/api/public/...`
2. Header-based API host fallback (no subdomain DNS needed)  
`https://api.{COMET_PUBLIC_DOMAIN}/api/public/...` with header `X-Site-Subdomain: {subdomain}`

For analytics endpoints, provide either `site_id` in the JSON body OR `X-Site-Subdomain` header.

Frontend quick-start (header-based API host):

```ts
const API_BASE = "https://api.<COMET_PUBLIC_DOMAIN>/api/public";
const subdomain = "fadez";
const visitorId = localStorage.getItem("comet_visitor_id") ?? crypto.randomUUID();
localStorage.setItem("comet_visitor_id", visitorId);

const siteRes = await fetch(`${API_BASE}/site-by-slug`, {
  headers: { "X-Site-Subdomain": subdomain }
});

await fetch(`${API_BASE}/analytics/pageviews`, {
  method: "POST",
  headers: {
    "Content-Type": "application/json",
    "X-Site-Subdomain": subdomain
  },
  body: JSON.stringify({
    session_id: crypto.randomUUID(),
    visitor_id: visitorId
  })
});
```

### `GET /api/public/site`

- Purpose: fetch the published mini-site payload for rendering
- Auth: None
- Rate limit: public-site

**Response (200):**

```json
{
  "published": true,
  "site": { "id": "uuid", "subdomain": "fadez", "settings": {} },
  "professional": { "id": "uuid", "handle": "fadez", "display_name": "Fadez Studio", "bio": null },
  "theme": { "id": "uuid", "key": "modern", "name": "Modern", "config": {} },
  "blocks": [],
  "gallery": [],
  "services": []
}
```

**Common status codes:** 200, 403 (site not published), 404 (site not found), 429

### `POST /api/public/analytics/pageviews`

- Purpose: record a page view
- Auth: None
- Rate limit: analytics

**Request body:**

```json
{
  "site_id": "optional-uuid",
  "session_id": "optional-uuid",
  "visitor_id": "optional-uuid",
  "referrer": "optional string",
  "utm_source": "optional string",
  "utm_medium": "optional string",
  "utm_campaign": "optional string"
}
```

**Response (201):**

```json
{
  "message": "Pageview recorded",
  "visit_id": "uuid"
}
```

**Notes:** `occurred_at` is generated server-side.

**Common status codes:** 201, 403, 404, 422, 429

### `POST /api/public/analytics/clicks`

- Purpose: record a link click or supported section interaction
- Auth: None
- Rate limit: analytics

**Request body:**

```json
{
  "block_id": "required-uuid",
  "site_id": "optional-uuid",
  "session_id": "optional-uuid",
  "visitor_id": "optional-uuid",
  "referrer": "optional string",
  "utm_source": "optional string",
  "utm_medium": "optional string",
  "utm_campaign": "optional string"
}
```

**Response (201):**

```json
{
  "message": "Click recorded",
  "click_id": "uuid"
}
```

`message` is `"Section interaction recorded"` when the clicked block is a supported section block.

**Common status codes:** 201, 403 (unpublished or inactive block), 404 (site or block), 422 (not trackable/validation), 429

### `POST /api/public/customers`

- Purpose: submit a customer lead (name + contact details)
- Auth: None
- Rate limit: leads Request body: { "occurred_at": "2026-01-12T05:12:00Z", "full_name": "Sam Smith", "email": "sam@example.com", "phone": "+61411111111", "notes": "optional", "form_started_at_ms": 1700000000000 } Response (201): { "message": "Lead captured", "lead_id": "uuid" } Common status codes: 201, 404, 403, 422, 429

### `POST /api/public/subscribe`

- Purpose: subscribe an email address to a marketing list for the professional
- Auth: None
- Rate limit: public-site Request body: { "email": "sam@example.com", "full_name": "Sam Smith", "list_key": "marketing" } Response (200): { "ok": true, "subscribed": true, "list_key": "marketing" } Common status codes: 200, 404, 400 (cannot determine site), 422, 429

### `GET /api/public/marketing-preference`

- Purpose: check current marketing subscription status for an email
- Auth: None
- Rate limit: public-site
- Query params:
  - `email` (required): customer email address
  - `subdomain` (required): mini-site subdomain to identify professional

**Response (200):**

```json
{
    "email": "sam@example.com",
    "opted_in": true,
    "status": "subscribed"
}
```

**Status values:** `subscribed`, `unsubscribed`, `bounced`, `complained`, `unknown`

**Common status codes:** 200, 404 (site not found), 400 (missing params), 429

### `POST /api/public/unsubscribe/{token}`

- Purpose: unsubscribe from marketing emails using token from email link
- Auth: None
- Rate limit: public-site
- Path params: `token` (required): unsubscribe token from email

**Response (200):**

```json
{
    "message": "Successfully unsubscribed from marketing emails",
    "email": "sam@example.com"
}
```

**Common status codes:** 200, 404 (token not found), 400 (invalid token), 429

### `POST /api/public/resubscribe/{token}`

- Purpose: resubscribe to marketing emails using the same token
- Auth: None
- Rate limit: public-site
- Path params: `token` (required): unsubscribe token from email

**Response (200):**

```json
{
    "message": "Successfully resubscribed to marketing emails",
    "email": "sam@example.com"
}
```

**Common status codes:** 200, 404 (token not found), 400 (invalid token), 429

### Online Booking API

#### Domain-Scoped Booking Endpoints

The following booking endpoints are domain-scoped and accessed via the mini-site subdomain:
`https://{subdomain}.{COMET_PUBLIC_DOMAIN}/api/public/booking/...`

#### `GET /api/public/booking/config`

- Purpose: fetch booking integration configuration and primary location details
- Auth: None
- Rate limit: public-site

**Response (200):**

```json
{
    "booking_enabled": true,
    "application_id": "SQ0...",
    "location": {
        "id": "location-uuid",
        "name": "Main Location",
        "country": "AU",
        "currency": "AUD",
        "status": "ACTIVE"
    }
}
```

**Common status codes:** 200, 404 (site not found), 409 (booking not enabled/not connected), 502 (system unavailable), 503 (not configured)

#### `GET /api/public/booking/services`

- Purpose: fetch available appointment services and variations
- Auth: None
- Rate limit: public-site

**Response (200):**

```json
{
    "services": [
        {
            "id": "item-uuid",
            "variationId": "variation-uuid",
            "name": "Men's Haircut",
            "variationName": "Standard",
            "description": "Professional haircut",
            "durationMinutes": 30,
            "priceCents": 3500,
            "currency": "AUD",
            "availableForBooking": true,
            "category": "Haircuts"
        }
    ],
    "count": 1
}
```

**Common status codes:** 200, 404, 409, 502, 503

#### `POST /api/public/booking/availability`

- Purpose: search available appointment times for a service on a specific date
- Auth: None
- Rate limit: public-site

**Request body:**

```json
{
    "date": "2026-02-23",
    "serviceVariationId": "variation-uuid",
    "locationId": "optional-location-id"
}
```

**Response (200):**

```json
{
    "availabilities": [
        {
            "startAt": "2026-02-23T09:00:00Z",
            "locationId": "location-uuid",
            "teamMemberId": "team-member-uuid",
            "serviceVariationId": "variation-uuid",
            "serviceVariationVersion": 1,
            "durationMinutes": 30
        }
    ],
    "count": 5
}
```

**Common status codes:** 200, 404, 409, 422 (invalid date/variation), 502, 503

#### `POST /api/public/booking/checkout`

- Purpose: create and pay for a booking appointment
- Auth: None
- Rate limit: public-site

**Request body:**

```json
{
    "serviceVariationId": "variation-uuid",
    "serviceVariationVersion": 1,
    "teamMemberId": "team-member-uuid",
    "durationMinutes": 30,
    "startAt": "2026-02-23T09:00:00Z",
    "locationId": "optional-location-id",
    "paymentMethod": "card",
    "sourceId": "nonce_from_square",
    "customer": {
        "firstName": "John",
        "lastName": "Doe",
        "email": "john@example.com",
        "phone": "+61412345678",
        "note": "No beard trim"
    }
}
```

**Response (201):**

```json
{
    "success": true,
    "booking": {
        "id": "booking-uuid",
        "status": "CONFIRMED"
    },
    "payment": {
        "id": "payment-uuid",
        "status": "COMPLETED",
        "receiptUrl": "https://..."
    },
    "paid": true
}
```

**Common status codes:** 201, 400 (missing header), 404 (site/user not found), 409 (booking not enabled), 422 (validation error, payment error), 502, 503

---

#### Header-Based Slug Routing

For frontends that cannot use subdomain DNS routing, the following endpoints accept the subdomain via the `X-Site-Subdomain` header and are accessed on the API host:
`https://api.{COMET_PUBLIC_DOMAIN}/api/public/...`

#### `GET /api/public/site-by-slug`

- Purpose: fetch the published mini-site payload using header-based subdomain resolution
- Auth: None
- Rate limit: public-site
- Headers: `X-Site-Subdomain` (required): the site subdomain slug

**Response (200):** Same as `GET /api/public/site`

**Common status codes:** 200, 400 (missing header), 404 (site not found), 403 (site not published)

#### `GET /api/public/booking/config-by-slug`
#### `GET /api/public/booking/services-by-slug`
#### `POST /api/public/booking/availability-by-slug`
#### `POST /api/public/booking/checkout-by-slug`

- Purpose: header-based variants of the booking endpoints
- Auth: None
- Rate limit: public-site
- Headers: `X-Site-Subdomain` (required): the site subdomain slug

**Behavior:** Identical to domain-scoped versions above, but resolve subdomain from header instead of domain routing.

**Response:** Same as corresponding domain-scoped endpoints

**Common status codes:** Same as corresponding domain-scoped endpoints, plus 400 (missing header)

#### `POST /api/public/analytics/pageviews`
#### `POST /api/public/analytics/clicks`

- Purpose: header-based variants of the analytics endpoints (record page views and link clicks)
- Auth: None
- Rate limit: analytics
- Headers: `X-Site-Subdomain` (required if `site_id` is not in the request body): the site subdomain slug

**Behavior:** Identical to the domain-scoped analytics endpoints above. The `PageviewRequest` and `ClickRequest` form requests automatically resolve the subdomain from the `X-Site-Subdomain` header when no route-level subdomain is present. You must provide either `site_id` in the body OR the `X-Site-Subdomain` header — otherwise validation returns 422.

**Request body:** Same as domain-scoped versions (`POST /api/public/analytics/pageviews` and `POST /api/public/analytics/clicks` documented above).

**Response:** Same as corresponding domain-scoped endpoints.

**Common status codes:** Same as corresponding domain-scoped endpoints (201, 404, 403, 422, 429)

---

#### Payment Method Notes

- Square.js Web Payments SDK is required frontend-side to tokenize cards
- `sourceId` must be a valid Square nonce/token
- `paymentMethod` options: `card` (default), `apple_pay`
- Bookings without payment (free services) return `paid: false`
- If payment fails after booking creation, the booking is automatically cancelled

---

## 7) Professional (Barber) Dashboard API

All routes below require: Authorization header AND a professional profile (current.pro middleware).

### `GET /api/me`

- Purpose: bootstrap dashboard UI with current professional, site, blocks, services, and customer count
- Auth: Required Response (200): { "uid": "supabase-user-uuid", "professional": { "...": "..." }, "site": { "...": "..." }, "blocks": [], "services": [], "customers_count": 0 } Common status codes: 200, 401, 403

### `PATCH /api/me`

- Purpose: update professional profile fields Request body (all fields optional; if provided they are validated): { "display_name": "Josh Barber", "bio": "Mobile barber", "public_contact_email": "bookings@example.com" } Response (200): { professional: ... } Common status codes: 200, 401, 403, 422
- Images are managed via `POST /api/uploads` (pool=gallery or pool=content). No image fields are accepted on this endpoint.

### `GET /api/site`

- Purpose: fetch site record for the logged-in professional Response (200): { site: ... }

### `PATCH /api/site`

- Purpose: update site settings, subdomain, and theme_id. Request body: { "subdomain": "joshbarber", "theme_id": "uuid or null", "settings": { "primary_color": "#000000" } } Response (200): { site: ... } Common status codes: 200, 401, 403, 422
- Banners are managed via `POST /api/uploads` (pool=content) and the frontend picks from `optimized` / `maximized`. No banner fields are accepted on this endpoint.

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

- GET /api/customers?search=...&marketing_opt_in=true/false&page=1&per_page=25 (filters by marketing opt-in status using cache)
- GET /api/customers/{customer}
- POST /api/customers
- PATCH /api/customers/{customer}
- DELETE /api/customers/{customer}
- POST /api/customers/{customer}/restore

**Store/Update body:**

```json
{
    "full_name": "Sam Smith",
    "email": "sam@example.com",
    "phone": "+61411111111",
    "notes": "Optional",
    "source": "manual",
    "external_id": "square:cus_123",
    "marketing_opt_in_cached": true
}
```

**Query params:**
- `search`: search in full_name, email, phone
- `marketing_opt_in`: filter by `true`, `false`, or omit (applies to marketing_opt_in_cached field)
- `page`: pagination (default 1)
- `per_page`: items per page (default 25, max 100)

**Note:** `marketing_opt_in_cached` is a UX cache of the source-of-truth `EmailSubscription.status`. Defaults to `true` for new customers. When professionals update this field:
- Setting to `true` enables marketing emails
- Setting to `false` disables marketing emails
- Cache auto-syncs when EmailSubscription status changes Themes
- GET /api/themes
- POST /api/themes/{theme}/select Select response: { site: ... }

### Image Uploads (server-side processing)

Images are uploaded through the Comet API (not directly to storage). The server stores the original on the media disk (Laravel Cloud Object Storage / Cloudflare R2) and generates two full-resolution WebP variants (`optimized`, `maximized`).

**Queue modes:**
- `QUEUE_CONNECTION=sync` (default for dev / early production): variants are generated **inline** during the upload request (~2-5 sec). The response contains completed variants immediately with `processing: false`. No queue worker needed.
- `QUEUE_CONNECTION=database` or `redis` (recommended for scale): the upload returns instantly with `processing: true`, and a background queue job generates variants async. Frontend polls `GET /api/images` until `processing: false`.

#### `POST /api/uploads`

- Auth: Required (professional)
- Content-Type: `multipart/form-data`
- Request body:
  - `pool` (required): `gallery` or `content`
  - `image` (required): file upload (JPEG, PNG, or WebP; max 10 MB)
  - `alt_text` (optional): string, max 255
- Response (201) — sync mode (default):
```json
{
  "id": "uuid",
  "pool": "gallery",
  "original_path": "images/<proId>/<imageId>/original_abc123.jpg",
  "processing": false,
  "variants": {
    "optimized": "https://cdn.example.com/images/.../optimized_abc123.webp",
    "maximized": "https://cdn.example.com/images/.../maximized_def456.webp"
  }
}
```
- Response (201) — async mode (`QUEUE_CONNECTION=database` or `redis`):
```json
{
  "id": "uuid",
  "pool": "gallery",
  "original_path": "images/<proId>/<imageId>/original_abc123.jpg",
  "processing": true
}
```
- `processing: false` + `variants` when `QUEUE_CONNECTION=sync` (variants generated inline). `processing: true` (no `variants` key) when using a queue worker — poll `GET /api/images` until done.
- Business rules:
  - Max 5 gallery images per professional (configurable via `COMET_GALLERY_IMAGE_MAX`)
  - Max 5 content images per professional (configurable via `COMET_CONTENT_IMAGE_MAX`)
  - Race-safe: uses PostgreSQL advisory locks to enforce pool limits under concurrent uploads
- Variants: 2 full-resolution WebP outputs
  - `optimized`: adaptive quality targeting `COMET_IMAGE_TARGET_KB` (default 500KB)
  - `maximized`: highest quality output for detail-heavy views
- Common status codes: 201, 401, 403, 422 (pool limit exceeded or validation errors)

#### `GET /api/images`

- Auth: Required (professional)
- Query params:
  - `pool` (optional): `gallery` or `content` — filters by pool; omit for all images
- Response (200):
```json
{
  "images": [
    {
      "id": "uuid",
      "pool": "gallery",
      "alt_text": "Fade haircut",
      "sort_order": 0,
      "variants": {
        "optimized": "https://cdn.example.com/images/.../optimized_abc123.webp",
        "maximized": "https://cdn.example.com/images/.../maximized_def456.webp"
      },
      "processing": false,
      "created_at": "2026-03-02T10:00:00Z",
      "updated_at": "2026-03-02T10:00:05Z"
    }
  ],
  "limits": {
    "gallery": 5,
    "content": 5
  }
}
```
- Note: `processing: true` means variants have not been generated yet (queue job still running). `variants` will be an empty object in that case.
- Common status codes: 200, 401, 403

#### `DELETE /api/images/{image}`

- Auth: Required (professional)
- Deletes the image, all its WebP variants from storage, and the original file
- Response (200): `{ "deleted": true }`
- Common status codes: 200, 401, 403, 404

### Gallery (ordering & legacy routes)

Gallery-pool images can also be accessed / managed via the legacy gallery routes:

- `GET /api/gallery` — list gallery-pool images with variants (same format as GET /api/images?pool=gallery)
- `POST /api/gallery` — **deprecated (410)**: use `POST /api/uploads` with `pool=gallery` instead
- `POST /api/gallery/reorder` — reorder gallery images; body: `{ "ids": ["uuid1", "uuid2", ...] }`
- `DELETE /api/gallery/{image}` — delete a gallery image and its variants

### Square Integration

Square integration manages online booking appointments and service synchronization.

#### `GET /api/square/status`

- Purpose: get current Square connection status and token expiry
- Auth: Required (professional)
- Response (200): { "connected": true, "merchant_id": "MERCHANT_ID", "expires_at": "2026-02-23T05:12:00Z" }
- Common status codes: 200, 401, 403

#### `POST /api/square/connect`

- Purpose: store Square OAuth tokens after user authorizes via Square OAuth flow
- Auth: Required (professional)
- Request body: { "access_token": "...", "refresh_token": "...", "merchant_id": "...", "expires_at": "2026-02-23T..." }
- Response (200): { "connected": true, "merchant_id": "...", "expires_at": "...", "sync_queued": true, "sync_fallback_inline": false }
- Behavior: automatically triggers initial service sync from Square (queued or inline fallback)
- Common status codes: 200, 401, 403, 422

#### `POST /api/square/disconnect`

- Purpose: clear stored Square tokens and stop syncing
- Auth: Required (professional)
- Response (200): { "connected": false }
- Common status codes: 200, 401, 403

#### `GET /api/square/token`

- Purpose: fetch decrypted Square access token for frontend use
- Auth: Required (professional)
- Response (200): { "access_token": "...", "expires_at": "..." }
- Common status codes: 200, 401, 403, 404 (not connected)

#### `POST /api/square/services/sync`

- Purpose: manually trigger full service sync from Square
- Auth: Required (professional)
- Response (200): { "queued": false, "synced_inline": true, "merchant_id": "...", "synced": 5, "deleted": 2, "latest_time": "..." }
- Behavior: always runs inline (no queue required); useful for manual refresh button
- Common status codes: 200, 401, 403, 404 (not connected), 409 (access issue), 422

#### `POST /api/square/services/{service}/push`

- Purpose: push a local service update to Square immediately
- Auth: Required (professional), must own the service
- Response (200): { "pushed": true, "service_id": "...", "square_catalog_object_id": "...", "square_variation_id": "...", "square_last_synced_at": "...", "square_sync_error": null }
- Common status codes: 200, 401, 403, 404 (not connected/service not found), 422

### Fresha Integration

Fresha integration manages service catalog synchronization between Comet and Fresha.

Unlike Square (which exposes a full platform API for bookings, payments, and catalog), Fresha restricts third-party integrations to **catalog sync only**. Bookings, payments, and availability remain within the Fresha ecosystem. The Fresha integration therefore focuses on keeping the service catalog in sync between Comet and Fresha. The `FreshaApiClient` does include prepared methods for availability, bookings, and customer creation — these are scaffolded for future use if Fresha opens its API further, but they are not currently wired to any public routes.

#### `GET /api/fresha/status`

- Purpose: get current Fresha connection status and token expiry
- Auth: Required (professional)
- Response (200): { "connected": true, "business_id": "biz_123", "partner_id": "partner_456", "expires_at": "2026-02-23T05:12:00Z" }
- Common status codes: 200, 401, 403

#### `POST /api/fresha/connect`

- Purpose: store Fresha OAuth tokens after user authorizes via Fresha OAuth flow
- Auth: Required (professional)
- Request body: { "access_token": "...", "refresh_token": "...", "business_id": "...", "partner_id": "...", "expires_at": "2026-02-23T..." }
- Response (200): { "connected": true, "business_id": "...", "partner_id": "...", "expires_at": "...", "sync_queued": true, "sync_fallback_inline": false }
- Behavior: automatically triggers initial service sync from Fresha (queued or inline fallback)
- Common status codes: 200, 401, 403, 422

#### `POST /api/fresha/disconnect`

- Purpose: clear stored Fresha tokens and stop syncing
- Auth: Required (professional)
- Response (200): { "connected": false }
- Common status codes: 200, 401, 403

#### `GET /api/fresha/token`

- Purpose: fetch decrypted Fresha access token for frontend use
- Auth: Required (professional)
- Response (200): { "access_token": "...", "expires_at": "..." }
- Common status codes: 200, 401, 403, 404 (not connected)

#### `POST /api/fresha/services/sync`

- Purpose: manually trigger full service sync from Fresha
- Auth: Required (professional)
- Response (200): { "queued": false, "synced_inline": true, "business_id": "...", "synced": 5, "deleted": 2, "latest_time": "..." }
- Behavior: always runs inline (no queue required); useful for manual refresh button
- Common status codes: 200, 401, 403, 404 (not connected), 409 (access issue), 422

#### `POST /api/fresha/services/{service}/push`

- Purpose: push a local service update to Fresha immediately
- Auth: Required (professional), must own the service
- Response (200): { "pushed": true, "service_id": "...", "fresha_service_id": "...", "fresha_variation_id": "...", "fresha_last_synced_at": "...", "fresha_sync_error": null }
- Common status codes: 200, 401, 403, 404 (not connected/service not found), 422

---

### Fresha Webhooks

Fresha sends catalog change notifications to the Comet webhook endpoint. These routes have **no auth middleware** — authentication is performed via HMAC signature validation.

#### `POST /api/webhooks/fresha`
#### `POST /api/webhooks/fresha/catalog`

- Purpose: receive Fresha catalog change notifications and trigger delta sync
- Auth: HMAC signature validation via `X-Fresha-Signature` header (uses `FRESHA_WEBHOOK_SIGNATURE_KEY`)
- Supported event types: `catalog.updated`, `catalog.deleted`, `authorization.revoked`
- Behavior:
  - Validates HMAC-SHA256 signature against request body
  - Deduplicates events using `event_id` (ignores replays within 24 hours via cache)
  - For `catalog.updated` / `catalog.deleted`: dispatches `SyncFreshaCatalogDeltaJob` to the `integrations` queue
  - For `authorization.revoked`: clears the professional's stored Fresha tokens
- Response (200): { "received": true }
- Common status codes: 200, 400 (missing signature), 403 (invalid signature), 404 (professional not found), 422 (unknown event)

---

### Integration Comparison: Square vs Fresha

| Capability               | Square                         | Fresha                                   |
|--------------------------|--------------------------------|------------------------------------------|
| Service catalog sync     | Yes (pull & push)              | Yes (pull & push)                        |
| Public online booking    | Yes (full checkout flow)       | No (bookings stay in Fresha)             |
| Payment processing       | Yes (Square Web Payments SDK)  | No (payments stay in Fresha)             |
| Availability search      | Yes (exposed via public API)   | Prepared in client, not wired to routes  |
| Customer creation        | Yes (during checkout)          | Prepared in client, not wired to routes  |
| Webhook delta sync       | No (manual sync only)          | Yes (catalog.updated, catalog.deleted)   |
| Token refresh            | Manual via connect              | Automatic retry on 401 with refresh flow |
| Auth revocation webhook  | No                              | Yes (authorization.revoked event)        |
| Observer auto-sync       | Yes (on service save/delete)   | Yes (on service save/delete)             |
| Queue                    | `integrations`                 | `integrations`                           |

**Why they differ:** Square exposes a complete platform API — catalog, bookings, payments, availability, customers, and locations are all accessible to third-party developers. Comet leverages this to offer a full public booking + payment flow embedded in the mini-site. Fresha, by contrast, restricts third-party API access to catalog (service) management. Bookings, payments, availability, and customer data remain within the Fresha ecosystem. The Fresha integration therefore focuses exclusively on keeping the service catalog synchronized. The `FreshaApiClient` includes scaffolded methods for availability, bookings, and customer creation to allow rapid expansion if Fresha opens these APIs in the future.

---

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
- GET /api/staff/professionals/{professional}/subscription
- PATCH /api/staff/professionals/{professional}/subscription
- POST /api/staff/professionals/{professional}/subscription/cancel
- POST /api/staff/professionals/{professional}/subscription/resume
- POST /api/staff/notifications Staff analytics summary endpoints Stage 1-2 staff analytics is:
- GET /api/staff/professionals/{professional}/analytics It returns totals, daily charts, and top links for the selected professional.

It requires a staff JWT (core.comet_staff).

## 9) Image uploads & processing (server-side WebP)

Images are uploaded through the Comet API and processed entirely server-side. No direct-to-storage uploads from the frontend.

### Architecture

1. Frontend sends `POST /api/uploads` with `pool` (gallery or content) and the image file as `multipart/form-data`.
2. The server validates the file, stores the original on the **media disk** (Laravel Cloud Object Storage / Cloudflare R2), creates a `site_images` row.
3. Variant generation runs either **inline** (`QUEUE_CONNECTION=sync` — no worker needed, ~2-5 sec response) or **async** via queue job (`QUEUE_CONNECTION=database`/`redis` — returns instantly, worker processes in background).
4. When async: frontend polls `GET /api/images` — when `processing` flips to `false`, the `variants` map is populated with CDN URLs. When sync: variants are ready in the upload response.

### Queue modes

| Mode | Env var | Worker needed? | Upload response time | Frontend polling? |
|------|---------|---------------|---------------------|------------------|
| **Sync** (dev / early prod) | `QUEUE_CONNECTION=sync` | No | ~2-5 seconds | No — variants ready immediately |
| **Async** (scaled prod) | `QUEUE_CONNECTION=database` or `redis` | Yes (1+ worker) | ~50-100ms | Yes — poll until `processing: false` |

Switch between modes with zero code changes — just change the env var and optionally add a queue worker.

### Image pools

Each professional has two image pools:

- **gallery** — portfolio / work showcase images (max configurable, default 5)
- **content** — general-purpose branding images; frontend typically uses:
  - `optimized` for normal display/performance
  - `maximized` for zoom/full-detail or hero-style displays

### Content-hashed filenames & CDN caching

All variant filenames include a 16-char SHA-256 hash (for example, `optimized_abc123def456.webp`). The media disk is configured with `Cache-Control: public, max-age=31536000, immutable`, so CDN / browser caches are aggressive. When an image is re-processed, the hash (and therefore URL) changes, automatically busting the cache.

### Frontend upload flow

1. `POST /api/uploads` with `pool=gallery` (or `content`), `image=<file>`, optional `alt_text`.
2. If response has `processing: true` → poll `GET /api/images?pool=gallery` until `processing: false`. If `processing: false` → variants are already available in the response.
3. Use the `variants` map to pick the right URL for each UI context (typically `variants.optimized`, or `variants.maximized` where detail quality matters).
4. To delete: `DELETE /api/images/{image}`.
5. To reorder gallery: `POST /api/gallery/reorder` with `{ "ids": [...] }`.

### Supported file types

- JPEG (`image/jpeg`)
- PNG (`image/png`)
- WebP (`image/webp`)
- Max upload size: 10 MB (configurable via `COMET_IMAGE_MAX_UPLOAD_KB`)

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
- Optionally: STAFF_DASHBOARD_ENABLED flag if you ship staff tooling in the same frontend

Note: The frontend does not need any storage credentials — all image URLs come from the API `variants` map.

## 13) Backend env var checklist

### Core Laravel

- APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL
- LOG_LEVEL Database
- DB_CONNECTION=pgsql
- DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
- DB_SEARCH_PATH (recommended: public,core,analytics,billing,retail)
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
- COMET_MEDIA_DISK (default: media — the Laravel filesystem disk name)
- COMET_GALLERY_IMAGE_MAX (default: 5)
- COMET_CONTENT_IMAGE_MAX (default: 5)
- COMET_IMAGE_MAX_UPLOAD_KB (default: 10240 = 10 MB)
- SOFT_DELETE_RETENTION_DAYS (default: 30)

### Media disk (Laravel Cloud Object Storage / Cloudflare R2)

**On Laravel Cloud:** No manual env vars needed. Create a bucket in the Cloud dashboard, and set:
- `COMET_MEDIA_DISK` = the disk name from `LARAVEL_CLOUD_DISK_CONFIG` (e.g., `public_dev`)

Laravel Cloud auto-injects credentials via `LARAVEL_CLOUD_DISK_CONFIG`. The image system reads `COMET_MEDIA_DISK` to find the right disk.

**Self-managed (standalone R2 / AWS S3):** Configure the `media` disk manually:
- MEDIA_DISK_KEY (S3 access key)
- MEDIA_DISK_SECRET (S3 secret key)
- MEDIA_DISK_REGION (default: auto)
- MEDIA_DISK_BUCKET (default: comet-media)
- MEDIA_DISK_URL (public CDN base URL)
- MEDIA_DISK_ENDPOINT (R2/custom S3 endpoint URL)

### Square integration

- SQUARE_APPLICATION_ID (Square app ID, used by public booking config endpoint)
- SQUARE_ENVIRONMENT (sandbox or production)

### Fresha integration

- FRESHA_CLIENT_ID (Fresha OAuth client ID)
- FRESHA_CLIENT_SECRET (Fresha OAuth client secret)
- FRESHA_ENVIRONMENT (sandbox or production)
- FRESHA_WEBHOOK_SIGNATURE_KEY (HMAC key for validating Fresha webhook signatures)
- FRESHA_WEBHOOK_NOTIFICATION_URL (URL Fresha sends webhook events to)

### Optional: cache, queues, mail

- CACHE_STORE, REDIS_URL or REDIS_HOST/REDIS_PASSWORD
- QUEUE_CONNECTION: `sync` (no worker needed — processes inline) | `database` | `redis` (worker required; recommended for scale)
- MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS

## 14) Known implementation gotchas

### Domain-scoped public routes

- If you call /api/public/site on the API host instead of {subdomain}.{COMET_PUBLIC_DOMAIN}, the route may not match or may return 404.
- Always use public_api_base_url = https://{subdomain}.{COMET_PUBLIC_DOMAIN}/api for public routes.

### Analytics timestamps

- Public analytics endpoints set `occurred_at` server-side (`now()`).
- Frontend does not need to send `occurred_at`.

### Gallery limits and ordering

- Gallery pool: max 5 active images (configurable via `COMET_GALLERY_IMAGE_MAX`). Content pool: max 5 (via `COMET_CONTENT_IMAGE_MAX`).
- Pool limits are enforced server-side with PostgreSQL advisory locks for race safety.
- `POST /api/uploads` validates the pool limit before creating a new image.
- Reorder endpoint (`POST /api/gallery/reorder`) accepts an `ids` array; any omitted ids will be appended in existing order.
- Variants are generated inline (sync mode) or asynchronously (queue mode). If async, poll `GET /api/images` until `processing: false`.
- Content-hashed variant URLs are immutable for CDN caching; re-processing generates new URLs automatically.
