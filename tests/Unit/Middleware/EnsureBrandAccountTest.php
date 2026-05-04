<?php

use App\Http\Middleware\EnsureBrandAccount;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('passes the request through when the resolved professional is a brand', function () {
    $pro = new Professional(['professional_type' => 'brand']);
    $request = Request::create('/test', 'GET');
    $request->attributes->set('professional', $pro);

    $next = fn ($req) => new Response('ok', 200);

    $response = (new EnsureBrandAccount())->handle($request, $next);

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('ok');
});

it('returns 403 with a JSON error when the professional is not a brand', function () {
    $pro = new Professional(['professional_type' => 'professional']);
    $request = Request::create('/test', 'GET');
    $request->attributes->set('professional', $pro);

    $response = (new EnsureBrandAccount())->handle($request, fn ($req) => new Response('ok'));

    expect($response->getStatusCode())->toBe(403);
    expect(json_decode($response->getContent(), true))
        ->toMatchArray(['error' => 'This endpoint is only available for brand accounts.']);
});

it('returns 401 when no professional is bound to the request', function () {
    $request = Request::create('/test', 'GET');

    $response = (new EnsureBrandAccount())->handle($request, fn ($req) => new Response('ok'));

    expect($response->getStatusCode())->toBe(401);
});
