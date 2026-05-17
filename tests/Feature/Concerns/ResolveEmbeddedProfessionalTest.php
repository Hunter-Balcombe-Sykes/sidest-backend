<?php

use App\Http\Controllers\Concerns\ResolveEmbeddedProfessional;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Tests for the ResolveEmbeddedProfessional trait — the single source of truth
// for tenant identity on the Partna embedded Shopify app surface. The trait
// reads `embedded_professional_id` (set by VerifyShopifySessionToken after JWT
// validation) and refuses to fall back to any other source.

beforeEach(function () {
    setupProfessionalsTable();

    $this->resolver = new class
    {
        use ResolveEmbeddedProfessional {
            currentEmbeddedProfessional as public;
        }
    };
});

it('resolves Professional from embedded_professional_id attribute', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'display_name' => 'Brand A',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $request = Request::create('/internal/embedded/anything', 'GET');
    $request->attributes->set('embedded_professional_id', $id);

    $pro = $this->resolver->currentEmbeddedProfessional($request);

    expect($pro)->toBeInstanceOf(Professional::class)
        ->and((string) $pro->id)->toBe($id);
});

it('aborts 401 when embedded_professional_id is absent', function () {
    $request = Request::create('/internal/embedded/anything', 'GET');

    expect(fn () => $this->resolver->currentEmbeddedProfessional($request))
        ->toThrow(HttpException::class);
});

it('aborts 401 when embedded_professional_id is the empty string', function () {
    $request = Request::create('/internal/embedded/anything', 'GET');
    $request->attributes->set('embedded_professional_id', '');

    expect(fn () => $this->resolver->currentEmbeddedProfessional($request))
        ->toThrow(HttpException::class);
});

it('caches the resolved Professional on the request', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'display_name' => 'Brand B',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $request = Request::create('/internal/embedded/anything', 'GET');
    $request->attributes->set('embedded_professional_id', $id);

    $first = $this->resolver->currentEmbeddedProfessional($request);
    $second = $this->resolver->currentEmbeddedProfessional($request);

    // Same instance — caches on request attributes so repeated calls within
    // a request don't re-query.
    expect($second)->toBe($first);
});
