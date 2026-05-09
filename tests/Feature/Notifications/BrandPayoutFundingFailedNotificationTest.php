<?php

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Notifications\Brand\BrandPayoutFundingFailedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();

    // Full column set needed by CommissionPayoutFactory and the notification.
    // Includes lifecycle cols (next_retry_at, funding_failure_count) and failure_reason.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        status TEXT NULL,
        gross_commission_cents INTEGER NULL,
        platform_fee_cents INTEGER NULL,
        net_payout_cents INTEGER NULL,
        wallet_debit_cents INTEGER NULL,
        charge_cents INTEGER NULL,
        ledger_entry_count INTEGER NULL,
        retry_count INTEGER NULL,
        needs_manual_refund INTEGER NULL,
        currency_code TEXT NULL,
        failure_reason TEXT NULL,
        failure_code TEXT NULL,
        failure_category TEXT NULL,
        transfer_completed_at TEXT NULL,
        stripe_error_code TEXT NULL,
        stripe_error_message TEXT NULL,
        next_retry_at TEXT NULL,
        last_retry_at TEXT NULL,
        funding_failure_count INTEGER NULL,
        grace_notifications_sent TEXT NULL,
        eligible_after TEXT NULL,
        processed_at TEXT NULL,
        void_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

/**
 * Insert a Professional row directly and return the model.
 * Avoids the auto-resolved factory namespace mismatch (ProfessionalFactory lives at
 * Database\Factories\ProfessionalFactory, not the nested path Laravel infers from
 * App\Models\Core\Professional\Professional). Named with a "Brand" prefix to avoid
 * collision with the sibling makeProfessional() in AffiliatePayoutGraceWarningNotificationTest.
 */
function makeBrandTestProfessional(string $type = 'brand', ?string $displayName = null): Professional
{
    $id = (string) Str::uuid();
    $handle = 'pro-'.Str::random(8);
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => $displayName ?? ucfirst($type).'-'.Str::random(4),
        'primary_email' => $handle.'@example.test',
        'professional_type' => $type,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::query()->findOrFail($id);
}

/**
 * Insert a CommissionPayout row directly and return the model.
 * CommissionPayout is guarded (*) so we bypass the factory to avoid the same
 * factory namespace issue as Professional.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeBrandTestPayout(array $overrides = []): CommissionPayout
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    $data = array_merge([
        'id' => $id,
        'brand_professional_id' => null,
        'affiliate_professional_id' => null,
        'status' => 'pending',
        'gross_commission_cents' => 0,
        'platform_fee_cents' => 0,
        'net_payout_cents' => 0,
        'wallet_debit_cents' => 0,
        'charge_cents' => 0,
        'ledger_entry_count' => 0,
        'retry_count' => 0,
        'needs_manual_refund' => 0,
        'currency_code' => 'AUD',
        'failure_reason' => null,
        'funding_failure_count' => 0,
        'grace_notifications_sent' => null,
        'next_retry_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert($data);

    return CommissionPayout::query()->findOrFail($id);
}

it('sends mail + database channel for first-cycle failure (funding_failure_count <= 1)', function () {
    Notification::fake();

    $brand = makeBrandTestProfessional('brand');
    $payout = makeBrandTestPayout([
        'brand_professional_id' => $brand->id,
        'failure_reason' => 'Card declined',
        'next_retry_at' => now()->addDay()->toDateTimeString(),
        'funding_failure_count' => 1,
    ]);

    $brand->notify(new BrandPayoutFundingFailedNotification($payout, isTerminal: false));

    Notification::assertSentTo($brand, BrandPayoutFundingFailedNotification::class, function ($n) use ($brand) {
        return $n->via($brand) === ['mail', 'database']
            && data_get($n->toArray($brand), 'is_terminal') === false;
    });
});

it('sends database-only channel for mid-cycle failure (funding_failure_count > 1, not terminal)', function () {
    $brand = makeBrandTestProfessional('brand');
    $payout = makeBrandTestPayout([
        'brand_professional_id' => $brand->id,
        'funding_failure_count' => 3,
    ]);

    $n = new BrandPayoutFundingFailedNotification($payout, isTerminal: false);

    expect($n->via($brand))->toBe(['database']);
});

it('sends mail + database channel for terminal failure regardless of count', function () {
    $brand = makeBrandTestProfessional('brand');
    $payout = makeBrandTestPayout([
        'brand_professional_id' => $brand->id,
        'funding_failure_count' => 7,
    ]);

    $n = new BrandPayoutFundingFailedNotification($payout, isTerminal: true);

    expect($n->via($brand))->toBe(['mail', 'database']);
});

it('database payload contains required fields for terminal variant', function () {
    $brand = makeBrandTestProfessional('brand');
    $aff = makeBrandTestProfessional('affiliate', 'Affiliate Inc');
    $payout = makeBrandTestPayout([
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $aff->id,
        'failure_reason' => 'Card declined permanently',
        'next_retry_at' => null,
        'gross_commission_cents' => 12300,
        'funding_failure_count' => 7,
    ]);

    $payload = (new BrandPayoutFundingFailedNotification($payout, isTerminal: true))->toArray($brand);

    expect($payload)->toMatchArray([
        'payout_id'      => $payout->id,
        'amount_cents'   => 12300,
        'failure_reason' => 'Card declined permanently',
        'is_terminal'    => true,
        'next_retry_at'  => null,
    ]);
});
