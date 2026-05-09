<?php

use App\Support\Money;

it('formats AUD cents with A$ prefix', function () {
    expect(Money::format(1099, 'AUD'))->toBe('A$10.99');
});

it('formats USD cents with $ prefix', function () {
    expect(Money::format(2500, 'USD'))->toBe('$25.00');
});

it('formats GBP cents with £ prefix', function () {
    expect(Money::format(500, 'GBP'))->toBe('£5.00');
});

it('formats EUR cents with € prefix', function () {
    expect(Money::format(750, 'EUR'))->toBe('€7.50');
});

it('formats unknown currency codes with code-space prefix', function () {
    expect(Money::format(1000, 'NZD'))->toBe('NZD 10.00');
});

it('normalises lowercase currency codes', function () {
    expect(Money::format(1000, 'aud'))->toBe('A$10.00');
});

it('normalises currency codes with surrounding whitespace', function () {
    expect(Money::format(1000, ' aud '))->toBe('A$10.00');
});

it('falls back to AUD when currency code is empty', function () {
    expect(Money::format(1000, ''))->toBe('A$10.00');
});

it('falls back to AUD when currency code is whitespace only', function () {
    expect(Money::format(1000, '   '))->toBe('A$10.00');
});

it('uses comma thousands separator', function () {
    expect(Money::format(1_000_00, 'AUD'))->toBe('A$1,000.00');
});
