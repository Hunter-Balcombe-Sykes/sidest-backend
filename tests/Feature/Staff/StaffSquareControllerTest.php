<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffSquareController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Square\SquareTokenService;
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

    // Truncate so each test starts clean.
    $conn->statement('DELETE FROM core.professional_integrations');
});

function makeStaffSquareProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();

    return $pro;
}

function makeSquareIntegrationFor(Professional $pro, ?string $token = 'sq0atp-xyz'): ProfessionalIntegration
{
    $integration = new ProfessionalIntegration([
        'professional_id' => $pro->id,
        'provider' => ProfessionalIntegration::PROVIDER_SQUARE,
        'external_account_id' => 'merchant-123',
        'access_token' => $token,
        'refresh_token' => 'refresh-abc',
    ]);
    $integration->id = (string) Str::uuid();
    $integration->save();

    return $integration;
}

it('status returns connected=false when no Square integration exists', function () {
    $pro = makeStaffSquareProfessional();
    $controller = new StaffSquareController(Mockery::mock(SquareTokenService::class));

    $response = $controller->status(Request::create('/', 'GET'), $pro);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toMatchArray([
            'connected' => false,
            'merchant_id' => null,
            'expires_at' => null,
        ]);
});

it('status returns connected=true with merchant_id when Square is connected', function () {
    $pro = makeStaffSquareProfessional();
    makeSquareIntegrationFor($pro);

    $controller = new StaffSquareController(Mockery::mock(SquareTokenService::class));
    $response = $controller->status(Request::create('/', 'GET'), $pro);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['connected'])->toBeTrue()
        ->and($data['merchant_id'])->toBe('merchant-123');
});

it('disconnect revokes token at Square and deletes the integration row', function () {
    $pro = makeStaffSquareProfessional();
    $integration = makeSquareIntegrationFor($pro);

    $tokenService = Mockery::mock(SquareTokenService::class);
    $tokenService->shouldReceive('revokeToken')
        ->once()
        ->with(Mockery::on(fn ($arg) => $arg instanceof ProfessionalIntegration && $arg->id === $integration->id));

    $controller = new StaffSquareController($tokenService);
    $request = Request::create('/', 'POST');
    $request->attributes->set('partna_staff', (object) ['id' => 'staff-uuid-1']);

    $response = $controller->disconnect($request, $pro);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['connected'])->toBeFalse();

    // Integration row should be gone.
    $remaining = ProfessionalIntegration::query()
        ->where('professional_id', $pro->id)
        ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE)
        ->count();
    expect($remaining)->toBe(0);
});

it('disconnect is idempotent when no Square integration exists', function () {
    $pro = makeStaffSquareProfessional();

    $tokenService = Mockery::mock(SquareTokenService::class);
    // No revoke should be called.
    $tokenService->shouldNotReceive('revokeToken');

    $controller = new StaffSquareController($tokenService);
    $request = Request::create('/', 'POST');
    $request->attributes->set('partna_staff', (object) ['id' => 'staff-uuid-1']);

    $response = $controller->disconnect($request, $pro);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['connected'])->toBeFalse();
});

it('disconnect continues even when revokeToken throws', function () {
    $pro = makeStaffSquareProfessional();
    makeSquareIntegrationFor($pro);

    $tokenService = Mockery::mock(SquareTokenService::class);
    $tokenService->shouldReceive('revokeToken')->andThrow(new \RuntimeException('Square down'));

    $controller = new StaffSquareController($tokenService);
    $request = Request::create('/', 'POST');
    $request->attributes->set('partna_staff', (object) ['id' => 'staff-uuid-1']);

    $response = $controller->disconnect($request, $pro);

    expect($response->status())->toBe(200);
    expect(ProfessionalIntegration::query()
        ->where('professional_id', $pro->id)
        ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE)
        ->count())->toBe(0);
});
