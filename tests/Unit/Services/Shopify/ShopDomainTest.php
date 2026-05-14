<?php

use App\Exceptions\Shopify\InvalidShopDomainException;
use App\Services\Shopify\ShopDomain;

it('accepts a well-formed myshopify handle', function () {
    $shop = ShopDomain::fromUntrusted('side-st.myshopify.com');

    expect($shop->value)->toBe('side-st.myshopify.com');
    expect((string) $shop)->toBe('side-st.myshopify.com');
});

it('lowercases mixed-case input at the boundary', function () {
    $shop = ShopDomain::fromUntrusted('Side-ST.MyShopify.Com');

    expect($shop->value)->toBe('side-st.myshopify.com');
});

it('trims surrounding whitespace', function () {
    $shop = ShopDomain::fromUntrusted("  side-st.myshopify.com\n");

    expect($shop->value)->toBe('side-st.myshopify.com');
});

it('rejects empty string', function () {
    expect(fn () => ShopDomain::fromUntrusted(''))
        ->toThrow(InvalidShopDomainException::class);
});

it('rejects an apex domain (non-myshopify host)', function () {
    expect(fn () => ShopDomain::fromUntrusted('example.com'))
        ->toThrow(InvalidShopDomainException::class);
});

it('rejects a handle missing the myshopify.com suffix', function () {
    expect(fn () => ShopDomain::fromUntrusted('side-st'))
        ->toThrow(InvalidShopDomainException::class);
});

it('rejects an underscore in the handle (regex requires hyphen-only)', function () {
    expect(fn () => ShopDomain::fromUntrusted('side_st.myshopify.com'))
        ->toThrow(InvalidShopDomainException::class);
});

it('rejects a handle that starts with a hyphen', function () {
    expect(fn () => ShopDomain::fromUntrusted('-side.myshopify.com'))
        ->toThrow(InvalidShopDomainException::class);
});

it('rejects an embedded host (path-traversal style)', function () {
    expect(fn () => ShopDomain::fromUntrusted('attacker.com/side.myshopify.com'))
        ->toThrow(InvalidShopDomainException::class);
});

it('rejects a subdomain spoof (.myshopify.com.attacker.com)', function () {
    expect(fn () => ShopDomain::fromUntrusted('side.myshopify.com.attacker.com'))
        ->toThrow(InvalidShopDomainException::class);
});

it('rejects a CRLF injection attempt', function () {
    expect(fn () => ShopDomain::fromUntrusted("side.myshopify.com\r\nHost: evil"))
        ->toThrow(InvalidShopDomainException::class);
});

it('exposes the offending input on the exception for debugging', function () {
    try {
        ShopDomain::fromUntrusted('evil.example.com');
        $this->fail('expected InvalidShopDomainException');
    } catch (InvalidShopDomainException $e) {
        expect($e->input)->toBe('evil.example.com');
    }
});

it('is equality-comparable by value', function () {
    $a = ShopDomain::fromUntrusted('foo.myshopify.com');
    $b = ShopDomain::fromUntrusted('foo.myshopify.com');

    expect($a->value)->toBe($b->value);
});
