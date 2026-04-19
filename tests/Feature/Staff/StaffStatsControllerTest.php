<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffStatsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('returns correct shape with zero data', function () {
    $profQuery = Mockery::mock();
    $profQuery->shouldReceive('selectRaw')->andReturnSelf();
    $profQuery->shouldReceive('groupBy')->andReturnSelf();
    $profQuery->shouldReceive('pluck')->andReturn(collect());

    $subQuery = Mockery::mock();
    $subQuery->shouldReceive('whereNull')->andReturnSelf();
    $subQuery->shouldReceive('count')->andReturn(0);

    $commQuery = Mockery::mock();
    $commQuery->shouldReceive('where')->andReturnSelf();
    $commQuery->shouldReceive('sum')->andReturn(0);

    DB::shouldReceive('table')->with('core.professionals')->andReturn($profQuery);
    DB::shouldReceive('table')->with('billing.subscriptions')->andReturn($subQuery);
    DB::shouldReceive('table')->with('commerce.commission_ledger_entries')->andReturn($commQuery);

    $controller = new StaffStatsController;
    $response = $controller->show(Request::create('/', 'GET'));
    $data = json_decode($response->getContent(), true);

    expect($data)->toHaveKeys(['professionals', 'subscriptions', 'commissions'])
        ->and($data['professionals'])->toHaveKeys(['brands', 'influencers', 'professionals', 'total'])
        ->and($data['professionals']['total'])->toBe(0)
        ->and($data['subscriptions']['active_count'])->toBe(0)
        ->and($data['commissions']['pending_cents'])->toBe(0);
});

it('sums professional type counts correctly', function () {
    $profQuery = Mockery::mock();
    $profQuery->shouldReceive('selectRaw')->andReturnSelf();
    $profQuery->shouldReceive('groupBy')->andReturnSelf();
    $profQuery->shouldReceive('pluck')->andReturn(collect([
        'brand' => '3',
        'influencer' => '12',
        'professional' => '5',
    ]));

    $subQuery = Mockery::mock();
    $subQuery->shouldReceive('whereNull')->andReturnSelf();
    $subQuery->shouldReceive('count')->andReturn(8);

    $commQuery = Mockery::mock();
    $commQuery->shouldReceive('where')->andReturnSelf();
    $commQuery->shouldReceive('sum')->andReturn(150000);

    DB::shouldReceive('table')->with('core.professionals')->andReturn($profQuery);
    DB::shouldReceive('table')->with('billing.subscriptions')->andReturn($subQuery);
    DB::shouldReceive('table')->with('commerce.commission_ledger_entries')->andReturn($commQuery);

    $controller = new StaffStatsController;
    $response = $controller->show(Request::create('/', 'GET'));
    $data = json_decode($response->getContent(), true);

    expect($data['professionals']['brands'])->toBe(3)
        ->and($data['professionals']['influencers'])->toBe(12)
        ->and($data['professionals']['professionals'])->toBe(5)
        ->and($data['professionals']['total'])->toBe(20)
        ->and($data['subscriptions']['active_count'])->toBe(8)
        ->and($data['commissions']['pending_cents'])->toBe(150000);
});
