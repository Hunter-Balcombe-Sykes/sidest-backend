<?php

use App\Services\Stripe\CommissionPayoutService;

it('maps insufficient_funds to platform_balance', function () {
    expect(CommissionPayoutService::categorizeTransferFailure('insufficient_funds'))
        ->toBe('platform_balance');
});

it('maps affiliate-side codes to affiliate_account', function (string $code) {
    expect(CommissionPayoutService::categorizeTransferFailure($code))
        ->toBe('affiliate_account');
})->with([
    'account_closed',
    'account_frozen',
    'bank_account_restricted',
    'no_account',
    'declined',
]);

it('maps currency_not_supported to currency', function () {
    expect(CommissionPayoutService::categorizeTransferFailure('currency_not_supported'))
        ->toBe('currency');
});

it('maps null and empty string to unknown', function (?string $code) {
    expect(CommissionPayoutService::categorizeTransferFailure($code))
        ->toBe('unknown');
})->with([null, '']);

it('maps unrecognised codes to other', function () {
    expect(CommissionPayoutService::categorizeTransferFailure('something_new_from_stripe'))
        ->toBe('other');
});
