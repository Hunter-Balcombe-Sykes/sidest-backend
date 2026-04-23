<?php

use App\Services\Shopify\Client\ShopifyBulkOperationLock;
use Illuminate\Support\Facades\Redis;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->shop = 'test-shop.myshopify.com';
    $this->lock = new ShopifyBulkOperationLock();
    // Pre-clean the key used in tests
    Redis::del("shopify:bulk_lock:{$this->shop}");
    Redis::del("shopify:bulk_lock:shop-a.myshopify.com");
    Redis::del("shopify:bulk_lock:shop-b.myshopify.com");
});

it('acquires the lock on first try', function () {
    expect($this->lock->acquire($this->shop))->toBeTrue();
});

it('refuses a second acquire while the first is held', function () {
    $this->lock->acquire($this->shop);

    expect($this->lock->acquire($this->shop))->toBeFalse();
});

it('allows re-acquire after release', function () {
    $this->lock->acquire($this->shop);
    $this->lock->release($this->shop);

    expect($this->lock->acquire($this->shop))->toBeTrue();
});

it('scopes locks per shop', function () {
    $this->lock->acquire('shop-a.myshopify.com');

    expect($this->lock->acquire('shop-b.myshopify.com'))->toBeTrue();
});
