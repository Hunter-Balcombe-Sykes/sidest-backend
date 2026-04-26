<?php

/** @phpstan-ignore-all */

use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::flushdb());

it('returns is_live=true for a live streaming link block on the public profile', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    Redis::set('streaming:live:twitch:shroud', '1', 'EX', 180);

    $payload = [
        'links' => [[
            'block_group' => 'links',
            'settings' => [
                'platform' => 'twitch',
                'handle' => 'shroud',
                'live_check_enabled' => true,
            ],
        ]],
        'blocks' => [],
    ];

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('getPublicSitePayload')
        ->with('testsite')
        ->andReturn($payload);

    $this->app->instance(SiteCacheService::class, $cache);

    $response = $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => 'testsite',
    ]);

    $response->assertOk();
    $response->assertJsonPath('links.0.settings.is_live', true);
});

it('returns is_live=false when the handle is not in Redis', function () {
    config(['sidest.streaming_platforms' => ['twitch', 'kick']]);
    // No Redis key for this handle

    $payload = [
        'links' => [[
            'block_group' => 'links',
            'settings' => [
                'platform' => 'twitch',
                'handle' => 'offlineuser',
                'live_check_enabled' => true,
            ],
        ]],
        'blocks' => [],
    ];

    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('getPublicSitePayload')
        ->with('testsite')
        ->andReturn($payload);
    $this->app->instance(SiteCacheService::class, $cache);

    $response = $this->getJson('/api/public/site-by-slug', [
        'X-Site-Subdomain' => 'testsite',
    ]);

    $response->assertOk();
    $response->assertJsonPath('links.0.settings.is_live', false);
});
