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

it('returns all 8 supported platforms in stable order', function () {
    $response = $this->getJson('/api/public/config/social-platforms');

    $platforms = $response->json('platforms');
    $keys = array_column($platforms, 'key');

    expect($keys)->toBe([
        'instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x', 'spotify', 'soundcloud',
    ]);
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
