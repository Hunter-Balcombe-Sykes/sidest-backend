<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionPayoutController;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('returns paginated payouts', function () {
    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();
    $mockQuery->shouldReceive('paginate')->andReturn(
        new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1)
    );

    DB::shouldReceive('table')->with('commerce.commission_payouts')->andReturn($mockQuery);

    $controller = new StaffCommissionPayoutController(
        Mockery::mock(CommissionPayoutService::class)
    );
    $response = $controller->index(Request::create('/', 'GET'));
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKeys(['data', 'meta']);
});

it('filters payouts by status when provided', function () {
    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->with('status', 'failed')->andReturnSelf();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();
    $mockQuery->shouldReceive('paginate')->andReturn(
        new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1)
    );

    DB::shouldReceive('table')->with('commerce.commission_payouts')->andReturn($mockQuery);

    $controller = new StaffCommissionPayoutController(
        Mockery::mock(CommissionPayoutService::class)
    );
    $response = $controller->index(Request::create('/', 'GET', ['status' => 'failed']));

    expect($response->status())->toBe(200);
});
