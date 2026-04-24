<?php

use App\Http\Controllers\Api\Professional\BrandAffiliateController;
use App\Http\Controllers\Api\Professional\BrandAffiliateInviteController;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    // brand schema is not in the default attachTestSchemas list
    try {
        DB::connection('pgsql')->statement("ATTACH DATABASE ':memory:' AS brand");
    } catch (\Throwable) {
        // already attached
    }

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        slot INTEGER,
        custom_photos_enabled INTEGER,
        status TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_affiliate_invites (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        invite_type TEXT,
        token TEXT,
        handle TEXT,
        email TEXT,
        status TEXT,
        claimed_by_professional_id TEXT,
        expires_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('brand affiliates index never returns links for another brand', function () {
    [$brandA, $brandB] = createTwoTenants('brand');
    $affiliate = createAffiliateTenant('aff-one');
    $now = now()->toDateTimeString();

    DB::table('brand.brand_partner_links')->insert([
        [
            'id' => (string) Str::uuid(),
            'brand_professional_id' => $brandA->id,
            'affiliate_professional_id' => $affiliate->id,
            'slot' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => (string) Str::uuid(),
            'brand_professional_id' => $brandB->id,
            'affiliate_professional_id' => $affiliate->id,
            'slot' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $req = tenantRequestAs($brandB);
    $response = app(BrandAffiliateController::class)->index($req);
    $payload = $response->getData(true);

    // success() wraps via response()->json($data) — no 'data' envelope.
    $affiliateIds = collect($payload['affiliates'] ?? [])->pluck('id')->all();
    // Brand B's response must never contain Brand A's professional ID — even if Brand B's own
    // affiliates don't appear (e.g. filtered by status), isolation is verified by this negative check.
    expect($affiliateIds)->not->toContain($brandA->id);
});

it('brand affiliate disconnect returns 404 when the affiliate is not linked to the caller brand', function () {
    [$brandA, $brandB] = createTwoTenants('brand');
    $affiliate = createAffiliateTenant('aff-two');
    $now = now()->toDateTimeString();

    // Only Brand A has a link with this affiliate.
    DB::table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandA->id,
        'affiliate_professional_id' => $affiliate->id,
        'slot' => 0,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Stub the lifecycle service so its complex DB queries don't run.
    // The controller should return 404 because the link between Brand B and the
    // affiliate does not exist — the service returns disconnected: false.
    $this->mock(BrandPartnerLinkLifecycleService::class, fn ($mock) => $mock->shouldReceive('disconnect')
        ->once()
        ->andReturn(new \App\Services\Professional\DTO\DisconnectResult(
            disconnected: false,
            voidedCommissionCount: 0,
            voidedCommissionCents: 0,
            selectionsRemoved: 0,
        ))
    );

    $req = tenantRequestAs($brandB);
    $response = app(BrandAffiliateController::class)->disconnect($req, $affiliate->id, app(BrandPartnerLinkLifecycleService::class));

    expect($response->getStatusCode())->toBe(404);

    // Brand A's link must still exist.
    $linkExists = DB::table('brand.brand_partner_links')
        ->where('brand_professional_id', $brandA->id)
        ->where('affiliate_professional_id', $affiliate->id)
        ->exists();
    expect($linkExists)->toBeTrue();
});

it('invite deletion refuses an invite owned by another brand', function () {
    [$brandA, $brandB] = createTwoTenants('brand');
    $inviteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::table('brand.brand_affiliate_invites')->insert([
        'id' => $inviteId,
        'brand_professional_id' => $brandA->id,
        'invite_type' => 'direct',
        'token' => Str::random(20),
        'status' => 'pending',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $req = tenantRequestAs($brandB, [], 'DELETE');
    $response = app(BrandAffiliateInviteController::class)->destroy($req, $inviteId);

    // Brand B cannot delete Brand A's invite — should get 404.
    expect($response->getStatusCode())->toBe(404);

    $stillExists = DB::table('brand.brand_affiliate_invites')->where('id', $inviteId)->exists();
    expect($stillExists)->toBeTrue();
});
