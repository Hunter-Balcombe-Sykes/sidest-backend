<?php

use App\Services\Cache\CacheKeyGenerator;

// Master Pattern 15: the three new Hydrogen response-cache keys produced by
// CacheKeyGenerator. The format is load-bearing because invalidation calls
// the same methods to derive what to delete — drift here silently breaks
// cache busts.

it('hydrogenAffiliate key includes brand id and lowercased handle under v1 namespace', function () {
    $brand = 'a3f1cc6e-1111-4111-8111-111111111111';

    expect(CacheKeyGenerator::hydrogenAffiliate($brand, 'JohnDoe'))
        ->toBe("hydrogen:affiliate:v1:{$brand}:johndoe")
        ->and(CacheKeyGenerator::hydrogenAffiliate($brand, 'johndoe'))
        ->toBe("hydrogen:affiliate:v1:{$brand}:johndoe");
});

it('hydrogenBrandConfig key lowercases shop_domain under v1 namespace', function () {
    expect(CacheKeyGenerator::hydrogenBrandConfig('Acme-Co.myshopify.com'))
        ->toBe('hydrogen:brand-config:v1:acme-co.myshopify.com')
        ->and(CacheKeyGenerator::hydrogenBrandConfig('acme-co.myshopify.com'))
        ->toBe('hydrogen:brand-config:v1:acme-co.myshopify.com');
});

it('hydrogenAffiliateProducts key is the affiliate id under v1 namespace', function () {
    $affiliate = 'b5b2dd7f-2222-4222-8222-222222222222';

    expect(CacheKeyGenerator::hydrogenAffiliateProducts($affiliate))
        ->toBe("hydrogen:affiliate-products:v1:{$affiliate}");
});

it('cache key methods never accidentally collide across the three families', function () {
    // Defence against a future rename that forgets to update one of the three.
    $a = CacheKeyGenerator::hydrogenAffiliate('brand-id', 'slug');
    $b = CacheKeyGenerator::hydrogenBrandConfig('shop-id');
    $c = CacheKeyGenerator::hydrogenAffiliateProducts('aff-id');

    expect([$a, $b, $c])
        ->toHaveCount(3)
        ->and(array_unique([$a, $b, $c]))->toHaveCount(3);
});
