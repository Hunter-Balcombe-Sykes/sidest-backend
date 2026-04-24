<?php

/** @phpstan-ignore-all */

use App\Exceptions\Streaming\KickRateLimitException;
use App\Services\Streaming\KickApiClient;
use App\Services\Streaming\LiveStatusPoller;
use App\Services\Streaming\TwitchApiClient;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(fn () => Redis::flushdb());

it('writes live=1 to Redis for a Twitch handle that is live', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);
    $twitch->shouldReceive('getLiveHandles')
        ->with(['shroud'])
        ->andReturn(['shroud']);

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['shroud']);

    expect(Redis::get('streaming:live:twitch:shroud'))->toBe('1');
});

it('writes live=0 to Redis for a Twitch handle that is offline', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);
    $twitch->shouldReceive('getLiveHandles')
        ->with(['offlineuser'])
        ->andReturn([]);

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['offlineuser']);

    expect(Redis::get('streaming:live:twitch:offlineuser'))->toBe('0');
});

it('deduplicates handles before calling Twitch API', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);
    // Should only be called once with unique handles, not twice
    $twitch->shouldReceive('getLiveHandles')
        ->once()
        ->with(['shroud'])
        ->andReturn(['shroud']);

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['shroud', 'shroud']);
});

it('skips a Twitch handle whose Redis key is still fresh (TTL > 60s)', function () {
    Redis::set('streaming:live:twitch:freshuser', '1', 'EX', 120);

    $twitch = Mockery::mock(TwitchApiClient::class);
    $twitch->shouldNotReceive('getLiveHandles');

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['freshuser']);
});

it('batches Twitch handles in groups of 100', function () {
    $handles = array_map(fn ($i) => "user{$i}", range(1, 150));

    $twitch = Mockery::mock(TwitchApiClient::class);
    // First batch of 100, second batch of 50
    $twitch->shouldReceive('getLiveHandles')
        ->twice()
        ->andReturn([]);

    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', $handles);
});

it('writes live=1 to Redis for a Kick handle that is live (batched)', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);

    $kick = Mockery::mock(KickApiClient::class);
    $kick->shouldReceive('getLiveHandles')->with(['xqc'])->andReturn(['xqc']);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('kick', ['xqc']);

    expect(Redis::get('streaming:live:kick:xqc'))->toBe('1');
});

it('batches Kick handles into groups of 50', function () {
    $handles = array_map(fn ($i) => "user{$i}", range(1, 80));

    $twitch = Mockery::mock(TwitchApiClient::class);
    $kick = Mockery::mock(KickApiClient::class);
    // Expect 2 batches: 50 + 30
    $kick->shouldReceive('getLiveHandles')->twice()->andReturn([]);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('kick', $handles);
});

it('sets rate_limited Redis key and aborts remaining Kick batches on 429', function () {
    $twitch = Mockery::mock(TwitchApiClient::class);

    // 60 handles → 2 batches. First throws, second should never be called.
    $handles = array_map(fn ($i) => "user{$i}", range(1, 60));

    $kick = Mockery::mock(KickApiClient::class);
    $kick->shouldReceive('getLiveHandles')
        ->once()
        ->andThrow(new KickRateLimitException(60));

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('kick', $handles);

    expect(Redis::exists('streaming:kick:rate_limited'))->toBe(1);
});

it('resets offline_count and writes short TTL when a handle goes live', function () {
    Redis::set('streaming:offline_count:twitch:shroud', '5');

    $twitch = Mockery::mock(TwitchApiClient::class);
    $twitch->shouldReceive('getLiveHandles')->with(['shroud'])->andReturn(['shroud']);
    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['shroud']);

    expect(Redis::get('streaming:live:twitch:shroud'))->toBe('1');
    expect(Redis::exists('streaming:offline_count:twitch:shroud'))->toBe(0);
    // Live TTL should be ~180s (allow small jitter)
    expect(Redis::ttl('streaming:live:twitch:shroud'))->toBeGreaterThan(150);
});

it('demotes TTL to cool tier (600s) after 3+ consecutive offline reads', function () {
    Redis::set('streaming:offline_count:twitch:sleeper', '2');

    $twitch = Mockery::mock(TwitchApiClient::class);
    $twitch->shouldReceive('getLiveHandles')->andReturn([]);
    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['sleeper']);

    // count incremented to 3 → cool tier: TTL in [500, 600]
    expect(Redis::get('streaming:live:twitch:sleeper'))->toBe('0');
    expect(Redis::ttl('streaming:live:twitch:sleeper'))->toBeGreaterThan(500);
    expect(Redis::ttl('streaming:live:twitch:sleeper'))->toBeLessThanOrEqual(600);
});

it('demotes TTL to cold tier (1800s) after 11+ consecutive offline reads', function () {
    Redis::set('streaming:offline_count:twitch:dormant', '10');

    $twitch = Mockery::mock(TwitchApiClient::class);
    $twitch->shouldReceive('getLiveHandles')->andReturn([]);
    $kick = Mockery::mock(KickApiClient::class);

    $poller = new LiveStatusPoller($twitch, $kick);
    $poller->poll('twitch', ['dormant']);

    expect(Redis::ttl('streaming:live:twitch:dormant'))->toBeGreaterThan(1700);
    expect(Redis::ttl('streaming:live:twitch:dormant'))->toBeLessThanOrEqual(1800);
});
