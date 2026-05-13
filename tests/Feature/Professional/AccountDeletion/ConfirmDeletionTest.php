<?php

use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Services\Professional\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Professional\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function seedRequestedProfessional(string $rawToken = 'a-raw-token-64-chars-long-for-testing-purposes-1234567890123456', array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Pro',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
        'deletion_token_hash' => hash('sha256', $rawToken),
        'deletion_requested_at' => now()->toIso8601String(),
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($data);

    return Professional::query()->where('id', $id)->first();
}

it('confirms with valid token: flips status, snapshots previous status, nulls token', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    $service = new AccountDeletionService;
    $result = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200)
        ->and($result['deletes_at'])->not->toBeEmpty();

    $pro->refresh();
    expect($pro->status)->toBe('pending_deletion')
        ->and($pro->deletion_previous_status)->toBe('active')
        ->and($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_confirmed_at)->not->toBeNull();

    Mail::assertSent(AccountDeletionScheduledMail::class);
});

it('deletes professional integrations at confirm time (security)', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'provider' => 'shopify',
        'access_token' => 'shpat_secret_token',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $service = new AccountDeletionService;
    $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    $count = DB::connection('pgsql')->table('core.professional_integrations')
        ->where('professional_id', $pro->id)->count();

    expect($count)->toBe(0);
});

it('rejects with 410 when token is older than 24 hours', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken, [
        'deletion_requested_at' => Carbon::now()->subHours(25)->toIso8601String(),
    ]);

    $service = new AccountDeletionService;
    $result = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(410);

    $pro->refresh();
    expect($pro->deletion_token_hash)->toBeNull()
        ->and($pro->deletion_requested_at)->toBeNull();
});

it('rejects with 404 when token does not match', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    $service = new AccountDeletionService;
    $result = $service->confirm($pro, 'wrong-token', Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(404);

    $pro->refresh();
    expect($pro->status)->toBe('active');
});

it('rejects with 404 when no deletion request exists', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'plain',
        'handle_lc' => 'plain',
        'display_name' => 'Plain',
        'primary_email' => 'plain@example.com',
        'status' => 'active',
    ]);
    $pro = Professional::query()->where('id', $id)->first();

    $service = new AccountDeletionService;
    $result = $service->confirm($pro, 'any-token', Request::create('/', 'POST'));

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(404);
});

it('writes confirmed audit event', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    $service = new AccountDeletionService;
    $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    $audit = DB::connection('pgsql')->table('core.professional_deletion_audit')
        ->where('professional_id', $pro->id)
        ->where('event', 'confirmed')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe(ProfessionalDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL);
});

it('unpublishes the site immediately when deletion is confirmed', function () {
    $rawToken = 'raw-token-'.Str::random(54);
    $pro = seedRequestedProfessional($rawToken);

    // Seed a published site for this professional.
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $pro->id,
        'subdomain' => 'pro-'.$pro->id,
        'is_published' => 1,
        'unpublished_at' => null,
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    // Reload with site relation.
    $pro = Professional::query()->with('site')->find($pro->id);

    $service = app(AccountDeletionService::class);
    $result = $service->confirm($pro, $rawToken, Request::create('/', 'POST'));

    expect($result['success'])->toBeTrue();

    $site = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->first();
    expect((bool) $site->is_published)->toBeFalse()
        ->and($site->unpublished_at)->not->toBeNull();
});
