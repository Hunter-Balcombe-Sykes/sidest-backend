# Link Categories & Expanded Platform Registry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a required `category` enum (social/booking/education/content/events/other) to every link block, plus 16 new platforms across booking/education/events/content — with strict ASCII-handle, host-allowlist, https-canonical validation, and support for subdomain-handle platforms (Substack/Bandcamp/Kajabi/Circle).

**Architecture:** Zero schema changes. Category + platform tags live in `site.blocks.settings` JSONB. Existing `SocialLinkNormalizer` gets one new parser branch for subdomain-handle platforms, driven by a new per-platform `handle_location` registry field. The public `/api/public/config/social-platforms` endpoint response grows two fields (`category` per platform, `categories` sibling array) but stays backward-compatible for existing consumers. No new Laravel migrations (composer guard would reject them).

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, Postgres JSONB. Config-driven registry in `config/sidest.php`.

**Spec:** [`docs/superpowers/specs/2026-04-22-link-categories-and-platforms-design.md`](../specs/2026-04-22-link-categories-and-platforms-design.md)

**Scope adjustment from spec:**
- Spec §5.5 described a top-level `category` field on a read Resource. The link block controllers currently return raw `Block` Eloquent models (no `LinkBlockResource` class in use — confirmed via reading `ProfessionalLinkBlockController::index`). Since `settings` is cast to array and already serializes by default, `settings.category` appears in every API response automatically. No resource layer needed; readers access `block.settings.category`. This matches how `settings.platform` and `settings.handle` are already surfaced.

---

## Task 1: Add category enum + settings key to config

**Files:**
- Modify: `config/sidest.php:33-45` (extend `link_block_settings_keys`)
- Modify: `config/sidest.php:46` (insert new `link_categories` key between settings and social_platforms registry)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Config/LinkCategoriesConfigTest.php`:

```php
<?php

it('exposes the 6 link categories in config', function () {
    $categories = config('sidest.link_categories');

    expect($categories)->toBe(['social', 'booking', 'education', 'content', 'events', 'other']);
});

it('includes category in the link_block_settings_keys allowlist', function () {
    expect(config('sidest.link_block_settings_keys'))->toContain('category');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: FAIL — `link_categories` is null; `category` not in settings allowlist.

- [ ] **Step 3: Edit `config/sidest.php` — add `category` to `link_block_settings_keys`**

Find the existing block at lines 33-45:

```php
    'link_block_settings_keys' => [
        'open_in_new_tab',
        'rel_nofollow',
        'rel_sponsored',
        'rel_ugc',
        'highlight',
        'note',
        // Social link tagging — set by SocialLinkNormalizer when a brand-controlled
        // platform is selected. Soft tag in JSONB rather than a column; promote to
        // a real column (Option B) only when query-ability matters. See docs/social-links.md.
        'platform',
        'handle',
    ],
```

Replace with:

```php
    'link_block_settings_keys' => [
        'open_in_new_tab',
        'rel_nofollow',
        'rel_sponsored',
        'rel_ugc',
        'highlight',
        'note',
        // Social link tagging — set by SocialLinkNormalizer when a brand-controlled
        // platform is selected. Soft tag in JSONB rather than a column; promote to
        // a real column (Option B) only when query-ability matters. See docs/social-links.md.
        'platform',
        'handle',
        // Link category — one of config('sidest.link_categories'). Required on every
        // write; resolved from the platform's default_category when not supplied.
        // Same JSONB-first rationale as `platform` above.
        'category',
    ],

    /*
    |--------------------------------------------------------------------------
    | Link categories
    |--------------------------------------------------------------------------
    |
    | Fixed enum applied to every link block in site.blocks.settings.category.
    | One source of truth — imported by the Form Requests, the public registry
    | endpoint response, and the backfill command. Do not add values without
    | updating the frontend category picker and confirming the public mini-site
    | renderer handles the new value.
    */
    'link_categories' => ['social', 'booking', 'education', 'content', 'events', 'other'],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: PASS — both assertions green.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Unit/Config/LinkCategoriesConfigTest.php
git commit -m "feat(links): add link_categories enum and settings key"
```

---

## Task 2: Add 16 new icon keys to the icon allowlist

**Files:**
- Modify: `config/sidest.php:13-32` (`link_block_icon_keys`)

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Config/LinkCategoriesConfigTest.php`:

```php
it('includes the 16 new platform icon keys in the allowlist', function () {
    $keys = config('sidest.link_block_icon_keys');

    foreach ([
        'fresha', 'booksy', 'timely', 'calendly', 'square',
        'stan', 'skool', 'kajabi', 'circle',
        'eventbrite', 'humanitix', 'luma', 'partiful',
        'apple_podcasts', 'substack', 'bandcamp',
    ] as $expected) {
        expect($keys)->toContain($expected);
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: FAIL — none of the 16 new keys present.

- [ ] **Step 3: Edit `config/sidest.php` — extend `link_block_icon_keys`**

Find lines 13-32:

```php
    'link_block_icon_keys' => [
        // Functional / custom-link icons
        'scissors',
        'calendar',
        'map',
        'phone',
        'website',
        'link',
        'email',
        'whatsapp',
        // Social platform icons (mirrored in social_platforms registry below)
        'instagram',
        'facebook',
        'linkedin',
        'youtube',
        'tiktok',
        'x',
        'spotify',
        'soundcloud',
    ],
```

Replace with:

```php
    'link_block_icon_keys' => [
        // Functional / custom-link icons
        'scissors',
        'calendar',
        'map',
        'phone',
        'website',
        'link',
        'email',
        'whatsapp',
        // Social platform icons (mirrored in social_platforms registry below)
        'instagram',
        'facebook',
        'linkedin',
        'youtube',
        'tiktok',
        'x',
        'spotify',
        'soundcloud',
        // Booking platform icons
        'fresha',
        'booksy',
        'timely',
        'calendly',
        'square',
        // Education platform icons
        'stan',
        'skool',
        'kajabi',
        'circle',
        // Event platform icons
        'eventbrite',
        'humanitix',
        'luma',
        'partiful',
        // Content platform icons
        'apple_podcasts',
        'substack',
        'bandcamp',
    ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Unit/Config/LinkCategoriesConfigTest.php
git commit -m "feat(links): allowlist 16 new platform icon keys"
```

---

## Task 3: Add `default_category` and `handle_location` to the 8 existing social platforms

**Files:**
- Modify: `config/sidest.php:74-150` (`social_platforms` — all 8 existing entries)

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Config/LinkCategoriesConfigTest.php`:

```php
it('each existing social platform has default_category=social and handle_location=path', function () {
    foreach (['instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x', 'spotify', 'soundcloud'] as $key) {
        $config = config("sidest.social_platforms.{$key}");
        expect($config['default_category'])->toBe('social', "platform {$key} missing default_category=social");
        expect($config['handle_location'])->toBe('path', "platform {$key} missing handle_location=path");
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: FAIL — keys don't exist on any platform.

- [ ] **Step 3: Edit `config/sidest.php` — add two fields to each of the 8 existing entries**

For each of `instagram`, `facebook`, `linkedin`, `youtube`, `tiktok`, `x`, `spotify`, `soundcloud`, add two lines at the end of the entry (before the closing `],`). Example for instagram:

```php
        'instagram' => [
            'display_name' => 'Instagram',
            'icon_key' => 'instagram',
            'placeholder' => '@yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._]{1,30}$/',
            'url_template' => 'https://instagram.com/{handle}',
            'host_allowlist' => ['instagram.com', 'www.instagram.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9._]{1,30})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
```

Apply the same two lines (`'default_category' => 'social'`, `'handle_location' => 'path'`) to all 8 existing entries.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: PASS — all 8 platforms have both fields.

- [ ] **Step 5: Verify existing tests still pass**

Run: `./vendor/bin/pest tests/Feature/Site/SocialLinkNormalizerTest.php`
Expected: PASS — normalizer doesn't read the new fields yet, so old behavior is preserved.

- [ ] **Step 6: Commit**

```bash
git add config/sidest.php tests/Unit/Config/LinkCategoriesConfigTest.php
git commit -m "feat(links): tag existing 8 social platforms with default_category and handle_location"
```

---

## Task 4: Add 5 booking platforms (path mode)

**Files:**
- Modify: `config/sidest.php` — append to `social_platforms` array

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Config/LinkCategoriesConfigTest.php`:

```php
it('registers the 5 booking platforms with default_category=booking', function () {
    foreach (['fresha', 'booksy', 'timely', 'calendly', 'square'] as $key) {
        $config = config("sidest.social_platforms.{$key}");
        expect($config)->not->toBeNull("booking platform {$key} not registered");
        expect($config['default_category'])->toBe('booking');
        expect($config['handle_location'])->toBe('path');
        expect($config['url_template'])->toStartWith('https://');
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: FAIL — none of these platforms exist yet.

- [ ] **Step 3: Edit `config/sidest.php` — append booking block to `social_platforms`**

After the existing `soundcloud` entry (last in the array), before the closing `],` of `social_platforms`, append:

```php
        // --- Booking platforms (default_category: booking) ---
        'fresha' => [
            'display_name' => 'Fresha',
            'icon_key' => 'fresha',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://fresha.com/a/{handle}',
            'host_allowlist' => ['fresha.com', 'www.fresha.com'],
            'url_path_extractor' => '#^/a/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'booksy' => [
            'display_name' => 'Booksy',
            'icon_key' => 'booksy',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,80}$/',
            'url_template' => 'https://booksy.com/en-us/{handle}',
            'host_allowlist' => ['booksy.com', 'www.booksy.com'],
            // Booksy URLs include a locale prefix (e.g. /en-us/12345_salon-name)
            'url_path_extractor' => '#^/[a-z]{2}-[a-z]{2}/([a-zA-Z0-9_-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'timely' => [
            'display_name' => 'Timely',
            'icon_key' => 'timely',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://book.gettimely.com/book/{handle}',
            'host_allowlist' => ['gettimely.com', 'book.gettimely.com', 'www.gettimely.com'],
            'url_path_extractor' => '#^/book/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'calendly' => [
            'display_name' => 'Calendly',
            'icon_key' => 'calendly',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{2,40}$/',
            'url_template' => 'https://calendly.com/{handle}',
            'host_allowlist' => ['calendly.com', 'www.calendly.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{2,40})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'square' => [
            'display_name' => 'Square',
            'icon_key' => 'square',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://book.squareup.com/appointments/{handle}',
            'host_allowlist' => ['book.squareup.com', 'squareup.com', 'www.squareup.com'],
            'url_path_extractor' => '#^/appointments/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Unit/Config/LinkCategoriesConfigTest.php
git commit -m "feat(links): register 5 booking platforms (fresha, booksy, timely, calendly, square)"
```

---

## Task 5: Add 2 path-mode education platforms (Stan, Skool)

**Files:**
- Modify: `config/sidest.php` — append to `social_platforms`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Config/LinkCategoriesConfigTest.php`:

```php
it('registers stan and skool as education path-mode platforms', function () {
    foreach (['stan', 'skool'] as $key) {
        $config = config("sidest.social_platforms.{$key}");
        expect($config)->not->toBeNull();
        expect($config['default_category'])->toBe('education');
        expect($config['handle_location'])->toBe('path');
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: FAIL.

- [ ] **Step 3: Edit `config/sidest.php` — append after the booking block**

```php
        // --- Education platforms — path mode (default_category: education) ---
        'stan' => [
            'display_name' => 'Stan',
            'icon_key' => 'stan',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{2,40}$/',
            'url_template' => 'https://stan.store/{handle}',
            'host_allowlist' => ['stan.store', 'www.stan.store'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{2,40})/?$#',
            'default_category' => 'education',
            'handle_location' => 'path',
        ],
        'skool' => [
            'display_name' => 'Skool',
            'icon_key' => 'skool',
            'placeholder' => 'community-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,60}$/',
            'url_template' => 'https://skool.com/{handle}',
            'host_allowlist' => ['skool.com', 'www.skool.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{3,60})/?$#',
            'default_category' => 'education',
            'handle_location' => 'path',
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Unit/Config/LinkCategoriesConfigTest.php
git commit -m "feat(links): register stan and skool as education platforms"
```

---

## Task 6: Add 2 subdomain-mode education platforms (Kajabi, Circle)

**Files:**
- Modify: `config/sidest.php` — append to `social_platforms`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Config/LinkCategoriesConfigTest.php`:

```php
it('registers kajabi and circle as education subdomain-mode platforms', function () {
    foreach (['kajabi' => 'mykajabi.com', 'circle' => 'circle.so'] as $key => $base) {
        $config = config("sidest.social_platforms.{$key}");
        expect($config)->not->toBeNull();
        expect($config['default_category'])->toBe('education');
        expect($config['handle_location'])->toBe('subdomain');
        // In subdomain mode, host_allowlist[0] is the base domain
        expect($config['host_allowlist'][0])->toBe($base);
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: FAIL.

- [ ] **Step 3: Edit `config/sidest.php` — append subdomain-mode education**

```php
        // --- Education platforms — subdomain mode (default_category: education) ---
        // Handle lives in the subdomain: {handle}.mykajabi.com / {handle}.circle.so
        // host_allowlist[0] = base domain; labelled-suffix match in normalizer.
        'kajabi' => [
            'display_name' => 'Kajabi',
            'icon_key' => 'kajabi',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.mykajabi.com/',
            'host_allowlist' => ['mykajabi.com'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'education',
            'handle_location' => 'subdomain',
        ],
        'circle' => [
            'display_name' => 'Circle',
            'icon_key' => 'circle',
            'placeholder' => 'community-name',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.circle.so/',
            'host_allowlist' => ['circle.so'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'education',
            'handle_location' => 'subdomain',
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Unit/Config/LinkCategoriesConfigTest.php
git commit -m "feat(links): register kajabi and circle as subdomain-mode education platforms"
```

---

## Task 7: Add 4 event platforms (path mode)

**Files:**
- Modify: `config/sidest.php` — append to `social_platforms`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Config/LinkCategoriesConfigTest.php`:

```php
it('registers the 4 event platforms with default_category=events', function () {
    foreach (['eventbrite', 'humanitix', 'luma', 'partiful'] as $key) {
        $config = config("sidest.social_platforms.{$key}");
        expect($config)->not->toBeNull();
        expect($config['default_category'])->toBe('events');
        expect($config['handle_location'])->toBe('path');
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: FAIL.

- [ ] **Step 3: Edit `config/sidest.php` — append events block**

```php
        // --- Event platforms (default_category: events) ---
        // Most event URLs are event-specific (/e/abc-123), not profile URLs.
        // The url_path_extractor targets the "organizer profile" shape; deep
        // links fall through to the lenient URL fallback — see docs/social-links.md §5.2.
        'eventbrite' => [
            'display_name' => 'Eventbrite',
            'icon_key' => 'eventbrite',
            'placeholder' => 'organizer-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://eventbrite.com/o/{handle}',
            'host_allowlist' => ['eventbrite.com', 'www.eventbrite.com'],
            'url_path_extractor' => '#^/o/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'humanitix' => [
            'display_name' => 'Humanitix',
            'icon_key' => 'humanitix',
            'placeholder' => 'organizer-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://humanitix.com/host/{handle}',
            'host_allowlist' => ['humanitix.com', 'www.humanitix.com', 'events.humanitix.com'],
            'url_path_extractor' => '#^/host/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'luma' => [
            'display_name' => 'Luma',
            'icon_key' => 'luma',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{2,40}$/',
            'url_template' => 'https://lu.ma/{handle}',
            'host_allowlist' => ['lu.ma', 'www.lu.ma'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{2,40})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'partiful' => [
            'display_name' => 'Partiful',
            'icon_key' => 'partiful',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,40}$/',
            'url_template' => 'https://partiful.com/u/{handle}',
            'host_allowlist' => ['partiful.com', 'www.partiful.com'],
            'url_path_extractor' => '#^/u/([a-zA-Z0-9-]{3,40})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Unit/Config/LinkCategoriesConfigTest.php
git commit -m "feat(links): register 4 event platforms (eventbrite, humanitix, luma, partiful)"
```

---

## Task 8: Add Apple Podcasts (path mode, content)

**Files:**
- Modify: `config/sidest.php` — append to `social_platforms`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Config/LinkCategoriesConfigTest.php`:

```php
it('registers apple_podcasts as a content path-mode platform', function () {
    $config = config('sidest.social_platforms.apple_podcasts');
    expect($config)->not->toBeNull();
    expect($config['default_category'])->toBe('content');
    expect($config['handle_location'])->toBe('path');
    expect($config['host_allowlist'])->toContain('podcasts.apple.com');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: FAIL.

- [ ] **Step 3: Edit `config/sidest.php` — append**

```php
        // --- Content platforms — path mode (default_category: content) ---
        // Apple Podcasts URLs always have the numeric ID as the stable identifier:
        //   https://podcasts.apple.com/us/podcast/{slug}/id{numeric-id}
        // The extractor captures the numeric id; most users will paste the full
        // URL, so the lenient fallback does most of the real work here.
        'apple_podcasts' => [
            'display_name' => 'Apple Podcasts',
            'icon_key' => 'apple_podcasts',
            'placeholder' => 'Paste the show URL',
            'handle_pattern' => '/^\d{5,15}$/',
            'url_template' => 'https://podcasts.apple.com/us/podcast/id{handle}',
            'host_allowlist' => ['podcasts.apple.com'],
            'url_path_extractor' => '#^/[a-z]{2}/podcast/[a-zA-Z0-9-]+/id(\d{5,15})/?$#',
            'default_category' => 'content',
            'handle_location' => 'path',
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php tests/Unit/Config/LinkCategoriesConfigTest.php
git commit -m "feat(links): register apple_podcasts as content platform"
```

---

## Task 9: Add 2 subdomain-mode content platforms (Substack, Bandcamp)

**Files:**
- Modify: `config/sidest.php` — append to `social_platforms`

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Config/LinkCategoriesConfigTest.php`:

```php
it('registers substack and bandcamp as content subdomain-mode platforms', function () {
    foreach (['substack' => 'substack.com', 'bandcamp' => 'bandcamp.com'] as $key => $base) {
        $config = config("sidest.social_platforms.{$key}");
        expect($config)->not->toBeNull();
        expect($config['default_category'])->toBe('content');
        expect($config['handle_location'])->toBe('subdomain');
        expect($config['host_allowlist'][0])->toBe($base);
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: FAIL.

- [ ] **Step 3: Edit `config/sidest.php` — append**

```php
        // --- Content platforms — subdomain mode (default_category: content) ---
        'substack' => [
            'display_name' => 'Substack',
            'icon_key' => 'substack',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.substack.com/',
            'host_allowlist' => ['substack.com'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'content',
            'handle_location' => 'subdomain',
        ],
        'bandcamp' => [
            'display_name' => 'Bandcamp',
            'icon_key' => 'bandcamp',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.bandcamp.com/',
            'host_allowlist' => ['bandcamp.com'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'content',
            'handle_location' => 'subdomain',
        ],
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/LinkCategoriesConfigTest.php`
Expected: PASS — all 16 new platforms plus the existing 8 are registered.

- [ ] **Step 5: Sanity check — existing normalizer tests still green**

Run: `./vendor/bin/pest tests/Feature/Site/SocialLinkNormalizerTest.php`
Expected: First test ("returns 8 platforms") FAILS — the count is now 24. Update it:

Open `tests/Feature/Site/SocialLinkNormalizerTest.php:12-19` and replace with:

```php
it('returns 24 platforms in the public registry', function () {
    $registry = normalizer()->getPublicRegistry();

    expect($registry)->toHaveCount(24);
});
```

(The old test hard-coded the 8 keys; we drop that check since the shape is verified per-platform elsewhere. The `strips internal validation fields` test below it still passes because it loops.)

Run tests again: `./vendor/bin/pest tests/Feature/Site/SocialLinkNormalizerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add config/sidest.php tests/Unit/Config/LinkCategoriesConfigTest.php tests/Feature/Site/SocialLinkNormalizerTest.php
git commit -m "feat(links): register substack and bandcamp as subdomain content platforms"
```

---

## Task 10: SocialLinkNormalizer — subdomain parser branch

**Files:**
- Modify: `app/Services/Site/SocialLinkNormalizer.php:72-188` (add subdomain-mode branches to `normalizeHandle()` and `normalizeUrl()`)

- [ ] **Step 1: Write the failing tests**

Add the following tests to `tests/Feature/Site/SocialLinkNormalizerTest.php` (at the end of the file):

```php
// --- Subdomain-mode normalization ---

it('normalizes a substack handle by subdomain', function () {
    $result = normalizer()->normalize('substack', 'joshhunter', null);

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
    expect($result['icon_key'])->toBe('substack');
    expect($result['platform_key'])->toBe('substack');
});

it('strips leading @ in subdomain-mode handle input', function () {
    $result = normalizer()->normalize('substack', '@joshhunter', null);

    expect($result['handle'])->toBe('joshhunter');
    expect($result['url'])->toBe('https://joshhunter.substack.com/');
});

it('extracts the handle from a substack root URL', function () {
    $result = normalizer()->normalize('substack', null, 'https://joshhunter.substack.com/');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
    expect($result['handle'])->toBe('joshhunter');
});

it('extracts the handle from a bandcamp root URL', function () {
    $result = normalizer()->normalize('bandcamp', null, 'https://somebands.bandcamp.com');

    expect($result['handle'])->toBe('somebands');
    expect($result['url'])->toBe('https://somebands.bandcamp.com/');
});

it('extracts the handle for kajabi (mykajabi.com base)', function () {
    $result = normalizer()->normalize('kajabi', null, 'https://acmecoach.mykajabi.com/');

    expect($result['handle'])->toBe('acmecoach');
    expect($result['url'])->toBe('https://acmecoach.mykajabi.com/');
});

it('falls back to lenient URL storage on subdomain deep-link', function () {
    $result = normalizer()->normalize('substack', null, 'https://joshhunter.substack.com/p/my-post');

    // Deep link, handle not extracted
    expect($result['handle'])->toBeNull();
    // URL is preserved, https forced
    expect($result['url'])->toBe('https://joshhunter.substack.com/p/my-post');
});

it('forces https on http subdomain input', function () {
    $result = normalizer()->normalize('substack', null, 'http://joshhunter.substack.com/');

    expect($result['url'])->toBe('https://joshhunter.substack.com/');
});

it('rejects a labelled-suffix attack: evilsubstack.com must not match substack', function () {
    expect(fn () => normalizer()->normalize('substack', null, 'https://evilsubstack.com/fake'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a wrong-host URL for a subdomain platform', function () {
    expect(fn () => normalizer()->normalize('substack', null, 'https://alice.medium.com/'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a subdomain handle with invalid characters', function () {
    expect(fn () => normalizer()->normalize('substack', 'josh.hunter', null))
        ->toThrow(InvalidArgumentException::class); // dots not allowed per pattern
});

it('rejects a subdomain handle that is too short', function () {
    expect(fn () => normalizer()->normalize('substack', 'ab', null))
        ->toThrow(InvalidArgumentException::class); // min 3 chars
});

it('rejects the bare base domain as handle-less (no subdomain present)', function () {
    expect(fn () => normalizer()->normalize('substack', null, 'https://substack.com/'))
        ->toThrow(InvalidArgumentException::class);
});
```

Also add one `use InvalidArgumentException;` to the top of the file if not already there — Pest test files using `toThrow(InvalidArgumentException::class)` need the class in scope. The existing tests further up in the file already use this pattern so the import is likely already there; verify before adding.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Site/SocialLinkNormalizerTest.php --filter='subdomain|labelled'`
Expected: FAIL on all 12 new tests — the normalizer has no subdomain branch.

- [ ] **Step 3: Implement the subdomain branch**

Edit `app/Services/Site/SocialLinkNormalizer.php`.

**Change 1:** Update the `normalize()` method (around lines 72-86) — no code change, but its private helpers need to branch. Replace `normalizeHandle()` (lines 119-139) with a dispatcher + two helpers:

Replace the existing `private function normalizeHandle(string $platformKey, array $config, string $handle): array` (lines 119-139) with:

```php
    /**
     * @return array{url: string, handle: string, icon_key: string, display_name: string, platform_key: string}
     */
    private function normalizeHandle(string $platformKey, array $config, string $handle): array
    {
        $cleaned = ltrim(trim($handle), '@');

        if (preg_match($config['handle_pattern'], $cleaned) !== 1) {
            throw new InvalidArgumentException(
                "Invalid {$config['display_name']} handle. Expected format: {$config['placeholder']}."
            );
        }

        // Both path-mode and subdomain-mode use {handle} substitution; the
        // url_template baked the mode-specific shape in at registry time.
        return [
            'url' => str_replace('{handle}', $cleaned, $config['url_template']),
            'handle' => $cleaned,
            'icon_key' => $config['icon_key'],
            'display_name' => $config['display_name'],
            'platform_key' => $platformKey,
        ];
    }
```

(No change to handle-path logic — `url_template` already encodes the handle's location.)

**Change 2:** Update `normalizeUrl()` (lines 141-188) to branch on `handle_location`. Replace the entire method with:

```php
    /**
     * @return array{url: string, handle: string|null, icon_key: string, display_name: string, platform_key: string}
     */
    private function normalizeUrl(string $platformKey, array $config, string $url): array
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || ! isset($parsed['host'])) {
            throw new InvalidArgumentException(
                "That doesn't look like a valid URL for {$config['display_name']}."
            );
        }

        $host = strtolower($parsed['host']);

        if (($config['handle_location'] ?? 'path') === 'subdomain') {
            return $this->normalizeSubdomainUrl($platformKey, $config, $host, $parsed);
        }

        // Path-mode (existing behaviour)
        if (! in_array($host, $config['host_allowlist'], true)) {
            throw new InvalidArgumentException(
                "That URL doesn't belong to {$config['display_name']}. Expected one of: ".implode(', ', $config['host_allowlist']).'.'
            );
        }

        // Try to extract a handle from the path. If we can, recurse into the
        // handle path so the stored URL is the clean canonical form (no query
        // params, no www, always https). If we can't (deep link, post URL),
        // keep the URL as-is — but we can't guarantee https/canonicalization
        // for arbitrary deep links, so we at least force the scheme to https.
        $path = $parsed['path'] ?? '/';
        if (preg_match($config['url_path_extractor'], $path, $matches) === 1) {
            return $this->normalizeHandle($platformKey, $config, $matches[1]);
        }

        // Lenient deep-link path: rebuild the URL with forced https + the original
        // path/query/fragment. No handle extracted.
        $rebuilt = 'https://'.$host.$path;
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $rebuilt .= '?'.$parsed['query'];
        }
        if (isset($parsed['fragment']) && $parsed['fragment'] !== '') {
            $rebuilt .= '#'.$parsed['fragment'];
        }

        return [
            'url' => $rebuilt,
            'handle' => null,
            'icon_key' => $config['icon_key'],
            'display_name' => $config['display_name'],
            'platform_key' => $platformKey,
        ];
    }

    /**
     * Subdomain-mode URL normalization.
     *
     * Host validation is a labelled-suffix check against the registry's base
     * domain (host_allowlist[0]). The leading dot is essential — without it,
     * `evilsubstack.com` would match `substack.com`. That is an open-phishing
     * vulnerability; see docs/social-links.md §8 and the spec's §8.2.
     *
     * If the host is the bare base (e.g. `substack.com` with no subdomain), we
     * reject — there's no handle to extract and no sensible canonical URL.
     *
     * If the leftmost label passes the handle_pattern AND the path is the bare
     * root (`/` or empty), recurse into normalizeHandle to get the clean URL.
     * Otherwise fall back to lenient storage: keep the URL, force https, no
     * handle extracted (e.g. `alice.substack.com/p/my-post`).
     *
     * @param  array{host?: string, path?: string, query?: string, fragment?: string}  $parsed
     * @return array{url: string, handle: string|null, icon_key: string, display_name: string, platform_key: string}
     */
    private function normalizeSubdomainUrl(string $platformKey, array $config, string $host, array $parsed): array
    {
        $base = $config['host_allowlist'][0];

        // Labelled-suffix match: host must be "X.base" for some non-empty X.
        // Bare base (`substack.com`) is rejected — no handle present.
        if ($host === $base || ! str_ends_with($host, '.'.$base)) {
            throw new InvalidArgumentException(
                "That URL doesn't belong to {$config['display_name']}. Expected a {$base} subdomain."
            );
        }

        // Extract the leftmost label as the candidate handle.
        // str_ends_with guaranteed the trailing ".{base}", so strlen math is safe.
        $subdomainPortion = substr($host, 0, strlen($host) - strlen($base) - 1);
        $labels = explode('.', $subdomainPortion);
        $candidate = $labels[0] ?? '';

        $path = $parsed['path'] ?? '/';
        $hasQuery = isset($parsed['query']) && $parsed['query'] !== '';
        $hasFragment = isset($parsed['fragment']) && $parsed['fragment'] !== '';

        // Root-URL fast path: candidate must match handle_pattern AND
        // path must be empty-or-slash-only AND no query/fragment.
        if (
            preg_match($config['handle_pattern'], $candidate) === 1 &&
            in_array($path, ['', '/'], true) &&
            ! $hasQuery &&
            ! $hasFragment
        ) {
            return $this->normalizeHandle($platformKey, $config, $candidate);
        }

        // Lenient deep-link path: preserve the URL but force https. Candidate
        // handle may be invalid (e.g. multi-label subdomain) or URL has extra
        // path/query — we still trust the host validation above and store as-is.
        $rebuilt = 'https://'.$host.$path;
        if ($hasQuery) {
            $rebuilt .= '?'.$parsed['query'];
        }
        if ($hasFragment) {
            $rebuilt .= '#'.$parsed['fragment'];
        }

        return [
            'url' => $rebuilt,
            'handle' => null,
            'icon_key' => $config['icon_key'],
            'display_name' => $config['display_name'],
            'platform_key' => $platformKey,
        ];
    }
```

**Change 3:** Update the `resolvePlatform` return-shape PHPDoc (line 191) to include the two new fields:

```php
    /**
     * @return array{display_name: string, icon_key: string, placeholder: string, handle_pattern: string, url_template: string, host_allowlist: array<int, string>, url_path_extractor: string, default_category: string, handle_location: string}
     */
    private function resolvePlatform(string $platformKey): array
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Site/SocialLinkNormalizerTest.php`
Expected: all tests PASS — existing 32 + new 12 = 44+ assertions.

**Important:** the `rejects the bare base domain` test expects the bare `https://substack.com/` to throw. The labelled-suffix check `! str_ends_with($host, '.'.$base)` is true when `host === $base`, but we also have `$host === $base` as the first disjunct — ensuring it throws. Verify manually: `substack.com` → ends with `.substack.com`? No. So the OR triggers → throw. Correct.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Site/SocialLinkNormalizer.php tests/Feature/Site/SocialLinkNormalizerTest.php
git commit -m "feat(links): add subdomain-mode parser branch with labelled-suffix host check"
```

---

## Task 11: SocialLinkNormalizer — include category in public registry

**Files:**
- Modify: `app/Services/Site/SocialLinkNormalizer.php:34-49` (`getPublicRegistry()`)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Site/SocialLinkNormalizerTest.php`:

```php
// --- Public registry: category exposure ---

it('exposes category in the public registry entries', function () {
    $registry = normalizer()->getPublicRegistry();

    foreach ($registry as $entry) {
        expect($entry)->toHaveKey('category');
        expect($entry['category'])->toBeIn(['social', 'booking', 'education', 'content', 'events', 'other']);
    }
});

it('maps instagram to category=social in the public registry', function () {
    $registry = normalizer()->getPublicRegistry();
    $instagram = collect($registry)->firstWhere('key', 'instagram');

    expect($instagram['category'])->toBe('social');
});

it('maps calendly to category=booking in the public registry', function () {
    $registry = normalizer()->getPublicRegistry();
    $calendly = collect($registry)->firstWhere('key', 'calendly');

    expect($calendly['category'])->toBe('booking');
});

it('still strips internal validation fields including handle_location', function () {
    $registry = normalizer()->getPublicRegistry();

    foreach ($registry as $entry) {
        expect($entry)->not->toHaveKey('handle_pattern');
        expect($entry)->not->toHaveKey('host_allowlist');
        expect($entry)->not->toHaveKey('url_path_extractor');
        expect($entry)->not->toHaveKey('url_template');
        expect($entry)->not->toHaveKey('handle_location');
        expect($entry)->not->toHaveKey('default_category'); // renamed to `category` in output
    }
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Site/SocialLinkNormalizerTest.php --filter='public registry'`
Expected: FAIL — `category` key missing.

- [ ] **Step 3: Update `getPublicRegistry()`**

In `app/Services/Site/SocialLinkNormalizer.php`, replace lines 34-49 with:

```php
    /**
     * Return the public-safe view of the registry — display name, icon key,
     * placeholder, and category only. Strips handle_pattern, host_allowlist,
     * url_path_extractor, url_template, and handle_location so internal
     * validation logic never reaches the wire.
     *
     * `default_category` in the stored registry is renamed to `category` in
     * the output — consumers don't need to know there's an override mechanism.
     *
     * @return array<int, array{key: string, display_name: string, icon_key: string, placeholder: string, category: string}>
     */
    public function getPublicRegistry(): array
    {
        $registry = config('sidest.social_platforms', []);
        $public = [];

        foreach ($registry as $key => $config) {
            $public[] = [
                'key' => $key,
                'display_name' => $config['display_name'],
                'icon_key' => $config['icon_key'],
                'placeholder' => $config['placeholder'],
                'category' => $config['default_category'],
            ];
        }

        return $public;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Site/SocialLinkNormalizerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Site/SocialLinkNormalizer.php tests/Feature/Site/SocialLinkNormalizerTest.php
git commit -m "feat(links): expose category in public platform registry response"
```

---

## Task 12: PublicConfigController — include `categories` array in response

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/PublicConfigController.php:35-40`

- [ ] **Step 1: Write the failing test**

Check whether a test file exists:

```bash
ls tests/Feature/PublicSite/PublicConfigSocialPlatformsTest.php
```

If yes, append to it. If no, create `tests/Feature/PublicSite/PublicConfigSocialPlatformsTest.php`:

```php
<?php

it('returns 24 platforms with category field each', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $response->assertOk();
    $response->assertJsonCount(24, 'platforms');

    $platforms = $response->json('platforms');
    foreach ($platforms as $p) {
        expect($p)->toHaveKeys(['key', 'display_name', 'icon_key', 'placeholder', 'category']);
    }
});

it('returns the canonical categories array alongside platforms', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $response->assertOk();
    $response->assertJson([
        'categories' => ['social', 'booking', 'education', 'content', 'events', 'other'],
    ]);
});

it('sends a 1-hour public cache header', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $response->assertHeader('Cache-Control', 'public, max-age=3600');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PublicSite/PublicConfigSocialPlatformsTest.php`
Expected: FAIL — `categories` key missing from response.

- [ ] **Step 3: Update `socialPlatforms()`**

Edit `app/Http/Controllers/Api/PublicSite/PublicConfigController.php:35-40`. Replace with:

```php
    /**
     * GET /api/public/config/social-platforms
     *
     * Returns the list of supported platforms with frontend-facing metadata
     * (display name, icon key, placeholder, category) plus the canonical
     * `categories` enum. Used by the affiliate dashboard to render the
     * platform picker grouped by category. See docs/social-links.md.
     */
    public function socialPlatforms(): JsonResponse
    {
        return response()
            ->json([
                'platforms' => $this->normalizer->getPublicRegistry(),
                'categories' => config('sidest.link_categories', []),
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PublicSite/PublicConfigSocialPlatformsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/PublicSite/PublicConfigController.php tests/Feature/PublicSite/PublicConfigSocialPlatformsTest.php
git commit -m "feat(links): include categories enum in public registry endpoint response"
```

---

## Task 13: StoreLinkBlockRequest — category validation

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php:65-125`

**Test pattern note:** this codebase has no Professional/Site/Block factories. Existing Form Request tests (`tests/Feature/Site/LinkBlockSocialValidationTest.php`) exercise the Form Request pipeline directly via `validateStoreRequest()` / `validateUpdateRequest()` helpers — no DB, no HTTP, no models. We follow the same pattern here. Tests that need actual persistence (controller → DB round-trip) move to Task 15 where the controller change lands.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Site/LinkBlockCategoryValidationTest.php` following the existing `LinkBlockSocialValidationTest.php` helper pattern:

```php
<?php

use App\Http\Requests\Api\Professional\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Category-rule coverage for StoreLinkBlockRequest and UpdateLinkBlockRequest.
 * Follows the direct-form-request pattern from LinkBlockSocialValidationTest —
 * no DB, no HTTP stack. The controller-level persistence tests live in
 * tests/Feature/Site/LinkBlockCategoryPersistenceTest.php (added in Task 15).
 */

function validateStoreRequestCategory(array $payload): array
{
    $request = Request::create('/api/test', 'POST', $payload);
    $formRequest = StoreLinkBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['ok' => true, 'data' => $formRequest->validated()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'errors' => $e->errors()];
    }
}

function validateUpdateRequestCategory(array $payload, ?string $blockId = null): array
{
    $request = Request::create('/api/test', 'PATCH', $payload);
    $request->setRouteResolver(function () use ($blockId) {
        $route = new Illuminate\Routing\Route(['PATCH'], '/api/test', []);
        $route->parameters = ['linkBlock' => $blockId ?? (string) Str::uuid()];

        return $route;
    });

    $formRequest = UpdateLinkBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['ok' => true, 'data' => $formRequest->validated()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'errors' => $e->errors()];
    }
}

// --- Custom mode: category required ---

it('rejects a custom link without category', function () {
    $result = validateStoreRequestCategory([
        'title' => 'My custom',
        'url' => 'https://example.com',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('category');
});

it('accepts a custom link with a valid category', function () {
    $result = validateStoreRequestCategory([
        'title' => 'My custom',
        'url' => 'https://example.com',
        'category' => 'other',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['category'])->toBe('other');
});

it('rejects an invalid category value', function () {
    $result = validateStoreRequestCategory([
        'title' => 'Bad',
        'url' => 'https://example.com',
        'category' => 'not-a-real-category',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('category');
});

// --- Social mode: category optional (override semantics handled in controller) ---

it('accepts a social link without category (platform default applies in controller)', function () {
    $result = validateStoreRequestCategory([
        'platform' => 'calendly',
        'handle' => 'joshhunter',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a social link with an explicit category override', function () {
    $result = validateStoreRequestCategory([
        'platform' => 'instagram',
        'handle' => 'joshhunter',
        'category' => 'events',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['category'])->toBe('events');
});

it('rejects a social link with an invalid override category', function () {
    $result = validateStoreRequestCategory([
        'platform' => 'instagram',
        'handle' => 'joshhunter',
        'category' => 'not-real',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('category');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Site/LinkBlockCategoryValidationTest.php`
Expected: FAIL — `category` not in rules, so enum validation doesn't trigger and "required for custom" check doesn't exist.

- [ ] **Step 3: Update `StoreLinkBlockRequest`**

Edit `app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php`:

**3a.** Add `category` to the `rules()` array (insert after `settings.note` at line 82):

```php
            'category' => ['sometimes', 'nullable', 'string', Rule::in(config('sidest.link_categories', []))],
```

**3b.** Add a "required when no platform" check inside `withValidator()`. Find the custom-mode block (around lines 100-110) — replace the inner `else` clause with:

```php
            } else {
                // Custom mode: title AND url both required (legacy contract preserved)
                if ($title === null || $title === '') {
                    $validator->errors()->add('title', 'The title field is required for custom links.');
                }
                if ($url === null || $url === '') {
                    $validator->errors()->add('url', 'The url field is required for custom links.');
                } elseif (! $this->isAllowedScheme($url)) {
                    $validator->errors()->add('url', 'Custom link URLs must use http or https.');
                }

                // Category is required for custom links; platform links fall back
                // to the registry's default_category when omitted.
                $category = $this->input('category');
                if ($category === null || $category === '') {
                    $validator->errors()->add('category', 'The category field is required for custom links.');
                }
            }
```

- [ ] **Step 4: Run tests to confirm all pass**

All 6 tests are validation-layer only (no controller/DB). They should all pass after the Form Request update.

Run: `./vendor/bin/pest tests/Feature/Site/LinkBlockCategoryValidationTest.php`
Expected: PASS — all 6 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php \
        tests/Feature/Site/LinkBlockCategoryValidationTest.php
git commit -m "feat(links): require category on StoreLinkBlockRequest with enum validation"
```

---

## Task 14: UpdateLinkBlockRequest — category validation (all-optional mirror)

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php:66-120`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Site/LinkBlockCategoryValidationTest.php`:

```php
// --- Update: category is all-optional but enum-checked when present ---

it('accepts an update with no category (partial update)', function () {
    $result = validateUpdateRequestCategory([
        'title' => 'New title only',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts an update with a valid category', function () {
    $result = validateUpdateRequestCategory([
        'category' => 'content',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['category'])->toBe('content');
});

it('rejects an update with an invalid category', function () {
    $result = validateUpdateRequestCategory([
        'category' => 'not-real',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('category');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Site/LinkBlockCategoryValidationTest.php --filter='update'`
Expected: FAIL — `category` not in the update rules.

- [ ] **Step 3: Update `UpdateLinkBlockRequest`**

Edit `app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php`.

Add `category` to `rules()` (insert after line 83 `settings.note`):

```php
            'category' => ['sometimes', 'nullable', 'string', Rule::in(config('sidest.link_categories', []))],
```

No change needed in `withValidator()` — update is partial, so nullable `category` is fine; the controller will apply override semantics.

- [ ] **Step 4: Run tests to confirm all pass**

Run: `./vendor/bin/pest tests/Feature/Site/LinkBlockCategoryValidationTest.php`
Expected: PASS — all 9 tests green (6 from Task 13 + 3 update tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php \
        tests/Feature/Site/LinkBlockCategoryValidationTest.php
git commit -m "feat(links): accept category on UpdateLinkBlockRequest with enum validation"
```

---

## Task 15: ProfessionalLinkBlockController — resolve category in `buildBlockFields`

**Files:**
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalLinkBlockController.php:150-203` (`buildBlockFields`)
- Modify: `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalLinkBlockController.php:104-148` (`update` — partial category updates)

- [ ] **Step 1: Update `buildBlockFields()` to resolve + write `settings.category`**

Edit `app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalLinkBlockController.php`. Replace the entire `buildBlockFields` method (lines 150-203) with:

```php
    /**
     * Translate a validated request payload into the Block column values to
     * persist. Handles the social/custom mode split centrally so store() and
     * update() share one source of truth.
     *
     * Social mode produces:
     *   - url       = canonical https URL from the normalizer
     *   - icon_key  = registry's icon_key for the platform
     *   - title     = user-supplied OR the platform's display_name
     *   - settings  = user settings + {platform, handle, category} soft tags
     *
     * Custom mode produces:
     *   - url       = as supplied
     *   - icon_key  = as supplied
     *   - title     = as supplied
     *   - settings  = user settings + {category} (required in request)
     *
     * Category resolution order:
     *   1. Request-provided `category` wins (validated against the enum in the Form Request).
     *   2. Else fall back to the platform's default_category (platform-link case).
     *   3. Else a 422-level guard (validation layer should have caught a missing category on custom links).
     *
     * @param  array<string, mixed>  $data  Validated request payload
     * @return array<string, mixed> Block fillable fields
     *
     * @throws InvalidArgumentException When social-mode normalization fails (caller maps to 422)
     */
    private function buildBlockFields(array $data): array
    {
        $platform = $data['platform'] ?? null;
        $requestedCategory = $data['category'] ?? null;

        if ($platform !== null && $platform !== '') {
            $normalized = $this->normalizer->normalize(
                $platform,
                $data['handle'] ?? null,
                $data['url'] ?? null
            );

            // Tag settings.platform + settings.handle so the frontend can
            // re-render the edit form in social mode and so analytics can
            // group by platform later (slow but works without a column).
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $settings['platform'] = $normalized['platform_key'];
            if ($normalized['handle'] !== null) {
                $settings['handle'] = $normalized['handle'];
            }

            // Category: explicit override wins, else platform default.
            $registry = config("sidest.social_platforms.{$normalized['platform_key']}", []);
            $settings['category'] = $requestedCategory ?: ($registry['default_category'] ?? 'other');

            return [
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : $normalized['display_name'],
                'url' => $normalized['url'],
                'icon_key' => $normalized['icon_key'],
                'settings' => $settings,
            ];
        }

        // Custom mode: category is required by the Form Request. Defensive
        // default here in case a future code path calls buildBlockFields
        // directly with incomplete data.
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        if ($requestedCategory === null || $requestedCategory === '') {
            throw new InvalidArgumentException('A category is required for custom links.');
        }
        $settings['category'] = $requestedCategory;

        return [
            'title' => $data['title'] ?? null,
            'url' => $data['url'] ?? null,
            'icon_key' => $data['icon_key'] ?? null,
            'settings' => $settings,
        ];
    }
```

- [ ] **Step 2: Handle partial category updates in `update()`**

The current `update()` method (lines 104-148) has two branches — "platform provided (social-mode refresh)" and "no platform (pass-through partial update)". The pass-through branch needs to merge `settings.category` when the client sends only `{ "category": "content" }`. Edit lines 139-143:

Replace:

```php
        } else {
            // Strip the social-mode-only keys before fill — they're not Block columns.
            unset($data['platform'], $data['handle']);
            $linkBlock->fill($data);
        }
```

With:

```php
        } else {
            // Strip the social-mode-only keys before fill — they're not Block columns.
            unset($data['platform'], $data['handle']);

            // Category lives in settings JSONB, not as a column. If the client
            // supplied a new category in isolation, merge it into existing settings.
            if (array_key_exists('category', $data)) {
                $existingSettings = is_array($linkBlock->settings) ? $linkBlock->settings : [];
                $existingSettings['category'] = $data['category'];
                $data['settings'] = array_merge($existingSettings, $data['settings'] ?? []);
                unset($data['category']);
            }

            $linkBlock->fill($data);
        }
```

Note: the social-mode branch (platform provided) already routes through `buildBlockFields()` which now writes `settings.category`, so it gets it for free.

- [ ] **Step 3: Write unit tests for `buildBlockFields` (via reflection helper)**

`buildBlockFields` is private. Rather than route through a full HTTP test (which would need factories), test via a tiny reflection helper. Create `tests/Unit/Controllers/BuildBlockFieldsCategoryTest.php`:

```php
<?php

use App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement\ProfessionalLinkBlockController;
use App\Services\Site\SocialLinkNormalizer;

function invokeBuildBlockFields(array $data): array
{
    $controller = new ProfessionalLinkBlockController(new SocialLinkNormalizer);
    $method = (new ReflectionClass($controller))->getMethod('buildBlockFields');
    $method->setAccessible(true);

    return $method->invoke($controller, $data);
}

it('writes settings.category=other for a custom link with explicit category', function () {
    $fields = invokeBuildBlockFields([
        'title' => 'My link',
        'url' => 'https://example.com',
        'icon_key' => 'link',
        'category' => 'other',
    ]);

    expect($fields['settings']['category'])->toBe('other');
});

it('writes settings.category=booking from platform default (calendly)', function () {
    $fields = invokeBuildBlockFields([
        'platform' => 'calendly',
        'handle' => 'joshhunter',
    ]);

    expect($fields['settings']['category'])->toBe('booking');
    expect($fields['settings']['platform'])->toBe('calendly');
});

it('respects an explicit category override on a platform link', function () {
    $fields = invokeBuildBlockFields([
        'platform' => 'instagram',
        'handle' => 'joshhunter',
        'category' => 'events',
    ]);

    expect($fields['settings']['category'])->toBe('events');
    expect($fields['settings']['platform'])->toBe('instagram');
});

it('throws when a custom link omits category (defensive guard)', function () {
    expect(fn () => invokeBuildBlockFields([
        'title' => 'My link',
        'url' => 'https://example.com',
    ]))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 4: Run all tests to confirm pass**

Run: `./vendor/bin/pest tests/Unit/Controllers/BuildBlockFieldsCategoryTest.php tests/Feature/Site/`
Expected: PASS — all category tests + existing Site feature tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalLinkBlockController.php \
        tests/Unit/Controllers/BuildBlockFieldsCategoryTest.php
git commit -m "feat(links): resolve category in buildBlockFields with platform-default override"
```

---

## Task 16: BackfillSocialLinksCommand — extend to write `settings.category`

**Files:**
- Modify: `app/Console/Commands/BackfillSocialLinksCommand.php`

- [ ] **Step 1: Write the failing test**

No Professional/Site factories exist in this codebase. We use direct `DB::table(...)->insert(...)` to create minimal parent rows, then `Block::create(...)` for the blocks. The backfill command queries `site.blocks` directly, so we only need FK-valid parent rows — no Professional/Site model usage at test level.

**Before writing the test:** inspect `app/Models/Core/Professional/Professional.php` and `app/Models/Core/Site/Site.php` for required columns. The fixture helper in the test must supply all NOT NULL fields. If the schema has many required columns on Professional, use `DB::table('core.professionals')->insert([...])` with a minimal valid row shape (copy the columns from the baseline migration `supabase/migrations/20260403000000_v2_baseline.sql` where `core.professionals` is defined). If this setup becomes onerous, **STOP and flag** to the controller — we may need to add a small Professional/Site factory instead.

Create `tests/Feature/Console/BackfillLinkCategoriesTest.php`:

```php
<?php

use App\Models\Core\Site\Block;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill command tests. Uses direct DB inserts to avoid a factory dependency
 * for Professional/Site — the backfill only cares about site.blocks rows; the
 * parent rows exist solely to satisfy FK constraints.
 */

function createBackfillFixtureIds(): array
{
    $professionalId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    // Minimal Professional row — inspect the schema in
    // supabase/migrations/20260403000000_v2_baseline.sql for required columns
    // and adapt this insert if additional NOT NULL columns exist.
    DB::table('core.professionals')->insert([
        'id' => $professionalId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $professionalId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$professionalId, $siteId];
}

it('backfills settings.category=social for pre-existing instagram links', function () {
    [$proId, $siteId] = createBackfillFixtureIds();

    $block = Block::create([
        'professional_id' => $proId,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'My IG',
        'url' => 'https://instagram.com/someone',
        'icon_key' => 'instagram',
        'sort_order' => 0,
        'is_active' => true,
        'settings' => [],
    ]);

    Artisan::call('sidest:backfill-social-links');

    $block->refresh();
    expect($block->settings['platform'] ?? null)->toBe('instagram');
    expect($block->settings['category'] ?? null)->toBe('social');
});

it('backfills settings.category=other for custom (icon_key=link) blocks', function () {
    [$proId, $siteId] = createBackfillFixtureIds();

    $block = Block::create([
        'professional_id' => $proId,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'My custom',
        'url' => 'https://example.com',
        'icon_key' => 'link',
        'sort_order' => 0,
        'is_active' => true,
        'settings' => [],
    ]);

    Artisan::call('sidest:backfill-social-links');

    $block->refresh();
    expect($block->settings['category'] ?? null)->toBe('other');
});

it('is idempotent — existing category is preserved on re-run', function () {
    [$proId, $siteId] = createBackfillFixtureIds();

    $block = Block::create([
        'professional_id' => $proId,
        'site_id' => $siteId,
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'Already set',
        'url' => 'https://instagram.com/someone',
        'icon_key' => 'instagram',
        'sort_order' => 0,
        'is_active' => true,
        'settings' => ['platform' => 'instagram', 'category' => 'events'], // manually overridden
    ]);

    Artisan::call('sidest:backfill-social-links');

    $block->refresh();
    expect($block->settings['category'] ?? null)->toBe('events'); // preserved
});
```

**Schema-inspection note for the implementer:** before running, `Read` the v2 baseline at `supabase/migrations/20260403000000_v2_baseline.sql` for the `core.professionals` and `site.sites` CREATE TABLE statements. If either has NOT NULL columns beyond `id` and the timestamp columns above, extend `createBackfillFixtureIds()` accordingly. Typical missing fields: `professional_type`, `email`, `supabase_auth_user_id` on professionals; `subdomain` on sites. Add just enough to make the insert succeed.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Console/BackfillLinkCategoriesTest.php`
Expected: FAIL — backfill doesn't write `settings.category` yet.

- [ ] **Step 3: Extend the backfill command**

Edit `app/Console/Commands/BackfillSocialLinksCommand.php`. The command currently only processes rows where `icon_key` is in the social-icon list (line 76-77). We need two changes:

**3a.** Widen the query: process ALL link blocks, not just those with a social `icon_key`. Custom links need `category=other`.

**3b.** Add category-resolution to the per-row loop.

Replace the entire `handle()` method body (lines 46-158) with:

```php
    public function handle(SocialLinkNormalizer $normalizer): int
    {
        $registry = config('sidest.social_platforms', []);
        $iconToPlatform = $this->buildIconToPlatformMap($registry);

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // Audit log on start — gives us a record of who ran the backfill and when.
        Log::info('Backfill link blocks started', [
            'operator' => get_current_user() ?: 'unknown',
            'dry_run' => $dryRun,
            'limit' => $limit,
        ]);

        $stats = [
            'total' => 0,
            'already_tagged' => 0,
            'tagged_with_handle' => 0,
            'tagged_url_only' => 0,
            'category_only' => 0,
            'url_normalized' => 0,
            'unmatched_host' => 0,
            'errors' => 0,
        ];

        // Process ALL link blocks. Rows with a social icon_key get platform+category
        // resolution; rows without get category='other' only.
        $query = Block::query()
            ->where('block_group', 'links')
            ->where('block_type', 'link')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(200, function ($blocks) use (&$stats, $iconToPlatform, $registry, $normalizer, $dryRun) {
            DB::transaction(function () use ($blocks, &$stats, $iconToPlatform, $registry, $normalizer, $dryRun) {
                foreach ($blocks as $block) {
                    $stats['total']++;

                    $settings = is_array($block->settings) ? $block->settings : [];
                    $hasCategory = isset($settings['category']);
                    $hasPlatform = isset($settings['platform']);

                    // Fully-tagged rows: skip (idempotent).
                    if ($hasCategory && $hasPlatform) {
                        $stats['already_tagged']++;

                        continue;
                    }

                    // Category-only path: row has platform tagged but no category,
                    // OR row has no platform and no category. Backfill category from
                    // the platform's default, falling back to 'other'.
                    $platformKey = $hasPlatform
                        ? $settings['platform']
                        : ($iconToPlatform[$block->icon_key] ?? null);

                    // Legacy social-icon path (no platform tag yet, URL needs normalization)
                    if (! $hasPlatform && $platformKey !== null && $block->url !== null) {
                        try {
                            $normalized = $normalizer->normalize($platformKey, null, $block->url);
                        } catch (InvalidArgumentException $e) {
                            $stats['unmatched_host']++;
                            $this->warn(sprintf('  Skipping block %s (%s): host mismatch', $block->id, $platformKey));
                            Log::warning('Backfill: host mismatch', [
                                'block_id' => (string) $block->id,
                                'platform' => $platformKey,
                            ]);

                            continue;
                        }

                        $settings['platform'] = $platformKey;
                        if ($normalized['handle'] !== null) {
                            $settings['handle'] = $normalized['handle'];
                            $stats['tagged_with_handle']++;
                        } else {
                            $stats['tagged_url_only']++;
                        }

                        if ($normalized['url'] !== $block->url) {
                            $stats['url_normalized']++;
                            if (! $dryRun) {
                                $block->url = $normalized['url'];
                            }
                        }
                    }

                    // Resolve category: platform default, or 'other' for custom/unknown rows.
                    if (! $hasCategory) {
                        $resolvedCategory = $platformKey !== null
                            ? ($registry[$platformKey]['default_category'] ?? 'other')
                            : 'other';
                        $settings['category'] = $resolvedCategory;
                        $stats['category_only']++;
                    }

                    if (! $dryRun) {
                        $block->settings = $settings;
                        $block->save();
                    }
                }
            });
        });

        $this->newLine();
        $this->info($dryRun ? 'DRY RUN — no changes written.' : 'Backfill complete.');
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn ($v, $k) => [$k, $v])->all()
        );

        return self::SUCCESS;
    }
```

Also update the `$description` property and the class-level docblock to reflect the widened scope:

```php
    protected $description = 'Backfill link blocks with settings.platform, settings.handle (when derivable), and settings.category. Idempotent — safe to re-run.';
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Console/BackfillLinkCategoriesTest.php`
Expected: PASS — all 3 tests green.

Also re-run any existing backfill tests to confirm no regression:

```bash
./vendor/bin/pest tests/Feature/Console/ tests/Unit/Console/
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/BackfillSocialLinksCommand.php tests/Feature/Console/BackfillLinkCategoriesTest.php
git commit -m "feat(links): extend backfill command to populate settings.category for all link blocks"
```

---

## Task 17: Happy-path tests for each new path-mode platform

**Files:**
- Modify: `tests/Feature/Site/SocialLinkNormalizerTest.php` (append)

- [ ] **Step 1: Append the test block**

Append to `tests/Feature/Site/SocialLinkNormalizerTest.php`:

```php
// --- New path-mode platforms: happy-path smoke tests ---
// One case each: clean handle → canonical URL.

it('normalizes a calendly handle', function () {
    $r = normalizer()->normalize('calendly', 'joshhunter', null);
    expect($r['url'])->toBe('https://calendly.com/joshhunter');
    expect($r['handle'])->toBe('joshhunter');
});

it('normalizes a fresha business slug', function () {
    $r = normalizer()->normalize('fresha', 'acme-hair', null);
    expect($r['url'])->toBe('https://fresha.com/a/acme-hair');
});

it('normalizes a booksy business slug', function () {
    $r = normalizer()->normalize('booksy', '12345_acme-salon', null);
    expect($r['url'])->toBe('https://booksy.com/en-us/12345_acme-salon');
});

it('normalizes a timely business slug', function () {
    $r = normalizer()->normalize('timely', 'acme-hair', null);
    expect($r['url'])->toBe('https://book.gettimely.com/book/acme-hair');
});

it('normalizes a square business slug', function () {
    $r = normalizer()->normalize('square', 'acme-hair', null);
    expect($r['url'])->toBe('https://book.squareup.com/appointments/acme-hair');
});

it('normalizes a stan handle', function () {
    $r = normalizer()->normalize('stan', 'joshhunter', null);
    expect($r['url'])->toBe('https://stan.store/joshhunter');
});

it('normalizes a skool community slug', function () {
    $r = normalizer()->normalize('skool', 'my-community', null);
    expect($r['url'])->toBe('https://skool.com/my-community');
});

it('normalizes an eventbrite organizer slug', function () {
    $r = normalizer()->normalize('eventbrite', 'acme-events', null);
    expect($r['url'])->toBe('https://eventbrite.com/o/acme-events');
});

it('normalizes a humanitix organizer slug', function () {
    $r = normalizer()->normalize('humanitix', 'acme-events', null);
    expect($r['url'])->toBe('https://humanitix.com/host/acme-events');
});

it('normalizes a luma handle', function () {
    $r = normalizer()->normalize('luma', 'joshhunter', null);
    expect($r['url'])->toBe('https://lu.ma/joshhunter');
});

it('normalizes a partiful handle', function () {
    $r = normalizer()->normalize('partiful', 'joshhunter', null);
    expect($r['url'])->toBe('https://partiful.com/u/joshhunter');
});

// Apple Podcasts uses the numeric ID as the handle
it('normalizes an apple_podcasts numeric id', function () {
    $r = normalizer()->normalize('apple_podcasts', '1234567890', null);
    expect($r['url'])->toBe('https://podcasts.apple.com/us/podcast/id1234567890');
});
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Site/SocialLinkNormalizerTest.php`
Expected: PASS — all new happy-path tests green.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Site/SocialLinkNormalizerTest.php
git commit -m "test(links): happy-path normalization for 12 new path-mode platforms"
```

---

## Task 18: URL-based extraction + wrong-host rejection for new path platforms

**Files:**
- Modify: `tests/Feature/Site/SocialLinkNormalizerTest.php` (append)

- [ ] **Step 1: Append tests**

Append to `tests/Feature/Site/SocialLinkNormalizerTest.php`:

```php
// --- New path-mode platforms: URL extraction + wrong-host rejection ---

it('extracts a handle from a calendly URL', function () {
    $r = normalizer()->normalize('calendly', null, 'https://calendly.com/joshhunter');
    expect($r['handle'])->toBe('joshhunter');
    expect($r['url'])->toBe('https://calendly.com/joshhunter');
});

it('accepts a calendly deep-link (event URL) via lenient fallback', function () {
    $r = normalizer()->normalize('calendly', null, 'https://calendly.com/joshhunter/30min');
    expect($r['handle'])->toBeNull();
    expect($r['url'])->toBe('https://calendly.com/joshhunter/30min');
});

it('rejects a calendly URL with a wrong host', function () {
    expect(fn () => normalizer()->normalize('calendly', null, 'https://calendl-y.com/joshhunter'))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts an eventbrite event URL via lenient fallback', function () {
    $r = normalizer()->normalize('eventbrite', null, 'https://eventbrite.com/e/some-event-123456789');
    expect($r['handle'])->toBeNull();
    expect($r['url'])->toBe('https://eventbrite.com/e/some-event-123456789');
});

it('extracts an apple_podcasts id from a full URL', function () {
    $r = normalizer()->normalize('apple_podcasts', null, 'https://podcasts.apple.com/us/podcast/my-show/id1234567890');
    expect($r['handle'])->toBe('1234567890');
    expect($r['url'])->toBe('https://podcasts.apple.com/us/podcast/id1234567890');
});

it('forces https on an http calendly URL', function () {
    $r = normalizer()->normalize('calendly', null, 'http://calendly.com/joshhunter');
    expect($r['url'])->toBe('https://calendly.com/joshhunter');
});

it('rejects an invalid eventbrite handle with special characters', function () {
    expect(fn () => normalizer()->normalize('eventbrite', 'has spaces', null))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Feature/Site/SocialLinkNormalizerTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Site/SocialLinkNormalizerTest.php
git commit -m "test(links): URL extraction and host rejection for new path-mode platforms"
```

---

## Task 19: Full test suite + backfill dry-run verification

**Files:** none edited — verification only.

- [ ] **Step 1: Run the full Pest suite**

Run: `composer test`
Expected: PASS — no failures. If anything fails, it's likely a test that hard-codes "8 platforms" — fix the count and re-run.

- [ ] **Step 2: Run the backfill in dry-run to verify logic**

```bash
php artisan sidest:backfill-social-links --dry-run
```

Expected: a stats table that shows at least one of `already_tagged`, `tagged_with_handle`, `tagged_url_only`, or `category_only` > 0 (if there's dev data) or all zero (on a fresh DB). No errors. `errors` column should be 0.

- [ ] **Step 3: Run the backfill for real (dev env only)**

```bash
php artisan sidest:backfill-social-links
```

Verify: no errors. Repeat — second run should show `already_tagged = total` (idempotency check).

- [ ] **Step 4: Composer guards pass**

Run: `composer guard:no-laravel-migrations`
Expected: PASS — no Laravel-style migrations added (we only changed config + PHP code).

- [ ] **Step 5: Pint passes**

Run: `php artisan pint --test`
Expected: PASS or auto-fixable. If anything needs fixing, run `php artisan pint` and commit.

- [ ] **Step 6: Commit any pint fixes (if needed)**

```bash
git add -A
git commit -m "chore(links): pint style fixes"
```

---

## Task 20: Update `docs/social-links.md`

**Files:**
- Modify: `docs/social-links.md`

- [ ] **Step 1: Update §2 "The 8 supported platforms" → "The 24 supported platforms"**

Find the section header at line ~23 in `docs/social-links.md` and replace with a section that references the 24 platforms. Structure:

```markdown
## 2. The 24 supported platforms

Grouped by `default_category`. See `config('sidest.social_platforms')` for the full per-platform config.

### 2.1 Social (8)

Instagram, Facebook, LinkedIn, YouTube, TikTok, X, Spotify, SoundCloud — all path-mode. See the original §2 table below (preserved) for handle/URL details.

### 2.2 Booking (5) — path mode

| Key | Display name | Example URL |
|-----|--------------|-------------|
| fresha | Fresha | https://fresha.com/a/{slug} |
| booksy | Booksy | https://booksy.com/en-us/{slug} |
| timely | Timely | https://book.gettimely.com/book/{slug} |
| calendly | Calendly | https://calendly.com/{handle} |
| square | Square | https://book.squareup.com/appointments/{slug} |

### 2.3 Education (4)

Path mode: stan, skool. Subdomain mode: kajabi, circle.

| Key | Display name | Example URL | Mode |
|-----|--------------|-------------|------|
| stan | Stan | https://stan.store/{handle} | path |
| skool | Skool | https://skool.com/{slug} | path |
| kajabi | Kajabi | https://{handle}.mykajabi.com | subdomain |
| circle | Circle | https://{handle}.circle.so | subdomain |

### 2.4 Events (4) — path mode

| Key | Display name | Example URL |
|-----|--------------|-------------|
| eventbrite | Eventbrite | https://eventbrite.com/o/{slug} |
| humanitix | Humanitix | https://humanitix.com/host/{slug} |
| luma | Luma | https://lu.ma/{handle} |
| partiful | Partiful | https://partiful.com/u/{handle} |

### 2.5 Content (3)

Path mode: apple_podcasts. Subdomain mode: substack, bandcamp.

| Key | Display name | Example URL | Mode |
|-----|--------------|-------------|------|
| apple_podcasts | Apple Podcasts | https://podcasts.apple.com/us/podcast/id{numeric-id} | path |
| substack | Substack | https://{handle}.substack.com/ | subdomain |
| bandcamp | Bandcamp | https://{handle}.bandcamp.com/ | subdomain |
```

Keep the existing detailed §2 table below this as the original per-platform reference — don't delete it, just title the existing table "### 2.6 Legacy social-only reference table" or similar.

- [ ] **Step 2: Update §3 "Adding a 9th platform" to cover the new fields**

Find the step-by-step guide at ~line 40 of `docs/social-links.md`. Update the registry-entry requirements list to include:

```markdown
- `default_category` (one of `config('sidest.link_categories')`)
- `handle_location` (`'path'` or `'subdomain'`)
```

And add a new paragraph describing subdomain mode:

```markdown
### Subdomain-mode platforms

If the platform assigns each user their own subdomain (e.g. `alice.substack.com`), set `handle_location: 'subdomain'`. The `host_allowlist` stores only the base domain (`['substack.com']`) — the normalizer applies a labelled-suffix check (`.substack.com`) to validate the host. The `url_template` uses `{handle}` in the subdomain position: `https://{handle}.substack.com/`. The `url_path_extractor` is unused in subdomain mode; set it to `'#^/?$#'` for consistency.

**Security:** the leading dot in the labelled-suffix check is critical — without it, `evilsubstack.com` would match `substack.com`. See §8.2.
```

- [ ] **Step 3: Add a §3.5 for categories**

After the current §3 "Adding a 9th platform" section, add:

```markdown
## 3.5 Link categories

Every link block has a required `category` stored in `settings.category`. The six valid values live in `config('sidest.link_categories')`:

`social`, `booking`, `education`, `content`, `events`, `other`

**Resolution:**
- Request-provided `category` wins (must pass the enum).
- Else for platform-tagged links, the platform's `default_category` is used.
- Else for custom links, the request must include `category` (422 otherwise).

**Storage:** `settings.category` JSONB — zero schema change. Promotion to a real column follows the additive-migration path in §9; same trigger conditions as `settings.platform`.

The public registry endpoint (`GET /api/public/config/social-platforms`) returns the platform-to-category mapping plus a `categories` array so the frontend can build a picker without hardcoding the enum.
```

- [ ] **Step 4: Update §8 "Security considerations" to mention subdomain suffix check**

Append to §8 (or insert as §8.4):

```markdown
### 8.4 Subdomain-mode labelled-suffix check

Subdomain-mode platforms (Substack, Bandcamp, Kajabi, Circle) validate the host with `$host === $base || str_ends_with($host, '.' . $base)`. The leading dot is critical: without it, `evilsubstack.com` would match `substack.com` and become an open-phishing vulnerability. The bare base domain (`substack.com` with no subdomain) is also rejected — there's no handle to extract.
```

(Renumber subsequent subsections if needed.)

- [ ] **Step 5: Verify docs render**

Manually inspect the markdown file — tables render, links resolve, headings nest correctly.

- [ ] **Step 6: Commit**

```bash
git add docs/social-links.md
git commit -m "docs(links): update social-links.md for categories and 16 new platforms"
```

---

## Task 21: Final verification pass

**Files:** none edited — verification only.

- [ ] **Step 1: Full test suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 2: Pint**

Run: `php artisan pint --test`
Expected: PASS.

- [ ] **Step 3: Check composer guards**

Run: `composer run guard:no-laravel-migrations`
Expected: PASS.

- [ ] **Step 4: Smoke-test the public endpoint locally**

In one terminal: `composer dev`. In another:

```bash
curl -s http://localhost:8000/api/public/config/social-platforms | jq '.categories, (.platforms | length), (.platforms[] | select(.key=="calendly"))'
```

Expected output:
```json
["social","booking","education","content","events","other"]
24
{"key":"calendly","display_name":"Calendly","icon_key":"calendly","placeholder":"yourname","category":"booking"}
```

- [ ] **Step 5: Smoke-test a platform write via `php artisan tinker`**

```
php artisan tinker
>>> $pro = \App\Models\Core\Professional\Professional::first();
>>> app(\App\Services\Site\SocialLinkNormalizer::class)->normalize('substack', 'joshhunter', null);
```

Expected: an array with `url => 'https://joshhunter.substack.com/'`, `handle => 'joshhunter'`, `platform_key => 'substack'`.

- [ ] **Step 6: Git log sanity check**

Run: `git log --oneline -30`
Expected: ~15-20 commits, each small, each with a passing test. No giant bundled commits.

---

## Self-Review Checklist

**Spec coverage (each §):**
- §1 motivation → covered by all tasks collectively.
- §2 spelling corrections → baked into Tasks 4-9 platform keys and display names.
- §3 category system (enum, storage, override, promotion) → Task 1 (enum), Task 15 (override), docs Task 20 (promotion).
- §4 platform registry extension → Tasks 3-9 (config); Task 10 (normalizer subdomain branch).
- §4.5 new icon keys → Task 2.
- §5.1 Form Request rules → Tasks 13-14.
- §5.2 controller resolution → Task 15.
- §5.3 normalizer subdomain branch → Task 10.
- §5.4 public registry endpoint → Tasks 11-12.
- §5.5 read shape — **intentionally dropped** (scope adjustment at top of plan) — `settings.category` surfaces naturally; no Resource class exists.
- §6 backfill → Task 16.
- §7 tests → Tasks 17-18 platform coverage, Task 10 subdomain security, Tasks 13-14 category validation, Task 12 public endpoint, Task 19 suite pass.
- §8 security — subdomain suffix check, ReDoS-safe patterns, enum validation → Task 10 (code + test), Task 1 (enum).
- §9 scalability — JSONB rationale, promotion path → docs Task 20.
- §10 out-of-scope items remain out of scope. No tasks added for them.
- §11 implementation pointers — every file listed has a task touching it.

**Placeholder scan:**
- No "TBD", "TODO", or "figure out" strings anywhere.
- Every code change shows the exact replacement block or insertion text.
- Every test shows full Pest code.
- Every command shows exact CLI invocation with expected output.

**Type consistency:**
- `handle_location` is `'path' | 'subdomain'` — used consistently across Tasks 3, 6, 9, 10.
- `default_category` is a string enum — used consistently.
- `settings.category` is a string enum — used consistently.
- Public endpoint field name is `category` (not `default_category`) — consistent Tasks 11, 12, 17, 18.

**Commit hygiene:**
- Each commit is a self-contained unit with green tests (except Tasks 13-14 which are intentionally bundled into Task 15's commit — flagged explicitly).

---

## Done-definition

All of:
- 24 platforms in `config('sidest.social_platforms')`, 4 of them subdomain-mode.
- Every link block write requires `category` (enum-validated). Platform writes default to `default_category`; custom writes require explicit `category`.
- `GET /api/public/config/social-platforms` returns `platforms[].category` and top-level `categories`.
- Subdomain-handle platforms (Substack/Bandcamp/Kajabi/Circle) validate via labelled-suffix host check with dedicated test for `evilsubstack.com` rejection.
- Backfill command idempotently assigns `settings.category` to every pre-existing link block.
- `composer test` green, `php artisan pint --test` green, no new Laravel migrations.
- `docs/social-links.md` updated to describe categories, the two modes, and the 24 platforms.
