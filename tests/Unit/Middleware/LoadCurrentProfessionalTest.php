<?php

use App\Http\Middleware\Context\LoadCurrentProfessional;
use Tests\TestCase;

uses(TestCase::class);
use App\Models\Core\Professional\Professional;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

beforeEach(function () {
    $this->cache = Mockery::mock(ProfessionalCacheService::class);
    $this->middleware = new LoadCurrentProfessional($this->cache);
    $this->next = fn ($req) => new Response('ok', 200);
});

it('returns 401 when supabase_uid attribute is missing', function () {
    $request = Request::create('/test', 'GET');

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode($response->getContent(), true)['message'])->toBe('Missing uid');
});

it('returns 401 when supabase_uid is not a valid UUID', function () {
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', 'not-a-uuid');

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode($response->getContent(), true)['message'])->toBe('Invalid uid');
});

it('returns 401 for SQL injection attempt in supabase_uid', function () {
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', "1' OR '1'='1");

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

it('returns 403 when no professional matches a valid UUID', function () {
    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);

    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn(null);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(403);
});

it('proceeds when supabase_uid is a valid UUID and professional is found', function () {
    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);

    $pro = new Professional(['status' => 'active']);
    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn($pro);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(200);
});

it('returns 403 when professional account is suspended', function () {
    $uid = '550e8400-e29b-41d4-a716-446655440000';
    $request = Request::create('/test', 'GET');
    $request->attributes->set('supabase_uid', $uid);

    $pro = new Professional(['status' => 'suspended']);
    $this->cache->shouldReceive('getByAuthId')->with($uid)->once()->andReturn($pro);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(403);
});
