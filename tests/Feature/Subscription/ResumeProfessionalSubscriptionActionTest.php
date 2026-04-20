<?php

use App\Actions\Subscription\ResumeProfessionalSubscriptionAction;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        plan_id TEXT NOT NULL DEFAULT \'plan-1\',
        provider TEXT NOT NULL DEFAULT \'stripe\',
        stripe_customer_id TEXT NULL,
        stripe_subscription_id TEXT NULL,
        status TEXT NOT NULL DEFAULT \'active\',
        current_period_start TEXT NULL,
        current_period_end TEXT NULL,
        cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
        trial_ends_at TEXT NULL,
        ended_at TEXT NULL,
        provider_payload TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

function resumeTestProfessional(): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id'            => $id,
        'primary_email' => 'pro@example.com',
        'status'        => 'active',
        'created_at'    => now()->toIso8601String(),
        'updated_at'    => now()->toIso8601String(),
    ]);

    return Professional::query()->where('id', $id)->first();
}

function seedResumeSubscription(string $professionalId, array $overrides = []): void
{
    DB::connection('pgsql')->table('billing.subscriptions')->insert(array_merge([
        'id'                     => (string) Str::uuid(),
        'professional_id'        => $professionalId,
        'provider'               => 'stripe',
        'stripe_subscription_id' => 'sub_test_123',
        'status'                 => 'active',
        'cancel_at_period_end'   => 1,
        'current_period_end'     => now()->addDays(10)->toIso8601String(),
        'ended_at'               => null,
        'created_at'             => now()->toIso8601String(),
        'updated_at'             => now()->toIso8601String(),
    ], $overrides));
}

it('rolls back DB update when Stripe throws', function () {
    $pro = resumeTestProfessional();
    seedResumeSubscription($pro->id);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('resumeSubscription')
        ->once()
        ->andThrow(new \RuntimeException('Stripe API error'));

    $action = new ResumeProfessionalSubscriptionAction($billing);

    expect(fn () => $action->execute($pro))->toThrow(\RuntimeException::class, 'Stripe API error');

    $row = DB::connection('pgsql')
        ->table('billing.subscriptions')
        ->where('professional_id', $pro->id)
        ->first();

    expect((bool) $row->cancel_at_period_end)->toBeTrue();
});

it('clears cancel_at_period_end on success', function () {
    $pro = resumeTestProfessional();
    seedResumeSubscription($pro->id);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('resumeSubscription')->once()->andReturn(Mockery::mock(\Stripe\Subscription::class));

    $action   = new ResumeProfessionalSubscriptionAction($billing);
    $returned = $action->execute($pro);

    expect($returned->cancel_at_period_end)->toBeFalse();

    $row = DB::connection('pgsql')
        ->table('billing.subscriptions')
        ->where('professional_id', $pro->id)
        ->first();

    expect((bool) $row->cancel_at_period_end)->toBeFalse();
});

it('skips Stripe call for non-stripe provider and still clears DB flag', function () {
    $pro = resumeTestProfessional();
    seedResumeSubscription($pro->id, ['provider' => 'manual', 'stripe_subscription_id' => null]);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldNotReceive('resumeSubscription');

    $action   = new ResumeProfessionalSubscriptionAction($billing);
    $returned = $action->execute($pro);

    expect($returned->cancel_at_period_end)->toBeFalse();
});
