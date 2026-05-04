<?php

use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

// #PR-001: VerifyHydrogenApiKey must fail closed when no key is configured
// outside local/testing. A regression here re-opens every /internal/hydrogen/*
// route — including the deployment-token endpoint that can rewrite a brand's
// storefront — to anonymous traffic the moment HYDROGEN_API_KEY goes missing
// on a production deploy.

beforeEach(function () {
    Route::middleware('hydrogen.key')
        ->get('/__test/hydrogen-guard', fn () => response()->json(['ok' => true]));
});

it('allows the dev bypass through when env is testing and no key is configured', function () {
    config()->set('services.hydrogen.api_key', '');

    getJson('/__test/hydrogen-guard')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('returns 500 (fails closed) when env is production and no key is configured', function () {
    config()->set('services.hydrogen.api_key', '');
    app()['env'] = 'production';

    getJson('/__test/hydrogen-guard')->assertStatus(500);
});

it('returns 500 (fails closed) when env is staging and no key is configured', function () {
    config()->set('services.hydrogen.api_key', '');
    app()['env'] = 'staging';

    getJson('/__test/hydrogen-guard')->assertStatus(500);
});

it('returns 403 when key is configured but the request header is missing', function () {
    config()->set('services.hydrogen.api_key', 'secret-key');

    getJson('/__test/hydrogen-guard')
        ->assertStatus(403)
        ->assertJson(['message' => 'Invalid or missing API key.']);
});

it('returns 403 when key is configured and the request header is wrong', function () {
    config()->set('services.hydrogen.api_key', 'secret-key');

    getJson('/__test/hydrogen-guard', ['X-Hydrogen-Api-Key' => 'wrong-key'])
        ->assertStatus(403);
});

it('passes through when key is configured and a matching header is provided', function () {
    config()->set('services.hydrogen.api_key', 'secret-key');

    getJson('/__test/hydrogen-guard', ['X-Hydrogen-Api-Key' => 'secret-key'])
        ->assertOk()
        ->assertJson(['ok' => true]);
});
