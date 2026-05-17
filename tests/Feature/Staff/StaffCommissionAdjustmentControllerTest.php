<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionAdjustmentController;
use App\Http\Requests\Api\Staff\PostCommissionAdjustmentRequest;
use App\Services\Stripe\CommissionAdjustmentService;
use App\Services\Stripe\DuplicateAdjustmentException;
use Illuminate\Support\Str;

// LEDGER-1 — controller + form-request tests for the staff commission
// adjustment endpoint. Mirrors the unit-style pattern in
// StaffCommissionVoidControllerTest: service is mocked, request is hand-
// constructed so the suite runs without commerce/core tables present.
// The `exists:` rules on the professional IDs are stripped from the
// validator below — the integration coverage for FK resolution sits in
// HTTP-level tests against a real DB.

function makeValidator(array $payload, PostCommissionAdjustmentRequest $request)
{
    $rules = collect($request->rules())
        ->map(fn ($r) => array_values(array_filter(
            (array) $r,
            fn ($x) => ! is_string($x) || ! str_starts_with($x, 'exists:'),
        )))
        ->all();

    return app('validator')->make($payload, $rules, $request->messages());
}

function makeAdjustmentRequest(array $payload): PostCommissionAdjustmentRequest
{
    $request = PostCommissionAdjustmentRequest::create('/staff/commissions/adjust', 'POST', $payload);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->setValidator(makeValidator($payload, $request));
    $request->attributes->set('partna_staff', (object) [
        'id' => 'staff-1',
        'email' => 'support@partna.au',
    ]);

    return $request;
}

it('posts an adjustment and returns 201', function () {
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $movementId = (string) Str::uuid();

    $service = Mockery::mock(CommissionAdjustmentService::class);
    $service->shouldReceive('post')
        ->once()
        ->withArgs(function (
            string $brand,
            string $affiliate,
            int $amount,
            string $currency,
            string $reason,
            string $reference,
            array $actor,
        ) use ($brandId, $affiliateId) {
            return $brand === $brandId
                && $affiliate === $affiliateId
                && $amount === 5000
                && $currency === 'AUD'
                && str_starts_with($reason, 'Refund mis-attributed')
                && $reference === 'support-ticket-123'
                && $actor['actor_id'] === 'staff-1';
        })
        ->andReturn([
            'id' => $movementId,
            'amount_cents' => 5000,
            'currency_code' => 'AUD',
            'reference' => 'support-ticket-123',
        ]);

    $controller = new StaffCommissionAdjustmentController($service);
    $request = makeAdjustmentRequest([
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'amount_cents' => 5000,
        'reason' => 'Refund mis-attributed to wrong affiliate, correcting per ticket #123.',
        'reference' => 'support-ticket-123',
    ]);

    $response = $controller->store($request);
    $body = json_decode($response->getContent(), true);

    expect($response->status())->toBe(201)
        ->and($body['id'])->toBe($movementId)
        ->and($body['amount_cents'])->toBe(5000)
        ->and($body['reference'])->toBe('support-ticket-123');
});

it('accepts a negative adjustment (clawback shape)', function () {
    $service = Mockery::mock(CommissionAdjustmentService::class);
    $service->shouldReceive('post')
        ->once()
        ->withArgs(fn ($brand, $aff, int $amount) => $amount === -2500)
        ->andReturn([
            'id' => (string) Str::uuid(),
            'amount_cents' => -2500,
            'currency_code' => 'AUD',
            'reference' => 'support-ticket-neg',
        ]);

    $controller = new StaffCommissionAdjustmentController($service);
    $request = makeAdjustmentRequest([
        'brand_professional_id' => (string) Str::uuid(),
        'affiliate_professional_id' => (string) Str::uuid(),
        'amount_cents' => -2500,
        'reason' => 'Reverse double-credited commission posted in error last week.',
        'reference' => 'support-ticket-neg',
    ]);

    $response = $controller->store($request);

    expect($response->status())->toBe(201);
});

it('maps a duplicate-reference exception to a 409 response', function () {
    $service = Mockery::mock(CommissionAdjustmentService::class);
    $service->shouldReceive('post')
        ->once()
        ->andThrow(new DuplicateAdjustmentException('support-ticket-dup'));

    $controller = new StaffCommissionAdjustmentController($service);
    $request = makeAdjustmentRequest([
        'brand_professional_id' => (string) Str::uuid(),
        'affiliate_professional_id' => (string) Str::uuid(),
        'amount_cents' => 5000,
        'reason' => 'A long enough reason for the validator to accept.',
        'reference' => 'support-ticket-dup',
    ]);

    $response = $controller->store($request);
    $body = json_decode($response->getContent(), true);

    expect($response->status())->toBe(409)
        ->and($body['message'])->toContain('support-ticket-dup');
});

it('rejects amount_cents = 0 via validation', function () {
    $request = PostCommissionAdjustmentRequest::create('/staff/commissions/adjust', 'POST', [
        'brand_professional_id' => (string) Str::uuid(),
        'affiliate_professional_id' => (string) Str::uuid(),
        'amount_cents' => 0,
        'reason' => 'This reason is sufficiently long to pass min 20.',
        'reference' => 'support-ticket-zero',
    ]);

    $validator = makeValidator($request->all(), $request);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('amount_cents'))->toBeTrue();
});

it('rejects reason shorter than 20 chars via validation', function () {
    $request = PostCommissionAdjustmentRequest::create('/staff/commissions/adjust', 'POST', [
        'brand_professional_id' => (string) Str::uuid(),
        'affiliate_professional_id' => (string) Str::uuid(),
        'amount_cents' => 1000,
        'reason' => 'too short',
        'reference' => 'support-ticket-short',
    ]);

    $validator = makeValidator($request->all(), $request);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('reason'))->toBeTrue();
});

it('rejects identical brand and affiliate ids via validation', function () {
    $sameId = (string) Str::uuid();
    $request = PostCommissionAdjustmentRequest::create('/staff/commissions/adjust', 'POST', [
        'brand_professional_id' => $sameId,
        'affiliate_professional_id' => $sameId,
        'amount_cents' => 1000,
        'reason' => 'A long enough reason for the validator to accept.',
        'reference' => 'support-ticket-self',
    ]);

    $validator = makeValidator($request->all(), $request);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('affiliate_professional_id'))->toBeTrue();
});

it('requires a reference for idempotency', function () {
    $request = PostCommissionAdjustmentRequest::create('/staff/commissions/adjust', 'POST', [
        'brand_professional_id' => (string) Str::uuid(),
        'affiliate_professional_id' => (string) Str::uuid(),
        'amount_cents' => 1000,
        'reason' => 'A long enough reason for the validator to accept.',
    ]);

    $validator = makeValidator($request->all(), $request);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('reference'))->toBeTrue();
});
