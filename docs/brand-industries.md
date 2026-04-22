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
A brand can legitimately span multiple industries (a haircare line that also does skincare). The column is `jsonb NOT NULL DEFAULT '[]'`. Do not split into a separate join table — the cost/benefit does not justify it at current scale.

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

### `Professional::effectiveIndustries(): array<int, string>`

- **Brand**: returns `brandProfile.industries`.
- **Affiliate** (professional or influencer): returns the primary (slot=0) connected brand's industries.
- **No brand connection** or no profile: returns `[]`.
- Filters out empty strings and non-string entries defensively.

### `Professional::primaryIndustry(): ?string`

- Returns `effectiveIndustries()[0]` or null.

### `BrandProfile::primaryIndustry(): ?string`

- Convenience for brand-side callers that already have a `BrandProfile` loaded. Same first-non-empty-string semantics.

**Eager-loading note:** on hot paths, eager-load the affiliate chain:
```php
$pro->load('primaryBrandPartnerLink.brandProfessional.brandProfile');
```
Otherwise three lazy queries fire per `effectiveIndustries()` call on an affiliate.

---

## 7. Multi-brand affiliate case (deferred)

V2 uses a single-brand-per-affiliate model (`slot = 0`). If multi-brand support lands later, `effectiveIndustries()` currently returns *only* slot 0's brand industries — it does not union across brands.

When that changes, decisions to make:

- **Union vs. primary-only.** Return all distinct industries across all connected brands, or stick with primary slot?
- **Order.** If union, by connection recency? By slot order? By affiliate preference?
- **Primary-industry tie-break.** `primaryIndustry()` is called from template-selection code — it must return a single value deterministically.

Do not change this method silently. Update this doc and add new tests.

---

## 8. Data reset (one-time migration)

`supabase/migrations/20260422030000_reset_brand_profiles_industries.sql` clears the existing free-form `industries` values on all brand profiles and flips `setup_complete` back to false. Pre-beta, no real customers — brands re-pick from the new enum on next dashboard visit.

Idempotent (`WHERE jsonb_array_length(industries) > 0`), safe to re-run.

---

## 9. Implementation pointers

| Concern | File |
|---------|------|
| Enum definition | [config/sidest.php](../config/sidest.php) — `brand_industries` key |
| Validation | [`BrandProfileController::update`](../app/Http/Controllers/Api/Professional/BrandProfileController.php) |
| Setup gate | [`BrandSetupController`](../app/Http/Controllers/Api/Professional/BrandSetupController.php) — requires non-empty `industries` for `setup_complete` |
| Derive-on-read | [`Professional::effectiveIndustries`](../app/Models/Core/Professional/Professional.php) |
| Brand-side helper | [`BrandProfile::primaryIndustry`](../app/Models/Core/Professional/BrandProfile.php) |
| Connection model | [`BrandPartnerLink`](../app/Models/Core/Professional/BrandPartnerLink.php) — slot 0 is primary |
| Reset migration | [supabase/migrations/20260422030000_reset_brand_profiles_industries.sql](../supabase/migrations/20260422030000_reset_brand_profiles_industries.sql) |
| Validation tests | [tests/Feature/Brand/BrandProfileIndustriesValidationTest.php](../tests/Feature/Brand/BrandProfileIndustriesValidationTest.php) |
| Derive tests | [tests/Unit/Professional/EffectiveIndustriesTest.php](../tests/Unit/Professional/EffectiveIndustriesTest.php) |
