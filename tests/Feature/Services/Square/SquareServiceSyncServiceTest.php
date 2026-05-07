<?php

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Services\Square\SquareApiClient;
use App\Services\Square\SquareServiceSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupServicesTable();
    $this->proId = (string) Str::uuid();
    // Direct assignment bypasses $fillable; 'id' is not mass-assignable on Professional.
    $this->pro = new Professional;
    $this->pro->id = $this->proId;
    $this->mock(SquareApiClient::class);
    $this->syncService = app(SquareServiceSyncService::class);
    $this->applySnapshot = (function (Professional $professional, array $squareRows, bool $fullSync) {
        $ref = new ReflectionClass($this);
        $method = $ref->getMethod('applySquareSnapshot');
        $method->setAccessible(true);

        return $method->invoke($this, $professional, $squareRows, $fullSync);
    })->bindTo($this->syncService, SquareServiceSyncService::class);
});

// Zombie service: manually deleted in Partna, still active in Square → must stay deleted.
it('does not restore a manually-deleted service during full sync', function () {
    $svcId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => $svcId,
        'professional_id' => $this->proId,
        'title' => 'Haircut',
        'square_catalog_object_id' => 'item-1',
        'square_variation_id' => 'var-1',
        'price_cents' => 5000,
        'currency_code' => 'AUD',
        'is_active' => 0,
        'sort_order' => 0,
        'deleted_origin' => null,  // manually deleted — no origin set
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Square says this service is active
    ($this->applySnapshot)($this->pro, [[
        'item_id' => 'item-1',
        'variation_id' => 'var-1',
        'item_name' => 'Haircut',
        'price_cents' => 5000,
        'currency_code' => 'AUD',
        'available_for_booking' => true,
        'deleted' => false,
    ]], true);

    $service = Service::withTrashed()->find($svcId);
    expect($service->trashed())->toBeTrue()
        ->and($service->deleted_origin)->toBeNull();
});

// Square-deleted service comes back in Square → should be restored.
it('restores a square-deleted service when Square re-sends it', function () {
    $svcId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => $svcId,
        'professional_id' => $this->proId,
        'title' => 'Wax',
        'square_catalog_object_id' => 'item-2',
        'square_variation_id' => 'var-2',
        'price_cents' => 3000,
        'currency_code' => 'AUD',
        'is_active' => 0,
        'sort_order' => 0,
        'deleted_origin' => 'square',  // was deleted by a previous sync
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    ($this->applySnapshot)($this->pro, [[
        'item_id' => 'item-2',
        'variation_id' => 'var-2',
        'item_name' => 'Wax',
        'price_cents' => 3000,
        'currency_code' => 'AUD',
        'available_for_booking' => true,
        'deleted' => false,
    ]], false);

    $service = Service::withTrashed()->find($svcId);
    expect($service->trashed())->toBeFalse();
});

// Incremental delete from Square marks deleted_origin = 'square'.
it('stamps deleted_origin=square when Square reports a service as deleted', function () {
    $svcId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => $svcId,
        'professional_id' => $this->proId,
        'title' => 'Tint',
        'square_catalog_object_id' => 'item-3',
        'square_variation_id' => 'var-3',
        'price_cents' => 2000,
        'currency_code' => 'AUD',
        'is_active' => 1,
        'sort_order' => 0,
        'deleted_origin' => null,
        'deleted_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    ($this->applySnapshot)($this->pro, [[
        'item_id' => 'item-3',
        'variation_id' => 'var-3',
        'deleted' => true,
    ]], false);

    $service = Service::withTrashed()->find($svcId);
    expect($service->trashed())->toBeTrue()
        ->and($service->deleted_origin)->toBe('square');
});

// Full sync stamps deleted_origin=square on services absent from the Square snapshot.
it('stamps deleted_origin=square when full sync removes a service missing from Square', function () {
    $keepId = (string) Str::uuid();
    $dropId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.services')->insert([
        ['id' => $keepId, 'professional_id' => $this->proId, 'title' => 'Keep', 'square_catalog_object_id' => 'item-k', 'square_variation_id' => 'var-k', 'price_cents' => 1000, 'currency_code' => 'AUD', 'is_active' => 1, 'sort_order' => 0, 'deleted_at' => null, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
        ['id' => $dropId, 'professional_id' => $this->proId, 'title' => 'Drop', 'square_catalog_object_id' => 'item-d', 'square_variation_id' => 'var-d', 'price_cents' => 2000, 'currency_code' => 'AUD', 'is_active' => 1, 'sort_order' => 1, 'deleted_at' => null, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
    ]);

    // Full sync: Square only returns 'var-k'; 'var-d' is gone from the snapshot.
    ($this->applySnapshot)($this->pro, [[
        'item_id' => 'item-k',
        'variation_id' => 'var-k',
        'item_name' => 'Keep',
        'price_cents' => 1000,
        'currency_code' => 'AUD',
        'available_for_booking' => true,
        'deleted' => false,
    ]], true);

    $dropped = Service::withTrashed()->find($dropId);
    expect($dropped->trashed())->toBeTrue()
        ->and($dropped->deleted_origin)->toBe('square');

    expect(Service::withTrashed()->find($keepId)->trashed())->toBeFalse();
});
