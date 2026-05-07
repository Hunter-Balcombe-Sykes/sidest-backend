<?php

use App\Jobs\Notifications\InviteExpirySweepJob;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupBrandAffiliateInvitesTable();
});

/**
 * Insert a brand.brand_affiliate_invites row and return its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeInvite(array $overrides = []): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('brand.brand_affiliate_invites')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => (string) Str::uuid(),
        'email' => Str::random(6).'@example.test',
        'first_name' => 'Test',
        'status' => 'pending',
        'expires_at' => now()->subMinute()->toDateTimeString(),
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return $id;
}

it('issues exactly one UPDATE per chunk, not one per row', function () {
    // Insert 3 pending+expired invites (all fit in one chunk of 500).
    makeInvite();
    makeInvite();
    makeInvite();

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->times(3)->andReturnNull();

    DB::enableQueryLog();

    $job = new InviteExpirySweepJob;
    $job->handle($publisher);

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // Count UPDATE statements that target brand.brand_affiliate_invites.
    $updates = array_filter($log, function ($entry) {
        return stripos($entry['query'], 'update') !== false
            && stripos($entry['query'], 'brand_affiliate_invites') !== false;
    });

    // One chunk → exactly one bulk UPDATE.
    expect(count($updates))->toBe(1);
});

it('marks all matching pending+expired invites as expired', function () {
    $id1 = makeInvite(['first_name' => 'Alice']);
    $id2 = makeInvite(['first_name' => 'Bob']);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->twice()->andReturnNull();

    $job = new InviteExpirySweepJob;
    $job->handle($publisher);

    $rows = DB::connection('pgsql')
        ->table('brand.brand_affiliate_invites')
        ->whereIn('id', [$id1, $id2])
        ->get();

    foreach ($rows as $row) {
        expect($row->status)->toBe('expired');
        expect($row->updated_at)->not->toBeNull();
    }
});

it('outer chunkById excludes invites whose status is no longer pending', function () {
    // This covers the OUTER filter (status='pending' on the SELECT). It does not
    // directly exercise the inner bulk-UPDATE guard — that's covered by the next test.
    $originalUpdatedAt = now()->subDay()->toDateTimeString();

    $alreadyExpiredId = (string) Str::uuid();
    DB::connection('pgsql')->table('brand.brand_affiliate_invites')->insert([
        'id' => $alreadyExpiredId,
        'brand_professional_id' => (string) Str::uuid(),
        'email' => 'already@example.test',
        'first_name' => 'Already',
        'status' => 'expired',
        'expires_at' => now()->subHour()->toDateTimeString(),
        'created_at' => $originalUpdatedAt,
        'updated_at' => $originalUpdatedAt,
    ]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->never();

    $job = new InviteExpirySweepJob;
    $job->handle($publisher);

    $row = DB::connection('pgsql')
        ->table('brand.brand_affiliate_invites')
        ->where('id', $alreadyExpiredId)
        ->first();

    expect($row->updated_at)->toBe($originalUpdatedAt);
});

it('bulk UPDATE carries the status=pending guard for the concurrent-update race', function () {
    // The inner ->where('status','pending') is a guard against a concurrent process
    // expiring a row between the SELECT and our UPDATE. The outer test above can't
    // exercise this since the row never enters the chunk. Here we capture the actual
    // UPDATE SQL + bindings and assert the guard is present in the query Laravel emits.
    makeInvite();

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturnNull();

    $captured = [];
    DB::listen(function ($query) use (&$captured) {
        if (stripos($query->sql, 'update') === 0
            && stripos($query->sql, 'brand_affiliate_invites') !== false) {
            $captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });

    $job = new InviteExpirySweepJob;
    $job->handle($publisher);

    expect($captured)->toHaveCount(1);
    // The compiled SQL must restrict to status = ? AND that binding must be 'pending'.
    expect($captured[0]['sql'])->toMatch('/"status"\s*=\s*\?/');
    expect($captured[0]['bindings'])->toContain('pending');
});

it('calls publish once per expired invite with the correct dedupeKey', function () {
    $id1 = makeInvite(['first_name' => 'Carol', 'email' => 'carol@example.test']);
    $id2 = makeInvite(['first_name' => 'Dave', 'email' => 'dave@example.test']);

    $publishedKeys = [];

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')
        ->twice()
        ->andReturnUsing(function () use (&$publishedKeys) {
            // Capture the dedupeKey argument (6th positional / named param).
            $args = func_get_args();
            // Named args come through as an array when using named parameters in PHP 8+.
            // The mock captures them in order: professionalId, frontendType, category,
            // title, body, dedupeKey, ...
            $publishedKeys[] = $args[5] ?? null;
        });

    $job = new InviteExpirySweepJob;
    $job->handle($publisher);

    expect($publishedKeys)->toContain("invite.expired.{$id1}");
    expect($publishedKeys)->toContain("invite.expired.{$id2}");
});
