<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function makeCommissionPaginator(array $items = []): \Illuminate\Pagination\LengthAwarePaginator
{
    return new \Illuminate\Pagination\LengthAwarePaginator($items, count($items), 25, 1);
}

it('returns paginated commissions for a professional', function () {
    $professional = new Professional([
        'id' => (string) Str::uuid(),
        'professional_type' => 'brand',
    ]);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();
    $mockQuery->shouldReceive('paginate')->andReturn(makeCommissionPaginator());

    DB::shouldReceive('table')->with('commerce.commission_ledger_entries')->andReturn($mockQuery);

    $controller = new StaffCommissionController;
    $response = $controller->index(Request::create('/', 'GET'), $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKeys(['data', 'meta']);
});

it('filters commissions by status when provided', function () {
    $professional = new Professional([
        'id' => (string) Str::uuid(),
        'professional_type' => 'influencer',
    ]);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();
    $mockQuery->shouldReceive('paginate')->andReturn(makeCommissionPaginator());

    DB::shouldReceive('table')->with('commerce.commission_ledger_entries')->andReturn($mockQuery);

    $controller = new StaffCommissionController;
    $response = $controller->index(Request::create('/', 'GET', ['status' => 'pending']), $professional);

    expect($response->status())->toBe(200);
});
