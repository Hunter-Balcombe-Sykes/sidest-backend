<?php

uses(Tests\TestCase::class);

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\BrandPartnerLinkAuditor;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\BrandPartnerLinkNotifier;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\BrandPartnerSiteSettingsSync;
use App\Services\Store\SelectionCleanupService;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Str;
use RuntimeException;

it('createForStaff rejects if brand is not type=brand', function () {
    $brand = (new Professional())->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional', 'status' => 'active']);
    $affiliate = (new Professional())->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional', 'status' => 'active']);

    $svc = new BrandPartnerLinkLifecycleService(
        Mockery::mock(BrandPartnerLinkService::class),
        Mockery::mock(SelectionCleanupService::class),
        Mockery::mock(CommissionVoidService::class),
        Mockery::mock(BrandPartnerLinkNotifier::class),
        Mockery::mock(BrandPartnerLinkAuditor::class),
        Mockery::mock(BrandPartnerSiteSettingsSync::class),
    );

    expect(fn () => $svc->createForStaff($brand, $affiliate, 'reason here', (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'Target brand is not a brand account');
});

it('createForStaff rejects if affiliate is type=brand', function () {
    $brand = (new Professional())->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'brand', 'status' => 'active']);
    $affiliate = (new Professional())->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'brand', 'status' => 'active']);

    $svc = new BrandPartnerLinkLifecycleService(
        Mockery::mock(BrandPartnerLinkService::class),
        Mockery::mock(SelectionCleanupService::class),
        Mockery::mock(CommissionVoidService::class),
        Mockery::mock(BrandPartnerLinkNotifier::class),
        Mockery::mock(BrandPartnerLinkAuditor::class),
        Mockery::mock(BrandPartnerSiteSettingsSync::class),
    );

    expect(fn () => $svc->createForStaff($brand, $affiliate, 'reason here', (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'Cannot link two brand accounts');
});

it('createForStaff happy path inserts link, audit row, and syncs site settings', function () {
    setupSitesTable();

    $brand = (new Professional())->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'brand', 'status' => 'active']);
    $affiliate = (new Professional())->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional', 'status' => 'active']);

    // Insert a site so the sync path is exercised.
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $affiliate->id,
        'settings' => '{}',
    ]);
    $staffId = (string) Str::uuid();
    $reason = 'Manual recovery for lost invite';

    $link = new BrandPartnerLink([
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $brand->id,
        'slot' => 0,
    ]);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('connectBrandToAffiliate')
        ->once()
        ->with($affiliate->id, $brand->id)
        ->andReturn($link);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordCreation')
        ->once()
        ->with($brand->id, $affiliate->id, $staffId, 0, $reason);

    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldReceive('sync')->once();
    $sync->shouldReceive('invalidateAffiliateCaches')->once();

    $svc = new BrandPartnerLinkLifecycleService(
        $linkService,
        Mockery::mock(SelectionCleanupService::class),
        Mockery::mock(CommissionVoidService::class),
        Mockery::mock(BrandPartnerLinkNotifier::class),
        $auditor,
        $sync,
    );

    $result = $svc->createForStaff($brand, $affiliate, $reason, $staffId);

    expect($result->slot)->toBe(0);
    expect($result->brand_professional_id)->toBe($brand->id);
});
