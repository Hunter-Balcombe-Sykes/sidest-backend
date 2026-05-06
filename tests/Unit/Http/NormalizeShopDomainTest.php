<?php

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function makeNormalizer(): object
{
    return new class
    {
        use \App\Http\Controllers\Concerns\NormalizesShopDomain;

        public function normalize(string $value): string
        {
            return $this->normalizeShopDomain($value);
        }
    };
}

it('normalizes a standard shop domain', function () {
    expect(makeNormalizer()->normalize('my-shop.myshopify.com'))->toBe('my-shop.myshopify.com');
});

it('strips https prefix', function () {
    expect(makeNormalizer()->normalize('https://my-shop.myshopify.com'))->toBe('my-shop.myshopify.com');
});

it('strips trailing slash', function () {
    expect(makeNormalizer()->normalize('my-shop.myshopify.com/'))->toBe('my-shop.myshopify.com');
});

it('strips leading dot', function () {
    expect(makeNormalizer()->normalize('.my-shop.myshopify.com'))->toBe('my-shop.myshopify.com');
});

it('strips port number', function () {
    expect(makeNormalizer()->normalize('my-shop.myshopify.com:8080'))->toBe('my-shop.myshopify.com');
});

it('returns empty string for non-myshopify domain', function () {
    expect(makeNormalizer()->normalize('evil.com'))->toBe('');
});

it('returns empty string when dot-trimming would expose a non-myshopify domain', function () {
    // "../evil.com" trims to "evil.com" — must not pass through
    expect(makeNormalizer()->normalize('../evil.com'))->toBe('');
    expect(makeNormalizer()->normalize('./evil.com/'))->toBe('');
});

it('returns empty string for subdomain spoofing attempt', function () {
    // "shop.myshopify.com.evil.com" must not pass
    expect(makeNormalizer()->normalize('shop.myshopify.com.evil.com'))->toBe('');
});

it('returns empty string for empty input', function () {
    expect(makeNormalizer()->normalize(''))->toBe('');
});
