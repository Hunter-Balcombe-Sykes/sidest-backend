# Brand Industries Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current free-form `industries` string bag on `brand.brand_profiles` with a controlled 13-value taxonomy, and expose a derive-on-read `effectiveIndustries()` on the `Professional` model so affiliates inherit their connected brand's industries without denormalization.

**Architecture:** Three locked decisions shape every task:
1. **Keep the existing jsonb array column.** No schema change — a brand can be in multiple industries.
2. **First-is-primary convention.** `industries[0]` is the primary industry; order is semantic. The frontend preserves user-specified order; the backend does not re-sort.
3. **Derive-on-read for affiliates.** Affiliates have no `industries` column of their own. The `Professional::effectiveIndustries()` method walks the `brand_partner_links` relationship (slot 0 = primary brand in V2) and returns that brand's industries. No stale state, no cascade jobs.

Industries are metadata today. The eventual "drive sections/themes per industry" work is **explicitly out of scope** — the derive method returns an array (not a scalar) so later tie-breaking logic has somewhere to live without another refactor.

**Tech Stack:** Laravel 12, PHP 8.2, Pest 4 (tests), Supabase/PostgreSQL (raw SQL migrations in `supabase/migrations/`).

---

## File Structure

**Create:**
- `supabase/migrations/20260422030000_reset_brand_profiles_industries.sql` — one-time hard-reset of existing free-form values (safe: pre-beta, no customers)
- `docs/brand-industries.md` — canonical doc following `social-links.md` style
- `tests/Feature/Brand/BrandProfileIndustriesValidationTest.php` — validation regression tests
- `tests/Unit/Professional/EffectiveIndustriesTest.php` — derive-on-read tests

**Modify:**
- `config/sidest.php` — add `brand_industries` key
- `app/Http/Controllers/Api/Professional/BrandProfileController.php` — tighten validation against the enum
- `app/Models/Core/Professional/Professional.php` — add `brandPartnerLinks()` + `primaryBrandPartnerLink()` relationships and `effectiveIndustries()` / `primaryIndustry()` methods
- `app/Models/Core/Professional/BrandProfile.php` — add `primaryIndustry()` helper for brand convenience

---

## Task 1: Config — the canonical industry list

**Files:**
- Modify: `config/sidest.php` (after the `waitlist` block, ~line 427)

- [ ] **Step 1: Add the `brand_industries` config key**

Open `config/sidest.php` and insert this block immediately after the closing `],` of the `waitlist` array (around line 427, before `account_type_defaults`):

```php
    /*
    |----------------------------------------------------------------------
    | Brand industries – canonical taxonomy
    |----------------------------------------------------------------------
    | Controlled list of industries a brand may declare on its BrandProfile.
    | Order here is NOT semantic (display order is a frontend concern).
    | Keys are slugs used in storage + API; values are human display names.
    |
    | Additive-only: renaming a slug requires a data migration. Adding a new
    | slug is safe. See docs/brand-industries.md for the stability contract.
    */
    'brand_industries' => [
        'apparel' => 'Apparel',
        'footwear' => 'Footwear',
        'accessories' => 'Accessories',
        'skin_care' => 'Skin Care',
        'haircare' => 'Haircare',
        'makeup' => 'Makeup',
        'fragrance' => 'Fragrance',
        'mens_grooming' => "Men's Grooming",
        'health_wellness_supplements' => 'Health, Wellness & Supplements',
        'activewear_fitness' => 'Activewear & Fitness',
        'home_living' => 'Home & Living',
        'electronics_tech' => 'Electronics & Tech',
        'other' => 'Other',
    ],
```

- [ ] **Step 2: Verify the config loads**

Run: `php artisan tinker --execute="echo json_encode(array_keys(config('sidest.brand_industries')));"`
Expected output: `["apparel","footwear","accessories","skin_care","haircare","makeup","fragrance","mens_grooming","health_wellness_supplements","activewear_fitness","home_living","electronics_tech","other"]`

- [ ] **Step 3: Commit**

```bash
git add config/sidest.php
git commit -m "feat(brand): add canonical brand_industries config taxonomy"
```

---

## Task 2: Migration — reset existing free-form values

**Why a hard reset:** pre-beta, no real customers. Any existing `industries` values are test data with free-form strings (per memory: `string, max:100` previously). Best-effort mapping adds code for no real benefit — clearing is one SQL statement and forces existing test brands to re-pick from the enum on next dashboard visit.

**Files:**
- Create: `supabase/migrations/20260422030000_reset_brand_profiles_industries.sql`

- [ ] **Step 1: Write the migration**

Create `supabase/migrations/20260422030000_reset_brand_profiles_industries.sql`:

```sql
-- Reset brand_profiles.industries before the controlled-enum validation ships.
-- Pre-beta: no real customers. Existing values are free-form strings that will
-- fail the new enum check; clearing them forces brands to re-pick via the
-- dashboard multi-select.
--
-- Idempotent: re-running sets already-empty arrays to the same empty value.
-- No data loss risk: the setup_complete flag is cleared so brands cannot stay
-- "set up" with no valid industries.

UPDATE brand.brand_profiles
   SET industries = '[]'::jsonb,
       setup_complete = false
 WHERE jsonb_array_length(industries) > 0;
```

- [ ] **Step 2: Apply via Supabase MCP**

Use the Supabase MCP `apply_migration` tool with the filename `reset_brand_profiles_industries` and the SQL content above. Confirm the migration applies cleanly on the dev branch.

- [ ] **Step 3: Verify state**

Run via `execute_sql`:
```sql
SELECT COUNT(*) AS total,
       COUNT(*) FILTER (WHERE jsonb_array_length(industries) > 0) AS with_industries,
       COUNT(*) FILTER (WHERE setup_complete) AS still_setup_complete
  FROM brand.brand_profiles;
```

Expected: `with_industries = 0`, `still_setup_complete = 0`.

- [ ] **Step 4: Commit**

```bash
git add supabase/migrations/20260422030000_reset_brand_profiles_industries.sql
git commit -m "chore(brand): reset brand_profiles.industries ahead of enum validation"
```

---

## Task 3: Tighten brand profile validation (TDD)

**Files:**
- Create: `tests/Feature/Brand/BrandProfileIndustriesValidationTest.php`
- Modify: `app/Http/Controllers/Api/Professional/BrandProfileController.php:45-46`

- [ ] **Step 1: Write failing validation tests**

Create `tests/Feature/Brand/BrandProfileIndustriesValidationTest.php`:

```php
<?php

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patchJson;

beforeEach(function () {
    $this->brand = Professional::factory()->create(['professional_type' => 'brand']);
    BrandProfile::factory()->create(['professional_id' => $this->brand->id]);
    actingAs($this->brand);
});

it('accepts a single valid industry slug', function () {
    patchJson('/api/professional/brand-profile', [
        'industries' => ['haircare'],
    ])->assertOk();

    expect($this->brand->brandProfile->fresh()->industries)->toBe(['haircare']);
});

it('accepts multiple valid industry slugs and preserves order', function () {
    patchJson('/api/professional/brand-profile', [
        'industries' => ['skin_care', 'haircare', 'fragrance'],
    ])->assertOk();

    // First-is-primary: order is semantic, do not re-sort.
    expect($this->brand->brandProfile->fresh()->industries)
        ->toBe(['skin_care', 'haircare', 'fragrance']);
});

it('rejects an unknown industry slug with 422', function () {
    patchJson('/api/professional/brand-profile', [
        'industries' => ['surfboards'],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('industries.0');
});

it('rejects a mix of valid and invalid slugs with 422', function () {
    patchJson('/api/professional/brand-profile', [
        'industries' => ['haircare', 'not_a_real_industry'],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('industries.1');
});

it('rejects more than 3 industries with 422', function () {
    patchJson('/api/professional/brand-profile', [
        'industries' => ['apparel', 'footwear', 'accessories', 'skin_care'],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('industries');
});

it('accepts an empty industries array (profile not yet setup)', function () {
    patchJson('/api/professional/brand-profile', [
        'industries' => [],
    ])->assertOk();

    expect($this->brand->brandProfile->fresh()->industries)->toBe([]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=BrandProfileIndustriesValidationTest`
Expected: all cases FAIL — the current controller accepts any string, so the 422 cases will return 200.

- [ ] **Step 3: Tighten the validation**

Edit `app/Http/Controllers/Api/Professional/BrandProfileController.php`. Locate the validator (around lines 40-50) and replace the two `industries` rules:

```php
// Replace these lines:
'industries' => ['sometimes', 'array', 'max:10'],
'industries.*' => ['string', 'max:100'],
```

with:

```php
'industries' => ['sometimes', 'array', 'max:3'],
'industries.*' => [
    'string',
    \Illuminate\Validation\Rule::in(array_keys(config('sidest.brand_industries', []))),
],
```

The `max:3` cap matches the primary/secondary/tertiary pattern discussed in planning. The `Rule::in` call reads the enum keys at request time — config cache safe.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=BrandProfileIndustriesValidationTest`
Expected: all six cases PASS.

- [ ] **Step 5: Run the full brand test suite to check for regressions**

Run: `php artisan test tests/Feature/Brand/`
Expected: no new failures. Any pre-existing failures are out of scope.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Brand/BrandProfileIndustriesValidationTest.php \
        app/Http/Controllers/Api/Professional/BrandProfileController.php
git commit -m "feat(brand): validate industries against brand_industries enum, cap at 3"
```

---

## Task 4: Brand partner link relationships on Professional (TDD)

`Professional` has no relationship to `BrandPartnerLink` yet. Task 5 needs it. Adding just the relationship here keeps each commit small.

**Files:**
- Modify: `app/Models/Core/Professional/Professional.php`

- [ ] **Step 1: Add the import**

In `app/Models/Core/Professional/Professional.php`, find the existing use block (around lines 3-17) and verify these imports exist — add any missing ones:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
```

Both should already be present. No change needed if so.

- [ ] **Step 2: Add the two relationships**

In `Professional.php`, add these two methods immediately after the existing `brandAffiliateInvites()` method (around line 188):

```php
    /**
     * All brand connections where this professional is the affiliate.
     * Empty for brand accounts (brands connect TO affiliates, not the reverse).
     */
    public function brandPartnerLinks(): HasMany
    {
        return $this->hasMany(BrandPartnerLink::class, 'affiliate_professional_id');
    }

    /**
     * The affiliate's primary brand connection (slot 0). V2 uses a single-brand
     * model, so this is effectively "the brand" for an affiliate, or null for
     * an affiliate that hasn't connected yet / a brand account.
     */
    public function primaryBrandPartnerLink(): HasOne
    {
        return $this->hasOne(BrandPartnerLink::class, 'affiliate_professional_id')
            ->where('slot', 0);
    }
```

- [ ] **Step 3: Verify the relationships resolve**

Run: `php artisan tinker --execute="App\Models\Core\Professional\Professional::query()->first()?->brandPartnerLinks()->toSql();"`
Expected output: a SQL string containing `select * from "brand"."brand_partner_links" where "brand"."brand_partner_links"."affiliate_professional_id" =` — confirms the FK wiring is correct.

- [ ] **Step 4: Commit**

```bash
git add app/Models/Core/Professional/Professional.php
git commit -m "feat(professional): add brandPartnerLinks + primaryBrandPartnerLink relationships"
```

---

## Task 5: `effectiveIndustries()` derive-on-read (TDD)

This is the core of the "affiliate inherits brand's industries" behavior.

**Files:**
- Create: `tests/Unit/Professional/EffectiveIndustriesTest.php`
- Modify: `app/Models/Core/Professional/Professional.php`
- Modify: `app/Models/Core/Professional/BrandProfile.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Professional/EffectiveIndustriesTest.php`:

```php
<?php

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;

it('returns a brand account own industries', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    BrandProfile::factory()->create([
        'professional_id' => $brand->id,
        'industries' => ['haircare', 'skin_care'],
    ]);

    expect($brand->effectiveIndustries())->toBe(['haircare', 'skin_care']);
    expect($brand->primaryIndustry())->toBe('haircare');
});

it('returns empty array for a brand with no profile', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);

    expect($brand->effectiveIndustries())->toBe([]);
    expect($brand->primaryIndustry())->toBeNull();
});

it('returns empty array for a brand with null industries', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    BrandProfile::factory()->create([
        'professional_id' => $brand->id,
        'industries' => [],
    ]);

    expect($brand->effectiveIndustries())->toBe([]);
    expect($brand->primaryIndustry())->toBeNull();
});

it('returns the connected brand industries for an affiliate at slot 0', function () {
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    BrandProfile::factory()->create([
        'professional_id' => $brand->id,
        'industries' => ['activewear_fitness'],
    ]);

    $affiliate = Professional::factory()->create(['professional_type' => 'professional']);
    BrandPartnerLink::factory()->create([
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $brand->id,
        'slot' => 0,
    ]);

    expect($affiliate->effectiveIndustries())->toBe(['activewear_fitness']);
    expect($affiliate->primaryIndustry())->toBe('activewear_fitness');
});

it('returns empty array for an affiliate with no brand connection', function () {
    $affiliate = Professional::factory()->create(['professional_type' => 'professional']);

    expect($affiliate->effectiveIndustries())->toBe([]);
    expect($affiliate->primaryIndustry())->toBeNull();
});

it('ignores non-slot-0 brand partner links for affiliate derivation', function () {
    // V2 is single-brand (slot 0 only), but the query must be explicit so
    // adding slot 1+ later does not silently change the primary-industry
    // contract.
    $primaryBrand = Professional::factory()->create(['professional_type' => 'brand']);
    BrandProfile::factory()->create([
        'professional_id' => $primaryBrand->id,
        'industries' => ['haircare'],
    ]);
    $otherBrand = Professional::factory()->create(['professional_type' => 'brand']);
    BrandProfile::factory()->create([
        'professional_id' => $otherBrand->id,
        'industries' => ['apparel'],
    ]);

    $affiliate = Professional::factory()->create(['professional_type' => 'professional']);
    BrandPartnerLink::factory()->create([
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $primaryBrand->id,
        'slot' => 0,
    ]);
    BrandPartnerLink::factory()->create([
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $otherBrand->id,
        'slot' => 1,
    ]);

    expect($affiliate->effectiveIndustries())->toBe(['haircare']);
});

it('filters non-string and empty entries from industries arrays', function () {
    // Defense against legacy data: historical free-form values could contain
    // empty strings or non-strings (via raw SQL). Derive must not expose them.
    $brand = Professional::factory()->create(['professional_type' => 'brand']);
    BrandProfile::factory()->create([
        'professional_id' => $brand->id,
        'industries' => ['haircare', '', null, 'skin_care'],
    ]);

    expect($brand->effectiveIndustries())->toBe(['haircare', 'skin_care']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Professional/EffectiveIndustriesTest.php`
Expected: all seven cases FAIL with "method effectiveIndustries does not exist" or equivalent.

- [ ] **Step 3: Add the methods to `Professional.php`**

In `app/Models/Core/Professional/Professional.php`, add these two methods immediately after `primaryBrandPartnerLink()` (added in Task 4):

```php
    /**
     * Return the industries that should drive this professional's experience.
     *
     * - Brand: its own BrandProfile.industries (primary at index 0).
     * - Affiliate (professional/influencer): the primary (slot=0) connected
     *   brand's industries. If multiple brand connections exist in future,
     *   this stays single-source for determinism — the "union across brands"
     *   decision is deferred to the eventual template-cascading work.
     *
     * Always returns a clean array of string slugs (empty arrays, empty
     * strings, and non-strings are filtered out).
     *
     * @return array<int, string>
     */
    public function effectiveIndustries(): array
    {
        $industries = $this->isBrand()
            ? ($this->brandProfile?->industries ?? [])
            : ($this->primaryBrandPartnerLink?->brandProfessional?->brandProfile?->industries ?? []);

        if (! is_array($industries)) {
            return [];
        }

        return array_values(array_filter(
            $industries,
            static fn ($value) => is_string($value) && $value !== ''
        ));
    }

    /**
     * First-is-primary convention: the first industry in the list is the
     * "primary" one. Returns null if no industries are set.
     */
    public function primaryIndustry(): ?string
    {
        return $this->effectiveIndustries()[0] ?? null;
    }
```

- [ ] **Step 4: Add a convenience helper to `BrandProfile`**

In `app/Models/Core/Professional/BrandProfile.php`, add this method after the `professional()` relationship:

```php
    /**
     * First-is-primary convention: the first entry in industries is the
     * primary. Intended for brand-side callers that already have a
     * BrandProfile loaded; affiliate code should use
     * Professional::primaryIndustry() instead.
     */
    public function primaryIndustry(): ?string
    {
        $industries = is_array($this->industries) ? $this->industries : [];

        foreach ($industries as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Professional/EffectiveIndustriesTest.php`
Expected: all seven cases PASS.

- [ ] **Step 6: Run the full test suite**

Run: `composer test`
Expected: no new failures.

- [ ] **Step 7: Commit**

```bash
git add tests/Unit/Professional/EffectiveIndustriesTest.php \
        app/Models/Core/Professional/Professional.php \
        app/Models/Core/Professional/BrandProfile.php
git commit -m "feat(professional): add effectiveIndustries + primaryIndustry derive-on-read"
```

---

## Task 6: Documentation

**Files:**
- Create: `docs/brand-industries.md`

- [ ] **Step 1: Write the canonical doc**

Create `docs/brand-industries.md` with this content:

```markdown
# Brand Industries — Conceptual Guide

> **Audience:** Backend, frontend (brand + affiliate dashboards), and anyone adding/removing supported industries.
> **Status:** Active. This is the canonical model for how brand industries are stored, validated, and propagated to affiliates.
> **See also:** [social-links.md](./social-links.md) for the analogous enum+config pattern.

---

## 1. Overview

A brand declares one to three industries it operates in (haircare, apparel, etc.). Those industries are stored on `brand.brand_profiles.industries` as a `jsonb` array of slugs drawn from a controlled list in `config('sidest.brand_industries')`.

Affiliates inherit their connected brand's industries via `Professional::effectiveIndustries()`. No denormalization — the derive method reads through `brand_partner_links` (slot 0 = primary brand) at call time.

Today industries are pure metadata. A planned future project will drive section visibility, theme defaults, and onboarding copy off industry, but none of that is wired up yet.

---

## 2. The 13 supported industries

| Slug | Display name |
|------|--------------|
| `apparel` | Apparel |
| `footwear` | Footwear |
| `accessories` | Accessories |
| `skin_care` | Skin Care |
| `haircare` | Haircare |
| `makeup` | Makeup |
| `fragrance` | Fragrance |
| `mens_grooming` | Men's Grooming |
| `health_wellness_supplements` | Health, Wellness & Supplements |
| `activewear_fitness` | Activewear & Fitness |
| `home_living` | Home & Living |
| `electronics_tech` | Electronics & Tech |
| `other` | Other |

---

## 3. Three design decisions (locked)

### 3.1 Keep the jsonb array
A brand can legitimately span multiple industries (e.g. a haircare brand that also does skincare). The column is `jsonb NOT NULL DEFAULT '[]'`. Do not split into a separate join table — the cost/benefit does not justify it at current scale.

### 3.2 First-is-primary
`industries[0]` is the primary industry. The list order is **semantic** — the backend does not re-sort. The frontend preserves user-specified order on save. When the eventual template work needs a single industry for theme/section selection, the primary is the answer.

### 3.3 Derive-on-read for affiliates
Affiliates do not have their own `industries` column. `Professional::effectiveIndustries()` walks the primary (slot 0) `brand_partner_link` and returns that brand's industries. If the brand changes industries, every affiliate picks it up on the next read — no cascade job, no staleness.

If this becomes a read hotspot (100k+ affiliates, per-request reads in the public bootstrap path), the remediation is a cached accessor or a small materialized column, not a re-architecture.

---

## 4. Adding a 14th industry

1. Add an entry to `config/sidest.php` under `brand_industries`. Slug must be `snake_case`, ASCII, stable.
2. Add a display-name translation if the app is localized (not today).
3. Update the table in §2 above.
4. Frontend picks it up on next config fetch — no deploy dependency.

**Do not rename existing slugs.** Renames require a data migration (`UPDATE brand.brand_profiles SET industries = ...`). The enum is additive-only by contract.

---

## 5. Validation

**Endpoint:** `PATCH /api/professional/brand-profile` (see `BrandProfileController::update`).

```
industries       sometimes | array | max:3
industries.*     string | in:<config-keys>
```

- **Cap of 3.** Matches the primary/secondary/tertiary pattern. Larger lists dilute the "primary" signal and mean the frontend has to decide which to surface.
- **Empty array is valid.** A brand can be partially onboarded with no industries set; `setup_complete` cannot flip to true until at least one industry is present (see `BrandSetupController`).
- **Unknown slugs return 422** on the first offending index (`industries.N`).

---

## 6. Derive-on-read API

`Professional::effectiveIndustries(): array`

- Brand: returns `brandProfile.industries`.
- Affiliate (professional or influencer): returns the primary connected brand's industries.
- No brand connection (or no profile): returns `[]`.
- Filters out empty strings and non-string entries defensively.

`Professional::primaryIndustry(): ?string`

- Returns `effectiveIndustries()[0]` or null.

`BrandProfile::primaryIndustry(): ?string`

- Convenience for brand-side callers that already have a `BrandProfile` loaded. Same first-non-empty-string semantics.

---

## 7. Multi-brand affiliate case (deferred)

V2 uses a single-brand-per-affiliate model (`slot = 0`). If multi-brand support lands later, `effectiveIndustries()` currently returns *only* slot 0's brand industries — it does not union across brands.

When that changes, the contract decisions to make:

- **Union vs. primary-only.** Return all distinct industries across all connected brands, or stick with primary slot?
- **Order.** If union, by connection recency? By slot order? By affiliate preference?
- **Primary-industry tie-break.** `primaryIndustry()` is called from template-selection code — it must return a single value deterministically.

Do not change this method silently. Update this doc and add new tests.

---

## 8. Implementation pointers

| Concern | File |
|---------|------|
| Enum definition | [config/sidest.php](../config/sidest.php) — `brand_industries` key |
| Validation | [`BrandProfileController::update`](../app/Http/Controllers/Api/Professional/BrandProfileController.php) |
| Setup gate | [`BrandSetupController`](../app/Http/Controllers/Api/Professional/BrandSetupController.php) — requires non-empty `industries` for `setup_complete` |
| Derive-on-read | [`Professional::effectiveIndustries`](../app/Models/Core/Professional/Professional.php) |
| Brand-side helper | [`BrandProfile::primaryIndustry`](../app/Models/Core/Professional/BrandProfile.php) |
| Connection model | [`BrandPartnerLink`](../app/Models/Core/Professional/BrandPartnerLink.php) — slot 0 is primary |
| Validation tests | [tests/Feature/Brand/BrandProfileIndustriesValidationTest.php](../tests/Feature/Brand/BrandProfileIndustriesValidationTest.php) |
| Derive tests | [tests/Unit/Professional/EffectiveIndustriesTest.php](../tests/Unit/Professional/EffectiveIndustriesTest.php) |
```

- [ ] **Step 2: Verify the doc renders and links resolve**

Open the file in an editor or run `php artisan tinker --execute="echo file_exists(base_path('docs/brand-industries.md')) ? 'ok' : 'missing';"`.
Expected: `ok`.

- [ ] **Step 3: Commit**

```bash
git add docs/brand-industries.md
git commit -m "docs(brand): canonical guide for brand industries taxonomy + derive-on-read"
```

---

## Task 7: Final verification

- [ ] **Step 1: Run the full test suite clean**

Run: `composer test`
Expected: all tests pass, no new failures.

- [ ] **Step 2: Confirm the setup-readiness gate still works**

The existing `BrandSetupController::completeSetup` already rejects brands with empty `industries`. No change needed, but verify manually:

```bash
php artisan tinker --execute="
\$p = App\Models\Core\Professional\Professional::factory()->create(['professional_type' => 'brand']);
App\Models\Core\Professional\BrandProfile::factory()->create([
  'professional_id' => \$p->id,
  'industries' => [],
  'legal_business_name' => 'X',
  'business_type' => 'llc',
]);
echo \$p->brandProfile->fresh()->industries === [] ? 'empty ok' : 'unexpected';
"
```

Expected: `empty ok` — then the frontend-facing `completeSetup` call would return 422 `missing_fields: [industries]` as before.

- [ ] **Step 3: Push the branch**

```bash
git push origin $(git branch --show-current)
```

---

## Out-of-scope (explicitly deferred)

These are intentionally NOT in this plan. Flag them in future PRs if touched:

1. **Frontend multiselect UI.** Tobias's area. The backend exposes the list via `config('sidest.brand_industries')` — a public endpoint serving the enum can be added later if needed (follow the `PublicConfigController::socialPlatforms` pattern from `docs/social-links.md`).
2. **Affiliate dashboard readout** ("Connected to X — Haircare"). Needs an endpoint surface decision; cleanest is adding `effective_industries` + `primary_industry` to whatever professional-bootstrap endpoint the affiliate dashboard already calls. Trivial once decided.
3. **Driving sections/themes off industry.** The eventual template-cascading work. Touches `AccountTypeDefaultsService`, `SectionVisibilityService`, theme selection. Roughly a week, separate plan.
4. **Public brand directory filter-by-industry.** Needs a new endpoint + possibly a GIN index on `industries`.
5. **Multi-brand-connection union semantics.** V2 is single-brand; revisit when/if multi-brand lands.
6. **Resource class for BrandProfile.** `BrandProfileController` currently returns the raw model — technically a CLAUDE.md violation ("never return raw Eloquent models"). Worth fixing in a separate PR; not this plan's concern.

---

## Issues and risks to watch

- **"Other" as dumping ground.** Monitor pick distribution once a few brands sign up. If >25% pick "Other," the enum has a gap — add the missing slug rather than broadening "Other."
- **Enum stability.** Every slug in `config/sidest.php` is now an API contract. Renames are breaking changes that require a migration. Additions are safe.
- **Affiliate factory coverage.** Tests assume `BrandPartnerLink::factory()` exists. If it doesn't, add a minimal factory before Task 5's tests — pattern is standard Laravel factory stub.
- **Eager loading.** `effectiveIndustries()` triggers up to three queries (primaryBrandPartnerLink → brandProfessional → brandProfile) for an affiliate. For hot paths, eager-load via `->with('primaryBrandPartnerLink.brandProfessional.brandProfile')` at the query site.
