<?php

use App\Services\Cache\CacheLockService;
use App\Services\Notifications\CommerceNotificationService;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// CACHE-10: every booking-completion was triggering a fresh COUNT(*)+SUM over
// analytics.booking_events. These tests pin the 60s memoisation that makes the
// second-and-subsequent bookings in a burst skip the scan.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.booking_events (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        amount_paid_cents INTEGER NOT NULL DEFAULT 0,
        occurred_at TEXT NULL,
        created_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        type TEXT NULL,
        category TEXT NULL,
        title TEXT NULL,
        body TEXT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        dedupe_key TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE(professional_id, dedupe_key)
    )');

    Cache::flush();
});

function makeProWithBookings(int $count, int $perBookingCents = 1000): string
{
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'pro-'.Str::random(6),
        'display_name' => 'Test Pro',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'professional_id' => $proId,
            'amount_paid_cents' => $perBookingCents,
            'occurred_at' => now()->subMinutes($i + 1)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ];
    }
    DB::connection('pgsql')->table('analytics.booking_events')->insert($rows);

    return $proId;
}

function bookingEventScanCount(array $queries): int
{
    // Query log SQL is quoted by the connection grammar (e.g. `"analytics"."booking_events"`
    // on sqlite via the schema-attach trick), so strip quotes/dots before substring matching.
    return collect($queries)
        ->filter(function (array $q): bool {
            $sql = str_replace(['"', '.', '`'], '', (string) $q['sql']);

            return stripos($sql, 'analyticsbooking_events') !== false
                && stripos($sql, 'count(*)') !== false;
        })
        ->count();
}

function notifyCompleted(CommerceNotificationService $svc, string $proId): void
{
    $svc->notifyBookingCompleted([
        'professional_id' => $proId,
        'booking_event_id' => (string) Str::uuid(),
        'service_name' => 'Cut',
        'customer_name' => 'Customer',
        'amount_paid_cents' => 1000,
        'currency_code' => 'AUD',
    ]);
}

it('only scans booking_events once across a burst of bookings inside the 60s TTL', function () {
    $proId = makeProWithBookings(2_000); // simulates the "top professional" worst case

    $svc = new CommerceNotificationService(new NotificationPublisher, new CacheLockService);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
    });

    // First booking: cold cache, should scan.
    notifyCompleted($svc, $proId);
    $afterFirst = bookingEventScanCount($queries);

    // Three more bookings in the same TTL window — every one of these previously
    // triggered a 2,000-row scan. Now they should hit the cache.
    notifyCompleted($svc, $proId);
    notifyCompleted($svc, $proId);
    notifyCompleted($svc, $proId);

    $afterFour = bookingEventScanCount($queries);

    expect($afterFirst)->toBe(1);
    expect($afterFour)->toBe(1);
});

it('rescans booking_events once the cached totals expire', function () {
    $proId = makeProWithBookings(3);

    $svc = new CommerceNotificationService(new NotificationPublisher, new CacheLockService);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
    });

    notifyCompleted($svc, $proId);
    expect(bookingEventScanCount($queries))->toBe(1);

    // Manually evict the cached totals (simulates TTL expiry without sleeping).
    Cache::forget(\App\Services\Cache\CacheKeyGenerator::bookingMilestoneTotals($proId));
    Cache::forget(\App\Services\Cache\CacheKeyGenerator::bookingMilestoneTotals($proId).':stale');

    notifyCompleted($svc, $proId);
    expect(bookingEventScanCount($queries))->toBe(2);
});
