<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('resolves real client IP from X-Forwarded-For header', function () {
    Route::get('/_test/proxy-ip', fn (Request $request) => ['ip' => $request->ip()]);

    $response = $this->call('GET', '/_test/proxy-ip', [], [], [], [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
    ]);

    $response->assertOk();
    expect($response->json('ip'))->toBe('203.0.113.1');
});

it('resolves last hop IP when no forwarded header is present', function () {
    Route::get('/_test/proxy-ip', fn (Request $request) => ['ip' => $request->ip()]);

    $response = $this->call('GET', '/_test/proxy-ip', [], [], [], [
        'REMOTE_ADDR' => '127.0.0.1',
    ]);

    $response->assertOk();
    expect($response->json('ip'))->toBe('127.0.0.1');
});
