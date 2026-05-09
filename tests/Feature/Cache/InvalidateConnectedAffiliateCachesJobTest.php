<?php

use App\Jobs\Cache\InvalidateConnectedAffiliateCachesJob;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('deletes the public payload cache key and its stale twin for the given subdomain', function () {
    $subdomain = 'affiliate-one';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    Cache::shouldReceive('deleteMultiple')
        ->once()
        ->with([$key, $key.':stale']);

    $job = new InvalidateConnectedAffiliateCachesJob($subdomain);
    $job->handle();
});

it('runs on the default queue', function () {
    $job = new InvalidateConnectedAffiliateCachesJob('some-affiliate');

    expect($job->queue)->toBe('default');
});

it('can be dispatched with a delay via Queue::fake', function () {
    Queue::fake();

    InvalidateConnectedAffiliateCachesJob::dispatch('delayed-affiliate')
        ->delay(now()->addSeconds(15));

    Queue::assertPushed(InvalidateConnectedAffiliateCachesJob::class, function ($job) {
        return $job->subdomain === 'delayed-affiliate';
    });
});
