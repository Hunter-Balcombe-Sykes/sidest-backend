<?php

uses(Tests\TestCase::class);

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Professional\BrandPartnerLinkAuditor;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\BrandPartnerLinkNotifier;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\BrandPartnerSiteSettingsSync;
use App\Services\Store\SelectionCleanupService;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('createForStaff rejects if brand is not type=brand', function () {
    $brand = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional', 'status' => 'active']);
    $affiliate = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional', 'status' => 'active']);

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
    $brand = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'brand', 'status' => 'active']);
    $affiliate = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'brand', 'status' => 'active']);

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

    $brand = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'brand', 'status' => 'active']);
    $affiliate = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional', 'status' => 'active']);

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

it('disconnect (keep) severs link without voiding pending commissions', function () {
    setupSitesTable();

    $brand = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $affiliate->id,
        'settings' => '{}',
    ]);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('disconnectBrandFromAffiliate')
        ->once()
        ->with($affiliate->id, $brand->id)
        ->andReturn(true);
    $linkService->shouldReceive('getLinkForPair')
        ->once()
        ->andReturn(new \App\Models\Core\Professional\BrandPartnerLink([
            'affiliate_professional_id' => $affiliate->id,
            'brand_professional_id' => $brand->id,
            'slot' => 1,
        ]));

    $commissionVoid = Mockery::mock(CommissionVoidService::class);
    $commissionVoid->shouldReceive('pendingSummaryForAffiliateBrand')
        ->once()
        ->andReturn(['count' => 3, 'total_cents' => 4500]);
    $commissionVoid->shouldNotReceive('voidPendingForAffiliateBrand');

    $selections = Mockery::mock(SelectionCleanupService::class);
    $selections->shouldReceive('removeSelectionsForAffiliateBrand')->once()->andReturn(4);

    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldReceive('sync')->once();
    $sync->shouldReceive('invalidateAffiliateCaches')->once();
    $sync->shouldReceive('settingsStillReferenceBrand')->andReturn(false);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordRemoval')->once();

    $notifier = Mockery::mock(BrandPartnerLinkNotifier::class);
    $notifier->shouldReceive('notifyAffiliateOfRemoval')->once();
    $notifier->shouldNotReceive('notifyBrandOfRemoval');

    $svc = new BrandPartnerLinkLifecycleService(
        $linkService, $selections, $commissionVoid, $notifier, $auditor, $sync,
    );

    $req = \App\Services\Professional\DTO\DisconnectRequest::forBrand($brand, $affiliate, 'not a fit');
    $result = $svc->disconnect($req);

    expect($result->disconnected)->toBeTrue();
    expect($result->voidedCommissionCount)->toBe(0);
    expect($result->voidedCommissionCents)->toBe(0);
    expect($result->selectionsRemoved)->toBe(4);
});

it('disconnect (staff void, under cap) voids pending commissions inline', function () {
    setupSitesTable();

    $brand = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staffId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $affiliate->id,
        'settings' => '{}',
    ]);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('disconnectBrandFromAffiliate')->once()->andReturn(true);
    $linkService->shouldReceive('getLinkForPair')->once()->andReturn(new \App\Models\Core\Professional\BrandPartnerLink(['slot' => 0]));

    $commissionVoid = Mockery::mock(CommissionVoidService::class);
    $commissionVoid->shouldReceive('pendingSummaryForAffiliateBrand')->andReturn(['count' => 10, 'total_cents' => 1500]);
    $commissionVoid->shouldReceive('voidPendingForAffiliateBrand')
        ->once()
        ->andReturn(['count' => 10, 'total_cents' => 1500, 'overflow' => false]);

    $selections = Mockery::mock(SelectionCleanupService::class);
    $selections->shouldReceive('removeSelectionsForAffiliateBrand')->once()->andReturn(0);

    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldReceive('sync')->once();
    $sync->shouldReceive('invalidateAffiliateCaches')->once();
    $sync->shouldReceive('settingsStillReferenceBrand')->andReturn(false);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordRemoval')->once();

    $notifier = Mockery::mock(BrandPartnerLinkNotifier::class);
    $notifier->shouldReceive('notifyAffiliateOfRemoval')->once()->with(Mockery::type(Professional::class), Mockery::type(Professional::class), 1500);
    $notifier->shouldReceive('notifyBrandOfRemoval')->once();

    $svc = new BrandPartnerLinkLifecycleService(
        $linkService, $selections, $commissionVoid, $notifier, $auditor, $sync,
    );

    $req = \App\Services\Professional\DTO\DisconnectRequest::forStaff(
        $brand, $affiliate, 'staff voiding pending commissions for migration',
        \App\Services\Professional\Enums\CommissionHandling::Void,
        $staffId,
    );

    $result = $svc->disconnect($req);

    expect($result->voidedCommissionCount)->toBe(10);
    expect($result->voidedCommissionCents)->toBe(1500);
    expect($result->voidedAsync)->toBeFalse();
});

it('disconnect (staff void, over cap) returns voidedAsync=true and does not void inline', function () {
    setupSitesTable();
    Queue::fake();

    $brand = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staffId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $affiliate->id,
        'settings' => '{}',
    ]);

    $linkService = Mockery::mock(BrandPartnerLinkService::class);
    $linkService->shouldReceive('disconnectBrandFromAffiliate')->once()->andReturn(true);
    $linkService->shouldReceive('getLinkForPair')->once()->andReturn(new \App\Models\Core\Professional\BrandPartnerLink(['slot' => 2]));

    $commissionVoid = Mockery::mock(CommissionVoidService::class);
    $commissionVoid->shouldReceive('pendingSummaryForAffiliateBrand')->andReturn(['count' => 500, 'total_cents' => 75000]);
    $commissionVoid->shouldReceive('voidPendingForAffiliateBrand')
        ->once()
        ->andReturn(['count' => 0, 'total_cents' => 0, 'overflow' => true]);

    $selections = Mockery::mock(SelectionCleanupService::class);
    $selections->shouldReceive('removeSelectionsForAffiliateBrand')->once()->andReturn(0);

    $sync = Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $sync->shouldReceive('sync')->once();
    $sync->shouldReceive('invalidateAffiliateCaches')->once();
    $sync->shouldReceive('settingsStillReferenceBrand')->andReturn(false);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordRemoval')->once();

    $notifier = Mockery::mock(BrandPartnerLinkNotifier::class);
    // On overflow, immediate notifications are skipped — the async job sends them.
    $notifier->shouldNotReceive('notifyAffiliateOfRemoval');
    $notifier->shouldNotReceive('notifyBrandOfRemoval');

    $svc = new BrandPartnerLinkLifecycleService(
        $linkService, $selections, $commissionVoid, $notifier, $auditor, $sync,
    );

    $req = \App\Services\Professional\DTO\DisconnectRequest::forStaff(
        $brand, $affiliate, 'bulk void for brand closure',
        \App\Services\Professional\Enums\CommissionHandling::Void,
        $staffId,
    );

    $result = $svc->disconnect($req);

    expect($result->voidedAsync)->toBeTrue();
    expect($result->pendingCommissionCount)->toBe(500);
    expect($result->pendingCommissionCents)->toBe(75000);
});
