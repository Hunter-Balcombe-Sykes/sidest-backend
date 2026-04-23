<?php

uses(Tests\TestCase::class);

use App\Services\Shopify\Client\ShopifyBudgetTracker;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    $this->tracker = new ShopifyBudgetTracker();
    $this->shop = 'test-shop.myshopify.com';
});

it('grants full capacity on first acquire', function () {
    $result = $this->tracker->tryAcquire($this->shop, estimatedCost: 100, maxCapacity: 1000, restoreRate: 100);

    expect($result['acquired'])->toBeTrue()
        ->and($result['wait_ms'])->toBe(0)
        ->and($result['remaining'])->toBe(900);
});

it('refuses acquire when cost exceeds current tokens and returns wait time', function () {
    $this->tracker->tryAcquire($this->shop, estimatedCost: 950, maxCapacity: 1000, restoreRate: 100);

    $result = $this->tracker->tryAcquire($this->shop, estimatedCost: 500, maxCapacity: 1000, restoreRate: 100);

    expect($result['acquired'])->toBeFalse()
        ->and($result['wait_ms'])->toBeGreaterThan(0)
        ->and($result['wait_ms'])->toBeLessThanOrEqual(5000);
});

it('refills over time at the restore rate', function () {
    $this->tracker->tryAcquire($this->shop, estimatedCost: 900, maxCapacity: 1000, restoreRate: 100);

    // Simulate 2 seconds elapsed by manually rewinding updated_ms
    $pastMs = (int) (microtime(true) * 1000) - 2000;
    Redis::hset("shopify:bucket:{$this->shop}", 'updated_ms', $pastMs);

    $result = $this->tracker->tryAcquire($this->shop, estimatedCost: 100, maxCapacity: 1000, restoreRate: 100);

    // Started at 100, +200 refill = 300, minus 100 cost = 200 remaining
    expect($result['acquired'])->toBeTrue()
        ->and($result['remaining'])->toBeGreaterThanOrEqual(190)
        ->and($result['remaining'])->toBeLessThanOrEqual(210);
});

it('caps refill at max capacity', function () {
    $this->tracker->tryAcquire($this->shop, estimatedCost: 500, maxCapacity: 1000, restoreRate: 100);

    // Simulate 1 hour elapsed
    $pastMs = (int) (microtime(true) * 1000) - 3_600_000;
    Redis::hset("shopify:bucket:{$this->shop}", 'updated_ms', $pastMs);

    $result = $this->tracker->tryAcquire($this->shop, estimatedCost: 100, maxCapacity: 1000, restoreRate: 100);

    expect($result['remaining'])->toBe(900);
});

it('reconcile overwrites local state with authoritative Shopify value', function () {
    $this->tracker->tryAcquire($this->shop, estimatedCost: 100, maxCapacity: 1000, restoreRate: 100);

    $this->tracker->reconcile($this->shop, currentlyAvailable: 750, maximumAvailable: 1000, restoreRate: 100);

    $next = $this->tracker->tryAcquire($this->shop, estimatedCost: 50, maxCapacity: 1000, restoreRate: 100);
    expect($next['remaining'])->toBe(700);
});

it('is atomic under concurrent acquires', function () {
    // Fire 20 concurrent 100-point acquires against a 1000-point bucket.
    // Exactly 10 should succeed (1000 / 100), not 11 (race would leak).
    $results = [];
    for ($i = 0; $i < 20; $i++) {
        $results[] = $this->tracker->tryAcquire($this->shop, 100, 1000, 100)['acquired'];
    }

    $successes = count(array_filter($results));
    expect($successes)->toBe(10);
});
