<?php

use App\Http\Controllers\Api\Internal\EmbeddedSetupController;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Cold-miss SQL coverage for EmbeddedSetupController::overview.
// Exercises the real commerce.orders + brand_affiliate_rollup queries that the
// existing cache-hit test (EmbeddedSetupOverviewCacheTest) intentionally skips.
// Each case seeds rows directly and asserts the computed fields a wizard
// regression would surface (excluded statuses, reversal floor, currency
// resolution, 30-day window, recent_sales join).

beforeEach(function () {
    Cache::flush();
    setupCommerceOrdersTables();
    setupProfessionalsTable();
    attachTestSchemas();

    // brand.brand_partner_links is needed for affiliateCount; tenant helper
    // already creates it but we don't always seed a tenant here.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        slot INTEGER NULL,
        custom_photos_enabled INTEGER NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

    $this->controller = app(EmbeddedSetupController::class);
    $this->brandId = (string) Str::uuid();
    $this->affiliateId = (string) Str::uuid();
});

function seedOrder(string $brandId, string $affiliateId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    $row = array_merge([
        'id' => $id,
        'shopify_order_id' => (string) random_int(1000, 9_999_999),
        'shopify_shop_domain' => 'shop.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'paid',
        'gross_cents' => 10000,
        'net_cents' => 10000,
        'commission_cents' => 1000,
        'commission_rate' => 0.10,
        'currency_code' => 'AUD',
        'occurred_at' => now()->toDateTimeString(),
    ], $overrides);

    DB::connection('pgsql')->table('commerce.orders')->insert($row);

    return $id;
}

function seedRollup(string $brandId, string $affiliateId, int $reversedCents, array $overrides = []): void
{
    DB::connection('pgsql')->table('commerce.brand_affiliate_rollup')->insert(array_merge([
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'day' => now()->toDateString(),
        'currency_code' => 'AUD',
        'reversed_commission_cents' => $reversedCents,
    ], $overrides));
}

function seedAffiliateProfessional(string $id, string $displayName, ?string $handle = null): void
{
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'display_name' => $displayName,
        'handle' => $handle ?? strtolower(str_replace(' ', '-', $displayName)),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function callOverview(EmbeddedSetupController $controller, string $brandId): array
{
    $request = Request::create('/internal/embedded/overview', 'GET');
    $request->attributes->set('embedded_professional_id', $brandId);
    $response = $controller->overview($request);

    return json_decode($response->getContent(), true);
}

it('excludes stub, cancelled, voided, and refunded orders from total commission', function () {
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'paid', 'commission_cents' => 1000]);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'approved', 'commission_cents' => 500]);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'pending', 'commission_cents' => 200]);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'stub', 'commission_cents' => 99999]);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'cancelled', 'commission_cents' => 99999]);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'voided', 'commission_cents' => 99999]);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'refunded', 'commission_cents' => 99999]);

    $data = callOverview($this->controller, $this->brandId);

    expect($data['total_commission_cents'])->toBe(1700);
});

it('floors total commission at zero when reversals exceed accruals', function () {
    seedOrder($this->brandId, $this->affiliateId, ['commission_cents' => 1000]);
    // Reversed > accrued — must clamp at 0, never go negative.
    seedRollup($this->brandId, $this->affiliateId, reversedCents: 5000);

    $data = callOverview($this->controller, $this->brandId);

    expect($data['total_commission_cents'])->toBe(0);
});

it('returns AUD when there are no orders and resolves the dominant currency otherwise', function () {
    // No orders — expect AUD fallback.
    $empty = callOverview($this->controller, $this->brandId);
    expect($empty['currency_code'])->toBe('AUD');

    // Seed mixed currencies; USD wins on count.
    seedOrder($this->brandId, $this->affiliateId, ['currency_code' => 'AUD']);
    seedOrder($this->brandId, $this->affiliateId, ['currency_code' => 'USD']);
    seedOrder($this->brandId, $this->affiliateId, ['currency_code' => 'USD']);
    seedOrder($this->brandId, $this->affiliateId, ['currency_code' => 'USD']);
    // Excluded statuses must not influence the currency tally.
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'cancelled', 'currency_code' => 'EUR']);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'cancelled', 'currency_code' => 'EUR']);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'cancelled', 'currency_code' => 'EUR']);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'cancelled', 'currency_code' => 'EUR']);
    seedOrder($this->brandId, $this->affiliateId, ['status' => 'cancelled', 'currency_code' => 'EUR']);

    Cache::flush();
    $data = callOverview($this->controller, $this->brandId);

    expect($data['currency_code'])->toBe('USD');
});

it('only counts orders within the last 30 days for windowed metrics', function () {
    // 5 days ago — inside the window
    seedOrder($this->brandId, $this->affiliateId, [
        'occurred_at' => now()->subDays(5)->toDateTimeString(),
        'commission_cents' => 500,
        'gross_cents' => 5000,
    ]);
    // 45 days ago — outside the window
    seedOrder($this->brandId, $this->affiliateId, [
        'occurred_at' => now()->subDays(45)->toDateTimeString(),
        'commission_cents' => 99999,
        'gross_cents' => 99999,
    ]);

    $data = callOverview($this->controller, $this->brandId);

    expect($data['commission_30d_cents'])->toBe(500);
    expect($data['revenue_30d_cents'])->toBe(5000);
});

it('returns the 5 most recent sales with affiliate display_name joined', function () {
    seedAffiliateProfessional($this->affiliateId, 'Jane Smith', 'jane-smith');

    // 6 orders — newest first; the 6th (oldest) should be excluded by limit(5).
    foreach (range(1, 6) as $i) {
        seedOrder($this->brandId, $this->affiliateId, [
            'occurred_at' => now()->subHours($i)->toDateTimeString(),
            'commission_cents' => $i * 100,
        ]);
    }

    $data = callOverview($this->controller, $this->brandId);

    expect($data['recent_sales'])->toHaveCount(5);
    expect($data['recent_sales'][0]['affiliate_name'])->toBe('Jane Smith');
    // Newest sale has commission_cents = 100 (i=1).
    expect($data['recent_sales'][0]['commission'])->toBe('1.00 AUD');
    // ISO8601 timestamp parses cleanly.
    expect(Carbon::parse($data['recent_sales'][0]['occurred_at']))->toBeInstanceOf(Carbon::class);
});
