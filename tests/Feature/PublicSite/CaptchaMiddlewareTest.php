<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Tests for VerifyTurnstileCaptcha middleware applied to public lead-capture routes.

it('passes through when captcha feature flag is disabled', function () {
    config(['partna.features.captcha' => false]);

    Http::fake();

    $response = $this->postJson('/api/public/waitlist', ['email' => 'test@example.com']);

    // Cloudflare must not be called when the feature is off
    Http::assertNothingSent();

    // Response is not a CAPTCHA block — any other error (e.g. validation) is fine
    $message = $response->json('message') ?? '';
    expect($message)->not->toContain('CAPTCHA');
})->group('captcha');

it('rejects waitlist submission when captcha is enabled and token is missing', function () {
    config(['partna.features.captcha' => true]);
    config(['services.turnstile.secret_key' => 'test-secret']);

    Http::fake();

    $response = $this->postJson('/api/public/waitlist', ['email' => 'test@example.com']);

    $response->assertStatus(422)->assertJson(['message' => 'CAPTCHA token missing.']);
    Http::assertNothingSent();
})->group('captcha');

it('rejects enquiry submission when captcha is enabled and token is missing', function () {
    config(['partna.features.captcha' => true]);
    config(['services.turnstile.secret_key' => 'test-secret']);

    Http::fake();

    $response = $this->postJson('/api/public/enquiry', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'subject' => 'General enquiry',
        'message' => 'Hello',
    ]);

    $response->assertStatus(422)->assertJson(['message' => 'CAPTCHA token missing.']);
    Http::assertNothingSent();
})->group('captcha');

it('rejects customer lead submission when captcha is enabled and token is missing', function () {
    config(['partna.features.captcha' => true]);
    config(['services.turnstile.secret_key' => 'test-secret']);

    Http::fake();

    $response = $this->postJson('/api/public/customers', ['email' => 'test@example.com']);

    $response->assertStatus(422)->assertJson(['message' => 'CAPTCHA token missing.']);
    Http::assertNothingSent();
})->group('captcha');

it('rejects submission when Turnstile returns success=false', function () {
    config(['partna.features.captcha' => true]);
    config(['services.turnstile.secret_key' => 'test-secret']);

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response([
            'success' => false,
            'error-codes' => ['invalid-input-response'],
        ], 200),
    ]);

    $response = $this->postJson('/api/public/waitlist', [
        'email' => 'test@example.com',
        'cf_turnstile_response' => 'bad-token',
    ]);

    $response->assertStatus(422)->assertJson(['message' => 'CAPTCHA verification failed.']);
})->group('captcha');

it('passes captcha check and forwards to controller when token is valid', function () {
    config(['partna.features.captcha' => true]);
    config(['services.turnstile.secret_key' => 'test-secret']);

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
    ]);

    $response = $this->postJson('/api/public/waitlist', [
        'email' => 'test@example.com',
        'cf_turnstile_response' => 'valid-token',
    ]);

    // Turnstile was called with the submitted token
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'siteverify')
            && $request['response'] === 'valid-token'
            && $request['secret'] === 'test-secret';
    });

    // Response is NOT a CAPTCHA error — whatever the controller returns is fine
    expect($response->status())->not->toBe(503);
    expect($response->json('message') ?? '')->not->toContain('CAPTCHA');
})->group('captcha');

it('returns 503 when Turnstile secret key is not configured', function () {
    config(['partna.features.captcha' => true]);
    config(['services.turnstile.secret_key' => null]);

    Http::fake();

    $response = $this->postJson('/api/public/waitlist', [
        'email' => 'test@example.com',
        'cf_turnstile_response' => 'any-token',
    ]);

    $response->assertStatus(503)->assertJson(['message' => 'CAPTCHA verification unavailable.']);
    Http::assertNothingSent();
})->group('captcha');

it('applies captcha middleware to waitlist route', function () {
    $route = app('router')->getRoutes()->match(
        Request::create('/api/public/waitlist', 'POST')
    );

    expect($route->gatherMiddleware())->toContain('captcha');
})->group('captcha');

it('applies captcha middleware to enquiry route', function () {
    $route = app('router')->getRoutes()->match(
        Request::create('/api/public/enquiry', 'POST')
    );

    expect($route->gatherMiddleware())->toContain('captcha');
})->group('captcha');

it('applies captcha middleware to customer lead route', function () {
    $route = app('router')->getRoutes()->match(
        Request::create('/api/public/customers', 'POST')
    );

    expect($route->gatherMiddleware())->toContain('captcha');
})->group('captcha');
