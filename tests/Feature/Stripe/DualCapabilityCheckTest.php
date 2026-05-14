<?php

use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Tests for StripeConnectService::determineAccountStatus — the dual-capability check
// that derives the canonical local status from a v2 Account object.
//
// v2 capability paths:
//   configuration.merchant.capabilities.card_payments.status
//   configuration.recipient.capabilities.stripe_balance.stripe_transfers.status
//
// Brand: BOTH card_payments AND stripe_transfers active + no requirements → 'active'.
//        Either one active but not both, or both active with requirements → 'restricted'.
//        Neither active → 'onboarding'.
// Affiliate: only stripe_transfers matters. Active → 'active', not → 'onboarding'.

beforeEach(function () {
    setupProfessionalsTable();
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
    ] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }
});

function dualCap_seedProfessional(string $type): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "pro-{$id}",
        'handle_lc' => "pro-{$id}",
        'display_name' => 'Test Pro',
        'professional_type' => $type,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($id);
}

function dualCap_buildAccount(bool $cardActive, bool $transfersActive, array $currentlyDue = []): object
{
    return (object) [
        'id' => 'acct_test',
        'configuration' => (object) [
            'merchant' => (object) [
                'capabilities' => (object) [
                    'card_payments' => (object) [
                        'status' => $cardActive ? 'active' : 'pending',
                    ],
                ],
            ],
            'customer' => (object) [],
            'recipient' => (object) [
                'capabilities' => (object) [
                    'stripe_balance' => (object) [
                        'stripe_transfers' => (object) [
                            'status' => $transfersActive ? 'active' : 'pending',
                        ],
                    ],
                ],
            ],
        ],
        'requirements' => (object) ['currently_due' => $currentlyDue],
    ];
}

// ============================================================
// Brand dual-capability checks
// ============================================================

it('returns active for brand with both capabilities active and no requirements', function () {
    $brand = dualCap_seedProfessional('brand');
    $account = dualCap_buildAccount(true, true, []);

    expect(StripeConnectService::determineAccountStatus($account, $brand))->toBe('active');
});

it('returns restricted for brand with card payments active but stripe_transfers pending', function () {
    $brand = dualCap_seedProfessional('brand');
    $account = dualCap_buildAccount(true, false, []);

    expect(StripeConnectService::determineAccountStatus($account, $brand))->toBe('restricted');
});

it('returns restricted for brand with stripe_transfers active but card_payments pending', function () {
    $brand = dualCap_seedProfessional('brand');
    $account = dualCap_buildAccount(false, true, []);

    expect(StripeConnectService::determineAccountStatus($account, $brand))->toBe('restricted');
});

it('returns restricted for brand with both capabilities active but outstanding requirements', function () {
    $brand = dualCap_seedProfessional('brand');
    $account = dualCap_buildAccount(true, true, ['individual.verification.document']);

    expect(StripeConnectService::determineAccountStatus($account, $brand))->toBe('restricted');
});

it('returns onboarding for brand with neither capability active', function () {
    $brand = dualCap_seedProfessional('brand');
    $account = dualCap_buildAccount(false, false, []);

    expect(StripeConnectService::determineAccountStatus($account, $brand))->toBe('onboarding');
});

it('returns restricted for brand with one capability active and requirements due (even if second capability pending)', function () {
    $brand = dualCap_seedProfessional('brand');
    // card_payments active, stripe_transfers pending, but requirements exist → restricted
    $account = dualCap_buildAccount(true, false, ['external_account']);

    expect(StripeConnectService::determineAccountStatus($account, $brand))->toBe('restricted');
});

// ============================================================
// Affiliate (non-brand) capability checks — transfers only
// ============================================================

it('returns active for affiliate with stripe_transfers active', function () {
    $affiliate = dualCap_seedProfessional('professional');
    $account = dualCap_buildAccount(false, true, []);

    expect(StripeConnectService::determineAccountStatus($account, $affiliate))->toBe('active');
});

it('returns onboarding for affiliate with stripe_transfers pending', function () {
    $affiliate = dualCap_seedProfessional('influencer');
    $account = dualCap_buildAccount(false, false, []);

    expect(StripeConnectService::determineAccountStatus($account, $affiliate))->toBe('onboarding');
});

it('returns active for affiliate even when card_payments is also active (ignored)', function () {
    $affiliate = dualCap_seedProfessional('professional');
    // card_payments doesn't affect affiliate status.
    $account = dualCap_buildAccount(true, true, []);

    expect(StripeConnectService::determineAccountStatus($account, $affiliate))->toBe('active');
});

it('returns onboarding for affiliate even with card_payments active but transfers pending', function () {
    $affiliate = dualCap_seedProfessional('influencer');
    $account = dualCap_buildAccount(true, false, []);

    expect(StripeConnectService::determineAccountStatus($account, $affiliate))->toBe('onboarding');
});
