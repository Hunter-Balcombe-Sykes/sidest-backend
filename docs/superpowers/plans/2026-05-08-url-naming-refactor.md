# URL & Naming Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move URL composition from frontend to backend by storing pre-built `user_url` and `brand_affiliate_url` columns, projecting `display_name` as persona-specific `brand_name` / `username` in API responses, and removing the now-redundant QR code redirect indirection.

**Architecture:** Two new trigger-managed URL columns (`core.professionals.user_url`, `brand.brand_partner_links.brand_affiliate_url`), one new alias table (`site.professional_handle_aliases`) mirroring the existing subdomain alias pattern, one Postgres function (`site.compute_professional_url`) as the single source of URL composition, five triggers maintaining invariants. API resources project the underlying `display_name` column to `brand_name` (brands) or `username` (everyone else). Custom domains take precedence over partna.au subdomains when verified. The QR `/p/{qr_slug}` indirection is removed because the new alias machinery makes it redundant for partna.au URLs.

**Tech Stack:** PHP 8.5 / Laravel 12, PostgreSQL (Supabase), Pest 4 + PHPUnit, Eloquent.

**Spec:** `docs/superpowers/specs/2026-05-08-url-naming-refactor-design.md`

**Prereqs (Josh runs these manually before starting):**
- `git fetch && git pull` on `development-v2`
- `git log --oneline -10` to confirm branch state
- Cut a feature branch: `git checkout -b feat/url-naming-refactor`

---

## Phase 1 — Database schema, triggers, backfill

### Task 1: Migration 1 — schema additions, alias table, triggers

**Files:**
- Create: `supabase/migrations/20260508000000_url_columns_and_triggers.sql`

- [ ] **Step 1: Create the migration file**

```sql
-- 20260508000000_url_columns_and_triggers.sql
-- Adds user_url and brand_affiliate_url columns (nullable initially),
-- creates the professional_handle_aliases table, defines the URL composition
-- function, and installs five triggers that keep URL columns in sync.

BEGIN;

-- 1. New columns (nullable; backfill in migration 2 will populate, NOT NULL added in migration 3)
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS user_url text NULL;

ALTER TABLE brand.brand_partner_links
    ADD COLUMN IF NOT EXISTS brand_affiliate_url text NULL;

-- 2. Lookup indexes
CREATE INDEX IF NOT EXISTS professionals_user_url_idx
    ON core.professionals (user_url) WHERE user_url IS NOT NULL;

CREATE INDEX IF NOT EXISTS brand_partner_links_brand_aff_url_idx
    ON brand.brand_partner_links (brand_affiliate_url);

-- 3. Professional handle alias table — mirrors site.site_subdomain_aliases
CREATE TABLE IF NOT EXISTS site.professional_handle_aliases (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    handle varchar(63) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS professional_handle_aliases_handle_lc_uq
    ON site.professional_handle_aliases (LOWER(handle));
CREATE INDEX IF NOT EXISTS professional_handle_aliases_professional_idx
    ON site.professional_handle_aliases (professional_id);

ALTER TABLE site.professional_handle_aliases ENABLE ROW LEVEL SECURITY;

-- 4. URL composition function (single source of truth for URL construction)
CREATE OR REPLACE FUNCTION site.compute_professional_url(p_professional_id uuid)
RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_subdomain text;
    v_custom_domain text;
    v_custom_verified timestamptz;
BEGIN
    SELECT bss.custom_domain, bss.custom_domain_verified_at
      INTO v_custom_domain, v_custom_verified
      FROM brand.brand_store_settings bss
     WHERE bss.professional_id = p_professional_id;

    IF v_custom_domain IS NOT NULL AND v_custom_verified IS NOT NULL THEN
        RETURN 'https://' || v_custom_domain;
    END IF;

    SELECT s.subdomain INTO v_subdomain
      FROM site.sites s
     WHERE s.professional_id = p_professional_id;

    IF v_subdomain IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN 'https://' || v_subdomain || '.partna.au';
END;
$$;

-- 5. Trigger function: recompute user_url + cascade to brand_partner_links
CREATE OR REPLACE FUNCTION site.trg_recompute_user_url(p_professional_id uuid)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    v_url text;
BEGIN
    v_url := site.compute_professional_url(p_professional_id);

    UPDATE core.professionals
       SET user_url = v_url
     WHERE id = p_professional_id;

    -- Cascade: if this professional is a brand, every connected affiliate's URL
    -- (brand subdomain + affiliate handle) needs to recompute.
    UPDATE brand.brand_partner_links bpl
       SET brand_affiliate_url = v_url || '/' || p.handle
      FROM core.professionals p
     WHERE bpl.brand_professional_id = p_professional_id
       AND bpl.affiliate_professional_id = p.id;
END;
$$;

-- 6. Trigger function: when an affiliate's handle changes, every brand_partner_links
-- row where this professional is the AFFILIATE has its URL path-segment updated.
CREATE OR REPLACE FUNCTION site.trg_recompute_affiliate_path(p_affiliate_id uuid, p_new_handle text)
RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    UPDATE brand.brand_partner_links bpl
       SET brand_affiliate_url = brand.user_url || '/' || p_new_handle
      FROM core.professionals brand
     WHERE bpl.affiliate_professional_id = p_affiliate_id
       AND bpl.brand_professional_id = brand.id;
END;
$$;

-- 7. Trigger 1: site.sites INSERT or UPDATE OF subdomain
CREATE OR REPLACE FUNCTION site.trg_sites_url_sync()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM site.trg_recompute_user_url(NEW.professional_id);
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS sites_url_sync_aiu ON site.sites;
CREATE TRIGGER sites_url_sync_aiu
    AFTER INSERT OR UPDATE OF subdomain ON site.sites
    FOR EACH ROW EXECUTE FUNCTION site.trg_sites_url_sync();

-- 8. Trigger 2: brand.brand_store_settings INSERT or UPDATE OF custom_domain*
CREATE OR REPLACE FUNCTION brand.trg_store_settings_url_sync()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM site.trg_recompute_user_url(NEW.professional_id);
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS store_settings_url_sync_aiu ON brand.brand_store_settings;
CREATE TRIGGER store_settings_url_sync_aiu
    AFTER INSERT OR UPDATE OF custom_domain, custom_domain_verified_at ON brand.brand_store_settings
    FOR EACH ROW EXECUTE FUNCTION brand.trg_store_settings_url_sync();

-- 9. Trigger 3 (AFTER): core.professionals UPDATE OF handle
-- Inserts old handle into aliases + recomputes affiliate-side URL paths.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_change()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO site.professional_handle_aliases (professional_id, handle)
    VALUES (NEW.id, OLD.handle)
    ON CONFLICT DO NOTHING;

    PERFORM site.trg_recompute_affiliate_path(NEW.id, NEW.handle);

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS professional_handle_change_au ON core.professionals;
CREATE TRIGGER professional_handle_change_au
    AFTER UPDATE OF handle ON core.professionals
    FOR EACH ROW
    WHEN (OLD.handle IS DISTINCT FROM NEW.handle)
    EXECUTE FUNCTION core.trg_professional_handle_change();

-- 10. Trigger 4: brand.brand_partner_links BEFORE INSERT — initial URL computation
CREATE OR REPLACE FUNCTION brand.trg_partner_link_url_init()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_brand_url text;
    v_affiliate_handle text;
BEGIN
    SELECT user_url INTO v_brand_url
      FROM core.professionals WHERE id = NEW.brand_professional_id;

    SELECT handle INTO v_affiliate_handle
      FROM core.professionals WHERE id = NEW.affiliate_professional_id;

    IF v_brand_url IS NOT NULL AND v_affiliate_handle IS NOT NULL THEN
        NEW.brand_affiliate_url := v_brand_url || '/' || v_affiliate_handle;
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS partner_link_url_init_bi ON brand.brand_partner_links;
CREATE TRIGGER partner_link_url_init_bi
    BEFORE INSERT ON brand.brand_partner_links
    FOR EACH ROW EXECUTE FUNCTION brand.trg_partner_link_url_init();

-- 11. Trigger 5 (BEFORE): core.professionals BEFORE UPDATE OF handle
-- Constraint check: prevent renaming into a handle that's currently aliased to another professional.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_alias_check()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_blocking_pro uuid;
BEGIN
    IF NEW.handle IS NOT DISTINCT FROM OLD.handle THEN
        RETURN NEW;
    END IF;

    SELECT professional_id INTO v_blocking_pro
      FROM site.professional_handle_aliases
     WHERE LOWER(handle) = LOWER(NEW.handle)
       AND professional_id <> NEW.id
     LIMIT 1;

    IF v_blocking_pro IS NOT NULL THEN
        RAISE EXCEPTION 'Handle % is reserved as a redirect for another professional', NEW.handle
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS professional_handle_alias_check_bu ON core.professionals;
CREATE TRIGGER professional_handle_alias_check_bu
    BEFORE UPDATE OF handle ON core.professionals
    FOR EACH ROW
    EXECUTE FUNCTION core.trg_professional_handle_alias_check();

COMMIT;
```

- [ ] **Step 2: Apply the migration locally**

Run: `supabase migration up` (or whatever the project's local Supabase command is — verify via project README / past PR descriptions).

Expected: migration applies cleanly, no errors. Confirm by running `psql ... -c "\d core.professionals"` and seeing `user_url` listed.

- [ ] **Step 3: Smoke-test the trigger**

In `psql` against the local Supabase DB:

```sql
-- Pick an existing professional with a site
SELECT p.id, p.handle, s.subdomain FROM core.professionals p
  JOIN site.sites s ON s.professional_id = p.id LIMIT 1;

-- Confirm user_url is NULL (backfill hasn't run yet)
SELECT id, user_url FROM core.professionals WHERE id = '<id from above>';

-- Touch the subdomain to force trigger 1 to fire
UPDATE site.sites SET subdomain = subdomain WHERE professional_id = '<id from above>';

-- Confirm user_url is now populated
SELECT id, user_url FROM core.professionals WHERE id = '<id from above>';
```

Expected: `user_url` becomes `https://<subdomain>.partna.au`.

- [ ] **Step 4: Stage and ask Josh to commit (Josh commits per workflow)**

```bash
git add supabase/migrations/20260508000000_url_columns_and_triggers.sql
# Suggested message: "feat(db): url_columns + handle alias table + triggers"
```

---

### Task 2: Migration 2 — backfill existing rows

**Files:**
- Create: `supabase/migrations/20260508000001_backfill_user_urls.sql`

- [ ] **Step 1: Create the migration file**

```sql
-- 20260508000001_backfill_user_urls.sql
-- Backfills user_url for every professional with a linked site row, and
-- brand_affiliate_url for every existing brand_partner_links row.

BEGIN;

-- Backfill professional user_url. The compute function returns NULL for
-- professionals without a site row (e.g., newly-created accounts pre-site).
UPDATE core.professionals p
   SET user_url = site.compute_professional_url(p.id)
 WHERE p.user_url IS NULL;

-- Backfill brand_partner_links.brand_affiliate_url
UPDATE brand.brand_partner_links bpl
   SET brand_affiliate_url = brand.user_url || '/' || aff.handle
  FROM core.professionals brand, core.professionals aff
 WHERE brand.id = bpl.brand_professional_id
   AND aff.id   = bpl.affiliate_professional_id
   AND bpl.brand_affiliate_url IS NULL
   AND brand.user_url IS NOT NULL;

COMMIT;
```

- [ ] **Step 2: Apply the migration**

Run: `supabase migration up`

Expected: success, no errors. Pre-beta data volume — runs in <1s.

- [ ] **Step 3: Verify backfill**

```sql
-- Should be 0 (every professional with a site has a URL)
SELECT count(*) FROM core.professionals p
  JOIN site.sites s ON s.professional_id = p.id
 WHERE p.user_url IS NULL;

-- Should be 0 (every partner link has its URL populated when brand has user_url)
SELECT count(*) FROM brand.brand_partner_links bpl
  JOIN core.professionals brand ON brand.id = bpl.brand_professional_id
 WHERE bpl.brand_affiliate_url IS NULL AND brand.user_url IS NOT NULL;
```

Expected: both queries return 0.

- [ ] **Step 4: Stage**

```bash
git add supabase/migrations/20260508000001_backfill_user_urls.sql
# Suggested message: "feat(db): backfill user_url and brand_affiliate_url"
```

---

### Task 3: Migration 3 — NOT NULL constraints

**Files:**
- Create: `supabase/migrations/20260508000002_url_columns_not_null.sql`

- [ ] **Step 1: Create the migration file**

```sql
-- 20260508000002_url_columns_not_null.sql
-- Now that backfill has populated all rows, enforce NOT NULL.

BEGIN;

ALTER TABLE core.professionals
    ALTER COLUMN user_url SET NOT NULL;

ALTER TABLE brand.brand_partner_links
    ALTER COLUMN brand_affiliate_url SET NOT NULL;

COMMIT;
```

- [ ] **Step 2: Apply the migration**

Run: `supabase migration up`

Expected: success. If it fails with "column contains null values", a row was missed in backfill — investigate by checking professionals without a `site` row.

- [ ] **Step 3: Stage**

```bash
git add supabase/migrations/20260508000002_url_columns_not_null.sql
# Suggested message: "feat(db): enforce NOT NULL on url columns"
```

---

## Phase 2 — Models

### Task 4: Update `Professional` model — guard `user_url`, remove `qr_slug`

**Files:**
- Modify: `app/Models/Core/Professional/Professional.php:49-93` (the `$fillable` array)
- Modify: `app/Models/Core/Professional/Professional.php:19-25` (the property docblock)
- Test: `tests/Unit/Models/ProfessionalUrlTest.php` (new)

- [ ] **Step 1: Write the failing test**

`tests/Unit/Models/ProfessionalUrlTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;

it('does not allow mass-assigning user_url', function () {
    $pro = new Professional();
    $pro->fill(['user_url' => 'https://attacker.example.com']);

    expect($pro->user_url)->toBeNull();
});

it('does not allow mass-assigning qr_slug', function () {
    $pro = new Professional();
    $pro->fill(['qr_slug' => 'attacker']);

    expect($pro->qr_slug ?? null)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ProfessionalUrlTest`

Expected: second test FAILS — `qr_slug` is currently fillable (`Professional.php:59`), so the assignment goes through.

- [ ] **Step 3: Update the model**

Edit `app/Models/Core/Professional/Professional.php`:

1. Remove `'qr_slug',` from the `$fillable` array (line 59).
2. Do NOT add `'user_url'` to `$fillable` — it stays guarded (trigger-managed).
3. Update the docblock to add the new `user_url` property:

```php
/**
 * @property string $id
 * @property string $auth_user_id
 * @property string $handle
 * @property string $display_name
 * @property int $onboarding_step
 * @property string|null $user_url
 */
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ProfessionalUrlTest`

Expected: both tests PASS.

- [ ] **Step 5: Run Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Models/Core/Professional/Professional.php tests/Unit/Models/ProfessionalUrlTest.php
# Suggested message: "feat(model): guard user_url, remove qr_slug from fillable"
```

---

### Task 5: Update `BrandPartnerLink` model — expose `brand_affiliate_url` (read-only)

**Files:**
- Modify: `app/Models/Core/Professional/BrandPartnerLink.php`
- Test: `tests/Unit/Models/BrandPartnerLinkUrlTest.php` (new)

- [ ] **Step 1: Read the existing model to understand current structure**

```bash
cat app/Models/Core/Professional/BrandPartnerLink.php
```

Confirm the model uses `$fillable` (vs `$guarded`) and note its existing keys.

- [ ] **Step 2: Write the failing test**

`tests/Unit/Models/BrandPartnerLinkUrlTest.php`:

```php
<?php

use App\Models\Core\Professional\BrandPartnerLink;

it('does not allow mass-assigning brand_affiliate_url', function () {
    $link = new BrandPartnerLink();
    $link->fill(['brand_affiliate_url' => 'https://attacker.example.com']);

    expect($link->brand_affiliate_url)->toBeNull();
});

it('exposes brand_affiliate_url as a readable attribute', function () {
    $link = new BrandPartnerLink();
    $link->setRawAttributes(['brand_affiliate_url' => 'https://evo.partna.au/josh']);

    expect($link->brand_affiliate_url)->toBe('https://evo.partna.au/josh');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=BrandPartnerLinkUrlTest`

Expected: tests FAIL with "Class doesn't allow this attribute" or similar — column doesn't exist in fillable.

- [ ] **Step 4: Update the model**

Edit `app/Models/Core/Professional/BrandPartnerLink.php`:

1. Do NOT add `brand_affiliate_url` to `$fillable` (stays guarded).
2. Add to the property docblock at the top of the class:

```php
/**
 * @property string $brand_affiliate_url
 */
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=BrandPartnerLinkUrlTest`

Expected: both tests PASS.

- [ ] **Step 6: Run Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Models/Core/Professional/BrandPartnerLink.php tests/Unit/Models/BrandPartnerLinkUrlTest.php
# Suggested message: "feat(model): expose brand_affiliate_url on BrandPartnerLink"
```

---

## Phase 3 — Resources

### Task 6: Update `ProfessionalResource` — project persona names, expose `user_url`, drop `handle`/`qr_slug`

**Files:**
- Modify: `app/Http/Resources/ProfessionalResource.php`
- Test: `tests/Feature/Resources/ProfessionalResourceTest.php` (new)

- [ ] **Step 1: Write the failing test**

`tests/Feature/Resources/ProfessionalResourceTest.php`:

```php
<?php

use App\Http\Resources\ProfessionalResource;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;

function buildPro(array $overrides = []): Professional
{
    $pro = new Professional();
    $pro->setRawAttributes(array_merge([
        'id' => 'pro-1',
        'handle' => 'evo',
        'handle_lc' => 'evo',
        'display_name' => 'Evo',
        'professional_type' => 'brand',
        'user_url' => 'https://evo.partna.au',
        'first_name' => null,
        'last_name' => null,
        'bio' => null,
        'about' => null,
        'phone' => null,
        'primary_email' => 'evo@example.com',
        'country_code' => 'AU',
        'timezone' => 'Australia/Sydney',
        'status' => 'active',
        'onboarding_step' => 0,
        'public_contact_number' => null,
        'public_contact_email' => null,
        'location_street_address' => null,
        'location_city' => null,
        'location_state' => null,
        'location_postcode' => null,
        'location_country' => null,
        'stripe_connect_status' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $pro;
}

it('returns brand_name for brand-type professionals', function () {
    $pro = buildPro(['professional_type' => 'brand', 'display_name' => 'Push Pull']);
    $array = (new ProfessionalResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('brand_name', 'Push Pull')
        ->not->toHaveKey('username')
        ->toHaveKey('user_url', $pro->user_url);
});

it('returns username for non-brand professionals', function () {
    $pro = buildPro(['professional_type' => 'influencer', 'display_name' => 'Barber Josh']);
    $array = (new ProfessionalResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('username', 'Barber Josh')
        ->not->toHaveKey('brand_name');
});

it('does not expose handle or qr_slug', function () {
    $pro = buildPro();
    $array = (new ProfessionalResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->not->toHaveKey('handle')
        ->not->toHaveKey('handle_lc')
        ->not->toHaveKey('qr_slug')
        ->not->toHaveKey('display_name');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ProfessionalResourceTest`

Expected: tests FAIL — current resource still returns `handle`, `display_name`, `qr_slug` and lacks `brand_name` / `username` / `user_url`.

- [ ] **Step 3: Replace the resource body**

Edit `app/Http/Resources/ProfessionalResource.php` — replace the `toArray()` method:

```php
public function toArray(Request $request): array
{
    $isBrand = $this->resource->isBrand();

    return [
        'id' => $this->id,
        'professional_type' => $this->professional_type,
        'brand_name' => $this->when($isBrand, $this->display_name),
        'username' => $this->when(! $isBrand, $this->display_name),
        'user_url' => $this->user_url,
        'first_name' => $this->first_name,
        'last_name' => $this->last_name,
        'bio' => $this->bio,
        'about' => (object) ($this->about ?? []),
        'phone' => $this->phone,
        'primary_email' => $this->primary_email,
        'country_code' => $this->country_code,
        'timezone' => $this->timezone,
        'status' => $this->status,
        'onboarding_step' => $this->onboarding_step,
        'public_contact_number' => $this->public_contact_number,
        'public_contact_email' => $this->public_contact_email,
        'location_street_address' => $this->location_street_address,
        'location_city' => $this->location_city,
        'location_state' => $this->location_state,
        'location_postcode' => $this->location_postcode,
        'location_country' => $this->location_country,
        'stripe_connect_status' => $this->stripe_connect_status,
        'created_at' => $this->created_at?->toIso8601String(),
        'updated_at' => $this->updated_at?->toIso8601String(),
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ProfessionalResourceTest`

Expected: all three tests PASS.

- [ ] **Step 5: Run wider tests to check for breakage**

Run: `php artisan test --compact --filter=Professional`

Expected: any tests asserting on the OLD shape (`handle`, `display_name`, `qr_slug`) will fail. Update those tests to assert on the new shape — they're testing the same behavior with a renamed contract.

- [ ] **Step 6: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Resources/ProfessionalResource.php tests/Feature/Resources/ProfessionalResourceTest.php
# Suggested message: "feat(api): project display_name as brand_name/username on ProfessionalResource"
```

---

### Task 7: Update `ProfessionalPublicResource` — same projection, no PII

**Files:**
- Modify: `app/Http/Resources/ProfessionalPublicResource.php`
- Test: `tests/Feature/Resources/ProfessionalPublicResourceTest.php` (new)

- [ ] **Step 1: Write the failing test**

`tests/Feature/Resources/ProfessionalPublicResourceTest.php`:

```php
<?php

use App\Http\Resources\ProfessionalPublicResource;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;

it('returns brand_name and user_url, no PII', function () {
    $pro = new Professional();
    $pro->setRawAttributes([
        'id' => 'pro-1',
        'handle' => 'evo',
        'handle_lc' => 'evo',
        'display_name' => 'Evo',
        'professional_type' => 'brand',
        'user_url' => 'https://evo.partna.au',
        'bio' => 'Hair and beauty',
        'public_contact_number' => null,
        'public_contact_email' => 'shop@evo.example',
        'location_city' => 'Sydney',
        'location_state' => 'NSW',
        'location_country' => 'AU',
        'first_name' => 'SHOULD-NOT-LEAK',
        'last_name' => 'SHOULD-NOT-LEAK',
        'primary_email' => 'shouldnotleak@example.com',
    ]);

    $array = (new ProfessionalPublicResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('brand_name', 'Evo')
        ->toHaveKey('user_url', 'https://evo.partna.au')
        ->not->toHaveKey('handle')
        ->not->toHaveKey('display_name')
        ->not->toHaveKey('first_name')
        ->not->toHaveKey('last_name')
        ->not->toHaveKey('primary_email');
});

it('returns username for non-brand professionals', function () {
    $pro = new Professional();
    $pro->setRawAttributes([
        'id' => 'pro-2',
        'handle' => 'barber-josh',
        'handle_lc' => 'barber-josh',
        'display_name' => 'Barber Josh',
        'professional_type' => 'influencer',
        'user_url' => 'https://barber-josh.partna.au',
    ]);

    $array = (new ProfessionalPublicResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('username', 'Barber Josh')
        ->not->toHaveKey('brand_name');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ProfessionalPublicResourceTest`

Expected: FAIL — current resource returns `handle` and `display_name`.

- [ ] **Step 3: Replace the resource body**

Edit `app/Http/Resources/ProfessionalPublicResource.php`:

```php
public function toArray(Request $request): array
{
    $isBrand = $this->resource->isBrand();

    return [
        'id' => $this->id,
        'professional_type' => $this->professional_type,
        'brand_name' => $this->when($isBrand, $this->display_name),
        'username' => $this->when(! $isBrand, $this->display_name),
        'user_url' => $this->user_url,
        'bio' => $this->bio,
        'public_contact_number' => $this->public_contact_number,
        'public_contact_email' => $this->public_contact_email,
        'location_city' => $this->location_city,
        'location_state' => $this->location_state,
        'location_country' => $this->location_country,
    ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ProfessionalPublicResourceTest`

Expected: PASS.

- [ ] **Step 5: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Resources/ProfessionalPublicResource.php tests/Feature/Resources/ProfessionalPublicResourceTest.php
# Suggested message: "feat(api): persona-aware projection on ProfessionalPublicResource"
```

---

### Task 8: Update `ProfessionalDashboardResource` — same projection

**Files:**
- Modify: `app/Http/Resources/ProfessionalDashboardResource.php`

- [ ] **Step 1: Read existing file**

```bash
cat app/Http/Resources/ProfessionalDashboardResource.php
```

Note: this resource also exposes Square integration fields via `whenLoaded`. Preserve those.

- [ ] **Step 2: Update the resource**

Replace the keys `'handle'`, `'handle_lc'`, `'display_name'`, `'qr_slug'` with the new projection. Keep all other fields (PII visible — this is the auth'd own-profile view).

```php
public function toArray(Request $request): array
{
    $isBrand = $this->resource->isBrand();

    return [
        'id' => $this->id,
        'auth_user_id' => $this->auth_user_id,
        'professional_type' => $this->professional_type,
        'brand_name' => $this->when($isBrand, $this->display_name),
        'username' => $this->when(! $isBrand, $this->display_name),
        'user_url' => $this->user_url,
        'first_name' => $this->first_name,
        'last_name' => $this->last_name,
        'bio' => $this->bio,
        'about' => (object) ($this->about ?? []),
        'phone' => $this->phone,
        'primary_email' => $this->primary_email,
        'country_code' => $this->country_code,
        'timezone' => $this->timezone,
        'status' => $this->status,
        'onboarding_step' => $this->onboarding_step,
        'public_contact_number' => $this->public_contact_number,
        'public_contact_email' => $this->public_contact_email,
        'location_street_address' => $this->location_street_address,
        'location_city' => $this->location_city,
        'location_state' => $this->location_state,
        'location_postcode' => $this->location_postcode,
        'location_country' => $this->location_country,
        'stripe_connect_status' => $this->stripe_connect_status,
        'created_at' => $this->created_at?->toIso8601String(),
        'updated_at' => $this->updated_at?->toIso8601String(),
        // Square — preserve existing whenLoaded blocks unchanged
        'square_connected' => $this->whenLoaded('squareIntegration', function () {
            $integration = $this->squareIntegration;

            return $integration !== null
                && ! empty($integration->access_token)
                && ! empty($integration->external_account_id);
        }),
        'square_merchant_id' => $this->whenLoaded('squareIntegration', fn () => $this->squareIntegration?->external_account_id),
        // (paste back any other Square fields that exist in the current file)
    ];
}
```

**IMPORTANT:** Read the existing file first to capture any Square fields below `square_merchant_id` and preserve them verbatim. The diff is: drop handle/handle_lc/display_name/qr_slug, add brand_name/username/user_url.

- [ ] **Step 3: Run any existing tests**

Run: `php artisan test --compact --filter=Dashboard`

Expected: any tests asserting on the old keys fail; update them to assert on the new shape.

- [ ] **Step 4: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Resources/ProfessionalDashboardResource.php
# Suggested message: "feat(api): persona-aware projection on ProfessionalDashboardResource"
```

---

### Task 9: Update `ProfessionalStaffResource` — same projection

**Files:**
- Modify: `app/Http/Resources/ProfessionalStaffResource.php`

- [ ] **Step 1: Read the existing file**

```bash
cat app/Http/Resources/ProfessionalStaffResource.php
```

- [ ] **Step 2: Apply the same diff as ProfessionalResource**

Drop: `handle`, `handle_lc`, `display_name`, `qr_slug`.
Add: `brand_name` (when brand), `username` (when not brand), `user_url`.

Preserve every other field exactly as it is — staff resources usually have audit/internal fields.

- [ ] **Step 3: Run any existing staff-resource tests**

Run: `php artisan test --compact --filter=Staff`

Expected: update assertions for the new shape; passing.

- [ ] **Step 4: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Resources/ProfessionalStaffResource.php
# Suggested message: "feat(api): persona-aware projection on ProfessionalStaffResource"
```

---

### Task 10: Update `BrandStoreSettingsResource` — drop `storefront_base_url`

**Files:**
- Modify: `app/Http/Resources/BrandStoreSettingsResource.php`

- [ ] **Step 1: Read the existing file**

```bash
cat app/Http/Resources/BrandStoreSettingsResource.php
```

Find the `'storefront_base_url' => …` line.

- [ ] **Step 2: Remove the field**

Delete the `'storefront_base_url'` key from `toArray()`. No replacement — frontend reads `user_url` from the linked professional.

- [ ] **Step 3: Run brand store settings tests**

Run: `php artisan test --compact --filter=BrandStoreSettings`

Expected: tests asserting on `storefront_base_url` fail; update them or remove those specific assertions.

- [ ] **Step 4: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Resources/BrandStoreSettingsResource.php
# Suggested message: "feat(api): drop storefront_base_url; frontend uses user_url"
```

---

## Phase 4 — Form Requests

### Task 11: Update `BootstrapRequest` — handle uniqueness checks aliases too

**Files:**
- Modify: `app/Http/Requests/Api/BootstrapRequest.php`
- Test: `tests/Feature/Api/BootstrapHandleAliasUniquenessTest.php` (new)

- [ ] **Step 1: Read the existing handle uniqueness rule**

```bash
grep -n "handle_lc\|unique" app/Http/Requests/Api/BootstrapRequest.php | head -20
```

Locate the rule (around line 58 per earlier exploration). It currently uses `Rule::unique('professionals', 'handle_lc')`.

- [ ] **Step 2: Write the failing test**

`tests/Feature/Api/BootstrapHandleAliasUniquenessTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;

it('rejects a handle that exists in professional_handle_aliases', function () {
    // Create a professional and orphan their old handle as an alias
    $existing = Professional::factory()->create(['handle' => 'evolution', 'handle_lc' => 'evolution']);

    DB::table('site.professional_handle_aliases')->insert([
        'professional_id' => $existing->id,
        'handle' => 'evo',
    ]);

    // A new signup attempting to claim 'evo' should fail
    $response = $this->postJson('/api/bootstrap', [
        'display_name' => 'New Brand',
        'handle' => 'evo',
        'professional_type' => 'brand',
        // ... other required fields per BootstrapRequest
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['handle_lc']);
});
```

NOTE: the bootstrap endpoint likely requires more fields (email, etc.); adjust per the actual `BootstrapRequest::rules()` contract before running.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=BootstrapHandleAliasUniquenessTest`

Expected: FAIL — alias is not currently checked.

- [ ] **Step 4: Update the rule**

Locate the existing handle uniqueness rule:

```php
'handle_lc' => [
    'string',
    Rule::unique('professionals', 'handle_lc')->ignore($existingProfessionalId, 'id'),
    // ...other rules
],
```

Add a second uniqueness rule for the alias table:

```php
'handle_lc' => [
    'string',
    Rule::unique('professionals', 'handle_lc')->ignore($existingProfessionalId, 'id'),
    Rule::unique('site.professional_handle_aliases', 'handle')->where(function ($q) {
        return $q->whereRaw('LOWER(handle) = LOWER(?)', [request('handle')]);
    }),
    // ...other rules
],
```

NOTE: SQLite does not support `LOWER()` in unique constraints the same way — for tests, ensure the connection supports the function or write the validation as a custom rule that does the lookup explicitly.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=BootstrapHandleAliasUniquenessTest`

Expected: PASS.

- [ ] **Step 6: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Requests/Api/BootstrapRequest.php tests/Feature/Api/BootstrapHandleAliasUniquenessTest.php
# Suggested message: "feat(api): bootstrap handle uniqueness checks alias table"
```

---

### Task 12: Update `UpdateSiteRequest` — subdomain check covers professional handle aliases

**Files:**
- Modify: `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php` (around line 144-180 per spec)

- [ ] **Step 1: Read the custom subdomain validator**

```bash
sed -n '140,195p' app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php
```

The current logic checks against `site.sites` and `site.site_subdomain_aliases`. Add a third check against `site.professional_handle_aliases`.

- [ ] **Step 2: Add the alias check**

Locate the existing aggregated existence check. Wrap it (or extend it) so the validator queries the new alias table too. Pseudocode:

```php
$existsInProfessionalAliases = DB::table('site.professional_handle_aliases')
    ->whereRaw('LOWER(handle) = LOWER(?)', [$value])
    ->where('professional_id', '!=', $currentProfessionalId)
    ->exists();

if ($existsInSites || $existsInSubdomainAliases || $existsInProfessionalAliases) {
    $fail('subdomain', 'This subdomain is already taken or reserved.');
}
```

- [ ] **Step 3: Run subdomain validation tests**

Run: `php artisan test --compact --filter=UpdateSiteRequest`

Expected: existing tests pass. If a test for "subdomain reserved as another professional's old handle" doesn't exist, add one.

- [ ] **Step 4: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php
# Suggested message: "feat(api): subdomain validation also rejects professional handle aliases"
```

---

## Phase 5 — QR cleanup

### Task 13: Rewrite `QrCodeController::svg` to use `user_url` directly

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/QrCodeController.php`

- [ ] **Step 1: Read the existing controller**

```bash
cat app/Http/Controllers/Api/PublicSite/QrCodeController.php
```

The existing `svg` method takes `$qr_slug`, looks up a professional, and calls `$this->qrUrl($qr_slug, $request)` from the `BuildsQrCodeUrls` trait.

- [ ] **Step 2: Rewrite the svg method**

The new `svg` method takes a professional ID (or slug — match whatever the route expects after Task 14's route change) and reads `user_url` directly:

```php
public function svg(string $professionalId, Request $request): Response
{
    $professional = Professional::query()
        ->whereKey($professionalId)
        ->first();

    if (! $professional || ! $professional->user_url) {
        abort(404);
    }

    $qrCode = QrCode::create($professional->user_url)
        ->setSize(320)
        ->setMargin(10);

    $writer = new SvgWriter();

    return response($writer->write($qrCode)->getString())
        ->header('Content-Type', 'image/svg+xml');
}
```

- [ ] **Step 3: Remove the `BuildsQrCodeUrls` trait usage from the controller**

Edit the top of the class — delete `use BuildsQrCodeUrls;`. Remove the `use App\Http\Controllers\Concerns\BuildsQrCodeUrls;` import.

- [ ] **Step 4: Run any QR controller tests**

Run: `php artisan test --compact --filter=QrCode`

Expected: tests likely fail because the route shape changed (slug → id). Either update tests or wait for Task 14 to settle the route.

- [ ] **Step 5: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/PublicSite/QrCodeController.php
# Suggested message: "refactor(qr): generate SVG directly from user_url"
```

---

### Task 14: Delete `QrCodeController::redirect` + remove `/p/{qr_slug}` route

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/QrCodeController.php`
- Modify: `routes/api.php` and any of `routes/api/*.php` referencing the route

- [ ] **Step 1: Find the route definition**

```bash
grep -rn "qr.*slug\|/p/{\|QrCodeController" routes/
```

- [ ] **Step 2: Delete the redirect route**

Remove the line(s) referencing `QrCodeController@redirect` or `[QrCodeController::class, 'redirect']` and the `/p/{qr_slug}` URI.

- [ ] **Step 3: Delete the redirect method**

In `QrCodeController.php`, delete the `public function redirect(string $qr_slug, Request $request): Response { ... }` method entirely.

- [ ] **Step 4: Update the SVG route signature if needed**

If the SVG route was `/qr/{qr_slug}.svg`, change it to `/qr/{professional}.svg` (or however the project conventions handle ID-based public routes — check `BootstrapController` in the same controller directory for patterns).

- [ ] **Step 5: Run controller tests**

Run: `php artisan test --compact --filter=QrCode`

Expected: tests asserting the redirect route fail with "route not defined" — remove or update those tests since the redirect endpoint no longer exists.

- [ ] **Step 6: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Api/PublicSite/QrCodeController.php routes/
# Suggested message: "refactor(qr): remove /p/{qr_slug} redirect endpoint"
```

---

### Task 15: Delete `BuildsQrCodeUrls` trait

**Files:**
- Delete: `app/Http/Controllers/Concerns/BuildsQrCodeUrls.php`

- [ ] **Step 1: Confirm no remaining usages**

```bash
grep -rn "BuildsQrCodeUrls" app/ --include="*.php"
```

Expected: zero matches (Task 13 removed the only usage).

- [ ] **Step 2: Delete the file**

```bash
rm app/Http/Controllers/Concerns/BuildsQrCodeUrls.php
```

- [ ] **Step 3: Run the full test suite**

Run: `composer test`

Expected: PASS. If there's a use-statement leftover anywhere, the autoloader will fail and a test will tell you.

- [ ] **Step 4: Stage**

```bash
git add app/Http/Controllers/Concerns/BuildsQrCodeUrls.php
# (git records the deletion automatically)
# Suggested message: "refactor(qr): drop BuildsQrCodeUrls trait — no longer used"
```

---

## Phase 6 — Service layer audit

### Task 16: Audit and remove remaining `qr_slug` references

**Files:**
- Modify: `app/Services/Professional/SiteProvisioningService.php`
- Modify: `app/Services/Cache/ProfessionalCacheService.php`
- Modify: `app/Services/Analytics/AffiliateProjectionsService.php`
- Modify: `app/Http/Controllers/Api/PublicSite/BootstrapController.php`

- [ ] **Step 1: Find every `qr_slug` reference outside notifications/email_subscriptions**

```bash
grep -rn "qr_slug\|qrSlug" app/ --include="*.php" | grep -v "EmailSubscription\|email_subscriptions"
```

- [ ] **Step 2: For each reference, decide: keep or remove**

For `SiteProvisioningService`: if it generates a `qr_slug` during signup, remove that generation step. The professional no longer needs a slug.

For `ProfessionalCacheService`: if it caches keyed on `qr_slug`, switch to caching by professional ID or `handle`.

For `AffiliateProjectionsService`: if it uses `qr_slug` for analytics joins, replace with `handle` or `professional_id` (check existing use carefully — this is the riskiest of the four).

For `BootstrapController`: if it returns `qr_slug` to the frontend or accepts it as input, remove.

**For each file, document what you changed in the commit message.**

- [ ] **Step 3: Run full test suite**

Run: `composer test`

Expected: PASS. Failures here likely mean an integration test still references `qr_slug` — update accordingly.

- [ ] **Step 4: Verify no `qr_slug` left outside email subscriptions**

```bash
grep -rn "qr_slug\|qrSlug" app/ --include="*.php" | grep -v "EmailSubscription\|email_subscriptions"
```

Expected: zero matches.

- [ ] **Step 5: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/
# Suggested message: "refactor: remove qr_slug usage from services and bootstrap"
```

---

## Phase 7 — Per-brand affiliate listing

### Task 17: Find and update brand-affiliate listing resource(s)

**Files:**
- Modify: One or more resource classes — needs discovery

- [ ] **Step 1: Find the resource that serializes BrandPartnerLink**

```bash
grep -rn "BrandPartnerLink\|brand_partner_links" app/Http/Resources/ app/Http/Controllers/ --include="*.php"
```

The most likely candidate is something like `app/Http/Resources/BrandAffiliateResource.php` or a controller method returning a transformed pivot row. Per the spec, it lives in or near `app/Http/Controllers/Api/Professional/BrandAffiliateController.php`.

- [ ] **Step 2: Read the resource/transformer**

Open the relevant file. Identify where the pivot row is being transformed.

- [ ] **Step 3: Add `brand_affiliate_url` to the response**

Add the key:

```php
'brand_affiliate_url' => $this->brand_affiliate_url,
```

If the resource currently composes the URL itself (`'url' => $brandSubdomain . '.partna.au/' . $affiliateHandle`), replace that line with a read from the column.

- [ ] **Step 4: Add a feature test**

`tests/Feature/Api/Professional/BrandAffiliateListingUrlTest.php`:

```php
<?php

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;

it('returns brand_affiliate_url from the listing endpoint', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    $affiliate = Professional::factory()->create(['professional_type' => 'influencer']);

    $link = BrandPartnerLink::factory()->create([
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $affiliate->id,
        'slot' => 0,
        'brand_affiliate_url' => 'https://evo.partna.au/josh', // forced via raw insert if trigger doesn't run
    ]);

    $response = $this->actingAsProfessional($brand)
        ->getJson("/api/brand/affiliates"); // adjust to actual endpoint

    $response->assertOk();
    $response->assertJsonFragment(['brand_affiliate_url' => 'https://evo.partna.au/josh']);
});
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --compact --filter=BrandAffiliateListingUrlTest`

Expected: PASS once the resource update from Step 3 is in place.

- [ ] **Step 6: Pint and stage**

```bash
vendor/bin/pint --dirty
git add app/Http/Resources/ app/Http/Controllers/ tests/
# Suggested message: "feat(api): expose brand_affiliate_url on per-brand listings"
```

---

## Phase 8 — Drop qr_slug column + final verification

### Task 18: Migration 4 — drop `qr_slug` column

**Files:**
- Create: `supabase/migrations/20260508000003_drop_professionals_qr_slug.sql`

**Order:** This migration runs LAST, after all PHP code has been deployed and verified. The previous tasks ensured no PHP code references `qr_slug` anymore.

- [ ] **Step 1: Confirm no remaining references**

```bash
grep -rn "qr_slug\|qrSlug" app/ --include="*.php" | grep -v "EmailSubscription\|email_subscriptions"
```

Expected: zero matches.

- [ ] **Step 2: Create the migration file**

```sql
-- 20260508000003_drop_professionals_qr_slug.sql
-- Drops the qr_slug column from core.professionals.
-- The notifications.email_subscriptions.qr_slug column (a separate per-row
-- tracking token, not the professional-level slug) is left untouched.

BEGIN;

-- The unique partial index will cascade-drop with the column.
ALTER TABLE core.professionals DROP COLUMN IF EXISTS qr_slug;

COMMIT;
```

- [ ] **Step 3: Apply the migration**

Run: `supabase migration up`

Expected: success.

- [ ] **Step 4: Verify**

```sql
\d core.professionals
```

Expected: no `qr_slug` column.

- [ ] **Step 5: Run the full test suite**

Run: `composer test`

Expected: PASS.

- [ ] **Step 6: Stage**

```bash
git add supabase/migrations/20260508000003_drop_professionals_qr_slug.sql
# Suggested message: "feat(db): drop qr_slug column — replaced by user_url"
```

---

### Task 19: Final verification + Pint pass

- [ ] **Step 1: Run the full test suite**

Run: `composer test`

Expected: all tests pass. If anything fails, fix root cause — don't disable.

- [ ] **Step 2: Run Pint on the full diff**

```bash
vendor/bin/pint --dirty
```

Expected: minimal changes if any (we ran it after each task).

- [ ] **Step 3: Manual smoke test against dev Supabase**

Run these in order against the local Supabase dev DB to validate the trigger flow:

```sql
-- Create test brand + affiliate
INSERT INTO core.professionals (id, handle, handle_lc, display_name, professional_type, primary_email)
VALUES ('00000000-0000-0000-0000-000000000001', 'testbrand', 'testbrand', 'Test Brand', 'brand', 'tb@example.com');

INSERT INTO core.professionals (id, handle, handle_lc, display_name, professional_type, primary_email)
VALUES ('00000000-0000-0000-0000-000000000002', 'testaff', 'testaff', 'Test Aff', 'influencer', 'ta@example.com');

-- Create their site rows (triggers fire, user_url populates)
INSERT INTO site.sites (professional_id, subdomain) VALUES ('00000000-0000-0000-0000-000000000001', 'testbrand');
INSERT INTO site.sites (professional_id, subdomain) VALUES ('00000000-0000-0000-0000-000000000002', 'testaff');

-- Verify URLs
SELECT id, handle, user_url FROM core.professionals WHERE handle IN ('testbrand', 'testaff');
-- Expected: testbrand → https://testbrand.partna.au, testaff → https://testaff.partna.au

-- Connect them
INSERT INTO brand.brand_partner_links (affiliate_professional_id, brand_professional_id, slot)
VALUES ('00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', 0);

-- Verify per-brand URL
SELECT brand_affiliate_url FROM brand.brand_partner_links WHERE slot = 0;
-- Expected: https://testbrand.partna.au/testaff

-- Rename brand
UPDATE core.professionals SET handle = 'newbrand', handle_lc = 'newbrand' WHERE id = '00000000-0000-0000-0000-000000000001';
UPDATE site.sites SET subdomain = 'newbrand' WHERE professional_id = '00000000-0000-0000-0000-000000000001';

-- Verify cascade
SELECT user_url FROM core.professionals WHERE id = '00000000-0000-0000-0000-000000000001';
-- Expected: https://newbrand.partna.au

SELECT brand_affiliate_url FROM brand.brand_partner_links WHERE slot = 0;
-- Expected: https://newbrand.partna.au/testaff (cascaded)

SELECT * FROM site.professional_handle_aliases WHERE professional_id = '00000000-0000-0000-0000-000000000001';
-- Expected: one row with handle='testbrand'

-- Cleanup
DELETE FROM core.professionals WHERE id IN ('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002');
```

- [ ] **Step 4: Open a PR — Josh handles this**

Per project workflow: Josh runs the final commit chain and opens the PR. Plan handoff is complete here.

---

## Notes on testing strategy

- **Resource and validation tests run on SQLite in-memory** (the project default per `tests/TestCase.php`). They mock the trigger's effect by setting `user_url` / `brand_affiliate_url` explicitly via `setRawAttributes` or factory state.
- **Trigger correctness tests must run against real Postgres.** Pest 4 supports `->group('pgsql')`. Mark trigger tests so CI can opt into them when a Supabase test connection is configured (`DB_CONNECTION=pgsql` plus appropriate connection vars). For initial implementation, manual smoke testing per Task 19 Step 3 is sufficient — automated trigger tests are a follow-up.
- **`composer test` enforces no Laravel migrations** via the `guard:no-laravel-migrations` composer hook (per CLAUDE.md). All schema changes in this plan are raw SQL under `supabase/migrations/` ✓.

## Out of scope (explicitly deferred)

- Custom domain alias table (`brand.custom_domain_aliases`) — defer until a brand actually changes their custom domain.
- QR scan attribution — if needed, encode `?utm_source=qr` in the QR target (frontend SVG generation change).
- Affiliate handle-rename HTTP endpoint — `UpdateProfessionalRequest.php:19` keeps its `// keep handle out of this endpoint` comment as a follow-up signal.
- Frontend code changes — separate PR after backend ships.
