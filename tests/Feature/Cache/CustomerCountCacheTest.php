<?php

/** @phpstan-ignore-all */

use App\Models\Core\Professional\Customer;
use App\Observers\Core\CustomerObserver;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
});

it('invalidates customer count cache when a customer is created', function () {
    $professionalId = (string) Str::uuid();
    $cacheKey = CacheKeyGenerator::customerCount($professionalId);

    // Seed a cached count.
    Cache::put($cacheKey, 5, now()->addMinutes(15));
    expect(Cache::get($cacheKey))->toBe(5);

    $customer = new Customer;
    $customer->professional_id = $professionalId;

    $observer = new CustomerObserver;
    $observer->created($customer);

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('invalidates customer count cache when a customer is updated', function () {
    $professionalId = (string) Str::uuid();
    $cacheKey = CacheKeyGenerator::customerCount($professionalId);

    Cache::put($cacheKey, 10, now()->addMinutes(15));

    $customer = new Customer;
    $customer->professional_id = $professionalId;

    $observer = new CustomerObserver;
    $observer->updated($customer);

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('invalidates customer count cache when a customer is deleted', function () {
    $professionalId = (string) Str::uuid();
    $cacheKey = CacheKeyGenerator::customerCount($professionalId);

    Cache::put($cacheKey, 7, now()->addMinutes(15));

    $customer = new Customer;
    $customer->professional_id = $professionalId;

    $observer = new CustomerObserver;
    $observer->deleted($customer);

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('invalidates customer count cache when a customer is restored', function () {
    $professionalId = (string) Str::uuid();
    $cacheKey = CacheKeyGenerator::customerCount($professionalId);

    Cache::put($cacheKey, 3, now()->addMinutes(15));

    $customer = new Customer;
    $customer->professional_id = $professionalId;

    $observer = new CustomerObserver;
    $observer->restored($customer);

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('does not throw when professional_id is missing', function () {
    $customer = new Customer;
    // professional_id intentionally not set

    $observer = new CustomerObserver;
    expect(fn () => $observer->created($customer))->not->toThrow(\Throwable::class);
});

it('only invalidates the correct professional count key', function () {
    $professionalA = (string) Str::uuid();
    $professionalB = (string) Str::uuid();

    $keyA = CacheKeyGenerator::customerCount($professionalA);
    $keyB = CacheKeyGenerator::customerCount($professionalB);

    Cache::put($keyA, 5, now()->addMinutes(15));
    Cache::put($keyB, 8, now()->addMinutes(15));

    $customer = new Customer;
    $customer->professional_id = $professionalA;

    $observer = new CustomerObserver;
    $observer->deleted($customer);

    expect(Cache::has($keyA))->toBeFalse();
    expect(Cache::get($keyB))->toBe(8); // unaffected
});
