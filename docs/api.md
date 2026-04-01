# API Reference

This document is the single source of truth for backend so the frontend can build:

- Public mini-site (read-only site payload + lead capture + email subscribe + analytics)
- Professional dashboard (profile + site settings + links + sections + services + gallery + customers + analytics + notifications)
- Staff dashboard (staff-only browsing + admin editing tools)
- Backend: Laravel API (this repo)
- Auth: Supabase Auth (JWT access token)
- Media: Laravel Cloud Object Storage (S3-compatible / Cloudflare R2) with server-side WebP processing

## Contents

- Recent Backend Changes (Commit Log Snapshot)
- Environments and Base URLs
- Authentication (Supabase JWT)
- Roles and permissions
- Data Models
- Conventions (headers, errors, pagination, rate limits)
- Public Mini-Site API
- Professional Dashboard API
- Brand Partnerships and Brand Store API
- Enterprise API
- Staff API
- Media uploads & processing (images + videos, server-side via queue)
- Test users and getting tokens
- Insomnia collection
- Frontend env var checklist
- Backend env var checklist
- Known implementation gotchas

## 0) Recent Backend Changes (Commit Log Snapshot)

Snapshot date: **March 20, 2026**.

### Unreleased (working tree)

- Add video upload support (`POST /api/uploads` with `video` field); FFmpeg-based MP4 + HLS transcoding on dedicated `redis_video` queue; feature-flagged via `COMET_VIDEO_UPLOADS_ENABLED`.
- Extend `core.site_images` with `media_type`, `processing_state`, `processing_error`, `duration_ms`, `poster_path`, `original_mime`, `original_size_bytes`.
- Add `core.media_variants` table for video artifacts (MP4s, HLS playlists, poster).
- Add `gallery_videos` and `content_videos` arrays to `public_site_payload`; `gallery` / `content_images` remain image-only.
- Add `media_type` and `ids[]` filter params to `GET /api/images`; add `media_type` param to `POST /api/images/reorder`.
- Add per-professional legal content storage with generated + manual variants and source toggles.
- Add `GET /api/site/legal-content` and `PUT|PATCH /api/site/legal-content`.
- Add public payload `legal` object with active document resolution.
- Ensure legal defaults to templated content and never renders blank when manual content is empty.
- Add enterprise architecture: enterprises, memberships, ambassador-promoter contracts, and promoter commerce tables.
- Add enterprise self-service routes: `GET|POST|PATCH|DELETE /api/enterprise/me`.
- Expand professional types to: `barber`, `hairdresser`, `ambassador`, `promoter`, `barbershop`, `salon`.
- Update bootstrap and profile update flows to auto-provision enterprise records for enterprise owner types (`promoter`, `barbershop`, `salon`).
- Update featured products rules for enterprise linkage and ambassador promoter-contract validation.
- Add relational brand partner links (`brand_partner_links`) and move brand-affiliate relationship logic off heavy `site.settings` JSON lookups.
- Add brand-affiliate invite hardening: transactional claim/decline, expired invite handling, and consistent email matching.
- Add brand role-gates for brand store endpoints and paginate `GET /api/brand-partners`.
- Add brand partner + invite endpoints documentation and frontend contract notes.
- Add brand-approved affiliate commerce schema: `retail.brand_products`, `retail.brand_product_affiliate_overrides`, `core.enterprise_brand_links`, and `distributor` enterprise type support.
- Hard-cutover `PUT /api/store/featured-products` to `selected_products[{brand_product_id, sort_order}]`; legacy Shopify-id payload is rejected.
- Add `GET /api/store/available-products` for affiliate-visible products across connected brands (featured-first ordering).
- Add manager catalog endpoints: `GET /api/store/brand-products`, `PATCH /api/store/brand-products/{brandProductId}`, `PATCH /api/store/brand-products/bulk`.
- Expand affiliate override endpoints to deny/allow access control (`GET /api/store/affiliate-overrides`, `PUT|DELETE /api/store/affiliate-overrides/{deny|allow}`).
- Add per-affiliate product pricing endpoints: `GET|PUT|DELETE /api/store/affiliate-product-settings`.
- Enforce storefront validity via joins: connected brand link + availability + sync-active + deny override restriction.
- Activate `allow` override behavior to bypass brand-level availability for a specific affiliate while `deny` keeps unconditional precedence.
- Add selection cleanup flows: unavailability/disconnect/deny now remove invalid selections and create notifications.
- Add pricing outputs for store payloads: effective commission and discounted price with ceil-to-nearest-5-cents rounding, including affiliate-level override fields.
- Add pre-launch waitlist capture endpoint (`POST /api/public/waitlist`) with conditional fields and email-based upsert semantics.
- Add waitlist-mode gate on `POST /api/bootstrap` for new users only (existing professionals continue to bootstrap).

### Recent commits

- `37b4749` (2026-03-11): image variant changes.
- `580c222` (2026-03-10): allow `barbershop_info` section block type.
- `a91d6b7` (2026-03-10): add per-user Google Business Profile endpoints.
- `ddc1c76` (2026-03-10): sync booking contacts during checkout intent.
- `63350c9` (2026-03-10): public lead subdomain fallback (header/query/body/host).
- `438dcc7` (2026-03-10): fallback public customers route for path-based frontend.
- `31d0910` (2026-03-10): sync site bookings into local contacts.
- `626ee6c` (2026-03-09): infer subscriber names from email when full name is missing.
- `260d29d` (2026-03-09): stabilize public subscribe write path (no conflict upsert dependency).
- `6f94482` (2026-03-09): harden public subscribe and relax email validation.
- `8a078f5` (2026-03-09): support public subscribe via slug/header and upsert marketing customers.
- `df3fa60` (2026-03-09): create welcome notification on first signup bootstrap.

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

- POST /api/bootstrap This creates/updates `core.professionals` and `core.sites` tied to the Supabase user id (sub in JWT), and auto-provisions enterprise records for enterprise owner types.

If you skip bootstrap, professional routes will return 403 with a message prompting bootstrap.

### `POST /api/bootstrap`

### Auth: Required (Supabase JWT)

**Purpose:** Create or refresh the authenticated user profile + site in one call.  
If the selected `professional_type` is an enterprise-owner type (`promoter`, `barbershop`, or `salon`), an enterprise profile and owner membership are auto-provisioned in the same transaction.

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
"professional_type": "barber",
"handle": "joshbarber",
"invite_token": null,
"brand_partner_professional_id": null
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
- `professional_type`:
  - required for first bootstrap of a new Supabase user
  - optional for subsequent bootstrap calls
  - allowed values: `professional`, `influencer`, `barber`, `hairdresser`, `ambassador`, `promoter`, `brand`, `barbershop`, `salon`
- `handle` (optional): Unique username/slug (if omitted, auto-generated from display_name)
- `invite_token` (optional): claim a brand-affiliate invite during bootstrap
- `brand_partner_professional_id` (optional): connect to a brand partner during bootstrap when no invite token is provided

**Waitlist mode behavior:**
- If `COMET_WAITLIST_ENABLED=true`, bootstrap is blocked for users who do not already have a professional row.
- Existing professionals can still call bootstrap normally.
- Blocked response shape:
  - Status: `403`
  - Body: `{ "message": "New account creation is currently waitlist-only. Please join the waitlist.", "errors": { "code": "WAITLIST_ONLY" } }`

**Response (200):**

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
        "professional_type": "barber",
        "status": "active",
        "onboarding_step": 0
    },
    "site": {
        "id": "uuid",
        "professional_id": "uuid",
        "subdomain": "josh-barber",
        "is_published": false
    },
    "enterprise": null
}
```

`enterprise` response behavior:
- `null` for non-enterprise types (`barber`, `hairdresser`, `ambassador`)
- object for enterprise-owner types (`promoter`, `barbershop`, `salon`)

**Common status codes:** 200, 401 (invalid JWT), 403 (waitlist-only gate or disabled account), 422 (validation error)

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
| professional_type       | string   | no       | barber                                   | One of: professional, influencer, barber, hairdresser, ambassador, promoter, brand, barbershop, salon |
| primary_enterprise_id   | uuid     | yes      | `10000000-...`                           | Optional pointer to primary enterprise (membership table is source of truth) |
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

### Enterprise (core.enterprises)
| Name                    | Type     | Nullable | Example                | Constraints / Notes |
|-------------------------|----------|----------|------------------------|---------------------|
| id                      | uuid     | no       | `10000000-...`         | Primary key |
| auth_user_id            | uuid     | yes      | `30000000-...`         | Optional owner auth user id (unique among active enterprises) |
| name                    | string   | no       | `Atlas Promotions`     | Max 255 |
| handle                  | string   | yes      | `atlas-promotions`     | Unique among active enterprises |
| enterprise_type         | string   | no       | `promoter`             | One of: `promoter`, `barbershop`, `salon`, `distributor` |
| status                  | string   | no       | `active`               | One of: `active`, `inactive`, `suspended` |
| subscription_tier       | string   | yes      | `enterprise`           | Enterprise plan key/label |
| primary_email           | string   | yes      | `hello@example.com`    | Business contact |
| phone                   | string   | yes      | `+61400000000`         | Business contact |
| public_contact_email    | string   | yes      | `bookings@example.com` | Public-facing contact |
| public_contact_number   | string   | yes      | `+61400000001`         | Public-facing contact |
| location_*              | string   | yes      | `Melbourne`            | Address fields |
| metadata                | jsonb    | no       | `{}`                   | Flexible metadata |
| deleted_at              | datetime | yes      | `null`                 | Soft delete marker |

### Professional Enterprise Membership (core.professional_enterprise_memberships)
| Name                    | Type     | Nullable | Example        | Constraints / Notes |
|-------------------------|----------|----------|----------------|---------------------|
| id                      | uuid     | no       | `40000000-...` | Primary key |
| professional_id         | uuid     | no       | `20000000-...` | FK to professional |
| enterprise_id           | uuid     | no       | `10000000-...` | FK to enterprise |
| relationship_type       | string   | no       | `owner`        | One of: `owner`, `employee`, `chair_renter`, `contractor`, `affiliate`, `member` |
| is_primary              | boolean  | no       | `true`         | Only one active primary membership per professional |
| starts_at               | datetime | no       | `...`          | Start timestamp |
| ends_at                 | datetime | yes      | `null`         | Active when null |

### Ambassador Promoter Contract (core.influencer_promoter_contracts)
| Name                        | Type     | Nullable | Example        | Constraints / Notes |
|-----------------------------|----------|----------|----------------|---------------------|
| id                          | uuid     | no       | `50000000-...` | Primary key |
| influencer_professional_id  | uuid     | no       | `20000000-...` | Must reference `professional_type = ambassador` |
| promoter_enterprise_id      | uuid     | no       | `10000000-...` | Must reference `enterprise_type = promoter` |
| status                      | string   | no       | `active`       | `draft`, `active`, `paused`, `ended`, `terminated` |
| exclusive                   | boolean  | no       | `true`         | At most one active exclusive contract per ambassador |
| starts_at                   | datetime | no       | `...`          | Contract start |
| ends_at                     | datetime | yes      | `null`         | Active when null and status is `active` |


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
| professional | object  | no       | `{ id, handle, display_name, professional_type, bio, ... }` | Includes public-facing location fields + professional_type |
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
| block_id (click only) | uuid   | no       | `d5b0...`               | Must belong to the site, be active, and be trackable: `links/link` or `sections/{gallery,services,shop,booking,barbershop_info}` |

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
  "site": { "id": "uuid", "subdomain": "fadez", "settings": {}, "gallery": [], "content_images": [], "gallery_videos": [], "content_videos": [] },
  "professional": { "id": "uuid", "handle": "fadez", "display_name": "Fadez Studio", "professional_type": "barber", "bio": null },
  "theme": { "id": "uuid", "key": "modern", "name": "Modern", "config": {} },
  "links": [],
  "sections": [],
  "blocks": [],
  "services": [],
  "selected_products": [],
  "default_commission_rate": 15,
  "max_featured_products": 10,
  "store": {
    "selected_products": [],
    "default_commission_rate": 15,
    "max_featured_products": 10
  },
  "legal": {
    "privacy_policy": "## Privacy Policy\\n...",
    "terms_and_conditions": "## Terms and Conditions\\n...",
    "active_privacy_source": "templated",
    "active_terms_source": "templated"
  }
}
```

**Common status codes:** 200, 403 (site not published), 404 (site not found), 429

**Notes:**
- `blocks` is a combined, sort-ordered array of both `links` and `sections` and includes `block_group` on each item.
- Featured-products data is embedded for theme rendering via both top-level keys (`selected_products`, rates) and a nested `store` object.
- `site.gallery` and `site.content_images` are image-only arrays (unchanged). `site.gallery_videos` and `site.content_videos` are video-only arrays. Each video item includes `{ id, sort_order, processing_state, duration_ms, poster, variants: { optimized, maximized }, streams: { adaptive, optimized, maximized } }`. Videos with `processing_state != ready` are excluded automatically.

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
- Rate limit: leads
- Site resolution order: `X-Site-Subdomain` header -> `subdomain`/`slug` query -> `subdomain`/`slug` body -> host subdomain
- Request body (example): `{ "full_name": "Sam Smith", "email": "sam@example.com", "phone": "+61411111111", "notes": "optional", "marketing_opt_in": true, "form_started_at_ms": 1700000000000 }`
- Response (201): `{ "ok": true, "customer_id": "uuid" }`
- Common status codes: 201, 400 (cannot determine site), 404, 403, 422, 429

### `POST /api/public/waitlist`

- Purpose: collect pre-launch waitlist submissions for Comet account access
- Auth: None
- Rate limit: waitlist
- Request body:
  - `name` (required)
  - `email` (required)
  - `phone` (required)
  - `type` (required): `influencer`, `professional`, `brand`, `other`
  - `industry` (required): `mens_grooming`, `womens_haircare`, `beauty_products`, `vitamins_and_supplements`, `services_and_software`, `other`
  - `pilot_program_opt_in` (required boolean)
  - `type_other_text` (required when `type = other`)
  - `industry_other_text` (required when `industry = other`)
  - `number_of_team_members` (required when `type = brand`)
  - `number_of_affiliates_ambassadors` (required when `type = brand`)
  - `is_brand_partner_or_ambassador` (required when `type = influencer` or `professional`)
  - `currently_sells_products` (required when `type = influencer` or `professional`)
- Upsert semantics: submissions are deduplicated by normalized email (`email_lc`), then updated on re-submit.
- Response:
  - `201` for a new email submission: `{ "ok": true }`
  - `200` for a repeat email submission (updated row): `{ "ok": true }`
- Common status codes: 200, 201, 422, 429

### `POST /api/public/subscribe`

- Purpose: subscribe an email address to a marketing list for the professional
- Auth: None
- Rate limit: public-site
- Site resolution order: `X-Site-Subdomain` header -> `subdomain`/`slug` query -> `subdomain`/`slug` body -> host subdomain
- Request body: `{ "email": "sam@example.com", "full_name": "Sam Smith", "list_key": "marketing" }`
- Response (200): `{ "ok": true, "subscribed": true, "list_key": "marketing" }`
- Common status codes: 200, 404, 400 (cannot determine site), 422, 429

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

### Public Store API

#### Domain-Scoped Store Endpoints

The following store endpoints are domain-scoped and accessed via the mini-site subdomain:
`https://{subdomain}.{COMET_PUBLIC_DOMAIN}/api/public/store/...`

#### `GET /api/public/store/featured-products`

- Purpose: fetch publicly visible featured product payload for the resolved site
- Auth: None
- Rate limit: public-site
- Response (200): selected product payload including pricing/commission context
- Common status codes: 200, 404, 429

#### `POST /api/public/store/checkout-session`

- Purpose: mint deterministic checkout attribution token for Shopify order attribution
- Auth: None
- Rate limit: public-site
- Site resolution order: `X-Site-Subdomain` header -> `subdomain`/`slug` query -> `subdomain`/`slug` body -> host subdomain
- Request body (optional fields):
  - `currency_code` (string, 3 chars)
  - `line_items` (array, max 100)
    - `brand_product_id` (uuid, optional)
    - `shopify_product_id` (string, optional)
    - `quantity` (int >= 1, optional)
    - `unit_price_cents` (int >= 0, optional)
    - `line_total_cents` (int >= 0, optional)
  - `context` (object, optional)
- Response (201):
  - `{ "token": "comet_session_...", "expires_at": "...", "affiliate_professional_id": "...", "brand_professional_id": "...", "site_id": "...", "currency_code": "AUD" }`
- Common status codes:
  - 201
  - 400 (site identifier missing)
  - 404 (site not found)
  - 409 (`MULTIPLE_BRANDS_NOT_SUPPORTED`)
  - 422 (`NO_CONNECTED_BRAND` or validation failure)
  - 429

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
#### `GET /api/public/store/featured-products-by-slug`
#### `POST /api/public/store/checkout-session-by-slug`

- Purpose: header-based variants of the booking/store endpoints
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

## 7) Professional Dashboard API

All routes below require: Authorization header AND a professional profile (current.pro middleware).

### `GET /api/me`

- Purpose: bootstrap dashboard UI with current professional, site, blocks, services, and customer count
- Auth: Required
- Response (200): `{ "uid": "supabase-user-uuid", "professional": { ..., "professional_type": "barber" }, "site": { ... }, "legal_content": { ... }, "blocks": [], "services": [], "customers_count": 0 }`
- Enterprise fields in `professional`:
  - `primary_enterprise_id`
  - `primary_enterprise` (object or null)
  - `enterprise_memberships` (array, each with `enterprise` object snapshot)
  - `active_promoter_contract` (object or null; ambassador-focused)
- Common status codes: 200, 401, 403

### `PATCH /api/me`

- Purpose: update professional profile fields
- Request body (all fields optional; if provided they are validated): `{ "display_name": "Josh Barber", "bio": "Mobile barber", "professional_type": "barber", "public_contact_email": "bookings@example.com" }`
- `professional_type` allowed values: `professional`, `influencer`, `barber`, `hairdresser`, `ambassador`, `promoter`, `brand`, `barbershop`, `salon`
- Behavior: switching to `promoter`, `barbershop`, or `salon` auto-provisions/updates an enterprise profile and owner membership
- Response (200): `{ "professional": { ... } }`
- Common status codes: 200, 401, 403, 422
- Images are managed via `POST /api/uploads` (pool=gallery or pool=content). No image fields are accepted on this endpoint.

### `GET /api/site`

- Purpose: fetch site record for the logged-in professional
- Response (200): `{ "site": { ... } }`

### `PATCH /api/site`

- Purpose: update site settings, subdomain, and theme_id
- Request body: `{ "subdomain": "joshbarber", "theme_id": "uuid or null", "settings": { "primary_color": "#000000" } }`
- Relationship settings are not writable here: `settings.brand_partner`, `settings.brandPartner`, and `settings.additional_brand_partners` are rejected. Use the brand partner/invite endpoints instead.
- Response (200): `{ "site": { ... } }`
- Common status codes: 200, 401, 403, 422
- Banners are managed via `POST /api/uploads` (pool=content) and the frontend picks from `optimized` / `maximized`. No banner fields are accepted on this endpoint.

### `GET /api/site/google-business-profile`

- Purpose: fetch the professional's saved Google Business Profile details from site settings
- Auth: Required
- Response (200): `{ "google_business_profile": { "place_id": "...", "name": "...", "address": "...", "latitude": -37.8, "longitude": 144.9, "phone": "...", "website": "...", "hours": ["Mon: 9:00-17:00"] } }` or `null`
- Common status codes: 200, 401, 403

### `PUT /api/site/google-business-profile`

- Purpose: upsert Google Business Profile details into site settings
- Auth: Required
- Request body: `{ "place_id": "ChIJ...", "name": "Fadez Studio", "address": "...", "latitude": -37.8, "longitude": 144.9, "phone": "+61...", "website": "https://...", "hours": ["Mon: 9:00-17:00"] }`
- Response (200): `{ "google_business_profile": { ... } }`
- Common status codes: 200, 401, 403, 422

### `GET /api/site/legal-content`

- Purpose: fetch generated/manual legal content and current active sources for the logged-in professional
- Auth: Required
- Response (200): `{ "legal_content": { "generated_privacy_policy": "...", "manual_privacy_policy": "...", "active_privacy_source": "templated", "active_privacy_policy": "...", "generated_terms_and_conditions": "...", "manual_terms_and_conditions": "...", "active_terms_source": "templated", "active_terms_and_conditions": "...", "template_variables": { ... }, "generated_at": "...", "updated_at": "..." } }`
- Common status codes: 200, 401, 403

### `PUT /api/site/legal-content`
### `PATCH /api/site/legal-content`

- Purpose: save manual legal text, toggle active sources, or regenerate templated documents
- Auth: Required
- Request body (all fields optional): `{ "manual_privacy_policy": "...", "manual_terms_and_conditions": "...", "active_privacy_source": "templated|manual", "active_terms_source": "templated|manual", "regenerate_templated": true }`
- Behavior: if active source is `manual` but manual content is empty, source is forced back to `templated` (never blank output)
- Response (200): `{ "legal_content": { ... } }`
- Common status codes: 200, 401, 403, 422

### `PATCH /api/site/visibility`

- Purpose: publish or unpublish the mini-site
- Request body: `{ "published": true }`
- Response (200): `{ "published": true }`

### Brand Partnerships & Invites

#### `GET /api/brand-affiliates` (brand-only)
- Purpose: list affiliates currently connected to the logged-in brand
- Response (200): `{ "affiliates": [{ "id": "uuid", "full_name": "...", "handle": "...", "email": "...", "phone": "...", "connected_at": "...", "is_primary": true }] }`
- Common status codes: 200, 401, 403

#### `DELETE /api/brand-affiliates/{affiliate}` (brand-only)
- Purpose: disconnect an affiliate from the logged-in brand
- Path param: `affiliate` (uuid)
- Response (200): `{ "affiliate_id": "uuid", "disconnected": true }`
- Common status codes: 200, 401, 403, 404

#### `GET /api/brand-affiliate-invites` (brand-only)
- Purpose: list invites created by the logged-in brand
- Response (200): `{ "invites": [{ "id": "uuid", "status": "pending|accepted|declined|expired", "invite_type": "generic|personalised", "email": "...", "token": "...", "created_at": "...", "accepted_at": "..." }] }`
- Common status codes: 200, 401, 403

#### `POST /api/brand-affiliate-invites/availability` (brand-only)
- Purpose: check invite availability by email before creating an invite
- Request body: `{ "email": "affiliate@example.com" }`
- Response (200): availability object for `email` and `phone` channels
  - `email.will_refresh` is `true` when this email already has a non-accepted invite for this brand and resend will refresh it in place.
  - `email.available` is only `false` when the email is already connected to this brand.
- Common status codes: 200, 401, 403, 422

#### `POST /api/brand-affiliate-invites` (brand-only)
- Purpose: create a brand-affiliate invite
- Request body: `{ "email": "affiliate@example.com", "phone": null, "first_name": "Sam", "last_name": "Smith", "message": "Join us", "expiration": "24h|7d|30d|none" }`
  - If `expiration` is omitted, default is `30d`.
  - Resend behavior: same brand + same email refreshes existing non-accepted invite (`pending|expired|declined`) with the same token.
- Response:
  - `201` when created, `200` when refreshed.
  - `{ "invite": { "id": "uuid", "token": "...", "status": "pending", ... }, "action": "created|refreshed" }`
- Common status codes: 200, 201, 401, 403, 422

#### `POST /api/brand-affiliate-invites/bulk` (brand-only)
- Purpose: create/refresh many invites in one request
- Request body:
  - `{ "invites": [{ "email": "affiliate@example.com", "phone": null, "first_name": "Sam", "last_name": "Smith", "message": "Join us", "expiration": "24h|7d|30d|none" }] }`
  - Max 500 rows per request.
  - Duplicate emails in one request use last-row-wins; earlier duplicates are skipped.
- Response (200):
  - `{ "summary": { "total_rows": 3, "created_count": 1, "refreshed_count": 1, "skipped_count": 1, "error_count": 0 }, "results": [...] }`
  - Partial success: valid rows are processed even when other rows fail.
- Common status codes: 200, 401, 403, 422

#### `POST /api/brand-affiliate-invites/import-csv` (brand-only)
- Purpose: CSV import for invite create/refresh
- Content-Type: `multipart/form-data`
- Form fields:
  - `file` (required CSV upload)
- Notes:
  - Synchronous processing, max 500 data rows.
  - Flexible header matching (case/spacing/underscore-insensitive aliases).
  - Unknown columns are ignored.
  - Partial success with row-level error reporting.
- Response shape matches `POST /api/brand-affiliate-invites/bulk`.
- Common status codes: 200, 401, 403, 422

#### `DELETE /api/brand-affiliate-invites/{invite}` (brand-only)
- Purpose: delete an invite created by the logged-in brand
- Path param: `invite` (uuid)
- Response (200): `{ "invite_id": "uuid", "deleted": true }`
- Common status codes: 200, 401, 403, 404

#### `POST /api/brand-affiliate-invites/{token}/claim` (non-brand professional)
#### `POST /api/brand-affiliate-invites/{token}/decline` (non-brand professional)
- Purpose: claim or decline invite token
- Path param: `token` (string)
- Response (200): `invite` status payload
- Common status codes: 200, 401, 404, 422

#### `GET /api/brand-partners`
- Purpose: list available active brands for partner selection
- Pagination: query `per_page` supported (default 25, max 100)
- Response (200): `{ "brands": [...], "meta": { "current_page": 1, "per_page": 25, "total": 123, "last_page": 5, "next_page_url": "...", "prev_page_url": null } }`
- Common status codes: 200, 401

#### `POST /api/brand-partners/{brandProfessionalId}/promote` (non-brand professional)
#### `DELETE /api/brand-partners/{brandProfessionalId}` (non-brand professional)
- Purpose: promote a connected brand to primary or disconnect a connected brand
- Path param: `brandProfessionalId` (uuid)
- Common status codes: 200, 401, 403, 404

### Services

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

Allowed section block types are defined in config: `gallery`, `services`, `shop`, `booking`, `barbershop_info`

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
- Cache auto-syncs when EmailSubscription status changes

### Themes

- `GET /api/themes`
- `POST /api/themes/{theme}/select`
- Select response: `{ "site": { ... } }`

### Store: Affiliate Product Selection

#### `GET /api/store/featured-products`

- Purpose: get selected, strictly-valid storefront products for the logged-in professional
- Auth: Required
- Response (200): `{ "selected_products": [{ "id": "uuid", "brand_product_id": "uuid", "brand_professional_id": "uuid", "shopify_product_id": "gid://shopify/Product/...", "sort_order": 0, "commission_override": null, "affiliate_commission_override": 20, "effective_commission_rate": 20, "discount_rate": 10, "affiliate_discount_rate": 5, "custom_price": null, "affiliate_custom_price": 32.95, "base_price_cents": 3295, "discounted_price_cents": 3130 }], "default_commission_rate": 15, "max_featured_products": 10 }`
- Validity checks:
  - product must be sync-active
  - brand setting must be available, unless an affiliate `allow` override exists
  - affiliate must still be connected to the brand
  - no deny override for that affiliate/product
- Notes:
  - Reads from `retail.professional_selections` + joined retail/core tables.
- Common status codes: 200, 401, 403

#### `PUT /api/store/featured-products`

- Purpose: replace the full selected product list (global max 10)
- Auth: Required
- Hard cutover request body:
  - `selected_products`: required array (max 10)
  - `selected_products[].brand_product_id`: required uuid
  - `selected_products[].sort_order`: optional integer >= 0
- Response (200): same shape as GET endpoint
- Notes:
  - Legacy payload (`products[].shopify_product_id`) is rejected with 422.
  - DB triggers enforce connected brand link + sync-active + deny-override restrictions; `allow` overrides can bypass brand availability.
  - Duplicate `brand_product_id` values are rejected.
- Common status codes: 200, 401, 403, 422, 503

#### `GET /api/store/available-products`

- Purpose: list affiliate-visible products across connected brands
- Auth: Required
- Query params:
  - `brand_professional_id` (optional uuid): limit to one connected brand
- Ordering:
  - `is_featured DESC`
  - `sort_order ASC`
  - `title ASC`
- Response (200): `{ "available_products": [...], "max_featured_products": 10 }`
- Product payload includes both brand-level and affiliate-level pricing fields:
  - `commission_override`, `affiliate_commission_override`, `effective_commission_rate`
  - `discount_rate`, `affiliate_discount_rate`
  - `custom_price`, `affiliate_custom_price`
- Common status codes: 200, 401, 403, 422

### Store: Brand/Distributor Catalog Management

#### `GET /api/store/brand-products`
- Purpose: full synced catalog + settings state for managed brands
- Auth: Required (brand account, or professional in an active `distributor` enterprise linked via `core.enterprise_brand_links`)
- Query params:
  - `brand_professional_id` (optional uuid): limit to one managed brand
- Response includes each product's synced fields and settings fields (`is_available`, `is_featured`, `commission_override`, `discount_rate`, `custom_price`, effective pricing/commission outputs).
- Common status codes: 200, 401, 403, 422

#### `PATCH /api/store/brand-products/{brandProductId}`
- Purpose: update one brand product's settings
- Auth: Required manager access for that brand
- Allowed fields:
  - `is_available`, `is_featured`, `sort_order`
  - `commission_override`, `discount_rate`, `custom_price`
- Notes:
  - If a product becomes unavailable, affected affiliate selections are removed and users are notified.
- Common status codes: 200, 401, 403, 404, 422

#### `PATCH /api/store/brand-products/bulk`
- Purpose: bulk update settings for one brand, one/many/all products
- Auth: Required manager access for that brand
- Request body:
  - `brand_professional_id`: required uuid
  - `brand_product_ids`: optional uuid[] (omit to target all products for brand)
  - one or more mutable fields from the single-product PATCH endpoint
- Notes:
  - Executes in a transaction with row locking.
  - If products become unavailable, affected selections are removed + notifications are created.
- Common status codes: 200, 401, 403, 422

### Store: Affiliate Access Overrides (Deny/Allow)

#### `GET /api/store/affiliate-overrides`
- Purpose: list affiliate access overrides for a managed brand
- Auth: Required manager access for `brand_professional_id`
- Query params:
  - `brand_professional_id` (required uuid)
  - `affiliate_professional_id` (optional uuid)
  - `override_type` (optional enum: `deny` | `allow`)
  - `page` (optional integer, default 1)
  - `per_page` (optional integer, default 100, max 200)
- Response (200): paginated
  - `{ "overrides": [...], "meta": { "current_page": 1, "per_page": 100, "total": 42, "last_page": 1, ... } }`
- Common status codes: 200, 401, 403, 422

#### `PUT /api/store/affiliate-overrides/deny`
- Purpose: set deny overrides for one affiliate across one/many brand products
- Auth: Required manager access for the brand
- Request body:
  - `brand_professional_id` (required uuid)
  - `affiliate_professional_id` (required uuid)
  - `brand_product_ids` (required uuid[], min 1)
- Notes:
  - Creating deny overrides also removes matching current selections for that affiliate and sends notifications.
- Common status codes: 200, 401, 403, 422

#### `DELETE /api/store/affiliate-overrides/deny`
- Purpose: remove deny overrides for one affiliate/brand pair (all or subset)
- Auth: Required manager access for the brand
- Request body:
  - `brand_professional_id` (required uuid)
  - `affiliate_professional_id` (required uuid)
  - `brand_product_ids` (optional uuid[])
- Common status codes: 200, 401, 403, 422

#### `PUT /api/store/affiliate-overrides/allow`
- Purpose: set allow overrides for one affiliate across one/many brand products
- Auth: Required manager access for the brand
- Request body:
  - `brand_professional_id` (required uuid)
  - `affiliate_professional_id` (required uuid)
  - `brand_product_ids` (required uuid[], min 1)
- Notes:
  - `allow` overrides bypass brand-level product availability for that affiliate.
  - `deny` still takes precedence when present.
- Common status codes: 200, 401, 403, 422

#### `DELETE /api/store/affiliate-overrides/allow`
- Purpose: remove allow overrides for one affiliate/brand pair (all or subset)
- Auth: Required manager access for the brand
- Request body:
  - `brand_professional_id` (required uuid)
  - `affiliate_professional_id` (required uuid)
  - `brand_product_ids` (optional uuid[])
- Common status codes: 200, 401, 403, 422

### Store: Per-Affiliate Product Pricing Settings

#### `GET /api/store/affiliate-product-settings`
- Purpose: list per-affiliate product pricing settings for a managed brand
- Auth: Required manager access for `brand_professional_id`
- Query params:
  - `brand_professional_id` (required uuid)
  - `affiliate_professional_id` (optional uuid)
  - `page` (optional integer, default 1)
  - `per_page` (optional integer, default 100, max 200)
- Response (200): paginated
  - `{ "settings": [...], "meta": { "current_page": 1, "per_page": 100, "total": 42, "last_page": 1, ... } }`
- `settings[]` fields:
  - `brand_product_id`
  - `commission_override` (nullable, 0-100)
  - `discount_rate` (nullable, 0-100)
  - `custom_price` (nullable, >= 0)
- Common status codes: 200, 401, 403, 422

#### `PUT /api/store/affiliate-product-settings`
- Purpose: batch upsert per-affiliate product pricing settings
- Auth: Required manager access for the brand
- Request body:
  - `brand_professional_id` (required uuid)
  - `affiliate_professional_id` (required uuid)
  - `settings` (required array, min 1)
  - `settings[].brand_product_id` (required uuid)
  - `settings[].commission_override` (nullable number, min 0, max 100)
  - `settings[].discount_rate` (nullable number, min 0, max 100)
  - `settings[].custom_price` (nullable number, min 0)
- Notes:
  - Duplicate `settings[].brand_product_id` values are rejected with 422.
  - Effective storefront precedence is:
    - commission: affiliate override → product override → brand default → system default
    - discount/custom price: affiliate override → product-level value
- Common status codes: 200, 401, 403, 422

#### `DELETE /api/store/affiliate-product-settings`
- Purpose: remove per-affiliate product pricing settings (all or subset)
- Auth: Required manager access for the brand
- Request body:
  - `brand_professional_id` (required uuid)
  - `affiliate_professional_id` (required uuid)
  - `brand_product_ids` (optional uuid[])
- Common status codes: 200, 401, 403, 422

### Store: Brand Settings

#### `GET /api/store/brand-settings`
- Purpose: read brand default commission
- Auth: Required (professional with `store.manage` capability for target brand)
- Query params:
  - `brand_professional_id` (optional uuid)
- Behavior:
  - direct brand users default to their own brand when `brand_professional_id` is omitted
  - non-direct users (enterprise/team) must provide `brand_professional_id`
- Common status codes: 200, 401, 403

#### `PATCH /api/store/brand-settings`
- Purpose: set default commission rate/favourites for a managed brand
- Auth: Required (professional with `store.manage` capability for target brand)
- Request body: `{ "brand_professional_id": "uuid?", "default_commission_rate": 15, "favourite_brand_product_ids": ["uuid"] }`
- Response (200): `{ "brand_professional_id": "uuid", "default_commission_rate": 15, "favourite_brand_product_ids": [] }`
- Common status codes: 200, 401, 403, 422

### Store Analytics (Shopify-Canonical)

Analytics is sourced from canonical Shopify order events normalized into `retail.orders` and daily aggregates in the `analytics` schema.

#### Legacy Cutover Endpoints

#### `GET /api/store/analytics`
#### `GET /api/store/brand-analytics`

- Purpose: legacy compatibility during cutover
- Behavior: both endpoints return `410 Gone` with migration targets
- Common status codes: 410, 401

#### Brand Analytics Endpoints

- `GET /api/store/brand-analytics/overview`
- `GET /api/store/brand-analytics/influencers`
- `GET /api/store/brand-analytics/influencers/{professionalId}`
- `GET /api/store/brand-analytics/products`
- `GET /api/store/brand-analytics/products/{brandProductId}`
- `GET /api/store/brand-analytics/commissions`
- `GET /api/store/brand-analytics/timeseries`

Scope and access:
- Auth: Required (professional)
- Scope: restricted to brand IDs the caller can access for the required capability (`BrandAccessService` RBAC scope)
- Non-financial endpoints (`overview`, `influencers`, `influencers/{professionalId}`, `products`, `products/{brandProductId}`, `timeseries`) require `analytics.non_financial.read`
- Financial endpoints (`commissions`) require `analytics.financial.read`
- `brand_professional_id` outside scope is rejected with 403

#### My Analytics Endpoints (Self-Scoped)

- `GET /api/store/my-analytics/overview`
- `GET /api/store/my-analytics/products`
- `GET /api/store/my-analytics/products/{brandProductId}`
- `GET /api/store/my-analytics/commissions`
- `GET /api/store/my-analytics/customers`
- `GET /api/store/my-analytics/timeseries`

Scope and access:
- Auth: Required (professional)
- Scope: always fixed to authenticated professional as affiliate/influencer
- No `professional_id` override accepted

#### Common Query Parameters

Validated query params (endpoint-relevant subsets apply):
- `from`, `to` (`Y-m-d`)
- `group_by` (`day|week|month`)
- `brand_professional_id` (brand endpoints only)
- `product_ids[]`, `categories[]`, `collections[]`
- `regions[]`, `lifecycle_status[]`, `financial_status[]`, `payout_status[]` (for commission ledger endpoints)
- `sort_by`, `sort_dir` (`asc|desc`), `page`, `per_page` (max 100)

Rollups:
- Weekly/monthly results are query-time rollups from daily tables
- Week buckets use Monday start (`date_trunc('week', day::timestamp)::date`)

### Media Uploads (images and videos, server-side processing)

Images and videos are uploaded through the Comet API (not directly to storage). Each upload stores the original on the media disk (Laravel Cloud Object Storage / Cloudflare R2) and enqueues a processing job.

**Processing modes:**
- **Images:** GD-based WebP transcoding on the `images` queue. Queue `sync` mode processes inline. Async mode: poll until `processing_state = ready`.
- **Videos:** FFmpeg-based MP4 + HLS transcoding on the dedicated `videos` queue (`redis_video` connection). Always async in production. Requires `COMET_VIDEO_UPLOADS_ENABLED=true`.

**Pool limits:** Images and videos share the same per-pool cap (default 5 per pool). A video upload occupies a slot that could hold an image.

#### `POST /api/uploads`

- Auth: Required (professional)
- Content-Type: `multipart/form-data`
- Request body:
  - `pool` (required): `gallery` or `content`
  - `image` OR `video` (exactly one required): file upload
    - `image`: JPEG, PNG, or WebP; max `COMET_IMAGE_MAX_UPLOAD_KB` (default 10 MB)
    - `video`: MP4, MOV, WebM, or AVI; max `COMET_VIDEO_MAX_UPLOAD_KB` (default 500 MB); max duration `COMET_VIDEO_MAX_DURATION_SECONDS` (default 300s / 5 min); requires `COMET_VIDEO_UPLOADS_ENABLED=true`
  - `alt_text` (optional): string, max 255
- Response (201) — image, sync mode:
```json
{
  "id": "uuid",
  "pool": "gallery",
  "media_type": "image",
  "processing_state": "ready",
  "processing": false,
  "processing_error": null,
  "original_path": "images/<proId>/<imageId>/original_abc123.jpg",
  "variants": {
    "optimized": "https://cdn.example.com/images/.../optimized_abc123.webp",
    "maximized": "https://cdn.example.com/images/.../maximized_def456.webp"
  }
}
```
- Response (201) — video (always async):
```json
{
  "id": "uuid",
  "pool": "gallery",
  "media_type": "video",
  "processing_state": "pending",
  "processing": true,
  "processing_error": null,
  "duration_ms": null,
  "poster": null,
  "variants": [],
  "streams": []
}
```
- Response (201) — video, after processing completes (`processing_state = ready`):
```json
{
  "id": "uuid",
  "pool": "gallery",
  "media_type": "video",
  "processing_state": "ready",
  "processing": false,
  "processing_error": null,
  "duration_ms": 45000,
  "poster": "https://cdn.example.com/videos/.../poster.jpg",
  "variants": {
    "optimized": "https://cdn.example.com/videos/.../optimized.mp4",
    "maximized": "https://cdn.example.com/videos/.../maximized.mp4"
  },
  "streams": {
    "optimized": "https://cdn.example.com/videos/.../hls/optimized/playlist.m3u8",
    "maximized": "https://cdn.example.com/videos/.../hls/maximized/playlist.m3u8",
    "adaptive":  "https://cdn.example.com/videos/.../hls/adaptive.m3u8"
  }
}
```
- `processing_state` lifecycle: `pending → processing → ready | failed`
- `processing` is a boolean alias for `processing_state IN (pending, processing)` (backward-compatible)
- Business rules:
  - Max 5 items per pool per professional, shared across images and videos (configurable via `COMET_GALLERY_IMAGE_MAX` / `COMET_CONTENT_IMAGE_MAX`)
  - Race-safe: PostgreSQL advisory locks
  - Video uploads rejected with 422 if `COMET_VIDEO_UPLOADS_ENABLED=false`
- Common status codes: 201, 401, 403, 422 (pool limit, validation, feature flag)

#### `GET /api/images`

- Auth: Required (professional)
- Query params:
  - `pool` (optional): `gallery` or `content`
  - `media_type` (optional): `image` | `video` | `all` — default `image` (backward-compatible)
  - `ids[]` (optional): list of UUIDs — return only these items (efficient polling during upload)
- Response (200):
```json
{
  "images": [
    {
      "id": "uuid",
      "pool": "gallery",
      "alt_text": "Fade haircut",
      "sort_order": 0,
      "media_type": "image",
      "processing_state": "ready",
      "processing": false,
      "processing_error": null,
      "variants": {
        "optimized": "https://cdn.example.com/images/.../optimized_abc123.webp",
        "maximized": "https://cdn.example.com/images/.../maximized_def456.webp"
      },
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
- Video polling pattern: `GET /api/images?media_type=video&ids[]=<id>` — stop polling when `processing_state` is `ready` or `failed`.
- Common status codes: 200, 401, 403

#### `POST /api/images/reorder`

- Auth: Required (professional)
- Request body:
  - `pool` (required): `gallery` or `content`
  - `media_type` (optional): `image` | `video` — default `image`; reorder scope is `pool + media_type`
  - `ids` (required): array of UUIDs in desired order
- Response (200): `{ "ok": true }`
- Common status codes: 200, 401, 403, 422

#### `DELETE /api/images/{image}`

- Auth: Required (professional)
- Images: synchronous cleanup (variant files + original + DB rows deleted immediately)
- Videos: async cleanup via `DeleteMediaArtifactsJob` (many HLS segment files); `SiteImage` is soft-deleted immediately so the response is fast
- Response (200): `{ "deleted": true }`
- Common status codes: 200, 401, 403, 404

### Gallery (ordering & legacy routes)

Gallery-pool images can also be accessed / managed via the legacy gallery routes:

- `GET /api/gallery` — list gallery-pool images with variants (same format as GET /api/images?pool=gallery)
- `POST /api/gallery` — **deprecated (410)**: use `POST /api/uploads` with `pool=gallery` instead
- `POST /api/gallery/reorder` — reorder gallery images; body: `{ "ids": ["uuid1", "uuid2", ...] }`
- `DELETE /api/gallery/{image}` — delete a gallery image and its variants

### Shopify Integration

Shopify integration is brand-scoped and used for canonical order attribution/analytics ingestion.

#### `GET /api/shopify/status`

- Purpose: get Shopify connection status for a managed brand
- Auth: Required (professional)
- Query params:
  - `brand_professional_id` (optional uuid)
- Response (200):
  - `{ "eligible": true, "connected": true, "brand_professional_id": "uuid", "shop_domain": "example.myshopify.com", "shop_id": "12345", "expires_at": "...", "webhook_registration_state": "queued|pending_manual_setup|...", "webhook_registration_last_attempt_at": "...", "webhook_orders_topic": "orders/paid" }`
- Notes:
  - direct brand users default to their own brand when `brand_professional_id` is omitted
  - non-direct users must supply a brand they are authorized to manage for Shopify
  - when a non-direct caller omits `brand_professional_id`, response remains `eligible=false`
- Common status codes: 200, 401

#### `POST /api/shopify/connect`

- Purpose: connect/update Shopify credentials + metadata for a managed brand
- Auth: Required (`shopify.manage` capability for target brand)
- Request body:
  - `brand_professional_id` (optional uuid for direct brand users; required for non-direct users)
  - `shop_domain` (required string)
  - `access_token` (required string)
  - `refresh_token` (optional string)
  - `expires_at` (optional date)
  - `shop_id` (optional string)
  - `scopes[]` (optional string array)
  - `webhook_orders_topic` (optional string; default from config)
- Behavior:
  - normalizes and persists `provider_metadata.shop_domain`
  - rejects if the same `shop_domain` is already connected to another brand (409)
  - queues `RegisterShopifyOrderWebhooksJob`
- Response (200): `{ "connected": true, "brand_professional_id": "uuid", "shop_domain": "...", "shop_id": "...", "expires_at": "...", "webhook_registration_queued": true }`
- Common status codes: 200, 401, 403, 409, 422

#### `POST /api/shopify/disconnect`

- Purpose: remove Shopify integration row for managed brand
- Auth: Required (`shopify.manage` capability for target brand)
- Request body:
  - `brand_professional_id` (optional uuid for direct brand users; required for non-direct users)
- Response (200): `{ "connected": false, "brand_professional_id": "uuid" }`
- Common status codes: 200, 401, 403

#### `GET /api/shopify/token`

- Purpose: fetch decrypted Shopify access token for connected managed brand
- Auth: Required (`shopify.manage` capability for target brand)
- Query params:
  - `brand_professional_id` (optional uuid for direct brand users; required for non-direct users)
- Response (200): `{ "brand_professional_id": "uuid", "access_token": "...", "expires_at": "...", "shop_domain": "...", "shop_id": "..." }`
- Common status codes: 200, 401, 403, 404

#### `POST /api/shopify/webhooks/register`

- Purpose: queue webhook registration/refresh job for connected Shopify integration of a managed brand
- Auth: Required (`shopify.manage` capability for target brand)
- Request body:
  - `brand_professional_id` (optional uuid for direct brand users; required for non-direct users)
- Response (200): `{ "queued": true, "integration_id": "uuid", "brand_professional_id": "uuid" }`
- Common status codes: 200, 401, 403, 404

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

### Shopify Webhooks

Shopify order ingestion endpoints have **no auth middleware**. Signature validation is handled at controller level.

#### `POST /api/webhooks/shopify/orders`

- Purpose: receive native Shopify order webhook events
- Auth: Shopify HMAC via `X-Shopify-Hmac-Sha256` header (`SHOPIFY_WEBHOOK_SECRET`)
- Required headers:
  - `X-Shopify-Webhook-Id`
  - `X-Shopify-Shop-Domain`
  - `X-Shopify-Topic` (recommended)
- Behavior:
  - deduplicates by `(source, external_event_id)` in `retail.order_event_inbox`
  - resolves brand integration by `provider='shopify'` + `provider_metadata.shop_domain`
  - queues `ProcessShopifyOrderEventJob` when resolvable
  - unresolved/ambiguous brand mapping is persisted as rejected inbox event
- Response (200): `{ "received": true, "queued": true|false, "status": "pending|rejected", "inbox_id": "uuid", ... }`
- Common status codes: 200, 400 (missing required header), 401 (invalid signature)

#### `POST /api/webhooks/shopify/orders/fallback`

- Purpose: fallback ingestion path when caller only has `shop_domain + order_id` (or cached payload)
- Auth: Comet fallback HMAC via `X-Comet-Fallback-Signature` (`SHOPIFY_FALLBACK_SECRET`)
- Request body:
  - `shop_domain` (required)
  - `order_id` (required; numeric id or gid containing numeric id)
  - `event_id` (optional; default `fallback_order_{order_id}`)
  - `topic` (optional; default `orders/fallback`)
  - `payload` (optional full Shopify order object)
- Behavior:
  - if `payload` omitted, server fetches canonical order payload from Shopify Admin REST using the connected integration token
  - ingests through same inbox/normalizer path as native webhooks
- Response (200): same shape as native webhook ingestion
- Common status codes: 200, 401 (invalid fallback signature), 422 (invalid input or upstream order fetch failure)

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

## 8) Enterprise API

Enterprise routes are self-service endpoints for the authenticated user and require a Supabase JWT.

### `GET /api/enterprise/me`

- Purpose: fetch the current user's enterprise profile (if one exists)
- Response (200): `{ "enterprise": { ... } }`
- Common status codes: 200, 401, 404

### `POST /api/enterprise/me`

- Purpose: create an enterprise profile for the authenticated user
- Request body:
  - `name` (required)
  - `handle` (optional, unique among active enterprises; auto-slugged from name if omitted)
  - `enterprise_type` (required): `promoter`, `barbershop`, `salon`, `distributor`
  - `subscription_tier` (optional)
  - `primary_email`, `phone`, `public_contact_email`, `public_contact_number` (optional)
  - `country_code`, `timezone` (optional)
  - `location_street_address`, `location_city`, `location_state`, `location_postcode`, `location_country` (optional)
- Behavior:
  - Requires an existing professional profile (`/api/bootstrap` first).
  - Creates owner membership in `core.professional_enterprise_memberships`.
  - Sets `professional.primary_enterprise_id` if empty.
- Common status codes: 201, 401, 409, 422

### `PATCH /api/enterprise/me`

- Purpose: update current enterprise profile fields
- Notes:
  - `enterprise_type` allowed values: `promoter`, `barbershop`, `salon`, `distributor`
  - `status` allowed values: `active`, `inactive`, `suspended`
- Response (200): `{ "enterprise": { ... } }`
- Common status codes: 200, 401, 404, 422

### `DELETE /api/enterprise/me`

- Purpose: soft-delete/deactivate current enterprise profile
- Behavior:
  - Ends active memberships for that enterprise (`ends_at = now()`).
  - Clears `primary_enterprise_id` for professionals pointing to this enterprise.
  - Marks enterprise as `inactive` and sets `deleted_at`.
- Response (200): `{ "deleted": true }`
- Common status codes: 200, 401, 404

### Enterprise + Bootstrap integration

- If `POST /api/bootstrap` is called with `professional_type` = `promoter`, `barbershop`, or `salon`, the enterprise is auto-provisioned.
- Because of this, many enterprise-owner users will not need to call `POST /api/enterprise/me` manually.

## 9) Staff API

Staff routes are for internal staff tooling. They require a staff JWT (user must exist in core.comet_staff).

### Staff (non-admin) routes

- GET /api/staff/me
- GET /api/staff/sites/{subdomain}
- GET /api/staff/professionals?q=...&status=...&professional_type=...&per_page=...&page=...
  - `professional_type` filter supports: `professional`, `influencer`, `barber`, `hairdresser`, `ambassador`, `promoter`, `brand`, `barbershop`, `salon`
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

## 10) Media uploads & processing (images + videos)

Images and videos are uploaded through the Comet API and processed entirely server-side. No direct-to-storage uploads from the frontend.

### Architecture

1. Frontend sends `POST /api/uploads` with `pool` and either `image` or `video` as `multipart/form-data`.
2. The server validates the file, stores the original on the **media disk** (Laravel Cloud Object Storage / Cloudflare R2), creates a `site_images` row with `processing_state = pending`.
3. Processing runs on the appropriate worker queue (images → `images` queue, videos → `videos` queue on dedicated `redis_video` connection).
4. Frontend polls `GET /api/images?media_type=video&ids[]=<id>` until `processing_state` is `ready` or `failed`.

### Queue modes

| Media | Queue | Worker command |
|-------|-------|---------------|
| Images | `images` | `php artisan queue:work --queue=images` |
| Videos | `videos` (`redis_video` connection) | `php artisan queue:work redis_video --queue=videos --timeout=3600` |

Both queues fall back to sync inline processing in `local` and `testing` environments (no worker needed for dev).

### Media pools

Each professional has two pools:

- **gallery** — portfolio / showcase media (max configurable, default 5 items total)
- **content** — general-purpose branding media (max configurable, default 5 items total)

Images and videos share the same per-pool cap.

### Image processing

- Two WebP variants per upload: `optimized` (adaptive quality ~500 KB) and `maximized` (100% quality)
- GD-based encoding; content-hashed filenames for immutable CDN caching
- Inline in dev/sync mode; async via `ProcessImageVariantsJob` in production

### Video processing

- Requires `COMET_VIDEO_UPLOADS_ENABLED=true` and `ffmpeg`/`ffprobe` on the worker's `$PATH`
- Outputs per video:
  - **MP4:** `variants.optimized` (720p / 2 Mbps), `variants.maximized` (1080p / 5 Mbps)
  - **HLS:** `streams.optimized` (720p playlist), `streams.maximized` (1080p playlist), `streams.adaptive` (master playlist for ABR)
  - **Poster:** `poster` — JPEG frame extracted at ~1s
- HLS segments are stream-copied from the MP4s (no extra re-encode)
- `processing_state` lifecycle: `pending → processing → ready | failed`

### Frontend upload flow (image)

1. `POST /api/uploads` with `pool=gallery`, `image=<file>`, optional `alt_text`.
2. If `processing_state = pending/processing` → poll `GET /api/images?pool=gallery` until `ready`. If already `ready` → variants in upload response.
3. Use `variants.optimized` for normal display, `variants.maximized` for high-detail/zoom.
4. Delete: `DELETE /api/images/{image}`.
5. Reorder: `POST /api/images/reorder` with `{ "pool": "gallery", "media_type": "image", "ids": [...] }`.

### Frontend upload flow (video)

1. `POST /api/uploads` with `pool=gallery`, `video=<file>`, optional `alt_text`.
2. Response always has `processing_state = pending` (video is always async).
3. Poll `GET /api/images?media_type=video&ids[]=<id>` until `processing_state = ready` or `failed`.
4. On `ready`: render using `streams.adaptive` (best for ABR), fall back to `variants.optimized`. Use `poster` for preview/placeholder.
5. Delete: `DELETE /api/images/{image}` (storage cleanup is async for video).
6. Reorder: `POST /api/images/reorder` with `{ "pool": "gallery", "media_type": "video", "ids": [...] }`.

### Supported file types

**Images:** JPEG, PNG, WebP — max `COMET_IMAGE_MAX_UPLOAD_KB` (default 10 MB)

**Videos:** MP4, MOV, WebM, AVI — max `COMET_VIDEO_MAX_UPLOAD_KB` (default 500 MB), max duration `COMET_VIDEO_MAX_DURATION_SECONDS` (default 300s)

## 11) Test users and getting tokens

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

## 12) Insomnia collection

Import the provided Insomnia export JSON.
It contains requests for all Stage 1-2 endpoints plus Supabase login requests.

-
-
- Set workspace environment variables first (api_base_url, public_api_base_url, supabase_url, supabase_anon_key, access_token, subdomain, ids).

## 13) Frontend env var checklist

- SUPABASE_URL
- SUPABASE_ANON_KEY
- API_BASE_URL (example: https://api.comet.app/api)
- PUBLIC_DOMAIN (example: comet.app or localtest.me)
- Optionally: STAFF_DASHBOARD_ENABLED flag if you ship staff tooling in the same frontend

Note: The frontend does not need any storage credentials — all image URLs come from the API `variants` map.

## 14) Backend env var checklist

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
- COMET_LEGAL_SITE_SCHEME (default: `https`)
- COMET_LEGAL_DEFAULT_CONTACT_NAME (default: `Customer Support`)
- COMET_LEGAL_DEFAULT_SUPPORT_EMAIL (default: `support@comet.app`)
- COMET_LEGAL_DEFAULT_SUPPORT_PHONE (default: `N/A`)
- COMET_WAITLIST_ENABLED (default: `false`; when true, blocks bootstrap for new users)
- SOFT_DELETE_RETENTION_DAYS (default: 30)

### Pre-launch account gating

- Set `COMET_WAITLIST_ENABLED=true` to block new account creation at `POST /api/bootstrap`.
- Existing professionals are unaffected by this gate.
- Also disable public signups in Supabase Auth (Dashboard -> Authentication -> Providers -> Email -> Disable Signups) to prevent new auth accounts during waitlist-only mode.

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

## 15) Known implementation gotchas

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
