<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffShopifyResyncController;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyDataResyncService;
use App\Services\Shopify\ShopifyDisconnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        provider_metadata TEXT,
        shopify_shop_domain TEXT,
        webhook_registration_state TEXT,
        disconnected_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('DELETE FROM core.professional_integrations');
});

function makeStaffShopifyProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();

    return $pro;
}

function makeShopifyIntegrationFor(Professional $pro, ?string $token = 'shpat_test', ?string $integrationId = null): ProfessionalIntegration
{
    $integration = new ProfessionalIntegration([
        'professional_id' => $pro->id,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => $token,
        'provider_metadata' => ['shop_domain' => 'test.myshopify.com'],
    ]);
    $integration->id = $integrationId ?? (string) Str::uuid();
    $integration->save();

    return $integration;
}

it('disconnect returns 404 when no Shopify integration exists', function () {
    $pro = makeStaffShopifyProfessional();

    $disconnectService = Mockery::mock(ShopifyDisconnectService::class);
    $disconnectService->shouldNotReceive('disconnect');

    $controller = new StaffShopifyResyncController(
        Mockery::mock(ShopifyDataResyncService::class),
        $disconnectService,
    );
    $request = Request::create('/', 'POST');
    $request->attributes->set('partna_staff', (object) ['id' => 'staff-uuid-1']);

    $response = $controller->disconnect($request, $pro);

    expect($response->status())->toBe(404);
});

it('disconnect delegates to ShopifyDisconnectService and returns its summary', function () {
    $pro = makeStaffShopifyProfessional();
    makeShopifyIntegrationFor($pro);

    $summary = [
        'teardown' => ['metafield_definitions_deleted' => 5],
        'selections_deleted' => 12,
    ];

    $disconnectService = Mockery::mock(ShopifyDisconnectService::class);
    $disconnectService->shouldReceive('disconnect')
        ->once()
        ->with($pro->id, Mockery::on(fn ($ctx) => is_array($ctx) && ($ctx['actor_staff_id'] ?? null) === 'staff-uuid-1'))
        ->andReturn($summary);

    $controller = new StaffShopifyResyncController(
        Mockery::mock(ShopifyDataResyncService::class),
        $disconnectService,
    );
    $request = Request::create('/', 'POST');
    $request->attributes->set('partna_staff', (object) ['id' => 'staff-uuid-1']);

    $response = $controller->disconnect($request, $pro);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['connected'])->toBeFalse()
        ->and($data['teardown'])->toBe($summary['teardown'])
        ->and($data['selections_deleted'])->toBe(12);
});

it('registerWebhooks returns 404 when no Shopify integration exists', function () {
    $pro = makeStaffShopifyProfessional();
    Queue::fake();

    $controller = new StaffShopifyResyncController(
        Mockery::mock(ShopifyDataResyncService::class),
        Mockery::mock(ShopifyDisconnectService::class),
    );
    $response = $controller->registerWebhooks(Request::create('/', 'POST'), $pro);

    expect($response->status())->toBe(404);
    Queue::assertNothingPushed();
});

it('registerWebhooks returns 404 when integration exists but has no access token', function () {
    $pro = makeStaffShopifyProfessional();
    makeShopifyIntegrationFor($pro, token: null);
    Queue::fake();

    $controller = new StaffShopifyResyncController(
        Mockery::mock(ShopifyDataResyncService::class),
        Mockery::mock(ShopifyDisconnectService::class),
    );
    $response = $controller->registerWebhooks(Request::create('/', 'POST'), $pro);

    expect($response->status())->toBe(404);
    Queue::assertNothingPushed();
});

it('registerWebhooks dispatches RegisterShopifyWebhooksJob and returns queued=true', function () {
    $pro = makeStaffShopifyProfessional();
    $integration = makeShopifyIntegrationFor($pro);
    Queue::fake();

    $controller = new StaffShopifyResyncController(
        Mockery::mock(ShopifyDataResyncService::class),
        Mockery::mock(ShopifyDisconnectService::class),
    );
    $response = $controller->registerWebhooks(Request::create('/', 'POST'), $pro);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['queued'])->toBeTrue()
        ->and($data['integration_id'])->toBe($integration->id);

    Queue::assertPushed(RegisterShopifyWebhooksJob::class, function ($job) use ($integration) {
        $reflection = new ReflectionClass($job);
        $prop = $reflection->getProperty('integrationId');
        $prop->setAccessible(true);

        return $prop->getValue($job) === $integration->id;
    });
});
