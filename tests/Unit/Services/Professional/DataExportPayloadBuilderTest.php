<?php

uses(Tests\TestCase::class)->in(__FILE__);

use App\Services\Professional\DataExport\DataExportPayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Professional\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
});

function seedExportProfessional(string $id, string $handle = 'jane', string $email = 'jane@example.com'): void
{
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => mb_strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);
}

it('builds payload with metadata, profile, schema_version=1', function () {
    $profId = (string) Str::uuid();
    seedExportProfessional($profId);

    $builder = new DataExportPayloadBuilder;
    $payload = $builder->build($profId);

    expect($payload['metadata']['professional_id'])->toBe($profId);
    expect($payload['metadata']['professional_handle'])->toBe('jane');
    expect($payload['metadata']['schema_version'])->toBe(1);
    expect($payload['metadata']['notes'])->toContain('PII');
    expect($payload['profile']['professional']['handle'])->toBe('jane');
});

it('excludes auth_user_id and any deletion_token from profile', function () {
    $profId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $profId,
        'handle' => 'jane',
        'auth_user_id' => 'auth-uuid-secret',
        'primary_email' => 'jane@example.com',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['profile']['professional'])->not->toHaveKey('auth_user_id');
    expect($payload['profile']['professional'])->not->toHaveKey('deletion_token_hash');
});

it('includes customers belonging to this professional and excludes others', function () {
    $profId = (string) Str::uuid();
    $otherProfId = (string) Str::uuid();
    seedExportProfessional($profId);
    seedExportProfessional($otherProfId, 'bob', 'bob@example.com');

    DB::connection('pgsql')->table('core.customers')->insert([
        ['id' => (string) Str::uuid(), 'professional_id' => $profId, 'email' => 'cust1@example.com', 'full_name' => 'Cust One', 'created_at' => '2026-01-01T00:00:00Z'],
        ['id' => (string) Str::uuid(), 'professional_id' => $otherProfId, 'email' => 'other@example.com', 'full_name' => 'Other Cust', 'created_at' => '2026-01-01T00:00:00Z'],
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['customers'])->toHaveCount(1);
    expect($payload['customers'][0]['email'])->toBe('cust1@example.com');
});

it('excludes raw_payload from booking_events (third-party PII)', function () {
    $profId = (string) Str::uuid();
    seedExportProfessional($profId);

    DB::connection('pgsql')->table('analytics.booking_events')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $profId,
        'customer_name' => 'Customer',
        'customer_email' => 'c@example.com',
        'occurred_at' => '2026-01-01T00:00:00Z',
        'amount_paid_cents' => 5000,
        'currency_code' => 'GBP',
        'raw_payload' => '{"square_secret":"OTHER_PARTY_DATA"}',
        'created_at' => '2026-01-01T00:00:00Z',
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['bookings']['booking_events'])->toHaveCount(1);
    expect($payload['bookings']['booking_events'][0])->not->toHaveKey('raw_payload');
});

it('excludes oauth tokens from integration metadata', function () {
    $profId = (string) Str::uuid();
    seedExportProfessional($profId);

    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $profId,
        'provider' => 'shopify',
        'shop_domain' => 'jane.myshopify.com',
        'access_token' => 'shpat_secret',
        'refresh_token' => 'rtok_secret',
        'last_sync_at' => '2026-01-01T00:00:00Z',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['integrations'])->toHaveCount(1);
    expect($payload['integrations'][0]['provider'])->toBe('shopify');
    expect($payload['integrations'][0])->not->toHaveKey('access_token');
    expect($payload['integrations'][0])->not->toHaveKey('refresh_token');
});

it('includes brand sections only for brand professionals', function () {
    $profId = (string) Str::uuid();
    seedExportProfessional($profId);

    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $profId,
        'industry' => 'beauty',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['profile']['brand_profile'])->not->toBeNull();
    expect($payload['profile']['brand_profile']['industry'])->toBe('beauty');
});

it('omits brand sections for non-brand professionals', function () {
    $profId = (string) Str::uuid();
    seedExportProfessional($profId);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['profile']['brand_profile'])->toBeNull();
});

it('builds an empty-but-valid payload for a professional with no related data', function () {
    $profId = (string) Str::uuid();
    seedExportProfessional($profId);

    $payload = (new DataExportPayloadBuilder)->build($profId);

    expect($payload['customers'])->toBe([]);
    expect($payload['enquiries'])->toBe([]);
    expect($payload['bookings']['booking_events'])->toBe([]);
    expect($payload['bookings']['lead_submissions'])->toBe([]);
});
