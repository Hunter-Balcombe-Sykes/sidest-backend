# Link Categories & Expanded Platform Registry — Design

> **Status:** Proposed.
> **Audience:** Backend (primary), affiliate-dashboard frontend (category picker & new platforms).
> **See also:** [`docs/social-links.md`](../../social-links.md), [`config/sidest.php`](../../../config/sidest.php), [`app/Services/Site/SocialLinkNormalizer.php`](../../../app/Services/Site/SocialLinkNormalizer.php).

---

## 1. Motivation

Today every link on a professional's mini-site is rendered as a flat list. There's no way for a user (or us) to say "these three are booking links, these two are content, this one is a social follow." The request is two things in one:

1. **Add a required `category` to every link block** — one of `social`, `booking`, `education`, `content`, `events`, `other`. This enables grouped rendering on the public mini-site, filtered analytics, and category-aware editing UI.
2. **Extend the platform registry** beyond the 8 existing social platforms to cover booking (Fresha, Booksy, Timely, Calendly, Square), education (Stan, Skool, Circle, Kajabi), events (Eventbrite, Humanitix, Luma, Partiful), and content (Apple Podcasts, Substack, Bandcamp). Same strict-validation guarantees the existing 8 platforms enjoy — ASCII-only handle regex, host allowlist, canonical `https://` URL rebuild.

Both ship together. The category system needs real platforms to demonstrate the grouping; the platforms need a category to slot into.

---

## 2. Spelling corrections to the user's request

The user's original list contained several typos. Confirmed corrections used throughout this spec:

| User wrote | Actual platform |
|------------|-----------------|
| fresh | Fresha |
| timely | Timely |
| booksly | Booksy |
| school | Skool |
| event brite | Eventbrite |
| humanatix | Humanitix |
| bancamp | Bandcamp |

Spellings already correct: Square, Calendly, Stan, Circle, Kajabi, Luma, Partiful, Apple Podcasts, Substack.

---

## 3. The category system

### 3.1 The fixed enum

Six categories, stored in `config('sidest.link_categories')` as the single source of truth:

```php
'link_categories' => ['social', 'booking', 'education', 'content', 'events', 'other'],
```

Every link block write path imports this list for validation (`in:social,booking,...`). No string duplication.

### 3.2 Storage

- Column: `settings.category` inside the existing `site.blocks.settings` JSONB column.
- Added to `config('sidest.link_block_settings_keys')` so the allowlist check permits writes.
- **Required on every link write.** Both social-mode and custom-mode requests must resolve to a category (either explicit, or derived from platform default).

Why JSONB not a column: zero migration, zero coordinated deploy, and the established pattern from `docs/social-links.md` §9 — promote to a column only when a query feature needs fast filtering. Equivalent security (Form Request enum validation is authoritative either way). Promotion path is already written down.

### 3.3 Default-from-platform with override (Option B from brainstorming)

Each platform in the registry gets a `default_category` field. Category resolution at write time:

1. If the request includes an explicit `category`, use it (must pass enum validation).
2. Else if the request includes a `platform`, use that platform's `default_category` from the registry.
3. Else (custom link, no platform) — the request MUST include `category`. Validation fails with 422 if absent.

The override is by design: a brand using their Instagram exclusively to announce events can tag that link as `events` even though Instagram's default is `social`.

### 3.4 Deferred promotion

When a product feature requires fast filtering/aggregation by category at scale (e.g. per-brand analytics by category, public "discover by category" feed), run the same additive migration described in `docs/social-links.md` §9:

1. `ALTER TABLE site.blocks ADD COLUMN category text NULL;`
2. Backfill from `settings->>'category'`.
3. Add `NOT NULL` + `CHECK (category IN (...))` once backfilled.
4. Update Block model fillable + writers to set column alongside `settings.category`.
5. Eventually drop `settings.category`.

Not in scope for this spec.

---

## 4. Platform registry extension

### 4.1 New registry fields

Two fields added to every entry in `config('sidest.social_platforms')` (both existing and new):

| Field | Type | Purpose |
|-------|------|---------|
| `default_category` | string enum | One of the 6 categories. The category auto-applied when this platform is selected and no override is given. |
| `handle_location` | `'path'` \| `'subdomain'` | Where the handle lives in the canonical URL. Drives which parser branch the normalizer takes. |

Existing 8 social platforms get `default_category: 'social'` and `handle_location: 'path'` — no behavioral change.

### 4.2 Subdomain mode (new parser branch)

For platforms where each user gets their own subdomain (Substack, Bandcamp, Kajabi, Circle), the existing path-based parser doesn't fit. Instead:

- `host_allowlist` stores the **base domain** (e.g. `substack.com`). A host matches if it equals the base OR ends with `.` + base (labelled suffix — the dot matters, so `evilsubstack.com` does NOT match).
- The handle is the leftmost label of the subdomain portion: `alice.substack.com` → `alice`.
- `handle_pattern` still validates (ASCII regex, bounded length).
- `url_template` uses `{handle}` as before: `https://{handle}.substack.com/`.
- `url_path_extractor` is not used in subdomain mode (the lenient URL fallback still applies — see §4.4).

### 4.3 Registry entries (new)

All 16 new platforms. Handle patterns are ASCII-only with bounded quantifiers (ReDoS-safe). `display_name`, `icon_key`, `placeholder` shown only where non-obvious.

**Booking — path mode, `default_category: 'booking'`**

| Platform | Host allowlist | URL template | Handle pattern | Path extractor |
|----------|----------------|--------------|----------------|-----------------|
| fresha | fresha.com, www.fresha.com | `https://fresha.com/a/{handle}` | `/^[a-zA-Z0-9-]{3,80}$/` | `#^/a/([a-zA-Z0-9-]{3,80})/?$#` |
| booksy | booksy.com, www.booksy.com | `https://booksy.com/en-us/{handle}` | `/^[a-zA-Z0-9_-]{3,80}$/` | `#^/[a-z]{2}-[a-z]{2}/([a-zA-Z0-9_-]{3,80})/?$#` |
| timely | gettimely.com, book.gettimely.com | `https://book.gettimely.com/book/{handle}` | `/^[a-zA-Z0-9-]{3,80}$/` | `#^/book/([a-zA-Z0-9-]{3,80})/?$#` |
| calendly | calendly.com, www.calendly.com | `https://calendly.com/{handle}` | `/^[a-zA-Z0-9-]{2,40}$/` | `#^/([a-zA-Z0-9-]{2,40})/?$#` |
| square | book.squareup.com, squareup.com | `https://book.squareup.com/appointments/{handle}` | `/^[a-zA-Z0-9-]{3,80}$/` | `#^/appointments/([a-zA-Z0-9-]{3,80})/?$#` |

**Education — path mode, `default_category: 'education'`**

| Platform | Host allowlist | URL template | Handle pattern | Path extractor |
|----------|----------------|--------------|----------------|-----------------|
| stan | stan.store, www.stan.store | `https://stan.store/{handle}` | `/^[a-zA-Z0-9_-]{2,40}$/` | `#^/([a-zA-Z0-9_-]{2,40})/?$#` |
| skool | skool.com, www.skool.com | `https://skool.com/{handle}` | `/^[a-zA-Z0-9-]{3,60}$/` | `#^/([a-zA-Z0-9-]{3,60})/?$#` |

**Education — subdomain mode, `default_category: 'education'`**

| Platform | Base domain | URL template | Handle pattern |
|----------|-------------|--------------|----------------|
| kajabi | mykajabi.com | `https://{handle}.mykajabi.com/` | `/^[a-zA-Z0-9-]{3,63}$/` |
| circle | circle.so | `https://{handle}.circle.so/` | `/^[a-zA-Z0-9-]{3,63}$/` |

**Events — path mode, `default_category: 'events'`**

| Platform | Host allowlist | URL template | Handle pattern | Path extractor |
|----------|----------------|--------------|----------------|-----------------|
| eventbrite | eventbrite.com, www.eventbrite.com | `https://eventbrite.com/o/{handle}` | `/^[a-zA-Z0-9-]{3,80}$/` | `#^/o/([a-zA-Z0-9-]{3,80})/?$#` |
| humanitix | humanitix.com, www.humanitix.com, events.humanitix.com | `https://humanitix.com/host/{handle}` | `/^[a-zA-Z0-9-]{3,80}$/` | `#^/host/([a-zA-Z0-9-]{3,80})/?$#` |
| luma | lu.ma, www.lu.ma | `https://lu.ma/{handle}` | `/^[a-zA-Z0-9-]{2,40}$/` | `#^/([a-zA-Z0-9-]{2,40})/?$#` |
| partiful | partiful.com, www.partiful.com | `https://partiful.com/u/{handle}` | `/^[a-zA-Z0-9-]{3,40}$/` | `#^/u/([a-zA-Z0-9-]{3,40})/?$#` |

**Content — path mode, `default_category: 'content'`**

| Platform | Host allowlist | URL template | Handle pattern | Path extractor |
|----------|----------------|--------------|----------------|-----------------|
| apple_podcasts | podcasts.apple.com | (see note) | `/^\d{5,15}$/` | `#^/[a-z]{2}/podcast/[a-zA-Z0-9-]+/id(\d{5,15})/?$#` |

Apple Podcasts' canonical URL includes a slug AND an ID; the ID is the stable identifier. `url_template` is `https://podcasts.apple.com/us/podcast/{handle}` — but since extraction returns only the numeric ID, we lean heavily on the lenient URL fallback here. Most users will paste the full URL.

**Content — subdomain mode, `default_category: 'content'`**

| Platform | Base domain | URL template | Handle pattern |
|----------|-------------|--------------|----------------|
| substack | substack.com | `https://{handle}.substack.com/` | `/^[a-zA-Z0-9-]{3,63}$/` |
| bandcamp | bandcamp.com | `https://{handle}.bandcamp.com/` | `/^[a-zA-Z0-9-]{3,63}$/` |

### 4.4 Lenient URL fallback is unchanged

The existing behavior from `docs/social-links.md` §5.2 still applies for path-mode platforms: if the user pastes a deep URL that doesn't match the `url_path_extractor` (e.g. a specific Eventbrite event, an Apple Podcasts episode), the normalizer:

- Verifies the host matches the allowlist.
- Forces `https://`.
- Rebuilds the URL from parsed parts (drops tracking params).
- Stores it as-is with the platform tag but no handle.

This covers the real-world case where users want to link to a specific event/episode, not a profile root.

For subdomain-mode platforms, the lenient fallback means: host validates (labelled suffix check), https forcing applies, URL stored as pasted. Handle extraction only succeeds if the URL is the bare `https://alice.substack.com/` root.

### 4.5 New icon keys

16 new entries added to `link_block_icon_keys`:

`fresha`, `booksy`, `timely`, `calendly`, `square`, `stan`, `skool`, `kajabi`, `circle`, `eventbrite`, `humanitix`, `luma`, `partiful`, `apple_podcasts`, `substack`, `bandcamp`.

Frontend (Tobias) supplies the actual icon assets; backend only validates the key.

---

## 5. API changes

### 5.1 Write path — `StoreLinkBlockRequest` and `UpdateLinkBlockRequest`

Both Form Request classes gain one rule and one conditional rule:

- `category`: required if `platform` is absent; optional (override) if `platform` is present. Must be in `config('sidest.link_categories')`. Enforced via `Rule::in($categories)`.
- No other rule changes. Existing `platform` / `handle` / `url` / `title` / `icon_key` rules unchanged.

`StaffStoreLinkRequest` / `StaffUpdateLinkRequest` inherit from the professional versions, so they pick up the changes automatically.

### 5.2 Controller — `ProfessionalLinkBlockController::buildBlockFields()`

This is the single source of truth for write-shape construction. One change:

After platform normalization (if any), resolve the final category:
1. If the request provided `category`, use it.
2. Else if a platform was used, use its `default_category` from the registry.
3. Else — should have been caught by validation, but defensively 422.

Write `settings.category = $resolved`.

`StaffLinkBlockManagementController` uses the same helper or mirrors the logic.

### 5.3 `SocialLinkNormalizer` — subdomain parser branch

New branch at the top of the URL-parsing path:

```php
if ($platform['handle_location'] === 'subdomain') {
    // 1. parse_url -> host
    // 2. labelled-suffix check against $platform['host_allowlist'][0] (the base)
    // 3. split host -> leftmost label = candidate handle
    // 4. if leftmost label matches handle_pattern: canonical URL = url_template substitution
    // 5. else: lenient fallback -> keep URL, force https, no handle extracted
}
```

Handle path (user supplies a clean handle without a URL) works identically to path mode — strip leading `@`, trim, validate against regex, substitute into `url_template`.

Labelled-suffix check in PHP:

```php
$host === $base || str_ends_with($host, '.' . $base)
```

The leading dot is essential — `evilsubstack.com` must not pass the `substack.com` check.

### 5.4 Public registry endpoint

Currently: `GET /api/public/config/social-platforms` returns `{ platforms: [{ key, display_name, icon_key, placeholder }, ...] }`.

**Two changes, backward-compatible:**

1. Each platform gains a `category` field in the response (derived from `default_category`).
2. Response adds a sibling `categories` array: `['social', 'booking', 'education', 'content', 'events', 'other']`. This gives the frontend the canonical enum for category pickers without hard-coding it.

Internal fields (`handle_pattern`, `host_allowlist`, `url_path_extractor`, `url_template`, `handle_location`) continue to stay server-side only. Same security rationale as today.

**Endpoint name:** kept as `social-platforms` — renaming creates churn with no benefit (we're pre-beta, the only current caller is the affiliate dashboard and Tobias can update both at once; but the existing name is short and the concept is still "a platform registry for link blocks"). If the name becomes genuinely misleading we rename later.

### 5.5 Response shape change — `LinkBlockResource` / Block resources

The read resource adds one field to its output:

```json
{
  "id": "...",
  "title": "...",
  "url": "...",
  "icon_key": "...",
  "sort_order": 0,
  "is_active": true,
  "category": "booking",
  "settings": { "platform": "calendly", "handle": "joshhunter", "category": "booking" }
}
```

The top-level `category` is a convenience for frontends that don't want to reach into `settings`. It's a computed read — `settings.category` is the source of truth. We surface it at the top level the same way `icon_key` is surfaced separately from `settings`.

---

## 6. Backfill

Every existing `site.blocks` row with `block_group='links'` needs `settings.category` populated. Without it, the first post-deploy write to those rows would fail validation (the updated Form Request requires `category`).

**Command:** extend the existing `BackfillSocialLinksCommand` (rather than create a new one — the concerns overlap: both walk all link rows, both write to `settings`). Keep the existing `php artisan sidest:backfill-social-links` signature for operator continuity; add alias `php artisan sidest:backfill-link-categories` for discoverability.

**Resolution logic per row:**

1. If `settings->>'category'` is already set AND is in the enum → skip (idempotent).
2. Else if `settings->>'platform'` is set → look up platform in registry → use `default_category`.
3. Else → `category = 'other'`.

**Properties preserved from the existing command:**

- Idempotent (safe to re-run).
- Chunked (200 rows per transaction).
- Stats table output.
- Dry-run flag.
- Audit log entry on start.

**Deployment order:**

1. Deploy code with the new config (enum + platform entries + normalizer changes).
2. **Before** enabling the new `category` validation rule, run the backfill in production.
3. Enable validation.

Because validation is enforced in the Form Request (PHP) not the DB, steps 2 and 3 are the same deploy — validation takes effect as soon as code ships. Solution: ship the backfill first in one release, then the validation rule in a follow-up release 24h later. OR: ship together and accept that between the migration running and the validation taking effect, there's a ~30-second window where writes could briefly fail on unbackfilled rows. Given we're pre-beta with no customers, the second option is acceptable.

---

## 7. Tests

**New / extended tests (all Pest, all in `tests/Feature/Site/`):**

1. `SocialLinkNormalizerTest` — add one test case per new platform covering: clean handle, URL extraction, deep-link lenient fallback, wrong-host rejection. 16 new cases × 4 scenarios ≈ 64 new assertions. Follows existing file's pattern exactly.
2. `SocialLinkNormalizerTest` — add subdomain-mode cases: valid subdomain, labelled-suffix attack (`evilsubstack.com` must reject), handle-too-short, handle-with-special-chars.
3. `LinkBlockCategoryValidationTest` — new file. Covers: category required for custom link, category optional+overrides for social, category enum rejection (`"random"` → 422), platform→category default derivation, override.
4. `PublicConfigSocialPlatformsTest` — extend to assert `category` field present and the top-level `categories` array is returned.
5. One-line assertion in an existing feature test: after creating a link block via the API, `settings.category` is always in the enum.

Target: `composer test` green, no new lint warnings, no new Laravel migrations (composer guard enforces this).

---

## 8. Security considerations

### 8.1 Homoglyph + ReDoS posture unchanged

All new `handle_pattern` regexes use ASCII character classes only (`[a-zA-Z0-9-]`, `[a-zA-Z0-9_-]`) with bounded quantifiers. Same guarantees as the existing 8 platforms: no Cyrillic/Greek impersonation, no catastrophic backtracking.

### 8.2 Subdomain-mode labelled-suffix check

The single most important security detail in this spec: subdomain-mode host validation **must** check `.` + base, not just base.

- Correct: `str_ends_with($host, '.' . $base)` → `evil.substack.com` passes, `evilsubstack.com` fails.
- Wrong: `str_ends_with($host, $base)` → both pass. This is an open-phishing vulnerability.

Covered by a dedicated Pest test case asserting `evilsubstack.com` rejects.

### 8.3 `https://` forcing

Every canonical URL — path mode or subdomain mode — is rebuilt with `https://` even if the user pasted `http://`. No change from today's posture.

### 8.4 Icon key allowlist

`link_block_icon_keys` is a flat allowlist. Each new icon key is added explicitly. No wildcard, no user-controlled icon keys.

### 8.5 Category enum validation

`category` validated against `config('sidest.link_categories')` in the Form Request. No path for unexpected values to reach storage via the public API.

### 8.6 Defense-in-depth — frontend escaping

Unchanged from `docs/social-links.md` §7.2 and §8.1. `category`, `platform`, and `handle` are all untrusted strings on render. Frontends must rely on framework auto-escaping; unsafe-HTML renderers must not be used on any link block field. See the referenced sections for the full list of forbidden render paths.

### 8.7 No SSRF, no backend fetching

Unchanged — the backend never fetches a user-supplied URL. If link previews are ever added, the guidance in `docs/social-links.md` §8.6 applies.

---

## 9. Scalability considerations

### 9.1 Reads

Per-row access (`settings->>'category'`, `settings->>'platform'`) is O(1) on already-parsed JSONB. No measurable difference from a real column for single-row or per-site reads.

### 9.2 Aggregation queries

Grouping/filtering by category at scale benefits from a functional index when query patterns emerge:

```sql
CREATE INDEX blocks_category_idx ON site.blocks ((settings->>'category'));
```

Not added in v1 — we have no such query yet. Added when the first one appears.

### 9.3 Promotion to a real column

Documented in `docs/social-links.md` §9 and replayed in §3.4 here. Additive migration, no API breakage.

### 9.4 Registry growth

The registry currently has 8 entries; this change brings it to 24. Even 100 platforms would sit comfortably in `config/sidest.php` — the config is loaded once per boot and cached by Laravel's config caching. No runtime lookup cost.

### 9.5 Public registry endpoint caching

`GET /api/public/config/social-platforms` already sends `Cache-Control: public, max-age=3600`. CDN absorbs the 3x traffic from more platforms. No change needed.

---

## 10. Out of scope

- **Column promotion for `category` or `platform`.** Deferred per §3.4.
- **Per-category UI styling on the public mini-site.** This spec lands the data model; how brands render grouped-by-category links is a frontend-design decision for a later spec.
- **Analytics dashboards filtered by category.** Data is now tagged; dashboards come later.
- **Endpoint rename** (`social-platforms` → `link-platforms`). Kept as-is; revisit if the name becomes confusing.
- **New platforms beyond the 16 listed.** Adding a 17th follows the same process described in `docs/social-links.md` §3.

---

## 11. Implementation pointers

| Concern | File |
|---------|------|
| Category enum | `config/sidest.php` — new `link_categories` key |
| Settings allowlist | `config/sidest.php` — `link_block_settings_keys` (add `category`) |
| Icon allowlist | `config/sidest.php` — `link_block_icon_keys` (add 16 entries) |
| Platform registry | `config/sidest.php` — `social_platforms` (add `default_category`, `handle_location` to existing 8; add 16 new entries) |
| Normalizer branch | `app/Services/Site/SocialLinkNormalizer.php` — new subdomain-mode path |
| Write-path validation | `app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php`, `UpdateLinkBlockRequest.php` |
| Category resolution | `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalLinkBlockController.php` — `buildBlockFields()` |
| Read shape | `app/Http/Resources/Professional/LinkBlockResource.php` (or equivalent) — surface `category` top-level |
| Public registry | `app/Http/Controllers/Api/PublicSite/PublicConfigController.php` — `socialPlatforms()` returns `category` per-platform + top-level `categories` |
| Backfill | `app/Console/Commands/BackfillSocialLinksCommand.php` — extend with category-resolution step |
| Docs | `docs/social-links.md` — update §2 platform table, §3 adding-a-platform steps (mention `default_category` + `handle_location`), §9 category promotion path |

---

## 12. Open questions

None at time of writing. All design decisions resolved during brainstorming:

- Categories: fixed enum, 6 values, required on every write.
- Storage: `settings.category` JSONB, promotion deferred.
- Override: platform default, user can override.
- Subdomain handling: extend registry with `handle_location`, one normalizer branch.
- Strict validation for all new platforms (with existing lenient URL fallback for deep links).
- Backfill: extend the existing command.
