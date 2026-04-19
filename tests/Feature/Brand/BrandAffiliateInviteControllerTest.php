<?php

use App\Http\Controllers\Api\Professional\BrandAffiliateInviteController;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandAffiliateInviteService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

function makeInviteControllerRequest(string $professionalType, array $payload = [], array $files = []): Request
{
    $request = Request::create('/api/test', 'POST', $payload, [], $files);
    $request->attributes->set('professional', new Professional([
        'id' => (string) Str::uuid(),
        'professional_type' => $professionalType,
    ]));

    return $request;
}

it('blocks non-brand users from bulk invite endpoint', function () {
    $controller = new BrandAffiliateInviteController;
    $request = makeInviteControllerRequest('barber', [
        'invites' => [
            ['email' => 'one@example.com'],
        ],
    ]);
    $service = \Mockery::mock(BrandAffiliateInviteService::class);

    $response = $controller->bulk($request, $service);

    expect($response->status())->toBe(403);
});

it('forwards validated bulk invite rows to the service', function () {
    $controller = new BrandAffiliateInviteController;
    $request = makeInviteControllerRequest('brand', [
        'invites' => [
            ['email' => 'one@example.com', 'first_name' => 'One'],
            ['email' => 'two@example.com', 'message' => 'Join us'],
        ],
    ]);

    $expected = [
        'summary' => [
            'total_rows' => 2,
            'created_count' => 2,
            'refreshed_count' => 0,
            'skipped_count' => 0,
            'error_count' => 0,
        ],
        'results' => [],
    ];

    $service = \Mockery::mock(BrandAffiliateInviteService::class);
    $service->shouldReceive('processBulkInvites')
        ->once()
        ->withArgs(function (Professional $professional, array $rows): bool {
            return strtolower((string) $professional->professional_type) === 'brand'
                && count($rows) === 2
                && ($rows[0]['email'] ?? null) === 'one@example.com'
                && ($rows[1]['email'] ?? null) === 'two@example.com';
        })
        ->andReturn($expected);

    $response = $controller->bulk($request, $service);

    expect($response->status())->toBe(200);
    expect($response->getData(true))->toBe($expected);
});

it('parses csv invite aliases and sends rows to the service', function () {
    $controller = new BrandAffiliateInviteController;

    $path = tempnam(sys_get_temp_dir(), 'invite_csv_');
    file_put_contents(
        $path,
        implode("\n", [
            'Email Address,First Name,Last Name,Phone Number,Expiry,Notes,Ignored Column',
            'alice@example.com,Alice,Smith,0400,7d,Join us,something',
            'bob@example.com,Bob,Jones,,,,',
        ])
    );

    $file = new UploadedFile($path, 'invites.csv', 'text/csv', null, true);
    $request = makeInviteControllerRequest('brand', [], ['file' => $file]);

    $expected = [
        'summary' => [
            'total_rows' => 2,
            'created_count' => 1,
            'refreshed_count' => 1,
            'skipped_count' => 0,
            'error_count' => 0,
        ],
        'results' => [
            ['row' => 2, 'status' => 'created'],
            ['row' => 3, 'status' => 'refreshed'],
        ],
    ];

    $service = \Mockery::mock(BrandAffiliateInviteService::class);
    $service->shouldReceive('processBulkInvites')
        ->once()
        ->withArgs(function (Professional $professional, array $rows): bool {
            return strtolower((string) $professional->professional_type) === 'brand'
                && count($rows) === 2
                && ($rows[0]['email'] ?? null) === 'alice@example.com'
                && ($rows[0]['first_name'] ?? null) === 'Alice'
                && ($rows[0]['last_name'] ?? null) === 'Smith'
                && ($rows[0]['phone'] ?? null) === '0400'
                && ($rows[0]['expiration'] ?? null) === '7d'
                && ($rows[0]['message'] ?? null) === 'Join us'
                && ($rows[0]['_row_number'] ?? null) === 2
                && ($rows[1]['email'] ?? null) === 'bob@example.com'
                && ($rows[1]['_row_number'] ?? null) === 3;
        })
        ->andReturn($expected);

    try {
        $response = $controller->importCsv($request, $service);

        expect($response->status())->toBe(200);
        expect($response->getData(true))->toBe($expected);
    } finally {
        @unlink($path);
    }
});
