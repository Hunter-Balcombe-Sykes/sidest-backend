<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffBookingController;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
});

function makeStaffBookingProfessional(array $siteSettings = []): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'book-'.substr($id, 0, 8),
        'handle_lc' => 'book-'.substr($id, 0, 8),
        'display_name' => 'Booking Pro',
        'primary_email' => 'book-'.substr($id, 0, 8).'@example.com',
        'professional_type' => 'professional',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $id,
        'subdomain' => 'book-'.substr($id, 0, 8),
        'settings' => json_encode($siteSettings),
        'is_published' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return Professional::query()->find($id);
}

it('returns booking_mode=manual when nothing is stored', function () {
    $pro = makeStaffBookingProfessional();
    $controller = new StaffBookingController(new CacheLockService);

    $response = $controller->settings($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['booking_mode'])->toBe('manual')
        ->and($body['manual_booking_url'])->toBeNull()
        ->and($body['services_auto_sync_enabled'])->toBeFalse();
});

it('returns the stored booking_mode and manual_booking_url', function () {
    $pro = makeStaffBookingProfessional([
        'booking_mode' => 'smart',
        'manual_booking_url' => 'https://example.com/book',
        'services_auto_sync_enabled' => true,
    ]);
    $controller = new StaffBookingController(new CacheLockService);

    $response = $controller->settings($pro);
    $body = $response->getData(true);

    expect($body['booking_mode'])->toBe('smart')
        ->and($body['manual_booking_url'])->toBe('https://example.com/book')
        ->and($body['services_auto_sync_enabled'])->toBeTrue();
});

it('short-circuits analytics with smart_mode_required when the brand is in manual mode', function () {
    $pro = makeStaffBookingProfessional(['booking_mode' => 'manual']);
    $controller = new StaffBookingController(new CacheLockService);

    $response = $controller->analytics(Request::create('/', 'GET'), $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['smart_mode_required'])->toBeTrue();
});
