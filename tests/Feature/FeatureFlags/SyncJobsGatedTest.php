<?php

use App\Jobs\Fresha\PushServiceToFreshaJob;
use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
use App\Jobs\Square\PushServiceToSquareJob;
use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use App\Services\Fresha\FreshaServiceSyncService;
use App\Services\Square\SquareServiceSyncService;
use Mockery\MockInterface;

it('PushServiceToSquareJob::handle does nothing when square_sync flag is off', function () {
    config()->set('sidest.features.square_sync', false);

    $syncService = $this->mock(SquareServiceSyncService::class, function (MockInterface $m) {
        $m->shouldNotReceive('pushServiceToSquare');
    });

    (new PushServiceToSquareJob('any-service-id'))->handle($syncService);
});

it('SyncSquareCatalogDeltaJob::handle does nothing when square_sync flag is off', function () {
    config()->set('sidest.features.square_sync', false);

    $syncService = $this->mock(SquareServiceSyncService::class, function (MockInterface $m) {
        $m->shouldNotReceive('syncFromSquare');
    });

    (new SyncSquareCatalogDeltaJob('merchant-1'))->handle($syncService);
});

it('PushServiceToFreshaJob::handle does nothing when fresha_sync flag is off', function () {
    config()->set('sidest.features.fresha_sync', false);

    $syncService = $this->mock(FreshaServiceSyncService::class, function (MockInterface $m) {
        $m->shouldNotReceive('pushServiceToFresha');
    });

    (new PushServiceToFreshaJob('any-service-id'))->handle($syncService);
});

it('SyncFreshaCatalogDeltaJob::handle does nothing when fresha_sync flag is off', function () {
    config()->set('sidest.features.fresha_sync', false);

    $syncService = $this->mock(FreshaServiceSyncService::class, function (MockInterface $m) {
        $m->shouldNotReceive('syncFromFresha');
    });

    (new SyncFreshaCatalogDeltaJob('acct-1'))->handle($syncService);
});
