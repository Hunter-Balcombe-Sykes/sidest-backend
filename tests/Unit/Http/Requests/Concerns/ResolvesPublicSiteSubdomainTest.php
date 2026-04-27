<?php

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ResolvesPublicSiteSubdomain;
use Illuminate\Routing\Route;

function makeSubdomainRequest(?string $routeValue, ?string $headerValue, ?string $bodyValue = null): BaseFormRequest
{
    $request = new class extends BaseFormRequest {
        use ResolvesPublicSiteSubdomain;
        public function rules(): array { return []; }
        public function exposeMerge(?string $headerName = null): void { $this->mergeSubdomainFromRoute($headerName); }
    };

    if ($bodyValue !== null) {
        $request->merge(['subdomain' => $bodyValue]);
    }
    if ($headerValue !== null) {
        $request->headers->set('X-Site-Subdomain', $headerValue);
    }
    if ($routeValue !== null) {
        $route = new Route(['GET'], '/x/{subdomain}', []);
        $route->bind($request);
        $route->setParameter('subdomain', $routeValue);
        $request->setRouteResolver(fn () => $route);
    }
    return $request;
}

it('lowercases the route subdomain when present', function () {
    $r = makeSubdomainRequest('FooBar', null);
    $r->exposeMerge();
    expect($r->input('subdomain'))->toBe('foobar');
});

it('falls back to the configured header when route is empty', function () {
    $r = makeSubdomainRequest(null, '  HeaderName  ');
    $r->exposeMerge('X-Site-Subdomain');
    expect($r->input('subdomain'))->toBe('headername');
});

it('does not fall back to the header when no header name is supplied', function () {
    $r = makeSubdomainRequest(null, 'HeaderName');
    $r->exposeMerge();
    expect($r->has('subdomain'))->toBeFalse();
});

it('prefers the route value over the header value', function () {
    $r = makeSubdomainRequest('FromRoute', 'FromHeader');
    $r->exposeMerge('X-Site-Subdomain');
    expect($r->input('subdomain'))->toBe('fromroute');
});

it('leaves request untouched when neither source is present', function () {
    $r = makeSubdomainRequest(null, null);
    $r->exposeMerge('X-Site-Subdomain');
    expect($r->has('subdomain'))->toBeFalse();
});
