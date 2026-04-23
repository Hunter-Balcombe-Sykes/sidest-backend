<?php

use App\Services\Shopify\Client\ShopifyCostTracker;
use Illuminate\Support\Facades\Redis;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tracker = new ShopifyCostTracker();
    // Pre-clean only the keys this test suite uses
    Redis::del('shopify:cost:q1', 'shopify:cost:q2', 'shopify:cost:some-query-hash');
});

it('returns a conservative default estimate when nothing is recorded', function () {
    $estimate = $this->tracker->estimate('some-query-hash', requestedCost: 100);

    // No history yet — use requestedCost as-is (Shopify's estimate)
    expect($estimate)->toBe(100);
});

it('records actual cost and adjusts future estimates downward if actual < requested', function () {
    // Shopify typically charges much less than requestedCost for list queries.
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->record('q1', requestedCost: 100, actualCost: 20);
    }

    // Ratio is 0.2, so new estimate for same query should be 100 * 0.2 = 20
    $estimate = $this->tracker->estimate('q1', requestedCost: 100);
    expect($estimate)->toBe(20);
});

it('never returns an estimate lower than the minimum floor', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->record('q1', requestedCost: 100, actualCost: 1);
    }

    // Ratio would push estimate to 1, but we enforce a minimum of 10
    $estimate = $this->tracker->estimate('q1', requestedCost: 100);
    expect($estimate)->toBeGreaterThanOrEqual(10);
});

it('uses a bounded sliding window so stale data ages out', function () {
    // Window size is 20 — fill with low-cost samples, then flip to high-cost
    for ($i = 0; $i < 20; $i++) {
        $this->tracker->record('q1', 100, 20);
    }
    expect($this->tracker->estimate('q1', 100))->toBe(20);

    // Now 20 new high-cost samples — old should age out
    for ($i = 0; $i < 20; $i++) {
        $this->tracker->record('q1', 100, 80);
    }
    expect($this->tracker->estimate('q1', 100))->toBe(80);
});

it('keeps separate history per query hash', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->tracker->record('q1', 100, 20);
        $this->tracker->record('q2', 100, 80);
    }

    expect($this->tracker->estimate('q1', 100))->toBe(20);
    expect($this->tracker->estimate('q2', 100))->toBe(80);
});
