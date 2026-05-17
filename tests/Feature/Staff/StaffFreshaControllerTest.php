<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffFreshaController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Fresha\FreshaTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

function makeStaffFreshaProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();

    return $pro;
}

function makeFreshaIntegrationFor(Professional $pro, ?string $token = 'fresha-token'): ProfessionalIntegration
{
    $integration = new ProfessionalIntegration([
        'professional_id' => $pro->id,
        'provider' => ProfessionalIntegration::PROVIDER_FRESHA,
        'external_account_id' => 'business-456',
        'access_token' => $token,
        'refresh_token' => 'refresh-def',
    ]);
    $integration->id = (string) Str::uuid();
    $integration->save();

    return $integration;
}

it('status returns connected=false when no Fresha integration exists', function () {
    $pro = makeStaffFreshaProfessional();
    $controller = new StaffFreshaController(Mockery::mock(FreshaTokenService::class));

    $response = $controller->status(Request::create('/', 'GET'), $pro);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toMatchArray([
            'connected' => false,
            'business_id' => null,
            'expires_at' => null,
        ]);
});

it('status returns connected=true with business_id when Fresha is connected', function () {
    $pro = makeStaffFreshaProfessional();
    makeFreshaIntegrationFor($pro);

    $controller = new StaffFreshaController(Mockery::mock(FreshaTokenService::class));
    $response = $controller->status(Request::create('/', 'GET'), $pro);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['connected'])->toBeTrue()
        ->and($data['business_id'])->toBe('business-456');
});

it('disconnect revokes token at Fresha and deletes the integration row', function () {
    $pro = makeStaffFreshaProfessional();
    $integration = makeFreshaIntegrationFor($pro);

    $tokenService = Mockery::mock(FreshaTokenService::class);
    $tokenService->shouldReceive('revokeToken')
        ->once()
        ->with(Mockery::on(fn ($arg) => $arg instanceof ProfessionalIntegration && $arg->id === $integration->id));

    $controller = new StaffFreshaController($tokenService);
    $request = Request::create('/', 'POST');
    $request->attributes->set('partna_staff', (object) ['id' => 'staff-uuid-1']);

    $response = $controller->disconnect($request, $pro);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['connected'])->toBeFalse();

    expect(ProfessionalIntegration::query()
        ->where('professional_id', $pro->id)
        ->where('provider', ProfessionalIntegration::PROVIDER_FRESHA)
        ->count())->toBe(0);
});

it('disconnect is idempotent when no Fresha integration exists', function () {
    $pro = makeStaffFreshaProfessional();

    $tokenService = Mockery::mock(FreshaTokenService::class);
    $tokenService->shouldNotReceive('revokeToken');

    $controller = new StaffFreshaController($tokenService);
    $request = Request::create('/', 'POST');
    $request->attributes->set('partna_staff', (object) ['id' => 'staff-uuid-1']);

    $response = $controller->disconnect($request, $pro);

    expect($response->status())->toBe(200)
        ->and(json_decode($response->getContent(), true)['connected'])->toBeFalse();
});

it('disconnect continues even when revokeToken throws', function () {
    $pro = makeStaffFreshaProfessional();
    makeFreshaIntegrationFor($pro);

    $tokenService = Mockery::mock(FreshaTokenService::class);
    $tokenService->shouldReceive('revokeToken')->andThrow(new \RuntimeException('Fresha down'));

    $controller = new StaffFreshaController($tokenService);
    $request = Request::create('/', 'POST');
    $request->attributes->set('partna_staff', (object) ['id' => 'staff-uuid-1']);

    $response = $controller->disconnect($request, $pro);

    expect($response->status())->toBe(200);
    expect(ProfessionalIntegration::query()
        ->where('professional_id', $pro->id)
        ->where('provider', ProfessionalIntegration::PROVIDER_FRESHA)
        ->count())->toBe(0);
});
