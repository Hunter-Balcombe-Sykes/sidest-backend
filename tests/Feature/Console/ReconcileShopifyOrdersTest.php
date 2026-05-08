<?php

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Tests for sidest:reconcile-shopify-orders — the Phase 3 backstop that pulls
// Shopify orders updated since reconciled_through and dispatches the webhook job
// for missing or stale local rows.

beforeEach(function () {
    setupProfessionalsTable();
    setupProfessionalIntegrationsTable();
    setupCommerceOrdersTables();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Insert a minimal Shopify integration row.
 * Returns the integration id.
 */
function insertReconcileIntegration(
    string $professionalId,
    string $shopDomain = 'brand-a.myshopify.com',
    ?string $reconciledThrough = null,
): string {
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => $id,
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => $shopDomain,
        // access_token is cast as 'encrypted' on the model — store pre-encrypted value
        // so $integration->access_token decrypts to a non-empty string.
        'access_token' => encrypt('shpat_test'),
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'reconciled_through' => $reconciledThrough,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

/**
 * Insert a minimal professional row. Returns the id.
 */
function insertReconcileProfessional(string $handle = 'brand-a'): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

/**
 * Insert a minimal commerce.orders row.
 */
function insertLocalOrder(
    string $shopDomain,
    string $shopifyOrderId,
    string $brandId,
    string $affiliateId,
    string $shopifyUpdatedAt,
): void {
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => (string) Str::uuid(),
        'shopify_order_id' => $shopifyOrderId,
        'shopify_shop_domain' => $shopDomain,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => 10000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 10000,
        'commission_cents' => 1500,
        'commission_rate' => 15.0,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'line_items' => '[]',
        'shopify_data' => '{}',
        'shopify_updated_at' => $shopifyUpdatedAt,
        'occurred_at' => $shopifyUpdatedAt,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * Build a fake Shopify orders REST response for a given list of order stubs.
 *
 * Response::body() calls (string) $psrResponse->getBody(), which invokes
 * StreamInterface::__toString() — NOT getContents(). We use a GuzzleHttp
 * stream stub that handles __toString correctly.
 *
 * @param  array<int, array<string, mixed>>  $orders
 */
function fakeOrdersResponse(array $orders, string $nextPageInfo = ''): Response
{
    $body = json_encode(['orders' => $orders]);
    $stream = GuzzleHttp\Psr7\Utils::streamFor($body);

    $headers = ['Content-Type' => ['application/json']];

    if ($nextPageInfo !== '') {
        $headers['Link'] = [
            '<https://shop.myshopify.com/admin/api/2025-01/orders.json?page_info='.$nextPageInfo.'>; rel="next"',
        ];
    }

    $psrResponse = new GuzzleHttp\Psr7\Response(
        status: 200,
        headers: $headers,
        body: $stream,
    );

    return new Response($psrResponse);
}

/**
 * Build a minimal Shopify order payload for use in the mocked response.
 *
 * @return array<string, mixed>
 */
function shopifyOrderStub(string $id, string $updatedAt, string $affiliateSlug = 'affiliate-a'): array
{
    return [
        'id' => $id,
        'domain' => 'brand-a.myshopify.com',
        'name' => '#'.$id,
        'financial_status' => 'paid',
        'currency' => 'AUD',
        'total_price' => '100.00',
        'subtotal_price' => '100.00',
        'total_discounts' => '0.00',
        'note_attributes' => [['name' => 'affiliate', 'value' => $affiliateSlug]],
        'line_items' => [
            [
                'id' => '5001',
                'product_id' => '8001',
                'variant_id' => '9001',
                'title' => 'Product A',
                'price' => '100.00',
                'quantity' => 1,
                'total_discount' => '0.00',
                'sku' => 'SKU-A',
            ],
        ],
        'created_at' => '2026-05-01T10:00:00+00:00',
        'updated_at' => $updatedAt,
    ];
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('skips an order whose local row has a NEWER shopify_updated_at', function () {
    Bus::fake();

    $proId = insertReconcileProfessional('brand-skip');
    $affId = (string) Str::uuid();
    insertReconcileIntegration($proId, 'brand-skip.myshopify.com');

    // Local row updated_at is 1 hour AFTER what Shopify reports → skip.
    $localUpdatedAt = '2026-05-01T11:00:00+00:00';
    $shopUpdatedAt = '2026-05-01T10:00:00+00:00';

    insertLocalOrder('brand-skip.myshopify.com', '111', $proId, $affId, $localUpdatedAt);

    $shopOrder = shopifyOrderStub('111', $shopUpdatedAt);

    $mockClient = Mockery::mock(ShopifyAdminClient::class);
    $mockClient->shouldReceive('rest')
        ->once()
        ->andReturn(fakeOrdersResponse([$shopOrder]));

    app()->instance(ShopifyAdminClient::class, $mockClient);

    $this->artisan('partna:reconcile-shopify-orders', ['--integration' => DB::connection('pgsql')
        ->table('core.professional_integrations')
        ->where('professional_id', $proId)
        ->value('id'),
    ])->assertExitCode(0);

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('dispatches for an order whose local row has an OLDER shopify_updated_at', function () {
    Bus::fake();

    $proId = insertReconcileProfessional('brand-stale');
    $affId = (string) Str::uuid();
    $integrationId = insertReconcileIntegration($proId, 'brand-stale.myshopify.com');

    // Local row is 1 hour behind Shopify → dispatch.
    $localUpdatedAt = '2026-05-01T09:00:00+00:00';
    $shopUpdatedAt = '2026-05-01T10:00:00+00:00';

    insertLocalOrder('brand-stale.myshopify.com', '222', $proId, $affId, $localUpdatedAt);

    $shopOrder = shopifyOrderStub('222', $shopUpdatedAt);

    $mockClient = Mockery::mock(ShopifyAdminClient::class);
    $mockClient->shouldReceive('rest')
        ->once()
        ->andReturn(fakeOrdersResponse([$shopOrder]));

    app()->instance(ShopifyAdminClient::class, $mockClient);

    $this->artisan('partna:reconcile-shopify-orders', ['--integration' => $integrationId])
        ->assertExitCode(0);

    Bus::assertDispatchedSync(ProcessShopifyOrderWebhookJob::class, function (ProcessShopifyOrderWebhookJob $job) {
        return $job->source === 'reconciler'
            && $job->shopifyEventId === ''
            && Arr::get($job->orderPayload, 'id') === '222';
    });
});

it('dispatches for an order with NO local row', function () {
    Bus::fake();

    $proId = insertReconcileProfessional('brand-missing');
    $integrationId = insertReconcileIntegration($proId, 'brand-missing.myshopify.com');

    // No local row for order 333.
    $shopOrder = shopifyOrderStub('333', '2026-05-01T10:00:00+00:00');

    $mockClient = Mockery::mock(ShopifyAdminClient::class);
    $mockClient->shouldReceive('rest')
        ->once()
        ->andReturn(fakeOrdersResponse([$shopOrder]));

    app()->instance(ShopifyAdminClient::class, $mockClient);

    $this->artisan('partna:reconcile-shopify-orders', ['--integration' => $integrationId])
        ->assertExitCode(0);

    Bus::assertDispatchedSync(ProcessShopifyOrderWebhookJob::class, function (ProcessShopifyOrderWebhookJob $job) {
        return $job->source === 'reconciler' && Arr::get($job->orderPayload, 'id') === '333';
    });
});

it('continues to the next integration when one throws', function () {
    Bus::fake();

    // Integration A: Shopify client will throw.
    $proA = insertReconcileProfessional('brand-throws');
    $integrationA = insertReconcileIntegration($proA, 'brand-throws.myshopify.com');

    // Integration B: valid, has one missing order.
    $proB = insertReconcileProfessional('brand-continues');
    $integrationB = insertReconcileIntegration($proB, 'brand-continues.myshopify.com');
    $shopOrder = shopifyOrderStub('444', '2026-05-01T10:00:00+00:00');

    $mockClient = Mockery::mock(ShopifyAdminClient::class);
    // First call (brand-throws) — throw a transport exception.
    $mockClient->shouldReceive('rest')
        ->once()
        ->with(Mockery::any(), 'brand-throws.myshopify.com', Mockery::any(), Mockery::any())
        ->andThrow(new \RuntimeException('Connection refused'));

    // Second call (brand-continues) — return one order.
    $mockClient->shouldReceive('rest')
        ->once()
        ->with(Mockery::any(), 'brand-continues.myshopify.com', Mockery::any(), Mockery::any())
        ->andReturn(fakeOrdersResponse([$shopOrder]));

    app()->instance(ShopifyAdminClient::class, $mockClient);

    Log::shouldReceive('error')->once()->with('ReconcileShopifyOrders: integration failed', Mockery::any());
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    // Run without --integration filter so both integrations are processed.
    $this->artisan('partna:reconcile-shopify-orders')
        ->assertExitCode(0);

    // Brand B's order should still be dispatched despite A failing.
    Bus::assertDispatchedSync(ProcessShopifyOrderWebhookJob::class, function (ProcessShopifyOrderWebhookJob $job) use ($proB) {
        return $job->brandProfessionalId === $proB
            && Arr::get($job->orderPayload, 'id') === '444';
    });
});

it('does NOT dispatch in --dry-run mode', function () {
    Bus::fake();

    $proId = insertReconcileProfessional('brand-dryrun');
    $integrationId = insertReconcileIntegration($proId, 'brand-dryrun.myshopify.com');

    // No local row → would normally dispatch.
    $shopOrder = shopifyOrderStub('555', '2026-05-01T10:00:00+00:00');

    $mockClient = Mockery::mock(ShopifyAdminClient::class);
    $mockClient->shouldReceive('rest')
        ->once()
        ->andReturn(fakeOrdersResponse([$shopOrder]));

    app()->instance(ShopifyAdminClient::class, $mockClient);

    $this->artisan('partna:reconcile-shopify-orders', [
        '--integration' => $integrationId,
        '--dry-run' => true,
    ])->assertExitCode(0);

    Bus::assertNothingDispatched();
});

it('passes the --since override timestamp to the Shopify REST call', function () {
    Bus::fake();

    $proId = insertReconcileProfessional('brand-since');
    $integrationId = insertReconcileIntegration($proId, 'brand-since.myshopify.com');

    $since = '2026-04-01T00:00:00+00:00';

    $mockClient = Mockery::mock(ShopifyAdminClient::class);
    $mockClient->shouldReceive('rest')
        ->once()
        ->with(
            'GET',
            'brand-since.myshopify.com',
            Mockery::any(),
            // The path must contain the updated_at_min with the overridden since value.
            Mockery::on(fn ($path) => str_contains($path, urlencode($since)) || str_contains($path, '2026-04-01')),
        )
        ->andReturn(fakeOrdersResponse([]));

    app()->instance(ShopifyAdminClient::class, $mockClient);

    $this->artisan('partna:reconcile-shopify-orders', [
        '--integration' => $integrationId,
        '--since' => $since,
    ])->assertExitCode(0);
});
