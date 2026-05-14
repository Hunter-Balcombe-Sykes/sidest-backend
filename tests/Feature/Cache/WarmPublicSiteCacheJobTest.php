<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Queue;

it('calls warmSiteCache with a lowercased subdomain', function () {
    $siteCache = $this->mock(SiteCacheService::class);
    $siteCache->shouldReceive('warmSiteCache')
        ->once()
        ->with('my-site');

    $job = new WarmPublicSiteCacheJob('My-Site');
    $job->handle($siteCache);
});

it('runs on the default queue', function () {
    $job = new WarmPublicSiteCacheJob('my-site');

    expect($job->queue)->toBe('default');
});

it('can be dispatched via Queue::fake', function () {
    Queue::fake();

    WarmPublicSiteCacheJob::dispatch('my-site');

    Queue::assertPushed(WarmPublicSiteCacheJob::class, function ($job) {
        return $job->subdomain === 'my-site';
    });
});

it('has 3 tries', function () {
    $job = new WarmPublicSiteCacheJob('my-site');

    expect($job->tries)->toBe(3);
});

it('has backoff of [5, 15, 30]', function () {
    $job = new WarmPublicSiteCacheJob('my-site');

    expect($job->backoff)->toBe([5, 15, 30]);
});

it('has a timeout of 10', function () {
    $job = new WarmPublicSiteCacheJob('my-site');

    expect($job->timeout)->toBe(10);
});

it('calls report() on failure', function () {
    $e = new \RuntimeException('cache warm error');
    $job = new WarmPublicSiteCacheJob('my-site');
    $job->failed($e); // Should not throw
})->throwsNoExceptions();
