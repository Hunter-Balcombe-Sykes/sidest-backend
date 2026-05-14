<?php

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Notifications\Affiliate\AffiliatePayoutGraceWarningNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();

    // Full column set needed by CommissionPayoutFactory and the notification.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        status TEXT NULL,
        gross_commission_cents INTEGER NULL,
        platform_fee_cents INTEGER NULL,
        net_payout_cents INTEGER NULL,
        payment_intent_id TEXT NULL,
        charge_id TEXT NULL,
        charge_cents INTEGER NULL,
        ledger_entry_count INTEGER NULL,
        retry_count INTEGER NULL,
        needs_manual_refund INTEGER NULL,
        currency_code TEXT NULL,
        transfer_completed_at TEXT NULL,
        stripe_error_code TEXT NULL,
        stripe_error_message TEXT NULL,
        next_retry_at TEXT NULL,
        last_retry_at TEXT NULL,
        funding_failure_count INTEGER NULL,
        failure_category TEXT NULL,
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
 * Avoids the auto-resolved factory namespace mismatch (ProfessionalFactory
 * lives at Database\Factories\ProfessionalFactory, not the nested path Laravel
 * would infer from the App\Models\Core\Professional\Professional namespace).
 */
function graceWarning_makeProfessional(string $type = 'affiliate', ?string $displayName = null): Professional // renamed to avoid global fn collision
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
 * CommissionPayout is guarded (*) so we use forceFill after constructing via
 * raw insert (factory uses forceFill internally but triggers the same namespace
 * problem as Professional).
 *
 * @param  array<string, mixed>  $overrides
 */
function makePayout(array $overrides = []): CommissionPayout
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
        'charge_cents' => 0,
        'ledger_entry_count' => 0,
        'retry_count' => 0,
        'needs_manual_refund' => 0,
        'currency_code' => 'AUD',
        'funding_failure_count' => 0,
        'grace_notifications_sent' => null,
        'void_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    // Datetime overrides may be Carbon instances — cast to string for SQLite.
    foreach (['void_at', 'eligible_after', 'processed_at', 'created_at', 'updated_at'] as $col) {
        if (isset($data[$col]) && $data[$col] instanceof \DateTimeInterface) {
            $data[$col] = $data[$col]->toDateTimeString();
        }
    }

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert($data);

    return CommissionPayout::query()->findOrFail($id);
}

it('sends mail + database channel for T-30 variant', function () {
    Notification::fake();

    $aff = graceWarning_makeProfessional('affiliate');
    $brand = graceWarning_makeProfessional('brand', 'Brand X');
    $payout = makePayout([
        'affiliate_professional_id' => $aff->id,
        'brand_professional_id' => $brand->id,
        'gross_commission_cents' => 8500,
        'void_at' => now()->addDays(30),
    ]);

    $aff->notify(new AffiliatePayoutGraceWarningNotification($payout, daysRemaining: 30));

    Notification::assertSentTo($aff, AffiliatePayoutGraceWarningNotification::class, function ($n) use ($aff) {
        return $n->via($aff) === ['mail', 'database']
            && $n->daysRemaining === 30;
    });
});

it('database payload contains required fields for each variant', function (int $days) {
    $aff = graceWarning_makeProfessional('affiliate');
    $brand = graceWarning_makeProfessional('brand', 'Brand X');
    $payout = makePayout([
        'affiliate_professional_id' => $aff->id,
        'brand_professional_id' => $brand->id,
        'gross_commission_cents' => 8500,
        'void_at' => now()->addDays($days),
    ]);

    $payload = (new AffiliatePayoutGraceWarningNotification($payout, $days))->toArray($aff);

    expect($payload)->toMatchArray([
        'payout_id' => $payout->id,
        'brand_name' => 'Brand X',
        'amount_cents' => 8500,
        'days_remaining' => $days,
    ]);
    expect($payload['void_at'])->not->toBeNull();
    expect($payload['connect_url'])->toContain('/affiliate/stripe/connect');
})->with([30, 7, 1]);

it('mail subject escalates by days_remaining', function () {
    $aff = graceWarning_makeProfessional('affiliate');
    $brand = graceWarning_makeProfessional('brand', 'Brand Y');
    $payout = makePayout([
        'affiliate_professional_id' => $aff->id,
        'brand_professional_id' => $brand->id,
        'gross_commission_cents' => 5000,
    ]);

    $mail30 = (new AffiliatePayoutGraceWarningNotification($payout, 30))->toMail($aff)->subject;
    $mail7 = (new AffiliatePayoutGraceWarningNotification($payout, 7))->toMail($aff)->subject;
    $mail1 = (new AffiliatePayoutGraceWarningNotification($payout, 1))->toMail($aff)->subject;

    expect($mail30)->toContain('30 days');
    expect($mail7)->toContain('7 days');
    expect($mail1)->toMatch('/(tomorrow|24 hours|final)/i');
});
