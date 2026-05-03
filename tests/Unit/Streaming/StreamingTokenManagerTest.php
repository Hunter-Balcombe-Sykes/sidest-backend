<?php

/** @phpstan-ignore-all */

use App\Services\Streaming\StreamingTokenManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    Redis::flushdb();
});

it('fetches a Twitch token and caches it in Redis', function () {
    config([
        'services.twitch.client_id' => 'test-id',
        'services.twitch.client_secret' => 'test-secret',
    ]);

    Http::fake([
        'id.twitch.tv/oauth2/token' => Http::response([
            'access_token' => 'twitch-token-abc',
            'expires_in' => 3600,
        ], 200),
    ]);

    $manager = new StreamingTokenManager;
    $token = $manager->getToken('twitch');

    expect($token)->toBe('twitch-token-abc');
    expect(Redis::exists('streaming:token:twitch'))->toBe(1);
});

it('returns cached Twitch token without making an HTTP call', function () {
    Redis::set('streaming:token:twitch', 'cached-token', 'EX', 3600);

    Http::fake(); // no calls expected

    $manager = new StreamingTokenManager;
    $token = $manager->getToken('twitch');

    expect($token)->toBe('cached-token');
    Http::assertNothingSent();
});

it('returns null if credentials are missing', function () {
    config(['services.twitch.client_id' => null]);
    config(['services.twitch.client_secret' => null]);

    $manager = new StreamingTokenManager;
    $token = $manager->getToken('twitch');

    expect($token)->toBeNull();
});

it('fetches a Kick token and caches it', function () {
    config([
        'services.kick.client_id' => 'kick-id',
        'services.kick.client_secret' => 'kick-secret',
    ]);

    Http::fake([
        'id.kick.com/oauth/token' => Http::response([
            'access_token' => 'kick-token-xyz',
            'expires_in' => 3600,
        ], 200),
    ]);

    $manager = new StreamingTokenManager;
    $token = $manager->getToken('kick');

    expect($token)->toBe('kick-token-xyz');
});
