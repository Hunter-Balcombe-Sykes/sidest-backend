<?php

use App\Http\Controllers\Api\Professional\Affiliate\AffiliateOrdersController;
use App\Http\Controllers\Api\Professional\Brand\BrandOrdersController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Exercises deriveExpectedPayoutAt on both controllers via reflection.
// The bug it fixes: the old code returned occurred_at + grace_period_days (60d),
// which is the void-deadline, not the actual payout cutoff. Correct behaviour
// reads the brand's payout_hold_days from brand.brand_store_settings.

beforeEach(function () {
    attachTestSchemas();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        payout_hold_days INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        processed_at TEXT,
        created_at TEXT
    )');
});

function seedBrandHold(string $brandId, int $days): void
{
    DB::connection('pgsql')->table('brand.brand_store_settings')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'payout_hold_days' => $days,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function callBrandDerive(object $row, string $brandId): ?string
{
    $controller = new BrandOrdersController;
    $method = (new ReflectionClass($controller))->getMethod('deriveExpectedPayoutAt');
    $method->setAccessible(true);

    return $method->invoke($controller, $row, $brandId);
}

function callAffiliateDerive(object $row, string $brandId): ?string
{
    $controller = new AffiliateOrdersController;
    $method = (new ReflectionClass($controller))->getMethod('deriveExpectedPayoutAt');
    $method->setAccessible(true);

    return $method->invoke($controller, $row, $brandId);
}

it('returns occurred_at unchanged when brand hold is 0 (instant)', function () {
    $brandId = (string) Str::uuid();
    seedBrandHold($brandId, 0);

    $occurredAt = Carbon::parse('2026-05-13T02:47:04Z');
    $row = (object) [
        'payout_id' => null,
        'refund_cents' => 0,
        'net_cents' => 3060,
        'occurred_at' => $occurredAt->toIso8601String(),
    ];

    expect(callBrandDerive($row, $brandId))->toBe($occurredAt->toIso8601String());
});

it('returns occurred_at + 7 days when brand hold is 7', function () {
    $brandId = (string) Str::uuid();
    seedBrandHold($brandId, 7);

    $occurredAt = Carbon::parse('2026-05-13T02:47:04Z');
    $expected = $occurredAt->copy()->addDays(7)->toIso8601String();
    $row = (object) [
        'payout_id' => null,
        'refund_cents' => 0,
        'net_cents' => 3060,
        'occurred_at' => $occurredAt->toIso8601String(),
    ];

    expect(callBrandDerive($row, $brandId))->toBe($expected);
});

it('falls back to system default when brand has no settings row', function () {
    $brandId = (string) Str::uuid(); // no row inserted
    config()->set('partna.store.payout_hold_days', 7);

    $occurredAt = Carbon::parse('2026-05-13T02:47:04Z');
    $expected = $occurredAt->copy()->addDays(7)->toIso8601String();
    $row = (object) [
        'payout_id' => null,
        'refund_cents' => 0,
        'net_cents' => 3060,
        'occurred_at' => $occurredAt->toIso8601String(),
    ];

    expect(callBrandDerive($row, $brandId))->toBe($expected);
});

it('does not use grace_period_days even when brand has hold=0', function () {
    $brandId = (string) Str::uuid();
    seedBrandHold($brandId, 0);
    // Set a "trap" config value — if the controller still reads this, the
    // result would be wrong by 60 days.
    config()->set('partna.store.grace_period_days', 60);

    $occurredAt = Carbon::parse('2026-05-13T02:47:04Z');
    $row = (object) [
        'payout_id' => null,
        'refund_cents' => 0,
        'net_cents' => 3060,
        'occurred_at' => $occurredAt->toIso8601String(),
    ];

    // Should be occurred_at exactly, not occurred_at + 60.
    expect(callBrandDerive($row, $brandId))->toBe($occurredAt->toIso8601String());
});

it('returns the payout processed_at when the order is paid out', function () {
    $brandId = (string) Str::uuid();
    seedBrandHold($brandId, 0);

    $payoutId = (string) Str::uuid();
    $processedAt = '2026-05-15T10:00:00Z';
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
        'id' => $payoutId,
        'processed_at' => $processedAt,
        'created_at' => '2026-05-15T09:00:00Z',
    ]);

    $row = (object) [
        'payout_id' => $payoutId,
        'refund_cents' => 0,
        'net_cents' => 3060,
        'occurred_at' => '2026-05-13T02:47:04Z',
    ];

    expect(callBrandDerive($row, $brandId))->toBe(Carbon::parse($processedAt)->toIso8601String());
});

it('returns null for fully-refunded orders', function () {
    $brandId = (string) Str::uuid();
    seedBrandHold($brandId, 0);

    $row = (object) [
        'payout_id' => null,
        'refund_cents' => 3060,
        'net_cents' => 3060,
        'occurred_at' => '2026-05-13T02:47:04Z',
    ];

    expect(callBrandDerive($row, $brandId))->toBeNull();
});

it('affiliate controller produces the same result as brand controller', function () {
    $brandId = (string) Str::uuid();
    seedBrandHold($brandId, 0);

    $occurredAt = Carbon::parse('2026-05-13T02:47:04Z');
    $row = (object) [
        'payout_id' => null,
        'refund_cents' => 0,
        'net_cents' => 3060,
        'occurred_at' => $occurredAt->toIso8601String(),
    ];

    expect(callAffiliateDerive($row, $brandId))->toBe(callBrandDerive($row, $brandId));
});
