<?php

use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('Square webhook returns 200 and does not dispatch sync when flag is off', function () {
    config()->set('sidest.features.square_sync', false);

    $this->postJson('/api/webhooks/square', [
        'event_id' => 'test-evt-1',
        'type' => 'catalog.version.updated',
        'merchant_id' => 'MERCHANT_ABC',
    ])->assertStatus(200)
      ->assertJson(['received' => true, 'feature_gated' => true]);

    Queue::assertNotPushed(SyncSquareCatalogDeltaJob::class);
});

it('Fresha webhook returns 200 and does not dispatch sync when flag is off', function () {
    config()->set('sidest.features.fresha_sync', false);

    $this->postJson('/api/webhooks/fresha', [
        'event_id' => 'test-evt-2',
        'type' => 'catalog.version.updated',
        'merchant_id' => 'MERCHANT_XYZ',
    ])->assertStatus(200)
      ->assertJson(['received' => true, 'feature_gated' => true]);

    Queue::assertNotPushed(SyncFreshaCatalogDeltaJob::class);
});
