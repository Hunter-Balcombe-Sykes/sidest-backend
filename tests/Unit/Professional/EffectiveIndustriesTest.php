<?php

uses(Tests\TestCase::class);

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Str;

/**
 * Pure in-memory tests for Professional::effectiveIndustries() and
 * primaryIndustry(). Uses forceFill + setRelation to avoid the DB —
 * matches the forceFill pattern in BrandPartnerLinkLifecycleServiceTest.
 *
 * The derive-on-read logic is pure data traversal, so an in-memory setup
 * is sufficient. Integration-level coverage (via a real query through
 * brand_partner_links) would belong in a feature test using the existing
 * SQLite bootstrap pattern if a read regression ever arises.
 */

/**
 * Build a brand Professional with its BrandProfile pre-attached.
 */
function makeBrandProfessional(array $industries): Professional
{
    $brand = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'professional_type' => 'brand',
    ]);

    $profile = (new BrandProfile)->forceFill([
        'id' => (string) Str::uuid(),
        'professional_id' => $brand->id,
        'industries' => $industries,
    ]);

    $brand->setRelation('brandProfile', $profile);

    return $brand;
}

/**
 * Build an affiliate Professional with a primary BrandPartnerLink pointing
 * at the given brand. Passing null for the brand produces an unlinked
 * affiliate.
 */
function makeAffiliateProfessional(?Professional $brand): Professional
{
    $affiliate = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'professional_type' => 'professional',
    ]);

    if ($brand === null) {
        $affiliate->setRelation('primaryBrandPartnerLink', null);

        return $affiliate;
    }

    $link = (new BrandPartnerLink)->forceFill([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $brand->id,
        'slot' => 0,
    ]);
    $link->setRelation('brandProfessional', $brand);

    $affiliate->setRelation('primaryBrandPartnerLink', $link);

    return $affiliate;
}

// ── Brand account ───────────────────────────────────────────────────────────

it('returns a brand accounts own industries', function () {
    $brand = makeBrandProfessional(['haircare', 'skin_care']);

    expect($brand->effectiveIndustries())->toBe(['haircare', 'skin_care']);
    expect($brand->primaryIndustry())->toBe('haircare');
});

it('returns empty for a brand with no profile relation loaded', function () {
    $brand = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'professional_type' => 'brand',
    ]);
    $brand->setRelation('brandProfile', null);

    expect($brand->effectiveIndustries())->toBe([]);
    expect($brand->primaryIndustry())->toBeNull();
});

it('returns empty for a brand with an empty industries array', function () {
    $brand = makeBrandProfessional([]);

    expect($brand->effectiveIndustries())->toBe([]);
    expect($brand->primaryIndustry())->toBeNull();
});

// ── Affiliate account ───────────────────────────────────────────────────────

it('inherits industries from the primary connected brand', function () {
    $brand = makeBrandProfessional(['activewear_fitness']);
    $affiliate = makeAffiliateProfessional($brand);

    expect($affiliate->effectiveIndustries())->toBe(['activewear_fitness']);
    expect($affiliate->primaryIndustry())->toBe('activewear_fitness');
});

it('returns empty for an affiliate with no brand connection', function () {
    $affiliate = makeAffiliateProfessional(null);

    expect($affiliate->effectiveIndustries())->toBe([]);
    expect($affiliate->primaryIndustry())->toBeNull();
});

it('preserves first-is-primary ordering through the inheritance chain', function () {
    $brand = makeBrandProfessional(['skin_care', 'haircare', 'fragrance']);
    $affiliate = makeAffiliateProfessional($brand);

    expect($affiliate->effectiveIndustries())->toBe(['skin_care', 'haircare', 'fragrance']);
    expect($affiliate->primaryIndustry())->toBe('skin_care');
});

// ── Defensive data cleaning ────────────────────────────────────────────────

it('filters non-string and empty entries from industries', function () {
    // Legacy free-form data could contain empty strings or non-strings.
    // The derive method must not leak them through to callers.
    $brand = makeBrandProfessional(['haircare', '', null, 'skin_care', 0]);

    expect($brand->effectiveIndustries())->toBe(['haircare', 'skin_care']);
    expect($brand->primaryIndustry())->toBe('haircare');
});

it('handles non-array industries defensively', function () {
    $brand = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'professional_type' => 'brand',
    ]);
    // Simulate a corrupt-shape industries value (shouldnt happen post-validation,
    // but derive must not blow up on it).
    $profile = (new BrandProfile)->forceFill([
        'id' => (string) Str::uuid(),
        'professional_id' => $brand->id,
    ]);
    $profile->setRawAttributes(['industries' => 'not-an-array']);
    $brand->setRelation('brandProfile', $profile);

    expect($brand->effectiveIndustries())->toBe([]);
    expect($brand->primaryIndustry())->toBeNull();
});

// ── BrandProfile::primaryIndustry() convenience helper ─────────────────────

it('BrandProfile primaryIndustry returns the first non-empty string', function () {
    $profile = (new BrandProfile)->forceFill([
        'industries' => ['', null, 'haircare', 'skin_care'],
    ]);

    expect($profile->primaryIndustry())->toBe('haircare');
});

it('BrandProfile primaryIndustry returns null on empty or missing', function () {
    expect((new BrandProfile)->forceFill(['industries' => []])->primaryIndustry())->toBeNull();
    expect((new BrandProfile)->primaryIndustry())->toBeNull();
});
