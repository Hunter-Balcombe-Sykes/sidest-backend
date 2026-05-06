<?php

use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Phase 4+: this test now does real DB work (a real Order::query()->count()),
// so it needs the Laravel app booted. Opt the file into Tests\TestCase.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupCommerceOrdersTables();
});

// Phase 4+: voidPendingForAffiliateBrand counts approved orders without a
// payout_id. When count > cap, returns overflow=true without doing any work.

it('returns overflow: true without voiding when count exceeds cap', function () {
    $affiliateId = (string) Str::uuid();
    $brandId = (string) Str::uuid();

    // Seed 250 approved+unstamped orders for the pair — over the cap of 200.
    $rows = [];
    for ($i = 0; $i < 250; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'shopify_order_id' => "shop_{$i}",
            'shopify_shop_domain' => 'cap.myshopify.com',
            'brand_professional_id' => $brandId,
            'affiliate_professional_id' => $affiliateId,
            'status' => 'approved',
            'commission_cents' => 100,
            'gross_cents' => 1000,
            'net_cents' => 1000,
            'commission_rate' => 10,
            'rate_source' => 'platform_default',
            'currency_code' => 'AUD',
            'shopify_updated_at' => now()->toDateTimeString(),
            'occurred_at' => now()->toDateTimeString(),
        ];
    }
    DB::connection('pgsql')->table('commerce.orders')->insert($rows);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $svc = new CommissionVoidService($publisher);

    $result = $svc->voidPendingForAffiliateBrand($affiliateId, $brandId, 'reason', cap: 200);

    expect($result['overflow'])->toBeTrue()
        ->and($result['count'])->toBe(0)
        ->and($result['total_cents'])->toBe(0);
});
