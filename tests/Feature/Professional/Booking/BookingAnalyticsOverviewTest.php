<?php

use App\Http\Controllers\Api\Professional\Booking\BookingAnalyticsController;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();
    setupSitesTable();
    setupBookingEventsTable();
    shimDateTruncForSqlite();
    Cache::flush();
});

/**
 * analytics.booking_events — minimal columns needed by BookingAnalyticsController::myOverview().
 */
function setupBookingEventsTable(): void
{
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.booking_events (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        occurred_at TEXT NULL,
        appointment_start_at TEXT NULL,
        amount_paid_cents INTEGER NOT NULL DEFAULT 0,
        customer_email TEXT NULL,
        customer_name TEXT NULL,
        service_name TEXT NULL,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        payment_method TEXT NULL,
        status TEXT NULL,
        square_booking_id TEXT NULL,
        square_payment_id TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * SQLite shim for PostgreSQL DATE_TRUNC used by the hourly analytics query path.
 * Registered as a UDF on the same PDO handle used by the pgsql connection so
 * queries containing DATE_TRUNC('hour', ...) execute without syntax errors.
 */
function shimDateTruncForSqlite(): void
{
    $conn = DB::connection('pgsql');
    if ($conn->getDriverName() !== 'sqlite') {
        return;
    }

    $conn->getPdo()->sqliteCreateFunction('date_trunc', function (string $granularity, ?string $dt): ?string {
        if ($dt === null) {
            return null;
        }
        $ts = strtotime($dt);

        return match (strtolower($granularity)) {
            'hour' => date('Y-m-d H:00:00', $ts),
            default => date('Y-m-d 00:00:00', $ts),
        };
    }, 2);
}

/**
 * Create a professional whose site has booking_mode = smart, enabling the analytics endpoint.
 */
function makeSmartModePro(string $handle): Professional
{
    $pro = createTenant($handle);

    DB::connection('pgsql')
        ->table('site.sites')
        ->where('professional_id', $pro->id)
        ->update(['settings' => json_encode(['booking_mode' => 'smart'])]);

    return Professional::query()->with('site')->findOrFail($pro->id);
}

function insertBookingEvent(string $professionalId, array $attrs = []): void
{
    DB::connection('pgsql')->table('analytics.booking_events')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'occurred_at' => now()->subMinutes(5)->toDateTimeString(),
        'amount_paid_cents' => 5000,
        'customer_email' => 'customer@example.test',
        'currency_code' => 'AUD',
        'status' => 'completed',
        'created_at' => now()->toDateTimeString(),
    ], $attrs));
}

it('returns zero metrics when no booking events exist', function () {
    $pro = makeSmartModePro('analytics-empty');
    $controller = new BookingAnalyticsController(new CacheLockService);
    $request = tenantRequestAs($pro, ['group_by' => 'hour', 'days' => 1]);

    $response = $controller->myOverview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['totals'])->toMatchArray([
        'bookings_count' => 0,
        'total_spent_cents' => 0,
        'paid_bookings_count' => 0,
        'customers_count' => 0,
    ]);
    expect($data['timeseries'])->toBeArray()->toBeEmpty();
    expect($data['events'])->toBeArray()->toBeEmpty();
});

it('aggregates counts and totals correctly from booking_events', function () {
    $pro = makeSmartModePro('analytics-with-data');

    insertBookingEvent($pro->id, [
        'amount_paid_cents' => 10000,
        'customer_email' => 'alice@example.test',
        'occurred_at' => now()->subMinutes(10)->toDateTimeString(),
    ]);
    insertBookingEvent($pro->id, [
        'amount_paid_cents' => 5000,
        'customer_email' => 'bob@example.test',
        'occurred_at' => now()->subMinutes(20)->toDateTimeString(),
    ]);
    // Free booking: paid_bookings_count should exclude it; alice counts once (distinct email)
    insertBookingEvent($pro->id, [
        'amount_paid_cents' => 0,
        'customer_email' => 'alice@example.test',
        'occurred_at' => now()->subMinutes(30)->toDateTimeString(),
    ]);

    $controller = new BookingAnalyticsController(new CacheLockService);
    $request = tenantRequestAs($pro, ['group_by' => 'hour', 'days' => 1]);

    $response = $controller->myOverview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['totals']['bookings_count'])->toBe(3);
    expect($data['totals']['total_spent_cents'])->toBe(15000);
    expect($data['totals']['paid_bookings_count'])->toBe(2);
    expect($data['totals']['customers_count'])->toBe(2); // alice + bob, alice deduped
    expect($data['totals']['currency_code'])->toBe('AUD');
    expect($data['events'])->toHaveCount(3);
    expect($data['group_by'])->toBe('hour');
});
