<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\ExportService;
use App\Services\Stripe\StripeBalanceService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeTransactionFetcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Coverage for the /stripe/status caching layer under v2:
//  - syncAccountStatus caches the v2->core->accounts->retrieve() round-trip
//  - Dual-capability check: card_payments_active AND stripe_transfers_active
//  - forgetStatusCache evicts both primary + :stale (SWR safety)
//  - Controller honours ?fresh=1 by forgetting before delegating
//  - v2 account webhook (POST /api/webhooks/stripe-platform-thin) busts the cache
//
// v2 uses $stripe->v2->core->accounts->retrieve(), not the v1 getService('accounts').

beforeEach(function () {
    Cache::flush();
    setupProfessionalsTable();

    Config::set('services.stripe.secret_key', 'sk_test_fake');
    Config::set('services.stripe.platform_thin_webhook_secret', 'whsec_platform_thin_test');
    Config::set('services.stripe.platform_webhook_secret', 'whsec_platform_test');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        received_at TEXT,
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

function attachStripeV2MockToService(StripeConnectService $service, object $stripeClient): void
{
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);
}

/**
 * Build a v2 account response shape. The dual-capability status fields are nested
 * under configuration.merchant.capabilities.card_payments.status and
 * configuration.recipient.capabilities.stripe_balance.stripe_transfers.status.
 */
function buildV2AccountObject(string $accountId, bool $cardPaymentsActive = true, bool $transfersActive = true, array $currentlyDue = []): object
{
    return (object) [
        'id' => $accountId,
        'configuration' => (object) [
            'merchant' => (object) [
                'capabilities' => (object) [
                    'card_payments' => (object) [
                        'status' => $cardPaymentsActive ? 'active' : 'pending',
                    ],
                ],
            ],
            'customer' => (object) [],
            'recipient' => (object) [
                'capabilities' => (object) [
                    'stripe_balance' => (object) [
                        'stripe_transfers' => (object) [
                            'status' => $transfersActive ? 'active' : 'pending',
                        ],
                    ],
                ],
            ],
        ],
        'requirements' => (object) ['currently_due' => $currentlyDue],
    ];
}

it('caches the v2 accounts->retrieve call across consecutive syncAccountStatus invocations', function () {
    $pro = statusCachingProfessional('acct_cache_hit');

    $v2AccountsSpy = Mockery::mock();
    $v2AccountsSpy->shouldReceive('retrieve')
        ->once()
        ->with('acct_cache_hit', Mockery::any())
        ->andReturn(buildV2AccountObject('acct_cache_hit'));

    $v2CoreMock = (object) ['accounts' => $v2AccountsSpy];
    $v2Mock = (object) ['core' => $v2CoreMock];

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->v2 = $v2Mock;

    $service = app(StripeConnectService::class);
    attachStripeV2MockToService($service, $stripeClient);

    $first = $service->syncAccountStatus($pro);
    $second = $service->syncAccountStatus($pro);

    expect($first)->toBe($second);
    expect($first['card_payments_active'])->toBeTrue();
    expect($first['stripe_transfers_active'])->toBeTrue();
});

it('forgetStatusCache evicts the cached status so the next syncAccountStatus call refetches', function () {
    $pro = statusCachingProfessional('acct_forget');

    $v2AccountsSpy = Mockery::mock();
    $v2AccountsSpy->shouldReceive('retrieve')
        ->twice()
        ->with('acct_forget', Mockery::any())
        ->andReturn(buildV2AccountObject('acct_forget'));

    $v2CoreMock = (object) ['accounts' => $v2AccountsSpy];
    $v2Mock = (object) ['core' => $v2CoreMock];

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->v2 = $v2Mock;

    $service = app(StripeConnectService::class);
    attachStripeV2MockToService($service, $stripeClient);

    $service->syncAccountStatus($pro);
    $service->forgetStatusCache('acct_forget');
    $service->syncAccountStatus($pro);
});

it('forgetStatusCache evicts the SWR stale copy too', function () {
    Cache::put('stripe:connect:status:acct_swr', ['stale' => 'primary'], 60);
    Cache::put('stripe:connect:status:acct_swr:stale', ['stale' => 'last-good'], 600);

    $service = app(StripeConnectService::class);
    $service->forgetStatusCache('acct_swr');

    expect(Cache::get('stripe:connect:status:acct_swr'))->toBeNull();
    expect(Cache::get('stripe:connect:status:acct_swr:stale'))->toBeNull();
});

it('controller status endpoint with ?fresh=1 forgets the cache before delegating to syncAccountStatus', function () {
    $pro = statusCachingProfessional('acct_fresh');

    Cache::put('stripe:connect:status:acct_fresh', [
        'status' => 'pending',
        'card_payments_active' => false,
        'stripe_transfers_active' => false,
        'requirements' => [],
    ], 60);

    $v2AccountsSpy = Mockery::mock();
    $v2AccountsSpy->shouldReceive('retrieve')
        ->once()
        ->with('acct_fresh', Mockery::any())
        ->andReturn(buildV2AccountObject('acct_fresh'));

    $v2CoreMock = (object) ['accounts' => $v2AccountsSpy];
    $v2Mock = (object) ['core' => $v2CoreMock];

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->v2 = $v2Mock;

    $service = app(StripeConnectService::class);
    attachStripeV2MockToService($service, $stripeClient);

    $payoutStub = Mockery::mock(CommissionPayoutService::class);
    $controller = new StripeConnectController(
        $service,
        $payoutStub,
        Mockery::mock(StripeTransactionFetcher::class),
        Mockery::mock(StripeBalanceService::class),
        Mockery::mock(ExportService::class),
        app(CacheLockService::class),
    );

    $request = Request::create('/api/stripe/status?fresh=1', 'GET');
    $request->attributes->set('professional', $pro);

    $response = $controller->status($request);
    $payload = json_decode($response->getContent(), true);

    expect($payload['connect']['card_payments_active'])->toBeTrue();
    expect($payload['connect']['status'])->not->toBe('pending');
});

it('controller status endpoint without fresh=1 serves cached payload without calling stripe', function () {
    $pro = statusCachingProfessional('acct_warm');

    Cache::put('stripe:connect:status:acct_warm', [
        'status' => 'active',
        'card_payments_active' => true,
        'stripe_transfers_active' => true,
        'requirements' => [],
    ], 60);

    $v2AccountsSpy = Mockery::mock();
    $v2AccountsSpy->shouldNotReceive('retrieve');

    $v2CoreMock = (object) ['accounts' => $v2AccountsSpy];
    $v2Mock = (object) ['core' => $v2CoreMock];

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->v2 = $v2Mock;

    $service = app(StripeConnectService::class);
    attachStripeV2MockToService($service, $stripeClient);

    $payoutStub = Mockery::mock(CommissionPayoutService::class);
    $controller = new StripeConnectController(
        $service,
        $payoutStub,
        Mockery::mock(StripeTransactionFetcher::class),
        Mockery::mock(StripeBalanceService::class),
        Mockery::mock(ExportService::class),
        app(CacheLockService::class),
    );

    $request = Request::create('/api/stripe/status', 'GET');
    $request->attributes->set('professional', $pro);

    $response = $controller->status($request);
    $payload = json_decode($response->getContent(), true);

    expect($payload['connect']['status'])->toBe('active');
});

it('v2.core.account.updated webhook via platform-thin forgets the cached status', function () {
    $accountId = 'acct_webhook_bust';
    $pro = statusCachingProfessional($accountId, 'pending');

    Cache::put('stripe:connect:status:'.$accountId, [
        'status' => 'pending',
        'card_payments_active' => false,
        'stripe_transfers_active' => false,
        'requirements' => [],
    ], 60);
    Cache::put('stripe:connect:status:'.$accountId.':stale', [
        'status' => 'pending',
        'card_payments_active' => false,
        'stripe_transfers_active' => false,
        'requirements' => [],
    ], 600);

    // The thin handler calls StripeConnectService::syncAccountStatus, which retrieves
    // the v2 account from Stripe. Mock it so the test stays unit-isolated.
    $mockService = Mockery::mock(\App\Services\Stripe\StripeConnectService::class)->makePartial();
    $mockService->shouldReceive('syncAccountStatus')->andReturn([
        'status' => 'active',
        'stripe_connect_account_id' => $accountId,
        'card_payments_active' => true,
        'stripe_transfers_active' => true,
        'requirements' => [],
    ]);
    app()->instance(\App\Services\Stripe\StripeConnectService::class, $mockService);

    $event = [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'v2.core.account.updated',
        'data' => ['account_id' => $accountId],
        'related_object' => [
            'id' => $accountId,
            'type' => 'v2.core.account',
            'url' => '/v2/core/accounts/'.$accountId,
        ],
        'livemode' => false,
    ];
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_platform_thin_test');

    $this->call('POST', '/api/webhooks/stripe-platform-thin', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    expect(Cache::get('stripe:connect:status:'.$accountId))->toBeNull();
    expect(Cache::get('stripe:connect:status:'.$accountId.':stale'))->toBeNull();
});

it('createOnboardingLink appends fresh=1 to the return_url so post-onboarding bypasses cache', function () {
    $pro = statusCachingProfessional('acct_onboard_link');
    $pro->update(['country_code' => 'AU']);

    $v2AccountsSpy = Mockery::mock();
    $v2AccountsSpy->shouldReceive('retrieve')
        ->andReturn(buildV2AccountObject('acct_onboard_link'));

    $v2AccountLinksSpy = Mockery::mock();
    $v2AccountLinksSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params) {
            expect($params['use_case']['account_onboarding']['return_url'])->toContain('fresh=1');

            return true;
        })
        ->andReturn((object) ['url' => 'https://stripe.com/connect/onboard']);

    $v2CoreMock = (object) [
        'accounts' => $v2AccountsSpy,
        'accountLinks' => $v2AccountLinksSpy,
    ];
    $v2Mock = (object) ['core' => $v2CoreMock];

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->v2 = $v2Mock;

    $service = app(StripeConnectService::class);
    attachStripeV2MockToService($service, $stripeClient);

    $service->createOnboardingLink($pro, 'https://app.partna.au/dashboard', 'https://app.partna.au/onboarding/refresh');
});

it('createOnboardingLink merges fresh=1 with an existing return_url query string using &', function () {
    $pro = statusCachingProfessional('acct_onboard_qs');
    $pro->update(['country_code' => 'AU']);

    $v2AccountsSpy = Mockery::mock();
    $v2AccountsSpy->shouldReceive('retrieve')
        ->andReturn(buildV2AccountObject('acct_onboard_qs'));

    $v2AccountLinksSpy = Mockery::mock();
    $v2AccountLinksSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params) {
            expect($params['use_case']['account_onboarding']['return_url'])
                ->toBe('https://app.partna.au/dashboard?utm=stripe&fresh=1');

            return true;
        })
        ->andReturn((object) ['url' => 'https://stripe.com/connect/onboard']);

    $v2CoreMock = (object) [
        'accounts' => $v2AccountsSpy,
        'accountLinks' => $v2AccountLinksSpy,
    ];
    $v2Mock = (object) ['core' => $v2CoreMock];

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->v2 = $v2Mock;

    $service = app(StripeConnectService::class);
    attachStripeV2MockToService($service, $stripeClient);

    $service->createOnboardingLink(
        $pro,
        'https://app.partna.au/dashboard?utm=stripe',
        'https://app.partna.au/onboarding/refresh',
    );
});
