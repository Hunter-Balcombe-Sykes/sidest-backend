<?php

uses(\Tests\TestCase::class);

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use App\Services\Professional\Brand\BrandPartnerSiteSettingsSync;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();
});

it('sets primary brand and additional brands in site settings from links', function () {
    $site = new Site(['settings' => []]);
    $site->setRawAttributes(['id' => (string) Str::uuid(), 'settings' => []], true);

    $links = collect([
        (new BrandPartnerLink(['brand_professional_id' => 'brand-A', 'slot' => 0])),
        (new BrandPartnerLink(['brand_professional_id' => 'brand-B', 'slot' => 1])),
    ]);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('getLinksForAffiliate')->andReturn($links);

    $cache = Mockery::mock(ProfessionalCacheService::class);

    $sync = new BrandPartnerSiteSettingsSync($linkService, $cache);

    // Don't actually save the site — just inspect the mutated settings.
    $sync->syncWithoutPersist($site, 'affiliate-id');

    expect($site->settings['brand_partner']['professional_id'])->toBe('brand-A');
    expect($site->settings['additional_brand_partners'])->toHaveCount(1);
    expect($site->settings['additional_brand_partners'][0]['professional_id'])->toBe('brand-B');
});

it('detects when site settings still reference a brand', function () {
    $site = new Site(['settings' => [
        'brand_partner' => ['professional_id' => 'brand-X'],
        'additional_brand_partners' => [],
    ]]);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $cache = Mockery::mock(ProfessionalCacheService::class);

    $sync = new BrandPartnerSiteSettingsSync($linkService, $cache);

    expect($sync->settingsStillReferenceBrand($site, 'brand-X'))->toBeTrue();
    expect($sync->settingsStillReferenceBrand($site, 'brand-Y'))->toBeFalse();
});
