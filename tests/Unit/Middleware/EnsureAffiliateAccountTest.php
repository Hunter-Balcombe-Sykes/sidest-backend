<?php

use App\Http\Middleware\EnsureAffiliateAccount;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

it('passes through when the professional is an affiliate (non-brand)', function () {
    $pro = new Professional(['professional_type' => 'professional']);
    $request = Request::create('/test', 'GET');
    $request->attributes->set('professional', $pro);

    $response = (new EnsureAffiliateAccount)->handle($request, fn ($req) => new Response('ok', 200));

    expect($response->getStatusCode())->toBe(200);
});

it('returns 403 when the professional is a brand', function () {
    $pro = new Professional(['professional_type' => 'brand']);
    $request = Request::create('/test', 'GET');
    $request->attributes->set('professional', $pro);

    $response = (new EnsureAffiliateAccount)->handle($request, fn ($req) => new Response('ok'));

    expect($response->getStatusCode())->toBe(403);
    expect(json_decode($response->getContent(), true))
        ->toMatchArray(['error' => 'Brand accounts cannot use this endpoint.']);
});

it('returns 401 when no professional is bound', function () {
    $request = Request::create('/test', 'GET');
    $response = (new EnsureAffiliateAccount)->handle($request, fn ($req) => new Response('ok'));
    expect($response->getStatusCode())->toBe(401);
});
