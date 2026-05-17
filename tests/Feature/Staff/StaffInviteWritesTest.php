<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffInviteController;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

function staffInvite_makeBrand(bool $funded = true): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();
    $pro->professional_type = 'brand';
    $pro->exists = true;

    $connect = Mockery::mock(StripeConnectService::class);
    $connect->shouldReceive('brandHasPaymentMethod')->andReturn($funded);
    app()->instance(StripeConnectService::class, $connect);

    return $pro;
}

function staffInvite_makeAffiliate(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();
    $pro->professional_type = 'affiliate';
    $pro->exists = true;

    return $pro;
}

it('store creates an invite for a funded brand', function () {
    $brand = staffInvite_makeBrand(funded: true);

    $invite = new BrandAffiliateInvite([
        'id' => (string) Str::uuid(),
        'token' => Str::random(48),
        'status' => 'pending',
        'invite_type' => 'personalised',
        'email' => 'aff@example.test',
        'first_name' => 'Jane',
    ]);

    $service = Mockery::mock(BrandAffiliateInviteService::class);
    $service->shouldReceive('createOrRefreshInvite')
        ->once()
        ->andReturn(['invite' => $invite, 'action' => 'created']);

    $controller = new StaffInviteController;
    $response = $controller->store(
        Request::create('/', 'POST', ['email' => 'aff@example.test', 'first_name' => 'Jane']),
        $brand,
        $service,
    );

    expect($response->status())->toBe(201);
    $body = json_decode($response->getContent(), true);
    expect($body['invite']['email'])->toBe('aff@example.test');
    expect($body['action'])->toBe('created');
});

it('store returns 422 when the professional is not a brand', function () {
    $affiliate = staffInvite_makeAffiliate();
    $service = Mockery::mock(BrandAffiliateInviteService::class);
    // service must not be called when the gate fails
    $service->shouldNotReceive('createOrRefreshInvite');

    $controller = new StaffInviteController;
    $response = $controller->store(
        Request::create('/', 'POST', ['email' => 'aff@example.test']),
        $affiliate,
        $service,
    );

    expect($response->status())->toBe(422);
    expect(json_decode($response->getContent(), true)['message'])
        ->toContain('not a brand account');
});

it('store returns 402 + funding-required payload when the brand has no payment method', function () {
    $brand = staffInvite_makeBrand(funded: false);
    $service = Mockery::mock(BrandAffiliateInviteService::class);
    $service->shouldNotReceive('createOrRefreshInvite');

    $controller = new StaffInviteController;
    $response = $controller->store(
        Request::create('/', 'POST', ['email' => 'aff@example.test']),
        $brand,
        $service,
    );

    expect($response->status())->toBe(402);
    $body = json_decode($response->getContent(), true);
    expect($body['code'])->toBe('brand_funding_required');
    expect($body['data']['reason'])->toBe('no_payment_method');
});

it('bulk forwards the rows to processBulkInvites and returns the summary envelope', function () {
    $brand = staffInvite_makeBrand(funded: true);

    $summary = [
        'summary' => ['total_rows' => 2, 'created_count' => 2, 'refreshed_count' => 0, 'skipped_count' => 0, 'error_count' => 0],
        'results' => [],
    ];

    $service = Mockery::mock(BrandAffiliateInviteService::class);
    $service->shouldReceive('processBulkInvites')
        ->once()
        ->withArgs(function ($passedBrand, $rows) use ($brand) {
            expect($passedBrand->id)->toBe($brand->id);
            expect($rows)->toHaveCount(2);

            return true;
        })
        ->andReturn($summary);

    $controller = new StaffInviteController;
    $response = $controller->bulk(
        Request::create('/', 'POST', ['invites' => [['email' => 'a@x.test'], ['email' => 'b@x.test']]]),
        $brand,
        $service,
    );

    expect($response->status())->toBe(200);
    expect(json_decode($response->getContent(), true))->toBe($summary);
});

it('importCsv parses the uploaded CSV and forwards parsed rows to processBulkInvites', function () {
    $brand = staffInvite_makeBrand(funded: true);

    // Real CSV bytes — exercises the trait's parseInviteCsvRows including the
    // BOM strip + header alias map, so this test fails if the trait extraction
    // ever drifts from the original self-service behaviour.
    $csv = "Email,First Name\naff1@example.test,Alice\naff2@example.test,Bob\n";
    $tmp = tempnam(sys_get_temp_dir(), 'invite-csv-');
    file_put_contents($tmp, $csv);
    $file = new UploadedFile($tmp, 'invites.csv', 'text/csv', null, true);

    $service = Mockery::mock(BrandAffiliateInviteService::class);
    $service->shouldReceive('processBulkInvites')
        ->once()
        ->withArgs(function ($_brand, $rows) {
            expect($rows)->toHaveCount(2);
            expect($rows[0]['email'])->toBe('aff1@example.test');
            expect($rows[0]['first_name'])->toBe('Alice');
            expect($rows[1]['email'])->toBe('aff2@example.test');

            return true;
        })
        ->andReturn(['summary' => ['total_rows' => 2], 'results' => []]);

    $controller = new StaffInviteController;
    $request = Request::create('/', 'POST', [], [], ['file' => $file]);

    $response = $controller->importCsv($request, $brand, $service);

    expect($response->status())->toBe(200);
});

it('resend delegates to resendInvite and returns a resent payload', function () {
    $brand = staffInvite_makeBrand(funded: true);

    $oldInvite = new BrandAffiliateInvite([
        'id' => (string) Str::uuid(),
        'status' => 'expired',
        'email' => 'aff@example.test',
    ]);

    $refreshedInvite = new BrandAffiliateInvite([
        'id' => $oldInvite->id,
        'token' => Str::random(48),
        'status' => 'pending',
        'email' => 'aff@example.test',
        'expires_at' => now()->addDays(30),
    ]);

    $service = Mockery::mock(BrandAffiliateInviteService::class);
    $service->shouldReceive('resendInvite')
        ->once()
        ->with(Mockery::on(fn ($i) => $i instanceof BrandAffiliateInvite && $i->id === $oldInvite->id))
        ->andReturn($refreshedInvite);

    $controller = new StaffInviteController;
    $response = $controller->resend(Request::create('/', 'POST'), $brand, $oldInvite, $service);

    expect($response->status())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['invite']['status'])->toBe('pending');
    expect($body['invite']['email'])->toBe('aff@example.test');
    expect($body['action'])->toBe('resent');
});

it('resend surfaces a 422 when the service refuses (e.g. accepted or generic invite)', function () {
    $brand = staffInvite_makeBrand(funded: true);

    $invite = new BrandAffiliateInvite([
        'id' => (string) Str::uuid(),
        'status' => 'accepted',
        'email' => 'aff@example.test',
    ]);

    $service = Mockery::mock(BrandAffiliateInviteService::class);
    $service->shouldReceive('resendInvite')
        ->once()
        ->andThrow(new RuntimeException('This invite has already been accepted.'));

    $controller = new StaffInviteController;
    $response = $controller->resend(Request::create('/', 'POST'), $brand, $invite, $service);

    expect($response->status())->toBe(422);
    expect(json_decode($response->getContent(), true)['message'])
        ->toBe('This invite has already been accepted.');
});

it('resendInvite service method refuses generic (no-email) invites', function () {
    $invite = new BrandAffiliateInvite([
        'id' => (string) Str::uuid(),
        'status' => 'pending',
        'email' => null,
    ]);

    $service = new BrandAffiliateInviteService(
        Mockery::mock(\App\Services\Professional\Brand\BrandPartnerLinkService::class)
    );

    expect(fn () => $service->resendInvite($invite))
        ->toThrow(RuntimeException::class, 'generic invite');
});
