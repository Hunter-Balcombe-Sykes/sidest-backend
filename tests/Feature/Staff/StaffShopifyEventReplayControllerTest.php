<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffShopifyEventReplayController;
use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Core\Professional\Professional;
use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\ShopDomain;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

// WEBHOOK-1 — staff replay of a single Shopify webhook event. The endpoint
// re-fetches the order from Shopify Admin API and re-dispatches the
// ProcessShopifyOrderWebhookJob with the original shopify_event_id so the
// internal dedup short-circuits. These tests verify the orchestration
// (lookup, scope, fetch, dispatch) — the job's own idempotency is covered
// by tests/Feature/Webhooks/Shopify/*.

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('shopify-event-replay:evt-abc-1');
    RateLimiter::clear('shopify-event-replay:evt-abc-2');

    setupProfessionalsTable();
    setupProfessionalIntegrationsTable();
    setupCommerceOrdersTables();

    Config::set('services.shopify.api_version', '2025-01');
});

/** Make a Professional that lives only in memory + the DB row used for FK joins. */
function makeReplayBrand(string $shopDomain = 'brand-a.myshopify.com'): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();
    $pro->forceFill(['professional_type' => 'brand'])->save();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'provider' => 'shopify',
        // access_token has an 'encrypted' cast on the model — store the
        // ciphertext so the read in the controller decrypts cleanly.
        'access_token' => Crypt::encryptString('shpat_test_token'),
        'shopify_shop_domain' => $shopDomain,
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
    ]);

    return $pro;
}

/** Seed a commerce.orders row + matching commerce.order_events row. */
function seedOrderAndEvent(
    Professional $brand,
    string $shopifyOrderId = '111222333',
    string $shopifyEventId = 'evt-abc-1',
    string $shopDomain = 'brand-a.myshopify.com',
): array {
    $orderId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();

    DB::table('commerce.orders')->insert([
        'id' => $orderId,
        'shopify_order_id' => $shopifyOrderId,
        'shopify_shop_domain' => $shopDomain,
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => 10000,
        'net_cents' => 10000,
        'commission_cents' => 1000,
        'commission_rate' => 0.10,
        'rate_source' => 'global',
        'currency_code' => 'AUD',
        'line_items' => '[]',
        'shopify_data' => '{}',
        'shopify_updated_at' => '2026-05-01T10:00:00+00:00',
        'occurred_at' => '2026-05-01T10:00:00+00:00',
    ]);

    DB::table('commerce.order_events')->insert([
        'id' => (string) Str::uuid(),
        'order_id' => $orderId,
        'event_type' => 'paid',
        'source' => 'webhook',
        'shopify_event_id' => $shopifyEventId,
        'shopify_triggered_at' => '2026-05-01T10:00:00+00:00',
        'metadata' => json_encode(['shopify_order_id' => $shopifyOrderId, 'financial_status' => 'paid']),
    ]);

    return ['order_id' => $orderId, 'affiliate_id' => $affiliateId];
}

/** Build a JSON Request that the controller can validate. */
function replayRequest(array $body): Request
{
    return Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
}

it('returns 422 when shopify_event_id is missing', function () {
    $brand = makeReplayBrand();
    $client = Mockery::mock(ShopifyAdminClient::class);
    $controller = new StaffShopifyEventReplayController($client);

    expect(fn () => $controller->invoke(replayRequest([]), $brand))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('returns 404 when shopify_event_id does not exist', function () {
    $brand = makeReplayBrand();
    $client = Mockery::mock(ShopifyAdminClient::class);
    $controller = new StaffShopifyEventReplayController($client);

    $response = $controller->invoke(replayRequest(['shopify_event_id' => 'evt-missing']), $brand);

    expect($response->status())->toBe(404);
});

it('returns 404 when event belongs to a different professional (cross-tenant hide)', function () {
    $brand = makeReplayBrand('brand-a.myshopify.com');
    $otherBrand = makeReplayBrand('brand-b.myshopify.com');
    seedOrderAndEvent($otherBrand, '999000111', 'evt-abc-1', 'brand-b.myshopify.com');

    $client = Mockery::mock(ShopifyAdminClient::class);
    $controller = new StaffShopifyEventReplayController($client);

    $response = $controller->invoke(replayRequest(['shopify_event_id' => 'evt-abc-1']), $brand);

    expect($response->status())->toBe(404);
});

it('returns 404 when the professional has no Shopify integration', function () {
    // Build a brand with NO integration row.
    $pro = new Professional;
    $pro->id = (string) Str::uuid();
    $pro->forceFill(['professional_type' => 'brand'])->save();

    seedOrderAndEvent($pro, '111222333', 'evt-abc-1', 'brand-a.myshopify.com');

    $client = Mockery::mock(ShopifyAdminClient::class);
    $controller = new StaffShopifyEventReplayController($client);

    $response = $controller->invoke(replayRequest(['shopify_event_id' => 'evt-abc-1']), $pro);

    expect($response->status())->toBe(404);
});

it('replays a known event: fetches from Shopify and dispatches the job sync with the original shopify_event_id', function () {
    Bus::fake();

    $brand = makeReplayBrand();
    seedOrderAndEvent($brand, '111222333', 'evt-abc-1');

    $ordersBefore = DB::table('commerce.orders')->count();
    $eventsBefore = DB::table('commerce.order_events')->count();

    $payload = [
        'id' => '111222333',
        'domain' => 'brand-a.myshopify.com',
        'updated_at' => '2026-05-01T10:00:00+00:00',
    ];

    $fakeResponse = Mockery::mock(HttpResponse::class);
    $fakeResponse->shouldReceive('json')->with('order')->andReturn($payload);

    $client = Mockery::mock(ShopifyAdminClient::class);
    $client->shouldReceive('rest')
        ->once()
        ->withArgs(function ($method, $shop, $token, $path) {
            return $method === 'GET'
                && $shop instanceof ShopDomain
                && $shop->value === 'brand-a.myshopify.com'
                && $token === 'shpat_test_token'
                && str_contains($path, '/admin/api/2025-01/orders/111222333.json');
        })
        ->andReturn($fakeResponse);

    $controller = new StaffShopifyEventReplayController($client);
    $response = $controller->invoke(replayRequest(['shopify_event_id' => 'evt-abc-1']), $brand);

    expect($response->status())->toBe(200);

    $data = json_decode($response->getContent(), true);
    expect($data)->toMatchArray([
        'replayed' => true,
        'shopify_event_id' => 'evt-abc-1',
        'shopify_order_id' => '111222333',
        'already_processed' => true,
        'dispatched' => true,
    ]);

    // Controller's contract with the pipeline: same brand id, same event id,
    // source='manual', and the freshly-fetched payload. The job's own dedup
    // (covered by ProcessShopifyOrderWebhookJob tests) does the rest.
    Bus::assertDispatchedSync(
        ProcessShopifyOrderWebhookJob::class,
        function (ProcessShopifyOrderWebhookJob $job) use ($brand) {
            return (string) $job->brandProfessionalId === (string) $brand->id
                && $job->shopifyEventId === 'evt-abc-1'
                && $job->source === 'manual'
                && Arr::get($job->orderPayload, 'id') === '111222333';
        }
    );

    // The controller alone does not touch commerce.orders/order_events, so
    // counts are unchanged. The job's idempotency (which this controller
    // depends on) is asserted in the next test.
    expect(DB::table('commerce.orders')->count())->toBe($ordersBefore);
    expect(DB::table('commerce.order_events')->count())->toBe($eventsBefore);
});

it('preserves the dedup invariant: inserting the same shopify_event_id twice raises a unique-constraint violation', function () {
    // This test guards the property the controller relies on. If a schema
    // change ever loosens the unique partial index on
    // commerce.order_events.shopify_event_id, the replay endpoint stops being
    // safe — and this test fails loudly before that lands.
    $brand = makeReplayBrand();
    seedOrderAndEvent($brand, '111222333', 'evt-abc-1');

    $duplicate = function () {
        DB::table('commerce.order_events')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => (string) Str::uuid(),
            'event_type' => 'paid',
            'source' => 'manual',
            'shopify_event_id' => 'evt-abc-1',
            'shopify_triggered_at' => '2026-05-01T10:00:00+00:00',
            'metadata' => '{}',
        ]);
    };

    expect($duplicate)->toThrow(\Exception::class);
});

it('returns 502 when the Shopify fetch fails', function () {
    $brand = makeReplayBrand();
    seedOrderAndEvent($brand, '111222333', 'evt-abc-1');

    $client = Mockery::mock(ShopifyAdminClient::class);
    $client->shouldReceive('rest')
        ->once()
        ->andThrow(new \App\Exceptions\Shopify\ShopifyTransportException('brand-a.myshopify.com', 500, 'boom'));

    $controller = new StaffShopifyEventReplayController($client);
    $response = $controller->invoke(replayRequest(['shopify_event_id' => 'evt-abc-1']), $brand);

    expect($response->status())->toBe(502);
});

it('returns 502 when Shopify returns an empty payload', function () {
    $brand = makeReplayBrand();
    seedOrderAndEvent($brand, '111222333', 'evt-abc-1');

    $fakeResponse = Mockery::mock(HttpResponse::class);
    $fakeResponse->shouldReceive('json')->with('order')->andReturn(null);

    $client = Mockery::mock(ShopifyAdminClient::class);
    $client->shouldReceive('rest')->once()->andReturn($fakeResponse);

    $controller = new StaffShopifyEventReplayController($client);
    $response = $controller->invoke(replayRequest(['shopify_event_id' => 'evt-abc-1']), $brand);

    expect($response->status())->toBe(502);
});

it('rate-limits replays for the same event after 3 attempts in a minute', function () {
    $brand = makeReplayBrand();
    seedOrderAndEvent($brand, '111222333', 'evt-abc-1');

    // Pre-fill the bucket past the limit.
    RateLimiter::hit('shopify-event-replay:evt-abc-1', 60);
    RateLimiter::hit('shopify-event-replay:evt-abc-1', 60);
    RateLimiter::hit('shopify-event-replay:evt-abc-1', 60);

    $client = Mockery::mock(ShopifyAdminClient::class);
    // Shopify should NOT be called once we hit the limit.
    $client->shouldNotReceive('rest');

    $controller = new StaffShopifyEventReplayController($client);
    $response = $controller->invoke(replayRequest(['shopify_event_id' => 'evt-abc-1']), $brand);

    expect($response->status())->toBe(429);
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});
