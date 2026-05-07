<?php

use App\Jobs\Fresha\PushServiceToFreshaJob;
use App\Jobs\Square\PushServiceToSquareJob;
use App\Services\Fresha\FreshaServiceSyncService;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.services (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        name TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    config([
        'partna.features.square_sync' => true,
        'partna.features.fresha_sync' => true,
    ]);
});

it('does not call SquareServiceSyncService when service is soft-deleted', function () {
    $serviceId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => $serviceId,
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $syncService = Mockery::mock(SquareServiceSyncService::class);
    $syncService->shouldNotReceive('pushServiceToSquare');

    $job = new PushServiceToSquareJob($serviceId, 'upsert');
    $job->handle($syncService);
});

it('does not call FreshaServiceSyncService when service is soft-deleted', function () {
    $serviceId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => $serviceId,
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $syncService = Mockery::mock(FreshaServiceSyncService::class);
    $syncService->shouldNotReceive('pushServiceToFresha');

    $job = new PushServiceToFreshaJob($serviceId, 'upsert');
    $job->handle($syncService);
});

it('calls SquareServiceSyncService when service is not deleted', function () {
    $serviceId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => $serviceId,
        'deleted_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $syncService = Mockery::mock(SquareServiceSyncService::class);
    $syncService->shouldReceive('pushServiceToSquare')->once();

    $job = new PushServiceToSquareJob($serviceId, 'upsert');
    $job->handle($syncService);
});

it('calls FreshaServiceSyncService when service is not deleted', function () {
    $serviceId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => $serviceId,
        'deleted_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $syncService = Mockery::mock(FreshaServiceSyncService::class);
    $syncService->shouldReceive('pushServiceToFresha')->once();

    $job = new PushServiceToFreshaJob($serviceId, 'upsert');
    $job->handle($syncService);
});
