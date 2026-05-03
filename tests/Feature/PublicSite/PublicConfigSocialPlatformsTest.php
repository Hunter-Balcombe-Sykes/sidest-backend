<?php

it('returns 200 with the social platforms registry without auth', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'platforms' => [
            '*' => ['key', 'display_name', 'icon_key', 'placeholder'],
        ],
    ]);
});

it('returns all supported platforms and the first 8 are the original social platforms', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $platforms = $response->json('platforms');
    $keys = array_column($platforms, 'key');

    // Original 8 social platforms remain first in stable registry order
    expect(array_slice($keys, 0, 8))->toBe([
        'instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x', 'spotify', 'soundcloud',
    ]);
    // All known platforms are present
    expect($keys)->toContain('instagram');
    expect($keys)->toContain('twitch');
    expect($keys)->toContain('kick');
});

it('does not leak internal validation fields', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $platforms = $response->json('platforms');

    foreach ($platforms as $platform) {
        // Internal-only fields must never reach the wire — exposing them gives
        // attackers a head start on crafting bypass payloads.
        expect($platform)->not->toHaveKey('handle_pattern');
        expect($platform)->not->toHaveKey('host_allowlist');
        expect($platform)->not->toHaveKey('url_path_extractor');
        expect($platform)->not->toHaveKey('url_template');
    }
});

it('sets a public cache header so the CDN can absorb traffic', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    expect($response->headers->get('Cache-Control'))->toContain('public');
    expect($response->headers->get('Cache-Control'))->toContain('max-age=3600');
});

it('returns expected display names for each platform', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $names = collect($response->json('platforms'))->pluck('display_name', 'key')->all();

    expect($names)->toMatchArray([
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'linkedin' => 'LinkedIn',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'x' => 'X',
        'spotify' => 'Spotify',
        'soundcloud' => 'SoundCloud',
    ]);
});

it('returns all platforms with category field each', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $response->assertOk();

    $platforms = $response->json('platforms');
    expect($platforms)->not->toBeEmpty();
    foreach ($platforms as $p) {
        expect($p)->toHaveKeys(['key', 'display_name', 'icon_key', 'placeholder', 'category']);
    }
});

it('returns the canonical categories array alongside platforms', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $response->assertOk();
    $response->assertJson([
        'categories' => ['social', 'booking', 'education', 'content', 'events', 'streaming', 'other'],
    ]);
});

it('sends a 1-hour public cache header', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    // assertHeader does an exact match — Laravel normalizes the header as max-age first
    $response->assertHeader('Cache-Control', 'max-age=3600, public');
});
