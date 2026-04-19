<?php

/** @phpstan-ignore-all */

use App\Jobs\Stripe\VoidPendingCommissionsForLinkJob;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandPartnerLinkAuditor;
use App\Services\Professional\BrandPartnerLinkNotifier;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Str;

it('runs the void loop, writes audit completion row, and notifies both parties', function () {
    $affiliate = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'display_name' => 'Affi']);
    $brand = (new Professional)->forceFill(['id' => (string) Str::uuid(), 'display_name' => 'Brand']);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $voidService->shouldReceive('runVoidLoop')
        ->once()
        ->with($affiliate->id, $brand->id, Mockery::on(fn ($r) => str_contains($r, 'link_removed_by_staff')))
        ->andReturn(['count' => 42, 'total_cents' => 12600, 'overflow' => false]);

    $auditor = Mockery::mock(BrandPartnerLinkAuditor::class);
    $auditor->shouldReceive('recordAsyncVoidCompletion')
        ->once()
        ->with($brand->id, $affiliate->id, 42, 12600, Mockery::type('string'));

    $notifier = Mockery::mock(BrandPartnerLinkNotifier::class);
    $notifier->shouldReceive('notifyAffiliateOfRemoval')->once()->with(Mockery::type(Professional::class), Mockery::type(Professional::class), 12600);
    $notifier->shouldReceive('notifyBrandOfRemoval')->once()->with(Mockery::type(Professional::class), Mockery::type(Professional::class));

    // Class-based partial mock so $this inside handle() refers to the mock,
    // allowing loadProfessionals() to be intercepted without hitting the DB.
    $jobPartial = Mockery::mock(VoidPendingCommissionsForLinkJob::class, [
        $affiliate->id,
        $brand->id,
        'link_removed_by_staff: closing account',
    ])->makePartial();

    $jobPartial->shouldReceive('loadProfessionals')
        ->once()
        ->andReturn([$affiliate, $brand]);

    $jobPartial->handle($voidService, $auditor, $notifier);
});
