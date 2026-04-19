<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffIntegrationController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns empty integrations when none exist', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')->with('core.professional_integrations')->andReturn($mockQuery);

    $controller = new StaffIntegrationController();
    $response = $controller->index(Request::create('/', 'GET'), $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['integrations'])->toBe([]);
});

it('returns integration shape without sensitive fields', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $row = (object) [
        'id'                      => (string) Str::uuid(),
        'provider'                => 'shopify',
        'external_account_id'     => 'mystore.myshopify.com',
        'last_catalog_sync_at'    => now()->toIso8601String(),
        'last_catalog_sync_error' => null,
        'expires_at'              => null,
        'created_at'              => now()->toIso8601String(),
    ];

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('get')->andReturn(collect([$row]));

    DB::shouldReceive('table')->with('core.professional_integrations')->andReturn($mockQuery);

    $controller = new StaffIntegrationController();
    $response = $controller->index(Request::create('/', 'GET'), $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['integrations'])->toHaveCount(1)
        ->and($data['integrations'][0])->toHaveKeys([
            'id', 'provider', 'external_account_id',
            'last_catalog_sync_at', 'last_catalog_sync_error',
            'expires_at', 'connected_at',
        ])
        ->and($data['integrations'][0])->not->toHaveKey('access_token')
        ->and($data['integrations'][0])->not->toHaveKey('refresh_token');
});
