<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandSetupController;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandOnboardingReadinessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupBrandProfilesTable();
    // The Pest helper's brand_profiles schema is intentionally minimal — extend
    // it here with the columns this controller surfaces so the test can write
    // and read full setup-status payloads without a global schema change.
    DB::connection('pgsql')->statement('ALTER TABLE brand.brand_profiles ADD COLUMN legal_business_name TEXT');
    DB::connection('pgsql')->statement('ALTER TABLE brand.brand_profiles ADD COLUMN business_type TEXT');
    DB::connection('pgsql')->statement('ALTER TABLE brand.brand_profiles ADD COLUMN industries TEXT');
});

function makeStaffSetupProfessional(): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'setup-'.substr($id, 0, 8),
        'handle_lc' => 'setup-'.substr($id, 0, 8),
        'display_name' => 'Setup Brand',
        'primary_email' => 'setup-'.substr($id, 0, 8).'@example.com',
        'professional_type' => 'brand',
        'status' => 'active',
    ]);

    return Professional::query()->find($id);
}

it('delegates onboarding-readiness to BrandOnboardingReadinessService', function () {
    $pro = makeStaffSetupProfessional();

    $checklist = [
        'images_uploaded' => true,
        'shopify_connected' => false,
        'stripe_connected' => false,
        'ready' => false,
    ];

    $readinessService = Mockery::mock(BrandOnboardingReadinessService::class);
    $readinessService->shouldReceive('getChecklist')
        ->once()
        ->withArgs(fn (Professional $arg) => $arg->id === $pro->id)
        ->andReturn($checklist);

    $controller = new StaffBrandSetupController($readinessService);
    $response = $controller->readiness($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toBe($checklist);
});

it('returns setup_complete=false plus missing_fields when brand_profile is empty', function () {
    $pro = makeStaffSetupProfessional();

    $readinessService = Mockery::mock(BrandOnboardingReadinessService::class);
    $controller = new StaffBrandSetupController($readinessService);
    $response = $controller->setupStatus($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['setup_complete'])->toBeFalse()
        ->and($body['missing_fields'])->toContain('legal_business_name', 'business_type', 'industries');
});

it('returns setup_complete=true when brand_profile has every required field', function () {
    $pro = makeStaffSetupProfessional();

    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'brand_status' => 'active',
        'setup_complete' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // BrandProfile model casts industries from JSONB. The test schema is barebones
    // (setupBrandProfilesTable only has minimal columns) so we patch the model in
    // memory rather than extending the schema for one test.
    $brandProfile = \App\Models\Core\Professional\BrandProfile::query()
        ->where('professional_id', $pro->id)
        ->first();
    $brandProfile->legal_business_name = 'Acme Pty Ltd';
    $brandProfile->business_type = 'pty_ltd';
    $brandProfile->industries = ['fashion'];
    $brandProfile->save();

    $readinessService = Mockery::mock(BrandOnboardingReadinessService::class);
    $controller = new StaffBrandSetupController($readinessService);
    $response = $controller->setupStatus($pro);
    $body = $response->getData(true);

    expect($body['setup_complete'])->toBeTrue()
        ->and($body['missing_fields'])->toBeEmpty();
});
