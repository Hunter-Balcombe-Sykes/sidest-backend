<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffStoreSettingsController;
use App\Models\Core\Professional\Professional;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Hydrogen\HydrogenDeploymentService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS brand");
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
        id TEXT PRIMARY KEY,
        professional_id TEXT UNIQUE NOT NULL,
        default_commission_rate TEXT,
        payout_hold_days INTEGER,
        theme_id INTEGER,
        oxygen_deployment_token TEXT,
        oxygen_storefront_id TEXT,
        hydrogen_install_confirmed INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    )');
});

function makeStaffSettingsController(?BrandCatalogService $catalog = null, ?HydrogenDeploymentService $deployment = null): StaffStoreSettingsController
{
    return new StaffStoreSettingsController(
        $catalog ?? Mockery::mock(BrandCatalogService::class),
        $deployment ?? Mockery::mock(HydrogenDeploymentService::class),
    );
}

function makeStaffSettingsProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();

    return $pro;
}

it('returns updated store settings after patch', function () {
    $professional = makeStaffSettingsProfessional();

    $controller = makeStaffSettingsController();
    $request = Request::create('/', 'PATCH', [
        'default_commission_rate' => 20,
        'payout_hold_days' => 14,
    ]);

    $response = $controller->update($request, $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKeys(['default_commission_rate', 'payout_hold_days'])
        ->and($data['default_commission_rate'])->toEqual(20.0)
        ->and($data['payout_hold_days'])->toBe(14);
});

it('is idempotent — second patch overwrites first', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = makeStaffSettingsController();

    $controller->update(Request::create('/', 'PATCH', ['payout_hold_days' => 14]), $professional);
    $response = $controller->update(Request::create('/', 'PATCH', ['payout_hold_days' => 28]), $professional);
    $data = json_decode($response->getContent(), true);

    // Staff controller's validation rule is `in:0,7,14,28` (same as the brand-facing tier
    // set) — the previous 21 here was outside the allowed set. Using 28 keeps the
    // idempotent-overwrite semantics while staying inside the validated tier list.
    expect($response->status())->toBe(200)
        ->and($data['payout_hold_days'])->toBe(28);
});

it('returns 422 when no updatable fields provided', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = makeStaffSettingsController();
    $request = Request::create('/', 'PATCH', []);

    $response = $controller->update($request, $professional);

    expect($response->status())->toBe(422);
});

it('rejects commission rate below 0', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = makeStaffSettingsController();
    $request = Request::create('/', 'PATCH', ['default_commission_rate' => -1]);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects commission rate above 100', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = makeStaffSettingsController();
    $request = Request::create('/', 'PATCH', ['default_commission_rate' => 101]);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects payout_hold_days below system minimum of 7', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = makeStaffSettingsController();
    $request = Request::create('/', 'PATCH', ['payout_hold_days' => 1]);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('returns 422 on deploy when no store settings exist', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = makeStaffSettingsController();

    $response = $controller->deploy(Request::create('/', 'POST'), $professional);

    expect($response->status())->toBe(422);
});

it('pushes commission_rate to Shopify metafield and returns deployed:true', function () {
    $professional = makeStaffSettingsProfessional();
    // Seed the DB state staff has just edited
    BrandStoreSettings::create([
        'professional_id' => $professional->id,
        'default_commission_rate' => 25,
    ]);

    $integration = new ProfessionalIntegration([
        'professional_id' => $professional->id,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
    ]);

    $catalog = Mockery::mock(BrandCatalogService::class);
    $catalog->shouldReceive('resolveBrandIntegration')
        ->once()
        ->andReturn(['integration' => $integration, 'shop_domain' => 'x.myshopify.com', 'access_token' => 't', 'metadata' => []]);
    $catalog->shouldReceive('setShopMetafields')
        ->once()
        ->withArgs(function ($int, array $metafields) use ($integration) {
            return $int === $integration
                && $metafields[0]['key'] === 'default_commission_rate'
                // decimal:2 cast → "25.00"; just verify the float value
                && (float) $metafields[0]['value'] === 25.0
                && $metafields[0]['type'] === 'number_decimal';
        })
        ->andReturn(['success' => true, 'userErrors' => []]);

    $deployment = Mockery::mock(HydrogenDeploymentService::class);
    // No oxygen_deployment_token → Hydrogen rebuild must NOT be dispatched
    $deployment->shouldNotReceive('dispatchDeployment');

    $controller = makeStaffSettingsController($catalog, $deployment);

    Log::spy();
    $response = $controller->deploy(Request::create('/', 'POST'), $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['deployed'])->toBeTrue()
        ->and($data['hydrogen_rebuild_triggered'])->toBeFalse()
        ->and((float) $data['default_commission_rate'])->toEqual(25.0);

    Log::shouldHaveReceived('info')->with('staff-deploy: brand store settings pushed to Shopify', Mockery::on(fn ($ctx) => $ctx['action'] === 'staff-deploy' && $ctx['professional_id'] === $professional->id));
});

it('triggers Hydrogen rebuild when oxygen_deployment_token is set', function () {
    $professional = makeStaffSettingsProfessional();
    BrandStoreSettings::create([
        'professional_id' => $professional->id,
        'default_commission_rate' => 30,
    ]);
    // Oxygen token is not in $fillable — set directly
    $settings = BrandStoreSettings::where('professional_id', $professional->id)->first();
    $settings->oxygen_deployment_token = 'shp_abc123';
    $settings->save();

    $integration = new ProfessionalIntegration(['professional_id' => $professional->id]);

    $catalog = Mockery::mock(BrandCatalogService::class);
    $catalog->shouldReceive('resolveBrandIntegration')->once()->andReturn(['integration' => $integration, 'shop_domain' => 'x', 'access_token' => 't', 'metadata' => []]);
    $catalog->shouldReceive('setShopMetafields')->once()->andReturn(['success' => true, 'userErrors' => []]);

    $deployment = Mockery::mock(HydrogenDeploymentService::class);
    $deployment->shouldReceive('dispatchDeployment')->once()->with($professional->id);

    $controller = makeStaffSettingsController($catalog, $deployment);

    $response = $controller->deploy(Request::create('/', 'POST'), $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['hydrogen_rebuild_triggered'])->toBeTrue();
});

it('returns 422 when Shopify rejects the metafield write', function () {
    $professional = makeStaffSettingsProfessional();
    BrandStoreSettings::create([
        'professional_id' => $professional->id,
        'default_commission_rate' => 15,
    ]);

    $integration = new ProfessionalIntegration(['professional_id' => $professional->id]);

    $catalog = Mockery::mock(BrandCatalogService::class);
    $catalog->shouldReceive('resolveBrandIntegration')->once()->andReturn(['integration' => $integration, 'shop_domain' => 'x', 'access_token' => 't', 'metadata' => []]);
    $catalog->shouldReceive('setShopMetafields')->once()->andReturn([
        'success' => false,
        'userErrors' => [['message' => 'Metafield type mismatch.']],
    ]);

    $deployment = Mockery::mock(HydrogenDeploymentService::class);
    $deployment->shouldNotReceive('dispatchDeployment');

    $controller = makeStaffSettingsController($catalog, $deployment);

    $response = $controller->deploy(Request::create('/', 'POST'), $professional);

    expect($response->status())->toBe(422);
});

it('returns 422 on deploy when Shopify is not connected', function () {
    $professional = makeStaffSettingsProfessional();
    BrandStoreSettings::create([
        'professional_id' => $professional->id,
        'default_commission_rate' => 15,
    ]);

    $catalog = Mockery::mock(BrandCatalogService::class);
    $catalog->shouldReceive('resolveBrandIntegration')
        ->andThrow(new \RuntimeException('Your Shopify store is not connected.', 422));

    $deployment = Mockery::mock(HydrogenDeploymentService::class);

    $controller = makeStaffSettingsController($catalog, $deployment);

    $response = $controller->deploy(Request::create('/', 'POST'), $professional);

    expect($response->status())->toBe(422);
});
