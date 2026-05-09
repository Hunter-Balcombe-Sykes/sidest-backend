<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Coverage for the /stripe/status caching layer:
//  - syncAccountStatus caches the accounts->retrieve round-trip
//  - forgetStatusCache evicts both primary + :stale (SWR safety)
//  - controller honours ?fresh=1 by forgetting before delegating
//  - webhook account.updated busts the cache so the next status call refetches
//
// We use Reflection to swap StripeConnectService::$stripe for a Mockery double —
// matches the established pattern in StripeIdempotencyKeysTest.

beforeEach(function () {
    Cache::flush();
    setupProfessionalsTable();

    Config::set('services.stripe.secret_key', 'sk_test_fake');
    Config::set('services.stripe.connect_webhook_secret', 'whsec_connect_test');
    Config::set('services.stripe.webhook_secret', 'whsec_billing_test');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');
});

function statusCachingProfessional(string $accountId, string $localStatus = 'onboarding'): Professional
{
    $id = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'pro_'.Str::random(6),
        'professional_type' => 'professional',
        'status' => 'active',
        'stripe_connect_account_id' => $accountId,
        'stripe_connect_status' => $localStatus,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Professional::find($id);
}

function attachStripeMockToService(StripeConnectService $service, object $stripeClient): void
{
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);
}

function buildAccountObject(string $accountId, bool $detailsSubmitted = true): object
{
    return (object) [
        'id' => $accountId,
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => $detailsSubmitted,
        'requirements' => (object) ['currently_due' => []],
    ];
}

it('caches the stripe accounts->retrieve call across consecutive syncAccountStatus invocations', function () {
    $pro = statusCachingProfessional('acct_cache_hit');

    $accountsSpy = Mockery::mock();
    $accountsSpy->shouldReceive('retrieve')
        ->once()
        ->with('acct_cache_hit')
        ->andReturn(buildAccountObject('acct_cache_hit'));

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('accounts')->andReturn($accountsSpy);

    $service = app(StripeConnectService::class);
    attachStripeMockToService($service, $stripeClient);

    $first = $service->syncAccountStatus($pro);
    $second = $service->syncAccountStatus($pro);

    expect($first)->toBe($second);
    expect($first['charges_enabled'])->toBeTrue();
});

it('forgetStatusCache evicts the cached status so the next syncAccountStatus call refetches', function () {
    $pro = statusCachingProfessional('acct_forget');

    $accountsSpy = Mockery::mock();
    $accountsSpy->shouldReceive('retrieve')
        ->twice()
        ->with('acct_forget')
        ->andReturn(buildAccountObject('acct_forget'));

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('accounts')->andReturn($accountsSpy);

    $service = app(StripeConnectService::class);
    attachStripeMockToService($service, $stripeClient);

    $service->syncAccountStatus($pro);
    $service->forgetStatusCache('acct_forget');
    $service->syncAccountStatus($pro);
});

it('forgetStatusCache evicts the SWR stale copy too (defence against last-good leakage)', function () {
    // Manually seed both keys to simulate a cache that's already been written.
    Cache::put('stripe:connect:status:acct_swr', ['stale' => 'primary'], 60);
    Cache::put('stripe:connect:status:acct_swr:stale', ['stale' => 'last-good'], 600);

    $service = app(StripeConnectService::class);
    $service->forgetStatusCache('acct_swr');

    expect(Cache::get('stripe:connect:status:acct_swr'))->toBeNull();
    expect(Cache::get('stripe:connect:status:acct_swr:stale'))->toBeNull();
});

it('controller status endpoint with ?fresh=1 forgets the cache before delegating to syncAccountStatus', function () {
    $pro = statusCachingProfessional('acct_fresh');

    // Prime the cache with a stale entry the controller MUST overwrite.
    Cache::put('stripe:connect:status:acct_fresh', [
        'status' => 'pending',
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => false,
        'requirements' => [],
    ], 60);

    $accountsSpy = Mockery::mock();
    $accountsSpy->shouldReceive('retrieve')
        ->once()
        ->with('acct_fresh')
        ->andReturn(buildAccountObject('acct_fresh'));

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('accounts')->andReturn($accountsSpy);

    $service = app(StripeConnectService::class);
    attachStripeMockToService($service, $stripeClient);

    $payoutStub = Mockery::mock(CommissionPayoutService::class);
    $controller = new StripeConnectController($service, $payoutStub);

    $request = Request::create('/api/stripe/status?fresh=1', 'GET');
    $request->attributes->set('professional', $pro);

    $response = $controller->status($request);
    $payload = json_decode($response->getContent(), true);

    expect($payload['connect']['charges_enabled'])->toBeTrue();
    expect($payload['connect']['status'])->not->toBe('pending');
});

it('controller status endpoint without fresh=1 serves cached payload without calling stripe', function () {
    $pro = statusCachingProfessional('acct_warm');

    Cache::put('stripe:connect:status:acct_warm', [
        'status' => 'active',
        'charges_enabled' => true,
        'payouts_enabled' => true,
        'details_submitted' => true,
        'requirements' => [],
    ], 60);

    $accountsSpy = Mockery::mock();
    $accountsSpy->shouldNotReceive('retrieve');

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('accounts')->andReturn($accountsSpy)->zeroOrMoreTimes();

    $service = app(StripeConnectService::class);
    attachStripeMockToService($service, $stripeClient);

    $payoutStub = Mockery::mock(CommissionPayoutService::class);
    $controller = new StripeConnectController($service, $payoutStub);

    $request = Request::create('/api/stripe/status', 'GET');
    $request->attributes->set('professional', $pro);

    $response = $controller->status($request);
    $payload = json_decode($response->getContent(), true);

    expect($payload['connect']['status'])->toBe('active');
});

it('account.updated webhook forgets the cached status for the affected account', function () {
    $accountId = 'acct_webhook_bust';
    $pro = statusCachingProfessional($accountId, 'pending');

    // Cache is warm before the webhook fires.
    Cache::put('stripe:connect:status:'.$accountId, [
        'status' => 'pending',
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => false,
        'requirements' => [],
    ], 60);
    Cache::put('stripe:connect:status:'.$accountId.':stale', [
        'status' => 'pending',
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'details_submitted' => false,
        'requirements' => [],
    ], 600);

    $event = [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'account' => $accountId,
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'account.updated',
        'data' => [
            'object' => [
                'id' => $accountId,
                'object' => 'account',
                'charges_enabled' => true,
                'payouts_enabled' => true,
                'details_submitted' => true,
                'requirements' => [
                    'currently_due' => [],
                    'past_due' => [],
                    'pending_verification' => [],
                ],
            ],
        ],
        'livemode' => false,
    ];
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_connect_test');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    expect(Cache::get('stripe:connect:status:'.$accountId))->toBeNull();
    expect(Cache::get('stripe:connect:status:'.$accountId.':stale'))->toBeNull();
});

it('createOnboardingLink appends fresh=1 to the return_url so post-onboarding bypasses cache', function () {
    $pro = statusCachingProfessional('acct_onboard_link');
    $pro->update(['country_code' => 'AU']);

    $accountsSpy = Mockery::mock();
    $accountsSpy->shouldReceive('retrieve')
        ->andReturn((object) [
            'id' => 'acct_onboard_link',
            'details_submitted' => false,
            'business_type' => 'individual',
        ]);

    $accountLinksSpy = Mockery::mock();
    $accountLinksSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params) {
            // Plain return_url without query string → ?fresh=1 appended
            expect($params['return_url'])->toContain('fresh=1');

            return true;
        })
        ->andReturn((object) ['url' => 'https://stripe.com/connect/onboard']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('accounts')->andReturn($accountsSpy);
    $stripeClient->shouldReceive('getService')->with('accountLinks')->andReturn($accountLinksSpy);

    $service = app(StripeConnectService::class);
    attachStripeMockToService($service, $stripeClient);

    $service->createOnboardingLink($pro, 'https://app.partna.au/dashboard', 'https://app.partna.au/onboarding/refresh');
});

it('createOnboardingLink merges fresh=1 with an existing return_url query string using &', function () {
    $pro = statusCachingProfessional('acct_onboard_qs');
    $pro->update(['country_code' => 'AU']);

    $accountsSpy = Mockery::mock();
    $accountsSpy->shouldReceive('retrieve')
        ->andReturn((object) [
            'id' => 'acct_onboard_qs',
            'details_submitted' => false,
            'business_type' => 'individual',
        ]);

    $accountLinksSpy = Mockery::mock();
    $accountLinksSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params) {
            expect($params['return_url'])->toBe('https://app.partna.au/dashboard?utm=stripe&fresh=1');

            return true;
        })
        ->andReturn((object) ['url' => 'https://stripe.com/connect/onboard']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('accounts')->andReturn($accountsSpy);
    $stripeClient->shouldReceive('getService')->with('accountLinks')->andReturn($accountLinksSpy);

    $service = app(StripeConnectService::class);
    attachStripeMockToService($service, $stripeClient);

    $service->createOnboardingLink(
        $pro,
        'https://app.partna.au/dashboard?utm=stripe',
        'https://app.partna.au/onboarding/refresh',
    );
});
