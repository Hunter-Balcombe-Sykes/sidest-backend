<?php

use App\Http\Controllers\Api\Professional\Subscription\SubscriptionController;
use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffSubscriptionManagementController;
use App\Models\Core\Professional\Professional;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        plan_id TEXT NOT NULL,
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

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.plans (
        id TEXT PRIMARY KEY,
        plan_key TEXT NULL,
        stripe_price_id TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // Unqualified `plans` table — Laravel's `exists:plans,id` validator queries
    // the default (sqlite main) schema, not the attached `billing` schema, so
    // we mirror the plan row there too. Without this, validation would fail
    // before the controller even runs.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS plans (
        id TEXT PRIMARY KEY,
        stripe_price_id TEXT NULL
    )');

    DB::connection('pgsql')->statement('DELETE FROM billing.subscriptions');
    DB::connection('pgsql')->statement('DELETE FROM billing.plans');
    DB::connection('pgsql')->statement('DELETE FROM plans');
});

function staffSubExtensions_makeProfessional(): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'primary_email' => 'brand@example.test',
        'status' => 'active',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    return Professional::query()->where('id', $id)->first();
}

function staffSubExtensions_seedPlan(string $planId = 'plan-target', string $priceId = 'price_target'): void
{
    DB::connection('pgsql')->table('billing.plans')->insert([
        'id' => $planId,
        'plan_key' => 'pro',
        'stripe_price_id' => $priceId,
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);
    DB::connection('pgsql')->table('plans')->insert([
        'id' => $planId,
        'stripe_price_id' => $priceId,
    ]);
}

function staffSubExtensions_seedActiveStripeSub(string $professionalId): void
{
    DB::connection('pgsql')->table('billing.subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'plan_id' => 'plan-current',
        'provider' => 'stripe',
        'stripe_customer_id' => 'cus_test_123',
        'stripe_subscription_id' => 'sub_test_123',
        'status' => 'active',
        'cancel_at_period_end' => 0,
        'current_period_end' => now()->addDays(10)->toIso8601String(),
        'ended_at' => null,
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);
}

it('previewChange returns the same { data: ... } envelope as self-service previewPlanChange', function () {
    $pro = staffSubExtensions_makeProfessional();
    staffSubExtensions_seedActiveStripeSub($pro->id);
    staffSubExtensions_seedPlan(planId: 'plan-target', priceId: 'price_target');

    // Stripe preview is deterministic — same customer + sub + price ⇒ same
    // proration math. Both controllers wrap it in `{ data: <array> }`, so a
    // single mocked return value lets us assert literal shape parity.
    $sharedPreview = [
        'amount_due' => 1234,
        'currency' => 'aud',
        'lines' => [
            ['description' => 'Proration credit', 'amount' => -567],
            ['description' => 'New plan', 'amount' => 1801],
        ],
    ];

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('previewPlanChange')
        ->twice()
        ->with('cus_test_123', 'sub_test_123', 'price_target')
        ->andReturn($sharedPreview);
    app()->instance(StripeBillingService::class, $billing);

    $staffController = new StaffSubscriptionManagementController;
    $staffRequest = Request::create('/staff/...', 'GET', ['plan_id' => 'plan-target']);
    $staffResponse = $staffController->previewChange($staffRequest, $pro);

    $selfController = new SubscriptionController;
    $selfRequest = Request::create('/professionals/me/subscription/preview-change', 'GET', ['plan_id' => 'plan-target']);
    $selfRequest->attributes->set('professional', $pro);
    $selfResponse = $selfController->previewPlanChange($selfRequest);

    expect($staffResponse->status())->toBe(200);
    expect($selfResponse->status())->toBe(200);

    $staffJson = json_decode($staffResponse->getContent(), true);
    $selfJson = json_decode($selfResponse->getContent(), true);

    expect($staffJson)->toBe(['data' => $sharedPreview]);
    expect($staffJson)->toBe($selfJson);
});

it('previewChange returns 422 when no Stripe-managed subscription exists', function () {
    $pro = staffSubExtensions_makeProfessional();
    staffSubExtensions_seedPlan(planId: 'plan-target', priceId: 'price_target');
    // intentionally no subscription row

    $controller = new StaffSubscriptionManagementController;
    $request = Request::create('/', 'GET', ['plan_id' => 'plan-target']);
    $response = $controller->previewChange($request, $pro);

    expect($response->status())->toBe(422);
    expect(json_decode($response->getContent(), true)['message'])
        ->toBe('No Stripe-managed subscription to preview changes for.');
});

it('billingPortal NEVER returns the portal URL to staff and publishes a notification to the brand instead', function () {
    $pro = staffSubExtensions_makeProfessional();

    $portalUrl = 'https://billing.stripe.test/session/'.Str::random(24);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('createBillingPortalSession')
        ->once()
        ->with(Mockery::on(fn ($p) => $p instanceof Professional && $p->id === $pro->id), 'https://app.example.test/return')
        ->andReturn($portalUrl);
    app()->instance(StripeBillingService::class, $billing);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')
        ->once()
        ->withArgs(function (...$args) use ($pro, $portalUrl) {
            // NotificationPublisher::publish uses named arguments — when called
            // positionally via reflection they arrive in declaration order:
            // (professionalId, frontendType, category, title, body, dedupeKey,
            //  ctaUrl, primaryActionLabel, secondaryActionLabel, secondaryActionUrl,
            //  retentionConfigKey)
            [$professionalId, , $category, , , $dedupeKey, $ctaUrl] = $args + array_fill(0, 11, null);

            expect($professionalId)->toBe($pro->id);
            expect($category)->toBe('subscriptions');
            expect($ctaUrl)->toBe($portalUrl);
            expect($dedupeKey)->toStartWith('subscription.staff_portal.'.$pro->id.'.');

            return true;
        });
    app()->instance(NotificationPublisher::class, $publisher);

    $controller = new StaffSubscriptionManagementController;
    $request = Request::create('/', 'POST', ['return_url' => 'https://app.example.test/return']);
    $request->attributes->set('partna_staff', (object) ['id' => 'staff-uuid-1']);

    $response = $controller->billingPortal($request, $pro);
    $body = $response->getContent();
    $json = json_decode($body, true);

    // Security defence — the response is the assertion.
    expect($response->status())->toBe(200);
    expect($json)->toBe([
        'data' => [
            'sent' => true,
            'professional_id' => $pro->id,
        ],
    ]);
    // Belt-and-braces: the portal URL must not appear anywhere in the body —
    // not in headers, error messages, or accidental dump output.
    expect($body)->not->toContain($portalUrl);
    expect($body)->not->toContain('billing.stripe.test');
    expect($body)->not->toContain('portal_url');
});
