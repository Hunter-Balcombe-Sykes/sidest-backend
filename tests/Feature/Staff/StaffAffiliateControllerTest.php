<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffAffiliateController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns empty affiliates list when brand has no links', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('join')->andReturnSelf();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('whereNull')->andReturnSelf();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();
    $mockQuery->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')->with('brand.brand_partner_links as bpl')->andReturn($mockQuery);

    $controller = new StaffAffiliateController();
    $response = $controller->index(Request::create('/', 'GET'), $brand);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['affiliates'])->toBe([]);
});

it('returns affiliate summary shape', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);

    $row = (object) [
        'id'                   => (string) Str::uuid(),
        'first_name'           => 'Sarah',
        'last_name'            => 'Jones',
        'display_name'         => 'Sarah Jones',
        'handle'               => 'sarah',
        'professional_type'    => 'influencer',
        'status'               => 'active',
        'primary_email'        => 'sarah@example.com',
        'public_contact_email' => null,
        'phone'                => null,
        'public_contact_number' => null,
        'slot'                 => 0,
        'custom_photos_enabled' => true,
        'connected_at'         => now()->toIso8601String(),
    ];

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('join')->andReturnSelf();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('whereNull')->andReturnSelf();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();
    $mockQuery->shouldReceive('get')->andReturn(collect([$row]));

    DB::shouldReceive('table')->with('brand.brand_partner_links as bpl')->andReturn($mockQuery);

    $controller = new StaffAffiliateController();
    $response = $controller->index(Request::create('/', 'GET'), $brand);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['affiliates'])->toHaveCount(1)
        ->and($data['affiliates'][0])->toHaveKeys([
            'id', 'full_name', 'handle', 'status', 'email',
            'is_primary', 'custom_photos_enabled', 'connected_at',
        ])
        ->and($data['affiliates'][0]['full_name'])->toBe('Sarah Jones')
        ->and($data['affiliates'][0]['is_primary'])->toBeTrue();
});
