<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffStoreSettingsController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        created_at TEXT,
        updated_at TEXT
    )');
});

function makeStaffSettingsProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();

    return $pro;
}

it('returns updated store settings after patch', function () {
    $professional = makeStaffSettingsProfessional();

    $controller = new StaffStoreSettingsController;
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
    $controller = new StaffStoreSettingsController;

    $controller->update(Request::create('/', 'PATCH', ['payout_hold_days' => 14]), $professional);
    $response = $controller->update(Request::create('/', 'PATCH', ['payout_hold_days' => 21]), $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['payout_hold_days'])->toBe(21);
});

it('returns 422 when no updatable fields provided', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = new StaffStoreSettingsController;
    $request = Request::create('/', 'PATCH', []);

    $response = $controller->update($request, $professional);

    expect($response->status())->toBe(422);
});

it('rejects commission rate below 0', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = new StaffStoreSettingsController;
    $request = Request::create('/', 'PATCH', ['default_commission_rate' => -1]);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects commission rate above 100', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = new StaffStoreSettingsController;
    $request = Request::create('/', 'PATCH', ['default_commission_rate' => 101]);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects payout_hold_days below system minimum of 7', function () {
    $professional = makeStaffSettingsProfessional();
    $controller = new StaffStoreSettingsController;
    $request = Request::create('/', 'PATCH', ['payout_hold_days' => 1]);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
