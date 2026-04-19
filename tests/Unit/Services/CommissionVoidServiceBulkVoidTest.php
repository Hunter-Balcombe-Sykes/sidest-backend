<?php

use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

// Note: the bulk void method is a loop over the existing voidEntry()
// method. These tests focus on the cap + count logic, not on voidEntry
// internals (those are covered elsewhere).

it('returns overflow: true without voiding when count exceeds cap', function () {
    // Build a query result fake that reports 250 pending entries.
    $queryMock = Mockery::mock();
    $queryMock->shouldReceive('where')->andReturnSelf();
    $queryMock->shouldReceive('whereNull')->andReturnSelf();
    $queryMock->shouldReceive('count')->andReturn(250);

    DB::shouldReceive('table')->with('commerce.commission_ledger_entries')->andReturn($queryMock);

    $svc = Mockery::mock(CommissionVoidService::class)->makePartial();

    $result = $svc->voidPendingForAffiliateBrand(
        (string) Str::uuid(),
        (string) Str::uuid(),
        'reason',
        cap: 200,
    );

    expect($result['overflow'])->toBeTrue();
    expect($result['count'])->toBe(0);
    expect($result['total_cents'])->toBe(0);
});
